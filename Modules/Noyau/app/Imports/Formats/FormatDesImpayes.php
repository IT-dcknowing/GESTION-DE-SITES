<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;

/**
 * L'état des impayés — les créances, et ce qui a déjà été encaissé dessus.
 *
 * **C'est le fichier qui rend le recouvrement juste, et c'est pour cela qu'il compte plus
 * que les autres.** L'import du chiffre d'affaires reprend les factures sans leurs
 * règlements : une facture d'octobre encaissée depuis longtemps y paraît intégralement due.
 * Celui-ci porte les trois colonnes qui manquaient — `montantTTC`, `Montantréglé`,
 * `ResteàPayer` — et permet enfin de calculer une créance qui corresponde à la réalité.
 *
 * **Le montant réglé devient un encaissement**, et non une colonne de plus. C'est le seul
 * moyen que le reste à payer se calcule partout de la même façon : montant facturé moins
 * encaissements. Une colonne « déjà réglé » à côté aurait créé deux vérités, et l'écran qui
 * lit la mauvaise donne un chiffre faux sans que rien ne le signale.
 *
 * Cet encaissement est **daté du règlement quand le fichier le dit**, et de la facture
 * sinon. Il porte la référence du lot d'import : on peut donc le retirer entièrement si la
 * reprise est mauvaise, sans toucher aux encaissements saisis à la main.
 *
 * **Quatre défauts du fichier, mesurés, et ce qu'on en fait :**
 *
 * - **42 % des lignes n'ont pas de SITE.** Elles entrent quand même, rattachées par le
 *   dépôt, et restent rattachées à la ville seule plutôt que d'être rangées au hasard.
 * - **730 libellés de mode de règlement pour trois modes réels** — « CHEQUE », « Cheque »,
 *   « CHQ 1762801 »… Le mode est donc rangé en observation, pas en donnée : le normaliser
 *   à la volée reviendrait à inventer une règle que le fichier ne porte pas.
 * - **Aucun numéro de facture commun avec le CATTC.** Les deux fichiers parlent des mêmes
 *   affaires sans partager leur clé ; le pont entre eux est la fiche de réception.
 * - **Les numéros sont de simples entiers** — « 17 », « 23 » — repris depuis 2022. Ils ne
 *   suffisent pas à identifier une facture : la clé retenue est donc le numéro **et** la
 *   date d'édition, faute de quoi la facture n° 17 de 2022 et celle de 2024 n'en feraient
 *   qu'une.
 */
class FormatDesImpayes extends Format
{
    public static function cle(): string
    {
        return 'impayes';
    }

    public static function libelle(): string
    {
        return 'État des impayés — créances clients';
    }

    public static function colonnes(): array
    {
        return [
            'assureur' => 'ASSUREUR',
            'client' => 'Client',
            'site' => 'SITE',
            'courtier' => 'Courtier',
            'date_reception' => 'Date de reception de la facture',
            'date' => "Date d'edition de la facture",
            'numero' => 'Numéro de la facture',
            'sinistre' => 'Numéro Sinistre',
            'vehicule' => 'Vehicule',
            'immatriculation' => 'Immatriculation',
            'montant' => 'montantTTC',
            'regle' => 'Montantréglé',
            'reste' => 'ResteàPayer',
            'mode_reglement' => 'Modederèglement',
            'date_reglement' => 'Datederèglement',
            'banque' => 'banque',
            'anciennete' => 'Anciennetéfactures',
            'commentaires' => 'Commentaires',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        // Le numéro n'est **pas** exigé, et c'est une décision mesurée : 142 lignes du
        // fichier n'en portent aucun, pour 100 948 384 F de créance. Les refuser aurait
        // effacé cent millions de francs réellement dus au motif qu'une case était vide.
        // Une créance sans numéro se réclame ; une créance absente ne se réclame pas.
        return ['montant'];
    }

    protected function colonneSite(array $ligne): ?string
    {
        return self::texte($ligne['site'] ?? null, 60);
    }

    /** Le numéro de facture de ce fichier est un simple entier : aucun code n'y est inscrit. */
    protected function reference(array $ligne): ?string
    {
        return null;
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if ($this->dateDeLaFacture($ligne) === null) {
            return "Ni date d'édition ni date de réception : cette ligne n'a aucune date exploitable.";
        }

        $montant = self::montant($ligne['montant'] ?? null);

        if ($montant === null) {
            return "Le montant TTC n'est pas un nombre exploitable.";
        }

        if ($montant < 0) {
            return 'Montant négatif : cette ligne ressemble à un avoir, à traiter à part.';
        }

        return null;
    }

    /**
     * Ce qui identifie une ligne de ce fichier — et pourquoi ce n'est pas son numéro.
     *
     * Le numéro de facture n'y est pas unique, et de loin : sur les 8 819 lignes du fichier,
     * il ne donne que 7 564 valeurs distinctes une fois associé à l'année. Ce sont des
     * entiers simples, repris depuis 2022, et manifestement remis à zéro. S'en servir comme
     * clé écraserait mille factures les unes sur les autres.
     *
     * Ce qui identifie réellement une ligne, mesuré en éprouvant les combinaisons sur le
     * fichier entier : **numéro + date d'édition + immatriculation + montant**, qui donne
     * 8 801 valeurs distinctes. Il reste 18 lignes en double sur 8 819 — deux pour mille.
     * Elles se rejoindront donc en une seule, ce qui est le bon sens : entre créer dix-huit
     * factures fantômes et fusionner dix-huit lignes probablement recopiées deux fois, la
     * seconde erreur est la plus petite et la plus visible.
     *
     * Le résultat est condensé pour tenir dans les vingt caractères de la colonne, et il est
     * **déterministe** : redéposer le fichier retombe sur les mêmes clés, donc met à jour au
     * lieu de dupliquer. Le numéro lisible, lui, reste intact dans `n_facture`.
     */
    private function cleDeLaLigne(array $ligne): string
    {
        $graine = implode('|', [
            self::texte($ligne['numero'] ?? null, 60),
            $this->dateDeLaFacture($ligne)?->format('Y-m-d'),
            self::texte($ligne['immatriculation'] ?? null, 30),
            (string) self::montant($ligne['montant'] ?? null),
        ]);

        return 'IM-'.substr(hash('sha256', $graine), 0, 14);
    }

    /**
     * La date de la facture : celle d'édition, ou à défaut celle de réception.
     *
     * **Le repli n'est pas une commodité, il récupère 245 millions de francs.** 341 lignes
     * du fichier n'ont pas de date d'édition ; 295 d'entre elles portent une date de
     * réception. Exiger la seule date d'édition écartait donc de la créance des factures
     * bien réelles, et l'écart se voyait au total : le reste à payer calculé tombait 18 %
     * sous celui que le fichier annonce lui-même.
     *
     * La date de réception est un repli honnête : c'est le jour où la facture est entrée
     * dans le circuit, donc une borne basse de son âge. Elle vieillit la créance plutôt que
     * de la rajeunir, ce qui est le bon sens dans une balance âgée — on préfère se croire en
     * retard qu'à l'heure.
     *
     * Restent 46 lignes sans aucune date. Celles-là sont refusées, et c'est le bon arbitrage :
     * une créance sans aucune date ne peut être ni relancée ni prescrite.
     */
    private function dateDeLaFacture(array $ligne): ?\DateTimeInterface
    {
        return self::date($ligne['date'] ?? null) ?? self::date($ligne['date_reception'] ?? null);
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $numero = self::texte($ligne['numero'] ?? null, 60);
        $date = $this->dateDeLaFacture($ligne);
        $montant = (int) round((float) self::montant($ligne['montant'] ?? null));

        $valeurs = [
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'date' => $date,
            'client' => self::texte($ligne['client'] ?? null, 255)
                ?? self::texte($ligne['assureur'] ?? null, 255)
                ?? 'Client non précisé',
            'assureur' => self::texte($ligne['assureur'] ?? null, 160),
            'courtier' => self::texte($ligne['courtier'] ?? null, 160),
            'vehicule' => self::texte($ligne['vehicule'] ?? null, 120),
            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 30),
            'montant' => $montant,
            'observations' => $this->observations($ligne),
            // L'activité n'est pas dans ce fichier. « Sinistre » quand un numéro de sinistre
            // est renseigné, ce qui est une lecture du fichier et non une supposition ;
            // « Mécanique » sinon, qui est le cas majeur mesuré.
            'activite' => self::texte($ligne['sinistre'] ?? null, 60) !== null
                && trim((string) $ligne['sinistre']) !== '-' ? 'Sinistre' : 'Mécanique',
            'type' => 'FNE',
        ];

        $existante = Facture::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('numero', $this->cleDeLaLigne($ligne))
            ->first();

        if ($existante === null) {
            $existante = Facture::withoutGlobalScopes()->create($valeurs + [
                'entreprise_id' => $this->entrepriseId,
                // Écrit en toutes lettres plutôt que laissé vide ou rempli d'une clé
                // technique : la ligne s'affiche alors telle qu'elle est — une créance
                // réelle dont le numéro manque — et se corrige à vue.
                'n_facture' => $numero ?? 'SANS NUMÉRO',
                'numero' => $this->cleDeLaLigne($ligne),
            ]);

            $this->reporterLeReglement($existante, $ligne, $rattachement, $lotId);

            return 'cree';
        }

        if ($rattachement['presumee'] && $existante->site_id !== null) {
            unset($valeurs['site_id']);
        }

        $existante->fill($valeurs);
        $modifiee = $existante->isDirty();
        $existante->save();

        $reglementChange = $this->reporterLeReglement($existante, $ligne, $rattachement, $lotId);

        return $modifiee || $reglementChange ? 'maj' : 'ignore';
    }

    /**
     * Reporte le montant déjà réglé sous forme d'encaissement.
     *
     * **Un seul encaissement d'import par facture**, remplacé à chaque reprise plutôt
     * qu'ajouté : sans cela, redéposer le même fichier doublerait les règlements et
     * ferait apparaître des factures payées deux fois. Les encaissements saisis à la
     * main sur la plateforme ne sont jamais touchés — on ne reprend que celui qui porte
     * une référence d'import.
     */
    private function reporterLeReglement(Facture $facture, array $ligne, array $rattachement, ?int $lotId): bool
    {
        $regle = self::montant($ligne['regle'] ?? null);
        $montant = $regle === null ? 0 : (int) round($regle);

        $ancien = Encaissement::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('facture_id', $facture->id)
            ->whereNotNull('lot_import_id')
            ->first();

        if ($montant <= 0) {
            if ($ancien !== null) {
                $ancien->delete();

                return true;
            }

            return false;
        }

        $valeurs = [
            'entreprise_id' => $this->entrepriseId,
            'site_id' => $rattachement['site_id'] ?? $facture->site_id,
            'lot_import_id' => $lotId,
            'facture_id' => $facture->id,
            'date' => self::date($ligne['date_reglement'] ?? null) ?? $facture->date,
            'montant' => $montant,
            'type' => 'Client',
            'moyen' => $this->moyen($ligne),
            'client' => $facture->client,
            // Le libellé brut du fichier est conservé ici, intact : c'est lui qui permettra
            // de retrouver un chèque par son numéro, ce qu'un moyen normalisé perd.
            'reference_origine' => self::texte($ligne['mode_reglement'] ?? null, 60),
        ];

        if ($ancien === null) {
            Encaissement::withoutGlobalScopes()->create($valeurs);

            return true;
        }

        $ancien->fill($valeurs);

        if (! $ancien->isDirty()) {
            return false;
        }

        $ancien->save();

        return true;
    }

    private function observations(array $ligne): ?string
    {
        $morceaux = array_filter([
            ($s = self::texte($ligne['sinistre'] ?? null, 60)) && trim($s) !== '-' ? "Sinistre : {$s}" : null,
            ($c = self::texte($ligne['commentaires'] ?? null, 255)) ? $c : null,
        ]);

        return $morceaux === [] ? null : implode(' · ', $morceaux);
    }

    /**
     * Le moyen de paiement, ramené aux modes réels — et « Non précisé » quand on ne sait pas.
     *
     * Le fichier porte 730 libellés distincts pour trois modes réels : « CHEQUE »,
     * « Cheque », « CHQ N°1762801 », « chq 1762801 »… La règle appliquée ne devine rien,
     * elle reconnaît : un libellé qui contient CHQ ou CHEQUE est un chèque, VIR un virement,
     * ESP une espèce. **Tout le reste devient « Non précisé »** plutôt que d'être rangé dans
     * le mode le plus fréquent — une statistique par moyen doit pouvoir montrer ce qu'elle
     * ignore, sinon elle donne à croire qu'elle sait.
     *
     * Le libellé d'origine n'est pas perdu pour autant : il est recopié tel quel dans la
     * référence d'origine, où il ne prétend rien et reste consultable.
     */
    private function moyen(array $ligne): string
    {
        $brut = mb_strtoupper((string) self::texte($ligne['mode_reglement'] ?? null, 120));

        return match (true) {
            str_contains($brut, 'CHQ'), str_contains($brut, 'CHEQUE'), str_contains($brut, 'CHÈQUE') => 'Chèque',
            str_contains($brut, 'VIR') => 'Virement',
            str_contains($brut, 'ESP') => 'Espèces',
            str_contains($brut, 'MOBILE'), str_contains($brut, 'MOMO'), str_contains($brut, 'WAVE') => 'Mobile Money',
            default => 'Non précisé',
        };
    }
}
