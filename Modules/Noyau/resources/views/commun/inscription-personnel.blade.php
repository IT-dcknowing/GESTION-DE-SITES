<?php

use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{state, computed, layout, title};

layout('layouts.guest');
title('Rejoindre mon entreprise');

state([
    // Étape 1 : saisie du code. Étape 2 : le formulaire d'inscription.
    'etape' => 1,
    'code' => '',
    'entrepriseId' => null,

    'role' => 'commercial',
    'nom' => '',
    'email' => '',
    'telephone' => '',
    'motDePasse' => '',
    'motDePasse_confirmation' => '',
    'siteId' => '', 'activite' => 'Mécanique/Sinistre',
    'objectifMecanique' => 14_000_000, 'objectifSinistre' => 6_000_000,

    'succes' => false,
]);

$entreprise = computed(fn () => $this->entrepriseId ? Entreprise::find($this->entrepriseId) : null);

$sites = computed(fn () => $this->entrepriseId
    ? Site::where('entreprise_id', $this->entrepriseId)->where('est_actif', true)->orderBy('nom')->get()
    : collect());

$verifierCode = function () {
    $this->validate(
        ['code' => ['required', 'string', 'max:20']],
        [],
        ['code' => 'code entreprise']
    );

    $entreprise = Entreprise::where('code_entreprise', strtoupper(trim($this->code)))
        ->where('est_active', true)
        ->first();

    if (! $entreprise) {
        $this->addError('code', "Ce code entreprise n'existe pas ou l'entreprise est suspendue.");

        return;
    }

    if ($entreprise->sites()->where('est_actif', true)->doesntExist()) {
        $this->addError('code', "Cette entreprise n'a pas encore de site actif. Contactez votre gérant.");

        return;
    }

    $this->entrepriseId = $entreprise->id;
    $this->siteId = $this->sites->first()->id;
    $this->etape = 2;
};

$sInscrire = function (CreerAcces $action) {
    $regles = [
        'role' => ['required', 'in:commercial,responsable_site'],
        'nom' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'telephone' => ['nullable', 'string', 'max:40'],
        'motDePasse' => ['required', 'string', 'min:8', 'confirmed'],
        // Le site doit appartenir à l'entreprise du code saisi : on ne fait pas confiance
        // à l'identifiant renvoyé par le navigateur.
        'siteId' => ['required', Rule::exists('sites', 'id')->where('entreprise_id', $this->entrepriseId)],
    ];

    if ($this->role === 'commercial') {
        $regles['activite'] = ['required', 'in:Mécanique,Sinistre,Mécanique/Sinistre'];
        $regles['objectifMecanique'] = ['nullable', 'numeric', 'min:0'];
        $regles['objectifSinistre'] = ['nullable', 'numeric', 'min:0'];
    }

    $donnees = $this->validate($regles, [], [
        'nom' => 'nom et prénoms', 'email' => 'adresse e-mail', 'motDePasse' => 'mot de passe',
        'siteId' => 'site', 'activite' => 'activité',
    ]);

    $utilisateur = $action->executer($this->entreprise, $donnees['role'], [
        'nom' => $donnees['nom'],
        'email' => $donnees['email'],
        'mot_de_passe' => $donnees['motDePasse'],
        'telephone' => $donnees['telephone'] ?: null,
        'perimetre' => $donnees['role'] === 'responsable_site' ? 'site:'.$donnees['siteId'] : null,
        'site_id' => $donnees['role'] === 'commercial' ? $donnees['siteId'] : null,
        'activite' => $donnees['activite'] ?? 'Mécanique/Sinistre',
        'objectif_mecanique' => $donnees['objectifMecanique'] ?? 0,
        'objectif_sinistre' => $donnees['objectifSinistre'] ?? 0,
        // Inscription volontaire : le mot de passe est déjà choisi par la personne.
        'doit_changer_mot_de_passe' => false,
    ]);

    auth()->login($utilisateur);

    $this->redirect(route('redirection'), navigate: true);
};

?>

<div style="min-height:100vh; background:var(--th-paper,#F4F3EF); display:flex; align-items:center; justify-content:center; padding:32px 20px;">
    <div style="width:100%; max-width:640px;">

        <div style="text-align:center; margin-bottom:22px;">
            <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:34px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; margin:0; color:var(--th-ink,#191B20);">
                Rejoindre mon entreprise
            </h1>
            <p style="color:var(--th-gris,#6B6E76); font-size:14.5px; margin:6px 0 0;">
                Inscrivez-vous avec le code que votre gérant vous a communiqué.
            </p>
        </div>

        {{-- ------------------------------------------- Étape 1 : le code --}}
        @if ($etape === 1)
            <div class="carte" style="padding:28px;">
                <form wire:submit="verifierCode">
                    <label class="champ-libelle">Code entreprise <span style="color:var(--th-accent,#C8102E);">*</span></label>
                    <input type="text" wire:model="code" value="{{ $code }}" placeholder="Ex : ART-K7M2QP" class="champ"
                        style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700; letter-spacing:3px; text-align:center; text-transform:uppercase;">
                    @error('code') <span class="champ-erreur">{{ $message }}</span> @enderror

                    <button type="submit" class="bouton" style="width:100%; justify-content:center; margin-top:18px; padding:12px;">
                        Continuer →
                    </button>
                </form>

                <p style="text-align:center; font-size:13.5px; color:var(--th-gris,#6B6E76); margin:20px 0 0;">
                    Vous avez déjà un compte ?
                    <a href="{{ route('login') }}" style="color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">Se connecter</a>
                </p>
            </div>
        @endif

        {{-- --------------------------------- Étape 2 : le formulaire complet --}}
        @if ($etape === 2)
            <div class="encart encart-succes" style="display:flex; align-items:center; gap:10px;">
                <span style="font-weight:700;">{{ $this->entreprise?->nom }}</span>
                <span style="color:var(--th-gris,#6B6E76);">— code {{ $this->entreprise?->code_entreprise }} reconnu.</span>
            </div>

            <form wire:submit="sInscrire">
                <x-carte-section titre="Votre inscription">
                    <label class="champ-libelle">Votre rôle dans l'entreprise</label>
                    <div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
                        @foreach (['commercial' => 'Commercial', 'responsable_site' => 'Responsable de site'] as $cle => $libelle)
                            <button type="button" wire:click="$set('role', '{{ $cle }}')"
                                class="onglet {{ $role === $cle ? 'est-actif' : '' }}">{{ $libelle }}</button>
                        @endforeach
                    </div>

                    <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                        <x-champ label="Nom et prénoms" model="nom" requis="true" />
                        <x-champ label="Adresse e-mail" model="email" type="email" requis="true" />
                        <x-champ label="Téléphone" model="telephone" placeholder="+225 07 ..." />
                        <x-champ label="Site de rattachement" model="siteId" type="select"
                            :options="$this->sites->mapWithKeys(fn ($s) => [$s->id => $s->nom.' ('.$s->activite.')'])" requis="true" width="240" />
                        @if ($role === 'commercial')
                            <x-champ label="Activité" model="activite" type="select" width="200"
                                :options="['Mécanique/Sinistre' => 'Mécanique/Sinistre (les deux)', 'Mécanique' => 'Mécanique', 'Sinistre' => 'Sinistre']" />
                            <x-champ label="Objectif Mécanique (FCFA)" model="objectifMecanique" type="number" width="190"
                                aide="Laissez vide si votre gérant le définira." />
                            <x-champ label="Objectif Sinistre (FCFA)" model="objectifSinistre" type="number" width="190" />
                        @endif
                        <x-champ label="Mot de passe" model="motDePasse" type="password" requis="true" aide="8 caractères minimum" />
                        <x-champ label="Confirmer le mot de passe" model="motDePasse_confirmation" type="password" requis="true" />
                    </div>
                </x-carte-section>

                <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <button type="button" wire:click="$set('etape', 1)" class="bouton bouton-secondaire">← Changer de code</button>
                    <button type="submit" wire:loading.attr="disabled" class="bouton">Créer mon compte →</button>
                </div>
            </form>
        @endif

    </div>
</div>
