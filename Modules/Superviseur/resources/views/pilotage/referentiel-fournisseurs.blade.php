<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\ConditionsFournisseur;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Tracabilite\Services\JournalLisible;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Référentiel fournisseurs — à quel terme chacun se règle
|--------------------------------------------------------------------------
| **Une feuille qu'on ne lisait pas.** Les deux classeurs de suivi portent, à côté de
| `DETAIL`, une feuille « Liste fournisseurs » qui décrit les fournisseurs eux-mêmes : le
| terme de règlement négocié, l'assujettissement à la TVA, et pour six d'entre eux le
| plafond d'encours. On lisait les factures depuis un mois sans jamais ouvrir la page qui
| dit pour quand elles sont dues.
|
| **Ce que cela débloque.** Sur les deux fichiers réels, 7 171 pièces sur 7 350 ont
| désormais une fiche, et 5 184 gagnent une échéance qu'aucune ligne ne portait. La colonne
| *Échéance* de l'écran principal n'était pas vide par hasard : la réponse était dans le
| classeur, un onglet plus loin.
|
| **Deuxième tableau, et il vaut le premier.** Les fournisseurs facturés qui n'ont pas de
| fiche sont nommés, avec ce qu'on leur doit. Aucun rapprochement approché n'est tenté :
| « CFAO BABI » et « CFAO BABI MOTORS » se ressemblent, et se ressembler n'autorise pas à
| attribuer un délai de paiement. La liste est là pour être corrigée dans le classeur, où
| elle est tenue — pas pour être devinée ici.
|
| **Elle n'est plus en lecture seule depuis le 24/09.** On y lisait « la correction se fait
| dans la feuille « Liste fournisseurs » du classeur » — vrai, et insuffisant : le classeur
| est tenu ailleurs, par quelqu'un d'autre, et une correction qui suppose d'aller la demander
| ne se fait pas. Une fiche se corrige donc ici, et **chaque correction laisse sa trace** :
| qui, quoi, quand, depuis quelle adresse et quel poste.
|
| **Ce qui ne se corrige pas ici.** Le nom d'une fiche venue d'un classeur est verrouillé :
| c'est la clé qui la relie à ses factures, et la changer en orphelinerait une — le prochain
| dépôt recréerait la fiche sous l'ancien nom. Les jours et le « fin de mois » ne se
| saisissent pas non plus : ils sont la lecture du libellé, relue ici comme à l'import, sans
| quoi on pourrait écrire « Comptant » et « 30 jours » sur la même fiche.
*/

state(['recherche' => ''])->url(except: '');
state(['sansTerme' => false])->url(except: false);
state(['page' => 1]);

$updatedRecherche = function () { $this->page = 1; };
$updatedSansTerme = function () { $this->page = 1; };

/* La fiche en cours de correction, et les trois champs corrigeables. */
state(['ficheId' => null, 'nom' => '', 'delai' => '', 'tva' => '', 'note' => '']);

$peutEcrire = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

$fiche = computed(fn () => $this->ficheId === null
    ? null
    : FournisseurReferentiel::query()
        ->where('entreprise_id', auth()->user()->entreprise_id)
        ->find((int) $this->ficheId));

$corriger = function (int $id) {
    /* L'autorisation se revérifie ici, et pas seulement à la route : une action Livewire
       est appelable directement. Même règle d'écriture que le suivi fournisseur — le
       responsable d'atelier lit, il n'engage pas l'entreprise auprès d'un fournisseur. */
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $this->ficheId = $id;
    $fiche = $this->fiche;

    if ($fiche === null) {
        abort(404);
    }

    $this->nom = (string) $fiche->nom;
    $this->delai = (string) $fiche->delai_reglement;
    $this->tva = $fiche->assujetti_tva === null ? '' : ($fiche->assujetti_tva ? 'oui' : 'non');
    $this->note = (string) $fiche->note;
};

$annuler = function () {
    $this->ficheId = null;
    $this->resetValidation();
};

$enregistrer = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $fiche = $this->fiche;

    if ($fiche === null) {
        abort(404);
    }

    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:190'],
        'delai' => ['nullable', 'string', 'max:60'],
        'tva' => ['nullable', 'in:oui,non'],
        'note' => ['nullable', 'string', 'max:255'],
    ]);

    $fiche->corriger([
        'nom' => $donnees['nom'],
        'delai_reglement' => $donnees['delai'] ?: null,
        'assujetti_tva' => $donnees['tva'] === '' ? null : $donnees['tva'] === 'oui',
        'note' => $donnees['note'] ?: null,
    ]);

    $this->ficheId = null;
    unset($this->fiches, $this->totaux, $this->orphelins, $this->journal);

    $this->dispatch('annonce', texte: 'La fiche de '.$fiche->nom.' est corrigée.', ton: 'succes');
};

/**
 * Les dernières corrections faites à la main, toutes fiches confondues.
 *
 * Elles se lisent ensemble et non fiche par fiche : la question qu'on se pose devant un
 * référentiel corrigé est « qu'est-ce qui a bougé depuis la dernière fois ? », et non
 * « qu'a-t-on fait sur celui-ci ». Les trente dernières suffisent à y répondre.
 */
$journal = computed(fn () => Activity::query()
    ->with('causer')
    ->where('subject_type', (new FournisseurReferentiel)->getMorphClass())
    ->latest('id')
    ->limit(30)
    ->get());

$fiches = computed(function () {
    $requete = FournisseurReferentiel::query()
        ->where('entreprise_id', auth()->user()->entreprise_id);

    if (trim($this->recherche) !== '') {
        $requete->where('nom', 'like', '%'.trim($this->recherche).'%');
    }

    // « Sans terme » est le filtre utile : une fiche sans délai ne produit aucune
    // échéance, et c'est exactement la ligne du classeur qu'il faut aller compléter.
    if ($this->sansTerme) {
        $requete->whereNull('jours_reglement');
    }

    return $requete->orderBy('nom')->get();
});

$totaux = computed(function () {
    $toutes = FournisseurReferentiel::query()
        ->where('entreprise_id', auth()->user()->entreprise_id)
        ->get();

    return [
        'fiches' => $toutes->count(),
        'avecTerme' => $toutes->whereNotNull('jours_reglement')->count(),
        'tva' => $toutes->where('assujetti_tva', '===', true)->count(),
        'inconnue' => $toutes->whereNull('assujetti_tva')->count(),
    ];
});

/**
 * Les fournisseurs facturés que la liste ne connaît pas, dans le périmètre du lecteur.
 *
 * Le périmètre se lit du compte connecté, jamais d'un paramètre reçu : un responsable de
 * ville ne doit pas apprendre d'ici ce qu'une autre ville doit à qui.
 */
/**
 * Le dernier dépôt du suivi fournisseur, s'il y en a un.
 *
 * **Pourquoi cet écran en a besoin.** Un référentiel vide a deux causes qui n'appellent
 * pas la même réponse, et l'écran les confondait : ou bien rien n'a jamais été déposé, et
 * il faut déposer ; ou bien un classeur est bien entré **avant que la feuille « Liste
 * fournisseurs » ne soit lue** — c'est le cas au 24/09 : le dépôt date du 8 septembre, la
 * lecture de cette feuille a été écrite le 24 — et il suffit alors de relire ce dépôt, sans
 * redemander le fichier à personne.
 *
 * Dire « complétez le classeur » dans le second cas est un mauvais conseil : le classeur
 * est complet, c'est nous qui ne l'avions pas encore lu en entier.
 */
$dernierDepot = computed(fn () => LotImport::where('format', 'fournisseurs')
    ->where('etat', 'termine')
    ->latest('termine_le')
    ->first());

$orphelins = computed(fn () => ConditionsFournisseur::sansFiche(
    (int) auth()->user()->entreprise_id,
    EtatDesFournisseurs::dansLePerimetre(
        EtatDesFournisseurs::requete(EtatDesFournisseurs::exerciceOuvert((int) auth()->user()->entreprise_id)),
        PerimetreSites::idsVillesRetenus(auth()->user(), ''),
    ),
));

?>

<div>
    {{-- « Référentiel » est un mot de développeur : il dit comment la chose est rangée,
         pas ce qu'on y lit. Le propriétaire l'a relevé le 24/09 — il pensait y trouver la
         liste des fournisseurs sans facture. Le titre dit donc ce que la page porte : le
         terme de règlement et la TVA, fournisseur par fournisseur. --}}
    <x-titre-ecran titre="Conditions de règlement des fournisseurs"
        sous-titre="À quel terme chaque fournisseur se règle, et s'il facture la TVA — tels que la feuille « Liste fournisseurs » du classeur les déclare. C'est de là que vient l'échéance attendue d'une facture que le classeur n'a pas datée.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Suivi fournisseur</a>
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements</a>
        </div>
    </x-titre-ecran>

    @if (session('message'))
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #0E9F6E;">
            <p style="margin:0; font-size:13px; color:#1E7B34; font-weight:600;">{{ session('message') }}</p>
        </div>
    @endif

    @if ($this->fiche)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Corriger la fiche de {{ $this->fiche->nom }}</h3>

            @if ($this->fiche->champsVerrouilles() !== [])
                {{-- Le refus s'explique, il ne se grise pas en silence : un champ inactif
                     sans raison se lit comme une panne. --}}
                <p style="margin:0 0 12px; font-size:12.5px; color:#B9791C;">
                    Le <b>nom</b> ne se corrige pas ici : cette fiche vient d'un classeur, et son nom
                    est la clé qui la relie à ses factures. Le changer l'orphelinerait, et le prochain
                    dépôt en recréerait une sous l'ancien nom. La correction du nom se fait dans la
                    feuille « Liste fournisseurs », qui la reposera au dépôt suivant.
                </p>
            @endif

            <form wire:submit="enregistrer">
                <div style="display:flex; flex-wrap:wrap; gap:12px;">
                    @if ($this->fiche->champsVerrouilles() === [])
                        <x-champ label="Fournisseur" model="nom" :requis="true" width="260" />
                    @else
                        <x-champ-fige label="Fournisseur" :valeur="$this->fiche->nom" width="260" />
                    @endif

                    <x-champ label="Terme de règlement" model="delai" width="220"
                        placeholder="Comptant · 30 jours · 45 jours · 30 jours fin de mois" />

                    <x-champ label="TVA" model="tva" type="select" width="170"
                        :options="['oui' => 'Assujetti', 'non' => 'Non assujetti']" vide="non renseignée" />

                    <x-champ label="Note du classeur" model="note" width="320"
                        placeholder="Plafond d'encours, par exemple" />
                </div>

                {{-- L'échéance comptée n'est pas un champ : elle se relit du libellé, ici
                     comme à l'import. Deux saisies pour une même chose finissent par se
                     contredire, et personne ne saurait laquelle croire. --}}
                <p style="margin:10px 0 0; font-size:12.5px; color:#6B6E76;">
                    L'échéance attendue se déduit du terme écrit ci-dessus — elle ne se saisit pas.
                    Un terme qu'on ne sait pas lire est conservé tel quel et ne produit aucune date.
                </p>

                <div style="display:flex; gap:9px; margin-top:14px;">
                    <button type="submit" class="bouton">Enregistrer</button>
                    <button type="button" wire:click="annuler" class="bouton bouton-secondaire">Annuler</button>
                </div>
            </form>
        </div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:12px; margin-bottom:18px;">
        <x-kpi-card label="Fiches" :value="$this->totaux['fiches']" sub="Fournisseurs décrits" />
        <x-kpi-card label="Terme connu" :value="$this->totaux['avecTerme']"
            sub="Produisent une échéance attendue"
            :couleur="$this->totaux['avecTerme'] > 0 ? '#0E9F6E' : '#6B6E76'" />
        <x-kpi-card label="Assujettis à la TVA" :value="$this->totaux['tva']" />
        <x-kpi-card label="TVA non renseignée" :value="$this->totaux['inconnue']"
            sub="Le classeur ne le dit pas"
            :couleur="$this->totaux['inconnue'] > 0 ? '#D97706' : '#6B6E76'" />
    </div>

    <div class="carte">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
            <x-champ label="Rechercher un fournisseur" model="recherche" :live="true" placeholder="Nom du fournisseur…" />
            <x-champ label="Sans terme de règlement seulement" model="sansTerme" type="checkbox" live="true" />
        </div>

        @if ($this->totaux['fiches'] === 0)
            @if ($this->dernierDepot !== null)
                {{-- Le cas qu'on rencontre aujourd'hui, et que l'écran taisait : le classeur
                     est bien entré, mais avant que cette feuille-là ne soit lue. Le fichier
                     déposé est conservé — il n'y a rien à redemander, seulement à relire. --}}
                <div class="imp-hint warn" style="margin:0;">
                    <strong>Aucune fiche, et le classeur est pourtant déjà entré.</strong>
                    « {{ $this->dernierDepot->nom_fichier }} » a été lu le
                    {{ $this->dernierDepot->termine_le?->format('d/m/Y') }} — mais la feuille
                    « Liste fournisseurs » n'était pas encore lue à cette date : seules les
                    factures l'étaient.
                    <p style="margin:10px 0 0;">
                        <strong>Rien à redemander à personne.</strong> Le fichier déposé est conservé :
                        ouvrez ce dépôt dans le journal des imports et relancez sa lecture
                        (« Réimporter »). Les fiches arriveront avec, sans qu'aucune facture ne soit
                        écrite deux fois.
                    </p>
                    <p style="margin:10px 0 0;">
                        <a href="{{ route('import.lot', $this->dernierDepot->id) }}" wire:navigate
                           style="font-weight:700; color:#C8102E;">Ouvrir ce dépôt →</a>
                    </p>
                </div>
            @else
                <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                    Aucune fiche, et aucun classeur de suivi fournisseur n'a encore été déposé.
                    Elles arrivent avec lui, depuis le module <b>Import</b> : la feuille
                    « Liste fournisseurs » est lue en même temps que la feuille des factures,
                    en un seul dépôt.
                </p>
            @endif
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th>Terme de règlement</th>
                            <th>Échéance comptée</th>
                            <th>TVA</th>
                            <th>Note du classeur</th>
                            <th class="colonne-collee"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->fiches->forPage($page, 25) as $fiche)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $fiche->nom }}</td>
                                {{-- Le libellé du fichier, à la lettre : personne ne se
                                     reconnaîtrait dans une reformulation. --}}
                                <td>{{ $fiche->delai_reglement ?: '—' }}</td>
                                <td style="color:#6B6E76;">
                                    @if ($fiche->jours_reglement === null)
                                        {{-- Dire qu'on ne sait pas compter vaut mieux que
                                             de compter faux sans le dire. --}}
                                        <span style="color:#D97706;">non déduite</span>
                                    @elseif ($fiche->jours_reglement === 0 && ! $fiche->fin_de_mois)
                                        le jour de la facture
                                    @else
                                        {{ $fiche->jours_reglement }} j
                                        {{ $fiche->fin_de_mois ? 'après la fin du mois de facture' : 'après la facture' }}
                                    @endif
                                </td>
                                <td>
                                    @if ($fiche->assujetti_tva === null)
                                        <span style="color:#9A9DA5;">—</span>
                                    @else
                                        {{ $fiche->assujetti_tva ? 'Oui' : 'Non' }}
                                    @endif
                                </td>
                                <td style="color:#6B6E76; font-size:12.5px;">{{ $fiche->note ?: '—' }}</td>
                                <td class="colonne-collee" style="text-align:right;">
                                    @if ($this->peutEcrire)
                                        <button type="button" wire:click="corriger({{ $fiche->id }})"
                                            class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;">Corriger</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucune fiche ne correspond à ce filtre." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$page" :total="$this->fiches->count()" prop="page" :par-page="25" />

            <p style="margin:14px 0 0; font-size:12.5px; color:#6B6E76;">
                Le plafond d'encours est recopié tel qu'il est écrit, sans être converti en
                nombre : l'un des six porte « 10 00 000 », et deviner s'il s'agit d'un
                million ou de dix effacerait la faute au lieu de la montrer.
            </p>
        @endif
    </div>

    @if ($this->orphelins->isNotEmpty())
        <div class="carte" style="margin-top:18px;">
            <h3 style="margin:0 0 6px; font-size:15px;">Facturés, mais absents de la liste</h3>

            {{-- **Deux situations, et elles ne se disent pas dans les mêmes mots.**

                 Quand le référentiel est vide, *tous* les fournisseurs facturés y sont
                 absents : la liste ne signale alors rien du tout, elle recopie le tableau
                 des fournisseurs. Le texte qui parlait de « compléter le classeur » était
                 dans ce cas un mauvais conseil — le classeur est complet, c'est sa feuille
                 qui n'avait pas encore été lue. Relevé par le propriétaire le 24/09 :
                 « je ne comprends rien à ce texte ». --}}
            @if ($this->totaux['fiches'] === 0)
                <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                    Le référentiel étant vide, <b>tous</b> les fournisseurs facturés y figurent :
                    cette liste ne signale donc rien pour l'instant, elle redit le tableau des
                    fournisseurs. Elle reprendra son sens une fois la feuille
                    « Liste fournisseurs » lue — il ne restera alors que ceux qui y manquent
                    réellement, ou dont le nom s'y écrit autrement.
                </p>
            @else
                <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                    Ces fournisseurs ont des pièces en base et aucune fiche : <b>leurs factures
                    n'auront pas d'échéance attendue</b>, faute de terme de règlement connu.
                    Certains noms sont vraisemblablement une autre orthographe d'un fournisseur
                    déjà listé — l'application ne les rapproche pas d'elle-même, parce
                    qu'attribuer un délai de paiement sur une ressemblance est une décision qui
                    ne lui appartient pas.
                </p>
                <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                    <b>Deux façons de corriger.</b> Ajouter le fournisseur à la feuille
                    « Liste fournisseurs » du classeur, qui posera sa fiche au prochain dépôt ;
                    ou, quand c'est une orthographe qui diffère, corriger ici le nom de la fiche
                    existante pour qu'il colle à celui des factures. Chaque correction laisse sa
                    trace au journal, en bas de cette page.
                </p>
            @endif

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur facturé</th>
                            <th style="text-align:right;">Pièces</th>
                            <th style="text-align:right;">Reste dû</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->orphelins as $orphelin)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $orphelin['fournisseur'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $orphelin['pieces'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $orphelin['reste'] > 0 ? '#C8102E' : '#6B6E76' }};">
                                    {{ ae($orphelin['reste']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($this->journal->isNotEmpty())
        <div class="carte" style="margin-top:18px;">
            <h3 style="margin:0 0 6px; font-size:15px;">Ce qui a été corrigé à la main</h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                Une fiche corrigée ici ne vient plus seulement du classeur : sans trace, plus personne
                ne saurait d'où sort un terme de règlement. Le « poste » est ce que le navigateur
                déclare de lui-même — un serveur ne connaît pas le nom de la machine qui l'appelle.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Quand</th>
                            <th>Qui</th>
                            <th>Fiche</th>
                            <th>Ce qui a changé</th>
                            <th class="colonne-collee">D'où</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->journal as $trace)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ $trace->created_at?->format('d/m/Y à H:i') }}</td>
                                <td>{{ $trace->causer?->name ?? 'import' }}</td>
                                <td>{{ $trace->properties['attributes']['nom'] ?? $trace->properties['old']['nom'] ?? '—' }}</td>
                                <td style="font-size:12.5px;">
                                    <x-journal-changements
                                        :changements="JournalLisible::changements($trace)"
                                        :creation="JournalLisible::estUneCreation($trace)" />
                                </td>
                                <td class="colonne-collee" style="font-size:11.5px; color:#6B6E76;">
                                    {{ $trace->properties['ip'] ?? '—' }}
                                    @if ($trace->properties['poste'] ?? null)
                                        <div title="{{ $trace->properties['poste'] }}">
                                            {{ \Modules\Noyau\Tracabilite\Services\SignatureDeDecision::posteLisible($trace->properties['poste']) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
