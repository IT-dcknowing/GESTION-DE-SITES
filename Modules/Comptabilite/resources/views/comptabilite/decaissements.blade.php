<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Modeles\Site;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{state, computed, mount};

state([
    'siteId' => null,
    'chgDate' => null,
    'chgTypeOp' => 'Charges',
    'chgLibelle' => 'Achats pièces',
    'chgMotif' => '',
    'chgMoyen' => 'Espèces',
    'chgMontant' => '',
    'chgTiers' => '',
    'chgActivite' => '',
    'chgReference' => '',
    'chgObs' => '',
    'message' => null,
    'pageDuJour' => 1,
    'pageTousSites' => 1,
]);

/** Site(s) dont le caissier a la charge — un précis, ou les deux d'une ville entière. */
$mesSites = computed(fn () => Site::visiblesPour(auth()->user()));

$site = computed(fn () => $this->siteId
    ? $this->mesSites->firstWhere('id', (int) $this->siteId)
    : $this->mesSites->first());

mount(function () {
    $this->chgDate = now()->toDateString();
    $this->siteId = $this->mesSites->first()?->id;
});

$updatedSiteId = function () {
    unset($this->decaissementsDuJour);
};

$optionsMoyenPaiement = computed(fn () => Referentiel::options(Referentiel::MOYEN_PAIEMENT));

$libellesOperation = computed(function () {
    // Les libellés de charge sont enrichissables par l'entreprise ; les deux libellés de
    // décaissement restent figés, comme dans la saisie du responsable.
    $decaissements = ['Transfert de trésorerie vers un autre site', 'Décaissements DG'];

    return $this->chgTypeOp === 'Charges'
        ? array_values(Referentiel::options(Referentiel::LIBELLE_CHARGE))
        : $decaissements;
});

$updatedChgTypeOp = function () {
    $this->chgLibelle = $this->chgTypeOp === 'Charges' ? 'Achats pièces' : 'Transfert de trésorerie vers un autre site';
};

$decaissementsDuJour = computed(fn () => $this->site
    ? Charge::where('site_id', $this->site->id)->whereDate('date', $this->chgDate)->latest('id')->get()
    : collect());

$mesDecaissementsDuJour = computed(fn () => Charge::whereIn('site_id', $this->mesSites->pluck('id'))
    ->where('cree_par', auth()->id())->whereDate('date', now())->latest('id')->get());

$ajouterCharge = function () {
    if ($this->site && Exercice::estFerme(auth()->user()->entreprise_id, $this->site->ville_id, $this->chgDate)) {
        $this->addError('chgMontant', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    $donnees = $this->validate([
        'chgDate' => ['required', 'date'],
        'chgTypeOp' => ['required', 'in:Charges,Décaissements'],
        'chgLibelle' => ['required', 'string'],
        /* Le motif est obligatoire, et il n'est pas le libellé : celui-ci range la dépense
           dans une catégorie comptable choisie dans une liste, celui-là dit ce qui s'est
           passé ce jour-là. Trois mois plus tard, devant un relevé, c'est le second qu'on
           cherche. */
        'chgMotif' => ['required', 'string', 'max:190'],
        'chgMoyen' => ['required', Rule::in(array_keys($this->optionsMoyenPaiement))],
        'chgMontant' => ['required', 'numeric', 'min:1'],
        'chgTiers' => ['nullable', 'string', 'max:255'],
        'chgActivite' => ['nullable', 'in:Mécanique,Sinistre'],
        'chgReference' => ['nullable', 'string', 'max:60'],
        'chgObs' => ['nullable', 'string'],
    ], [], ['chgMontant' => 'montant', 'chgLibelle' => "libellé d'opération", 'chgMotif' => 'motif']);

    $typeOperation = 'Charges';
    if ($donnees['chgTypeOp'] === 'Décaissements') {
        $typeOperation = str_starts_with($donnees['chgLibelle'], 'Transfert') ? 'Transfert' : 'Décaissement DG';
    }

    Charge::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $this->site->id,
        'date' => $donnees['chgDate'],
        'type_operation' => $typeOperation,
        'activite' => $donnees['chgActivite'] ?: null,
        'libelle' => $donnees['chgLibelle'],
        'motif' => $donnees['chgMotif'],
        'moyen' => $donnees['chgMoyen'],
        'montant' => (int) $donnees['chgMontant'],
        'tiers' => $donnees['chgTiers'] ?: null,
        'reference_origine' => $donnees['chgReference'] ?: null,
        'observations' => $donnees['chgObs'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    $this->reset(['chgMontant', 'chgMotif', 'chgTiers', 'chgObs', 'chgActivite', 'chgReference']);
    $this->chgTypeOp = 'Charges';
    $this->chgLibelle = 'Achats pièces';
    $this->chgMoyen = 'Espèces';
    unset($this->decaissementsDuJour, $this->mesDecaissementsDuJour);
    $this->message = 'Décaissement enregistré.';
};

?>

<div>
    @if (! $this->site)
        <x-a-venir titre="Aucun site assigné" description="Ce compte Comptabilité n'est rattaché à aucun site pour le moment. Contactez votre gérant ou votre responsable." />
    @else
        <div class="carte">
            <div>
                <h1 style="font-size:19px; font-weight:800; margin:0 0 4px;">Décaissements — {{ $this->site->nom }}</h1>
                <p style="color:#6B6E76; font-size:14px; margin:0;">Charges d'exploitation, transferts de trésorerie et décaissements DG.</p>
            </div>
            @if ($this->mesSites->count() > 1)
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <label class="champ-libelle">Site</label>
                    <select wire:model.live="siteId" class="champ" style="width:220px;">
                        @foreach ($this->mesSites as $s)
                            <option value="{{ $s->id }}" @selected((string) $siteId === (string) $s->id)>{{ $s->nom }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        @if ($message)
            <div class="encart encart-succes">{{ $message }}</div>
        @endif

        <x-carte-section titre="Nouveau décaissement" icone="charge" couleur="var(--th-accent,#C8102E)">
            <div class="bloc-saisie">
                <x-champ label="Date" model="chgDate" type="date" live="true" width="150" />
                <x-champ label="Type d'opération" model="chgTypeOp" type="select" live="true" :options="['Charges' => 'Charges', 'Décaissements' => 'Décaissements']" width="160" />
                <x-champ label="Libellé d'opération" model="chgLibelle" type="select" :options="array_combine($this->libellesOperation, $this->libellesOperation)" width="220" />
                <x-champ label="Motif" model="chgMotif" :requis="true" width="260"
                    placeholder="Ce qui s'est passé ce jour-là" />
                <x-champ label="Moyens" model="chgMoyen" type="select" :options="$this->optionsMoyenPaiement" width="150" />
                <x-champ label="Montant (FCFA)" model="chgMontant" type="number" width="150" />
                <x-champ label="Tiers" model="chgTiers" placeholder="Ex : fournisseur, bénéficiaire…" />
                {{-- Beaucoup de décaissements (loyer, salaires) relèvent du lieu entier :
                     l'activité ne se renseigne que lorsqu'elle est réellement connue. --}}
                <x-champ label="Activité (facultatif)" model="chgActivite" type="select" width="150"
                    :options="['' => '— Non précisée', 'Mécanique' => 'Mécanique', 'Sinistre' => 'Sinistre']" />
                <x-champ label="Origine / référence" model="chgReference" width="170" placeholder="Pièce, bon, N° externe…" />
            </div>
            <div class="bloc-saisie" style="margin-top:10px;">
                <x-champ label="Observations" model="chgObs" />
                <button type="button" wire:click="ajouterCharge" class="bouton bouton-sombre">+ Ajouter</button>
            </div>
        </x-carte-section>

        <x-carte-section titre="Décaissements du {{ \Illuminate\Support\Carbon::parse($chgDate)->format('d/m/Y') }} — {{ $this->site->nom }}" icone="liste" couleur="#2A2E35">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th><th>Type d'opération</th><th>Activité</th><th>Libellé</th><th>Motif</th><th>Moyens</th>
                            <th>Montant</th><th>Tiers</th><th>Origine / référence</th><th>Observations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->decaissementsDuJour->forPage($pageDuJour, 10) as $c)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><x-numero-ligne :ligne="$c" /></td>
                                <td>{{ $c->type_operation }}</td>
                                <td>{{ $c->activite ?? '—' }}</td>
                                <td>{{ $c->libelle }}</td>
                                {{-- Les lignes d'avant le 24/09 n'ont pas de motif : on ne
                                     leur en invente pas un. --}}
                                <td>{{ $c->motif ?: '—' }}</td>
                                <td>{{ $c->moyen }}</td>
                                <td style="font-weight:700; color:#C8102E; font-variant-numeric:tabular-nums;">{{ ae($c->montant) }}</td>
                                <td>{{ $c->tiers ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $c->reference_origine ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $c->observations ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="10" texte="Aucun décaissement pour cette journée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageDuJour" :total="$this->decaissementsDuJour->count()" prop="pageDuJour" />
        </x-carte-section>

        <x-carte-section titre="Mes décaissements du jour, tous sites" icone="liste" couleur="#2A2E35">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>N°</th><th>Type</th><th>Libellé</th><th>Montant</th><th>Tiers</th></tr></thead>
                    <tbody>
                        @forelse ($this->mesDecaissementsDuJour->forPage($pageTousSites, 10) as $c)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><x-numero-ligne :ligne="$c" /></td>
                                <td>{{ $c->type_operation }}</td>
                                <td>{{ $c->libelle }}</td>
                                <td style="font-weight:700; color:#C8102E; font-variant-numeric:tabular-nums;">{{ ae($c->montant) }}</td>
                                <td>{{ $c->tiers ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Aucun décaissement saisi aujourd'hui." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageTousSites" :total="$this->mesDecaissementsDuJour->count()" prop="pageTousSites" />
        </x-carte-section>
    @endif
</div>
