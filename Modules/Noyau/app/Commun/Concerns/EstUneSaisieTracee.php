<?php

namespace Modules\Noyau\Commun\Concerns;

use Modules\Noyau\Commun\Services\CodeAuteur;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;

/**
 * Toute ligne saisie porte deux marques, posées d'elles-mêmes.
 *
 *   - son numéro de document, unique et continu dans l'entreprise ;
 *   - le code de celui qui l'a saisie, avec son rang à lui.
 *
 * Le travail se fait dans un écouteur du modèle, et non dans chaque écran : cinq
 * interfaces créent ces lignes, et une seule oubliée aurait suffi à trouer la
 * numérotation. Ici, aucun appel à ne pas oublier — la ligne ne peut pas naître sans.
 *
 * Le modèle déclare son type de compteur :
 *
 *      protected static string $typeDeSaisie = 'enc';
 */
trait EstUneSaisieTracee
{
    public static function bootEstUneSaisieTracee(): void
    {
        static::creating(function ($ligne) {
            $entrepriseId = $ligne->entreprise_id ?? auth()->user()?->entreprise_id;

            // Le « ?? » protège les écrans qui posent déjà leur numéro eux-mêmes : on
            // ne le remplace jamais, sous peine de consommer deux numéros pour une ligne.
            if ($entrepriseId && static::$typeDeSaisie && ! $ligne->numero) {
                /*
                 * La date de l'opération entre dans le numéro, et c'est bien la sienne —
                 * pas celle de la frappe. Une facture du 12 enregistrée le 14 porte 1209 :
                 * la référence date ce qui s'est passé, pas le moment où on l'a tapé.
                 *
                 * Les devis portent leur date sous un autre nom ; les deux sont essayés
                 * plutôt que d'imposer une colonne à des modèles qui n'ont pas les mêmes.
                 */
                $ligne->numero = GenerateurNumero::suivant(
                    $entrepriseId,
                    static::$typeDeSaisie,
                    $ligne->date ?? $ligne->date_emission ?? null,
                );
            }

            // Le code auteur ne dépend plus du type de saisie : il désigne la personne,
            // pas le rang de la ligne dans son travail. Le rang de la ligne, lui, est déjà
            // au-dessus — c'est le numéro de la pièce.
            if (! $ligne->code_auteur) {
                $ligne->code_auteur = CodeAuteur::pour(auth()->user());
            }
        });
    }
}
