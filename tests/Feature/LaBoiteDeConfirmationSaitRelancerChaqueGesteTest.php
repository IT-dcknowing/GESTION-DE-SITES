<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La boîte de confirmation sait relancer chaque geste qu'elle intercepte.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Le défaut que ce test existe pour empêcher — relevé le 02/10.** *« Après avoir confirmé,
 * rien ne se passe. »*
 *
 * La boîte intercepte le clic en phase de capture, avec `preventDefault()` et
 * `stopPropagation()` : le geste d'origine ne part pas, il est mis en attente. Si l'on
 * confirme, `relancer()` le repose. Or `relancer()` ne connaissait que **deux** formes : un
 * lien, qu'on suit, et un bouton de formulaire, qu'on soumet. Tout le reste tombait sur un
 * `return` muet.
 *
 * Deux écrans portaient des boutons d'une troisième forme — `<button type="button">` avec un
 * `wire:click`, hors de tout formulaire :
 *
 * - **Maintenance (SuperAdmin)** — « Vider les ensembles cochés » ;
 * - **Barème de commission (Gérant)** — « Première activation », « Récrire la grille en
 *   vigueur » et « Poser une nouvelle grille ». Ce fichier ne contient aucun `<form>` : les
 *   trois gestes demandaient une confirmation, puis ne faisaient rien.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi un test sur le texte des gabarits, et non sur le comportement.** Le défaut est
 * dans du JavaScript, et le projet n'a pas de navigateur de test. Un test qui se contenterait
 * de lire le composant ne prouverait rien. Celui-ci tient l'autre bout, qui est le bout
 * utile : **tout élément qui demande une confirmation doit être d'une forme que la boîte sait
 * reposer.** Une quatrième forme introduite demain tombe ici, et non en production.
 */
class LaBoiteDeConfirmationSaitRelancerChaqueGesteTest extends TestCase
{
    /** Les formes que `relancer()` sait reposer, chacune dans l'ordre où il les essaie. */
    private const FORMES = [
        'une note qui n’attend aucun geste' => '/data-confirmer-mode\s*=\s*"information"/',
        'un lien, qu’on suit' => '/^<a\b/',
        'un bouton de formulaire, qu’on soumet' => '/type\s*=\s*"submit"/',
        'un bouton d’action, dont on repose le clic' => '/\b(wire:click|x-on:click|@click)/',
    ];

    public function test_chaque_geste_a_confirmer_est_dune_forme_que_la_boite_sait_reposer(): void
    {
        $balises = $this->balisesQuiDemandentUneConfirmation();

        $this->assertNotEmpty(
            $balises,
            'Aucune balise trouvée : le test ne lit plus les gabarits, donc il ne garde plus rien.',
        );

        $orphelines = [];

        foreach ($balises as $balise) {
            $connue = false;

            foreach (self::FORMES as $motif) {
                if (preg_match($motif, $balise['texte']) === 1) {
                    $connue = true;
                    break;
                }
            }

            if (! $connue) {
                $orphelines[] = $balise['fichier'].':'.$balise['ligne'];
            }
        }

        $this->assertSame([], $orphelines, implode("\n", [
            'Ces éléments demandent une confirmation que la boîte ne saura pas reposer :',
            ...$orphelines,
            '',
            'La boîte annule le clic en phase de capture. Si elle ne sait pas le reposer,',
            'confirmer ne fait rien du tout — et c’est précisément le geste le plus grave',
            'de l’écran qui ne part pas. Voir resources/views/components/confirmation.blade.php.',
        ]));
    }

    /**
     * Les gestes connus, nommés, pour que la liste ne maigrisse pas sans qu'on le voie.
     *
     * Un bouton qu'on retirerait par accident — en refondant un écran, par exemple — ferait
     * passer le test précédent sans rien garder. Celui-ci dit combien il y en a et où.
     */
    public function test_les_ecrans_qui_demandent_une_confirmation_sont_ceux_quon_connait(): void
    {
        $parFichier = [];

        foreach ($this->balisesQuiDemandentUneConfirmation() as $balise) {
            $parFichier[$balise['fichier']] = ($parFichier[$balise['fichier']] ?? 0) + 1;
        }

        ksort($parFichier);

        $this->assertSame([
            'Modules/Gerant/resources/views/gerant/bareme-commission.blade.php' => 4,
            'Modules/Import/resources/views/components/progression.blade.php' => 1,
            'Modules/Import/resources/views/import/lot.blade.php' => 1,
            'Modules/SuperAdmin/resources/views/superadmin/maintenance.blade.php' => 1,
            'Modules/Superviseur/resources/views/pilotage/acces-creer.blade.php' => 3,
        ], $parFichier, implode("\n", [
            'La liste des gestes à confirmer a changé.',
            'Si c’est voulu, mettez ce tableau à jour — il est là pour que le changement se voie.',
        ]));
    }

    /**
     * @return array<int, array{fichier: string, ligne: int, texte: string}>
     */
    private function balisesQuiDemandentUneConfirmation(): array
    {
        $racine = str_replace('\\', '/', base_path());
        $balises = [];

        $fichiers = array_merge(
            glob($racine.'/Modules/*/resources/views/**/*.blade.php') ?: [],
            glob($racine.'/Modules/*/resources/views/**/**/*.blade.php') ?: [],
            glob($racine.'/resources/views/**/*.blade.php') ?: [],
        );

        foreach (array_unique($fichiers) as $chemin) {
            $relatif = ltrim(str_replace($racine, '', str_replace('\\', '/', $chemin)), '/');

            // Le composant lui-même parle de `data-confirmer` sans en porter : c'est lui
            // qui les lit.
            if ($relatif === 'resources/views/components/confirmation.blade.php') {
                continue;
            }

            $contenu = file_get_contents($chemin);

            if (! str_contains($contenu, 'data-confirmer=')) {
                continue;
            }

            /*
             * On isole chaque balise ouvrante en partant du `<`, et **on saute ce qui est
             * entre guillemets**.
             *
             * Ce détail est tout le test. S'arrêter au premier `>` paraît suffire, et c'est
             * faux : une valeur d'attribut Blade en contient — `{{ $n > 0 ? … }}`, un
             * `=>` dans un tableau. La balise était alors coupée en deux, la moitié qui
             * portait `wire:click` tombait hors du morceau examiné, et le test déclarait
             * orphelin un bouton très bien formé — ou, bien pire ici, en laissait passer un
             * qui ne l'était pas. Mesuré en l'écrivant : quatre balises sur dix-huit étaient
             * mal découpées.
             */
            $position = 0;

            while (($debut = strpos($contenu, '<', $position)) !== false) {
                $i = $debut + 1;
                $guillemet = null;
                $longueur = strlen($contenu);

                while ($i < $longueur) {
                    $caractere = $contenu[$i];

                    if ($guillemet !== null) {
                        if ($caractere === $guillemet) {
                            $guillemet = null;
                        }
                    } elseif ($caractere === '"' || $caractere === "'") {
                        $guillemet = $caractere;
                    } elseif ($caractere === '>') {
                        break;
                    }

                    $i++;
                }

                $ouvrante = substr($contenu, $debut, $i - $debut + 1);

                // On reprend **après** la balise, et non un caractère plus loin : repartir du
                // début ferait recompter la même balise autant de fois qu'elle contient de
                // `<` dans ses attributs.
                $position = $i + 1;

                if (! str_contains($ouvrante, 'data-confirmer=')) {
                    continue;
                }

                $balises[] = [
                    'fichier' => $relatif,
                    'ligne' => substr_count(substr($contenu, 0, $debut), "\n") + 1,
                    'texte' => $ouvrante,
                ];
            }
        }

        return $balises;
    }
}
