<?php

namespace Modules\Superviseur\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Noyau\Commun\Services\Exportateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
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
 * **Le fichier reprend l'écran, filtres compris.** Ville, état et recherche voyagent par
 * l'adresse, comme le fait le recouvrement : le fichier emporté contient donc exactement
 * ce que l'écran montrait. Un export qui refiltre autrement est un export qu'on finit par
 * ne plus croire.
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

        $lignes = FactureFournisseur::query()
            ->whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), $villeFiltre))
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
            $p->date_facture?->format('d/m/Y') ?? '—',
            $p->date_facture ? $p->date_facture->diffInDays(now()).' j' : '—',
            (string) ($p->imputation ?: '—'),
            (string) ($p->immatriculation ?: '—'),
            (string) ($p->ville?->nom ?: '—'),
            (int) $p->montant,
            (int) $p->montant_regle,
            (int) $p->reste_a_payer,
        ])->values()->all();

        $libelle = match ($etat) {
            'anciennes' => 'Pièces dues depuis plus de 90 jours',
            'soldees' => 'Pièces soldées',
            'toutes' => 'Toutes les pièces',
            default => 'Pièces avec un reste à payer',
        };

        $chapeau = $libelle.' — '.PerimetreSites::libellePerimetre(auth()->user(), $villeFiltre)
            .' · '.$pieces->count().' pièce(s)'
            .($recherche !== '' ? ' · recherche « '.$recherche.' »' : '');

        $entetes = ['Fournisseur', 'N° pièce', 'Date de facture', 'Ancienneté', 'Imputation',
            'Immatriculation', 'Ville', 'Montant', 'Déjà payé', 'Reste à payer'];

        // Le total refait le corps, et ne se recalcule pas à part : un pied qui ne
        // correspond pas à ses lignes finit toujours par être celui qu'on croit.
        $total = ['TOTAL', '', '', '', '', '', '',
            (int) $pieces->sum('montant'),
            (int) $pieces->sum('montant_regle'),
            (int) $pieces->sum('reste_a_payer')];

        $nom = 'Fournisseurs au '.now()->format('d-m-Y');

        return match ($format) {
            'excel' => Exportateur::excel($nom, 'Fournisseurs', $entetes, $corps, ['total' => $total]),
            'word' => Exportateur::word($nom, 'Fournisseurs', $entetes, $corps, $chapeau, ['total' => $total]),
            default => Exportateur::pdf($nom, 'Fournisseurs', $entetes, $corps, $chapeau, ['total' => $total]),
        };
    }
}
