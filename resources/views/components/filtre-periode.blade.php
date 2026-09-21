@props([
    'periode', 'villes' => null, 'villeUnique' => null, 'villeFiltre' => null, 'activiteFiltre' => null,
    'sites' => null, 'siteFiltre' => null,
    'moisFiltre' => null, 'semaineFiltre' => null, 'jourFiltre' => null,
    'masquerActivite' => false, 'commerciaux' => null, 'commercialFiltre' => null,
    /*
     * Les deux bornes du mode « Période ».
     *
     * Elles n'étaient pas déclarées : le gabarit les lisait sans que personne ne les lui
     * passe, et l'onglet « Période » tombait donc sur « Undefined variable $dateDebut » dès
     * qu'on cliquait dessus. Aucun test ne l'avait vu parce qu'aucun n'ouvrait cet onglet —
     * l'écran s'affichait toujours en mode « Calendrier ». C'est la panne qui se cachait
     * derrière la demande d'un filtre « du … au … » : il existait, il ne s'ouvrait pas.
     */
    'dateDebut' => null, 'dateFin' => null,
])

@php
    use Modules\Noyau\Commun\Services\PeriodeCalculateur;
    use Carbon\Carbon;

    // La précision Mécanique/Sinistre/Consolidé n'a de sens qu'une fois une ville
    // précise en contexte : soit l'utilisateur n'en a qu'une (fixe), soit le Gérant
    // vient d'en choisir une dans la liste. Le caissier fait exception : sa
    // comptabilité reste toujours consolidée à l'échelle de la ville, jamais scindée
    // par activité.
    $villeEnContexte = $villeUnique !== null || ($villes !== null && $villeFiltre);
    $afficherPrecision = ! $masquerActivite && $villeEnContexte;

    // Le choix du lieu suit la même règle, et n'est proposé que là où la ville en compte
    // réellement plusieurs (Abidjan) : ailleurs le site se confond avec la ville.
    $afficherSite = $villeEnContexte && $sites !== null && $sites->count() > 1;

    // Le sélecteur de commercial suit la même logique : il n'apparaît qu'une fois la
    // ville connue (fixe ou choisie), et seulement si la page en fournit la liste.
    $afficherCommercial = $villeEnContexte && $commerciaux !== null;

    $mois = PeriodeCalculateur::moisDeLAnnee();
    // L'année de référence des mois, semaines et jours est **celle qu'on regarde**,
    // pas celle du calendrier : sinon, choisir « Mars » en consultant 2025 afficherait
    // les semaines de mars 2026.
    $anneeEnCours = \Modules\Noyau\Entreprises\Services\ExerciceDeTravail::annee() ?? Carbon::today()->year;

    $semainesDuMois = $moisFiltre
        ? PeriodeCalculateur::semainesDuMois(Carbon::create($anneeEnCours, (int) $moisFiltre, 1))
        : [];

    $joursOptions = [];
    if ($semaineFiltre && $semainesDuMois) {
        $semaineChoisie = $semainesDuMois[(int) $semaineFiltre - 1] ?? null;
        if ($semaineChoisie) {
            $nb = $semaineChoisie['debut']->diffInDays($semaineChoisie['fin']) + 1;
            $joursOptions = range(1, $nb);
        }
    } elseif ($moisFiltre) {
        $joursOptions = range(1, Carbon::create($anneeEnCours, (int) $moisFiltre, 1)->daysInMonth);
    }
@endphp

<div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
    <div style="display:flex; gap:6px;">
        <button type="button" wire:click="$set('periode', 'calendrier')"
            class="onglet {{ $periode === 'calendrier' ? 'est-actif' : '' }}">Calendrier</button>
        <button type="button" wire:click="$set('periode', 'periode')"
            class="onglet {{ $periode === 'periode' ? 'est-actif' : '' }}">Période</button>
    </div>

    @if ($villeUnique)
        <span style="font-size:13.5px; font-weight:700; color:var(--th-ink,#191B20); padding:9px 14px; background:#F4F3EF; border-radius:8px; white-space:nowrap;">
            Ville : {{ $villeUnique->nom }}
        </span>
    @elseif ($villes !== null)
        <select wire:model.live="villeFiltre" class="champ" style="width:auto; font-weight:600; background:#fff;">
            <option value="" @selected($villeFiltre === '')>Toutes les villes (consolidé)</option>
            @foreach ($villes as $ville)
                <option value="{{ $ville->id }}" @selected((string) $villeFiltre === (string) $ville->id)>{{ $ville->nom }}</option>
            @endforeach
        </select>
    @endif

    @if ($afficherSite)
        <select wire:model.live="siteFiltre" class="champ" style="width:auto; font-weight:600; background:#fff;">
            <option value="" @selected($siteFiltre === '')>Tous les sites de la ville</option>
            @foreach ($sites as $site)
                <option value="{{ $site->id }}" @selected((string) $siteFiltre === (string) $site->id)>{{ $site->nom }}</option>
            @endforeach
        </select>
    @endif

    @if ($afficherPrecision)
        <select wire:model.live="activiteFiltre" class="champ" style="width:auto; font-weight:600; background:#fff;">
            <option value="" @selected($activiteFiltre === '')>Consolidé (les deux activités)</option>
            <option value="Mécanique" @selected((string) $activiteFiltre === 'Mécanique')>Mécanique</option>
            <option value="Sinistre" @selected((string) $activiteFiltre === 'Sinistre')>Sinistre</option>
        </select>
    @endif

    @if ($afficherCommercial)
        <select wire:model.live="commercialFiltre" class="champ" style="width:auto; font-weight:600; background:#fff;">
            <option value="" @selected($commercialFiltre === '')>Tous les commerciaux</option>
            @foreach ($commerciaux->where('est_spontane', false) as $commercial)
                <option value="{{ $commercial->id }}" @selected((string) $commercialFiltre === (string) $commercial->id)>{{ $commercial->nom }}</option>
            @endforeach
            {{-- Le « Client spontané » n'est pas un commercial nommé : une seule entrée à
                 l'écran, qui filtre sur ceux de toutes les villes retenues à la fois. --}}
            @if ($commerciaux->contains('est_spontane', true))
                <option value="spontane" @selected((string) $commercialFiltre === 'spontane')>Client spontané</option>
            @endif
        </select>
    @endif

    {{-- Ce qu'un écran veut poser sur la même ligne que ses filtres : un bouton qui mène
         ailleurs, une mention. Vide par défaut, et donc sans effet sur les onze écrans qui
         n'en passent pas. --}}
    {{ $slot }}
</div>

@if ($periode === 'calendrier')
    <div style="display:flex; gap:12px; align-items:center; margin:-4px 0 20px; font-size:14px; flex-wrap:wrap;">
        <span style="color:var(--th-gris,#6B6E76); font-weight:600;">Exercice {{ $anneeEnCours }}</span>

        <select wire:model.live="moisFiltre" class="champ" style="width:auto;">
            <option value="" @selected($moisFiltre === '')>Tous les mois</option>
            @foreach ($mois as $numero => $libelle)
                <option value="{{ $numero }}" @selected((string) $moisFiltre === (string) $numero)>{{ $libelle }}</option>
            @endforeach
        </select>

        <select wire:model.live="semaineFiltre" class="champ" style="width:auto;" @if (! $moisFiltre) disabled @endif>
            <option value="" @selected($semaineFiltre === '')>Toutes les semaines</option>
            @foreach ($semainesDuMois as $semaine)
                <option value="{{ $semaine['numero'] }}" @selected((string) $semaineFiltre === (string) $semaine['numero'])>
                    Semaine {{ $semaine['numero'] }} ({{ $semaine['debut']->format('d/m') }} – {{ $semaine['fin']->format('d/m') }})
                </option>
            @endforeach
        </select>

        <select wire:model.live="jourFiltre" class="champ" style="width:auto;" @if (! $moisFiltre) disabled @endif>
            <option value="" @selected($jourFiltre === '')>Tous les jours</option>
            @foreach ($joursOptions as $j)
                <option value="{{ $j }}" @selected((string) $jourFiltre === (string) $j)>Jour {{ $j }}</option>
            @endforeach
        </select>
    </div>
@else
    {{-- Au jour, et non au mois.
         « Du 3 au 17 mars » était impossible : on ne pouvait demander qu'un mois entier,
         alors que c'est précisément l'intervalle exact qu'on cherche pour rapprocher une
         caisse ou vérifier une journée. Les bornes écrites au mois (`Y-m`) restent lues —
         voir PeriodeCalculateur::borne() — mais l'affichage les ramène au jour, sans quoi
         un champ de date resterait vide devant une valeur qu'il ne sait pas relire. --}}
    @php
        $auJour = function (?string $valeur, bool $versLaFin) use ($anneeEnCours) {
            $valeur = trim((string) $valeur);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
                return $valeur;
            }

            if (preg_match('/^\d{4}-\d{2}$/', $valeur)) {
                $mois = Carbon::createFromFormat('Y-m', $valeur);

                return ($versLaFin ? $mois->endOfMonth() : $mois->startOfMonth())->format('Y-m-d');
            }

            return $versLaFin
                ? Carbon::today()->format('Y-m-d')
                : Carbon::create($anneeEnCours, 1, 1)->format('Y-m-d');
        };
    @endphp

    <div style="display:flex; gap:12px; align-items:center; margin:-4px 0 20px; font-size:14px; flex-wrap:wrap;">
        <label style="color:var(--th-gris,#6B6E76); font-weight:600;">Du</label>
        <input type="date" wire:model.live="dateDebut" value="{{ $auJour($dateDebut, false) }}"
            class="champ" style="width:auto;">
        <label style="color:var(--th-gris,#6B6E76); font-weight:600;">Au</label>
        <input type="date" wire:model.live="dateFin" value="{{ $auJour($dateFin, true) }}"
            class="champ" style="width:auto;">
    </div>
@endif
