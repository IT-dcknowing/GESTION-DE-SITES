<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Commun\Concerns\GereLesDonneesLibres;
use Modules\Noyau\Commun\Modeles\NotificationApp;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Commun\Services\Notificateur;
use Livewire\WithPagination;
use function Livewire\Volt\{state, computed, mount, uses, usesPagination};

uses([GereLesDonneesLibres::class, WithPagination::class]);
usesPagination();

state([
    // Les informations libres ajoutées après transmission, une par ligne ouverte.
    'complements' => [],

    // Le complément d'observation s'ouvre à la demande, une ligne à la fois : une boîte
    // de saisie posée en permanence dans une colonne de valeurs se lit comme un champ
    // resté ouvert, et l'on ne sait plus si ce qu'on voit est enregistré.
    'completionId' => null,
    'date' => null,
    'client' => '', 'localisation' => '', 'moyen' => 'RDV',
    // Le véhicule visé, et sa fiche quand elle est déjà ouverte. Les deux sont facultatifs :
    // c'est ce qui permettra plus tard de retrouver le devis né de cette visite, ce n'est
    // pas une condition pour la saisir.
    'immatriculation' => '', 'nFicheReception' => '', 'nDevis' => '',
    'activite' => '', 'passage' => false, 'datePassage' => null,
    'devisApres' => false, 'dateDevis' => null, 'observations' => '',
    'commentaire' => '',
    'selection' => [],

    // Édition en ligne d'un brouillon, tant qu'il n'est pas parti chez le responsable.
    'editionId' => null,
    'eClient' => '', 'eLocalisation' => '', 'eMoyen' => 'RDV', 'eActivite' => '',
    'eImmatriculation' => '', 'eNFicheReception' => '', 'eNDevis' => '',
    'ePassage' => false, 'eDatePassage' => null,
    'eDevisApres' => false, 'eDateDevis' => null, 'eObservations' => '',
]);

// Les filtres sont portés par l'adresse de la page : ils survivent au rechargement,
// au retour arrière et au partage du lien.
state([
    'fNumero' => '', 'fDate' => '', 'fActivite' => '',
    'fClient' => '', 'fStatut' => '',
])->url(except: '');

mount(function () {
    $this->date = now()->toDateString();
    // Un commercial n'est pas cantonné à une activité : il travaille dans une ville et
    // choisit l'activité concernée à chaque prospection. « Mécanique/Sinistre » (valeur
    // possible sur la fiche commercial) n'est pas une activité de prospection valide.
    $this->activite = array_key_first($this->optionsActivite);
});

$commercial = computed(fn () => Commercial::where('user_id', auth()->id())->with('ville')->first());

$optionsActivite = computed(fn () => Referentiel::options(Referentiel::ACTIVITE));

$optionsMoyen = computed(fn () => Referentiel::options(Referentiel::MOYEN_PROSPECTION));

/** Requête filtrée : chaque filtre est appliqué dès la frappe. */
$requete = computed(function () {
    $q = Prospection::where('commercial_id', $this->commercial?->id ?? 0)->with('donneesLibres');

    if ($this->fNumero !== '') {
        $q->where('numero', 'like', '%'.$this->fNumero.'%');
    }
    if ($this->fDate !== '') {
        $q->whereDate('date', $this->fDate);
    }
    if ($this->fActivite !== '') {
        $q->where('activite', $this->fActivite);
    }
    if ($this->fClient !== '') {
        $q->where('client', 'like', '%'.$this->fClient.'%');
    }
    if ($this->fStatut !== '') {
        $q->where('statut_validation', $this->fStatut);
    }

    return $q->latest('date')->latest('id');
});

$lignes = computed(fn () => $this->requete->paginate(20));

$compteurs = computed(function () {
    $base = Prospection::where('commercial_id', $this->commercial?->id ?? 0);

    return [
        'brouillon' => (clone $base)->where('statut_validation', 'Brouillon')->count(),
        'transmise' => (clone $base)->where('statut_validation', 'Transmise')->count(),
        'validee' => (clone $base)->where('statut_validation', 'Validée')->count(),
        'refusee' => (clone $base)->where('statut_validation', 'Refusée')->count(),
    ];
});

/** Identifiants des brouillons de la page courante, pour le « tout sélectionner ». */
$brouillonsAffiches = computed(fn () => $this->lignes->getCollection()
    ->where('statut_validation', 'Brouillon')->pluck('id')->all());

$selectionnes = computed(fn () => collect($this->selection)->filter()->keys()->map(fn ($i) => (int) $i)->all());

// Tout filtre modifié ramène à la première page, sinon on peut se retrouver sur une page vide.
$updatedFNumero = fn () => $this->resetPage();
$updatedFDate = fn () => $this->resetPage();
$updatedFActivite = fn () => $this->resetPage();
$updatedFClient = fn () => $this->resetPage();
$updatedFStatut = fn () => $this->resetPage();

$reinitialiserFiltres = function () {
    $this->reset(['fNumero', 'fDate', 'fActivite', 'fClient', 'fStatut']);
    $this->resetPage();
};

$toutSelectionner = function () {
    foreach ($this->brouillonsAffiches as $id) {
        $this->selection[$id] = true;
    }
};

$toutDeselectionner = function () {
    $this->selection = [];
};

/** Impossible de faire un devis sans passage : cocher l'un coche et date l'autre, à la même date. */
$updatedPassage = function ($valeur) {
    if (! $valeur) {
        $this->devisApres = false;
        $this->dateDevis = null;
        $this->datePassage = null;

        return;
    }

    $this->datePassage ??= $this->date;
};

$updatedDevisApres = function ($valeur) {
    if (! $valeur) {
        $this->dateDevis = null;

        return;
    }

    $this->dateDevis ??= $this->date;
    $this->passage = true;
    $this->datePassage = $this->dateDevis;
};

/** Le devis n'a pas de date propre : elle vaut toujours la date de passage. */
$updatedDateDevis = function ($valeur) {
    if ($this->devisApres) {
        $this->datePassage = $valeur;
    }
};

/*
|--------------------------------------------------------------------------
| Enregistrer une prospection
|--------------------------------------------------------------------------
| Deux gestes, pas un : « Brouillon » met de côté une visite dont on n'est pas
| encore sûr, « Ajouter » la transmet aussitôt au responsable. Obliger à passer
| par le brouillon puis à cocher pour transmettre faisait trois clics là où la
| plupart des saisies n'en demandent qu'un.
*/
$ajouterEnBrouillon = function () {
    $this->enregistrerProspection('Brouillon');
};

$ajouterEtTransmettre = function () {
    $this->enregistrerProspection('Transmise');
};

$enregistrerProspection = function (string $statut) {
    if (! $this->commercial) {
        return;
    }

    $donnees = $this->validate([
        'date' => ['required', 'date'],
        'client' => ['required', 'string', 'max:255'],
        'localisation' => ['nullable', 'string', 'max:255'],
        'immatriculation' => ['nullable', 'string', 'max:32'],
        'nFicheReception' => ['nullable', 'string', 'max:60'],
        /*
         * Obligatoire **seulement** quand le passage en devis est déclaré.
         *
         * C'est l'instant où le commercial tient le devis : lui demander son numéro alors
         * ne coûte rien, et cela supprime tout le travail de rapprochement qui suivait.
         * L'exiger en dehors de ce cas reviendrait à refuser une visite qui n'a encore rien
         * produit — c'est-à-dire la plupart d'entre elles.
         */
        'nDevis' => [$this->devisApres ? 'required' : 'nullable', 'string', 'max:60'],
        'moyen' => ['required', 'string', 'max:60'],
        'activite' => ['required', 'string', 'max:60'],
        'datePassage' => ['nullable', 'date'],
        'dateDevis' => ['nullable', 'date'],
        'observations' => ['nullable', 'string'],
    ], [], ['client' => 'clients visités', 'activite' => 'activité', 'nDevis' => 'n° du devis']);

    $coherence = Prospection::normaliserPassage(
        (bool) $this->passage, $donnees['datePassage'],
        (bool) $this->devisApres, $donnees['dateDevis'],
    );

    // Le commercial est rattaché à une ville, pas à un lieu : sa prospection est
    // enregistrée sur le premier lieu de sa ville, qui accueille les deux activités.
    // C'est la prospection elle-même qui dit laquelle est concernée.
    $siteId = Site::where('ville_id', $this->commercial->ville_id)->orderBy('id')->value('id');

    Prospection::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $siteId,
        'commercial_id' => $this->commercial->id,
        // La date de la prospection entre dans son numéro : P-1409-0574. Celle de
        // l'opération, pas celle de la frappe — on saisit souvent le lendemain.
        'numero' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'pro', $donnees['date']),
        'date' => $donnees['date'],
        'client' => $donnees['client'],
        'localisation' => $donnees['localisation'] ?: null,
        // La plaque est rangée par le modèle : majuscules, espaces simples. On ne la met
        // pas en forme ici, sinon elle le serait deux fois et différemment.
        'immatriculation' => $donnees['immatriculation'] ?: null,
        'n_fiche_reception' => trim($donnees['nFicheReception'] ?? '') ?: null,
        'n_devis' => trim($donnees['nDevis'] ?? '') ?: null,
        'moyen' => $donnees['moyen'],
        'activite' => $donnees['activite'],
        ...$coherence,
        'observations' => $donnees['observations'] ?: null,
        // Le commentaire part avec la prospection. Il était saisi et jamais enregistré :
        // le responsable recevait des lignes nues, le commercial croyait avoir expliqué.
        'commentaire' => trim($this->commentaire) ?: null,
        'cree_par' => auth()->id(),
        'statut_validation' => $statut,
        'transmise_le' => $statut === 'Transmise' ? now() : null,
    ]);

    if ($statut === 'Transmise') {
        $this->prevenirLeResponsable(1);
    }

    $this->reset(['client', 'localisation', 'immatriculation', 'nFicheReception', 'nDevis',
        'observations', 'commentaire', 'passage', 'datePassage', 'devisApres', 'dateDevis']);
    $this->resetPage();
    $this->annoncer($statut === 'Transmise'
        ? 'Prospection transmise à votre responsable.'
        : "Prospection enregistrée en brouillon — modifiable tant qu'elle n'est pas transmise.");
};

/**
 * Dit ce qui vient de se passer, trois secondes, puis s'efface.
 *
 * Remplace le bandeau qui restait à l'écran jusqu'au geste suivant, et les boîtes
 * de dialogue de confirmation sur les gestes réversibles : transmettre n'efface
 * rien, c'est le responsable qui arbitre ensuite.
 */
$annoncer = function (string $texte, string $ton = 'succes') {
    $this->dispatch('annonce', texte: $texte, ton: $ton);
};

/** Le responsable est prévenu tout de suite : sans cela, une transmission peut dormir des jours. */
$prevenirLeResponsable = function (int $nombre) {
    Notificateur::pourPlusieurs(
        destinataires: Notificateur::encadrementDeVille($this->commercial?->ville_id, auth()->user()->entreprise_id),
        titre: $nombre.' prospection(s) à valider',
        corps: auth()->user()->name.' vient de transmettre '.$nombre.' prospection(s).',
        canal: NotificationApp::CANAL_GESTION,
        niveau: NotificationApp::NIVEAU_ALERTE,
        lien: route('saisie-du-jour'),
    );
};

/*
|--------------------------------------------------------------------------
| Modifier un brouillon
|--------------------------------------------------------------------------
| Tant qu'une prospection n'est pas partie, elle appartient encore au commercial :
| il doit pouvoir corriger un nom mal orthographié ou une case oubliée. Une fois
| transmise, elle ne lui appartient plus — c'est le responsable qui la corrige,
| sans quoi une ligne pourrait changer sous les yeux de celui qui l'arbitre.
*/
$modifier = function (int $id) {
    $p = Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->where('statut_validation', 'Brouillon')->findOrFail($id);

    $this->editionId = $p->id;
    $this->eClient = $p->client;
    $this->eLocalisation = $p->localisation ?? '';
    $this->eImmatriculation = $p->immatriculation ?? '';
    $this->eNFicheReception = $p->n_fiche_reception ?? '';
    $this->eNDevis = $p->n_devis ?? '';
    $this->eMoyen = $p->moyen;
    $this->eActivite = $p->activite;
    $this->ePassage = (bool) $p->passage;
    $this->eDatePassage = $p->date_passage?->toDateString();
    $this->eDevisApres = (bool) $p->devis_apres_passage;
    $this->eDateDevis = $p->date_devis?->toDateString();
    $this->eObservations = $p->observations ?? '';
};

$annulerEdition = function () {
    $this->editionId = null;
    $this->resetValidation();
};

/** Mêmes règles de cohérence qu'à la saisie : un devis suppose un passage. */
$updatedEPassage = function ($valeur) {
    if (! $valeur) {
        $this->eDevisApres = false;
        $this->eDateDevis = null;
        $this->eDatePassage = null;

        return;
    }

    $this->eDatePassage ??= now()->toDateString();
};

$updatedEDevisApres = function ($valeur) {
    if (! $valeur) {
        $this->eDateDevis = null;

        return;
    }

    $this->eDateDevis ??= now()->toDateString();
    $this->ePassage = true;
    $this->eDatePassage = $this->eDateDevis;
};

$updatedEDateDevis = function ($valeur) {
    if ($this->eDevisApres) {
        $this->eDatePassage = $valeur;
    }
};

$enregistrerEdition = function () {
    // Le formulaire n'apparaît qu'une ligne ouverte : un appel sans ligne ne peut venir
    // que d'une requête forgée, on l'ignore sans rien changer.
    $p = $this->editionId
        ? Prospection::where('commercial_id', $this->commercial?->id ?? 0)
            ->where('statut_validation', 'Brouillon')->find($this->editionId)
        : null;

    if (! $p) {
        $this->annulerEdition();

        return;
    }

    $donnees = $this->validate([
        'eClient' => ['required', 'string', 'max:255'],
        'eLocalisation' => ['nullable', 'string', 'max:255'],
        'eMoyen' => ['required', 'string', 'max:60'],
        'eActivite' => ['required', 'string', 'max:60'],
        'eImmatriculation' => ['nullable', 'string', 'max:32'],
        'eNFicheReception' => ['nullable', 'string', 'max:60'],
        'eNDevis' => [$this->eDevisApres ? 'required' : 'nullable', 'string', 'max:60'],
        'eDatePassage' => ['nullable', 'date'],
        'eDateDevis' => ['nullable', 'date'],
        'eObservations' => ['nullable', 'string'],
    ], [], ['eClient' => 'clients visités', 'eActivite' => 'activité', 'eNDevis' => 'n° du devis']);

    $coherence = Prospection::normaliserPassage(
        (bool) $this->ePassage, $donnees['eDatePassage'],
        (bool) $this->eDevisApres, $donnees['eDateDevis'],
    );

    $p->update([
        'client' => $donnees['eClient'],
        'localisation' => $donnees['eLocalisation'] ?: null,
        'immatriculation' => $donnees['eImmatriculation'] ?: null,
        'n_fiche_reception' => trim($donnees['eNFicheReception'] ?? '') ?: null,
        'n_devis' => trim($donnees['eNDevis'] ?? '') ?: null,
        'moyen' => $donnees['eMoyen'],
        'activite' => $donnees['eActivite'],
        ...$coherence,
        'observations' => $donnees['eObservations'] ?: null,
    ]);

    $this->editionId = null;
    $this->annoncer('Brouillon modifié.');
};

/** Cocher directement dans la liste, sans ouvrir le formulaire pour une seule case. */
$basculerPassage = function (int $id) {
    $p = Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->where('statut_validation', 'Brouillon')->findOrFail($id);

    $passage = ! $p->passage;

    $p->update([
        'passage' => $passage,
        'date_passage' => $passage ? ($p->date_passage ?? $p->date) : null,
        'devis_apres_passage' => $passage ? $p->devis_apres_passage : false,
        'date_devis' => $passage ? $p->date_devis : null,
    ]);
};

/**
 * Cocher « devis après passage », **y compris sur une prospection déjà transmise**.
 *
 * Un devis ne se signe pas pendant la visite : il arrive un jour, une semaine plus tard.
 * Interdire cette case après transmission revenait à garantir que l'indicateur reste vide
 * pour presque toutes les prospections — celles qui sont parties, c'est-à-dire toutes celles
 * qui comptent.
 *
 * La ligne refusée, elle, reste fermée : elle a été arbitrée, la rouvrir par une case
 * effacerait la décision du responsable.
 */
$basculerDevisApres = function (int $id) {
    $p = Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->whereIn('statut_validation', ['Brouillon', 'Transmise'])->findOrFail($id);

    $devisApres = ! $p->devis_apres_passage;
    $dateDevis = $devisApres ? ($p->date_devis ?? $p->date_passage ?? $p->date) : null;

    $p->update([
        'devis_apres_passage' => $devisApres,
        'date_devis' => $dateDevis,
        'passage' => $devisApres ? true : $p->passage,
        'date_passage' => $devisApres ? $dateDevis : $p->date_passage,
    ]);

    // Le responsable arbitre cette ligne : si elle change après lui être partie, il doit
    // l'apprendre. Une ligne qui bouge en silence sous les yeux de qui l'arbitre est pire
    // qu'une ligne qu'on ne peut pas corriger.
    if ($p->statut_validation === 'Transmise') {
        $this->prevenirDUnComplement($p, $devisApres ? 'un devis obtenu après le passage' : 'le retrait du devis');
    }

    $this->annoncer($p->statut_validation === 'Transmise'
        ? 'Mise à jour transmise à votre responsable.'
        : 'Brouillon modifié.');
};

/**
 * Compléter les informations libres d'une prospection déjà partie.
 *
 * Il n'y a pas de brouillon ici : ce qui est écrit est immédiatement transmis, parce que
 * la ligne est déjà chez le responsable. D'où un seul bouton, et son libellé qui le dit.
 */
$ouvrirCompletion = function (int $id) {
    $this->completionId = $id;
};

$fermerCompletion = function () {
    $this->completionId = null;
};

$completerLesInformations = function (int $id) {
    $p = Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->where('statut_validation', 'Transmise')->find($id);

    if (! $p) {
        return;
    }

    $texte = trim((string) ($this->complements[$id] ?? ''));

    $p->update(['observations' => $texte ?: null]);
    $this->completionId = null;
    $this->prevenirDUnComplement($p, 'des informations complémentaires');
    $this->annoncer('Informations transmises à votre responsable.');
};

/**
 * Prévenir l'encadrement qu'une ligne déjà partie vient de changer.
 *
 * Le même chemin que la transmission elle-même — pas un second mécanisme : deux façons de
 * prévenir finissent toujours par en avoir une qui ne prévient plus.
 */
$prevenirDUnComplement = function (Prospection $p, string $quoi) {
    Notificateur::pourPlusieurs(
        destinataires: Notificateur::encadrementDeVille($this->commercial?->ville_id, auth()->user()->entreprise_id),
        titre: 'Prospection '.$p->numero.' complétée',
        corps: auth()->user()->name.' a ajouté '.$quoi.' sur une prospection déjà transmise.',
        canal: NotificationApp::CANAL_GESTION,
        niveau: NotificationApp::NIVEAU_INFO,
        lien: route('saisie-du-jour'),
    );
};

$supprimer = function (int $id) {
    Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->where('statut_validation', 'Brouillon')->where('id', $id)->delete();

    unset($this->selection[$id]);
    $this->annoncer('Brouillon supprimé.');
};

/**
 * Transmettre une seule ligne, depuis sa propre colonne Actions.
 *
 * Le commercial ne « valide » pas : il transmet. La validation appartient au
 * responsable, qui arbitre. C'est pour lui le geste équivalent — la ligne quitte
 * son brouillon et part à l'arbitrage.
 */
$transmettre = function (int $id) {
    $nombre = Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->where('statut_validation', 'Brouillon')
        ->where('id', $id)
        ->update(['statut_validation' => 'Transmise', 'transmise_le' => now()]);

    if ($nombre === 0) {
        // Déjà transmise, ou pas à lui : rien à faire, et surtout aucune notification
        // à envoyer pour un geste qui n'a rien changé.
        return;
    }

    $this->prevenirLeResponsable(1);

    unset($this->selection[$id]);
    $this->annoncer('Prospection transmise à votre responsable.');
};

$transmettreSelection = function () {
    $ids = $this->selectionnes;

    if (empty($ids)) {
        $this->annoncer('Sélectionnez au moins un brouillon à transmettre.', 'alerte');

        return;
    }

    // On ne transmet que ses propres brouillons, jamais une ligne déjà arbitrée.
    $nombre = Prospection::where('commercial_id', $this->commercial?->id ?? 0)
        ->where('statut_validation', 'Brouillon')
        ->whereIn('id', $ids)
        ->update(['statut_validation' => 'Transmise', 'transmise_le' => now()]);

    // Le responsable est prévenu tout de suite : sans cela, une transmission peut
    // dormir plusieurs jours avant d'être arbitrée.
    if ($nombre > 0) {
        $this->prevenirLeResponsable($nombre);
    }

    $this->selection = [];
    $this->annoncer(
        $nombre > 0 ? "$nombre prospection(s) transmise(s) à votre responsable de site." : 'Aucun brouillon transmis.',
        $nombre > 0 ? 'succes' : 'alerte',
    );
};

?>

<div>
    @if (! $this->commercial)
        <x-a-venir titre="Aucune fiche commerciale associée"
            description="Votre compte n'est rattaché à aucune fiche commerciale. Contactez votre responsable de site." />
    @else
        <div class="carte" style="margin-bottom:14px; display:flex; flex-wrap:wrap; gap:14px; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:12px;">
                <x-avatar :utilisateur="auth()->user()" :taille="44" />
                <div>
                    <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:23px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:0;">
                        {{ $this->commercial->nom }}
                    </h1>
                    <p style="color:var(--th-gris,#6B6E76); font-size:12.5px; margin:2px 0 0;">
                        {{ $this->commercial->ville->nom }} · N° {{ $this->commercial->numero }}
                    </p>
                </div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" wire:click="toutSelectionner" class="bouton bouton-secondaire bouton-petit">
                    Tout sélectionner ({{ count($this->brouillonsAffiches) }})
                </button>
                <button type="button" wire:click="toutDeselectionner" class="bouton bouton-secondaire bouton-petit">Aucun</button>
                <button type="button" wire:click="transmettreSelection"
                    class="bouton" @disabled(count($this->selectionnes) === 0)>
                    Transmettre la sélection ({{ count($this->selectionnes) }})
                </button>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(165px, 1fr)); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Brouillons" :value="$this->compteurs['brouillon']" sub="À transmettre" />
            <x-kpi-card label="Transmises" :value="$this->compteurs['transmise']" sub="En attente du responsable" />
            <x-kpi-card label="Validées" :value="$this->compteurs['validee']" :bon="true" />
            <x-kpi-card label="Refusées" :value="$this->compteurs['refusee']" :accent="$this->compteurs['refusee'] > 0" />
        </div>

        <x-carte-section titre="Nouvelle prospection">
            <div class="bloc-saisie">
                <x-champ label="Date" model="date" type="date" width="140" />
                <x-champ label="Clients visités" model="client" requis="true" />
                <x-champ label="Localisation" model="localisation" width="150" />
                {{-- La plaque, et non le n° de fiche, est ce que le commercial a sous les
                     yeux au moment de la visite. C'est elle qui retrouvera le devis émis
                     trois à cinq jours plus tard : personne ne reviendra écrire un numéro
                     de fiche sur une visite de la semaine passée. --}}
                <x-champ label="Immatriculation" model="immatriculation" width="150"
                    placeholder="1234 AB 01" aide="Facultatif — sert à retrouver le devis" />
                <x-champ label="N° de fiche de réception" model="nFicheReception" width="170"
                    placeholder="FR-…" aide="Facultatif — si le véhicule est déjà à l'atelier" />
                <x-champ label="Moyens" model="moyen" type="select" :options="$this->optionsMoyen" width="140" />
                <x-champ label="Activité" model="activite" type="select" :options="$this->optionsActivite" width="150" />
                <x-champ label="Passage" model="passage" type="checkbox" live="true" />
                @if ($passage && ! $devisApres)
                    <x-champ label="Date de passage" model="datePassage" type="date" width="150" />
                @endif
                <x-champ label="Devis après passage" model="devisApres" type="checkbox" live="true" />
                @if ($devisApres)
                    <x-champ label="Date du devis (= date de passage)" model="dateDevis" type="date" live="true" width="180" />
                    {{-- Il n'apparaît qu'ici, et il est exigé : c'est l'instant où le devis
                         existe et où son numéro est sous les yeux du commercial. Le donner
                         maintenant évite tout le rapprochement qui suivrait. --}}
                    <x-champ label="N° du devis" model="nDevis" width="170" requis="true"
                        placeholder="PR-MT-11434" aide="Ou le n° de fiche, à défaut" />
                @endif
                <x-champ label="Observations" model="observations" />
                {{-- Deux gestes distincts : mettre de côté, ou transmettre tout de suite.
                     La plupart des visites se saisissent une fois rentré, sûr de soi :
                     les faire passer par le brouillon coûtait deux clics de plus. --}}
                <button type="button" wire:click="ajouterEtTransmettre" class="bouton bouton-sombre">+ Ajouter et transmettre</button>
                <button type="button" wire:click="ajouterEnBrouillon" class="bouton bouton-secondaire">Enregistrer en brouillon</button>
            </div>
            {{-- Les listes déroulantes sont fixées par la direction, dans Paramètres →
                 Listes déroulantes. Inviter chaque poste à créer sa propre valeur
                 rendrait les indicateurs incomparables d'une ville à l'autre. --}}
            <p style="font-size:11.5px; color:#9A9DA5; margin:8px 0 0;">
                Une valeur manque dans « Moyens » ou « Activité » ? Signalez-le à votre responsable :
                ces listes sont définies pour toute l'entreprise.
            </p>

            <div style="margin-top:12px;">
                <label class="champ-libelle">Commentaire à l'attention de votre responsable</label>
                <textarea wire:model="commentaire" rows="2" class="champ" style="resize:vertical;"
                    placeholder="Ex. : affluence en baisse (pluies), campagne en cours sur la zone industrielle..."></textarea>
            </div>
        </x-carte-section>

        <x-carte-section titre="Mes prospections">
            {{-- Filtres : appliqués à la frappe et conservés dans l'adresse de la page. --}}
            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="N°" model="fNumero" live="true" width="120" placeholder="P-00…" />
                <x-champ label="Date" model="fDate" type="date" live="true" width="150" />
                <x-champ label="Activité" model="fActivite" type="select" live="true" width="150"
                    :options="collect(['' => 'Toutes'])->union($this->optionsActivite)" />
                <x-champ label="Client" model="fClient" live="true" placeholder="Nom du client…" />
                <x-champ label="Statut" model="fStatut" type="select" live="true" width="150"
                    :options="['' => 'Tous', 'Brouillon' => 'Brouillon', 'Transmise' => 'Transmise', 'Validée' => 'Validée', 'Refusée' => 'Refusée']" />
                <button type="button" wire:click="reinitialiserFiltres" class="bouton bouton-secondaire bouton-petit">Réinitialiser</button>
            </div>

            <div class="tableau-conteneur" style="margin-top:12px;">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>✓</th><th>N°</th><th>Date</th><th>Clients visités</th><th>Localisation</th>
                            <th>Véhicule</th><th>Moyens</th><th>Activité</th><th>Passage</th><th>Devis après passage</th>
                            <th>Observations</th><th>Informations libres</th><th>Statut</th>
                            <th>Décision</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes as $ligne)
                            @php
                                $pastille = [
                                    'Brouillon' => 'pastille-ambre', 'Transmise' => 'pastille-bleu',
                                    'Validée' => 'pastille-vert', 'Refusée' => 'pastille-rouge',
                                ][$ligne->statut_validation] ?? 'pastille-ambre';
                            @endphp
                            @php $brouillon = $ligne->statut_validation === 'Brouillon'; @endphp

                            @if ($editionId === $ligne->id)
                                {{-- Un brouillon n'est pas encore parti : il appartient toujours
                                     au commercial, qui doit pouvoir le corriger. --}}
                                <tr wire:key="pros-edit-{{ $ligne->id }}" style="background:#FDF2F4;">
                                    <td>—</td>
                                    <td><x-numero-ligne :ligne="$ligne" /></td>
                                    <td>{{ $ligne->date->format('d/m/Y') }}</td>
                                    <td><input type="text" wire:model="eClient" value="{{ $eClient }}" class="champ" style="min-width:130px;"></td>
                                    <td><input type="text" wire:model="eLocalisation" value="{{ $eLocalisation }}" class="champ" style="min-width:110px;"></td>
                                    <td style="white-space:normal; min-width:150px;">
                                        <input type="text" wire:model="eImmatriculation" value="{{ $eImmatriculation }}"
                                            class="champ" placeholder="1234 AB 01">
                                        <input type="text" wire:model="eNFicheReception" value="{{ $eNFicheReception }}"
                                            class="champ" placeholder="N° de fiche" style="margin-top:4px;">
                                    </td>
                                    <td>
                                        <select wire:model="eMoyen" class="champ">
                                            @foreach ($this->optionsMoyen as $valeur => $libelle)
                                                <option value="{{ $valeur }}" @selected((string) $eMoyen === (string) $valeur)>{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select wire:model="eActivite" class="champ">
                                            @foreach ($this->optionsActivite as $valeur => $libelle)
                                                <option value="{{ $valeur }}" @selected((string) $eActivite === (string) $valeur)>{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td style="white-space:normal; min-width:140px;">
                                        <label style="display:flex; align-items:center; gap:5px; font-size:13px;">
                                            <input type="checkbox" wire:model.live="ePassage"> Passage
                                        </label>
                                        @if ($ePassage && ! $eDevisApres)
                                            <input type="date" wire:model="eDatePassage" value="{{ $eDatePassage }}" class="champ" style="margin-top:4px;">
                                        @endif
                                    </td>
                                    <td style="white-space:normal; min-width:170px;">
                                        <label style="display:flex; align-items:center; gap:5px; font-size:13px;">
                                            <input type="checkbox" wire:model.live="eDevisApres"> Devis après passage
                                        </label>
                                        @if ($eDevisApres)
                                            <input type="date" wire:model.live="eDateDevis" value="{{ $eDateDevis }}" class="champ" style="margin-top:4px;">
                                            <input type="text" wire:model="eNDevis" value="{{ $eNDevis }}"
                                                class="champ" placeholder="N° du devis" style="margin-top:4px;">
                                        @endif
                                    </td>
                                    <td style="white-space:normal; min-width:180px;">
                                        <textarea wire:model="eObservations" rows="2" placeholder="Observations"
                                            style="width:100%; box-sizing:border-box; padding:6px 8px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:13px;"></textarea>
                                    </td>
                                    <td>—</td>
                                    <td><span class="pastille pastille-ambre">Brouillon</span></td>
                                    <td>—</td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <button type="button" wire:click="enregistrerEdition" class="bouton bouton-petit bouton-vert" style="margin-right:5px;">Enregistrer</button>
                                        <button type="button" wire:click="annulerEdition" class="bouton bouton-petit bouton-secondaire">Annuler</button>
                                    </td>
                                </tr>
                            @else
                            <tr wire:key="pros-{{ $ligne->id }}">
                                <td>
                                    @if ($brouillon)
                                        <input type="checkbox" wire:model.live="selection.{{ $ligne->id }}">
                                    @endif
                                </td>
                                <td><x-numero-ligne :ligne="$ligne" /></td>
                                <td>{{ $ligne->date->format('d/m/Y') }}</td>
                                <td>{{ $ligne->client }}</td>
                                <td style="color:var(--th-gris,#6B6E76);">{{ $ligne->localisation ?? '—' }}</td>
                                <td style="color:var(--th-gris,#6B6E76);">
                                    {{ $ligne->immatriculation ?? '—' }}
                                    @if ($ligne->n_fiche_reception)
                                        <span style="font-size:11px; display:block;">{{ $ligne->n_fiche_reception }}</span>
                                    @endif
                                </td>
                                <td>{{ $ligne->moyen }}</td>
                                <td>{{ $ligne->activite }}</td>

                                {{-- Ce qui est **arbitré** se fige à la transmission — le client, la
                                     date, le moyen, l'activité : ils ne doivent pas changer sous les
                                     yeux du responsable. Ce qui est **constaté** reste ouvert, et le
                                     devis en fait partie : il arrive une semaine après la visite, et
                                     verrouiller sa case revenait à garantir que l'indicateur reste
                                     vide pour toutes les prospections parties.

                                     La date reste affichée sous la case : une coche sans date laisse
                                     croire que la date n'a pas été enregistrée. --}}
                                <td style="text-align:center;">
                                    @if ($brouillon)
                                        <input type="checkbox" wire:click="basculerPassage({{ $ligne->id }})"
                                            @checked($ligne->passage) style="width:16px; height:16px; cursor:pointer;"
                                            title="Êtes-vous passé sur site ?">
                                    @else
                                        {{ $ligne->passage ? '☑' : '☐' }}
                                    @endif
                                    <x-date-sous-case :date="$ligne->date_passage" />
                                </td>
                                <td style="text-align:center;">
                                    @if ($brouillon || $ligne->statut_validation === 'Transmise')
                                        <input type="checkbox" wire:click="basculerDevisApres({{ $ligne->id }})"
                                            @checked($ligne->devis_apres_passage) style="width:16px; height:16px; cursor:pointer;"
                                            title="Un devis a-t-il suivi ? Sur une ligne transmise, la mise à jour part aussitôt au responsable.">
                                    @else
                                        {{ $ligne->devis_apres_passage ? '☑' : '☐' }}
                                    @endif
                                    <x-date-sous-case :date="$ligne->date_devis" />
                                </td>

                                {{-- Sur une ligne transmise, les informations libres restent
                                     ouvertes — mais il n'y a plus de brouillon : ce qui est écrit
                                     part immédiatement, puisque la ligne est déjà chez le
                                     responsable. D'où un seul bouton, et son libellé qui le dit. --}}
                                {{-- L'observation est une valeur, et elle s'affiche comme les
                                     autres. Elle sortait ici sous la forme d'une boîte de saisie
                                     doublée d'un bouton, au milieu d'une colonne de textes : on ne
                                     savait plus si ce qu'on lisait était enregistré ou en cours de
                                     frappe. Ce qu'on peut encore ajouter après transmission se fait
                                     dans la colonne d'à côté, avec les autres informations libres. --}}
                                <td style="color:var(--th-gris,#6B6E76); white-space:normal; min-width:200px;">
                                    {{ $ligne->observations ?: '—' }}
                                </td>
                                <td style="white-space:normal; min-width:230px;">
                                    {{-- Une fois la ligne transmise, on ajoute encore mais on
                                         n'efface plus : le responsable arbitre sur ce qu'il lit. --}}
                                    <x-saisie-libre :sujet="$ligne" :supprimable="$brouillon"
                                        :ouvert="$libreSujetId === $ligne->id && $libreSujetType === get_class($ligne)" />

                                    {{-- Compléter l'observation d'une ligne déjà partie. C'est le
                                         même geste que la saisie libre — ajouter un renseignement à
                                         une ligne qui est chez le responsable — et il se tient donc
                                         au même endroit. Il n'y a pas de brouillon ici : ce qui est
                                         écrit part aussitôt, et le libellé du bouton le dit. --}}
                                    @if ($ligne->statut_validation === 'Transmise')
                                        @if ($completionId === $ligne->id)
                                            <div style="margin-top:8px; background:#FAF9F5; border:1px dashed var(--th-ligne,#E2E0D8);
                                                        border-radius:8px; padding:10px;">
                                                <label style="display:block; font-size:12px; font-weight:700;
                                                              color:#4B4E55; margin-bottom:4px;">
                                                    Observations
                                                </label>
                                                <textarea wire:model="complements.{{ $ligne->id }}" rows="2"
                                                    placeholder="Ce que vous voulez ajouter à cette prospection…"
                                                    style="width:100%; box-sizing:border-box; padding:6px 8px;
                                                           border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px;
                                                           font-size:12.5px;">{{ $ligne->observations }}</textarea>
                                                <div style="display:flex; gap:7px; margin-top:6px; flex-wrap:wrap;">
                                                    <button type="button" wire:click="completerLesInformations({{ $ligne->id }})"
                                                        class="bouton bouton-petit bouton-vert">Valider et transmettre</button>
                                                    <button type="button" wire:click="fermerCompletion"
                                                        class="bouton bouton-secondaire bouton-petit">Annuler</button>
                                                </div>
                                            </div>
                                        @else
                                            <button type="button" wire:click="ouvrirCompletion({{ $ligne->id }})"
                                                class="bouton bouton-secondaire bouton-petit" style="margin-top:6px;">
                                                + Observation
                                            </button>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    <span class="pastille {{ $pastille }}">{{ $ligne->statut_validation }}</span>
                                    @if ($ligne->statut_validation === 'Refusée' && $ligne->motif_refus)
                                        <div style="font-size:11px; color:var(--th-accent,#C8102E); margin-top:3px; white-space:normal;">{{ $ligne->motif_refus }}</div>
                                    @endif
                                </td>

                                {{-- Qui a tranché, et quand. La ligne passait au vert sans qu'on
                                     sache à qui s'adresser quand le devis promis tardait. --}}
                                <td style="white-space:normal; min-width:150px;">
                                    @if ($ligne->validateur)
                                        <b>{{ $ligne->validateur }}</b>
                                        <div style="font-size:11px; color:var(--th-gris,#6B6E76);">
                                            {{ $ligne->valide_le?->format('d/m/Y à H\hi') }}
                                        </div>
                                    @elseif (in_array($ligne->statut_validation, ['Validée', 'Refusée'], true))
                                        <span style="color:var(--th-gris,#6B6E76); font-size:12px;">
                                            avant la traçabilité
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    {{-- Le détail est un lien, pas un bouton : il s'ouvre dans un
                                         autre onglet, se met en favori, et ne dépend d'aucun script. --}}
                                    <a href="{{ route('prospection.fiche', $ligne->id) }}"
                                       class="bouton bouton-secondaire bouton-petit"
                                       style="text-decoration:none; margin-right:5px;">Détail</a>

                                    @if ($brouillon)
                                        {{-- Transmettre une seule ligne sans passer par la sélection :
                                             cocher puis remonter au bouton du haut faisait trois gestes
                                             pour un brouillon isolé, ce qui est le cas courant. --}}
                                        <button type="button" wire:click="transmettre({{ $ligne->id }})"
                                            class="bouton bouton-petit bouton-vert" style="margin-right:5px;">Transmettre</button>
                                        <button type="button" wire:click="modifier({{ $ligne->id }})"
                                            class="bouton bouton-secondaire bouton-petit" style="margin-right:5px;">Modifier</button>
                                        <button type="button" wire:click="supprimer({{ $ligne->id }})"
                                            wire:confirm="Supprimer ce brouillon ?"
                                            class="bouton bouton-secondaire bouton-petit">Supprimer</button>
                                    @endif
                                </td>
                            </tr>
                            @endif
                        @empty
                            <x-table-vide :colspan="14" texte="Aucune prospection ne correspond à ces filtres." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:12px;">{{ $this->lignes->links() }}</div>
        </x-carte-section>
    @endif
</div>
