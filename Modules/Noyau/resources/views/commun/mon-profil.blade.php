<?php


/**
 * Mon profil : qui je suis, et sous quels codes mon travail est retrouvable.
 *
 * **Deux codes cohabitent, et les confondre coûte cher.** Ils n'ont ni la même origine ni
 * le même rôle, et l'écran les met côte à côte pour qu'on cesse de les prendre l'un pour
 * l'autre :
 *
 * - **le code de saisie**, produit par cette plateforme — `A-C-KY-0007`. Il signe chaque
 *   ligne saisie ici et dit qui l'a écrite. Il ne se choisit pas : il se déduit de la ville,
 *   du rôle et du nom, et se fige à la première saisie pour qu'un changement de poste ne
 *   réécrive pas l'histoire de ce qui a déjà été fait. Il est donc affiché, jamais modifiable ;
 * - **l'identifiant de liaison**, les deux lettres du logiciel d'atelier — `KZ`, `AB`. Il
 *   vient de l'autre logiciel, et il est modifiable ici.
 *
 * **Pourquoi l'identifiant de liaison n'est pas obligatoire.** Il a d'abord servi à deviner
 * de quel atelier venait une ligne importée. Ce n'est plus nécessaire : l'extraction est
 * filtrée par site avant d'être déposée, et le dépôt le déclare. Il garde deux usages, tous
 * deux facultatifs — la **traçabilité**, savoir qui a rédigé une fiche ; et le **rattachement
 * des prospections à un commercial**, sans quoi ses indicateurs restent vides.
 *
 * Et tout le monde ne saisit pas dans le logiciel d'atelier : exiger ce code aurait obligé
 * les trois quarts des comptes à inventer une valeur pour se débarrasser d'un champ rouge.
 */
use function Livewire\Volt\computed;

$utilisateur = computed(fn () => auth()->user());

?>

<div style="max-width:720px; margin:0 auto;">

    <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:800; margin:0 0 3px;">
        Mon profil
    </h1>
    <p style="color:#6B6E76; font-size:13.5px; margin:0 0 18px;">
        {{ $this->utilisateur->name }} — {{ $this->utilisateur->email }}
    </p>

    {{-- Le bloc est écrit une fois, dans un composant, et servi ici comme sur
         « Mon espace » : recopié, il aurait fini par diverger. --}}
    <x-codes-du-profil />

    <div style="margin-top:14px;">
        <a href="{{ route('mot-de-passe.modifier') }}" wire:navigate
           style="display:inline-block; padding:8px 15px; border-radius:7px; border:1.5px solid #191B20;
                  color:#191B20; text-decoration:none; font-size:13.5px; font-weight:700;">
            Changer mon mot de passe
        </a>
    </div>
</div>
