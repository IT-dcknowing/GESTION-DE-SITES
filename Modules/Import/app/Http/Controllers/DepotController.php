<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\ControlePrealable;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\DepotEnDouble;
use RuntimeException;

/**
 * Le dépôt d'un fichier, en requête HTTP ordinaire.
 *
 * **Pourquoi un contrôleur et pas seulement le composant interactif.** L'import est le seul
 * geste de cette application dont tout le reste dépend : sans lui, il n'y a ni chiffre
 * d'affaires, ni encours, ni parc. Or il reposait entièrement sur la couche JavaScript — et
 * le jour où celle-ci n'a pas démarré dans un navigateur, le bouton « Lancer l'import » n'a
 * plus rien fait du tout. Pas d'erreur, pas de message : rien. Le serveur, lui, était
 * parfaitement capable de faire le travail ; personne ne le lui demandait.
 *
 * Un formulaire HTML qui poste vers une adresse est le mécanisme le plus ancien du web et le
 * seul qui ne puisse pas tomber en panne discrètement : ou bien la page change, ou bien elle
 * affiche une erreur. Le chemin critique passe donc par ici. L'écran garde sa partie vivante
 * — l'avancée du traitement — mais elle n'est plus qu'un confort.
 *
 * **Le contrôle préalable a lieu avant l'écriture, et il avertit sans bloquer.** Un fichier
 * dont les colonnes ne sont pas celles du type annoncé revient pour vérification, avec ce
 * qui manque nommé. Le fichier est mis de côté pendant ce temps : le faire ressortir du
 * disque pour un doute que nous avons soulevé serait une punition. Si la personne confirme,
 * l'import part — c'est elle qui connaît son fichier, pas nous.
 */
class DepotController
{
    /**
     * Où dorment les fichiers en attente de confirmation, hors du serveur web.
     *
     * Le nom vient du modèle : c'est lui que la purge interroge pour nettoyer ces mises de
     * côté, et deux copies du même nom finiraient un jour par ne plus désigner le même
     * dossier.
     */
    private const ATTENTE = LotImport::DOSSIER_ATTENTE;

    /** La clé de session qui garde le fichier mis de côté. Jamais son chemin par le client. */
    public const CLE_ATTENTE = 'import.controle';

    /**
     * La valeur qui dit « ce fichier n'a été filtré sur aucune ville ».
     *
     * Ce n'est pas l'absence de choix : c'en est un, et il a un effet précis — la ville de
     * chaque ligne est alors décidée par la colonne SITE puis par le code de l'employé qui
     * a rédigé la fiche, sans qu'aucune déclaration ne vienne trancher par défaut.
     */
    public const TOUTES_LES_VILLES = 'toutes';

    public function store(Request $requete): RedirectResponse
    {
        $utilisateur = $requete->user();

        if (! AccesImport::peutDeposer($utilisateur)) {
            return back()->with('refus-import', "Le dépôt de fichiers n'est pas ouvert à votre rôle.");
        }

        $entrepriseId = (int) $utilisateur->entreprise_id;
        $service = new Depot($entrepriseId);
        $villes = $service->villesOuvertes($utilisateur);

        // Le fichier peut venir de deux endroits : le disque de la personne, ou la mise de
        // côté d'un contrôle précédent. Dans le second cas, **le chemin ne vient pas du
        // navigateur mais de la session** : accepter un chemin posté reviendrait à laisser
        // choisir n'importe quel fichier du serveur.
        $garde = $requete->boolean('confirme') ? $this->reprendreLeFichierGarde($requete) : null;

        // « toutes » n'est proposé qu'à qui dépose pour plusieurs villes : le service le
        // revérifie de son côté, une liste déroulante n'ayant jamais fermé une requête.
        $choixDeVille = array_map('strval', array_keys($villes));

        if (count($villes) > 1) {
            $choixDeVille[] = self::TOUTES_LES_VILLES;
        }

        $requete->validate([
            'fichier' => [$garde === null ? 'required' : 'nullable', 'file', 'max:40960'],
            'format' => ['required', Rule::in(array_keys(Registre::DISPONIBLES))],
            'ville' => ['required', Rule::in($choixDeVille)],
            'site' => ['nullable', 'integer'],
        ], [
            'fichier.required' => "Choisissez d'abord un fichier.",
            'fichier.max' => 'Le fichier dépasse 40 Mo.',
            'format.required' => "Indiquez de quel type de fichier il s'agit.",
            'format.in' => "Ce type de fichier n'est pas encore pris en charge.",
            'ville.required' => 'Indiquez la ville au titre de laquelle vous déposez.',
            'ville.in' => 'Vous ne déposez pas pour cette ville.',
        ]);

        $fichier = $requete->file('fichier') ?? $garde;

        if ($fichier === null) {
            return back()->withErrors(['fichier' => "Le fichier mis de côté n'est plus disponible. Redéposez-le."]);
        }

        $format = (string) $requete->input('format');
        $toutesVilles = (string) $requete->input('ville') === self::TOUTES_LES_VILLES;
        $villeId = $toutesVilles ? null : (int) $requete->input('ville');

        // L'atelier n'est retenu que s'il appartient bien à la ville déclarée : un site
        // d'Abidjan glissé dans un dépôt pour Bouaké enverrait huit cents fiches ailleurs.
        $sites = $service->sitesDeLaVille($villeId);
        $site = $requete->input('site');
        $siteId = ($site !== null && $site !== '' && isset($sites[(int) $site])) ? (int) $site : null;

        if (! $requete->boolean('confirme')) {
            // Avec « toutes les villes », il n'y a pas de ville annoncée à contredire : le
            // contrôle se limite alors au type de fichier.
            $arret = $this->controler($requete, $fichier, $entrepriseId, $format, $villeId);

            if ($arret !== null) {
                return $arret;
            }
        }

        try {
            $lot = $service->recevoir($utilisateur, $fichier, $format, $villeId, false, $siteId, $toutesVilles);
        } catch (DepotEnDouble $double) {
            // Un doublon n'est pas une faute : c'est le plus souvent quelqu'un qui vérifie
            // que le travail a bien été fait. On répond en montrant le dépôt d'origine.
            $this->oublierLeFichierGarde($requete);

            return back()->with('doublon-import', [
                'id' => $double->lotExistant->id,
                'message' => $double->getMessage(),
            ]);
        } catch (RuntimeException $panne) {
            return back()->withErrors(['fichier' => $panne->getMessage()])->withInput($requete->except('fichier'));
        }

        $this->oublierLeFichierGarde($requete);

        return redirect()
            ->route('import.depot', ['lot' => $lot->id])
            ->with('annonce-import', 'Fichier reçu. La lecture se poursuit en arrière-plan.');
    }

    /** Le contrôle préalable ; rend une réponse quand il faut s'arrêter pour demander. */
    private function controler(
        Request $requete,
        UploadedFile $fichier,
        int $entrepriseId,
        string $format,
        ?int $villeId,
    ): ?RedirectResponse {
        $chemin = $fichier->getRealPath();

        if ($chemin === false || ! is_file($chemin)) {
            return null;
        }

        $rapport = (new ControlePrealable($entrepriseId))->examiner($chemin, $format, $villeId);

        if ($rapport['avertissements'] === []) {
            return null;
        }

        // Rangé sous le numéro de l'entreprise, et pas en tas : un fichier mis de côté
        // contient les données d'un client, et la purge doit pouvoir les emporter sans
        // toucher à ceux d'une autre entreprise. Le chemin retenu en session reste lu tel
        // quel, jamais recalculé — les mises de côté d'avant ce changement fonctionnent.
        $range = $fichier->store(self::ATTENTE.'/'.$entrepriseId, LotImport::DISQUE);

        $requete->session()->put(self::CLE_ATTENTE, [
            'chemin' => $range,
            'nom' => $fichier->getClientOriginalName(),
            'rapport' => $rapport,
        ]);

        return back()->withInput($requete->except('fichier'));
    }

    /** Le fichier mis de côté au contrôle précédent, s'il est toujours là. */
    private function reprendreLeFichierGarde(Request $requete): ?UploadedFile
    {
        $garde = $requete->session()->get(self::CLE_ATTENTE);

        if (! is_array($garde) || ! isset($garde['chemin'])) {
            return null;
        }

        $absolu = Storage::disk(LotImport::DISQUE)->path($garde['chemin']);

        if (! is_file($absolu)) {
            return null;
        }

        // `$test = true` : ce fichier n'arrive pas d'un formulaire, il vient de notre propre
        // disque. Sans ce drapeau, Laravel refuserait de le traiter comme un téléversement.
        return new UploadedFile($absolu, $garde['nom'] ?? 'fichier.xlsx', null, null, true);
    }

    private function oublierLeFichierGarde(Request $requete): void
    {
        $garde = $requete->session()->pull(self::CLE_ATTENTE);

        if (is_array($garde) && isset($garde['chemin'])) {
            Storage::disk(LotImport::DISQUE)->delete($garde['chemin']);
        }
    }
}
