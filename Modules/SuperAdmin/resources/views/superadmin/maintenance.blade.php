<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Actions\PurgeParModule;
use Modules\Noyau\Entreprises\Actions\PurgerDonneesEntreprise;
use Modules\Noyau\Entreprises\Actions\SupprimerEntreprise;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Imports\Modeles\LotImport;
use Illuminate\Support\Facades\DB;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Maintenance : vider, ou effacer
|--------------------------------------------------------------------------
| Deux gestes de portée très différente, séparés à dessein.
|
| La purge vide les écritures et laisse l'entreprise debout : c'est ce qu'on
| fait après une période d'essai, quand la structure est bonne mais que les
| chiffres ne le sont pas.
|
| La suppression, elle, ne laisse rien. Les deux ont leur propre confirmation
| par le nom, et leur propre bouton : un seul formulaire pour les deux aurait
| tôt ou tard fait effacer une entreprise qu'on voulait seulement vider.
*/

state([
    'entrepriseId' => '',
    'purgerCommerciaux' => false,
    'purgerAcces' => false,
    'purgerReglagesImport' => false,
    'confirmation' => '',
    'resultat' => null,

    // Suppression définitive : son propre identifiant et sa propre confirmation,
    // pour qu'aucun champ ne soit partagé avec la purge.
    'suppressionId' => '',
    'confirmationSuppression' => '',
    'resultatSuppression' => null,

    /*
     * **Vider ce qu'on désigne — demandé le 01/10.**
     *
     * « Fais des cases à cocher des pages ayant des données, et dès que les pages seront
     * cochées et supprimées, les données seront supprimées. » Son propre identifiant
     * d'entreprise et sa propre confirmation : aucun champ n'est partagé avec les deux
     * autres gestes, parce qu'un champ partagé finit par faire déclencher l'un pour l'autre.
     */
    'choixId' => '',
    'lotsChoisis' => [],
    'confirmationChoix' => '',
    'resultatChoix' => null,
]);

$entreprises = computed(fn () => Entreprise::orderBy('nom')->get());

$cible = computed(fn () => $this->entrepriseId ? Entreprise::find($this->entrepriseId) : null);

$cibleSuppression = computed(fn () => $this->suppressionId ? Entreprise::find($this->suppressionId) : null);

$volumes = computed(function () {
    if (! $this->entrepriseId) {
        return null;
    }

    $id = $this->entrepriseId;

    return [
        'Prospections' => Prospection::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Devis' => Devis::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Factures' => Facture::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Encaissements' => Encaissement::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Charges' => Charge::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Fiches de réception' => DB::table('dossiers_vehicules')->where('entreprise_id', $id)->count(),
        'Mouvements de caisse' => DB::table('mouvements_caisse')->where('entreprise_id', $id)->count(),
        'Factures fournisseurs' => DB::table('factures_fournisseurs')->where('entreprise_id', $id)->count(),
        'Entrées et sorties' => DB::table('mouvements_vehicules')->where('entreprise_id', $id)->count(),
        'Relances' => DB::table('relances_recouvrement')->where('entreprise_id', $id)->count(),
        'Dépôts de fichiers' => DB::table('lots_import')->where('entreprise_id', $id)->count(),
        'Fichiers sur le disque' => LotImport::nombreDeFichiersDe((int) $id),
    ];
});

/** Ce que la suppression emporterait : on le montre avant, pas après. */
$portee = computed(function () {
    if (! $this->suppressionId) {
        return null;
    }

    $id = $this->suppressionId;

    return [
        'Villes' => Ville::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Lieux' => Site::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Accès' => User::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Écritures' => Prospection::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Devis::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Facture::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Encaissement::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Charge::withoutGlobalScopes()->where('entreprise_id', $id)->count(),

        // Les lignes venues des imports sont comptées à part : on ne les mélange pas aux
        // écritures, parce que ce ne sont pas les mêmes gestes qui les ont produites.
        'Lignes importées' => DB::table('dossiers_vehicules')->where('entreprise_id', $id)->count()
            + DB::table('mouvements_caisse')->where('entreprise_id', $id)->count()
            + DB::table('mouvements_vehicules')->where('entreprise_id', $id)->count()
            + DB::table('factures_fournisseurs')->where('entreprise_id', $id)->count(),

        'Fichiers déposés' => LotImport::nombreDeFichiersDe((int) $id),
    ];
});

$cibleChoix = computed(fn () => $this->choixId ? Entreprise::find($this->choixId) : null);

/** Ce que chaque ensemble porte, pour l'entreprise regardée — compté avant tout geste. */
$volumesParLot = computed(fn () => $this->choixId
    ? PurgeParModule::volumes((int) $this->choixId)
    : []);

/**
 * Les ensembles réellement cochés, ramenés à ceux qui existent et qui portent quelque chose.
 *
 * **Un ensemble vide est écarté ici**, et pas seulement grisé à l'écran : une case cochée
 * puis vidée par un autre geste ferait annoncer une suppression qui ne supprimerait rien, et
 * le bilan dirait « 0 » là où l'on attendait un chiffre.
 */
$lotsRetenus = computed(function () {
    $volumes = $this->volumesParLot;

    return collect($this->lotsChoisis)
        ->filter(fn ($coche, $cle) => $coche && ($volumes[$cle] ?? 0) > 0)
        ->keys()
        ->all();
});

/**
 * Ce que la boîte de confirmation dira, mot pour mot.
 *
 * **Elle nomme les lignes et les entraînements.** Le propriétaire l'a demandé — « dire ce
 * qui sera vraiment supprimé » —, et c'est la seule protection qui vaille : un écran qui
 * demande « êtes-vous sûr ? » sans dire de quoi ne protège de rien.
 */
$recapitulatif = computed(function () {
    $lots = PurgeParModule::lots();
    $volumes = $this->volumesParLot;
    $lignes = [];
    $entraines = [];

    foreach ($this->lotsRetenus as $cle) {
        $lignes[] = $lots[$cle]['libelle'].' : '.number_format($volumes[$cle], 0, ',', ' ').' ligne(s)';

        if (isset($lots[$cle]['entraine'])) {
            $entraines[] = $lots[$cle]['entraine'];
        }
    }

    return ['lignes' => $lignes, 'entraines' => $entraines, 'total' => array_sum(array_map(
        fn ($cle) => $volumes[$cle] ?? 0, $this->lotsRetenus,
    ))];
});

/** Cocher ou décocher un module entier — « un bouton tout cocher au niveau de chaque module ». */
$basculerLeModule = function (string $module) {
    $lots = PurgeParModule::MODULES[$module]['lots'] ?? [];
    $volumes = $this->volumesParLot;

    // Tout cocher si l'un au moins manque ; tout décocher si l'on est déjà complet. C'est
    // ce qu'un seul bouton peut faire sans qu'on se demande dans quel sens il agit.
    $aCompleter = collect($lots)->contains(
        fn ($lot, $cle) => ($volumes[$cle] ?? 0) > 0 && empty($this->lotsChoisis[$cle]),
    );

    foreach ($lots as $cle => $lot) {
        if (($volumes[$cle] ?? 0) > 0) {
            $this->lotsChoisis[$cle] = $aCompleter;
        }
    }
};

$updatedChoixId = function () {
    // Changer d'entreprise remet les cases à zéro : des cases cochées pour une entreprise
    // n'ont aucun sens pour une autre, et les garder ferait supprimer ailleurs.
    $this->lotsChoisis = [];
    $this->confirmationChoix = '';
    $this->resultatChoix = null;
    unset($this->volumesParLot);
};

/**
 * Vide les ensembles cochés.
 *
 * **Le nom de l'entreprise doit être retapé**, comme pour les deux autres gestes. Ce n'est
 * pas une formalité : c'est le seul moment où la personne écrit elle-même ce qu'elle vise,
 * et c'est ce qui distingue un clic d'une décision.
 */
$viderLesChoisis = function (PurgeParModule $action) {
    $this->validate([
        'choixId' => ['required', 'exists:entreprises,id'],
    ], [], ['choixId' => 'entreprise']);

    $cible = $this->cibleChoix;

    if ($this->lotsRetenus === []) {
        $this->addError('lotsChoisis', 'Cochez au moins un ensemble qui porte des données.');

        return;
    }

    if (trim($this->confirmationChoix) !== $cible->nom) {
        $this->addError('confirmationChoix', "Saisissez exactement « {$cible->nom} » pour confirmer.");

        return;
    }

    $this->resultatChoix = $action->executer($cible, $this->lotsRetenus);

    activity()
        ->causedBy(auth()->user())
        ->performedOn($cible)
        ->withProperties($this->resultatChoix)
        ->log('Purge sélective des données');

    $this->reset(['lotsChoisis', 'confirmationChoix']);
    unset($this->volumesParLot, $this->volumes);
};

$purger = function (PurgerDonneesEntreprise $action) {
    $this->validate([
        'entrepriseId' => ['required', 'exists:entreprises,id'],
    ], [], ['entrepriseId' => 'entreprise']);

    // Garde-fou : le nom exact de l'entreprise doit être retapé.
    if (trim($this->confirmation) !== $this->cible->nom) {
        $this->addError('confirmation', "Saisissez exactement « {$this->cible->nom} » pour confirmer la purge.");

        return;
    }

    $this->resultat = $action->executer(
        $this->cible, $this->purgerCommerciaux, $this->purgerAcces, $this->purgerReglagesImport,
    );

    activity()
        ->causedBy(auth()->user())
        ->performedOn($this->cible)
        ->withProperties($this->resultat)
        ->log("Purge des données d'exploitation");

    $this->reset(['confirmation', 'purgerCommerciaux', 'purgerAcces', 'purgerReglagesImport']);
    unset($this->volumes);
};

$supprimer = function (SupprimerEntreprise $action) {
    $this->validate([
        'suppressionId' => ['required', 'exists:entreprises,id'],
    ], [], ['suppressionId' => 'entreprise']);

    $entreprise = $this->cibleSuppression;

    if (trim($this->confirmationSuppression) !== $entreprise->nom) {
        $this->addError('confirmationSuppression', "Saisissez exactement « {$entreprise->nom} » pour confirmer la suppression.");

        return;
    }

    // Personne ne se supprime le sol sous les pieds : un super administrateur
    // rattaché à cette entreprise perdrait sa propre session en cours de route.
    if (auth()->user()->entreprise_id === $entreprise->id) {
        $this->addError('confirmationSuppression', "Vous appartenez à cette entreprise : vous ne pouvez pas la supprimer.");

        return;
    }

    $nom = $entreprise->nom;

    // Le journal est écrit avant : la ligne de l'entreprise ne sera plus là après,
    // et une trace qui disparaît avec son sujet ne prouve plus rien.
    activity()
        ->causedBy(auth()->user())
        ->withProperties(['entreprise' => $nom, 'id' => $entreprise->id, 'portee' => $this->portee])
        ->log("Suppression définitive de l'entreprise « $nom »");

    $this->resultatSuppression = ['nom' => $nom, 'bilan' => $action->executer($entreprise)];

    $this->reset(['suppressionId', 'confirmationSuppression']);
    unset($this->entreprises, $this->portee, $this->volumes);
};

?>

<div>
    <x-titre-ecran titre="Maintenance"
        sous-titre="L'état technique de la plateforme et les gestes d'entretien." />

    {{-- ═══════════════════════════════ vider ce qu'on désigne

         **Demandé le 01/10** : « fais des cases à cocher des pages ayant des données, et dès
         que les pages seront cochées et supprimées, les données seront supprimées […] classer
         par module […] un bouton tout cocher au niveau de chaque module ».

         **Il vient avant la purge totale**, et ce n'est pas un hasard d'ordre : c'est le
         geste qu'on cherche neuf fois sur dix — rejouer un import, refaire un relevé. Mettre
         le geste total en premier inviterait à s'en servir pour ce que celui-ci fait mieux.

         **Les ensembles vides sont montrés et désactivés**, plutôt que cachés : une liste qui
         change de longueur d'une entreprise à l'autre fait chercher ce qui a disparu. --}}
    <x-carte-section titre="Vider seulement ce qu'on désigne">
        <div class="encart encart-alerte">
            <b>Action irréversible.</b> Chaque ensemble coché est définitivement supprimé pour
            l'entreprise choisie. Ce qui n'est pas coché n'est pas touché.
            <br><br>
            <b>Ce qui n'est jamais touché :</b> la fiche entreprise, les villes, les lieux, les accès,
            les exercices, et le journal d'activité — qui garde la trace de cette suppression.
        </div>

        <div class="bloc-saisie">
            <x-champ label="Entreprise" model="choixId" type="select" live="true" width="280"
                :options="collect(['' => '— Choisir une entreprise —'])->union($this->entreprises->pluck('nom', 'id'))" />
        </div>

        @if ($this->cibleChoix)
            @php $volumes = $this->volumesParLot; @endphp

            @foreach (\Modules\Noyau\Entreprises\Actions\PurgeParModule::MODULES as $cleModule => $module)
                @php
                    $porte = collect($module['lots'])->contains(fn ($lot, $cle) => ($volumes[$cle] ?? 0) > 0);
                @endphp

                <div style="margin-top:18px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:14px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                        <h3 style="font-size:14.5px; font-weight:700; margin:0;">{{ $module['libelle'] }}</h3>

                        {{-- Un seul bouton, et il agit dans le sens qui reste à faire : tout
                             cocher tant qu'il manque quelque chose, tout décocher ensuite. --}}
                        @if ($porte)
                            <button type="button" wire:click="basculerLeModule('{{ $cleModule }}')"
                                class="bouton bouton-secondaire" style="padding:4px 11px; font-size:12px;">
                                Tout cocher / décocher
                            </button>
                        @endif
                    </div>

                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:8px;">
                        @foreach ($module['lots'] as $cle => $lot)
                            @php $nombre = $volumes[$cle] ?? 0; @endphp
                            <label style="display:flex; align-items:flex-start; gap:9px; padding:9px 11px;
                                          border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px;
                                          background:{{ $nombre > 0 ? '#fff' : '#F7F6F2' }};
                                          cursor:{{ $nombre > 0 ? 'pointer' : 'not-allowed' }};
                                          opacity:{{ $nombre > 0 ? '1' : '.6' }};">
                                <input type="checkbox" wire:model.live="lotsChoisis.{{ $cle }}"
                                    @disabled($nombre === 0) style="margin-top:3px;">
                                <span style="min-width:0;">
                                    <b style="font-size:13.5px;">{{ $lot['libelle'] }}</b>
                                    <span style="display:block; font-size:11.5px; color:#6B6E76;">
                                        Écran : {{ $lot['ecran'] }}
                                    </span>
                                    <span style="display:block; font-size:12.5px; font-weight:700; margin-top:3px;
                                                 font-variant-numeric:tabular-nums;
                                                 color:{{ $nombre > 0 ? '#C8102E' : '#6B6E76' }};">
                                        {{ $nombre > 0 ? number_format($nombre, 0, ',', ' ').' ligne(s)' : 'rien à vider' }}
                                    </span>
                                    @if (isset($lot['entraine']) && $nombre > 0)
                                        {{-- L'entraînement est dit à côté de la case, pas seulement
                                             dans la boîte : c'est au moment de cocher qu'il compte. --}}
                                        <span style="display:block; font-size:11.5px; color:#B45309; margin-top:4px; line-height:1.45;">
                                            ⚠ {{ $lot['entraine'] }}
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @php $recap = $this->recapitulatif; @endphp

            @if ($recap['lignes'] !== [])
                <div class="encart encart-alerte" style="margin-top:18px;">
                    <b>{{ number_format($recap['total'], 0, ',', ' ') }} ligne(s)</b> seront supprimées pour
                    <b>{{ $this->cibleChoix->nom }}</b> :
                    <ul style="margin:8px 0 0; padding-left:18px; font-size:13px;">
                        @foreach ($recap['lignes'] as $ligne)
                            <li>{{ $ligne }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bloc-saisie" style="margin-top:14px;">
                <x-champ label="Retapez le nom de l'entreprise pour confirmer" model="confirmationChoix"
                    width="320" :placeholder="$this->cibleChoix->nom" />

                {{-- **La boîte de confirmation est celle de l'application, au centre de
                     l'écran** — demandé le 01/10, « dans une boîte bien au centre ». Elle dit
                     ce qui part, ligne par ligne, avant de le faire. Sans JavaScript, le geste
                     part directement : c'est une dégradation assumée, et le champ de
                     confirmation au-dessus, lui, ne dépend d'aucun script. --}}
                <button type="button" wire:click="viderLesChoisis" class="bouton bouton-sombre"
                    data-confirmer="Supprimer définitivement {{ number_format($recap['total'], 0, ',', ' ') }} ligne(s) de « {{ $this->cibleChoix->nom }} » ?"
                    data-confirmer-titre="Vider les données cochées"
                    data-confirmer-ton="alerte"
                    data-confirmer-detail="{{ implode(' · ', $recap['lignes']) }}{{ $recap['entraines'] !== [] ? ' — '.implode(' ', $recap['entraines']) : '' }}">
                    Vider les ensembles cochés
                </button>
            </div>

            <x-erreurs-du-bloc prefixe="lotsChoisis" />
            <x-erreurs-du-bloc prefixe="confirmationChoix" />
            <x-erreurs-du-bloc prefixe="choixId" />
        @endif

        @if ($resultatChoix)
            <div class="encart encart-succes" style="margin-top:16px;">
                <b>Fait.</b>
                <ul style="margin:8px 0 0; padding-left:18px; font-size:13px;">
                    @foreach ($resultatChoix as $libelle => $nombre)
                        <li>{{ $libelle }} : {{ number_format($nombre, 0, ',', ' ') }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-carte-section>

    <x-carte-section titre="Purger les données d'une entreprise">
        <div class="encart encart-alerte">
            <b>Action irréversible.</b> Tout ce qui a été saisi ou importé pour l'entreprise choisie est
            définitivement supprimé, et la numérotation repart à zéro.
            <br><br>
            <b>Ce qui part :</b> les écritures de l'application (prospections, devis, factures,
            encaissements, charges, saisies journalières), tout ce que les imports ont rempli (fiches de
            réception, mouvements de caisse, entrées et sorties de véhicules, factures fournisseurs), le
            travail de recouvrement (relances et commentaires d'écart), et les dépôts d'import eux-mêmes —
            <b>y compris les fichiers rangés sur le disque</b>, pour que le même fichier puisse être
            redéposé sans être refusé comme doublon.
            <br><br>
            <b>Ce qui reste :</b> la fiche entreprise, les villes, les lieux, les exercices, les listes
            déroulantes, les accès, et le journal d'activité.
        </div>

        <div class="bloc-saisie">
            <x-champ label="Entreprise" model="entrepriseId" type="select" live="true" width="280"
                :options="collect(['' => '— Choisir une entreprise —'])->union($this->entreprises->pluck('nom', 'id'))" />
        </div>

        @if ($this->volumes)
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin:18px 0;">
                @foreach ($this->volumes as $libelle => $nombre)
                    <x-kpi-card :label="$libelle" :value="$nombre" />
                @endforeach
            </div>

            <div style="margin-top:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; margin-bottom:10px;">
                    <input type="checkbox" wire:model="purgerCommerciaux">
                    Supprimer également les fiches commerciales (les comptes utilisateurs sont conservés)
                </label>

                {{-- Le gérant est épargné à dessein : c'est lui qui recréera les autres.
                     Une entreprise sans aucun accès ne se rouvre plus depuis l'application,
                     il faudrait repasser ici pour chaque compte. --}}
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; margin-bottom:6px;">
                    <input type="checkbox" wire:model="purgerAcces">
                    Supprimer également <b>tous les accès sauf celui du gérant</b>
                </label>
                <p style="font-size:11.5px; color:#9A9DA5; margin:0 0 14px 26px;">
                    Superviseurs, responsables de site, commerciaux et comptabilité sont effacés avec leurs
                    fiches. Le gérant reste, pour pouvoir rouvrir les accès lui-même. Votre propre compte
                    n'est jamais touché.
                </p>

                {{-- Les codes agents et les correspondances ne sont pas des données importées :
                     ce sont les réponses données à la main aux questions que les imports ont
                     posées (« le code KZ, c'est quelle ville ? »). Les refaire après chaque
                     purge n'aurait aucun sens, donc ils survivent — sauf demande expresse. --}}
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; margin-bottom:6px;">
                    <input type="checkbox" wire:model="purgerReglagesImport">
                    Supprimer également les <b>réglages de rattachement des imports</b>
                </label>
                <p style="font-size:11.5px; color:#9A9DA5; margin:0 0 14px 26px;">
                    Codes agents et correspondances de libellés. Sans cette case, ils sont conservés :
                    leurs compteurs d'occurrences repartent à zéro, et seules les correspondances encore
                    en attente de réponse sont retirées — la question n'a plus d'objet sans son fichier.
                </p>

                <label class="champ-libelle">
                    Pour confirmer, saisissez le nom exact de l'entreprise : <b>{{ $this->cible?->nom }}</b>
                </label>
                <input type="text" wire:model="confirmation" value="{{ $confirmation }}" class="champ" style="max-width:420px;">
                @error('confirmation') <span class="champ-erreur">{{ $message }}</span> @enderror

                <div style="margin-top:16px;">
                    <button type="button" wire:click="purger"
                        wire:confirm="Confirmez-vous la suppression définitive des données d'exploitation de cette entreprise ?"
                        class="bouton bouton-danger">
                        Purger définitivement les données
                    </button>
                </div>
            </div>
        @endif

        @if ($resultat)
            <div class="encart encart-succes" style="margin-top:18px;">
                <b>Purge effectuée.</b>
                {{ collect($resultat)->map(fn ($n, $t) => "$t : $n")->implode(' · ') }}
            </div>
        @endif
    </x-carte-section>

    {{-- ------------------------------------------------ Suppression totale --}}
    <x-carte-section titre="Supprimer définitivement une entreprise">
        <div class="encart encart-alerte">
            <b>Rien ne survit à cette action.</b> Supprimer une entreprise efface l'intégralité de ses
            données : ses villes et ses lieux, toutes ses écritures, ses listes déroulantes, ses exercices,
            ses conversations et ses notes, ses imports et <b>les fichiers déposés sur le disque</b> —
            et <b>tous ses accès</b>, gérant compris. Les personnes concernées ne pourront plus se
            connecter, et rien ne se récupère ensuite.
            <br><br>
            Pour ne vider que les chiffres en gardant l'organisation et les comptes, utilisez la purge
            ci-dessus.
        </div>

        <div class="bloc-saisie">
            <x-champ label="Entreprise à supprimer" model="suppressionId" type="select" live="true" width="280"
                :options="collect(['' => '— Choisir une entreprise —'])->union($this->entreprises->pluck('nom', 'id'))" />
        </div>

        @if ($this->portee)
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin:18px 0;">
                @foreach ($this->portee as $libelle => $nombre)
                    <x-kpi-card :label="$libelle" :value="$nombre" :accent="$nombre > 0" />
                @endforeach
            </div>

            <label class="champ-libelle">
                Pour confirmer, saisissez le nom exact de l'entreprise : <b>{{ $this->cibleSuppression?->nom }}</b>
            </label>
            <input type="text" wire:model="confirmationSuppression" value="{{ $confirmationSuppression }}" class="champ" style="max-width:420px;">
            @error('confirmationSuppression') <span class="champ-erreur">{{ $message }}</span> @enderror

            <div style="margin-top:16px;">
                <button type="button" wire:click="supprimer"
                    wire:confirm="Supprimer définitivement cette entreprise, ses données et tous ses accès ? Cette action ne se rattrape pas."
                    class="bouton bouton-danger">
                    Supprimer définitivement l'entreprise
                </button>
            </div>
        @endif

        @if ($resultatSuppression)
            <div class="encart encart-succes" style="margin-top:18px;">
                <b>« {{ $resultatSuppression['nom'] }} » supprimée.</b>
                {{ collect($resultatSuppression['bilan'])->map(fn ($n, $t) => "$t : $n")->implode(' · ') }}
            </div>
        @endif
    </x-carte-section>
</div>
