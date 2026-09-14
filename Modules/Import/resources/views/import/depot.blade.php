<?php

use Modules\Import\Http\Controllers\DepotController;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\EtatDeLaFile;
use Modules\Noyau\Imports\Services\NomDeFichier;
use Modules\Noyau\Imports\Services\TableauDesImports;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Déposer un fichier
|--------------------------------------------------------------------------
| **Cet écran ne dépose plus rien lui-même.** Le formulaire est un formulaire HTML
| ordinaire, qui poste vers `import.deposer`. Le composant ne sert qu'à ce qui vient
| après : suivre l'avancée du lot en cours et tenir le tableau de bord des imports.
|
| Ce n'est pas un recul, c'est une correction. Le dépôt reposait entièrement sur la
| couche interactive, et le jour où celle-ci n'a pas démarré dans un navigateur, le
| bouton n'a plus rien fait — sans message, sans erreur, sans trace dans le journal
| du serveur. Le mécanisme le plus ancien du web est aussi celui qui ne peut pas
| tomber en panne en silence : ou la page change, ou elle affiche une erreur.
|
| Conséquence directe : **les trois listes portent leur valeur dans le HTML**
| (`selected`, `value`). Une page qui affiche « à choisir » alors que le serveur tient
| « Abidjan » raconte quelque chose de faux, et l'on cherche ensuite le défaut là où
| il n'est pas.
*/

state([
    'lotSuivi' => null,
]);

mount(function () {
    // Le lot vient de l'adresse : c'est le contrôleur qui y renvoie après un dépôt réussi.
    $demande = request()->integer('lot');

    if ($demande > 0) {
        $lot = LotImport::find($demande);

        if ($lot !== null) {
            $this->lotSuivi = $lot->id;
        }
    }
});

$service = fn () => new Depot((int) auth()->user()->entreprise_id);

$villesOuvertes = computed(fn () => $this->service()->villesOuvertes(auth()->user()));

/**
 * Les ateliers de chaque ville — toutes les villes à la fois.
 *
 * Tout est rendu d'un coup, et le navigateur ne montre que ce qui correspond à la ville
 * choisie. La version précédente demandait un aller-retour au serveur pour peupler cette
 * liste : le champ restait donc inerte tant que le serveur n'avait pas répondu, et il
 * paraissait cassé. Un champ dont l'existence dépend d'une requête réseau est un champ
 * qu'on croit cassé.
 *
 * @return array<int, array<int, string>>
 */
$ateliersParVille = computed(function () {
    $par = [];

    foreach (array_keys($this->villesOuvertes) as $villeId) {
        $par[(int) $villeId] = $this->service()->sitesDeLaVille((int) $villeId);
    }

    return $par;
});

$formats = computed(fn () => Registre::options());

$peutDeposer = computed(fn () => AccesImport::peutDeposer(auth()->user()));

$tableau = computed(fn () => (new TableauDesImports((int) auth()->user()->entreprise_id))->lignes());

$lot = computed(fn () => $this->lotSuivi ? LotImport::find($this->lotSuivi) : null);

/**
 * Ce que la ventilation par les codes vaut aujourd'hui.
 *
 * Un dépôt « toutes les villes » ne range que ce que les codes savent situer. Annoncer
 * l'option sans dire combien de codes sont renseignés, c'est offrir un bouton qui laisse
 * tout indéterminé et laisser conclure que le rattachement est cassé.
 *
 * @return array{total: int, situes: int, part: float}
 */
$couvertureDesCodes = computed(function () {
    $requete = CodeAgent::where('entreprise_id', auth()->user()->entreprise_id);
    $total = (clone $requete)->count();
    $situes = (clone $requete)->whereNotNull('ville_id')->count();

    return [
        'total' => $total,
        'situes' => $situes,
        'part' => $total > 0 ? $situes / $total : 0.0,
    ];
});

/** L'état du travail de fond : combien attendent, et depuis quand. */
$file = computed(fn () => (new EtatDeLaFile((int) auth()->user()->entreprise_id))->mesurer());

/** Le contrôle préalable qui attend une réponse, s'il y en a un. */
$controle = computed(fn () => session(DepotController::CLE_ATTENTE));

$abandonner = function () {
    $garde = session()->pull(DepotController::CLE_ATTENTE);

    if (is_array($garde) && isset($garde['chemin'])) {
        \Illuminate\Support\Facades\Storage::delete($garde['chemin']);
    }
};

?>

<x-import::coquille page="depot">

    @if (! $this->peutDeposer)
        <div class="imp-lock">
            Votre rôle vous permet de consulter les imports, pas d'en déposer.
            Le dépôt engage la base : il est réservé au gérant et aux responsables d'une ville.
        </div>
    @else

        @if (session('annonce-import'))
            <x-boite-message titre="Fichier reçu" ton="succes">
                {{ session('annonce-import') }}
                <p style="margin:10px 0 0;">
                    Vous pouvez quitter cette page ou déposer un autre fichier&nbsp;: le traitement continue
                    de son côté. Son avancée s'affiche ci-dessous.
                </p>
            </x-boite-message>
        @endif

        @if (session('refus-import'))
            <x-boite-message titre="Dépôt refusé" ton="alerte">{{ session('refus-import') }}</x-boite-message>
        @endif

        {{-- ------------------------------------------------- le travail de fond, en clair

             « Lu en arrière-plan » supposait qu'on sache par qui. Si aucun exécuteur de
             file ne tourne, le dépôt réussit, l'écran dit « Déposé », le compteur reste à
             zéro — et rien ne dit que personne ne viendra jamais. On attend devant une
             progression qui n'a pas commencé. Ce bandeau ne s'affiche que quand il a
             quelque chose à dire. --}}
        @php $f = $this->file; @endphp
        @if ($f['executeur_douteux'])
            <div class="imp-hint warn" style="margin-bottom:14px;">
                <strong>Un traitement attend depuis {{ (int) round($f['plus_ancien'] / 60) }} minute(s) sans démarrer.</strong>
                Le fichier est bien arrivé et il est rangé&nbsp;: il manque l'ouvrier qui vide la file
                d'attente en arrière-plan. Vous pouvez faire le travail vous-même —
                <a href="{{ route('import.traitements') }}" style="font-weight:700; color:#C8102E;">page Traitement</a>
                — ou lancer l'ouvrier une fois pour toutes sur le serveur&nbsp;:
                <code>php artisan queue:work</code>.
            </div>
        @elseif ($f['en_file'] + $f['en_cours'] + $f['en_attente'] > 0)
            <div class="imp-hint" style="margin-bottom:14px;">
                <strong>{{ $f['en_cours'] + $f['en_attente'] }} import(s) en cours de traitement.</strong>
                Vous pouvez en déposer d'autres sans attendre&nbsp;: chaque dépôt est un travail
                séparé, ils se suivent dans la file et ne se gênent pas.
                @if ($f['echecs'] > 0)
                    <br><strong style="color:#C8102E;">{{ $f['echecs'] }} travail(aux) en échec</strong>
                    dans la file — le journal des imports dit lesquels.
                @endif
            </div>
        @endif

        {{-- ================================================ LE CONTRÔLE QUI ATTEND ===== --}}
        @if ($this->controle)
            @php $c = $this->controle; $r = $c['rapport']; @endphp
            <div class="imp-carte" style="border-left:4px solid #B87A00; margin-bottom:15px;">
                <h2>Vérifiez avant de lancer <span class="chip">{{ $c['nom'] }}</span></h2>

                @foreach ($r['avertissements'] as $mot)
                    <div class="imp-hint warn">{{ $mot }}</div>
                @endforeach

                @if ($r['codes_etrangers'])
                    <div class="imp-hint">
                        <strong>Les codes en cause.</strong>
                        @foreach ($r['codes_etrangers'] as $code => $qui)
                            <span style="display:inline-block; margin-right:12px;">
                                <code>{{ $code }}</code> — {{ $qui }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="imp-hint">
                    <strong>Votre fichier est gardé de côté</strong> — vous n'avez pas à le ressortir de votre
                    disque. Corrigez le type ou la ville ci-dessous et relancez, ou bien confirmez si vous savez
                    que le fichier est le bon&nbsp;: un employé muté travaille un temps avec le code de son
                    ancienne ville, et le logiciel ne peut pas le deviner.
                </div>

                <div class="imp-actions">
                    <form method="POST" action="{{ route('import.deposer') }}" style="display:inline;">
                        @csrf
                        <input type="hidden" name="confirme" value="1">
                        <input type="hidden" name="format" value="{{ old('format') }}">
                        <input type="hidden" name="ville" value="{{ old('ville') }}">
                        <input type="hidden" name="site" value="{{ old('site') }}">
                        <button type="submit" class="imp-btn r">Importer quand même</button>
                    </form>

                    <button type="button" class="imp-btn o" wire:click="abandonner">Abandonner ce fichier</button>
                </div>
            </div>
        @endif

        {{-- ===================================================== LA ZONE DE DÉPÔT ===== --}}
        <div class="imp-carte">
            <h2>Déposer un fichier <span class="chip">.xls · .xlsx · .xlsm — 40 Mo au plus</span></h2>

            <form method="POST" action="{{ route('import.deposer') }}" enctype="multipart/form-data"
                  id="frm-depot">
                @csrf

                <div class="imp-zone" id="zone-depot">
                    <div class="imp-zone-vide">
                        <span class="fleche">⇪</span>
                        <span class="titre">Glissez le fichier ici</span>
                        <span class="det">ou choisissez-le sur votre ordinateur :</span>

                        <input type="file" id="fichier" name="fichier"
                               accept=".xls,.xlsx,.xlsm" class="imp-zone-champ" required>
                    </div>
                </div>

                @error('fichier') <div class="imp-hint warn">{{ $message }}</div> @enderror

                {{-- Les trois champs sont toujours présents, toujours actifs, et portent
                     leur valeur. L'atelier n'attend plus le serveur pour se remplir : tous
                     les ateliers sont là, le navigateur n'affiche que ceux de la ville
                     retenue. Sans JavaScript, la liste complète reste utilisable — chaque
                     entrée dit sa ville. --}}
                <div class="imp-frm" style="margin-top:15px;">
                    <div class="imp-fld">
                        <label for="format">Type de fichier</label>
                        <select id="format" name="format" required>
                            <option value="">— à choisir —</option>
                            @foreach ($this->formats as $cle => $libelle)
                                <option value="{{ $cle }}" @selected(old('format') === $cle)>{{ $libelle }}</option>
                            @endforeach
                        </select>
                        @error('format') <div class="imp-hint warn">{{ $message }}</div> @enderror
                    </div>

                    <div class="imp-fld">
                        <label for="ville">Ville du dépôt</label>
                        <select id="ville" name="ville" required
                                @if (count($this->villesOuvertes) === 1) data-unique="1" @endif>
                            <option value="">— à choisir —</option>
                            @foreach ($this->villesOuvertes as $id => $nom)
                                <option value="{{ $id }}"
                                    @selected((string) old('ville', count($this->villesOuvertes) === 1 ? $id : '') === (string) $id)>
                                    {{ $nom }}
                                </option>
                            @endforeach
                            {{-- Tous les exports ne sont pas filtrés, et certains ne le seront
                                 jamais : ils sortent en un seul bloc, les trois villes mêlées.
                                 Cette valeur ne renonce pas au rattachement — elle le confie
                                 aux codes employés, qui sont dans la donnée. --}}
                            @if (count($this->villesOuvertes) > 1)
                                <option value="{{ DepotController::TOUTES_LES_VILLES }}"
                                    @selected(old('ville') === DepotController::TOUTES_LES_VILLES)>
                                    Toutes les villes — fichier non filtré
                                </option>
                            @endif
                        </select>
                        @error('ville') <div class="imp-hint warn">{{ $message }}</div> @enderror
                    </div>

                    <div class="imp-fld">
                        <label for="site">Atelier <span style="font-weight:400; color:#6B6E76;">(facultatif)</span></label>
                        <select id="site" name="site">
                            <option value="" data-ville="">— tous / non filtré —</option>
                            @foreach ($this->ateliersParVille as $villeId => $ateliers)
                                @foreach ($ateliers as $id => $nom)
                                    <option value="{{ $id }}" data-ville="{{ $villeId }}"
                                        @selected((string) old('site') === (string) $id)>{{ $nom }}</option>
                                @endforeach
                            @endforeach
                        </select>
                        @error('site') <div class="imp-hint warn">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- La boîte s'ouvre au moment du choix, parce que c'est là qu'elle sert.
                     Elle est rendue visible d'emblée quand le formulaire revient avec cette
                     valeur : sans JavaScript, l'explication doit tout de même arriver. --}}
                <div id="avis-toutes-villes" class="imp-hint warn"
                     @unless (old('ville') === DepotController::TOUTES_LES_VILLES) hidden @endunless>
                    <strong>Ce fichier sera ventilé par les codes du personnel.</strong>
                    Vous déposez sans déclarer de ville : chaque ligne ira donc là où la désigne son
                    contenu — d'abord la colonne SITE quand le fichier en a une, puis
                    <strong>le code de deux lettres de l'employé qui a rédigé la fiche</strong>
                    (<code>FR-KZN° 010669</code> → KZ). Une ligne dont le code n'est rattaché à personne
                    reste indéterminée plutôt que d'être rangée au hasard&nbsp;: elle attendra que ce code
                    soit renseigné dans <a href="{{ route('import.codes') }}" style="font-weight:700; color:#C8102E;">Employés & codes</a>,
                    et le prochain import la remettra en place.

                    @php $c = $this->couvertureDesCodes; @endphp
                    <div style="margin-top:9px; padding-top:8px; border-top:1px dashed #E3E0D8; font-weight:400;">
                        <strong>Aujourd'hui, {{ $c['situes'] }} code(s) sur {{ $c['total'] }} portent une ville.</strong>
                        @if ($c['part'] < 0.5)
                            C'est peu&nbsp;: une bonne part des lignes resterait sans lieu. Si votre fichier
                            <em>peut</em> être filtré dans le logiciel, filtrez-le et déclarez la ville — le
                            résultat sera meilleur. Sinon, déposez tout de même&nbsp;: ce qui reste indéterminé
                            se replacera de lui-même au prochain import, une fois les codes renseignés.
                        @else
                            La ventilation devrait bien se passer. Ce qui reste indéterminé se replacera de
                            lui-même au prochain import, une fois les codes manquants renseignés.
                        @endif
                    </div>
                </div>

                <div class="imp-hint">
                    <strong>L'atelier ne se remplit que là où il y a un choix à faire.</strong>
                    Bouaké et San Pédro n'ont qu'un atelier : la ville suffit. Abidjan en a deux, et la
                    colonne SITE des exports dit seulement « ABIDJAN ». Si vous avez filtré l'extraction sur
                    un atelier dans le logiciel, indiquez-le ici et tout l'import ira là. Sinon, laissez
                    « non filtré » : le rattachement se fera par le code employé, et ce que personne ne sait
                    trancher reste au niveau de la ville plutôt que d'être rangé au hasard entre deux ateliers.
                </div>

                <div class="imp-actions">
                    <button type="submit" class="imp-btn r">Lancer l'import</button>

                    <a href="{{ route('import.lots') }}" class="imp-btn o"
                       style="text-decoration:none; display:inline-block;">Journal des imports</a>
                </div>
            </form>

            <div class="imp-hint">
                <strong>Le nom du fichier n'est qu'une indication.</strong> Le rattachement de chaque ligne se
                fait sur son contenu — la colonne SITE et le code de l'employé — jamais sur le nom, qui est
                réécrit à la main. La convention conseillée reste <code>{{ NomDeFichier::CONVENTION }}</code>.
                Avant d'écrire, le fichier est comparé à tous les formats connus&nbsp;: si les colonnes ne
                sont pas celles du type annoncé, ou si les codes désignent une autre ville, on vous le dit.
            </div>

            {{-- Le refus barre l'écran. Il l'a fait une fois sans être vu : le message
                 s'était glissé entre deux cadres, à l'endroit exact où l'œil ne s'arrête
                 pas, et la personne a conclu qu'il ne s'était rien passé. --}}
            @if (session('doublon-import'))
                @php $d = session('doublon-import'); @endphp
                <x-boite-message titre="Ce fichier est déjà passé" ton="alerte"
                    :lien="route('import.lot', $d['id'])" libelle-lien="Voir ce dépôt">
                    {{ $d['message'] }}
                    <p style="margin:10px 0 0;">
                        <strong>Rien n'a été refait ni écrit deux fois.</strong> L'empreinte du contenu est
                        identique à celle d'un dépôt existant — le nom du fichier n'y change rien, c'est
                        bien le même classeur.
                    </p>
                </x-boite-message>
            @endif
        </div>

        {{-- Glisser-déposer et filtrage des ateliers : du JavaScript nu, sur un formulaire
             qui fonctionne déjà sans lui. Si ce script ne s'exécute pas, on perd le confort,
             jamais la fonction. --}}
        <script data-navigate-once>
            (function () {
                var zone = document.getElementById('zone-depot');
                var champ = document.getElementById('fichier');
                var ville = document.getElementById('ville');
                var site = document.getElementById('site');

                if (zone && champ) {
                    ['dragover', 'dragenter'].forEach(function (e) {
                        zone.addEventListener(e, function (ev) { ev.preventDefault(); zone.classList.add('survol'); });
                    });
                    ['dragleave', 'drop'].forEach(function (e) {
                        zone.addEventListener(e, function () { zone.classList.remove('survol'); });
                    });
                    zone.addEventListener('drop', function (ev) {
                        ev.preventDefault();
                        if (ev.dataTransfer && ev.dataTransfer.files.length) {
                            champ.files = ev.dataTransfer.files;
                        }
                    });
                }

                // L'avis « toutes les villes » s'affiche au moment du choix : c'est le
                // seul instant où il change quelque chose à ce que la personne fait.
                var avis = document.getElementById('avis-toutes-villes');

                if (ville && avis) {
                    ville.addEventListener('change', function () {
                        avis.hidden = ville.value !== 'toutes';
                    });
                }

                if (ville && site) {
                    var filtrer = function () {
                        var choisie = ville.value;
                        var visibles = 0;

                        Array.prototype.forEach.call(site.options, function (opt) {
                            var sienne = opt.getAttribute('data-ville');
                            var garder = sienne === '' || sienne === choisie;
                            opt.hidden = ! garder;
                            opt.disabled = ! garder;
                            if (garder && sienne !== '') { visibles++; }
                        });

                        if (site.selectedOptions.length && site.selectedOptions[0].disabled) {
                            site.value = '';
                        }

                        // Une ville à un seul atelier ne pose aucune question : le champ
                        // disparaît plutôt que d'offrir un choix qui n'en est pas un.
                        site.closest('.imp-fld').style.display = visibles > 0 ? '' : 'none';
                    };

                    ville.addEventListener('change', filtrer);
                    filtrer();
                }
            })();
        </script>

        {{-- ============================================ le suivi du traitement en cours --}}
        @if ($this->lot)
            @php $lot = $this->lot; @endphp
            <div class="imp-carte" style="border-left:4px solid #C8102E;"
                 @if (in_array($lot->etat, ['depose', 'en_cours'], true)) wire:poll.2s @endif>
                <h2>{{ $lot->nom_fichier }} <span class="chip">{{ $lot->etatLisible() }}</span></h2>

                @if (in_array($lot->etat, ['depose', 'en_cours'], true))
                    <div style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;">
                        {{ number_format((int) $lot->lignes_lues, 0, ',', ' ') }} ligne(s) lue(s)
                    </div>
                    <div class="imp-jauge"><i style="width:{{ $lot->etat === 'en_cours' ? '66%' : '12%' }}"></i></div>
                    <div class="imp-hint">
                        La longueur du fichier n'est connue qu'une fois lu : le compteur monte, il ne vise rien.
                        <strong>Vous pouvez quitter la page</strong>, le traitement continue — et vous pouvez
                        en déposer un autre pendant ce temps.
                        @if ($lot->etat === 'depose')
                            <br>Si le compteur ne bouge pas, personne ne prend le travail :
                            <a href="{{ route('import.traitements') }}" style="color:#C8102E; font-weight:700;">
                                lancez-le depuis la page Traitement</a>.
                        @endif
                        <br><a href="{{ route('import.lot', $lot->id) }}" style="color:#C8102E; font-weight:700;">
                            Rafraîchir à la main</a> si le compteur reste figé.
                    </div>
                @else
                    <div class="imp-kpis cinq">
                        <div class="imp-kpi"><div class="lab">Lues</div>
                            <div class="val">{{ number_format((int) $lot->lignes_lues, 0, ',', ' ') }}</div>
                            <div class="sub">dans le fichier</div></div>
                        <div class="imp-kpi vert"><div class="lab">Créées</div>
                            <div class="val">{{ number_format((int) $lot->lignes_creees, 0, ',', ' ') }}</div>
                            <div class="sub">nouvelles</div></div>
                        <div class="imp-kpi"><div class="lab">Mises à jour</div>
                            <div class="val">{{ number_format((int) $lot->lignes_majs, 0, ',', ' ') }}</div>
                            <div class="sub">déjà connues</div></div>
                        <div class="imp-kpi"><div class="lab">Inchangées</div>
                            <div class="val">{{ number_format((int) $lot->lignes_ignorees, 0, ',', ' ') }}</div>
                            <div class="sub">rien à modifier</div></div>
                        <div class="imp-kpi {{ $lot->lignes_rejetees > 0 ? 'rouge' : '' }}">
                            <div class="lab">Rejetées</div>
                            <div class="val">{{ number_format((int) $lot->lignes_rejetees, 0, ',', ' ') }}</div>
                            <div class="sub">à corriger dans le fichier</div></div>
                    </div>

                    <div class="imp-hint {{ $lot->etat === 'echec' ? 'warn' : 'ok' }}">{{ $lot->message }}</div>

                    <div class="imp-actions">
                        <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn n"
                           style="text-decoration:none; display:inline-block;">Voir le détail</a>
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- ==================================== TOUS LES IMPORTS, RÉUNIS DANS UN SEUL TABLEAU --}}
    <div class="imp-carte">
        <h2>
            Où en est chaque import
            {{-- « 8 disponibles sur 8 » n'apprenait plus rien : les huit formats du logiciel
                 sont lus depuis que le dernier a été écrit. Le chiffre utile est celui des
                 types déjà alimentés. --}}
            <span class="chip">{{ $this->tableau->where('lots', '>', 0)->count() }} type(s) déjà déposé(s) sur {{ $this->tableau->count() }}</span>
        </h2>

        <div class="imp-tbl-wrap">
            <table class="imp-tbl">
                <thead>
                    <tr>
                        <th style="width:26%;">Type de fichier</th>
                        <th>Dernier import</th>
                        <th>Villes faites</th>
                        <th>Villes manquantes</th>
                        <th class="num">En base</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->tableau as $ligne)
                        <tr wire:key="tab-{{ $ligne['cle'] }}">
                            <td>
                                <strong>{{ $ligne['libelle'] }}</strong>
                                @if ($ligne['source'])
                                    <div style="color:#6B6E76; font-size:11.5px; margin-top:3px;">{{ $ligne['source'] }}</div>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                @if ($ligne['dernier'])
                                    {{ $ligne['dernier']->created_at?->format('d/m/Y') }}
                                    <div style="color:#6B6E76; font-size:11.5px;">
                                        {{ $ligne['lots'] }} dépôt(s) · {{ $ligne['dernier']->deposant }}
                                    </div>
                                @else
                                    <span style="color:#6B6E76;">jamais</span>
                                @endif
                            </td>
                            <td style="font-size:11.5px;">{{ $ligne['villes'] ? implode(', ', $ligne['villes']) : '—' }}</td>
                            <td style="font-size:11.5px;">
                                @if ($ligne['disponible'] && $ligne['manquantes'])
                                    <span class="pastille pVide">{{ implode(', ', $ligne['manquantes']) }}</span>
                                @elseif ($ligne['manquantes'])
                                    <span style="color:#6B6E76;">{{ implode(', ', $ligne['manquantes']) }}</span>
                                @else
                                    <span class="pastille pTermine">aucune</span>
                                @endif
                            </td>
                            <td class="num">
                                @if ($ligne['lignes_en_base'] === null)
                                    <span style="color:#C8102E; font-size:11.5px; font-family:var(--font-sans);">table à créer</span>
                                @else
                                    {{ number_format($ligne['lignes_en_base'], 0, ',', ' ') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</x-import::coquille>
