<?php

namespace Modules\Recouvrement\Support;

use App\Models\User;

/**
 * Qui voit quelles pages du recouvrement, et jusqu'à quel niveau de relance.
 *
 * Le partage n'est pas décoratif, il traduit une séparation des fonctions :
 *
 * - l'**agent** relance et encaisse. Il ne voit ni la synthèse ni la piste d'audit :
 *   la première contient les objectifs et les commentaires d'écart, qui sont un
 *   instrument de pilotage de son propre travail ; la seconde est le journal qui
 *   permet de contrôler ce qu'il a fait. On ne confie pas à quelqu'un la lecture du
 *   registre où ses propres gestes sont consignés ;
 * - le **superviseur** pilote, arbitre, et **lit la piste d'audit**. C'est le sens même
 *   de son poste : le journal est l'instrument qui lui permet de vérifier le travail de
 *   son équipe, et le lui fermer revenait à lui demander de superviser à l'aveugle. Qu'il
 *   y figure lui-même n'est pas une objection — ses propres gestes y restent inscrits, et
 *   c'est le gérant qui les relit ;
 * - le **gérant** voit tout, et lui seul engage le contentieux ;
 * - la **comptabilité** et le **superviseur de ville** consultent, sans rien y écrire.
 *
 * **Ces deux derniers ont été ajoutés, et il faut dire pourquoi ils manquaient.** Le module
 * avait été ouvert à la seule équipe de recouvrement et à la direction, au motif que ce sont
 * eux qui relancent. Le motif était court : la comptabilité encaisse ce que le recouvrement
 * poursuit, et elle décrochait le téléphone sans pouvoir lire ce qu'un client devait ; le
 * superviseur de ville répond de son chiffre d'affaires, dont l'encours est la moitié qu'on
 * ne lui montrait pas.
 *
 * Ils consultent et n'écrivent pas : ni relance, ni encaissement depuis ce module, ni
 * facture, ni tiers, ni synthèse, ni piste d'audit. La comptabilité a son propre écran
 * d'encaissement, et une seconde porte vers la même table n'aurait rien apporté qu'une
 * chance de double saisie.
 *
 * **Ce qu'on ne peut pas leur donner : une vue bornée à leur ville.** Elle n'existe pas.
 * L'encours vient de l'état des impayés du logiciel, et ce fichier ne porte pas l'atelier :
 * mille trois cent vingt et une des mille trois cent quarante et une factures ouvertes n'ont
 * aucun site. Filtrer par ville leur montrerait vingt lignes sur mille trois cent quarante et
 * une — un écran vide qui passerait pour une panne. Ils voient donc l'entreprise entière, et
 * la loupe du bandeau restreint la vue quand une ligne porte son atelier.
 *
 * Ces règles sont appliquées par le middleware sur chaque route et relues par chaque
 * écran avant d'agir. Une page cachée du menu n'est pas une page fermée : son adresse
 * s'écrit à la main.
 */
class AccesRecouvrement
{
    /**
     * Les neuf écrans de travail, dans l'ordre où ils se lisent.
     *
     * Le tableau de bord n'y figure pas, et ce n'est pas un oubli : il se lit en pleine
     * page, sans barre latérale. Le ranger à côté des neuf le faisait passer pour une
     * dixième page de travail, alors que c'est celle d'où l'on part. Son habilitation est
     * plus bas, dans PAGES_PAR_ROLE, où le middleware la lit.
     */
    public const PAGES = [
        // Saisie n'a pas d'icône : la place reste réservée pour que les libellés
        // s'alignent, mais le crayon n'apportait rien que le mot ne dise déjà.
        'saisie' => ['libelle' => 'Saisie', 'icone' => ''],
        'synthese' => ['libelle' => 'Synthèse & pilotage', 'icone' => '▦'],
        'balance' => ['libelle' => 'Balance âgée', 'icone' => '≣'],
        'courtiers' => ['libelle' => 'Courtiers', 'icone' => '⇄'],
        'clients' => ['libelle' => 'Clients & tiers', 'icone' => '☰'],
        'extrait' => ['libelle' => 'Extrait de compte', 'icone' => '⎙'],
        'relances' => ['libelle' => 'Journal des relances', 'icone' => '⚑'],
        'encaissements' => ['libelle' => 'Journal des encaissements', 'icone' => '₣'],
        'audit' => ['libelle' => "Piste d'audit", 'icone' => '◉'],
    ];

    /** Le sous-titre du tableau de bord, qui n'est pas l'un des neuf écrans. */
    public const SOUS_TITRE_TABLEAU = "Ce qui est dû, par qui, depuis quand, et qui s'en occupe.";

    /** Sous-titre de chaque page, affiché sous son titre. */
    public const SOUS_TITRES = [
        'saisie' => 'Chaque enregistrement met à jour toutes les vues, et la trésorerie avec elles.',
        'synthese' => 'Objectif contre réalisé (jour / semaine / mois), balance âgée, principaux débiteurs.',
        'balance' => "Encours par tiers et par tranche d'ancienneté — recalculé après chaque saisie.",
        'courtiers' => 'Le courtier est le tiers payant : ce qu’il porte en tout, et pour le compte de qui.',
        'clients' => 'Tous les tiers connus de la base — clients, assurances, courtiers — et ce que chacun doit.',
        'extrait' => "Extrait de compte par client, imprimable, avec anciennetés et niveaux de relance.",
        'relances' => 'Historique des actions de relance, de N1 à N5.',
        'encaissements' => 'Historique des règlements par mode : banque, espèce, mobile money.',
        'audit' => 'Journal horodaté de toutes les actions du module, par auteur.',
    ];

    /**
     * Ce que voit qui consulte sans relancer.
     *
     * Ni `saisie` — ils ne relancent pas et n'encaissent pas ici —, ni `synthese`, qui
     * porte l'objectif de la direction et les explications d'écart de l'équipe, ni `audit`.
     */
    private const PAGES_CONSULTATION = [
        'tableau-de-bord', 'balance', 'courtiers', 'clients', 'extrait', 'relances', 'encaissements',
    ];

    /** @var array<string, array<int, string>> */
    private const PAGES_PAR_ROLE = [
        // La page Courtiers est ouverte à l'agent : c'est une lecture de la balance âgée,
        // qu'il a déjà, et c'est elle qui lui dit à qui adresser sa relance. La lui fermer
        // reviendrait à lui demander de relancer la bonne personne sans lui montrer qui
        // c'est.
        'agent_recouvrement' => ['tableau-de-bord', 'saisie', 'balance', 'courtiers', 'clients', 'extrait', 'relances', 'encaissements'],

        /*
         * Consultation, pour ceux qui subissent l'encours sans le poursuivre.
         *
         * Les deux mêmes pages pour les deux rôles, et c'est voulu : ils posent la même
         * question — « que nous doit-on, et où en est-on ? » — depuis deux places
         * différentes. Le tableau de bord en fait partie parce que c'est la porte du
         * module : sans lui, un refus renverrait vers une page également fermée.
         */
        'caissier' => self::PAGES_CONSULTATION,
        'responsable_ville' => self::PAGES_CONSULTATION,
        'superviseur_recouvrement' => ['tableau-de-bord', 'saisie', 'synthese', 'balance', 'courtiers', 'clients', 'extrait', 'relances', 'encaissements', 'audit'],
        'gerant' => ['tableau-de-bord', 'saisie', 'synthese', 'balance', 'courtiers', 'clients', 'extrait', 'relances', 'encaissements', 'audit'],
    ];

    /*
     * Il y avait ici un plafond de niveau par rôle — N3 pour l'agent, N4 pour le
     * superviseur, N5 pour le gérant. Il a été retiré sur décision de la direction : les
     * cinq niveaux sont ouverts à tous les rôles du module.
     *
     * Le contrôle n'a pas disparu, il a changé de moment. Chaque relance est datée,
     * nominative et inscrite au journal d'audit que lisent le superviseur et le gérant :
     * la question « qui a envoyé cette mise en demeure » se lit après coup au lieu de se
     * poser avant. Ce qui reste réservé, en revanche, c'est la création de la créance —
     * voir REDACTEURS plus bas : celui qui relance et encaisse ne crée pas la facture
     * qu'il encaisse. C'est cette règle-là qui fait tenir le journal.
     */

    /**
     * Rôles qui créent la créance et le référentiel client.
     *
     * L'agent en est écarté à dessein : celui qui relance et encaisse ne doit pas
     * pouvoir créer la facture qu'il encaisse, ni renommer le tiers qui la doit. C'est
     * la règle qui rend le reste du journal digne de foi.
     */
    private const REDACTEURS = ['superviseur_recouvrement', 'gerant'];

    public static function ouvertA(?User $utilisateur): bool
    {
        return $utilisateur !== null && self::role($utilisateur) !== null;
    }

    /** @return array<int, string> */
    public static function pagesDe(?User $utilisateur): array
    {
        $role = $utilisateur ? self::role($utilisateur) : null;

        return $role ? self::PAGES_PAR_ROLE[$role] : [];
    }

    public static function peutVoir(?User $utilisateur, string $page): bool
    {
        return in_array($page, self::pagesDe($utilisateur), true);
    }

    /**
     * Qui lit tout le portefeuille, et qui ne lit que le sien.
     *
     * L'agent ne voit que ses dossiers et la réserve de ceux que personne n'a pris : c'est
     * sa charge de travail, pas celle des autres. Tous les autres rôles du module lisent
     * l'ensemble — y compris ceux qui ne relancent pas, faute de quoi la comptabilité
     * ouvrirait un tableau de bord vide, n'ayant elle-même jamais relancé personne.
     */
    public static function voitToutLePortefeuille(?User $utilisateur): bool
    {
        $role = self::role($utilisateur);

        return $role !== null && $role !== 'agent_recouvrement';
    }

    /** Qui pose un geste dans ce module, et qui ne fait que le lire. */
    public static function peutSaisir(?User $utilisateur): bool
    {
        return self::peutVoir($utilisateur, 'saisie');
    }

    public static function peutCreerUneFacture(?User $utilisateur): bool
    {
        return in_array($utilisateur ? self::role($utilisateur) : null, self::REDACTEURS, true);
    }

    public static function peutCreerUnTiers(?User $utilisateur): bool
    {
        return self::peutCreerUneFacture($utilisateur);
    }

    /** Commenter un écart, c'est expliquer un résultat : cela relève de l'encadrement. */
    public static function peutCommenter(?User $utilisateur): bool
    {
        return in_array($utilisateur ? self::role($utilisateur) : null, self::REDACTEURS, true);
    }

    /** Seul le gérant fixe l'objectif : c'est une décision de direction. */
    public static function peutFixerLObjectif(?User $utilisateur): bool
    {
        return $utilisateur !== null && self::role($utilisateur) === 'gerant';
    }

    /** L'intitulé de l'habilitation, tel qu'il s'affiche dans la barre latérale. */
    public static function habilitation(?User $utilisateur): string
    {
        return match (self::role($utilisateur)) {
            'agent_recouvrement' => 'Habilitation N1–N3',
            'superviseur_recouvrement' => 'Habilitation N1–N4 · Validation',
            'gerant' => 'Habilitation complète · N5 Contentieux',
            'caissier' => 'Consultation',
            'responsable_ville' => 'Consultation',
            default => '—',
        };
    }

    /**
     * Le rôle de recouvrement de cette personne, ou null.
     *
     * Un compte peut porter plusieurs rôles ; on retient le plus étendu, sans quoi un
     * gérant à qui l'on aurait aussi donné « agent » perdrait la moitié de ses écrans.
     */
    public static function role(?User $utilisateur): ?string
    {
        if (! $utilisateur) {
            return null;
        }

        // L'ordre est celui de l'étendue : un compte qui porterait deux rôles garde le
        // plus large. Les deux rôles de consultation viennent donc en dernier.
        foreach (['gerant', 'superviseur_recouvrement', 'agent_recouvrement', 'responsable_ville', 'caissier'] as $role) {
            if ($utilisateur->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }
}
