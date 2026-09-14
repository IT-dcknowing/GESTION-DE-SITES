<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use Modules\Noyau\Tracabilite\Modeles\SessionUtilisateur;
use Modules\Noyau\Tracabilite\Modeles\VisiteEcran;
use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Traçabilité — qui est là, depuis quand, et sur quel écran
|--------------------------------------------------------------------------
| Le journal d'audit voisin répond à « qu'a-t-on modifié » ; celui-ci répond à
| « qui était devant l'application, et combien de temps ». Les deux se complètent
| et ne se remplacent pas : on peut passer une heure sur un tableau de bord sans
| rien changer, et changer un objectif en dix secondes.
|
| Un mot sur ce que ces chiffres valent. La durée d'un écran est celle qui sépare
| deux pages : c'est un temps d'affichage, pas un temps d'attention. Quelqu'un peut
| avoir répondu au téléphone, ou avoir laissé l'onglet ouvert. L'écran le dit à
| l'utilisateur plutôt que de le laisser conclure de travers — un chiffre présenté
| sans sa limite finit toujours par servir d'argument dans une conversation qu'il
| n'était pas capable d'arbitrer.
*/

state([
    'periode' => '7',      // en jours ; « 0 » vaut « aujourd'hui »
    'entrepriseId' => '',
    'recherche' => '',
    // Compte dont on déplie le détail, ou null.
    'detailId' => null,
    'pageComptes' => 1,
    'pageSessions' => 1,
    'pageJamais' => 1,
    // Quel tableau on regarde : « temps » (ceux qui viennent) ou « jamais » (ceux qui
    // ne sont jamais venus). Deux populations disjointes, et la seconde manquait.
    'vue' => 'temps',
]);

mount(function () {
    // La bascule passe par l'adresse : un lien ordinaire l'ouvre, et l'adresse se partage.
    // Elle emporte aussi les filtres courants, sans quoi basculer les remettrait à zéro.
    $this->vue = request()->query('vue') === 'jamais' ? 'jamais' : 'temps';

    foreach (['periode', 'entrepriseId', 'recherche'] as $champ) {
        $valeur = request()->query($champ);

        if ($valeur !== null) {
            $this->{$champ} = (string) $valeur;
        }
    }
});

/** Bornes de la période lue. Le jour courant est toujours inclus, en entier. */
$debut = computed(fn () => (int) $this->periode === 0
    ? now()->startOfDay()
    : now()->subDays((int) $this->periode)->startOfDay());

$entreprises = computed(fn () => Entreprise::orderBy('nom')->pluck('nom', 'id'));

/** Sessions de la période, filtrées comme l'écran le demande. */
$sessions = computed(function () {
    $recherche = trim($this->recherche);

    return SessionUtilisateur::with('utilisateur:id,name,email,entreprise_id')
        ->where('ouverte_le', '>=', $this->debut)
        ->when($this->entrepriseId !== '', fn ($q) => $q->where('entreprise_id', (int) $this->entrepriseId))
        ->when($recherche !== '', fn ($q) => $q->whereHas('utilisateur', fn ($u) => $u
            ->where('name', 'like', "%$recherche%")
            ->orWhere('email', 'like', "%$recherche%")))
        ->orderByDesc('ouverte_le')
        ->limit(2000)
        ->get();
});

/**
 * Présences en cours, quelle que soit la période affichée.
 *
 * Une session ouverte hier soir et toujours active ne doit pas disparaître de la
 * ligne « en ligne » parce qu'on regarde la journée : la présence est un état, pas
 * un événement de la période.
 */
$enLigne = computed(fn () => SessionUtilisateur::enCours()
    ->with('utilisateur:id,name,email,entreprise_id')
    ->when($this->entrepriseId !== '', fn ($q) => $q->where('entreprise_id', (int) $this->entrepriseId))
    ->orderByDesc('derniere_activite_le')
    ->get());

/** Dernier écran ouvert par session, pour dire où chacun se trouve. */
$ecransCourants = computed(function () {
    $ids = $this->enLigne->pluck('id');

    if ($ids->isEmpty()) {
        return [];
    }

    return VisiteEcran::whereIn('session_utilisateur_id', $ids)
        ->orderBy('id')
        ->get(['session_utilisateur_id', 'ecran', 'vue_le'])
        // Le tri croissant fait que la dernière écrasée est la plus récente.
        ->keyBy('session_utilisateur_id')
        ->all();
});

/**
 * Une ligne par personne : nombre de connexions, temps cumulé, dernière venue.
 *
 * Agrégé en base plutôt qu'en PHP : sur une période de trois mois, ramener chaque
 * session pour les additionner ferait passer des dizaines de milliers de lignes par
 * le réseau pour en afficher vingt.
 */
$comptes = computed(function () {
    $recherche = trim($this->recherche);

    $lignes = DB::table('sessions_utilisateur')
        ->join('users', 'users.id', '=', 'sessions_utilisateur.user_id')
        ->where('sessions_utilisateur.ouverte_le', '>=', $this->debut)
        ->when($this->entrepriseId !== '', fn ($q) => $q->where('sessions_utilisateur.entreprise_id', (int) $this->entrepriseId))
        ->when($recherche !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('users.name', 'like', "%$recherche%")
            ->orWhere('users.email', 'like', "%$recherche%")))
        ->groupBy('users.id', 'users.name', 'users.email', 'users.entreprise_id')
        ->select(
            'users.id', 'users.name', 'users.email', 'users.entreprise_id',
            DB::raw('count(*) as connexions'),
            DB::raw('sum(sessions_utilisateur.duree_secondes) as secondes'),
            DB::raw('max(sessions_utilisateur.derniere_activite_le) as vu_le'),
        )
        ->orderByDesc('secondes')
        ->get();

    $entreprises = $this->entreprises;
    $roles = User::nomsRolesParUtilisateur($lignes->pluck('id'));
    $presents = $this->enLigne->pluck('user_id')->flip();

    return $lignes->map(fn ($l) => [
        'id' => (int) $l->id,
        'nom' => $l->name,
        'email' => $l->email,
        'entreprise' => $entreprises[$l->entreprise_id] ?? '— Plateforme —',
        'role' => LibellesRoles::liste($roles[$l->id] ?? null),
        'connexions' => (int) $l->connexions,
        'secondes' => (int) $l->secondes,
        'duree' => SessionUtilisateur::enClair((int) $l->secondes),
        'vu_le' => $l->vu_le,
        'en_ligne' => $presents->has((int) $l->id),
    ]);
});

/**
 * Les comptes qui ne se sont jamais connectés.
 *
 * **Deux témoins, et non un seul.** Un compte est tenu pour jamais venu s'il n'a ni date de
 * dernière connexion ni la moindre ligne de présence. Les deux se posent au même moment —
 * à l'authentification — mais ils ne viennent pas du même endroit, et se fier à un seul
 * laisserait passer les comptes antérieurs à la mise en place du journal de présence.
 *
 * **Rien d'autre n'inscrit une connexion.** Ni la création d'un accès, ni l'envoi du
 * courriel, ni son renvoi, ni la remise d'un mot de passe. Un compte qui apparaît ici n'est
 * jamais entré, et c'est une information sûre.
 *
 * La période ne s'applique pas : « jamais » ne se borne pas dans le temps.
 */
$jamaisVenus = computed(function () {
    $recherche = trim($this->recherche);

    $lignes = User::query()
        ->when($this->entrepriseId !== '', fn ($q) => $q->where('entreprise_id', (int) $this->entrepriseId))
        ->when($recherche !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('name', 'like', "%$recherche%")
            ->orWhere('email', 'like', "%$recherche%")))
        ->whereNull('derniere_connexion_le')
        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('sessions_utilisateur')
            ->whereColumn('sessions_utilisateur.user_id', 'users.id'))
        ->orderBy('created_at')
        ->get(['id', 'name', 'email', 'entreprise_id', 'est_actif', 'created_at']);

    $entreprises = $this->entreprises;
    $roles = User::nomsRolesParUtilisateur($lignes->pluck('id'));

    return $lignes->map(fn ($u) => [
        'id' => (int) $u->id,
        'nom' => $u->name,
        'email' => $u->email,
        'entreprise' => $entreprises[$u->entreprise_id] ?? '— Plateforme —',
        'role' => LibellesRoles::liste($roles[$u->id] ?? null),
        'actif' => (bool) $u->est_actif,
        'cree_le' => $u->created_at,
        'jours' => $u->created_at ? (int) $u->created_at->diffInDays(now()) : null,
    ]);
});

/** L'adresse de cet écran avec les filtres courants — pour que la bascule ne les perde pas. */
$lien = function (string $vue): string {
    return route('super-admin.tracabilite', array_filter([
        'vue' => $vue === 'temps' ? null : $vue,
        'periode' => $this->periode,
        'entrepriseId' => $this->entrepriseId ?: null,
        'recherche' => trim($this->recherche) ?: null,
    ], fn ($v) => $v !== null && $v !== ''));
};

/** Écrans les plus ouverts sur la période : où passe réellement le temps. */
$ecrans = computed(function () {
    $lignes = DB::table('visites_ecran')
        ->where('vue_le', '>=', $this->debut)
        ->when($this->entrepriseId !== '', fn ($q) => $q->where('entreprise_id', (int) $this->entrepriseId))
        ->groupBy('ecran')
        ->select(
            'ecran',
            DB::raw('count(*) as ouvertures'),
            DB::raw('sum(duree_secondes) as secondes'),
            DB::raw('count(distinct user_id) as personnes'),
        )
        ->orderByDesc('secondes')
        ->limit(25)
        ->get();

    return $lignes->map(fn ($l) => [
        'ecran' => $l->ecran,
        'ouvertures' => (int) $l->ouvertures,
        'personnes' => (int) $l->personnes,
        'duree' => SessionUtilisateur::enClair((int) $l->secondes),
    ]);
});

/** Le compte déplié, s'il en est un — et seulement lui. */
$detail = computed(fn () => $this->detailId ? User::find($this->detailId) : null);

/** Ses connexions sur la période, de la plus récente à la plus ancienne. */
$sessionsDuDetail = computed(fn () => $this->detailId
    ? SessionUtilisateur::where('user_id', $this->detailId)
        ->where('ouverte_le', '>=', $this->debut)
        ->withCount('visites')
        ->orderByDesc('ouverte_le')->get()
    : collect());

/** Son parcours écran par écran, borné pour rester lisible. */
$parcoursDuDetail = computed(fn () => $this->detailId
    ? VisiteEcran::where('user_id', $this->detailId)
        ->where('vue_le', '>=', $this->debut)
        ->orderByDesc('vue_le')->limit(200)->get()
    : collect());

$ouvrirDetail = function (int $id) {
    $this->detailId = $this->detailId === $id ? null : $id;
    $this->pageSessions = 1;
};

$updatedPeriode = function () {
    $this->pageComptes = 1;
    $this->pageSessions = 1;
};

$updatedRecherche = function () {
    $this->pageComptes = 1;
};

$updatedEntrepriseId = function () {
    $this->pageComptes = 1;
    // Le compte déplié peut ne pas appartenir à l'entreprise choisie : le garder
    // ouvert afficherait un détail hors du filtre annoncé au-dessus.
    $this->detailId = null;
};

?>

<x-a-venir titre="Traçabilité des connexions"
    description="Qui est entré, quand, depuis quel appareil, combien de temps il est resté et sur quels écrans.">

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:18px;">
        <select wire:model.live="periode"
            style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px; background:#fff;">
            <option value="0" @selected((string) $periode === '0')>Aujourd'hui</option>
            <option value="7" @selected((string) $periode === '7')>7 derniers jours</option>
            <option value="30" @selected((string) $periode === '30')>30 derniers jours</option>
            <option value="90" @selected((string) $periode === '90')>90 derniers jours</option>
        </select>

        <select wire:model.live="entrepriseId"
            style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px; background:#fff; max-width:260px;">
            <option value="" @selected($entrepriseId === '')>Toutes les entreprises</option>
            @foreach ($this->entreprises as $id => $nom)
                <option value="{{ $id }}" @selected((string) $entrepriseId === (string) $id)>{{ $nom }}</option>
            @endforeach
        </select>

        <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}" class="champ"
            placeholder="Rechercher un nom ou un e-mail…" style="max-width:280px;">

        <a href="{{ route('super-admin.journal.index') }}" wire:navigate
            style="margin-left:auto; font-size:13px; color:#6B6E76; text-decoration:underline;">
            Journal des modifications →
        </a>
    </div>

    @php
        $secondesTotales = $this->comptes->sum('secondes');
        $ouvertures = $this->ecrans->sum('ouvertures');
    @endphp

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:18px;">
        <x-kpi-card label="Connectés à l'instant" :value="$this->enLigne->count()"
            :sub="$this->enLigne->count() > 0 ? 'activité dans les '.\Modules\Noyau\Tracabilite\Modeles\SessionUtilisateur::MINUTES_AVANT_INACTIVITE.' dernières minutes' : 'personne sur l\'application'"
            :bon="$this->enLigne->count() > 0" />
        <x-kpi-card label="Connexions sur la période" :value="$this->comptes->sum('connexions')"
            :sub="$this->comptes->count().' compte(s) distinct(s)'" />
        <x-kpi-card label="Temps passé, cumulé"
            :value="\Modules\Noyau\Tracabilite\Modeles\SessionUtilisateur::enClair($secondesTotales)"
            sub="toutes personnes confondues" />
        <x-kpi-card label="Écrans ouverts" :value="number_format($ouvertures, 0, ',', ' ')"
            :sub="$this->ecrans->count().' écran(s) différent(s)'" />
    </div>

    {{-- Présence immédiate. Placée en tête : c'est la question qu'on vient poser. --}}
    <h3 class="titre-section" style="margin-top:6px;">En ligne maintenant</h3>

    @if ($this->enLigne->isEmpty())
        <p style="color:#6B6E76; font-size:14px; margin:0 0 20px;">
            Personne n'est actuellement sur l'application.
        </p>
    @else
        <div class="tableau-conteneur" style="margin-bottom:22px;">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Personne</th>
                        <th>Entreprise</th>
                        <th>Écran en cours</th>
                        <th>Connecté depuis</th>
                        <th>Dernière action</th>
                        <th>Appareil</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->enLigne as $session)
                        <tr wire:key="enligne-{{ $session->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="width:8px; height:8px; border-radius:99px; background:#0E9F6E; flex:none;"></span>
                                    <div>
                                        <div style="font-weight:600;">{{ $session->utilisateur?->name ?? 'Compte supprimé' }}</div>
                                        <div style="color:#6B6E76; font-size:13px;">{{ $session->utilisateur?->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $this->entreprises[$session->entreprise_id] ?? '— Plateforme —' }}</td>
                            <td>{{ $this->ecransCourants[$session->id]->ecran ?? '—' }}</td>
                            <td>{{ $session->ouverte_le->format('H:i') }} · {{ $session->duree() }}</td>
                            <td>{{ $session->derniere_activite_le->diffForHumans() }}</td>
                            <td style="color:#6B6E76; font-size:13px;">
                                {{ $session->plateforme }} · {{ $session->adresse_ip }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Deux populations disjointes, et un seul emplacement : ceux qui viennent, et ceux
         qui ne sont jamais venus. Les seconds n'apparaissaient nulle part — ils sont
         absents par définition d'un tableau qui ne parle que de présence — alors que ce
         sont eux qu'il faut relancer. --}}
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:6px;">
        <h3 class="titre-section" style="margin:0;">
            {{ $vue === 'jamais' ? 'Comptes jamais connectés' : 'Temps passé par personne' }}
        </h3>

        <div style="display:flex; gap:0; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; overflow:hidden;">
            <a href="{{ $this->lien('temps') }}"
               style="padding:6px 13px; font-size:13px; font-weight:700; text-decoration:none;
                      background:{{ $vue === 'temps' ? '#191B20' : '#fff' }};
                      color:{{ $vue === 'temps' ? '#fff' : '#4B4E55' }};">
                Ceux qui viennent
            </a>
            <a href="{{ $this->lien('jamais') }}"
               style="padding:6px 13px; font-size:13px; font-weight:700; text-decoration:none;
                      border-left:1px solid var(--th-ligne,#E2E0D8);
                      background:{{ $vue === 'jamais' ? '#C8102E' : '#fff' }};
                      color:{{ $vue === 'jamais' ? '#fff' : '#4B4E55' }};">
                Jamais connectés ({{ $this->jamaisVenus->count() }})
            </a>
        </div>
    </div>

    @if ($vue === 'jamais')
        <p style="color:#6B6E76; font-size:13.5px; margin:8px 0 14px; line-height:1.6; max-width:82ch;">
            Ces comptes n'ont <strong>jamais</strong> ouvert l'application : ni date de connexion, ni
            ligne de présence. Créer un accès, envoyer le courriel, le renvoyer ou remettre un mot de
            passe n'inscrit aucune connexion — le système ne compte que les entrées réelles. La période
            choisie plus haut ne s'applique pas ici : « jamais » ne se borne pas dans le temps.
        </p>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Personne</th>
                        <th>Entreprise</th>
                        <th>Rôle</th>
                        <th>Accès</th>
                        <th style="text-align:right;">Ouvert depuis</th>
                        <th>Créé le</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->jamaisVenus->forPage($pageJamais, 15) as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>
                                <div style="font-weight:600;">{{ $ligne['nom'] }}</div>
                                <div style="color:#6B6E76; font-size:13px;">{{ $ligne['email'] }}</div>
                            </td>
                            <td>{{ $ligne['entreprise'] }}</td>
                            <td>{{ $ligne['role'] }}</td>
                            <td>
                                @if ($ligne['actif'])
                                    <span style="color:#0E9F6E; font-weight:600;">Ouvert</span>
                                @else
                                    <span style="color:#6B6E76; font-weight:600;">Préparé, non ouvert</span>
                                @endif
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;
                                       {{ ($ligne['jours'] ?? 0) > 30 ? 'color:#C8102E; font-weight:700;' : '' }}">
                                {{ $ligne['jours'] !== null ? $ligne['jours'].' j' : '—' }}
                            </td>
                            <td style="color:#6B6E76;">{{ $ligne['cree_le']?->format('d/m/Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="6" texte="Tout le monde est entré au moins une fois." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageJamais" :total="$this->jamaisVenus->count()" prop="pageJamais" />

        <p style="color:#6B6E76; font-size:13px; margin:12px 0 0; line-height:1.6; max-width:82ch;">
            Pour relancer&nbsp;: <a href="{{ route('super-admin.acces.index') }}"
            style="color:#C8102E; font-weight:700;">Gestion des accès</a> — le bouton
            <em>Renvoyer</em> repart avec un lien de mot de passe, et un accès encore fermé s'ouvre
            au passage. Sur un compte déjà en service, le mot de passe reste le sien.
        </p>
    @else

    <div class="tableau-conteneur">
        <table class="tableau">
            <thead>
                <tr>
                    <th>Personne</th>
                    <th>Entreprise</th>
                    <th>Rôle</th>
                    <th style="text-align:right;">Connexions</th>
                    <th style="text-align:right;">Temps cumulé</th>
                    <th>Dernière venue</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->comptes->forPage($pageComptes, 12) as $ligne)
                    <tr wire:key="compte-{{ $ligne['id'] }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                        <td>
                            <div style="font-weight:600;">
                                @if ($ligne['en_ligne'])
                                    <span style="display:inline-block; width:7px; height:7px; border-radius:99px; background:#0E9F6E; margin-right:5px;"></span>
                                @endif
                                {{ $ligne['nom'] }}
                            </div>
                            <div style="color:#6B6E76; font-size:13px;">{{ $ligne['email'] }}</div>
                        </td>
                        <td>{{ $ligne['entreprise'] }}</td>
                        <td>{{ $ligne['role'] }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $ligne['connexions'] }}</td>
                        <td style="text-align:right; font-weight:600; font-variant-numeric:tabular-nums;">{{ $ligne['duree'] }}</td>
                        <td style="color:#6B6E76;">{{ \Illuminate\Support\Carbon::parse($ligne['vu_le'])->format('d/m/Y H:i') }}</td>
                        <td style="text-align:right;">
                            <button type="button" wire:click="ouvrirDetail({{ $ligne['id'] }})"
                                style="background:transparent; border:1px solid var(--th-ligne,#E2E0D8); color:#4B4E55; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer;">
                                {{ $detailId === $ligne['id'] ? 'Replier' : 'Détail' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <x-table-vide :colspan="7" texte="Aucune connexion sur cette période." />
                @endforelse
            </tbody>
        </table>
    </div>
    <x-pagination :page="$pageComptes" :total="$this->comptes->count()" prop="pageComptes" />
    @endif

    {{-- Détail d'un compte : ses connexions, puis son parcours écran par écran. --}}
    @if ($this->detail)
        <div class="carte" style="margin-top:18px; border:1px solid #C8102E44;">
            <h3 class="titre-section">Parcours de {{ $this->detail->name }}</h3>
            <p style="font-size:13px; color:#6B6E76; margin:0 0 16px; line-height:1.55; max-width:78ch;">
                {{ $this->detail->email }} · {{ $this->sessionsDuDetail->count() }} connexion(s) sur la période.
                La durée d'un écran est le temps écoulé jusqu'à l'ouverture du suivant : c'est un temps
                d'affichage, pas un temps de travail. Un appel téléphonique reçu devant un tableau de bord
                s'y compte comme du temps passé dessus.
            </p>

            <h4 style="font-size:13px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; margin:0 0 8px;">Connexions</h4>
            <div class="tableau-conteneur" style="margin-bottom:18px;">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Ouverte le</th>
                            <th>Fermée le</th>
                            <th style="text-align:right;">Durée</th>
                            <th style="text-align:right;">Écrans</th>
                            <th>Appareil</th>
                            <th>Fin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->sessionsDuDetail->forPage($pageSessions, 8) as $session)
                            <tr wire:key="sess-{{ $session->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $session->ouverte_le->format('d/m/Y H:i') }}</td>
                                <td>
                                    @if ($session->estEnCours())
                                        <span style="color:#0E9F6E; font-weight:600;">en cours</span>
                                    @else
                                        {{ $session->fermee_le?->format('d/m/Y H:i') ?? '—' }}
                                    @endif
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $session->duree() }}</td>
                                <td style="text-align:right;">{{ $session->visites_count }}</td>
                                <td style="color:#6B6E76; font-size:13px;">{{ $session->plateforme }} · {{ $session->adresse_ip }}</td>
                                <td style="color:#6B6E76; font-size:13px;">
                                    {{ match ($session->motif_fin) {
                                        'deconnexion' => 'Déconnexion',
                                        'expiration' => 'Session expirée',
                                        default => $session->estEnCours() ? '—' : 'Inconnue',
                                    } }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageSessions" :total="$this->sessionsDuDetail->count()" prop="pageSessions" />

            <h4 style="font-size:13px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; margin:18px 0 8px;">
                Écrans ouverts (200 derniers)
            </h4>
            <div class="tableau-conteneur" style="max-height:420px; overflow:auto;">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Horodatage</th>
                            <th>Écran</th>
                            <th style="text-align:right;">Durée</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->parcoursDuDetail as $visite)
                            <tr wire:key="visite-{{ $visite->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-variant-numeric:tabular-nums;">{{ $visite->vue_le->format('d/m/Y H:i:s') }}</td>
                                <td>{{ $visite->ecran }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">
                                    {{ $visite->duree_secondes > 0 ? $visite->duree() : '—' }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="3" texte="Aucun écran ouvert sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Où passe le temps, tous comptes confondus. --}}
    <h3 class="titre-section" style="margin-top:22px;">Écrans les plus consultés</h3>

    <div class="tableau-conteneur">
        <table class="tableau">
            <thead>
                <tr>
                    <th>Écran</th>
                    <th style="text-align:right;">Ouvertures</th>
                    <th style="text-align:right;">Personnes</th>
                    <th style="text-align:right;">Temps cumulé</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->ecrans as $ligne)
                    <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                        <td>{{ $ligne['ecran'] }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $ligne['ouvertures'] }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $ligne['personnes'] }}</td>
                        <td style="text-align:right; font-weight:600; font-variant-numeric:tabular-nums;">{{ $ligne['duree'] }}</td>
                    </tr>
                @empty
                    <x-table-vide :colspan="4" texte="Aucun écran ouvert sur cette période." />
                @endforelse
            </tbody>
        </table>
    </div>

    <p style="font-size:12px; color:#9A9DA5; margin:16px 0 0; max-width:80ch; line-height:1.6;">
        Ce journal est nominatif. Il est conservé six mois, puis effacé automatiquement
        (<code>php artisan tracabilite:entretenir</code>). Les requêtes de fond de l'interface comptent
        comme une preuve de présence, jamais comme l'ouverture d'un écran : une page reste une page,
        quel que soit le nombre de champs qu'on y saisit.
    </p>
</x-a-venir>
