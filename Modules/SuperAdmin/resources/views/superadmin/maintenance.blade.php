<?php

use App\Models\User;
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
