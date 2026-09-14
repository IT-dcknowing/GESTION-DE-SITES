<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Le code de deux lettres d'une personne : qui le lui donne, et qui le confirme.
 *
 * **Ce qui manquait.** L'annuaire des codes existait et le gérant pouvait l'arbitrer, mais
 * le lien entre un code et un **compte** n'était posé nulle part en pratique — trente-huit
 * codes en base, aucun rattaché à quelqu'un. Et surtout : personne n'avait jamais demandé
 * à l'intéressé si le code qu'on lui prêtait était bien le sien.
 *
 * Or c'est la seule vérification qui vaille. Un code mal attribué ne se voit pas : les
 * fiches partent dans le mauvais atelier, le chiffre du Site 1 gonfle de celui du Site 2,
 * et aucun contrôle ne s'en aperçoit. La personne, elle, le sait du premier coup d'œil —
 * elle lit son code sur chacune de ses fiches, « FR-**KZ**N° 010669 ».
 *
 * **Trois gestes, trois responsables.**
 *
 * - le **super administrateur** attribue, corrige, déplace — y compris d'une personne à
 *   une autre, ce que personne d'autre ne peut faire ;
 * - **chacun** peut corriger le sien, parce que c'est lui qui le voit tous les jours ;
 * - **chacun confirme** le sien une fois, et la question ne revient qu'au prochain
 *   changement.
 *
 * **Tout est écrit au journal.** Attribution, correction, confirmation : trois lignes
 * d'activité nominatives. Un code décide de quel atelier reçoit quel chiffre d'affaires ;
 * il se relit comme une écriture, pas comme une préférence.
 */
class CodeDeLAtelier
{
    /** Exactement deux lettres : c'est la forme que le logiciel inscrit dans ses numéros. */
    public const FORMAT = '/^[A-Za-z]{2}$/';

    /** Le code rattaché à cette personne, ou null si personne ne lui en a donné. */
    public static function de(?User $personne): ?CodeAgent
    {
        if (! $personne || ! $personne->entreprise_id) {
            return null;
        }

        return CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $personne->entreprise_id)
            ->where('user_id', $personne->id)
            ->first();
    }

    /**
     * Faut-il poser la question à cette personne ?
     *
     * Seulement à celles dont le code a été saisi : demander « est-ce bien votre code ? »
     * à quelqu'un qui n'en a pas serait une question sans réponse possible.
     */
    public static function aConfirmer(?User $personne): bool
    {
        return $personne !== null
            && $personne->code_atelier_confirme_le === null
            && self::de($personne) !== null;
    }

    /**
     * La personne dit que ce code est bien le sien.
     *
     * On écrit la date plutôt qu'un simple oui : savoir *quand* la confirmation a été
     * donnée est ce qui permet, plus tard, de dire si elle portait sur le code d'avant ou
     * sur celui d'après.
     */
    public static function confirmer(User $personne): void
    {
        $code = self::de($personne);

        if ($code === null) {
            return;
        }

        User::withoutGlobalScopes()->whereKey($personne->id)
            ->update(['code_atelier_confirme_le' => now()]);

        $personne->code_atelier_confirme_le = now();

        activity()
            ->causedBy($personne)
            ->performedOn($code)
            ->withProperties(['code' => $code->code])
            ->log('Code atelier confirmé par son porteur');
    }

    /**
     * Donne ce code à cette personne, ou le lui retire quand il est vide.
     *
     * @param  bool  $deplacerSiPris  vrai pour le super administrateur seul : lui peut
     *                                reprendre un code à quelqu'un pour le donner à un
     *                                autre, parce que c'est précisément son rôle d'arbitrer.
     *                                Refusé à tous les autres, sans quoi n'importe qui
     *                                s'attribuerait les fiches d'un collègue.
     * @return array{code: ?string, ancien: ?string, repris_a: ?string}
     */
    public static function attribuer(
        User $personne,
        ?string $code,
        User $auteur,
        bool $deplacerSiPris = false,
    ): array {
        $entrepriseId = (int) $personne->entreprise_id;

        if ($entrepriseId === 0) {
            throw new InvalidArgumentException(
                "Un code d'atelier appartient à une entreprise : ce compte n'en a aucune."
            );
        }

        $code = mb_strtoupper(trim((string) $code));
        $ancien = self::de($personne)?->code;

        if ($code !== '' && ! preg_match(self::FORMAT, $code)) {
            throw new InvalidArgumentException(
                "Un code d'atelier fait exactement deux lettres : c'est sous cette forme que le "
                ."logiciel l'inscrit dans les numéros de fiche, « FR-KZN° 010669 »."
            );
        }

        if ($code === $ancien) {
            return ['code' => $ancien, 'ancien' => $ancien, 'repris_a' => null];
        }

        $reprisA = null;

        DB::transaction(function () use (
            $personne, $code, $entrepriseId, $deplacerSiPris, &$reprisA
        ) {
            $affectation = new AffectationDesCodes($entrepriseId);

            if ($code === '') {
                $affectation->detacherDe($personne);

                return;
            }

            $occupant = CodeAgent::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)
                ->where('code', $code)
                ->first();

            $prisParUnAutre = $occupant
                && $occupant->user_id !== null
                && (int) $occupant->user_id !== (int) $personne->id;

            if ($prisParUnAutre && ! $deplacerSiPris) {
                throw new InvalidArgumentException(
                    'Le code « '.$code.' » est déjà celui de '
                    .(User::withoutGlobalScopes()->find($occupant->user_id)?->name ?: 'un autre employé')
                    .". Si c'est une erreur, l'administrateur de la plateforme peut le déplacer."
                );
            }

            if ($prisParUnAutre) {
                $porteur = User::withoutGlobalScopes()->find($occupant->user_id);
                $reprisA = $porteur?->name;

                // Le précédent porteur perd sa confirmation avec son code : elle portait
                // sur un code qui n'est plus le sien. Et il faut le détacher avant de
                // poser le code ailleurs — l'affectation refuse, à juste titre, d'écrire
                // sur un code que quelqu'un porte encore.
                if ($porteur) {
                    $affectation->detacherDe($porteur);

                    User::withoutGlobalScopes()->whereKey($porteur->id)
                        ->update(['code_atelier_confirme_le' => null]);
                }
            }

            // Une personne ne porte qu'un code : l'ancien est détaché avant que le
            // nouveau ne soit posé, sinon elle en aurait deux et les fiches se
            // partageraient entre les deux ateliers.
            $affectation->detacherDe($personne);
            $affectation->attribuerA($personne, $code);
        });

        // La confirmation porte sur un code précis. Le code change, elle tombe.
        User::withoutGlobalScopes()->whereKey($personne->id)
            ->update(['code_atelier_confirme_le' => null]);

        $personne->code_atelier_confirme_le = null;

        activity()
            ->causedBy($auteur)
            ->performedOn($personne)
            ->withProperties(array_filter([
                'code' => $code ?: null,
                'ancien' => $ancien,
                'repris_a' => $reprisA,
            ]))
            ->log($code === ''
                ? "Code atelier retiré"
                : ($auteur->is($personne) ? 'Code atelier corrigé par son porteur' : 'Code atelier attribué'));

        return ['code' => $code ?: null, 'ancien' => $ancien, 'repris_a' => $reprisA];
    }
}
