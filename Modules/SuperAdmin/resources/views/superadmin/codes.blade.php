<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
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
| Chaque ligne est un formulaire qui poste : aucun geste ne dépend d'un script. Le seul
| script de l'écran accorde l'atelier à la ville dans le formulaire de déclaration, et
| s'il ne part pas la liste reste entière — le serveur refuse de toute façon un atelier
| qui ne serait pas de la ville choisie.
|
| **Deux entrées, deux boutons en haut.** « Comptes » part des personnes qui ont un accès ;
| « Code-import » part de ceux qui saisissent dans le logiciel et n'en ont pas. Un code
| déclaré là-bas est un code comme les autres pour le rattachement des imports : même
| référentiel, même cascade, mêmes fiches comptées. Il n'a pas d'accès, voilà tout.
*/

state(['entrepriseId' => '']);

/*
 * Deux vues sur la même donnée, et le passage de l'une à l'autre est un simple lien.
 *
 * « Comptes » part des personnes qui ont un accès : Koffi Yao, quel est son code ?
 * « Code-import » part des codes que les imports rencontrent et qui n'appartiennent à
 * personne : QUI est « TT », où travaille-t-il, qui l'appelle en cas de doute ?
 *
 * La seconde manquait, et c'est elle qui portait le vrai trou : une partie de ceux qui
 * saisissent dans le logiciel d'atelier n'ont pas accès à cette application. Leur code
 * arrive à chaque import et restait une énigme de deux lettres.
 */
state(['vue' => 'comptes']);

/*
 * Les deux lettres portées dans le formulaire de déclaration.
 *
 * Elles arrivent par l'adresse, jamais autrement : « Corriger » sur une ligne du tableau et
 * les pastilles des codes déjà croisés sont de simples liens. Le formulaire s'ouvre donc
 * rempli sans qu'aucun script n'ait eu à s'exécuter.
 */
state(['codeSaisi' => '']);

mount(function () {
    // L'entreprise peut arriver par l'adresse : le contrôleur y renvoie après chaque
    // enregistrement, et la page doit revenir là où l'on travaillait.
    $this->entrepriseId = (string) (request()->integer('entreprise') ?: '');
    $this->vue = request()->string('vue')->value() === 'import' ? 'import' : 'comptes';
    $this->codeSaisi = mb_strtoupper(trim(request()->string('code')->value()));

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
        ->with(['ville', 'site'])
        ->orderByDesc('occurrences')->orderBy('code')
        ->get()
    : collect());

$libres = computed(fn () => $this->codesConnus->whereNull('user_id'));

/**
 * Les codes de saisie déclarés — ceux qu'on a nommés, sans leur ouvrir d'accès.
 *
 * Un code est « déclaré » dès qu'il porte un nom. Le critère est celui-là et pas une
 * colonne de plus : le nom est justement ce qui manquait, et l'écrire est tout ce que ce
 * formulaire fait. Qu'il vienne d'un import ou qu'on l'ait créé de toutes pièces ne change
 * rien à ce qu'il est ensuite.
 */
$declares = computed(fn () => $this->codesConnus
    ->whereNull('user_id')
    ->filter(fn (CodeAgent $code) => trim((string) $code->nom) !== '')
    ->sortBy('code', SORT_NATURAL | SORT_FLAG_CASE)
    ->values());

/** Ceux que les fichiers ont croisés et que personne n'a encore nommés. */
$nonNommes = computed(fn () => $this->codesConnus
    ->whereNull('user_id')
    ->filter(fn (CodeAgent $code) => trim((string) $code->nom) === '')
    ->values());

/** Les villes de l'entreprise, pour dire où travaille un code sans compte. */
$villes = computed(fn () => $this->entrepriseId
    ? Ville::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)->orderBy('nom')->get()
    : collect());

/**
 * Les ateliers, rangés par ville.
 *
 * C'est le « lieu précis » : Abidjan en compte deux, et c'est justement ce qu'aucun
 * fichier ne sait distinguer. Le code est le seul à pouvoir trancher, encore faut-il
 * qu'on lui ait dit lequel des deux.
 */
$sites = computed(fn () => $this->entrepriseId
    ? Site::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)
        ->with('ville')->orderBy('nom')->get()
    : collect());

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
            <b>Une confirmation qui tarde ne bloque rien</b>&nbsp;: les imports se servent du
            rattachement dès qu'il est posé. La question sert à le corriger, pas à l'autoriser.
        </div>
    </x-carte-section>

    @if ($this->cible)
        {{-- Les deux entrées sur la même donnée. De vrais liens : la bascule fonctionne
             dans un navigateur où la couche interactive ne démarre pas, comme le reste
             de cet écran. --}}
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 16px;">
            <a href="{{ route('super-admin.codes', ['entreprise' => $this->entrepriseId]) }}"
                class="bouton {{ $vue === 'comptes' ? 'bouton-principal' : 'bouton-secondaire' }}"
                style="padding:8px 16px;">Comptes ({{ $this->personnel->count() }})</a>
            <a href="{{ route('super-admin.codes', ['entreprise' => $this->entrepriseId, 'vue' => 'import']) }}"
                class="bouton {{ $vue === 'import' ? 'bouton-principal' : 'bouton-secondaire' }}"
                style="padding:8px 16px;">Code-import ({{ $this->libres->count() }})</a>
        </div>
    @endif

    @if ($this->cible && $vue === 'import')
        {{-- CODE-IMPORT — ceux qui saisissent dans le logiciel sans avoir de compte ici.

             **Ce que cette section répare.** Les personnes habilitées à l'application sont
             dans l'onglet d'à côté. Mais une partie de ceux qui saisissent dans le logiciel
             d'atelier n'ont aucun accès ici, et n'en auront peut-être jamais : leur code
             arrivait à chaque import et restait une énigme de deux lettres. On y lisait un
             volume de fiches, jamais un nom, et personne à appeler pour lever un doute.

             **On déclare, on ne choisit pas dans une liste.** Le code se tape à la main :
             une recrue est connue le jour où elle arrive, et son travail doit se ranger au
             bon atelier dès sa première fiche — bien avant qu'un import ait rencontré ses
             deux lettres.

             **Un code déclaré ici est un code comme les autres.** Le rattachement des
             imports s'en sert exactement de la même façon, et compte ses fiches : c'est le
             même référentiel, la même cascade, la même colonne `ville_id`. La seule chose
             qu'il n'a pas, c'est un accès — et c'est le bouton de sa ligne qui le lui ouvre,
             le jour venu. --}}
        <x-carte-section titre="Déclarer un code de saisie">
            <div class="encart" style="margin-bottom:14px;">
                Ce que l'on écrit ici <b>n'ouvre aucun accès</b>&nbsp;: c'est de quoi savoir à
                qui s'adresser quand une fiche pose question, et de quoi rattacher son travail
                au bon atelier. Dès qu'un code est déclaré, <b>les imports lui rattachent ses
                fiches</b> comme à n'importe quel autre&nbsp;: il est compté avec les autres,
                simplement sans compte.
            </div>

            @error('code') <div class="encart encart-alerte" style="margin-bottom:12px;">{{ $message }}</div> @enderror
            @error('nom') <div class="encart encart-alerte" style="margin-bottom:12px;">{{ $message }}</div> @enderror

            <form method="POST" action="{{ route('super-admin.codes.import') }}">
                @csrf
                <input type="hidden" name="entreprise" value="{{ $this->entrepriseId }}">

                <div style="display:grid; grid-template-columns:110px repeat(auto-fit, minmax(150px, 1fr)); gap:11px; align-items:end;">
                    {{-- Les deux lettres se saisissent : on n'attend pas qu'un import les ait
                         rencontrées. Un code déjà déclaré se retrouve par elles et se
                         corrige — la base refuse de toute façon deux fois les mêmes. --}}
                    <label style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#5A6472; font-weight:700;">
                        Code
                        <input type="text" name="code" maxlength="2" required
                            pattern="[A-Za-z]{2}" autocomplete="off" placeholder="KZ"
                            value="{{ old('code', $codeSaisi) }}"
                            style="display:block; width:100%; box-sizing:border-box; margin-top:3px; border:1px solid #E3E0D8;
                                   border-radius:6px; padding:7px 9px; font-size:15px; font-family:inherit; font-weight:700;
                                   text-transform:uppercase; letter-spacing:2px; text-align:center;
                                   background:var(--th-champ,#FFFBEA);">
                    </label>

                    <label style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#5A6472; font-weight:700;">
                        Nom
                        <input type="text" name="nom" maxlength="120" required autocomplete="off"
                            placeholder="KOUASSI" value="{{ old('nom') }}"
                            style="display:block; width:100%; box-sizing:border-box; margin-top:3px; border:1px solid #E3E0D8;
                                   border-radius:6px; padding:7px 9px; font-size:13.5px; font-family:inherit;
                                   font-weight:400; text-transform:none; letter-spacing:normal; background:var(--th-champ,#FFFBEA);">
                    </label>

                    <label style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#5A6472; font-weight:700;">
                        Prénom
                        <input type="text" name="prenom" maxlength="120" autocomplete="off"
                            placeholder="Jean-Baptiste" value="{{ old('prenom') }}"
                            style="display:block; width:100%; box-sizing:border-box; margin-top:3px; border:1px solid #E3E0D8;
                                   border-radius:6px; padding:7px 9px; font-size:13.5px; font-family:inherit;
                                   font-weight:400; text-transform:none; letter-spacing:normal; background:var(--th-champ,#FFFBEA);">
                    </label>

                    {{-- Facultatif, et il doit le rester : on ne connaît pas toujours la
                         fonction, et l'exiger ferait renoncer à noter le nom. --}}
                    <label style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#5A6472; font-weight:700;">
                        Rôle <span style="font-weight:400; text-transform:none;">(facultatif)</span>
                        <input type="text" name="fonction" maxlength="120" autocomplete="off"
                            placeholder="Réceptionnaire" value="{{ old('fonction') }}"
                            style="display:block; width:100%; box-sizing:border-box; margin-top:3px; border:1px solid #E3E0D8;
                                   border-radius:6px; padding:7px 9px; font-size:13.5px; font-family:inherit;
                                   font-weight:400; text-transform:none; letter-spacing:normal; background:var(--th-champ,#FFFBEA);">
                    </label>

                    <label style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#5A6472; font-weight:700;">
                        Ville
                        <select name="ville_id" data-commande-atelier
                            style="display:block; width:100%; box-sizing:border-box; margin-top:3px; border:1px solid #E3E0D8;
                                   border-radius:6px; padding:7px 9px; font-size:13.5px; font-family:inherit;
                                   font-weight:400; text-transform:none; letter-spacing:normal; background:var(--th-champ,#FFFBEA);">
                            <option value="">— À préciser —</option>
                            @foreach ($this->villes as $ville)
                                <option value="{{ $ville->id }}" @selected((string) old('ville_id') === (string) $ville->id)>{{ $ville->nom }}</option>
                            @endforeach
                        </select>
                    </label>

                    {{-- Le lieu précis, commandé par la ville.

                         Abidjan compte deux ateliers, et c'est exactement ce qu'aucun
                         fichier ne sait distinguer : le code est le seul à pouvoir trancher,
                         encore faut-il qu'on lui ait dit lequel des deux. Choisir la ville
                         réduit donc la liste à ses ateliers, et l'efface quand elle n'en a
                         qu'un — il n'y a alors rien à préciser.

                         **Sans script, rien n'est perdu** : la liste reste entière et chaque
                         atelier porte le nom de sa ville. Le formulaire poste de la même
                         façon, et le serveur refuse de toute manière un atelier qui ne
                         serait pas de la ville choisie. --}}
                    <label style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#5A6472; font-weight:700;">
                        Atelier précis
                        <select name="site_id" data-ateliers
                            style="display:block; width:100%; box-sizing:border-box; margin-top:3px; border:1px solid #E3E0D8;
                                   border-radius:6px; padding:7px 9px; font-size:13.5px; font-family:inherit;
                                   font-weight:400; text-transform:none; letter-spacing:normal; background:var(--th-champ,#FFFBEA);">
                            <option value="" data-ville="">— Non précisé —</option>
                            @foreach ($this->sites as $site)
                                <option value="{{ $site->id }}" data-ville="{{ $site->ville_id }}"
                                    @selected((string) old('site_id') === (string) $site->id)>
                                    {{ $site->ville?->nom }} · {{ $site->nom }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div style="display:flex; justify-content:flex-end; margin-top:13px;">
                    <button type="submit" class="bouton bouton-principal" style="padding:8px 20px;">
                        Créer
                    </button>
                </div>
            </form>

            @if ($this->nonNommes->isNotEmpty())
                <p style="font-size:12px; color:#6B6E76; margin:16px 0 6px;">
                    Codes déjà croisés dans les fichiers déposés et que personne n'a encore
                    nommés. Cliquer sur l'un d'eux le porte dans le champ ci-dessus&nbsp;:
                </p>
                <div style="display:flex; flex-wrap:wrap; gap:7px;">
                    @foreach ($this->nonNommes as $vu)
                        <a href="{{ route('super-admin.codes', ['entreprise' => $this->entrepriseId, 'vue' => 'import', 'code' => $vu->code]) }}"
                            style="display:inline-block; background:#FFF3B0; border:1px solid #E5D98A; border-radius:20px;
                                   padding:3px 11px; font-size:12.5px; text-decoration:none; color:inherit;">
                            <b style="letter-spacing:1px;">{{ $vu->code }}</b>
                            <span style="color:#6B6E76;">· {{ number_format($vu->occurrences, 0, ',', ' ') }} fiche(s)</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-carte-section>

        <x-carte-section :titre="'Codes de saisie déclarés ('.$this->declares->count().')'">
            @if ($this->declares->isEmpty())
                <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                    Aucun code déclaré pour cette entreprise. Le formulaire ci-dessus en crée un.
                </p>
            @else
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th style="width:80px;">Code</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Rôle</th>
                                <th>Lieu précis</th>
                                <th style="text-align:right;">Fiches</th>
                                <th style="width:230px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->declares as $declare)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                    <td style="font-weight:700; letter-spacing:2px;">{{ $declare->code }}</td>
                                    <td>{{ $declare->nom ?: '—' }}</td>
                                    <td>{{ $declare->prenom ?: '—' }}</td>
                                    <td style="color:#6B6E76;">{{ $declare->fonction ?: '—' }}</td>
                                    <td style="color:#6B6E76; font-size:12.5px;">
                                        {{ $declare->site?->nom ?? $declare->ville?->nom ?? '—' }}
                                        @unless ($declare->ville_id)
                                            {{-- Sans lieu, le code ne sait pas ranger son travail :
                                                 c'est la seule chose qui manque vraiment. --}}
                                            <div style="color:#D97706; font-weight:600; font-size:11.5px;">lieu à préciser</div>
                                        @endunless
                                    </td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">
                                        {{ number_format($declare->occurrences, 0, ',', ' ') }}
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        {{-- Corriger : le même formulaire, rappelé avec ces deux lettres. --}}
                                        <a href="{{ route('super-admin.codes', ['entreprise' => $this->entrepriseId, 'vue' => 'import', 'code' => $declare->code]) }}"
                                            class="bouton bouton-secondaire" style="padding:5px 11px; font-size:12px;">Corriger</a>

                                        {{-- Les informations déjà là partent avec le lien : l'écran
                                             de création s'ouvre rempli, code compris, plutôt que de
                                             faire retaper ce qui vient d'être écrit. --}}
                                        <a href="{{ route('super-admin.acces.creer', array_filter([
                                            'entreprise' => $this->entrepriseId,
                                            'code' => $declare->code,
                                            'nom' => $declare->nomComplet(),
                                            'ville' => $declare->ville_id,
                                            'site' => $declare->site_id,
                                        ])) }}" wire:navigate class="bouton bouton-principal" style="padding:5px 11px; font-size:12px;">
                                            Créer un compte
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p style="font-size:11.5px; color:#9A9DA5; margin:12px 0 0;">
                    La colonne « Fiches » est le nombre de lignes que les imports ont déjà
                    rattachées à ce code. Elle reste à zéro tant qu'aucun fichier ne l'a
                    rencontré&nbsp;: déclarer un code ne fabrique aucune donnée.
                </p>
            @endif
        </x-carte-section>

        {{-- L'atelier suit la ville. Posé après les deux sections, et sans dépendance : si
             ce script ne part pas, la liste reste entière et le formulaire marche pareil.

             Sans `data-navigate-once` : la page se rouvre à chaque « Corriger », et un
             script qui ne s'exécuterait qu'une fois laisserait le champ figé sur l'état
             de la visite précédente. --}}
        <script>
        (function () {
            var ville = document.querySelector('[data-commande-atelier]');
            var ateliers = document.querySelector('[data-ateliers]');

            if (! ville || ! ateliers) {
                return;
            }

            // Les options sont retirées du document plutôt que masquées : `display:none` sur
            // une <option> est ignoré par plusieurs navigateurs, et l'atelier d'une autre
            // ville resterait choisissable.
            var toutes = Array.prototype.slice.call(ateliers.options);

            var accorder = function () {
                var choisie = ville.value;
                var gardees = toutes.filter(function (o) {
                    return o.value === '' || o.dataset.ville === choisie;
                });

                ateliers.innerHTML = '';
                gardees.forEach(function (o) { ateliers.appendChild(o); });

                // Une ville d'un seul atelier n'a rien à préciser : le champ se tait.
                var aChoisir = gardees.length > 2;
                ateliers.parentNode.style.display = (choisie !== '' && aChoisir) ? '' : 'none';

                if (! aChoisir) {
                    ateliers.value = '';
                }
            };

            ville.addEventListener('change', accorder);
            accorder();
        })();
        </script>
    @endif

    @if ($this->cible && $vue === 'comptes')
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
                            <th>Téléphone</th>
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
                                    {{-- Le numéro se lit ici comme partout ailleurs : c'est
                                         le même champ que celui de l'écran des accès, et
                                         celui que la personne remplit elle-même depuis la
                                         boîte « Où vous joindre ». Un seul endroit en base,
                                         donc jamais deux versions du même numéro. --}}
                                    <td style="font-size:12.5px; white-space:nowrap;">
                                        @if ($compte->telephone)
                                            {{ $compte->telephone }}
                                        @else
                                            <span style="color:#D97706;" title="Il lui sera demandé à son prochain écran.">à renseigner</span>
                                        @endif
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
                            <x-table-vide :colspan="7" texte="Cette entreprise n'a encore aucun accès ouvert." />
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
