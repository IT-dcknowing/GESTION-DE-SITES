<?php

namespace Modules\Noyau\Commun\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Une ligne peut avoir été saisie sur la plateforme, ou reprise du logiciel d'atelier.
 *
 * La colonne `lot_import_id` porte cette distinction : elle est nulle pour une saisie, et
 * renseignée pour une reprise. Ce trait n'ajoute rien à la table, il donne juste un nom à
 * la question — `saisieManuelle()` — pour ne plus l'écrire à la main dans chaque écran.
 *
 * **Pourquoi la distinction n'est pas cosmétique.** Une ligne importée n'a pas les mêmes
 * garanties qu'une ligne saisie :
 *
 *   - elle n'a pas de commercial, parce que le logiciel d'atelier ne connaît pas cette
 *     notion — il connaît un code de deux lettres ;
 *   - elle peut n'avoir pas d'atelier, quand le code n'est pas encore rattaché ;
 *   - son statut vaut ce que le fichier en dit, c'est-à-dire souvent rien : un devis
 *     importé est « En attente » faute de mieux, pas parce qu'une décision se prépare ;
 *   - et surtout, **son historique de règlement n'est pas repris**. Une facture importée
 *     paraît donc impayée quand bien même elle a été réglée il y a six mois.
 *
 * Les écrans de travail quotidien — la saisie du jour, l'encaissement — s'en tiennent donc
 * aux lignes saisies. Les écrans de pilotage, eux, comptent tout : c'est bien l'intérêt
 * d'avoir importé. Un chiffre d'affaires doit être complet ; une liste de factures à
 * encaisser doit être juste, et ce n'est pas la même exigence.
 */
trait PeutVenirDUnImport
{
    /** Les lignes nées sur la plateforme, avec tout ce que la saisie garantit. */
    public function scopeSaisieManuelle(Builder $query): Builder
    {
        return $query->whereNull('lot_import_id');
    }

    /** Les lignes reprises d'un fichier. */
    public function scopeImportee(Builder $query): Builder
    {
        return $query->whereNotNull('lot_import_id');
    }

    public function estImportee(): bool
    {
        return $this->lot_import_id !== null;
    }

    /**
     * Les lignes sur lesquelles on peut calculer un reste à payer.
     *
     * C'est-à-dire : celles saisies ici, **plus** celles reprises d'un fichier qui apportait
     * aussi les règlements. Aujourd'hui un seul le fait, l'état des impayés — et c'est tout
     * son intérêt : il porte « Montant réglé », que le module transforme en encaissements.
     *
     * La distinction ne porte donc pas sur l'origine mais sur ce que l'origine garantit. Une
     * facture reprise du chiffre d'affaires arrive sans un seul règlement en face ; la
     * compter comme créance la déclarerait intégralement due, ce qu'elle n'est pas. Une
     * facture reprise des impayés arrive avec ce qui a été encaissé dessus : son reste est
     * calculable, donc elle a sa place dans une balance âgée.
     *
     * Le jour où un autre fichier apportera des règlements, il s'ajoutera à cette liste —
     * délibérément, et pas par le simple fait d'exister.
     */
    public function scopeAvecHistoriqueDeReglement(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereNull('lot_import_id')
            ->orWhereIn('lot_import_id', function ($sous) {
                $sous->select('id')->from('lots_import')->whereIn('format', self::FORMATS_AVEC_REGLEMENTS);
            }));
    }

    /** Les reprises qui apportent aussi les règlements, et pas seulement les factures. */
    public const FORMATS_AVEC_REGLEMENTS = ['impayes'];
}
