<?php

namespace Modules\Superviseur\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Noyau\Commun\Services\Exportateur;
use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emporter ce que l'entreprise doit à ses fournisseurs.
 *
 * **Ce qui manquait.** L'écran des fournisseurs était le seul tableau de l'application à
 * ne rien proposer d'emportable : on lisait mille huit cent quarante-huit pièces à
 * l'écran, et pour en discuter avec un fournisseur il fallait les recopier. Le service
 * existait déjà — {@see Exportateur} rend les trois formats — il n'était simplement
 * branché nulle part ici.
 *
 * **Le fichier reprend l'écran, filtres compris.** Année, ville, état et recherche
 * voyagent par l'adresse, comme le fait le recouvrement : le fichier emporté contient donc
 * exactement ce que l'écran montrait. Un export qui refiltre autrement est un export qu'on
 * finit par ne plus croire — et depuis que l'état se tient par année, l'oublier suffirait à
 * emporter quatre exercices au lieu d'un.
 *
 * **La colonne « déjà payé » y est**, à côté du montant et du reste. C'est la demande qui
 * a motivé ce travail : savoir ce qu'on a déjà réglé à un fournisseur change la façon dont
 * on lui parle du reste.
 *
 * **Le périmètre est relu ici, jamais reçu.** La ville arrive du navigateur, et elle n'est
 * crue que de ce qu'elle désigne : `PerimetreSites` la confronte au compte connecté. Une
 * ville forgée dans l'adresse ne sort donc rien de plus que ce que ce compte pouvait
 * déjà lire à l'écran.
 */
class TelechargerLesFournisseurs
{
    public function __invoke(Request $requete, string $format): Response
    {
        abort_unless(array_key_exists($format, Exportateur::FORMATS), 404);

        $villeFiltre = (string) $requete->query('ville', '');
        $etat = (string) $requete->query('etat', 'ouvertes');
        $recherche = trim((string) $requete->query('recherche', ''));

        // L'année reçue n'est crue que comme une année : tout le reste retombe sur
        // l'exercice ouvert, et aucune valeur forgée n'atteint la requête.
        $annee = (int) $requete->query('exercice', '');
        $annee = $annee >= 2000 && $annee <= 2100
            ? $annee
            : EtatDesFournisseurs::exerciceOuvert((int) auth()->user()->entreprise_id);

        $lignes = EtatDesFournisseurs::dansLePerimetre(
            EtatDesFournisseurs::requete($annee),
            PerimetreSites::idsVillesRetenus(auth()->user(), $villeFiltre),
        )
            ->when($recherche !== '', function ($q) use ($recherche) {
                $terme = '%'.$recherche.'%';

                $q->where(fn ($sous) => $sous->where('fournisseur', 'like', $terme)
                    ->orWhere('numero_piece', 'like', $terme)
                    ->orWhere('immatriculation', 'like', $terme)
                    ->orWhere('imputation', 'like', $terme));
            });

        $lignes = match ($etat) {
            'anciennes' => $lignes->where('reste_a_payer', '>', 0)
                ->whereDate('date_facture', '<', now()->subDays(90)),
            'echues' => $lignes->where('reste_a_payer', '>', 0)
                ->whereNotNull('date_echeance')->whereDate('date_echeance', '<', now()),
            'soldees' => $lignes->where('reste_a_payer', '<=', 0),
            'toutes' => $lignes,
            default => $lignes->where('reste_a_payer', '>', 0),
        };

        // Le même ordre qu'à l'écran : la plus vieille dette en tête, faute d'échéance dans
        // le fichier d'origine.
        $pieces = $lignes->with('ville')->orderBy('date_facture')->get();

        $corps = $pieces->map(fn (FactureFournisseur $p) => [
            (string) ($p->fournisseur ?: '—'),
            (string) ($p->numero_piece ?: '—'),
            EtatDesFournisseurs::libelleReport($p, $annee),
            $p->date_facture?->format('d/m/Y') ?? '—',
            $p->date_facture ? NombreDeJours::entre($p->date_facture, now()).' j' : '—',
            (string) ($p->imputation ?: '—'),
            (string) ($p->immatriculation ?: '—'),
            (string) ($p->ville?->nom ?: '—'),
            (int) $p->montant,
            (int) $p->montant_regle,
            (int) $p->reste_a_payer,
        ])->values()->all();

        $libelle = match ($etat) {
            'anciennes' => 'Pièces dues depuis plus de 90 jours',
            'echues' => 'Pièces échues',
            'soldees' => 'Pièces soldées',
            'toutes' => 'Toutes les pièces',
            default => 'Pièces avec un reste à payer',
        };

        // L'année est dite dans le chapeau : une feuille qui porte un report de 2024 sans
        // dire de quel exercice elle est se lit de travers trois mois plus tard.
        $chapeau = $libelle.' — état '.$annee.' — '.PerimetreSites::libellePerimetre(auth()->user(), $villeFiltre)
            .' · '.$pieces->count().' pièce(s)'
            .($recherche !== '' ? ' · recherche « '.$recherche.' »' : '');

        $entetes = ['Fournisseur', 'N° pièce', 'Report', 'Date de facture', 'Ancienneté', 'Imputation',
            'Immatriculation', 'Ville', 'Montant', 'Déjà payé', 'Reste à payer'];

        // Le total refait le corps, et ne se recalcule pas à part : un pied qui ne
        // correspond pas à ses lignes finit toujours par être celui qu'on croit.
        $total = ['TOTAL', '', '', '', '', '', '', '',
            (int) $pieces->sum('montant'),
            (int) $pieces->sum('montant_regle'),
            (int) $pieces->sum('reste_a_payer')];

        $nom = 'Fournisseurs '.$annee.' au '.now()->format('d-m-Y');

        return match ($format) {
            'excel' => Exportateur::excel($nom, 'Fournisseurs', $entetes, $corps, ['total' => $total]),
            'word' => Exportateur::word($nom, 'Fournisseurs', $entetes, $corps, $chapeau, ['total' => $total]),
            default => Exportateur::pdf($nom, 'Fournisseurs', $entetes, $corps, $chapeau, ['total' => $total]),
        };
    }
}
