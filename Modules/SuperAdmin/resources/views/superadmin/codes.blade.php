<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use Modules\Noyau\Imports\Modeles\CodeAgent;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Les codes d'atelier, personne par personne
|--------------------------------------------------------------------------
| **Ce que cet écran répare.** Le code de deux lettres est la seule chose qui, dans
| les fichiers du logiciel, dise de qui vient une fiche et donc à quel atelier elle
| appartient. Trente-huit codes étaient connus de la base — et pas un seul n'était
| rattaché à un compte. Le rattachement existait pourtant : il fallait le faire code
| par code, dans le module Import, une entreprise à la fois.
|
| On part donc des personnes. C'est ainsi qu'on y pense quand on ouvre les accès d'une
| entreprise : « Koffi Yao, quel est son code ? », et non « KZ, c'est qui ? ».
|
| **Rien n'est deviné.** La liste déroulante propose les codes que les imports ont
| réellement rencontrés, avec le nombre de fiches derrière chacun — on choisit parmi
| ce qui existe plutôt que d'inventer deux lettres. Et ce qui est attribué ici devra
| être confirmé par l'intéressé lui-même à son prochain écran.
|
| Chaque ligne est un formulaire qui poste : aucun geste ne dépend d'un script.
*/

state(['entrepriseId' => '']);

mount(function () {
    // L'entreprise peut arriver par l'adresse : le contrôleur y renvoie après chaque
    // enregistrement, et la page doit revenir là où l'on travaillait.
    $this->entrepriseId = (string) (request()->integer('entreprise') ?: '');

    if ($this->entrepriseId === '') {
        $this->entrepriseId = (string) (Entreprise::orderBy('nom')->value('id') ?: '');
    }
});

$entreprises = computed(fn () => Entreprise::orderBy('nom')->get());

$cible = computed(fn () => $this->entrepriseId ? Entreprise::find($this->entrepriseId) : null);

/** Les comptes de l'entreprise, avec leur code et la date où ils l'ont confirmé. */
$personnel = computed(function () {
    if (! $this->entrepriseId) {
        return collect();
    }

    $comptes = User::withoutGlobalScopes()
        ->where('entreprise_id', $this->entrepriseId)
        ->with(['ville', 'site'])
        ->orderBy('name')
        ->get();

    $roles = User::nomsRolesParUtilisateur($comptes->pluck('id'));

    $codes = CodeAgent::withoutGlobalScopes()
        ->where('entreprise_id', $this->entrepriseId)
        ->whereNotNull('user_id')
        ->get()
        ->keyBy('user_id');

    return $comptes->map(fn (User $compte) => [
        'compte' => $compte,
        'role' => LibellesRoles::liste($roles[$compte->id] ?? ''),
        'code' => $codes->get($compte->id),
    ]);
});

/**
 * Les codes que les imports ont vus, et ce qu'il y a derrière chacun.
 *
 * Montrés pour qu'on choisisse parmi ce qui existe. Un code inventé ne rattachera
 * jamais rien : il n'apparaît dans aucun numéro de fiche.
 */
$codesConnus = computed(fn () => $this->entrepriseId
    ? CodeAgent::withoutGlobalScopes()
        ->where('entreprise_id', $this->entrepriseId)
        ->orderByDesc('occurrences')->orderBy('code')
        ->get()
    : collect());

$libres = computed(fn () => $this->codesConnus->whereNull('user_id'));

?>

<div>
    <x-titre-ecran titre="Codes d'atelier"
        sous-titre="Les deux lettres par lesquelles le logiciel désigne chaque personne — et qui décident de l'atelier auquel son travail est rattaché." />

    @if (session('refus-code'))
        <div class="encart encart-alerte" style="margin-bottom:16px;">{{ session('refus-code') }}</div>
    @endif

    <x-carte-section titre="Choisir l'entreprise">
        <div class="bloc-saisie">
            <x-champ label="Entreprise" model="entrepriseId" type="select" live="true" width="280"
                :options="$this->entreprises->pluck('nom', 'id')" />
        </div>

        <div class="encart" style="margin-top:4px;">
            <b>Où se lit un code.</b> Il est inscrit dans le numéro de chaque fiche du logiciel&nbsp;:
            <span style="font-family:var(--font-mono,monospace);">FR-<b>KZ</b>N° 010669</span> —
            juste après «&nbsp;FR-&nbsp;». C'est lui, et lui seul, qui distingue le Site&nbsp;1 du
            Site&nbsp;2 d'Abidjan, qu'aucun fichier ne sépare.
            <br><br>
            <b>Ce qui suit l'attribution.</b> La personne verra la question à son prochain écran&nbsp;:
            «&nbsp;est-ce bien votre code&nbsp;?&nbsp;». Tant qu'elle n'a pas répondu, la colonne
            «&nbsp;Confirmé&nbsp;» reste vide. Elle peut aussi le corriger elle-même depuis son profil.
        </div>
    </x-carte-section>

    @if ($this->cible)
        @if ($this->libres->isNotEmpty())
            <x-carte-section titre="Codes vus dans les imports, encore sans titulaire">
                <div style="display:flex; flex-wrap:wrap; gap:7px;">
                    @foreach ($this->libres as $libre)
                        <span style="display:inline-block; background:#FFF3B0; border:1px solid #E5D98A; border-radius:20px;
                                     padding:3px 11px; font-size:12.5px;">
                            <b style="letter-spacing:1px;">{{ $libre->code }}</b>
                            <span style="color:#6B6E76;">· {{ number_format($libre->occurrences, 0, ',', ' ') }} fiche(s)</span>
                            @if ($libre->libelle)
                                <span style="color:#6B6E76;">· {{ $libre->libelle }}</span>
                            @endif
                        </span>
                    @endforeach
                </div>
            </x-carte-section>
        @endif

        <x-carte-section :titre="'Personnel de '.$this->cible->nom">
            {{-- Une seule liste de suggestions pour toute la page : la même partagée par
                 tous les champs, plutôt que recopiée à chaque ligne. --}}
            <datalist id="codes-connus">
                @foreach ($this->codesConnus as $connu)
                    <option value="{{ $connu->code }}">{{ $connu->occurrences }} fiche(s){{ $connu->libelle ? ' — '.$connu->libelle : '' }}</option>
                @endforeach
            </datalist>

            {{-- Les formulaires vivent hors du tableau, et les champs les désignent par
                 leur identifiant.

                 **Pourquoi.** Un `<form>` placé entre `<tr>` et `<td>` n'est pas du HTML
                 valide : le navigateur le déplace hors du tableau au moment de l'analyse, et
                 le bouton se retrouve à poster un formulaire vide. La ligne paraîtrait alors
                 enregistrée sans que rien ne parte — exactement le genre de panne muette
                 qu'on chasse partout ailleurs ici. L'attribut `form` règle cela sans script. --}}
            @foreach ($this->personnel as $ligne)
                <form method="POST" action="{{ route('super-admin.codes.enregistrer') }}"
                      id="code-{{ $ligne['compte']->id }}" hidden>
                    @csrf
                    <input type="hidden" name="utilisateur" value="{{ $ligne['compte']->id }}">
                    <input type="hidden" name="entreprise" value="{{ $this->entrepriseId }}">
                </form>
            @endforeach

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Personne</th>
                            <th>Rôle</th>
                            <th>Rattachement</th>
                            <th style="width:150px;">Code d'atelier</th>
                            <th style="width:150px;">Confirmé</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->personnel as $ligne)
                            @php $compte = $ligne['compte']; @endphp
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                    <td>
                                        <strong>{{ $compte->name }}</strong>
                                        <div style="font-size:11.5px; color:#6B6E76;">{{ $compte->email }}</div>
                                    </td>
                                    <td>{{ $ligne['role'] ?: '—' }}</td>
                                    <td style="color:#6B6E76; font-size:12.5px;">
                                        {{ $compte->site?->nom ?? $compte->ville?->nom ?? '—' }}
                                    </td>
                                    <td>
                                        <input type="text" name="code" form="code-{{ $compte->id }}"
                                               list="codes-connus" maxlength="2"
                                               pattern="[A-Za-z]{2}" autocomplete="off"
                                               value="{{ $ligne['code']?->code }}" placeholder="—"
                                               style="width:100%; box-sizing:border-box; text-transform:uppercase;
                                                      letter-spacing:2px; font-weight:700; text-align:center;
                                                      border:1px solid #E3E0D8; border-radius:6px; padding:6px 8px;
                                                      font-size:14px; font-family:inherit; background:var(--th-champ,#FFFBEA);">
                                    </td>
                                    <td style="font-size:12px;">
                                        @if ($ligne['code'] === null)
                                            <span style="color:#9A9DA5;">—</span>
                                        @elseif ($compte->code_atelier_confirme_le)
                                            <span style="color:#1E7B34; font-weight:600;">
                                                le {{ $compte->code_atelier_confirme_le->format('d/m/Y') }}
                                            </span>
                                        @else
                                            <span style="color:#D97706; font-weight:600;">en attente</span>
                                        @endif
                                    </td>
                                    <td style="text-align:right;">
                                        <button type="submit" form="code-{{ $compte->id }}"
                                                class="bouton bouton-secondaire"
                                                style="padding:6px 13px; font-size:12.5px;">Enregistrer</button>
                                    </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Cette entreprise n'a encore aucun accès ouvert." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p style="font-size:11.5px; color:#9A9DA5; margin:12px 0 0;">
                Vider le champ retire le code. Saisir un code déjà porté par quelqu'un le lui
                reprend&nbsp;: le déplacement est annoncé et inscrit au journal des deux côtés.
            </p>
        </x-carte-section>
    @endif
</div>
