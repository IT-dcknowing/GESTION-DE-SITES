<?php

use Livewire\WithFileUploads;
use Modules\Noyau\Rapport\Modeles\DepotRapport;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Services\AssistantDuRapport;
use Modules\Noyau\Rapport\Services\DepotDeRapport;
use Modules\Noyau\Rapport\Services\DepotEnDoubleDeRapport;
use Modules\Noyau\Rapport\Services\RapportFinancier;
use Modules\Noyau\Rapport\Support\LectureDesPieces;
use function Livewire\Volt\{state, computed, uses};

/*
 * Le module Rapport — 06/11. L'Excel « Rapport financier », dans l'application, et vivant.
 *
 * « Dès que le bouton Rapport est cliqué, la première page est la présentation, et les autres boutons
 * sont alignés en haut, comme les feuilles de l'Excel. » Chaque bouton est une page ; l'onglet se lit
 * dans l'adresse (`?vue=charges`), donc se partage.
 *
 * Tout vient des services que les autres écrans lisent déjà (`RapportFinancier`) : le rapport bouge
 * avec les données, sur l'exercice en cours — et la page « Année précédente » rappelle N-1.
 *
 * Le dépôt de fichiers (impôts, CNPS, récapitulatifs) ne crée jamais de doublon : voir `DepotDeRapport`.
 * L'assistant IA ne parle qu'au clic, et seulement si une clé est posée (`AssistantDuRapport`).
 */
uses(WithFileUploads::class);

state(['vue' => 'presentation'])->url(except: 'presentation');
state(['exercice' => null])->url(except: null);
state([
    'fichier' => null, 'natureDepot' => '', 'messageDepot' => '', 'erreurDepot' => '',
    'commentaireIa' => '', 'erreurIa' => '',
    'pieceNature' => 'impot', 'pieceStatut' => 'du', 'pieceDate' => '', 'pieceLibelle' => '', 'pieceTiers' => '', 'pieceReference' => '', 'pieceMontant' => '',
    'propositions' => [], 'depotLu' => null,
]);

$onglets = [
    'presentation' => 'Présentation', 'ca' => 'Chiffre d\'affaires', 'recouvrement' => 'Recouvrement', 'charges' => 'Charges',
    'conclusion' => 'Conclusion', 'commerciaux' => 'Commerciaux', 'precedent' => 'Année précédente', 'depot' => 'Dépôt de fichiers',
];

$exerciceChoisi = computed(fn () => (int) ($this->exercice ?: now()->year));
$annees = computed(fn () => range((int) now()->year, (int) now()->year - 4));
$rapport = computed(fn () => RapportFinancier::pour(auth()->user(), $this->exerciceChoisi));
$donnees = computed(fn () => $this->rapport->tout());
$commentaires = computed(fn () => AssistantDuRapport::commentaires($this->donnees));
$peutDeposer = computed(fn () => auth()->user()->hasRole('gerant'));
$iaDispo = computed(fn () => AssistantDuRapport::iaDisponible());
$depots = computed(fn () => DepotRapport::query()->with('deposant')->latest()->limit(15)->get());
$pieces = computed(fn () => PieceRapport::query()->where('exercice', $this->exerciceChoisi)->latest('id')->limit(60)->get());
$logo = computed(fn () => auth()->user()->entreprise?->logoUrl());

$aller = function (string $vue) {
    $this->vue = array_key_exists($vue, ['presentation' => 1, 'ca' => 1, 'recouvrement' => 1, 'charges' => 1, 'conclusion' => 1, 'commerciaux' => 1, 'precedent' => 1, 'depot' => 1]) ? $vue : 'presentation';
};

$commenterParIA = function () {
    $this->commentaireIa = '';
    $this->erreurIa = '';
    $texte = AssistantDuRapport::commenterParIA($this->rapport->resumePourIA());

    if ($texte === null) {
        $this->erreurIa = 'L\'assistant n\'a pas répondu (clé absente ou service indisponible). Les commentaires de calcul restent affichés.';

        return;
    }

    $this->commentaireIa = $texte;
};

$deposer = function () {
    abort_unless($this->peutDeposer, 403);
    $this->messageDepot = $this->erreurDepot = '';
    $this->validate(['fichier' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt,pdf,jpg,jpeg,png,webp']]);

    try {
        $depot = DepotDeRapport::deposer($this->fichier, auth()->user(), $this->natureDepot ?: null, $this->exerciceChoisi);
        $this->messageDepot = $depot->nom_original.' — '.($depot->message ?: 'lu.');
    } catch (DepotEnDoubleDeRapport $e) {
        $this->erreurDepot = $e->getMessage();
    }

    $this->fichier = null;
};

$saisir = function () {
    abort_unless($this->peutDeposer, 403);
    $this->messageDepot = $this->erreurDepot = '';
    $d = $this->validate([
        'pieceNature' => ['required', 'in:impot,cnps,salaire,recap,autre'], 'pieceStatut' => ['required', 'in:du,paye,info'],
        'pieceDate' => ['nullable', 'date'], 'pieceLibelle' => ['required', 'string', 'max:200'], 'pieceTiers' => ['nullable', 'string', 'max:200'],
        'pieceReference' => ['nullable', 'string', 'max:80'], 'pieceMontant' => ['required', 'numeric', 'min:1'],
    ]);

    $bilan = DepotDeRapport::saisir([
        'nature' => $d['pieceNature'], 'statut' => $d['pieceNature'] === 'recap' ? 'info' : $d['pieceStatut'], 'date' => $d['pieceDate'] ?: null,
        'libelle' => $d['pieceLibelle'], 'tiers' => $d['pieceTiers'] ?: null, 'reference' => $d['pieceReference'] ?: null,
        'montant' => (int) round((float) $d['pieceMontant']),
    ], auth()->user(), $this->exerciceChoisi);

    if ($bilan['doublons'] > 0) {
        $this->erreurDepot = 'Cette pièce existe déjà (même nature, statut, référence et montant) : rien n\'est recréé.';

        return;
    }

    $this->messageDepot = 'Pièce enregistrée'.($bilan['dejaDansLApplication'] > 0 ? ' — déjà comptée par la caisse ou la banque : gardée, non recomptée.' : '.');
    $this->reset('pieceLibelle', 'pieceTiers', 'pieceReference', 'pieceMontant');
};

$lireParIA = function (int $depotId) {
    abort_unless($this->peutDeposer, 403);
    $this->erreurDepot = '';
    $depot = DepotRapport::query()->findOrFail($depotId);
    $mime = match (strtolower(pathinfo($depot->nom_original, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };
    $pieces = AssistantDuRapport::lireUneImage(\Illuminate\Support\Facades\Storage::disk('local')->path($depot->chemin), $mime);

    if ($pieces === null || $pieces === []) {
        $this->erreurDepot = 'L\'assistant n\'a rien pu lire de cette image : saisissez les montants à la main.';

        return;
    }

    $this->depotLu = $depot->id;
    $this->propositions = $pieces;
};

$confirmerLesPropositions = function () {
    abort_unless($this->peutDeposer, 403);
    $depot = $this->depotLu ? DepotRapport::query()->find($this->depotLu) : null;
    $nouvelles = $doublons = 0;

    foreach ($this->propositions as $p) {
        $b = DepotDeRapport::saisir($p, auth()->user(), $this->exerciceChoisi, $depot);
        $nouvelles += $b['nouvelles'];
        $doublons += $b['doublons'];
    }

    $this->messageDepot = "Lecture confirmée : {$nouvelles} pièce(s) enregistrée(s), {$doublons} déjà connue(s).";
    $this->propositions = [];
    $this->depotLu = null;
};

$retirerLesPropositions = function () {
    $this->propositions = [];
    $this->depotLu = null;
};

?>

<div>
    <x-titre-ecran titre="Rapport — exercice {{ $this->exerciceChoisi }}"
        sous-titre="Du 1er janvier au {{ $this->donnees['fin']->format('d/m/Y') }} — le rapport se met à jour avec les données de l'application." />

    {{-- Les boutons alignés en haut : les feuilles de l'Excel. --}}
    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin:6px 0 14px;">
        @foreach ($onglets as $cle => $libelle)
            <button type="button" wire:click="aller('{{ $cle }}')" class="bouton"
                style="padding:8px 14px; font-weight:700; {{ $this->vue === $cle ? 'background:#C8102E; color:#fff; border-color:#C8102E;' : '' }}">{{ $libelle }}</button>
        @endforeach
        <select wire:model.live="exercice" style="margin-left:auto; padding:7px 10px;" aria-label="Exercice">
            @foreach ($this->annees as $a)
                <option value="{{ $a }}">{{ $a }}</option>
            @endforeach
        </select>
    </div>

    @php
        $d = $this->donnees;
        $cm = $this->commentaires;
        $couleurs = ['info' => '#2563EB', 'alerte' => '#D97706', 'bon' => '#0E9F6E'];
        $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 1, ',', ' ').' %';
    @endphp

    @php $remarques = $cm[$this->vue] ?? []; @endphp
    @if ($remarques !== [] && $this->vue !== 'presentation' && $this->vue !== 'depot')
        <div class="carte" style="margin-bottom:14px; padding:12px 14px;">
            <div style="font-weight:800; margin-bottom:6px;">Ce que disent les chiffres</div>
            @foreach ($remarques as $r)
                <div style="border-left:4px solid {{ $couleurs[$r['niveau']] }}; padding:3px 10px; margin:5px 0;">{{ $r['texte'] }}</div>
            @endforeach
        </div>
    @endif

    {{-- ═════════════════════════ Présentation ═════════════════════════ --}}
    @if ($this->vue === 'presentation')
        <div class="carte" style="padding:22px; text-align:center; background:#191B20; color:#fff; border-radius:10px;">
            @if ($this->logo)
                <div style="display:inline-block; background:#fff; padding:10px 16px; border-radius:8px;"><img src="{{ $this->logo }}" alt="Logo de l'entreprise" style="height:72px;"></div>
            @endif
            <div style="font-size:30px; font-weight:800; margin-top:14px; letter-spacing:.5px;">RAPPORT FINANCIER</div>
            <div style="color:#E2E0D8; margin-top:4px;">Chiffre d'affaires · Recouvrement · Charges · Conclusion</div>
            <div style="font-weight:700; margin-top:8px;">Du 1er janvier au {{ $d['fin']->format('d/m/Y') }}</div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:10px; margin:14px 0;">
            <x-kpi-card label="Chiffre d'affaires" :value="ae($d['ca']['total'])" couleur="#C8102E" :sub="$d['ca']['nombre'].' factures'" />
            <x-kpi-card label="Impayés à recouvrer" :value="ae($d['recouvrement']['reste'])" :sub="$d['recouvrement']['nombreClients'].' clients'" />
            <x-kpi-card label="Charges comptées" :value="ae($d['charges']['totalCompte'])" sub="Caisse administrative, saisies, suivi fournisseurs, FNE" />
            <x-kpi-card label="Engagements à couvrir" :value="ae($d['conclusion']['engagements'])" sub="Fournisseurs, salaires, impôts, CNPS" />
        </div>

        <div class="carte" style="padding:14px;">
            <div style="font-weight:800; margin-bottom:6px;">Commentaire de l'assistant</div>
            @foreach (collect($cm)->flatten(1)->take(8) as $r)
                <div style="border-left:4px solid {{ $couleurs[$r['niveau']] }}; padding:3px 10px; margin:5px 0;">{{ $r['texte'] }}</div>
            @endforeach
            @if ($this->iaDispo)
                <button type="button" wire:click="commenterParIA" wire:loading.attr="disabled" class="bouton" style="margin-top:10px; padding:8px 14px;">
                    <span wire:loading.remove wire:target="commenterParIA">Commenter avec l'IA</span><span wire:loading wire:target="commenterParIA">Lecture en cours…</span>
                </button>
                <div style="color:#6B6F78; font-size:12px; margin-top:4px;">Seuls des chiffres agrégés sont transmis (aucun nom de client, de fournisseur ou de salarié), et seulement à ce clic.</div>
            @endif
            @if ($this->commentaireIa !== '')
                <div style="margin-top:10px; padding:10px 12px; background:#F4F3EF; border-radius:8px;">
                    <div style="font-size:11px; font-weight:800; color:#6B6F78; text-transform:uppercase;">Rédigé par l'assistant IA</div>
                    <div style="white-space:pre-line;">{{ $this->commentaireIa }}</div>
                </div>
            @endif
            @if ($this->erreurIa !== '')<div style="color:#C8102E; margin-top:8px;">{{ $this->erreurIa }}</div>@endif
        </div>
    @endif

    {{-- ═════════════════════════ Chiffre d'affaires ═════════════════════════ --}}
    @if ($this->vue === 'ca')
        @php $ca = $d['ca']; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:10px; margin-bottom:14px;">
            <x-kpi-card label="CA de l'entreprise" :value="ae($ca['total'])" couleur="#C8102E" :sub="$ca['nombre'].' factures'" />
            <x-kpi-card label="CA moyen mensuel" :value="ae($ca['moyenneMensuelle'])" sub="Mois pleins uniquement" />
            @if ($ca['nonRattache']['nombre'] > 0)
                <x-kpi-card label="Sans code éditeur" :value="ae($ca['nonRattache']['montant'])" :sub="$ca['nonRattache']['nombre'].' factures : la ville est inconnue'" accent />
            @endif
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:12px;">
            <div class="carte"><table class="tableau"><thead><tr><th>Par ville</th><th style="text-align:right;">Factures</th><th style="text-align:right;">CA</th><th style="text-align:right;">%</th></tr></thead><tbody>
                @foreach ($ca['parVille'] as $l)
                    <tr><td>{{ $l['ville'] }}</td><td style="text-align:right;">{{ $l['nombre'] }}</td><td style="text-align:right;">{{ ae($l['montant']) }}</td><td style="text-align:right;">{{ $ca['total'] > 0 ? $pct($l['montant'] / $ca['total']) : '—' }}</td></tr>
                @endforeach
                @if ($ca['nonRattache']['nombre'] > 0)
                    <tr style="color:#C8102E; font-weight:700;"><td>Non rattaché (sans code éditeur)</td><td style="text-align:right;">{{ $ca['nonRattache']['nombre'] }}</td><td style="text-align:right;">{{ ae($ca['nonRattache']['montant']) }}</td><td style="text-align:right;">{{ $ca['total'] > 0 ? $pct($ca['nonRattache']['montant'] / $ca['total']) : '—' }}</td></tr>
                @endif
                <tr style="font-weight:800;"><td>Total</td><td style="text-align:right;">{{ $ca['nombre'] }}</td><td style="text-align:right;">{{ ae($ca['total']) }}</td><td></td></tr>
            </tbody></table></div>
            <div class="carte"><table class="tableau"><thead><tr><th>Par activité</th><th style="text-align:right;">Factures</th><th style="text-align:right;">CA</th><th style="text-align:right;">%</th></tr></thead><tbody>
                @foreach ($ca['parActivite'] as $l)
                    <tr><td>{{ $l['activite'] }}</td><td style="text-align:right;">{{ $l['nombre'] }}</td><td style="text-align:right;">{{ ae($l['montant']) }}</td><td style="text-align:right;">{{ $ca['total'] > 0 ? $pct($l['montant'] / $ca['total']) : '—' }}</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
        <div class="carte" style="margin-top:12px;"><table class="tableau"><thead><tr><th>Mois</th><th style="text-align:right;">Factures</th><th style="text-align:right;">CA</th></tr></thead><tbody>
            @foreach ($ca['parMois'] as $l)
                <tr><td>{{ $l['libelle'] }}{{ $l['complet'] ? '' : ' (en cours)' }}</td><td style="text-align:right;">{{ $l['nombre'] }}</td><td style="text-align:right;">{{ ae($l['montant']) }}</td></tr>
            @endforeach
        </tbody></table></div>
        <div class="carte" style="margin-top:12px; overflow:auto;"><table class="tableau"><thead><tr><th>Ville × activité</th>
            @foreach (collect($ca['parActivite'])->pluck('activite') as $a)<th style="text-align:right;">{{ $a }}</th>@endforeach</tr></thead><tbody>
            @foreach ($ca['matrice'] as $ville => $acts)
                <tr><td>{{ $ville }}</td>@foreach (collect($ca['parActivite'])->pluck('activite') as $a)<td style="text-align:right;">{{ ae($acts[$a] ?? 0) }}</td>@endforeach</tr>
            @endforeach
        </tbody></table></div>
    @endif

    {{-- ═════════════════════════ Recouvrement ═════════════════════════ --}}
    @if ($this->vue === 'recouvrement')
        @php $rec = $d['recouvrement']; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:10px; margin-bottom:14px;">
            <x-kpi-card label="Total des impayés" :value="ae($rec['reste'])" couleur="#C8102E" :sub="$rec['ouvertes'].' factures ouvertes · '.$rec['nombreClients'].' clients'" />
            <x-kpi-card label="dont exercice {{ $d['exercice'] }}" :value="ae($rec['resteExercice'])" sub="Le reste vient des exercices antérieurs" />
        </div>
        <div class="carte" style="overflow:auto;"><table class="tableau"><thead><tr><th>#</th><th>Client</th><th style="text-align:right;">Factures</th><th style="text-align:right;">Reste dû</th><th style="text-align:right;">dont {{ $d['exercice'] }}</th><th>Plus ancienne</th><th>Dernier règlement</th></tr></thead><tbody>
            @foreach ($rec['top10'] as $i => $t)
                <tr><td>{{ $i + 1 }}</td><td style="font-weight:700;">{{ $t['client'] }}</td><td style="text-align:right;">{{ $t['nb'] }}</td><td style="text-align:right; font-weight:700;">{{ ae($t['reste']) }}</td><td style="text-align:right;">{{ ae($t['resteExercice']) }}</td>
                    <td>{{ $t['plusAncienne']?->format('d/m/Y') ?? '—' }}</td>
                    <td>@if ($t['dernierReglement']){{ $t['dernierReglement']->format('d/m/Y') }}@else<span style="color:#C8102E; font-weight:700;">Aucun règlement</span>@endif</td></tr>
            @endforeach
            <tr style="background:#F4F3EF; font-weight:700;"><td></td><td>Autres clients ({{ $rec['autres']['nombreClients'] }})</td><td></td><td style="text-align:right;">{{ ae($rec['autres']['reste']) }}</td><td style="text-align:right;">{{ ae($rec['autres']['resteExercice']) }}</td><td></td><td></td></tr>
            <tr style="font-weight:800;"><td></td><td>Total</td><td></td><td style="text-align:right;">{{ ae($rec['reste']) }}</td><td style="text-align:right;">{{ ae($rec['resteExercice']) }}</td><td></td><td></td></tr>
        </tbody></table></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:12px; margin-top:12px;">
            <div class="carte"><table class="tableau"><thead><tr><th>Ancienneté</th><th style="text-align:right;">Reste dû</th><th style="text-align:right;">%</th></tr></thead><tbody>
                @foreach ($rec['anciennete'] as $tr => $m)<tr><td>{{ $tr }}</td><td style="text-align:right;">{{ ae($m) }}</td><td style="text-align:right;">{{ $rec['reste'] > 0 ? $pct($m / $rec['reste']) : '—' }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="carte"><table class="tableau"><thead><tr><th>Exercice de la facture</th><th style="text-align:right;">Reste dû</th></tr></thead><tbody>
                @foreach ($rec['parExercice'] as $an => $m)<tr><td>{{ $an }}</td><td style="text-align:right;">{{ ae($m) }}</td></tr>@endforeach
            </tbody></table></div>
        </div>
    @endif

    {{-- ═════════════════════════ Charges ═════════════════════════ --}}
    @if ($this->vue === 'charges')
        @php $ch = $d['charges']; $f = $ch['fournisseurs']; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:10px; margin-bottom:14px;">
            <x-kpi-card label="Charges comptées" :value="ae($ch['totalCompte'])" couleur="#C8102E" sub="Comme sur la page Charges" />
            <x-kpi-card label="Fournisseurs : facturé" :value="ae($f['facture'])" :sub="'Réglé '.ae($f['regle'])" />
            <x-kpi-card label="Fournisseurs : reste à payer" :value="ae($f['reste'])" accent />
            <x-kpi-card label="Impôts à régler" :value="ae($ch['impots']['aRegler'])" :sub="'Payé '.ae($ch['impots']['paye'])" />
            <x-kpi-card label="Salaires non réglés" :value="ae($ch['salaires']['nonRegle'])" :sub="'Payés '.ae($ch['salaires']['paye'])" />
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:12px;">
            <div class="carte"><table class="tableau"><thead><tr><th>Où dépense-t-on le plus ?</th><th style="text-align:right;">Montant</th><th style="text-align:right;">%</th></tr></thead><tbody>
                @foreach ($ch['classement'] as $i => $l)<tr><td>{{ $i + 1 }}. {{ $l['poste'] }}</td><td style="text-align:right;">{{ ae($l['montant']) }}</td><td style="text-align:right;">{{ $ch['totalRapport'] > 0 ? $pct($l['montant'] / $ch['totalRapport']) : '—' }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="carte"><table class="tableau"><thead><tr><th>Fournisseurs</th><th style="text-align:right;">Facturé</th><th style="text-align:right;">Réglé</th><th style="text-align:right;">Reste</th></tr></thead><tbody>
                @foreach (['pieces' => 'Pièces de rechange', 'consommables' => 'Consommables (peinture, lubrifiants)', 'autres' => 'Autres (frais généraux, loyer, CIE…)'] as $k => $lib)
                    <tr><td>{{ $lib }}</td><td style="text-align:right;">{{ ae($f['familles'][$k]['facture']) }}</td><td style="text-align:right;">{{ ae($f['familles'][$k]['regle']) }}</td><td style="text-align:right;">{{ ae($f['familles'][$k]['reste']) }}</td></tr>
                @endforeach
                <tr><td>Factures FNE pas encore au suivi ({{ $f['fne']['nombre'] }})</td><td style="text-align:right;">{{ ae($f['fne']['facture']) }}</td><td style="text-align:right;">—</td><td style="text-align:right;">{{ ae($f['fne']['facture']) }}</td></tr>
            </tbody></table></div>
        </div>
        <div class="carte" style="margin-top:12px;"><table class="tableau"><thead><tr><th>Fournisseurs que l'on doit le plus</th><th style="text-align:right;">Facturé</th><th style="text-align:right;">Réglé</th><th style="text-align:right;">Reste à payer</th></tr></thead><tbody>
            @foreach ($f['top'] as $t)<tr><td>{{ $t['fournisseur'] }}</td><td style="text-align:right;">{{ ae($t['facture']) }}</td><td style="text-align:right;">{{ ae($t['regle']) }}</td><td style="text-align:right; font-weight:700;">{{ ae($t['reste']) }}</td></tr>@endforeach
        </tbody></table></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:12px; margin-top:12px;">
            <div class="carte"><table class="tableau"><thead><tr><th>Ancienneté des dettes fournisseurs</th><th style="text-align:right;">Reste</th></tr></thead><tbody>
                @foreach ($f['anciennete'] as $tr => $m)<tr><td>{{ $tr }}</td><td style="text-align:right;">{{ ae($m) }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="carte"><table class="tableau"><thead><tr><th>Caisse administrative et saisies, par catégorie</th><th style="text-align:right;">Montant</th></tr></thead><tbody>
                @foreach ($ch['categories'] as $l)<tr><td>{{ $l['categorie'] ?: 'Sans catégorie' }} ({{ $l['nombre'] }})</td><td style="text-align:right;">{{ ae($l['montant']) }}</td></tr>@endforeach
            </tbody></table></div>
        </div>
        <div class="carte" style="margin-top:12px;"><table class="tableau"><thead><tr><th>Impôts et CNPS (pièces déposées)</th><th>Date</th><th>Statut</th><th style="text-align:right;">Montant</th></tr></thead><tbody>
            @forelse ($ch['impots']['pieces']->concat($ch['cnps']['pieces']) as $p)
                <tr><td>{{ $p->libelle }}{{ $p->reference ? ' · '.$p->reference : '' }}</td><td>{{ $p->date_piece?->format('d/m/Y') ?? '—' }}</td><td>{{ $p->statut === 'paye' ? 'Payé' : 'À régler' }}</td><td style="text-align:right;">{{ ae($p->montant) }}</td></tr>
            @empty
                <tr><td colspan="4" style="color:#6B6F78;">Aucune pièce — déposez l'avis des impôts ou l'appel CNPS dans « Dépôt de fichiers ».</td></tr>
            @endforelse
        </tbody></table></div>
    @endif

    {{-- ═════════════════════════ Conclusion ═════════════════════════ --}}
    @if ($this->vue === 'conclusion')
        @php $co = $d['conclusion']; $tr = $d['tresorerie']; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:10px; margin-bottom:14px;">
            <x-kpi-card label="Engagements à couvrir" :value="ae($co['engagements'])" couleur="#C8102E" />
            <x-kpi-card label="Impayés à recouvrer" :value="ae($d['recouvrement']['reste'])" />
            <x-kpi-card label="Recouvrement nécessaire" :value="$pct($co['recouvrementNecessaire'])" :sub="'des impayés — '.$pct($co['recouvrementNecessaire26']).' des créances '.$d['exercice']" />
            <x-kpi-card label="Rythme d'encaissement" :value="ae($co['encaissementHebdo'])" :sub="$co['semaines'] !== null ? 'par semaine — environ '.number_format($co['semaines'], 1, ',', ' ').' semaines pour couvrir' : 'par semaine'" />
        </div>
        <div class="carte"><table class="tableau"><thead><tr><th>Engagement</th><th style="text-align:right;">Montant</th><th>Délai / échéance</th></tr></thead><tbody>
            @foreach ($co['lignes'] as $l)<tr><td>{{ $l['poste'] }}</td><td style="text-align:right;">{{ $l['montant'] > 0 ? ae($l['montant']) : '—' }}</td><td>{{ $l['delai'] }}</td></tr>@endforeach
            <tr style="font-weight:800;"><td>Total</td><td style="text-align:right;">{{ ae($co['engagements']) }}</td><td></td></tr>
        </tbody></table></div>
        <div class="carte" style="margin-top:12px;"><table class="tableau"><thead><tr><th>Trésorerie</th><th style="text-align:right;">Au 1er janvier</th><th style="text-align:right;">Actuel</th></tr></thead><tbody>
            @forelse ($tr['banques'] as $b)<tr><td>{{ $b['banque'] }}</td><td style="text-align:right;">{{ $b['depart'] !== null ? ae($b['depart']) : '—' }}</td><td style="text-align:right;">{{ $b['actuel'] !== null ? ae($b['actuel']) : '—' }}{{ $b['au'] ? ' ('.$b['au']->format('d/m').')' : '' }}</td></tr>
            @empty<tr><td colspan="3" style="color:#6B6F78;">Aucun relevé bancaire.</td></tr>@endforelse
            <tr><td>Entrées de la période</td><td></td><td style="text-align:right;">{{ ae($tr['entrees']) }}</td></tr>
            <tr><td>Sorties de la période</td><td></td><td style="text-align:right;">{{ ae($tr['sorties']) }}</td></tr>
            <tr style="font-weight:800;"><td>Solde de fonctionnement</td><td></td><td style="text-align:right;">{{ ae($tr['net']) }}</td></tr>
        </tbody></table></div>
        <div class="carte" style="margin-top:12px; padding:12px 14px;">
            <div style="font-weight:800;">Synthèse</div>
            <div>CA {{ ae($d['ca']['total']) }} · Charges comptées {{ ae($d['charges']['totalCompte']) }} · Impayés {{ ae($d['recouvrement']['reste']) }} · Engagements {{ ae($co['engagements']) }}.</div>
        </div>
    @endif

    {{-- ═════════════════════════ Commerciaux ═════════════════════════ --}}
    @if ($this->vue === 'commerciaux')
        @php $lignesC = $this->rapport->commerciaux(); @endphp
        <div class="carte" style="overflow:auto;"><table class="tableau"><thead><tr><th>Commercial</th><th>Ville</th><th style="text-align:right;">Factures</th><th style="text-align:right;">CA réalisé</th><th style="text-align:right;">Mécanique</th><th style="text-align:right;">Sinistre</th><th style="text-align:right;">Objectif (prorata)</th><th style="text-align:right;">Réalisé</th><th style="text-align:right;">Encours clients</th></tr></thead><tbody>
            @forelse ($lignesC as $l)
                <tr><td style="font-weight:700;">{{ $l['commercial'] }}</td><td>{{ $l['ville'] }}</td><td style="text-align:right;">{{ $l['factures'] }}</td><td style="text-align:right;">{{ ae($l['realisation']) }}</td><td style="text-align:right;">{{ ae($l['mecanique']) }}</td><td style="text-align:right;">{{ ae($l['sinistre']) }}</td><td style="text-align:right;">{{ $l['objectif'] > 0 ? ae($l['objectif']) : '—' }}</td><td style="text-align:right; font-weight:700;">{{ $pct($l['taux']) }}</td><td style="text-align:right;">{{ ae($l['encours']) }}</td></tr>
            @empty
                <tr><td colspan="9" style="color:#6B6F78;">Aucun commercial actif dans votre périmètre.</td></tr>
            @endforelse
        </tbody></table></div>
    @endif

    {{-- ═════════════════════════ Année précédente ═════════════════════════ --}}
    @if ($this->vue === 'precedent')
        @php $ap = $this->rapport->anneePrecedente(); @endphp
        <div class="carte" style="overflow:auto;"><table class="tableau"><thead><tr><th>Grandes lignes</th><th style="text-align:right;">{{ $ap['passe']['exercice'] }}</th><th style="text-align:right;">{{ $ap['actuel']['exercice'] }} (en cours)</th><th style="text-align:right;">Variation</th></tr></thead><tbody>
            @foreach (['ca' => 'Chiffre d\'affaires', 'charges' => 'Charges comptées', 'resultat' => 'Résultat (CA − charges)', 'encaisse' => 'Encaissements clients', 'encours' => 'Encours clients (impayés)'] as $k => $lib)
                <tr><td>{{ $lib }}</td><td style="text-align:right;">{{ ae($ap['passe'][$k]) }}</td><td style="text-align:right;">{{ ae($ap['actuel'][$k]) }}</td><td style="text-align:right;">{{ isset($ap['variations'][$k]) ? $pct($ap['variations'][$k]) : '—' }}</td></tr>
            @endforeach
        </tbody></table></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:12px; margin-top:12px;">
            <div class="carte"><table class="tableau"><thead><tr><th>CA {{ $ap['passe']['exercice'] }} par ville</th><th style="text-align:right;">CA</th></tr></thead><tbody>
                @foreach ($ap['passe']['parVille'] as $l)<tr><td>{{ $l['ville'] }}</td><td style="text-align:right;">{{ ae($l['montant']) }}</td></tr>@endforeach</tbody></table></div>
            <div class="carte"><table class="tableau"><thead><tr><th>CA {{ $ap['passe']['exercice'] }} par activité</th><th style="text-align:right;">CA</th></tr></thead><tbody>
                @foreach ($ap['passe']['parActivite'] as $l)<tr><td>{{ $l['activite'] }}</td><td style="text-align:right;">{{ ae($l['montant']) }}</td></tr>@endforeach</tbody></table></div>
        </div>
        <div style="color:#6B6F78; font-size:12px; margin-top:8px;">Les chiffres de {{ $ap['actuel']['exercice'] }} vont jusqu'à aujourd'hui : la variation compare une année entière à une année en cours. L'encours est l'état actuel des créances de l'exercice.</div>
    @endif

    {{-- ═════════════════════════ Dépôt de fichiers ═════════════════════════ --}}
    @if ($this->vue === 'depot')
        <div class="carte" style="padding:14px; margin-bottom:12px;">
            <div style="font-weight:800;">Déposer un fichier</div>
            <div style="color:#6B6F78; margin:4px 0 10px;">Avis des impôts, CNPS, salaires, récapitulatifs (Excel, CSV, PDF) ou image. Un fichier déjà déposé est refusé ; une ligne déjà connue n'est pas recréée ; un paiement déjà compté par la caisse n'est pas recompté ; un récapitulatif ne sert qu'au contrôle.</div>
            @if ($this->peutDeposer)
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <input type="file" wire:model="fichier" accept=".xlsx,.xls,.csv,.txt,.pdf,.jpg,.jpeg,.png,.webp">
                    <select wire:model="natureDepot" style="padding:7px 10px;"><option value="">Nature : la deviner</option>@foreach (\Modules\Noyau\Rapport\Support\LectureDesPieces::NATURES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    <button type="button" wire:click="deposer" wire:loading.attr="disabled" class="bouton" style="padding:8px 14px;">Déposer</button>
                </div>
                @error('fichier')<div style="color:#C8102E; margin-top:6px;">{{ $message }}</div>@enderror
            @else
                <div style="color:#D97706;">Seul le gérant dépose des fichiers.</div>
            @endif
            @if ($this->messageDepot !== '')<div style="color:#0E9F6E; margin-top:8px; font-weight:700;">{{ $this->messageDepot }}</div>@endif
            @if ($this->erreurDepot !== '')<div style="color:#C8102E; margin-top:8px; font-weight:700;">{{ $this->erreurDepot }}</div>@endif
        </div>

        @if ($this->peutDeposer)
            <div class="carte" style="padding:14px; margin-bottom:12px;">
                <div style="font-weight:800; margin-bottom:6px;">Saisir une pièce à la main</div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                    <select wire:model="pieceNature" style="padding:7px 10px;">@foreach (\Modules\Noyau\Rapport\Support\LectureDesPieces::NATURES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    <select wire:model="pieceStatut" style="padding:7px 10px;"><option value="du">À régler</option><option value="paye">Payé</option></select>
                    <input type="date" wire:model="pieceDate" style="padding:7px 10px;">
                    <input type="text" wire:model="pieceLibelle" placeholder="Libellé (ex. Droits simples)" style="padding:7px 10px; min-width:220px;">
                    <input type="text" wire:model="pieceReference" placeholder="Référence (ex. avis n° 329)" style="padding:7px 10px;">
                    <input type="number" wire:model="pieceMontant" placeholder="Montant FCFA" style="padding:7px 10px; width:150px;">
                    <button type="button" wire:click="saisir" class="bouton" style="padding:8px 14px;">Enregistrer</button>
                </div>
                @foreach (['pieceLibelle', 'pieceMontant', 'pieceDate'] as $champ)@error($champ)<div style="color:#C8102E; margin-top:4px;">{{ $message }}</div>@enderror @endforeach
            </div>
        @endif

        @if ($this->propositions !== [])
            <div class="carte" style="padding:14px; margin-bottom:12px; border:1px solid #D97706;">
                <div style="font-weight:800;">Lecture de l'assistant — à confirmer (rien n'est encore enregistré)</div>
                <table class="tableau"><thead><tr><th>Nature</th><th>Statut</th><th>Libellé</th><th>Référence</th><th style="text-align:right;">Montant</th></tr></thead><tbody>
                    @foreach ($this->propositions as $p)<tr><td>{{ $p['nature'] }}</td><td>{{ $p['statut'] === 'paye' ? 'Payé' : 'À régler' }}</td><td>{{ $p['libelle'] }}</td><td>{{ $p['reference'] }}</td><td style="text-align:right;">{{ ae($p['montant']) }}</td></tr>@endforeach
                </tbody></table>
                <div style="margin-top:8px; display:flex; gap:8px;"><button type="button" wire:click="confirmerLesPropositions" class="bouton" style="padding:8px 14px;">Confirmer et enregistrer</button><button type="button" wire:click="retirerLesPropositions" class="bouton" style="padding:8px 14px;">Écarter</button></div>
            </div>
        @endif

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(380px,1fr)); gap:12px;">
            <div class="carte" style="overflow:auto;"><table class="tableau"><thead><tr><th>Fichiers déposés</th><th>Résultat</th><th></th></tr></thead><tbody>
                @forelse ($this->depots as $dep)
                    <tr><td>{{ $dep->nom_original }}<div style="font-size:11px; color:#6B6F78;">{{ $dep->created_at?->format('d/m/Y H:i') }}{{ $dep->deposant ? ' · '.$dep->deposant->name : '' }}</div></td>
                        <td>{{ $dep->message }}</td>
                        <td>@if ($this->peutDeposer && $this->iaDispo && $dep->statut === 'a_completer' && preg_match('/\.(jpe?g|png|webp)$/i', $dep->nom_original))<button type="button" wire:click="lireParIA({{ $dep->id }})" class="bouton" style="padding:4px 10px; font-size:12px;">Lire avec l'IA</button>@endif</td></tr>
                @empty
                    <tr><td colspan="3" style="color:#6B6F78;">Aucun fichier déposé.</td></tr>
                @endforelse
            </tbody></table></div>
            <div class="carte" style="overflow:auto;"><table class="tableau"><thead><tr><th>Pièces de {{ $this->exerciceChoisi }}</th><th>Statut</th><th style="text-align:right;">Montant</th><th>Compte ?</th></tr></thead><tbody>
                @forelse ($this->pieces as $p)
                    <tr><td>{{ $p->libelle }}<div style="font-size:11px; color:#6B6F78;">{{ \Modules\Noyau\Rapport\Support\LectureDesPieces::NATURES[$p->nature] ?? $p->nature }}{{ $p->reference ? ' · '.$p->reference : '' }}</div></td>
                        <td>{{ ['du' => 'À régler', 'paye' => 'Payé', 'info' => 'Info'][$p->statut] ?? $p->statut }}</td><td style="text-align:right;">{{ ae($p->montant) }}</td>
                        <td>{{ $p->compte ? 'Oui' : 'Non' }}</td></tr>
                @empty
                    <tr><td colspan="4" style="color:#6B6F78;">Aucune pièce.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    @endif
</div>
