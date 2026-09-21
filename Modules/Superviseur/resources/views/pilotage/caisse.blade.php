<?php

use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;

use function Livewire\Volt\{computed, mount, state};

/**
 * Les états de caisse repris du logiciel d'atelier.
 *
 * **Pourquoi cet écran existe.** Le fichier « États de caisse » était lu, ses mille cent
 * cinquante-cinq mouvements étaient en base depuis le 8 septembre — et aucun écran ne les
 * affichait. Une donnée importée que personne ne peut voir n'a pas été importée : elle a
 * été rangée. Le travail de dépôt, de contrôle et de correction ne servait à rien tant que
 * la dernière marche manquait.
 *
 * **Le périmètre se lit par ville, pas par atelier.** Le fichier ne porte pas l'atelier :
 * les mille cent cinquante-cinq lignes ont une ville et aucune n'a de site. Filtrer par
 * site aurait rendu l'écran vide, ce qui aurait ressemblé à une panne alors que c'est le
 * fichier qui ne le dit pas.
 *
 * **Le solde annoncé est montré à côté du nôtre, jamais à sa place.** Le fichier porte son
 * propre solde courant ; on le recopie sans le corriger. Un écart entre les deux n'est pas
 * une erreur de calcul de notre côté, c'est le signe qu'une ligne a été retouchée à la main
 * dans le classeur — et c'est précisément ce qu'on veut pouvoir montrer.
 */
state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'villeFiltre' => '',
    'sensFiltre' => '',
    'recherche' => '',
    'pageDetail' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedVilleFiltre = function () { $this->pageDetail = 1; };
$updatedSensFiltre = function () { $this->pageDetail = 1; };
$updatedRecherche = function () { $this->pageDetail = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

/**
 * Le périmètre nu : la ville et la période, rien d'autre.
 *
 * Séparé des filtres de confort à dessein. Le rapprochement du solde suit une chaîne de
 * mouvements dans l'ordre où ils se sont produits ; la calculer sur une liste réduite à
 * « sorties seulement » ou au résultat d'une recherche donnerait un écart qui ne dirait
 * rien d'autre que « vous avez filtré ».
 */
$perimetre = computed(function () {
    [$debut, $fin] = $this->plage;

    return MouvementCaisse::query()
        ->whereIn('ville_id', $this->idsVilles)
        ->whereBetween('date', [$debut, $fin]);
});

$requete = computed(fn () => (clone $this->perimetre)
    ->when($this->sensFiltre, fn ($q) => $q->where('sens', $this->sensFiltre))
    ->when(trim($this->recherche) !== '', function ($q) {
        $terme = '%'.trim($this->recherche).'%';

        $q->where(fn ($sous) => $sous->where('libelle', 'like', $terme)
            ->orWhere('beneficiaire', 'like', $terme)
            ->orWhere('immatriculation', 'like', $terme));
    }));

$kpis = computed(function () {
    $entrees = (int) (clone $this->requete)->where('sens', MouvementCaisse::ENTREE)->sum('montant');
    $sorties = (int) (clone $this->requete)->where('sens', MouvementCaisse::SORTIE)->sum('montant');

    return [
        'entrees' => $entrees,
        'sorties' => $sorties,
        'solde' => $entrees - $sorties,
        'lignes' => (clone $this->requete)->count(),
    ];
});

/**
 * Le solde du fichier, refait plutôt que recopié.
 *
 * **Ce qui n'allait pas.** L'écran prenait le solde courant de la dernière ligne et le
 * posait à côté du total entrées moins sorties de la période, en appelant écart la
 * différence entre les deux. Ce n'en était pas un : le solde courant du classeur part d'un
 * fonds de caisse déjà présent avant la période, tandis que notre total ne compte que ce
 * qui a bougé pendant. Deux nombres qui ne mesurent pas la même chose ne peuvent pas
 * diverger — ils n'ont jamais été d'accord.
 *
 * **Ce qu'on fait maintenant.** On part du solde que le fichier lui-même annonce sur sa
 * première ligne de la période, on lui applique un à un tous les mouvements qui suivent,
 * et on compare le résultat au solde de la dernière ligne. Là, les deux nombres mesurent
 * exactement la même chose, et l'écart devient un vrai renseignement : quelque part entre
 * les deux, le solde du classeur a sauté sans qu'un mouvement l'explique — une ligne
 * retouchée à la main, un apport d'espèces non saisi, ou un report entre deux feuillets.
 *
 * Rendu seulement quand il y a quelque chose à dire : quand les deux coïncident, il n'y a
 * pas d'écart à montrer, et un indicateur qui répète « tout va bien » cesse d'être lu.
 *
 * @return array{depart: MouvementCaisse, arrivee: MouvementCaisse, attendu: int, annonce: int, ecart: int}|null
 */
$rapprochement = computed(function () {
    $annonces = fn () => (clone $this->perimetre)->whereNotNull('solde_annonce');

    $depart = $annonces()->orderBy('date')->orderBy('id')->first();
    $arrivee = $annonces()->orderByDesc('date')->orderByDesc('id')->first();

    // Une seule ligne annoncée ne fait pas une chaîne : il n'y a rien à vérifier.
    if ($depart === null || $arrivee === null || $depart->id === $arrivee->id) {
        return null;
    }

    // Tout ce qui vient après la ligne de départ. Elle-même est exclue : le solde qu'elle
    // annonce la comprend déjà, et la recompter la ferait compter deux fois.
    $jourDepart = $depart->date->toDateString();

    $suite = (clone $this->perimetre)->where(fn ($q) => $q
        ->whereDate('date', '>', $jourDepart)
        ->orWhere(fn ($memeJour) => $memeJour->whereDate('date', $jourDepart)->where('id', '>', $depart->id)));

    $attendu = (int) $depart->solde_annonce
        + (int) (clone $suite)->where('sens', MouvementCaisse::ENTREE)->sum('montant')
        - (int) (clone $suite)->where('sens', MouvementCaisse::SORTIE)->sum('montant');

    $annonce = (int) $arrivee->solde_annonce;

    if ($annonce === $attendu) {
        return null;
    }

    // La phrase est montée ici plutôt que dans le gabarit : elle porte des apostrophes,
    // et une apostrophe dans une expression d'attribut Blade casse la compilation.
    $phrase = sprintf(
        "Solde du %s suivi mouvement par mouvement jusqu'au %s. Le solde du classeur a sauté sans qu'un mouvement l'explique.",
        $depart->date->format('d/m/Y'),
        $arrivee->date->format('d/m/Y'),
    );

    return compact('depart', 'arrivee', 'attendu', 'annonce', 'phrase') + ['ecart' => $annonce - $attendu];
});

/** Les plus grosses sorties : c'est là que se joue la caisse, pas dans les petites lignes. */
$grossesSorties = computed(fn () => (clone $this->requete)
    ->where('sens', MouvementCaisse::SORTIE)
    ->selectRaw('libelle, count(*) as nombre, sum(montant) as total')
    ->groupBy('libelle')->orderByDesc('total')->limit(8)->get());

$detail = computed(fn () => (clone $this->requete)
    ->with('ville')
    ->orderByDesc('date')->orderByDesc('id')
    ->paginate(25, ['*'], 'pageDetail', $this->pageDetail));

?>

<div>
    <x-titre-ecran titre="Caisse"
        sous-titre="Les entrées et les sorties d'espèces, reprises des états de caisse de l'atelier." />

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="null" :site-filtre="null"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    {{-- Trois indicateurs, et un quatrième seulement quand le rapprochement a quelque
         chose à dire. La grille s'adapte d'elle-même au nombre de cartes. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Entrées — {{ $this->libellePerimetre }}" :value="ae($this->kpis['entrees'])"
            :sub="$this->kpis['lignes'].' mouvement(s) sur la période'" />
        <x-kpi-card label="Sorties" :value="ae($this->kpis['sorties'])" couleur="#C8102E" />

        {{-- « Mouvement net » et non « solde » : ce nombre dit de combien la caisse a varié
             pendant la période, pas ce qu'elle contient. C'est la confusion entre les deux
             qui faisait passer un fonds de caisse d'avant la période pour une anomalie. --}}
        <x-kpi-card label="Mouvement net de la période" :value="ae($this->kpis['solde'])"
            :couleur="$this->kpis['solde'] >= 0 ? '#0E9F6E' : '#C8102E'" sub="Entrées − sorties" />

        @if ($this->rapprochement)
            <x-kpi-card label="Écart avec le fichier"
                :value="ae(abs($this->rapprochement['ecart']))" :accent="true"
                :lignes="[
                    'Le fichier annonce' => ae($this->rapprochement['annonce']),
                    'Notre calcul donne' => ae($this->rapprochement['attendu']),
                ]"
                :sub="$this->rapprochement['phrase']" />
        @endif
    </div>

    @if ($this->grossesSorties->isNotEmpty())
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Où part l'argent — huit premiers postes</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Libellé</th>
                            <th style="text-align:right;">Mouvements</th>
                            <th style="text-align:right;">Total</th>
                            <th style="text-align:right;">Part des sorties</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->grossesSorties as $poste)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $poste->libelle ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $poste->nombre }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae((int) $poste->total) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                    {{ $this->kpis['sorties'] > 0 ? round($poste->total / $this->kpis['sorties'] * 100) : 0 }} %
                                </td>
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
                Mouvements ({{ number_format($this->detail->total(), 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Libellé, bénéficiaire, immatriculation…" class="champ" style="min-width:260px;">

                <select wire:model.live="sensFiltre" class="champ">
                    <option value="" @selected($sensFiltre === '')>Entrées et sorties</option>
                    <option value="entree" @selected($sensFiltre === 'entree')>Entrées seulement</option>
                    <option value="sortie" @selected($sensFiltre === 'sortie')>Sorties seulement</option>
                </select>
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Sens</th>
                        <th>Libellé</th>
                        <th>Bénéficiaire</th>
                        <th>Immatriculation</th>
                        <th>Ville</th>
                        <th style="text-align:right;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>
                                <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600;
                                    background:{{ $ligne->sens === 'entree' ? '#E5F2E8' : '#FCF0F2' }};
                                    color:{{ $ligne->sens === 'entree' ? '#1E7B34' : '#C8102E' }};">
                                    {{ $ligne->sens === 'entree' ? 'Entrée' : 'Sortie' }}
                                </span>
                            </td>
                            <td>{{ $ligne->libelle ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->beneficiaire ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->immatriculation ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->ville?->nom ?? '—' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne->sens === 'entree' ? '#1E7B34' : '#C8102E' }};">
                                {{ ae($ligne->montant) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="7"
                            texte="Aucun mouvement de caisse sur cette période. Les états de caisse se déposent depuis le module Import." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:12px;">{{ $this->detail->links() }}</div>
    </div>
</div>
