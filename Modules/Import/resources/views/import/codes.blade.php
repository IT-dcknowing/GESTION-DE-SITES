<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Imports\Services\AffectationDesCodes;
use Modules\Noyau\Imports\Services\CodesDesCommerciaux;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Employés & codes — l'annuaire du logiciel d'atelier
|--------------------------------------------------------------------------
| **Cette page a changé de nature, parce que la réalité a changé.**
|
| Elle servait à trancher : la colonne SITE des exports disait « ABIDJAN » sans
| distinguer les deux ateliers, et le code de deux lettres était le seul moyen de
| savoir où ranger une fiche. Il fallait donc dire, code par code, qui travaillait
| où — sans quoi le chiffre d'affaires d'Abidjan restait en un seul bloc.
|
| Ce n'est plus vrai. Dans le logiciel, l'extraction se filtre sur l'atelier —
| Site 1, Site 2, Bouaké, San Pédro — et c'est ce filtre qu'on déclare au dépôt.
| **Le rattachement est décidé au dépôt, par celui qui sort le fichier.** Plus rien
| ne se devine ici.
|
| Ce qui reste, et qui vaut d'être tenu : un annuaire. Qui écrit sous quelles
| initiales. Il sert à lire un nom en clair dans les tableaux plutôt qu'un code, à
| rattacher chaque devis à son commercial pour les indicateurs, et à prévenir au
| dépôt quand les codes d'un fichier désignent une autre ville que celle annoncée.
|
| Les formulaires postent — ils ne dépendent pas de la couche interactive. Le
| bouton « Renseigner » ne répondait pas, et la cause n'était pas dans cette page.
*/

state([
    'recherche' => '',
    'villeFiltre' => '',
]);

$service = fn () => new AffectationDesCodes((int) auth()->user()->entreprise_id);

$peutArbitrer = computed(fn () => AccesImport::peutArbitrer(auth()->user()));

$villes = computed(fn () => Ville::where('est_actif', true)->orderBy('nom')->pluck('nom', 'id')->all());

$tousLesSites = computed(fn () => Site::where('est_actif', true)->orderBy('nom')->get());

$observations = computed(fn () => $this->service()->observations());

/**
 * L'état de chaque ligne, en un mot.
 *
 * Trois états et trois actions différentes, là où il n'y avait qu'un bouton « Renseigner »
 * pour tout le monde — y compris pour les lignes déjà renseignées, ce qui n'avait aucun
 * sens et donnait l'impression que le bouton ne faisait rien.
 */
$etatDe = function (array $ligne): array {
    if ($ligne['ville_id'] !== null && ($ligne['site_id'] !== null || $ligne['sites_possibles']->count() <= 1)) {
        return ['cle' => 'complet', 'libelle' => 'Complet', 'classe' => 'pTermine', 'action' => 'Modifier'];
    }

    if ($ligne['ville_id'] !== null) {
        return ['cle' => 'atelier', 'libelle' => 'Atelier à préciser', 'classe' => 'pPresume', 'action' => 'Préciser'];
    }

    if ($ligne['ville_proposee'] !== null) {
        return ['cle' => 'propose', 'libelle' => 'Proposition à confirmer', 'classe' => 'pControle', 'action' => 'Choisir'];
    }

    return ['cle' => 'vide', 'libelle' => 'À renseigner', 'classe' => 'pVide', 'action' => 'Renseigner'];
};

$listeFiltree = computed(function () {
    $cherche = trim(mb_strtolower($this->recherche));
    $ville = $this->villeFiltre;

    return $this->observations
        ->when($cherche !== '', fn ($lignes) => $lignes->filter(
            fn (array $o) => str_contains(mb_strtolower($o['code']), $cherche)
                || str_contains(mb_strtolower((string) $o['libelle']), $cherche),
        ))
        ->when($ville === 'sans', fn ($lignes) => $lignes->filter(fn (array $o) => $o['ville_id'] === null))
        ->when(is_numeric($ville), fn ($lignes) => $lignes->filter(
            fn (array $o) => (int) $o['ville_id'] === (int) $ville,
        ))
        ->values();
});

$aNommer = computed(fn () => $this->observations->whereNull('ville_id')->count());

$jamaisVus = computed(fn () => $this->observations->where('occurrences', 0)->count());

/** Combien de codes désignent réellement quelqu'un — voir CodesDesCommerciaux. */
$couverture = computed(fn () => CodesDesCommerciaux::pour((int) auth()->user()->entreprise_id)->couverture());

/**
 * Ce que le rattachement donne aujourd'hui, en devis.
 *
 * Compté en base et non déduit : c'est le seul chiffre qui dit si la chaîne « code →
 * compte → commercial » sert à quelque chose. Il valait 0 sur 2 432 au 25/09.
 */
$devis = computed(fn () => [
    'total' => Devis::query()->count(),
    'rattaches' => Devis::query()->whereNotNull('commercial_id')->count(),
]);

/**
 * Combien de devis portent chaque code, et quel commercial ce code désigne.
 *
 * En une requête pour toute la table : une par ligne aurait fait trente-neuf requêtes
 * pour une page qu'on ouvre pour lire, pas pour attendre.
 *
 * @return array{devis: array<string, int>, commerciaux: array<string, string>}
 */
$parCode = computed(function () {
    $devis = Devis::query()
        ->whereNotNull('code_auteur')
        ->selectRaw('code_auteur, count(*) as n')
        ->groupBy('code_auteur')
        ->pluck('n', 'code_auteur')
        ->mapWithKeys(fn ($n, $code) => [mb_strtoupper((string) $code) => (int) $n])
        ->all();

    $commerciaux = DB::table('codes_agents')
        ->join('commerciaux', function ($jointure) {
            $jointure->on('commerciaux.user_id', '=', 'codes_agents.user_id')
                ->on('commerciaux.entreprise_id', '=', 'codes_agents.entreprise_id');
        })
        ->where('codes_agents.entreprise_id', auth()->user()->entreprise_id)
        ->whereNotNull('codes_agents.user_id')
        ->pluck('commerciaux.nom', 'codes_agents.code')
        ->mapWithKeys(fn ($nom, $code) => [mb_strtoupper((string) $code) => (string) $nom])
        ->all();

    return ['devis' => $devis, 'commerciaux' => $commerciaux];
});

/** Les mutations récentes — c'est ce qui explique un code vu dans deux villes. */
$mutations = computed(fn () => Reaffectation::where('entreprise_id', auth()->user()->entreprise_id)
    ->whereNotNull('code_agent_id')
    ->with(['code', 'villeAvant', 'villeApres', 'siteAvant', 'siteApres'])
    ->latest()
    ->limit(10)
    ->get());

?>

<x-import::coquille page="codes">

    @error('code')
        <div class="imp-hint warn" style="margin-bottom:14px;">{{ $message }}</div>
    @enderror

    <div class="imp-kpis trois">
        <div class="imp-kpi">
            <div class="lab">Employés connus</div>
            <div class="val">{{ $this->observations->count() }}</div>
            <div class="sub">dans le logiciel d'atelier</div>
        </div>
        <div class="imp-kpi {{ $this->aNommer > 0 ? '' : 'vert' }}">
            <div class="lab">Lieu non renseigné</div>
            <div class="val">{{ $this->aNommer }}</div>
            <div class="sub">information, pas blocage</div>
        </div>
        {{-- « Jamais croisés » ne se traduisait par aucune action : le propriétaire a
             demandé le 25/09 de le retirer. Ce qui appelle une action, en revanche, c'est
             le code qui ne désigne personne — ses devis restent sans commercial. --}}
        <div class="imp-kpi {{ $this->couverture['relies'] < $this->couverture['total'] ? '' : 'vert' }}">
            <div class="lab">Reliés à un commercial</div>
            <div class="val">{{ $this->couverture['relies'] }}/{{ $this->couverture['total'] }}</div>
            <div class="sub">sinon le devis reste sans commercial</div>
        </div>
    </div>

    {{-- --------------------------------------------------------------- ce que fait la page --}}
    <div class="imp-carte">
        <h2>L'annuaire des employés <span class="chip">{{ $this->observations->count() }} codes</span></h2>

        <div style="font-size:14px; line-height:1.65;">
            Chaque employé du logiciel d'atelier écrit sous deux lettres, inscrites dans le numéro de
            chaque fiche qu'il rédige&nbsp;:
            <span style="font-family:ui-monospace,Consolas,monospace; background:#F4F2EC; padding:2px 6px;
                         border-radius:4px;">FR-<strong>KZ</strong>N° 010669</span>.
            C'est par elles qu'un devis importé <strong>peut</strong> rejoindre son commercial, et que les
            tableaux affichent un nom plutôt qu'un code.
        </div>

        {{-- **Ce paragraphe disait une promesse, il dit maintenant une mesure.**

             « C'est par elles que les devis importés rejoignent leur commercial » : c'était
             faux, et le propriétaire l'a relevé le 25/09. Mesuré ce jour-là, 2 432 devis
             importés sur 2 432 n'avaient ni code ni commercial. Deux causes : l'import
             jetait le code après s'en être servi pour la ville — c'est réparé —, et aucun
             des codes n'est relié à un compte, donc à un commercial.

             Un écran qui promet ce qu'il ne fait pas coûte plus cher qu'un écran qui se
             tait : on ne cherche pas la cause d'un manque qu'on croit déjà couvert. --}}
        <div class="imp-hint {{ $this->couverture['relies'] === 0 ? 'warn' : ($this->couverture['relies'] < $this->couverture['total'] ? '' : 'ok') }}"
             style="margin-top:11px;">
            <strong>{{ $this->couverture['relies'] }} code(s) sur {{ $this->couverture['total'] }}
                désignent un commercial.</strong>
            @if ($this->couverture['relies'] < $this->couverture['total'])
                Les autres sont des initiales sans personne derrière : leurs devis restent
                <strong>sans commercial</strong>, et le chiffre de celui qui a décroché l'affaire ne les
                compte pas. Le lien se fait ici, code par code, en ouvrant une ligne — il ne se devine
                pas : attribuer un chiffre d'affaires sur une ressemblance de noms se trompe un jour,
                et ce jour-là rien ne l'affiche.
            @else
                Chaque devis importé rejoint donc son commercial par le code de son numéro.
            @endif
            @if ($this->devis['total'] > 0)
                <div style="margin-top:7px;">
                    Aujourd'hui : <strong>{{ number_format($this->devis['rattaches'], 0, ',', ' ') }}</strong>
                    devis rattachés sur <strong>{{ number_format($this->devis['total'], 0, ',', ' ') }}</strong>.
                </div>
            @endif
        </div>

        {{-- Deux cas, et il faut les tenir tous les deux. Ne dire que le premier laissait
             croire que cet écran ne servait plus à ranger quoi que ce soit. --}}
        <div class="imp-hint ok" style="margin-top:11px;">
            <strong>Selon le fichier, le lieu vient d'ici ou du dépôt. Les deux cas existent.</strong>
            <ul style="margin:7px 0 0; padding-left:20px; line-height:1.65;">
                <li><strong>Extraction filtrée</strong> — vous avez filtré sur Site&nbsp;1, Site&nbsp;2,
                    Bouaké ou San&nbsp;Pédro dans le logiciel, et vous le déclarez en déposant. C'est cette
                    déclaration qui range les lignes&nbsp;: le code n'a rien à trancher.</li>
                <li><strong>Extraction non filtrée</strong> — le fichier sort d'un bloc, les trois villes
                    mêlées. Vous déposez alors sous « <em>Toutes les villes</em> », et <strong>ce sont les
                    codes ci-dessous qui ventilent chaque ligne</strong>. Un code sans lieu laisse sa ligne
                    indéterminée jusqu'à ce qu'il soit renseigné.</li>
            </ul>
            Dans les deux cas, l'annuaire sert aussi à lire un nom plutôt qu'un code, à rattacher les devis
            à leur commercial, et à prévenir au dépôt quand les codes désignent une autre ville que celle
            annoncée.
        </div>

    </div>

    {{-- ------------------------------------------------------------------ la table des codes --}}
    <div class="imp-carte">
        <h2>
            Les employés
            <span class="chip">{{ $this->listeFiltree->count() }} affiché(s) · les plus actifs d'abord</span>
        </h2>

        <div class="imp-frm deux" style="margin-bottom:13px;">
            <div class="imp-fld">
                <label for="q">Chercher un nom ou un code</label>
                <input type="search" id="q" wire:model.live.debounce.250ms="recherche"
                       value="{{ $recherche }}" placeholder="KZ, Keita, Bahintchie…">
            </div>
            <div class="imp-fld">
                <label for="vf">Ville</label>
                <select id="vf" wire:model.live="villeFiltre">
                    <option value="" @selected($villeFiltre === '')>Toutes</option>
                    <option value="sans" @selected($villeFiltre === 'sans')>Lieu non renseigné</option>
                    @foreach ($this->villes as $id => $nom)
                        <option value="{{ $id }}" @selected((string) $villeFiltre === (string) $id)>{{ $nom }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($this->observations->isEmpty())
            <div class="imp-hint">
                Aucun code n'a encore été rencontré.
                <a href="{{ route('import.depot') }}" wire:navigate style="font-weight:700; color:#C8102E;">
                    Déposez une situation du parc</a> — les codes apparaîtront ici tout seuls, avec leur volume.
            </div>
        @else
            <div class="imp-tbl-wrap">
                <table class="imp-tbl">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Employé</th>
                            <th>Commercial désigné</th>
                            <th class="num">Fiches</th>
                            <th class="num">Devis</th>
                            <th>Ville</th>
                            <th>Atelier</th>
                            <th>État</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->listeFiltree as $o)
                            @php $etat = $this->etatDe($o); @endphp
                            <tr wire:key="code-{{ $o['code'] }}">
                                <td class="mono"><strong style="font-size:14px;">{{ $o['code'] }}</strong></td>
                                <td>{{ $o['libelle'] ?: '—' }}</td>
                                {{-- La colonne qui manquait, et qui explique le reste : un code sans
                                     commercial laisse tous ses devis sans commercial. --}}
                                <td>
                                    @if ($nomDuCommercial = ($this->parCode['commerciaux'][mb_strtoupper($o['code'])] ?? null))
                                        {{ $nomDuCommercial }}
                                    @else
                                        <span style="color:#B0000A;">— non relié —</span>
                                    @endif
                                </td>
                                <td class="num">{{ $o['dossiers'] ? number_format($o['dossiers'], 0, ',', ' ') : '·' }}</td>
                                <td class="num">
                                    {{ ($n = ($this->parCode['devis'][mb_strtoupper($o['code'])] ?? 0))
                                        ? number_format($n, 0, ',', ' ') : '·' }}
                                </td>
                                <td>
                                    @if ($o['ville_id'])
                                        {{ $this->villes[$o['ville_id']] ?? '?' }}
                                    @elseif ($o['ville_proposee'])
                                        <span style="color:#6B6E76;">{{ $this->villes[$o['ville_proposee']] ?? '?' }} <em>(proposé)</em></span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if ($o['site_id'])
                                        {{ $this->tousLesSites->firstWhere('id', $o['site_id'])?->nom ?? '—' }}
                                    @elseif ($o['sites_possibles']->count() === 1)
                                        <span style="color:#6B6E76;">{{ $o['sites_possibles']->first()->nom }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                {{-- L'état d'abord, l'action ensuite — et l'action nommée d'après
                                     l'état. Un bouton « Renseigner » sur une ligne déjà renseignée
                                     ne veut rien dire, et on finit par croire qu'il ne marche pas. --}}
                                <td><span class="pastille {{ $etat['classe'] }}">{{ $etat['libelle'] }}</span></td>
                                <td>
                                    {{-- Un lien vers une page, et non un volet qui s'ouvre
                                         hors de l'écran : on sait toujours où l'on est. --}}
                                    <a href="{{ route('import.codes.fiche', $o['code']) }}"
                                       class="imp-btn p" style="text-decoration:none; display:inline-block;">
                                        {{ $this->peutArbitrer ? $etat['action'] : 'Consulter' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->listeFiltree->isEmpty())
                <div class="imp-hint">
                    Aucun employé ne correspond à ce filtre. L'annuaire en compte
                    {{ $this->observations->count() }}.
                </div>
            @endif
        @endif
    </div>

    {{-- ------------------------------------------------------------------- les mutations --}}
    <div class="imp-carte">
        <h2>Mutations récentes <span class="chip">lecture seule</span></h2>

        <div class="imp-hint">
            <strong>Un code vu dans deux villes n'est pas une anomalie</strong> — c'est presque toujours
            quelqu'un qui a été muté. Ses anciennes fiches restent dans la ville où le travail a eu lieu ;
            seules les suivantes partent au nouveau lieu. Les mutations se décident dans
            <strong>Général → Paramètres → Personnel</strong>, par le gérant.
        </div>

        @if ($this->mutations->isEmpty())
            <div class="imp-hint" style="margin-top:9px;">Aucune mutation enregistrée à ce jour.</div>
        @else
            <div class="imp-tbl-wrap" style="margin-top:11px;">
                <table class="imp-tbl">
                    <thead><tr><th>Date</th><th>Code</th><th>Depuis</th><th>Vers</th><th>Motif</th></tr></thead>
                    <tbody>
                        @foreach ($this->mutations as $m)
                            <tr wire:key="mut-{{ $m->id }}">
                                <td style="white-space:nowrap;">{{ $m->created_at?->format('d/m/Y') }}</td>
                                <td class="mono"><strong>{{ $m->code?->code ?? '—' }}</strong></td>
                                <td>{{ $m->siteAvant?->nom ?? $m->villeAvant?->nom ?? '—' }}</td>
                                <td>{{ $m->siteApres?->nom ?? $m->villeApres?->nom ?? '—' }}</td>
                                <td>{{ $m->motif ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-import::coquille>
