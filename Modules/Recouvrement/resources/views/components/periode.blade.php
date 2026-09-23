@props([
    'route',
    'periode',
    'recherche' => null,
    'placeholder' => 'Filtrer…',
    'parametres' => [],
])

@php
    use Modules\Noyau\Commun\Services\PeriodeCalculateur;
    use Modules\Noyau\Commun\Services\PeriodeCalculateur as Calendrier;
    use Illuminate\Support\Carbon;

    $mois = Calendrier::moisDeLAnnee();

    $semaines = $periode->mois !== null
        ? Calendrier::semainesDuMois(Carbon::create($periode->annee, $periode->mois, 1))
        : [];

    $jours = $periode->mois !== null
        ? range(1, Carbon::create($periode->annee, $periode->mois, 1)->daysInMonth)
        : [];

    $etiquette = 'display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.7px;'
        .' color:#5A6472; font-weight:700; margin-bottom:3px;';
    $champ = 'border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px;'
        .' background:var(--th-champ,#FFFBEA); font-family:inherit;';
@endphp

{{-- La période du recouvrement — et pourquoi elle a remplacé une date libre.

     Chaque écran portait un champ « Date de l'écriture » : une date tapée à la main, qui
     faisait deux métiers à la fois. Sur la saisie, c'était la date de l'écriture qu'on
     s'apprêtait à enregistrer ; partout ailleurs, c'était l'arrêté de l'encours. Un même
     champ pour deux métiers finit toujours par nuire aux deux — reculer la date pour
     consulter un encours passé changeait aussi la date du prochain encaissement.

     Ici on choisit une **période** — exercice, mois, semaine, jour — comme partout ailleurs
     dans l'application, et **l'arrêté s'en déduit** : sa dernière journée. Il est écrit en
     toutes lettres à côté, parce qu'un chiffre arrêté à une date qu'on ne voit pas est un
     chiffre qu'on ne peut pas défendre.

     Formulaire GET : il filtre avec ou sans script, et l'adresse obtenue se transmet. --}}

<form method="GET" action="{{ route($route) }}" data-filtre-auto
      style="background:#fff; border:1px solid #E3E0D8; border-radius:10px; padding:9px 12px;
             display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">

    @foreach ($parametres as $nom => $valeur)
        <input type="hidden" name="{{ $nom }}" value="{{ $valeur }}">
    @endforeach

    <div>
        <label for="p-mois" style="{{ $etiquette }}">Exercice {{ $periode->annee }}</label>
        <select id="p-mois" name="moisFiltre" wire:model.live="moisFiltre" style="{{ $champ }}">
            <option value="" @selected($periode->mois === null)>Tous les mois</option>
            @foreach ($mois as $numero => $libelle)
                <option value="{{ $numero }}" @selected($periode->mois === $numero)>{{ $libelle }}</option>
            @endforeach
        </select>
    </div>

    {{-- Semaine et jour ne s'ouvrent qu'une fois le mois choisi : « semaine 3 » de rien du
         tout ne veut rien dire, et un champ actif qui ne fait rien se lit comme une panne. --}}
    <div>
        <label for="p-semaine" style="{{ $etiquette }}">Semaine</label>
        <select id="p-semaine" name="semaineFiltre" wire:model.live="semaineFiltre"
                style="{{ $champ }}" @disabled($periode->mois === null)>
            <option value="" @selected($periode->semaine === null)>Toutes les semaines</option>
            @foreach ($semaines as $semaine)
                <option value="{{ $semaine['numero'] }}" @selected($periode->semaine === (int) $semaine['numero'])>
                    Semaine {{ $semaine['numero'] }} ({{ $semaine['debut']->format('d/m') }} – {{ $semaine['fin']->format('d/m') }})
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="p-jour" style="{{ $etiquette }}">Jour</label>
        <select id="p-jour" name="jourFiltre" wire:model.live="jourFiltre"
                style="{{ $champ }}" @disabled($periode->mois === null)>
            <option value="" @selected($periode->jour === null)>Tous les jours</option>
            @foreach ($jours as $j)
                <option value="{{ $j }}" @selected($periode->jour === $j)>Jour {{ $j }}</option>
            @endforeach
        </select>
    </div>

    {{-- Il y avait ici un champ « Ville » qui ne servait à rien : aucun écran ne lisait
         le paramètre qu'il envoyait, et il doublait à l'identique le sélecteur de ville du
         bandeau, posé juste à côté. Deux listes côte à côte portant le même intitulé, dont
         une seule agit, c'est pire qu'un réglage absent. La ville se choisit dans le
         bandeau, une fois, pour toute l'application. --}}

    {{-- La recherche filtre à la frappe, comme partout ailleurs dans l'application.

         **Pourquoi elle ne faisait rien.** C'était un champ de formulaire ordinaire : il
         fallait appuyer sur Entrée pour qu'il parte, et rien ne le disait. On tapait un nom,
         on regardait le tableau, il ne bougeait pas — et la seule conclusion possible était
         que le filtre était cassé. Les listes déroulantes, elles, partent au changement :
         deux champs côte à côte dont un seul réagit, c'est pire qu'un champ mort.

         Le `name` reste : sans script, le formulaire l'emporte toujours, et la touche Entrée
         fait le même travail. Les deux chemins mènent au même endroit. --}}
    @if ($recherche !== null)
        <div>
            <label for="p-recherche" style="{{ $etiquette }}">Rechercher</label>
            <input type="search" id="p-recherche" name="recherche" value="{{ $recherche }}"
                   wire:model.live.debounce.400ms="recherche"
                   placeholder="{{ $placeholder }}" style="{{ $champ }} min-width:190px;">
        </div>
    @endif

    {{-- Les champs propres à l'écran, s'il en a : le portefeuille regardé, le niveau de
         relance. Ils entrent dans le même formulaire — un second formulaire à côté
         perdrait la période dès qu'on s'en servirait. --}}
    {{ $slot }}

    {{-- Le filtre part au changement : choisir un mois, c'est vouloir le voir. Le bouton
         ne subsiste que là où le script ne s'exécute pas — sinon le filtre ne partirait
         plus du tout. --}}
    <noscript>
        <button type="submit" class="rec-btn n" style="padding:7px 15px; font-size:13px;">Appliquer</button>
    </noscript>

    {{-- L'arrêté n'est pas un réglage : c'est une conséquence. On l'affiche donc, sans
         permettre de le saisir — un chiffre arrêté à une date invisible ne se défend pas. --}}
    <div style="font-size:11.5px; color:#5A6472; line-height:1.45; padding-bottom:6px;">
        {{ $periode->enClair() }}<br>
        <strong>Encours arrêté au {{ $periode->arrete->format('d/m/Y') }}</strong>
    </div>

    @if ($periode->mois !== null || ($recherche !== null && $recherche !== ''))
        <a href="{{ route($route, $parametres) }}" wire:navigate
           style="font-size:12px; color:#C8102E; font-weight:700; text-decoration:none; padding-bottom:8px;">
            Tout voir
        </a>
    @endif

    {{-- Le filtre part de lui-même au changement, et le reste de la page suit.

         **Ce qui a changé le 24/09, et pourquoi.** Chaque liste renvoyait le formulaire,
         donc rechargeait la page entière : feuilles de style, scripts, menu, bandeau, tout
         était redemandé pour changer un mois. Le propriétaire l'a mesuré à l'usage — « ils
         font recharger la page et on a un temps de latence » — et il avait raison : sur le
         tableau de bord du recouvrement, chaque clic sur un filtre coûtait une page neuve.
         Les listes sont désormais liées au composant (`wire:model.live`) : seule la partie
         qui change est renvoyée, l'adresse se met à jour, et la position dans la page est
         conservée.

         **Le chemin sans script reste ouvert**, et c'est la raison d'être du formulaire :
         les `name` sont intacts, le bouton « Appliquer » de `<noscript>` l'envoie, et les
         mêmes paramètres arrivent dans l'adresse. Les deux chemins mènent au même endroit.

         **L'écoute déléguée subsiste pour les listes non liées** — un écran qui poserait
         ici un filtre à lui sans le brancher sur un composant. Elle est posée une fois sur
         le document, qui survit aux navigations : branchée liste par liste au chargement,
         elle était sautée au deuxième écran ouvert depuis le menu, et plus aucun filtre ne
         partait. Une liste déjà liée à Livewire est laissée tranquille : envoyer le
         formulaire par-dessus rechargerait justement la page qu'on vient d'éviter. --}}
    <script data-navigate-once>
        document.addEventListener('change', function (evenement) {
            var champ = evenement.target;

            if (! champ || champ.tagName !== 'SELECT') { return; }

            // Liée au composant : Livewire s'en charge, et mieux.
            for (var i = 0; i < champ.attributes.length; i++) {
                if (champ.attributes[i].name.indexOf('wire:model') === 0) { return; }
            }

            var formulaire = champ.closest('form[data-filtre-auto]');

            if (! formulaire) { return; }

            // requestSubmit respecte la validation du formulaire ; submit ne la voit pas.
            if (formulaire.requestSubmit) { formulaire.requestSubmit(); } else { formulaire.submit(); }
        });
    </script>
</form>
