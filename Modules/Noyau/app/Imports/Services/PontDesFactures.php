<?php

namespace Modules\Noyau\Imports\Services;

use DateTimeInterface;
use Modules\Noyau\Exploitation\Modeles\Facture;

/**
 * Le chiffre d'affaires et l'état des impayés se reconnaissent, au lieu de se doubler.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **La question du propriétaire, le 07/10** : « lorsque j'ai importé l'état des impayés,
 * automatiquement la page chiffre d'affaires s'est remplie ; il ne faudrait pas que j'aie des
 * doublons avec les factures que je vais importer ».
 *
 * Les deux fichiers décrivent les mêmes factures **sans aucun numéro commun** — « FA -5713 »
 * au CATTC, « 17 » à l'état, mesuré le 15/09 —, et chaque import ne reconnaissait une facture
 * que par son propre numéro. Le second dépôt aurait donc réécrit chaque facture une deuxième
 * fois, et le chiffre d'affaires l'aurait comptée deux fois.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi pas le pont de l'écran de rapprochement.** `EtatDesImpayes::clePont()` relie
 * par plaque + montant, et son commentaire dit lui-même pourquoi il ne suffit pas à
 * apparier : un client de flotte ramène le même véhicule au même tarif. Apparier sur lui
 * « se tromperait sans le dire ».
 *
 * **La clé d'ici est plus forte : même date, même montant, même plaque.** Et elle n'apparie
 * que lorsqu'**un seul** candidat y répond. Deux candidats, aucun, une plaque absente : on
 * ne choisit pas, l'import crée la ligne comme avant. Entre un doublon qu'un écran de
 * rapprochement montrera et une fusion fausse que rien ne montrera, c'est le doublon qu'on
 * préfère — la fusion ne se défait pas à l'œil.
 *
 * **Ce qui reste à mesurer** — sur les fichiers réels, qu'on n'a pas ici : la part de
 * factures que cette clé retrouve. La date d'édition de l'état est-elle partout celle du
 * CATTC ? L'état la remplace par la date de réception sur 341 lignes ; celles-là ne se
 * retrouveront pas, et resteront en double, visibles. `php artisan factures:doublons` le
 * compte sans rien écrire.
 *
 * Un candidat ne sert qu'une fois par dépôt : deux lignes identiques du même fichier ne se
 * disputent pas la même créance.
 */
class PontDesFactures
{
    /** @var array<string, array<int, int>>|null clé => identifiants des créances libres */
    private ?array $creances = null;

    /** @var array<string, array<int, int>>|null clé => identifiants des factures du CATTC libres */
    private ?array $cattc = null;

    public function __construct(private readonly int $entrepriseId)
    {
    }

    /** La clé forte : date du jour, montant entier, plaque sans espaces ni tirets. */
    public static function cle(mixed $date, mixed $montant, ?string $plaque): ?string
    {
        $plaque = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $plaque));

        if ($plaque === '' || $date === null || $montant === null) {
            return null;
        }

        $jour = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);

        return $jour.'|'.(int) round((float) $montant).'|'.$plaque;
    }

    /**
     * La créance de l'état des impayés que cette ligne du CATTC décrit — si elle est seule.
     *
     * Ne sont candidates que les créances qu'aucun CATTC n'a encore reconnues.
     */
    public function creanceUnique(?string $cle): ?int
    {
        $this->creances ??= $this->indexer(
            Facture::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('est_etat_initial', true)
                ->whereNull('n_facture_cattc'),
        );

        return $this->prendre($this->creances, $cle);
    }

    /**
     * La facture du CATTC que cette ligne de l'état décrit — si elle est seule.
     *
     * Ne sont candidates que les factures qui ne sont pas déjà une créance de l'état.
     */
    public function factureUnique(?string $cle): ?int
    {
        $this->cattc ??= $this->indexer(
            Facture::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where(fn ($q) => $q->where('est_etat_initial', false)->orWhereNull('est_etat_initial')),
        );

        return $this->prendre($this->cattc, $cle);
    }

    /** @return array<string, array<int, int>> */
    private function indexer($requete): array
    {
        $index = [];

        foreach ($requete->select('id', 'date', 'montant', 'immatriculation')->cursor() as $facture) {
            $cle = self::cle($facture->date, $facture->montant, $facture->immatriculation);

            if ($cle !== null) {
                $index[$cle][] = (int) $facture->id;
            }
        }

        return $index;
    }

    /** @param  array<string, array<int, int>>  $index */
    private function prendre(array &$index, ?string $cle): ?int
    {
        if ($cle === null || count($index[$cle] ?? []) !== 1) {
            return null;
        }

        $id = $index[$cle][0];
        unset($index[$cle]);

        return $id;
    }
}
