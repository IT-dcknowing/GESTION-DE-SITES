<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\DossierVehicule;

/**
 * « CATTC » — le chiffre d'affaires facturé.
 *
 * Douze colonnes, et une différence capitale avec la situation du parc : **ce fichier porte
 * une vraie colonne SITE**. Le rattachement ne dépend donc plus du code employé, il est
 * écrit dans la donnée. C'est le seul des grands formats dans ce cas avec l'état des
 * impayés.
 *
 * Trois mesures faites sur l'export réel, qui expliquent les choix :
 *
 * - **7 lignes sur 1 920 contredisent le parc** sur la ville. Ce n'est pas rien et ce n'est
 *   pas beaucoup : 99,6 % d'accord. En cas de désaccord, c'est la fiche déjà en base qui
 *   gagne — une affaire ne change pas de ville entre deux fichiers, et la fiche de réception
 *   est établie avant la facture.
 * - **Aucun numéro de facture n'est commun avec l'état des impayés.** Les deux fichiers
 *   parlent des mêmes affaires sans partager leur clé. Le pont entre eux, c'est la fiche de
 *   réception, présente des deux côtés — d'où l'ordre d'import : le parc d'abord.
 * - **Les dates sortent en clair**, contrairement aux devis où elles arrivent en numéros de
 *   série. La conversion gère les deux sans qu'on ait à choisir.
 *
 * L'activité — Mécanique ou Sinistre — n'est pas dans ce fichier. Elle se déduit du motif de
 * venue de la fiche de réception, et à défaut reste sur « Mécanique », qui est le cas majeur.
 * On ne l'invente pas ligne par ligne : on la lit là où elle est écrite.
 */
class FormatDesFactures extends Format
{
    private ?array $fichesConnues = null;

    /** @var array<string, string>|null numéro de fiche => motif de venue */
    private ?array $motifsDesFiches = null;

    private ?array $numerosConnus = null;

    public static function cle(): string
    {
        return 'factures';
    }

    public static function libelle(): string
    {
        return "Chiffre d'affaires TTC — factures";
    }

    public static function colonnes(): array
    {
        return [
            'date' => 'DATE DE LA FACTURE',
            'numero' => 'N° FACTURE',
            'sticker' => 'N° STICKER',
            'fiche' => 'FICHE DE RECEPTION',
            'sinistre' => 'N° SINISTRE',
            'immatriculation' => 'IMMAT. VEHICULE',
            'marque' => 'MARQUE',
            'modele' => 'MODELE',
            'code_client' => 'CODE CLIENT',
            'client' => 'CLIENTS',
            'montant' => 'MONTANT FACTURE',
            'site' => 'SITE',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['numero', 'montant'];
    }

    protected function reference(array $ligne): ?string
    {
        // Le code agent se lit dans le numéro de fiche, pas dans le numéro de facture :
        // « FA -5713 » ne porte aucune initiale.
        return self::texte($ligne['fiche'] ?? null, 40);
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if (self::date($ligne['date'] ?? null) === null) {
            return "La date de la facture est absente ou illisible.";
        }

        $montant = self::montant($ligne['montant'] ?? null);

        if ($montant === null) {
            return "Le montant n'est pas un nombre exploitable.";
        }

        // Un montant négatif est un avoir, pas une facture. On le signale plutôt que de le
        // compter dans un chiffre d'affaires qu'il ferait baisser sans qu'on sache pourquoi.
        if ($montant < 0) {
            return 'Montant négatif : cette ligne ressemble à un avoir, à traiter à part.';
        }

        return null;
    }

    /** La fiche de réception, si elle a déjà été importée : c'est elle qui fait autorité. */
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
            Facture::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->pluck('n_facture')
                ->filter()
                ->all(),
            true,
        );

        $valeurs = [
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'date' => self::date($ligne['date'] ?? null),
            'reference_devis' => self::texte($ligne['fiche'] ?? null, 60),
            'client' => self::texte($ligne['client'] ?? null, 255) ?? 'Client non précisé',
            'vehicule' => self::texte(trim(
                (string) self::texte($ligne['marque'] ?? null, 60).' '.self::texte($ligne['modele'] ?? null, 60)
            ), 120),
            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 30),
            'montant' => (int) round((float) self::montant($ligne['montant'] ?? null)),
            'observations' => $this->observations($ligne),
            'activite' => $this->activite($ligne),
            'type' => 'FNE',
        ];

        $existante = isset($this->numerosConnus[$numero])
            ? Facture::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('n_facture', $numero)
                ->first()
            : null;

        if ($existante === null) {
            Facture::withoutGlobalScopes()->create($valeurs + [
                'entreprise_id' => $this->entrepriseId,
                'n_facture' => $numero,
                // Le numéro interne suit la même valeur : la facture n'est pas née ici, elle
                // est née dans le logiciel d'atelier, et c'est son numéro qui fait foi.
                'numero' => mb_substr($numero, 0, 20),
            ]);

            $this->numerosConnus[$numero] = true;

            return 'cree';
        }

        // Un site déjà établi par la donnée ne se laisse pas écraser par une présomption.
        if ($rattachement['presumee'] && $existante->site_id !== null) {
            unset($valeurs['site_id']);
        }

        $existante->fill($valeurs);

        if (! $existante->isDirty()) {
            return 'ignore';
        }

        $existante->save();

        return 'maj';
    }

    /**
     * Mécanique ou Sinistre, lu sur la fiche de réception plutôt que deviné.
     *
     * Le fichier des factures ne porte pas l'activité. La fiche, si — par son motif de
     * venue. Quand la fiche est inconnue, on retient « Mécanique », qui est le cas majeur
     * mesuré (1 620 fiches sur 3 179) ; c'est un choix par défaut assumé, pas une déduction.
     */
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

    /** Ce que le fichier porte en plus et qu'aucune colonne n'accueille. */
    private function observations(array $ligne): ?string
    {
        $morceaux = array_filter([
            ($s = self::texte($ligne['sinistre'] ?? null, 60)) ? "Sinistre : {$s}" : null,
            ($t = self::texte($ligne['sticker'] ?? null, 60)) ? "Sticker : {$t}" : null,
            ($c = self::texte($ligne['code_client'] ?? null, 40)) ? "Code client : {$c}" : null,
        ]);

        return $morceaux === [] ? null : implode(' · ', $morceaux);
    }
}
