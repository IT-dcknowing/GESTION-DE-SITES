<?php

use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\ReconnaissanceDeBanque;
use Modules\Noyau\Exploitation\Services\SupportDeReglement;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Banques — ce qui est passé par chaque compte
|--------------------------------------------------------------------------
| **Le pendant de la caisse.** L'argent passe par un tiroir ou par un compte ; les deux
| écrans répondent à la même question sur deux supports, et tous deux s'ouvrent depuis la
| trésorerie, qui les additionne.
|
| **La ligne de boutons, telle que demandée le 01/10.** « Toutes les banques », puis une par
| banque de l'entreprise — *« et si on a ORANGE MONEY mets-le, si on a MTN money mets-le ; et
| pour le dernier, ceux dont le mode n'a pas été déclaré, mets Moyen non précisé »*.
|
| Trois sortes de boutons, donc, et elles ne naissent pas de la même façon :
|
| | Bouton | D'où il vient | Paraît |
| |---|---|---|
| | une banque | d'une fiche déclarée à la main | **toujours**, même sans une seule écriture |
| | un portefeuille mobile | d'un moyen trouvé dans les écritures | dès qu'une écriture le porte |
| | Moyen non précisé | des écritures sans moyen lisible | dès qu'il y en a une |
|
| **Une banque déclarée paraît même vide**, et c'est voulu : on la crée *avant* d'y encaisser,
| et un bouton qui n'apparaîtrait qu'une fois la première écriture passée laisserait croire que
| la déclaration n'a pas pris.
|
| **Ce sont nos comptes, pas ceux des autres.** La colonne « banque émettrice » d'un relevé
| désigne la banque du chèque **reçu** ; elle n'a rien à faire ici. Ce que cet écran range,
| c'est l'argent qui entre sur **nos** comptes.
|
| **L'origine se filtre, elle ne fait pas de boutons.** *« Au niveau de chacune des banques
| ajoute un filtre pour pouvoir trier ce qui est saisi dans l'application, ce qui est importé,
| et les deux à la fois — au lieu de venir mettre des boutons. »* Les boutons disent *où* est
| l'argent ; l'origine dit *d'où vient la ligne*. Deux questions, deux formes.
*/

state(['villeFiltre' => ''])->url(except: '');
state(['supportFiltre' => ''])->url(except: '');
state(['origineFiltre' => ''])->url(except: '');
state(['recherche' => '']);
state(['page' => 1]);
state(['filtresLibres' => []]);

/* La période, comme sur les autres écrans d'argent. */
state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
]);

/*
 * La déclaration d'une banque, repliée tant qu'on ne la demande pas.
 *
 * **Sans ville, et c'est une correction du 01/10** : « retire le champ ville au niveau du
 * formulaire, car la banque créée devra s'afficher en liste déroulante partout ». Une banque
 * rattachée à une ville ne se proposerait pas aux autres, alors que le compte sert
 * l'entreprise entière — et c'est ce que le logiciel comptable fait déjà : il demande le
 * compte sans demander de site.
 */
state(['formulaireOuvert' => false, 'nom' => '', 'code' => '', 'note' => '']);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedVilleFiltre = function () { $this->page = 1; };
$updatedSupportFiltre = function () { $this->page = 1; };
$updatedOrigineFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };
$updatedFiltresLibres = function () { $this->page = 1; };
$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->page = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->page = 1; };
$updatedJourFiltre = function () { $this->page = 1; };
$updatedPeriode = function () { $this->page = 1; };
$updatedDateDebut = function () { $this->page = 1; };
$updatedDateFin = function () { $this->page = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

/* Les villes **en modèles** : `x-filtre-periode` lit `$ville->id`. */
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, ''));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

/** Le gérant déclare les banques ; les autres les lisent. Vérifié ici **et** dans l'action. */
$peutDeclarer = computed(fn () => auth()->user()->hasRole('gerant'));

$banques = computed(fn () => Banque::query()->where('est_active', true)->orderBy('nom')->get());

// ------------------------------------------------------------------ ce que la page lit

/**
 * Les entrées et les sorties qui ne sont pas passées par le tiroir.
 *
 * **Le support fait le tri avant la banque**, et c'est l'ordre juste : un règlement en espèces
 * n'a pas de compte, et sa facture peut pourtant porter une banque — celle du chèque reçu la
 * fois d'avant. Les ranger ici gonflerait un compte de ce qui n'y est jamais passé.
 */
$entreesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return PerimetreDeTresorerie::encaissements(
        Encaissement::query(), $this->idsSites, $this->idsVilles,
    )
        ->whereBetween('encaissements.date', [$debut, $fin])
        ->whereNotIn('encaissements.moyen', SupportDeReglement::moyensDuSupport('encaissements', SupportDeReglement::CAISSE));
});

$sortiesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return PerimetreDeTresorerie::charges(Charge::query(), $this->idsSites)
        ->whereBetween('charges.date', [$debut, $fin])
        ->whereNotIn('charges.moyen', SupportDeReglement::moyensDuSupport('charges', SupportDeReglement::CAISSE));
});

/**
 * Les libellés de banque trouvés sur les créances, et ce que chacun porte.
 *
 * Compté par la base en une requête : un `join` sur les factures, un `group by` sur leur
 * colonne `banque`. Quatorze lignes reviennent, pas sept mille.
 */
$libelles = computed(fn () => (clone $this->entreesQ)
    ->leftJoin('factures', 'factures.id', '=', 'encaissements.facture_id')
    ->selectRaw('factures.banque as libelle, count(*) as nombre, sum(encaissements.montant) as montant')
    ->groupBy('factures.banque')
    ->get());

/** Les libellés rangés sous la banque qu'ils désignent, et ceux qu'on ne sait pas ranger. */
$repartition = computed(function () {
    $banques = $this->banques;
    $parBanque = [];
    $nonRanges = [];

    foreach ($this->libelles as $ligne) {
        $verdict = ReconnaissanceDeBanque::pour($ligne->libelle, $banques);

        // Seule la certitude range. Une ressemblance **propose** — la poser d'office
        // rangerait des écritures sous une banque qui n'est pas la bonne, et rien ne le
        // dirait ensuite.
        if ($verdict['verdict'] === ReconnaissanceDeBanque::CERTAINE) {
            $id = $verdict['banque']->id;

            $parBanque[$id] ??= ['nombre' => 0, 'montant' => 0, 'libelles' => []];
            $parBanque[$id]['nombre'] += (int) $ligne->nombre;
            $parBanque[$id]['montant'] += (int) $ligne->montant;
            $parBanque[$id]['libelles'][] = (string) $ligne->libelle;

            continue;
        }

        $nonRanges[] = [
            'libelle' => $ligne->libelle,
            'nombre' => (int) $ligne->nombre,
            'montant' => (int) $ligne->montant,
            'raison' => $verdict['raison'],
        ];
    }

    return ['parBanque' => $parBanque, 'nonRanges' => $nonRanges];
});

/**
 * Les portefeuilles mobiles réellement employés, et ce qu'ils portent.
 *
 * Lus dans les écritures et non écrits à la main : le jour où un quatrième opérateur
 * apparaît, son bouton paraît sans qu'on y touche. Et le jour où l'on cesse de s'en servir,
 * il disparaît — un bouton qui ne mène à rien use la confiance qu'on porte aux autres.
 */
$portefeuilles = computed(function () {
    $parMoyen = [];

    $compter = function ($requete, string $table, string $signe) use (&$parMoyen) {
        $groupes = (clone $requete)
            ->selectRaw($table.'.moyen as moyen, count(*) as nombre, sum('.$table.'.montant) as montant')
            ->groupBy($table.'.moyen')
            ->get();

        foreach ($groupes as $groupe) {
            if (SupportDeReglement::pour($groupe->moyen) !== SupportDeReglement::MOBILE) {
                continue;
            }

            $cle = (string) $groupe->moyen;
            $parMoyen[$cle] ??= ['libelle' => $cle, 'nombre' => 0, 'montant' => 0];
            $parMoyen[$cle]['nombre'] += (int) $groupe->nombre;
            $parMoyen[$cle]['montant'] += $signe === '+' ? (int) $groupe->montant : -(int) $groupe->montant;
        }
    };

    $compter($this->entreesQ, 'encaissements', '+');
    $compter($this->sortiesQ, 'charges', '-');

    ksort($parMoyen);

    return $parMoyen;
});

/** Ce que les écritures sans moyen lisible pèsent — le dernier bouton, s'il a lieu d'être. */
$nonPrecise = computed(function () {
    $entrees = SupportDeReglement::appliquer(
        clone $this->entreesQ, 'encaissements', SupportDeReglement::INCONNU,
    );
    $sorties = SupportDeReglement::appliquer(
        clone $this->sortiesQ, 'charges', SupportDeReglement::INCONNU,
    );

    return [
        'nombre' => (clone $entrees)->count() + (clone $sorties)->count(),
        'montant' => (int) (clone $entrees)->sum('encaissements.montant')
            - (int) (clone $sorties)->sum('charges.montant'),
    ];
});

/**
 * La ligne de boutons, dans l'ordre demandé.
 *
 * @return array<string, array{libelle: string, montant: int, nombre: int, sorte: string, vide: bool}>
 */
$comptes = computed(function () {
    $comptes = [];

    foreach ($this->banques as $banque) {
        $part = $this->repartition['parBanque'][$banque->id] ?? ['nombre' => 0, 'montant' => 0];

        $comptes['b'.$banque->id] = [
            'libelle' => $banque->nom,
            'montant' => (int) $part['montant'],
            'nombre' => (int) $part['nombre'],
            'sorte' => 'banque',
            // Une banque déclarée paraît même sans écriture : on la crée avant d'y encaisser.
            'vide' => $part['nombre'] === 0,
        ];
    }

    foreach ($this->portefeuilles as $moyen => $part) {
        $comptes['m'.md5($moyen)] = [
            'libelle' => $part['libelle'],
            'montant' => $part['montant'],
            'nombre' => $part['nombre'],
            'sorte' => 'mobile',
            'vide' => false,
        ];
    }

    if ($this->nonPrecise['nombre'] > 0) {
        $comptes['inconnu'] = [
            'libelle' => 'Moyen non précisé',
            'montant' => $this->nonPrecise['montant'],
            'nombre' => $this->nonPrecise['nombre'],
            'sorte' => 'inconnu',
            'vide' => false,
        ];
    }

    return $comptes;
});

$compteChoisi = computed(fn () => $this->comptes[$this->supportFiltre] ?? null);

// ------------------------------------------------------------------ le tableau

/**
 * Les règlements du compte choisi, avec l'origine demandée.
 *
 * L'origine se lit sur `lot_import_id` : une ligne qui porte un lot vient d'un fichier, une
 * ligne qui n'en porte pas a été tapée ici. C'est la seule chose qui les distingue en base,
 * et c'est suffisant.
 */
$requete = computed(function () {
    $requete = clone $this->entreesQ;
    $choisi = $this->supportFiltre;

    if ($choisi !== '' && isset($this->comptes[$choisi])) {
        $compte = $this->comptes[$choisi];

        if ($compte['sorte'] === 'banque') {
            $id = (int) substr($choisi, 1);
            $libelles = $this->repartition['parBanque'][$id]['libelles'] ?? ['__aucun__'];

            $requete->whereHas('facture', fn ($f) => $f->whereIn('banque', $libelles));
        } elseif ($compte['sorte'] === 'mobile') {
            $requete->where('encaissements.moyen', $compte['libelle']);
        } else {
            $requete = SupportDeReglement::appliquer($requete, 'encaissements', SupportDeReglement::INCONNU);
        }
    }

    return FiltreLibre::appliquer(
        $requete
            ->when($this->origineFiltre === 'import', fn ($q) => $q->whereNotNull('encaissements.lot_import_id'))
            ->when($this->origineFiltre === 'saisie', fn ($q) => $q->whereNull('encaissements.lot_import_id'))
            ->when(trim($this->recherche) !== '', function ($q) {
                $terme = '%'.trim($this->recherche).'%';

                $q->where(fn ($sous) => $sous
                    ->where('encaissements.client', 'like', $terme)
                    ->orWhere('encaissements.numero', 'like', $terme)
                    ->orWhere('encaissements.reference_origine', 'like', $terme)
                    ->orWhere('encaissements.motif', 'like', $terme));
            }),
        $this->colonnesFiltrables,
        (array) $this->filtresLibres,
    );
});

/**
 * Les colonnes du tableau qu'aucun filtre du haut ne couvre.
 *
 * Ni la banque ni l'origine n'y figurent : elles ont leurs propres commandes, et les proposer
 * deux fois laisserait poser deux conditions contradictoires sur la même donnée.
 */
$colonnesFiltrables = computed(fn () => [
    'encaissements.client' => FiltreLibre::colonne('Client'),
    'encaissements.numero' => FiltreLibre::colonne('N° de règlement'),
    'encaissements.reference_origine' => FiltreLibre::colonne('Référence (chèque, transaction)'),
    'encaissements.motif' => FiltreLibre::colonne('Motif'),
    'encaissements.type' => FiltreLibre::colonne('Type'),
    'encaissements.montant' => FiltreLibre::colonne('Montant', 'nombre'),
    'encaissements.date' => FiltreLibre::colonne('Date du règlement', 'date'),
]);

$kpis = computed(fn () => [
    'montant' => (int) (clone $this->requete)->sum('encaissements.montant'),
    'nombre' => (clone $this->requete)->count(),
    'clients' => (clone $this->requete)->distinct()->count('encaissements.client'),
]);

$lignes = computed(fn () => (clone $this->requete)
    ->with(['facture', 'site.ville'])
    ->orderByDesc('encaissements.date')
    ->orderByDesc('encaissements.id')
    ->forPage($this->page, 25)
    ->get());

$total = computed(fn () => (clone $this->requete)->count());

// ------------------------------------------------------------------ déclarer une banque

$ouvrirLaDeclaration = function (?string $nomPropose = null) {
    $this->formulaireOuvert = true;
    $this->nom = $nomPropose ? Banque::formePresentable($nomPropose) : '';
    $this->code = '';
    $this->note = '';
    $this->resetErrorBag();
};

$fermerLaDeclaration = function () {
    $this->formulaireOuvert = false;
    $this->fill(['nom' => '', 'code' => '', 'note' => '']);
    $this->resetErrorBag();
};

/**
 * Déclare une banque.
 *
 * **Le droit est revérifié ici**, et pas seulement sur la route : une route ne protège que
 * l'entrée, et une action Livewire s'appelle depuis le navigateur.
 *
 * **Le nom est unique par sa forme réduite**, pas par sa lettre : « BGFI » et « B.G.F.I » sont
 * le même établissement, et deux fiches couperaient ses totaux en deux.
 */
$declarerLaBanque = function () {
    abort_unless($this->peutDeclarer, 403, 'La déclaration des banques est réservée au gérant.');

    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:120'],
        'code' => ['nullable', 'string', 'max:16'],
        'note' => ['nullable', 'string', 'max:500'],
    ], attributes: ['nom' => 'nom de la banque']);

    $cle = Banque::clePour($donnees['nom']);

    if ($cle === '') {
        $this->addError('nom', "Ce nom ne porte aucune lettre : ce n'est pas un nom de banque.");

        return;
    }

    if (Banque::query()->where('nom_normalise', $cle)->exists()) {
        $this->addError('nom', 'Cette banque est déjà déclarée : ses règlements se rejoindraient sur deux fiches.');

        return;
    }

    Banque::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'nom' => Banque::formePresentable($donnees['nom']),
        'nom_normalise' => $cle,
        'code' => $donnees['code'] ? mb_strtoupper(trim($donnees['code'])) : null,
        'note' => $donnees['note'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    unset($this->banques, $this->repartition, $this->libelles, $this->comptes);

    $this->fermerLaDeclaration();

    session()->flash('message', 'La banque est déclarée. Elle paraît aussitôt dans la ligne ci-dessous, '
        .'et dans les listes de l’import et des saisies.');
};

?>

<div>
    <x-titre-ecran titre="Banques"
        sous-titre="Ce qui est passé par chaque compte : les règlements reçus autrement qu'en espèces.">
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            @if ($this->peutDeclarer)
                <button type="button" wire:click="ouvrirLaDeclaration" class="bouton" style="padding:8px 14px;">
                    + Déclarer une banque
                </button>
            @endif

            <a href="{{ route('tresorerie') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; text-decoration:none; margin-left:auto;">← Retour à la trésorerie</a>
        </div>
    </x-titre-ecran>

    @if (session('message'))
        <div class="encart encart-succes" style="margin-bottom:16px;">{{ session('message') }}</div>
    @endif

    @if ($formulaireOuvert)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Déclarer une banque</h3>

            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="Nom de la banque" model="nom" :requis="true" width="260"
                    placeholder="BGFI, BNI, BDA…" />
                <x-champ label="Code court" model="code" width="130" placeholder="Facultatif" />
                <x-champ label="Note" model="note" width="280" placeholder="Facultatif" />

                <button type="button" wire:click="declarerLaBanque" class="bouton">Déclarer</button>
                <button type="button" wire:click="fermerLaDeclaration" class="bouton bouton-secondaire">Annuler</button>
            </div>

            <p style="margin:10px 0 0; font-size:12.5px; color:#6B6E76; line-height:1.55;">
                Le nom est enregistré en capitales, et deux écritures du même établissement se
                rejoignent sur leur forme réduite : « BGFI » et « B.G.F.I » sont la même banque.
                <b>Pas de ville</b> : le compte sert l'entreprise entière, et il doit se proposer
                partout — à l'import comme aux saisies du recouvrement et des impayés.
            </p>

            <x-erreurs-du-bloc prefixe="nom" />
            <x-erreurs-du-bloc prefixe="code" />
        </div>
    @endif

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin"
        :villes="$this->mesVilles" :ville-unique="$this->villeUnique" :ville-filtre="$villeFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        masquer-activite />

    {{-- ─────────────────────────────── la ligne des comptes

         Trois sortes de boutons : les banques déclarées — qui paraissent **même vides** —, les
         portefeuilles mobiles trouvés dans les écritures, et le reliquat des moyens illisibles.
         Chacun dit ce qu'il porte, pour qu'on sache avant de cliquer. --}}
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
        @php $totalGeneral = collect($this->comptes)->sum('montant'); @endphp

        <button type="button" wire:click="$set('supportFiltre', '')"
            class="bouton {{ $supportFiltre === '' ? '' : 'bouton-secondaire' }}"
            @if ($supportFiltre === '') aria-current="true" @endif
            style="padding:9px 16px;">
            Toutes les banques
            <span style="opacity:.72; font-weight:600;">({{ ae($totalGeneral) }})</span>
        </button>

        @foreach ($this->comptes as $cle => $compte)
            @php $actif = (string) $supportFiltre === (string) $cle; @endphp
            <button type="button" wire:click="$set('supportFiltre', '{{ $actif ? '' : $cle }}')"
                class="bouton {{ $actif ? '' : 'bouton-secondaire' }}"
                @if ($actif) aria-current="true" @endif
                @if ($compte['vide']) title="Déclarée, aucune écriture sur cette période" @endif
                style="padding:9px 16px; {{ $compte['vide'] && ! $actif ? 'opacity:.62;' : '' }}">
                {{ $compte['libelle'] }}
                <span style="opacity:.72; font-weight:600;">
                    {{ $compte['vide'] ? '(—)' : '('.ae($compte['montant']).')' }}
                </span>
            </button>
        @endforeach

        @if ($this->banques->isEmpty())
            <span style="font-size:13px; color:#6B6E76; align-self:center;">
                Aucune banque déclarée — les libellés trouvés sont listés plus bas.
            </span>
        @endif
    </div>

    {{-- ─────────────────────────────── l'origine, en filtre et non en boutons

         Demandé le 01/10. Les boutons du dessus disent **où** est l'argent ; ce filtre dit
         **d'où vient la ligne**. Deux questions, deux formes — et les mêmes trois valeurs que
         sur l'écran Caisse, pour qu'on ne les réapprenne pas. --}}
    <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center; margin-bottom:16px;">
        <span style="font-size:12.5px; color:#6B6E76;">Origine</span>
        <select wire:model.live="origineFiltre" class="champ" style="width:auto;">
            <option value="" @selected($origineFiltre === '')>Importé et saisi</option>
            <option value="import" @selected($origineFiltre === 'import')>Importé seulement</option>
            <option value="saisie" @selected($origineFiltre === 'saisie')>Saisi dans l’application</option>
        </select>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Encaissé — {{ $this->compteChoisi['libelle'] ?? 'tous les comptes' }}"
            :value="ae($this->kpis['montant'])" couleur="#0E9F6E" :sub="$this->libellePerimetre" />
        <x-kpi-card label="Règlements" :value="number_format($this->kpis['nombre'], 0, ',', ' ')"
            sub="Hors espèces" />
        <x-kpi-card label="Clients distincts" :value="number_format($this->kpis['clients'], 0, ',', ' ')" />
    </div>

    {{-- ─────────────────────────────── ce qu'on ne sait pas ranger

         **Rendu à part plutôt que fondu dans un total.** C'est la liste à corriger, et elle
         vaut mieux qu'un total faux qui ne dit rien. Chaque ligne porte la raison du refus :
         une faute de frappe ne se corrige pas comme deux banques dans une même case. --}}
    @if ($this->repartition['nonRanges'] !== [])
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #C8102E;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">
                {{ count($this->repartition['nonRanges']) }} libellé(s) ne sont rangés sous aucune banque
            </h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.55;">
                Ils ne sont comptés dans aucun bouton ci-dessus. Les ranger au jugé fausserait des
                totaux sans que rien ne le signale.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Libellé trouvé</th>
                            <th style="text-align:right;">Règlements</th>
                            <th style="text-align:right;">Montant</th>
                            <th>Pourquoi</th>
                            @if ($this->peutDeclarer)
                                <th class="colonne-collee"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->repartition['nonRanges'] as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $ligne['libelle'] ?: '— vide —' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $ligne['nombre'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne['montant']) }}</td>
                                <td style="font-size:12.5px; color:#6B6E76;">{{ $ligne['raison'] }}</td>
                                @if ($this->peutDeclarer)
                                    <td class="colonne-collee" style="white-space:nowrap;">
                                        @if ($ligne['libelle'])
                                            <button type="button" class="bouton bouton-secondaire"
                                                style="padding:3px 9px; font-size:11.5px;"
                                                wire:click="ouvrirLaDeclaration('{{ addslashes($ligne['libelle']) }}')">
                                                Déclarer
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                Règlements ({{ number_format($this->total, 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Client, n° de règlement…" class="champ"
                    style="width:auto; min-width:0; flex:0 1 230px;">

                <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>N° de règlement</th>
                        <th>Client</th>
                        <th>Moyen</th>
                        <th>Banque notée</th>
                        <th>Origine</th>
                        <th class="colonne-collee" style="text-align:right;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->numero ?: '—' }}</td>
                            <td>{{ $ligne->client ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->moyen ?: '—' }}</td>
                            {{-- « Banque notée » et non « Banque » : c'est ce que quelqu'un a
                                 écrit sur la créance, pas ce qu'un relevé confirme. --}}
                            <td style="color:#6B6E76;">{{ $ligne->facture?->banque ?: '—' }}</td>
                            <td style="font-size:11.5px; color:#6B6E76;">
                                {{ $ligne->lot_import_id === null ? 'Saisi ici' : 'Importé' }}
                            </td>
                            <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">
                                {{ ae((int) $ligne->montant) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="7" texte="Aucun règlement sur cette période pour ce compte." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->total" prop="page" :par-page="25" />
    </div>
</div>
