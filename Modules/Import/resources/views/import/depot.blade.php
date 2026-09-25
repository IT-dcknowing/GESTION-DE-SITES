<?php

use Modules\Import\Http\Controllers\DepotController;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\EtatDeLaFile;
use Modules\Noyau\Imports\Services\NomDeFichier;
use Modules\Noyau\Imports\Services\SuiviDuTraitement;
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

/*
 * Un dépôt que rien n'a démarré repart de lui-même quand on regarde cet écran — voir
 * SuiviDuTraitement::reveiller(). Une tentative par minute au plus, et un contrôle reste un
 * contrôle. Sans cela, un fichier déposé avant que le lancement immédiat n'existe attend
 * indéfiniment, sans qu'aucun geste ne puisse le reprendre.
 */
$lot = computed(function () {
    $lot = $this->lotSuivi ? LotImport::find($this->lotSuivi) : null;

    if ($lot !== null) {
        SuiviDuTraitement::reveiller($lot);
    }

    return $lot;
});

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

/**
 * Quels types se rangent par le code de deux lettres de l'employé — et lesquels non.
 *
 * **Le défaut relevé le 24/09.** L'avis « ce fichier sera ventilé par les codes du
 * personnel » s'affichait pour tout dépôt sans ville, quel que soit le type. Or la balance
 * fournisseurs, les règlements fournisseurs, le journal de caisse, le classeur de caisse,
 * l'état des impayés et les factures fournisseurs **ne portent aucun numéro de fiche** :
 * aucun code n'en sort, et rien n'y sera ventilé. Leur import se passe très bien ; c'est
 * l'explication qui décrivait un mécanisme étranger à ce qu'on faisait. Un avertissement
 * qui ne s'applique pas à ce qu'on fait apprend à ne plus lire les avertissements.
 *
 * Lu sur les formats eux-mêmes plutôt que recopié ici : une seconde liste divergerait le
 * jour où un format changerait de nature.
 */
$ventilationParLesCodes = computed(fn () => Registre::ventilationParLesCodes());

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
                    La lecture a démarré&nbsp;: son avancée s'affiche ci-dessous. Vous pouvez quitter cette
                    page ou déposer un autre fichier, elle continue de son côté.
                </p>
            </x-boite-message>
        @endif

        @if (session('refus-import'))
            <x-boite-message titre="Dépôt refusé" ton="alerte">{{ session('refus-import') }}</x-boite-message>
        @endif

        {{-- ------------------------------------------------- ce qui tourne déjà

             Il y avait ici un bandeau « aucun exécuteur ne prend le travail », qui renvoyait vers
             la page Traitement pour lancer la lecture à la main. La lecture démarre désormais
             d'elle-même au dépôt : le bandeau n'avait plus rien à dire, et son lien menait à un
             bouton qui n'existe plus. Il reste l'information utile — d'autres imports tournent. --}}
        @php $f = $this->file; @endphp
        @if ($f['en_cours'] + $f['en_attente'] > 0 && ! $this->lot)
            <div class="imp-hint" style="margin-bottom:14px;">
                <strong>{{ $f['en_cours'] + $f['en_attente'] }} import(s) en cours de lecture.</strong>
                Vous pouvez en déposer d'autres sans attendre : chacun est lu de son côté.
                <a href="{{ route('import.traitements') }}" style="color:#C8102E; font-weight:700;">Voir leur avancée</a>.
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
            <h2>Déposer un fichier <span class="chip">.xls · .xlsx · .xlsm · .pdf — 40 Mo au plus</span></h2>

            {{-- **Le tableur d'abord, quand on a le choix.** Un classeur se lit par ses
                 cellules ; un imprimé se lit par la position de ses caractères sur la page,
                 et cette lecture-là peut se tromper de colonne quand un texte déborde — elle
                 l'a fait une fois, sur sept numéros de pièce. Le type d'import, lui, est le
                 même dans les deux cas : c'est le document qui change, pas la nature de ce
                 qu'il contient. Demandé le 24/09. --}}
            <p class="imp-hint" style="margin:0 0 14px;">
                Le <b>PDF n'est accepté que faute de mieux</b> : le logiciel ne sort aujourd'hui
                que le journal de caisse sous cette forme. Dès qu'un export en tableur existe,
                apportez-le — <b>sous le même type d'import</b>, il n'y en a pas un second à créer.
            </p>

            <form method="POST" action="{{ route('import.deposer') }}" enctype="multipart/form-data"
                  id="frm-depot">
                @csrf

                <div class="imp-zone" id="zone-depot">
                    <div class="imp-zone-vide">
                        <span class="fleche">⇪</span>
                        <span class="titre">Glissez le fichier ici</span>
                        <span class="det">ou choisissez-le sur votre ordinateur :</span>

                        <input type="file" id="fichier" name="fichier"
                               accept=".xls,.xlsx,.xlsm,.pdf" class="imp-zone-champ" required>
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
                        {{-- Chaque type porte, dans sa propre option, s'il se range par les
                             codes employés. C'est ce qui permet de n'afficher l'explication
                             de la ventilation qu'aux cinq types qu'elle concerne, sans
                             interroger le serveur au changement de liste. --}}
                        <select id="format" name="format" required>
                            <option value="" data-codes="">— à choisir —</option>
                            @foreach ($this->formats as $cle => $libelle)
                                <option value="{{ $cle }}"
                                        data-codes="{{ ($this->ventilationParLesCodes[$cle] ?? true) ? '1' : '0' }}"
                                        @selected(old('format') === $cle)>{{ $libelle }}</option>
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
                     @unless (old('ville') === DepotController::TOUTES_LES_VILLES
                         && ($this->ventilationParLesCodes[old('format')] ?? false)) hidden @endunless>
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

                {{-- L'autre moitié de la vérité, pour les six types qui ne portent aucun
                     numéro de fiche. Leur dire « vos lignes seront ventilées par les codes »
                     décrivait un mécanisme qui ne les concerne pas ; leur dire ce qui va
                     réellement se passer vaut mieux que de se taire. --}}
                <div id="avis-sans-codes" class="imp-hint"
                     @unless (old('ville') === DepotController::TOUTES_LES_VILLES
                         && old('format') && ! ($this->ventilationParLesCodes[old('format')] ?? true)) hidden @endunless>
                    <strong>Ce type de fichier ne se range pas par les codes du personnel.</strong>
                    Ses lignes ne portent pas de numéro de fiche de réception — il n'y a donc pas de
                    code de deux lettres à en tirer, et rien à ventiler. L'import se fait normalement :
                    c'est <strong>la ville que vous déclarez</strong> qui range ce qu'il contient.
                    Déposé sans ville, ce qui en sort restera sans lieu.
                </div>

                <div class="imp-hint">
                    <strong>L'atelier ne se remplit que là où il y a un choix à faire.</strong>
                    Bouaké et San Pédro n'ont qu'un atelier : la ville suffit. Abidjan en a deux, et la
                    colonne SITE des exports dit seulement « ABIDJAN ». Si vous avez filtré l'extraction sur
                    un atelier dans le logiciel, indiquez-le ici et tout l'import ira là. Sinon, laissez
                    « non filtré »<span id="mention-du-code"
                        @unless ($this->ventilationParLesCodes[old('format')] ?? true) hidden @endunless> : le
                    rattachement se fera par le code employé, et</span> ce que personne ne sait
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
        {{-- Glisser-déposer, avis, et filtrage des ateliers : du JavaScript nu, sur un
             formulaire qui fonctionne déjà sans lui.

             **Aucun élément n'est retenu.** Ce script porte `data-navigate-once` : il
             s'exécute une fois pour toute la durée du document, alors qu'on arrive sur cet
             écran par un menu qui navigue sans recharger la page. Tout ce qu'il retiendrait
             au chargement désignerait, au deuxième passage, un élément détaché — et le
             geste ne ferait plus rien. C'est ce qui obligeait à recharger la page pour que
             le champ « Atelier » se remette à suivre la ville (relevé le 24/09), et c'est
             le même piège que celui de la boîte de confirmation et du bandeau vert.

             Les écoutes vivent donc sur le `document`, qui survit à toutes les
             navigations, et chaque geste relit les champs au moment où il agit. --}}
        <script data-navigate-once>
            (function () {
                /** Les champs, relus à chaque fois — jamais retenus. */
                var champs = function () {
                    return {
                        zone: document.getElementById('zone-depot'),
                        fichier: document.getElementById('fichier'),
                        ville: document.getElementById('ville'),
                        site: document.getElementById('site'),
                        format: document.getElementById('format'),
                    };
                };

                /*
                 * L'avis « toutes les villes » dépend de **deux** listes, et non d'une : la
                 * ville dit qu'il y a une ventilation à faire, le type dit si ce fichier
                 * s'y prête. Les six types qui ne portent aucun numéro de fiche reçoivent
                 * l'autre phrase — celle qui décrit ce qui va réellement se passer.
                 */
                var rafraichirLesAvis = function () {
                    var c = champs();
                    var avisCodes = document.getElementById('avis-toutes-villes');
                    var avisSansCodes = document.getElementById('avis-sans-codes');
                    var mention = document.getElementById('mention-du-code');

                    if (! c.ville || ! c.format) { return; }

                    var choisi = c.format.selectedOptions.length ? c.format.selectedOptions[0] : null;
                    var declare = choisi ? choisi.getAttribute('data-codes') : '';
                    // Type non choisi : on ne préjuge de rien, et l'on se tait.
                    var parLesCodes = declare === '1';
                    var connu = declare === '1' || declare === '0';
                    var sansVille = c.ville.value === 'toutes';

                    if (avisCodes) { avisCodes.hidden = ! (sansVille && parLesCodes); }
                    if (avisSansCodes) { avisSansCodes.hidden = ! (sansVille && connu && ! parLesCodes); }
                    if (mention) { mention.hidden = connu && ! parLesCodes; }
                };

                /*
                 * Le champ « Atelier » ne montre que les ateliers de la ville choisie, et
                 * disparaît quand cette ville n'en compte qu'un — un choix qui n'en est pas
                 * un ne se pose pas. Abidjan en a deux, et c'est le seul cas où la question
                 * vaut d'être posée.
                 */
                var filtrerLesAteliers = function () {
                    var c = champs();

                    if (! c.ville || ! c.site) { return; }

                    var choisie = c.ville.value;
                    var visibles = 0;

                    Array.prototype.forEach.call(c.site.options, function (opt) {
                        var sienne = opt.getAttribute('data-ville');
                        var garder = sienne === '' || sienne === choisie;
                        opt.hidden = ! garder;
                        opt.disabled = ! garder;
                        if (garder && sienne !== '') { visibles++; }
                    });

                    if (c.site.selectedOptions.length && c.site.selectedOptions[0].disabled) {
                        c.site.value = '';
                    }

                    var enveloppe = c.site.closest('.imp-fld');

                    if (enveloppe) {
                        enveloppe.style.display = visibles > 0 ? '' : 'none';
                    }
                };

                // Une seule écoute déléguée pour les deux listes : elle survit aux
                // navigations, et n'a rien à rebrancher quand la page est remplacée.
                document.addEventListener('change', function (evenement) {
                    var cible = evenement.target;

                    if (! cible || (cible.id !== 'ville' && cible.id !== 'format')) { return; }

                    rafraichirLesAvis();

                    if (cible.id === 'ville') { filtrerLesAteliers(); }
                });

                // Le glisser-déposer, par délégation lui aussi.
                ['dragover', 'dragenter'].forEach(function (nom) {
                    document.addEventListener(nom, function (ev) {
                        var zone = ev.target.closest ? ev.target.closest('#zone-depot') : null;

                        if (zone) { ev.preventDefault(); zone.classList.add('survol'); }
                    });
                });

                ['dragleave', 'drop'].forEach(function (nom) {
                    document.addEventListener(nom, function (ev) {
                        var zone = ev.target.closest ? ev.target.closest('#zone-depot') : null;

                        if (zone) { zone.classList.remove('survol'); }
                    });
                });

                document.addEventListener('drop', function (ev) {
                    var zone = ev.target.closest ? ev.target.closest('#zone-depot') : null;

                    if (! zone) { return; }

                    ev.preventDefault();

                    var c = champs();

                    if (c.fichier && ev.dataTransfer && ev.dataTransfer.files.length) {
                        c.fichier.files = ev.dataTransfer.files;
                    }
                });

                /*
                 * L'état de départ, à chaque arrivée sur l'écran. `livewire:navigated` est
                 * émis après chaque navigation sans rechargement : sans lui, la première
                 * mise en place n'aurait lieu qu'une fois, et le champ « Atelier »
                 * arriverait déplié au deuxième passage.
                 */
                var remettreEnPlace = function () {
                    rafraichirLesAvis();
                    filtrerLesAteliers();
                };

                document.addEventListener('livewire:navigated', remettreEnPlace);
                remettreEnPlace();
            })();
        </script>

        {{-- ============================================ le suivi du traitement en cours

             La lecture a démarré au moment du dépôt : cette carte la montre avancer, ligne après
             ligne, vers la longueur que le classeur annonce. Le seul geste proposé pendant ce
             temps est de l'arrêter. --}}
        @if ($this->lot)
            @php $lot = $this->lot; @endphp
            <div class="imp-carte" style="border-left:4px solid #C8102E;"
                 @if ($lot->estEnTravail()) wire:poll.1s @endif>
                <h2>{{ $lot->nom_fichier }} <span class="chip">{{ $lot->etatLisible() }}</span></h2>

                @if ($lot->estEnTravail())
                    <x-import::progression :lot="$lot" :peut-arreter="$this->peutDeposer" />
                @elseif ($lot->etat === 'annule')
                    <div class="imp-hint warn">{{ $lot->message }}</div>
                    <div class="imp-actions">
                        <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn n"
                           style="text-decoration:none; display:inline-block;">Voir le détail</a>
                    </div>
                @else
                    <div class="imp-jauge grande"><i style="width:{{ $lot->etat === 'termine' ? 100 : 0 }}%;"></i></div>

                    <div class="imp-kpis cinq" style="margin-top:12px;">
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
