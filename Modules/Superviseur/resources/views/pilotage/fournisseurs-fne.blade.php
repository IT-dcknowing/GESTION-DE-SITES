<?php

use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\ReleveFne;
use Modules\Noyau\Imports\Services\AchatsFne;
use Modules\Noyau\Imports\Services\LanceurDeReleveFne;

use function Livewire\Volt\{computed, mount, protect, state, usesFileUploads};

usesFileUploads();

/*
|--------------------------------------------------------------------------
| Les factures d'achat FNE — 12/10
|--------------------------------------------------------------------------
| « La récupération FNE prend toutes les pièces émises par un tiers sur la plateforme, avoirs
| compris — elle doit mettre chacune dans son exercice. »
|
| Le relevé vient du scraper `SCRAPER-PORTAIL-FNE/achats.js` (repris de Selflow) : déposé ici,
| ou rangé par `php artisan fne:releve-achats`. Chaque facture est **rapprochée** du suivi
| fournisseurs quand une ligne la confirme — dans les deux sens : le classeur peut venir avant
| ou après — ; sinon elle attend d'être **portée au suivi**, comme une créance à l'état des
| impayés. Rien ne se porte d'office : une dette créée parce qu'un fichier est arrivé
| doublonnerait la ligne que le classeur apportera.
|
| Les **différences** se lisent dans les deux sens : FNE sans ligne au suivi, et lignes du suivi
| sans FNE — ces dernières sont des achats non normalisés, ou pas encore relevés.
*/

state(['exercice' => null, 'filtre' => 'tous', 'page' => 1, 'fichier' => null, 'bilan' => null]);

// Le bouton « Actualiser » : l'instant du lancement (Unix) tant que le relevé est attendu, sinon null.
state(['attenteDepuis' => null, 'messageActualisation' => null]);

mount(function () {
    $this->exercice = (int) (FactureFne::query()->max('exercice') ?: now()->year);
});

$updatedExercice = function () { $this->page = 1; };
$updatedFiltre = function () { $this->page = 1; };

$peutEcrire = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

$exercices = computed(function () {
    $annees = FactureFne::query()->whereNotNull('exercice')->distinct()->orderByDesc('exercice')->pluck('exercice')
        ->push(now()->year)->unique()->sortDesc()->values();

    return $annees->mapWithKeys(fn ($a) => [$a => (string) $a])->all();
});

$base = protect(fn () => FactureFne::query()->where('exercice', (int) $this->exercice));

$kpi = computed(function () {
    $toutes = $this->base()->selectRaw('count(*) as n, sum(case when est_avoir then 1 else 0 end) as avoirs,
        sum(case when facture_fournisseur_id is null then 0 else 1 end) as au_suivi,
        sum(montant_ttc) as ttc, sum(case when facture_fournisseur_id is null then montant_ttc else 0 end) as ttc_a_porter')
        ->first();

    return [
        'n' => (int) $toutes->n,
        'avoirs' => (int) $toutes->avoirs,
        'au_suivi' => (int) $toutes->au_suivi,
        'a_porter' => (int) $toutes->n - (int) $toutes->au_suivi,
        'ttc' => montant_de($toutes->ttc),
        'ttc_a_porter' => montant_de($toutes->ttc_a_porter),
        'suivi_sans_fne' => AchatsFne::suiviSansFne((int) auth()->user()->entreprise_id, (int) $this->exercice)->count(),
    ];
});

/*
 * Paginée en base, et sans la colonne `contenu` (18/10) : 2 728 factures relevées le 16/10, chacune
 * avec sa pièce entière en JSON. Les lire toutes à chaque clic pour en montrer vingt-cinq coûtait
 * plus que l'écran ne rapporte ; le détail se lit sur la page « Détails ».
 */
$filtrees = protect(fn () => $this->base()
    ->when($this->filtre === 'a_porter', fn ($q) => $q->whereNull('facture_fournisseur_id'))
    ->when($this->filtre === 'au_suivi', fn ($q) => $q->whereNotNull('facture_fournisseur_id'))
    ->when($this->filtre === 'avoirs', fn ($q) => $q->where('est_avoir', true)));

$nombreLignes = computed(fn () => $this->filtrees()->count());

$lignes = computed(fn () => $this->filtrees()
    ->select(['id', 'reference', 'est_avoir', 'date_facture', 'emetteur_nom', 'emetteur_ncc', 'montant_ht', 'montant_tva', 'montant_ttc', 'facture_fournisseur_id'])
    ->with('pieceDuSuivi:id,numero_piece,reste_a_payer')
    ->orderByDesc('date_facture')->orderByDesc('id')
    ->forPage($this->page, 25)
    ->get());

$suiviSansFne = computed(fn () => $this->filtre !== 'suivi_sans_fne' ? collect()
    : AchatsFne::suiviSansFne((int) auth()->user()->entreprise_id, (int) $this->exercice)
        ->orderByDesc('date_facture')->limit(500)
        ->get(['id', 'fournisseur', 'numero_piece', 'date_facture', 'montant', 'reste_a_payer']));

$releves = computed(fn () => ReleveFne::query()->latest('id')->limit(5)->get());

$deposer = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $this->validate(['fichier' => ['required', 'file', 'max:51200']], [], ['fichier' => 'relevé']);

    try {
        $this->bilan = AchatsFne::importer(
            (string) file_get_contents($this->fichier->getRealPath()),
            $this->fichier->getClientOriginalName(),
            (int) auth()->user()->entreprise_id,
            auth()->id(),
        );
    } catch (\RuntimeException $e) {
        $this->addError('fichier', $e->getMessage());

        return;
    }

    $this->fichier = null;
    unset($this->kpi, $this->lignes, $this->nombreLignes, $this->releves, $this->exercices);
};

/**
 * « Actualiser » — relève le portail maintenant, sans attendre l'heure pleine.
 *
 * Le script part détaché (un relevé dure des dizaines de secondes) ; la page interroge ensuite
 * toutes les cinq secondes (`verifier`) et range le fichier dès qu'il arrive. Le même verrou que le
 * passage horaire : cliquer dix fois n'ouvre pas dix sessions sur le portail de l'État.
 */
$actualiser = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $this->messageActualisation = null;

    if (! LanceurDeReleveFne::actif()) {
        $this->messageActualisation = "Le relevé n'est pas encore réglé sur ce serveur : il manque le fichier SCRAPER-PORTAIL-FNE/.env "
            ."(adresse du portail, NCC, mot de passe) — voir SCRAPER-PORTAIL-FNE/GUIDE-COMPLET.md. En attendant, un fichier de relevé "
            ."peut se déposer à la main en bas de page.";

        return;
    }

    if (! LanceurDeReleveFne::verrouiller()) {
        $this->messageActualisation = 'Un relevé est déjà en cours : le résultat apparaîtra ici dès son arrivée.';
        $this->attenteDepuis ??= now()->timestamp;

        return;
    }

    // Le premier relevé est complet (depuis 2023) ; les suivants ne reprennent que les derniers jours.
    $complet = ! ReleveFne::query()->exists();
    $ncc = trim((string) auth()->user()->entreprise?->ncc);

    try {
        LanceurDeReleveFne::detacher($complet, $ncc !== '' ? $ncc : null);
    } catch (\RuntimeException $e) {
        LanceurDeReleveFne::deverrouiller();
        $this->messageActualisation = $e->getMessage();

        return;
    }

    $this->attenteDepuis = now()->timestamp;
};

/** Appelée par `wire:poll` : le fichier est-il arrivé ? Le range, ou renonce passé le délai. */
$verifier = function () {
    if ($this->attenteDepuis === null) {
        return;
    }

    $arrives = LanceurDeReleveFne::relevesArrivesDepuis((int) $this->attenteDepuis - 2);

    if ($arrives !== []) {
        $bilan = ['deja' => true, 'factures' => 0, 'nouvelles' => 0, 'avoirs' => 0, 'ignorees' => 0, 'rapprochees' => 0];

        foreach ($arrives as $fichier) {
            $lu = AchatsFne::importer((string) file_get_contents($fichier), basename($fichier), (int) auth()->user()->entreprise_id, auth()->id());
            $bilan = $lu['deja'] ? $bilan : $lu;
        }

        $this->bilan = $bilan;
        $this->attenteDepuis = null;
        LanceurDeReleveFne::deverrouiller();
        unset($this->kpi, $this->lignes, $this->nombreLignes, $this->releves, $this->exercices);

        return;
    }

    if (now()->timestamp - (int) $this->attenteDepuis > (int) config('fne.delai_secondes')) {
        $this->attenteDepuis = null;
        LanceurDeReleveFne::deverrouiller();
        $this->messageActualisation = "Le relevé n'est pas arrivé après ".((int) config('fne.delai_secondes') / 60).' minutes. La cause est dans storage/logs/fne-scraper.log (accès refusés, portail injoignable…).';
    }
};

$rapprocher = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $n = AchatsFne::rapprocher((int) auth()->user()->entreprise_id);
    unset($this->kpi, $this->lignes, $this->nombreLignes);
    $this->dispatch('annonce', texte: $n === 0 ? 'Aucune nouvelle correspondance au suivi.' : "{$n} facture(s) rapprochée(s) du suivi.", ton: 'succes');
};

/**
 * « Porter » — 18/10 : « porter ne veut pas dire réglé ; il doit faire apparaître les informations
 * dans le formulaire du suivi des fournisseurs, sur la page des fournisseurs ». On y va, formulaire
 * ouvert et rempli de la facture ; c'est l'enregistrement là-bas qui crée la ligne et la relie.
 */
$porter = function (int $id) {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $this->redirectRoute('fournisseurs', ['porter' => $id], navigate: true);
};

?>

<div>
    <x-titre-ecran titre="Factures FNE des fournisseurs"
        sous-titre="Les pièces émises par les tiers sur la plateforme, avoirs compris, chacune dans son exercice.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Retour au suivi</a>
            <x-champ label="Exercice" model="exercice" type="select" :options="$this->exercices" :live="true" width="130" />
            @if ($this->peutEcrire)
                {{-- Relève le portail FNE maintenant ; les relevés automatiques passent d'eux-mêmes
                     toutes les heures aux heures ouvrées. --}}
                <button type="button" wire:click="actualiser" class="bouton" @disabled($attenteDepuis !== null)>
                    {{ $attenteDepuis !== null ? 'Relevé en cours…' : 'Actualiser depuis la FNE' }}
                </button>
                <button type="button" wire:click="rapprocher" class="bouton bouton-secondaire">Relancer le rapprochement</button>
            @endif
        </div>
    </x-titre-ecran>

    @if ($attenteDepuis !== null)
        <div wire:poll.5s="verifier" class="carte" style="margin-bottom:16px; border-left:3px solid #2563EB;">
            <p style="margin:0; font-size:13px; color:#1E3A8A;">Relevé du portail FNE en cours — la page se met à jour toute seule dès que les factures sont arrivées (une à deux minutes).</p>
        </div>
    @endif
    @if ($messageActualisation)
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #D97706;">
            <p style="margin:0; font-size:13px; color:#92400E;">{{ $messageActualisation }}</p>
        </div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Récupérées de la FNE" :value="number_format($this->kpi['n'], 0, ',', ' ')" :sub="ae($this->kpi['ttc']).' TTC'" />
        <x-kpi-card label="Dont avoirs" :value="number_format($this->kpi['avoirs'], 0, ',', ' ')" sub="Montants négatifs" />
        <x-kpi-card label="Rapprochées du suivi" :value="number_format($this->kpi['au_suivi'], 0, ',', ' ')" couleur="#0E9F6E" />
        {{-- Les deux sens des différences, demandées le 12/10 : ce que la FNE connaît et le
             suivi non, et ce que le suivi porte sans pièce normalisée. --}}
        <x-kpi-card label="FNE absentes du suivi" :value="number_format($this->kpi['a_porter'], 0, ',', ' ')"
            :sub="ae($this->kpi['ttc_a_porter']).' à porter'" :couleur="$this->kpi['a_porter'] > 0 ? '#C8102E' : '#6B6E76'" />
        <x-kpi-card label="Suivi sans FNE" :value="number_format($this->kpi['suivi_sans_fne'], 0, ',', ' ')"
            sub="Non normalisées, ou pas encore relevées" :couleur="$this->kpi['suivi_sans_fne'] > 0 ? '#D97706' : '#6B6E76'" />
    </div>

    <div class="carte" style="margin-bottom:16px; border-left:3px solid {{ \Modules\Noyau\Imports\Services\LanceurDeReleveFne::actif() ? '#0E9F6E' : '#D97706' }};">
        <p style="margin:0; font-size:13px;">
            @if (\Modules\Noyau\Imports\Services\LanceurDeReleveFne::actif())
                <b>Relevé automatique en service</b> : le système va chercher les nouvelles factures sur le portail toutes les heures
                de {{ config('fne.heure_debut') }} à {{ config('fne.heure_fin') }} (lundi → samedi), et tout l'historique à {{ config('fne.heure_complet') }}.
                « Actualiser depuis la FNE » le fait tout de suite, en arrière-plan.
            @else
                <b>Relevé pas encore réglé sur ce serveur</b> : il manque <code>SCRAPER-PORTAIL-FNE/.env</code> (voir le guide). Les factures déjà relevées restent ici.
            @endif
            @if ($this->releves->isNotEmpty())
                <span style="color:#6B6E76;">Dernier relevé : {{ $this->releves->first()->created_at?->format('d/m/Y H:i') }} ({{ $this->releves->first()->factures }} factures).</span>
            @endif
        </p>
    </div>

    @if ($this->peutEcrire)
        {{-- Le dépôt d'un fichier n'est plus le chemin : le relevé se fait seul (18/10). Gardé replié,
             en secours — un relevé fait sur un PC quand le serveur ne peut pas lancer le navigateur. --}}
        <details class="carte" style="margin-bottom:16px;">
            <summary style="cursor:pointer; font-size:13px; font-weight:700; color:#6B6E76;">Secours : déposer à la main un fichier de relevé</summary>
            <h3 style="font-size:15px; font-weight:700; margin:10px 0 6px;">Déposer un relevé</h3>
            <p style="margin:0 0 10px; font-size:12.5px; color:#6B6E76;">
                Le fichier JSON qu'écrit le scraper (<code>node achats.js</code>). Un relevé déjà lu n'entre pas deux fois ;
                une facture déjà connue est mise à jour sans perdre son lien au suivi.
            </p>
            <form wire:submit="deposer" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <input type="file" wire:model="fichier" accept=".json,application/json">
                <button type="submit" class="bouton" wire:loading.attr="disabled">Lire le relevé</button>
            </form>
            @error('fichier') <p style="margin:8px 0 0; color:#C8102E; font-size:12.5px;">{{ $message }}</p> @enderror
            @if ($bilan)
                <p style="margin:10px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">
                    @if ($bilan['deja'])
                        Ce relevé a déjà été lu : rien n'a changé.
                    @else
                        {{ $bilan['factures'] }} factures lues ({{ $bilan['nouvelles'] }} nouvelles, dont {{ $bilan['avoirs'] }} avoirs),
                        {{ $bilan['rapprochees'] }} rapprochées du suivi{{ $bilan['ignorees'] ? ', '.$bilan['ignorees'].' sans référence ignorées' : '' }}.
                    @endif
                </p>
            @endif
            @if ($this->releves->isNotEmpty())
                <p style="margin:10px 0 0; font-size:12px; color:#9A9DA5;">
                    Derniers relevés :
                    @foreach ($this->releves as $r)
                        {{ $r->fichier }} ({{ $r->created_at?->format('d/m/Y') }}, {{ $r->factures }} factures){{ $loop->last ? '' : ' · ' }}
                    @endforeach
                </p>
            @endif
        </details>
    @endif

    <div class="carte">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
            <x-champ label="Afficher" model="filtre" type="select" :live="true" width="240" :options="[
                'tous' => 'Toutes les factures FNE',
                'a_porter' => 'FNE absentes du suivi',
                'au_suivi' => 'Rapprochées du suivi',
                'avoirs' => 'Avoirs',
                'suivi_sans_fne' => 'Lignes du suivi sans FNE',
            ]" />
        </div>

        @if ($filtre === 'suivi_sans_fne')
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Fournisseur</th><th>N° de pièce</th><th>Date</th><th style="text-align:right;">Montant</th><th style="text-align:right;">Reste</th></tr></thead>
                    <tbody>
                        @forelse ($this->suiviSansFne->forPage($page, 25) as $l)
                            <tr>
                                <td><a href="{{ route('fournisseurs.piece', $l->id) }}" wire:navigate>{{ $l->fournisseur }}</a></td>
                                <td>{{ $l->numero_piece }}</td>
                                <td>{{ $l->date_facture?->format('d/m/Y') }}</td>
                                <td style="text-align:right;">{{ ae(montant_de($l->montant)) }}</td>
                                <td style="text-align:right;">{{ ae(montant_de($l->reste_a_payer)) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Chaque ligne du suivi de cet exercice a sa facture FNE." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$page" :total="$this->suiviSansFne->count()" prop="page" :par-page="25" />
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>N° FNE</th><th>Date</th><th>Émetteur</th><th style="text-align:right;">HT</th>
                            <th style="text-align:right;">TVA</th><th style="text-align:right;">TTC</th><th>Au suivi</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes as $f)
                            <tr wire:key="fne-{{ $f->id }}">
                                <td>{{ $f->reference }} @if ($f->est_avoir)<span style="color:#C8102E; font-weight:700;">avoir</span>@endif</td>
                                <td>{{ $f->date_facture?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $f->emetteur_nom ?: '—' }}<div style="font-size:11px; color:#9A9DA5;">{{ $f->emetteur_ncc }}</div></td>
                                <td style="text-align:right;">{{ ae(montant_de($f->montant_ht)) }}</td>
                                <td style="text-align:right;">{{ ae(montant_de($f->montant_tva)) }}</td>
                                <td style="text-align:right; font-weight:700;">{{ ae(montant_de($f->montant_ttc)) }}</td>
                                <td>
                                    @if ($f->pieceDuSuivi)
                                        <a href="{{ route('fournisseurs.piece', $f->pieceDuSuivi->id) }}" wire:navigate>{{ $f->pieceDuSuivi->numero_piece }}</a>
                                        <div style="font-size:11px; color:#6B6E76;">reste {{ ae(montant_de($f->pieceDuSuivi->reste_a_payer)) }}</div>
                                    @elseif ($this->peutEcrire)
                                        <a href="{{ route('fournisseurs', ['porter' => $f->id]) }}" wire:navigate class="bouton">Porter au suivi</a>
                                    @else
                                        <span style="color:#C8102E;">absente du suivi</span>
                                    @endif
                                </td>
                                {{-- Le détail de la pièce, ligne à ligne : de quoi passer l'écriture comptable (18/10). --}}
                                <td><a href="{{ route('fournisseurs.fne.detail', $f->id) }}" wire:navigate class="bouton bouton-secondaire" style="padding:5px 10px;">Détails</a></td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="8" texte="Aucune facture FNE pour cet exercice." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$page" :total="$this->nombreLignes" prop="page" :par-page="25" />
        @endif
    </div>
</div>
