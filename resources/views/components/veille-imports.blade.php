@php
    use Modules\Import\Support\AccesImport;

    // Seuls ceux qui voient les imports ont une raison d'être prévenus. Pour les autres, le
    // composant ne rend rien du tout : pas de script, pas d'appel réseau, pas de bruit.
    $ouvert = auth()->check() && AccesImport::peutVoir(auth()->user(), 'lots');
@endphp

@if ($ouvert)
{{-- La veille des imports — la sonnette qui manquait.

     **Ce qu'elle résout.** Un import se termine pendant qu'on travaille ailleurs : on est
     sur la balance âgée, et le parc vient de finir de se charger. Jusqu'ici il fallait
     retourner sur la page de l'import et rafraîchir pour l'apprendre. Or c'est au moment où
     il finit que l'information sert — c'est là qu'on peut aller voir les chiffres.

     Elle interroge une petite adresse de loin en loin et annonce ce qui vient de finir, par
     la même bulle que le reste de l'application, trois secondes. Du JavaScript nu : elle ne
     doit rien à la couche interactive, qui est précisément ce qui manque là où ce travail a
     commencé.

     **Elle se tait quand il n'y a rien à dire**, et c'est la moitié du travail. Une sonnette
     qui sonne pour tout finit par ne plus être entendue : on n'annonce que la transition —
     un lot qui était en cours et ne l'est plus — jamais un état. Et on ne recommence pas :
     ce qui a été annoncé une fois est retenu pour la durée de l'onglet. --}}
<script data-navigate-once>
(function () {
    var ADRESSE = @json(route('import.traitements.etat'));

    // Assez rare pour ne rien coûter, assez fréquent pour que la nouvelle arrive pendant
    // qu'elle intéresse encore : un import de neuf mille lignes dure quelques secondes.
    var CADENCE = 6000;

    // Au repos, on n'interroge plus : rien ne tourne, rien ne finira.
    var CADENCE_AU_REPOS = 30000;

    var dejaDits = {};
    var premierPassage = true;

    var annoncer = function (texte, ton) {
        if (typeof window.annonce === 'function') {
            window.annonce(texte, ton);
        }
    };

    var battre = function (delai) {
        window.setTimeout(veiller, delai);
    };

    var veiller = function () {
        // Un onglet caché n'a personne devant lui : inutile d'interroger le serveur pour
        // une bulle que nul ne verra, et qui aurait disparu au retour.
        if (document.hidden) {
            battre(CADENCE_AU_REPOS);

            return;
        }

        fetch(ADRESSE, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (reponse) { return reponse.ok ? reponse.json() : null; })
            .then(function (etat) {
                if (! etat) { battre(CADENCE_AU_REPOS); return; }

                (etat.termines || []).forEach(function (lot) {
                    if (dejaDits[lot.id]) { return; }

                    dejaDits[lot.id] = true;

                    // Au tout premier passage on se contente de retenir ce qui est déjà
                    // fini : annoncer à l'ouverture d'une page ce qui s'est terminé il y a
                    // dix minutes serait du bruit, pas une nouvelle.
                    if (premierPassage) { return; }

                    if (lot.etat === 'echec') {
                        annoncer('Import interrompu : ' + lot.fichier + '. Rien n\'a été écrit.', 'alerte');

                        return;
                    }

                    annoncer(
                        'Import terminé : ' + lot.fichier + ' — '
                        + lot.lues.toLocaleString('fr-FR') + ' ligne(s) lue(s)'
                        + (lot.rejetees > 0 ? ', ' + lot.rejetees + ' refusée(s)' : '') + '.',
                        lot.rejetees > 0 ? 'alerte' : 'succes'
                    );
                });

                premierPassage = false;
                battre((etat.en_cours || []).length > 0 ? CADENCE : CADENCE_AU_REPOS);
            })
            .catch(function () { battre(CADENCE_AU_REPOS); });
    };

    battre(1500);
})();
</script>
@endif
