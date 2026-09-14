<?php

use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\DossierVehicule;

use function Livewire\Volt\{computed, mount, state};

/**
 * Le parc véhicules : une section d'indicateurs, pas un écran d'import.
 *
 * Elle vivait dans le module Import parce que c'est l'import qui la remplit. C'était une
 * erreur de rangement : le module Import sert à **importer**, et une fois la fiche entrée
 * elle appartient à l'exploitation, comme le chiffre d'affaires ou la trésorerie. On la
 * consulte tous les jours ; on n'importe qu'une fois par semaine.
 */
state([
    // La période porte les mêmes noms que sur les autres écrans de pilotage : le composant
    // de filtre s'y branche alors sans adaptation, et surtout la page se comporte comme ses
    // voisines — c'est ce qui évite qu'on doive réapprendre chaque écran.
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'recherche' => '',
    'villeFiltre' => '',
    'siteFiltre' => '',
    'statutFiltre' => '',
    'motifFiltre' => '',
    'pageDetail' => 1,
]);

$parPage = computed(fn () => 25);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m');
    $this->dateFin ??= now()->format('Y-m');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->pageDetail = 1; };

/**
 * La période regardée, calculée comme partout ailleurs.
 *
 * Elle porte sur la **date de la fiche**, c'est-à-dire la date d'entrée du véhicule.
 * C'est la seule qui existe sur toutes les lignes ; les autres — fin prévue, fin des
 * travaux — sont vides tant que l'affaire n'est pas avancée, et filtrer dessus ferait
 * disparaître précisément les véhicules encore à l'atelier.
 */
$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

/**
 * Le périmètre du lecteur, filtres compris.
 *
 * On passe par le même service que le chiffre d'affaires et la trésorerie : un responsable
 * de site ne voit que son atelier, un superviseur sa ville, le gérant tout. Refaire ce
 * calcul ici aurait donné une page qui montre autre chose que ses voisines — et c'est
 * exactement ainsi que deux écrans finissent par annoncer deux totaux différents.
 */
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre ?: null));

$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre ?: null));

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));

$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));

$mesSites = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre ?: null));

/**
 * Les fiches, filtrées en base.
 *
 * Le périmètre est appliqué ici et non à l'affichage : une fiche hors périmètre ne doit
 * pas compter dans les chiffres du haut, sans quoi le total ne correspondrait pas au
 * tableau qu'on lit en dessous.
 */
$requete = function () {
    // Une fiche sans atelier reste visible pour qui voit sa ville : c'est justement celle
    // qu'il faut affecter, et la cacher reviendrait à cacher le travail qui reste à faire.
    $requete = DossierVehicule::query()
        ->where(fn ($q) => $q->whereIn('site_id', $this->idsSites)
            ->orWhere(fn ($sansSite) => $sansSite->whereNull('site_id')
                ->whereIn('ville_id', $this->idsVilles)))
        ->whereBetween('date_fiche', $this->plage)
        ->latest('date_fiche');

    if (trim($this->recherche) !== '') {
        $motif = '%'.trim($this->recherche).'%';
        $requete->where(fn ($q) => $q->where('numero_fiche', 'like', $motif)
            ->orWhere('immatriculation', 'like', $motif)
            ->orWhere('client', 'like', $motif)
            ->orWhere('proprietaire', 'like', $motif)
            ->orWhere('marque', 'like', $motif)
            ->orWhere('modele', 'like', $motif));
    }

    if ($this->siteFiltre === 'aucun') {
        $requete->whereNull('site_id');
    } elseif ($this->siteFiltre !== '') {
        // Le site demandé doit appartenir au périmètre : une valeur forgée à la main dans
        // l'adresse ne doit pas ouvrir l'atelier du voisin.
        $requete->whereIn('site_id', array_values(array_intersect([(int) $this->siteFiltre], $this->idsSites)) ?: [0]);
    }

    if ($this->statutFiltre !== '' && in_array($this->statutFiltre, DossierVehicule::STATUTS, true)) {
        $requete->where('statut', $this->statutFiltre);
    }

    if ($this->motifFiltre !== '' && in_array($this->motifFiltre, DossierVehicule::MOTIFS, true)) {
        $requete->where('motif', $this->motifFiltre);
    }

    return $requete;
};

$total = computed(fn () => $this->requete()->count());

$page = computed(function () {
    $dernier = max(1, (int) ceil($this->total / $this->parPage));

    return min(max(1, (int) $this->pageDetail), $dernier);
});

$lignes = computed(fn () => $this->requete()->forPage($this->page, $this->parPage)->get());

/** Le décompte par état, sur le même périmètre et les mêmes filtres que le tableau. */
$repartition = computed(fn () => (clone $this->requete())
    ->reorder()
    ->selectRaw('statut, count(*) n')
    ->groupBy('statut')
    ->pluck('n', 'statut'));

/**
 * Les véhicules encore à l'atelier : tout sauf « travaux terminés ».
 *
 * C'est le chiffre que le responsable regarde le matin — combien de voitures j'ai sur les
 * bras. Il ne se déduit d'aucun autre : le total inclut les affaires closes depuis des mois.
 */
$enAtelier = computed(fn () => $this->repartition
    ->reject(fn ($n, $statut) => str_starts_with((string) $statut, 'TRAVAUX TERMINES'))
    ->sum());

$sansAtelier = computed(fn () => (clone $this->requete())->reorder()->whereNull('site_id')->count());

$reinitialiser = function () {
    $this->reset(['recherche', 'villeFiltre', 'siteFiltre', 'statutFiltre', 'motifFiltre',
        'moisFiltre', 'semaineFiltre', 'jourFiltre']);
    $this->pageDetail = 1;
};

$auFiltre = fn () => $this->pageDetail = 1;

$couleurStatut = fn (?string $statut) => match (true) {
    str_starts_with((string) $statut, 'TRAVAUX TERMINES') => '#1E7B34',
    str_starts_with((string) $statut, 'DEVIS VALIDE') => '#B87A00',
    str_starts_with((string) $statut, 'VEHICULE RECEPTIONNE') => '#5A6472',
    default => '#3A5A8C',
};

?>

<div>
    <x-titre-ecran titre="Parc de véhicules"
        sous-titre="Les véhicules reçus à l'atelier, et où en est chaque dossier." />

    {{-- La même barre que sur les autres écrans de pilotage : calendrier ou période,
         ville, atelier. La précision Mécanique/Sinistre est masquée — le parc ne porte pas
         cette notion, il porte un motif de venue, filtré plus bas. --}}
    <x-filtre-periode :periode="$periode" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSites" :site-filtre="$siteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        masquer-activite />

    {{-- ------------------------------------------------------------------ les chiffres --}}
    <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px;">
        <x-kpi-card label="Véhicules à l'atelier" :value="number_format($this->enAtelier, 0, ',', ' ')"
            sub="affaires non clôturées" accent />
        <x-kpi-card label="Fiches au total" :value="number_format($this->total, 0, ',', ' ')"
            :sub="'sur la période retenue'" />
        <x-kpi-card label="Travaux terminés"
            :value="number_format($this->repartition->filter(fn ($n, $s) => str_starts_with((string) $s, 'TRAVAUX TERMINES'))->sum(), 0, ',', ' ')"
            sub="véhicules livrés" bon />
        <x-kpi-card label="Sans atelier affecté" :value="number_format($this->sansAtelier, 0, ',', ' ')"
            sub="Abidjan a deux sites" :couleur="$this->sansAtelier > 0 ? '#C8102E' : null" />
    </div>

    {{-- Le détail par étape du parcours, qui répond à « où ça coince ? ». --}}
    <x-carte-section titre="Le parc par étape" icone="atelier">
        <div style="display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px;">
            @foreach (DossierVehicule::STATUTS as $statut)
                @php $n = (int) ($this->repartition[$statut] ?? 0); @endphp
                <button type="button" wire:click="$set('statutFiltre', @js($statutFiltre === $statut ? '' : $statut))"
                    style="text-align:left; cursor:pointer; background:{{ $statutFiltre === $statut ? '#FCF0F2' : '#fff' }};
                           border:1px solid {{ $statutFiltre === $statut ? '#C8102E' : 'var(--th-ligne,#E2E0D8)' }};
                           border-left:4px solid {{ $this->couleurStatut($statut) }};
                           border-radius:10px; padding:11px 13px; font-family:inherit;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#6B6E76;
                                font-weight:700; line-height:1.35; min-height:30px;">
                        {{ \Illuminate\Support\Str::of($statut)->before('/')->trim() }}
                    </div>
                    <div style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;
                                font-variant-numeric:tabular-nums;">{{ number_format($n, 0, ',', ' ') }}</div>
                    <div style="font-size:11px; color:#6B6E76;">
                        {{ \Illuminate\Support\Str::of($statut)->after('/')->trim() }}
                    </div>
                </button>
            @endforeach
        </div>
    </x-carte-section>

    {{-- ------------------------------------------------------------------------ filtres --}}
    <x-carte-section titre="Filtrer" icone="liste">
        <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px;">
            <div style="grid-column:span 2;">
                <label for="q" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">
                    Fiche, immatriculation, client, marque…
                </label>
                <input type="search" id="q" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}" wire:input="auFiltre"
                    placeholder="FR-KZN° 010669, AA598AZ, WILLIS…"
                    style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:14px; background:var(--th-champ,#FFFBEA);">
            </div>
            @if ($this->mesVilles)
                <div>
                    <label for="v" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Ville</label>
                    <select id="v" wire:model.live="villeFiltre" wire:change="auFiltre"
                        style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:14px; background:var(--th-champ,#FFFBEA);">
                        <option value="" @selected($villeFiltre === '')>Toutes</option>
                        @foreach ($this->mesVilles as $uneVille)
                            <option value="{{ $uneVille->id }}" @selected((string) $villeFiltre === (string) $uneVille->id)>{{ $uneVille->nom }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif ($this->villeUnique)
                {{-- Hors gérant, la ville est connue d'avance : on l'affiche sans en faire
                     un choix, plutôt que de proposer une liste à une seule entrée. --}}
                <div>
                    <label style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Ville</label>
                    <div style="padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:14px; background:#F4F2EC; color:#6B6E76;">
                        {{ $this->villeUnique->nom }}
                    </div>
                </div>
            @endif
            <div>
                <label for="s" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Atelier</label>
                <select id="s" wire:model.live="siteFiltre" wire:change="auFiltre"
                    style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:14px; background:var(--th-champ,#FFFBEA);">
                    <option value="" @selected($siteFiltre === '')>Tous</option>
                    <option value="aucun" @selected((string) $siteFiltre === 'aucun')>— non affecté —</option>
                    @foreach ($this->mesSites ?? [] as $unSite)
                        <option value="{{ $unSite->id }}" @selected((string) $siteFiltre === (string) $unSite->id)>{{ $unSite->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="mo" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Motif de venue</label>
                <select id="mo" wire:model.live="motifFiltre" wire:change="auFiltre"
                    style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:14px; background:var(--th-champ,#FFFBEA);">
                    <option value="" @selected($motifFiltre === '')>Tous</option>
                    @foreach (DossierVehicule::MOTIFS as $motif)
                        <option value="{{ $motif }}" @selected((string) $motifFiltre === (string) $motif)>{{ $motif }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Le statut se choisissait uniquement en cliquant l'une des cinq cartes du
                 haut. C'était rapide mais incomplet : on ne pouvait pas le combiner de tête
                 avec les autres filtres, et surtout on ne voyait pas qu'il était filtrable.
                 Les deux gestes coexistent maintenant et se reflètent l'un l'autre. --}}
            <div>
                <label for="st" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Statut</label>
                <select id="st" wire:model.live="statutFiltre" wire:change="auFiltre"
                    style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:14px; background:var(--th-champ,#FFFBEA);">
                    <option value="" @selected($statutFiltre === '')>Tous</option>
                    @foreach (DossierVehicule::STATUTS as $statut)
                        <option value="{{ $statut }}" @selected((string) $statutFiltre === (string) $statut)>{{ $statut }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="margin-top:11px;">
            <button type="button" wire:click="reinitialiser"
                style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; padding:6px 13px; font-size:12.5px; font-weight:600; cursor:pointer; color:#4B4E55;">
                Tout effacer
            </button>
        </div>
    </x-carte-section>

    {{-- ------------------------------------------------------------------------ le parc --}}
    <x-carte-section titre="Fiches de réception" icone="atelier">
        <div style="overflow:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                <thead>
                    <tr>
                        {{-- Pas de colonne de rang : le numéro de fiche est déjà l'identité
                             de la ligne, et il est parlant. Deux numéros côte à côte
                             obligeraient à se demander lequel compte.

                             Les intitulés sont **ceux du logiciel d'atelier**, à la lettre.
                             « Véhicule » réunissait auparavant l'immatriculation, la marque
                             et le modèle en une seule colonne inventée : plus compact, mais
                             introuvable pour qui a la fiche sous les yeux dans le logiciel.
                             Ville et Atelier sont les deux seules colonnes qui nous
                             appartiennent — le logiciel ne les a pas. --}}
                        <th style="text-align:left; padding:7px 9px;">N° FICHE RECEPTION</th>
                        <th style="text-align:left; padding:7px 9px;">DATE DE LA FICHE</th>
                        <th style="text-align:left; padding:7px 9px;">IMMAT. VEHICULE</th>
                        <th style="text-align:left; padding:7px 9px;">MARQUE</th>
                        <th style="text-align:left; padding:7px 9px;">MODELE</th>
                        <th style="text-align:left; padding:7px 9px;">CLIENTS / ASSURANCES</th>
                        <th style="text-align:left; padding:7px 9px;">STATUT</th>
                        <th style="text-align:left; padding:7px 9px;">Ville</th>
                        <th style="text-align:left; padding:7px 9px;">Atelier</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $dossier)
                        <tr wire:key="fiche-{{ $dossier->id }}" style="border-top:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="padding:6px 9px; white-space:nowrap; font-family:ui-monospace,Consolas,monospace; font-size:12px;">
                                {{ $dossier->numero_fiche }}
                            </td>
                            <td style="padding:6px 9px; white-space:nowrap;">{{ $dossier->date_fiche?->format('d/m/Y') ?? '—' }}</td>
                            <td style="padding:6px 9px; white-space:nowrap;"><strong>{{ $dossier->immatriculation ?? '—' }}</strong></td>
                            <td style="padding:6px 9px;">{{ $dossier->marque ?? '—' }}</td>
                            <td style="padding:6px 9px;">{{ $dossier->modele ?? '—' }}</td>
                            <td style="padding:6px 9px; max-width:210px;">{{ $dossier->client ?? '—' }}</td>
                            <td style="padding:6px 9px;">
                                <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px;
                                             font-weight:600; white-space:nowrap; color:#fff;
                                             background:{{ $this->couleurStatut($dossier->statut) }};">
                                    {{ \Illuminate\Support\Str::of($dossier->statut ?? '—')->before('/')->trim() }}
                                </span>
                            </td>
                            <td style="padding:6px 9px;">{{ $dossier->ville?->nom ?? '—' }}</td>
                            <td style="padding:6px 9px;">
                                {{ $dossier->site?->nom ?? '' }}
                                @unless ($dossier->site)
                                    <span style="color:#C8102E; font-size:11.5px; font-weight:600;">non affecté</span>
                                @endunless
                            </td>
                            <td style="padding:6px 9px;">
                                <a href="{{ route('parc-fiche', $dossier->id) }}" wire:navigate
                                    style="display:inline-block; background:#fff; text-decoration:none; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; padding:4px 11px; font-size:12.5px; font-weight:600; cursor:pointer; color:#4B4E55;">
                                    Détails
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="10" texte="Aucune fiche de réception ne correspond." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$this->page" :total="$this->total" prop="pageDetail" :par-page="$this->parPage" />
    </x-carte-section>
</div>
