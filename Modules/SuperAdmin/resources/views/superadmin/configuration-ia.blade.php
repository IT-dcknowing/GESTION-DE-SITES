<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\SuperAdmin\Http\Controllers\ConfigurationIAController;

use function Livewire\Volt\with;

/*
 * Erreur en ligne du 24/11 : « Undefined variable $usages ». Dans un composant Volt, les
 * variables écrites en tête de fichier restent locales au bloc PHP : la vue ne les voit pas.
 * Elles passent donc par `with()`, relues à chaque rendu.
 */
with(fn () => [
    'configurations' => DB::table('configurations_ia')->get()->keyBy('fournisseur'),
    'affectations' => Schema::hasTable('affectations_ia') ? DB::table('affectations_ia')->get()->keyBy('usage') : collect(),
    'usages' => ConfigurationIAController::USAGES,
    'fournisseurs' => [
        'openai' => ['nom' => 'OpenAI', 'modele' => 'gpt-6'],
        'anthropic' => ['nom' => 'Anthropic', 'modele' => 'claude'],
        'google' => ['nom' => 'Google Gemini', 'modele' => 'gemini'],
        'openrouter' => ['nom' => 'OpenRouter', 'modele' => ''],
    ],
]);
?>

<div>
    <x-titre-ecran titre="Intelligence artificielle"
        sous-titre="Les clés des fournisseurs d'IA, et l'IA affectée à chaque usage : le rapport, les imputations comptables. Accès réservé au superadministrateur." />

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

    <div class="carte" style="margin-bottom:16px;">
        <strong>Protection des clés</strong>
        <p style="margin:6px 0 0;color:#6B7280;font-size:13px;">
            Les clés API sont chiffrées en base. Elles ne sont jamais réaffichées.
            Laisser le champ vide conserve la clé déjà enregistrée.
        </p>
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">Affectation des IA par usage</h3>
        <p style="margin:0 0 12px;color:#6B7280;font-size:13px;">
            Chaque usage a son IA : le <b>Rapport</b> (analyse, conversation, documents) et les <b>Imputations comptables</b>.
            Choisissez un fournisseur déjà configuré plus bas ; le modèle et les instructions sont propres à l'usage.
            L'accès au Rapport et à son assistant se donne dans Habilitations (domaine « Rapport financier »).
        </p>
        @foreach ($usages as $usage => $libelle)
            @php($a = $affectations->get($usage))
            <form method="POST" action="{{ route('super-admin.ia.affecter') }}" style="border-top:1px solid #E5E7EB;padding:12px 0;">
                @csrf
                <input type="hidden" name="usage" value="{{ $usage }}">
                <strong>{{ $libelle }}</strong>
                @if ($usage === 'imputations')
                    <span style="font-size:12px;color:#B45309;"> — affectation prête ; le moteur d'imputation n'est pas encore branché.</span>
                @endif
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
                    <div>
                        <label style="display:block;font-size:12px;">Fournisseur</label>
                        <select name="fournisseur" class="champ" required>
                            @foreach ($fournisseurs as $code => $f)
                                <option value="{{ $code }}" @selected(($a->fournisseur ?? 'anthropic') === $code)>{{ $f['nom'] }}{{ $configurations->has($code) ? '' : ' (non configuré)' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;font-size:12px;">Modèle (vide = celui du fournisseur)</label>
                        <input name="modele" class="champ" value="{{ $a->modele ?? '' }}" placeholder="Identifiant exact du modèle">
                    </div>
                </div>
                <label style="display:block;font-size:12px;margin-top:8px;">Instructions propres à cet usage</label>
                <textarea name="instructions" class="champ" rows="3" placeholder="Ex. ton, règles de présentation, consignes comptables…">{{ $a->instructions ?? '' }}</textarea>
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0;">
                    <input type="checkbox" name="actif" value="1" @checked($a->actif ?? false)> IA activée pour cet usage
                </label>
                <button type="submit" class="bouton">Enregistrer</button>
                <button type="submit" formaction="{{ route('super-admin.ia.tester') }}" class="bouton bouton-secondaire">Tester la connexion</button>
            </form>
        @endforeach
    </div>

    <h3 style="margin:18px 0 8px;">Fournisseurs et clés</h3>
    @foreach ($fournisseurs as $code => $fournisseur)
        @php($configuration = $configurations->get($code))
        <div class="carte" style="margin-bottom:14px;">
            <h3 style="margin-top:0;">{{ $fournisseur['nom'] }}</h3>
            <form method="POST" action="{{ route('super-admin.ia.enregistrer') }}">
                @csrf
                <input type="hidden" name="fournisseur" value="{{ $code }}">

                <label for="modele-{{ $code }}">Modèle</label>
                <input id="modele-{{ $code }}" name="modele" class="champ"
                    value="{{ old('modele', $configuration->modele ?? $fournisseur['modele']) }}"
                    placeholder="Identifiant exact du modèle" required>

                <label for="cle-{{ $code }}" style="display:block;margin-top:12px;">Clé API</label>
                <input id="cle-{{ $code }}" name="cle_api" type="password" class="champ"
                    autocomplete="new-password"
                    placeholder="{{ $configuration ? 'Clé enregistrée — saisir uniquement pour la remplacer' : 'Saisir la clé API' }}">

                <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
                    <input type="checkbox" name="actif" value="1" @checked($configuration?->actif)>
                    Fournisseur disponible pour les IA de l'application
                </label>

                <p style="font-size:12px;color:#6B7280;">
                    Statut : {{ $configuration ? ($configuration->actif ? 'Actif' : 'Configuré, désactivé') : 'Non configuré' }}
                </p>
                <button type="submit" class="bouton">Enregistrer la configuration</button>
            </form>
        </div>
    @endforeach

    <div class="carte">
        <h3>Règles d'utilisation de l'IA comptable</h3>
        <ul>
            <li>L'IA propose les comptes à partir des libellés, pièces et règles de correspondance.</li>
            <li>Les comptes, journaux et tiers proposés doivent exister dans le référentiel COMPTAFLOW ou être créés par son mécanisme natif.</li>
            <li>Une proposition incertaine doit être signalée et soumise à validation humaine.</li>
            <li>L'IA ne déclenche jamais directement un déversement comptable sans validation.</li>
        </ul>
        <p style="font-size:12px;color:#6B7280;">
            Les clés et les modèles se règlent ici, l'affectation par usage au-dessus, avec un bouton de test réel. Le moteur d'imputation sera branché dans une étape distincte.
        </p>
    </div>
</div>
