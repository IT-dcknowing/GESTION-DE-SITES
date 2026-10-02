@props([
    /** La barre visée : « rec », « imp » ou « bandeau ». Sert de clé de mémoire. */
    'cible',
    /** Ce qu'on replie, dit en toutes lettres dans l'infobulle et pour les lecteurs d'écran. */
    'quoi' => 'le menu',
])

{{-- Replier une barre de navigation.

     **Demandé le 01/10** : un bouton pour fermer et rouvrir la barre latérale du
     recouvrement, celle de l'import, et le bandeau horizontal du haut.

     ─────────────────────────────────────────────────────────────────────────────────────

     **Pourquoi ce fichier a été réécrit le 02/10 : rien ne répondait, et la cause était
     mécanique.** `@once` n'a pas dédupliqué — mesuré sur le HTML rendu : sur `/recouvrement`
     et sur `/import`, qui portent deux boutons, le bloc sortait **deux fois**. Deux scripts,
     donc **deux écouteurs sur `document`** : chaque clic basculait puis rebasculait, et
     l'écran ne bougeait pas. Sur `/tresorerie`, qui n'a que le bouton du bandeau, il sortait
     une fois et le bouton marchait — d'où le « parfois ça passe, parfois non ».

     **La parade ne dépend plus de Blade.** Le script se garde lui-même par un drapeau posé
     sur `window` : qu'il soit injecté une fois, deux fois ou dix, il ne s'installe qu'une.
     C'est aussi ce qui le protège du second cas connu — Livewire réinsère le corps de la page
     à chaque navigation, et un `<script>` inséré ainsi ne s'exécute pas, mais s'il
     s'exécutait, il doublerait l'écouteur.

     **La classe est posée sur `<html>`, et non sur la barre.** Les deux coquilles sont des
     racines de composants Livewire : à la première mise à jour, Livewire compare le DOM à ce
     que le serveur a rendu et **retire une classe qu'il n'y a pas mise**. La barre se
     redépliait alors toute seule. `<html>` n'est touché ni par la comparaison ni par la
     navigation.

     ─────────────────────────────────────────────────────────────────────────────────────

     **Le repli est un confort, pas une fonction.** Les barres sont ouvertes par défaut dans
     le HTML : sans JavaScript, elles restent là et tout fonctionne. C'est la règle de la
     maison sur le chemin critique.

     **Ce qu'on retient, et où.** L'état va dans `localStorage`, lu et écrit sous `try/catch` :
     en navigation privée ou avec les données de site bloquées, l'accès lève, et une barre qui
     refuserait de s'afficher parce qu'on ne peut pas se souvenir d'elle serait absurde. --}}

@once
    <style>
        /* **Une seule colonne quand la barre est repliée, et non une colonne à zéro.**
           Un `aside` passé en `display:none` quitte le flux de la grille, et le contenu se
           replace alors dans la **première** cellule — celle qu'on vient de ramener à zéro.
           L'écran d'import s'affichait ainsi sur cent soixante pixels, un mot par ligne. */
        .rec, .imp { transition: grid-template-columns .18s ease; }
        html.barre-rec-repliee .rec,
        html.barre-imp-repliee .imp { grid-template-columns: 1fr; }
        html.barre-rec-repliee .rec > .rec-side,
        html.barre-imp-repliee .imp > .imp-side { display: none; }

        /* Le bandeau du haut ne replie que sa navigation : la marque, l'exercice et le compte
           restent, sinon on ne saurait plus où l'on est ni sous quelle identité.

           **`!important`, et c'est la seule place où il se justifie.** Le `<nav>` de la mise
           en page porte `style="display:flex"` **en ligne**, et un style en ligne l'emporte
           sur toute règle de feuille de style, quelle qu'en soit la spécificité. Sans ce
           mot-là, la règle était écrite, lue, et sans effet — le bouton du bandeau n'a
           jamais rien replié, même sur une page où le script ne sortait qu'une fois.

           L'autre voie serait de retirer le `display:flex` en ligne du gabarit ; elle
           toucherait la mise en page de toutes les pages pour un confort, et c'est le
           mauvais échange. */
        html.barre-bandeau-repliee header nav { display: none !important; }

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
        .replier-barre.sur-sombre { background: transparent; border-color: #3A3E47; color: #C7C9CF; }
        .replier-barre.sur-sombre:hover { border-color: #5A5F6A; color: #fff; }
    </style>

    <script>
        (function () {
            /*
             * **Le drapeau d'abord.** Ce bloc peut être rendu plusieurs fois sur une même
             * page, une fois par bouton, et la directive Blade censée l'éviter ne l'a pas
             * fait — c'est tout l'objet de cette garde. Sans elle,
             * chaque copie poserait son écouteur, et un clic basculerait autant de fois qu'il
             * y a de copies : l'écran ne bougerait pas.
             */
            if (window.__barresRepliables) { return; }
            window.__barresRepliables = true;

            var CLES = ['rec', 'imp', 'bandeau'];

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
                } catch (e) { /* le repli marche, il ne se retient pas. */ }
            };

            var appliquer = function (cle) {
                var repliee = lire(cle);

                // Sur `<html>` : les deux coquilles sont des racines Livewire, et Livewire
                // retire d'une racine les classes que le serveur n'a pas rendues.
                document.documentElement.classList.toggle('barre-' + cle + '-repliee', repliee);

                var boutons = document.querySelectorAll('[data-replier="' + cle + '"]');

                for (var i = 0; i < boutons.length; i++) {
                    boutons[i].setAttribute('aria-expanded', repliee ? 'false' : 'true');

                    var chevron = boutons[i].querySelector('.chevron');

                    if (chevron) {
                        chevron.textContent = boutons[i].getAttribute('data-sens') === 'haut'
                            ? (repliee ? '▾' : '▴')
                            : (repliee ? '▸' : '◂');
                    }
                }
            };

            var toutAppliquer = function () {
                for (var i = 0; i < CLES.length; i++) { appliquer(CLES[i]); }
            };

            document.addEventListener('click', function (evenement) {
                var cible = evenement.target;
                var bouton = cible && cible.closest ? cible.closest('[data-replier]') : null;

                if (! bouton) { return; }

                var cle = bouton.getAttribute('data-replier');
                ecrire(cle, ! lire(cle));
                appliquer(cle);
            });

            // Au premier affichage, et après chaque navigation sans rechargement.
            document.addEventListener('livewire:navigated', toutAppliquer);
            document.addEventListener('DOMContentLoaded', toutAppliquer);
            toutAppliquer();
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
    <span style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0);">
        Replier ou déplier {{ $quoi }}
    </span>
    <span aria-hidden="true">Menu</span>
</button>
