<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\ReconnaissanceDeBanque;
use Modules\Noyau\Exploitation\Services\SupportDeReglement;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Banques — ce qui est passé par chaque compte
|--------------------------------------------------------------------------
| **Demandé le 30/09** : « tout comme nous avons la page Caisse à travers le bouton Caisse
| dans la page tréso, on fera aussi pareillement pour les banques. Et lorsque le bouton sera
| cliqué, on aura différents boutons en dessous qui seront les banques, sur une même ligne.
| Donc les KPI vont devoir changer en fonction de la banque sélectionnée. »
|
| **D'où vient le nom de la banque, aujourd'hui.** D'aucun relevé : l'import bancaire n'existe
| pas encore. Il vient de `factures.banque`, où il est écrit à la main depuis le début — c'est
| la banque du chèque reçu, notée sur la créance au moment de l'encaisser. Un encaissement
| hérite donc de la banque de sa facture.
|
| **Et c'est un champ libre, avec ce que cela produit.** Relevé le 01/10 : quatre banques pour
| quatorze orthographes, dont `BGFIU`, `BGFI+BNI`, `234665`, `CAISSE` et `wave`. Cet écran ne
| devine pas : il range ce qu'il reconnaît, et **montre à part ce qu'il ne reconnaît pas** —
| c'est la liste à corriger, et elle vaut mieux qu'un total faux qui ne dit rien.
|
| **Les banques se déclarent, elles ne se devinent pas.** Poser d'office une fiche par valeur
| trouvée créerait `BGFIU` et `234665` comme banques de l'entreprise. L'écran propose, le
| lecteur tranche.
*/

state(['villeFiltre' => ''])->url(except: '');
state(['banqueFiltre' => ''])->url(except: '');
state(['recherche' => '']);
state(['page' => 1]);
state(['filtresLibres' => []]);

/* La période, comme sur les autres écrans d'argent. */
state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
]);

/* La déclaration d'une banque, repliée tant qu'on ne la demande pas. */
state(['formulaireOuvert' => false, 'nom' => '', 'code' => '', 'villeDeLaBanque' => '', 'note' => '']);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedVilleFiltre = function () { $this->page = 1; };
$updatedBanqueFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };
$updatedFiltresLibres = function () { $this->page = 1; };
$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->page = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->page = 1; };
$updatedJourFiltre = function () { $this->page = 1; };
$updatedPeriode = function () { $this->page = 1; };
$updatedDateDebut = function () { $this->page = 1; };
$updatedDateFin = function () { $this->page = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

/*
 * Les villes **en modèles**, et non en `id => nom`.
 *
 * `x-filtre-periode` les parcourt et lit `$ville->id` : lui donner un tableau de chaînes
 * fait tomber l'écran sur « Attempt to read property "id" on string ». C'est le pendant du
 * défaut inverse corrigé le 29/09 sur la caisse, où `x-champ` recevait des modèles alors
 * qu'il attend `valeur => libellé`. Les deux composants ne lisent pas la même forme, et
 * c'est à l'écran de donner la bonne à chacun.
 */
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, ''));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

/** Le gérant déclare les banques ; les autres les lisent. Vérifié ici **et** dans l'action. */
$peutDeclarer = computed(fn () => auth()->user()->hasRole('gerant'));

/** Les banques déclarées de l'entreprise, actives d'abord. */
$banques = computed(fn () => Banque::query()->orderBy('nom')->get());

$villes = computed(fn () => Ville::query()->where('est_actif', true)->orderBy('nom')->pluck('nom', 'id')->all());

/**
 * Les règlements passés par une banque, dans le périmètre et la période.
 *
 * **Le support fait le tri avant la banque**, et c'est l'ordre juste : un encaissement en
 * espèces n'a pas de banque, et sa facture peut pourtant en porter une — celle du chèque
 * reçu la fois d'avant. Ranger les 121 règlements en espèces sous une banque gonflerait son
 * total de ce qui n'y est jamais passé.
 */
$requeteDeBase = computed(function () {
    [$debut, $fin] = $this->plage;

    return SupportDeReglement::appliquer(
        PerimetreDeTresorerie::encaissements(
            Encaissement::query(), $this->idsSites, $this->idsVilles,
        ),
        'encaissements',
        SupportDeReglement::BANQUE,
    )->whereBetween('encaissements.date', [$debut, $fin]);
});

/**
 * Ce que chaque libellé de banque porte — avant toute reconnaissance.
 *
 * Compté par la base, en une requête : un `join` sur les factures, un `group by` sur leur
 * colonne `banque`. Quatorze lignes reviennent, pas sept mille.
 *
 * @return \Illuminate\Support\Collection<int, object{libelle: ?string, nombre: int, montant: int}>
 */
$libelles = computed(fn () => (clone $this->requeteDeBase)
    ->leftJoin('factures', 'factures.id', '=', 'encaissements.facture_id')
    ->selectRaw('factures.banque as libelle, count(*) as nombre, sum(encaissements.montant) as montant')
    ->groupBy('factures.banque')
    ->orderByDesc('montant')
    ->get());

/**
 * Les libellés rangés sous la banque qu'ils désignent — et ceux qu'on ne sait pas ranger.
 *
 * **Les deux sont rendus**, et c'est le point de l'écran. Un total qui ne montrerait que ce
 * qu'il a su classer serait juste pour ce qu'il affiche et faux pour ce qu'il prétend : la
 * part non reconnue est précisément ce qu'il faut aller corriger.
 *
 * @return array{parBanque: array<int, array{banque: Banque, nombre: int, montant: int, libelles: array<int, string>}>, nonRanges: array<int, array{libelle: ?string, nombre: int, montant: int, raison: string}>}
 */
$repartition = computed(function () {
    $banques = $this->banques;
    $parBanque = [];
    $nonRanges = [];

    foreach ($this->libelles as $ligne) {
        $verdict = ReconnaissanceDeBanque::pour($ligne->libelle, $banques);

        // Seule la certitude range. Une ressemblance **propose** — la poser d'office
        // rangerait des écritures sous une banque qui n'est pas la bonne, et rien ne le
        // dirait ensuite.
        if ($verdict['verdict'] === ReconnaissanceDeBanque::CERTAINE) {
            $id = $verdict['banque']->id;

            $parBanque[$id] ??= ['banque' => $verdict['banque'], 'nombre' => 0, 'montant' => 0, 'libelles' => []];
            $parBanque[$id]['nombre'] += (int) $ligne->nombre;
            $parBanque[$id]['montant'] += (int) $ligne->montant;
            $parBanque[$id]['libelles'][] = (string) $ligne->libelle;

            continue;
        }

        $nonRanges[] = [
            'libelle' => $ligne->libelle,
            'nombre' => (int) $ligne->nombre,
            'montant' => (int) $ligne->montant,
            'raison' => $verdict['raison'],
            'proposee' => $verdict['verdict'] === ReconnaissanceDeBanque::PROPOSEE ? $verdict['banque'] : null,
        ];
    }

    uasort($parBanque, fn ($a, $b) => $b['montant'] <=> $a['montant']);

    return ['parBanque' => $parBanque, 'nonRanges' => $nonRanges];
});

/** La banque regardée, ramenée à la liste connue : un identifiant tapé à la main ne vaut rien. */
$banqueChoisie = computed(fn () => $this->banqueFiltre === ''
    ? null
    : $this->banques->firstWhere('id', (int) $this->banqueFiltre));

/**
 * Les libellés que la banque choisie recouvre — pour filtrer le tableau.
 *
 * On filtre sur les **libellés** et non sur un identifiant, parce qu'aucune colonne ne
 * porte l'identifiant : la banque est un nom écrit sur la facture. C'est ce que l'import
 * bancaire changera, et c'est pourquoi ce chemin est isolé ici.
 *
 * @return array<int, string>
 */
$libellesDeLaBanque = computed(function () {
    $id = $this->banqueChoisie?->id;

    if ($id === null) {
        return [];
    }

    return $this->repartition['parBanque'][$id]['libelles'] ?? ['__aucun__'];
});

$requete = computed(function () {
    $requete = (clone $this->requeteDeBase)
        ->when($this->banqueChoisie !== null, fn ($q) => $q->whereHas(
            'facture', fn ($f) => $f->whereIn('banque', $this->libellesDeLaBanque),
        ))
        ->when(trim($this->recherche) !== '', function ($q) {
            $terme = '%'.trim($this->recherche).'%';

            $q->where(fn ($sous) => $sous
                ->where('encaissements.client', 'like', $terme)
                ->orWhere('encaissements.numero', 'like', $terme)
                ->orWhere('encaissements.reference_origine', 'like', $terme)
                ->orWhere('encaissements.motif', 'like', $terme));
        });

    return FiltreLibre::appliquer($requete, $this->colonnesFiltrables, (array) $this->filtresLibres);
});

/**
 * Les colonnes du tableau qu'aucun filtre du haut ne couvre.
 *
 * La banque n'y est pas : elle a ses boutons, et la proposer deux fois laisserait poser deux
 * conditions contradictoires sur la même donnée.
 */
$colonnesFiltrables = computed(fn () => [
    'encaissements.client' => FiltreLibre::colonne('Client'),
    'encaissements.numero' => FiltreLibre::colonne('N° de règlement'),
    'encaissements.reference_origine' => FiltreLibre::colonne('Référence (chèque, transaction)'),
    'encaissements.motif' => FiltreLibre::colonne('Motif'),
    'encaissements.type' => FiltreLibre::colonne('Type'),
    'encaissements.moyen' => FiltreLibre::colonne('Moyen', 'liste', [
        'Chèque' => 'Chèque', 'Virement' => 'Virement',
    ]),
    'encaissements.montant' => FiltreLibre::colonne('Montant', 'nombre'),
    'encaissements.date' => FiltreLibre::colonne('Date du règlement', 'date'),
]);

/**
 * Les indicateurs — ils suivent la banque choisie, comme demandé.
 *
 * Le nombre de clients est compté par la base et non sur la page affichée : « combien de
 * clients règlent par cette banque » ne se lit pas sur vingt-cinq lignes.
 */
$kpis = computed(fn () => [
    'montant' => (int) (clone $this->requete)->sum('encaissements.montant'),
    'nombre' => (clone $this->requete)->count(),
    'clients' => (clone $this->requete)->distinct()->count('encaissements.client'),
]);

$lignes = computed(fn () => (clone $this->requete)
    ->with(['facture', 'site.ville'])
    ->orderByDesc('encaissements.date')
    ->orderByDesc('encaissements.id')
    ->forPage($this->page, 25)
    ->get());

$total = computed(fn () => (clone $this->requete)->count());

// ------------------------------------------------------------------ déclarer une banque

$ouvrirLaDeclaration = function (?string $nomPropose = null) {
    $this->formulaireOuvert = true;
    $this->nom = $nomPropose ? Banque::formePresentable($nomPropose) : '';
    $this->code = '';
    $this->villeDeLaBanque = '';
    $this->note = '';
    $this->resetErrorBag();
};

$fermerLaDeclaration = function () {
    $this->formulaireOuvert = false;
    $this->fill(['nom' => '', 'code' => '', 'villeDeLaBanque' => '', 'note' => '']);
    $this->resetErrorBag();
};

/**
 * Déclare une banque.
 *
 * **Le droit est revérifié ici**, et pas seulement sur la route : une route ne protège que
 * l'entrée, et une action Livewire s'appelle depuis le navigateur.
 *
 * **Le nom est unique par sa forme réduite**, pas par sa lettre : « BGFI » et « B.G.F.I »
 * sont le même établissement, et deux fiches couperaient ses totaux en deux.
 */
$declarerLaBanque = function () {
    abort_unless($this->peutDeclarer, 403, 'La déclaration des banques est réservée au gérant.');

    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:120'],
        'code' => ['nullable', 'string', 'max:16'],
        'villeDeLaBanque' => ['nullable', Rule::in(array_map('strval', array_keys($this->villes)))],
        'note' => ['nullable', 'string', 'max:500'],
    ], attributes: ['nom' => 'nom de la banque', 'villeDeLaBanque' => 'ville']);

    $cle = Banque::clePour($donnees['nom']);

    if ($cle === '') {
        $this->addError('nom', "Ce nom ne porte aucune lettre : ce n'est pas un nom de banque.");

        return;
    }

    if (Banque::query()->where('nom_normalise', $cle)->exists()) {
        $this->addError('nom', 'Cette banque est déjà déclarée : ses règlements se rejoindraient sur deux fiches.');

        return;
    }

    Banque::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'nom' => Banque::formePresentable($donnees['nom']),
        'nom_normalise' => $cle,
        'code' => $donnees['code'] ? mb_strtoupper(trim($donnees['code'])) : null,
        'ville_id' => $donnees['villeDeLaBanque'] ?: null,
        'note' => $donnees['note'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    unset($this->banques, $this->repartition, $this->libelles);

    $this->fermerLaDeclaration();

    session()->flash('message', 'La banque est déclarée. Ses règlements se rangent sous elle dès maintenant.');
};

?>

<div>
    <x-titre-ecran titre="Banques"
        sous-titre="Ce qui est passé par chaque compte : les règlements reçus par chèque et par virement.">
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <a href="{{ route('caisse') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; text-decoration:none;">Caisse</a>

            @if ($this->peutDeclarer)
                <button type="button" wire:click="ouvrirLaDeclaration" class="bouton" style="padding:8px 14px;">
                    + Déclarer une banque
                </button>
            @endif

            <a href="{{ route('tresorerie') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; text-decoration:none; margin-left:auto;">← Retour à la trésorerie</a>
        </div>
    </x-titre-ecran>

    @if (session('message'))
        <div class="encart encart-succes" style="margin-bottom:16px;">{{ session('message') }}</div>
    @endif

    {{-- **Ce que cet écran lit, dit avant les chiffres.** Une page qui montre des totaux
         sans dire d'où ils viennent se croit sur parole, et c'est le plus mauvais moment
         pour cela : l'import bancaire n'existe pas encore. --}}
    <div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
        <p style="margin:0; font-size:13px; line-height:1.6;">
            <strong>D'où viennent ces chiffres, tant que les relevés ne sont pas importés.</strong>
            De la colonne <b>Banque</b> des créances — celle du chèque reçu, notée au moment de
            l'encaisser. Un règlement hérite donc de la banque de sa facture, et seuls les
            règlements <b>par chèque ou par virement</b> sont comptés : des espèces ne passent par
            aucune banque, même si la facture en porte une.
        </p>
        @if ($this->banques->isEmpty())
            <p style="margin:9px 0 0; font-size:13px; line-height:1.6;">
                <strong>Aucune banque n'est encore déclarée.</strong> Les libellés trouvés sont listés
                plus bas : déclarez ceux qui sont de vraies banques, et les règlements se rangeront
                sous elles. Rien n'est posé d'office — le champ est libre depuis le début, et l'on y
                trouve aussi bien <code>BGFIU</code> que <code>234665</code>.
            </p>
        @endif
    </div>

    @if ($formulaireOuvert)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Déclarer une banque</h3>

            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="Nom de la banque" model="nom" :requis="true" width="260"
                    placeholder="BGFI, BNI, BDA…" />
                <x-champ label="Code court" model="code" width="130" placeholder="Facultatif" />
                @if (count($this->villes) > 1)
                    <x-champ label="Ville" model="villeDeLaBanque" type="select" :options="$this->villes"
                        vide="Toutes les villes" width="190" />
                @endif
                <x-champ label="Note" model="note" width="280" placeholder="Facultatif" />

                <button type="button" wire:click="declarerLaBanque" class="bouton">Déclarer</button>
                <button type="button" wire:click="fermerLaDeclaration" class="bouton bouton-secondaire">Annuler</button>
            </div>

            <p style="margin:10px 0 0; font-size:12.5px; color:#6B6E76; line-height:1.55;">
                Le nom est enregistré en capitales, et deux écritures du même établissement se
                rejoignent sur leur forme réduite : « BGFI » et « B.G.F.I » sont la même banque.
                La <b>ville est facultative</b> — une banque sert ordinairement toutes les villes,
                et elle ne sert qu'à proposer, jamais à écarter une écriture.
            </p>

            <x-erreurs-du-bloc prefixe="nom" />
            <x-erreurs-du-bloc prefixe="code" />
            <x-erreurs-du-bloc prefixe="villeDeLaBanque" />
        </div>
    @endif

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin"
        :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        masquer-activite />

    {{-- ─────────────────────────────── les banques, sur une ligne

         Demandé le 30/09 : « on aura différents boutons en dessous qui seront les banques,
         sur une même ligne, et les KPI vont devoir changer en fonction de la banque
         sélectionnée ». Chaque bouton dit ce qu'il porte, pour qu'on sache avant de cliquer
         si la banque a quelque chose à montrer sur la période. --}}
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
        @php $totalGeneral = collect($this->repartition['parBanque'])->sum('montant'); @endphp

        <button type="button" wire:click="$set('banqueFiltre', '')"
            class="bouton {{ $banqueFiltre === '' ? '' : 'bouton-secondaire' }}"
            @if ($banqueFiltre === '') aria-current="page" @endif
            style="padding:9px 16px;">
            Toutes les banques
            <span style="opacity:.72; font-weight:600;">({{ ae($totalGeneral) }})</span>
        </button>

        @foreach ($this->repartition['parBanque'] as $id => $part)
            @php $actif = (string) $banqueFiltre === (string) $id; @endphp
            <button type="button" wire:click="$set('banqueFiltre', '{{ $id }}')"
                class="bouton {{ $actif ? '' : 'bouton-secondaire' }}"
                @if ($actif) aria-current="page" @endif
                style="padding:9px 16px;">
                {{ $part['banque']->nom }}
                <span style="opacity:.72; font-weight:600;">({{ ae($part['montant']) }})</span>
            </button>
        @endforeach

        @if ($this->repartition['parBanque'] === [])
            <span style="font-size:13px; color:#6B6E76; align-self:center;">
                Aucune banque déclarée ne porte de règlement sur cette période.
            </span>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Encaissé — {{ $this->banqueChoisie?->nom ?? 'toutes banques' }}"
            :value="ae($this->kpis['montant'])" couleur="#0E9F6E"
            :sub="$this->libellePerimetre" />
        <x-kpi-card label="Règlements" :value="number_format($this->kpis['nombre'], 0, ',', ' ')"
            sub="Chèques et virements" />
        <x-kpi-card label="Clients distincts" :value="number_format($this->kpis['clients'], 0, ',', ' ')" />
    </div>

    {{-- ─────────────────────────────── ce qu'on ne sait pas ranger

         **Rendu à part plutôt que fondu dans un total.** C'est la liste à corriger, et elle
         vaut mieux qu'un total faux qui ne dit rien. Chaque ligne porte la raison du refus :
         une faute de frappe ne se corrige pas comme deux banques dans une même case. --}}
    @if ($this->repartition['nonRanges'] !== [])
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #C8102E;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">
                {{ count($this->repartition['nonRanges']) }} libellé(s) ne sont rangés sous aucune banque
            </h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.55;">
                Ils ne sont comptés dans aucun bouton ci-dessus. Les ranger au jugé fausserait des
                totaux sans que rien ne le signale.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Libellé trouvé</th>
                            <th style="text-align:right;">Règlements</th>
                            <th style="text-align:right;">Montant</th>
                            <th>Pourquoi</th>
                            @if ($this->peutDeclarer)
                                <th class="colonne-collee"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->repartition['nonRanges'] as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $ligne['libelle'] ?: '— vide —' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $ligne['nombre'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne['montant']) }}</td>
                                <td style="font-size:12.5px; color:#6B6E76;">{{ $ligne['raison'] }}</td>
                                @if ($this->peutDeclarer)
                                    <td class="colonne-collee" style="white-space:nowrap;">
                                        @if ($ligne['libelle'])
                                            <button type="button" class="bouton bouton-secondaire"
                                                style="padding:3px 9px; font-size:11.5px;"
                                                wire:click="ouvrirLaDeclaration('{{ addslashes($ligne['libelle']) }}')">
                                                Déclarer
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                Règlements ({{ number_format($this->total, 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Client, n° de règlement…" class="champ"
                    style="width:auto; min-width:0; flex:0 1 230px;">

                <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>N° de règlement</th>
                        <th>Client</th>
                        <th>Moyen</th>
                        <th>Banque notée</th>
                        <th>Référence</th>
                        <th class="colonne-collee" style="text-align:right;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->numero ?: '—' }}</td>
                            <td>{{ $ligne->client ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->moyen ?: '—' }}</td>
                            {{-- « Banque notée » et non « Banque » : c'est ce que quelqu'un a
                                 écrit sur la créance, pas ce qu'un relevé confirme. --}}
                            <td style="color:#6B6E76;">{{ $ligne->facture?->banque ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->reference_origine ?: '—' }}</td>
                            <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">
                                {{ ae((int) $ligne->montant) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="7" texte="Aucun règlement bancaire sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->total" prop="page" :par-page="25" />
    </div>
</div>
