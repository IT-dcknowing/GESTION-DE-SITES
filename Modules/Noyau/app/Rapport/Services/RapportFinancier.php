<?php

namespace Modules\Noyau\Rapport\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\ChargesDeLaPeriode;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Exploitation\Services\TresorerieReelle;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Support\EmpreinteDePiece;

/**
 * Le rapport financier de l'entreprise — l'Excel « Rapport financier », vivant — 06/11.
 *
 * **Rien n'est recalculé à part.** Chaque chiffre vient du service que l'écran correspondant lit déjà :
 * le CA de `Facture::duChiffreDAffaires`, l'encours de `EtatDesImpayes`, les charges de
 * `ChargesDeLaPeriode` (caisse administrative + saisies + suivi + FNE), la trésorerie de
 * `TresorerieReelle` et des relevés. Le rapport ne fait que les ranger et les rapprocher : il bouge
 * donc tout seul avec les données, et ne peut pas contredire une page.
 *
 * **L'exercice** est celui de l'année en cours, du 1er janvier à aujourd'hui ; un exercice passé va
 * jusqu'au 31 décembre. La page « Année précédente » rappelle les grandes lignes de N-1.
 *
 * Ce que le logiciel ne sait pas encore — l'avis des impôts, la CNPS, les salaires à payer — vient du
 * dépôt de fichiers (`PieceRapport`), sans doublon avec ce que l'application compte déjà.
 */
final class RapportFinancier
{
    private array $memo = [];

    /** @param  array<int, int>  $idsSites */
    public function __construct(
        private readonly int $entrepriseId,
        private readonly array $idsSites,
        private readonly array $idsVilles,
        private readonly int $exercice,
    ) {}

    public static function pour(User $compte, ?int $exercice = null): self
    {
        $ids = PerimetreSites::idsRetenus($compte, null, null);

        return new self((int) $compte->entreprise_id, $ids, EtatDesImpayes::villesDesSites($ids), $exercice ?: (int) now()->year);
    }

    public function exercice(): int
    {
        return $this->exercice;
    }

    public function debut(): Carbon
    {
        return Carbon::create($this->exercice, 1, 1)->startOfDay();
    }

    public function fin(): Carbon
    {
        return $this->exercice >= (int) now()->year ? Carbon::today()->endOfDay() : Carbon::create($this->exercice, 12, 31)->endOfDay();
    }

    /** @template T @param  callable(): T  $calcul @return T */
    private function une(string $cle, callable $calcul): mixed
    {
        return $this->memo[$cle] ??= $calcul();
    }

    private const MOIS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    // ───────────────────────────────────────────────────────────── CA

    /** @return array<string, mixed> */
    public function chiffreDAffaires(): array
    {
        return $this->une('ca', function () {
            $lignes = EtatDesImpayes::dansLePerimetre(Facture::query()->duChiffreDAffaires(), $this->idsSites, $this->idsVilles)
                ->whereBetween('factures.date', [$this->debut(), $this->fin()])
                ->toBase()
                ->get(['factures.date', 'factures.montant', 'factures.activite', 'factures.ville_id']);

            $villes = Ville::withoutGlobalScopes()->pluck('nom', 'id');
            $total = montant_de($lignes->sum('montant'));
            $nonRattache = $lignes->whereNull('ville_id');

            $parVille = $lignes->whereNotNull('ville_id')->groupBy('ville_id')->map(fn ($g, $id) => [
                'ville' => (string) ($villes[$id] ?? 'Ville '.$id), 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant')),
            ])->sortByDesc('montant')->values()->all();

            $parActivite = $lignes->groupBy(fn ($l) => $l->activite ?: 'Autres')->map(fn ($g, $a) => [
                'activite' => (string) $a, 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant')),
            ])->sortByDesc('montant')->values()->all();

            $parMois = [];
            $moisCourant = $this->exercice >= (int) now()->year ? (int) now()->month : 12;
            for ($m = 1; $m <= $moisCourant; $m++) {
                $g = $lignes->filter(fn ($l) => (int) substr((string) $l->date, 5, 2) === $m);
                $parMois[] = [
                    'libelle' => self::MOIS[$m - 1], 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant')),
                    'complet' => $this->exercice < (int) now()->year || $m < $moisCourant,
                ];
            }

            $pleins = collect($parMois)->where('complet', true);

            // La matrice ville × activité : ce que le classeur appelle « CA par ville et par activité ».
            $matrice = $lignes->groupBy(fn ($l) => $l->ville_id === null ? 'Non rattaché' : (string) ($villes[$l->ville_id] ?? $l->ville_id))
                ->map(fn ($g) => $g->groupBy(fn ($l) => $l->activite ?: 'Autres')->map(fn ($x) => montant_de($x->sum('montant')))->all())->all();

            return [
                'total' => $total,
                'nombre' => $lignes->count(),
                'moyenneMensuelle' => $pleins->isNotEmpty() ? montant_de($pleins->avg('montant')) : 0,
                'parVille' => $parVille,
                'parActivite' => $parActivite,
                'parMois' => $parMois,
                'matrice' => $matrice,
                // Les factures dont le code éditeur a sauté : la ville est inconnue, le total les garde.
                'nonRattache' => ['nombre' => $nonRattache->count(), 'montant' => montant_de($nonRattache->sum('montant'))],
            ];
        });
    }

    // ─────────────────────────────────────────────────────── recouvrement

    /** @return array<string, mixed> */
    public function recouvrement(): array
    {
        return $this->une('rec', function () {
            $lignes = EtatDesImpayes::dansLePerimetre(EtatDesImpayes::requete($this->exercice), $this->idsSites, $this->idsVilles)
                ->withSum('encaissements', 'montant')
                ->withMax('encaissements', 'date')
                ->get(['factures.id', 'factures.client', 'factures.date', 'factures.montant', 'factures.exercice_impayes']);

            $aujourdhui = Carbon::today();
            $tranches = ['0-30 j' => 0, '31-90 j' => 0, '91-180 j' => 0, '181-365 j' => 0, '> 1 an' => 0];
            $parExercice = [];
            $clients = [];
            $ouvertes = 0;
            $reste = 0;

            foreach ($lignes as $l) {
                $du = max(montant_de($l->montant) - montant_de($l->encaissements_sum_montant ?? 0), 0);

                if ($du > 0) {
                    $reste += $du;
                    $jours = $l->date ? (int) $l->date->diffInDays($aujourdhui, false) : 0;
                    $tranches[match (true) { $jours <= 30 => '0-30 j', $jours <= 90 => '31-90 j', $jours <= 180 => '91-180 j', $jours <= 365 => '181-365 j', default => '> 1 an' }] += $du;
                    $parExercice[$l->exercice_impayes] = ($parExercice[$l->exercice_impayes] ?? 0) + $du;
                    $ouvertes += $du >= Recouvrement::SEUIL_SOLDE ? 1 : 0;
                }

                $cle = EmpreinteDePiece::texte($l->client) ?: 'SANSNOM';
                $c = $clients[$cle] ??= ['client' => trim((string) $l->client) ?: 'Client non renseigné', 'reste' => 0, 'resteExercice' => 0, 'nb' => 0, 'plusAncienne' => null, 'dernierReglement' => null];

                if ($du >= Recouvrement::SEUIL_SOLDE) {
                    $c['reste'] += $du;
                    $c['resteExercice'] += (int) $l->exercice_impayes === $this->exercice ? $du : 0;
                    $c['nb']++;
                    $c['plusAncienne'] = $c['plusAncienne'] === null || ($l->date && $l->date->lt($c['plusAncienne'])) ? $l->date : $c['plusAncienne'];
                }

                $dernier = $l->encaissements_max_date ? Carbon::parse($l->encaissements_max_date) : null;
                if ($dernier && $dernier->lte($aujourdhui) && ($c['dernierReglement'] === null || $dernier->gt($c['dernierReglement']))) {
                    $c['dernierReglement'] = $dernier;
                }

                $clients[$cle] = $c;
            }

            $debiteurs = collect($clients)->filter(fn ($c) => $c['reste'] > 0)->sortByDesc('reste')->values();
            $top10 = $debiteurs->take(10)->values();
            $autres = $debiteurs->slice(10);

            return [
                'reste' => montant_de($reste),
                'ouvertes' => $ouvertes,
                'nombreClients' => $debiteurs->count(),
                'top10' => $top10,
                'autres' => ['nombreClients' => $autres->count(), 'reste' => montant_de($autres->sum('reste')), 'resteExercice' => montant_de($autres->sum('resteExercice'))],
                'resteExercice' => montant_de($debiteurs->sum('resteExercice')),
                'anciennete' => array_map('montant_de', $tranches),
                'parExercice' => array_map('montant_de', $parExercice),
            ];
        });
    }

    // ─────────────────────────────────────────────────────────── charges

    private function charges_(): ChargesDeLaPeriode
    {
        return $this->une('cp', fn () => ChargesDeLaPeriode::pourLesSites($this->idsSites, $this->debut(), $this->fin()));
    }

    /** @return Collection<int, \Modules\Noyau\Rapport\Modeles\PieceRapport> */
    private function pieces(): Collection
    {
        return $this->une('pieces', fn () => PieceRapport::query()->where('exercice', $this->exercice)->where('compte', true)->get());
    }

    /** @return array<string, mixed> */
    public function charges(): array
    {
        return $this->une('charges', function () {
            $cp = $this->charges_();
            $t = $cp->totaux();
            $lignes = $cp->lignes();
            $cf = $cp->fournisseurs();
            $aujourdhui = Carbon::today();

            // ── le suivi fournisseurs, rangé : pièces, consommables, autres
            $suivi = $cf->suivi()->get(['fournisseur', 'imputation', 'montant', 'montant_regle', 'reste_a_payer', 'date_facture']);
            $famille = fn (?string $i) => match (true) {
                (bool) preg_match('/pi[èe]ce|achat de pi/iu', (string) $i) => 'pieces',
                (bool) preg_match('/peinture|lubrifiant|consommable/iu', (string) $i) => 'consommables',
                default => 'autres',
            };
            $fam = ['pieces' => [0, 0, 0], 'consommables' => [0, 0, 0], 'autres' => [0, 0, 0]];
            $parFournisseur = [];
            $age = ['0-30 j' => 0, '31-60 j' => 0, '61-90 j' => 0, '> 90 j' => 0];

            foreach ($suivi as $s) {
                $reste = max(montant_de($s->reste_a_payer), 0);
                $f = $famille($s->imputation);
                $fam[$f][0] += montant_de($s->montant);
                $fam[$f][1] += montant_de($s->montant_regle);
                $fam[$f][2] += $reste;

                $nom = trim((string) $s->fournisseur) ?: 'Fournisseur non renseigné';
                $k = EmpreinteDePiece::texte($nom);
                $parFournisseur[$k] ??= ['fournisseur' => $nom, 'facture' => 0, 'regle' => 0, 'reste' => 0, 'famille' => $f];
                $parFournisseur[$k]['facture'] += montant_de($s->montant);
                $parFournisseur[$k]['regle'] += montant_de($s->montant_regle);
                $parFournisseur[$k]['reste'] += $reste;

                if ($reste > 0 && $s->date_facture) {
                    $j = Carbon::parse($s->date_facture)->diffInDays($aujourdhui, false);
                    $age[match (true) { $j <= 30 => '0-30 j', $j <= 60 => '31-60 j', $j <= 90 => '61-90 j', default => '> 90 j' }] += $reste;
                }
            }

            // ── les FNE pas encore au suivi : comptées, dues tant qu'on ne les a pas marquées « réglées »
            $fne = $cp->fneSeules()?->get(['emetteur_nom', 'montant_ttc', 'date_facture']) ?? collect();

            foreach ($fne as $f) {
                $nom = trim((string) $f->emetteur_nom) ?: 'Fournisseur FNE';
                $k = EmpreinteDePiece::texte($nom);
                $m = montant_de($f->montant_ttc);
                $parFournisseur[$k] ??= ['fournisseur' => $nom, 'facture' => 0, 'regle' => 0, 'reste' => 0, 'famille' => 'fne'];
                $parFournisseur[$k]['facture'] += $m;
                $parFournisseur[$k]['reste'] += max($m, 0);

                if ($m > 0 && $f->date_facture) {
                    $j = Carbon::parse($f->date_facture)->diffInDays($aujourdhui, false);
                    $age[match (true) { $j <= 30 => '0-30 j', $j <= 60 => '31-60 j', $j <= 90 => '61-90 j', default => '> 90 j' }] += $m;
                }
            }

            $fneTotal = $t['fne'];
            $fourn = [
                'facture' => montant_de($fam['pieces'][0] + $fam['consommables'][0] + $fam['autres'][0] + $fneTotal),
                'regle' => montant_de($fam['pieces'][1] + $fam['consommables'][1] + $fam['autres'][1]),
                'reste' => montant_de($fam['pieces'][2] + $fam['consommables'][2] + $fam['autres'][2] + $fne->sum(fn ($f) => max(montant_de($f->montant_ttc), 0))),
                'familles' => collect($fam)->map(fn ($v) => ['facture' => montant_de($v[0]), 'regle' => montant_de($v[1]), 'reste' => montant_de($v[2])])->all(),
                'fne' => ['facture' => $fneTotal, 'nombre' => $t['nFne']],
                'top' => collect($parFournisseur)->sortByDesc('reste')->take(10)->values()->all(),
                'anciennete' => array_map('montant_de', $age),
            ];

            // ── ce que dit la caisse administrative et les charges saisies, par nature et par catégorie
            $directes = $lignes->whereIn('source', ['caisse', 'saisie']);
            $natures = $directes->groupBy('nature')->map(fn ($g) => montant_de($g->sum('montant')))->all();
            $categories = $directes->groupBy('categorie')->map(fn ($g, $c) => ['categorie' => (string) $c, 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant'))])
                ->sortByDesc('montant')->take(12)->values()->all();

            // ── ce que le dépôt apporte : impôts, CNPS, salaires — sans doublon avec la caisse
            $pieces = $this->pieces();
            $somme = fn (string $nature, string $statut) => (int) $pieces->where('nature', $nature)->where('statut', $statut)->sum('montant');
            $impots = ['aRegler' => $somme('impot', 'du'), 'paye' => $somme('impot', 'paye'), 'depose' => $pieces->whereIn('nature', ['impot', 'cnps'])->isNotEmpty(),
                'pieces' => $pieces->where('nature', 'impot')->values()];
            $cnps = ['aRegler' => $somme('cnps', 'du'), 'paye' => $somme('cnps', 'paye'), 'pieces' => $pieces->where('nature', 'cnps')->values()];
            $salaires = [
                'paye' => montant_de(($natures['Salaires & personnel'] ?? 0) + $somme('salaire', 'paye')),
                'nonRegle' => $somme('salaire', 'du'),
            ];

            // ── le classement : où l'on dépense le plus
            $classement = collect([
                'Fournisseurs — pièces de rechange' => $fam['pieces'][0],
                'Fournisseurs — consommables (peinture, lubrifiants)' => $fam['consommables'][0],
                'Autres fournisseurs (frais généraux, loyer, sous-traitance…)' => $fam['autres'][0],
                'Factures FNE (comptées directement)' => $fneTotal,
                'Salaires & personnel' => $natures['Salaires & personnel'] ?? 0,
                'Fonctionnement' => $natures['Fonctionnement'] ?? 0,
                'Autres décaissements' => ($natures['Autres décaissements'] ?? 0) + ($natures['Achats pièces'] ?? 0),
                'Impôts & CNPS payés (pièces déposées)' => $impots['paye'] + $cnps['paye'],
                'Salaires payés (pièces déposées)' => $somme('salaire', 'paye'),
            ])->filter(fn ($m) => $m > 0)->map(fn ($m, $p) => ['poste' => $p, 'montant' => montant_de($m)])->sortByDesc('montant')->values()->all();

            return [
                'totalCompte' => $t['total'],
                'totaux' => $t,
                'fournisseurs' => $fourn,
                'natures' => $natures,
                'categories' => $categories,
                'impots' => $impots,
                'cnps' => $cnps,
                'salaires' => $salaires,
                'classement' => $classement,
                // Ce que le dépôt ajoute aux charges comptées (les paiements que la caisse n'a pas déjà comptés).
                'totalRapport' => montant_de($t['total'] + $impots['paye'] + $cnps['paye'] + $somme('salaire', 'paye')),
            ];
        });
    }

    // ─────────────────────────────────────────────────────────── trésorerie

    /** @return array<string, mixed> */
    public function tresorerie(): array
    {
        return $this->une('treso', function () {
            $t = TresorerieReelle::pourLesSites($this->idsSites, $this->debut(), $this->fin())->totaux();

            $banques = [];
            foreach (Banque::query()->orderBy('nom')->get(['id', 'nom']) as $b) {
                $avant = MouvementBancaire::query()->where('banque_id', $b->id)->where('date_operation', '<', $this->debut())
                    ->orderByDesc('date_operation')->orderByDesc('rang')->first(['solde_annonce']);
                $apres = MouvementBancaire::query()->where('banque_id', $b->id)->where('date_operation', '<=', $this->fin())
                    ->orderByDesc('date_operation')->orderByDesc('rang')->first(['solde_annonce', 'date_operation']);

                if ($apres === null && $avant === null) {
                    continue;
                }

                $banques[] = [
                    'banque' => $b->nom,
                    'depart' => $avant !== null ? montant_de($avant->solde_annonce) : null,
                    'actuel' => $apres !== null ? montant_de($apres->solde_annonce) : null,
                    'au' => $apres?->date_operation ? Carbon::parse($apres->date_operation) : null,
                ];
            }

            return ['entrees' => $t['entrees'], 'sorties' => $t['sorties'], 'net' => $t['net'], 'detail' => $t['detail'] ?? [], 'banques' => $banques];
        });
    }

    // ───────────────────────────────────────────────────────── commerciaux

    /** @return list<array<string, mixed>> */
    public function commerciaux(): array
    {
        return $this->une('commerciaux', function () {
            $debut = $this->debut();
            $fin = $this->fin();

            $commerciaux = Commercial::actifs()->where('est_spontane', false)->whereIn('ville_id', $this->idsVilles)->with('ville')->get();

            // L'encours de chacun : le reste à encaisser de ses factures, par l'état des impayés.
            $encours = EtatDesImpayes::dansLePerimetre(EtatDesImpayes::requete($this->exercice), $this->idsSites, $this->idsVilles)
                ->whereNotNull('factures.commercial_id')
                ->withSum('encaissements', 'montant')
                ->get(['factures.id', 'factures.commercial_id', 'factures.montant'])
                ->groupBy('commercial_id')
                ->map(fn ($g) => montant_de($g->sum(fn ($l) => max(montant_de($l->montant) - montant_de($l->encaissements_sum_montant ?? 0), 0))));

            return $commerciaux->map(function (Commercial $c) use ($debut, $fin, $encours) {
                $lignes = Facture::query()->where('commercial_id', $c->id)->whereIn('site_id', $this->idsSites)
                    ->whereBetween('date', [$debut, $fin])->toBase()->get(['montant', 'activite']);
                $realisation = montant_de($lignes->sum('montant'));
                $objectif = (int) round(PeriodeCalculateur::objectifProrata((float) $c->objectif_mensuel, $debut, $fin));

                return [
                    'commercial' => $c->nom,
                    'ville' => $c->ville?->nom,
                    'factures' => $lignes->count(),
                    'realisation' => $realisation,
                    'mecanique' => montant_de($lignes->where('activite', 'Mécanique')->sum('montant')),
                    'sinistre' => montant_de($lignes->where('activite', 'Sinistre')->sum('montant')),
                    'objectif' => $objectif,
                    'taux' => $objectif > 0 ? $realisation / $objectif : null,
                    'encours' => (float) ($encours[$c->id] ?? 0),
                ];
            })->sortByDesc(fn ($l) => $l['realisation'])->values()->all();
        });
    }

    // ───────────────────────────────────────────────────────── conclusion

    /** @return array<string, mixed> */
    public function conclusion(): array
    {
        return $this->une('conclusion', function () {
            $ch = $this->charges();
            $rec = $this->recouvrement();

            $lignes = [
                ['poste' => 'Impôts', 'montant' => $ch['impots']['aRegler'], 'delai' => $ch['impots']['aRegler'] > 0 ? 'À régler dès réception de l\'avis (pièces déposées)' : '—'],
                ['poste' => 'CNPS', 'montant' => $ch['cnps']['aRegler'], 'delai' => $ch['cnps']['aRegler'] > 0 ? 'Selon l\'appel de cotisation déposé' : 'Aucune pièce déposée'],
                ['poste' => 'Salaires non réglés', 'montant' => $ch['salaires']['nonRegle'], 'delai' => $ch['salaires']['nonRegle'] > 0 ? 'Immédiat' : '—'],
                ['poste' => 'Fournisseurs — pièces et consommables', 'montant' => montant_de($ch['fournisseurs']['familles']['pieces']['reste'] + $ch['fournisseurs']['familles']['consommables']['reste']), 'delai' => $this->delaiFournisseurs($ch['fournisseurs']['anciennete'])],
                ['poste' => 'Autres fournisseurs et factures FNE', 'montant' => montant_de($ch['fournisseurs']['familles']['autres']['reste'] + max($ch['fournisseurs']['reste'] - $ch['fournisseurs']['familles']['pieces']['reste'] - $ch['fournisseurs']['familles']['consommables']['reste'] - $ch['fournisseurs']['familles']['autres']['reste'], 0)), 'delai' => 'Selon échéances'],
            ];

            $engagements = montant_de(collect($lignes)->sum('montant'));

            // Le rythme d'encaissement : les 28 derniers jours, ramenés à la semaine.
            $quatreSemaines = montant_de(Encaissement::query()->whereBetween('date', [Carbon::today()->subDays(28), Carbon::today()->endOfDay()])->sum('montant'));
            $hebdo = $quatreSemaines / 4;

            return [
                'lignes' => $lignes,
                'engagements' => $engagements,
                'recouvrementNecessaire' => $rec['reste'] > 0 ? $engagements / $rec['reste'] : null,
                'recouvrementNecessaire26' => $rec['resteExercice'] > 0 ? $engagements / $rec['resteExercice'] : null,
                'encaissementHebdo' => $hebdo,
                'semaines' => $hebdo > 0 ? $engagements / $hebdo : null,
            ];
        });
    }

    /** @param  array<string, int|float>  $age */
    private function delaiFournisseurs(array $age): string
    {
        $total = array_sum($age);

        return $total > 0
            ? sprintf('%s %% de la dette a plus de 90 jours', number_format(($age['> 90 j'] ?? 0) / $total * 100, 0, ',', ' '))
            : '—';
    }

    // ────────────────────────────────────────────────── année précédente

    /**
     * Les grandes lignes d'un exercice — pour la page « Année précédente » : CA, charges comptées,
     * résultat, encours restant, encaissements.
     *
     * @return array<string, mixed>
     */
    public function grandesLignes(): array
    {
        $ca = $this->chiffreDAffaires();
        $charges = $this->charges_()->totaux();
        $encaisse = montant_de(Encaissement::query()->whereBetween('date', [$this->debut(), $this->fin()])->sum('montant'));
        $rec = $this->recouvrement();

        return [
            'exercice' => $this->exercice,
            'ca' => $ca['total'],
            'nombreFactures' => $ca['nombre'],
            'parVille' => $ca['parVille'],
            'parActivite' => $ca['parActivite'],
            'charges' => $charges['total'],
            'resultat' => montant_de($ca['total'] - $charges['total']),
            'encaisse' => $encaisse,
            'encours' => $rec['reste'],
        ];
    }

    /** @return array<string, mixed> */
    public function anneePrecedente(): array
    {
        $avant = new self($this->entrepriseId, $this->idsSites, $this->idsVilles, $this->exercice - 1);
        $actuel = $this->grandesLignes();
        $passe = $avant->grandesLignes();

        $variation = fn (string $cle) => $passe[$cle] > 0 ? ($actuel[$cle] - $passe[$cle]) / $passe[$cle] : null;

        return [
            'passe' => $passe,
            'actuel' => $actuel,
            'variations' => ['ca' => $variation('ca'), 'charges' => $variation('charges'), 'resultat' => null, 'encaisse' => $variation('encaisse')],
        ];
    }

    // ───────────────────────────────────────────────────────────── tout

    /** @return array<string, mixed> */
    public function tout(): array
    {
        return [
            'exercice' => $this->exercice,
            'debut' => $this->debut(),
            'fin' => $this->fin(),
            'ca' => $this->chiffreDAffaires(),
            'recouvrement' => $this->recouvrement(),
            'charges' => $this->charges(),
            'tresorerie' => $this->tresorerie(),
            'conclusion' => $this->conclusion(),
        ];
    }

    /** Les seuls chiffres agrégés qui partent vers l'IA — jamais un nom de client, de salarié ou de pièce. */
    public function resumePourIA(): array
    {
        $r = $this->tout();

        return [
            'periode' => $r['debut']->format('d/m/Y').' au '.$r['fin']->format('d/m/Y'),
            'chiffre_affaires' => $r['ca']['total'],
            'ca_par_ville' => collect($r['ca']['parVille'])->pluck('montant', 'ville')->all(),
            'ca_par_activite' => collect($r['ca']['parActivite'])->pluck('montant', 'activite')->all(),
            'ca_sans_ville' => $r['ca']['nonRattache'],
            'impayes_total' => $r['recouvrement']['reste'],
            'impayes_anciennete' => $r['recouvrement']['anciennete'],
            'part_des_10_premiers_debiteurs' => $r['recouvrement']['reste'] > 0 ? $r['recouvrement']['top10']->sum('reste') / $r['recouvrement']['reste'] : null,
            'charges_comptees' => $r['charges']['totalCompte'],
            'classement_charges' => $r['charges']['classement'],
            'fournisseurs' => ['facture' => $r['charges']['fournisseurs']['facture'], 'regle' => $r['charges']['fournisseurs']['regle'], 'reste' => $r['charges']['fournisseurs']['reste'], 'anciennete' => $r['charges']['fournisseurs']['anciennete']],
            'impots_a_regler' => $r['charges']['impots']['aRegler'],
            'cnps_a_regler' => $r['charges']['cnps']['aRegler'],
            'salaires_non_regles' => $r['charges']['salaires']['nonRegle'],
            'tresorerie_nette_periode' => $r['tresorerie']['net'],
            'engagements' => $r['conclusion']['engagements'],
            'encaissement_hebdomadaire' => $r['conclusion']['encaissementHebdo'],
        ];
    }
}
