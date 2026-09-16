<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\CorrectionsDUnLot;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Corriger les lignes qu'un import a refusées
|--------------------------------------------------------------------------
| **Ce que cette page remplace.** Trois lignes refusées sur neuf mille obligeaient
| à rouvrir le classeur dans un tableur, à corriger, puis à tout redéposer — et le
| fichier corrigé, n'ayant plus la même empreinte, revenait comme un fichier neuf,
| sans lien avec celui qu'il remplaçait. On refaisait un import entier pour trois
| cellules.
|
| Ici on ne voit **que les lignes refusées**, et **que les colonnes du format** :
| pas quarante cases anonymes, mais « MONTANT », « DATE FACTURE », « CLIENT »,
| retrouvées dans l'en-tête réel du fichier. Deux gestes seulement : corriger, ou
| retirer la ligne de l'import quand elle n'a rien à y faire.
|
| **Le fichier déposé n'est jamais réécrit.** Il reste la pièce d'origine ; les
| corrections se posent par-dessus au moment de la relecture. La page Traçabilité
| montre ce qui sépare l'un de l'autre, et par qui.
|
| Tout poste : aucun geste de cet écran ne dépend de la couche interactive.
*/

state(['lotId' => null]);

mount(function (LotImport $lot) {
    $this->lotId = $lot->id;
});

$leLot = computed(fn () => LotImport::with('ville')->findOrFail($this->lotId));

$service = fn () => new CorrectionsDUnLot((int) auth()->user()->entreprise_id);

$plan = computed(fn () => $this->service()->plan($this->leLot));

$peutCorriger = computed(fn () => AccesImport::peutDeposer(auth()->user()));

$corrections = computed(fn () => $this->service()->histoire($this->leLot));

?>

<x-import::coquille page="lots">
    @php $lot = $this->leLot; $plan = $this->plan; @endphp

    <div class="imp-carte">
        <h2>
            Corriger les lignes refusées
            <span class="chip">{{ $plan['lignes']->count() }} ligne(s)</span>
        </h2>

        <div style="font-size:13.5px; line-height:1.9; margin-bottom:12px;">
            <div><strong>Fichier</strong> — {{ $lot->nom_fichier }}</div>
            <div><strong>Type</strong> — {{ Registre::libelle($lot->format) }}</div>
            <div><strong>Déposé par</strong> — {{ $lot->deposant }},
                le {{ $lot->created_at?->format('d/m/Y à H\hi') }}</div>
        </div>

        <div class="imp-hint">
            <strong>Le fichier déposé ne sera pas modifié.</strong> Ce que vous corrigez ici est
            conservé à côté de lui et appliqué à la <em>relecture</em> : l'import verra vos valeurs,
            le disque gardera celles du fichier, et l'écart entre les deux restera consultable.
            C'est ce qui permet, dans six mois, de répondre à « d'où sort ce montant ».
        </div>

        <div class="imp-actions">
            <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn o"
               style="text-decoration:none; display:inline-block;">‹ Retour au dépôt</a>
            <a href="{{ route('import.lot.tracabilite', $lot->id) }}" class="imp-btn n"
               style="text-decoration:none; display:inline-block;">Traçabilité ({{ $this->corrections->count() }})</a>
            @if ($lot->fichierPresent())
                <a href="{{ route('import.lot.fichier', $lot->id) }}" class="imp-btn p"
                   style="text-decoration:none; display:inline-block;">↓ Fichier d'origine</a>
            @endif
        </div>
    </div>

    @if (! $this->peutCorriger)
        <div class="imp-lock">
            Votre rôle vous permet de consulter les imports, pas de les corriger.
            Corriger une ligne change un montant qui finira dans un chiffre d'affaires.
        </div>
    @elseif ($plan['lignes']->isEmpty())
        <div class="imp-carte">
            <div class="imp-hint ok">
                Aucune ligne refusée sur ce dépôt&nbsp;: le fichier a été lu en entier.
            </div>
        </div>
    @else
        <div class="imp-carte">
            <h2>Les lignes à reprendre <span class="chip">colonnes réelles du fichier</span></h2>

            @if ($plan['fichier_absent'])
                <div class="imp-hint warn">
                    Le fichier de ce dépôt n'est plus conservé&nbsp;: les colonnes portent donc le repère
                    qu'elles ont dans le tableur — A, B, C — et non leur intitulé. Les corrections
                    s'enregistrent normalement&nbsp;; en revanche l'import ne pourra pas être rejoué
                    tant que le fichier n'aura pas été redéposé.
                </div>
            @elseif ($plan['colonnes'] === [])
                <div class="imp-hint warn">
                    L'en-tête de ce fichier n'a pas été reconnu&nbsp;: les colonnes sont désignées par
                    leur place. Vérifiez que le type déclaré est le bon.
                </div>
            @endif

            <form method="POST" action="{{ route('import.lot.corriger', $lot->id) }}">
                @csrf

                <div class="imp-tbl-wrap">
                    <table class="imp-tbl">
                        <thead>
                            <tr>
                                <th style="width:66px;">Ligne</th>
                                <th style="width:210px;">Pourquoi refusée</th>
                                @foreach ($plan['colonnes'] as $colonne)
                                    <th style="min-width:130px;">
                                        {{ $colonne['intitule'] }}
                                        @if ($colonne['obligatoire'])
                                            <span style="color:#C8102E;" title="Sans elle, la ligne sera refusée à nouveau.">*</span>
                                        @endif
                                    </th>
                                @endforeach
                                <th style="width:74px;">Retirer</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plan['lignes'] as $ligne)
                                @php $rejet = $ligne['rejet']; $n = (int) $rejet->numero_ligne; @endphp
                                <tr>
                                    <td class="num"><strong>{{ $n }}</strong></td>
                                    <td style="font-size:12px; line-height:1.45;">
                                        {{ $rejet->motif }}
                                        @if ($ligne['correction'])
                                            <div style="margin-top:4px;">
                                                <span class="pastille pControle">
                                                    {{ $ligne['correction']->actionLisible() }}
                                                    le {{ $ligne['correction']->created_at?->format('d/m à H\hi') }}
                                                </span>
                                            </div>
                                        @endif
                                    </td>

                                    @forelse ($plan['colonnes'] as $colonne)
                                        @php $position = $colonne['position']; @endphp
                                        <td>
                                            <input type="text"
                                                   name="valeurs[{{ $n }}][{{ $position }}]"
                                                   value="{{ $ligne['valeurs'][$position] ?? '' }}"
                                                   maxlength="190"
                                                   style="width:100%; box-sizing:border-box; padding:6px 8px;
                                                          border:1px solid #E3E0D8; border-radius:6px; font-size:12.5px;
                                                          font-family:var(--font-sans);">
                                        </td>
                                    @empty
                                        {{-- Plus aucune colonne connue, même par sa place : la ligne
                                             refusée ne portait aucune valeur. Il n'y a rien à
                                             corriger, seulement à écarter. --}}
                                        <td class="mono" style="font-size:11.5px; color:#6B6E76;">
                                            Ligne vide dans le fichier.
                                        </td>
                                    @endforelse

                                    <td style="text-align:center;">
                                        {{-- Retirer, c'est décider que cette ligne n'a pas à entrer.
                                             Ce n'est ni un rejet ni un oubli : c'est une décision, et
                                             elle est écrite comme telle dans la traçabilité. --}}
                                        <input type="checkbox" name="retirer[]" value="{{ $n }}"
                                               style="width:16px; height:16px; cursor:pointer;"
                                               title="Écarter cette ligne de l'import"
                                               @checked($ligne['correction']?->action === 'retiree')>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="imp-fld" style="margin-top:13px; max-width:560px;">
                    <label for="motif">Pourquoi cette correction&nbsp;? <span style="font-weight:400; color:#6B6E76;">(conservé avec elle)</span></label>
                    <input type="text" id="motif" name="motif" maxlength="255"
                           placeholder="Montant absent du fichier, confirmé par la facture papier…">
                </div>

                <div class="imp-actions">
                    <button type="submit" class="imp-btn o">Enregistrer sans relancer</button>
                    <button type="submit" name="relancer" value="1" class="imp-btn r">
                        Enregistrer et relancer l'import
                    </button>
                    <span style="font-size:12px; color:#6B6E76; max-width:400px; line-height:1.5;">
                        La lecture redémarre aussitôt et son avancée s'affiche&nbsp;: vous voyez vite si les
                        lignes corrigées passent. Ce qui est déjà en base n'est pas recréé.
                    </span>
                </div>
            </form>
        </div>
    @endif
</x-import::coquille>
