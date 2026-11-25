<?php

namespace Modules\Comptabilite\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Comptabilite\Services\AccesALaComptabilite;
use Modules\Noyau\Comptabilite\Services\PiecesJustificatives;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La pièce justificative d'une écriture — 25/11.
 *
 * L'opération est cherchée dans l'entreprise du compte connecté, jamais d'après un paramètre :
 * l'identifiant d'une autre entreprise rend 404, comme une opération qui n'existe pas. L'habilitation
 * se revérifie ici, pas seulement sur le lien : un lien recopié n'autorise personne.
 *
 * Le dépôt est un vrai POST (règle 6 : le chemin critique marche sans JavaScript).
 */
class JustificatifComptableController extends Controller
{
    public function ouvrir(Request $request, int $operation): Response
    {
        AccesALaComptabilite::exiger('voir');
        $op = $this->operation($request, $operation);

        if ($pdf = PiecesJustificatives::pdf($op)) {
            return response()->file($pdf['chemin'], [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.addcslashes(str_replace(["\r", "\n"], '', $pdf['nom']), '"\\').'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        // La FNE n'existe ici que par son lien de vérification : la pièce se lit sur le portail de la DGI.
        if ($lien = PiecesJustificatives::lienDuPortail($op)) {
            return redirect()->away($lien);
        }

        abort(404, 'Aucune pièce justificative pour cette opération.');
    }

    public function deposer(Request $request, int $operation): RedirectResponse
    {
        AccesALaComptabilite::exiger('lire_tva');
        $op = $this->operation($request, $operation);

        $request->validate([
            'justificatif' => ['required', 'file', 'max:'.PiecesJustificatives::TAILLE_MAX_KO, 'mimetypes:application/pdf'],
        ], ['justificatif.*' => 'Un PDF de 10 Mo au plus.']);

        try {
            PiecesJustificatives::deposer($op, $request->file('justificatif'), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['justificatif' => $e->getMessage()]);
        }

        activity()->performedOn($op)->withProperties(['source' => $op->source, 'source_id' => $op->source_id])->log('PDF justificatif joint');

        return back()->with('success', 'PDF joint à la pièce '.($op->reference_document ?: $op->numero_saisie).'.');
    }

    private function operation(Request $request, int $id): OperationComptable
    {
        return OperationComptable::withoutGlobalScopes()
            ->where('entreprise_id', (int) $request->user()->entreprise_id)
            ->findOrFail($id);
    }
}
