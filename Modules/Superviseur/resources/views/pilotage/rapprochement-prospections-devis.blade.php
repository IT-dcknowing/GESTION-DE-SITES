<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\RapprochementDevisFacture;
use Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Rapprochement — prospection / devis
|--------------------------------------------------------------------------
| **Le constat, mesuré le 21/09/2026.** 2 673 devis en base, 241 rattachés à une
| prospection : 9 %. Les 2 432 autres viennent de l'import de l'atelier, et rien ne dit
| quel commercial a décroché l'affaire. Le commercial ne voit donc pas son devis, et la
| prospection reste « sans suite » alors qu'elle en a eu une.
|
| **La clé retenue : immatriculation + date.** Le propriétaire a tranché le 18/09 : « le
| devis ne se fait pas toujours au même moment que la prospection, il sera difficile de
| revenir mettre le numéro de fiche de réception sur une prospection qui a eu lieu 3 ou 5
| jours après ». Le n° de fiche reste la preuve la plus forte quand il est là, il n'est
| jamais la condition.
|
| **Cet écran propose, il ne décide pas.** Chaque ligne se confirme ou s'écarte d'un clic,
| et le refus se garde — sans quoi la liste reproposerait indéfiniment ce qu'on vient de
| refuser. Le bouton « Confirmer les certains » ne touche que les rapprochements par fiche
| ou par plaque, et seulement ceux affichés : deux voitures ne partagent pas de plaque,
| deux clients partagent un nom.
*/

state(['fenetre' => RapprochementProspectionDevis::FENETRE_EN_JOURS])->url(except: RapprochementProspectionDevis::FENETRE_EN_JOURS);
state(['volet' => 'prospections'])->url(except: 'prospections');
state(['fenetreFacture' => RapprochementDevisFacture::FENETRE_EN_JOURS])->url(except: RapprochementDevisFacture::FENETRE_EN_JOURS);
state(['motifFiltre' => ''])->url(except: '');
state(['villeFiltre' => ''])->url(except: '');
state(['message' => '']);
state(['erreur' => '']);

$updatedFenetre = function () { $this->oublier(); };
$updatedFenetreFacture = function () { $this->oublier(); };
$updatedVolet = function () { $this->motifFiltre = ''; $this->oublier(); };
$updatedMotifFiltre = function () { $this->oublier(); };
$updatedVilleFiltre = function () { $this->oublier(); };

/** Les listes du bandeau, ramenées à ce qu'attend une liste déroulante. */
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user())?->pluck('nom', 'id')->all() ?? []);
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));

/**
 * Le périmètre de lecture : celui de l'identité du compte, réduit par le filtre de ville.
 *
 * Jamais un identifiant reçu du navigateur. Les actions relisent d'ailleurs leur périmètre
 * **sans** le filtre : un filtre règle l'affichage, il ne donne pas de droits.
 */
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, ''));
$idsSitesDuCompte = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), '', ''));

$propositions = computed(function () {
    $lignes = RapprochementProspectionDevis::propositions($this->idsSites, (int) $this->fenetre);

    if ($this->motifFiltre !== '') {
        $lignes = $lignes->where('motif', $this->motifFiltre)->values();
    }

    return $lignes;
});

/**
 * Le second maillon : les factures qui n'ont pas retrouvé leur devis.
 *
 * Même forme que le premier, et pour la même raison : le devis donne le commercial, la
 * facture le porte jusqu'au barème. L'un sans l'autre ne rémunère personne.
 */
$propositionsFactures = computed(function () {
    $lignes = RapprochementDevisFacture::propositions($this->idsSites, (int) $this->fenetreFacture);

    if ($this->motifFiltre !== '') {
        $lignes = $lignes->where('motif', $this->motifFiltre)->values();
    }

    return $lignes;
});

/** Combien de factures ne sont comptées à personne — le chiffre qui a motivé ce volet. */
$couvertureDesFactures = computed(function () {
    $base = Facture::query()->whereIn('site_id', $this->idsSites);

    return [
        'total' => (clone $base)->count(),
        'sansCommercial' => (clone $base)->whereNull('commercial_id')->count(),
    ];
});

/** Combien de prospections attendent encore leur devis — le reste à traiter, filtres compris. */
$enAttente = computed(fn () => Prospection::query()
    ->whereIn('site_id', $this->idsSites)
    ->where('statut_validation', 'Validée')
    ->whereDoesntHave('devis')
    ->count());

/** Combien de devis n'ont encore aucun commercial à qui être comptés. */
$devisOrphelins = computed(fn () => Devis::query()
    ->whereIn('site_id', $this->idsSites)
    ->whereNull('prospection_id')
    ->count());

$oublier = protect(function () {
    unset($this->propositions, $this->enAttente, $this->devisOrphelins,
        $this->propositionsFactures, $this->couvertureDesFactures);
    $this->message = '';
    $this->erreur = '';
});

$confirmer = function (int $prospectionId, int $devisId) {
    $refus = RapprochementProspectionDevis::confirmer(
        auth()->user(), $prospectionId, $devisId, $this->idsSitesDuCompte,
    );

    $this->oublier();

    if ($refus !== null) {
        $this->erreur = $refus;

        return;
    }

    $this->message = 'Rapprochement confirmé : le devis est porté au compte du commercial de la prospection.';
};

$ecarter = function (int $prospectionId, int $devisId) {
    $refus = RapprochementProspectionDevis::ecarter(
        auth()->user(), $prospectionId, $devisId, $this->idsSitesDuCompte,
    );

    $this->oublier();

    if ($refus !== null) {
        $this->erreur = $refus;

        return;
    }

    $this->message = 'Écarté : ce couple ne sera plus proposé.';
};

$confirmerLaFacture = function (int $factureId, int $devisId) {
    $refus = RapprochementDevisFacture::confirmer(
        auth()->user(), $factureId, $devisId, $this->idsSitesDuCompte,
    );

    $this->oublier();

    if ($refus !== null) {
        $this->erreur = $refus;

        return;
    }

    $this->message = 'Rapprochement confirmé : la facture est désormais comptée au commercial du devis.';
};

$ecarterLaFacture = function (int $factureId, int $devisId) {
    $refus = RapprochementDevisFacture::ecarter(
        auth()->user(), $factureId, $devisId, $this->idsSitesDuCompte,
    );

    $this->oublier();

    if ($refus !== null) {
        $this->erreur = $refus;

        return;
    }

    $this->message = 'Écarté : ce couple ne sera plus proposé.';
};

/** Comme pour le premier maillon : seulement ce qui ne s'interprète pas. */
$confirmerLesFacturesCertaines = function () {
    $faits = 0;
    $refuses = 0;

    foreach ($this->propositionsFactures->whereIn('motif', RapprochementDevisFacture::MOTIFS_CERTAINS) as $ligne) {
        $refus = RapprochementDevisFacture::confirmer(
            auth()->user(), $ligne['facture']->id, $ligne['devis']->id, $this->idsSitesDuCompte,
        );

        $refus === null ? $faits++ : $refuses++;
    }

    $this->oublier();

    $this->message = $faits === 0
        ? 'Aucun rapprochement certain à confirmer dans cette liste.'
        : $faits.' facture(s) rattachée(s)'.($refuses > 0 ? ', '.$refuses.' écartée(s) par un contrôle.' : '.');
};

/**
 * Confirmer d'un coup les rapprochements qui ne s'interprètent pas.
 *
 * Bornée à ce qui est affiché, et aux seules pistes « fiche » et « plaque ». Une confirmation
 * en lot sur le nom du client relierait des affaires distinctes d'un même client sans que
 * personne ne les ait regardées — c'est précisément ce qu'on veut éviter.
 */
$confirmerLesCertains = function () {
    $certains = $this->propositions
        ->whereIn('motif', RapprochementProspectionDevis::MOTIFS_CERTAINS);

    $faits = 0;
    $refuses = 0;

    foreach ($certains as $ligne) {
        $refus = RapprochementProspectionDevis::confirmer(
            auth()->user(), $ligne['prospection']->id, $ligne['devis']->id, $this->idsSitesDuCompte,
        );

        $refus === null ? $faits++ : $refuses++;
    }

    $this->oublier();

    $this->message = $faits === 0
        ? 'Aucun rapprochement certain à confirmer dans cette liste.'
        : $faits.' rapprochement(s) confirmé(s)'.($refuses > 0 ? ', '.$refuses.' écarté(s) par un contrôle.' : '.');
};

?>

<div>
    <x-titre-ecran titre="Rapprochement — prospections et devis"
        sous-titre="La chaîne qui mène du commercial à la facture : la prospection trouve son devis, le devis trouve sa facture. Rien n'est rattaché sans votre clic." />

    {{-- Les deux maillons, dans l'ordre où ils s'enchaînent. Le premier donne le
         commercial, le second le porte jusqu'à la facture — qui est ce que la
         commission compte. Confirmer le second sans le premier ne sert à rien : le
         service le refuse et le dit.

         Aucun compte n'est affiché sur l'onglet qu'on ne regarde pas : l'écrire
         obligerait à calculer les deux listes à chaque affichage, pour n'en montrer
         qu'une. Le compte figure dans le titre du tableau, là où il sert. --}}
    <div class="carte" style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" wire:click="$set('volet', 'prospections')"
            class="onglet {{ $volet === 'prospections' ? 'est-actif' : '' }}">
            1 — Prospection → devis
        </button>
        <button type="button" wire:click="$set('volet', 'factures')"
            class="onglet {{ $volet === 'factures' ? 'est-actif' : '' }}">
            2 — Devis → facture
        </button>
    </div>

    @if ($volet === 'prospections')
    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            @if (! $this->villeUnique && $this->mesVilles !== [])
                <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles" vide="Toutes" :live="true" width="170" />
            @endif

            {{-- La fenêtre est réglable et non figée : le propriétaire annonce trois à cinq
                 jours d'usage, on propose quinze, et celui qui cherche une affaire ancienne
                 l'élargit lui-même plutôt que de nous le demander. --}}
            <x-champ label="Fenêtre (jours)" model="fenetre" type="number" :live="true" width="140" />

            <x-champ label="Rapprochement par" model="motifFiltre" type="select" :live="true" width="230"
                :options="\Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis::MOTIFS" vide="Toutes les pistes" />

            @if ($this->propositions->whereIn('motif', \Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis::MOTIFS_CERTAINS)->isNotEmpty())
                <button type="button" wire:click="confirmerLesCertains" class="bouton bouton-sombre"
                    style="padding:9px 16px; white-space:nowrap;">
                    Confirmer les {{ $this->propositions->whereIn('motif', \Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis::MOTIFS_CERTAINS)->count() }} certains
                </button>
            @endif
        </div>

        @if ($message !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
        @endif

        @if ($erreur !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-bottom:18px;">
        <x-kpi-card label="Rapprochements proposés" :value="$this->propositions->count()" />
        <x-kpi-card label="Prospections sans devis" :value="$this->enAttente"
            sub="Validées, et sans suite connue" couleur="#D97706" />
        <x-kpi-card label="Devis sans prospection" :value="$this->devisOrphelins"
            sub="Aucun commercial à qui les compter" couleur="#C8102E" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">
            Propositions ({{ $this->propositions->count() }})
        </h3>
        <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
            Le devis suit la prospection, jamais l'inverse : on ne regarde que les
            {{ (int) $fenetre }} jour(s) qui la suivent. Confirmer porte le devis au compte du
            commercial de la prospection ; écarter retire le couple de cette liste pour de bon.
        </p>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Prospection</th>
                        <th>Date</th>
                        <th>Commercial</th>
                        <th>Client visité</th>
                        <th>Devis proposé</th>
                        <th>Émis le</th>
                        <th>Écart</th>
                        <th>Client du devis</th>
                        <th>Montant</th>
                        <th>Sur quoi</th>
                        <th class="colonne-collee"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->propositions as $ligne)
                        @php
                            $prospection = $ligne['prospection'];
                            $devis = $ligne['devis'];
                            $certain = in_array($ligne['motif'], \Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis::MOTIFS_CERTAINS, true);
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="font-weight:700;">{{ $prospection->numero }}</td>
                            <td>{{ $prospection->date->format('d/m/Y') }}</td>
                            <td>{{ $prospection->commercial?->nom ?? '—' }}</td>
                            <td>
                                {{ $prospection->client }}
                                @if ($prospection->immatriculation)
                                    <span style="color:#6B6E76; font-size:11.5px;">· {{ $prospection->immatriculation }}</span>
                                @endif
                            </td>
                            <td style="font-weight:700;">{{ $devis->numero }}</td>
                            <td>{{ $devis->date_emission?->format('d/m/Y') ?? '—' }}</td>
                            <td style="font-variant-numeric:tabular-nums;">
                                {{ $ligne['ecart'] === 0 ? 'le jour même' : '+'.$ligne['ecart'].' j' }}
                            </td>
                            <td>{{ $devis->client }}</td>
                            <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($devis->montant_devis) }}</td>
                            <td>
                                <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600;
                                    background:{{ $certain ? '#E5F2E8' : '#FDF3E3' }};
                                    color:{{ $certain ? '#1E7B34' : '#B45309' }};">
                                    {{ \Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis::MOTIFS[$ligne['motif']] }}
                                </span>
                            </td>
                            <td class="colonne-collee" style="white-space:nowrap;">
                                {{-- Pas de dialogue du navigateur : le geste est déjà explicite,
                                     et il se défait en rattachant autrement. --}}
                                <button type="button" class="bouton bouton-sombre" style="padding:3px 9px; font-size:11.5px;"
                                    wire:click="confirmer({{ $prospection->id }}, {{ $devis->id }})">Confirmer</button>
                                <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                    wire:click="ecarter({{ $prospection->id }}, {{ $devis->id }})">Écarter</button>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="11"
                            texte="Aucun rapprochement à proposer. Soit chaque prospection a déjà son devis, soit aucune plaque ni aucun nom ne se répond dans la fenêtre choisie — élargissez-la pour chercher plus loin." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if ($volet === 'factures')
        @php $couverture = $this->couvertureDesFactures; @endphp

        <div class="carte" style="margin-bottom:16px;">
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                @if (! $this->villeUnique && $this->mesVilles !== [])
                    <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles" vide="Toutes" :live="true" width="170" />
                @endif

                {{-- Plus large que celle de la prospection : un devis attend l'accord du
                     client, parfois celui de son assureur, avant que la facture ne sorte. --}}
                <x-champ label="Fenêtre (jours)" model="fenetreFacture" type="number" :live="true" width="140" />

                <x-champ label="Rapprochement par" model="motifFiltre" type="select" :live="true" width="250"
                    :options="\Modules\Noyau\Exploitation\Services\RapprochementDevisFacture::MOTIFS" vide="Toutes les pistes" />

                @if ($this->propositionsFactures->whereIn('motif', \Modules\Noyau\Exploitation\Services\RapprochementDevisFacture::MOTIFS_CERTAINS)->isNotEmpty())
                    <button type="button" wire:click="confirmerLesFacturesCertaines" class="bouton bouton-sombre"
                        style="padding:9px 16px; white-space:nowrap;">
                        Confirmer les {{ $this->propositionsFactures->whereIn('motif', \Modules\Noyau\Exploitation\Services\RapprochementDevisFacture::MOTIFS_CERTAINS)->count() }} certains
                    </button>
                @endif
            </div>

            @if ($message !== '')
                <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
            @endif

            @if ($erreur !== '')
                <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-bottom:18px;">
            <x-kpi-card label="Rapprochements proposés" :value="$this->propositionsFactures->count()" />
            <x-kpi-card label="Factures sans commercial" :value="$couverture['sansCommercial']"
                sub="Comptées à personne — donc hors commission" couleur="#C8102E" />
            <x-kpi-card label="Factures du périmètre" :value="$couverture['total']" />
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">
                Propositions ({{ $this->propositionsFactures->count() }})
            </h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                La facture suit le devis : on ne regarde que les {{ (int) $fenetreFacture }} jour(s)
                qui le suivent. Confirmer rattache la facture au devis <b>et lui porte son
                commercial</b> — c'est ce geste qui fait exister la commission. Un devis qui n'a
                pas encore trouvé sa prospection ne porte aucun commercial : commencez alors par
                le premier volet.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Facture</th>
                            <th>Date</th>
                            <th>Client facturé</th>
                            <th>Immatriculation</th>
                            <th>Montant</th>
                            <th>Devis proposé</th>
                            <th>Émis le</th>
                            <th>Écart</th>
                            <th>Commercial du devis</th>
                            <th>Sur quoi</th>
                            <th class="colonne-collee"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->propositionsFactures as $ligne)
                            @php
                                $facture = $ligne['facture'];
                                $devis = $ligne['devis'];
                                $certain = in_array($ligne['motif'], \Modules\Noyau\Exploitation\Services\RapprochementDevisFacture::MOTIFS_CERTAINS, true);
                            @endphp
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $facture->n_facture ?: $facture->numero }}</td>
                                <td>{{ $facture->date?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $facture->client }}</td>
                                <td style="color:#6B6E76;">{{ $facture->immatriculation ?: '—' }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($facture->montant) }}</td>
                                <td style="font-weight:700;">{{ $devis->numero }}</td>
                                <td>{{ $devis->date_emission?->format('d/m/Y') ?? '—' }}</td>
                                <td style="font-variant-numeric:tabular-nums;">
                                    {{ $ligne['ecart'] === 0 ? 'le jour même' : '+'.$ligne['ecart'].' j' }}
                                </td>
                                <td>
                                    @if ($devis->commercial_id)
                                        {{ $devis->commercial?->nom ?? '—' }}
                                    @else
                                        {{-- Sans commercial, rattacher ne rémunérerait personne :
                                             on le dit avant le clic plutôt qu'après. --}}
                                        <span style="color:#B45309; font-size:12px;">aucun — voir le volet 1</span>
                                    @endif
                                </td>
                                <td>
                                    <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600;
                                        background:{{ $certain ? '#E5F2E8' : '#FDF3E3' }};
                                        color:{{ $certain ? '#1E7B34' : '#B45309' }};">
                                        {{ \Modules\Noyau\Exploitation\Services\RapprochementDevisFacture::MOTIFS[$ligne['motif']] }}
                                    </span>
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" class="bouton bouton-sombre" style="padding:3px 9px; font-size:11.5px;"
                                        wire:click="confirmerLaFacture({{ $facture->id }}, {{ $devis->id }})">Confirmer</button>
                                    <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                        wire:click="ecarterLaFacture({{ $facture->id }}, {{ $devis->id }})">Écarter</button>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="11"
                                texte="Aucun rapprochement à proposer. Soit chaque facture a déjà son devis, soit rien ne se répond dans la fenêtre choisie — élargissez-la pour chercher plus loin." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
