<?php

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ConfigurationIAController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);

        $data = $request->validate([
            'fournisseur' => ['required', 'in:openai,anthropic,google,openrouter'],
            'modele' => ['required', 'string', 'max:150'],
            'cle_api' => ['nullable', 'string', 'max:4096'],
            'actif' => ['nullable', 'boolean'],
        ]);

        $configuration = DB::table('configurations_ia')
            ->where('fournisseur', $data['fournisseur'])
            ->first();

        $payload = [
            'modele' => trim($data['modele']),
            'actif' => $request->boolean('actif'),
            'updated_at' => now(),
        ];

        if (! $configuration) {
            if (blank($data['cle_api'] ?? null)) {
                return back()->withErrors(['cle_api' => 'Une clé API est obligatoire pour le premier enregistrement.']);
            }

            $payload += [
                'fournisseur' => $data['fournisseur'],
                'cle_api_chiffree' => Crypt::encryptString($data['cle_api']),
                'created_at' => now(),
            ];

            DB::table('configurations_ia')->insert($payload);
        } else {
            if (filled($data['cle_api'] ?? null)) {
                $payload['cle_api_chiffree'] = Crypt::encryptString($data['cle_api']);
            }

            DB::table('configurations_ia')
                ->where('fournisseur', $data['fournisseur'])
                ->update($payload);
        }

        return back()->with('success', 'Configuration IA enregistrée. La clé est conservée chiffrée.');
    }

    public const USAGES = ['rapport' => 'Rapport', 'imputations' => 'Imputations comptables'];

    /** Affecte un fournisseur (déjà configuré) à un usage, avec un modèle et des instructions propres — 10/11. */
    public function affecter(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);

        $data = $request->validate([
            'usage' => ['required', 'in:'.implode(',', array_keys(self::USAGES))],
            'fournisseur' => ['required', 'in:openai,anthropic,google,openrouter'],
            'modele' => ['nullable', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:6000'],
            'actif' => ['nullable', 'boolean'],
        ]);

        if (! DB::table('configurations_ia')->where('fournisseur', $data['fournisseur'])->exists()) {
            return back()->withErrors(['fournisseur' => 'Ce fournisseur n\'est pas encore configuré : enregistrez d\'abord sa clé plus bas.']);
        }

        DB::table('affectations_ia')->updateOrInsert(
            ['usage' => $data['usage']],
            [
                'fournisseur' => $data['fournisseur'],
                'modele' => filled($data['modele'] ?? null) ? trim($data['modele']) : null,
                'instructions' => filled($data['instructions'] ?? null) ? trim($data['instructions']) : null,
                'actif' => $request->boolean('actif'),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return back()->with('success', 'IA « '.self::USAGES[$data['usage']].' » enregistrée.');
    }

    /** Un appel réel, minimal, pour vérifier que la clé, le modèle et le fournisseur répondent. */
    public function tester(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);

        $usage = $request->validate(['usage' => ['required', 'in:'.implode(',', array_keys(self::USAGES))]])['usage'];
        $ia = \Modules\Noyau\IA\Services\ClientIA::pour($usage);

        if ($ia === null) {
            return back()->withErrors(['usage' => 'Aucune IA active pour « '.self::USAGES[$usage].' » : affectez un fournisseur configuré et actif.']);
        }

        try {
            $reponse = $ia->texte('Réponds uniquement par le mot OK.', [['type' => 'text', 'text' => 'Test de connexion.']], 20);
        } catch (\Modules\Noyau\IA\Services\IAIndisponible $e) {
            return back()->withErrors(['usage' => 'Échec du test ('.$ia->fournisseur.', '.$ia->modele.') : '.$e->getMessage()]);
        }

        return back()->with('success', 'Test réussi ('.$ia->fournisseur.', '.$ia->modele.') : « '.mb_substr(trim($reponse), 0, 60).' ».');
    }
}
