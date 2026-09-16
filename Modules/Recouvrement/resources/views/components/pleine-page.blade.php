@props([
    'titre',
    'sousTitre' => null,
    'retour' => null,
    'retourLibelle' => 'Retour',
])

{{-- La mise en page pleine largeur du module — pour les écrans qui lisent, pas qui saisissent.

     **Pourquoi le tableau de bord n'a pas de barre latérale.** La coquille du module range
     ses neuf écrans de travail dans un menu de gauche, et c'est juste : on y passe de la
     balance à l'extrait, de l'extrait aux relances. Le tableau de bord n'est pas l'un de
     ces écrans, c'est celui d'où l'on part. Le poser à côté des autres le faisait
     apparaître comme une dixième page de travail, et lui volait deux cent trente-six
     points de large — ceux qui manquaient à un tableau de onze colonnes.

     Il garde en tête un lien vers le module, parce qu'on y va tout de suite après.

     **Les filtres occupent une bande, en pleine largeur.** Coincés dans le coin supérieur
     droit de la coquille, ils se repliaient sur quatre lignes et doublaient le sélecteur de
     ville du bandeau. Ici ils tiennent sur une ligne, deux au plus. --}}

{{-- **La feuille de style est à l'intérieur du bloc, et elle doit y rester.**

     Livewire prend le **premier élément** du rendu comme racine du composant : c'est lui
     qui porte `wire:id`, et c'est à l'intérieur de lui seul que `wire:model`, `wire:click`
     et `wire:navigate` sont branchés. Posée avant le bloc, cette balise `<style>` devenait
     cette racine — et tout l'écran se retrouvait dehors, donc inerte. Mesuré : les listes
     déroulantes ne remontaient plus leur valeur au serveur, la liste des factures d'un
     tiers restait vide quel que soit le tiers choisi, et les boutons d'enregistrement ne
     déclenchaient rien. Quinze écrans étaient dans ce cas, tout le module Recouvrement et
     tout le module Import.

     Rien ne le signalait : la page s'affichait parfaitement, elle ne répondait simplement
     pas. Un test le garde désormais — voir RacineDesComposantsTest. --}}
<div class="rec-pleine">
    <x-recouvrement::styles />

    <style>
        .rec-pleine { font-family:var(--font-sans); font-size:16px; }
        .rec-pleine .entete { display:flex; justify-content:space-between; align-items:flex-end;
                              gap:16px; flex-wrap:wrap; margin-bottom:14px; }
        .rec-pleine h1 { font-family:'Barlow Condensed',sans-serif; font-size:28px; font-weight:700;
                         text-transform:uppercase; letter-spacing:1px; margin:0;
                         color:var(--th-steel,#2A2E35); }
        .rec-pleine .sub { color:var(--th-gris,#6B6E76); font-size:13.5px; margin-top:3px; }
        .rec-pleine .barre { background:#fff; border:1px solid var(--th-ligne,#E3E0D8); border-radius:10px;
                             padding:11px 14px; margin-bottom:15px; }
        @media print { .rec-pleine .barre, .rec-pleine .entete a, .no-print { display:none !important; } }
    </style>

    <div class="entete">
        <div>
            <h1>{{ $titre }}</h1>
            @if ($sousTitre)
                <div class="sub">{{ $sousTitre }}</div>
            @endif
        </div>

        <div style="display:flex; align-items:center; gap:9px; flex-wrap:wrap;">
            {{-- La ville regardée, comme dans la coquille du module. Elle manquait ici : le
                 tableau de bord lisait bien la ville choisie ailleurs, mais n'offrait aucun
                 moyen de la choisir — on ne pouvait ni la voir ni la changer depuis l'écran
                 d'où l'on part. Le sélecteur ne s'affiche que s'il y a plusieurs villes. --}}
            <livewire:commun.selecteur-ville />
            {{ $actions ?? '' }}

            @if ($retour)
                <a href="{{ $retour }}" class="rec-btn o" style="text-decoration:none;">
                    {{ $retourLibelle }}
                </a>
            @endif
        </div>
    </div>

    @isset($filtres)
        <div class="barre">{{ $filtres }}</div>
    @endisset

    @if (session('refus-recouvrement'))
        <div class="rec-lock" style="margin-bottom:14px;">🔒 {{ session('refus-recouvrement') }}</div>
    @endif

    {{ $slot }}
</div>
