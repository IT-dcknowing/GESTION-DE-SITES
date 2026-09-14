<?php

namespace Modules\Noyau\Tracabilite\Services;

use Illuminate\Support\Str;

/**
 * Le nom que porte un écran pour celui qui le lit.
 *
 * « super-admin.acces.index » ne dit rien à personne dans un rapport d'activité. Le
 * tableau ci-dessous nomme les écrans connus ; ce qui n'y figure pas est rendu lisible
 * plutôt qu'écarté — un écran ajouté demain doit apparaître dans le journal sans qu'on
 * ait pensé à revenir ici, faute de quoi une partie du parcours devient invisible, et un
 * journal troué est pire qu'un journal absent.
 */
class NomDEcran
{
    /** @var array<string, string> nom de route → intitulé */
    private const CONNUS = [
        'redirection' => 'Aiguillage',
        'tableau-de-bord' => 'Tableau de bord (direction)',
        'parametres' => "Paramètres de l'entreprise",
        'saisie-du-jour' => 'Saisie du jour',
        'prospects' => 'Prospects',
        'devis' => 'Devis',
        'chiffre-affaires' => "Chiffre d'affaires",
        'charges' => 'Charges',
        'tresorerie' => 'Trésorerie',
        'commerciaux' => 'Commerciaux',
        'acces.creer' => 'Accès — création',
        'messages' => 'Messagerie',
        'mes-notifications' => 'Notifications',
        'mon-espace' => 'Mon espace',
        'mot-de-passe.modifier' => 'Mot de passe',
        'ma-performance' => 'Ma performance',
        'mes-prospections' => 'Mes prospections',
        'mes-notes' => 'Mes notes',
        'caissier.tableau-de-bord' => 'Comptabilité — tableau de bord',
        'caissier.encaissements' => 'Comptabilité — encaissements',
        'caissier.decaissements' => 'Comptabilité — décaissements',
        'super-admin.dashboard' => 'Plateforme — tableau de bord',
        'super-admin.entreprises.index' => 'Plateforme — entreprises',
        'super-admin.entreprises.show' => 'Plateforme — fiche entreprise',
        'super-admin.acces.index' => 'Plateforme — accès',
        'super-admin.acces.creer' => 'Plateforme — création d\'accès',
        'super-admin.acces.modifier' => 'Plateforme — reprise d\'accès',
        'super-admin.administrateurs' => 'Plateforme — administrateurs',
        'super-admin.journal.index' => "Plateforme — journal d'activité",
        'super-admin.tracabilite' => 'Plateforme — traçabilité',
        'super-admin.maintenance' => 'Plateforme — maintenance',
        'recouvrement.saisie' => 'Recouvrement — saisie',
        'recouvrement.synthese' => 'Recouvrement — synthèse & pilotage',
        'recouvrement.balance' => 'Recouvrement — balance âgée',
        'recouvrement.courtiers' => 'Recouvrement — courtiers',
        'recouvrement.clients' => 'Recouvrement — clients & tiers',
        'recouvrement.extrait' => 'Recouvrement — extrait de compte',
        'recouvrement.relances' => 'Recouvrement — journal des relances',
        'recouvrement.encaissements' => 'Recouvrement — journal des encaissements',
        'recouvrement.audit' => "Recouvrement — piste d'audit",
        'super-admin.codes' => "Codes d'atelier",
        'recouvrement.tableau-de-bord' => 'Recouvrement — tableau de bord',
        'recouvrement.dossier' => 'Recouvrement — dossier du tiers',
        'import.depot' => 'Import — dépôt de fichier',
        'import.traitements' => 'Import — traitements',
        'import.lots' => 'Import — journal des imports',
        'import.lot' => 'Import — détail du dépôt',
        'import.lot.rejets' => 'Import — lignes refusées',
        'import.lot.tracabilite' => 'Import — traçabilité du dépôt',
        'import.codes' => 'Import — employés & codes',
        'import.codes.fiche' => "Import — fiche d'un code",
        'import.informations' => 'Import — informations',
        'clients' => "Clients de l'entreprise",
        'parc-vehicules' => 'Parc de véhicules',
        'parc-fiche' => 'Parc — fiche du véhicule',
        'mouvements-vehicules' => 'Entrées & sorties de véhicules',
        'annuaire' => "Annuaire de l'entreprise",
        'super-admin.annuaire' => 'Plateforme — annuaire',
        'mon-profil' => 'Mon profil',
        'prospection.voir' => 'Prospection — fiche',
        'prospection.fiche' => 'Prospection — fiche',
        'acces.modifier' => 'Accès — modification',
        'caisse' => 'Caisse — mouvements',
        'fournisseurs' => 'Fournisseurs — factures reçues',
    ];

    public static function pour(?string $route, string $chemin): string
    {
        if ($route !== null && isset(self::CONNUS[$route])) {
            return self::CONNUS[$route];
        }

        $brut = $route ?: trim($chemin, '/');

        if ($brut === '') {
            return 'Accueil';
        }

        // « super-admin.entreprises.show » → « Super admin · entreprises · show ».
        // Imparfait, mais lisible, et daté du jour où l'écran a été ajouté sans être nommé.
        return Str::limit(
            collect(preg_split('/[.\/]/', $brut))
                ->filter()
                ->map(fn (string $morceau) => Str::ucfirst(str_replace('-', ' ', $morceau)))
                ->implode(' · '),
            116,
        );
    }
}
