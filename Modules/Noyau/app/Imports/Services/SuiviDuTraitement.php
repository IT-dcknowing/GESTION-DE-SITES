<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Modeles\LotImport;
use RuntimeException;

/**
 * Ce qu'un traitement en cours dit de lui-même : où il en est, et si on lui demande d'arrêter.
 *
 * **Pourquoi la barre de progression ne bougeait pas.** La lecture écrivait son avancée sur la
 * ligne du lot, toutes les cent lignes — mais depuis l'intérieur de la transaction qui porte
 * tout l'import. Une écriture non validée n'est visible de personne d'autre : l'écran qui
 * interrogeait le lot lisait zéro jusqu'à la dernière seconde, puis le résultat final. La
 * barre ne pouvait rien montrer, et elle ne montrait rien.
 *
 * **Pourquoi pas une seconde connexion à la base.** C'était le remède évident, et il bloque :
 * chaque facture, devis ou encaissement écrit par l'import porte une clé étrangère vers son
 * lot, et la base pose alors un verrou partagé sur la ligne du lot jusqu'à la fin de la
 * transaction. Une mise à jour de cette ligne depuis une autre connexion attendrait la fin de
 * l'import — que le même processus attend lui-même. Mesuré sur la base : dix tables pointent
 * vers `lots_import`.
 *
 * **D'où le cache sur fichier.** Aucune transaction ne le voit, aucun verrou ne le retient,
 * et il existe sur n'importe quel hébergement. Il porte deux choses et rien d'autre : le
 * nombre de lignes lues, avec l'heure du dernier signe de vie, et la demande d'arrêt. Le lot en
 * base reste la vérité ; le cache n'est que ce qu'on sait *pendant* le travail.
 */
final class SuiviDuTraitement
{
    /** Le magasin de cache : sur fichier, exprès — voir plus haut. */
    public const MAGASIN = 'file';

    /** Au-delà, une lecture qui ne donne plus signe de vie est tenue pour coupée. */
    public const SILENCE_MAXIMUM_EN_SECONDES = 600;

    /** Deux heures : bien plus qu'aucun import, et le cache se vide seul ensuite. */
    private const DUREE = 7200;

    /**
     * Prend un lot pour le traiter — et un seul processus y parvient.
     *
     * Le lot peut être lancé par deux chemins à la fois : le processus détaché démarré au dépôt,
     * et la file d'attente qui sert de filet. Sans cette prise, les deux liraient le même
     * fichier en même temps et chacun créerait ses factures. La condition sur l'état fait de la
     * mise à jour une prise atomique : le second trouve le lot déjà « en cours » et s'en va.
     */
    public static function prendre(LotImport $lot): bool
    {
        $pris = DB::table('lots_import')
            ->where('id', $lot->id)
            ->where('etat', 'depose')
            ->update([
                'etat' => 'en_cours',
                'demarre_le' => now(),
                'message' => null,
                'lignes_lues' => 0,
                'updated_at' => now(),
            ]) === 1;

        if ($pris) {
            Cache::store(self::MAGASIN)->forget(self::cleArret($lot->id));
            self::avancer($lot->id, 0);
        }

        return $pris;
    }

    /**
     * Note l'avancée — et s'arrête net si on l'a demandé.
     *
     * L'arrêt passe par une exception parce que c'est la seule sortie qui garantisse l'annulation
     * de la transaction : tout ce que l'import avait commencé d'écrire est défait, la base
     * revient à l'état d'avant le dépôt.
     */
    public static function avancer(int $lotId, int $lues): void
    {
        $arret = Cache::store(self::MAGASIN)->get(self::cleArret($lotId));

        if ($arret !== null) {
            throw new ImportArrete($lues, (int) ($arret['par'] ?? 0), (string) ($arret['nom'] ?? ''));
        }

        Cache::store(self::MAGASIN)->put(self::cleAvancee($lotId), ['lues' => $lues, 'a' => time()], self::DUREE);
    }

    /**
     * Ce que l'écran affiche d'un lot.
     *
     * @return array{lues: int, estimees: ?int, pourcentage: ?int, arret_demande: bool, interrompu: bool, attente: int}
     */
    public static function etat(LotImport $lot): array
    {
        $avancee = Cache::store(self::MAGASIN)->get(self::cleAvancee($lot->id));
        $enCours = $lot->etat === 'en_cours';

        $lues = $enCours && is_array($avancee) ? (int) $avancee['lues'] : (int) $lot->lignes_lues;
        $estimees = $lot->lignes_estimees ? (int) $lot->lignes_estimees : null;

        $pourcentage = match (true) {
            $lot->etat === 'termine' => 100,
            $estimees === null => null,
            // Plafonné à 99 tant que ce n'est pas fini : après la dernière ligne il reste à
            // valider la transaction et écrire le journal des rejets, et une barre pleine sur
            // un traitement qui tourne encore se lit comme une panne.
            default => min(99, (int) floor($lues * 100 / $estimees)),
        };

        $dernierSigne = is_array($avancee)
            ? (int) $avancee['a']
            : ($lot->demarre_le?->getTimestamp() ?? $lot->updated_at?->getTimestamp() ?? time());

        return [
            'lues' => $lues,
            'estimees' => $estimees,
            'pourcentage' => $pourcentage,
            'arret_demande' => Cache::store(self::MAGASIN)->has(self::cleArret($lot->id)),
            // Même le classeur des impayés, le plus lourd, avance de cent lignes en une seconde :
            // dix minutes sans nouvelle, c'est un processus coupé, pas un fichier lent.
            'interrompu' => $enCours && time() - $dernierSigne > self::SILENCE_MAXIMUM_EN_SECONDES,
            'attente' => $lot->etat === 'depose' ? max(0, time() - ($lot->created_at?->getTimestamp() ?? time())) : 0,
        ];
    }

    /**
     * Arrête un import qui n'est pas fini, et dit ce qui s'est passé.
     *
     * Deux cas, parce qu'il y a deux moments :
     *
     * - **pas encore commencé** : le lot passe en « annulé » tout de suite, par une mise à jour
     *   conditionnelle — si le traitement le prend au même instant, c'est lui qui gagne, et on
     *   retombe dans le second cas ;
     * - **en cours** : on pose la demande, et le traitement la voit à la centaine de lignes
     *   suivante. Il annule sa transaction et marque lui-même le lot. On ne touche pas la ligne
     *   du lot d'ici : elle est verrouillée par l'import jusqu'à sa fin, voir plus haut.
     */
    public static function demanderLArret(LotImport $lot, int $parUserId, string $parNom): string
    {
        $annule = DB::table('lots_import')
            ->where('id', $lot->id)
            ->where('etat', 'depose')
            ->update([
                'etat' => 'annule',
                'message' => 'Import arrêté par '.$parNom.' avant le début de la lecture : rien n\'a été lu ni écrit.',
                'termine_le' => now(),
                'annule_le' => now(),
                'annule_par' => $parUserId,
                'updated_at' => now(),
            ]) === 1;

        if ($annule) {
            return "Import arrêté avant d'avoir commencé : rien n'a été écrit.";
        }

        $frais = $lot->fresh();

        if ($frais?->etat !== 'en_cours') {
            throw new RuntimeException("Ce traitement est déjà terminé : il n'y a plus rien à arrêter. "
                .'Un import terminé se défait depuis son détail, par « Annuler cet import ».');
        }

        // Une lecture coupée par le serveur ne verra jamais la demande : personne ne tourne plus
        // pour la lire. Sa transaction est morte avec elle, et ses verrous aussi — on peut donc
        // marquer le lot directement.
        if (self::etat($frais)['interrompu']) {
            DB::table('lots_import')->where('id', $lot->id)->where('etat', 'en_cours')->update([
                'etat' => 'annule',
                'message' => 'Import interrompu par le serveur, puis annulé par '.$parNom.' : rien n\'a été écrit.',
                'termine_le' => now(),
                'annule_le' => now(),
                'annule_par' => $parUserId,
                'updated_at' => now(),
            ]);
            self::oublier($lot->id);

            return "Cet import s'était interrompu : il est marqué annulé. Rien n'avait été écrit.";
        }

        Cache::store(self::MAGASIN)->put(
            self::cleArret($lot->id),
            ['par' => $parUserId, 'nom' => $parNom, 'a' => time()],
            self::DUREE,
        );

        return 'Arrêt demandé. La lecture s\'interrompt dans un instant, et tout ce qu\'elle avait '
            .'commencé d\'écrire est défait : la base revient à son état d\'avant le dépôt.';
    }

    /** Le traitement est fini, d'une façon ou d'une autre : on efface ce qu'il laissait derrière lui. */
    public static function oublier(int $lotId): void
    {
        Cache::store(self::MAGASIN)->forget(self::cleAvancee($lotId));
        Cache::store(self::MAGASIN)->forget(self::cleArret($lotId));
    }

    private static function cleAvancee(int $lotId): string
    {
        return 'import.avancee.'.$lotId;
    }

    private static function cleArret(int $lotId): string
    {
        return 'import.arret.'.$lotId;
    }
}
