<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Comptabilite\Jobs\GenererEtDeverser;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Comptabilite\Services\AccesALaComptabilite;
use Modules\Noyau\Comptabilite\Services\GenerateurDEcritures;
use Modules\Noyau\Comptabilite\Services\Montants;
use Modules\Noyau\Comptabilite\Services\PiecesJustificatives;
use Modules\Noyau\Exploitation\Modeles\LiaisonComptaflow;

use function Livewire\Volt\{computed, mount, protect, state, updated};

/*
|--------------------------------------------------------------------------
| Écritures comptables — le journal, la TVA à lire, le déversement — 24/11
|--------------------------------------------------------------------------
| « Comptabilité : vas-y, commence et termine. » Les écritures se génèrent depuis les opérations de
| l'application (`GenerateurDEcritures`), en tâche de fond ; celles qui attendent une donnée sont
| « à vérifier » avec leur motif ; les prêtes partent vers COMPTAFLOW par le bouton Déverser.
|
| 25/11 — « un tableau à 9 colonnes, comme COMPTAFLOW et SELFLOW » : une ligne par ligne
| d'écriture, dans l'ordre du guide de COMPTAFLOW (Date, N° saisie, Code journal, N° pièce,
| N° compte général, Libellé, Débit, Crédit, N° compte tiers) — c'est aussi l'ordre de l'export
| TXT à venir (guide § 8). L'état de l'opération n'est pas une dixième colonne : il est en marge,
| sous ses lignes, avec sa pièce justificative. La pagination compte des opérations, pas des
| lignes : une opération coupée entre deux pages ne se lirait plus équilibrée.
|
| Tout se décide au serveur : l'habilitation `comptabilite.*` est revérifiée dans chaque action,
| l'entreprise vient du compte connecté, jamais du navigateur ; une TVA lue n'est acceptée que si
| HT + TVA = TTC exact.
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

$garder = protect(function (string $geste = 'voir') {
    AccesALaComptabilite::exiger($geste);
});

$entreprise = protect(fn () => (int) auth()->user()->entreprise_id);

/** Ce que le compte peut faire ici — pour les boutons ; chaque action le revérifie. */
$droits = computed(fn () => collect(AccesALaComptabilite::ACTIONS)
    ->mapWithKeys(fn ($a) => [$a => AccesALaComptabilite::peut(auth()->user(), $a)])->all());

$operations = computed(function () {
    $recherche = trim($this->recherche);

    return OperationComptable::query()->with('lignes')
        ->where('entreprise_id', $this->entreprise())
        ->whereYear('date_operation', $this->annee)
        ->when($this->statut === 'a_envoyer', fn ($q) => $q->where('statut', 'prete')->whereIn('statut_deversement', ['non_envoyee', 'refusee']))
        ->when($this->statut === 'refusees', fn ($q) => $q->where('statut_deversement', 'refusee'))
        ->when(in_array($this->statut, ['a_verifier', 'prete', 'contrepassee'], true), fn ($q) => $q->where('statut', $this->statut))
        ->when($this->journal !== '', fn ($q) => $q->where('code_journal', $this->journal))
        // Référence, libellé, ou un numéro de compte général ou de tiers d'une de ses lignes.
        ->when($recherche !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('reference_document', 'like', '%'.$recherche.'%')
            ->orWhere('libelle', 'like', '%'.$recherche.'%')
            ->orWhereHas('lignes', fn ($l) => $l->where('compte', 'like', $recherche.'%')->orWhere('compte_tiers', 'like', $recherche.'%'))))
        ->orderBy('date_operation')->orderBy('numero_saisie')
        ->paginate(25, ['*'], 'p', max(1, (int) $this->page));
});

/** Opération => PDF joint, lien du portail FNE — lu pour la page affichée seulement. */
$justificatifs = computed(fn () => PiecesJustificatives::pourLEcran($this->operations->getCollection()));

$compteurs = computed(fn () => OperationComptable::query()->where('entreprise_id', $this->entreprise())->whereYear('date_operation', $this->annee)->toBase()
    ->selectRaw("sum(case when statut = 'a_verifier' then 1 else 0 end) as a_verifier,
        sum(case when statut = 'prete' and statut_deversement in ('non_envoyee','refusee') then 1 else 0 end) as a_envoyer,
        sum(case when statut_deversement = 'acceptee' then 1 else 0 end) as acceptees,
        sum(case when statut_deversement = 'refusee' then 1 else 0 end) as refusees")->first());

$journaux = computed(fn () => OperationComptable::query()->where('entreprise_id', $this->entreprise())->whereYear('date_operation', $this->annee)
    ->distinct()->orderBy('code_journal')->pluck('code_journal')->all());

$bilan = computed(fn () => Cache::get(GenererEtDeverser::cleDuBilan($this->entreprise())));

/** La liaison COMPTAFLOW de l'entreprise, en lecture : elle se pose et se vérifie au Super admin. */
$liaison = computed(fn () => LiaisonComptaflow::query()->where('entreprise_id', $this->entreprise())
    ->first(['statut', 'comptaflow_company_id', 'liee_le', 'dernier_deversement_le', 'dernier_message']));

$generer = function () {
    $this->garder('generer');
    if (! GenerateurDEcritures::disponible()) {
        $this->dispatch('annonce', texte: "La mise à jour du serveur n'est pas encore passée (migration du 24/11).");

        return;
    }
    $annee = max(2000, min(2100, (int) $this->annee));
    GenererEtDeverser::dispatch($this->entreprise(), $annee.'-01-01', $annee.'-12-31', false);
    $this->dispatch('annonce', ton: 'succes', texte: 'Génération de '.$annee.' lancée : le bilan s’affiche à son retour.');
};

$deverser = function () {
    $this->garder('deverser');
    GenererEtDeverser::dispatch($this->entreprise(), null, null, true);
    $this->dispatch('annonce', ton: 'succes', texte: 'Déversement vers COMPTAFLOW lancé.');
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
    $this->garder('lire_tva');
    $this->validate([
        'ht' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        'tva' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
    ], ['ht.*' => 'Le hors-taxes, en chiffres (point décimal).', 'tva.*' => 'La TVA, en chiffres (0 si exonérée).']);

    $operation = OperationComptable::query()->where('entreprise_id', $this->entreprise())
        ->whereKey($this->ouverte)->where('source', 'facture')->firstOrFail();
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
        {{-- La liaison, en lecture seule : elle se pose et se vérifie au Super admin. --}}
        @php $l = $this->liaison; @endphp
        <div class="carte" style="margin-bottom:12px; border-left:3px solid {{ $l?->statut === 'active' ? '#15803D' : '#B45309' }}; font-size:12.5px; color:#4B4E55; display:flex; gap:18px; flex-wrap:wrap;">
            <span><b>Liaison COMPTAFLOW :</b>
                @if ($l?->statut === 'active')
                    liée (dossier n° {{ $l->comptaflow_company_id }}@if ($l->liee_le), depuis le {{ $l->liee_le->format('d/m/Y') }}@endif)
                @elseif ($l?->statut === 'erreur')
                    en erreur — à revérifier au Super admin
                @else
                    non liée
                @endif
            </span>
            <span><b>Dernière synchronisation :</b> {{ $l?->dernier_deversement_le?->format('d/m/Y H:i') ?? 'aucune' }}</span>
            <span><b>Dernier message :</b> {{ $l?->dernier_message ?: '—' }}</span>
        </div>

        @if (session('errors')?->has('justificatif'))
            <div class="carte" style="margin-bottom:12px; color:#B91C1C; font-size:12.5px;">{{ session('errors')->first('justificatif') }}</div>
        @endif

        <div class="carte" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end; margin-bottom:16px;">
            <label>Exercice<br><input type="number" wire:model.live="annee" class="champ" style="width:100px;" min="2000" max="2100"></label>
            @if ($this->droits['generer'])
                <button type="button" wire:click="generer" class="bouton">Générer les écritures de {{ $annee }}</button>
            @endif
            @if ($this->droits['deverser'])
                <button type="button" wire:click="deverser" class="bouton bouton-secondaire">Déverser vers COMPTAFLOW</button>
            @endif
            <a href="{{ route('ecritures.parametrage') }}" wire:navigate class="bouton bouton-secondaire">Paramétrage des comptes</a>
            @if ($this->droits['imputer_ia'])
                <a href="{{ route('ecritures.imputations') }}" wire:navigate class="bouton bouton-secondaire">Propositions de l'IA</a>
            @endif
            @if ($this->bilan)
                <span style="font-size:12px; color:#4B4E55;">
                    Dernier passage {{ $this->bilan['termine_le'] ?? '' }} :
                    @isset($this->bilan['generation'])
                        {{ $this->bilan['generation']['creees'] }} créée(s), {{ $this->bilan['generation']['inchangees'] }} inchangée(s),
                        {{ $this->bilan['generation']['contrepassees'] }} contre-passée(s), {{ $this->bilan['generation']['a_verifier'] }} à vérifier.
                    @endisset
                    @isset($this->bilan['deversement'])
                        {{ $this->bilan['deversement']['message'] ?? ($this->bilan['deversement']['acceptees'].' acceptée(s), '.$this->bilan['deversement']['refusees'].' refusée(s)') }}
                        @if (! empty($this->bilan['deversement']['justificatifs'])) · {{ $this->bilan['deversement']['justificatifs'] }} justificatif(s) envoyé(s) @endif
                    @endisset
                </span>
            @endif
        </div>

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
            <input type="search" wire:model.live.debounce.400ms="recherche" class="champ" style="width:240px;" placeholder="Référence, libellé ou n° de compte">
        </div>

        <div class="carte">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° saisie</th>
                            <th>Code journal</th>
                            <th>N° pièce / référence</th>
                            <th>N° compte général</th>
                            <th>Libellé</th>
                            <th style="text-align:right;">Débit</th>
                            <th style="text-align:right;">Crédit</th>
                            <th>N° compte tiers</th>
                        </tr>
                    </thead>
                    @forelse ($this->operations as $op)
                        @php
                            $couleur = ['a_verifier' => '#B45309', 'prete' => '#15803D', 'contrepassee' => '#6B6E76'][$op->statut] ?? '#6B6E76';
                            $piece = $this->justificatifs[$op->id] ?? ['pdf' => false, 'portail' => false];
                            $lignes = $op->lignes->isNotEmpty() ? $op->lignes : collect([null]);
                        @endphp
                        <tbody wire:key="op-{{ $op->id }}">
                            @foreach ($lignes as $ligne)
                                <tr>
                                    <td style="border-left:3px solid {{ $couleur }}; white-space:nowrap;">{{ $op->date_operation->format('d/m/Y') }}</td>
                                    <td>{{ $op->numero_saisie }}</td>
                                    <td>{{ $op->code_journal }}</td>
                                    <td>{{ $op->reference_document }}</td>
                                    <td>{{ $ligne?->compte }}</td>
                                    <td>{{ $ligne?->libelle ?? $op->libelle }}</td>
                                    <td style="text-align:right; white-space:nowrap;">{{ $ligne && Montants::unites($ligne->debit) !== 0 ? montant_nombre($ligne->debit) : '' }}</td>
                                    <td style="text-align:right; white-space:nowrap;">{{ $ligne && Montants::unites($ligne->credit) !== 0 ? montant_nombre($ligne->credit) : '' }}</td>
                                    <td>{{ $ligne?->compte_tiers }}</td>
                                </tr>
                            @endforeach
                            {{-- En marge : l'état de l'opération, sa pièce, ce qu'il reste à faire. --}}
                            <tr>
                                <td colspan="9" style="font-size:11.5px; color:#4B4E55; background:#FAFAF7; border-left:3px solid {{ $couleur }}; padding-top:3px; padding-bottom:6px;">
                                    <span style="font-weight:700; color:{{ $couleur }};">{{ ['a_verifier' => 'À vérifier', 'prete' => 'Prête', 'contrepassee' => 'Contre-passée'][$op->statut] ?? $op->statut }}</span>
                                    · {{ ['non_envoyee' => 'non envoyée', 'envoyee' => 'en cours', 'acceptee' => 'acceptée par COMPTAFLOW', 'refusee' => 'refusée'][$op->statut_deversement] ?? '' }}
                                    @if ($op->libelle) · {{ $op->libelle }} @endif
                                    @if ($op->motif)<span style="color:#B45309;"> · {{ $op->motif }}</span>@endif
                                    @if ($op->message_deversement)<span style="color:#B91C1C;"> · COMPTAFLOW : {{ $op->message_deversement }}</span>@endif
                                    @if ($piece['pdf'] || $piece['portail'])
                                        · <a href="{{ route('ecritures.justificatif', $op->id) }}" target="_blank" rel="noopener">{{ $piece['pdf'] ? 'Pièce justificative (PDF)' : 'Pièce sur le portail FNE ↗' }}</a>
                                    @endif
                                    @if ($op->justificatif_envoye_le ?? null)<span style="color:#15803D;"> · PDF chez COMPTAFLOW</span>@endif
                                    @if ($op->justificatif_message ?? null)<span style="color:#B91C1C;"> · {{ $op->justificatif_message }}</span>@endif
                                    @if ($this->droits['lire_tva'] && (($op->source === 'facture' && $op->statut === 'a_verifier') || (in_array($op->source, PiecesJustificatives::SOURCES, true) && PiecesJustificatives::disponible())))
                                        · <button type="button" wire:click="ouvrir({{ $op->id }})" class="lien-bouton" style="background:none; border:0; padding:0; color:#2563EB; cursor:pointer; font-size:11.5px;">{{ $ouverte === $op->id ? 'Fermer' : ($op->source === 'facture' && $op->statut === 'a_verifier' ? 'Lire la TVA / joindre le PDF' : 'Joindre le PDF') }}</button>
                                    @endif

                                    @if ($ouverte === $op->id && $this->droits['lire_tva'])
                                        @if ($op->source === 'facture' && $op->statut === 'a_verifier')
                                            <form wire:submit="lireLaTva" style="display:flex; gap:10px; align-items:end; margin-top:8px; flex-wrap:wrap;">
                                                <span style="font-size:12.5px;">Recopiez ce qu'imprime la facture {{ $op->reference_document }} :</span>
                                                <label>HT<br><input type="text" wire:model="ht" class="champ" style="width:150px;" inputmode="decimal"></label>
                                                <label>TVA (0 si exonérée)<br><input type="text" wire:model="tva" class="champ" style="width:150px;" inputmode="decimal"></label>
                                                <button type="submit" class="bouton">Enregistrer</button>
                                                @error('ht')<div class="champ-erreur">{{ $message }}</div>@enderror
                                                @error('tva')<div class="champ-erreur">{{ $message }}</div>@enderror
                                            </form>
                                        @endif
                                        @if (in_array($op->source, PiecesJustificatives::SOURCES, true) && PiecesJustificatives::disponible())
                                            {{-- Un vrai POST : le dépôt marche sans JavaScript (règle 6). --}}
                                            <form method="POST" action="{{ route('ecritures.justificatif.deposer', $op->id) }}" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:end; margin-top:8px; flex-wrap:wrap;">
                                                @csrf
                                                <label>PDF de la pièce (10 Mo au plus)<br><input type="file" name="justificatif" accept="application/pdf" class="champ" style="width:auto;"></label>
                                                <button type="submit" class="bouton bouton-secondaire">Joindre</button>
                                            </form>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    @empty
                        <tbody><tr><td colspan="9" style="color:#6B6E76;">Aucune écriture ici. Lancez la génération de l'exercice.</td></tr></tbody>
                    @endforelse
                </table>
            </div>
            <x-pagination :page="$this->operations->currentPage()" :total="$this->operations->total()" prop="page" :par-page="25" />
        </div>
    @endif
</div>
