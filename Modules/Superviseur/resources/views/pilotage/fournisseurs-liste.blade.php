<?php

use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| La liste des fournisseurs — l'annuaire, et son code
|--------------------------------------------------------------------------
| **Demandé le 28/09** : « on n'a pas de liste des fournisseurs ni de code fourni par
| l'application, donc dans la page fournisseur, ajoute un bouton liste des fournisseurs qui
| ouvre une page et qui fait la liste avec une colonne code, et aussi ajoute un bouton
| ajouter un fournisseur ».
|
| **Ce que cette page fait, et qu'aucune autre ne faisait.** L'écran des fournisseurs liste
| des **pièces** — des factures. Les conditions de règlement listent les **accords**. Ni
| l'un ni l'autre ne répondait à « quels fournisseurs connaissons-nous ? », alors que c'est
| la première question qu'on pose avant de saisir une pièce.
|
| **Et le code règle ici le même problème que chez les tiers** : deux orthographes du même
| fournisseur coupent sa dette en deux, et deux fournisseurs réellement homonymes doivent
| pouvoir coexister. Un nom ne peut pas faire les deux.
|
| Les codes ne sont pas posés d'office : ce serait écrire des données que personne n'a
| demandées. Ils s'attribuent par un geste, qui dit combien il écrit et se trace.
*/

state(['recherche' => ''])->url(except: '');
state(['page' => 1]);

// L'ajout d'un fournisseur, replié tant qu'on ne le demande pas.
state([
    'formulaireOuvert' => false,
    'nom' => '',
    'delai' => '',
    'note' => '',
    'aConfirmer' => '',
]);

$updatedRecherche = function () { $this->page = 1; };

$peutEcrire = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

/**
 * L'annuaire : les fiches déclarées, et les fournisseurs que seules les pièces connaissent.
 *
 * **Les deux, et c'est le point.** Une liste des seules fiches tairait les fournisseurs
 * qu'on facture sans les avoir déclarés — c'est-à-dire, au 28/09, la quasi-totalité. Une
 * liste des seules pièces tairait ceux qu'on a déclarés sans les avoir encore facturés.
 *
 * @return \Illuminate\Support\Collection<int, array{nom: string, code: ?string, terme: ?string, pieces: int, reste: int, fiche: bool}>
 */
$annuaire = computed(function () {
    $fiches = FournisseurReferentiel::query()->get()->keyBy('nom_normalise');

    $pieces = FactureFournisseur::query()
        ->selectRaw('fournisseur, count(*) as n, sum(case when reste_a_payer > 0 then reste_a_payer else 0 end) as du')
        ->whereNotNull('fournisseur')
        ->groupBy('fournisseur')
        ->get();

    $lignes = [];

    foreach ($pieces as $ligne) {
        $cle = FournisseurReferentiel::clePour($ligne->fournisseur);
        $fiche = $fiches->get($cle);

        $lignes[$cle] = [
            'nom' => (string) $ligne->fournisseur,
            'code' => $fiche?->code,
            'terme' => $fiche?->delai_reglement,
            'pieces' => (int) $ligne->n,
            'reste' => (int) $ligne->du,
            'fiche' => $fiche !== null,
        ];
    }

    // Les fiches sans aucune pièce : déclarées, jamais facturées. Elles comptent autant —
    // c'est souvent un fournisseur qu'on vient d'ouvrir.
    foreach ($fiches as $cle => $fiche) {
        $lignes[$cle] ??= [
            'nom' => (string) $fiche->nom,
            'code' => $fiche->code,
            'terme' => $fiche->delai_reglement,
            'pieces' => 0,
            'reste' => 0,
            'fiche' => true,
        ];
    }

    return collect($lignes)->sortBy('nom', SORT_NATURAL | SORT_FLAG_CASE)->values();
});

$filtrees = computed(function () {
    $cherche = mb_strtolower(trim($this->recherche));

    return $cherche === ''
        ? $this->annuaire
        : $this->annuaire->filter(fn (array $l) => str_contains(mb_strtolower($l['nom']), $cherche)
            || str_contains(mb_strtolower((string) $l['code']), $cherche))->values();
});

$sansCode = computed(fn () => $this->annuaire->whereNull('code')->count());

/**
 * Les fournisseurs dont le nom ressemble à celui qu'on saisit.
 *
 * Même règle que pour les tiers, et pour la même raison : on avertit, on ne bloque pas.
 * Deux orthographes du même fournisseur coupent sa dette en deux ; deux fournisseurs
 * réellement homonymes doivent pouvoir coexister, et c'est le code qui les distingue.
 */
$ressemblants = computed(function () {
    $saisi = trim($this->nom);

    if ($saisi === '') {
        return collect();
    }

    $cle = FournisseurReferentiel::clePour($saisi);

    return $this->annuaire
        ->map(function (array $ligne) use ($cle) {
            similar_text($cle, FournisseurReferentiel::clePour($ligne['nom']), $proximite);

            return $ligne + ['proximite' => round($proximite, 1)];
        })
        ->filter(fn (array $l) => $l['proximite'] >= 55.0)
        ->sortByDesc('proximite')
        ->take(6)
        ->values();
});

$updatedNom = function () {
    if (mb_strtoupper(trim($this->nom)) !== $this->aConfirmer) {
        $this->aConfirmer = '';
    }

    unset($this->ressemblants);
};

/** Le code d'un fournisseur : posé une fois, et jamais recalculé. */
$coder = function (string $nom) {
    if (! $this->peutEcrire) {
        return;
    }

    /*
     * Le code n'est pas posé ici : c'est le modèle qui le pose à la naissance de la fiche.
     * Demandé le 28/09 — « le code doit être attribué en même temps, pas par
     * l'utilisateur ». Trois chemins créent une fiche ; un code posé par l'appelant est un
     * code que l'un des trois oubliera.
     */
    $fiche = FournisseurReferentiel::consigner(
        (int) auth()->user()->entreprise_id,
        $nom,
        ['source_feuille' => 'code attribué à l’écran'],
    );

    unset($this->annuaire, $this->filtrees, $this->sansCode);

    session()->flash('message', '« '.$nom.' » porte désormais le code '.$fiche->fresh()->code.'.');
};

$coderTout = function () {
    if (! $this->peutEcrire) {
        return;
    }

    $entrepriseId = (int) auth()->user()->entreprise_id;
    $poses = 0;

    foreach ($this->annuaire as $ligne) {
        if ($ligne['code'] !== null) {
            continue;
        }

        FournisseurReferentiel::consigner($entrepriseId, $ligne['nom'], [
            'source_feuille' => 'code attribué à l’écran',
        ]);

        $poses++;
    }

    activity()->causedBy(auth()->user())
        ->withProperties(['codes_poses' => $poses])
        ->log('Fournisseurs — codes attribués');

    unset($this->annuaire, $this->filtrees, $this->sansCode);

    session()->flash('message', $poses.' code(s) attribué(s).');
};

$ajouter = function () {
    if (! $this->peutEcrire) {
        return;
    }

    $this->validate([
        'nom' => ['required', 'string', 'max:160'],
        'delai' => ['nullable', 'string', 'max:60'],
        'note' => ['nullable', 'string', 'max:500'],
    ], [], ['nom' => 'nom du fournisseur', 'delai' => 'terme de règlement']);

    $nom = mb_strtoupper(trim($this->nom));

    // On avertit, on ne bloque pas : le premier clic montre les voisins, le second crée.
    if ($this->aConfirmer !== $nom && $this->ressemblants->isNotEmpty()) {
        $this->aConfirmer = $nom;

        return;
    }

    $entrepriseId = (int) auth()->user()->entreprise_id;

    $fiche = FournisseurReferentiel::consigner($entrepriseId, $nom, array_filter([
        'delai_reglement' => trim($this->delai) ?: null,
        'note' => trim($this->note) ?: null,
        'source_feuille' => 'saisi à l’écran',
    ]));

    $this->fill(['nom' => '', 'delai' => '', 'note' => '', 'aConfirmer' => '', 'formulaireOuvert' => false]);
    unset($this->annuaire, $this->filtrees, $this->sansCode, $this->ressemblants);

    session()->flash('message', 'Fournisseur « '.$nom.' » ajouté sous le code '.$fiche->fresh()->code.'.');
};

?>

<div>
    <x-titre-ecran titre="Liste des fournisseurs"
        sous-titre="Tous les fournisseurs que la maison connaît — ceux qu'on facture et ceux qu'on a déclarés — avec leur code.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Suivi fournisseur</a>
            <a href="{{ route('referentiel-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Conditions de règlement</a>
            @if ($this->peutEcrire)
                <button type="button" class="bouton" wire:click="$toggle('formulaireOuvert')">
                    {{ $formulaireOuvert ? 'Fermer le formulaire' : '+ Ajouter un fournisseur' }}
                </button>
            @endif
        </div>
    </x-titre-ecran>

    @if (session('message'))
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #0E9F6E;">
            <p style="margin:0; font-size:13px; color:#1E7B34; font-weight:600;">{{ session('message') }}</p>
        </div>
    @endif

    @if ($formulaireOuvert && $this->peutEcrire)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Ajouter un fournisseur</h3>

            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="Nom du fournisseur" model="nom" :requis="true" width="280" live="true" />
                {{-- **À quoi il sert, puisque la question a été posée le 28/09.**

                     Une facture fournisseur devrait dire pour quand elle est à payer.
                     5 184 des 7 350 lignes du classeur ne le disent pas : la colonne
                     « date d'échéance » y est vide. Le terme comble ce trou — « 30 jours »
                     sur une facture du 4 mars donne une échéance **attendue** au 3 avril,
                     déduite et jamais écrite en base.

                     Facultatif, et c'est voulu : on ouvre souvent un fournisseur avant
                     d'avoir négocié ses conditions. Laissé vide, ses factures n'auront
                     simplement pas d'échéance attendue — et la colonne le dira. --}}
                <x-champ label="Terme de règlement" model="delai" width="220"
                    placeholder="Comptant · 30 jours · 45 jours fin de mois"
                    aide="Facultatif — sert à déduire l'échéance des factures qui n'en portent pas" />
                <x-champ label="Note" model="note" width="280" />
                <button type="button" wire:click="ajouter" class="bouton">
                    {{ $aConfirmer !== '' ? 'Oui, ajouter quand même' : 'Ajouter' }}
                </button>
            </div>

            {{-- On avertit, on ne bloque pas : deux orthographes du même fournisseur coupent
                 sa dette en deux, mais deux fournisseurs réellement homonymes doivent
                 pouvoir coexister — et c'est le code qui les distinguera. --}}
            @if ($this->ressemblants->isNotEmpty())
                <div class="imp-hint {{ $aConfirmer !== '' ? 'warn' : '' }}" style="margin-top:10px;">
                    <strong>{{ $this->ressemblants->count() }} fournisseur(s) portent un nom voisin :</strong>
                    <div style="margin-top:6px;">
                        @foreach ($this->ressemblants as $proche)
                            <div style="padding:2px 0;">
                                <b>{{ $proche['nom'] }}</b>
                                @if ($proche['code'])
                                    <span style="font-family:ui-monospace,Consolas,monospace; font-size:11.5px; color:#6B6E76;">
                                        {{ $proche['code'] }}
                                    </span>
                                @endif
                                <span style="color:#6B6E76; font-size:12px;">
                                    — {{ $proche['pieces'] }} pièce(s)
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <p style="margin:8px 0 0;">
                        Vérifiez que le fournisseur n'y figure pas. L'ajout reste possible : cliquez une
                        seconde fois pour confirmer.
                    </p>
                </div>
            @endif

            @error('nom')
                <div class="imp-hint warn" style="margin-top:8px;">⚠ {{ $message }}</div>
            @enderror
        </div>
    @endif

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="Chercher" model="recherche" :live="true" placeholder="Nom ou code…" />
        </div>

        @if ($this->sansCode > 0 && $this->peutEcrire)
            <div class="imp-hint" style="margin-top:11px;">
                <strong>{{ number_format($this->sansCode, 0, ',', ' ') }} fournisseur(s) n'ont pas encore de code.</strong>
                Le code distingue deux fournisseurs réellement homonymes, là où le nom ne le peut pas.
                <button type="button" wire:click="coderTout" class="bouton"
                    style="margin-left:8px; padding:4px 11px; font-size:12px;">
                    Attribuer les codes manquants
                </button>
            </div>
        @endif
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
            {{ number_format($this->filtrees->count(), 0, ',', ' ') }} fournisseur(s)
        </h3>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Fournisseur</th>
                        <th>Terme de règlement</th>
                        <th class="num">Pièces</th>
                        <th style="text-align:right;">Reste dû</th>
                        {{-- « Fiche » ne disait rien à qui n'a pas écrit le code — relevé le
                             28/09. La colonne répond en réalité à une question précise :
                             sait-on à quel terme ce fournisseur se règle ? --}}
                        <th>Échéance déductible</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->filtrees->forPage($page, 25) as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap; font-family:ui-monospace,Consolas,monospace; font-size:12.5px;">
                                @if ($ligne['code'])
                                    {{ $ligne['code'] }}
                                @elseif ($this->peutEcrire)
                                    <button type="button" wire:click="coder('{{ addslashes($ligne['nom']) }}')"
                                        class="bouton bouton-secondaire" style="padding:2px 8px; font-size:11px;">coder</button>
                                @else
                                    <span style="color:#9A9DA5;">—</span>
                                @endif
                            </td>
                            <td style="font-weight:600;">{{ $ligne['nom'] }}</td>
                            {{-- Le terme vient des conditions de règlement : c'est de lui que
                                 sort l'échéance attendue d'une pièce que le classeur n'a pas
                                 datée — 5 184 lignes sur 7 350. --}}
                            <td style="color:#6B6E76;">{{ $ligne['terme'] ?: '—' }}</td>
                            <td class="num">{{ $ligne['pieces'] ?: '·' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne['reste'] > 0 ? '#C8102E' : '#6B6E76' }};">
                                {{ ae($ligne['reste']) }}
                            </td>
                            <td>
                                @if ($ligne['terme'])
                                    <span style="color:#0E9F6E; font-weight:600;">oui</span>
                                @else
                                    {{-- Sans terme connu, ses factures qui ne portent pas
                                         d'échéance n'en auront aucune — ni attendue, ni écrite.
                                         C'est le cas de 5 184 lignes sur 7 350. --}}
                                    <span style="color:#B87A00;" title="Aucun terme de règlement connu : ses factures sans échéance n'en auront pas.">
                                        non — terme inconnu
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="6"
                            texte="Aucun fournisseur. Ils arrivent avec le classeur de suivi, ou s'ajoutent ici un par un." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->filtrees->count()" prop="page" :par-page="25" />
    </div>
</div>
