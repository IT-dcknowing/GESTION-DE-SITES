<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Exploitation\Services\VilleParLeNumeroDeFiche;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Situe les factures restées « Lieu non précisé », et corrige leur activité par le devis.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **La question du propriétaire, le 07/10**, devant la synthèse par site : « pourquoi a-t-on
 * "Lieu non précisé" ? Normalement on a un code pour chaque facture. » Deux raisons, lues dans
 * le code des imports :
 *
 * - **une facture importée avant que son code soit rattaché à une ville le reste** : l'import
 *   la situe au moment du dépôt, par la colonne SITE ou par le code à deux lettres de sa fiche
 *   (« FR-KZN° 010669 » → KZ) ; si KZ n'avait pas encore de ville, la facture n'en reçoit pas,
 *   et rien ne la reprend ensuite ;
 * - **une facture venue de l'état des impayés n'a pas de fiche** : 42 % de ses lignes n'ont pas
 *   de SITE, et aucune ne porte de code. Elle ne se situe que lorsque le CATTC la reconnaît
 *   (même date, montant, plaque — voir `PontDesFactures`) et lui apporte sa fiche.
 *
 * **Ce que fait cette commande**, pour les factures sans atelier ni ville, par ordre de
 * certitude : (1) la **fiche du parc** au même numéro (`dossiers_vehicules`), quand elle n'est
 * pas elle-même présumée ; (2) le **devis** à la même fiche (`devis.n_fiche_reception`), qui a
 * son atelier ; (3) le **code à deux lettres** de la fiche, quand l'écran des codes lui a donné
 * une ville.
 *
 * **Et l'activité**, demandée le même jour : « remonte jusqu'au devis par le n° de fiche de
 * réception, puisque chaque devis dit s'il est Sinistre ou Mécanique ». L'import la lisait sur
 * le motif de la fiche, et retombait sur « Mécanique » quand il ne la trouvait pas. Le devis
 * de la même fiche prime désormais.
 *
 * **Le numéro de la fiche — 24/11.** Le propriétaire : « parfois les deux lettres sautaient » —
 * `FR-N° 006165` n'a pas de code, et la facture restait sans ville. Quand ni le parc ni le devis
 * ne portent la fiche **écrite à l'identique**, on la cherche par son **numéro seul**
 * (`VilleParLeNumeroDeFiche`) : la fiche de réception qui porte 006165 a ses lettres, son
 * atelier ou sa ville. Le numéro ne vient qu'**à défaut du code** ; si les deux répondent et se
 * contredisent, la facture **n'est pas située** et la commande la nomme (`--details`) ; si
 * plusieurs fiches de villes différentes portent le numéro, elle ne choisit pas. Les factures
 * déjà situées ne sont jamais déplacées : celles que leur numéro place ailleurs sont comptées,
 * pas réécrites.
 *
 * **Base réelle** : sans `--appliquer`, elle compte et n'écrit rien (règle 1). Elle n'écrit pas
 * par le modèle : une facture située n'a pas été « modifiée aujourd'hui ». Elle ne s'inscrit pas
 * dans `app:deployer`.
 */
class SituerLesFactures extends Command
{
    protected $signature = 'factures:situer
        {--appliquer : écrit réellement}
        {--entreprise= : ne traiter qu-une entreprise, par son identifiant}
        {--details : nomme les factures en conflit, au numéro ambigu ou contredites}';

    protected $description = 'Situe les factures sans lieu par leur fiche, leur devis ou leur code, et prend leur activité au devis (constat par défaut)';

    public function handle(): int
    {
        $appliquer = (bool) $this->option('appliquer');

        if (! $appliquer) {
            $this->warn('Mode constat : rien ne sera écrit. Ajoutez --appliquer pour enregistrer.');
        }

        $entreprises = DB::table('entreprises')
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('id', (int) $id))
            ->orderBy('id')->pluck('nom', 'id');

        foreach ($entreprises as $id => $nom) {
            $this->info("— {$nom}");
            $this->table(['Constat', 'Nombre'], $this->traiter((int) $id, $appliquer));

            if ($this->option('details') && $this->aSignaler !== []) {
                $this->table(['Facture', 'Fiche', 'Constat', 'Par le code', 'Par le numéro', 'Ville en base'], $this->aSignaler);
            }
        }

        return self::SUCCESS;
    }

    /** @var list<list<string>> les factures que la commande nomme sans les écrire */
    private array $aSignaler = [];

    /** @var array<int, string> ville_id => nom, pour le détail */
    private array $nomsDesVilles = [];

    /** @return list<array{0: string, 1: string}> */
    private function traiter(int $entrepriseId, bool $appliquer): array
    {
        $villeDuSite = DB::table('sites')->where('entreprise_id', $entrepriseId)->pluck('ville_id', 'id');

        $fiches = DB::table('dossiers_vehicules')->where('entreprise_id', $entrepriseId)
            ->whereNotNull('numero_fiche')
            ->where(fn ($q) => $q->where('rattachement_presume', false)->orWhereNull('rattachement_presume'))
            ->get(['numero_fiche', 'site_id', 'ville_id'])->keyBy('numero_fiche');

        $devis = DB::table('devis')->where('entreprise_id', $entrepriseId)
            ->whereNotNull('n_fiche_reception')
            ->orderBy('id')
            ->get(['n_fiche_reception', 'site_id', 'activite'])->keyBy('n_fiche_reception');

        $codes = DB::table('codes_agents')->where('entreprise_id', $entrepriseId)
            ->where(fn ($q) => $q->whereNotNull('ville_id')->orWhereNotNull('site_id'))
            ->get(['code', 'ville_id', 'site_id'])->keyBy(fn ($c) => mb_strtoupper($c->code));

        $parLeNumero = new VilleParLeNumeroDeFiche($entrepriseId);
        $this->nomsDesVilles = DB::table('villes')->where('entreprise_id', $entrepriseId)->pluck('nom', 'id')->all();
        $this->aSignaler = [];

        $compte = ['sans_lieu' => 0, 'fiche' => 0, 'devis' => 0, 'code' => 0, 'numero' => 0, 'restent' => 0, 'activite' => 0, 'sans_fiche' => 0,
            'ville_seule' => 0, 'atelier_trouve' => 0, 'conflit' => 0, 'ambigu' => 0, 'contredite' => 0];

        $factures = DB::table('factures')->where('entreprise_id', $entrepriseId)
            ->get(['id', 'n_facture', 'site_id', 'ville_id', 'reference_devis', 'activite']);

        foreach ($factures as $f) {
            $fiche = trim((string) $f->reference_devis);
            $changements = [];

            if ($f->site_id === null && $f->ville_id === null) {
                $compte['sans_lieu']++;
                [$site, $ville, $source, $signal] = $this->lieu($fiche, $fiches, $devis, $codes, $villeDuSite, $parLeNumero);

                if ($signal !== null) {
                    $compte[$signal['constat']]++;
                    $this->signaler($f, $fiche, $signal);
                }

                if ($source === null) {
                    $compte['restent']++;
                    $compte['sans_fiche'] += $fiche === '' ? 1 : 0;
                } else {
                    $compte[$source]++;
                    $changements = array_filter(['site_id' => $site, 'ville_id' => $ville], fn ($v) => $v !== null);
                }
            }

            /*
             * **La ville sans atelier — 12/10.** « Abidjan — atelier non précisé » portait
             * 1,44 milliard sur l'accueil du gérant : la ville est connue, pas l'atelier. La
             * fiche le dit souvent (parc, sinon devis). On ne prend l'atelier que **dans la
             * même ville** : une fiche ne déplace jamais une facture d'une ville à l'autre.
             */
            if ($f->site_id === null && $f->ville_id !== null && $fiche !== '') {
                $compte['ville_seule']++;
                [$site] = $this->lieu($fiche, $fiches, $devis, $codes, $villeDuSite, $parLeNumero);

                if ($site !== null && (int) ($villeDuSite[$site] ?? 0) === (int) $f->ville_id) {
                    $compte['atelier_trouve']++;
                    $changements['site_id'] = $site;
                }
            }

            /*
             * **Une facture déjà située que son numéro place ailleurs — 24/11.** Comptée et
             * nommée, jamais déplacée : la ville en base vient de la colonne SITE, du code ou de
             * la fiche, et ce désaccord-là demande un regard, pas une règle.
             */
            if ($f->ville_id !== null && $fiche !== '') {
                $n = $parLeNumero->resoudre($fiche);

                if ($n['statut'] === VilleParLeNumeroDeFiche::TROUVEE && $n['ville_id'] !== (int) $f->ville_id) {
                    $compte['contredite']++;
                    $this->signaler($f, $fiche, ['constat' => 'contredite', 'code' => null, 'numero' => [$n['ville_id']]]);
                }
            }

            $activite = $fiche !== '' ? ($devis[$fiche]->activite ?? null) : null;

            if (in_array($activite, ['Mécanique', 'Sinistre'], true) && $activite !== $f->activite) {
                $compte['activite']++;
                $changements['activite'] = $activite;
            }

            if ($appliquer && $changements !== []) {
                DB::table('factures')->where('id', $f->id)->update($changements);
            }
        }

        $verbe = $appliquer ? '' : ' (seraient)';

        return [
            ['Factures sans atelier ni ville', number_format($compte['sans_lieu'], 0, ',', ' ')],
            ["… situées{$verbe} par leur fiche du parc", number_format($compte['fiche'], 0, ',', ' ')],
            ["… situées{$verbe} par leur devis", number_format($compte['devis'], 0, ',', ' ')],
            ["… situées{$verbe} par le code de leur fiche", number_format($compte['code'], 0, ',', ' ')],
            ["… situées{$verbe} par le numéro de leur fiche (code absent ou sans ville)", number_format($compte['numero'], 0, ',', ' ')],
            ['… restant sans lieu', montant_nombre($compte['restent'])],
            ['    dont code et numéro en conflit (non situées, --details)', number_format($compte['conflit'], 0, ',', ' ')],
            ['    dont numéro porté par des fiches de plusieurs villes', number_format($compte['ambigu'], 0, ',', ' ')],
            ['    dont sans n° de fiche (état des impayés)', number_format($compte['sans_fiche'], 0, ',', ' ')],
            ['Factures avec ville mais sans atelier, et une fiche', number_format($compte['ville_seule'], 0, ',', ' ')],
            ["… atelier trouvé{$verbe} dans la même ville", number_format($compte['atelier_trouve'], 0, ',', ' ')],
            ["Activité corrigée{$verbe} d'après le devis", number_format($compte['activite'], 0, ',', ' ')],
            ['Factures situées que leur numéro de fiche place ailleurs (non modifiées)', number_format($compte['contredite'], 0, ',', ' ')],
        ];
    }

    /**
     * Le lieu d'une facture, par ordre de certitude, et ce qu'il faut en dire.
     *
     * 1. la fiche du parc écrite à l'identique ; 2. le devis écrit à l'identique ; 3. le code à
     * deux lettres ; 4. le numéro seul — à défaut du code, jamais contre lui.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string, 3: ?array} atelier, ville, source, signalement
     */
    private function lieu(string $fiche, $fiches, $devis, $codes, $villeDuSite, VilleParLeNumeroDeFiche $parLeNumero): array
    {
        if ($fiche === '') {
            return [null, null, null, null];
        }

        $f = $fiches[$fiche] ?? null;

        if ($f !== null && ($f->site_id !== null || $f->ville_id !== null)) {
            $site = $f->site_id !== null ? (int) $f->site_id : null;

            return [$site, $f->ville_id !== null ? (int) $f->ville_id : ($site ? (int) $villeDuSite[$site] : null), 'fiche', null];
        }

        $d = $devis[$fiche] ?? null;

        if ($d !== null && $d->site_id !== null) {
            return [(int) $d->site_id, (int) ($villeDuSite[$d->site_id] ?? 0) ?: null, 'devis', null];
        }

        $code = CodeAgent::extraire($fiche);
        $c = $code !== null ? ($codes[$code] ?? null) : null;
        $parLeCode = null;

        if ($c !== null) {
            $site = $c->site_id !== null ? (int) $c->site_id : null;
            $ville = $c->ville_id !== null ? (int) $c->ville_id : ($site ? (int) $villeDuSite[$site] : null);
            $parLeCode = $ville !== null ? [$site, $ville] : null;
        }

        $n = $parLeNumero->resoudre($fiche);

        if ($n['statut'] === VilleParLeNumeroDeFiche::TROUVEE) {
            // Deux méthodes, deux villes : l'une des deux se trompe, et rien ne dit laquelle.
            if ($parLeCode !== null && $parLeCode[1] !== $n['ville_id']) {
                return [null, null, null, ['constat' => 'conflit', 'code' => $parLeCode[1], 'numero' => [$n['ville_id']]]];
            }

            if ($parLeCode !== null) {
                // D'accord : le code reste la source, le numéro peut prêter son atelier.
                return [$parLeCode[0] ?? $n['site_id'], $parLeCode[1], 'code', null];
            }

            return [$n['site_id'], $n['ville_id'], 'numero', null];
        }

        if ($parLeCode !== null) {
            return [$parLeCode[0], $parLeCode[1], 'code', null];
        }

        if ($n['statut'] === VilleParLeNumeroDeFiche::AMBIGUE) {
            return [null, null, null, ['constat' => 'ambigu', 'code' => null, 'numero' => $n['villes']]];
        }

        return [null, null, null, null];
    }

    /** Une ligne du détail (`--details`) : de quoi retrouver la facture et voir les deux réponses. */
    private function signaler(object $facture, string $fiche, array $signal): void
    {
        $nom = fn (?int $id) => $id === null ? '—' : ($this->nomsDesVilles[$id] ?? "ville {$id}");

        $this->aSignaler[] = [
            (string) ($facture->n_facture ?? $facture->id),
            $fiche,
            match ($signal['constat']) {
                'conflit' => 'code et numéro en conflit',
                'ambigu' => 'numéro de plusieurs villes',
                default => 'ville en base ≠ numéro',
            },
            $nom($signal['code']),
            implode(' / ', array_map($nom, $signal['numero'])),
            $nom($facture->ville_id !== null ? (int) $facture->ville_id : null),
        ];
    }
}
