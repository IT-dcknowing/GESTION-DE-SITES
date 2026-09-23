{{-- La boîte de confirmation de l'application, au centre de l'écran.

     **Pourquoi ne plus utiliser celle du navigateur.** `confirm()` affiche une boîte
     système : elle s'ancre en haut de la fenêtre, porte l'adresse du site en titre
     (« 127.0.0.1:8004 indique »), ignore les couleurs et la typographie de
     l'application, et ne sait afficher qu'un bloc de texte gris. Sur un geste grave —
     supprimer un accès, annuler un import — c'est exactement le moment où l'écran doit
     avoir l'air de savoir ce qu'il fait.

     **Comment elle s'emploie.** N'importe quel bouton ou lien porte
     `data-confirmer="La question posée."`, et facultativement `data-confirmer-titre`,
     `data-confirmer-detail` et `data-confirmer-ton="alerte"`. Rien d'autre : la boîte est
     posée une seule fois dans la mise en page, et un seul écouteur sert toute
     l'application.

     **Le mode information.** `data-confirmer-mode="information"` affiche la même boîte
     pour dire quelque chose plutôt que pour demander : un seul bouton « Fermer », et
     aucun geste relancé. C'est ce qu'il fallait pour les notes du barème, qui
     s'ouvraient jusqu'ici en dépliant la page sous le tableau qu'on était en train de
     lire — elles poussaient le tableau vers le bas au moment où l'on comparait les
     tranches. Une note qu'on lit puis qu'on referme est une boîte, pas un volet.

     **Ce qui se passe sans JavaScript.** Le geste part directement, sans question. C'est
     une dégradation assumée et elle est du bon côté : mieux vaut un bouton qui agit qu'un
     bouton qui ne fait rien. Les gestes vraiment irréversibles — annuler un import,
     supprimer une entreprise — demandent en plus un motif écrit dans un champ du
     formulaire, qui lui ne dépend d'aucun script. --}}

<dialog id="boite-confirmation" aria-labelledby="boite-confirmation-titre"
        style="border:0; border-radius:14px; padding:0; max-width:min(94vw, 470px); width:100%;
               box-shadow:0 26px 70px rgba(25,27,32,.34); font-family:var(--font-sans);
               color:var(--th-ink,#191B20);">

    <div id="boite-confirmation-bande" style="height:5px; background:var(--th-accent,#C8102E);"></div>

    <div style="padding:22px 24px 20px;">
        <h2 id="boite-confirmation-titre"
            style="font-family:'Barlow Condensed',sans-serif; font-size:22px; font-weight:700;
                   text-transform:uppercase; letter-spacing:1px; margin:0 0 10px;">
            Confirmer
        </h2>

        <p id="boite-confirmation-message" style="font-size:15px; line-height:1.6; margin:0;"></p>

        <p id="boite-confirmation-detail"
           style="font-size:13px; line-height:1.6; margin:10px 0 0; color:var(--th-gris,#6B6E76);" hidden></p>

        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:22px; flex-wrap:wrap;">
            <button type="button" id="boite-confirmation-annuler"
                    style="background:#fff; border:1.5px solid var(--th-ligne,#E2E0D8); color:#4B4E55;
                           border-radius:8px; padding:9px 18px; font-size:14.5px; font-weight:700;
                           cursor:pointer; font-family:inherit;">
                Annuler
            </button>
            <button type="button" id="boite-confirmation-valider"
                    style="background:var(--th-accent,#C8102E); border:0; color:#fff; border-radius:8px;
                           padding:9px 20px; font-size:14.5px; font-weight:700; cursor:pointer;
                           font-family:inherit;">
                Confirmer
            </button>
        </div>
    </div>
</dialog>

<script data-navigate-once>
(function () {
    /*
     * Les éléments sont relus à chaque ouverture, et jamais gardés en mémoire.
     *
     * **La panne qu'on corrige.** `wire:navigate` remplace le corps de la page sans
     * recharger les scripts — c'est tout son intérêt. Le script, lui, retenait la boîte
     * trouvée au premier chargement : après une seule navigation, cette boîte-là n'était
     * plus dans le document, et le clic suivant levait « Failed to execute 'showModal' on
     * 'HTMLDialogElement': The element is not in a Document ». Plus aucune confirmation ne
     * s'ouvrait tant qu'on ne rechargeait pas la page à la main.
     *
     * Chercher l'élément au moment du clic coûte un `getElementById` et rend le script
     * indifférent au nombre de navigations.
     */
    var boite = function () { return document.getElementById('boite-confirmation'); };
    var par = function (suffixe) { return document.getElementById('boite-confirmation-' + suffixe); };

    // L'élément qu'on relancera si la personne confirme. On repose le geste tel quel
    // plutôt que de le simuler : un formulaire se soumet, un lien se suit.
    var enAttente = null;

    var fermer = function () {
        var dialogue = boite();

        enAttente = null;

        if (dialogue && dialogue.open) {
            dialogue.close();
        }
    };

    var relancer = function () {
        var cible = enAttente;

        fermer();

        if (! cible) { return; }

        // En mode information, il n'y a pas de geste à relancer : la boîte a dit ce
        // qu'elle avait à dire.
        if (cible.getAttribute('data-confirmer-mode') === 'information') { return; }

        if (cible.tagName === 'A') {
            window.location.href = cible.href;

            return;
        }

        // Un bouton de formulaire porte souvent un nom et une valeur qui disent *quel*
        // geste il déclenche : requestSubmit les conserve, submit() les perdrait.
        var formulaire = cible.form || cible.closest('form');

        if (! formulaire) { return; }

        if (typeof formulaire.requestSubmit === 'function') {
            formulaire.requestSubmit(cible.tagName === 'BUTTON' ? cible : undefined);
        } else {
            formulaire.submit();
        }
    };

    /*
     * Un seul écouteur sur le document, posé une fois pour toutes.
     *
     * Les boutons de la boîte sont eux aussi remplacés à chaque navigation : leur attacher
     * un écouteur au chargement le perdrait au premier `wire:navigate`. On écoute donc le
     * document, qui ne change pas, et l'on reconnaît le bouton à son identifiant.
     */
    document.addEventListener('click', function (evenement) {
        var valider = evenement.target.closest('#boite-confirmation-valider');

        if (valider) {
            evenement.preventDefault();
            relancer();

            return;
        }

        if (evenement.target.closest('#boite-confirmation-annuler')) {
            evenement.preventDefault();
            fermer();

            return;
        }

        var cible = evenement.target.closest('[data-confirmer]');

        if (! cible || cible === enAttente) { return; }

        var dialogue = boite();

        // Navigateur sans <dialog>, ou boîte absente de cette mise en page : le geste part
        // directement, comme sans script. Mieux vaut un bouton qui agit qu'un bouton mort.
        if (! dialogue || typeof dialogue.showModal !== 'function') { return; }

        evenement.preventDefault();
        evenement.stopPropagation();

        par('titre').textContent = cible.getAttribute('data-confirmer-titre') || 'Confirmer';
        par('message').textContent = cible.getAttribute('data-confirmer');

        var complement = cible.getAttribute('data-confirmer-detail');
        par('detail').textContent = complement || '';
        par('detail').hidden = ! complement;

        var alerte = cible.getAttribute('data-confirmer-ton') === 'alerte';
        par('bande').style.background = alerte ? '#C8102E' : '#191B20';
        par('valider').style.background = alerte ? '#C8102E' : '#191B20';

        var information = cible.getAttribute('data-confirmer-mode') === 'information';
        // « Annuler » n'a pas de sens devant une note : il n'y a rien à annuler.
        par('annuler').hidden = information;
        par('valider').textContent = cible.getAttribute('data-confirmer-libelle')
            || (information ? 'Fermer' : 'Confirmer');

        enAttente = cible;
        dialogue.showModal();
        par('valider').focus();
    }, true);

    // La touche Échap ferme la boîte : le geste en attente ne doit pas lui survivre.
    document.addEventListener('cancel', function (evenement) {
        if (evenement.target && evenement.target.id === 'boite-confirmation') {
            enAttente = null;
        }
    }, true);
})();
</script>
