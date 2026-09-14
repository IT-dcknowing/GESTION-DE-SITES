<?php

use Modules\Noyau\Entreprises\Actions\CreerEntreprise;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Entreprises de la plateforme
|--------------------------------------------------------------------------
| Ouvrir un dossier ne demande qu'une raison sociale. Le reste — informations
| légales, villes, accès — se remplit ensuite, chacun à sa place : un dossier
| se prépare souvent avant qu'on connaisse le nom du gérant, et exiger une
| adresse de contact obligerait à en inventer une, puis à la retrouver.
|
| Le nom se corrige ici même. Deux entreprises portant le même intitulé, c'est
| une facture présentée au mauvais client tôt ou tard — typiquement une base de
| démonstration laissée à côté de la vraie exploitation.
*/

state([
    'pageEntreprises' => 1,
    'nouvelleEntreprise' => '',
    'message' => null,

    // Renommage en ligne : l'identifiant de la ligne ouverte, et le nom en cours.
    'renommeId' => null,
    'renommeNom' => '',
]);

$entreprises = computed(fn () => Entreprise::withCount(['sites', 'utilisateurs'])->orderBy('nom')->get());

$creer = function (CreerEntreprise $action) {
    $donnees = $this->validate([
        'nouvelleEntreprise' => ['required', 'string', 'min:2', 'max:255'],
    ], [], ['nouvelleEntreprise' => "nom de l'entreprise"]);

    $entreprise = $action->creerSeule(['nom' => $donnees['nouvelleEntreprise']]);

    $this->reset('nouvelleEntreprise');
    unset($this->entreprises);
    $this->message = "« {$entreprise->nom} » créée. Ajoutez-lui ses villes puis ses accès.";
};

$renommer = function (int $id) {
    $entreprise = Entreprise::find($id);

    if (! $entreprise) {
        return;
    }

    $this->renommeId = $entreprise->id;
    $this->renommeNom = $entreprise->nom;
};

$annulerRenommage = function () {
    $this->renommeId = null;
    $this->resetValidation();
};

$enregistrerRenommage = function () {
    // Le champ n'apparaît qu'une ligne ouverte : un appel sans ligne ne peut venir
    // que d'une requête forgée, on l'ignore sans rien changer.
    $entreprise = $this->renommeId ? Entreprise::find($this->renommeId) : null;

    if (! $entreprise) {
        $this->annulerRenommage();

        return;
    }

    $donnees = $this->validate([
        'renommeNom' => ['required', 'string', 'min:2', 'max:255'],
    ], [], ['renommeNom' => "nom de l'entreprise"]);

    // Le nom seul change : ni le slug, ni les écritures, ni les accès. Renommer une
    // base de démonstration ne doit rien lui faire perdre.
    $entreprise->forceFill(['nom' => $donnees['renommeNom']])->save();

    $this->renommeId = null;
    unset($this->entreprises);
    $this->message = 'Entreprise renommée.';
};

$basculerActive = function (int $id) {
    $entreprise = Entreprise::find($id);

    if (! $entreprise) {
        return;
    }

    $entreprise->forceFill(['est_active' => ! $entreprise->est_active])->save();
    unset($this->entreprises);
    $this->message = $entreprise->est_active
        ? "« {$entreprise->nom} » réactivée."
        : "« {$entreprise->nom} » suspendue : plus personne n'y accède.";
};

?>

<div>
    <div style="margin-bottom:14px;">
        <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:23px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:0;">
            Entreprises
        </h1>
        <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:4px 0 0;">
            Toutes les entreprises clientes de la plateforme. Chacune est étanche aux autres :
            aucune donnée ne circule de l'une à l'autre.
        </p>
    </div>

    @if ($message)
        <div class="encart encart-succes">{{ $message }}</div>
    @endif

    <x-carte-section titre="Nouvelle entreprise">
        <div class="bloc-saisie">
            <x-champ label="Nom de l'entreprise" model="nouvelleEntreprise" requis="true"
                placeholder="Ex. : L'Artisan Automobile" />
            <button type="button" wire:click="creer" class="bouton bouton-sombre">+ Créer l'entreprise</button>
        </div>
        <p style="font-size:11.5px; color:#9A9DA5; margin:8px 0 0;">
            Le nom suffit pour ouvrir le dossier. Ses villes, son logo et ses informations légales
            se renseignent ensuite dans ses paramètres ; ses accès dans « Accès ».
        </p>
    </x-carte-section>

    <x-carte-section titre="Entreprises de la plateforme">
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Entreprise</th>
                        <th>Plan</th>
                        <th>Sites</th>
                        <th>Utilisateurs</th>
                        <th>Statut</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->entreprises->forPage($pageEntreprises, 10) as $entreprise)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);" wire:key="ent-{{ $entreprise->id }}">
                            <td style="font-weight:700;">
                                @if ($renommeId === $entreprise->id)
                                    <input type="text" wire:model="renommeNom" value="{{ $renommeNom }}" class="champ" style="min-width:220px;">
                                    @error('renommeNom')
                                        <div style="font-size:11.5px; color:var(--th-accent,#C8102E); margin-top:4px;">{{ $message }}</div>
                                    @enderror
                                @else
                                    <a href="{{ route('super-admin.entreprises.show', $entreprise) }}" wire:navigate style="color:inherit;">
                                        {{ $entreprise->nom }}
                                    </a>
                                @endif
                            </td>
                            <td style="text-transform:capitalize;">{{ $entreprise->plan }}</td>
                            <td>{{ $entreprise->sites_count }}</td>
                            <td>{{ $entreprise->utilisateurs_count }}</td>
                            <td>
                                @if ($entreprise->est_active)
                                    <span class="pastille pastille-vert">Active</span>
                                @else
                                    <span class="pastille pastille-rouge">Suspendue</span>
                                @endif
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                @if ($renommeId === $entreprise->id)
                                    <button type="button" wire:click="enregistrerRenommage"
                                        class="bouton bouton-petit bouton-vert" style="margin-right:5px;">Enregistrer</button>
                                    <button type="button" wire:click="annulerRenommage"
                                        class="bouton bouton-petit bouton-secondaire">Annuler</button>
                                @else
                                    <button type="button" wire:click="renommer({{ $entreprise->id }})"
                                        class="bouton bouton-petit bouton-secondaire" style="margin-right:5px;">Renommer</button>
                                    <button type="button" wire:click="basculerActive({{ $entreprise->id }})"
                                        wire:confirm="{{ $entreprise->est_active ? 'Suspendre cette entreprise ? Plus personne ne pourra s\'y connecter.' : 'Réactiver cette entreprise ?' }}"
                                        class="bouton bouton-petit bouton-secondaire">
                                        {{ $entreprise->est_active ? 'Suspendre' : 'Réactiver' }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="6" texte="Aucune entreprise sur la plateforme." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageEntreprises" :total="$this->entreprises->count()" prop="pageEntreprises" />
    </x-carte-section>
</div>
