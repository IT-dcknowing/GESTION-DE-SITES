{{-- Le témoin de bon fonctionnement de la couche interactive.

     Il répond à une panne qui a coûté une session entière à diagnostiquer : la page
     s'affichait parfaitement, les champs étaient là, les boutons aussi — et rien ne
     répondait. Aucune erreur à l'écran, aucune ligne dans le journal du serveur. Le
     serveur, lui, était irréprochable : il n'avait tout simplement **jamais été
     appelé**. Le script qui rend la page vivante n'était pas chargé dans le
     navigateur.

     Une page qui ne peut pas signaler sa propre panne est le pire des cas : on croit
     à un défaut de saisie, on recommence, on doute de soi. On y perd des heures.

     Ce témoin n'utilise ni Livewire ni Alpine — ce serait circulaire. Du JavaScript
     nu, quelques lignes, qui vérifie après le chargement que la couche interactive
     s'est bien annoncée. Si elle manque, il le dit, et il dit quoi faire. --}}
<div id="veille-lw" hidden
     style="position:fixed; left:0; right:0; bottom:0; z-index:9999; background:#C8102E; color:#fff;
            font-family:var(--font-sans, system-ui), sans-serif; font-size:13.5px; line-height:1.55;
            padding:12px 16px; box-shadow:0 -3px 14px rgba(0,0,0,.28);">
    <div style="max-width:1680px; margin:0 auto; display:flex; gap:14px; align-items:flex-start; flex-wrap:wrap;">
        <span style="font-size:19px; line-height:1;">⚠</span>
        <div style="flex:1; min-width:260px;">
            <strong>Cette page est affichée, mais elle ne répond pas.</strong>
            Le script qui rend les champs vivants n'a pas pu se charger&nbsp;: les listes, les filtres,
            les boutons et l'envoi de fichiers resteront sans effet, et les chiffres affichés sont ceux
            du chargement.
            <div style="margin-top:5px; opacity:.93;">
                Dans l'ordre&nbsp;: <strong>rechargez en forçant</strong> (Ctrl+Maj+R) —
                si cela ne suffit pas, essayez une <strong>fenêtre de navigation privée</strong>
                (cela écarte le cache et les extensions) — et vérifiez que le serveur tourne toujours.
            </div>
        </div>
        <button type="button" onclick="document.getElementById('veille-lw').hidden = true"
                style="background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.5); color:#fff;
                       border-radius:7px; padding:6px 12px; font:inherit; font-weight:700; cursor:pointer;">
            Masquer
        </button>
    </div>
</div>

<script data-navigate-once>
    (function () {
        // Deux secondes : le temps qu'un script de 550 Ko arrive sur une machine lente.
        // Trop court, on accuse à tort ; trop long, on laisse quelqu'un cliquer dans le vide.
        var DELAI = 2000;

        function verifier() {
            if (window.Livewire) {
                return;
            }

            var bandeau = document.getElementById('veille-lw');

            if (bandeau) {
                bandeau.hidden = false;
            }

            // Et dans la console, la même chose écrite pour qui saura la lire.
            console.error(
                '[Gestion de sites] Livewire ne s\'est pas chargé. ' +
                'Regardez l\'onglet Réseau : la requête vers /livewire-*/livewire.js doit répondre 200 ' +
                'avec environ 550 Ko. Un 404, un 0 octet ou une requête bloquée explique toute la panne.'
            );
        }

        if (document.readyState === 'complete') {
            setTimeout(verifier, DELAI);
        } else {
            window.addEventListener('load', function () { setTimeout(verifier, DELAI); });
        }
    })();
</script>
