<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Commun\Services\EnregistreurPhoto;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{state, computed, mount, usesFileUploads};

usesFileUploads();

state([
    'onglet' => 'profil',
    'message' => null,

    'nom' => '', 'email' => '', 'telephone' => '', 'photo' => null,

    // Villes (responsables uniquement) : créer une ville crée aussitôt son lieu,
    // où se pratiquent les deux activités.
    'villeId' => null,
    'villeNom' => '', 'villeCommune' => '', 'villeTelephone' => '', 'villeAdresse' => '',

    'pageVilles' => 1,
    'pageSites' => 1,
]);

mount(function () {
    $this->nom = auth()->user()->name;
    $this->email = auth()->user()->email;
    $this->telephone = auth()->user()->telephone ?? '';
});

$utilisateur = computed(fn () => auth()->user());

$entreprise = computed(fn () => auth()->user()->entreprise);

$monRole = computed(function () {
    if (auth()->user()->hasRole('responsable_ville')) {
        return 'Superviseur de ville';
    }

    if (auth()->user()->hasRole('responsable_site')) {
        return 'Responsable de site';
    }

    if (auth()->user()->hasRole('caissier')) {
        return 'Comptabilité';
    }

    return 'Commercial';
});

/** Lieux couverts par l'utilisateur : ceux de sa ville pour un commercial, ceux de son périmètre pour les autres rôles. */
$mesSites = computed(function () {
    $sitesDuPerimetre = Site::visiblesPour(auth()->user());

    if ($sitesDuPerimetre->isNotEmpty()) {
        return $sitesDuPerimetre;
    }

    $villeId = Commercial::where('user_id', auth()->id())->first()?->ville_id;

    return $villeId ? Site::where('ville_id', $villeId)->orderBy('nom')->get() : collect();
});

$maFicheCommerciale = computed(fn () => Commercial::where('user_id', auth()->id())->first());

/**
 * Les responsables tiennent les villes à jour depuis leur poste, sans dépendre
 * de la disponibilité du gérant. Le commercial, lui, n'y a pas accès.
 */
$estResponsable = computed(fn () => auth()->user()->hasRole('responsable_ville') || auth()->user()->hasRole('responsable_site'));

$villes = computed(fn () => Ville::where('entreprise_id', auth()->user()->entreprise_id)
    ->withCount('commerciaux')
    ->with('sites')
    ->orderBy('code')->get());

$tousLesSites = computed(fn () => $this->villes
    ->flatMap(fn ($v) => $v->sites->each(fn ($s) => $s->setRelation('ville', $v)))
    ->values());

$viderFormulaireVille = function () {
    $this->villeId = null;
    $this->villeNom = '';
    $this->villeCommune = '';
    $this->villeTelephone = '';
    $this->villeAdresse = '';
};

$editerVille = function (int $id) {
    abort_unless($this->estResponsable, 403);

    $ville = Ville::where('entreprise_id', auth()->user()->entreprise_id)->findOrFail($id);

    $this->villeId = $ville->id;
    $this->villeNom = $ville->nom;
    $this->villeCommune = $ville->commune ?? '';
    $this->villeTelephone = $ville->telephone ?? '';
    $this->villeAdresse = $ville->adresse ?? '';
};

$enregistrerVille = function () {
    abort_unless($this->estResponsable, 403);

    $donnees = $this->validate([
        'villeNom' => ['required', 'string', 'max:255'],
        'villeCommune' => ['nullable', 'string', 'max:255'],
        'villeTelephone' => ['nullable', 'string', 'max:40'],
        'villeAdresse' => ['nullable', 'string', 'max:255'],
    ], [], [
        'villeNom' => 'nom de la ville', 'villeCommune' => 'commune',
        'villeTelephone' => 'téléphone', 'villeAdresse' => 'adresse',
    ]);

    $valeurs = [
        'nom' => $donnees['villeNom'],
        'commune' => $donnees['villeCommune'] ?: null,
        'telephone' => $donnees['villeTelephone'] ?: null,
        'adresse' => $donnees['villeAdresse'] ?: null,
    ];

    if ($this->villeId) {
        Ville::where('entreprise_id', auth()->user()->entreprise_id)
            ->findOrFail($this->villeId)->update($valeurs);

        $this->message = 'Ville mise à jour.';
    } else {
        // Le code sert de préfixe aux numéros de documents : il doit rester unique
        // dans l'entreprise, y compris après la suppression d'une ville.
        $rang = Ville::where('entreprise_id', auth()->user()->entreprise_id)->max('id') + 1;

        $ville = Ville::create([
            ...$valeurs,
            'entreprise_id' => auth()->user()->entreprise_id,
            'code' => 'V'.str_pad((string) $rang, 2, '0', STR_PAD_LEFT),
            'est_actif' => true,
        ]);

        // Une ville naît avec un seul lieu, confondu avec elle : les deux activités s'y
        // pratiquent. Un second lieu ne se justifie que le jour où l'entreprise en ouvre
        // réellement un — comme Abidjan.
        Site::create([
            'entreprise_id' => auth()->user()->entreprise_id,
            'ville_id' => $ville->id,
            'code' => $ville->code,
            'nom' => $donnees['villeNom'],
            'est_actif' => true,
        ]);

        $this->message = 'Ville créée avec son site : il est désormais proposé dans les listes déroulantes.';
    }

    $this->viderFormulaireVille();
    unset($this->villes, $this->mesSites);
};

$basculerVille = function (int $id) {
    abort_unless($this->estResponsable, 403);

    $ville = Ville::where('entreprise_id', auth()->user()->entreprise_id)->findOrFail($id);
    $ville->update(['est_actif' => ! $ville->est_actif]);

    unset($this->villes);
    $this->message = $ville->est_actif ? 'Ville réactivée.' : 'Ville désactivée.';
};

$basculerSite = function (int $id) {
    abort_unless($this->estResponsable, 403);

    $site = Site::where('entreprise_id', auth()->user()->entreprise_id)->findOrFail($id);
    $site->update(['est_actif' => ! $site->est_actif]);

    unset($this->villes);
    $this->message = $site->est_actif ? 'Site réactivé.' : 'Site désactivé.';
};

$enregistrerProfil = function (EnregistreurPhoto $enregistreur) {
    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore(auth()->id())],
        'telephone' => ['nullable', 'string', 'max:40'],
        'photo' => EnregistreurPhoto::REGLES,
    ], [], ['nom' => 'nom', 'email' => 'adresse e-mail', 'telephone' => 'téléphone']);

    $utilisateur = auth()->user();

    $utilisateur->update([
        'name' => $donnees['nom'],
        'email' => $donnees['email'],
        'telephone' => $donnees['telephone'] ?: null,
    ]);

    if ($this->photo) {
        $utilisateur->update(['photo_chemin' => $enregistreur->enregistrer($this->photo, $utilisateur)]);
        $this->reset('photo');
    }

    $this->message = 'Vos informations ont été mises à jour.';
};

?>

<div>
    <x-titre-ecran titre="Mon espace"
        sous-titre="Votre fiche, vos codes et vos réglages personnels." />

    <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
        @php
            $onglets = ['profil' => 'Mon profil', 'entreprise' => 'Mon entreprise'];
            if ($this->estResponsable) {
                $onglets['villes'] = 'Villes';
            }
        @endphp
        @foreach ($onglets as $cle => $libelle)
            <button type="button" wire:click="$set('onglet', '{{ $cle }}')"
                class="onglet {{ $onglet === $cle ? 'est-actif' : '' }}">{{ $libelle }}</button>
        @endforeach
    </div>

    @if ($message)
        <div class="encart encart-succes">{{ $message }}</div>
    @endif

    {{-- ------------------------------------------------------ Mon profil --}}
    @if ($onglet === 'profil')
        <form wire:submit="enregistrerProfil">
            <x-carte-section titre="Mon profil">
                <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap; margin-bottom:16px;">
                    <div style="text-align:center;">
                        @if ($photo)
                            <img src="{{ $photo->temporaryUrl() }}" alt="Aperçu"
                                style="width:84px; height:84px; border-radius:99px; object-fit:cover; border:2px solid var(--th-accent,#C8102E);">
                            <div style="font-size:11.5px; color:var(--th-accent,#C8102E); font-weight:600; margin-top:5px;">Enregistrez pour appliquer</div>
                        @else
                            <x-avatar :utilisateur="$this->utilisateur" :taille="84" />
                        @endif
                    </div>
                    <div style="flex:1; min-width:240px;">
                        <label class="champ-libelle">Photo de profil</label>
                        <input type="file" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="champ" style="max-width:320px;">
                        <div wire:loading wire:target="photo" style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin-top:4px;">Chargement…</div>
                        @error('photo') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                    <x-champ label="Nom et prénoms" model="nom" requis="true" />
                    <x-champ label="Adresse e-mail" model="email" type="email" requis="true" />
                    <x-champ label="Téléphone" model="telephone" />
                </div>

                <div style="margin-top:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <button type="submit" class="bouton">Enregistrer</button>
                    <a href="{{ route('mot-de-passe.modifier') }}" wire:navigate
                        style="font-size:14px; color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">Changer mon mot de passe</a>
                </div>
            </x-carte-section>
        </form>

        {{-- Les deux codes qui désignent la personne. Ils n'existaient que sur « Mon
             profil », que presque personne n'ouvre : c'est ici que les rôles arrivent
             depuis leur menu Paramètres, et ceux qui saisissent dans le logiciel
             d'atelier n'avaient donc aucun endroit pour corriger leur identifiant.

             Hors du formulaire ci-dessus, et c'est voulu : la liaison poste vers sa
             propre adresse, sans dépendre de la couche interactive. --}}
        <x-carte-section titre="Mes codes">
            <x-codes-du-profil />
        </x-carte-section>
    @endif

    {{-- -------------------------------------------------- Mon entreprise --}}
    @if ($onglet === 'entreprise')
        <x-carte-section titre="Mon rattachement">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Information</th><th>Valeur</th></tr></thead>
                    <tbody>
                        <tr><td style="font-weight:600;">Entreprise</td><td>{{ $this->entreprise?->nom ?? '—' }}</td></tr>
                        <tr><td style="font-weight:600;">Mon rôle</td><td>{{ $this->monRole }}</td></tr>
                        <tr><td style="font-weight:600;">{{ $this->mesSites->count() > 1 ? 'Mes sites' : 'Mon site' }}</td><td>{{ $this->mesSites->pluck('nom')->implode(', ') ?: '—' }}</td></tr>
                        @if ($this->maFicheCommerciale)
                            <tr><td style="font-weight:600;">N° commercial</td><td>{{ $this->maFicheCommerciale->numero }}</td></tr>
                            <tr><td style="font-weight:600;">Objectif mensuel</td><td>{{ ae($this->maFicheCommerciale->objectif_mensuel) }}</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--th-ligne,#E2E0D8);">
                <label class="champ-libelle">Code entreprise</label>
                <p style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin:0 0 8px;">
                    Lecture seule. Seul le gérant peut le modifier.
                </p>
                <div style="font-family:'Barlow Condensed',sans-serif; font-size:30px; font-weight:700; letter-spacing:3px; color:var(--th-accent,#C8102E);">
                    {{ $this->entreprise?->code_entreprise ?? '— non généré —' }}
                </div>
            </div>
        </x-carte-section>
    @endif

    {{-- -------------------------------------------------------------- Villes --}}
    @if ($onglet === 'villes' && $this->estResponsable)
        <x-carte-section titre="{{ $villeId ? 'Modifier la ville' : 'Nouvelle ville' }}" icone="atelier" couleur="#2563EB">
            <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px;">
                Créer une ville crée aussitôt ses deux sites d'activité (Mécanique et Sinistre) :
                ils sont immédiatement proposés dans les listes déroulantes de saisie et le filtre
                du tableau de bord. Le code est attribué automatiquement.
            </p>

            <div class="bloc-saisie">
                <x-champ label="Nom de la ville" model="villeNom" requis="true" placeholder="Ex : Bouaké" />
                <x-champ label="Commune" model="villeCommune" placeholder="Ex : Koko" />
            </div>

            <div class="bloc-saisie" style="margin-top:10px;">
                <x-champ label="Téléphone" model="villeTelephone" placeholder="+225 …" />
                <x-champ label="Adresse" model="villeAdresse" placeholder="Ex : Boulevard Latrille" />
            </div>

            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" wire:click="enregistrerVille" class="bouton">
                    {{ $villeId ? 'Enregistrer les modifications' : '+ Créer la ville' }}
                </button>
                @if ($villeId)
                    <button type="button" wire:click="viderFormulaireVille" class="bouton bouton-secondaire">Annuler</button>
                @endif
            </div>
        </x-carte-section>

        <x-carte-section titre="Villes de l'entreprise" icone="liste" couleur="#2A2E35">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Code</th><th>Ville</th><th>Commune</th>
                            <th>Téléphone</th><th>Responsable</th><th>Sites</th><th>Commerciaux</th><th>Statut</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->villes->forPage($pageVilles, 10) as $v)
                            <tr wire:key="ville-{{ $v->id }}">
                                <td style="font-weight:700;">{{ $v->code }}</td>
                                <td style="font-weight:600;">{{ $v->nom }}</td>
                                <td>{{ $v->commune ?? '—' }}</td>
                                <td>{{ $v->telephone ?? '—' }}</td>
                                <td>{{ $v->responsable?->name ?? '—' }}</td>
                                <td>{{ $v->sites->map->nom->implode(', ') ?: '—' }}</td>
                                <td>{{ $v->commerciaux_count }}</td>
                                <td>
                                    <span class="pastille {{ $v->est_actif ? 'pastille-vert' : 'pastille-ambre' }}">
                                        {{ $v->est_actif ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <button type="button" wire:click="editerVille({{ $v->id }})"
                                        class="bouton bouton-secondaire bouton-petit">Modifier</button>
                                    <button type="button" wire:click="basculerVille({{ $v->id }})"
                                        class="bouton bouton-secondaire bouton-petit">
                                        {{ $v->est_actif ? 'Désactiver' : 'Réactiver' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($this->villes->isEmpty())
                <p class="legende-vide">Aucune ville pour le moment.</p>
            @endif
            <x-pagination :page="$pageVilles" :total="$this->villes->count()" prop="pageVilles" />
        </x-carte-section>

        <x-carte-section titre="Sites d'activité" icone="liste" couleur="#2A2E35">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Ville</th><th>Site</th><th>Code</th>
                            <th>Responsable propre</th><th>Statut</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->tousLesSites->forPage($pageSites, 10) as $s)
                            <tr wire:key="site-{{ $s->id }}">
                                <td>{{ $s->ville->nom }}</td>
                                <td style="font-weight:600;">{{ $s->nom }}</td>
                                <td style="color:#6B6E76;">{{ $s->code ?? '—' }}</td>
                                <td>{{ $s->responsable?->name ?? '— (hérite de la ville)' }}</td>
                                <td>
                                    <span class="pastille {{ $s->est_actif ? 'pastille-vert' : 'pastille-ambre' }}">
                                        {{ $s->est_actif ? 'Actif' : 'Inactif' }}
                                    </span>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <button type="button" wire:click="basculerSite({{ $s->id }})"
                                        class="bouton bouton-secondaire bouton-petit">
                                        {{ $s->est_actif ? 'Désactiver' : 'Réactiver' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageSites" :total="$this->tousLesSites->count()" prop="pageSites" />
        </x-carte-section>
    @endif

</div>
