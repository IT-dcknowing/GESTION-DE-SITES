<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\EtatDeLaFile;

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
| Deux gestes, donc : **suivre** ce qui tourne, et **prendre le travail en main** si
| rien ne le prend. Le bouton « Tout traiter » fait le travail dans la page.
|
| **Sur la barre de progression.** Elle ne vise rien, et c'est assumé : la longueur
| d'un fichier n'est connue qu'une fois lu. Elle montre donc l'avancée réelle — le
| nombre de lignes lues, qui monte — plutôt qu'un pourcentage inventé. Un pourcentage
| faux est pire qu'une absence de pourcentage : on l'attend, puis on n'y croit plus.
*/

$file = computed(fn () => (new EtatDeLaFile((int) auth()->user()->entreprise_id))->mesurer());

/** Ce qui tourne ou attend : c'est le sujet de la page. */
$enVol = computed(fn () => LotImport::whereIn('etat', ['depose', 'en_cours'])
    ->orderBy('created_at')
    ->get());

/** Ce qui vient de finir — une fenêtre courte, parce qu'on annonce un événement. */
$recents = computed(fn () => LotImport::whereIn('etat', ['termine', 'echec', 'controle'])
    ->where('termine_le', '>=', now()->subHours(6))
    ->orderByDesc('termine_le')
    ->limit(12)
    ->get());

$peutLancer = computed(fn () => AccesImport::peutDeposer(auth()->user()));

$pastille = fn (string $etat) => match ($etat) {
    'termine' => 'pTermine',
    'controle' => 'pControle',
    'en_cours' => 'pEnCours',
    'echec' => 'pEchec',
    default => 'pDepose',
};

?>

{{-- La page se rafraîchit tant que quelque chose tourne, et cesse dès qu'il n'y a plus
     rien : interroger le serveur toutes les trois secondes pour un écran figé ne sert à
     personne. Le rafraîchissement est un confort — la page est complète sans lui, et la
     veille posée dans la mise en page annonce la fin où que l'on soit. --}}
<x-import::coquille page="traitements">
    <div @if ($this->enVol->isNotEmpty()) wire:poll.3s @endif>

        @php $f = $this->file; @endphp

        {{-- ------------------------------------------------- l'exécuteur, dit franchement --}}
        @if ($f['executeur_douteux'])
            <div class="imp-hint">
                Aucun exécuteur de file ne tourne&nbsp;: les fichiers déposés attendent d'être pris.
                Le bouton <strong>Tout traiter maintenant</strong> fait la lecture dans cette page.
            </div>
        @elseif ($this->enVol->isEmpty())
            <div class="imp-carte">
                <h2>Aucun traitement en cours</h2>
                <div class="imp-hint ok">
                    La file est vide et tout ce qui a été déposé a été lu. Les derniers traitements
                    terminés sont listés plus bas.
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

                        <div style="font-family:'Barlow Condensed',sans-serif; font-size:24px; font-weight:700; margin-top:8px;">
                            {{ number_format((int) $lot->lignes_lues, 0, ',', ' ') }} ligne(s) lue(s)
                        </div>

                        {{-- La barre ne vise rien : la longueur du fichier n'est connue qu'une
                             fois lu. Elle dit « ça travaille », pas « il reste tant ». --}}
                        <div class="imp-jauge">
                            <i style="width:{{ $lot->etat === 'en_cours' ? '66%' : '10%' }}"></i>
                        </div>

                        <div class="imp-actions">
                            <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn p"
                               style="text-decoration:none; display:inline-block;">Voir le détail</a>

                            @if ($this->peutLancer && $lot->etat === 'depose')
                                <form method="POST" action="{{ route('import.lot.agir', $lot->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" name="geste" value="traiter" class="imp-btn n">
                                        Traiter celui-ci maintenant
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach

                @if ($this->peutLancer)
                    <form method="POST" action="{{ route('import.traitements.tout') }}">
                        @csrf
                        <div class="imp-actions">
                            <button type="submit" class="imp-btn r"
                                    data-confirmer-titre="Traiter les fichiers en attente"
                                    data-confirmer="Le travail sera fait dans cette page, fichier après fichier."
                                    data-confirmer-detail="Gardez l'onglet ouvert le temps de la lecture. Rien n'est écrit à moitié : chaque fichier est lu dans une transaction."
                                    data-confirmer-libelle="Tout traiter">
                                Tout traiter maintenant
                            </button>
                            <span style="font-size:12px; color:#6B6E76; max-width:420px; line-height:1.5;">
                                Le travail se fait dans cette page, jusqu'à
                                {{ \Modules\Import\Http\Controllers\TraitementsController::LOTS_PAR_PASSAGE }} fichiers
                                par passage&nbsp;: une requête web a un temps limité, et un fichier coupé en plein
                                traitement est exactement ce qu'on évite. Relancez pour prendre les suivants.
                            </span>
                        </div>
                    </form>
                @endif
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
