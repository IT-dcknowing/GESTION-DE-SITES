<?php

namespace Modules\SuperAdmin\Http\Controllers;

use App\Models\User;
use App\Support\SchemaDisponible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Support\CatalogueDesPages;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Entreprises\Support\LibellesRoles;

class ConfigurationIAController extends Controller
{
    public const USAGES = ['rapport' => 'Rapport', 'imputations' => 'Imputations comptables'];

    public const FOURNISSEURS = [
        'openai' => ['nom' => 'OpenAI', 'modele' => 'gpt-6'],
        'anthropic' => ['nom' => 'Anthropic Claude', 'modele' => 'claude'],
        'google' => ['nom' => 'Google Gemini', 'modele' => 'gemini'],
        'openrouter' => ['nom' => 'OpenRouter', 'modele' => ''],
    ];

    /**
     * Les gestes d'IA qu'on accorde depuis cette page — 25/11 : « accorder l'usage de l'IA à un rôle
     * ou à un compte précis, par entreprise ». Les autres gestes restent à Maintenance › Habilitations.
     */
    public const GESTES_IA = [
        'rapport.ia' => 'Assistant IA du Rapport',
        'comptabilite.imputer_ia' => 'IA d\'imputation comptable',
    ];

    public function store(Request $request): RedirectResponse
    {
        $this->garder($request);

        $data = $request->validate([
            'fournisseur' => ['required', 'in:'.implode(',', array_keys(self::FOURNISSEURS))],
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

        return back()->with('success', self::FOURNISSEURS[$data['fournisseur']]['nom'].' enregistré. La clé est conservée chiffrée.');
    }

    /** Affecte un fournisseur (déjà configuré) à un usage, avec un modèle et des instructions propres — 10/11. */
    public function affecter(Request $request): RedirectResponse
    {
        $this->garder($request);

        $data = $request->validate([
            'usage' => ['required', 'in:'.implode(',', array_keys(self::USAGES))],
            'fournisseur' => ['required', 'in:'.implode(',', array_keys(self::FOURNISSEURS))],
            'modele' => ['nullable', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:6000'],
            'actif' => ['nullable', 'boolean'],
        ]);

        if (! DB::table('configurations_ia')->where('fournisseur', $data['fournisseur'])->exists()) {
            return back()->withErrors(['fournisseur' => 'Ce fournisseur n\'est pas encore configuré : enregistrez d\'abord sa clé dans « Fournisseurs et clés ».']);
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

    /**
     * Une IA pour tout — 25/11 : « choisir un fournisseur et l'affecter en une fois à tous les
     * usages ». Chaque usage de `USAGES`, présent ou à venir, reçoit le même fournisseur et le même
     * modèle. Les instructions sont propres à un usage (le ton d'un rapport n'est pas une consigne
     * comptable) : laissées vides, celles de chaque usage sont gardées. L'affectation par usage
     * reste possible ensuite ; le dernier enregistrement l'emporte.
     */
    public function affecterPartout(Request $request): RedirectResponse
    {
        $this->garder($request);

        $data = $request->validate([
            'fournisseur' => ['required', 'in:'.implode(',', array_keys(self::FOURNISSEURS))],
            'modele' => ['nullable', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:6000'],
            'actif' => ['nullable', 'boolean'],
        ]);

        $configuration = DB::table('configurations_ia')->where('fournisseur', $data['fournisseur'])->first();

        if ($configuration === null) {
            return back()->withErrors(['fournisseur' => 'Ce fournisseur n\'est pas encore configuré : enregistrez d\'abord sa clé dans « Fournisseurs et clés ».']);
        }

        $modele = filled($data['modele'] ?? null) ? trim($data['modele']) : null;
        $instructions = filled($data['instructions'] ?? null) ? trim($data['instructions']) : null;

        DB::transaction(function () use ($data, $modele, $instructions, $request) {
            foreach (array_keys(self::USAGES) as $usage) {
                $valeurs = [
                    'fournisseur' => $data['fournisseur'],
                    'modele' => $modele,
                    'actif' => $request->boolean('actif'),
                    'updated_at' => now(),
                ];

                if ($instructions !== null) {
                    $valeurs['instructions'] = $instructions;
                }

                if (DB::table('affectations_ia')->where('usage', $usage)->exists()) {
                    DB::table('affectations_ia')->where('usage', $usage)->update($valeurs);
                } else {
                    DB::table('affectations_ia')->insert($valeurs + ['usage' => $usage, 'instructions' => $instructions, 'created_at' => now()]);
                }
            }
        });

        $nom = self::FOURNISSEURS[$data['fournisseur']]['nom'];
        $avertissement = $configuration->actif ? '' : ' Attention : ce fournisseur est marqué indisponible — cochez « Disponible » sur sa carte, sinon aucune IA ne répondra.';

        return back()->with('success', $nom.' affecté à tous les usages ('.implode(', ', self::USAGES).').'.$avertissement);
    }

    /** Un appel réel, minimal, pour vérifier que la clé, le modèle et le fournisseur répondent. */
    public function tester(Request $request): RedirectResponse
    {
        $this->garder($request);

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

    /**
     * Accorde ou retire un geste d'IA à un rôle ou à un compte, dans une entreprise — 25/11.
     *
     * Même règle que Maintenance › Habilitations (`HabilitationsDActions`) : une ligne de rôle ne dit
     * que l'écart à ce qui s'appliquerait sans elle ; une ligne de compte l'emporte sur son rôle. La
     * différence est la portée : ici, la ligne de rôle ne vaut que pour l'entreprise choisie.
     */
    public function habiliter(Request $request): RedirectResponse
    {
        $moi = $this->garder($request);

        $data = $request->validate([
            'entreprise_id' => ['required', 'integer'],
            'beneficiaire' => ['required', 'in:role,compte'],
            'role' => ['nullable', 'required_if:beneficiaire,role', 'string', 'max:60'],
            'compte' => ['nullable', 'required_if:beneficiaire,compte', 'integer'],
            'gestes' => ['required', 'array', 'min:1'],
            'gestes.*' => ['string', 'in:'.implode(',', array_keys(self::GESTES_IA))],
            'sens' => ['required', 'in:accorder,retirer'],
        ], [
            'gestes.required' => 'Cochez au moins un usage de l\'IA.',
            'role.required_if' => 'Choisissez le rôle.',
            'compte.required_if' => 'Choisissez le compte.',
        ]);

        // L'entreprise, le rôle et le compte sont relus en base : une valeur venue du navigateur n'ouvre rien.
        $entreprise = Entreprise::query()->find((int) $data['entreprise_id']);

        if ($entreprise === null) {
            return back()->withErrors(['entreprise_id' => 'Cette entreprise n\'existe pas.']);
        }

        $retour = redirect()->to(route('super-admin.ia.configuration', ['entreprise' => $entreprise->id]).'#habilitations-ia');
        $colonne = SchemaDisponible::colonne('habilitations_actions', 'entreprise_id');
        $accorder = $data['sens'] === 'accorder';

        if ($data['beneficiaire'] === 'role') {
            if (! array_key_exists((string) $data['role'], self::roles())) {
                return $retour->withErrors(['role' => 'Ce rôle n\'existe pas.']);
            }

            // Sans la colonne, une ligne de rôle vaudrait pour toutes les entreprises : on n'écrit rien.
            if (! $colonne) {
                return $retour->withErrors(['role' => 'La mise à jour du serveur n\'est pas encore passée (migration du 25/11) : accordez à un compte précis en attendant.']);
            }
        } else {
            $compte = User::query()->withoutGlobalScopes()
                ->whereKey((int) $data['compte'])->where('entreprise_id', $entreprise->id)->first();

            if ($compte === null) {
                return $retour->withErrors(['compte' => 'Ce compte n\'appartient pas à '.$entreprise->nom.'.']);
            }
        }

        foreach (array_unique($data['gestes']) as $geste) {
            [$domaine, $action] = explode('.', $geste, 2);

            if (! HabilitationsDActions::existe($domaine, $action)) {
                continue;
            }

            if ($data['beneficiaire'] === 'role') {
                $role = (string) $data['role'];
                $defaut = HabilitationsDActions::DOMAINES[$domaine]['actions'][$action]['defaut'];

                if (! $accorder && $role === HabilitationsDActions::INAMOVIBLE && in_array($role, $defaut, true)) {
                    return $retour->withErrors(['role' => 'Le gérant répond de l\'entreprise : « '.self::GESTES_IA[$geste].' » ne lui est pas retiré.']);
                }

                $cle = ['domaine' => $domaine, 'action' => $action, 'role' => $role, 'user_id' => null, 'entreprise_id' => $entreprise->id];
                $existante = HabilitationAction::query()->where($cle)->first();

                // Ce que le rôle aurait dans cette entreprise sans la ligne : la ligne commune, sinon le code.
                $commune = HabilitationAction::query()->where('domaine', $domaine)->where('action', $action)
                    ->where('role', $role)->whereNull('user_id')->whereNull('entreprise_id')->first();
                $sansElle = $commune !== null ? (bool) $commune->accorde : in_array($role, $defaut, true);

                if ($sansElle === $accorder) {
                    if ($existante !== null) {
                        activity()->performedOn($existante)->causedBy($moi)->withProperties($cle)
                            ->log('Habilitation rendue à la règle commune');
                        $existante->delete();
                    }

                    continue;
                }
            } else {
                $cle = ['domaine' => $domaine, 'action' => $action, 'role' => null, 'user_id' => $compte->id];
                $existante = HabilitationAction::query()->where($cle)->first();

                if ($colonne) {
                    $cle['entreprise_id'] = $entreprise->id;
                }
            }

            $ligne = $existante ?? new HabilitationAction($cle);
            $ligne->fill(['accorde' => $accorder, 'accorde_par' => $moi->id])->save();

            activity()->performedOn($ligne)->causedBy($moi)->withProperties($cle + ['accorde' => $accorder])
                ->log($accorder ? 'Habilitation accordée' : 'Habilitation retirée');
        }

        HabilitationsDActions::oublier();

        return $retour->with('success', 'Habilitations de l\'IA enregistrées pour '.$entreprise->nom.'.');
    }

    /** Efface une ligne d'habilitation d'IA : le rôle ou le compte revient à la règle commune. */
    public function effacerHabilitation(Request $request): RedirectResponse
    {
        $moi = $this->garder($request);

        $id = (int) $request->validate(['ligne' => ['required', 'integer']])['ligne'];
        $ligne = HabilitationAction::query()->find($id);

        // Seuls les gestes d'IA s'effacent d'ici : le reste a sa section dans la Maintenance.
        abort_unless($ligne !== null && array_key_exists($ligne->domaine.'.'.$ligne->action, self::GESTES_IA), 404);

        activity()->performedOn($ligne)->causedBy($moi)
            ->withProperties($ligne->only(['domaine', 'action', 'role', 'user_id', 'entreprise_id', 'accorde']))
            ->log('Habilitation effacée');
        $ligne->delete();
        HabilitationsDActions::oublier();

        return redirect()->to(route('super-admin.ia.configuration', ['entreprise' => $request->integer('entreprise') ?: null]).'#habilitations-ia')
            ->with('success', 'Habilitation effacée : la règle commune s\'applique de nouveau.');
    }

    /** Les rôles déjà créés, comme dans Maintenance › Habilitations : ceux du métier et ceux configurés. */
    public static function roles(): array
    {
        return collect(LibellesRoles::TOUS)->except('super_admin')
            ->merge(collect(CatalogueDesPages::roles())->map(fn ($r) => $r['libelle']))
            ->all();
    }

    /**
     * La page est une section de la Maintenance : le Super admin qui ne l'a pas reçue n'y écrit
     * rien, même en postant à la main. Vérifié dans chaque action, pas seulement à la route.
     */
    private function garder(Request $request): User
    {
        $moi = $request->user();

        abort_unless($moi !== null && $moi->hasRole('super_admin') && $moi->peutAccederA('maintenance'), 403);

        return $moi;
    }
}
