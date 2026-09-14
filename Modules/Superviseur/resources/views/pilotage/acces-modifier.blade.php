<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Support\HierarchieAcces;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use Modules\Noyau\Entreprises\Support\PeutModifierUnAcces;
use Modules\Noyau\Exploitation\Modeles\Commercial;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Reprendre un accès existant
|--------------------------------------------------------------------------
| **Ce qui manquait.** On créait des accès, on les révoquait, on les supprimait —
| on ne pouvait pas les corriger. Une adresse mal tapée, un nom mal orthographié, un
| changement de fonction : il fallait supprimer et recréer, c'est-à-dire perdre le
| lien entre la personne et ce qu'elle avait déjà écrit.
|
| **Ce que cet écran ouvre, et ce qu'il laisse fermé** — la question a été posée
| directement, et la réponse tient en trois lignes :
|
| - l'**entreprise** ne bouge pas : les écritures du compte y sont rattachées ;
| - le **rôle** se reprend dans les deux sens, sauf vers gérant et sauf pour le
|   dernier gérant d'une entreprise. Une écriture porte un lieu et un auteur, jamais
|   un rôle : la changer de rôle ne déplace rien de ce qui a été saisi ;
| - la **ville et l'atelier** passent par la réaffectation, qui garde l'historique et
|   laisse à l'intéressé la lecture de son ancien poste.
|
| Le formulaire poste. Rien ici ne dépend de la couche interactive.
*/

state(['compteId' => null]);

mount(function (User $utilisateur) {
    // La hiérarchie tranche avant l'affichage : on ne montre pas la fiche de quelqu'un
    // sur qui l'on n'a aucun droit, fût-ce en lecture.
    abort_if(HierarchieAcces::motifDuRefus(auth()->user(), $utilisateur) !== null, 403);

    $this->compteId = $utilisateur->id;
});

$compte = computed(fn () => User::findOrFail($this->compteId));

$permission = computed(fn () => new PeutModifierUnAcces(auth()->user(), $this->compte));

$fiche = computed(fn () => Commercial::where('user_id', $this->compteId)->first());

/** Les mutations de cette personne : c'est par là que sa ville se change, pas ici. */
$mutations = computed(fn () => Reaffectation::where('user_id', $this->compteId)
    ->with(['villeAvant', 'villeApres', 'siteAvant', 'siteApres'])
    ->latest()
    ->get());

?>

@php
    $etiquette = 'display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;';
    $saisie = 'width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8);'
        .' border-radius:8px; font-size:15.5px; font-family:inherit;';
    $erreur = 'color:#C8102E; font-size:13.5px; margin-top:4px;';
@endphp

<div>
    @php
        $compte = $this->compte;
        $permission = $this->permission;
        $roleActuel = $permission->roleActuel();
    @endphp

    @if (session('refus-acces'))
        <x-boite-message titre="Modification refusée" ton="alerte">{{ session('refus-acces') }}</x-boite-message>
    @endif

    <div class="carte">
        <h1 style="font-size:18px; font-weight:800; margin:0 0 4px;">
            Reprendre l'accès de {{ $compte->name }}
        </h1>
        <p style="color:#6B6E76; font-size:15px; margin:0 0 18px;">
            {{ LibellesRoles::de($roleActuel) }} ·
            {{ $compte->site?->nom ?? $compte->ville?->nom ?? "toute l'entreprise" }} ·
            {{ $compte->est_actif ? 'accès ouvert' : 'accès révoqué' }}
        </p>

        {{-- Trois verrous distincts, et non un seul : l'ancien message les confondait et
             interdisait bien plus qu'il ne fallait. --}}
        <div style="background:#EEF3FD; border:1px solid #2563EB55; color:#1B3F86; border-radius:8px;
                    padding:11px 13px; font-size:14px; line-height:1.6; margin-bottom:18px;">
            <strong>Ce qui se reprend ici, et ce qui passe ailleurs.</strong>
            <ul style="margin:7px 0 0; padding-left:20px;">
                <li><strong>L'entreprise est figée.</strong> Les écritures de ce compte y sont rattachées,
                    et le déplacer les laisserait derrière lui.</li>
                @if ($permission->estLeDernierGerant())
                    <li><strong>Le rôle est figé</strong> — c'est le dernier gérant de cette entreprise :
                        lui retirer son rôle la laisserait sans direction, et sans personne pour réparer
                        l'erreur.</li>
                @else
                    <li><strong>Le rôle reste modifiable.</strong> Une écriture porte un lieu et un auteur,
                        jamais un rôle : le changer ne déplace rien de ce qui a été saisi. Le rôle de
                        gérant, lui, ne s'obtient pas par ici — cet accès se crée.</li>
                @endif
                <li><strong>La ville et l'atelier</strong> se changent par une <em>réaffectation</em> —
                    Paramètres → Personnel — qui garde l'historique et laisse à l'intéressé la lecture de
                    son ancien poste.</li>
            </ul>
        </div>

        <form method="POST" action="{{ route('acces.modifier.enregistrer', $compte->id) }}"
              style="max-width:520px;">
            @csrf

            <label style="{{ $etiquette }} margin-top:0;" for="nom">Nom et prénoms</label>
            <input type="text" id="nom" name="nom" required maxlength="255"
                   value="{{ old('nom', $compte->name) }}" style="{{ $saisie }}">
            @error('nom') <div style="{{ $erreur }}">{{ $message }}</div> @enderror

            <label style="{{ $etiquette }}" for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" required maxlength="255"
                   value="{{ old('email', $compte->email) }}" style="{{ $saisie }}">
            @error('email') <div style="{{ $erreur }}">{{ $message }}</div> @enderror

            <label style="{{ $etiquette }}" for="telephone">
                Téléphone <span style="font-weight:400; color:#6B6E76;">— facultatif</span>
            </label>
            <input type="text" id="telephone" name="telephone" maxlength="40"
                   value="{{ old('telephone', $compte->telephone) }}" style="{{ $saisie }}">

            <label style="{{ $etiquette }}" for="role">Rôle</label>
            <select id="role" name="role" style="{{ $saisie }}"
                    @disabled(! $permission->roleModifiable())>
                @foreach ($permission->rolesAtteignables() as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('role', $roleActuel) === $cle)>{{ $libelle }}</option>
                @endforeach
            </select>
            @if (! $permission->roleModifiable())
                {{-- Un champ désactivé n'est pas posté : on repasse la valeur pour que
                     l'enregistrement ne perde pas le rôle en route. --}}
                <input type="hidden" name="role" value="{{ $roleActuel }}">
            @endif
            @error('role') <div style="{{ $erreur }}">{{ $message }}</div> @enderror

            <label style="{{ $etiquette }}" for="mdp">
                Nouveau mot de passe
                <span style="font-weight:400; color:#6B6E76;">— laisser vide pour ne pas y toucher</span>
            </label>
            <input type="password" id="mdp" name="motDePasse" minlength="8" autocomplete="new-password"
                   style="{{ $saisie }}">
            <div style="font-size:13px; color:#6B6E76; line-height:1.55; margin-top:4px;">
                Le remplacer coupe la connexion en cours de son titulaire et lui demande d'en choisir un
                nouveau. Corriger une adresse ou un nom, non.
            </div>
            @error('motDePasse') <div style="{{ $erreur }}">{{ $message }}</div> @enderror

            {{-- Les objectifs ne concernent que ceux qui prospectent. Les afficher pour un
                 caissier reviendrait à lui demander un chiffre qui n'existe pas. --}}
            @if (in_array($roleActuel, ['responsable_ville', 'responsable_site', 'commercial'], true))
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:6px;">
                    <div>
                        <label style="{{ $etiquette }}" for="meca">Objectif mécanique (FCFA / mois)</label>
                        <input type="number" id="meca" name="objectifMecanique" min="0" step="1000"
                               value="{{ old('objectifMecanique', $this->fiche?->objectif_mecanique ?? 0) }}"
                               style="{{ $saisie }}">
                    </div>
                    <div>
                        <label style="{{ $etiquette }}" for="sin">Objectif sinistre (FCFA / mois)</label>
                        <input type="number" id="sin" name="objectifSinistre" min="0" step="1000"
                               value="{{ old('objectifSinistre', $this->fiche?->objectif_sinistre ?? 0) }}"
                               style="{{ $saisie }}">
                    </div>
                </div>
            @endif

            <div style="display:flex; gap:10px; margin-top:20px; flex-wrap:wrap;">
                <button type="submit"
                        style="background:var(--th-accent,#C8102E); border:0; color:#fff; border-radius:8px;
                               padding:10px 20px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit;">
                    Enregistrer
                </button>
                <a href="{{ route('acces.creer') }}"
                   style="background:#fff; border:1.5px solid #191B20; color:#191B20; border-radius:8px;
                          padding:10px 20px; font-size:15px; font-weight:700; text-decoration:none;">
                    Revenir à la liste
                </a>
            </div>
        </form>
    </div>

    @if ($this->mutations->isNotEmpty())
        <div class="carte" style="margin-top:16px;">
            <h2 style="font-size:16px; font-weight:800; margin:0 0 12px;">Réaffectations de cette personne</h2>

            <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                <thead>
                    <tr style="text-align:left; border-bottom:2px solid #191B20;">
                        <th style="padding:6px 8px;">Date</th>
                        <th style="padding:6px 8px;">Depuis</th>
                        <th style="padding:6px 8px;">Vers</th>
                        <th style="padding:6px 8px;">Motif</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->mutations as $m)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="padding:6px 8px; white-space:nowrap;">{{ $m->created_at?->format('d/m/Y') }}</td>
                            <td style="padding:6px 8px;">{{ $m->siteAvant?->nom ?? $m->villeAvant?->nom ?? '—' }}</td>
                            <td style="padding:6px 8px;">{{ $m->siteApres?->nom ?? $m->villeApres?->nom ?? '—' }}</td>
                            <td style="padding:6px 8px;">{{ $m->motif ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
