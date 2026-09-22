<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Porter une facture existante à l'état des impayés
|--------------------------------------------------------------------------
| La communication du reste de l'application vers l'état : une facture que l'application
| connaît déjà — CATTC importé, saisie du jour, bloc « Facture » du recouvrement — y entre
| en disant seulement quand elle a été déposée chez le client et ce qui a déjà été payé.
|
| **Deux listes déroulantes, et non une case de recherche.** On choisit le client, puis la
| facture parmi les siennes, désignée par son numéro de saisie. Chacune se fouille par
| l'intérieur (x-select-cherchable). Mesuré en local : 425 clients et 2 480 factures hors
| de l'état, au plus 342 pour un même client — une liste par client reste lisible, une liste
| de toutes ne le serait pas.
|
| **Un composant à part, pour la vitesse.** Logé dans l'écran de l'état, chaque choix faisait
| relire et redessiner le tableau entier. Ici, un choix ne redessine que ce panneau.
*/

state([
    'client' => '',
    'factureId' => '',

    /*
     * Les colonnes du classeur, dans l'ordre et sous les mots de l'écran de saisie — c'est
     * volontairement le **même formulaire** que « Nouvelle créance ». Ce qui change, c'est
     * qu'ici les cases arrivent remplies par la facture, et que quatre d'entre elles ne se
     * touchent pas : la date d'édition, le numéro, le montant (ils appartiennent à l'écran
     * qui a fait naître la facture) et, sur une ligne reprise d'un fichier, l'immatriculation
     * — voir EtatDesImpayes::champsVerrouilles().
     */
    'pAssureur' => '',
    'pClient' => '',
    'pSiteId' => '',
    'pVilleId' => '',
    'pCourtier' => '',
    // Porter une facture, c'est déclarer qu'elle a été déposée : c'est donc ici, plus
    // qu'ailleurs, qu'on sait dire chez qui.
    'pDeposeChez' => '',
    'pDateReception' => '',
    'pSinistre' => '',
    'pVehicule' => '',
    'pImmatriculation' => '',
    'pRegle' => '',
    'pModeReglement' => '',
    'pDateReglement' => '',
    'pBanque' => '',
    'pCommentaires' => '',
    'pPasLeMemeDossier' => false,
]);

/** « Porter à l'état » depuis une autre page arrive ici avec la facture déjà choisie. */
mount(function (?int $facture = null) {
    if ($facture) {
        $this->factureId = (string) $facture;
        $this->prendreLaFacture();
    }
});

/*
 * Le périmètre du compte, relu à chaque requête. Les identifiants reçus du navigateur — le
 * client, la facture — ne sont jamais crus : ils ne servent qu'à chercher **dans** ce périmètre.
 */
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), '', ''));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));

/*
 * La requête commune : les factures hors de l'état que ce compte a le droit de voir.
 * Protégée, comme `prendreLaFacture` : une closure Volt nue est une action publique,
 * appelable depuis le navigateur.
 */
$horsEtat = protect(fn () => EtatDesImpayes::dansLePerimetre(
    Facture::query()->whereNull('exercice_impayes'),
    $this->idsSites,
    $this->idsVilles,
));

/** Les clients qui ont au moins une facture à porter — nom => nom (nombre). */
$clients = computed(function () {
    return $this->horsEtat()
        ->whereNotNull('client')->where('client', '!=', '')
        ->select('client', DB::raw('count(*) as nombre'))
        ->groupBy('client')
        ->orderBy('client')
        ->toBase()
        ->get()
        ->mapWithKeys(fn ($l) => [$l->client => $l->client.' ('.$l->nombre.')'])
        ->all();
});

/**
 * Les factures du client choisi, désignées par leur numéro de saisie.
 *
 * Colonnes choisies plutôt que `*` : la liste n'affiche que six valeurs, et hydrater les
 * trente colonnes d'une facture pour chacune coûtait sans rien montrer.
 */
$factures = computed(function () {
    if ($this->client === '') {
        return [];
    }

    return $this->horsEtat()
        ->where('client', $this->client)
        ->orderByDesc('date')->orderByDesc('id')
        ->toBase()
        ->get(['id', 'numero', 'n_facture', 'date', 'immatriculation', 'montant'])
        ->mapWithKeys(fn ($f) => [$f->id => implode(' · ', array_filter([
            $f->numero,
            $f->n_facture ? 'N° '.$f->n_facture : null,
            $f->date ? \Illuminate\Support\Carbon::parse($f->date)->format('d/m/Y') : null,
            $f->immatriculation,
            ae((int) $f->montant),
        ]))])
        ->all();
});

$facture = computed(fn () => $this->factureId === '' ? null : $this->horsEtat()
    ->withSum('encaissements', 'montant')
    ->with(['site', 'ville'])
    ->find((int) $this->factureId));

/**
 * Les lignes de l'état qui ressemblent à la facture qu'on s'apprête à porter.
 *
 * Le CATTC et le classeur parlent des mêmes affaires sans partager de numéro : la même
 * facture peut déjà figurer à l'état sous « 17 » pendant qu'on la cherche sous « FA -5713 ».
 * Le pont — immatriculation et montant — n'est pas unique : on montre et on fait confirmer.
 */
$semblables = computed(function () {
    $facture = $this->facture;
    $cle = $facture ? EtatDesImpayes::clePont($facture) : null;

    if ($cle === null) {
        return collect();
    }

    return Facture::query()
        ->whereNotNull('exercice_impayes')
        ->where('montant', (int) $facture->montant)
        ->whereNotNull('immatriculation')
        ->get(['id', 'numero', 'n_facture', 'date', 'client', 'immatriculation', 'montant', 'exercice_impayes'])
        ->filter(fn (Facture $f) => EtatDesImpayes::clePont($f) === $cle)
        ->values();
});

/**
 * L'avance déjà encaissée sur la facture, et ce qu'il reste à réclamer.
 *
 * Une facture du CATTC n'apporte aucun règlement, mais une facture de la saisie du jour ou du
 * recouvrement peut déjà avoir été payée en partie : l'écran le montre et le **déduit**, plutôt
 * que de laisser porter une créance de cinq millions dont trois sont déjà en caisse.
 *
 * @return array{avance: int, reste: int}
 */
$avance = computed(function () {
    $facture = $this->facture;

    if ($facture === null) {
        return ['avance' => 0, 'reste' => 0];
    }

    $avance = (int) ($facture->encaissements_sum_montant ?? 0);

    return ['avance' => $avance, 'reste' => max(0, (int) $facture->montant - $avance)];
});

/** Les colonnes que la facture impose — mêmes règles que « Modifier ». */
$verrouilles = computed(function () {
    $facture = $this->facture;

    if ($facture === null) {
        return [];
    }

    /*
     * La date d'édition, le numéro et le montant ne se changent jamais ici : ils appartiennent
     * à l'écran qui a fait naître la facture, et les réécrire au passage à l'état ferait deux
     * vérités pour une seule pièce. L'immatriculation s'y ajoute sur une ligne reprise d'un
     * fichier : elle fait partie de la clé par laquelle l'import la reconnaît.
     */
    return array_values(array_unique(array_merge(
        ['date', 'n_facture', 'montant'],
        EtatDesImpayes::champsVerrouilles($facture),
    )));
});

$sitesSaisissables = computed(fn () => Site::visiblesPour(auth()->user())->sortBy('nom')->pluck('nom', 'id')->all());

$villesSaisissables = computed(fn () => Ville::query()
    ->whereIn('id', Site::visiblesPour(auth()->user())->pluck('ville_id')->filter()->unique())
    ->orderBy('nom')->pluck('nom', 'id')->all());

$modes = computed(fn () => Referentiel::options(Referentiel::MODE_RECOUVREMENT));

/**
 * Remplit ce qui peut l'être depuis la facture, et vide le reste.
 *
 * Par affectation et non par `reset()` : dans Volt, `reset()` rend null, et la règle « un
 * règlement vide n'exige ni mode ni date » compare à la chaîne vide.
 */
$prendreLaFacture = protect(function () {
    foreach (['pAssureur', 'pClient', 'pSiteId', 'pVilleId', 'pCourtier', 'pDeposeChez', 'pDateReception', 'pSinistre',
        'pVehicule', 'pImmatriculation', 'pRegle', 'pModeReglement', 'pDateReglement', 'pBanque',
        'pCommentaires'] as $champ) {
        $this->{$champ} = '';
    }

    $this->pPasLeMemeDossier = false;
    $this->resetErrorBag();
    unset($this->facture, $this->semblables);

    $facture = $this->facture;

    if ($facture === null) {
        $this->factureId = '';

        return;
    }

    // Arrivée directe sur une facture : le client se pose de lui-même, pour que la liste
    // montre la facture choisie et ses voisines.
    $this->client = (string) $facture->client;
    // Chaque case du formulaire reçoit ce que la facture sait déjà : on ne retape rien.
    $this->pAssureur = (string) $facture->assureur;
    $this->pClient = (string) $facture->client;
    $this->pSiteId = (string) ($facture->site_id ?? '');
    $this->pVilleId = (string) ($facture->ville_id ?? '');
    $this->pCourtier = (string) $facture->courtier;
    $this->pDeposeChez = (string) $facture->depose_chez;
    $this->pSinistre = (string) $facture->n_sinistre;
    $this->pVehicule = (string) $facture->vehicule;
    $this->pImmatriculation = (string) $facture->immatriculation;
    $this->pBanque = (string) $facture->banque;
    $this->pCommentaires = (string) $facture->observations;
    $this->pDateReception = $facture->date_reception?->toDateString() ?? '';
});

$updatedClient = function () {
    $this->factureId = '';
    $this->prendreLaFacture();
};

$updatedFactureId = function () {
    $this->prendreLaFacture();
};

/**
 * Porte la facture à l'état.
 *
 * **Rien n'est créé, sauf le règlement déclaré.** La facture reçoit son année d'état et sa
 * date de réception ; son numéro, son montant et sa date d'édition ne bougent pas. **Le
 * règlement déjà reçu se déclare dans le même geste** : une facture du CATTC arrive sans
 * encaissement, et la porter sans dire ce qui a été payé la ferait lire intégralement due.
 */
$porter = function () {
    unset($this->facture, $this->semblables);
    $facture = $this->facture;

    if ($facture === null) {
        $this->addError('factureId', "Choisissez d'abord une facture dans la liste.");

        return;
    }

    $sitesPermis = array_keys($this->sitesSaisissables);

    if ($facture->site_id !== null) {
        $sitesPermis[] = $facture->site_id;
    }

    /*
     * Les colonnes verrouillées reprennent leur valeur en base, quoi qu'envoie le navigateur :
     * un champ désactivé à l'écran n'est pas une protection.
     */
    if (in_array('immatriculation', $this->verrouilles, true)) {
        $this->pImmatriculation = (string) $facture->immatriculation;
    }

    $donnees = $this->validate([
        // Obligatoire : c'est la date du dépôt chez le client, qui fait d'une facture une
        // créance de l'état et d'où part son ancienneté (décision du 16/09/2026).
        'pDateReception' => ['required', 'date', 'after_or_equal:'.$facture->date?->toDateString(), 'before_or_equal:today'],
        'pClient' => ['required', 'string', 'max:255'],
        'pAssureur' => ['nullable', 'string', 'max:160'],
        'pCourtier' => ['nullable', 'string', 'max:160'],
        'pDeposeChez' => ['nullable', 'string', 'max:160'],
        'pVehicule' => ['nullable', 'string', 'max:120'],
        'pImmatriculation' => ['nullable', 'string', 'max:30'],
        'pSiteId' => ['nullable', Rule::in($sitesPermis)],
        'pVilleId' => ['nullable', Rule::in(array_keys($this->villesSaisissables))],
        'pSinistre' => ['nullable', 'string', 'max:60'],
        'pBanque' => ['nullable', 'string', 'max:120'],
        'pCommentaires' => ['nullable', 'string', 'max:255'],
        // Le règlement saisi vient s'ajouter à l'avance : les deux réunis ne peuvent pas
        // dépasser le montant facturé.
        'pRegle' => ['nullable', 'integer', 'min:0', 'max:'.$this->avance['reste']],
        'pModeReglement' => ['exclude_if:pRegle,', 'required_unless:pRegle,0', Rule::in(array_keys($this->modes))],
        'pDateReglement' => ['exclude_if:pRegle,', 'required_unless:pRegle,0', 'date', 'before_or_equal:today'],
    ], [
        'pDateReception.required' => "La date de réception est obligatoire : c'est la date du dépôt chez le client.",
        'pDateReception.after_or_equal' => "Une facture ne se dépose pas avant d'avoir été éditée (le ".$facture->date?->format('d/m/Y').').',
        'pRegle.max' => 'Le règlement dépasse le reste à payer ('.ae($this->avance['reste']).', avance déduite).',
    ], [
        'pDateReception' => 'date de réception', 'pSiteId' => 'site', 'pVilleId' => 'ville',
        'pClient' => 'client', 'pAssureur' => 'assureur', 'pCourtier' => 'courtier', 'pDeposeChez' => 'déposée chez',
        'pVehicule' => 'véhicule', 'pImmatriculation' => 'immatriculation',
        'pRegle' => 'montant réglé', 'pModeReglement' => 'mode de règlement', 'pDateReglement' => 'date de règlement',
    ]);

    if ($this->semblables->isNotEmpty() && ! $this->pPasLeMemeDossier) {
        $this->addError('pPasLeMemeDossier', "Une ligne de l'état porte déjà la même immatriculation et le même montant. "
            ."Vérifiez qu'il ne s'agit pas de la même facture, puis cochez la case pour confirmer.");

        return;
    }

    $regle = (int) ($donnees['pRegle'] ?? 0);

    $resultat = DB::transaction(function () use ($facture, $donnees, $regle) {
        $verrouillee = Facture::whereKey($facture->id)->lockForUpdate()->first();

        // Deux personnes peuvent porter la même facture au même moment : la seconde échoue.
        if ($verrouillee === null || $verrouillee->exercice_impayes !== null) {
            return ['factureId', "Cette facture vient d'être portée à l'état par quelqu'un d'autre."];
        }

        $reste = max(0, (int) $verrouillee->montant - (int) $verrouillee->encaissements()->sum('montant'));

        if ($regle > $reste) {
            return ['pRegle', 'Le règlement dépasse le reste à payer ('.ae($reste).').'];
        }

        $siteId = $donnees['pSiteId'] ? (int) $donnees['pSiteId'] : $verrouillee->site_id;
        $commentaire = mb_substr(trim((string) ($donnees['pCommentaires'] ?? '')), 0, 255);

        $immatriculation = mb_strtoupper(trim((string) ($donnees['pImmatriculation'] ?? '')));

        $verrouillee->fill([
            'exercice_impayes' => (int) $verrouillee->date->format('Y'),
            'est_etat_initial' => false,
            'date_reception' => $donnees['pDateReception'],
            'site_id' => $siteId,
            'ville_id' => $siteId === null && $donnees['pVilleId'] ? (int) $donnees['pVilleId'] : $verrouillee->ville_id,
            'client' => trim($donnees['pClient']),
            'assureur' => $donnees['pAssureur'] ?: null,
            'courtier' => $donnees['pCourtier'] ?: null,
            'depose_chez' => trim((string) $donnees['pDeposeChez']) ?: null,
            'vehicule' => $donnees['pVehicule'] ?: null,
            // L'immatriculation verrouillée a déjà été relue en base plus haut.
            'immatriculation' => $immatriculation ?: null,
            'n_sinistre' => $donnees['pSinistre'] ?: null,
            'banque' => $donnees['pBanque'] ?: null,
            /*
             * Le commentaire remplace : la case arrive remplie de ce que la facture portait,
             * et l'effacer doit vouloir dire l'effacer. C'est la différence avec la version
             * précédente, où l'on tapait une note en plus sans voir l'ancienne.
             */
            'observations' => $commentaire ?: null,
            // Un numéro de sinistre range la créance en Sinistre, comme à la saisie.
            'activite' => EtatDesImpayes::activiteDeduite($donnees['pSinistre'] ?? null),
        ])->save();

        if ($regle > 0) {
            Encaissement::create([
                'entreprise_id' => $verrouillee->entreprise_id,
                'site_id' => $verrouillee->site_id,
                'facture_id' => $verrouillee->id,
                'date' => $donnees['pDateReglement'],
                'montant' => $regle,
                'type' => 'Client',
                'moyen' => $donnees['pModeReglement'],
                'client' => $verrouillee->tiersPayant(),
                'activite' => $verrouillee->activite,
                'reference_origine' => $verrouillee->n_facture,
                'cree_par' => auth()->id(),
            ]);
        }

        activity()->causedBy(auth()->user())
            ->performedOn($verrouillee)
            ->withProperties([
                'reference' => $verrouillee->numero,
                'n_facture' => $verrouillee->n_facture,
                'provenance' => EtatDesImpayes::provenance($verrouillee),
                'date_reception' => $donnees['pDateReception'],
                'regle_declare' => $regle,
                'confirme_pas_un_doublon' => (bool) $this->pPasLeMemeDossier,
            ])
            ->log('État des impayés — facture portée');

        return $verrouillee;
    });

    if (is_array($resultat)) {
        $this->addError($resultat[0], $resultat[1]);

        return;
    }

    $this->dispatch('facture-portee',
        exercice: (int) $resultat->exercice_impayes,
        texte: 'Facture '.$resultat->numero.' (n° '.$resultat->n_facture.') portée à l\'état '.$resultat->exercice_impayes
            .($regle > 0 ? ' — le règlement de '.ae($regle).' entre aussitôt en trésorerie.' : '.'),
    );
};

?>

<div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
    <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Porter une facture existante à l'état</h3>
    <p style="font-size:12.5px; color:#6B6E76; margin:0 0 12px; line-height:1.55;">
        Pour une facture que l'application connaît déjà — <strong>importée du CATTC</strong>, saisie par
        l'atelier dans la <strong>saisie du jour</strong>, ou créée au <strong>recouvrement</strong>. Choisissez
        le client, puis la facture par son numéro de saisie : ce qui est connu se remplit tout seul. Il reste à
        dire <strong>quand elle a été déposée</strong> chez le client, et ce qui a déjà été payé — une facture
        du CATTC n'apporte aucun règlement.
    </p>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start;">
        <div style="width:300px;">
            <x-select-cherchable id="porter-client" label="Client" model="client" :valeur="$client"
                :options="$this->clients" vide="— choisir le client —" placeholder="Rechercher un client…" :seuil="0" />
        </div>
        {{-- La clé suit le client : changer de client reconstruit la liste des factures au lieu
             de la rapiécer, et son panneau de recherche repart des bons choix. --}}
        <div wire:key="porter-factures-{{ md5($client) }}" style="flex:1; min-width:300px; max-width:560px;">
            @if ($client === '')
                <label class="champ-libelle">Facture (numéro de saisie)</label>
                <div style="padding:9px 10px; font-size:13px; color:#6B6E76;">Choisissez d'abord le client.</div>
            @else
                <x-select-cherchable id="porter-facture-{{ md5($client) }}" label="Facture (numéro de saisie)" model="factureId"
                    :valeur="$factureId" :options="$this->factures" vide="— choisir la facture —"
                    placeholder="Numéro de saisie, n° de facture, immatriculation…" :seuil="0" />
                <div style="font-size:11.5px; color:#6B6E76; margin-top:3px;">{{ count($this->factures) }} facture(s) hors de l'état pour ce client.</div>
            @endif
            @error('factureId') <span class="champ-erreur">{{ $message }}</span> @enderror
        </div>
    </div>

    @if ($aPorter = $this->facture)
        @php $verrou = $this->verrouilles; $compte = $this->avance; @endphp

        {{-- D'où vient la facture, et où elle va. Le reste se lit dans les cases, comme à la
             saisie : c'est le même formulaire, rempli d'avance. --}}
        <div style="margin:14px 0 10px; padding:9px 12px; background:#FBF7EC; border-radius:8px; font-size:12.5px; line-height:1.6;">
            Référence <strong>{{ $aPorter->numero }}</strong> · Provenance : {{ EtatDesImpayes::provenance($aPorter) }}
            · Entrera dans l'état <strong>{{ $aPorter->date?->format('Y') }}</strong>.
            La <strong>date d'édition</strong>, le <strong>numéro</strong> et le <strong>montant</strong> ne se
            changent pas ici : ils appartiennent à l'écran qui a créé la facture.
            @if (in_array('immatriculation', $verrou, true))
                L'<strong>immatriculation</strong> non plus : c'est par elle que l'import reconnaît cette ligne.
            @endif
        </div>

        @if ($this->semblables->isNotEmpty())
            <div style="margin-bottom:12px; padding:10px 12px; border:1px solid #E7B85C; background:#FFF6E0; border-radius:8px; font-size:12.5px; line-height:1.55;">
                <strong>Attention — {{ $this->semblables->count() }} ligne(s) de l'état portent la même immatriculation et le même montant</strong>,
                peut-être la même facture reprise du classeur sous un autre numéro :
                @foreach ($this->semblables as $s)
                    <div>· {{ $s->numero }} — n° {{ $s->n_facture }} du {{ $s->date?->format('d/m/Y') }}, {{ $s->client }} (état {{ $s->exercice_impayes }})</div>
                @endforeach
                <div style="margin-top:6px;">
                    <x-champ type="checkbox" label="J'ai vérifié : ce n'est pas la même facture" model="pPasLeMemeDossier" />
                    @error('pPasLeMemeDossier') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
        @endif

        {{-- Les deux rangées de « Nouvelle créance », dans le même ordre et sous les mêmes mots :
             on ne change pas de formulaire parce qu'on change de porte d'entrée. --}}
        <form wire:submit.prevent="porter">
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:flex-start;">
                <x-champ label="ASSUREUR" model="pAssureur" width="150" />
                <x-champ label="Client" model="pClient" :requis="true" width="175" />
                <x-champ label="SITE" model="pSiteId" type="select" :options="$this->sitesSaisissables" vide="— à préciser —" width="155" />
                @if ($pSiteId === '')
                    <x-champ label="Ville (sans atelier)" model="pVilleId" type="select" :options="$this->villesSaisissables" vide="— à préciser —" width="150" />
                @endif
                <x-champ label="Courtier" model="pCourtier" width="150" />
                {{-- Facultatif, et décisif : renseigné, c'est lui qu'on relance. --}}
                <x-champ label="Déposée chez" model="pDeposeChez" width="160" />
                <x-champ-fige label="Date d'édition" :valeur="$aPorter->date?->format('d/m/Y')" width="140" />
                <x-champ label="Date de réception" model="pDateReception" type="date" :requis="true" width="140" />
                <x-champ-fige label="N° de la facture" :valeur="$aPorter->n_facture" width="135" />
                <x-champ label="Numéro Sinistre" model="pSinistre" width="145" />
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; align-items:flex-start;">
                <x-champ label="Vehicule" model="pVehicule" width="150" />
                <x-champ label="Immatriculation" model="pImmatriculation" width="140" :disabled="in_array('immatriculation', $verrou, true)" />
                <x-champ-fige label="montantTTC" :valeur="ae($aPorter->montant)" width="130" />
                {{-- L'avance et le reste : ce qui a déjà été payé sur cette facture est montré et
                     déduit, au lieu de porter une créance dont une partie est déjà en caisse. --}}
                <x-champ-fige label="Avance déjà encaissée" :valeur="ae($compte['avance'])" width="150"
                    :aide="$compte['avance'] > 0 ? 'déjà en caisse' : 'aucun règlement'" />
                <x-champ-fige label="Reste à payer" :valeur="ae($compte['reste'])" width="140" aide="montant TTC − avance" />
                <x-champ label="Nouveau règlement" model="pRegle" type="number" width="140" />
                <x-champ label="Modederèglement" model="pModeReglement" type="select" :options="$this->modes" vide="— aucun —" width="160" />
                <x-champ label="Datederèglement" model="pDateReglement" type="date" width="140" />
                <x-champ label="banque" model="pBanque" width="140" />
                <x-champ label="Commentaires" model="pCommentaires" width="185" />
            </div>

            <div style="display:flex; gap:10px; align-items:center;">
                <button type="submit" class="bouton" style="padding:9px 18px;" wire:loading.attr="disabled" wire:target="porter">Porter à l'état</button>
                <span wire:loading wire:target="porter" style="font-size:12.5px; color:#6B6E76;">Enregistrement…</span>
            </div>
        </form>
    @endif
</div>

