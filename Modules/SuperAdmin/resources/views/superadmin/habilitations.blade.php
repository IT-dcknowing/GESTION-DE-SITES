<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Support\CatalogueDesPages;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Entreprises\Support\LibellesRoles;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Les habilitations de modification et de suppression — 26/10
|--------------------------------------------------------------------------
| « Au niveau de la maintenance, ajoute une section me permettant de donner des habilitations de
| suppression et de modification à certains rôles déjà créés ou à certains utilisateurs
| précisément ; les boutons vont devoir apparaître chez ceux qui ont ces habilitations. »
|
| La règle se lit dans `HabilitationsDActions` : le code dit qui a le geste au départ, cette
| section en donne ou en retire. Le gérant ne le perd jamais.
*/

state([
    // « role » ou « compte ».
    'beneficiaire' => 'role',
    'role' => '',
    'compte' => '',
    'domaine' => 'creances',
    'actions' => [],
    // « accorder » ou « retirer ».
    'sens' => 'accorder',
]);

/** La Maintenance est au Super admin qui en a la section : vérifié dans chaque action, pas seulement à la route. */
$garder = protect(function () {
    $moi = auth()->user();

    abort_unless($moi !== null && $moi->hasRole('super_admin') && $moi->peutAccederA('maintenance'), 403);
});

/** Les rôles déjà créés : ceux du métier (hors plateforme) et ceux configurés en Maintenance. */
$roles = computed(fn () => collect(LibellesRoles::TOUS)->except('super_admin')
    ->merge(collect(CatalogueDesPages::roles())->map(fn ($r) => $r['libelle']))
    ->all());

/** Les comptes des entreprises, nommés avec leur entreprise : le même nom se retrouve d'une entreprise à l'autre. */
$comptes = computed(fn () => User::query()->withoutGlobalScopes()
    ->leftJoin('entreprises', 'entreprises.id', '=', 'users.entreprise_id')
    ->whereNotNull('users.entreprise_id')
    ->orderBy('entreprises.nom')->orderBy('users.name')
    ->get(['users.id', 'users.name', 'users.email', 'entreprises.nom as entreprise'])
    ->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->email.($u->entreprise ? ' · '.$u->entreprise : '')])
    ->all());

$lignes = computed(fn () => HabilitationsDActions::lignes());

$enregistrer = function () {
    $this->garder();

    $this->validate([
        'beneficiaire' => ['required', 'in:role,compte'],
        'domaine' => ['required', 'in:'.implode(',', array_keys(HabilitationsDActions::DOMAINES))],
        'actions' => ['required', 'array', 'min:1'],
        'sens' => ['required', 'in:accorder,retirer'],
        'role' => ['required_if:beneficiaire,role'],
        'compte' => ['required_if:beneficiaire,compte'],
    ], [
        'actions.required' => 'Cochez au moins un geste : modifier ou supprimer.',
        'role.required_if' => 'Choisissez le rôle.',
        'compte.required_if' => 'Choisissez le compte.',
    ]);

    // Seuls les gestes et les bénéficiaires connus comptent : une valeur inventée par le navigateur n'ouvre rien.
    $actions = array_values(array_filter((array) $this->actions, fn ($a) => HabilitationsDActions::existe($this->domaine, (string) $a)));

    if ($this->beneficiaire === 'role') {
        if (! array_key_exists($this->role, $this->roles)) {
            $this->addError('role', 'Ce rôle n\'existe pas.');

            return;
        }

        if ($this->sens === 'retirer' && $this->role === HabilitationsDActions::INAMOVIBLE) {
            $this->addError('role', 'Le gérant répond de l\'entreprise : ses gestes ne se retirent pas.');

            return;
        }
    } elseif (! array_key_exists((int) $this->compte, $this->comptes)) {
        $this->addError('compte', 'Ce compte n\'existe pas.');

        return;
    }

    foreach ($actions as $action) {
        $cle = ['domaine' => $this->domaine, 'action' => $action]
            + ($this->beneficiaire === 'role' ? ['role' => $this->role, 'user_id' => null] : ['role' => null, 'user_id' => (int) $this->compte]);

        $existante = HabilitationAction::query()->where($cle)->first();
        $duDepart = $this->beneficiaire === 'role'
            && in_array($this->role, HabilitationsDActions::DOMAINES[$this->domaine]['actions'][$action]['defaut'], true);

        // Pour un rôle, une ligne ne dit que l'écart au code : accorder un geste du départ efface son retrait,
        // retirer un geste qui n'était qu'accordé efface l'accord.
        $inutile = $this->beneficiaire === 'role' && ($this->sens === 'accorder') === $duDepart;

        if ($inutile) {
            if ($existante !== null) {
                activity()->performedOn($existante)->causedBy(auth()->user())->withProperties($cle)
                    ->log('Habilitation rendue à la règle du code');
                $existante->delete();
            }

            continue;
        }

        $ligne = $existante ?? new HabilitationAction($cle);
        $ligne->fill(['accorde' => $this->sens === 'accorder', 'accorde_par' => auth()->id()])->save();

        activity()->performedOn($ligne)->causedBy(auth()->user())->withProperties($cle + ['accorde' => $ligne->accorde])
            ->log($ligne->accorde ? 'Habilitation accordée' : 'Habilitation retirée');
    }

    HabilitationsDActions::oublier();
    unset($this->lignes);
    $this->actions = [];

    $this->dispatch('annonce', ton: 'succes', texte: 'Habilitations enregistrées : les boutons suivent dès le prochain affichage.');
};

/** Efface une ligne : le rôle ou le compte revient à la règle du code. */
$effacer = function (int $id) {
    $this->garder();

    $ligne = HabilitationAction::query()->find($id);

    if ($ligne !== null) {
        activity()->performedOn($ligne)->causedBy(auth()->user())
            ->withProperties($ligne->only(['domaine', 'action', 'role', 'user_id', 'accorde']))
            ->log('Habilitation effacée');
        $ligne->delete();
    }

    HabilitationsDActions::oublier();
    unset($this->lignes);
    $this->dispatch('annonce', ton: 'succes', texte: 'Habilitation effacée : la règle du code s\'applique de nouveau.');
};

?>

<div>
    <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
        Qui voit les boutons <b>Modifier</b> et <b>Supprimer</b> sur les créances de l'état des impayés et sur les
        pièces fournisseurs. Au départ, le code les donne aux rôles qui les avaient déjà ; ici, on les donne à un autre
        rôle ou à un compte précis, ou on les retire. Ce qui est dit d'un compte l'emporte sur son rôle ; le gérant
        garde toujours ses gestes. L'habilitation ouvre le geste sans lever les verrous de l'écran : une pièce réglée ou
        venue d'un fichier ne se supprime pas, quel que soit le compte.
    </p>

    <div class="tableau-conteneur" style="margin-bottom:16px;">
        <table class="tableau">
            <thead>
                <tr><th>Geste</th><th>Rôles qui l'ont</th><th>Comptes précis</th></tr>
            </thead>
            <tbody>
                @php $libelleRole = fn (?string $r) => $this->roles[$r] ?? LibellesRoles::de($r); @endphp
                @foreach (HabilitationsDActions::DOMAINES as $domaineCle => $domaineDef)
                    @foreach ($domaineDef['actions'] as $actionCle => $actionDef)
                        @php
                            $etat = HabilitationsDActions::etat($domaineCle, $actionCle);
                            $parRole = $this->lignes->where('domaine', $domaineCle)->where('action', $actionCle)->whereNull('user_id')->keyBy('role');
                        @endphp
                        <tr wire:key="geste-{{ $domaineCle }}-{{ $actionCle }}">
                            <td>
                                <b>{{ $actionDef['libelle'] }}</b>
                                <div style="font-size:11px; color:#9A9DA5;">{{ $domaineDef['libelle'] }}</div>
                            </td>
                            <td style="font-size:12px;">
                                @foreach ($etat['defaut'] as $r)
                                    @if (in_array($r, $etat['retires'], true) && $r !== HabilitationsDActions::INAMOVIBLE)
                                        <div style="color:#9A9DA5;">
                                            <s>{{ $libelleRole($r) }}</s> — retiré
                                            <button type="button" wire:click="effacer({{ $parRole[$r]->id }})" class="bouton bouton-secondaire" style="padding:2px 8px; font-size:11px;">Rendre</button>
                                        </div>
                                    @else
                                        <div>{{ $libelleRole($r) }} <span style="color:#9A9DA5;">· par le code</span></div>
                                    @endif
                                @endforeach
                                @foreach ($etat['accordes'] as $r)
                                    <div style="color:#0E7A54; font-weight:600;">
                                        {{ $libelleRole($r) }} · accordé
                                        <button type="button" wire:click="effacer({{ $parRole[$r]->id }})" class="bouton bouton-secondaire" style="padding:2px 8px; font-size:11px;">Retirer</button>
                                    </div>
                                @endforeach
                            </td>
                            <td style="font-size:12px;">
                                @forelse ($etat['comptes'] as $ligne)
                                    <div style="{{ $ligne->accorde ? 'color:#0E7A54; font-weight:600;' : 'color:#C8102E;' }}">
                                        {{ $ligne->utilisateur?->name ?? 'Compte n° '.$ligne->user_id }}
                                        {{ $ligne->accorde ? '· accordé' : '· retiré' }}
                                        <button type="button" wire:click="effacer({{ $ligne->id }})" class="bouton bouton-secondaire" style="padding:2px 8px; font-size:11px;">Effacer</button>
                                    </div>
                                @empty
                                    <span style="color:#9A9DA5;">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    <form wire:submit="enregistrer">
        <h3 style="font-size:14.5px; font-weight:700; margin:0 0 8px;">Donner ou retirer une habilitation</h3>
        {{-- Les champs de la maison (`x-champ`) : libellé au-dessus, même cadre que partout — 26/10,
             « le style n'y est pas ». --}}
        <div class="bloc-saisie" style="background:#fff; border-style:solid; margin-bottom:12px;">
            <x-champ label="Geste" model="sens" type="select" width="150" :options="['accorder' => 'Accorder', 'retirer' => 'Retirer']" />
            <x-champ label="À" model="beneficiaire" type="select" :live="true" width="190" :options="['role' => 'Un rôle', 'compte' => 'Un compte précis']" />
            @if ($beneficiaire === 'role')
                <x-champ label="Rôle" model="role" type="select" width="240" vide="— à choisir —" :options="$this->roles" requis />
            @else
                <div style="flex:1 1 300px; max-width:420px;">
                    <x-select-cherchable id="hab-compte" label="Compte" model="compte" :valeur="$compte"
                        :options="$this->comptes" vide="— choisir le compte —" placeholder="Rechercher par le nom ou le courriel…" :seuil="0" />
                    @error('compte') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            @endif
            <x-champ label="Sur" model="domaine" type="select" width="280"
                :options="collect(HabilitationsDActions::DOMAINES)->map(fn ($d) => $d['libelle'])->all()" />
            <div style="display:flex; flex-direction:column;">
                <label class="champ-libelle">Gestes<span style="color:var(--th-accent);"> *</span></label>
                <div style="display:flex; gap:14px; align-items:center; min-height:38px;">
                    <label style="display:flex; gap:6px; align-items:center; font-size:13.5px; cursor:pointer;">
                        <input type="checkbox" wire:model="actions" value="modifier"> Modifier
                    </label>
                    <label style="display:flex; gap:6px; align-items:center; font-size:13.5px; cursor:pointer;">
                        <input type="checkbox" wire:model="actions" value="supprimer"> Supprimer
                    </label>
                </div>
            </div>
        </div>
        @error('actions') <p class="champ-erreur" style="margin:0 0 8px;">{{ $message }}</p> @enderror
        <button type="submit" class="bouton">Enregistrer</button>
    </form>
</div>
