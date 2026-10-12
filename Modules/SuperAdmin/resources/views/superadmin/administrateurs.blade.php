<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;
use function Livewire\Volt\{state, computed};

state([
    'message' => null,
    'erreur' => null,

    'editionId' => null,
    'nom' => '',
    'email' => '',
    'telephone' => '',
    'motDePasse' => '',
    'habilitations' => [],
    'voirMotDePasse' => false,
    'pageAdmins' => 1,
]);

$moi = computed(fn () => auth()->user());

$administrateurs = computed(function () {
    $ids = \Illuminate\Support\Facades\DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.model_type', User::class)
        ->where('roles.name', 'super_admin')
        ->pluck('model_has_roles.model_id');

    return User::whereIn('id', $ids)
        ->with('createur:id,name')
        ->orderByDesc('est_fondateur')
        ->orderBy('name')
        ->get();
});

$reinitialiser = function () {
    $this->reset('editionId', 'nom', 'email', 'telephone', 'motDePasse', 'habilitations', 'voirMotDePasse');
};

$editer = function (int $id) {
    $cible = User::findOrFail($id);

    if (! auth()->user()->peutGerer($cible)) {
        $this->erreur = "Vous n'avez pas la main sur ce compte.";

        return;
    }

    $this->editionId = $cible->id;
    $this->nom = $cible->name;
    $this->email = $cible->email;
    $this->telephone = $cible->telephone ?? '';
    $this->habilitations = $cible->habilitations ?? [];
    $this->motDePasse = '';
    $this->erreur = null;
};

$enregistrer = function () {
    $this->erreur = null;

    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:255'],
        'email' => ['required', new \App\Rules\AdresseEmail, 'max:255', Rule::unique('users', 'email')->ignore($this->editionId)],
        'telephone' => ['nullable', 'string', 'max:40'],
        'motDePasse' => [$this->editionId ? 'nullable' : 'required', 'string', 'min:10'],
        'habilitations' => ['array'],
        'habilitations.*' => [Rule::in(array_keys(User::SECTIONS_PLATEFORME))],
    ], [], [
        'nom' => 'nom', 'email' => 'adresse e-mail', 'telephone' => 'téléphone',
        'motDePasse' => 'mot de passe', 'habilitations' => 'habilitations',
    ]);

    if ($this->editionId) {
        $cible = User::findOrFail($this->editionId);

        // Deuxième vérification, côté serveur : l'écran a pu être manipulé depuis l'édition.
        if (! auth()->user()->peutGerer($cible)) {
            $this->erreur = "Vous n'avez pas la main sur ce compte.";

            return;
        }

        $cible->update([
            'name' => $donnees['nom'],
            'email' => $donnees['email'],
            'telephone' => $donnees['telephone'] ?: null,
            'habilitations' => array_values($donnees['habilitations'] ?? []),
            ...($donnees['motDePasse'] ? ['password' => Hash::make($donnees['motDePasse']), 'doit_changer_mot_de_passe' => true] : []),
        ]);

        $this->message = 'Administrateur mis à jour.';
        $this->reinitialiser();
        unset($this->administrateurs);

        return;
    }

    $nouveau = User::create([
        'name' => $donnees['nom'],
        'email' => $donnees['email'],
        'telephone' => $donnees['telephone'] ?: null,
        'password' => Hash::make($donnees['motDePasse']),
        'entreprise_id' => null,
        'est_actif' => true,
        'est_fondateur' => false,
        'doit_changer_mot_de_passe' => true,
        'cree_par_id' => auth()->id(),
        'habilitations' => array_values($donnees['habilitations'] ?? []),
        'email_verified_at' => now(),
    ]);

    // Les Super Admins n'appartiennent à aucune entreprise : équipe 0.
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $nouveau->assignRole('super_admin');

    $this->message = 'Super Admin secondaire créé. Il devra changer son mot de passe à la première connexion.';
    $this->reinitialiser();
    unset($this->administrateurs);
};

$basculerActivation = function (int $id) {
    $cible = User::findOrFail($id);

    if (! auth()->user()->peutGerer($cible)) {
        $this->erreur = "Le compte fondateur et les comptes créés par d'autres ne peuvent pas être modifiés.";

        return;
    }

    $cible->update(['est_actif' => ! $cible->est_actif]);
    unset($this->administrateurs);
    $this->message = $cible->est_actif ? 'Compte réactivé.' : 'Compte suspendu.';
};

$supprimer = function (int $id) {
    $cible = User::findOrFail($id);

    if (! auth()->user()->peutGerer($cible)) {
        $this->erreur = "Le compte fondateur et les comptes créés par d'autres ne peuvent pas être supprimés.";

        return;
    }

    $cible->delete();

    if ($this->editionId === $id) {
        $this->reinitialiser();
    }

    unset($this->administrateurs);
    $this->message = 'Compte supprimé.';
};

?>

<div>
    <div style="margin-bottom:14px;">
        <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:23px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:0;">
            Administrateurs de la plateforme
        </h1>
        <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:4px 0 0;">
            Créez des Super Admins secondaires et ouvrez-leur uniquement les sections nécessaires.
            Le compte fondateur ne peut être ni modifié ni supprimé, et chaque administrateur
            secondaire ne gère que les comptes qu'il a lui-même créés.
        </p>
    </div>

    @if ($message) <div class="encart encart-succes">{{ $message }}</div> @endif
    @if ($erreur) <div class="encart encart-alerte">{{ $erreur }}</div> @endif

    <x-carte-section titre="{{ $editionId ? 'Modifier un administrateur' : 'Nouveau Super Admin secondaire' }}"
        icone="commercial" couleur="#C8102E">

        <div class="bloc-saisie">
            <x-champ label="Nom et prénoms" model="nom" requis="true" />
            <x-champ label="Adresse e-mail" model="email" type="email" requis="true" />
            <x-champ label="Téléphone" model="telephone" />
        </div>

        <div style="margin-top:12px; max-width:420px;">
            <label class="champ-libelle">
                Mot de passe {!! $editionId ? '<span style="font-weight:400; color:var(--th-gris,#6B6E76);">(laisser vide pour ne pas changer)</span>' : '<span style="color:var(--th-accent,#C8102E);">*</span>' !!}
            </label>
            <div class="champ-mot-de-passe">
                <input type="{{ $voirMotDePasse ? 'text' : 'password' }}" wire:model="motDePasse" class="champ"
                    autocomplete="new-password" placeholder="10 caractères minimum">
                <button type="button" wire:click="$toggle('voirMotDePasse')" aria-label="Afficher le mot de passe">
                    @if ($voirMotDePasse)
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                            <path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                            <path d="M9.4 5.2A9.6 9.6 0 0 1 12 5c5 0 9 4.5 9 7 0 .9-.6 2.1-1.6 3.2M6.3 6.8C4 8.3 3 10.4 3 12c0 2.5 4 7 9 7 1.3 0 2.4-.2 3.5-.7"/>
                        </svg>
                    @else
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                            <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    @endif
                </button>
            </div>
            @error('motDePasse') <span class="champ-erreur">{{ $message }}</span> @enderror
        </div>

        <div style="margin-top:16px;">
            <label class="champ-libelle">Habilitations</label>
            <p style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin:0 0 8px;">
                Les sections non cochées sont inaccessibles : ni menu, ni adresse directe.
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:8px;">
                @foreach (\App\Models\User::SECTIONS_PLATEFORME as $cle => $libelle)
                    <label wire:key="hab-{{ $cle }}" class="messagerie-destinataire" style="border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; padding:9px 11px; background:#FFFDF6;">
                        <input type="checkbox" wire:model="habilitations" value="{{ $cle }}">
                        <span style="font-weight:600;">{{ $libelle }}</span>
                    </label>
                @endforeach
            </div>
            @error('habilitations.*') <span class="champ-erreur">{{ $message }}</span> @enderror
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" wire:click="enregistrer" class="bouton">
                {{ $editionId ? 'Enregistrer les modifications' : 'Créer le compte' }}
            </button>
            @if ($editionId)
                <button type="button" wire:click="reinitialiser" class="bouton bouton-secondaire">Annuler</button>
            @endif
        </div>
    </x-carte-section>

    <x-carte-section titre="Comptes existants" icone="liste" couleur="#2A2E35">
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Administrateur</th>
                        <th>E-mail</th>
                        <th>Type</th>
                        <th>Habilitations</th>
                        <th>Créé par</th>
                        <th>Statut</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->administrateurs->forPage($pageAdmins, 10) as $admin)
                        @php $gerable = $this->moi->peutGerer($admin); @endphp
                        <tr wire:key="admin-{{ $admin->id }}">
                            <td style="font-weight:600; display:flex; align-items:center; gap:8px;">
                                <x-avatar :utilisateur="$admin" :taille="26" />
                                {{ $admin->name }}
                            </td>
                            <td>{{ $admin->email }}</td>
                            <td>
                                <span class="pastille {{ $admin->est_fondateur ? 'pastille-rouge' : 'pastille-bleu' }}">
                                    {{ $admin->est_fondateur ? 'Fondateur' : 'Secondaire' }}
                                </span>
                            </td>
                            <td style="font-size:12.5px;">
                                @if ($admin->est_fondateur)
                                    Toutes les sections
                                @else
                                    {{ collect($admin->sectionsAutorisees())
                                        ->map(fn ($s) => \App\Models\User::SECTIONS_PLATEFORME[$s])
                                        ->implode(', ') ?: '— aucune —' }}
                                @endif
                            </td>
                            <td>{{ $admin->createur?->name ?? '—' }}</td>
                            <td>
                                <span class="pastille {{ $admin->est_actif ? 'pastille-vert' : 'pastille-ambre' }}">
                                    {{ $admin->est_actif ? 'Actif' : 'Suspendu' }}
                                </span>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                @if ($gerable)
                                    <button type="button" wire:click="editer({{ $admin->id }})"
                                        class="bouton bouton-secondaire bouton-petit">Modifier</button>
                                    <button type="button" wire:click="basculerActivation({{ $admin->id }})"
                                        class="bouton bouton-secondaire bouton-petit">
                                        {{ $admin->est_actif ? 'Suspendre' : 'Réactiver' }}
                                    </button>
                                    <button type="button" wire:click="supprimer({{ $admin->id }})"
                                        wire:confirm="Supprimer définitivement ce compte administrateur ?"
                                        class="bouton bouton-danger bouton-petit">Supprimer</button>
                                @else
                                    <span style="font-size:12px; color:var(--th-gris,#6B6E76);">
                                        {{ $admin->id === $this->moi->id ? 'Votre compte' : 'Hors de votre périmètre' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageAdmins" :total="$this->administrateurs->count()" prop="pageAdmins" />
    </x-carte-section>
</div>
