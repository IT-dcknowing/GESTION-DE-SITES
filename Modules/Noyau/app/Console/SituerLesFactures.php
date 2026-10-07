<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 * **Base réelle** : sans `--appliquer`, elle compte et n'écrit rien (règle 1). Elle n'écrit pas
 * par le modèle : une facture située n'a pas été « modifiée aujourd'hui ». Elle ne s'inscrit pas
 * dans `app:deployer`.
 */
class SituerLesFactures extends Command
{
    protected $signature = 'factures:situer
        {--appliquer : écrit réellement}
        {--entreprise= : ne traiter qu-une entreprise, par son identifiant}';

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
        }

        return self::SUCCESS;
    }

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

        $compte = ['sans_lieu' => 0, 'fiche' => 0, 'devis' => 0, 'code' => 0, 'restent' => 0, 'activite' => 0, 'sans_fiche' => 0];

        $factures = DB::table('factures')->where('entreprise_id', $entrepriseId)
            ->get(['id', 'site_id', 'ville_id', 'reference_devis', 'activite']);

        foreach ($factures as $f) {
            $fiche = trim((string) $f->reference_devis);
            $changements = [];

            if ($f->site_id === null && $f->ville_id === null) {
                $compte['sans_lieu']++;
                [$site, $ville, $source] = $this->lieu($fiche, $fiches, $devis, $codes, $villeDuSite);

                if ($source === null) {
                    $compte['restent']++;
                    $compte['sans_fiche'] += $fiche === '' ? 1 : 0;
                } else {
                    $compte[$source]++;
                    $changements = array_filter(['site_id' => $site, 'ville_id' => $ville], fn ($v) => $v !== null);
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
            ['… restant sans lieu', number_format($compte['restent'], 0, ',', ' ')],
            ['    dont sans n° de fiche (état des impayés)', number_format($compte['sans_fiche'], 0, ',', ' ')],
            ["Activité corrigée{$verbe} d'après le devis", number_format($compte['activite'], 0, ',', ' ')],
        ];
    }

    /** @return array{0: ?int, 1: ?int, 2: ?string} atelier, ville, source */
    private function lieu(string $fiche, $fiches, $devis, $codes, $villeDuSite): array
    {
        if ($fiche === '') {
            return [null, null, null];
        }

        $f = $fiches[$fiche] ?? null;

        if ($f !== null && ($f->site_id !== null || $f->ville_id !== null)) {
            $site = $f->site_id !== null ? (int) $f->site_id : null;

            return [$site, $f->ville_id !== null ? (int) $f->ville_id : ($site ? (int) $villeDuSite[$site] : null), 'fiche'];
        }

        $d = $devis[$fiche] ?? null;

        if ($d !== null && $d->site_id !== null) {
            return [(int) $d->site_id, (int) ($villeDuSite[$d->site_id] ?? 0) ?: null, 'devis'];
        }

        $code = CodeAgent::extraire($fiche);
        $c = $code !== null ? ($codes[$code] ?? null) : null;

        if ($c !== null) {
            $site = $c->site_id !== null ? (int) $c->site_id : null;
            $ville = $c->ville_id !== null ? (int) $c->ville_id : ($site ? (int) $villeDuSite[$site] : null);

            return [$site, $ville, 'code'];
        }

        return [null, null, null];
    }
}
