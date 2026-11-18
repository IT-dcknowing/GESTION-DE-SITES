<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\IA\Modeles\ProductionIA;
use function Livewire\Volt\{computed, mount, state};

/*
 * Un document produit par l'assistant du rapport — 10/11. Il s'ouvre dans l'application et
 * s'enregistre en PDF par « Imprimer / PDF » du navigateur : l'impression ne garde que le document.
 * Habilitations `rapport.voir` et `rapport.ia` ; le document appartient à l'entreprise du compte
 * (portée automatique du modèle).
 */
state(['productionId' => null]);

mount(function (int $production) {
    abort_unless(
        HabilitationsDActions::accorde(auth()->user(), 'rapport', 'voir') && HabilitationsDActions::accorde(auth()->user(), 'rapport', 'ia'),
        403,
    );

    $this->productionId = ProductionIA::query()->findOrFail($production)->id;
});

$doc = computed(fn () => ProductionIA::query()->findOrFail($this->productionId));

?>

<div class="rp-doc">
    @php $st = $this->doc->styleSur(); @endphp
    <style>
        .rp-doc .rp-barre{display:flex;gap:8px;margin-bottom:12px}
        .rp-doc .rp-btn{padding:9px 16px;font-weight:800;font-size:13px;border:1px solid #C8102E;background:#C8102E;color:#fff;border-radius:6px;cursor:pointer}
        .rp-doc .rp-btn.neutre{background:#fff;color:#191B20;border-color:#D4D1C7}
        .rp-doc .papier{background:var(--fond);border-left:10px solid var(--acc);border-radius:6px;padding:26px 32px;max-width:900px}
        .rp-doc .papier h1.titre{margin:0 0 4px;font-size:24px;text-transform:uppercase;color:var(--acc)}
        .rp-doc .papier .sous{color:#555;font-size:12px;margin-bottom:14px}
        .rp-doc h1,.rp-doc h2,.rp-doc h3{color:var(--acc)}
        .rp-doc table{border-collapse:collapse;width:100%;margin:10px 0}
        .rp-doc th{background:var(--acc);color:#fff;padding:7px 10px;text-align:left}
        .rp-doc td{border:1px solid #D4D1C7;padding:7px 10px;background:#fff}
        .rp-doc .colonnes .corps{column-count:2;column-gap:34px}
        .rp-doc .papier.sobre{border-left:0;background:#fff;border:1px solid #E2E0D8}
        .rp-doc .papier.sobre h1,.rp-doc .papier.sobre h2,.rp-doc .papier.sobre h3{color:#191B20}
        @media print{
            body *{visibility:hidden}
            .rp-doc,.rp-doc *{visibility:visible}
            .rp-doc{position:absolute;left:0;top:0;width:100%}
            .rp-doc .rp-barre{display:none}
            .rp-doc .papier{border-radius:0;max-width:none;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        }
    </style>

    <div class="rp-barre">
        <button type="button" class="rp-btn" onclick="window.print()">Imprimer / enregistrer en PDF</button>
        <a href="{{ route('rapport', ['vue' => 'ia']) }}" wire:navigate class="rp-btn neutre" style="text-decoration:none;">Retour au rapport</a>
    </div>

    <div class="papier {{ $st['mise_en_page'] }} {{ $st['mise_en_page'] === 'colonnes' ? 'colonnes' : '' }}" style="--acc:{{ $st['accent'] }}; --fond:{{ $st['fond'] }};">
        <h1 class="titre">{{ $this->doc->titre }}</h1>
        <div class="sous">{{ auth()->user()->entreprise?->nom }} — établi le {{ $this->doc->created_at->format('d/m/Y') }} par l'assistant IA</div>
        <div class="corps">{!! $this->doc->html() !!}</div>
    </div>
</div>
