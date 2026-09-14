<?php

use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\CorrectionsDUnLot;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Traçabilité des corrections d'un import
|--------------------------------------------------------------------------
| **Pourquoi une page à part.** Corriger une ligne d'import, c'est réécrire ce qu'un
| fichier officiel disait — un montant, une date, un client — et ce qu'on écrit là
| finit dans un chiffre d'affaires. Cela ne se glisse pas sous un tableau : cela se
| lit, cela se recoupe, cela se sort devant quelqu'un qui pose une question.
|
| Cinq choses sont conservées pour chaque correction, et aucune n'est décorative :
| **ce qui était**, **ce qui a été mis**, **par qui**, **quand**, et **depuis quel
| poste** — l'adresse IP et le navigateur. Les quatre premières disent l'histoire ;
| la cinquième est ce qui distingue une faute de frappe d'un contournement.
|
| Ces relevés sont des données personnelles. Ils ne sont montrés qu'à qui répond des
| imports, ils ne sortent pas de l'application, et ils disparaissent avec l'entreprise.
*/

state(['lotId' => null]);

mount(function (LotImport $lot) {
    $this->lotId = $lot->id;
});

$leLot = computed(fn () => LotImport::with('ville')->findOrFail($this->lotId));

$corrections = computed(fn () => (new CorrectionsDUnLot((int) auth()->user()->entreprise_id))
    ->histoire($this->leLot));

$colonnes = computed(function () {
    if (! $this->leLot->fichierPresent() || ! Registre::connait($this->leLot->format)) {
        return [];
    }

    $plan = (new CorrectionsDUnLot((int) auth()->user()->entreprise_id))->plan($this->leLot);

    return collect($plan['colonnes'])->pluck('intitule', 'position')->all();
});

/** Le nom d'une colonne, ou sa place quand le fichier ne dit plus rien. */
$nomDeColonne = fn (int $position) => $this->colonnes[$position] ?? ('Colonne '.chr(65 + min(25, $position)));

?>

<x-import::coquille page="lots">
    @php $lot = $this->leLot; @endphp

    <div class="imp-carte">
        <h2>
            Traçabilité des corrections
            <span class="chip">{{ $this->corrections->count() }} écriture(s)</span>
        </h2>

        <div style="font-size:13.5px; line-height:1.9; margin-bottom:12px;">
            <div><strong>Fichier</strong> — {{ $lot->nom_fichier }}</div>
            <div><strong>Type</strong> — {{ Registre::libelle($lot->format) }}</div>
            <div><strong>Empreinte du fichier reçu</strong> —
                <span style="font-family:ui-monospace,Consolas,monospace; font-size:11.5px;">
                    {{ substr((string) $lot->empreinte, 0, 32) }}…
                </span>
            </div>
        </div>

        <div class="imp-hint">
            <strong>Le fichier d'origine n'a jamais été réécrit.</strong> Son empreinte est celle
            calculée à la réception&nbsp;: elle le prouve. Chaque correction ci-dessous s'ajoute
            par-dessus au moment de la relecture, sans jamais toucher au classeur déposé.
        </div>

        <div class="imp-actions">
            <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn o"
               style="text-decoration:none; display:inline-block;">‹ Retour au dépôt</a>
            <a href="{{ route('import.lot.rejets', $lot->id) }}" class="imp-btn n"
               style="text-decoration:none; display:inline-block;">Corriger des lignes</a>
            @if ($lot->fichierPresent())
                <a href="{{ route('import.lot.fichier', $lot->id) }}" class="imp-btn p"
                   style="text-decoration:none; display:inline-block;">↓ Fichier d'origine</a>
            @endif
        </div>
    </div>

    @if ($this->corrections->isEmpty())
        <div class="imp-carte">
            <div class="imp-hint">
                Aucune correction n'a été portée sur ce dépôt. Ce qui est en base vient du fichier
                tel qu'il a été reçu.
            </div>
        </div>
    @else
        <div class="imp-carte">
            <h2>Ce qui a été changé, et par qui</h2>

            <div class="imp-tbl-wrap">
                <table class="imp-tbl">
                    <thead>
                        <tr>
                            <th style="width:130px;">Quand</th>
                            <th style="width:62px;">Ligne</th>
                            <th style="width:110px;">Action</th>
                            <th>Ce qui était → ce qui a été mis</th>
                            <th style="width:150px;">Par qui</th>
                            <th style="width:170px;">Depuis où</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->corrections as $correction)
                            <tr>
                                <td style="white-space:nowrap; font-size:12px;">
                                    {{ $correction->created_at?->format('d/m/Y') }}
                                    <div style="color:#6B6E76;">{{ $correction->created_at?->format('H\hi\m\i\n') }}</div>
                                </td>
                                <td class="num"><strong>{{ $correction->numero_ligne }}</strong></td>
                                <td>
                                    <span class="pastille {{ $correction->action === 'retiree' ? 'pAnnule' : 'pControle' }}">
                                        {{ $correction->actionLisible() }}
                                    </span>
                                </td>
                                <td style="font-size:12px; line-height:1.6;">
                                    @if ($correction->action === 'retiree')
                                        <span style="color:#6B6E76;">
                                            La ligne a été écartée de l'import. Ses valeurs d'origine sont conservées&nbsp;:
                                            {{ collect($correction->valeurs_avant ?? [])->filter(fn ($v) => trim((string) $v) !== '')
                                                ->take(6)->implode('  ·  ') }}
                                        </span>
                                    @else
                                        @forelse ($correction->ecarts() as $position => $ecart)
                                            <div style="margin-bottom:3px;">
                                                <strong>{{ $this->nomDeColonne($position) }}</strong> —
                                                <span style="text-decoration:line-through; color:#8C1023;">
                                                    {{ $ecart['avant'] !== '' ? $ecart['avant'] : '(vide)' }}
                                                </span>
                                                &nbsp;→&nbsp;
                                                <strong style="color:#1E7B34;">
                                                    {{ $ecart['apres'] !== '' ? $ecart['apres'] : '(vide)' }}
                                                </strong>
                                            </div>
                                        @empty
                                            <span style="color:#6B6E76;">Aucun écart de valeur.</span>
                                        @endforelse
                                    @endif

                                    @if ($correction->motif)
                                        <div style="margin-top:4px; color:#6B6E76;">
                                            <em>Motif&nbsp;: {{ $correction->motif }}</em>
                                        </div>
                                    @endif
                                </td>
                                <td style="font-size:12.5px;">
                                    <strong>{{ $correction->auteur }}</strong>
                                    @if ($correction->utilisateur)
                                        <div style="color:#6B6E76; font-size:11.5px; word-break:break-all;">
                                            {{ $correction->utilisateur->email }}
                                        </div>
                                    @endif
                                </td>
                                <td style="font-size:11.5px; color:#6B6E76;">
                                    <div class="mono">{{ $correction->adresse_ip ?? '—' }}</div>
                                    @if ($correction->poste)
                                        <div style="word-break:break-word; margin-top:3px;">
                                            {{ \Illuminate\Support\Str::limit($correction->poste, 70) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="imp-hint">
                <strong>Comment lire l'adresse.</strong> C'est celle du poste depuis lequel la
                correction a été envoyée, telle que la voit le serveur. Derrière un routeur d'entreprise,
                plusieurs postes partagent la même&nbsp;: elle situe un lieu, elle ne désigne pas une
                personne — c'est le nom à côté qui le fait.
            </div>
        </div>
    @endif
</x-import::coquille>
