{{-- « Enregistrement en cours… » — dire que le geste est parti, pendant qu'il voyage.

     **La demande, du 28/09** : « lorsque le bouton enregistrer est cliqué et que
     l'enregistrement se fait, pendant ce petit temps de latence, envoie un message pour
     dire enregistrement en cours ».

     **Ce que ce silence coûtait.** Un bouton cliqué qui ne répond pas immédiatement se lit
     comme un bouton en panne : on reclique. Recliquer sur « Enregistrer l'encaissement »
     n'est pas anodin — c'est un second versement sur les mêmes factures, et il faut ensuite
     le retrouver et l'annuler. La même chose sur une relance N5 envoie deux fois l'huissier.

     **Pourquoi un seul composant plutôt qu'un `wire:loading` par bouton.** Il y a des
     dizaines de boutons d'enregistrement dans l'application, et il en naîtra d'autres. Un
     indicateur posé bouton par bouton est un indicateur qu'on oubliera quelque part — et
     l'oubli ne se verra pas, puisqu'il se manifeste par l'absence de quelque chose.

     **Ce qu'on distingue, et c'est le point délicat.** Livewire envoie deux sortes
     d'échanges : les `updates` — une propriété qui change, c'est-à-dire un filtre qu'on
     tape, une case qu'on coche — et les `calls` — une méthode appelée, c'est-à-dire un
     geste. Annoncer « enregistrement en cours » quand quelqu'un tape dans une recherche
     serait pire que se taire : on apprendrait à ne plus lire le bandeau.

     On ne réagit donc qu'aux **appels de méthode**, et le mot change selon ce que la
     méthode fait : « Enregistrement » quand son nom dit qu'elle écrit, « Traitement »
     sinon.

     **Le délai avant affichage n'est pas un détail.** La plupart des réponses arrivent en
     moins de deux cents millisecondes ; afficher puis retirer un bandeau en cent
     millisecondes produit un clignotement qui fatigue et n'informe personne. On n'affiche
     donc que ce qui dure. --}}

<div id="travail-en-cours" role="status" aria-live="polite"
     style="display:none; position:fixed; left:50%; transform:translateX(-50%); bottom:22px; z-index:90;
            background:#191B20; color:#EDEBE4; border-radius:999px; padding:10px 20px;
            font-family:var(--font-sans); font-size:13.5px; font-weight:600; letter-spacing:.2px;
            box-shadow:0 10px 26px rgba(25,27,32,.28); align-items:center; gap:10px;">
    <span aria-hidden="true"
          style="width:13px; height:13px; border:2px solid rgba(237,235,228,.35); border-top-color:#EDEBE4;
                 border-radius:999px; display:inline-block; animation:travail-tourne .7s linear infinite;"></span>
    <span data-texte>Enregistrement en cours…</span>
</div>

<style>
    @keyframes travail-tourne { to { transform: rotate(360deg); } }

    /* Le mouvement gêne certaines personnes, et la roue n'apporte rien au message :
       elle s'arrête, le bandeau reste. */
    @media (prefers-reduced-motion: reduce) {
        #travail-en-cours span[aria-hidden] { animation: none; }
    }
</style>

<script data-navigate-once>
    (function () {
        'use strict';

        // Une seule fois par page, même si la coquille est incluse deux fois : deux jeux
        // de crochets compteraient chaque échange deux fois et le bandeau ne partirait plus.
        if (window.__travailEnCours) { return; }
        window.__travailEnCours = true;

        /**
         * Les verbes qui écrivent. Reconnus sur le **début** du nom de la méthode, parce
         * que c'est ainsi que la maison les nomme : `enregistrerEncaissement`,
         * `creerFacture`, `porterAlEtat`, `confirmerLeDevis`.
         *
         * Ce qui n'y figure pas n'est pas ignoré — c'est simplement annoncé comme un
         * traitement plutôt que comme un enregistrement. On ne ment jamais sur ce qui se
         * passe : on est seulement plus ou moins précis.
         */
        var VERBES = [
            'enregistr', 'creer', 'cree', 'ajouter', 'valider', 'confirmer', 'porter',
            'modifier', 'corriger', 'supprimer', 'effacer', 'transmettre', 'attribuer',
            'coder', 'relier', 'rapprocher', 'importer', 'reimporter', 'deposer',
            'basculer', 'retenir', 'repondre', 'cloturer', 'muter', 'affecter',
        ];

        var enCours = 0;
        var minuterie = null;

        /** L'élément est retrouvé à chaque fois : `wire:navigate` remplace le corps. */
        var bandeau = function () { return document.getElementById('travail-en-cours'); };

        var ecrit = function (appels) {
            return appels.some(function (nom) {
                var minuscule = String(nom || '').toLowerCase();

                return VERBES.some(function (verbe) { return minuscule.indexOf(verbe) === 0; });
            });
        };

        var montrer = function (texte) {
            enCours++;

            if (minuterie !== null) { return; }

            minuterie = window.setTimeout(function () {
                minuterie = null;

                var element = bandeau();

                if (! element || enCours === 0) { return; }

                element.querySelector('[data-texte]').textContent = texte;
                element.style.display = 'flex';
            }, 180);
        };

        var cacher = function () {
            enCours = Math.max(0, enCours - 1);

            if (enCours > 0) { return; }

            if (minuterie !== null) {
                window.clearTimeout(minuterie);
                minuterie = null;
            }

            var element = bandeau();

            if (element) { element.style.display = 'none'; }
        };

        var brancher = function () {
            if (! window.Livewire || window.__travailEnCoursBranche) { return; }

            window.__travailEnCoursBranche = true;

            window.Livewire.hook('commit', function (echange) {
                var appels = (echange.commit && echange.commit.calls ? echange.commit.calls : [])
                    .map(function (appel) { return appel.method; });

                // Un filtre qu'on tape, une case qu'on coche : rien à annoncer. Un bandeau
                // qui s'allume à chaque frappe est un bandeau qu'on cesse de lire.
                if (appels.length === 0) { return; }

                montrer(ecrit(appels) ? 'Enregistrement en cours…' : 'Traitement en cours…');

                // `respond` part aussi quand l'échange échoue : sans lui, un serveur qui
                // tombe laisserait le bandeau allumé pour toujours.
                echange.respond(cacher);
            });
        };

        document.addEventListener('livewire:init', brancher);
        document.addEventListener('livewire:navigated', function () {
            // Une navigation interrompt ce qui était en vol : on repart de zéro plutôt que
            // de laisser un compteur décalé allumer le bandeau sur la page suivante.
            enCours = 0;

            var element = bandeau();

            if (element) { element.style.display = 'none'; }
        });

        brancher();
    })();
</script>
