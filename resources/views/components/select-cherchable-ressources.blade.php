{{-- Le style et le script des listes déroulantes qu'on fouille par l'intérieur.

     À part du composant pour une raison précise : Livewire n'exécute pas un script qu'il
     insère en redessinant. Un écran dont la liste n'apparaît qu'après un clic — le panneau
     « Porter une facture existante » de l'état des impayés — doit donc inclure ces ressources
     dès son premier affichage. Les inclure deux fois est sans effet : le script se garde. --}}

<style>
    /* La largeur est bornée : une liste déroulante qui prend toute la fenêtre force à
       balayer l'écran des yeux pour lire un nom de vingt caractères. */
    .sel-ch { display:flex; flex-direction:column; gap:4px; max-width:340px; min-width:0; position:relative; }
    .sel-ch label { font-size:12.5px; font-weight:600; color:#4B4E55; }

    /* Le champ réel et le bouton qui le remplace portent exactement le même habit :
       une rangée de champs où l'un détonne se lit comme un champ d'une autre nature. */
    .sel-ch select, .sel-ch input[data-sel-champ], .sel-ch .sel-ch-btn {
        width:100%; box-sizing:border-box; font-family:inherit; font-size:14px;
        border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px; padding:8px 10px;
        background:var(--th-champ,#FFFBEA); color:var(--th-ink,#191B20); }
    .sel-ch select:focus, .sel-ch input[data-sel-champ]:focus, .sel-ch .sel-ch-btn:focus {
        outline:2px solid #C8102E; outline-offset:1px; border-color:#C8102E; }

    .sel-ch .sel-ch-btn {
        display:flex; align-items:center; justify-content:space-between; gap:8px;
        text-align:left; cursor:pointer; }
    .sel-ch .sel-ch-btn .txt { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* Gris tant que rien n'est choisi : le libellé d'attente ne doit pas se lire comme
       une valeur retenue. */
    .sel-ch .sel-ch-btn.vide .txt { color:var(--th-gris,#6B6E76); }
    .sel-ch .sel-ch-btn .fl { flex:0 0 auto; font-size:10px; color:#5A6472; }

    /* Le panneau. Il se pose au-dessus du reste et ne pousse rien : un formulaire qui
       se réorganise quand on ouvre une liste fait perdre la ligne qu'on lisait. */
    .sel-ch-pan {
        position:absolute; z-index:60; left:0; right:0; top:100%; margin-top:3px;
        background:#fff; border:1px solid var(--th-ligne,#E3E0D8); border-radius:8px;
        box-shadow:0 10px 26px rgba(25,27,32,.16); overflow:hidden; }
    .sel-ch-pan .q {
        width:100%; box-sizing:border-box; border:0; border-bottom:1px solid var(--th-ligne,#E3E0D8);
        padding:9px 11px; font-family:inherit; font-size:13.5px; background:#fff;
        color:var(--th-ink,#191B20); }
    .sel-ch-pan .q:focus { outline:0; border-bottom-color:#C8102E; }
    .sel-ch-pan ul { list-style:none; margin:0; padding:4px; max-height:260px; overflow:auto; }
    .sel-ch-pan li {
        padding:7px 9px; border-radius:5px; font-size:13.5px; cursor:pointer;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sel-ch-pan li[aria-selected="true"] { font-weight:700; }
    .sel-ch-pan li.sur { background:#191B20; color:#fff; }
    .sel-ch-pan .rien { padding:10px 11px; font-size:13px; color:var(--th-gris,#6B6E76); }
    .sel-ch-pan .cpt {
        padding:6px 11px; font-size:11px; color:var(--th-gris,#6B6E76);
        border-top:1px solid var(--th-ligne,#E3E0D8); background:#FAF8F2; }
</style>

<script data-navigate-once>
    (function () {
        'use strict';

        // Une seule fois par page, même si l'écran et un composant imbriqué incluent tous
        // deux ces ressources : deux jeux d'écouteurs ouvriraient puis refermeraient le
        // panneau au même clic.
        if (window.__selectCherchable) { return; }
        window.__selectCherchable = true;

        // Un seul jeu d'écouteurs, posé sur le document : il survit aux navigations et
        // aux remplacements de fragments, et n'a pas à être rebranché à chaque rendu.
        var ouvert = null;

        function champDe(bloc) { return bloc.querySelector('[data-sel-champ]'); }

        /** Les choix offerts : ceux du `select`, ou ceux du `datalist` partagé. */
        function choixDe(bloc) {
            var source = bloc.getAttribute('data-sel-source');

            if (source) {
                var liste = document.getElementById(source);

                return liste ? Array.prototype.map.call(liste.options, function (o) {
                    return { valeur: o.value, texte: o.label || o.value };
                }) : [];
            }

            var select = bloc.querySelector('[data-sel-liste]');

            if (! select) { return []; }

            return Array.prototype.filter.call(select.options, function (o) {
                return o.value !== '';
            }).map(function (o) {
                return { valeur: o.value, texte: o.text.trim() };
            });
        }

        /** Ce que le champ vaut aujourd'hui — la seule source du libellé affiché. */
        function valeurDe(bloc) {
            var champ = champDe(bloc);

            return champ ? (champ.value || '') : '';
        }

        function rafraichirBouton(bloc) {
            var bouton = bloc.querySelector('.sel-ch-btn');

            if (! bouton) { return; }

            var valeur = valeurDe(bloc);
            var texte = valeur;

            var select = bloc.querySelector('[data-sel-liste]');

            if (select && select.selectedOptions.length) {
                texte = select.selectedOptions[0].value === '' ? '' : select.selectedOptions[0].text.trim();
            }

            bouton.querySelector('.txt').textContent = texte || bloc.getAttribute('data-sel-vide');
            bouton.classList.toggle('vide', texte === '');
        }

        /** Pose le bouton qui remplace le champ, une seule fois par bloc. */
        function habiller(bloc) {
            var champ = champDe(bloc);

            if (! champ) { return; }

            // Livewire, en redessinant, rend au champ ses attributs d'origine : on le
            // remasque à chaque passage, bouton déjà posé ou non.
            masquer(champ);

            if (bloc.querySelector('.sel-ch-btn')) { return; }

            var bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'sel-ch-btn';
            bouton.setAttribute('aria-haspopup', 'listbox');
            bouton.setAttribute('aria-expanded', 'false');
            bouton.innerHTML = '<span class="txt"></span><span class="fl" aria-hidden="true">▼</span>';

            champ.parentNode.insertBefore(bouton, champ);
            rafraichirBouton(bloc);
        }

        /**
         * Le champ réel reste dans le document — il poste sa valeur et sert de repli —
         * mais il sort du parcours visuel et du parcours au clavier.
         */
        function masquer(champ) {
            champ.style.position = 'absolute';
            champ.style.opacity = '0';
            champ.style.pointerEvents = 'none';
            champ.style.height = '0';
            champ.style.padding = '0';
            champ.style.border = '0';
            champ.setAttribute('tabindex', '-1');
            champ.setAttribute('aria-hidden', 'true');
        }

        function fermer() {
            if (! ouvert) { return; }

            var panneau = ouvert.querySelector('.sel-ch-pan');

            if (panneau) { panneau.remove(); }

            var bouton = ouvert.querySelector('.sel-ch-btn');

            if (bouton) { bouton.setAttribute('aria-expanded', 'false'); }

            ouvert = null;
        }

        function choisir(bloc, valeur) {
            var champ = champDe(bloc);

            if (! champ) { return; }

            var select = bloc.querySelector('[data-sel-liste]');

            if (select) {
                select.value = valeur;
            } else {
                champ.value = valeur;
            }

            // Les deux évènements, et dans cet ordre : Livewire écoute `input` pour un
            // champ texte et `change` pour une liste. En omettre un laisserait la
            // valeur dans la page sans jamais l'envoyer au serveur — c'est exactement
            // le défaut qu'on répare ici.
            champ.dispatchEvent(new Event('input', { bubbles: true }));
            champ.dispatchEvent(new Event('change', { bubbles: true }));

            rafraichirBouton(bloc);
            fermer();

            var bouton = bloc.querySelector('.sel-ch-btn');

            if (bouton) { bouton.focus(); }
        }

        function dessinerListe(bloc, panneau, cherche) {
            var liste = panneau.querySelector('ul');
            var compteur = panneau.querySelector('.cpt');
            var choix = choixDe(bloc);
            var courante = valeurDe(bloc);
            var q = cherche.trim().toLowerCase();

            var gardes = choix.filter(function (c) {
                return q === '' || c.texte.toLowerCase().indexOf(q) !== -1;
            });

            liste.innerHTML = '';

            // Le choix vide reste toujours atteignable : sans lui on ne pourrait plus
            // effacer sa sélection après avoir filtré.
            var vide = document.createElement('li');
            vide.textContent = bloc.getAttribute('data-sel-vide');
            vide.setAttribute('data-valeur', '');
            vide.setAttribute('aria-selected', courante === '' ? 'true' : 'false');
            liste.appendChild(vide);

            gardes.slice(0, 300).forEach(function (c) {
                var li = document.createElement('li');
                li.textContent = c.texte;
                li.setAttribute('data-valeur', c.valeur);
                li.setAttribute('aria-selected', c.valeur === courante ? 'true' : 'false');
                liste.appendChild(li);
            });

            if (gardes.length === 0) {
                var rien = document.createElement('div');
                rien.className = 'rien';
                rien.textContent = 'Aucun résultat pour « ' + cherche.trim() + ' ».';
                liste.appendChild(rien);
            }

            compteur.textContent = q === ''
                ? choix.length + ' entrées'
                : gardes.length + ' sur ' + choix.length
                    + (gardes.length > 300 ? ' — les 300 premières' : '');
        }

        function ouvrir(bloc) {
            if (ouvert === bloc) { fermer(); return; }

            fermer();

            var panneau = document.createElement('div');
            panneau.className = 'sel-ch-pan';
            panneau.innerHTML = '<input type="search" class="q" autocomplete="off">'
                + '<ul role="listbox"></ul><div class="cpt"></div>';

            bloc.appendChild(panneau);

            var recherche = panneau.querySelector('.q');
            recherche.placeholder = bloc.getAttribute('data-sel-invite') || 'Rechercher…';

            dessinerListe(bloc, panneau, '');

            var bouton = bloc.querySelector('.sel-ch-btn');

            if (bouton) { bouton.setAttribute('aria-expanded', 'true'); }

            ouvert = bloc;
            recherche.focus();
        }

        function surligner(panneau, pas) {
            var items = Array.prototype.filter.call(panneau.querySelectorAll('li'), function (li) {
                return li.offsetParent !== null;
            });

            if (! items.length) { return; }

            var courant = panneau.querySelector('li.sur');
            var rang = courant ? items.indexOf(courant) : -1;
            var suivant = Math.max(0, Math.min(items.length - 1, rang + pas));

            if (courant) { courant.classList.remove('sur'); }

            items[suivant].classList.add('sur');
            items[suivant].scrollIntoView({ block: 'nearest' });
        }

        // ---------------------------------------------------------------- écouteurs

        document.addEventListener('click', function (e) {
            var bouton = e.target.closest ? e.target.closest('.sel-ch-btn') : null;

            if (bouton) {
                e.preventDefault();
                ouvrir(bouton.closest('[data-sel-ch]'));

                return;
            }

            var item = e.target.closest ? e.target.closest('.sel-ch-pan li') : null;

            if (item) {
                choisir(item.closest('[data-sel-ch]'), item.getAttribute('data-valeur'));

                return;
            }

            if (ouvert && ! e.target.closest('[data-sel-ch]')) { fermer(); }
        });

        document.addEventListener('input', function (e) {
            if (! ouvert || ! e.target.matches || ! e.target.matches('.sel-ch-pan .q')) { return; }

            dessinerListe(ouvert, ouvert.querySelector('.sel-ch-pan'), e.target.value);
        });

        document.addEventListener('keydown', function (e) {
            if (! ouvert) { return; }

            var panneau = ouvert.querySelector('.sel-ch-pan');

            if (e.key === 'Escape') { e.preventDefault(); fermer(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); surligner(panneau, 1); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); surligner(panneau, -1); return; }

            if (e.key === 'Enter') {
                var sur = panneau.querySelector('li.sur') || panneau.querySelector('li:not([data-valeur=""])');

                if (sur) { e.preventDefault(); choisir(ouvert, sur.getAttribute('data-valeur')); }
            }
        });

        /** Habiller ce qui est déjà là, et ce que Livewire remplacera ensuite. */
        function balayer() {
            document.querySelectorAll('[data-sel-ch][data-sel-cherchable]').forEach(function (bloc) {
                habiller(bloc);
                rafraichirBouton(bloc);
            });
        }

        /*
         * Après chaque échange Livewire. Aucun évènement du navigateur ne signale qu'un
         * composant vient d'être redessiné : sans ce crochet, une liste apparue après un
         * choix — les factures d'un client — restait nue, sans sa recherche.
         */
        function brancherLivewire() {
            if (! window.Livewire || window.__selectCherchableLivewire) { return; }

            window.__selectCherchableLivewire = true;
            window.Livewire.hook('commit', function (echange) {
                echange.succeed(function () { queueMicrotask(balayer); });
            });
        }

        document.addEventListener('livewire:init', brancherLivewire);
        brancherLivewire();

        document.addEventListener('DOMContentLoaded', balayer);
        document.addEventListener('livewire:navigated', balayer);
        document.addEventListener('livewire:initialized', balayer);
        document.addEventListener('livewire:update', balayer);
        balayer();
    })();
</script>
