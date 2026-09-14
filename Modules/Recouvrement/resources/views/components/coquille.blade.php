@props(['page', 'actions' => null])

@php
    use Modules\Recouvrement\Support\AccesRecouvrement;

    $utilisateur = auth()->user();
    $ouvertes = AccesRecouvrement::pagesDe($utilisateur);
    $roleLisible = \Modules\Noyau\Entreprises\Support\LibellesRoles::de(AccesRecouvrement::role($utilisateur));
@endphp

{{--
    La barre latérale de la section.

    Le module garde ses couleurs et ses codes — les pastilles N1 à N5, le noir des
    en-têtes de tableau, le rouge du contentieux — parce que ce sont eux qui font lire un
    encours d'un coup d'œil. Les styles sont posés ici, dans la coquille, plutôt que dans
    la feuille globale : ils ne servent qu'à ce module, et une classe « .pill » lâchée
    dans le style commun finirait par repeindre un écran qui ne l'a pas demandé.

    La **typographie**, elle, est celle du reste de l'application, et non celle de la
    maquette d'origine : le texte courant dans la police du bandeau de navigation, les
    titres et les chiffres en Barlow Condensed, aux mêmes tailles que les cartes et les
    tableaux des autres écrans. Une section qui n'écrit pas comme le reste se lit comme
    un autre logiciel — et le recouvrement s'ouvre depuis le même bandeau que la
    trésorerie, à deux clics d'écart.
--}}
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
<div class="rec">
    <x-recouvrement::styles />

    <aside class="rec-side">
        <div class="rec-badge">
            <div class="role">{{ $roleLisible }}</div>
            <span class="tag">{{ AccesRecouvrement::habilitation($utilisateur) }}</span>
            <div class="nom">Session : {{ $utilisateur?->name }}</div>
        </div>

        <nav class="rec-nav">
            {{-- **Seules les pages ouvertes sont listées.** On affichait autrefois les autres
                 en grisé, pour dire qu'elles existaient et relevaient de quelqu'un d'autre.
                 L'intention était bonne, l'effet non : la moitié d'un menu qui ne répond pas
                 se lit comme un logiciel en panne, et l'on essaie quand même, plusieurs fois.
                 Ce que chacun peut faire se dit mieux par ce qu'on lui montre que par ce
                 qu'on lui refuse. L'adresse, elle, reste fermée par le middleware — c'est là
                 que la fermeture compte, pas dans une barre latérale. --}}
            @foreach (AccesRecouvrement::PAGES as $clef => $entree)
                @if (in_array($clef, $ouvertes, true))
                    <a href="{{ route('recouvrement.'.$clef) }}" wire:navigate
                       class="{{ $page === $clef ? 'on' : '' }}">
                        <span class="ic">{{ $entree['icone'] }}</span>{{ $entree['libelle'] }}
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="rec-foot">
            {{ auth()->user()?->entreprise?->nom ?? 'Recouvrement' }}<br>
            Protocole N1 J+7 · N2 J+15 · N3 J+30 · N4 J+60 · N5 J+90 (AUPSRVE).
        </div>
    </aside>

    <div class="rec-main">
        <div class="rec-top">
            <div>
                <h1>{{ AccesRecouvrement::PAGES[$page]['libelle'] }}</h1>
                <div class="sub">{{ AccesRecouvrement::SOUS_TITRES[$page] }}</div>
            </div>
            {{-- La ville regardée, à côté de la date d'arrêté : ce sont les deux réglages
                 qui déterminent ce que montre la page. Le module porte sur l'entreprise
                 entière ; ce sélecteur ne restreint pas les droits, il restreint la vue —
                 préparer une visite à Bouaké sur une balance qui mélange trois villes est
                 une opération à laquelle on n'arrive pas. --}}
            <div class="rec-datebox" style="display:flex; align-items:center; gap:9px;">
                <livewire:commun.selecteur-ville />
                @if ($actions)
                    {{ $actions }}
                @endif
            </div>
        </div>

        @if (session('refus-recouvrement'))
            <div class="rec-lock" style="margin-bottom:14px;">🔒 {{ session('refus-recouvrement') }}</div>
        @endif

        {{ $slot }}
    </div>
</div>
