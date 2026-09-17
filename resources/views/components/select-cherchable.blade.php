@props([
    'id',
    'label' => null,
    'options' => [],
    'source' => null,
    'valeur' => '',
    'vide' => '— à choisir —',
    'model' => null,
    'name' => null,
    'placeholder' => 'Rechercher…',
    'seuil' => 12,
])

{{-- Une liste déroulante qu'on fouille **par l'intérieur**.

     **Le problème mesuré.** La liste des tiers du recouvrement comptait deux mille quatre
     cent quarante-cinq entrées, ramenées depuis à cent vingt-huit. Ouverte, une liste de
     cette taille occupe l'écran et se parcourt à la molette : trouver « NSIA ASSURANCES »
     demande une minute, et l'on finit par saisir un doublon plutôt que de chercher.

     **Pourquoi le champ de recherche est passé dedans.** Il était posé au-dessus de la
     liste, et ce n'était pas la même chose : on tapait dans une case, le résultat se
     produisait dans une autre, et la liste fermée continuait d'afficher son ancienne
     valeur. Un filtre séparé de ce qu'il filtre se lit comme deux champs à remplir. Il est
     maintenant dans le panneau qui s'ouvre, au-dessus des choix qu'il réduit — on ouvre,
     on tape, on choisit, d'un seul geste.

     **Ce que le panneau ne fait pas.** Il ne remplace pas le champ, il l'habille. En
     dessous il y a toujours un `<select>` natif — ou un `<input list>` quand la liste est
     partagée — qui poste sa valeur, fonctionne au clavier, et fonctionne sans JavaScript.
     Si le script ne s'exécute pas, on perd le confort, jamais la fonction. C'est la règle
     de la maison depuis le défaut d'import.

     **Le libellé affiché ne ment jamais.** Il est relu depuis le champ réel à chaque
     changement. L'ancienne version affichait le premier choix resté visible après filtrage
     alors que rien n'était sélectionné : on lisait « BIA-CI » dans une liste dont la
     valeur était vide, et l'écran d'à côté répondait « sélectionnez d'abord un tiers ».

     Deux façons de fournir les choix :

     - `options` : un tableau valeur => libellé. La liste vit dans le `<select>`.
     - `source` : l'identifiant d'un `<datalist>` partagé, quand plusieurs champs offrent le
       même annuaire. Trois `<select>` de deux mille quatre cents entrées pesaient 928 Ko ;
       un `<datalist>` partagé en pèse 62.

     `model` branche le champ sur une propriété Livewire ; `name` en fait un champ de
     formulaire HTML ordinaire. L'un ou l'autre, jamais les deux. --}}

@php
    $nombre = $source ? null : count($options);
    $cherchable = $source !== null || $nombre > (int) $seuil;
@endphp

<div class="sel-ch" data-sel-ch @if ($cherchable) data-sel-cherchable @endif
     @if ($source) data-sel-source="{{ $source }}" @endif
     data-sel-vide="{{ $vide }}" data-sel-invite="{{ $placeholder }}">

    @if ($label)
        <label for="{{ $id }}">{{ $label }}</label>
    @endif

    @if ($source)
        {{-- Sans script, c'est la liste native du navigateur qui rend le service : elle
             filtre déjà à la frappe. Le panneau ne fait que lui donner l'apparence et le
             comportement des autres champs de l'application. --}}
        <input type="text" id="{{ $id }}" data-sel-champ list="{{ $source }}" autocomplete="off"
            value="{{ $valeur }}" placeholder="{{ $vide }}"
            @if ($model) wire:model.blur="{{ $model }}" @endif
            @if ($name) name="{{ $name }}" @endif
            {{ $attributes->except(['class']) }}>
    @else
        <select id="{{ $id }}" data-sel-champ data-sel-liste
            @if ($model) wire:model.live="{{ $model }}" @endif
            @if ($name) name="{{ $name }}" @endif
            {{ $attributes->except(['class']) }}>
            <option value="" @selected((string) $valeur === '')>{{ $vide }}</option>
            @foreach ($options as $cle => $libelle)
                <option value="{{ $cle }}" @selected((string) $valeur === (string) $cle)>{{ $libelle }}</option>
            @endforeach
        </select>
    @endif
</div>

@once
    {{-- Le style et le script vivent à part : un écran qui ouvre une de ces listes plus tard,
         par un clic, les charge dès son premier affichage — voir select-cherchable-ressources. --}}
    @include('components.select-cherchable-ressources')
@endonce
