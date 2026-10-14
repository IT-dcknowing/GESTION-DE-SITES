<?php

use Illuminate\Support\Carbon;
use Modules\Noyau\Commun\Services\FormeComparable;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use function Livewire\Volt\{state, computed};

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * L'affichage général des prospections — 14/10.
 *
 * « Créer une page appelée affichage général, où tous les commerciaux voient les prospections
 * de tous, de sorte à motiver et à éviter qu'ils refassent les mêmes prospections. »
 *
 * Deux usages, donc deux parties :
 *
 * - **motiver** : le classement du mois, par commercial — prospections, passages, devis ;
 * - **ne pas refaire** : la liste de toutes les prospections, et une recherche par client ou par
 *   plaque qui porte sur **tout l'historique**, pas sur la seule période — c'est avant d'aller
 *   voir un client qu'on se demande si un collègue y est déjà passé. Chaque ligne dit si le même
 *   client ou la même plaque a déjà été prospecté, et combien de fois.
 *
 * Ce qui se montre : ce qu'il faut pour se reconnaître — date, commercial, client, lieu, plaque,
 * moyen, passage, devis. Ce qui ne se montre pas : les observations et commentaires, qui sont les
 * notes de travail d'un commercial et n'ont pas à circuler. Les brouillons non plus : seules les
 * prospections transmises ou validées (`Prospection::visibles`) paraissent.
 *
 * Toute l'entreprise, toutes les villes : un client de flotte se prospecte à Abidjan comme à
 * Bouaké, et c'est entre villes que les doublons de démarche coûtent le plus. Le filtre par ville
 * reste là pour qui veut regarder la sienne.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
state([
    'mois' => null,
    'villeFiltre' => '',
    'commercialFiltre' => '',
    'recherche' => '',
    'page' => 1,
]);

$updatedMois = function () { $this->page = 1; };
$updatedVilleFiltre = function () { $this->page = 1; $this->commercialFiltre = ''; };
$updatedCommercialFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };

$moisRegarde = computed(fn () => $this->mois && preg_match('/^\d{4}-\d{2}$/', $this->mois)
    ? Carbon::createFromFormat('Y-m', $this->mois)->startOfMonth()
    : now()->startOfMonth());

$moisProposes = computed(function () {
    $options = [];

    for ($i = 0; $i < 12; $i++) {
        $mois = now()->startOfMonth()->subMonths($i);
        $options[$mois->format('Y-m')] = ucfirst($mois->locale('fr')->translatedFormat('F Y'));
    }

    return $options;
});

$villes = computed(fn () => Ville::query()->orderBy('nom')->pluck('nom', 'id')->all());

$commerciaux = computed(fn () => Commercial::query()
    ->where('est_spontane', false)
    ->when($this->villeFiltre !== '', fn ($q) => $q->where('ville_id', (int) $this->villeFiltre))
    ->orderBy('nom')->pluck('nom', 'id')->all());

/** Les prospections montrées : le mois choisi — ou tout l'historique dès qu'on cherche un client ou une plaque. */
$requete = computed(function () {
    $terme = trim($this->recherche);

    return Prospection::query()->visibles()
        ->when($this->villeFiltre !== '', fn ($q) => $q->whereHas('commercial', fn ($c) => $c->where('ville_id', (int) $this->villeFiltre)))
        ->when($this->commercialFiltre !== '', fn ($q) => $q->where('commercial_id', (int) $this->commercialFiltre))
        ->when($terme === '', fn ($q) => $q->whereBetween('date', [
            $this->moisRegarde->toDateString(), $this->moisRegarde->copy()->endOfMonth()->toDateString(),
        ]))
        ->when($terme !== '', fn ($q) => $q->where(fn ($sous) => $sous
            ->where('client', 'like', '%'.$terme.'%')
            // La plaque se compare sans ses séparateurs : « aa 111 bb » retrouve « AA-111-BB ».
            ->when(FormeComparable::plaque($terme) !== '', fn ($p) => $p->orWhereRaw(
                "replace(replace(replace(upper(immatriculation), '-', ''), ' ', ''), '.', '') like ?",
                ['%'.FormeComparable::plaque($terme).'%'],
            ))
            ->orWhere('localisation', 'like', '%'.$terme.'%')));
});

$nombre = computed(fn () => (clone $this->requete)->count());

$lignes = computed(fn () => (clone $this->requete)
    ->with(['commercial.ville:id,nom', 'site:id,nom', 'devis:id,prospection_id'])
    ->orderByDesc('date')->orderByDesc('id')
    ->forPage($this->page, 25)->get());

/**
 * Combien de fois chaque client et chaque plaque ont été prospectés, sur tout l'historique de
 * l'entreprise. Deux colonnes seulement, lues une fois : c'est ce qui permet de dire, ligne à
 * ligne, « déjà vu ».
 *
 * @return array{clients: array<string, int>, plaques: array<string, int>}
 */
$dejaVus = computed(function () {
    $clients = [];
    $plaques = [];

    foreach (Prospection::query()->visibles()->toBase()->select(['client', 'immatriculation'])->cursor() as $p) {
        if (($cle = FormeComparable::nom($p->client)) !== '') {
            $clients[$cle] = ($clients[$cle] ?? 0) + 1;
        }

        if (($cle = FormeComparable::plaque($p->immatriculation)) !== '') {
            $plaques[$cle] = ($plaques[$cle] ?? 0) + 1;
        }
    }

    return ['clients' => $clients, 'plaques' => $plaques];
});

/** Le classement du mois : ce qui motive, c'est de se voir à côté des autres. */
$classement = computed(fn () => Prospection::query()->visibles()
    ->whereBetween('date', [$this->moisRegarde->toDateString(), $this->moisRegarde->copy()->endOfMonth()->toDateString()])
    ->when($this->villeFiltre !== '', fn ($q) => $q->whereHas('commercial', fn ($c) => $c->where('ville_id', (int) $this->villeFiltre)))
    ->whereNotNull('commercial_id')
    ->selectRaw('commercial_id, count(*) as prospections')
    ->selectRaw('sum(case when passage then 1 else 0 end) as passages')
    ->selectRaw('sum(case when devis_apres_passage then 1 else 0 end) as devis')
    ->groupBy('commercial_id')
    ->orderByDesc('prospections')
    ->with('commercial.ville:id,nom')
    ->get());

$moi = computed(fn () => Commercial::query()->where('user_id', auth()->id())->value('id'));

?>

<div>
    <x-titre-ecran titre="Affichage général des prospections"
        sous-titre="Les prospections de toute l'équipe : pour se motiver, et pour ne pas aller voir un client qu'un collègue a déjà vu." />

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px;">
        <x-champ label="Rechercher un client ou une plaque (tout l'historique)" model="recherche" :live="true" width="320" placeholder="Nom du client, AB-123-CD, quartier…" />
        <x-champ label="Mois" model="mois" type="select" width="180" :options="$this->moisProposes" vide="Ce mois-ci" />
        <x-champ label="Ville" model="villeFiltre" type="select" width="180" :options="$this->villes" vide="Toutes les villes" />
        <x-champ label="Commercial" model="commercialFiltre" type="select" width="200" :options="$this->commerciaux" vide="Tous" />
    </div>

    @if (trim($recherche) !== '')
        <div class="carte" style="margin-bottom:14px; border-left:4px solid {{ $this->nombre > 0 ? '#B45309' : '#0E9F6E' }};">
            @if ($this->nombre > 0)
                <b>Déjà prospecté :</b> {{ $this->nombre }} prospection(s) répondent à « {{ $recherche }} », toutes périodes confondues — voyez qui y est allé avant d'y retourner.
            @else
                <b>Personne n'a encore prospecté « {{ $recherche }} ».</b> La voie est libre.
            @endif
        </div>
    @endif

    {{-- ─────────────── le classement du mois --}}
    <div class="carte" style="margin-bottom:16px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Le classement — {{ ucfirst($this->moisRegarde->locale('fr')->translatedFormat('F Y')) }}</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr><th>#</th><th>Commercial</th><th>Ville</th><th style="text-align:right;">Prospections</th><th style="text-align:right;">Passages</th><th style="text-align:right;">Devis après passage</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->classement as $rang => $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); {{ $ligne->commercial_id === $this->moi ? 'background:#FFF7ED; font-weight:700;' : '' }}">
                            <td>{{ $rang + 1 }}{{ $rang === 0 ? ' 🏆' : '' }}</td>
                            <td>{{ $ligne->commercial?->nom ?? '—' }}{{ $ligne->commercial_id === $this->moi ? ' (vous)' : '' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->commercial?->ville?->nom ?? '—' }}</td>
                            <td style="text-align:right;">{{ (int) $ligne->prospections }}</td>
                            <td style="text-align:right;">{{ (int) $ligne->passages }}</td>
                            <td style="text-align:right;">{{ (int) $ligne->devis }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="6" texte="Aucune prospection transmise ce mois-ci." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─────────────── toutes les prospections --}}
    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">
            {{ trim($recherche) !== '' ? 'Résultats de la recherche' : 'Toutes les prospections du mois' }} ({{ number_format($this->nombre, 0, ',', ' ') }})
        </h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th><th>Commercial</th><th>Client</th><th>Localisation</th><th>Immatriculation</th>
                        <th>Moyen</th><th>Activité</th><th>Passage</th><th>Devis</th><th>Déjà prospecté ?</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $p)
                        @php
                            $fois = max(
                                $this->dejaVus['clients'][FormeComparable::nom($p->client)] ?? 0,
                                $this->dejaVus['plaques'][FormeComparable::plaque($p->immatriculation)] ?? 0,
                            );
                            $devis = $p->etatDuDevis();
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">{{ $p->date?->format('d/m/Y') }}</td>
                            <td>{{ $p->commercial?->nom ?? '—' }}<div style="font-size:11.5px; color:#6B6E76;">{{ $p->commercial?->ville?->nom }}</div></td>
                            <td style="font-weight:600;">{{ $p->client }}</td>
                            <td style="color:#6B6E76;">{{ $p->localisation ?: '—' }}</td>
                            <td>{{ $p->immatriculation ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $p->moyen ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $p->activite ?: '—' }}</td>
                            <td>{{ $p->passage ? 'Oui'.($p->date_passage ? ' — '.$p->date_passage->format('d/m') : '') : 'Non' }}</td>
                            <td style="font-size:12px;">{{ $devis['libelle'] }}</td>
                            <td style="font-size:12px; font-weight:700; color:{{ $fois > 1 ? '#B45309' : '#0E9F6E' }};">
                                {{ $fois > 1 ? 'Oui — '.$fois.' fois' : 'Première fois' }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="10" texte="Aucune prospection sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$page" :total="$this->nombre" prop="page" :par-page="25" />
    </div>
</div>
