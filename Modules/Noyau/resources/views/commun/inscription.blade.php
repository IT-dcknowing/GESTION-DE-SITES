<?php

use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Actions\CreerEntreprise;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\EnregistreurLogo;
use function Livewire\Volt\{state, computed, layout, title, usesFileUploads};

usesFileUploads();
layout('layouts.guest');
title('Inscrire mon entreprise');

state([
    // Étape courante : 1 = entreprise, 2 = villes, 3 = personnel.
    'etape' => 1,
    'entrepriseId' => null,

    // Étape 1 — Informations générales
    'nom' => '', 'gerantNom' => '', 'gerantPrenom' => '', 'gerantFonction' => 'Gérant',
    'gerantEmail' => '', 'adresse' => '', 'telephone' => '', 'email' => '', 'rccm' => '',
    // Étape 1 — Informations fiscales
    'ncc' => '', 'regimeImposition' => "RSI — Régime Simplifié d'Imposition",
    'centreImpots' => '', 'compteContribuable' => '',
    // Étape 1 — DGI & local professionnel
    'idu' => '', 'commune' => '', 'quartier' => '', 'referenceCadastrale' => '', 'proprietaireLocal' => '',
    // Étape 1 — Compte de connexion
    'compteEmail' => '', 'compteMotDePasse' => '', 'compteMotDePasse_confirmation' => '',
    'codeEntreprise' => '',
    'logo' => null,

    // Étape 2 — Ville (crée aussitôt ses deux sites : Mécanique et Sinistre)
    'villeNom' => '', 'villeCommune' => '', 'villeTelephone' => '', 'villeAdresse' => '',

    // Étape 3 — Personnel
    'persRole' => 'responsable_site', 'persNom' => '', 'persEmail' => '', 'persMotDePasse' => '',
    'persSiteId' => '', 'persActivite' => 'Mécanique/Sinistre',
    'persObjectifMecanique' => 14_000_000, 'persObjectifSinistre' => 6_000_000,

    'message' => null,
]);

$entreprise = computed(fn () => $this->entrepriseId ? Entreprise::find($this->entrepriseId) : null);

$villes = computed(fn () => $this->entrepriseId
    ? Ville::where('entreprise_id', $this->entrepriseId)->orderBy('code')->get()
    : collect());

$sites = computed(fn () => $this->entrepriseId
    ? Site::where('entreprise_id', $this->entrepriseId)->orderBy('nom')->get()
    : collect());

$personnel = computed(function () {
    if (! $this->entrepriseId) {
        return collect();
    }

    $utilisateurs = \App\Models\User::where('entreprise_id', $this->entrepriseId)->orderBy('id')->get();
    $roles = \App\Models\User::nomsRolesParUtilisateur($utilisateurs->pluck('id'));

    return $utilisateurs->map(fn ($u) => [
        'utilisateur' => $u,
        'role' => \Modules\Noyau\Entreprises\Support\LibellesRoles::liste($roles[$u->id] ?? null),
    ]);
});

$genererCode = function () {
    $this->codeEntreprise = Entreprise::genererCode($this->nom);
};

// ---------------------------------------------------------------- Étape 1

$creerEntreprise = function (CreerEntreprise $action, EnregistreurLogo $enregistreurLogo) {
    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:255'],
        'gerantNom' => ['required', 'string', 'max:255'],
        'gerantPrenom' => ['required', 'string', 'max:255'],
        'gerantFonction' => ['required', 'string', 'max:255'],
        'gerantEmail' => ['nullable', 'email', 'max:255'],
        'adresse' => ['nullable', 'string', 'max:255'],
        'telephone' => ['nullable', 'string', 'max:40'],
        'email' => ['nullable', 'email', 'max:255'],
        'rccm' => ['nullable', 'string', 'max:60'],
        'ncc' => ['required', 'string', 'max:40'],
        'regimeImposition' => ['required', 'string', 'max:255'],
        'centreImpots' => ['required', 'string', 'max:255'],
        'compteContribuable' => ['required', 'string', 'max:40'],
        'idu' => ['required', 'string', 'max:60'],
        'commune' => ['nullable', 'string', 'max:255'],
        'quartier' => ['nullable', 'string', 'max:255'],
        'referenceCadastrale' => ['nullable', 'string', 'max:255'],
        'proprietaireLocal' => ['nullable', 'string', 'max:255'],
        'compteEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
        'compteMotDePasse' => ['required', 'string', 'min:8', 'confirmed'],
        'codeEntreprise' => ['required', 'string', 'max:20', 'unique:entreprises,code_entreprise'],
        'logo' => EnregistreurLogo::REGLES,
    ], [], [
        'nom' => "nom de l'entreprise", 'gerantNom' => 'nom du gérant', 'gerantPrenom' => 'prénom du gérant',
        'gerantFonction' => 'fonction du gérant', 'ncc' => 'NCC', 'regimeImposition' => "régime d'imposition",
        'centreImpots' => 'centre des impôts', 'compteContribuable' => 'compte contribuable', 'idu' => 'IDU',
        'compteEmail' => 'e-mail de connexion', 'compteMotDePasse' => 'mot de passe',
        'codeEntreprise' => 'code entreprise', 'logo' => 'logo',
    ]);

    $gerant = $action->executer([
        'nom' => $donnees['nom'],
        'code_entreprise' => $donnees['codeEntreprise'],
        'gerant_nom' => $donnees['gerantNom'],
        'gerant_prenom' => $donnees['gerantPrenom'],
        'gerant_fonction' => $donnees['gerantFonction'],
        'gerant_email' => $donnees['gerantEmail'],
        'adresse' => $donnees['adresse'],
        'telephone' => $donnees['telephone'],
        'email' => $donnees['email'],
        'rccm' => $donnees['rccm'],
        'ncc' => $donnees['ncc'],
        'regime_imposition' => $donnees['regimeImposition'],
        'centre_impots' => $donnees['centreImpots'],
        'compte_contribuable' => $donnees['compteContribuable'],
        'idu' => $donnees['idu'],
        'commune' => $donnees['commune'],
        'quartier' => $donnees['quartier'],
        'reference_cadastrale' => $donnees['referenceCadastrale'],
        'proprietaire_local' => $donnees['proprietaireLocal'],
        'compte_email' => $donnees['compteEmail'],
        'compte_mot_de_passe' => $donnees['compteMotDePasse'],
    ]);

    if ($this->logo) {
        $entreprise = $gerant->entreprise;
        $entreprise->update([
            'logo_chemin' => $enregistreurLogo->enregistrer($this->logo, $entreprise),
        ]);
    }

    auth()->login($gerant);

    $this->entrepriseId = $gerant->entreprise_id;
    $this->etape = 2;
    $this->message = 'Entreprise créée. Ajoutez maintenant vos sites.';
};

// ---------------------------------------------------------------- Étape 2

$ajouterVille = function () {
    $donnees = $this->validate([
        'villeNom' => ['required', 'string', 'max:255'],
        'villeCommune' => ['nullable', 'string', 'max:255'],
        'villeTelephone' => ['nullable', 'string', 'max:40'],
        'villeAdresse' => ['nullable', 'string', 'max:255'],
    ], [], ['villeNom' => 'nom de la ville']);

    $rang = Ville::where('entreprise_id', $this->entrepriseId)->count() + 1;
    $couleurs = ['#2563EB', '#0E9F6E', '#D97706', '#C8102E', '#7C3AED', '#0891B2'];

    $ville = Ville::create([
        'entreprise_id' => $this->entrepriseId,
        'code' => 'V'.$rang,
        'nom' => $donnees['villeNom'],
        'commune' => $donnees['villeCommune'] ?: null,
        'telephone' => $donnees['villeTelephone'] ?: null,
        'adresse' => $donnees['villeAdresse'] ?: null,
        'couleur' => $couleurs[($rang - 1) % count($couleurs)],
        'est_actif' => true,
    ]);

    foreach (['Mécanique', 'Sinistre'] as $activite) {
        Site::create([
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $ville->id,
            'nom' => $donnees['villeNom'].' — '.$activite,
            'activite' => $activite,
            'est_actif' => true,
        ]);
    }

    $this->reset(['villeNom', 'villeCommune', 'villeTelephone', 'villeAdresse']);
    $this->message = 'Ville ajoutée avec ses deux sites (Mécanique et Sinistre).';
};

$allerEtape3 = function () {
    if ($this->villes->isEmpty()) {
        $this->addError('villeNom', 'Créez au moins une ville avant de continuer.');

        return;
    }

    $this->persSiteId = $this->sites->first()->id;
    $this->etape = 3;
    $this->message = null;
};

// ---------------------------------------------------------------- Étape 3

$ajouterPersonnel = function (CreerAcces $action) {
    $regles = [
        'persRole' => ['required', 'in:responsable_site,commercial'],
        'persNom' => ['required', 'string', 'max:255'],
        'persEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
        'persMotDePasse' => ['required', 'string', 'min:8'],
        'persSiteId' => ['required', 'exists:sites,id'],
    ];

    if ($this->persRole === 'commercial') {
        $regles['persActivite'] = ['required', 'in:Mécanique,Sinistre,Mécanique/Sinistre'];
        $regles['persObjectifMecanique'] = ['nullable', 'numeric', 'min:0'];
        $regles['persObjectifSinistre'] = ['nullable', 'numeric', 'min:0'];
    }

    $donnees = $this->validate($regles, [], [
        'persNom' => 'nom et prénoms', 'persEmail' => 'adresse e-mail',
        'persMotDePasse' => 'mot de passe', 'persSiteId' => 'site', 'persActivite' => 'activité',
    ]);

    $action->executer($this->entreprise, $donnees['persRole'], [
        'nom' => $donnees['persNom'],
        'email' => $donnees['persEmail'],
        'mot_de_passe' => $donnees['persMotDePasse'],
        'perimetre' => $donnees['persRole'] === 'responsable_site' ? 'site:'.$donnees['persSiteId'] : null,
        'site_id' => $donnees['persRole'] === 'commercial' ? $donnees['persSiteId'] : null,
        'activite' => $donnees['persActivite'] ?? 'Mécanique/Sinistre',
        'objectif_mecanique' => $donnees['persObjectifMecanique'] ?? 0,
        'objectif_sinistre' => $donnees['persObjectifSinistre'] ?? 0,
    ]);

    $this->reset(['persNom', 'persEmail', 'persMotDePasse', 'persObjectifMecanique', 'persObjectifSinistre']);
    $this->persActivite = 'Mécanique/Sinistre';
    $this->message = 'Accès créé — le mot de passe devra être changé à la première connexion.';
};

$terminer = function () {
    $this->redirect(route('tableau-de-bord'), navigate: true);
};

?>

<div style="min-height:100vh; background:var(--th-paper,#F4F3EF); padding:32px 20px;">
    <div style="max-width:940px; margin:0 auto;">

        {{-- Fil des étapes --}}
        <div style="display:flex; gap:10px; margin-bottom:24px; flex-wrap:wrap;">
            @foreach ([1 => 'Entreprise', 2 => 'Sites', 3 => 'Personnel'] as $numero => $libelle)
                <div style="flex:1; min-width:150px; padding:12px 16px; border-radius:10px; border:1px solid var(--th-ligne,#E2E0D8);
                            background:{{ $etape >= $numero ? 'var(--th-ink,#191B20)' : '#fff' }};
                            color:{{ $etape >= $numero ? '#fff' : '#9A9DA5' }};">
                    <div style="font-size:11.5px; text-transform:uppercase; letter-spacing:.6px; opacity:.75;">Étape {{ $numero }}</div>
                    <div style="font-family:'Barlow Condensed',sans-serif; font-size:20px; font-weight:700; text-transform:uppercase;">{{ $libelle }}</div>
                </div>
            @endforeach
        </div>

        @if ($message)
            <div class="encart encart-succes">{{ $message }}</div>
        @endif

        {{-- ============================================ ÉTAPE 1 : ENTREPRISE --}}
        @if ($etape === 1)
            <form wire:submit="creerEntreprise">
                <x-carte-section titre="Informations générales">
                    <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                        <x-champ label="Nom de l'entreprise" model="nom" requis="true" placeholder="Ex : DC-KNOWING" />
                        <x-champ label="Nom du Gérant / Représentant" model="gerantNom" requis="true" placeholder="Ex : AGNIMEL" />
                        <x-champ label="Prénom du Gérant" model="gerantPrenom" requis="true" placeholder="Ex : MELEDJE" />
                        <x-champ label="Fonction du Gérant" model="gerantFonction" requis="true" placeholder="Ex : Gérant" />
                        <x-champ label="E-mail du gérant" model="gerantEmail" type="email" placeholder="Ex : dcknowing@gmail.com" />
                        <x-champ label="Adresse physique" model="adresse" placeholder="Ex : 2 PLATEAUX VALLONS, ABIDJAN" />
                        <x-champ label="Téléphone" model="telephone" placeholder="Ex : +225 27 22 23 72" />
                        <x-champ label="E-mail" model="email" type="email" placeholder="Ex : dcknowing@gmail.com" />
                        <x-champ label="RCCM — Registre du Commerce et du Crédit Mobilier" model="rccm" placeholder="Ex : CI-ABJ-2019-B-18764" />
                    </div>

                    <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--th-ligne,#E2E0D8);">
                        <label class="champ-libelle">Logo principal de l'entreprise</label>
                        <p style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin:0 0 8px;">
                            PNG, JPG ou WEBP, 2 Mo maximum. Il apparaîtra sur votre page de connexion et dans le bandeau de l'application.
                        </p>
                        <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="champ" style="max-width:340px;">
                            <div wire:loading wire:target="logo" style="font-size:13px; color:var(--th-gris,#6B6E76);">Chargement…</div>
                            @if ($logo)
                                <div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; padding:8px 14px;">
                                    <img src="{{ $logo->temporaryUrl() }}" alt="Aperçu du logo" style="height:46px; display:block;">
                                </div>
                            @endif
                        </div>
                        @error('logo') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                </x-carte-section>

                <x-carte-section titre="Informations fiscales">
                    <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                        <x-champ label="NCC — N° Compte Contribuable" model="ncc" requis="true" placeholder="Ex : 1864699 A" />
                        <x-champ label="Régime d'imposition" model="regimeImposition" type="select"
                            :options="\Modules\Noyau\Entreprises\Modeles\Entreprise::REGIMES" requis="true" />
                        <x-champ label="Centre des impôts" model="centreImpots" requis="true" placeholder="Ex : 2 PLATEAUX 3" />
                        <x-champ label="N° Compte Contribuable (CC)" model="compteContribuable" requis="true" placeholder="Ex : 1864699 A" />
                    </div>
                </x-carte-section>

                <x-carte-section titre="DGI & local professionnel">
                    <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                        <x-champ label="IDU — Identifiant Unique DGI" model="idu" requis="true" placeholder="Ex : CI-001-2025-A123456" />
                        <x-champ label="Commune" model="commune" placeholder="Ex : COCODY" />
                        <x-champ label="Quartier" model="quartier" placeholder="Ex : Angré 8ème Tranche" />
                        <x-champ label="Référence cadastrale" model="referenceCadastrale" placeholder="Ex : Section B, Parcelle 042" />
                        <x-champ label="Propriétaire du local professionnel" model="proprietaireLocal" placeholder="Ex : SCI IMMOBILIERE COCODY" />
                    </div>
                </x-carte-section>

                <x-carte-section titre="Compte de connexion du gérant">
                    <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                        <x-champ label="E-mail de connexion" model="compteEmail" type="email" requis="true" />
                        <x-champ label="Mot de passe" model="compteMotDePasse" type="password" requis="true" aide="8 caractères minimum" />
                        <x-champ label="Confirmer le mot de passe" model="compteMotDePasse_confirmation" type="password" requis="true" />
                    </div>

                    <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--th-ligne,#E2E0D8);">
                        <label class="champ-libelle">Code entreprise <span style="color:var(--th-accent,#C8102E);">*</span></label>
                        <p style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin:0 0 8px;">
                            Ce code unique permettra à votre personnel de s'inscrire seul et d'être rattaché automatiquement à votre entreprise.
                        </p>
                        <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                            <input type="text" wire:model="codeEntreprise" value="{{ $codeEntreprise }}" readonly class="champ"
                                style="flex:1; min-width:200px; font-family:'Barlow Condensed',sans-serif; font-size:22px; font-weight:700; letter-spacing:2px; text-align:center;">
                            <button type="button" wire:click="genererCode" class="bouton bouton-sombre">Générer le code</button>
                        </div>
                        @error('codeEntreprise') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                </x-carte-section>

                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <a href="{{ route('login') }}" style="font-size:14px; color:var(--th-gris,#6B6E76); text-decoration:none;">← Retour à la connexion</a>
                    <button type="submit" wire:loading.attr="disabled" class="bouton">Créer l'entreprise et continuer →</button>
                </div>
            </form>
        @endif

        {{-- ================================================= ÉTAPE 2 : VILLES --}}
        @if ($etape === 2)
            <x-carte-section titre="Code entreprise à communiquer à votre personnel">
                <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div style="font-family:'Barlow Condensed',sans-serif; font-size:34px; font-weight:700; letter-spacing:3px; color:var(--th-accent,#C8102E);">
                        {{ $this->entreprise?->code_entreprise }}
                    </div>
                    <p style="font-size:13.5px; color:var(--th-gris,#6B6E76); margin:0; flex:1; min-width:240px;">
                        Vos commerciaux et responsables pourront s'inscrire eux-mêmes avec ce code, sans que vous ayez à créer chaque compte.
                    </p>
                </div>
            </x-carte-section>

            <x-carte-section titre="Création d'une ville">
                <div class="bloc-saisie">
                    <x-champ label="Nom de la ville" model="villeNom" requis="true" placeholder="Ex : Abidjan" />
                    <x-champ label="Commune" model="villeCommune" placeholder="Ex : Cocody" />
                    <x-champ label="Téléphone" model="villeTelephone" placeholder="+225 07 ..." />
                    <x-champ label="Adresse" model="villeAdresse" placeholder="Ex : Boulevard Latrille" />
                    <button type="button" wire:click="ajouterVille" class="bouton bouton-sombre">+ Ajouter la ville</button>
                </div>
                <p style="font-size:12px; color:#9A9DA5; margin:10px 0 0;">
                    Chaque ville crée aussitôt ses deux sites d'activité (Mécanique et Sinistre). Le
                    responsable de chacun pourra être nommé à l'étape suivante, ou plus tard depuis vos paramètres.
                </p>

                <div class="tableau-conteneur" style="margin-top:16px;">
                    <table class="tableau">
                        <thead>
                            <tr><th>Code</th><th>Ville</th><th>Commune</th><th>Téléphone</th><th>Adresse</th><th>Sites</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($this->villes as $ville)
                                <tr>
                                    <td style="font-weight:700;">{{ $ville->code }}</td>
                                    <td>
                                        <span style="display:inline-block; width:9px; height:9px; border-radius:99px; background:{{ $ville->couleur }}; margin-right:7px;"></span>
                                        {{ $ville->nom }}
                                    </td>
                                    <td>{{ $ville->commune ?? '—' }}</td>
                                    <td>{{ $ville->telephone ?? '—' }}</td>
                                    <td>{{ $ville->adresse ?? '—' }}</td>
                                    <td>{{ $this->sites->where('ville_id', $ville->id)->pluck('activite')->implode(', ') }}</td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="6" texte="Aucune ville créée pour le moment." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-carte-section>

            <div style="display:flex; justify-content:flex-end;">
                <button type="button" wire:click="allerEtape3" class="bouton">Suivant : le personnel →</button>
            </div>
        @endif

        {{-- ============================================= ÉTAPE 3 : PERSONNEL --}}
        @if ($etape === 3)
            <x-carte-section titre="Ajouter un membre du personnel">
                <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
                    @foreach (['responsable_site' => 'Responsable de site', 'commercial' => 'Commercial'] as $cle => $libelle)
                        <button type="button" wire:click="$set('persRole', '{{ $cle }}')"
                            class="onglet {{ $persRole === $cle ? 'est-actif' : '' }}">{{ $libelle }}</button>
                    @endforeach
                </div>

                <div class="bloc-saisie">
                    <x-champ label="Nom et prénoms" model="persNom" requis="true" />
                    <x-champ label="Adresse e-mail" model="persEmail" type="email" requis="true" />
                    <x-champ label="Mot de passe provisoire" model="persMotDePasse" type="password" requis="true" />
                    <x-champ label="Site d'affectation" model="persSiteId" type="select"
                        :options="$this->sites->mapWithKeys(fn ($s) => [$s->id => $s->nom.' ('.$s->activite.')'])" requis="true" width="240" />
                    @if ($persRole === 'commercial')
                        <x-champ label="Activité" model="persActivite" type="select" width="200"
                            :options="['Mécanique/Sinistre' => 'Mécanique/Sinistre (les deux)', 'Mécanique' => 'Mécanique', 'Sinistre' => 'Sinistre']" />
                        <x-champ label="Objectif Mécanique (FCFA)" model="persObjectifMecanique" type="number" width="180" />
                        <x-champ label="Objectif Sinistre (FCFA)" model="persObjectifSinistre" type="number" width="180" />
                    @endif
                    <button type="button" wire:click="ajouterPersonnel" class="bouton bouton-sombre">+ Ajouter</button>
                </div>

                <div class="tableau-conteneur" style="margin-top:16px;">
                    <table class="tableau">
                        <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th></tr></thead>
                        <tbody>
                            @foreach ($this->personnel as $ligne)
                                <tr>
                                    <td style="font-weight:600;">{{ $ligne['utilisateur']->name }}</td>
                                    <td>{{ $ligne['utilisateur']->email }}</td>
                                    <td>{{ $ligne['role'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-carte-section>

            <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <button type="button" wire:click="$set('etape', 2)" class="bouton bouton-secondaire">← Revenir aux sites</button>
                <button type="button" wire:click="terminer" class="bouton">Terminer et accéder à mon tableau de bord →</button>
            </div>
        @endif

    </div>
</div>
