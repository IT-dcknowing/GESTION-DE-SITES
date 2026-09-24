@props([
    'label',
    'value',
    'sub' => null,
    'accent' => false,
    'couleur' => null,
    'bon' => false,
    'mecanique' => null,
    'sinistre' => null,
    // Part de l'indicateur dont l'activité n'est pas renseignée. Affichée seulement si
    // elle n'est pas nulle : les trois lignes doivent toujours refaire le total exact,
    // faute de quoi le lecteur croirait à une erreur de calcul.
    'nonVentile' => null,
    // Des lignes libres sous la valeur, sous la forme libellé => montant. Utile quand un
    // indicateur ne se comprend qu'en montrant les deux chiffres qu'il compare : afficher
    // l'écart seul obligerait à aller chercher ailleurs ce qu'il oppose à quoi.
    'lignes' => null,
])

<div class="carte carte-kpi {{ $accent ? 'est-alerte' : ($bon ? 'est-bon' : '') }}">
    <div class="kpi-libelle">{{ $label }}</div>
    <div class="kpi-valeur {{ $accent ? 'est-alerte' : ($bon ? 'est-bon' : '') }}"
        @if ($couleur) style="color:{{ $couleur }};" @endif>{{ $value }}</div>
    @if ($sub)
        <div class="kpi-sous">{{ $sub }}</div>
    @endif
    @if ($lignes)
        <div style="margin-top:7px; padding-top:7px; border-top:1px solid var(--th-ligne,#E2E0D8); display:flex; flex-direction:column; gap:3px;">
            @foreach ($lignes as $intitule => $montant)
                <div style="display:flex; justify-content:space-between; gap:10px; font-size:11.5px; color:#4B4E55;">
                    <span>{{ $intitule }}</span>
                    <b style="font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $montant }}</b>
                </div>
            @endforeach
        </div>
    @endif
    @if ($mecanique !== null || $sinistre !== null || $nonVentile !== null)
        <div style="margin-top:7px; padding-top:7px; border-top:1px solid var(--th-ligne,#E2E0D8); display:flex; flex-direction:column; gap:3px;">
            @if ($mecanique !== null)
                {{-- Le libellé à gauche, le montant à droite, et le montant ne se coupe
                     jamais : c'est le même parti que les lignes libres ci-dessus. Posés
                     bout à bout, « Mécanique 854 947 305 F » cassait au milieu du nombre
                     dès que la carte se rétrécissait, et l'on lisait « 854 947 305 » sur
                     une ligne et « F » sur la suivante. --}}
                <div style="display:flex; align-items:baseline; gap:6px; font-size:11.5px; color:#4B4E55;">
                    <span style="flex:0 0 auto; align-self:center; width:3px; height:11px; border-radius:2px; background:#2563EB;"></span>
                    <span style="flex:1 1 auto; min-width:0;">Mécanique</span>
                    <b style="flex:0 0 auto; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $mecanique }}</b>
                </div>
            @endif
            @if ($sinistre !== null)
                <div style="display:flex; align-items:baseline; gap:6px; font-size:11.5px; color:#4B4E55;">
                    <span style="flex:0 0 auto; align-self:center; width:3px; height:11px; border-radius:2px; background:#D97706;"></span>
                    <span style="flex:1 1 auto; min-width:0;">Sinistre</span>
                    <b style="flex:0 0 auto; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $sinistre }}</b>
                </div>
            @endif
            @if ($nonVentile !== null)
                {{-- « Autres », et non « Non ventilé ». Le second est le mot du comptable :
                     il décrit ce que le calcul n'a pas su faire, pas ce que le lecteur
                     regarde. Or cette ligne se lit à côté de « Mécanique » et « Sinistre »,
                     et à cette place on attend le nom d'une troisième part, pas le constat
                     d'une lacune. L'infobulle dit toujours d'où vient le montant. --}}
                <div style="display:flex; align-items:baseline; gap:6px; font-size:11.5px; color:var(--th-gris,#6B6E76);"
                    title="Opérations saisies sans précision d'activité — ni mécanique, ni sinistre.">
                    <span style="flex:0 0 auto; align-self:center; width:3px; height:11px; border-radius:2px; background:#9CA3AF;"></span>
                    <span style="flex:1 1 auto; min-width:0;">Autres</span>
                    <b style="flex:0 0 auto; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $nonVentile }}</b>
                </div>
            @endif
        </div>
    @endif
</div>
