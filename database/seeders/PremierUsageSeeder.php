<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Exploitation\Modeles\CommentaireEcartRecouvrement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Modeles\SaisieJournaliere;
use Modules\Noyau\Exploitation\Services\Recouvrement;

/**
 * Le premier usage des écrans qui n'avaient jamais servi.
 *
 * **Pourquoi ce jeu de données existe.** Trois fonctions étaient construites, testées, et
 * n'avaient jamais rencontré une seule ligne : le journal des relances (zéro), les
 * commentaires d'écart de la synthèse (zéro), la saisie journalière (quatre lignes pour
 * trois villes et huit mois). Un écran vide ne se juge pas : on n'y voit ni si le tri
 * tient, ni comment la pagination se comporte, ni si les colonnes sont les bonnes — et
 * surtout pas ce qu'il donnera le jour où il sera plein.
 *
 * **Ce que ce jeu ne fait pas, et c'est le point important.** Il n'écrit aucune facture,
 * aucun encaissement, aucune charge. Il ne touche donc ni au chiffre d'affaires, ni à la
 * créance, ni à la trésorerie. Une relance est la trace d'un appel ; un commentaire est
 * une annotation ; la saisie journalière est un carnet de bord. Aucune des trois n'entre
 * dans un total comptable. Les chiffres de l'application sont exactement les mêmes avant
 * et après, et c'est ce que vérifie PremierUsageTest.
 *
 * **Il se retire d'un seul geste**, puisque les deux premières tables étaient vides :
 *
 *     php artisan db:seed --class=PremierUsageSeeder --force
 *     php artisan tinker --execute="DB::table('relances_recouvrement')->truncate();"
 *
 * La saisie journalière, elle, portait déjà quatre lignes réelles. Le seeder ne les touche
 * pas : il n'écrit que sur les journées vides.
 */
class PremierUsageSeeder extends Seeder
{
    /** Sur combien de jours les relances s'étalent. */
    private const JOURS = 75;

    /** Combien de tiers débiteurs sont travaillés. */
    private const TIERS = 40;

    /** Sur combien de jours le carnet de bord est rempli. */
    private const JOURS_DE_CARNET = 21;

    private const INTERLOCUTEURS = [
        'Mme Koffi, comptabilité',
        'M. Bamba, sinistres',
        'Mme Aka, recouvrement',
        'M. Yao, direction financière',
        'Le secrétariat',
        'Mme Touré, règlements',
        'M. Konaté, agence',
        'Service comptable',
        'M. Ouattara, gérance',
    ];

    /** @var array<int, array<int, string>> */
    private const SUITES = [
        1 => ['Relance adressée, accusé de réception demandé.', 'Courriel envoyé au service comptable.'],
        2 => ['Joint au téléphone, promet de regarder le dossier.', 'Rappel téléphonique, renvoi vers la comptabilité.'],
        3 => ['Lettre de relance remise en main propre.', "Courrier déposé à l'accueil, décharge signée."],
        4 => ['Mise en demeure remise, délai de huit jours notifié.', 'Mise en demeure envoyée en recommandé.'],
        5 => ["Dossier transmis à l'huissier.", 'Contentieux ouvert, pièces remises au conseil.'],
    ];

    public function run(): void
    {
        $entreprise = Entreprise::withoutGlobalScopes()->where('est_active', true)->first();

        if (! $entreprise) {
            $this->command?->warn('Aucune entreprise active : rien à poser.');

            return;
        }

        $this->relances($entreprise);
        $this->commentairesDEcart($entreprise);
        $this->saisiesJournalieres($entreprise);
    }

    /**
     * Le journal des relances, tiré des créances réelles.
     *
     * Les tiers ne sont pas inventés : ce sont les quarante plus gros débiteurs de la
     * balance, et le niveau atteint par chaque dossier découle de l'ancienneté vraie de sa
     * facture la plus vieille. Un journal peuplé de noms fictifs à des niveaux arbitraires
     * n'aurait rien montré — c'est justement la correspondance entre l'âge d'une créance et
     * le niveau atteint qu'on doit pouvoir lire à l'écran.
     */
    private function relances(Entreprise $entreprise): void
    {
        $deja = RelanceRecouvrement::withoutGlobalScopes()
            ->where('entreprise_id', $entreprise->id)->exists();

        if ($deja) {
            $this->command?->info('Journal des relances : déjà servi, rien à poser.');

            return;
        }

        $agents = $this->agentsDuRecouvrement($entreprise);

        if ($agents->isEmpty()) {
            $this->command?->warn('Aucun compte de recouvrement : pas de relance à poser.');

            return;
        }

        $arrete = Carbon::today();

        $debiteurs = Recouvrement::facturesOuvertes()
            ->groupBy(fn (Facture $f) => $f->tiersPayant())
            ->map(fn ($factures) => [
                'reste' => $factures->sum(fn (Facture $f) => Recouvrement::reste($f)),
                'niveau' => $factures->max(fn (Facture $f) => Recouvrement::niveau($f, $arrete)['niveau']),
                'nombre' => $factures->count(),
                // L'âge de la plus vieille facture ouverte : c'est lui qui datera les
                // relances, puisque c'est lui qui a déclenché le protocole.
                'age' => (int) $factures->max(fn (Facture $f) => Recouvrement::anciennete($f, $arrete) ?? 0),
            ])
            ->sortByDesc('reste')
            ->take(self::TIERS);

        $lignes = [];
        $rang = 0;

        foreach ($debiteurs as $tiers => $dossier) {
            $rang++;

            // Un dossier ancien a été relancé plusieurs fois : on remonte le protocole
            // depuis N1, sans jamais dépasser le niveau que son ancienneté appelle.
            $atteint = max(1, min(5, (int) $dossier['niveau']));

            for ($niveau = 1; $niveau <= $atteint; $niveau++) {
                $recul = $this->reculDe($niveau, $atteint, (int) $dossier['age'], $rang);

                $agent = $agents[($rang + $niveau) % $agents->count()];
                $suites = self::SUITES[$niveau];

                $promis = $niveau >= 2 && $rang % 3 === 0
                    ? (int) round($dossier['reste'] * 0.4 / 1000) * 1000
                    : 0;

                $lignes[] = [
                    'entreprise_id' => $entreprise->id,
                    'user_id' => $agent->id,
                    'responsable' => $agent->name,
                    'date' => Carbon::today()->subDays(max(0, $recul))->toDateString(),
                    'tiers' => $tiers,
                    'factures_visees' => 'Situation globale',
                    'niveau' => $niveau,
                    'canal' => $this->canal($niveau),
                    'interlocuteur' => self::INTERLOCUTEURS[($rang + $niveau) % count(self::INTERLOCUTEURS)],
                    'resultat' => $suites[($rang + $niveau) % count($suites)],
                    'montant_promis' => $promis,
                    'statut' => $this->statut($niveau, $promis, $rang),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($lignes, 200) as $paquet) {
            DB::table('relances_recouvrement')->insert($paquet);
        }

        $this->command?->info('Journal des relances : '.count($lignes).' relances posées sur '
            .$debiteurs->count().' tiers.');
    }

    /**
     * À combien de jours en arrière dater une relance de niveau N.
     *
     * **Le protocole d'abord.** Le niveau N se déclenche quand la facture atteint son seuil
     * — sept jours pour N1, quatre-vingt-dix pour N5. Une relance de niveau N a donc eu lieu
     * quand la facture avait cet âge-là, c'est-à-dire il y a `âge − seuil` jours. Dater les
     * cinq niveaux à intervalles réguliers aurait produit un journal qui se lit bien et qui
     * ment : un dossier de douze jours y aurait porté une première relance vieille de deux
     * mois, antérieure à la facture qu'elle réclame.
     *
     * **Le rattrapage ensuite.** Une créance de quatre ans donnerait, par ce calcul, des
     * relances de 2022 — or le module vient d'ouvrir. Au-delà de la fenêtre d'usage, les
     * cinq niveaux sont donc repliés dedans, dans l'ordre : c'est exactement ce qui se passe
     * quand on reprend un arriéré, on remonte le protocole en quelques semaines.
     *
     * Le décalage de quelques jours évite que tous les dossiers soient relancés le même
     * matin : personne ne traite quarante tiers d'un coup.
     */
    private function reculDe(int $niveau, int $atteint, int $age, int $rang): int
    {
        $retard = $rang % 4;

        if ($age - Recouvrement::SEUILS[1] <= self::JOURS) {
            return max(0, $age - Recouvrement::SEUILS[$niveau] - $retard);
        }

        return (int) round(self::JOURS * ($atteint - $niveau) / max(1, $atteint)) + $retard;
    }

    /** Le canal suit le niveau : on n'envoie pas un huissier pour une première relance. */
    private function canal(int $niveau): string
    {
        return match ($niveau) {
            1 => 'E-mail',
            2 => 'Téléphone',
            3 => 'Courrier',
            4 => 'LRAR',
            default => 'Huissier',
        };
    }

    private function statut(int $niveau, int $promis, int $rang): string
    {
        if ($niveau >= 5) {
            return 'Transmis au contentieux';
        }

        if ($promis > 0) {
            return 'Promesse de règlement';
        }

        return match ($rang % 4) {
            0 => 'Règlement partiel',
            1 => 'Litige / contestation',
            default => 'En cours',
        };
    }

    /**
     * Les commentaires d'écart de la synthèse.
     *
     * L'écran demande d'expliquer pourquoi l'encaissé s'écarte de l'objectif. Vide, il ne
     * montre pas à quoi ressemble une explication — c'est pourtant la seule chose qu'il
     * attend, et la forme de la réponse conditionne son utilité.
     */
    private function commentairesDEcart(Entreprise $entreprise): void
    {
        $deja = CommentaireEcartRecouvrement::withoutGlobalScopes()
            ->where('entreprise_id', $entreprise->id)->exists();

        if ($deja) {
            $this->command?->info("Commentaires d'écart : déjà servis, rien à poser.");

            return;
        }

        $encadrant = $this->agentsDuRecouvrement($entreprise)->last()
            ?? User::where('entreprise_id', $entreprise->id)->first();

        if (! $encadrant) {
            return;
        }

        $jour = Carbon::today();

        $textes = [
            ['jour', $jour->toDateString(),
                'Journée courte : deux règlements attendus de NSIA sont annoncés pour demain matin.'],
            ['semaine', $jour->format('o-\SW'),
                "Écart tenu par le dossier CIE — promesse de règlement partiel obtenue jeudi, le "
                .'solde suit à la fin du mois.'],
            ['mois', $jour->format('Y-m'),
                "Le mois reste en retard sur l'objectif : trois gros dossiers sont passés en mise "
                ."en demeure, l'encaissement ne se verra qu'au mois prochain."],
        ];

        foreach ($textes as [$periode, $reference, $texte]) {
            CommentaireEcartRecouvrement::withoutGlobalScopes()->create([
                'entreprise_id' => $entreprise->id,
                'user_id' => $encadrant->id,
                'periode' => $periode,
                'reference' => $reference,
                'texte' => $texte,
            ]);
        }

        $this->command?->info("Commentaires d'écart : 3 posés.");
    }

    /**
     * Le carnet de bord quotidien, sur les jours ouvrés récents.
     *
     * Les journées déjà saisies ne sont pas touchées : on n'écrase pas ce que quelqu'un a
     * écrit, même quand ce qu'il a écrit est plus pauvre que ce qu'on poserait.
     */
    private function saisiesJournalieres(Entreprise $entreprise): void
    {
        $sites = Site::withoutGlobalScopes()
            ->where('entreprise_id', $entreprise->id)->where('est_actif', true)->get();

        $notes = [
            'prospects' => ['Deux passages sans suite.', "Un prospect envoyé par l'assurance.", 'Rien à signaler.'],
            'devis' => ["Trois devis en attente de l'accord assureur.", 'Un devis refusé sur le prix.', 'Rien à signaler.'],
            'ca' => ['Journée dans la moyenne.', 'Grosse sortie de carrosserie.', 'Deux facturations décalées à demain.'],
            'tresorerie' => ['Remise en banque effectuée.', 'Caisse arrêtée sans écart.', "Un chèque à encaisser demain."],
            'charges' => ['Achat de pièces pour deux dossiers.', 'Rien à signaler.', 'Facture carburant du mois.'],
        ];

        $poses = 0;

        foreach ($sites->values() as $rang => $site) {
            for ($recul = 0; $recul < self::JOURS_DE_CARNET; $recul++) {
                $jour = Carbon::today()->subDays($recul);

                // Le dimanche, l'atelier est fermé : une ligne de carnet ce jour-là ferait
                // croire à une activité qui n'a pas eu lieu.
                if ($jour->isSunday()) {
                    continue;
                }

                $existe = SaisieJournaliere::withoutGlobalScopes()
                    ->where('site_id', $site->id)
                    ->whereDate('date', $jour)
                    ->exists();

                if ($existe) {
                    continue;
                }

                $i = ($rang + $recul) % 3;

                SaisieJournaliere::withoutGlobalScopes()->create([
                    'entreprise_id' => $entreprise->id,
                    'site_id' => $site->id,
                    'date' => $jour->toDateString(),
                    'vehicules_sans_facture' => ($rang + $recul) % 4,
                    'commentaire_prospects' => $notes['prospects'][$i],
                    'commentaire_devis' => $notes['devis'][$i],
                    'commentaire_ca' => $notes['ca'][$i],
                    'commentaire_tresorerie' => $notes['tresorerie'][$i],
                    'commentaire_charges' => $notes['charges'][$i],
                ]);

                $poses++;
            }
        }

        $this->command?->info('Saisie journalière : '.$poses.' journées posées, les existantes intactes.');
    }

    /**
     * Les comptes qui relancent.
     *
     * Le superviseur en fait partie : il relance lui aussi, et l'en exclure ferait
     * disparaître son propre travail du journal qu'il est censé contrôler.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function agentsDuRecouvrement(Entreprise $entreprise)
    {
        $ids = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->whereIn('r.name', ['agent_recouvrement', 'superviseur_recouvrement'])
            ->where('r.entreprise_id', $entreprise->id)
            ->pluck('mr.model_id');

        return User::whereIn('id', $ids)->where('est_actif', true)->orderBy('id')->get();
    }
}
