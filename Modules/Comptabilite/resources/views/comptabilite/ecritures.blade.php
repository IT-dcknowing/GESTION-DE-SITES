<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Comptabilite\Jobs\GenererEtDeverser;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Comptabilite\Services\GenerateurDEcritures;
use Modules\Noyau\Comptabilite\Services\Montants;
use Modules\Noyau\Comptabilite\Services\PropositionsDImputation;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;

use function Livewire\Volt\{computed, mount, protect, state, updated};

/*
|--------------------------------------------------------------------------
| Écritures comptables — le journal, la TVA à lire, le déversement — 24/11
|--------------------------------------------------------------------------
| « Comptabilité : vas-y, commence et termine. » Les écritures se génèrent depuis les opérations de
| l'application (`GenerateurDEcritures`), en tâche de fond ; celles qui attendent une donnée sont
| « à vérifier » avec leur motif ; les prêtes partent vers COMPTAFLOW par le bouton Déverser.
|
| Tout se décide au serveur : le rôle est revérifié dans chaque action, l'entreprise vient du
| compte connecté, jamais du navigateur ; une TVA lue n'est acceptée que si HT + TVA = TTC exact.
*/

state([
    'annee' => fn () => (int) now()->year,
    'statut' => 'a_verifier',
    'journal' => '',
    'recherche' => '',
    'ouverte' => null,
    'ht' => '',
    'tva' => '',
    // La pagination se pilote par `$set` (`<x-pagination>`), pas par le paginateur de Livewire :
    // c'est la convention de l'application depuis le correctif du 19/11 (« gotoPage »).
    'page' => 1,
]);

updated(['statut' => fn () => $this->page = 1, 'journal' => fn () => $this->page = 1, 'recherche' => fn () => $this->page = 1, 'annee' => fn () => $this->page = 1]);

mount(function () {
    $this->garder();
});

$garder = protect(function () {
    abort_unless(auth()->user()?->hasAnyRole(['gerant', 'caissier']) && auth()->user()->entreprise_id, 403);
});

$entreprise = protect(fn () => (int) auth()->user()->entreprise_id);

$operations = computed(function () {
    return OperationComptable::query()->with('lignes')
        ->whereYear('date_operation', $this->annee)
        ->when($this->statut === 'a_envoyer', fn ($q) => $q->where('statut', 'prete')->whereIn('statut_deversement', ['non_envoyee', 'refusee']))
        ->when($this->statut === 'refusees', fn ($q) => $q->where('statut_deversement', 'refusee'))
        ->when(in_array($this->statut, ['a_verifier', 'prete', 'contrepassee'], true), fn ($q) => $q->where('statut', $this->statut))
        ->when($this->journal !== '', fn ($q) => $q->where('code_journal', $this->journal))
        ->when(trim($this->recherche) !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('reference_document', 'like', '%'.trim($this->recherche).'%')
            ->orWhere('libelle', 'like', '%'.trim($this->recherche).'%')))
        ->orderBy('date_operation')->orderBy('numero_saisie')
        ->paginate(50, ['*'], 'p', max(1, (int) $this->page));
});

$compteurs = computed(fn () => OperationComptable::query()->whereYear('date_operation', $this->annee)->toBase()
    ->selectRaw("sum(case when statut = 'a_verifier' then 1 else 0 end) as a_verifier,
        sum(case when statut = 'prete' and statut_deversement in ('non_envoyee','refusee') then 1 else 0 end) as a_envoyer,
        sum(case when statut_deversement = 'acceptee' then 1 else 0 end) as acceptees,
        sum(case when statut_deversement = 'refusee' then 1 else 0 end) as refusees")->first());

$journaux = computed(fn () => OperationComptable::query()->whereYear('date_operation', $this->annee)
    ->distinct()->orderBy('code_journal')->pluck('code_journal')->all());

$bilan = computed(fn () => Cache::get(GenererEtDeverser::cleDuBilan($this->entreprise())));

$generer = function () {
    $this->garder();
    if (! GenerateurDEcritures::disponible()) {
        $this->dispatch('annonce', texte: "La mise à jour du serveur n'est pas encore passée (migration du 24/11).");

        return;
    }
    $annee = max(2000, min(2100, (int) $this->annee));
    GenererEtDeverser::dispatch($this->entreprise(), $annee.'-01-01', $annee.'-12-31', false);
    $this->dispatch('annonce', ton: 'succes', texte: 'Génération de '.$annee.' lancée : le bilan s’affiche à son retour.');
};

$deverser = function () {
    $this->garder();
    abort_unless(auth()->user()->hasRole('gerant'), 403, 'Le déversement vers COMPTAFLOW est réservé au gérant.');
    GenererEtDeverser::dispatch($this->entreprise(), null, null, true);
    $this->dispatch('annonce', ton: 'succes', texte: 'Déversement vers COMPTAFLOW lancé.');
};

/** Les propositions de l'IA en attente de décision, avec l'opération qu'elles touchent. */
$propositions = computed(fn () => PropositionsDImputation::disponible()
    ? DB::table('imputations_comptables as i')
        ->join('operations_comptables as o', fn ($j) => $j->on('o.source', '=', 'i.source')->on('o.source_id', '=', 'i.source_id')->on('o.entreprise_id', '=', 'i.entreprise_id'))
        ->where('i.entreprise_id', $this->entreprise())->where('i.statut', 'proposee')->where('o.statut', '!=', 'contrepassee')
        ->orderBy('o.date_operation')->limit(50)
        ->get(['i.id', 'i.cle_compte', 'i.confiance', 'i.raison', 'o.date_operation', 'o.libelle', 'o.total_debit'])
    : collect());

$natures = computed(fn () => PropositionsDImputation::disponible() ? (new PropositionsDImputation($this->entreprise()))->natures() : []);

/** L'IA propose une nature pour les charges restées « Autres » — elle n'écrit rien. */
$proposerParIA = function () {
    $this->garder();
    // L'usage de l'IA se donne depuis le Super admin (25/11) ; le bouton caché n'autorise personne.
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'comptabilite', 'imputer_ia'), 403);
    try {
        $n = (new PropositionsDImputation($this->entreprise()))->proposer();
        $this->dispatch('annonce', ton: 'succes', texte: $n.' proposition(s) de l’IA, à valider ci-dessous.');
    } catch (\Throwable $e) {
        $this->dispatch('annonce', texte: $e->getMessage());
    }
    unset($this->propositions);
};

/** Une personne décide ; seule une imputation validée change l'écriture. */
$decider = function (int $id, bool $valider, ?string $cle = null) {
    $this->garder();
    try {
        (new PropositionsDImputation($this->entreprise()))->decider($id, $valider, $cle ?: null, (int) auth()->id());
    } catch (\RuntimeException $e) {
        $this->dispatch('annonce', texte: $e->getMessage());
    }
    unset($this->propositions, $this->operations, $this->compteurs);
};

$ouvrir = function (int $id) {
    $this->garder();
    $this->ouverte = $this->ouverte === $id ? null : $id;
    $this->ht = '';
    $this->tva = '';
};

/**
 * La TVA d'une vente, lue sur la facture — 24/11 : « on ne regarde pas à l'aveugle, on vérifie la
 * facture d'abord ». Rien n'est calculé : la personne recopie le HT et la TVA imprimés ; le serveur
 * refuse s'ils ne font pas exactement le TTC.
 */
$lireLaTva = function () {
    $this->garder();
    $this->validate([
        'ht' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        'tva' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
    ], ['ht.*' => 'Le hors-taxes, en chiffres (point décimal).', 'tva.*' => 'La TVA, en chiffres (0 si exonérée).']);

    $operation = OperationComptable::query()->whereKey($this->ouverte)->where('source', 'facture')->firstOrFail();
    $facture = DB::table('factures')->where('entreprise_id', $this->entreprise())->where('id', $operation->source_id)->first();
    abort_unless($facture, 404);

    if (Montants::unites($this->ht) + Montants::unites($this->tva) !== abs(Montants::unites($facture->montant))) {
        $this->addError('ht', 'HT + TVA ('.Montants::texte(Montants::unites($this->ht) + Montants::unites($this->tva)).') ne font pas le TTC de la facture ('.Montants::texte(abs(Montants::unites($facture->montant))).').');

        return;
    }

    $signe = Montants::unites($facture->montant) < 0 ? -1 : 1;
    DB::table('factures')->where('id', $facture->id)->update([
        'montant_ht' => Montants::texte($signe * Montants::unites($this->ht)),
        'montant_tva' => Montants::texte($signe * Montants::unites($this->tva)),
        'tva_lue_par' => auth()->id(),
        'tva_lue_le' => now(),
    ]);
    activity()->performedOn($operation)->withProperties(['facture_id' => $facture->id, 'ht' => $this->ht, 'tva' => $this->tva])->log('TVA lue sur la facture');

    // Seule cette journée est relue : la vente passe « prête » aussitôt.
    (new GenerateurDEcritures($this->entreprise()))->generer((string) $facture->date, (string) $facture->date);
    $this->ouverte = null;
    unset($this->operations, $this->compteurs);
    $this->dispatch('annonce', ton: 'succes', texte: 'TVA enregistrée ; la vente est réécrite.');
};

?>

<div>
    <x-titre-ecran titre="Écritures comptables"
        sous-titre="Les écritures tirées des opérations de l'application, à vérifier puis à déverser vers COMPTAFLOW. Rien n'est arrondi : chaque montant est écrit tel qu'il a été saisi." />

    @if (! GenerateurDEcritures::disponible())
        <div class="carte"><p style="margin:0; color:#B45309;">La mise à jour du serveur n'est pas encore passée (migration du 24/11).</p></div>
    @else
        <div class="carte" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end; margin-bottom:16px;">
            <label>Exercice<br><input type="number" wire:model.live="annee" class="champ" style="width:100px;" min="2000" max="2100"></label>
            <button type="button" wire:click="generer" class="bouton">Générer les écritures de {{ $annee }}</button>
            @role('gerant')
                <button type="button" wire:click="deverser" class="bouton bouton-secondaire" wire:confirm="Envoyer les écritures prêtes vers COMPTAFLOW ?">Déverser vers COMPTAFLOW</button>
            @endrole
            <a href="{{ route('ecritures.parametrage') }}" class="bouton bouton-secondaire">Paramétrage des comptes</a>
            @if ($this->bilan)
                <span style="font-size:12px; color:#4B4E55;">
                    Dernier passage {{ $this->bilan['termine_le'] ?? '' }} :
                    @isset($this->bilan['generation'])
                        {{ $this->bilan['generation']['creees'] }} créée(s), {{ $this->bilan['generation']['inchangees'] }} inchangée(s),
                        {{ $this->bilan['generation']['contrepassees'] }} contre-passée(s), {{ $this->bilan['generation']['a_verifier'] }} à vérifier.
                    @endisset
                    @isset($this->bilan['deversement'])
                        {{ $this->bilan['deversement']['message'] ?? ($this->bilan['deversement']['acceptees'].' acceptée(s), '.$this->bilan['deversement']['refusees'].' refusée(s)') }}
                    @endisset
                </span>
            @endif
        </div>

        @if (PropositionsDImputation::disponible())
            <x-carte-section titre="Charges non classées — l'IA propose, vous décidez">
                <p style="font-size:12.5px; color:#4B4E55; margin:0 0 8px;">Les sorties et charges que la règle range en « Autres ». L'IA choisit une nature
                    du plan ; rien ne change tant que vous n'avez pas validé, et les montants ne bougent jamais.</p>
                @if (HabilitationsDActions::accorde(auth()->user(), 'comptabilite', 'imputer_ia'))
                    <button type="button" wire:click="proposerParIA" class="bouton bouton-secondaire" style="margin-bottom:8px;">Demander des propositions à l'IA</button>
                @endif
                @if ($this->propositions->isNotEmpty())
                    <table class="tableau">
                        <thead><tr><th>Date</th><th>Libellé</th><th style="text-align:right;">Montant</th><th>Nature proposée</th><th>Confiance</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($this->propositions as $p)
                                <tr wire:key="prop-{{ $p->id }}" x-data="{ cle: @js($p->cle_compte) }">
                                    <td>{{ \Illuminate\Support\Carbon::parse($p->date_operation)->format('d/m/Y') }}</td>
                                    <td>{{ $p->libelle }}<div style="font-size:11.5px; color:#6B6E76;">{{ $p->raison }}</div></td>
                                    <td style="text-align:right;">{{ montant_nombre($p->total_debit) }}</td>
                                    <td><select x-model="cle" class="champ" style="width:240px;">
                                        @foreach ($this->natures as $cle => $n)<option value="{{ $cle }}">{{ $n['numero'] }} — {{ $n['libelle'] }}</option>@endforeach
                                    </select></td>
                                    <td>{{ $p->confiance }} %</td>
                                    <td style="white-space:nowrap;">
                                        <button type="button" class="bouton" style="padding:2px 8px; font-size:12px;" x-on:click="$wire.decider({{ $p->id }}, true, cle)">Valider</button>
                                        <button type="button" class="bouton bouton-secondaire" style="padding:2px 8px; font-size:12px;" wire:click="decider({{ $p->id }}, false)">Rejeter</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-carte-section>
        @endif

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
            @foreach (['a_verifier' => 'À vérifier', 'a_envoyer' => 'Prêtes à envoyer', 'refusees' => 'Refusées par COMPTAFLOW', 'prete' => 'Toutes les prêtes', 'contrepassee' => 'Contre-passées'] as $cle => $nom)
                <button type="button" wire:click="$set('statut', '{{ $cle }}')" class="bouton {{ $statut === $cle ? '' : 'bouton-secondaire' }}" style="padding:4px 10px; font-size:12px;">
                    {{ $nom }}@if (in_array($cle, ['a_verifier', 'a_envoyer', 'refusees'], true)) ({{ (int) ($this->compteurs->{$cle} ?? 0) }})@endif
                </button>
            @endforeach
            <select wire:model.live="journal" class="champ" style="width:140px;">
                <option value="">Tous les journaux</option>
                @foreach ($this->journaux as $j)<option value="{{ $j }}">{{ $j }}</option>@endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="recherche" class="champ" style="width:220px;" placeholder="Référence ou libellé">
        </div>

        <div class="carte">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Saisie</th><th>Date</th><th>Journal</th><th>Pièce</th><th>Libellé</th><th style="text-align:right;">Débit</th><th style="text-align:right;">Crédit</th><th>État</th></tr></thead>
                    <tbody>
                        @forelse ($this->operations as $op)
                            <tr wire:key="op-{{ $op->id }}" wire:click="ouvrir({{ $op->id }})" style="cursor:pointer;">
                                <td>{{ $op->numero_saisie }}</td>
                                <td>{{ $op->date_operation->format('d/m/Y') }}</td>
                                <td>{{ $op->code_journal }}</td>
                                <td>{{ $op->reference_document }}</td>
                                <td>{{ $op->libelle }}@if ($op->motif)<div style="font-size:11.5px; color:#B45309;">{{ $op->motif }}</div>@endif
                                    @if ($op->message_deversement)<div style="font-size:11.5px; color:#B91C1C;">COMPTAFLOW : {{ $op->message_deversement }}</div>@endif</td>
                                <td style="text-align:right;">{{ montant_nombre($op->total_debit) }}</td>
                                <td style="text-align:right;">{{ montant_nombre($op->total_credit) }}</td>
                                <td style="font-size:12px;">{{ ['a_verifier' => 'À vérifier', 'prete' => 'Prête', 'contrepassee' => 'Contre-passée'][$op->statut] ?? $op->statut }}
                                    · {{ ['non_envoyee' => 'non envoyée', 'envoyee' => 'en cours', 'acceptee' => 'acceptée', 'refusee' => 'refusée'][$op->statut_deversement] ?? '' }}</td>
                            </tr>
                            @if ($ouverte === $op->id)
                                <tr wire:key="op-detail-{{ $op->id }}"><td colspan="8" style="background:#FAFAF7;">
                                    @if ($op->lignes->isNotEmpty())
                                        <table class="tableau" style="margin:0;">
                                            <thead><tr><th>Compte</th><th>Tiers</th><th>Libellé</th><th style="text-align:right;">Débit</th><th style="text-align:right;">Crédit</th></tr></thead>
                                            <tbody>
                                                @foreach ($op->lignes as $l)
                                                    <tr><td>{{ $l->compte }}</td><td>{{ $l->compte_tiers }}</td><td>{{ $l->libelle }}</td>
                                                        <td style="text-align:right;">{{ (float) $l->debit ? montant_nombre($l->debit) : '' }}</td>
                                                        <td style="text-align:right;">{{ (float) $l->credit ? montant_nombre($l->credit) : '' }}</td></tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                    @if ($op->source === 'facture' && $op->statut === 'a_verifier')
                                        <form wire:submit="lireLaTva" style="display:flex; gap:10px; align-items:end; margin-top:10px; flex-wrap:wrap;" wire:click.stop>
                                            <span style="font-size:12.5px;">Recopiez ce qu'imprime la facture {{ $op->reference_document }} :</span>
                                            <label>HT<br><input type="text" wire:model="ht" class="champ" style="width:150px;" inputmode="decimal"></label>
                                            <label>TVA (0 si exonérée)<br><input type="text" wire:model="tva" class="champ" style="width:150px;" inputmode="decimal"></label>
                                            <button type="submit" class="bouton">Enregistrer</button>
                                            @error('ht')<div class="champ-erreur">{{ $message }}</div>@enderror
                                            @error('tva')<div class="champ-erreur">{{ $message }}</div>@enderror
                                        </form>
                                    @endif
                                </td></tr>
                            @endif
                        @empty
                            <tr><td colspan="8" style="color:#6B6E76;">Aucune écriture ici. Lancez la génération de l'exercice.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$this->operations->currentPage()" :total="$this->operations->total()" prop="page" :par-page="50" />
        </div>
    @endif
</div>
