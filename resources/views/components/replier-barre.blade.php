@props([
    /** La barre visée : « rec », « imp » ou « bandeau ». Sert de clé de mémoire. */
    'cible',
    /** Ce qu'on replie, dit en toutes lettres dans l'infobulle et pour les lecteurs d'écran. */
    'quoi' => 'le menu',
])

{{-- Replier une barre de navigation.

     **Demandé le 01/10** : un bouton pour fermer et rouvrir la barre latérale du
     recouvrement, celle de l'import, et le bandeau horizontal du haut.

     **Pourquoi un écouteur global et non Alpine.** C'est l'idiome déjà en place dans
     `resources/js/app.js` pour les menus repliables du bandeau : un seul écouteur, posé une
     fois, qui survit aux navigations `wire:navigate` — le script ne se recharge pas, seul le
     corps de la page est remplacé. Un `x-data` posé sur une barre serait reconstruit à chaque
     navigation, et perdrait l'état à chaque page tournée.

     **Le repli est un confort, pas une fonction.** La barre est ouverte par défaut dans le
     HTML : sans JavaScript, elle reste là et tout fonctionne. C'est la règle de la maison sur
     le chemin critique, et elle vaut ici comme ailleurs.

     **Ce qu'on retient, et où.** L'état va dans `localStorage`, lu et écrit sous `try/catch` :
     en navigation privée ou avec les données de site bloquées, l'accès lève, et une barre qui
     refuserait de s'afficher parce qu'on ne peut pas se souvenir d'elle serait absurde. Il est
     **par poste et par personne**, ce qui est exactement le bon périmètre : replier un menu
     n'engage que celui qui le replie.

     **Le CSS voyage avec le bouton**, dans un `@once`. Le mettre dans `resources/css/app.css`
     obligerait à reconstruire les fichiers de Vite et à committer le résultat ; ici, la règle
     arrive avec la page qui s'en sert, et rien ne dépend d'une reconstruction. --}}

@once
    <style>
        /* Les deux coquilles sont des grilles `236px 1fr` : repliée, la colonne tombe à zéro
           et le `overflow:hidden` du conteneur fait le reste. Une transition sur la seule
           largeur de colonne : animer l'`aside` lui-même ferait sauter son contenu. */
        .rec, .imp { transition: grid-template-columns .18s ease; }
        .rec.est-repliee, .imp.est-repliee { grid-template-columns: 0 1fr; }
        .rec.est-repliee > .rec-side, .imp.est-repliee > .imp-side { display: none; }

        /* Le bandeau du haut ne replie que sa navigation : la marque, l'exercice et le compte
           restent, sinon on ne saurait plus où l'on est ni sous quelle identité. */
        header.est-repliee nav { display: none; }

        .replier-barre {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            border: 1px solid var(--th-ligne, #E3E0D8); background: #fff;
            border-radius: 7px; padding: 5px 9px; cursor: pointer;
            font: 600 12px var(--font-sans, system-ui), sans-serif; color: #4B4E55;
            line-height: 1;
        }
        .replier-barre:hover { border-color: #C9C5BB; color: #191B20; }
        .replier-barre .chevron { font-size: 10px; }

        /* Sur fond sombre — le bandeau du haut —, l'inverse. */
        .replier-barre.sur-sombre {
            background: transparent; border-color: #3A3E47; color: #C7C9CF;
        }
        .replier-barre.sur-sombre:hover { border-color: #5A5F6A; color: #fff; }
    </style>

    <script>
        (function () {
            /*
             * Un seul écouteur pour les trois barres, posé une fois. Il survit aux
             * navigations : `document` n'est pas remplacé, seul le corps de la page l'est.
             */
            var CIBLES = {
                rec: '.rec',
                imp: '.imp',
                bandeau: 'header',
            };

            var lire = function (cle) {
                try {
                    return window.localStorage.getItem('barre-' + cle) === 'repliee';
                } catch (e) {
                    // Navigation privée, données de site bloquées : on ne se souvient de
                    // rien, et la barre reste ouverte. C'est le bon défaut.
                    return false;
                }
            };

            var ecrire = function (cle, repliee) {
                try {
                    window.localStorage.setItem('barre-' + cle, repliee ? 'repliee' : 'ouverte');
                } catch (e) { /* rien à faire : le repli marche, il ne se retient pas. */ }
            };

            var appliquer = function (cle) {
                var racine = document.querySelector(CIBLES[cle]);

                if (! racine) { return; }

                var repliee = lire(cle);
                racine.classList.toggle('est-repliee', repliee);

                document.querySelectorAll('[data-replier="' + cle + '"]').forEach(function (bouton) {
                    bouton.setAttribute('aria-expanded', repliee ? 'false' : 'true');

                    var chevron = bouton.querySelector('.chevron');
                    if (chevron) { chevron.textContent = bouton.dataset.sens === 'haut'
                        ? (repliee ? '▾' : '▴')
                        : (repliee ? '▸' : '◂'); }
                });
            };

            document.addEventListener('click', function (evenement) {
                var bouton = evenement.target.closest
                    ? evenement.target.closest('[data-replier]')
                    : null;

                if (! bouton) { return; }

                var cle = bouton.dataset.replier;
                ecrire(cle, ! lire(cle));
                appliquer(cle);
            });

            var remettreEnPlace = function () {
                Object.keys(CIBLES).forEach(appliquer);
            };

            document.addEventListener('livewire:navigated', remettreEnPlace);
            document.addEventListener('DOMContentLoaded', remettreEnPlace);
            remettreEnPlace();
        })();
    </script>
@endonce

<button type="button" class="replier-barre {{ $cible === 'bandeau' ? 'sur-sombre' : '' }}"
    data-replier="{{ $cible }}"
    @if ($cible === 'bandeau') data-sens="haut" @endif
    aria-expanded="true"
    title="Replier ou déplier {{ $quoi }}"
    {{ $attributes }}>
    <span class="chevron" aria-hidden="true">{{ $cible === 'bandeau' ? '▴' : '◂' }}</span>
    <span class="sr-seulement" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0);">
        Replier ou déplier {{ $quoi }}
    </span>
    <span aria-hidden="true">Menu</span>
</button>
