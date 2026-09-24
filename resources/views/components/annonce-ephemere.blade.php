@php
    /*
     * Les annonces que le serveur vient de faire, ramassées ici plutôt que semées dans
     * les pages.
     *
     * Chaque écran flashait son message à sa façon — « annonce-import », « annonce-acces »,
     * « message-code » — et chacun le rendait dans un cadre différent, à un endroit
     * différent. Résultat : un message d'information se glissait entre deux blocs, à
     * l'endroit exact où l'œil ne s'arrête pas. Ils passent tous par ici désormais.
     *
     * Le refus, lui, ne passe pas par ici : il barre l'écran et demande un geste pour
     * disparaître (voir x-boite-message). Ce qui est réussi s'annonce et s'efface ; ce qui
     * a échoué attend d'être lu.
     */
    $annonces = collect(['annonce', 'annonce-import', 'annonce-acces', 'annonce-switch', 'message-code'])
        ->map(fn (string $cle) => session($cle))
        ->filter(fn ($texte) => is_string($texte) && trim($texte) !== '')
        ->values()
        ->all();
@endphp

{{-- Un bandeau qui dit ce qui vient de se passer, puis s'efface au bout de trois secondes.

     **Pourquoi il est écrit en JavaScript nu.** La version précédente reposait sur Alpine,
     donc sur la couche interactive — celle-là même qui ne démarre pas dans le navigateur où
     tout ce travail a commencé. Un composant chargé d'annoncer qu'un geste a réussi ne peut
     pas dépendre de ce qui tombe en panne : il ne dirait jamais rien, précisément le jour
     où il faudrait qu'il parle.

     Il sert deux sources, et c'est ce qui le rend utile partout :

     - les **annonces du serveur**, écrites directement dans la page à l'aller ; elles
       fonctionnent sans une ligne de script côté client au moment du rendu ;
     - l'événement `annonce`, que les écrans interactifs continuent d'émettre par
       `$this->dispatch('annonce', texte: '…', ton: 'succes'|'alerte')`.

     Il remplace aussi les boîtes « Confirmer ? » sur les gestes réversibles : demander
     confirmation pour transmettre une prospection coûtait un clic par ligne et n'évitait
     rien. La confirmation reste sur ce qui ne se rattrape pas, et passe alors par
     x-confirmation. --}}

<div id="annonces-ephemeres"
     style="position:fixed; top:16px; left:50%; transform:translateX(-50%); z-index:9999;
            display:flex; flex-direction:column; gap:8px; align-items:center;
            pointer-events:none; max-width:92vw;"
     aria-live="polite"></div>

{{-- **Le mécanisme, posé une fois pour toute la durée du document.**

     Il portait un défaut qu'on ne voit qu'à l'usage, et le propriétaire l'a signalé le
     24/09 sur la relance : « on ne sait pas si cela a enregistré, donc cela pousse à
     cliquer plusieurs fois ». Le geste était bien enregistré, et l'annonce bien émise —
     c'est la bulle qui n'arrivait jamais.

     La cause : ce script retenait la pile au premier chargement. Or `wire:navigate`
     remplace le corps de la page sans recharger le document ; au deuxième écran, la
     variable désignait un élément détaché, et `appendChild` posait la bulle dans le vide.
     La pile est donc **relue au moment de l'annonce**, jamais retenue à l'avance. C'est le
     même piège que celui de la boîte de confirmation et des filtres du recouvrement — le
     troisième de la même famille, et le plus discret, parce qu'il ne casse rien : il rend
     l'application muette. --}}
<script data-navigate-once>
(function () {
    var DUREE = 3000;

    var montrer = function (texte, ton) {
        if (! texte) { return; }

        var pile = document.getElementById('annonces-ephemeres');

        if (! pile) { return; }

        var bulle = document.createElement('div');
        bulle.className = 'annonce ' + (ton === 'alerte' ? 'annonce-alerte' : 'annonce-succes');
        bulle.textContent = texte;
        bulle.style.transition = 'opacity .28s ease, transform .28s ease';
        pile.appendChild(bulle);

        // Le retrait est en deux temps : on efface, puis on retire du document. Retirer
        // d'un coup ferait disparaître la bulle sans transition, ce qui se lit comme un
        // clignotement plutôt que comme une fin.
        window.setTimeout(function () {
            bulle.style.opacity = '0';
            bulle.style.transform = 'translateY(-8px)';
            window.setTimeout(function () { bulle.remove(); }, 300);
        }, DUREE);
    };

    // Ouvert à tous : un écran ordinaire peut annoncer sans passer par un événement.
    window.annonce = montrer;

    window.addEventListener('annonce', function (evenement) {
        var detail = evenement.detail || {};
        montrer(detail.texte, detail.ton);
    });
})();
</script>

{{-- **Les annonces du serveur, elles, changent à chaque page.**

     Elles vivaient dans le script ci-dessus, qui ne s'exécute qu'une fois : un message
     flashé après une navigation sans rechargement n'était donc jamais dit. Ce second
     script n'a pas `data-navigate-once` — il est réévalué à chaque page, ce qui est
     exactement ce qu'il faut pour un message qui vaut pour cette page-là. --}}
@if ($annonces !== [])
    <script>
        (function () {
            var dire = function () {
                @foreach ($annonces as $texte)
                    window.annonce(@json($texte), 'succes');
                @endforeach
            };

            // Le mécanisme est posé juste au-dessus, sauf au tout premier rendu où les
            // deux scripts s'évaluent dans l'ordre : on attend alors le document.
            if (typeof window.annonce === 'function') {
                dire();
            } else {
                window.addEventListener('DOMContentLoaded', dire);
            }
        })();
    </script>
@endif
