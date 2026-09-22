<?php

namespace Modules\Superviseur\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\PisteDeLaFiche;
use Modules\Noyau\Imports\Modeles\DossierVehicule;

/**
 * Ouvrir une fiche de réception à partir de son numéro.
 *
 * **Pourquoi une route de plus.** Le n° de fiche est la clé commune des états du logiciel
 * d'atelier : il s'affiche sur l'écran des devis, sur celui du chiffre d'affaires et sur
 * celui des entrées et sorties. Pour le rendre cliquable depuis ces trois écrans, il
 * faudrait connaître l'identifiant du dossier correspondant — donc une requête de plus par
 * ligne affichée, soit trente par page. Une route qui résout le numéro au moment du clic
 * n'en coûte aucune tant que personne ne clique.
 *
 * **Le périmètre est relu ici**, sur l'identité du lecteur. Un numéro deviné dans l'adresse
 * ne doit pas ouvrir la fiche d'une ville qu'on n'a pas le droit de voir : elle répond
 * comme une fiche qui n'existe pas, sans laisser entendre qu'elle existe ailleurs.
 *
 * **Une fiche absente n'est pas une erreur du lecteur.** 1 191 numéros de devis et 384
 * numéros de facture désignent une fiche que la situation du parc ne porte pas : cette
 * situation est une extraction à une date, elle ne contient pas tout l'historique. Le 404
 * dit donc ce qui s'est passé plutôt que de laisser croire à une panne.
 */
class OuvrirLaFicheParSonNumero
{
    public function __invoke(string $numero): RedirectResponse
    {
        abort_unless(PisteDeLaFiche::estUnNumero($numero), 404);

        $dossier = DossierVehicule::query()
            ->where('entreprise_id', auth()->user()->entreprise_id)
            ->where('numero_fiche', trim($numero))
            ->where(fn ($q) => $q
                ->whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), null))
                ->orWhereNull('ville_id'))
            // La plus récente quand l'affaire a été rouverte sous le même numéro : la page
            // ouverte signale alors qu'il en existe plusieurs.
            ->orderByDesc('id')
            ->first();

        abort_if($dossier === null, 404, "Aucune fiche de réception ne porte ce numéro dans la situation du parc — l'extraction du parc ne couvre pas forcément la période de cette pièce.");

        return redirect()->route('parc-fiche', $dossier);
    }
}
