<?php

use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Modeles\NotificationApp;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Commun\Services\Notificateur;
use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{state, computed};

state([
    'recherche' => '',
    'factureId' => null,
    'montant' => '',
    'motif' => '',
    'moyen' => 'Espèces',
    'message' => null,
    'pageAttente' => 1,
    'pageDuJour' => 1,
]);

$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), null));

$optionsMoyenPaiement = computed(fn () => Referentiel::options(Referentiel::MOYEN_PAIEMENT));

/**
 * Factures avec un reste à encaisser, dans le périmètre du caissier. C'est la seule
 * liste qu'il peut solder : une facture déjà réglée par le responsable de site, ou par
 * le caissier lui-même plus tôt, en disparaît aussitôt — impossible de la saisir deux fois.
 */
$facturesEnAttente = computed(function () {
    $q = Facture::whereIn('site_id', $this->idsSites)->saisieManuelle()->avecResteAEncaisser()
        ->withSum('encaissements', 'montant')->with('site')->latest('date')->latest('id');

    if ($this->recherche) {
        $q->where('client', 'like', '%'.$this->recherche.'%');
    }

    return $q->get();
});

$factureSelectionnee = computed(fn () => $this->factureId
    ? Facture::whereIn('site_id', $this->idsSites)->withSum('encaissements', 'montant')->find($this->factureId)
    : null);

$mesEncaissementsDuJour = computed(fn () => Encaissement::whereIn('site_id', $this->idsSites)
    ->where('cree_par', auth()->id())->whereDate('date', now())->with('facture')->latest('id')->get());

$choisirFacture = function (int $id) {
    $facture = Facture::whereIn('site_id', $this->idsSites)->withSum('encaissements', 'montant')->findOrFail($id);
    $this->factureId = $facture->id;
    $this->montant = $facture->resteAEncaisser();
    $this->message = null;
    $this->resetValidation();
};

$annuler = function () {
    $this->reset(['factureId', 'montant', 'motif']);
    $this->moyen = 'Espèces';
};

$encaisser = function () {
    $donnees = $this->validate([
        'factureId' => ['required', 'integer'],
        'montant' => ['required', 'numeric', 'min:1'],
        'moyen' => ['required', Rule::in(array_keys($this->optionsMoyenPaiement))],
        /* Le motif est obligatoire : une facture se règle en plusieurs fois, et savoir
           pourquoi celui-ci arrive aujourd'hui — un acompte convenu, un déblocage
           d'assurance, une remise de chèque — est ce qu'on cherche trois mois plus tard
           devant un relevé. Le numéro de facture dit sur quoi, pas pourquoi. */
        'motif' => ['required', 'string', 'max:190'],
    ], [], ['montant' => 'montant', 'moyen' => 'moyen de paiement', 'motif' => 'motif']);

    $montant = (int) $donnees['montant'];
    $encaissement = null;

    // Verrou en base : deux saisies simultanées (responsable + caissier, ou deux
    // caissiers) sur la même facture ne peuvent jamais dépasser ensemble son montant.
    $depassement = DB::transaction(function () use ($donnees, $montant, &$encaissement) {
        $facture = Facture::whereIn('site_id', $this->idsSites)->with('site')->lockForUpdate()->find($donnees['factureId']);

        if (! $facture || $montant > $facture->resteAEncaisser()) {
            return true;
        }

        if (Exercice::estFerme(auth()->user()->entreprise_id, $facture->site->ville_id, now())) {
            return true;
        }

        $encaissement = Encaissement::create([
            'entreprise_id' => auth()->user()->entreprise_id,
            'site_id' => $facture->site_id,
            'facture_id' => $facture->id,
            'date' => now()->toDateString(),
            'type' => 'Client',
            // L'encaissement hérite de l'activité et du numéro de la facture qu'il solde :
            // c'est ce qui rend la ligne ventilable et rapprochable plus tard.
            'activite' => $facture->activite,
            'moyen' => $donnees['moyen'],
            'motif' => $donnees['motif'],
            'montant' => $montant,
            'client' => $facture->client,
            'reference_origine' => $facture->n_facture,
            'cree_par' => auth()->id(),
        ]);

        return false;
    });

    if ($depassement) {
        $this->addError('montant', "Cette facture est déjà soldée, l'exercice est clos pour ce site, ou elle a été mise à jour entre-temps : rechargez la liste.");
        unset($this->facturesEnAttente);

        return;
    }

    // Le responsable du site (ou de sa ville) est notifié à chaque encaissement du caissier.
    Notificateur::pourPlusieurs(
        destinataires: Notificateur::encadrementDuSite($encaissement->site_id, auth()->user()->entreprise_id),
        titre: 'Encaissement caissier',
        corps: auth()->user()->name.' a encaissé '.ae($montant).' sur la facture '.$encaissement->facture->n_facture.'.',
        canal: NotificationApp::CANAL_GESTION,
        niveau: NotificationApp::NIVEAU_SUCCES,
        lien: route('tresorerie'),
    );

    $this->reset(['factureId', 'montant', 'motif']);
    $this->moyen = 'Espèces';
    unset($this->facturesEnAttente, $this->mesEncaissementsDuJour);
    $this->message = 'Encaissement enregistré — visible aussitôt chez le responsable du site.';
};

?>

<div>
    @if ($message)
        <div class="encart encart-succes">{{ $message }}</div>
    @endif

    <div style="display:grid; grid-template-columns:1.3fr 1fr; gap:20px; align-items:start;">
        <div class="carte">
            <h1 style="font-size:18px; font-weight:800; margin:0 0 4px;">Factures en attente d'encaissement</h1>
            <p style="color:#6B6E76; font-size:14px; margin:0 0 14px;">Une facture disparaît de cette liste dès qu'elle est intégralement réglée — par vous ou par le responsable du site.</p>

            <input type="text" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}" placeholder="Rechercher un client…"
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px; margin-bottom:14px;">

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N° facture</th>
                            <th>Client</th>
                            @if (count($this->idsSites) > 1)
                                <th>Site</th>
                            @endif
                            <th>Montant</th>
                            <th>Déjà encaissé</th>
                            <th>Reste</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->facturesEnAttente->forPage($pageAttente, 10) as $f)
                            <tr wire:key="facture-{{ $f->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8); {{ $factureId === $f->id ? 'background:#FDF2F4;' : '' }}">
                                <td style="font-weight:700;">{{ $f->n_facture }}</td>
                                <td>{{ $f->client }}</td>
                                @if (count($this->idsSites) > 1)
                                    <td>{{ $f->site->nom }}</td>
                                @endif
                                <td style="font-variant-numeric:tabular-nums;">{{ ae($f->montant) }}</td>
                                <td style="font-variant-numeric:tabular-nums; color:#6B6E76;">{{ ae($f->encaissements_sum_montant ?? 0) }}</td>
                                <td style="font-weight:700; color:#D97706; font-variant-numeric:tabular-nums;">{{ ae($f->resteAEncaisser()) }}</td>
                                <td style="text-align:right;">
                                    <button type="button" wire:click="choisirFacture({{ $f->id }})" class="bouton bouton-secondaire bouton-petit">Encaisser</button>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucune facture en attente d'encaissement." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageAttente" :total="$this->facturesEnAttente->count()" prop="pageAttente" />
        </div>

        <div class="carte" style="position:sticky; top:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
                {{ $this->factureSelectionnee ? 'Encaisser — '.$this->factureSelectionnee->n_facture : 'Choisissez une facture' }}
            </h3>

            @if ($this->factureSelectionnee)
                <div class="tableau-conteneur" style="margin-bottom:14px;">
                    <table class="tableau">
                        <tbody>
                            <tr><td style="font-weight:600;">Client</td><td>{{ $this->factureSelectionnee->client }}</td></tr>
                            <tr><td style="font-weight:600;">Montant facturé</td><td>{{ ae($this->factureSelectionnee->montant) }}</td></tr>
                            <tr><td style="font-weight:600;">Déjà encaissé</td><td>{{ ae($this->factureSelectionnee->encaissements_sum_montant ?? 0) }}</td></tr>
                            <tr><td style="font-weight:600;">Reste à encaisser</td><td style="font-weight:700; color:#D97706;">{{ ae($this->factureSelectionnee->resteAEncaisser()) }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <label class="champ-libelle">Montant encaissé (FCFA)</label>
                <input type="number" wire:model="montant" value="{{ $montant }}" class="champ" style="margin-bottom:4px;">
                @error('montant') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror
                <p style="font-size:12px; color:#9A9DA5; margin:0 0 12px;">Un encaissement partiel est autorisé : indiquez uniquement ce qui a été réellement reçu.</p>

                <label class="champ-libelle">Moyen de paiement</label>
                <select wire:model="moyen" class="champ" style="margin-bottom:14px;">
                    @foreach ($this->optionsMoyenPaiement as $valeur => $libelle)
                        <option value="{{ $valeur }}" @selected((string) $moyen === (string) $valeur)>{{ $libelle }}</option>
                    @endforeach
                </select>

                <label class="champ-libelle">Motif <span style="color:var(--th-accent);">*</span></label>
                <input type="text" wire:model="motif" value="{{ $motif }}" class="champ" style="margin-bottom:4px;"
                    placeholder="Acompte convenu, déblocage assurance, remise de chèque…">
                @error('motif') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror
                <p style="font-size:12px; color:#9A9DA5; margin:0 0 14px;">
                    Le n° de facture dit sur quoi porte ce règlement ; le motif dit pourquoi il arrive
                    aujourd'hui. C'est le second qu'on cherche devant un relevé, trois mois plus tard.
                </p>

                <div style="display:flex; gap:10px;">
                    <button type="button" wire:click="encaisser" class="bouton">Valider l'encaissement</button>
                    <button type="button" wire:click="annuler" class="bouton bouton-secondaire">Annuler</button>
                </div>
            @else
                <p style="font-size:13.5px; color:#6B6E76;">Cliquez sur « Encaisser » en face d'une facture pour préparer la saisie.</p>
            @endif
        </div>
    </div>

    <div class="carte" style="margin-top:20px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Mes encaissements du jour</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead><tr><th>N° encaissement</th><th>N° facture</th><th>Client</th><th>Motif</th><th>Moyen</th><th>Montant</th></tr></thead>
                <tbody>
                    @forelse ($this->mesEncaissementsDuJour->forPage($pageDuJour, 10) as $e)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$e" /></td>
                            <td style="font-weight:700;">{{ $e->facture?->n_facture ?? '—' }}</td>
                            <td>{{ $e->client ?? '—' }}</td>
                            {{-- Les lignes d'avant le 24/09 n'ont pas de motif : on ne leur
                                 en invente pas un. --}}
                            <td>{{ $e->motif ?: '—' }}</td>
                            <td>{{ $e->moyen }}</td>
                            <td style="font-weight:700; color:#0E9F6E; font-variant-numeric:tabular-nums;">{{ ae($e->montant) }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="5" texte="Aucun encaissement saisi aujourd'hui." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageDuJour" :total="$this->mesEncaissementsDuJour->count()" prop="pageDuJour" />
    </div>
</div>
