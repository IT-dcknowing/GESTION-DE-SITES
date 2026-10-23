<?php

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\RoleConfigure;
use Modules\Noyau\Entreprises\Support\CatalogueDesPages;
use Modules\Noyau\Entreprises\Support\LibellesRoles;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Les rôles configurés — 22/10
|--------------------------------------------------------------------------
| « Au Super admin, une section où je configure les rôles : je mets le nom du rôle, je coche les
| sections qui doivent apparaître avec les pages que je veux faire apparaître ; et un bouton
| Modifier pour modifier le rôle (sections et pages). Mets cette section dans la page
| Maintenance. »
|
| Un rôle ainsi défini **lit** ses pages, il n'y écrit pas : voir `CatalogueDesPages`. Le nom
| technique se tire du libellé à la création et ne change plus — c'est lui que portent les accès ;
| le libellé et les pages se modifient.
*/

state([
    'libelle' => '',
    'pagesCochees' => [],
    // Le nom technique du rôle repris par « Modifier », ou null pour une création.
    'enModification' => null,
]);

/** La Maintenance est au Super admin qui en a la section : vérifié dans chaque action, pas seulement à la route. */
$garder = protect(function () {
    $moi = auth()->user();

    abort_unless($moi !== null && $moi->hasRole('super_admin') && $moi->peutAccederA('maintenance'), 403);
});

$roles = computed(fn () => CatalogueDesPages::roles());

// Les rôles du métier, lus dans les routes : montrés, pas modifiables ici (23/10).
$rolesDuMetier = computed(fn () => CatalogueDesPages::rolesDuMetier());

/** Combien d'accès portent chaque rôle, toutes entreprises confondues : une requête pour la liste. */
$nombreDAcces = computed(fn () => DB::table('model_has_roles')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->whereIn('roles.name', [...array_keys($this->roles), ...array_keys(LibellesRoles::TOUS)])
    ->groupBy('roles.name')
    ->selectRaw('roles.name as nom, count(distinct model_has_roles.model_id) as n')
    ->pluck('n', 'nom')->all());

/** Une section cochée coche toutes ses pages ; décochée, elle les retire toutes. */
$basculerSection = function (string $section) {
    $this->garder();

    $pages = array_keys(CatalogueDesPages::SECTIONS[$section]['pages'] ?? []);
    $cochees = array_map('strval', (array) $this->pagesCochees);

    $this->pagesCochees = array_diff($pages, $cochees) === []
        ? array_values(array_diff($cochees, $pages))
        : array_values(array_unique([...$cochees, ...$pages]));
};

$modifier = function (string $nom) {
    $this->garder();

    $role = $this->roles[$nom] ?? null;

    if ($role === null) {
        return;
    }

    $this->enModification = $nom;
    $this->libelle = $role['libelle'];
    $this->pagesCochees = $role['pages'];
    $this->resetValidation();
};

$annuler = function () {
    $this->enModification = null;
    $this->libelle = '';
    $this->pagesCochees = [];
    $this->resetValidation();
};

$enregistrer = function () {
    $this->garder();

    $this->validate(['libelle' => ['required', 'string', 'max:120']], [], ['libelle' => 'nom du rôle']);

    // Seules les pages du catalogue comptent : une case inventée par le navigateur n'ouvre rien.
    $pages = CatalogueDesPages::pagesConnues((array) $this->pagesCochees);

    if ($pages === []) {
        $this->addError('pagesCochees', 'Cochez au moins une page : un rôle sans page ne mène nulle part.');

        return;
    }

    // Le rôle modifié est relu parmi ceux qui existent ; une création tire son nom du libellé.
    $nom = $this->enModification !== null && isset($this->roles[$this->enModification])
        ? $this->enModification
        : CatalogueDesPages::nomTechnique($this->libelle);

    if ($this->enModification === null) {
        if ($nom === '' || array_key_exists($nom, LibellesRoles::TOUS)) {
            $this->addError('libelle', 'Ce nom est celui d\'un rôle du métier, ou ne contient aucune lettre : choisissez-en un autre.');

            return;
        }

        if (isset($this->roles[$nom])) {
            $this->addError('libelle', 'Le rôle « '.$this->roles[$nom]['libelle'].' » existe déjà : utilisez son bouton Modifier.');

            return;
        }
    }

    $role = RoleConfigure::query()->firstOrNew(['nom' => $nom]);
    $creation = ! $role->exists;
    $role->fill([
        'libelle' => trim($this->libelle),
        'pages' => $pages,
        'modifie_par' => auth()->id(),
    ]);

    if ($creation) {
        $role->cree_par = auth()->id();
    }

    $role->save();

    activity()->performedOn($role)->causedBy(auth()->user())
        ->withProperties(['pages' => $pages])
        ->log($creation ? 'Rôle configuré créé' : 'Rôle configuré modifié');

    CatalogueDesPages::oublier();
    unset($this->roles, $this->nombreDAcces);

    $libelle = $role->libelle;
    $this->annuler();

    $this->dispatch('annonce', ton: 'succes', texte: 'Le rôle « '.$libelle.' » est enregistré'
        .($creation ? ' : il se choisit désormais à la création d\'un accès.' : '.'));
};

?>

<div>
    <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
        Un rôle configuré <b>lit</b> les pages cochées, dans la ville de son compte (ou toutes, s'il les couvre) ;
        il n'y écrit pas. Il se choisit à la création d'un accès, comme les rôles du métier. Le nom technique se tire
        du nom du rôle à sa création et ne change plus ; le nom affiché et les pages se modifient.
    </p>

    <h3 style="font-size:14.5px; font-weight:700; margin:0 0 6px;">Rôles configurés</h3>
    <div class="tableau-conteneur" style="margin-bottom:16px;">
        <table class="tableau">
            <thead>
                <tr><th>Rôle</th><th>Pages ouvertes</th><th style="text-align:right;">Accès</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($this->roles as $nom => $role)
                    <tr wire:key="role-{{ $nom }}" style="{{ $enModification === $nom ? 'background:#FDF2F4;' : '' }}">
                        <td>
                            <b>{{ $role['libelle'] }}</b>
                            <div style="font-size:11px; color:#9A9DA5;">{{ $nom }}{{ $role['enregistre'] ? '' : ' · défini dans le code' }}</div>
                        </td>
                        <td style="font-size:12px; color:#4B4E55;">
                            @foreach (CatalogueDesPages::SECTIONS as $section)
                                @php $dans = array_values(array_intersect(array_keys($section['pages']), $role['pages'])); @endphp
                                @if ($dans !== [])
                                    <div><b>{{ $section['libelle'] }}</b> : {{ collect($dans)->map(fn ($p) => $section['pages'][$p]['libelle'])->implode(', ') }}</div>
                                @endif
                            @endforeach
                        </td>
                        <td style="text-align:right;">{{ $this->nombreDAcces[$nom] ?? 0 }}</td>
                        <td style="text-align:right;">
                            <button type="button" wire:click="modifier('{{ $nom }}')" class="bouton bouton-secondaire" style="padding:5px 10px;">Modifier</button>
                        </td>
                    </tr>
                @empty
                    <x-table-vide :colspan="4" texte="Aucun rôle configuré." />
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- **Les rôles du métier aussi — 23/10** : « même les rôles déjà créés doivent figurer, avec ce
         qu'ils contiennent ». Écrits dans le code, ils écrivent dans leurs écrans : on les lit ici, on ne
         les modifie pas d'une case à cocher. --}}
    <h3 style="font-size:14.5px; font-weight:700; margin:0 0 6px;">Rôles du métier — définis dans le code</h3>
    <p style="margin:0 0 8px; font-size:12px; color:#6B6E76;">
        Lus dans les routes de l'application : ce que chacun ouvre aujourd'hui. Ils ne se modifient pas ici — leurs
        écrans écrivent, et ce qu'ils y font se vérifie dans le code.
    </p>
    <div class="tableau-conteneur" style="margin-bottom:18px;">
        <table class="tableau">
            <thead>
                <tr><th>Rôle</th><th>Pages ouvertes</th><th>Autres écrans</th><th style="text-align:right;">Accès</th></tr>
            </thead>
            <tbody>
                @foreach ($this->rolesDuMetier as $nom => $role)
                    <tr wire:key="metier-{{ $nom }}">
                        <td><b>{{ $role['libelle'] }}</b><div style="font-size:11px; color:#9A9DA5;">{{ $nom }}</div></td>
                        <td style="font-size:12px; color:#4B4E55;">
                            @foreach (CatalogueDesPages::SECTIONS as $section)
                                @php $dans = array_values(array_intersect(array_keys($section['pages']), $role['pages'])); @endphp
                                @if ($dans !== [])
                                    <div><b>{{ $section['libelle'] }}</b> : {{ collect($dans)->map(fn ($p) => $section['pages'][$p]['libelle'])->implode(', ') }}</div>
                                @endif
                            @endforeach
                            @if ($role['pages'] === []) <span style="color:#9A9DA5;">—</span> @endif
                        </td>
                        <td style="font-size:12px; color:#4B4E55;">{{ $role['autres'] === [] ? '—' : implode(' · ', $role['autres']) }}</td>
                        <td style="text-align:right;">{{ $this->nombreDAcces[$nom] ?? 0 }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <form wire:submit="enregistrer">
        <h3 style="font-size:14.5px; font-weight:700; margin:0 0 8px;">
            {{ $enModification ? 'Modifier le rôle « '.($this->roles[$enModification]['libelle'] ?? $enModification).' »' : 'Créer un rôle' }}
        </h3>
        <div class="bloc-saisie" style="background:#fff; border-style:solid; margin-bottom:12px;">
            <x-champ label="Nom du rôle" model="libelle" requis width="280" placeholder="Trésorerie, Contrôle de gestion…" />
            @if ($enModification)
                <div style="align-self:flex-end; font-size:12px; color:#6B6E76; padding-bottom:9px;">Nom technique : <code>{{ $enModification }}</code></div>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px;">
            @foreach (CatalogueDesPages::SECTIONS as $cle => $section)
                @php
                    $pagesSection = array_keys($section['pages']);
                    $cochees = array_intersect($pagesSection, array_map('strval', (array) $pagesCochees));
                @endphp
                <div wire:key="section-{{ $cle }}" style="border:1px solid #E2E0D8; border-radius:10px; padding:10px 12px;">
                    <label style="display:flex; gap:8px; align-items:center; font-weight:700; font-size:13.5px; cursor:pointer;">
                        <input type="checkbox" wire:click="basculerSection('{{ $cle }}')" @checked(count($cochees) === count($pagesSection))>
                        {{ $section['libelle'] }}
                    </label>
                    <div style="margin:6px 0 0 22px; display:flex; flex-direction:column; gap:4px;">
                        @foreach ($section['pages'] as $page => $definition)
                            <label style="display:flex; gap:7px; align-items:center; font-size:12.5px; cursor:pointer;">
                                <input type="checkbox" wire:model.live="pagesCochees" value="{{ $page }}">
                                {{ $definition['libelle'] }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @error('pagesCochees') <p style="margin:8px 0 0; color:#C8102E; font-size:12.5px;">{{ $message }}</p> @enderror

        <div style="margin-top:14px; display:flex; gap:8px;">
            <button type="submit" class="bouton">{{ $enModification ? 'Enregistrer les modifications' : 'Créer le rôle' }}</button>
            @if ($enModification || $libelle !== '' || $pagesCochees !== [])
                <button type="button" wire:click="annuler" class="bouton bouton-secondaire">Annuler</button>
            @endif
        </div>
    </form>
</div>
