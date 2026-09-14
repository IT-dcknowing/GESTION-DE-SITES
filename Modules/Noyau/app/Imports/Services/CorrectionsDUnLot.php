<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Lecteurs\LecteurCorrige;
use Modules\Noyau\Imports\Modeles\CorrectionImport;
use Modules\Noyau\Imports\Modeles\LigneRejeteeImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use RuntimeException;

/**
 * Corriger les lignes qu'un import a refusées, sans toucher au fichier reçu.
 *
 * **Ce que cette classe rend possible, et qui ne l'était pas.** Trois lignes refusées sur
 * neuf mille obligeaient à rouvrir le classeur, à le corriger dans le tableur, à le
 * redéposer en entier — et le fichier corrigé, n'ayant plus la même empreinte, revenait
 * comme un fichier neuf, sans lien avec celui qu'il remplaçait. On refaisait un import de
 * neuf mille lignes pour trois cellules.
 *
 * Désormais la correction se fait ici, ligne à ligne, sur les colonnes réelles du format —
 * pas sur quarante cases anonymes. Puis l'import se rejoue : il relit le même fichier, voit
 * les valeurs corrigées par-dessus, et **ne recrée pas ce qui existe déjà**, chaque format
 * sachant retrouver ses fiches par leur clé.
 *
 * **Trois garde-fous, et ils tiennent le tout.**
 *
 * - On ne corrige que les lignes que l'import a lui-même refusées. Le numéro de ligne vient
 *   du journal des rejets, jamais du formulaire : sans cela, n'importe quelle ligne du
 *   classeur — y compris une ligne acceptée et déjà écrite — deviendrait modifiable depuis
 *   le navigateur.
 * - On ne corrige que les colonnes du format. Le reste du classeur n'a pas à être réécrit
 *   par une page web, et la validation ne saurait pas quoi en dire.
 * - Tout est écrit dans {@see CorrectionImport} : la valeur d'avant, celle d'après, l'auteur,
 *   l'heure, l'adresse. Une correction d'import déplace de l'argent dans un chiffre
 *   d'affaires ; elle se relit comme une écriture comptable, pas comme un réglage.
 */
class CorrectionsDUnLot
{
    /** Ce qu'on accepte de recevoir dans une cellule corrigée. */
    public const LONGUEUR_MAXIMALE = 190;

    public function __construct(private int $entrepriseId) {}

    /**
     * Le plan de correction : les colonnes du format, et les lignes refusées avec leurs valeurs.
     *
     * @return array{
     *     feuille: ?string,
     *     colonnes: list<array{cle: string, intitule: string, position: int, obligatoire: bool}>,
     *     lignes: Collection<int, array{rejet: LigneRejeteeImport, valeurs: array<int, string>, correction: ?CorrectionImport}>,
     *     fichier_absent: bool,
     * }
     */
    public function plan(LotImport $lot): array
    {
        $rejets = LigneRejeteeImport::where('lot_import_id', $lot->id)
            ->orderBy('numero_ligne')
            ->get();

        $dejaFaites = $this->dernieresCorrections($lot);
        $colonnes = [];
        $absent = ! $lot->fichierPresent();

        if (! $absent && Registre::connait($lot->format)) {
            $colonnes = $this->colonnesDuFichier($lot);
        }

        // Sans le fichier, on ne peut pas nommer les colonnes — mais on connaît encore leur
        // place, parce que le journal des rejets a conservé les valeurs d'origine. On
        // repasse donc aux repères du tableur, et la ligne reste corrigeable.
        if ($colonnes === []) {
            $colonnes = $this->colonnesParLeurPlace($rejets, $dejaFaites);
        }

        $lignes = $rejets->map(function (LigneRejeteeImport $rejet) use ($dejaFaites) {
            $correction = $dejaFaites->get((int) $rejet->numero_ligne);

            // Ce qu'on montre dans les champs, c'est l'état courant : les valeurs
            // corrigées s'il y en a, celles du fichier sinon. Réafficher l'original après
            // une correction donnerait à croire qu'elle n'a pas été prise.
            $valeurs = array_map(
                fn ($v) => (string) $v,
                ($correction?->valeurs_apres ?? $rejet->valeurs ?? []),
            );

            return ['rejet' => $rejet, 'valeurs' => $valeurs, 'correction' => $correction];
        });

        return [
            'feuille' => $rejets->first()?->feuille,
            'colonnes' => $colonnes,
            'lignes' => $lignes,
            'fichier_absent' => $absent,
        ];
    }

    /**
     * Enregistre les corrections postées.
     *
     * @param  array<int, array<int, string>>  $saisies  numéro de ligne => position => valeur
     * @param  list<int>  $retirees  les numéros de ligne à écarter de l'import
     * @return array{corrigees: int, retirees: int}
     */
    public function enregistrer(
        LotImport $lot,
        array $saisies,
        array $retirees,
        User $auteur,
        ?string $adresseIp,
        ?string $poste,
        ?string $motif = null,
    ): array {
        if ((int) $lot->entreprise_id !== $this->entrepriseId) {
            throw new RuntimeException("Ce dépôt n'appartient pas à votre entreprise.");
        }

        // Les lignes corrigeables sont celles que l'import a refusées, et elles seules.
        $rejets = LigneRejeteeImport::where('lot_import_id', $lot->id)->get()->keyBy('numero_ligne');
        $positions = $this->positionsAutorisees($lot);
        $courantes = $this->dernieresCorrections($lot);

        $corrigees = 0;
        $ecartees = 0;

        DB::transaction(function () use (
            $lot, $saisies, $retirees, $auteur, $adresseIp, $poste, $motif,
            $rejets, $positions, $courantes, &$corrigees, &$ecartees
        ) {
            foreach ($rejets as $numero => $rejet) {
                $numero = (int) $numero;
                $retiree = in_array($numero, $retirees, true);
                $avant = array_map(
                    fn ($v) => (string) $v,
                    ($courantes->get($numero)?->valeurs_apres ?? $rejet->valeurs ?? []),
                );

                if ($retiree) {
                    $this->ecrire($lot, $rejet, 'retiree', $avant, null, $auteur, $adresseIp, $poste, $motif);
                    $ecartees++;

                    continue;
                }

                $apres = $avant;
                $touchee = false;

                foreach (($saisies[$numero] ?? []) as $position => $valeur) {
                    $position = (int) $position;

                    // Une position hors des colonnes du format n'est pas une erreur de
                    // l'utilisateur : c'est un champ qui n'existe pas dans la page. On
                    // l'ignore sans rien dire plutôt que de laisser écrire n'importe où.
                    if ($positions !== [] && ! in_array($position, $positions, true)) {
                        continue;
                    }

                    $valeur = mb_substr(trim((string) $valeur), 0, self::LONGUEUR_MAXIMALE);

                    if ($valeur !== (string) ($apres[$position] ?? '')) {
                        $apres[$position] = $valeur;
                        $touchee = true;
                    }
                }

                if ($touchee) {
                    $this->ecrire($lot, $rejet, 'corrigee', $avant, $apres, $auteur, $adresseIp, $poste, $motif);
                    $corrigees++;
                }
            }
        });

        return ['corrigees' => $corrigees, 'retirees' => $ecartees];
    }

    /**
     * La carte des corrections, prête pour {@see LecteurCorrige}.
     *
     * @return array<string, array{action: string, valeurs: array<int, string>}>
     */
    public function carte(LotImport $lot): array
    {
        $carte = [];

        foreach ($this->dernieresCorrections($lot) as $correction) {
            $carte[LecteurCorrige::cle($correction->feuille, (int) $correction->numero_ligne)] = [
                'action' => $correction->action,
                'valeurs' => array_map(fn ($v) => (string) $v, $correction->valeurs_apres ?? []),
            ];
        }

        return $carte;
    }

    /**
     * Toute l'histoire des corrections d'un lot, la plus récente d'abord.
     *
     * @return Collection<int, CorrectionImport>
     */
    public function histoire(LotImport $lot): Collection
    {
        return CorrectionImport::where('lot_import_id', $lot->id)
            ->with('utilisateur')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * La dernière correction de chaque ligne — c'est elle qui fait foi.
     *
     * @return Collection<int, CorrectionImport> indexée par numéro de ligne
     */
    public function dernieresCorrections(LotImport $lot): Collection
    {
        return CorrectionImport::where('lot_import_id', $lot->id)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (CorrectionImport $c) => (int) $c->numero_ligne);
    }

    /** Les positions de colonnes que le format connaît dans ce fichier. */
    private function positionsAutorisees(LotImport $lot): array
    {
        if ($lot->fichierPresent() && Registre::connait($lot->format)) {
            $positions = array_column($this->colonnesDuFichier($lot), 'position');

            if ($positions !== []) {
                return $positions;
            }
        }

        // Le même repli que pour l'affichage, et c'est indispensable qu'il soit le même :
        // proposer un champ à l'écran puis refuser sa valeur à l'enregistrement donnerait
        // une page qui accepte la saisie et n'en garde rien.
        $rejets = LigneRejeteeImport::where('lot_import_id', $lot->id)->get();

        return array_column(
            $this->colonnesParLeurPlace($rejets, $this->dernieresCorrections($lot)),
            'position'
        );
    }

    /**
     * Les colonnes désignées par leur place dans le tableur, quand le fichier n'est plus là.
     *
     * **Pourquoi ce repli existe.** Un dépôt dont le fichier a été retiré du disque n'avait
     * plus aucune colonne nommable, et l'écran de correction se réduisait alors à une case
     * « Retirer » par ligne : on pouvait écarter une ligne, jamais la réparer. Or le journal
     * des rejets a gardé les valeurs d'origine avec leur position — c'est moins qu'un
     * en-tête, mais c'est assez pour corriger, et « Colonne D » se retrouve dans un tableur
     * en une seconde.
     *
     * Les positions sont prises sur **l'ensemble des lignes refusées du dépôt**, pas ligne
     * par ligne : une cellule vide ne figure pas dans les valeurs conservées, et c'est
     * justement celle-là qu'on veut pouvoir remplir.
     *
     * @param  Collection<int, LigneRejeteeImport>  $rejets
     * @param  Collection<int, CorrectionImport>  $dejaFaites
     * @return list<array{cle: string, intitule: string, position: int, obligatoire: bool}>
     */
    private function colonnesParLeurPlace(Collection $rejets, Collection $dejaFaites): array
    {
        $positions = [];

        foreach ($rejets as $rejet) {
            $valeurs = $dejaFaites->get((int) $rejet->numero_ligne)?->valeurs_apres ?? $rejet->valeurs ?? [];

            foreach (array_keys($valeurs) as $position) {
                if (is_numeric($position)) {
                    $positions[(int) $position] = true;
                }
            }
        }

        if ($positions === []) {
            return [];
        }

        $positions = array_keys($positions);
        sort($positions);

        return array_map(fn (int $position) => [
            'cle' => 'colonne_'.$position,
            'intitule' => 'Colonne '.self::repereDeColonne($position),
            'position' => $position,
            'obligatoire' => false,
        ], $positions);
    }

    /**
     * Le repère d'une colonne comme l'écrit un tableur : 0 donne A, 25 donne Z, 26 donne AA.
     *
     * La version précédente s'arrêtait à Z et rendait Z pour tout ce qui suivait. Sur le
     * fichier du parc, qui compte trente-six colonnes, onze d'entre elles se seraient
     * appelées « Colonne Z » — et une correction posée sur la mauvaise aurait écrit un
     * montant dans une date.
     */
    public static function repereDeColonne(int $position): string
    {
        $repere = '';

        for ($reste = max(0, $position); ; $reste = intdiv($reste, 26) - 1) {
            $repere = chr(65 + $reste % 26).$repere;

            if ($reste < 26) {
                return $repere;
            }
        }
    }

    /**
     * Les colonnes du format, retrouvées dans l'en-tête réel du fichier.
     *
     * On relit le fichier plutôt que de deviner : l'ordre des colonnes varie d'un export à
     * l'autre, et une correction posée sur la mauvaise position écrirait un montant dans
     * une date.
     *
     * @return list<array{cle: string, intitule: string, position: int, obligatoire: bool}>
     */
    private function colonnesDuFichier(LotImport $lot): array
    {
        $classe = Registre::classe($lot->format);
        $lecteur = Classeur::ouvrir($lot->chemin());

        try {
            return (new $classe($this->entrepriseId, new Rattachement($this->entrepriseId)))
                ->planDesColonnes($lecteur);
        } catch (\Throwable) {
            return [];
        } finally {
            $lecteur->fermer();
        }
    }

    private function ecrire(
        LotImport $lot,
        LigneRejeteeImport $rejet,
        string $action,
        array $avant,
        ?array $apres,
        User $auteur,
        ?string $adresseIp,
        ?string $poste,
        ?string $motif,
    ): void {
        CorrectionImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entrepriseId,
            'lot_import_id' => $lot->id,
            'ligne_rejetee_id' => $rejet->id,
            'numero_ligne' => (int) $rejet->numero_ligne,
            'feuille' => $rejet->feuille,
            'action' => $action,
            'valeurs_avant' => $avant,
            'valeurs_apres' => $apres,
            'motif' => $motif === null ? null : mb_substr($motif, 0, 255),
            'user_id' => $auteur->id,
            'auteur' => mb_substr($auteur->name, 0, 120),
            'adresse_ip' => $adresseIp === null ? null : mb_substr($adresseIp, 0, 45),
            'poste' => $poste === null ? null : mb_substr($poste, 0, 255),
        ]);
    }
}
