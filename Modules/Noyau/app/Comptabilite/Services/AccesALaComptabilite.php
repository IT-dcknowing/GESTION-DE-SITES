<?php

namespace Modules\Noyau\Comptabilite\Services;

use App\Models\User;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;

/**
 * Qui entre dans les écritures comptables, et pour quel geste — 25/11.
 *
 * Le module se donne par habilitation (`HabilitationsDActions`, domaine `comptabilite`), plus par
 * rôle : la route ne demande que d'être connecté, et chaque écran, chaque action Volt, chaque
 * ouverture de justificatif repasse ici. Tout geste suppose `voir` — retirer `voir` à un compte
 * lui ferme le module entier, quels que soient ses autres gestes — et une entreprise : le Super
 * admin n'en a pas, et rien ici ne se lit hors de l'entreprise du compte connecté.
 */
final class AccesALaComptabilite
{
    public const ACTIONS = ['voir', 'generer', 'lire_tva', 'deverser', 'imputer_ia'];

    public static function peut(?User $compte, string $action = 'voir'): bool
    {
        return $compte !== null
            && (int) $compte->entreprise_id > 0
            && in_array($action, self::ACTIONS, true)
            && HabilitationsDActions::accorde($compte, 'comptabilite', 'voir')
            && ($action === 'voir' || HabilitationsDActions::accorde($compte, 'comptabilite', $action));
    }

    /** Refuse en 403 ce que l'habilitation n'ouvre pas — appelé par chaque action, pas seulement au montage. */
    public static function exiger(string $action = 'voir'): void
    {
        abort_unless(self::peut(auth()->user(), $action), 403, 'Ce geste comptable ne vous est pas ouvert.');
    }
}
