<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ce que l'entreprise sait d'un fournisseur, indépendamment de ses factures.
 *
 * La feuille « Liste fournisseurs » des deux classeurs de suivi porte, pour chaque nom, le
 * terme auquel il se règle, s'il facture la TVA, et parfois le plafond d'encours négocié.
 * C'est un **référentiel** : il décrit le fournisseur, pas une opération. Redéposer le
 * classeur met donc la fiche à jour au lieu d'en créer une seconde, comme pour la balance.
 *
 * **Le libellé est roi, l'interprétation l'accompagne.** `delai_reglement` garde la phrase
 * du fichier ; `jours_reglement` et `fin_de_mois` en sont la lecture, et restent nuls
 * quand on ne sait pas lire. Les quatre formes rencontrées sont « Comptant » (0 jour),
 * « 30 jours », « 45 jours » et « 30 jours fin de mois ». Une cinquième arrivera peut-être :
 * elle sera affichée telle quelle et n'aura pas d'échéance déduite, ce qui est la bonne
 * réponse tant que personne n'a dit ce qu'elle veut dire.
 *
 * **Ce que la fiche ne fait jamais**, c'est écrire sur une facture. L'échéance déduite
 * {@see echeancePour()} se calcule à l'affichage et ne s'enregistre nulle part. La ranger
 * dans `date_echeance` la rendrait indiscernable de celles que le fichier annonce vraiment,
 * et un terme renégocié demain laisserait derrière lui des dates fausses que plus rien ne
 * signalerait.
 */
#[Fillable([
    'entreprise_id', 'lot_import_id', 'nom', 'nom_normalise',
    'delai_reglement', 'jours_reglement', 'fin_de_mois', 'assujetti_tva',
    'note', 'source_feuille',
])]
class FournisseurReferentiel extends Model
{
    use AppartientAUneEntreprise;
    use LogsActivity;

    protected $table = 'referentiel_fournisseurs';

    /**
     * Ce que le journal retient d'une fiche corrigée à la main.
     *
     * **La fiche est corrigeable depuis le 24/09**, et c'est précisément pour cela qu'elle
     * se journalise. Jusque-là elle ne venait que du classeur : la corriger à l'écran
     * crée une seconde source, et une seconde source sans trace est un chiffre dont plus
     * personne ne sait d'où il sort. Le nom normalisé n'y figure pas — il se déduit du nom
     * et n'est jamais saisi.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'nom', 'delai_reglement', 'jours_reglement', 'fin_de_mois',
                'assujetti_tva', 'note',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Ce que le journal garde en plus du « qui » et du « quoi » : d'où, et sur quel écran.
     *
     * Le propriétaire l'a demandé en ouvrant la correction à la main : savoir qui a
     * changé quoi ne suffit pas s'il faut ensuite demander à la personne d'où elle l'a
     * fait. Le « poste » est ce que le navigateur déclare de lui-même — un serveur ne
     * connaît pas le nom de la machine qui l'appelle — et l'écran vient du référent HTTP.
     * L'heure, elle, est celle de la trace.
     */
    public function tapActivity(Activity $trace, string $evenement): void
    {
        $requete = request();

        $trace->properties = $trace->properties->merge([
            'ip' => $requete?->ip(),
            'poste' => mb_substr((string) $requete?->userAgent(), 0, 255) ?: null,
            'ecran' => mb_substr((string) $requete?->headers->get('referer', ''), 0, 255) ?: null,
        ]);
    }

    /**
     * Corrige une fiche à la main, et laisse la trace de ce geste.
     *
     * **Le nom d'une fiche venue d'un classeur ne se corrige pas ici.** C'est la clé qui
     * relie la fiche à ses factures : la changer orpheline la fiche, et le prochain dépôt
     * en recréerait une sous l'ancien nom — deux fiches pour un fournisseur, dont une que
     * plus rien n'alimente. C'est la même règle que les quatre champs verrouillés d'une
     * pièce fournisseur importée, et pour la même raison. La correction du nom se fait
     * dans le classeur, qui la reposera au dépôt suivant.
     *
     * **Les jours et le « fin de mois » ne se saisissent pas davantage** : ils sont la
     * lecture du libellé, relue ici comme à l'import. Les laisser saisir permettrait
     * d'écrire « Comptant » et « 30 jours » sur la même fiche.
     *
     * @param  array{delai_reglement: string|null, assujetti_tva: bool|null, note: string|null, nom?: string|null}  $valeurs
     */
    public function corriger(array $valeurs): self
    {
        $libelle = trim((string) ($valeurs['delai_reglement'] ?? '')) ?: null;
        $terme = self::lireLeTerme($libelle);

        // Le nom n'est modifiable que sur une fiche saisie à la main : aucun fichier ne
        // viendra la revendiquer, donc rien ne se désappariera.
        if ($this->lot_import_id === null && array_key_exists('nom', $valeurs)) {
            $nom = trim((string) $valeurs['nom']);

            if ($nom !== '') {
                $this->nom = $nom;
                $this->nom_normalise = self::clePour($nom);
            }
        }

        $this->delai_reglement = $libelle;
        $this->jours_reglement = $terme['jours'];
        $this->fin_de_mois = $terme['finDeMois'];
        $this->assujetti_tva = $valeurs['assujetti_tva'];
        $this->note = trim((string) ($valeurs['note'] ?? '')) ?: null;

        $this->save();

        return $this;
    }

    /** Les champs qu'une fiche venue d'un classeur ne laisse pas corriger ici. */
    public function champsVerrouilles(): array
    {
        return $this->lot_import_id === null ? [] : ['nom'];
    }

    protected function casts(): array
    {
        return [
            'jours_reglement' => 'integer',
            'fin_de_mois' => 'boolean',
            'assujetti_tva' => 'boolean',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }

    /** La clé de rapprochement : le nom, débarrassé de sa casse, de ses accents et de ses apostrophes. */
    public static function clePour(?string $nom): string
    {
        return CorrespondanceImport::normaliser((string) $nom);
    }

    /**
     * Pose la fiche d'un fournisseur, ou met à jour celle qui y est déjà.
     *
     * **Une valeur vide n'efface jamais une valeur déclarée.** Les deux classeurs ne se
     * contredisent que huit fois sur 211 noms communs, et sept de ces huit fois c'est
     * l'un des deux qui ne dit rien — la TVA d'EDF, celle de SNPC. Écraser au dernier
     * déposé perdrait donc, à chaque fois, la seule des deux feuilles qui savait.
     *
     * La huitième divergence est une vraie contradiction (THELEN, assujetti à Abidjan et
     * non à San-Pédro) : là, le dernier dépôt l'emporte, faute de savoir lequel a raison.
     * C'est visible à l'écran, qui nomme le classeur d'où vient la fiche.
     *
     * **Le terme fait exception, et d'un seul tenant.** `jours_reglement` et `fin_de_mois`
     * ne sont pas des informations du fichier : ce sont la lecture de `delai_reglement`.
     * Dès qu'un libellé arrive, les trois se posent ensemble, faux et nuls compris — sans
     * quoi un fournisseur passé de « 30 jours fin de mois » à « 30 jours » garderait pour
     * toujours une échéance calculée depuis la fin du mois.
     */
    public static function consigner(int $entrepriseId, string $nom, array $valeurs): self
    {
        $termeDeclare = trim((string) ($valeurs['delai_reglement'] ?? '')) !== '';
        $cle = self::clePour($nom);

        $fiche = self::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('nom_normalise', $cle)
            ->first()
            ?? self::withoutGlobalScopes()->make([
                'entreprise_id' => $entrepriseId,
                'nom' => $nom,
                'nom_normalise' => $cle,
            ]);

        foreach ($valeurs as $champ => $valeur) {
            if ($champ === 'jours_reglement' || $champ === 'fin_de_mois') {
                if ($termeDeclare) {
                    $fiche->{$champ} = $champ === 'fin_de_mois' ? (bool) $valeur : $valeur;
                }

                continue;
            }

            if ($valeur === null || $valeur === '') {
                continue;
            }

            $fiche->{$champ} = $valeur;
        }

        $fiche->save();

        return $fiche;
    }

    /**
     * Les quatre termes du fichier, lus : le nombre de jours, et s'il court de fin de mois.
     *
     * @return array{jours: int|null, finDeMois: bool}
     */
    public static function lireLeTerme(?string $libelle): array
    {
        $texte = CorrespondanceImport::normaliser((string) $libelle);

        if ($texte === '') {
            return ['jours' => null, 'finDeMois' => false];
        }

        if ($texte === 'COMPTANT') {
            return ['jours' => 0, 'finDeMois' => false];
        }

        // « 30 JOURS », « 45 JOURS », « 30 JOURS FIN DE MOIS ». Le nombre est pris en tête :
        // une phrase qui n'en porte pas n'est pas devinée.
        if (preg_match('/^(\d{1,3})\s+JOURS?(.*)$/', $texte, $trouve) !== 1) {
            return ['jours' => null, 'finDeMois' => false];
        }

        $reste = trim($trouve[2]);

        // Tout ce qui suit « 30 jours » et qu'on ne reconnaît pas fait renoncer : « 30 jours
        // après réception de la facture » ne se compte pas depuis la date de facture, et
        // faire comme si donnerait une date fausse sans le dire.
        if ($reste !== '' && $reste !== 'FIN DE MOIS') {
            return ['jours' => null, 'finDeMois' => false];
        }

        return ['jours' => (int) $trouve[1], 'finDeMois' => $reste === 'FIN DE MOIS'];
    }

    /**
     * Pour quand cette facture est due, d'après le terme du fournisseur — ou null.
     *
     * « Fin de mois » se compte comme le commerce le compte : la facture court jusqu'au
     * dernier jour de son mois, et le délai part de là. Une facture du 3 avril à trente
     * jours fin de mois est due le 30 mai, pas le 3.
     */
    public function echeancePour(?Carbon $dateFacture): ?Carbon
    {
        if ($dateFacture === null || $this->jours_reglement === null) {
            return null;
        }

        $depart = $this->fin_de_mois
            ? $dateFacture->copy()->endOfMonth()
            : $dateFacture->copy();

        return $depart->startOfDay()->addDays($this->jours_reglement);
    }
}
