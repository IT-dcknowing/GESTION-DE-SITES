<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
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
    <div @if ($this->enVol->isNotEmpty()) wire:poll.2s @endif>

        @php $f = $this->file; @endphp

        @if ($this->enVol->isEmpty())
            <div class="imp-carte">
                <h2>Aucun traitement en cours</h2>
                <div class="imp-hint ok">
                    Tout ce qui a été déposé a été lu. Les derniers traitements terminés sont listés plus bas.
                </div>
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
                    <div style="border:1px solid #E3E0D8; border-radius:10px; padding:13px; margin-bottom:11px;">
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
