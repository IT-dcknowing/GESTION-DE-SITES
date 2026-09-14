<?php

namespace Modules\Recouvrement\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Noyau\Commun\Services\Exportateur;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PortefeuilleDeRecouvrement;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emporter un document du recouvrement — en PDF, en Excel ou en Word.
 *
 * **Pourquoi un contrôleur plutôt qu'un bouton dans la page.** Un téléchargement est une
 * réponse HTTP avec ses en-têtes : il ne se fabrique pas dans un composant qui rend du HTML.
 * Et surtout, il partage l'adresse de la page — mêmes paramètres, même arrêté, même filtre —
 * ce qui garantit que **le fichier emporté contient exactement ce que l'écran montrait**.
 * Un export qui recalcule autrement est un export qu'on finit par ne plus croire.
 *
 * **Chaque document porte sa ligne de totaux.** Elle existait à l'écran et nulle part
 * ailleurs : on téléchargeait un tableau dont il fallait refaire la somme. C'est le contraire
 * du service rendu — un export sert à porter un chiffre à quelqu'un, pas à lui donner du
 * travail. Le total du fichier est **celui des lignes exportées**, jamais un total général
 * calculé à part : le pied doit refaire le corps, sans quoi les deux se contrediront un jour.
 *
 * **Les trois formats se ressemblent, et c'est voulu.** En-tête noir, montants alignés par la
 * droite, total en pied, pastille de couleur pour le niveau de relance. Un document remis à
 * un assureur, un tableau retravaillé et une pièce collée dans un courrier disent la même
 * chose : ils doivent se relire de la même façon.
 */
class TelechargementController
{
    /** Les documents qu'on sait produire, et le nom sous lequel ils s'appellent. */
    public const DOCUMENTS = [
        'balance' => 'Balance âgée',
        'courtiers' => 'Courtiers',
        'clients' => 'Clients & tiers',
        'extrait' => 'Extrait de compte',
        'relances' => 'Journal des relances',
        'encaissements' => 'Journal des encaissements',
        'portefeuille' => 'Portefeuille de créances',
    ];

    /**
     * Les couleurs des niveaux de relance, reprises de l'écran.
     *
     * Elles sont recopiées ici plutôt que lues dans la feuille de style : un document
     * emporté ne charge aucune feuille de style, et il doit rester lisible dans dix ans
     * sans rien d'autre que lui-même.
     */
    private const TEINTES = [
        'Soldée' => ['fond' => '#E5F2E8', 'texte' => '#1E7B34'],
        'Soldé' => ['fond' => '#E5F2E8', 'texte' => '#1E7B34'],
        'Courante' => ['fond' => '#EEF0F3', 'texte' => '#5A6472'],
        'À dater' => ['fond' => '#EEF0F3', 'texte' => '#5A6472'],
        'N1 · E-mail' => ['fond' => '#E8ECF5', 'texte' => '#3A5A8C'],
        'N2 · Appel' => ['fond' => '#FFF4DE', 'texte' => '#B87A00'],
        'N3 · Courrier' => ['fond' => '#FFE9D6', 'texte' => '#C05A12'],
        'N4 · Mise en demeure' => ['fond' => '#FBD9DE', 'texte' => '#C8102E'],
        'N5 · Contentieux' => ['fond' => '#C8102E', 'texte' => '#FFFFFF'],
    ];

    public function __invoke(Request $requete, string $document): Response
    {
        // Trois formats, et le PDF par défaut : c'est celui qu'on remet à un client.
        $format = in_array($requete->query('format'), ['pdf', 'excel', 'word'], true)
            ? (string) $requete->query('format')
            : 'pdf';
        $arrete = Recouvrement::arrete($requete->query('arrete'));
        $stamp = $arrete->format('d-m-Y');
        $au = ' arrêté au '.$arrete->format('d/m/Y').'.';

        return match ($document) {
            'balance' => $this->rendre($format, 'Balance agee au '.$stamp, 'Balance âgée', $this->balance($arrete),
                "Encours par tiers et par tranche d'ancienneté,".$au),
            'courtiers' => $this->rendre($format, 'Courtiers au '.$stamp, 'Courtiers', $this->courtiers($arrete),
                'Encours porté par chaque courtier,'.$au),
            'clients' => $this->rendre($format, 'Clients et tiers au '.$stamp, 'Clients & tiers', $this->clients($arrete),
                'Annuaire des tiers et encours,'.$au),
            'relances' => $this->rendre($format, 'Journal des relances au '.$stamp, 'Journal des relances',
                $this->relances(), 'Toutes les relances tracées, de la plus récente à la plus ancienne.'),
            'encaissements' => $this->rendre($format, 'Encaissements au '.$stamp, 'Journal des encaissements',
                $this->encaissements($requete), $this->periodeEnClair($requete)),
            'portefeuille' => $this->rendre($format, 'Portefeuille au '.$stamp, 'Portefeuille de créances',
                $this->portefeuille($arrete),
                "Ce qui est dû, depuis quand, qui s'en charge et ce qui est rentré,".$au),
            'extrait' => $this->extrait($requete, $format, $arrete),
            default => abort(404),
        };
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>} */
    private function balance($arrete): array
    {
        $lignes = Recouvrement::parTiers(Recouvrement::facturesOuvertes($arrete), $arrete);

        // Les intitulés des tranches viennent du service, pas d'ici : le jour où l'on
        // change le découpage, l'export suit sans qu'on ait à y penser.
        $colonnes = array_column(Recouvrement::TRANCHES, 'libelle');
        $nombreDeTranches = count($colonnes);

        $corps = $lignes->map(fn (array $l) => [
            $l['tiers'],
            ...array_map('intval', array_values($l['tranches'])),
            (int) $l['reste'],
            (int) $l['nombre'],
            $l['niveau']['libelle'] ?? '—',
        ])->values()->all();

        // Le total refait le corps, tranche par tranche : c'est la ligne noire du bas de
        // l'écran, et elle manquait à tous les téléchargements.
        $totaux = array_fill(0, $nombreDeTranches, 0);

        foreach ($lignes as $ligne) {
            foreach (array_values($ligne['tranches']) as $index => $montant) {
                $totaux[$index] += (int) $montant;
            }
        }

        $total = ['TOTAL', ...$totaux, array_sum($totaux), (int) $lignes->sum('nombre'), ''];

        return [
            ['Tiers', ...$colonnes, 'Total', 'Factures ouvertes', 'Niveau maximal'],
            $corps,
            $total,
            [$nombreDeTranches + 3 => self::TEINTES],
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>} */
    private function courtiers($arrete): array
    {
        $lignes = Recouvrement::parCourtier(Recouvrement::factures($arrete), $arrete);

        return [
            ['Courtier', 'Facturé', 'Réglé', 'Reste à payer', 'Factures ouvertes', 'Compagnies', 'Niveau'],
            $lignes->map(fn (array $l) => [
                $l['courtier'],
                (int) $l['facture'],
                (int) $l['regle'],
                (int) $l['reste'],
                (int) $l['ouvertes'],
                (int) $l['assurances'],
                $l['niveau']['libelle'] ?? '—',
            ])->values()->all(),
            [
                'TOTAL',
                (int) $lignes->sum('facture'),
                (int) $lignes->sum('regle'),
                (int) $lignes->sum('reste'),
                (int) $lignes->sum('ouvertes'),
                '',
                '',
            ],
            [6 => self::TEINTES],
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>} */
    private function clients($arrete): array
    {
        $lignes = Recouvrement::annuaireDesTiers(Recouvrement::factures($arrete), $arrete);

        return [
            ['Tiers', 'Rôles', 'Factures', 'Facturé', 'Réglé', 'Reste à payer', 'Ouvertes', 'Dernière facture'],
            $lignes->map(fn (array $l) => [
                $l['tiers'],
                implode(' · ', $l['roles']) ?: 'Déclaré',
                (int) $l['factures'],
                (int) $l['facture'],
                (int) $l['regle'],
                (int) $l['reste'],
                (int) $l['ouvertes'],
                $l['derniere']?->format('d/m/Y') ?? '—',
            ])->values()->all(),
            [
                'TOTAL',
                '',
                (int) $lignes->sum('factures'),
                (int) $lignes->sum('facture'),
                (int) $lignes->sum('regle'),
                (int) $lignes->sum('reste'),
                (int) $lignes->sum('ouvertes'),
                '',
            ],
            [],
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>} */
    private function relances(): array
    {
        $lignes = RelanceRecouvrement::orderByDesc('date')->orderByDesc('id')->get();

        return [
            ['Date', 'Tiers', 'Factures', 'Niveau', 'Canal', 'Interlocuteur', 'Résultat / engagement', 'Promis', 'Responsable', 'Statut'],
            $lignes->map(fn (RelanceRecouvrement $r) => [
                $r->date?->format('d/m/Y') ?? '—',
                (string) $r->tiers,
                (string) ($r->factures ?: '—'),
                (string) (RelanceRecouvrement::NIVEAUX[$r->niveau] ?? $r->niveau),
                (string) ($r->canal ?: '—'),
                (string) ($r->interlocuteur ?: '—'),
                (string) ($r->resultat ?: '—'),
                (int) $r->montant_promis,
                (string) ($r->responsable ?: '—'),
                (string) $r->statut,
            ])->values()->all(),
            ['TOTAL', '', '', '', '', '', '', (int) $lignes->sum('montant_promis'), '', ''],
            [3 => self::TEINTES],
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>} */
    private function encaissements(Request $requete): array
    {
        [$du, $au] = $this->periode($requete);

        $lignes = Encaissement::whereNotNull('facture_id')
            ->whereBetween('date', [$du, $au])
            ->with(['facture:id,n_facture'])
            ->orderByDesc('date')->orderByDesc('id')
            ->get();

        return [
            ['Date', 'Tiers', 'Facture', 'Mode', 'Montant', 'Référence'],
            $lignes->map(fn (Encaissement $e) => [
                $e->date?->format('d/m/Y') ?? '—',
                (string) ($e->client ?: '—'),
                (string) ($e->facture?->n_facture ?: '—'),
                (string) ($e->mode ?: '—'),
                (int) $e->montant,
                (string) ($e->reference_origine ?: '—'),
            ])->values()->all(),
            ['TOTAL', '', '', '', (int) $lignes->sum('montant'), ''],
            [],
        ];
    }

    /**
     * Le portefeuille tel que l'affiche le tableau de bord.
     *
     * Les mêmes colonnes, dans le même ordre, calculées par le même service : un état
     * qu'on emporte doit refaire l'écran d'où il vient, sans quoi la réunion se passe à
     * comparer deux papiers au lieu de traiter les dossiers.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>}
     */
    private function portefeuille($arrete): array
    {
        $lignes = (new PortefeuilleDeRecouvrement($arrete))->lignes();

        return [
            [
                'Tiers', 'Reste à payer', 'Factures ouvertes', 'Doit depuis (j)', 'Plus ancienne',
                'Niveau', "Qui s'en charge", 'Dernière relance', 'Relances', 'Promis',
                'Encaissé', 'Silence (j)',
            ],
            $lignes->map(fn (array $l) => [
                $l['tiers'],
                (int) $l['reste'],
                (int) $l['nombre'],
                $l['depuis'] !== null ? (int) $l['depuis'] : '—',
                $l['plus_ancienne']?->format('d/m/Y') ?? '—',
                $l['niveau']['libelle'] ?? '—',
                $l['responsable'] ?: 'À confier',
                $l['derniere_relance']?->format('d/m/Y') ?? 'jamais',
                (int) $l['relances'],
                (int) $l['promis'],
                (int) $l['encaisse'],
                $l['silence'] !== null ? (int) $l['silence'] : '—',
            ])->values()->all(),
            [
                'TOTAL — '.$lignes->count().' tiers',
                (int) $lignes->sum('reste'),
                (int) $lignes->sum('nombre'),
                '', '', '', '', '',
                (int) $lignes->sum('relances'),
                (int) $lignes->sum('promis'),
                (int) $lignes->sum('encaisse'),
                '',
            ],
            [5 => self::TEINTES],
        ];
    }

    private function periode(Request $requete): array
    {
        return [
            Recouvrement::arrete($requete->query('du') ?: now()->startOfMonth()->toDateString()),
            Recouvrement::arrete($requete->query('au') ?: now()->toDateString())->endOfDay(),
        ];
    }

    private function periodeEnClair(Request $requete): string
    {
        [$du, $au] = $this->periode($requete);

        return 'Règlements reçus du '.$du->format('d/m/Y').' au '.$au->format('d/m/Y').'.';
    }

    private function extrait(Request $requete, string $format, $arrete): Response
    {
        $tiers = trim((string) $requete->query('tiers', ''));

        // Le tiers est ramené à la liste connue : une valeur forgée produirait un document
        // vide portant l'en-tête de l'entreprise, ce qui est pire qu'un refus.
        if ($tiers === '' || ! array_key_exists($tiers, Recouvrement::tiers())) {
            abort(404, 'Aucun tiers désigné.');
        }

        $factures = Recouvrement::facturesDuTiers(Recouvrement::factures($arrete), $tiers);
        $regle = 0;
        $reste = 0;

        $corps = $factures->map(function (Facture $f) use ($arrete, &$regle, &$reste) {
            $du = Recouvrement::reste($f);
            $age = Recouvrement::anciennete($f, $arrete);
            $regle += (int) $f->montant - $du;
            $reste += $du;

            return [
                $f->date?->format('d/m/Y') ?? '—',
                (string) $f->n_facture,
                (string) ($f->vehicule ?: '—'),
                (string) ($f->immatriculation ?: '—'),
                (int) $f->montant,
                (int) $f->montant - $du,
                $du,
                $du < Recouvrement::SEUIL_SOLDE ? 'Soldée' : ($age === null ? '—' : $age.' j'),
            ];
        })->values()->all();

        $donnees = [
            ['Date', 'N° facture', 'Véhicule', 'Immatriculation', 'Montant TTC', 'Réglé', 'Reste à payer', 'Ancienneté'],
            $corps,
            ['TOTAL', '', '', '', (int) $factures->sum('montant'), $regle, $reste, ''],
            [],
        ];

        $chapeau = $tiers."\n".'Extrait arrêté au '.$arrete->format('d/m/Y')
            .' — '.$factures->count().' facture(s).';

        return $this->rendre($format, 'Extrait '.$tiers.' au '.$arrete->format('d-m-Y'), $tiers, $donnees, $chapeau);
    }

    /** @param  array{0: list<string>, 1: list<list<mixed>>, 2: ?list<mixed>, 3: array<int, array>}  $donnees */
    private function rendre(string $format, string $nomDeFichier, string $titre, array $donnees, string $chapeau): Response
    {
        [$entetes, $lignes, $total, $etiquettes] = $donnees;

        // Un tableau vide n'a pas de total à montrer : la ligne noire dirait « 0 » là où il
        // n'y a rien, et l'on chercherait ce qu'on a filtré de travers.
        /*
         * Le message accompagne le tableau dans les trois formats. Le classeur ne le
         * recevait pas : il sortait sans en-tête, et l'on ne savait plus, deux jours
         * après, à quel arrêté se rapportait le fichier ouvert.
         */
        $options = ['etiquettes' => $etiquettes, 'chapeau' => $chapeau]
            + ($lignes === [] ? [] : ['total' => $total]);

        return match ($format) {
            'word' => Exportateur::word($nomDeFichier, $titre, $entetes, $lignes, $chapeau, $options),
            'excel' => Exportateur::excel($nomDeFichier, $titre, $entetes, $lignes, $options),
            default => Exportateur::pdf($nomDeFichier, $titre, $entetes, $lignes, $chapeau, $options),
        };
    }
}
