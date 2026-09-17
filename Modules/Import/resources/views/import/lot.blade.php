<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LigneRejeteeImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AnnulationDUnLot;
use Modules\Noyau\Imports\Services\CorrectionsDUnLot;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Le détail d'un dépôt
|--------------------------------------------------------------------------
| **Tous les gestes de cette page postent.** Réimporter, arrêter une lecture,
| annuler : c'étaient quatre `wire:click`, et dans un navigateur où la couche
| interactive ne démarre pas, un `wire:click` est un bouton peint. Le clic n'allait
| nulle part — sans erreur, sans trace dans le journal du serveur, sans rien qui
| permette de comprendre. Ce sont maintenant quatre formulaires vers un contrôleur.
|
| Ce que le composant garde, c'est ce qu'un formulaire ne sait pas faire : suivre
| l'avancée d'un traitement en cours, et paginer les rejets.
*/

state([
    'lotId' => null,
    'pageCourante' => 1,
    // L'annulation se demande, s'explique, puis se fait. Le premier temps passe par
    // l'adresse plutôt que par un aller-retour interactif : ?annuler=1.
    'annulationDemandee' => false,
]);

/*
 * Le lot est résolu par la liaison de route, donc filtré par le périmètre de l'entreprise :
 * un identifiant appartenant à une autre société ne se résout pas et rend une 404. C'est le
 * cloisonnement le moins coûteux et le plus sûr — il n'y a pas de code à oublier d'écrire.
 */
mount(function (LotImport $lot) {
    $this->lotId = $lot->id;
    $this->annulationDemandee = request()->boolean('annuler');
});

$parPage = computed(fn () => 25);

$leLot = computed(fn () => LotImport::with(['ville', 'site'])->findOrFail($this->lotId));

$rejets = computed(fn () => LigneRejeteeImport::where('lot_import_id', $this->lotId)
    ->orderBy('numero_ligne')
    ->get());

$total = computed(fn () => $this->rejets->count());

$page = computed(function () {
    $dernier = max(1, (int) ceil($this->total / $this->parPage));

    return min(max(1, (int) $this->pageCourante), $dernier);
});

$lignes = computed(fn () => $this->rejets->slice(($this->page - 1) * $this->parPage, $this->parPage)->values());

/** Les motifs de rejet regroupés : cinquante lignes refusées ont souvent une seule cause. */
$causes = computed(fn () => $this->rejets
    ->groupBy('motif')
    ->map->count()
    ->sortDesc());

$peutRelancer = computed(fn () => AccesImport::peutDeposer(auth()->user()));

$enTravail = computed(fn () => $this->leLot->estEnTravail());

/** Une lecture coupée par le serveur ne finira jamais : elle se relance comme une autre. */
$interrompu = computed(fn () => \Modules\Noyau\Imports\Services\SuiviDuTraitement::etat($this->leLot)['interrompu']);

/** Combien de corrections ont déjà été portées sur ce dépôt. */
$corrections = computed(fn () => (new CorrectionsDUnLot((int) auth()->user()->entreprise_id))
    ->histoire($this->leLot)->count());

/**
 * Ce qu'une annulation retirerait — avant de décider, jamais après.
 */
$apercuAnnulation = computed(fn () => $this->leLot->etat === 'termine'
    ? (new AnnulationDUnLot((int) auth()->user()->entreprise_id))->apercu($this->leLot)
    : null);

?>

<x-import::coquille page="lots">
    @php $lot = $this->leLot; @endphp

    <div @if ($this->enTravail) wire:poll.1s @endif>

        <div class="imp-carte">
            <h2>
                {{ $lot->nom_fichier }}
                <span class="chip">{{ $lot->etatLisible() }}</span>
            </h2>

            <div class="imp-g2" style="margin-bottom:15px;">
                <div style="font-size:13.5px; line-height:1.9;">
                    <div><strong>Type</strong> — {{ Registre::libelle($lot->format) }}</div>
                    <div><strong>Ville du dépôt</strong> —
                        {{ $lot->ville?->nom ?? 'toutes — ventilation par les codes employés' }}
                        @if ($lot->site) · {{ $lot->site->nom }} @endif
                    </div>
                    <div><strong>Déposé par</strong> — {{ $lot->deposant }},
                        le {{ $lot->created_at?->format('d/m/Y à H\hi') }}</div>
                    @if ($lot->periode)
                        <div><strong>Période annoncée</strong> — {{ $lot->periode }}</div>
                    @endif
                </div>
                <div style="font-size:13.5px; line-height:1.9;">
                    <div><strong>Taille</strong> — {{ number_format($lot->taille / 1024, 0, ',', ' ') }} Ko</div>
                    <div><strong>Fichier conservé</strong> —
                        {{ $lot->fichierPresent() ? 'oui, hors du serveur web' : 'non, il a été retiré' }}</div>
                    <div><strong>Empreinte</strong> —
                        <span style="font-family:ui-monospace,Consolas,monospace; font-size:11.5px;">
                            {{ substr($lot->empreinte, 0, 24) }}…
                        </span>
                    </div>
                    @if ($lot->demarre_le && $lot->termine_le)
                        <div><strong>Durée</strong> — {{ $lot->demarre_le->diffInSeconds($lot->termine_le) }} s</div>
                    @endif
                </div>
            </div>

            @if ($this->enTravail)
                <x-import::progression :lot="$lot" :peut-arreter="$this->peutRelancer" />
            @else
                <div class="imp-kpis cinq" style="margin-bottom:0;">
                    <div class="imp-kpi"><div class="lab">Lues</div>
                        <div class="val">{{ number_format((int) $lot->lignes_lues, 0, ',', ' ') }}</div></div>
                    <div class="imp-kpi vert"><div class="lab">Créées</div>
                        <div class="val">{{ number_format((int) $lot->lignes_creees, 0, ',', ' ') }}</div></div>
                    <div class="imp-kpi"><div class="lab">Mises à jour</div>
                        <div class="val">{{ number_format((int) $lot->lignes_majs, 0, ',', ' ') }}</div></div>
                    <div class="imp-kpi"><div class="lab">Inchangées</div>
                        <div class="val">{{ number_format((int) $lot->lignes_ignorees, 0, ',', ' ') }}</div>
                        <div class="sub">déjà à jour en base</div></div>
                    <div class="imp-kpi {{ $lot->lignes_rejetees > 0 ? 'rouge' : '' }}">
                        <div class="lab">Rejetées</div>
                        <div class="val">{{ number_format((int) $lot->lignes_rejetees, 0, ',', ' ') }}</div></div>
                </div>
            @endif

            @if ($lot->message)
                <div class="imp-hint {{ $lot->etat === 'echec' ? 'warn' : ($lot->etat === 'termine' ? 'ok' : '') }}">
                    {{ $lot->message }}
                    @if ($lot->etat === 'controle')
                        <br><strong>Rien n'a été écrit</strong> — c'était un contrôle.
                    @endif
                </div>
            @endif

            {{-- Des formulaires plutôt que des `wire:click` : ces boutons ne répondaient pas,
                 et c'était la seule raison. Un formulaire qui poste ne peut pas échouer en
                 silence — ou la page change, ou elle dit pourquoi.

                 **Deux boutons retirés, et pourquoi.** « Recontrôler » relisait le fichier
                 pour annoncer ce qu'il produirait : utile avant le premier import, sans objet
                 sur un dépôt déjà écrit en base, où le journal ci-dessus dit déjà ce qui a été
                 fait. « Traiter maintenant » doublait « Réimporter » à un détail près, le
                 passage par la file — un réglage technique qui n'a rien à faire sous les yeux
                 de qui consulte un dépôt. Relancer tout de suite après une correction reste
                 possible : c'est le bouton de l'écran des lignes refusées, là où il sert. --}}
            @if ($this->peutRelancer && (! $this->enTravail || $this->interrompu))
                <div class="imp-actions">
                    <form method="POST" action="{{ route('import.lot.agir', $lot->id) }}" style="display:inline;">
                        @csrf
                        <button type="submit" name="geste" value="reimporter" class="imp-btn r">
                            {{ $lot->etat === 'termine' ? 'Réimporter' : 'Importer pour de bon' }}
                        </button>
                    </form>

                    <a href="{{ route('import.lot.fichier', $lot->id) }}" class="imp-btn p"
                       style="text-decoration:none; display:inline-block;
                              {{ $lot->fichierPresent() ? '' : 'opacity:.4; pointer-events:none;' }}">
                        ↓ Télécharger le fichier
                    </a>
                </div>

                <div class="imp-hint">
                    <strong>Ce que font ces deux boutons.</strong>
                    <em>Réimporter</em> rejoue le dépôt : le fichier est relu et ce qui existe déjà
                    n'est pas recréé, chaque format retrouvant ses fiches par leur clé. La lecture
                    démarre aussitôt, et la barre ci-dessus avance au fil des lignes.
                    <em>Télécharger</em> rend le classeur tel qu'il a été déposé, pour un audit.
                </div>
            @endif

            {{-- ------------------------------------------------------- revenir en arrière

                 « Aucun import n'écrit à moitié » parle de l'import qui échoue : la
                 transaction ramène la base à l'état d'avant. Elle ne disait rien du cas
                 fréquent — l'import qui réussit avec le mauvais fichier ou la mauvaise
                 ville. C'est ce que fait ce bloc, et il annonce d'abord ce qu'il ferait. --}}
            @if ($this->peutRelancer && $lot->etat === 'termine' && $this->apercuAnnulation)
                @php $ap = $this->apercuAnnulation; @endphp
                <div style="margin-top:16px; border-top:1px dashed #E3E0D8; padding-top:13px;">
                    @if (! $annulationDemandee)
                        <div class="imp-actions" style="margin-top:0;">
                            <a href="{{ route('import.lot', ['lot' => $lot->id, 'annuler' => 1]) }}"
                               class="imp-btn o" style="text-decoration:none; display:inline-block;">
                                Annuler cet import
                            </a>
                            <span style="font-size:12px; color:#6B6E76; max-width:420px; line-height:1.5;">
                                Retirerait <strong>{{ number_format($ap['total'], 0, ',', ' ') }}</strong> ligne(s)
                                de la base.
                                @if ($ap['retenues'] > 0)
                                    {{ number_format($ap['retenues'], 0, ',', ' ') }} seraient gardées : elles ont été
                                    retouchées depuis.
                                @endif
                            </span>
                        </div>
                    @else
                        <div class="imp-hint warn">
                            <strong>Ce que l'annulation va retirer.</strong>
                            <div class="imp-tbl-wrap" style="margin-top:8px; background:#fff;">
                                <table class="imp-tbl">
                                    <thead><tr><th>Table</th><th class="num">Supprimées</th><th class="num">Retenues</th></tr></thead>
                                    <tbody>
                                        @foreach ($ap['par_table'] as $ligne)
                                            <tr>
                                                <td>{{ $ligne['libelle'] }}</td>
                                                <td class="num">{{ number_format($ligne['supprimables'], 0, ',', ' ') }}</td>
                                                <td class="num">{{ number_format($ligne['retenues'], 0, ',', ' ') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div style="margin-top:8px; font-weight:400;">
                                Les lignes <strong>créées</strong> par cet import repartent : elles n'existaient pas
                                avant lui. Celles qu'il n'a fait que <strong>mettre à jour</strong>, ou qui ont été
                                retouchées depuis, restent — leur valeur d'avant n'a jamais été conservée, et
                                prétendre la restituer serait un mensonge.
                            </div>
                        </div>

                        <form method="POST" action="{{ route('import.lot.agir', $lot->id) }}">
                            @csrf
                            <input type="hidden" name="geste" value="annuler">

                            <div class="imp-fld" style="margin-top:10px; max-width:520px;">
                                <label for="motif">Pourquoi annuler&nbsp;?</label>
                                <input type="text" id="motif" name="motif" maxlength="255" required
                                       placeholder="Déposé sous la mauvaise ville, fichier de juillet au lieu d'août…">
                            </div>

                            <div class="imp-actions">
                                <button type="submit" class="imp-btn r"
                                        data-confirmer-titre="Annuler cet import"
                                        data-confirmer="{{ number_format($ap['total'], 0, ',', ' ') }} ligne(s) vont être retirées de la base."
                                        data-confirmer-detail="Les lignes créées par cet import repartent. Celles qu'il n'a fait que mettre à jour, ou qui ont été retouchées depuis, restent : leur valeur d'avant n'a jamais été conservée."
                                        data-confirmer-libelle="Annuler l'import"
                                        data-confirmer-ton="alerte">
                                    Annuler définitivement cet import
                                </button>
                                <a href="{{ route('import.lot', $lot->id) }}" class="imp-btn p"
                                   style="text-decoration:none; display:inline-block;">Revenir</a>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            @if ($lot->etat === 'annule' && $lot->annule_le)
                <div class="imp-hint">
                    Import annulé le {{ $lot->annule_le->format('d/m/Y à H:i') }}.
                    Le fichier reste déposé : vous pouvez le redéposer sous une autre ville ou un autre type.
                </div>
            @endif

        </div>

        {{-- ------------------------------------------------------------------ les rejets --}}
        @if ($this->total > 0)
            <div class="imp-carte">
                <h2>Pourquoi des lignes ont été refusées <span class="chip">{{ $this->total }} conservée(s)</span></h2>

                <div class="imp-tbl-wrap" style="margin-bottom:14px;">
                    <table class="imp-tbl">
                        <thead><tr><th>Motif</th><th class="num">Lignes</th></tr></thead>
                        <tbody>
                            @foreach ($this->causes as $motif => $combien)
                                <tr>
                                    <td>{{ $motif }}</td>
                                    <td class="num">{{ $combien }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="imp-hint">
                    Une ligne refusée n'est ni corrigée ni écrasée&nbsp;: elle est mise de côté avec ses
                    valeurs d'origine. Le numéro de ligne est celui du tableur&nbsp;: vous pouvez ouvrir le
                    fichier et y aller directement — ou <strong>corriger ici</strong>, ce qui évite de
                    redéposer neuf mille lignes pour trois cellules. Le fichier reçu, lui, n'est jamais
                    réécrit&nbsp;: la correction se pose par-dessus à la relecture, et la traçabilité dit
                    ce qui sépare les deux.
                </div>

                @if ($this->peutRelancer)
                    <div class="imp-actions">
                        <a href="{{ route('import.lot.rejets', $lot->id) }}" class="imp-btn r"
                           style="text-decoration:none; display:inline-block;">
                            Modifier les {{ $this->total }} ligne(s) refusée(s)
                        </a>
                        <a href="{{ route('import.lot.tracabilite', $lot->id) }}" class="imp-btn n"
                           style="text-decoration:none; display:inline-block;">
                            Traçabilité{{ $this->corrections > 0 ? ' ('.$this->corrections.')' : '' }}
                        </a>
                        <a href="{{ route('import.lot.fichier', $lot->id) }}" class="imp-btn p"
                           style="text-decoration:none; display:inline-block;
                                  {{ $lot->fichierPresent() ? '' : 'opacity:.4; pointer-events:none;' }}">
                            ↓ Fichier d'origine
                        </a>
                    </div>
                @endif

                <div class="imp-tbl-wrap" style="margin-top:14px;">
                    <table class="imp-tbl">
                        <thead>
                            <tr>
                                <th style="width:70px;">Ligne</th>
                                <th style="width:34%;">Motif</th>
                                <th>Ce que portait la ligne</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->lignes as $rejet)
                                <tr wire:key="rejet-{{ $rejet->id }}">
                                    <td class="num">{{ $rejet->numero_ligne }}</td>
                                    <td>{{ $rejet->motif }}</td>
                                    <td class="mono" style="max-width:520px;">
                                        {{ collect($rejet->valeurs)->filter(fn ($v) => trim((string) $v) !== '')
                                            ->take(9)->implode('  ·  ') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-pagination :page="$this->page" :total="$this->total" prop="pageCourante" :par-page="$this->parPage" />
            </div>
        @elseif ($lot->etat === 'termine')
            <div class="imp-carte">
                <h2>Rejets</h2>
                <div class="imp-hint ok">Aucune ligne refusée&nbsp;: le fichier a été lu en entier.</div>

                @if ($this->corrections > 0)
                    <div class="imp-actions">
                        <a href="{{ route('import.lot.tracabilite', $lot->id) }}" class="imp-btn n"
                           style="text-decoration:none; display:inline-block;">
                            Traçabilité des {{ $this->corrections }} correction(s)
                        </a>
                    </div>
                @endif
            </div>
        @endif

        <div class="imp-actions">
            <a href="{{ route('import.lots') }}" wire:navigate class="imp-btn o"
               style="text-decoration:none; display:inline-block;">‹ Retour au journal</a>
        </div>
    </div>
</x-import::coquille>
