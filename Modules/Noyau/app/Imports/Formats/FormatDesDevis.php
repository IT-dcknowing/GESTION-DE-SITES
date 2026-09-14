<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Imports\Modeles\DossierVehicule;

/**
 * « Devis » — les proformas.
 *
 * Dix colonnes, une seule feuille. Et **le format le plus piégeux des cinq**, pour une
 * raison qu'il faut avoir en tête avant de lire une ligne :
 *
 * **Les trois exports se recouvrent presque entièrement.** On l'a mesuré : le fichier nommé
 * « San Pédro » contient 216 de ses 217 proformas en commun avec celui nommé « Abidjan », et
 * leurs fiches renvoient au parc d'Abidjan. Autrement dit, celui qui filtre puis renomme n'a
 * pas filtré. Importer les trois fichiers en se fiant à leur nom créerait trois fois la même
 * proforma, ou pire, la déplacerait de ville à chaque dépôt.
 *
 * Deux protections en découlent, et elles sont le cœur de cette classe :
 *
 * 1. **Le numéro de proforma est la clé, et il est unique par entreprise** — pas par ville.
 *    Déposer les trois fichiers ne crée donc pas de doublon : le deuxième et le troisième
 *    reconnaissent ce qui est déjà là.
 * 2. **La ville du dépôt ne peut jamais déplacer une proforma déjà rattachée.** Un
 *    rattachement établi par la donnée — la fiche de réception, le code agent — résiste à la
 *    présomption du fichier suivant.
 *
 * Le code agent se lit ici dans le numéro de proforma, sous sa seconde forme :
 * « PR-SK-16091 » → SK. À Bouaké, certaines sortent en « PR--13699 », sans initiales ; ce
 * n'est pas une anomalie à rejeter, ce sont 36 devis bien réels.
 */
class FormatDesDevis extends Format
{
    private ?array $fichesConnues = null;

    /** @var array<string, string>|null numéro de fiche => motif de venue */
    private ?array $motifsDesFiches = null;

    private ?array $numerosConnus = null;

    public static function cle(): string
    {
        return 'devis';
    }

    public static function libelle(): string
    {
        return 'Devis et proformas';
    }

    public static function colonnes(): array
    {
        return [
            'date' => 'DATE DE LA PROFORMA',
            'numero' => 'N° PROFORMA',
            'fiche' => 'FICHE DE RECEPTION',
            'immatriculation' => 'IMMATRICULATION VEHICULE',
            'chassis' => 'NUMERO CHASSIS',
            'marque' => 'MARQUE',
            'modele' => 'MODELE',
            'code_client' => 'CODE CLIENT',
            'client' => 'CLIENTS',
            'montant' => 'MONTANT PROFORMA',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['numero', 'montant'];
    }

    /**
     * Le code agent se lit dans le numéro de proforma, et non dans la fiche.
     *
     * C'est celui qui a rédigé le devis qui compte pour l'attribution, pas celui qui a reçu
     * le véhicule — ce sont souvent deux personnes différentes.
     */
    protected function reference(array $ligne): ?string
    {
        return self::texte($ligne['numero'] ?? null, 40);
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if (self::date($ligne['date'] ?? null) === null) {
            return 'La date de la proforma est absente ou illisible.';
        }

        $montant = self::montant($ligne['montant'] ?? null);

        if ($montant === null || $montant < 0) {
            return "Le montant de la proforma n'est pas un nombre positif exploitable.";
        }

        return null;
    }

    /** La fiche de réception fait autorité sur la ville, quand elle est déjà connue. */
    protected function siteConnuDe(array $ligne): ?int
    {
        $fiche = self::texte($ligne['fiche'] ?? null, 40);

        if ($fiche === null) {
            return null;
        }

        $this->fichesConnues ??= DossierVehicule::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNotNull('site_id')
            ->pluck('site_id', 'numero_fiche')
            ->all();

        return $this->fichesConnues[$fiche] ?? null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $numero = self::texte($ligne['numero'] ?? null, 60);

        $this->numerosConnus ??= array_fill_keys(
            Devis::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->pluck('numero')
                ->filter()
                ->all(),
            true,
        );

        $montant = (int) round((float) self::montant($ligne['montant'] ?? null));

        $valeurs = [
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'n_fiche_reception' => self::texte($ligne['fiche'] ?? null, 255),
            'date_emission' => self::date($ligne['date'] ?? null),
            'client' => self::texte($ligne['client'] ?? null, 255) ?? 'Client non précisé',
            'montant_devis' => $montant,
            'activite' => $this->activite($ligne),
            'observations' => $this->observations($ligne),
        ];

        $existant = isset($this->numerosConnus[$numero])
            ? Devis::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('numero', $numero)
                ->first()
            : null;

        if ($existant === null) {
            Devis::withoutGlobalScopes()->create($valeurs + [
                'entreprise_id' => $this->entrepriseId,
                'numero' => mb_substr($numero, 0, 20),
                // Le fichier ne dit pas si le devis a été validé ou refusé : il liste les
                // proformas émises, un point c'est tout. « En attente » est donc la seule
                // valeur honnête à l'entrée — l'inventer validé gonflerait le taux de
                // transformation d'un chiffre que personne n'a mesuré.
                'statut' => 'En attente',
                'date_reception' => self::date($ligne['date'] ?? null),
            ]);

            $this->numerosConnus[$numero] = true;

            return 'cree';
        }

        // Deux garde-fous au réimport, et ils comptent parce que les trois fichiers se
        // recouvrent : ni le site établi ni le statut décidé à la main ne se laissent
        // écraser par un fichier redéposé au titre d'une autre ville.
        if ($rattachement['presumee'] && $existant->site_id !== null) {
            unset($valeurs['site_id']);
        }

        $existant->fill($valeurs);

        if (! $existant->isDirty()) {
            return 'ignore';
        }

        $existant->save();

        return 'maj';
    }

    private function activite(array $ligne): string
    {
        $fiche = self::texte($ligne['fiche'] ?? null, 40);

        if ($fiche === null) {
            return 'Mécanique';
        }

        // Chargé d'un bloc, une seule fois. La version qui interrogeait la base à chaque
        // ligne mettait dix-huit secondes là où il en faut deux : sur 2 400 factures, cela
        // faisait 2 400 requêtes pour lire une colonne de trois valeurs possibles.
        $this->motifsDesFiches ??= DossierVehicule::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNotNull('motif')
            ->pluck('motif', 'numero_fiche')
            ->all();

        return ($this->motifsDesFiches[$fiche] ?? null) === 'SINISTRE' ? 'Sinistre' : 'Mécanique';
    }

    private function observations(array $ligne): ?string
    {
        $morceaux = array_filter([
            ($i = self::texte($ligne['immatriculation'] ?? null, 40)) ? "Immat. : {$i}" : null,
            ($v = trim((string) self::texte($ligne['marque'] ?? null, 60).' '.self::texte($ligne['modele'] ?? null, 60)))
                ? "Véhicule : {$v}" : null,
            ($c = self::texte($ligne['chassis'] ?? null, 60)) ? "Châssis : {$c}" : null,
            ($k = self::texte($ligne['code_client'] ?? null, 40)) ? "Code client : {$k}" : null,
        ]);

        return $morceaux === [] ? null : implode(' · ', $morceaux);
    }
}
