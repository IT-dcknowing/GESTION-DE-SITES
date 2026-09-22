<?php

namespace Modules\Noyau\Imports\Concerns;

use DateTimeInterface;
use Modules\Noyau\Imports\Formats\Format;

/**
 * Montrer les colonnes du fichier, sans pouvoir en oublier une.
 *
 * **La règle qu'on s'était donnée** : les colonnes d'une page listent d'abord celles du
 * fichier d'origine, avant tout ajout de notre invention. Elle tenait par la vigilance,
 * c'est-à-dire pas longtemps — mesuré le 24/09, la page de détail d'une pièce fournisseur
 * recopiait 34 des 41 colonnes du format, et les sept oubliées comprenaient « TVA 2 », dont
 * l'import explique pourtant par écrit qu'il la conserve exprès. Une liste recopiée à la
 * main diverge de sa source : c'est sa nature, pas un accident.
 *
 * **Ce trait retire la recopie.** Les intitulés et l'ordre viennent de `colonnes()` du
 * format, qui est la seule description du fichier. Une colonne ajoutée à un format apparaît
 * d'elle-même à l'écran ; une colonne renommée dans le fichier l'est aux deux endroits à la
 * fois. Il n'y a plus deux listes à tenir d'accord.
 *
 * **Les intitulés restent ceux du logiciel** — « IMMAT. VEHICULE », « DATE THEORIQUE
 * ATELIER », fautes comprises. Quiconque a la fiche sous les yeux dans son logiciel doit
 * retrouver ici les mêmes mots : traduire obligerait chacun à faire la correspondance de
 * tête et finirait par créer deux vocabulaires pour une seule réalité.
 *
 * **Une valeur vide s'affiche, et ne se cache pas.** Sur une page qui répond à « que
 * sait-on de cette pièce ? », « rien » est une réponse — et elle se distingue mal d'une
 * colonne qu'on aurait oublié d'afficher, ce qui est précisément le défaut réparé ici.
 */
trait MontreLesColonnesDuFichier
{
    /**
     * Le format dont ce modèle reçoit ses lignes.
     *
     * @return class-string<Format>
     */
    abstract public static function formatDOrigine(): string;

    /**
     * Les colonnes qui portent des francs, pour qu'elles s'affichent comme tels.
     *
     * @return list<string>
     */
    protected function colonnesEnFrancs(): array
    {
        return [];
    }

    /**
     * Les colonnes du fichier qui ne se lisent pas dans un attribut du même nom.
     *
     * La colonne SITE des fichiers fournisseurs en est le seul cas aujourd'hui : elle ne
     * devient pas une colonne mais un rattachement, et se relit donc par la relation.
     *
     * @return array<string, callable>
     */
    protected function lecturesParticulieres(): array
    {
        return [];
    }

    /**
     * Toutes les colonnes du fichier : intitulé d'origine => valeur affichable.
     *
     * @return array<string, string>
     */
    public function champsDuFichier(): array
    {
        $champs = [];

        foreach (static::formatDOrigine()::colonnes() as $cle => $intitule) {
            $champs[$intitule] = $this->valeurDuFichier($cle);
        }

        return $champs;
    }

    /** La valeur d'une colonne du fichier, prête à être affichée. */
    public function valeurDuFichier(string $cle): string
    {
        $particuliere = $this->lecturesParticulieres()[$cle] ?? null;

        $valeur = $particuliere !== null ? $particuliere($this) : ($this->{$cle} ?? null);

        if ($valeur === null || $valeur === '') {
            return '—';
        }

        if ($valeur instanceof DateTimeInterface) {
            return $valeur->format('d/m/Y');
        }

        if (in_array($cle, $this->colonnesEnFrancs(), true)) {
            return ae((int) $valeur);
        }

        return (string) $valeur;
    }

    /**
     * Les colonnes rangées par bloc — et celles qu'aucun bloc ne réclame, groupées à part.
     *
     * **C'est le dernier bloc qui fait le travail.** Regrouper aide à lire : l'identité, les
     * dates, les montants. Mais un regroupement écrit à la main est une seconde liste, et
     * une seconde liste s'oublie. Tout ce qu'aucun bloc ne nomme atterrit donc dans
     * « Autres colonnes du fichier » : une colonne nouvelle est visible le jour où elle
     * entre, et le pire qui puisse arriver est qu'elle soit mal rangée — jamais qu'elle
     * disparaisse.
     *
     * @param  array<string, list<string>>  $blocs  titre du bloc => clés du format
     * @return array<string, array<string, string>>
     */
    public function champsDuFichierParBloc(array $blocs, string $titreDuReste = 'Autres colonnes du fichier'): array
    {
        $colonnes = static::formatDOrigine()::colonnes();
        $rangees = [];
        $rendu = [];

        foreach ($blocs as $titre => $cles) {
            $bloc = [];

            foreach ($cles as $cle) {
                // Une clé qui ne désigne aucune colonne du format est une faute de frappe,
                // et la taire donnerait une ligne vide qu'on prendrait pour une donnée
                // manquante. On ne l'affiche pas, et le reste continue.
                if (! isset($colonnes[$cle])) {
                    continue;
                }

                $bloc[$colonnes[$cle]] = $this->valeurDuFichier($cle);
                $rangees[$cle] = true;
            }

            if ($bloc !== []) {
                $rendu[$titre] = $bloc;
            }
        }

        $reste = [];

        foreach ($colonnes as $cle => $intitule) {
            if (! isset($rangees[$cle])) {
                $reste[$intitule] = $this->valeurDuFichier($cle);
            }
        }

        if ($reste !== []) {
            $rendu[$titreDuReste] = $reste;
        }

        return $rendu;
    }
}
