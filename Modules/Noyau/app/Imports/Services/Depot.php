<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Modeles\LotImport;
use RuntimeException;

/**
 * Recevoir un fichier : le ranger, le déclarer, et lancer le travail.
 *
 * L'ordre des trois gestes est le sujet de cette classe. On **range d'abord**, on répond
 * ensuite, on traite après. La requête qui reçoit le téléversement ne lit pas une ligne :
 * elle vérifie, copie, crée le lot et rend la main. Le reste part en file d'attente.
 *
 * **Le doublon est refusé sur le contenu, pas sur le nom.** L'empreinte est un SHA-256 du
 * fichier lui-même : deux extractions renommées différemment mais identiques donnent la
 * même empreinte et ne s'importent qu'une fois. C'est la réponse à la demande — « si un
 * import est déjà fait, qu'il ne soit pas repris par une autre, on ne doit pas avoir de
 * doublon ». Un nom de fichier n'aurait rien empêché, puisque justement le nom est réécrit
 * à la main avant chaque envoi.
 *
 * **Le périmètre est vérifié ici, pas seulement à l'écran.** Un responsable de ville dépose
 * pour sa ville. Le gérant dépose pour n'importe laquelle. La liste déroulante d'une page
 * n'a jamais empêché personne d'envoyer autre chose.
 */
class Depot
{
    public function __construct(private int $entrepriseId) {}

    /**
     * Range un fichier déposé et met le traitement en file d'attente.
     *
     * @param  bool  $simuler  demander un contrôle plutôt qu'un import : tout est lu et
     *                         analysé, rien n'est écrit
     * @param  bool  $toutesVilles  le fichier n'a été filtré sur aucune ville : la
     *                              ventilation se fera sur les codes employés
     *
     * @throws RuntimeException quand le fichier, le format ou le périmètre ne conviennent pas
     */
    public function recevoir(
        User $deposant,
        UploadedFile $fichier,
        string $format,
        ?int $villeId,
        bool $simuler = false,
        ?int $siteId = null,
        bool $toutesVilles = false,
    ): LotImport {
        if (! Registre::connait($format)) {
            throw new RuntimeException("Ce type de fichier n'est pas encore pris en charge.");
        }

        $this->verifierLePerimetre($deposant, $villeId, $toutesVilles);

        // Un dépôt non filtré ne porte pas d'atelier : déclarer « toutes les villes » et
        // « Site 1 » à la fois se contredit, et c'est la première déclaration qui tient.
        $siteId = $villeId === null ? null : $this->verifierLAtelier($deposant, $villeId, $siteId);

        $chemin = $fichier->getRealPath();

        if ($chemin === false || ! is_file($chemin)) {
            throw new RuntimeException("Le fichier n'est pas arrivé jusqu'au bout.");
        }

        // Le contrôle du contenu vient avant tout le reste : un fichier qui n'est pas un
        // classeur ne mérite ni une place sur le disque ni une ligne en base.
        if (Classeur::format($chemin) === null) {
            throw new RuntimeException(
                "Ce fichier n'est ni un classeur Excel récent (.xlsx, .xlsm) ni un classeur ancien (.xls)."
            );
        }

        if ($fichier->getSize() > Classeur::POIDS_MAXIMAL) {
            throw new RuntimeException('Le fichier dépasse '.(int) (Classeur::POIDS_MAXIMAL / 1048576).' Mo.');
        }

        $empreinte = LotImport::empreinteDe($chemin);

        // Un lot annulé ne fait plus obstacle : on annule précisément pour redéposer le
        // même fichier autrement — sous la bonne ville, sous le bon atelier. Le refus du
        // doublon protège du travail fait deux fois, pas du travail qu'on refait exprès.
        $deja = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('empreinte', $empreinte)
            ->where('etat', '!=', 'annule')
            ->first();

        if ($deja !== null) {
            throw new DepotEnDouble($deja);
        }

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $villeId,
            'site_id' => $siteId,
            'user_id' => $deposant->id,
            // Le nom est recopié à côté du lien : l'auteur peut partir, ce qu'il a importé
            // reste, et le journal doit rester lisible.
            'deposant' => mb_substr($deposant->name, 0, 120),
            'format' => $format,
            'nom_fichier' => mb_substr($fichier->getClientOriginalName(), 0, 255),
            'empreinte' => $empreinte,
            'taille' => (int) $fichier->getSize(),
            'periode' => (new NomDeFichier($this->entrepriseId))
                ->analyser($fichier->getClientOriginalName())['periode'],
            'etat' => 'depose',
            // Les compteurs sont posés explicitement plutôt que laissés aux valeurs par
            // défaut de la table : sans cela l'objet rendu porte des `null` là où la base
            // porte des zéros, et l'écran qui l'affiche aussitôt montre des cases vides
            // pour un lot qui vient d'être créé.
            'lignes_lues' => 0,
            'lignes_creees' => 0,
            'lignes_majs' => 0,
            'lignes_ignorees' => 0,
            'lignes_rejetees' => 0,
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        TraiterUnLot::dispatch($lot, ! $simuler);

        return $lot;
    }

    /** Relance un lot déjà déposé — après correction du fichier, ou après un échec. */
    public function relancer(LotImport $lot, bool $simuler = false): void
    {
        if (! $lot->fichierPresent()) {
            throw new RuntimeException('Le fichier de ce dépôt n\'est plus disponible. Redéposez-le.');
        }

        $lot->forceFill(['etat' => 'depose', 'message' => null, 'lignes_lues' => 0])->save();

        TraiterUnLot::dispatch($lot, ! $simuler);
    }

    /**
     * Les villes pour lesquelles cette personne a le droit de déposer.
     *
     * @return array<int, string>
     */
    public function villesOuvertes(User $deposant): array
    {
        $requete = Ville::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('est_actif', true)
            ->orderBy('nom');

        if ($deposant->hasRole('gerant')) {
            return $requete->pluck('nom', 'id')->all();
        }

        $ville = $deposant->ville_id;

        if ($ville === null && $deposant->site_id !== null) {
            $ville = Site::withoutGlobalScopes()->whereKey($deposant->site_id)->value('ville_id');
        }

        return $ville === null ? [] : $requete->whereKey($ville)->pluck('nom', 'id')->all();
    }

    /**
     * Le périmètre du déposant, et le cas du fichier non filtré.
     *
     * **Ne rien déclarer n'est pas une omission, c'est une déclaration.** Elle dit : ce
     * fichier n'a pas été filtré, ventilez-le sur les codes. Elle n'est ouverte qu'à qui
     * dépose pour plusieurs villes — sans quoi un responsable de ville s'en servirait pour
     * écrire dans les deux autres, ce que sa liste déroulante lui refuse par ailleurs.
     */
    private function verifierLePerimetre(User $deposant, ?int $villeId, bool $toutesVilles = false): void
    {
        if ($villeId === null) {
            if (! $toutesVilles) {
                throw new RuntimeException('Indiquez la ville au titre de laquelle vous déposez ce fichier.');
            }

            if (count($this->villesOuvertes($deposant)) < 2) {
                throw new RuntimeException(
                    "« Toutes les villes » ne s'ouvre qu'à qui dépose pour plusieurs villes. "
                    ."Déposez au titre de la vôtre."
                );
            }

            return;
        }

        if (! array_key_exists($villeId, $this->villesOuvertes($deposant))) {
            throw new RuntimeException("Vous ne pouvez pas déposer de fichier pour cette ville.");
        }
    }

    /**
     * Vérifie l'atelier déclaré, quand il l'est.
     *
     * Trois choses sont contrôlées ici plutôt qu'à l'écran, parce qu'une liste déroulante
     * n'a jamais empêché personne d'envoyer autre chose : que le site existe, qu'il
     * appartienne bien à la ville déclarée, et qu'il soit dans le périmètre du déposant.
     *
     * Le site est **facultatif**. L'omettre n'est pas une faute : c'est le cas d'un fichier
     * extrait sans filtre, ou d'une ville qui n'a qu'un atelier. Le rattachement reprend
     * alors sa cascade habituelle.
     */
    private function verifierLAtelier(User $deposant, int $villeId, ?int $siteId): ?int
    {
        if ($siteId === null) {
            return null;
        }

        $site = Site::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->find($siteId);

        if ($site === null) {
            throw new RuntimeException("Cet atelier n'existe pas.");
        }

        if ((int) $site->ville_id !== $villeId) {
            throw new RuntimeException("Cet atelier n'appartient pas à la ville déclarée.");
        }

        if (! array_key_exists($villeId, $this->villesOuvertes($deposant))) {
            throw new RuntimeException("Vous ne pouvez pas déposer de fichier pour cet atelier.");
        }

        return (int) $site->id;
    }

    /**
     * Les ateliers d'une ville, prêts pour une liste déroulante.
     *
     * Rendue vide quand la ville n'en compte qu'un : proposer un choix unique donne à
     * croire qu'il y en avait d'autres, et fait poser une question qui n'existe pas.
     *
     * @return array<int, string>
     */
    public function sitesDeLaVille(?int $villeId): array
    {
        if ($villeId === null) {
            return [];
        }

        $sites = Site::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('ville_id', $villeId)
            ->where('est_actif', true)
            ->orderBy('nom')
            ->pluck('nom', 'id')
            ->all();

        return count($sites) > 1 ? $sites : [];
    }
}
