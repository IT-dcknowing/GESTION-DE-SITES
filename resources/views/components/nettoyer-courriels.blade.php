{{--
    Une adresse e-mail se nettoie pendant qu'on la tape — demandé le 12/10.

    « t.tou@lartisanauto.com » était refusée par le navigateur : « La partie suivie du
    symbole @ ne doit pas contenir le caractère "" ». L'adresse était juste ; elle portait un
    caractère **invisible** — espace insécable, espace de largeur nulle —, venu d'un
    copier-coller depuis un courriel ou un tableur. Le navigateur le refusait sans pouvoir le
    montrer, et l'on cherchait la faute dans une adresse qui n'en avait pas.

    Les champs ne sont donc plus `type="email"` (qui bloque avant tout envoi) mais
    `inputmode="email"` (le clavier e-mail reste sur téléphone), et ce script retire les
    caractères invisibles et les espaces **avant** que Livewire ne lise la valeur : il écoute
    en phase de capture, donc avant `wire:model`. Le serveur valide ensuite l'adresse comme
    avant, et `User` la normalise encore à l'enregistrement.

    Inline et non dans `app.js` : il ne dépend d'aucune compilation.
--}}
<script>
    (function () {
        if (window.__nettoyerCourriels) { return; }
        window.__nettoyerCourriels = true;

        var invisibles = /[\s  ᠎ -‏ -  -⁤　﻿]/g;

        document.addEventListener('input', function (e) {
            var champ = e.target;
            if (! champ || ! champ.matches || ! champ.matches('input[inputmode="email"]')) { return; }

            var propre = champ.value.replace(invisibles, '');
            if (propre !== champ.value) {
                var position = champ.selectionStart;
                champ.value = propre;
                try { champ.setSelectionRange(position - 1, position - 1); } catch (x) {}
            }
        }, true);
    })();
</script>
