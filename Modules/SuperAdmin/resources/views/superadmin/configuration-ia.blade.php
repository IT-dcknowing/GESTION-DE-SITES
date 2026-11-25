<?php

use App\Models\User;
use App\Support\SchemaDisponible;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\SuperAdmin\Http\Controllers\ConfigurationIAController;

use function Livewire\Volt\with;

/*
 * Erreur en ligne du 24/11 : « Undefined variable $usages ». Dans un composant Volt, les
 * variables écrites en tête de fichier restent locales au bloc PHP : la vue ne les voit pas.
 * Elles passent donc par `with()`, relues à chaque rendu.
 *
 * 25/11, demandes du propriétaire : la page se lit dans l'ordre où l'on règle — une IA pour tout,
 * les fournisseurs côte à côte (quatre cartes au lieu de quatre écrans de haut), l'affectation fine
 * par usage, puis qui peut s'en servir, entreprise par entreprise. Tous les formulaires sont de
 * vrais POST : la page marche sans JavaScript, et chaque action revérifie le Super admin.
 */
with(function () {
    $configurations = DB::table('configurations_ia')->get()->keyBy('fournisseur');
    $affectations = Schema::hasTable('affectations_ia') ? DB::table('affectations_ia')->get()->keyBy('usage') : collect();

    // L'entreprise se choisit par l'adresse (?entreprise=) : un simple formulaire GET, sans script.
    $entreprises = Entreprise::query()->orderBy('nom')->pluck('nom', 'id');
    $entrepriseId = (int) request()->query('entreprise');
    $entrepriseId = $entreprises->has($entrepriseId) ? $entrepriseId : (int) $entreprises->keys()->first();

    $roles = ConfigurationIAController::roles();
    $gestes = [];

    foreach (ConfigurationIAController::GESTES_IA as $geste => $libelle) {
        [$domaine, $action] = explode('.', $geste, 2);

        if (! HabilitationsDActions::existe($domaine, $action)) {
            continue;
        }

        $lignes = HabilitationsDActions::lignes()->where('domaine', $domaine)->where('action', $action);
        $decidees = HabilitationsDActions::lignesDeRolePour($lignes, $entrepriseId ?: null);
        $defaut = HabilitationsDActions::DOMAINES[$domaine]['actions'][$action]['defaut'];
        $parRole = [];

        // Même lecture que `HabilitationsDActions::accorde()`, rôle par rôle, pour cette entreprise.
        foreach ($roles as $role => $nom) {
            $ligne = $decidees->get($role);
            $aLeGeste = $ligne !== null
                ? ($ligne->accorde || ($role === HabilitationsDActions::INAMOVIBLE && in_array($role, $defaut, true)))
                : in_array($role, $defaut, true);

            if ($aLeGeste || $ligne !== null) {
                $parRole[] = [
                    'role' => $role, 'nom' => $nom, 'a' => $aLeGeste,
                    'origine' => $ligne === null ? 'par le code' : ($ligne->entreprise_id ? 'dans cette entreprise' : 'pour toutes les entreprises'),
                    'ligne' => $ligne?->entreprise_id ? $ligne->id : null,
                ];
            }
        }

        $gestes[$geste] = [
            'libelle' => $libelle,
            'roles' => $parRole,
            'comptes' => $lignes->whereNotNull('user_id')
                ->filter(fn ($l) => (int) $l->utilisateur?->entreprise_id === $entrepriseId)->values(),
        ];
    }

    return [
        'configurations' => $configurations,
        'affectations' => $affectations,
        'usages' => ConfigurationIAController::USAGES,
        'fournisseurs' => ConfigurationIAController::FOURNISSEURS,
        'configures' => collect(ConfigurationIAController::FOURNISSEURS)->filter(fn ($f, $code) => $configurations->has($code)),
        // Le fournisseur commun, s'il y en a un : tous les usages affectés au même, avec le même modèle.
        'commun' => $affectations->count() === count(ConfigurationIAController::USAGES)
            && $affectations->pluck('fournisseur')->unique()->count() === 1
            && $affectations->pluck('modele')->unique()->count() === 1
                ? $affectations->first() : null,
        'entreprises' => $entreprises,
        'entrepriseId' => $entrepriseId,
        'roles' => $roles,
        'comptes' => $entrepriseId
            ? User::query()->withoutGlobalScopes()->where('entreprise_id', $entrepriseId)->orderBy('name')->get(['id', 'name', 'email'])
            : collect(),
        'gestes' => $gestes,
        'parEntrepriseDisponible' => SchemaDisponible::colonne('habilitations_actions', 'entreprise_id'),
    ];
});
?>

<div>
    {{-- Quatre colonnes sur un écran large, deux sur une tablette, une sur un téléphone. Une classe et
         non un style en ligne : un style en ligne bat la feuille de style (piège du `<nav>`). --}}
    <style>
        .ia-grille { display: grid; gap: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .ia-grille-2 { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (max-width: 1180px) { .ia-grille { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 720px) { .ia-grille, .ia-grille-2 { grid-template-columns: minmax(0, 1fr); } }
        .ia-carte { background: #fff; border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .ia-carte .champ { width: 100%; }
        .ia-libelle { display: block; font-size: 11.5px; color: #6B7280; margin-top: 2px; }
        .ia-aide { margin: 0 0 10px; color: #6B7280; font-size: 12.5px; line-height: 1.5; }
        .ia-pastille { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
        .ia-pastille.actif { background: #DCFCE7; color: #15803D; }
        .ia-pastille.inactif { background: #FEF3C7; color: #B45309; }
        .ia-pastille.absent { background: #F3F4F6; color: #6B7280; }
        .ia-section { margin: 18px 0 8px; font-size: 15px; }
    </style>

    <x-titre-ecran titre="Intelligence artificielle"
        sous-titre="Une IA pour tout ou une IA par usage, les clés des fournisseurs, et qui peut s'en servir dans chaque entreprise. Accès réservé au superadministrateur." />

    @if (session('success'))
        <div class="carte" style="border-left:3px solid #15803D;margin-bottom:14px;">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="carte" style="border-left:3px solid #B91C1C;margin-bottom:14px;">
            @foreach ($errors->all() as $error)
                <p style="margin:0 0 5px;">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- ═══════════════ 1. Une IA pour tout ═══════════════ --}}
    <h3 class="ia-section">1. Une IA pour tout</h3>
    <div class="carte" style="margin-bottom:16px;">
        <p class="ia-aide">
            Le même fournisseur et le même modèle pour <b>tous les usages</b> ({{ implode(', ', $usages) }}, et ceux qui viendront).
            <b>La règle :</b> le dernier enregistrement l'emporte. « Une IA pour tout » remplace le fournisseur et le modèle de chaque usage ;
            vous pouvez ensuite régler un usage à part dans la section 3. Les instructions saisies ici remplacent celles de chaque usage ;
            laissées vides, celles de chaque usage sont gardées.
        </p>
        <p style="margin:0 0 10px;font-size:13px;">
            Aujourd'hui :
            @if ($commun)
                tous les usages sont sur <b>{{ $fournisseurs[$commun->fournisseur]['nom'] ?? $commun->fournisseur }}</b>{{ $commun->modele ? ' ('.$commun->modele.')' : '' }}.
            @elseif ($affectations->isEmpty())
                aucun usage n'est affecté.
            @else
                @foreach ($usages as $usage => $libelle)
                    @php($a = $affectations->get($usage))
                    {{ $libelle }} → <b>{{ $a ? ($fournisseurs[$a->fournisseur]['nom'] ?? $a->fournisseur).($a->modele ? ' ('.$a->modele.')' : '') : 'non affecté' }}</b>@if (! $loop->last) · @endif
                @endforeach
            @endif
        </p>

        @if ($configures->isEmpty())
            <p style="margin:0;color:#B45309;font-size:13px;">Aucun fournisseur configuré : enregistrez d'abord une clé dans la section 2.</p>
        @else
            <form method="POST" action="{{ route('super-admin.ia.affecter-partout') }}">
                @csrf
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label class="ia-libelle" for="partout-fournisseur">Fournisseur configuré</label>
                        <select id="partout-fournisseur" name="fournisseur" class="champ" style="width:auto" required>
                            @foreach ($configures as $code => $f)
                                <option value="{{ $code }}" @selected(($commun->fournisseur ?? null) === $code)>{{ $f['nom'] }}{{ $configurations[$code]->actif ? '' : ' (indisponible)' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <label class="ia-libelle" for="partout-modele">Modèle (vide = celui de la carte du fournisseur)</label>
                        <input id="partout-modele" name="modele" class="champ" value="{{ $commun->modele ?? '' }}" placeholder="Identifiant exact du modèle">
                    </div>
                </div>
                <label class="ia-libelle" for="partout-instructions" style="margin-top:8px;">Instructions communes (facultatives)</label>
                <textarea id="partout-instructions" name="instructions" class="champ" rows="2" placeholder="Vide : chaque usage garde ses instructions."></textarea>
                <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:8px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                        <input type="checkbox" name="actif" value="1" checked> Activer l'IA pour tous les usages
                    </label>
                    <button type="submit" class="bouton">Affecter à tous les usages</button>
                </div>
            </form>
        @endif
    </div>

    {{-- ═══════════════ 2. Fournisseurs et clés ═══════════════ --}}
    <h3 class="ia-section">2. Fournisseurs et clés</h3>
    <p class="ia-aide">
        Les clés API sont chiffrées en base et ne sont jamais réaffichées. Laisser le champ vide conserve la clé déjà enregistrée.
        Chaque carte s'enregistre seule.
    </p>
    <div class="ia-grille" style="margin-bottom:16px;">
        @foreach ($fournisseurs as $code => $fournisseur)
            @php($configuration = $configurations->get($code))
            <form method="POST" action="{{ route('super-admin.ia.enregistrer') }}" class="ia-carte">
                @csrf
                <input type="hidden" name="fournisseur" value="{{ $code }}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong>{{ $fournisseur['nom'] }}</strong>
                    @if (! $configuration)
                        <span class="ia-pastille absent">Non configuré</span>
                    @elseif ($configuration->actif)
                        <span class="ia-pastille actif">Actif</span>
                    @else
                        <span class="ia-pastille inactif">Désactivé</span>
                    @endif
                </div>

                <label class="ia-libelle" for="modele-{{ $code }}">Modèle</label>
                <input id="modele-{{ $code }}" name="modele" class="champ"
                    value="{{ old('fournisseur') === $code ? old('modele') : ($configuration->modele ?? $fournisseur['modele']) }}"
                    placeholder="Identifiant exact du modèle" required>

                <label class="ia-libelle" for="cle-{{ $code }}">Clé API</label>
                <input id="cle-{{ $code }}" name="cle_api" type="password" class="champ"
                    autocomplete="new-password"
                    placeholder="{{ $configuration ? 'Clé enregistrée — saisir pour remplacer' : 'Saisir la clé API' }}">

                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;">
                    <input type="checkbox" name="actif" value="1" @checked($configuration?->actif)> Disponible
                </label>
                <button type="submit" class="bouton" style="margin-top:auto;">Enregistrer</button>
            </form>
        @endforeach
    </div>

    {{-- ═══════════════ 3. Affectation par usage ═══════════════ --}}
    <h3 class="ia-section">3. Affectation par usage</h3>
    <p class="ia-aide">
        Pour qu'un usage ait son propre fournisseur, son modèle ou ses instructions. Enregistrer ici remplace, pour cet usage
        seulement, ce qu'a posé « Une IA pour tout ». Le <b>Rapport</b> sert l'analyse, la conversation et les documents ; les
        <b>Imputations comptables</b>, les propositions de l'écran Écritures comptables.
    </p>
    <div class="ia-grille-2" style="margin-bottom:16px;">
        @foreach ($usages as $usage => $libelle)
            @php($a = $affectations->get($usage))
            <form method="POST" action="{{ route('super-admin.ia.affecter') }}" class="ia-carte">
                @csrf
                <input type="hidden" name="usage" value="{{ $usage }}">
                <strong>{{ $libelle }}</strong>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div>
                        <label class="ia-libelle">Fournisseur</label>
                        <select name="fournisseur" class="champ" style="width:auto" required>
                            @foreach ($fournisseurs as $code => $f)
                                <option value="{{ $code }}" @selected(($a->fournisseur ?? 'anthropic') === $code)>{{ $f['nom'] }}{{ $configurations->has($code) ? '' : ' (non configuré)' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <label class="ia-libelle">Modèle (vide = celui du fournisseur)</label>
                        <input name="modele" class="champ" value="{{ $a->modele ?? '' }}" placeholder="Identifiant exact du modèle">
                    </div>
                </div>
                <label class="ia-libelle">Instructions propres à cet usage</label>
                <textarea name="instructions" class="champ" rows="3" placeholder="Ex. ton, règles de présentation, consignes comptables…">{{ $a->instructions ?? '' }}</textarea>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;">
                    <input type="checkbox" name="actif" value="1" @checked($a->actif ?? false)> IA activée pour cet usage
                </label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="bouton">Enregistrer</button>
                    <button type="submit" formaction="{{ route('super-admin.ia.tester') }}" class="bouton bouton-secondaire">Tester la connexion</button>
                </div>
            </form>
        @endforeach
    </div>

    {{-- ═══════════════ 4. Habilitations de l'IA ═══════════════ --}}
    <h3 class="ia-section" id="habilitations-ia">4. Qui peut utiliser l'IA</h3>
    <div class="carte" style="margin-bottom:16px;">
        <p class="ia-aide">
            Entreprise par entreprise : accorder ou retirer l'usage de l'IA à un rôle ou à un compte précis. Ce qui est dit d'un compte
            l'emporte sur son rôle. L'assistant du Rapport demande aussi « Ouvrir le module Rapport » ; ce geste et les autres
            (modification, suppression, fusion) se règlent dans
            <a href="{{ route('super-admin.maintenance') }}#habilitations">Maintenance › Habilitations</a>, pour toutes les entreprises.
        </p>

        @if ($entreprises->isEmpty())
            <p style="margin:0;color:#6B7280;font-size:13px;">Aucune entreprise.</p>
        @else
            <form method="GET" action="{{ route('super-admin.ia.configuration') }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
                <div>
                    <label class="ia-libelle" for="hab-entreprise">Entreprise</label>
                    <select id="hab-entreprise" name="entreprise" class="champ" style="width:auto">
                        @foreach ($entreprises as $id => $nom)
                            <option value="{{ $id }}" @selected($id === $entrepriseId)>{{ $nom }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bouton bouton-secondaire">Afficher</button>
            </form>

            <div class="tableau-conteneur" style="margin-bottom:12px;">
                <table class="tableau">
                    <thead><tr><th>Usage</th><th>Rôles qui l'ont chez {{ $entreprises[$entrepriseId] }}</th><th>Comptes précis</th></tr></thead>
                    <tbody>
                        @foreach ($gestes as $geste => $g)
                            <tr>
                                <td><b>{{ $g['libelle'] }}</b><div style="font-size:11px;color:#9A9DA5;">{{ $geste }}</div></td>
                                <td style="font-size:12px;">
                                    @forelse ($g['roles'] as $r)
                                        <div style="{{ $r['a'] ? 'color:#0E7A54;font-weight:600;' : 'color:#C8102E;' }}">
                                            @if ($r['a']) {{ $r['nom'] }} @else <s>{{ $r['nom'] }}</s> — retiré @endif
                                            <span style="font-weight:400;color:#9A9DA5;">· {{ $r['origine'] }}</span>
                                            @if ($r['ligne'])
                                                <form method="POST" action="{{ route('super-admin.ia.habilitations.effacer') }}" style="display:inline;">
                                                    @csrf
                                                    <input type="hidden" name="ligne" value="{{ $r['ligne'] }}">
                                                    <input type="hidden" name="entreprise" value="{{ $entrepriseId }}">
                                                    <button type="submit" class="bouton bouton-secondaire" style="padding:2px 8px;font-size:11px;">Effacer</button>
                                                </form>
                                            @endif
                                        </div>
                                    @empty
                                        <span style="color:#9A9DA5;">Aucun rôle</span>
                                    @endforelse
                                </td>
                                <td style="font-size:12px;">
                                    @forelse ($g['comptes'] as $ligne)
                                        <div style="{{ $ligne->accorde ? 'color:#0E7A54;font-weight:600;' : 'color:#C8102E;' }}">
                                            {{ $ligne->utilisateur?->name ?? 'Compte n° '.$ligne->user_id }} · {{ $ligne->accorde ? 'accordé' : 'retiré' }}
                                            <form method="POST" action="{{ route('super-admin.ia.habilitations.effacer') }}" style="display:inline;">
                                                @csrf
                                                <input type="hidden" name="ligne" value="{{ $ligne->id }}">
                                                <input type="hidden" name="entreprise" value="{{ $entrepriseId }}">
                                                <button type="submit" class="bouton bouton-secondaire" style="padding:2px 8px;font-size:11px;">Effacer</button>
                                            </form>
                                        </div>
                                    @empty
                                        <span style="color:#9A9DA5;">—</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @unless ($parEntrepriseDisponible)
                <p style="margin:0 0 8px;color:#B45309;font-size:12.5px;">La mise à jour du serveur n'est pas encore passée (migration du 25/11) : seuls les comptes précis peuvent être habilités ici.</p>
            @endunless

            <form method="POST" action="{{ route('super-admin.ia.habiliter') }}">
                @csrf
                <input type="hidden" name="entreprise_id" value="{{ $entrepriseId }}">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label class="ia-libelle" for="hab-sens">Geste</label>
                        <select id="hab-sens" name="sens" class="champ" style="width:auto">
                            <option value="accorder">Accorder</option>
                            <option value="retirer">Retirer</option>
                        </select>
                    </div>
                    <div>
                        <label class="ia-libelle"><input type="radio" name="beneficiaire" value="role" @checked(old('beneficiaire', 'role') === 'role')> À un rôle</label>
                        <select name="role" class="champ" style="width:auto">
                            <option value="">— rôle —</option>
                            @foreach ($roles as $cle => $nom)
                                <option value="{{ $cle }}" @selected(old('role') === $cle)>{{ $nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="min-width:220px;">
                        <label class="ia-libelle"><input type="radio" name="beneficiaire" value="compte" @checked(old('beneficiaire') === 'compte')> Ou à un compte de {{ $entreprises[$entrepriseId] }}</label>
                        <select name="compte" class="champ" style="width:auto;max-width:100%;">
                            <option value="">— compte —</option>
                            @foreach ($comptes as $c)
                                <option value="{{ $c->id }}" @selected((int) old('compte') === $c->id)>{{ $c->name }} — {{ $c->email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <span class="ia-libelle">Usage</span>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;min-height:34px;align-items:center;">
                            @foreach ($gestes as $geste => $g)
                                <label style="display:flex;gap:6px;align-items:center;font-size:13px;">
                                    <input type="checkbox" name="gestes[]" value="{{ $geste }}"> {{ $g['libelle'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <button type="submit" class="bouton">Enregistrer</button>
                </div>
            </form>
        @endif
    </div>

    <div class="carte">
        <strong>Règles d'utilisation de l'IA comptable</strong>
        <ul style="margin:6px 0 0;font-size:13px;">
            <li>L'IA propose une nature du plan de l'entreprise pour les charges restées en « Autres » ; toute autre réponse est rejetée.</li>
            <li>Une proposition ne change l'écriture qu'une fois validée par une personne.</li>
            <li>L'IA ne déclenche jamais de déversement vers COMPTAFLOW.</li>
        </ul>
    </div>
</div>
