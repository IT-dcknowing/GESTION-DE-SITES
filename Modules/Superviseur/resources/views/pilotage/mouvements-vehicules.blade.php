<?php

use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\MouvementVehicule;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Entrées et sorties de véhicules
|--------------------------------------------------------------------------
| Les deux états du logiciel d'atelier — « Liste véhicules entrées » et « Liste
| véhicules sorties » — s'importaient depuis un moment et **ne s'affichaient nulle
| part**. Cent quarante-sept lignes en base, aucun écran pour les lire : c'est le
| genre d'oubli qui fait douter de tout le reste, parce qu'on ne peut pas vérifier
| que l'import a bien fait ce qu'il annonce.
|
| Un seul écran pour les deux sens, et pas deux : la question qu'on se pose devant
| un atelier est « qu'est-ce qui est entré et qu'est-ce qui est ressorti cette
| semaine », et la comparaison ne se fait pas en changeant de page. Le sens est un
| filtre, au même titre que la ville.
|
| L'écart entre les deux — entrées moins sorties — est le chiffre qui compte : c'est
| le nombre de véhicules encore immobilisés. Il est affiché en haut, et non laissé à
| calculer de tête.
*/

state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'recherche' => '',
    'villeFiltre' => '',
    'siteFiltre' => '',
    'sensFiltre' => '',
    'pageDetail' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$parPage = computed(fn () => 30);

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedRecherche = function () { $this->pageDetail = 1; };
$updatedSensFiltre = function () { $this->pageDetail = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre ?: null));

$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre ?: null));

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));

$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));

$mesSites = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre ?: null));

/**
 * Le mouvement, filtré en base et dans le périmètre du lecteur.
 *
 * Comme sur le parc, une ligne **sans atelier** reste visible pour qui voit sa ville :
 * c'est celle qu'il faut affecter, et la cacher reviendrait à cacher le travail restant.
 */
$requete = function () {
    $requete = MouvementVehicule::query()
        ->where(fn ($q) => $q->whereIn('site_id', $this->idsSites)
            ->orWhere(fn ($sansSite) => $sansSite->whereNull('site_id')
                ->whereIn('ville_id', $this->idsVilles)))
        ->whereBetween('date', $this->plage)
        ->latest('date');

    if (trim($this->recherche) !== '') {
        $motif = '%'.trim($this->recherche).'%';
        $requete->where(fn ($q) => $q->where('numero_fiche', 'like', $motif)
            ->orWhere('immatriculation', 'like', $motif)
            ->orWhere('client', 'like', $motif)
            ->orWhere('marque', 'like', $motif)
            ->orWhere('modele', 'like', $motif));
    }

    if ($this->siteFiltre === 'aucun') {
        $requete->whereNull('site_id');
    } elseif ($this->siteFiltre !== '') {
        // Le site demandé doit appartenir au périmètre : une valeur forgée dans l'adresse
        // ne doit pas ouvrir l'atelier du voisin.
        $requete->whereIn('site_id', array_values(array_intersect([(int) $this->siteFiltre], $this->idsSites)) ?: [0]);
    }

    if (in_array($this->sensFiltre, [MouvementVehicule::ENTREE, MouvementVehicule::SORTIE], true)) {
        $requete->where('sens', $this->sensFiltre);
    }

    return $requete;
};

$total = computed(fn () => $this->requete()->count());

/** Le décompte par sens, sur exactement le même périmètre que le tableau. */
$parSens = computed(fn () => (clone $this->requete())
    ->reorder()
    ->selectRaw('sens, count(*) n')
    ->groupBy('sens')
    ->pluck('n', 'sens'));

$entrees = computed(fn () => (int) ($this->parSens[MouvementVehicule::ENTREE] ?? 0));

$sorties = computed(fn () => (int) ($this->parSens[MouvementVehicule::SORTIE] ?? 0));

$sansAtelier = computed(fn () => (clone $this->requete())->reorder()->whereNull('site_id')->count());

$page = computed(function () {
    $dernier = max(1, (int) ceil($this->total / $this->parPage));

    return min(max(1, (int) $this->pageDetail), $dernier);
});

$lignes = computed(fn () => $this->requete()->with(['site', 'ville'])->forPage($this->page, $this->parPage)->get());

?>

<div>
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;
                       text-transform:uppercase; letter-spacing:1px; margin:0; color:var(--th-steel,#2A2E35);">
                Entrées & sorties de véhicules
            </h1>
            <div style="color:var(--th-gris,#6B6E76); font-size:13.5px; margin-top:3px;">
                Les deux états du logiciel d'atelier, réunis — ce qui est entré, ce qui est ressorti.
            </div>
        </div>
    </div>

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSites" :site-filtre="$siteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        masquer-activite />

    {{-- L'écart est le chiffre qui compte : entrées moins sorties, c'est ce qui est encore
         immobilisé. Le laisser calculer de tête, c'est le laisser non calculé. --}}
    <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px;">
        <x-kpi-card label="Entrées" :value="number_format($this->entrees, 0, ',', ' ')"
            sub="véhicules reçus sur la période" />
        <x-kpi-card label="Sorties" :value="number_format($this->sorties, 0, ',', ' ')"
            sub="véhicules livrés sur la période" bon />
        <x-kpi-card label="Écart" :value="number_format($this->entrees - $this->sorties, 0, ',', ' ')"
            sub="entrés et non ressortis" :accent="$this->entrees - $this->sorties > 0" />
        <x-kpi-card label="Sans atelier affecté" :value="number_format($this->sansAtelier, 0, ',', ' ')"
            :sub="$this->sansAtelier > 0 ? 'à rattacher — voir « À traiter »' : 'tout est rattaché'"
            :accent="$this->sansAtelier > 0" />
    </div>

    <div class="carte">
        <h3 class="titre-section">Filtrer</h3>

        <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; align-items:end; margin-bottom:14px;">
            <div>
                <label for="q" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">
                    Rechercher
                </label>
                <input type="search" id="q" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}"
                       placeholder="N° de fiche, immatriculation, client, marque…"
                       style="width:100%; box-sizing:border-box; border:1px solid #E3E0D8; border-radius:6px;
                              padding:8px 10px; font-size:14px; background:var(--th-champ,#FFFBEA); font-family:inherit;">
            </div>
            <div>
                <label for="sens" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Sens</label>
                <select id="sens" wire:model.live="sensFiltre"
                        style="width:100%; box-sizing:border-box; border:1px solid #E3E0D8; border-radius:6px;
                               padding:8px 10px; font-size:14px; background:var(--th-champ,#FFFBEA); font-family:inherit;">
                    <option value="" @selected($sensFiltre === '')>Entrées et sorties</option>
                    <option value="{{ MouvementVehicule::ENTREE }}" @selected($sensFiltre === MouvementVehicule::ENTREE)>Entrées seulement</option>
                    <option value="{{ MouvementVehicule::SORTIE }}" @selected($sensFiltre === MouvementVehicule::SORTIE)>Sorties seulement</option>
                </select>
            </div>
        </div>

        {{-- Les intitulés sont ceux du logiciel d'atelier, sans traduction : c'est ce qui
             permet de poser les deux écrans côte à côte et de vérifier ligne à ligne. --}}
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Sens</th>
                        <th>Date</th>
                        <th>N° fiche réception</th>
                        <th>Immat. véhicule</th>
                        <th>Marque</th>
                        <th>Modèle</th>
                        <th>Clients / assurances</th>
                        <th>Atelier</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $mouvement)
                        <tr wire:key="mv-{{ $mouvement->id }}">
                            <td>
                                <span class="pastille {{ $mouvement->sens === MouvementVehicule::ENTREE ? 'pastille-bleu' : 'pastille-vert' }}">
                                    {{ $mouvement->sens === MouvementVehicule::ENTREE ? 'Entrée' : 'Sortie' }}
                                </span>
                            </td>
                            <td>{{ $mouvement->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>
                                <x-numero-ligne :ligne="$mouvement" :numero="$mouvement->numero_fiche" />
                            </td>
                            <td style="font-weight:600;">{{ $mouvement->immatriculation ?: '—' }}</td>
                            <td>{{ $mouvement->marque ?: '—' }}</td>
                            <td>{{ $mouvement->modele ?: '—' }}</td>
                            <td>{{ $mouvement->client ?: '—' }}</td>
                            <td>
                                @if ($mouvement->site)
                                    {{ $mouvement->site->nom }}
                                @elseif ($mouvement->ville)
                                    <span style="color:#6B6E76;">{{ $mouvement->ville->nom }} — atelier non affecté</span>
                                @else
                                    <span class="pastille pastille-rouge">à rattacher</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="8"
                            texte="Aucun mouvement sur cette période. Les entrées et sorties viennent de l'import — déposez « Liste véhicules entrées » et « Liste véhicules sorties »." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->total > $this->parPage)
            <div style="display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap;">
                <button type="button" class="bouton bouton-secondaire bouton-petit"
                        wire:click="$set('pageDetail', {{ max(1, $this->page - 1) }})"
                        @disabled($this->page <= 1)>Précédent</button>
                <span style="font-size:12.5px; color:#6B6E76;">
                    Page {{ $this->page }} sur {{ max(1, (int) ceil($this->total / $this->parPage)) }}
                    — {{ number_format($this->total, 0, ',', ' ') }} mouvement(s)
                </span>
                <button type="button" class="bouton bouton-secondaire bouton-petit"
                        wire:click="$set('pageDetail', {{ $this->page + 1 }})"
                        @disabled($this->page >= (int) ceil($this->total / $this->parPage))>Suivant</button>
            </div>
        @endif
    </div>
</div>
