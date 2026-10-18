<?php

use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFne;
use function Livewire\Volt\{state, computed, mount};

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Le détail d'une facture FNE — 18/10.
 *
 * « Ce qu'on veut en plus, c'est connaître le détail de chaque facture : le but est de passer les
 * écritures comptables. Pour chaque ligne, un bouton Voir. »
 *
 * Tout vient de la pièce telle que le portail l'a rendue (`factures_fne.contenu`) : l'en-tête,
 * **les lignes** (désignation, quantité, prix, remise, taxes, montant) et les totaux. Le portail
 * ne publie pas de schéma : on lit les noms de champs qu'il emploie (items, description,
 * quantity, amount…), et tout ce qu'on ne reconnaît pas paraît quand même en bas, dans « Toutes
 * les données » — rien n'est laissé de côté.
 *
 * Le périmètre est l'entreprise (portée globale du modèle) : un identifiant d'une autre entreprise
 * rend 404.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
state(['factureId' => null]);

mount(function (int $facture) {
    $this->factureId = FactureFne::query()->findOrFail($facture)->id;
});

$facture = computed(fn () => FactureFne::query()->with('pieceDuSuivi:id,numero_piece,reste_a_payer,fournisseur')->findOrFail($this->factureId));

$contenu = computed(fn () => is_array($this->facture->contenu) ? $this->facture->contenu : (json_decode((string) $this->facture->contenu, true) ?: []));

/** Les lignes de la facture : `items`, sinon le plus grand tableau d'objets de la pièce. */
$lignes = computed(function () {
    $c = $this->contenu;

    foreach (['items', 'invoiceItems', 'lines', 'articles', 'details'] as $cle) {
        if (isset($c[$cle]) && is_array($c[$cle]) && array_is_list($c[$cle])) {
            return $c[$cle];
        }
    }

    $meilleur = [];
    foreach ($c as $valeur) {
        if (is_array($valeur) && array_is_list($valeur) && $valeur !== [] && is_array($valeur[0]) && count($valeur) > count($meilleur)) {
            $meilleur = $valeur;
        }
    }

    return $meilleur;
});

/** Les colonnes des lignes : d'abord celles qu'on reconnaît, dans un ordre de facture, puis les autres. */
$colonnes = computed(function () {
    $connues = [
        'reference' => 'Réf.', 'code' => 'Code', 'description' => 'Désignation', 'designation' => 'Désignation', 'name' => 'Désignation',
        'quantity' => 'Qté', 'measurementUnit' => 'Unité', 'unit' => 'Unité', 'amount' => 'Prix unitaire HT', 'unitPrice' => 'Prix unitaire HT',
        'discount' => 'Remise', 'taxes' => 'Taxes', 'customTaxes' => 'Autres taxes', 'totalBeforeTaxes' => 'Total HT',
        'totalTaxes' => 'TVA', 'totalAfterTaxes' => 'Total TTC', 'total' => 'Total',
    ];
    $presentes = collect($this->lignes)->flatMap(fn ($l) => is_array($l) ? array_keys($l) : [])->unique()
        ->reject(fn ($k) => in_array($k, ['id', '_id', 'invoiceId', 'createdAt', 'updatedAt'], true))->values();

    $ordonnees = collect(array_keys($connues))->filter(fn ($k) => $presentes->contains($k));

    return $ordonnees->merge($presentes->diff($ordonnees))->mapWithKeys(fn ($k) => [$k => $connues[$k] ?? $k])->all();
});

$peutPorter = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

?>

@php
    /** Une valeur de la pièce, lisible : nombre à la française, liste et objet résumés. */
    $lire = function ($v) {
        if (is_array($v)) {
            return collect($v)->map(fn ($e) => is_array($e) ? implode(' ', array_filter(array_map(fn ($x) => is_scalar($x) ? (string) $x : '', array_intersect_key($e, array_flip(['name', 'code', 'label', 'rate', 'amount', 'value']))))) : (string) $e)
                ->filter()->implode(', ') ?: '—';
        }
        if (is_bool($v)) {
            return $v ? 'oui' : 'non';
        }
        if (is_numeric($v) && ! preg_match('/^0\d/', (string) $v)) {
            return montant_nombre($v);
        }

        return $v === null || $v === '' ? '—' : (string) $v;
    };
    $f = $this->facture;
    $c = $this->contenu;
    $plat = \Illuminate\Support\Arr::dot(array_diff_key($c, array_flip(['items', 'invoiceItems', 'lines', 'articles', 'details'])));
@endphp

<div>
    <x-titre-ecran :titre="($f->est_avoir ? 'Avoir FNE ' : 'Facture FNE ').$f->reference"
        :sous-titre="($f->emetteur_nom ?: 'Émetteur inconnu').' — '.($f->date_facture?->format('d/m/Y') ?? 'sans date')">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs.fne') }}" wire:navigate class="bouton bouton-secondaire">← Factures FNE</a>
            @if ($f->pieceDuSuivi)
                <a href="{{ route('fournisseurs.piece', $f->pieceDuSuivi->id) }}" wire:navigate class="bouton bouton-secondaire">Au suivi : {{ $f->pieceDuSuivi->numero_piece }}</a>
            @elseif ($this->peutPorter)
                <a href="{{ route('fournisseurs', ['porter' => $f->id]) }}" wire:navigate class="bouton">Porter au suivi</a>
            @endif
        </div>
    </x-titre-ecran>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Total HT" :value="ae(montant_de($f->montant_ht))" />
        <x-kpi-card label="TVA" :value="ae(montant_de($f->montant_tva))" />
        <x-kpi-card label="Timbre" :value="ae(montant_de($f->timbre))" />
        <x-kpi-card label="Total TTC" :value="ae(montant_de($f->montant_ttc))" :couleur="$f->est_avoir ? '#C8102E' : null" />
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">En-tête</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:6px 18px; font-size:13px;">
            @foreach ([
                'N° FNE' => $f->reference,
                'Type' => trim(($f->type ?? '').' '.($f->sous_type ?? '')) ?: '—',
                'Date' => $f->date_facture?->format('d/m/Y') ?? '—',
                'Statut sur le portail' => $f->statut_portail ?? '—',
                'Fournisseur (émetteur)' => $f->emetteur_nom ?? '—',
                'NCC du fournisseur' => $f->emetteur_ncc ?? '—',
                'RCCM du fournisseur' => $c['company']['rccm'] ?? '—',
                'Moyen de paiement' => $f->moyen_paiement ?? '—',
                'Exercice' => $f->exercice ?? '—',
            ] as $libelle => $valeur)
                <div><span style="color:#6B6E76;">{{ $libelle }} :</span> <b>{{ $valeur }}</b></div>
            @endforeach
        </div>
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Lignes de la facture ({{ count($this->lignes) }})</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>#</th>
                        @foreach ($this->colonnes as $libelle)
                            <th>{{ $libelle }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $i => $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;">
                            <td style="color:#6B6E76;">{{ $i + 1 }}</td>
                            @foreach (array_keys($this->colonnes) as $cle)
                                <td style="{{ is_numeric($ligne[$cle] ?? null) ? 'text-align:right; font-variant-numeric:tabular-nums;' : '' }}">{{ $lire($ligne[$cle] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <x-table-vide :colspan="count($this->colonnes) + 1" texte="Le portail n'a pas rendu de lignes pour cette pièce." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <details class="carte">
        <summary style="cursor:pointer; font-size:14px; font-weight:700;">Toutes les données de la pièce ({{ count($plat) }})</summary>
        <div class="tableau-conteneur" style="margin-top:10px;">
            <table class="tableau">
                <tbody>
                    @foreach ($plat as $cle => $valeur)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="color:#6B6E76; font-size:12px; white-space:nowrap;">{{ $cle }}</td>
                            <td style="font-size:12.5px;">{{ $lire($valeur) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
