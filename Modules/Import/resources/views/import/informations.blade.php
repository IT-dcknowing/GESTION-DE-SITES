<?php

use Modules\Noyau\Imports\Services\AlimentationDesEcrans;

use function Livewire\Volt\computed;

/*
|--------------------------------------------------------------------------
| Informations — quel fichier alimente quelle page
|--------------------------------------------------------------------------
| **La question à laquelle cette page répond**, et qu'on posait faute de réponse
| écrite : devant une balance âgée vide, manque-t-il les factures, les impayés, ou
| les deux ? Le lien entre les huit fichiers du logiciel d'atelier et la quinzaine
| d'écrans qui en vivent n'était consigné nulle part. On redéposait au hasard.
|
| Le tableau se lit dans les deux sens, et c'est ce qui le rend utile :
| d'un fichier vers les écrans qu'il remplit, et d'un écran vers le fichier qui lui
| manque. Les compteurs sont mesurés en base à chaque affichage — un tableau de
| correspondances qui se désaligne du réel est pire qu'une absence de tableau.
*/

$service = fn () => new AlimentationDesEcrans((int) auth()->user()->entreprise_id);

$lignes = computed(fn () => $this->service()->lignes());

$ecrans = computed(fn () => $this->service()->parEcran());

/** Une route qui n'existe pas ne fait pas tomber la page : elle s'affiche sans lien. */
$adresse = function (string $nom): ?string {
    try {
        return route($nom);
    } catch (\Throwable) {
        return null;
    }
};

?>

<x-import::coquille page="informations">

    <div class="imp-carte">
        <h2>Ce que chaque import alimente <span class="chip">notice</span></h2>

        <div style="font-size:14px; line-height:1.7;">
            Huit fichiers sortent du logiciel d'atelier. Chacun remplit des écrans précis, et
            <strong>aucun écran ne se remplit tout seul</strong>. Ce tableau dit lequel dépose quoi,
            dans quel ordre, et ce qu'il en coûte de sauter une étape.
        </div>

        <div class="imp-hint" style="margin-top:11px;">
            <strong>L'ordre compte, sans être bloquant.</strong> Le parc fonde la fiche de réception que
            le devis et la facture citent ; les impayés apportent les règlements, qui n'ont de sens qu'en
            regard d'une facture. Importer dans le désordre ne casse rien — le rattachement rattrape ce
            qu'il peut — mais laisse des lignes arrêtées à la ville là où elles auraient pu descendre à
            l'atelier.
        </div>
    </div>

    <div class="imp-carte">
        <h2>Du fichier vers les pages</h2>

        <div class="imp-tbl-wrap">
            <table class="imp-tbl">
                <thead>
                    <tr>
                        <th style="width:20%;">Fichier déposé</th>
                        <th style="width:26%;">Ce qu'il apporte</th>
                        <th style="width:26%;">Pages alimentées</th>
                        <th class="num" style="width:110px;">En base</th>
                        <th style="width:130px;">Dernier dépôt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->lignes as $ligne)
                        <tr>
                            <td>
                                <strong>{{ $ligne['libelle'] }}</strong>
                                @if ($ligne['prealable'])
                                    <div style="color:#6B6E76; font-size:11.5px; margin-top:3px;">
                                        après&nbsp;: {{ $ligne['prealable'] }}
                                    </div>
                                @endif
                            </td>
                            <td style="font-size:12.5px; line-height:1.55;">
                                {{ $ligne['apporte'] }}
                                <div style="color:#6B6E76; margin-top:5px;">{{ $ligne['consequence'] }}</div>
                            </td>
                            <td style="font-size:12.5px; line-height:1.9;">
                                @foreach ($ligne['ecrans'] as $ecran)
                                    @php $url = $this->adresse($ecran['route']); @endphp
                                    @if ($url)
                                        <a href="{{ $url }}" style="color:#C8102E; font-weight:600; text-decoration:none;">
                                            {{ $ecran['libelle'] }}</a>@if (! $loop->last)<span style="color:#B7B9BE;"> · </span>@endif
                                    @else
                                        {{ $ecran['libelle'] }}@if (! $loop->last)<span style="color:#B7B9BE;"> · </span>@endif
                                    @endif
                                @endforeach
                            </td>
                            <td class="num" style="white-space:nowrap;">
                                @if ($ligne['lignes_en_base'] === null)
                                    <span style="color:#C8102E; font-size:11.5px;">table à créer</span>
                                @else
                                    {{ number_format($ligne['lignes_en_base'], 0, ',', ' ') }}
                                @endif
                            </td>
                            <td style="white-space:nowrap; font-size:12.5px;">
                                @if ($ligne['dernier'])
                                    {{ $ligne['dernier']->created_at?->format('d/m/Y') }}
                                    <div style="color:#6B6E76; font-size:11.5px;">
                                        {{ $ligne['lots'] }} dépôt(s)
                                    </div>
                                @else
                                    <span style="color:#C8102E; font-weight:600;">jamais déposé</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="imp-carte">
        <h2>De la page vers le fichier <span class="chip">la lecture inverse</span></h2>

        <div class="imp-hint">
            Une page vide n'est presque jamais une panne&nbsp;: c'est un fichier qui n'a pas été déposé.
            Cherchez la page dans cette colonne, et vous saurez quoi déposer.
        </div>

        <div class="imp-tbl-wrap" style="margin-top:11px;">
            <table class="imp-tbl">
                <thead>
                    <tr>
                        <th style="width:34%;">Page</th>
                        <th>Se remplit avec</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->ecrans as $ecran)
                        <tr>
                            <td>
                                @php $url = $this->adresse($ecran['route']); @endphp
                                @if ($url)
                                    <a href="{{ $url }}" style="color:#C8102E; font-weight:700; text-decoration:none;">
                                        {{ $ecran['libelle'] }}</a>
                                @else
                                    <strong>{{ $ecran['libelle'] }}</strong>
                                @endif
                            </td>
                            <td style="font-size:12.5px;">{{ implode(' · ', $ecran['fichiers']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="imp-carte">
        <h2>Ce que le recouvrement attend, précisément</h2>

        <div style="font-size:14px; line-height:1.75;">
            Trois fichiers seulement font vivre ce module, et ils s'enchaînent&nbsp;:
        </div>

        <ol style="margin:10px 0 0; padding-left:22px; font-size:13.5px; line-height:1.8;">
            <li><strong>La situation du parc</strong> — elle pose la fiche de réception que les factures
                citent, et sans laquelle une facture ne sait pas de quel atelier elle relève.</li>
            <li><strong>Les factures</strong> — elles créent la créance. Tant qu'elles ne sont pas
                déposées, la balance âgée est vide, et <em>ce n'est pas une anomalie</em>.</li>
            <li><strong>L'état des impayés</strong> — il apporte les règlements déjà reçus, donc le reste
                à devoir. Déposé avant les factures, il n'a rien à diminuer&nbsp;: l'encours reste faux
                jusqu'au dépôt suivant.</li>
        </ol>

        <div class="imp-hint" style="margin-top:12px;">
            Ce qui est saisi à la main dans ce module — encaissements, relances — <strong>ne vient
            d'aucun import et n'est jamais écrasé par un dépôt</strong>. Un import qui repasse met à jour
            ce qu'il a lui-même créé&nbsp;; il ne touche pas au travail de l'agent.
        </div>
    </div>

</x-import::coquille>
