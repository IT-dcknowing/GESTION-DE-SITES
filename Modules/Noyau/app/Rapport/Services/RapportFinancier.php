<?php

namespace Modules\Noyau\Rapport\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Services\ChargesDeLaPeriode;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Exploitation\Services\TransformationDesDevis;
use Modules\Noyau\Exploitation\Services\TresorerieReelle;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Support\EmpreinteDePiece;
use Modules\Noyau\Rapport\Support\PeriodeDuRapport;

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
 * **La période et le lieu (25/11).** « Une semaine du jeudi au mercredi, un mois ou des dates libres ;
 * la ville, l'atelier, l'activité. » Le rapport reçoit une `PeriodeDuRapport` et un lieu vérifiés
 * par `pour()` : le CA, le recouvrement, les charges, les commerciaux et la conclusion les lisent
 * tous. Sans critère, il lit l'exercice entier sur tout le périmètre du compte, comme avant.
 *
 * Ce que le logiciel ne sait pas encore — l'avis des impôts, la CNPS, les salaires à payer — vient du
 * dépôt de fichiers (`PieceRapport`), sans doublon avec ce que l'application compte déjà.
 */
final class RapportFinancier
{
    /** Les deux activités qu'un filtre peut isoler ; toute autre valeur reçue ne filtre rien. */
    public const ACTIVITES = ['Mécanique', 'Sinistre'];

    private array $memo = [];

    private readonly PeriodeDuRapport $periode;

    private readonly int $exercice;

    /**
     * @param  array<int, int>  $idsSites
     * @param  string  $lieu  '' (tout le périmètre), 'ville' ou 'atelier' : ce que le lecteur a isolé
     */
    public function __construct(
        private readonly int $entrepriseId,
        private readonly array $idsSites,
        private readonly array $idsVilles,
        int $exercice,
        ?PeriodeDuRapport $periode = null,
        private readonly string $activite = '',
        private readonly string $lieu = '',
    ) {
        $this->periode = $periode ?? PeriodeDuRapport::exercice($exercice);
        $this->exercice = $this->periode->exercice;
    }

    /**
     * Le rapport d'un compte, sur la période et le lieu demandés.
     *
     * **Le lieu se vérifie ici, au serveur.** Une ville ou un atelier hors du périmètre du compte est
     * **refusé** (403), jamais élargi : les identifiants viennent de l'adresse de la page, que
     * n'importe qui peut réécrire. Les ateliers retenus sortent ensuite de `PerimetreSites`,
     * c'est-à-dire de l'identité du lecteur.
     *
     * @param  array{periode?: ?string, jour?: ?string, mois?: ?string, du?: ?string, au?: ?string, ville?: int|string|null, atelier?: int|string|null, activite?: ?string}  $criteres
     */
    public static function pour(User $compte, ?int $exercice = null, array $criteres = []): self
    {
        $exercice = $exercice ?: (int) now()->year;
        $ville = (int) ($criteres['ville'] ?? 0) ?: null;
        $atelier = (int) ($criteres['atelier'] ?? 0) ?: null;

        abort_unless(self::lieuAutorise($compte, $ville, $atelier), 403, 'Ce lieu est hors de votre périmètre.');

        $ids = PerimetreSites::idsRetenus($compte, $ville ? (string) $ville : null, $atelier ? (string) $atelier : null);
        $periode = PeriodeDuRapport::lire((string) ($criteres['periode'] ?? 'exercice'), $exercice,
            $criteres['jour'] ?? null, $criteres['mois'] ?? null, $criteres['du'] ?? null, $criteres['au'] ?? null);
        $activite = in_array($criteres['activite'] ?? '', self::ACTIVITES, true) ? $criteres['activite'] : '';

        return new self((int) $compte->entreprise_id, $ids, EtatDesImpayes::villesDesSites($ids), $exercice,
            $periode, $activite, $atelier ? 'atelier' : ($ville ? 'ville' : ''));
    }

    /** Une ville et un atelier que ce compte peut lire, l'atelier dans la ville choisie s'il y en a une. */
    public static function lieuAutorise(User $compte, ?int $ville, ?int $atelier): bool
    {
        if ($ville !== null && ! PerimetreSites::villesVisibles($compte)->contains('id', $ville)) {
            return false;
        }

        if ($atelier !== null) {
            $site = PerimetreSites::sitesConsultables($compte)->firstWhere('id', $atelier);

            return $site !== null && ($ville === null || (int) $site->ville_id === $ville);
        }

        return true;
    }

    public function exercice(): int
    {
        return $this->exercice;
    }

    public function periode(): PeriodeDuRapport
    {
        return $this->periode;
    }

    public function debut(): Carbon
    {
        return $this->periode->debut->copy();
    }

    public function fin(): Carbon
    {
        return $this->periode->fin->copy();
    }

    /**
     * Les factures du périmètre, du lieu et de l'activité choisis.
     *
     * Sur tout le périmètre, `dansLePerimetre` garde les lignes sans ville : elles sont à tout le
     * monde, les cacher serait les perdre. Mais une ville ou un atelier choisi ne montre que ce qui
     * y a eu lieu : une facture dont on ignore la ville n'est pas une facture de Bouaké. Sous un
     * atelier, ses seules factures ; sous une ville, ses ateliers et ses lignes sans atelier.
     *
     * @param  Builder<Facture>  $requete
     * @return Builder<Facture>
     */
    private function dansLeLieu(Builder $requete): Builder
    {
        return EtatDesImpayes::dansLePerimetre($requete, $this->idsSites, $this->idsVilles)
            ->when($this->lieu === 'atelier', fn ($q) => $q->whereIn('factures.site_id', $this->idsSites))
            ->when($this->lieu === 'ville', fn ($q) => $q->where(fn ($l) => $l
                ->whereIn('factures.site_id', $this->idsSites)
                ->orWhere(fn ($s) => $s->whereNull('factures.site_id')->whereIn('factures.ville_id', $this->idsVilles))))
            ->when($this->activite !== '', fn ($q) => $q->where('factures.activite', $this->activite));
    }

    /**
     * Les impayés que lit le rapport : l'état de l'exercice ; sous une semaine, un mois ou des
     * dates, ceux des factures émises dans la période. C'est la question « ce qu'on a facturé
     * cette semaine, qu'en reste-t-il à encaisser ? ».
     *
     * @return Builder<Facture>
     */
    private function impayes(): Builder
    {
        return $this->dansLeLieu(EtatDesImpayes::requete($this->exercice))
            ->when(! $this->periode->estLExercice(), fn ($q) => $q->whereBetween('factures.date', [$this->debut(), $this->fin()]));
    }

    /**
     * Range des lignes datées dans des tranches (semaines, mois) : nombre et montant de chacune.
     * Les dates se comparent en `Y-m-d` : une ligne datée à 14 h le mercredi reste dans sa semaine.
     *
     * @param  list<array{debut: Carbon, fin: Carbon}>  $tranches
     * @return list<array<string, mixed>>
     */
    private static function ventiler(Collection $lignes, array $tranches, string $champDate, string $champMontant): array
    {
        $bornes = array_map(fn ($t) => [$t['debut']->format('Y-m-d'), $t['fin']->format('Y-m-d')], $tranches);
        $sommes = array_fill(0, count($tranches), ['nombre' => 0, 'montant' => 0]);

        foreach ($lignes as $l) {
            $jour = substr((string) data_get($l, $champDate), 0, 10);

            foreach ($bornes as $i => [$de, $a]) {
                if ($jour >= $de && $jour <= $a) {
                    $sommes[$i]['nombre']++;
                    $sommes[$i]['montant'] += montant_de(data_get($l, $champMontant));
                    break;
                }
            }
        }

        return array_map(fn ($t, $s) => ['nombre' => $s['nombre'], 'montant' => montant_de($s['montant'])] + $t, $tranches, $sommes);
    }

    /** @template T @param  callable(): T  $calcul @return T */
    private function une(string $cle, callable $calcul): mixed
    {
        return $this->memo[$cle] ??= $calcul();
    }

    // ───────────────────────────────────────────────────────────── CA

    /** @return array<string, mixed> */
    public function chiffreDAffaires(): array
    {
        return $this->une('ca', function () {
            $lignes = $this->dansLeLieu(Facture::query()->duChiffreDAffaires())
                ->whereBetween('factures.date', [$this->debut(), $this->fin()])
                ->toBase()
                ->get(['factures.date', 'factures.montant', 'factures.activite', 'factures.ville_id', 'factures.client', 'factures.numero', 'factures.reference_devis']);

            $villes = Ville::withoutGlobalScopes()->pluck('nom', 'id');
            $total = montant_de($lignes->sum('montant'));
            $nonRattache = $lignes->whereNull('ville_id');

            $parVille = $lignes->whereNotNull('ville_id')->groupBy('ville_id')->map(fn ($g, $id) => [
                'ville' => (string) ($villes[$id] ?? 'Ville '.$id), 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant')),
            ])->sortByDesc('montant')->values()->all();

            $parActivite = $lignes->groupBy(fn ($l) => $l->activite ?: 'Autres')->map(fn ($g, $a) => [
                'activite' => (string) $a, 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant')),
            ])->sortByDesc('montant')->values()->all();

            // Le détail par mois et par semaine (jeudi au mercredi) de la période choisie (25/11).
            $parMois = self::ventiler($lignes, $this->periode->mois(), 'date', 'montant');
            $parSemaine = self::ventiler($lignes, $this->periode->semaines(), 'date', 'montant');

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
                'parSemaine' => $parSemaine,
                'matrice' => $matrice,
                // Les factures dont le code éditeur a sauté : la ville est inconnue, le total les garde.
                'nonRattache' => ['nombre' => $nonRattache->count(), 'montant' => montant_de($nonRattache->sum('montant'))],
                // Leur liste, une par une, comme la section « Factures sans code éditeur » de l'Excel.
                'nonRattacheLignes' => $nonRattache->sortBy('date')->take(200)->map(fn ($l) => [
                    'numero' => (string) $l->numero, 'date' => $l->date, 'client' => (string) $l->client,
                    'activite' => (string) $l->activite, 'montant' => montant_de($l->montant), 'fiche' => (string) $l->reference_devis,
                ])->values()->all(),
            ];
        });
    }

    /**
     * Le CA par client — pour l'assistant : « qui nous a le plus fait travailler ? ».
     *
     * @return list<array{client: string, nombre: int, montant: int|float}>
     */
    public function caParClient(int $limite = 15): array
    {
        $lignes = $this->dansLeLieu(Facture::query()->duChiffreDAffaires())
            ->whereBetween('factures.date', [$this->debut(), $this->fin()])
            ->toBase()->get(['factures.client', 'factures.montant']);

        return $lignes->groupBy(fn ($l) => EmpreinteDePiece::texte($l->client) ?: 'SANSNOM')
            ->map(fn ($g) => ['client' => trim((string) $g->first()->client) ?: 'Client non renseigné', 'nombre' => $g->count(), 'montant' => montant_de($g->sum('montant'))])
            ->sortByDesc('montant')->take($limite)->values()->all();
    }

    /**
     * Les factures impayées une à une, les plus grosses d'abord — pour l'assistant.
     *
     * @return list<array<string, mixed>>
     */
    public function impayesParFacture(?string $client = null, int $limite = 25): array
    {
        $requete = $this->impayes()
            ->when($client !== null && trim($client) !== '', fn ($q) => $q->where('factures.client', 'like', '%'.trim($client).'%'))
            ->avecEncaisseEtAvoirs()->withMax('encaissements', 'date');

        return $requete->get(['factures.id', 'factures.numero', 'factures.client', 'factures.date', 'factures.montant', 'factures.exercice_impayes'])
            ->map(fn ($l) => [
                'numero' => (string) $l->numero, 'client' => (string) $l->client, 'date' => $l->date?->format('Y-m-d'),
                'montant' => montant_de($l->montant), 'regle' => montant_de($l->encaissements_sum_montant ?? 0),
                'reste' => Recouvrement::reste($l),
                'dernier_reglement' => $l->encaissements_max_date,
            ])->filter(fn ($l) => $l['reste'] >= Recouvrement::SEUIL_SOLDE)->sortByDesc('reste')->take($limite)->values()->all();
    }

    /** Les lignes de charges comptées (caisse, saisies, suivi, FNE), les plus grosses d'abord. @return list<array<string, mixed>> */
    public function lignesDeCharges(?string $source = null, ?string $nature = null, int $limite = 25): array
    {
        return $this->charges_()->lignes()
            ->when($source, fn ($c) => $c->where('source', $source))
            ->when($nature, fn ($c) => $c->filter(fn ($l) => mb_stripos((string) $l->nature.' '.(string) ($l->categorie ?? ''), $nature) !== false))
            ->sortByDesc('montant')->take($limite)
            ->map(fn ($l) => ['date' => (string) $l->date, 'montant' => $l->montant, 'nature' => (string) $l->nature, 'categorie' => (string) ($l->categorie ?? ''), 'source' => (string) $l->source])
            ->values()->all();
    }

    // ─────────────────────────────────────────────────────── recouvrement

    /** @return array<string, mixed> */
    public function recouvrement(): array
    {
        return $this->une('rec', function () {
            $lignes = $this->impayes()
                ->avecEncaisseEtAvoirs()
                ->withMax('encaissements', 'date')
                ->get(['factures.id', 'factures.client', 'factures.date', 'factures.montant', 'factures.exercice_impayes']);

            $aujourdhui = Carbon::today();
            $tranches = ['0-30 j' => 0, '31-90 j' => 0, '91-180 j' => 0, '181-365 j' => 0, '> 1 an' => 0];
            $parExercice = [];
            $clients = [];
            $ouvertes = 0;
            $reste = 0;

            foreach ($lignes as $l) {
                $du = Recouvrement::reste($l);

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
        return $this->une('cp', fn () => ChargesDeLaPeriode::pourLesSites($this->idsSites, $this->debut(), $this->fin(), $this->activite));
    }

    /**
     * Les pièces déposées qui comptent : celles de l'exercice ; sous une semaine, un mois ou des
     * dates, celles **datées** dans la période. Une pièce sans date ne se place dans aucune semaine :
     * elle reste comptée sur l'exercice, et `piecesSansDate()` dit combien la période en laisse.
     *
     * Les impôts et la CNPS sont ceux de l'entreprise : une pièce ne porte ni ville ni activité, et
     * le filtre de lieu ne les partage donc pas.
     *
     * @return Collection<int, \Modules\Noyau\Rapport\Modeles\PieceRapport>
     */
    private function pieces(): Collection
    {
        return $this->une('pieces', fn () => PieceRapport::query()->where('compte', true)
            ->when($this->periode->estLExercice(),
                fn ($q) => $q->where('exercice', $this->exercice),
                fn ($q) => $q->whereBetween('date_piece', [$this->debut()->toDateString(), $this->fin()->toDateString()]))
            ->get());
    }

    /** Les pièces de l'exercice sans date, que la période ne sait pas placer. */
    public function piecesSansDate(): int
    {
        return $this->periode->estLExercice() ? 0
            : PieceRapport::query()->where('compte', true)->where('exercice', $this->exercice)->whereNull('date_piece')->count();
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
            // `$cp->suivi()` plutôt que `$cf->suivi()` : le premier écarte le suivi sous un filtre
            // d'activité, que le suivi ne porte pas (comme la page Charges).
            $suivi = $cp->suivi()->get(['fournisseur', 'imputation', 'montant', 'montant_regle', 'reste_a_payer', 'date_facture']);
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

            // ── les FNE pas encore au suivi : comptées directement, et rangées par NATURE d'achat.
            // Une facture FNE n'est pas une dépense : c'est la preuve d'une opération. On la range donc
            // dans la famille que le suivi donne déjà à son fournisseur (pièces, consommables, autres) ;
            // faute de mieux, d'après le nom de l'émetteur ; sinon dans « autres ».
            $familleDuFournisseur = [];
            foreach ($parFournisseur as $k => $ligne) {
                $familleDuFournisseur[$k] = $ligne['famille'];
            }
            $nomVersFamille = fn (string $nom) => match (true) {
                (bool) preg_match('/peinture|lubrifi|sikkens|vernis|abrasif|colle|consommable/iu', $nom) => 'consommables',
                (bool) preg_match('/pi[èe]ce|auto|motor|toyota|cfao|tractafric|distribution|pneu|batterie|accessoire|garage|vitrage|pare[- ]?brise/iu', $nom) => 'pieces',
                default => 'autres',
            };

            $fne = $cp->fneSeules()?->get(['emetteur_nom', 'montant_ttc', 'date_facture']) ?? collect();
            $fneParFamille = ['pieces' => 0, 'consommables' => 0, 'autres' => 0];

            foreach ($fne as $f) {
                $nom = trim((string) $f->emetteur_nom) ?: 'Fournisseur FNE';
                $k = EmpreinteDePiece::texte($nom);
                $m = montant_de($f->montant_ttc);
                $fa = $familleDuFournisseur[$k] ?? $nomVersFamille($nom);

                $fam[$fa][0] += $m;
                $fam[$fa][2] += max($m, 0);
                $fneParFamille[$fa] += $m;

                $parFournisseur[$k] ??= ['fournisseur' => $nom, 'facture' => 0, 'regle' => 0, 'reste' => 0, 'famille' => $fa];
                $parFournisseur[$k]['facture'] += $m;
                $parFournisseur[$k]['reste'] += max($m, 0);

                if ($m > 0 && $f->date_facture) {
                    $j = Carbon::parse($f->date_facture)->diffInDays($aujourdhui, false);
                    $age[match (true) { $j <= 30 => '0-30 j', $j <= 60 => '31-60 j', $j <= 90 => '61-90 j', default => '> 90 j' }] += $m;
                }
            }

            $fneTotal = $t['fne'];
            $fourn = [
                'facture' => montant_de($fam['pieces'][0] + $fam['consommables'][0] + $fam['autres'][0]),
                'regle' => montant_de($fam['pieces'][1] + $fam['consommables'][1] + $fam['autres'][1]),
                'reste' => montant_de($fam['pieces'][2] + $fam['consommables'][2] + $fam['autres'][2]),
                'familles' => collect($fam)->map(fn ($v) => ['facture' => montant_de($v[0]), 'regle' => montant_de($v[1]), 'reste' => montant_de($v[2])])->all(),
                // Information seulement : ces montants sont DÉJÀ dans les familles ci-dessus.
                'fne' => ['facture' => $fneTotal, 'nombre' => $t['nFne'], 'parFamille' => array_map('montant_de', $fneParFamille)],
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
            $somme = fn (string $nature, string $statut) => montant_de($pieces->where('nature', $nature)->where('statut', $statut)->sum('montant'));
            $impots = ['aRegler' => $somme('impot', 'du'), 'paye' => $somme('impot', 'paye'), 'depose' => $pieces->whereIn('nature', ['impot', 'cnps'])->isNotEmpty(),
                'pieces' => $pieces->where('nature', 'impot')->values()];
            $cnps = ['aRegler' => $somme('cnps', 'du'), 'paye' => $somme('cnps', 'paye'), 'pieces' => $pieces->where('nature', 'cnps')->values()];
            $salaires = [
                'paye' => montant_de(($natures['Salaires & personnel'] ?? 0) + $somme('salaire', 'paye')),
                'nonRegle' => $somme('salaire', 'du'),
            ];

            // ── le classement : où l'on dépense le plus
            $classement = collect([
                'Fournisseurs : pièces de rechange' => $fam['pieces'][0],
                'Fournisseurs : consommables (peinture, lubrifiants)' => $fam['consommables'][0],
                'Autres fournisseurs (frais généraux, loyer, sous-traitance…)' => $fam['autres'][0],
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
                // Les charges comptées par semaine (jeudi au mercredi) et par mois (25/11).
                'parSemaine' => self::ventiler($lignes, $this->periode->semaines(), 'date', 'montant'),
                'parMois' => self::ventiler($lignes, $this->periode->mois(), 'date', 'montant'),
                'piecesSansDate' => $this->piecesSansDate(),
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
            $t = TresorerieReelle::pourLesSites($this->idsSites, $this->debut(), $this->fin(), $this->activite)->totaux();

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

    /**
     * Le récapitulatif par commercial : son activité (prospections → devis → factures), puis son
     * réalisé face à l'objectif — 24/11.
     *
     * « Un récapitulatif de l'activité des commerciaux : les prospections, celles passées en devis,
     * toutes celles saisies ; le nombre de prospections, en devis, en attente, les factures. » L'onglet
     * ne montrait que les factures, le réalisé, l'objectif et l'encours : rien de ce qui les précède.
     *
     * **Aucune définition n'est neuve** : chaque compte reprend celui de l'écran qui le montre déjà,
     * pour que le rapport ne contredise aucune page.
     *
     * | Colonne | Règle | Écran qui la tient |
     * |---|---|---|
     * | Prospections saisies | toutes celles du commercial datées de l'exercice, brouillons et refusées compris | « Mes prospections » |
     * | Transmises ou validées | `Prospection::visibles()` — les brouillons exclus | Prospects, Affichage général |
     * | Passées en devis | visibles et `devis_apres_passage` | « Devis après passage » (Prospects, classement de l'Affichage général) |
     * | En attente du devis | passées en devis sans devis relié | `ProspectionsPasseesEnDevis`, `Prospection::etatDuDevis()` |
     * | Devis émis | `devis.commercial_id`, émis dans l'exercice | Devis (répartition par commercial) |
     * | Devis transformés | validés **ou facturés** | `TransformationDesDevis` (08/10) |
     * | Devis en attente | « En attente » et non transformés | carte « En attente » de la page Devis (12/10) |
     * | Factures, CA facturé | factures portant le commercial | Commerciaux (classement) |
     *
     * **Le périmètre** : les commerciaux actifs des villes du lecteur, comme avant ; leurs prospections
     * et leurs devis pris dans ses ateliers **ou sans atelier** — `whereIn` seul perdrait les lignes
     * sans site (piège 1), et le commercial, lui, est déjà dans le périmètre.
     *
     * @return list<array<string, mixed>>
     */
    public function commerciaux(): array
    {
        return $this->une('commerciaux', function () {
            $debut = $this->debut();
            $fin = $this->fin();

            $commerciaux = Commercial::actifs()->where('est_spontane', false)->whereIn('ville_id', $this->idsVilles)->with('ville')->get();
            $ids = $commerciaux->pluck('id')->all();
            // L'activité choisie (25/11) filtre la prospection, le devis et la facture : chacun la porte.
            $dansLesSites = fn ($requete, string $table) => $requete
                ->where(fn ($q) => $q->whereIn($table.'.site_id', $this->idsSites)->orWhereNull($table.'.site_id'))
                ->when($this->activite !== '', fn ($q) => $q->where($table.'.activite', $this->activite));

            // Les prospections : une requête pour tous, regroupées ici — jamais une par commercial.
            $prospections = $ids === [] ? collect() : $dansLesSites(Prospection::query(), 'prospections')
                ->whereIn('commercial_id', $ids)
                ->whereBetween('date', [$debut, $fin])
                ->withExists('devis')
                ->get(['id', 'commercial_id', 'statut_validation', 'devis_apres_passage'])
                ->groupBy('commercial_id');

            /*
             * Les devis : **tous** ceux du périmètre et de l'exercice passent par la transformation, pas
             * seulement ceux des commerciaux listés. « Une fiche ne transforme qu'un devis », le plus
             * récent de la fiche : n'en donner qu'une partie ferait élire un autre devis que celui de la
             * page Devis, et les deux chiffres divergeraient.
             */
            $devis = $dansLesSites(Devis::query(), 'devis')
                ->whereBetween('date_emission', [$debut, $fin])
                ->toBase()
                ->get(['id', 'commercial_id', 'statut', 'n_fiche_reception', 'date_emission']);
            $suites = TransformationDesDevis::facturesDesTransformes($devis);
            $transformes = array_map(fn () => true, $suites);
            $devisParCommercial = $devis->whereNotNull('commercial_id')->groupBy('commercial_id');

            // L'encours de chacun : le reste à encaisser de ses factures, par l'état des impayés.
            $encours = $this->impayes()
                ->whereNotNull('factures.commercial_id')
                ->avecEncaisseEtAvoirs()
                ->get(['factures.id', 'factures.commercial_id', 'factures.montant'])
                ->groupBy('commercial_id')
                ->map(fn ($g) => montant_de($g->sum(fn ($l) => Recouvrement::reste($l))));

            return $commerciaux->map(function (Commercial $c) use ($debut, $fin, $encours, $prospections, $devisParCommercial, $suites, $transformes) {
                $lignes = Facture::query()->where('commercial_id', $c->id)->whereIn('site_id', $this->idsSites)
                    ->when($this->activite !== '', fn ($q) => $q->where('activite', $this->activite))
                    ->whereBetween('date', [$debut, $fin])->toBase()->get(['montant', 'activite']);
                $realisation = montant_de($lignes->sum('montant'));
                $objectif = (int) round(PeriodeCalculateur::objectifProrata((float) $c->objectif_mensuel, $debut, $fin));

                $saisies = $prospections[$c->id] ?? collect();
                $visibles = $saisies->whereIn('statut_validation', ['Transmise', 'Validée']);
                $enDevis = $visibles->filter(fn ($p) => (bool) $p->devis_apres_passage);
                $siens = $devisParCommercial[$c->id] ?? collect();
                $estTransforme = fn ($d) => array_key_exists((int) $d->id, $suites);

                return [
                    'commercial' => $c->nom,
                    'ville' => $c->ville?->nom,
                    'prospectionsSaisies' => $saisies->count(),
                    'prospections' => $visibles->count(),
                    'passeesEnDevis' => $enDevis->count(),
                    'attenteDuDevis' => $enDevis->reject(fn ($p) => (bool) $p->devis_exists)->count(),
                    'tauxProspectionDevis' => $visibles->count() > 0 ? $enDevis->count() / $visibles->count() : null,
                    'devisEmis' => $siens->count(),
                    'devisEnAttente' => $siens->where('statut', 'En attente')->reject($estTransforme)->count(),
                    'devisTransformes' => $siens->filter($estTransforme)->count(),
                    'devisFactures' => $siens->filter(fn ($d) => ($suites[(int) $d->id] ?? null) !== null)->count(),
                    'tauxDevisFacture' => TransformationDesDevis::taux($siens, $transformes),
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

    /**
     * La ligne TOTAL du récapitulatif, et les cartes du haut de l'onglet.
     *
     * Les taux se **recalculent sur les totaux** : additionner ou moyenner des taux ferait peser
     * autant le commercial qui a deux prospections que celui qui en a deux cents.
     *
     * @return array<string, mixed>
     */
    public function totalDesCommerciaux(): array
    {
        return $this->une('totalCommerciaux', function () {
            $lignes = collect($this->commerciaux());
            $somme = fn (string $cle) => $lignes->sum($cle);
            $prospections = $somme('prospections');
            $devisEmis = $somme('devisEmis');
            $objectif = $somme('objectif');
            $realisation = montant_de($somme('realisation'));

            return [
                'prospectionsSaisies' => $somme('prospectionsSaisies'),
                'prospections' => $prospections,
                'passeesEnDevis' => $somme('passeesEnDevis'),
                'attenteDuDevis' => $somme('attenteDuDevis'),
                'tauxProspectionDevis' => $prospections > 0 ? $somme('passeesEnDevis') / $prospections : null,
                'devisEmis' => $devisEmis,
                'devisEnAttente' => $somme('devisEnAttente'),
                'devisTransformes' => $somme('devisTransformes'),
                'devisFactures' => $somme('devisFactures'),
                'tauxDevisFacture' => $devisEmis > 0 ? $somme('devisTransformes') / $devisEmis : null,
                'factures' => $somme('factures'),
                'realisation' => $realisation,
                'mecanique' => montant_de($somme('mecanique')),
                'sinistre' => montant_de($somme('sinistre')),
                'objectif' => $objectif,
                'taux' => $objectif > 0 ? $realisation / $objectif : null,
                'encours' => montant_de($somme('encours')),
            ];
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
                ['poste' => 'Impôts', 'montant' => $ch['impots']['aRegler'], 'delai' => $ch['impots']['aRegler'] > 0 ? 'À régler dès réception de l\'avis (pièces déposées)' : 'Rien à régler'],
                ['poste' => 'CNPS', 'montant' => $ch['cnps']['aRegler'], 'delai' => $ch['cnps']['aRegler'] > 0 ? 'Selon l\'appel de cotisation déposé' : 'Aucune pièce déposée'],
                ['poste' => 'Salaires non réglés', 'montant' => $ch['salaires']['nonRegle'], 'delai' => $ch['salaires']['nonRegle'] > 0 ? 'Immédiat' : 'Rien à régler'],
                ['poste' => 'Fournisseurs : pièces et consommables', 'montant' => montant_de($ch['fournisseurs']['familles']['pieces']['reste'] + $ch['fournisseurs']['familles']['consommables']['reste']), 'delai' => $this->delaiFournisseurs($ch['fournisseurs']['anciennete'])],
                ['poste' => 'Autres fournisseurs et factures FNE', 'montant' => montant_de($ch['fournisseurs']['familles']['autres']['reste'] + max($ch['fournisseurs']['reste'] - $ch['fournisseurs']['familles']['pieces']['reste'] - $ch['fournisseurs']['familles']['consommables']['reste'] - $ch['fournisseurs']['familles']['autres']['reste'], 0)), 'delai' => 'Selon échéances'],
            ];

            $engagements = montant_de(collect($lignes)->sum('montant'));

            // Le rythme d'encaissement : les 28 derniers jours, ramenés à la semaine. Lu sur le lieu et
            // l'activité choisis (25/11) : il se comparait jusqu'ici à des engagements du périmètre
            // avec les encaissements de toute l'entreprise.
            $quatreSemaines = montant_de($this->encaissements()->whereBetween('encaissements.date', [Carbon::today()->subDays(28), Carbon::today()->endOfDay()])->sum('encaissements.montant'));
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
            : 'Aucune dette en cours';
    }

    /**
     * Les règlements reçus sur le lieu et l'activité choisis, toutes dates : chaque appelant pose
     * la sienne. Le périmètre est celui de la page Trésorerie (l'atelier, sinon la ville de la
     * facture) ; l'activité, celle du règlement ou de sa facture (`Encaissement::ACTIVITE_SQL`).
     */
    private function encaissements(): Builder
    {
        return PerimetreDeTresorerie::encaissements(Encaissement::query(), $this->idsSites, $this->idsVilles)
            ->when($this->activite !== '', fn ($q) => $q->deLActivite($this->activite));
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
        $encaisse = montant_de($this->encaissements()->whereBetween('encaissements.date', [$this->debut(), $this->fin()])->sum('encaissements.montant'));
        $rec = $this->recouvrement();

        return [
            'exercice' => $this->exercice,
            // L'en-tête de colonne : l'année pour un exercice, la période sinon (« novembre 2025 »).
            'titre' => $this->periode->estLExercice() ? (string) $this->exercice : $this->periode->libelle(),
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
        // La même période un an plus tôt, sur le même lieu et la même activité : une semaine se
        // compare à la même semaine de N-1, pas à l'année entière.
        $avant = new self($this->entrepriseId, $this->idsSites, $this->idsVilles, $this->exercice - 1, $this->periode->unAnPlusTot(), $this->activite, $this->lieu);
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
            'periode' => $this->periode,
            'activite' => $this->activite,
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
