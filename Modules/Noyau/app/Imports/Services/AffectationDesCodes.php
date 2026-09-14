<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\DossierVehicule;

/**
 * Qui travaille où : le référentiel des codes agents, appris puis confirmé.
 *
 * C'est la pièce qui manque pour répondre à la demande de départ — **répartir les données
 * d'Abidjan entre le Site 1 et le Site 2**. Rappel de la situation : aucun fichier ne
 * distingue les deux ateliers, la colonne SITE des exports dit « ABIDJAN » et s'arrête là.
 * Le seul discriminant qui existe est le code de deux lettres du numéro de fiche.
 *
 * **Ce que ce service sait faire tout seul, et ce qu'il ne fera jamais.**
 *
 * La *ville* d'un code se déduit des données, et honnêtement : les trois exports de la
 * situation du parc sont parfaitement disjoints — on l'a mesuré, aucun numéro de fiche
 * n'apparaît dans deux fichiers. Un code dont 923 fiches sur 924 sont arrivées par le
 * dépôt d'Abidjan travaille à Abidjan, et {@see observations()} le propose.
 *
 * Le *site*, lui, ne se déduit de rien. Aucune donnée existante ne dit si KZ est au Site 1
 * ou au Site 2 : l'information n'est dans aucun fichier. Ce service ne la devinera donc
 * pas. Il présente les codes, les classe par volume, montre ce qui est en jeu — 924 fiches
 * pour KZ, 869 pour AB — et attend qu'on lui dise. C'est un formulaire de treize lignes à
 * remplir une fois, pas un chantier.
 *
 * **Rien ne s'applique sans confirmation.** {@see observations()} propose, {@see retenir()}
 * enregistre ce qu'on lui a explicitement passé. Une proposition mesurée à 99 % reste une
 * proposition : c'est le chiffre d'affaires d'un atelier qui est au bout.
 */
class AffectationDesCodes
{
    /** En deçà de cette part, on ne propose rien : le code est trop dispersé pour conclure. */
    public const PART_MINIMALE = 0.7;

    public function __construct(private int $entrepriseId) {}

    /**
     * Ce que les données disent de chaque code, et ce qu'on en propose.
     *
     * @return Collection<int, array{
     *     code: string, libelle: string|null, occurrences: int, dossiers: int,
     *     ville_id: int|null, site_id: int|null,
     *     repartition: array<string, int>, ville_proposee: int|null, part: float,
     *     doit_choisir_un_site: bool, sites_possibles: Collection
     * }>
     */
    public function observations(): Collection
    {
        $villes = Ville::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->orderBy('nom')
            ->get();

        $sites = Site::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('est_actif', true)
            ->orderBy('nom')
            ->get();

        // Une seule requête pour toute la répartition, plutôt qu'une par code.
        $mesures = DossierVehicule::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNotNull('code_agent')
            ->selectRaw('code_agent, ville_id, count(*) as n')
            ->groupBy('code_agent', 'ville_id')
            ->get()
            ->groupBy('code_agent');

        return CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->orderByDesc('occurrences')
            ->get()
            ->map(function (CodeAgent $agent) use ($villes, $sites, $mesures) {
                $lignes = $mesures->get($agent->code, collect());
                $total = (int) $lignes->sum('n');

                $repartition = [];
                $dominante = null;
                $meilleure = 0;

                foreach ($lignes as $ligne) {
                    $nom = $ligne->ville_id
                        ? ($villes->firstWhere('id', $ligne->ville_id)?->nom ?? 'Ville inconnue')
                        : 'Sans ville';

                    $repartition[$nom] = (int) $ligne->n;

                    if ($ligne->ville_id !== null && $ligne->n > $meilleure) {
                        $meilleure = (int) $ligne->n;
                        $dominante = (int) $ligne->ville_id;
                    }
                }

                arsort($repartition);

                $part = $total > 0 ? $meilleure / $total : 0.0;
                $proposee = $part >= self::PART_MINIMALE ? $dominante : null;

                // La ville retenue s'il y en a une, sinon celle qu'on propose : c'est elle
                // qui détermine si un choix de site reste à faire.
                $villeEnJeu = $agent->ville_id ?? $proposee;

                $possibles = $villeEnJeu === null
                    ? collect()
                    : $sites->where('ville_id', $villeEnJeu)->values();

                return [
                    'code' => $agent->code,
                    'libelle' => $agent->libelle,
                    'occurrences' => (int) $agent->occurrences,
                    'dossiers' => $total,
                    'ville_id' => $agent->ville_id,
                    'site_id' => $agent->site_id,
                    'repartition' => $repartition,
                    'ville_proposee' => $proposee,
                    'part' => round($part, 3),
                    // Le seul cas qui demande vraiment une décision humaine : plusieurs
                    // ateliers dans la ville, et aucun choisi.
                    'doit_choisir_un_site' => $possibles->count() > 1 && $agent->site_id === null,
                    'sites_possibles' => $possibles,
                ];
            });
    }

    /**
     * Enregistre une affectation, après vérification que ville et site vont ensemble.
     *
     * Un site qui n'appartient pas à la ville annoncée est refusé plutôt que corrigé : il
     * signale une erreur de saisie ou un formulaire trafiqué, et dans les deux cas la bonne
     * réponse est de ne rien écrire.
     */
    public function retenir(string $code, ?int $villeId, ?int $siteId, ?string $libelle = null): CodeAgent
    {
        $agent = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('code', mb_strtoupper(trim($code)))
            ->firstOrFail();

        if ($villeId !== null) {
            $existe = Ville::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($villeId)
                ->exists();

            if (! $existe) {
                throw new \InvalidArgumentException('Cette ville n\'appartient pas à l\'entreprise.');
            }
        }

        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($siteId)
                ->first();

            if (! $site) {
                throw new \InvalidArgumentException('Ce site n\'appartient pas à l\'entreprise.');
            }

            if ($villeId !== null && $site->ville_id !== $villeId) {
                throw new \InvalidArgumentException('Ce site n\'est pas dans la ville indiquée.');
            }

            // Le site suffit à désigner la ville : on la remet d'aplomb plutôt que de
            // laisser les deux champs se contredire.
            $villeId ??= $site->ville_id;
        }

        $agent->forceFill([
            'ville_id' => $villeId,
            'site_id' => $siteId,
            'libelle' => $libelle !== null ? mb_substr(trim($libelle), 0, 120) : $agent->libelle,
        ])->save();

        return $agent;
    }

    /**
     * Rattache un code à un compte, à la création de l'accès ou plus tard.
     *
     * C'est le point d'entrée depuis les écrans du personnel : quand on ouvre un accès à
     * quelqu'un qui saisit des fiches dans le logiciel, on renseigne son code, et tout ce
     * qu'il a écrit depuis des mois se range enfin dans la bonne ville et le bon atelier.
     *
     * Le code peut très bien n'avoir jamais été vu dans un import — un nouvel embauché n'a
     * encore rien saisi. Il est alors créé à zéro rencontre, prêt à accueillir ce qui
     * viendra.
     *
     * La ville et le site sont **repris du périmètre du compte**, jamais saisis à part :
     * c'est ce qui évite qu'un responsable du Site 2 se retrouve avec un code pointant sur
     * le Site 1 parce que deux formulaires n'ont pas été remplis pareil.
     */
    public function attribuerA(User $utilisateur, ?string $code): ?CodeAgent
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            throw new \InvalidArgumentException(
                "Un code employé fait exactement deux lettres : c'est sous cette forme que le "
                ."logiciel l'inscrit dans les numéros de fiche, « FR-KZN° 010669 »."
            );
        }

        $occupant = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('code', $code)
            ->first();

        // Un code désigne une personne. Le laisser porter deux noms ferait basculer des
        // fiches d'un atelier à l'autre au gré des changements de fiche de poste.
        if ($occupant && $occupant->user_id !== null && $occupant->user_id !== $utilisateur->id) {
            throw new \InvalidArgumentException(
                'Le code « '.$code.' » est déjà celui de '.($occupant->libelle ?: 'un autre employé').'.'
            );
        }

        $agent = $occupant ?? new CodeAgent([
            'entreprise_id' => $this->entrepriseId,
            'code' => $code,
            'occurrences' => 0,
        ]);

        $site = $utilisateur->site_id
            ? Site::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($utilisateur->site_id)
                ->first()
            : null;

        $agent->forceFill([
            'entreprise_id' => $this->entrepriseId,
            'code' => $code,
            'user_id' => $utilisateur->id,
            'libelle' => mb_substr($utilisateur->name, 0, 120),
            'ville_id' => $site?->ville_id ?? $utilisateur->ville_id,
            'site_id' => $site?->id,
            'est_actif' => true,
        ])->save();

        return $agent;
    }

    /** Détache le code d'un compte sans effacer ce que les imports en savent. */
    public function detacherDe(User $utilisateur): void
    {
        CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('user_id', $utilisateur->id)
            ->update(['user_id' => null]);
    }

    /**
     * Reprend les lignes rattachées par présomption et leur applique les codes désormais connus.
     *
     * C'est ce qui évite de redemander les fichiers. Une fois les treize codes d'Abidjan
     * répartis entre les deux ateliers, cette reprise range les 2 203 fiches déjà en base
     * sans qu'on redépose quoi que ce soit — et elle ne touche que les lignes marquées
     * présumées, donc jamais un rattachement établi par la donnée.
     *
     * @return int le nombre de fiches déplacées
     */
    public function rejouerLesPresomptions(): int
    {
        $agents = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNotNull('ville_id')
            ->get()
            ->keyBy('code');

        if ($agents->isEmpty()) {
            return 0;
        }

        $rattachement = new Rattachement($this->entrepriseId);
        $deplacees = 0;

        DB::transaction(function () use ($agents, $rattachement, &$deplacees) {
            DossierVehicule::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('rattachement_presume', true)
                ->whereIn('code_agent', $agents->keys())
                ->select(['id', 'code_agent', 'ville_id', 'site_id', 'source_rattachement', 'rattachement_presume'])
                ->chunkById(500, function (Collection $fiches) use ($rattachement, &$deplacees) {
                    foreach ($fiches as $fiche) {
                        // On repasse par le service plutôt que d'écrire la ville à la main :
                        // c'est lui qui connaît la règle du site unique et celle du code
                        // dont l'atelier appartient à une autre ville.
                        $ou = $rattachement->resoudre(null, 'FR-'.$fiche->code_agent.'N');

                        if ($ou['ville_id'] === null) {
                            continue;
                        }

                        $fiche->forceFill([
                            'ville_id' => $ou['ville_id'],
                            'site_id' => $ou['site_id'],
                            'source_rattachement' => $ou['source'],
                            'rattachement_presume' => $ou['presumee'],
                        ])->save();

                        $deplacees++;
                    }
                });
        });

        // Les rencontres comptées pendant la reprise ne sont pas des rencontres de fichier :
        // les écrire fausserait le compteur qui sert à trier les codes à nommer.
        return $deplacees;
    }
}
