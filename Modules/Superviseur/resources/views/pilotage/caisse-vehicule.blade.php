<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\NoteVehicule;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Caisse — recherche par véhicule
|--------------------------------------------------------------------------
| Une plaque, et tout ce que l'entreprise sait d'elle.
|
| **Pourquoi une page à part.** L'écran de caisse répond à « combien est entré et sorti
| cette semaine ». Celle-ci répond à une tout autre question, posée au comptoir, souvent
| avec le client au téléphone : « la 1234 AB 01, on a payé quoi dessus, et est-ce qu'il
| reste quelque chose à régler ? ». Mêler les deux aurait donné un écran qui répond mal
| aux deux : le premier a besoin d'une période, celle-ci n'en veut pas — une dépense de
| l'an dernier sur ce véhicule compte toujours.
|
| **Ce que la page réunit**, et que rien ne réunissait :
|
|   - la **fiche de réception** du parc, qui dit la marque, le client et où en sont les
|     travaux ;
|   - les **mouvements de caisse** portant cette plaque, entrées et sorties mêlées, dans
|     l'ordre du temps ;
|   - les **factures** du véhicule avec leur reste à payer, pour répondre sans aller
|     ouvrir l'état des impayés ;
|   - les **notes** laissées par ceux qui ont suivi le dossier, et de quoi en ajouter une.
|
| **Aucune période, aucun filtre de confort.** On cherche un véhicule, pas un intervalle.
| Le périmètre du compte, lui, s'applique comme partout : la caisse par ville, les
| factures par atelier.
|
| **La plaque est rangée avant d'être comparée** — majuscules, espaces réduits — sinon
| « 1234 ab 01 » ouvrirait un dossier vide à côté de celui de « 1234 AB 01 ».
*/

state(['plaque' => ''])->url(except: '');

state([
    'note' => '',
    'noteEnregistree' => false,
]);

mount(function () {
    // On arrive souvent ici depuis une ligne de caisse ou une fiche véhicule : la plaque
    // est alors dans l'adresse, et l'écran s'ouvre déjà rempli.
    $this->plaque = NoteVehicule::plaque($this->plaque);
});

$updatedPlaque = function () {
    $this->plaque = NoteVehicule::plaque($this->plaque);
    $this->noteEnregistree = false;
    unset($this->mouvements, $this->factures, $this->notes, $this->fiches, $this->totaux);
};

$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), '', ''));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), ''));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), ''));

/** Vrai dès que la plaque saisie a de quoi chercher : deux signes suffisent à se tromper. */
$cherche = computed(fn () => mb_strlen($this->plaque) >= 3);

/**
 * Les fiches de réception de ce véhicule, la plus récente en tête.
 *
 * Un véhicule revient : trois passages, trois fiches. On les montre toutes — c'est
 * l'historique de l'atelier sur ce châssis — mais la première ligne suffit à répondre
 * « à qui est-il et où en est-il ».
 */
$fiches = computed(fn () => ! $this->cherche ? collect() : DossierVehicule::query()
    ->whereIn('ville_id', $this->idsVilles)
    ->where('immatriculation', $this->plaque)
    ->orderByDesc('date_fiche')->orderByDesc('id')
    ->limit(10)->get());

/** Les mouvements de caisse portant cette plaque — entrées et sorties, dans l'ordre du temps. */
$mouvements = computed(fn () => ! $this->cherche ? collect() : MouvementCaisse::query()
    ->with('ville')
    ->whereIn('ville_id', $this->idsVilles)
    ->where('immatriculation', $this->plaque)
    ->orderBy('date')->orderBy('id')
    ->get());

/**
 * Les factures du véhicule, avec ce qu'il reste à payer dessus.
 *
 * C'est la moitié de la question posée au comptoir. Le reste se calcule depuis les
 * encaissements, comme partout ailleurs — jamais depuis une colonne.
 */
$factures = computed(fn () => ! $this->cherche ? collect() : Facture::query()
    ->with('site')
    ->withSum('encaissements', 'montant')
    ->whereIn('site_id', $this->idsSites)
    ->where('immatriculation', $this->plaque)
    ->orderByDesc('date')->orderByDesc('id')
    ->get());

$notes = computed(fn () => ! $this->cherche ? collect() : NoteVehicule::query()
    ->with('utilisateur')
    ->where('immatriculation', $this->plaque)
    ->orderByDesc('created_at')->orderByDesc('id')
    ->limit(50)->get());

$totaux = computed(function () {
    $mouvements = $this->mouvements;
    $factures = $this->factures;

    return [
        'entrees' => (int) $mouvements->where('sens', MouvementCaisse::ENTREE)->sum('montant'),
        'sorties' => (int) $mouvements->where('sens', MouvementCaisse::SORTIE)->sum('montant'),
        'facture' => (int) $factures->sum('montant'),
        'reste' => (int) $factures->sum(fn (Facture $f) => Recouvrement::reste($f)),
        'ouvertes' => $factures->filter(fn (Facture $f) => Recouvrement::reste($f) >= Recouvrement::SEUIL_SOLDE)->count(),
    ];
});

/**
 * Ajoute une note au véhicule.
 *
 * La plaque n'est pas reprise du champ tel quel : elle repasse par le même rangement que
 * la recherche, sans quoi deux graphies de la même plaque porteraient chacune leurs notes
 * sans jamais se rencontrer. Le nom de l'auteur est recopié à côté de son identifiant :
 * un compte supprimé ne doit pas rendre la note anonyme.
 */
$enregistrerLaNote = function () {
    $texte = trim((string) $this->note);
    $plaque = NoteVehicule::plaque($this->plaque);

    if ($plaque === '' || mb_strlen($plaque) < 3) {
        $this->addError('note', 'Choisissez d’abord un véhicule.');

        return;
    }

    if ($texte === '') {
        $this->addError('note', 'Une note vide n’apprend rien à personne.');

        return;
    }

    NoteVehicule::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'immatriculation' => $plaque,
        'texte' => mb_substr($texte, 0, 2000),
        'user_id' => auth()->id(),
        'auteur' => auth()->user()->name,
    ]);

    activity()->causedBy(auth()->user())
        ->withProperties(['immatriculation' => $plaque, 'texte' => mb_substr($texte, 0, 2000)])
        ->log('Caisse — note ajoutée sur un véhicule');

    $this->note = '';
    $this->noteEnregistree = true;
    unset($this->notes);
};

?>

<div>
    <x-titre-ecran titre="Caisse — recherche par véhicule"
        sous-titre="Une plaque, et tout ce qu'on sait d'elle : sa fiche, ses mouvements de caisse, ses factures et ses notes." />

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div style="flex:1; min-width:260px;">
                <label style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:.7px;
                              color:#5A6472; font-weight:700; margin-bottom:5px;">Immatriculation</label>
                <input type="search" wire:model.live.debounce.400ms="plaque" value="{{ $plaque }}"
                    placeholder="Ex. 1234 AB 01" class="champ" style="width:100%; text-transform:uppercase; font-weight:700;">
            </div>

            <a href="{{ route('caisse') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:9px 16px; text-decoration:none;">Retour à la caisse</a>
        </div>

        @if (! $this->cherche)
            <p style="margin:12px 0 0; font-size:13px; color:#6B6E76;">
                Tapez au moins trois caractères d'une plaque. La recherche porte sur
                {{ $this->libellePerimetre }}.
            </p>
        @endif
    </div>

    @if ($this->cherche)
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Encaissé en caisse" :value="ae($this->totaux['entrees'])" couleur="#0E9F6E"
                :sub="$this->mouvements->count().' mouvement(s) sur cette plaque'" />
            <x-kpi-card label="Dépensé en caisse" :value="ae($this->totaux['sorties'])" couleur="#C8102E" />
            <x-kpi-card label="Facturé" :value="ae($this->totaux['facture'])"
                :sub="$this->factures->count().' facture(s)'" />
            {{-- La réponse à la question du comptoir : reste-t-il quelque chose à régler ? --}}
            <x-kpi-card label="Reste à payer" :value="ae($this->totaux['reste'])"
                :accent="$this->totaux['reste'] >= Recouvrement::SEUIL_SOLDE"
                :sub="$this->totaux['ouvertes'].' facture(s) ouverte(s)'" />
        </div>

        @if ($this->fiches->isNotEmpty())
            <div class="carte" style="margin-bottom:16px;">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">
                    Le véhicule ({{ $this->fiches->count() }} passage(s) connu(s))
                </h3>

                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th>N° fiche</th>
                                <th>Date</th>
                                <th>Marque / modèle</th>
                                <th>Client</th>
                                <th>Motif</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->fiches as $fiche)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                    <td style="font-weight:700;">{{ $fiche->numero_fiche ?: '—' }}</td>
                                    <td>{{ $fiche->date_fiche?->format('d/m/Y') ?? '—' }}</td>
                                    <td>{{ trim(($fiche->marque ?? '').' '.($fiche->modele ?? '')) ?: '—' }}</td>
                                    <td>{{ $fiche->client ?: '—' }}</td>
                                    <td style="max-width:240px; white-space:normal;">{{ $fiche->motif ?: '—' }}</td>
                                    <td style="font-size:12px;">{{ $fiche->statut ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">
                Mouvements de caisse ({{ $this->mouvements->count() }})
            </h3>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Sens</th>
                            <th>Libellé</th>
                            <th>Bénéficiaire / remettant</th>
                            <th>Ville</th>
                            <th style="text-align:right;">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->mouvements as $mouvement)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $mouvement->date?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    @if ($mouvement->sens === MouvementCaisse::ENTREE)
                                        <span class="pastille pastille-vert" style="font-weight:600;">Entrée</span>
                                    @else
                                        <span class="pastille pastille-rouge" style="font-weight:600;">Sortie</span>
                                    @endif
                                </td>
                                <td style="max-width:320px; white-space:normal;">{{ $mouvement->libelle ?: '—' }}</td>
                                <td>{{ $mouvement->beneficiaire ?: '—' }}</td>
                                <td>{{ $mouvement->ville?->nom ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $mouvement->sens === MouvementCaisse::ENTREE ? '#0E9F6E' : '#C8102E' }};">
                                    {{ ae((int) $mouvement->montant) }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucun mouvement de caisse sur cette plaque." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">
                Factures du véhicule ({{ $this->factures->count() }})
            </h3>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° facture</th>
                            <th>Payeur</th>
                            <th>Atelier</th>
                            <th style="text-align:right;">Montant</th>
                            <th style="text-align:right;">Réglé</th>
                            <th style="text-align:right;">Reste</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures as $facture)
                            @php $reste = Recouvrement::reste($facture); @endphp
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $facture->date?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $facture->n_facture ?: $facture->numero }}</td>
                                {{-- Le payeur, et non le client : c'est à lui qu'on réclamera. --}}
                                <td>{{ $facture->tiersPayant() }}</td>
                                <td>{{ $facture->site?->nom ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae((int) $facture->montant) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">
                                    {{ ae((int) ($facture->encaissements_sum_montant ?? 0)) }}
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $reste >= Recouvrement::SEUIL_SOLDE ? '#C8102E' : '#6B6E76' }};">
                                    {{ ae($reste) }}
                                </td>
                                <td>
                                    @if ($reste < Recouvrement::SEUIL_SOLDE)
                                        <span class="pastille pastille-vert" style="font-weight:600;">Soldée</span>
                                    @elseif ($facture->exercice_impayes !== null)
                                        <a href="{{ route('impayes.detail', $facture->id) }}" wire:navigate
                                            style="font-size:12px; color:#C8102E; font-weight:600;">À l'état {{ $facture->exercice_impayes }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="8" texte="Aucune facture enregistrée sur cette plaque." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Notes sur ce véhicule</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                Les notes s'ajoutent et ne s'effacent pas : « pièce commandée » et « pièce reçue »
                sont deux faits, et le second ne rend pas le premier faux.
            </p>

            <form wire:submit.prevent="enregistrerLaNote" style="margin-bottom:14px;">
                <textarea wire:model="note" rows="2" class="champ" style="width:100%; resize:vertical;"
                    placeholder="Ex. : pièce commandée le 21/09, attendue le 28. Facture remise en main propre."></textarea>

                @error('note')
                    <div style="color:#C8102E; font-size:12.5px; margin-top:5px;">{{ $message }}</div>
                @enderror

                <div style="display:flex; gap:10px; align-items:center; margin-top:8px;">
                    <button type="submit" class="bouton" style="padding:8px 16px;">Ajouter la note</button>
                    @if ($noteEnregistree)
                        <span style="color:#0E9F6E; font-size:12.5px; font-weight:600;">Note enregistrée.</span>
                    @endif
                </div>
            </form>

            @forelse ($this->notes as $ligne)
                <div style="border-left:3px solid #E2E0D8; padding:6px 0 6px 12px; margin-bottom:10px;">
                    <div style="font-size:11.5px; color:#6B6E76;">
                        {{ $ligne->created_at?->format('d/m/Y à H:i') }} ·
                        {{ $ligne->auteur ?: ($ligne->utilisateur?->name ?? 'auteur inconnu') }}
                    </div>
                    <div style="font-size:13.5px; white-space:pre-line;">{{ $ligne->texte }}</div>
                </div>
            @empty
                <p style="margin:0; font-size:13px; color:#6B6E76;">Aucune note pour l'instant.</p>
            @endforelse
        </div>
    @endif
</div>
