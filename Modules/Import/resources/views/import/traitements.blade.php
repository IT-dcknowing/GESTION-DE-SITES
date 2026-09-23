<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\CommercialDeLaFiche;
use Modules\Noyau\Imports\Services\EtatDeLaFile;
use Modules\Noyau\Imports\Services\SuiviDuTraitement;

use function Livewire\Volt\computed;

/*
|--------------------------------------------------------------------------
| Traitement — ce qui tourne, et ce qui attend que quelqu'un le prenne
|--------------------------------------------------------------------------
| **Le manque auquel cette page répond.** Un import part en file d'attente : le
| fichier est rangé, la page rend la main, un exécuteur travaille de son côté.
| Encore faut-il qu'un exécuteur tourne. Quand il n'y en a pas, le dépôt réussit,
| l'écran affiche « Déposé », le compteur reste à zéro — et rien ne dit que personne
| ne viendra jamais. On attend devant une progression qui n'a pas commencé.
|
| **Ce manque est comblé à la source.** La lecture démarre désormais d'elle-même au
| dépôt (voir LanceurDeTraitement) ; les boutons « Traiter celui-ci maintenant » et
| « Tout traiter maintenant » ont disparu avec le défaut qui les rendait nécessaires.
| Cette page ne fait plus que **suivre** — et permettre d'**arrêter** une lecture.
|
| **Sur la barre de progression.** Elle avance vers la longueur que le classeur annonce
| dans son en-tête. Quand elle est inconnue, elle bouge sans afficher de proportion :
| un pourcentage inventé est pire qu'une absence de pourcentage.
*/

$file = computed(fn () => (new EtatDeLaFile((int) auth()->user()->entreprise_id))->mesurer());

/** Ce qui tourne ou attend : c'est le sujet de la page. */
$enVol = computed(function () {
    $lots = LotImport::whereIn('etat', LotImport::ETATS_EN_TRAVAIL)->orderBy('created_at')->get();

    /*
     * Un dépôt que rien n'a démarré repart de lui-même quand on regarde cet écran — voir
     * SuiviDuTraitement::reveiller(). Une tentative par minute au plus, et un contrôle reste un
     * contrôle. Sans cela, un fichier déposé avant que le lancement immédiat n'existe attend
     * indéfiniment, sans qu'aucun geste ne puisse le reprendre.
     */
    $lots->each(fn (LotImport $lot) => SuiviDuTraitement::reveiller($lot));

    return $lots;
});

/** Ce qui vient de finir — une fenêtre courte, parce qu'on annonce un événement. */
$recents = computed(fn () => LotImport::whereIn('etat', ['termine', 'echec', 'controle', 'annule'])
    ->where('termine_le', '>=', now()->subHours(6))
    ->orderByDesc('termine_le')
    ->limit(12)
    ->get());

$peutLancer = computed(fn () => AccesImport::peutDeposer(auth()->user()));

/**
 * Ce que la page a à dire quand plus rien ne tourne.
 *
 * **Ce qui était affiché.** Une phrase unique — « Tout ce qui a été déposé a été lu » —
 * dès que la file était vide. Elle s'affichait aussi bien sur une plateforme où personne
 * n'avait jamais rien déposé que juste après un import tombé en échec. Le propriétaire l'a
 * relevé le 24/09 : « ce message est statique, ce n'est pas normal, le message doit être en
 * fonction du traitement ». Une phrase qui ne change pas ne dit rien ; pire, elle rassure
 * exactement quand il ne faut pas.
 *
 * On lit donc ce qui vient de se passer, et on le dit. Quatre situations, quatre phrases,
 * et un ton qui suit — vert quand tout est lu, orange quand le dernier dépôt s'est arrêté.
 *
 * @return array{ton: string, titre: string, texte: string}
 */
$situation = computed(function () {
    $dernier = LotImport::orderByDesc('termine_le')->orderByDesc('id')->first();

    if ($dernier === null) {
        return [
            'ton' => '',
            'titre' => 'Aucun fichier déposé',
            'texte' => "Rien n'a encore été déposé sur cette plateforme. La lecture démarre "
                ."d'elle-même dès qu'un fichier arrive, et c'est ici qu'elle se suit.",
        ];
    }

    $quand = $dernier->termine_le?->diffForHumans() ?? $dernier->created_at?->diffForHumans();

    if ($dernier->etat === 'echec') {
        return [
            'ton' => 'warn',
            'titre' => "Le dernier traitement s'est arrêté",
            'texte' => '« '.$dernier->nom_fichier.' » n\'est pas allé au bout ('.$quand.').'
                .($dernier->message ? ' '.$dernier->message : '')
                .' Le détail dit sur quelle ligne, et le fichier se redépose tel quel.',
        ];
    }

    if ($dernier->etat === 'annule') {
        return [
            'ton' => 'warn',
            'titre' => 'La dernière lecture a été arrêtée',
            'texte' => '« '.$dernier->nom_fichier.' » a été interrompu '.$quand.'. Ce qui avait '
                .'été écrit avant l\'arrêt est resté : le détail du dépôt le dit ligne à ligne.',
        ];
    }

    if ($dernier->etat === 'controle') {
        return [
            'ton' => '',
            'titre' => 'Le dernier fichier a été contrôlé, pas importé',
            'texte' => '« '.$dernier->nom_fichier.' » a été lu '.$quand.' sans qu\'une seule '
                .'ligne soit écrite. C\'est une simulation : elle se rejoue en import réel '
                .'depuis le détail du dépôt.',
        ];
    }

    return [
        'ton' => 'ok',
        'titre' => 'Aucun traitement en cours',
        'texte' => 'Le dernier fichier lu, « '.$dernier->nom_fichier.' », s\'est terminé '.$quand
            .' — '.number_format((int) $dernier->lignes_lues, 0, ',', ' ').' ligne(s) lue(s), '
            .number_format((int) $dernier->lignes_rejetees, 0, ',', ' ').' rejet(s).',
    ];
});

/**
 * Le dépôt qu'on vient de faire, quand on arrive d'un dépôt.
 *
 * Le contrôleur renvoie ici après un dépôt réussi : la lecture est déjà partie dans son
 * processus, et c'est cette page qui la suit. Le numéro dans l'adresse sert à mettre en
 * avant le bon fichier quand plusieurs tournent — sans lui, on cherche le sien dans la
 * liste.
 */
$lotSuivi = computed(fn () => request()->integer('lot') ?: null);

/**
 * Les noms de commerciaux lus sur les fiches, et que le référentiel ne reconnaît pas.
 *
 * **Pourquoi la question est ici.** La fiche de réception porte une colonne libre —
 * « INFORMATIONS SUR LA SITUATION » — où le saisisseur met en première position le
 * commercial qui a décroché l'affaire : son nom, son code de deux lettres, ou son code de
 * l'application. C'est le chemin arbitré avec la direction pour relier les devis du
 * logiciel d'atelier aux prospections, sans demander une colonne de plus au logiciel.
 *
 * Un nom se tape, donc un nom se déforme. Rien n'est deviné : ce qui ne correspond pas
 * exactement devient une question, posée là où l'on suit déjà la lecture, avec les noms du
 * référentiel classés du plus proche au plus lointain. La réponse vaut pour tous les dépôts
 * suivants et reprend les fiches déjà lues — on ne redépose pas un fichier pour ça.
 */
$lecture = computed(fn () => new CommercialDeLaFiche((int) auth()->user()->entreprise_id));

$nomsAReconnaitre = computed(fn () => $this->peutArbitrer ? $this->lecture->questions() : collect());

$peutArbitrer = computed(fn () => AccesImport::peutArbitrer(auth()->user()));

$pastille = fn (string $etat) => match ($etat) {
    'termine' => 'pTermine',
    'controle' => 'pControle',
    'en_cours' => 'pEnCours',
    'echec', 'annule' => 'pEchec',
    default => 'pDepose',
};

?>

{{-- La page se rafraîchit tant que quelque chose tourne, et cesse dès qu'il n'y a plus
     rien : interroger le serveur toutes les trois secondes pour un écran figé ne sert à
     personne. Le rafraîchissement est un confort — la page est complète sans lui, et la
     veille posée dans la mise en page annonce la fin où que l'on soit. --}}
<x-import::coquille page="traitements">
    {{-- Une seconde tant qu'on suit son propre dépôt — c'est le moment où l'on regarde
         la barre avancer — et deux secondes ensuite, quand on ne fait que surveiller. --}}
    <div @if ($this->enVol->isNotEmpty()) wire:poll.{{ $this->lotSuivi ? '1s' : '2s' }} @endif>

        @php $f = $this->file; @endphp

        @if ($this->enVol->isEmpty())
            @php $s = $this->situation; @endphp
            <div class="imp-carte">
                <h2>{{ $s['titre'] }}</h2>
                <div class="imp-hint {{ $s['ton'] }}">{{ $s['texte'] }}</div>
            </div>
        @endif

        {{-- ------------------------------------------- les noms qui attendent une réponse --}}
        @if ($this->nomsAReconnaitre->isNotEmpty())
            <div class="imp-carte">
                <h2>
                    Des commerciaux nommés sur les fiches attendent d'être reconnus
                    <span class="chip">{{ $this->nomsAReconnaitre->count() }}</span>
                </h2>

                <div class="imp-hint">
                    La colonne « informations sur la situation » de la fiche de réception nomme, en
                    première position, le commercial qui a décroché l'affaire. Ces libellés-là ne
                    correspondent à personne du référentiel — le plus souvent un nom abrégé ou mal
                    saisi. Dites à qui chacun correspond : la réponse vaut pour les dépôts suivants,
                    et les fiches déjà lues sont reprises. Laissé vide, un nom reste en attente.
                </div>

                @error('commerciaux')
                    <div class="imp-hint warn">{{ $message }}</div>
                @enderror

                @if (session('message-commerciaux'))
                    <div class="imp-hint ok">{{ session('message-commerciaux') }}</div>
                @endif

                <form method="POST" action="{{ route('import.traitements.commerciaux') }}">
                    @csrf

                    <div class="imp-tbl-wrap">
                        <table class="imp-tbl">
                            <thead>
                                <tr>
                                    <th>Lu sur la fiche</th>
                                    <th class="num">Fiches</th>
                                    <th>À qui cela correspond</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->nomsAReconnaitre as $question)
                                    @php $attente = $question['correspondance']; @endphp
                                    <tr>
                                        <td style="font-weight:700; word-break:break-word;">
                                            {{ $attente->valeur_source }}
                                        </td>
                                        <td class="num">{{ number_format((int) $attente->occurrences, 0, ',', ' ') }}</td>
                                        <td>
                                            <select name="reponses[{{ $attente->id }}]"
                                                    style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px;
                                                           font-size:13px; background:var(--th-champ,#FFFBEA);
                                                           font-family:inherit; min-width:260px;">
                                                <option value="">— laisser en attente —</option>
                                                @foreach ($question['candidats'] as $id => $nom)
                                                    <option value="{{ $id }}">{{ $nom }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="imp-actions">
                        <button type="submit" class="imp-btn n">Continuer</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ----------------------------------------------------------- ce qui tourne --}}
        @if ($this->enVol->isNotEmpty())
            <div class="imp-carte">
                <h2>
                    En cours de traitement
                    <span class="chip">{{ $this->enVol->count() }} fichier(s)</span>
                </h2>

                @foreach ($this->enVol as $lot)
                    {{-- Celui qu'on vient de déposer se reconnaît du premier coup d'œil :
                         on arrive ici juste après l'avoir envoyé. --}}
                    <div style="border:1px solid {{ $lot->id === $this->lotSuivi ? '#191B20' : '#E3E0D8' }};
                                border-width:{{ $lot->id === $this->lotSuivi ? '2px' : '1px' }};
                                border-radius:10px; padding:13px; margin-bottom:11px;">
                        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:baseline;">
                            <div style="font-weight:700; font-size:14.5px; word-break:break-word;">
                                {{ $lot->nom_fichier }}
                            </div>
                            <span class="pastille {{ $this->pastille($lot->etat) }}">{{ $lot->etatLisible() }}</span>
                        </div>

                        <div style="color:#6B6E76; font-size:12px; margin-top:3px;">
                            {{ Registre::libelle($lot->format) }} ·
                            {{ $lot->ville?->nom ?? 'toutes les villes' }} ·
                            déposé par {{ $lot->deposant }},
                            {{ $lot->created_at?->diffForHumans() }}
                        </div>

                        <div style="margin-top:8px;">
                            <x-import::progression :lot="$lot" :peut-arreter="$this->peutLancer" />
                        </div>

                        <div class="imp-actions">
                            <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn p"
                               style="text-decoration:none; display:inline-block;">Voir le détail</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- -------------------------------------------------------- ce qui vient de finir --}}
        <div class="imp-carte">
            <h2>Terminés récemment <span class="chip">6 dernières heures</span></h2>

            @if ($this->recents->isEmpty())
                <div class="imp-hint">Aucun traitement n'a abouti dans les six dernières heures.</div>
            @else
                <div class="imp-tbl-wrap">
                    <table class="imp-tbl">
                        <thead>
                            <tr>
                                <th>Terminé</th>
                                <th>Fichier</th>
                                <th>Type</th>
                                <th class="num">Lues</th>
                                <th class="num">Créées</th>
                                <th class="num">Rejets</th>
                                <th>État</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->recents as $lot)
                                <tr>
                                    <td style="white-space:nowrap;">{{ $lot->termine_le?->format('H\hi') }}</td>
                                    <td style="max-width:250px; word-break:break-word;">{{ $lot->nom_fichier }}</td>
                                    <td>{{ Registre::libelle($lot->format) }}</td>
                                    <td class="num">{{ number_format((int) $lot->lignes_lues, 0, ',', ' ') }}</td>
                                    <td class="num">{{ number_format((int) $lot->lignes_creees, 0, ',', ' ') }}</td>
                                    <td class="num" @if ($lot->lignes_rejetees > 0) style="color:#C8102E;" @endif>
                                        {{ number_format((int) $lot->lignes_rejetees, 0, ',', ' ') }}
                                    </td>
                                    <td><span class="pastille {{ $this->pastille($lot->etat) }}">{{ $lot->etatLisible() }}</span></td>
                                    <td>
                                        <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn p"
                                           style="text-decoration:none;">Détail</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------------------- la file, en clair --}}
        <div class="imp-carte">
            <h2>La file d'attente</h2>

            <div class="imp-kpis">
                <div class="imp-kpi">
                    <div class="lab">Travaux en file</div>
                    <div class="val">{{ $f['en_file'] }}</div>
                    <div class="sub">déposés, pas encore pris</div>
                </div>
                <div class="imp-kpi {{ $f['en_cours'] > 0 ? 'vert' : '' }}">
                    <div class="lab">En cours de lecture</div>
                    <div class="val">{{ $f['en_cours'] }}</div>
                    <div class="sub">fichiers ouverts à l'instant</div>
                </div>
                <div class="imp-kpi">
                    <div class="lab">En attente</div>
                    <div class="val">{{ $f['en_attente'] }}</div>
                    <div class="sub">déposés, lecture non commencée</div>
                </div>
                <div class="imp-kpi {{ $f['echecs'] > 0 ? 'rouge' : '' }}">
                    <div class="lab">Travaux en échec</div>
                    <div class="val">{{ $f['echecs'] }}</div>
                    <div class="sub">dans la file du serveur</div>
                </div>
            </div>

        </div>
    </div>
</x-import::coquille>
