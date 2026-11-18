<?php

namespace Modules\Noyau\Rapport\Services;

use App\Models\User;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\IA\Modeles\ConversationIA;
use Modules\Noyau\IA\Modeles\ProductionIA;
use Modules\Noyau\IA\Services\ClientIA;
use Modules\Noyau\IA\Services\IAIndisponible;

/**
 * L'agent du rapport — « comme Claude dans notre application » — 10/11.
 *
 * Un assistant qui **lit** les données de l'application (CA, impayés, charges, trésorerie,
 * commerciaux, année précédente), **analyse**, **répond** aux questions, **écrit** l'analyse globale du
 * rapport dans le style demandé, **produit** des documents imprimables en PDF, et **dépose** une pièce
 * (impôt, CNPS…) par la même porte que la page Dépôt — donc sans doublon.
 *
 * **Ce qu'il ne fait pas.** Il n'écrit dans aucune table de gestion : ni facture, ni encaissement, ni
 * charge. Ses seules écritures sont ses propres productions (`productions_ia`) et les pièces du dépôt de
 * rapport. Chaque outil se vérifie lui-même contre les habilitations du compte qui parle : l'IA n'a pas
 * plus de droits que lui.
 *
 * Les données lues sont envoyées au fournisseur d'IA que le Super admin a affecté au rapport : c'est le
 * prix d'un assistant qui voit les chiffres. Les clés d'API, elles, ne passent jamais par ici.
 */
final class AgentDuRapport
{
    public function __construct(private readonly User $compte, private readonly int $exercice) {}

    public static function disponible(): bool
    {
        return ClientIA::pour('rapport') !== null;
    }

    public function conversation(): ConversationIA
    {
        return ConversationIA::query()->firstOrCreate(
            ['entreprise_id' => $this->compte->entreprise_id, 'user_id' => $this->compte->id, 'usage' => 'rapport'],
            ['messages' => []],
        );
    }

    public function nouvelleConversation(): void
    {
        $this->conversation()->update(['messages' => []]);
    }

    /** Répond au message, l'inscrit à la conversation, et rend la réponse. */
    public function repondre(string $message): string
    {
        $conversation = $this->conversation();
        $conversation->ajouter('user', $message);

        try {
            $reponse = $this->ia()->discuter(
                $this->systeme(),
                array_map(fn ($t) => ['role' => $t['role'], 'content' => $t['content']], array_slice($conversation->tours(), -20)),
                $this->outils(),
                fn (string $nom, array $a) => $this->executer($nom, $a),
            );
        } catch (IAIndisponible $e) {
            $reponse = 'Je n\'ai pas pu répondre : '.$e->getMessage();
        }

        $conversation->ajouter('assistant', $reponse);

        return $reponse;
    }

    /**
     * L'analyse globale du rapport, écrite par l'IA dans son style ou dans celui que `$consigne` demande.
     * Elle remplace la précédente ; `revenirALEtat()` la retire.
     */
    public function analyserLeRapport(string $consigne = ''): ProductionIA
    {
        $demande = "Fais l'analyse globale du rapport financier de l'exercice {$this->exercice}. "
            .'Commence par lire les sections du rapport avec l\'outil lire_rapport (ca, recouvrement, charges, tresorerie, conclusion, commerciaux, annee_precedente), '
            .'puis enregistre ton analyse avec l\'outil enregistrer_analyse : un titre, puis un texte en Markdown — constat, points d\'alerte chiffrés, causes probables, actions à mener classées par priorité. '
            .'N\'invente aucun chiffre.'.($consigne !== '' ? "\n\nConsigne de présentation et de style : {$consigne}" : '');

        $avant = (int) ProductionIA::query()->where('usage', 'rapport')->where('type', 'analyse')->max('id');

        try {
            $this->ia()->discuter($this->systeme(), [['role' => 'user', 'content' => $demande]], $this->outils(), fn (string $nom, array $a) => $this->executer($nom, $a), 10, 3000);
        } catch (IAIndisponible $e) {
            throw $e;
        }

        $analyse = ProductionIA::query()->where('usage', 'rapport')->where('type', 'analyse')->where('exercice', $this->exercice)->where('id', '>', $avant)->latest('id')->first();

        return $analyse ?? throw new IAIndisponible('L\'assistant n\'a pas enregistré d\'analyse : relancez la demande.');
    }

    /**
     * « Revenir à l'état » : on retire les analyses de l'IA pour cet exercice ; le rapport reprend
     * l'analyse du système, celle de ses règles de calcul. Les documents demandés, eux, restent.
     */
    public static function revenirALEtat(int $exercice): int
    {
        return ProductionIA::query()->where('usage', 'rapport')->where('type', 'analyse')->where('exercice', $exercice)->delete();
    }

    private function ia(): ClientIA
    {
        return ClientIA::pour('rapport') ?? throw new IAIndisponible('L\'IA du rapport n\'est pas configurée : le Super admin doit renseigner une clé et affecter un fournisseur à l\'usage « rapport » (Super admin › IA).');
    }

    private function systeme(): string
    {
        return 'Tu es l\'assistant de gestion de l\'application de '.($this->compte->entreprise?->nom ?? 'l\'entreprise').', un groupe de garages automobiles en Côte d\'Ivoire. '
            .'Tu parles français, avec des montants en FCFA. Nous sommes le '.now()->format('d/m/Y').' ; l\'exercice regardé est '.$this->exercice.', '
            .'du 1er janvier à aujourd\'hui. Tu parles à '.$this->compte->name.'.'."\n\n"
            .'Tu disposes d\'outils pour LIRE les données réelles de l\'application (rapport : CA, impayés, charges, trésorerie, commerciaux, année précédente ; '
            .'factures impayées ; lignes de charges ; pièces déposées). Utilise-les pour répondre : ne devine jamais un chiffre, ne cite que ce que tu as lu, '
            .'et dis clairement quand une donnée manque (par exemple la CNPS tant qu aucune pièce n\'est déposée).'."\n"
            .'Tu peux ENREGISTRER l\'analyse globale du rapport (enregistrer_analyse), PRODUIRE un document imprimable en PDF (generer_document : donne ensuite le lien à l\'utilisateur), '
            .'et DÉPOSER une pièce d\'impôt, de CNPS ou de salaire (deposer_piece : demande confirmation du montant avant de l\'appeler). '
            .'Tu peux choisir le style d\'un document ou d\'une analyse : une couleur d\'accent, une couleur de fond, une mise en page (standard, colonnes, sobre).'."\n"
            .'Tu ne modifies jamais les factures, encaissements ou charges de l\'application. Les imputations comptables relèvent d\'un autre assistant, pas encore activé : '
            .'si on t\'en demande, dis-le simplement.'."\n"
            .'Réponds de façon claire et courte ; utilise le Markdown (titres, listes, tableaux) quand cela aide. Pour une demande de chiffres, donne les montants entiers avec séparateur d\'espace.';
    }

    /** @return list<array<string, mixed>> */
    private function outils(): array
    {
        $style = ['type' => 'object', 'description' => 'Style facultatif.', 'properties' => [
            'accent' => ['type' => 'string', 'description' => 'Couleur d\'accent, hexadécimale, ex. #C8102E'],
            'fond' => ['type' => 'string', 'description' => 'Couleur de fond, hexadécimale, ex. #F4F3EF'],
            'mise_en_page' => ['type' => 'string', 'enum' => ProductionIA::MISES_EN_PAGE],
        ]];

        return [
            ['name' => 'lire_rapport', 'description' => 'Lit une section du rapport financier : ca, recouvrement, charges, tresorerie, conclusion, commerciaux, annee_precedente, ou commentaires_systeme (l\'analyse du système).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'section' => ['type' => 'string', 'enum' => ['ca', 'recouvrement', 'charges', 'tresorerie', 'conclusion', 'commerciaux', 'annee_precedente', 'commentaires_systeme']],
                    'exercice' => ['type' => 'integer', 'description' => 'Année ; par défaut l\'exercice en cours.'],
                ], 'required' => ['section']]],
            ['name' => 'ca_par_client', 'description' => 'Le chiffre d\'affaires par client, les plus gros d\'abord.',
                'parameters' => ['type' => 'object', 'properties' => ['limite' => ['type' => 'integer'], 'exercice' => ['type' => 'integer']]]],
            ['name' => 'factures_impayees', 'description' => 'Les factures impayées une à une (reste dû, dernier règlement), les plus grosses d\'abord ; filtre facultatif sur le nom du client.',
                'parameters' => ['type' => 'object', 'properties' => ['client' => ['type' => 'string'], 'limite' => ['type' => 'integer']]]],
            ['name' => 'lignes_de_charges', 'description' => 'Les lignes de charges comptées (source : caisse, saisie, suivi, fne), les plus grosses d\'abord ; filtres facultatifs sur la source et la nature.',
                'parameters' => ['type' => 'object', 'properties' => ['source' => ['type' => 'string', 'enum' => ['caisse', 'saisie', 'suivi', 'fne']], 'nature' => ['type' => 'string'], 'limite' => ['type' => 'integer']]]],
            ['name' => 'pieces_deposees', 'description' => 'Les pièces déposées dans le rapport (impôts, CNPS, salaires, récapitulatifs).',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
            ['name' => 'enregistrer_analyse', 'description' => 'Enregistre l\'analyse globale du rapport : elle s\'affiche dans l\'onglet Assistant IA et remplace la précédente.',
                'parameters' => ['type' => 'object', 'properties' => ['titre' => ['type' => 'string'], 'contenu_markdown' => ['type' => 'string'], 'style' => $style], 'required' => ['titre', 'contenu_markdown']]],
            ['name' => 'generer_document', 'description' => 'Produit un document (état, statistiques, note) imprimable en PDF ; rend le lien à donner à l\'utilisateur.',
                'parameters' => ['type' => 'object', 'properties' => ['titre' => ['type' => 'string'], 'contenu_markdown' => ['type' => 'string'], 'style' => $style], 'required' => ['titre', 'contenu_markdown']]],
            ['name' => 'deposer_piece', 'description' => 'Dépose une pièce (avis d\'impôt, cotisation CNPS, salaire…) dans le rapport, sans doublon. À n\'appeler qu\'après confirmation du montant par l\'utilisateur.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'nature' => ['type' => 'string', 'enum' => ['impot', 'cnps', 'salaire', 'autre']],
                    'statut' => ['type' => 'string', 'enum' => ['du', 'paye']],
                    'date' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                    'libelle' => ['type' => 'string'], 'reference' => ['type' => 'string'], 'tiers' => ['type' => 'string'],
                    'montant' => ['type' => 'integer', 'description' => 'Entier, en francs'],
                ], 'required' => ['nature', 'statut', 'libelle', 'montant']]],
        ];
    }

    private function json(mixed $donnees): string
    {
        return (string) json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @param  array<string, mixed>  $a */
    private function executer(string $nom, array $a): string
    {
        // Lire le rapport, c'est avoir `rapport.voir` ; parler à l'assistant, `rapport.ia`.
        abort_unless(HabilitationsDActions::accorde($this->compte, 'rapport', 'voir') && HabilitationsDActions::accorde($this->compte, 'rapport', 'ia'), 403);

        $exercice = (int) ($a['exercice'] ?? $this->exercice) ?: $this->exercice;
        $rapport = RapportFinancier::pour($this->compte, $exercice);

        return match ($nom) {
            'lire_rapport' => $this->json(match ($a['section'] ?? '') {
                'ca' => $rapport->chiffreDAffaires(),
                'recouvrement' => $rapport->recouvrement(),
                'charges' => $rapport->charges(),
                'tresorerie' => $rapport->tresorerie(),
                'conclusion' => $rapport->conclusion(),
                'commerciaux' => $rapport->commerciaux(),
                'annee_precedente' => $rapport->anneePrecedente(),
                'commentaires_systeme' => AssistantDuRapport::commentaires($rapport->tout()),
                default => ['erreur' => 'Section inconnue.'],
            }),
            'ca_par_client' => $this->json($rapport->caParClient(min(max((int) ($a['limite'] ?? 15), 1), 50))),
            'factures_impayees' => $this->json($rapport->impayesParFacture($a['client'] ?? null, min(max((int) ($a['limite'] ?? 25), 1), 60))),
            'lignes_de_charges' => $this->json($rapport->lignesDeCharges($a['source'] ?? null, $a['nature'] ?? null, min(max((int) ($a['limite'] ?? 25), 1), 60))),
            'pieces_deposees' => $this->json(\Modules\Noyau\Rapport\Modeles\PieceRapport::query()->where('exercice', $exercice)->latest('id')->limit(80)->get(['nature', 'statut', 'date_piece', 'libelle', 'reference', 'montant', 'compte'])),
            'enregistrer_analyse' => $this->enregistrer('analyse', $a),
            'generer_document' => $this->enregistrer('document', $a),
            'deposer_piece' => $this->deposer($a),
            default => 'Outil inconnu : '.$nom,
        };
    }

    /** @param  array<string, mixed>  $a */
    private function enregistrer(string $type, array $a): string
    {
        $titre = trim((string) ($a['titre'] ?? ''));
        $contenu = trim((string) ($a['contenu_markdown'] ?? ''));

        if ($titre === '' || $contenu === '') {
            return 'Erreur : un titre et un contenu sont nécessaires.';
        }

        if ($type === 'analyse') {
            self::revenirALEtat($this->exercice);
        }

        $production = ProductionIA::query()->create([
            'entreprise_id' => $this->compte->entreprise_id, 'user_id' => $this->compte->id, 'usage' => 'rapport', 'type' => $type,
            'exercice' => $this->exercice, 'titre' => mb_substr($titre, 0, 200), 'contenu' => $contenu,
            'style' => json_encode(ProductionIA::styleValide(is_array($a['style'] ?? null) ? $a['style'] : [])),
        ]);

        return $type === 'analyse'
            ? 'Analyse enregistrée : elle s\'affiche dans l\'onglet Assistant IA.'
            : 'Document créé. Lien à donner à l\'utilisateur : '.route('rapport.document', $production->id).' (il s\'ouvre dans l\'application ; « Imprimer / PDF » l\'enregistre en PDF).';
    }

    /** @param  array<string, mixed>  $a */
    private function deposer(array $a): string
    {
        if (! HabilitationsDActions::accorde($this->compte, 'rapport', 'deposer')) {
            return 'Refusé : ce compte n\'a pas l\'habilitation de dépôt (rapport.deposer).';
        }

        $montant = \Modules\Noyau\Rapport\Support\LectureDesPieces::nombre($a['montant'] ?? null);

        if ($montant === null || $montant <= 0) {
            return 'Erreur : montant invalide.';
        }

        $bilan = DepotDeRapport::saisir([
            'nature' => in_array($a['nature'] ?? '', ['impot', 'cnps', 'salaire', 'autre'], true) ? $a['nature'] : 'autre',
            'statut' => ($a['statut'] ?? 'du') === 'paye' ? 'paye' : 'du',
            'date' => \Modules\Noyau\Rapport\Support\LectureDesPieces::date($a['date'] ?? null),
            'libelle' => mb_substr((string) ($a['libelle'] ?? 'Pièce'), 0, 200),
            'tiers' => isset($a['tiers']) ? mb_substr((string) $a['tiers'], 0, 200) : null,
            'reference' => isset($a['reference']) ? mb_substr((string) $a['reference'], 0, 80) : null,
            'montant' => $montant,
        ], $this->compte, $this->exercice);

        return $bilan['doublons'] > 0
            ? 'Cette pièce existait déjà : rien n\'a été recréé.'
            : 'Pièce déposée'.($bilan['dejaDansLApplication'] > 0 ? ' (déjà comptée par la caisse ou la banque : gardée, non recomptée).' : '.');
    }
}
