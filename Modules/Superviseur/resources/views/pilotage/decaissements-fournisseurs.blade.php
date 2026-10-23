<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\CategorieDeSortie;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Les décaissements fournisseurs de la caisse — 23/10
|--------------------------------------------------------------------------
| « Pour tous les décaissements faits en caisse qui concernent les fournisseurs — pas les charges
| du fonctionnement de l'entreprise —, il faudrait une page, au niveau des fournisseurs, qui les
| affiche ; celui qui en est chargé pourra faire le suivi : au bout de chaque ligne, un bouton
| Suivre ; cliqué, le formulaire du fournisseur s'ouvre. Et un lettrage entre la ligne de la caisse
| et la ligne du suivi, pour ne pas avoir deux montants à la fois — cliquable des deux côtés. »
|
| Les lignes : les sorties du journal que leur libellé range en **charges fournisseurs**
| (`CategorieDeSortie::volet`). Le lettrage : un règlement du suivi qui pointe la sortie
| (`reglements_fournisseur.mouvement_caisse_id`, 20/10) — posé par « Suivre » (une pièce neuve,
| réglée par cette sortie) ou par « Régler » sur une pièce déjà au suivi. Rien n'est écrit ici.
*/

state(['du' => '', 'au' => '', 'recherche' => '', 'lettrage' => '', 'page' => 1]);
// `?sortie=<id>` : le lien « payé par la caisse » d'une pièce mène à sa ligne.
state(['sortie' => null])->url(except: null);

mount(function () {
    $this->du = $this->du ?: now()->startOfYear()->format('Y-m-d');
    $this->au = $this->au ?: now()->format('Y-m-d');
});

$updated = function (string $propriete) {
    if (in_array($propriete, ['du', 'au', 'recherche', 'lettrage'], true)) {
        $this->page = 1;
    }
};

$peutEcrire = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

/**
 * Les sorties fournisseurs de la période et du périmètre, chacune avec son lettrage.
 *
 * La catégorie se lit en PHP (une lecture de texte) sur les seules colonnes utiles ; les règlements
 * qui les lettrent se lisent en une requête.
 */
$lignes = computed(function () {
    $idsSites = PerimetreSites::idsRetenus(auth()->user(), '');
    $jour = fn ($v, $defaut) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : $defaut;

    $sorties = PerimetreDeTresorerie::mouvementsDeCaisse(MouvementCaisse::query(), $idsSites, EtatDesImpayes::villesDesSites($idsSites))
        ->where('mouvements_caisse.sens', MouvementCaisse::SORTIE)
        ->when($this->sortie, fn ($q) => $q->where('mouvements_caisse.id', (int) $this->sortie),
            fn ($q) => $q->whereBetween('mouvements_caisse.date', [$jour($this->du, '1900-01-01'), $jour($this->au, '2999-12-31').' 23:59:59']))
        ->with(['site:id,nom', 'ville:id,nom'])
        ->orderByDesc('mouvements_caisse.date')->orderByDesc('mouvements_caisse.id')
        ->get(['mouvements_caisse.id', 'mouvements_caisse.date', 'mouvements_caisse.libelle', 'mouvements_caisse.motif',
            'mouvements_caisse.beneficiaire', 'mouvements_caisse.numero_piece', 'mouvements_caisse.montant',
            'mouvements_caisse.site_id', 'mouvements_caisse.ville_id'])
        ->filter(fn (MouvementCaisse $m) => CategorieDeSortie::volet(CategorieDeSortie::de($m->libelle, $m->motif, $m->beneficiaire)) === CategorieDeSortie::FOURNISSEURS)
        ->values();

    $lettres = ! \App\Support\SchemaDisponible::colonne('reglements_fournisseur', 'mouvement_caisse_id') ? collect()
        : ReglementFournisseur::query()->whereIn('mouvement_caisse_id', $sorties->pluck('id'))
            ->with('facture:id,numero_piece,fournisseur')
            ->get(['id', 'mouvement_caisse_id', 'facture_fournisseur_id', 'montant'])
            ->groupBy('mouvement_caisse_id');

    $terme = mb_strtolower(trim($this->recherche));

    return $sorties
        ->each(fn (MouvementCaisse $m) => $m->setAttribute('lettres', $lettres->get($m->id, collect())))
        ->when($this->lettrage === 'a_suivre', fn ($c) => $c->filter(fn ($m) => $m->lettres->isEmpty()))
        ->when($this->lettrage === 'suivis', fn ($c) => $c->filter(fn ($m) => $m->lettres->isNotEmpty()))
        ->when($terme !== '', fn ($c) => $c->filter(fn ($m) => str_contains(mb_strtolower($m->libelle.' '.$m->motif.' '.$m->beneficiaire.' '.$m->numero_piece), $terme)))
        ->values();
});

$totaux = computed(fn () => [
    'n' => $this->lignes->count(),
    'montant' => montant_de($this->lignes->sum(fn ($m) => montant_de($m->montant))),
    'nSuivis' => $this->lignes->filter(fn ($m) => $m->lettres->isNotEmpty())->count(),
    'suivis' => montant_de($this->lignes->filter(fn ($m) => $m->lettres->isNotEmpty())->sum(fn ($m) => montant_de($m->montant))),
]);

?>

<div>
    <x-titre-ecran titre="Décaissements fournisseurs de la caisse"
        sous-titre="Les sorties de caisse que leur libellé range en charges fournisseurs (pièces, travaux, mécanique, sinistre, règlements) — à suivre au suivi fournisseurs, et lettrées avec lui.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Charges fournisseurs</a>
            @if ($sortie)
                <button type="button" wire:click="$set('sortie', null)" class="bouton bouton-secondaire">Voir toutes les lignes</button>
            @endif
        </div>
    </x-titre-ecran>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Décaissements fournisseurs" :value="ae($this->totaux['montant'])" :sub="$this->totaux['n'].' sortie(s) du journal'" couleur="#B45309" />
        <x-kpi-card label="Suivis — lettrés au suivi" :value="ae($this->totaux['suivis'])" couleur="#0E9F6E" :sub="$this->totaux['nSuivis'].' sortie(s) reliée(s) à une pièce'" />
        <x-kpi-card label="À suivre" :value="ae(montant_de($this->totaux['montant'] - $this->totaux['suivis']))" :accent="$this->totaux['n'] > $this->totaux['nSuivis']"
            :sub="($this->totaux['n'] - $this->totaux['nSuivis']).' sortie(s) sans pièce au suivi'" />
    </div>

    <div class="carte">
        @unless ($sortie)
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
                <x-champ label="Du" model="du" type="date" :live="true" width="155" />
                <x-champ label="Au" model="au" type="date" :live="true" width="155" />
                <x-champ label="Rechercher" model="recherche" :live="true" width="220" placeholder="Libellé, bénéficiaire, n°…" />
                <x-champ label="Lettrage" model="lettrage" type="select" :live="true" width="190"
                    :options="['' => 'Toutes', 'a_suivre' => 'À suivre', 'suivis' => 'Déjà suivies']" />
            </div>
        @endunless
        <p style="margin:0 0 10px; font-size:12px; color:#6B6E76; line-height:1.5;">
            <b>Suivre</b> ouvre le formulaire du suivi fournisseurs prérempli de la sortie : la pièce créée est
            réglée par cette sortie, et les deux lignes se répondent. Si la pièce est <b>déjà au suivi</b>, ouvrez-la
            et « Régler » en choisissant cette sortie : le lettrage est le même, sans seconde pièce.
        </p>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr><th>Date</th><th>N° pièce caisse</th><th>Libellé</th><th>Bénéficiaire</th><th>Lieu</th><th>Catégorie</th>
                        <th style="text-align:right;">Montant</th><th>Lettrage — suivi fournisseurs</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes->forPage($page, 25) as $m)
                        <tr wire:key="dec-{{ $m->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8); {{ (int) $sortie === $m->id ? 'background:#FFFBEB;' : '' }}">
                            <td style="white-space:nowrap;">{{ $m->date?->format('d/m/Y') }}</td>
                            <td>{{ $m->numero_piece ?: '—' }}</td>
                            <td>{{ $m->libelle }}@if ($m->motif)<div style="font-size:11.5px; color:#6B6E76;">{{ $m->motif }}</div>@endif</td>
                            <td style="color:#6B6E76;">{{ $m->beneficiaire ?: '—' }}</td>
                            <td style="color:#6B6E76; font-size:12px;">{{ $m->site?->nom ?? ($m->ville?->nom ? $m->ville->nom.' — atelier non précisé' : '—') }}</td>
                            <td style="font-size:12px;">{{ CategorieDeSortie::de($m->libelle, $m->motif, $m->beneficiaire) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae(montant_de($m->montant)) }}</td>
                            <td style="font-size:12px;">
                                @forelse ($m->lettres as $r)
                                    <div>
                                        <a href="{{ route('fournisseurs.piece', $r->facture_fournisseur_id) }}" wire:navigate>{{ $r->facture?->numero_piece ?? 'pièce' }}</a>
                                        <span style="color:#6B6E76;">· {{ $r->facture?->fournisseur }} · {{ ae(montant_de($r->montant)) }}</span>
                                    </div>
                                @empty
                                    <span style="color:#B87A00;">à suivre</span>
                                @endforelse
                            </td>
                            <td style="white-space:nowrap;">
                                @if ($m->lettres->isEmpty() && $this->peutEcrire)
                                    <a href="{{ route('fournisseurs', ['suivre' => $m->id]) }}" wire:navigate class="bouton" style="padding:4px 10px; font-size:12px; text-decoration:none;">Suivre</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="9" texte="Aucun décaissement fournisseur de la caisse sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="25" />
    </div>
</div>
