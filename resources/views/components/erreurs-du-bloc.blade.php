@props(['prefixe'])

{{-- Ce qu'un formulaire a refusé, dit dans le formulaire qui l'a refusé.

     **Le défaut que ce composant ferme.** Une carte n'affichait que deux erreurs
     nommément — le n° de facture et le courtier. Toutes les autres partaient dans le sac
     des erreurs sans qu'aucune ligne ne les rende : le client obligatoire, l'atelier, la
     date, le montant. Le serveur refusait, l'écran ne bougeait pas, et le geste se lisait
     comme « le bouton ne réagit pas » — relevé par le propriétaire le 24/09 sur « Créer
     une facture ».

     **Pourquoi un préfixe plutôt que `$errors->any()`.** Trois formulaires cohabitent sur
     l'écran du recouvrement — encaisser, relancer, créer. Afficher toutes les erreurs dans
     les trois cartes ferait répondre la mauvaise : on lirait « le tiers est obligatoire »
     sous un bouton qui vient d'accepter le sien. Les champs d'une même carte partagent
     leur préfixe (`enc`, `rel`, `fac`) ; c'est ce qui les rassemble ici.

     Ce composant complète les messages écrits à la main, il ne les remplace pas : une
     règle de métier mérite souvent une phrase qui explique, pas seulement un refus. --}}

@php
    $messages = collect($errors->getMessages())
        ->filter(fn ($lignes, $champ) => str_starts_with($champ, $prefixe))
        ->flatten();
@endphp

@if ($messages->isNotEmpty())
    <div class="rec-hint warn" role="alert">
        <strong>Enregistrement refusé.</strong>
        <ul style="margin:6px 0 0; padding-left:18px;">
            @foreach ($messages as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
