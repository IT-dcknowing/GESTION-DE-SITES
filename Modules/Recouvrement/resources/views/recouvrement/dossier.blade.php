<?php

use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\AccesRecouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use Modules\Recouvrement\Support\PortefeuilleDeRecouvrement;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Dossier d'un tiers — tout ce qu'on sait de lui, sur une seule page
|--------------------------------------------------------------------------
| C'est la page qu'ouvre le bouton « Détail » du tableau de bord, et elle a sa
| propre adresse : elle se met en favori, s'ouvre dans un autre onglet, et
| s'envoie par message à qui doit s'en occuper.
|
| Elle rassemble ce qui était jusqu'ici éparpillé sur trois écrans : les factures
| ouvertes et leur âge (balance âgée), les relances faites et par qui (journal des
| relances), les règlements reçus (journal des encaissements). Ce qui fait la
| différence, c'est de les voir ensemble : une facture de cent quatre-vingts jours
| relancée trois fois sans résultat et une facture du même âge que personne n'a
| jamais appelée appellent deux décisions opposées, et la balance âgée seule les
| affiche à l'identique.
|
| L'extrait de compte reste à côté, et il garde son rôle : c'est le document qu'on
| remet au client. Celui-ci est le dossier interne, celui qu'on lit avant d'appeler.
*/

state(['tiers' => '']);
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

mount(function (string $tiers) {
    $this->tiers = $tiers;
});

$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

$dossier = computed(fn () => (new PortefeuilleDeRecouvrement($this->arrete, $this->periode->debut))
    ->dossier($this->tiers));

?>

{{-- Pleine page, comme le tableau de bord d'où l'on vient : c'est une fiche qu'on lit et
     qu'on imprime, pas un écran de travail du module. Elle n'a donc pas à porter la barre
     latérale des neuf écrans, ni à s'en laisser rogner la largeur. --}}
<x-recouvrement::pleine-page
    :titre="$this->tiers"
    sous-titre="Ce qui est dû, ce qui a été fait, et ce qui est rentré."
    :retour="route('recouvrement.tableau-de-bord')"
    retour-libelle="← Retour au tableau de bord">

    @php
        $d = $this->dossier;
        $ligne = $d['ligne'];
    @endphp

    @if ($ligne === null)
        <div class="rec-carte">
            <h2>{{ $d['tiers'] }}</h2>
            <div class="rec-hint ok">
                <strong>Ce tiers ne doit rien à l'arrêté du {{ $this->arrete->format('d/m/Y') }}.</strong>
                Soit toutes ses factures sont soldées, soit elles sont postérieures à cette date.
                Son historique reste consultable plus bas.
            </div>
        </div>
    @else
        {{-- ─────────────────────────── l'essentiel, en tête ─────────────────────────── --}}
        <div class="rec-carte" style="margin-bottom:15px;">
            <h2>
                Situation du compte
                <span class="chip">arrêté au {{ $this->arrete->format('d/m/Y') }}</span>
            </h2>

            <div class="rec-kpis" style="margin-bottom:0;">
                <div class="rec-kpi rouge">
                    <div class="lab">Reste à payer</div>
                    <div class="val">{{ Recouvrement::fr($ligne['reste']) }}</div>
                    <div class="sub">{{ $ligne['nombre'] }} facture(s) ouverte(s)</div>
                </div>
                <div class="rec-kpi">
                    <div class="lab">Doit depuis</div>
                    <div class="val">{{ $ligne['depuis'] !== null ? $ligne['depuis'].' j' : '—' }}</div>
                    <div class="sub">
                        @if ($ligne['plus_ancienne_numero'])
                            plus ancienne : n° {{ $ligne['plus_ancienne_numero'] }}
                        @else
                            facture non datée
                        @endif
                    </div>
                </div>
                <div class="rec-kpi">
                    <div class="lab">Niveau appelé</div>
                    <div class="val" style="font-size:20px; padding-top:4px;">
                        <span class="pill {{ $ligne['niveau']['classe'] }}">{{ $ligne['niveau']['libelle'] }}</span>
                    </div>
                    <div class="sub">d'après la facture la plus ancienne</div>
                </div>
                <div class="rec-kpi">
                    <div class="lab">Qui s'en charge</div>
                    <div class="val" style="font-size:19px;">
                        {{ $ligne['responsable'] ?? 'À confier' }}
                    </div>
                    <div class="sub">
                        @if ($ligne['derniere_relance'])
                            dernière relance le {{ $ligne['derniere_relance']->format('d/m/Y') }}
                        @else
                            aucune relance à ce jour
                        @endif
                    </div>
                </div>
                <div class="rec-kpi">
                    <div class="lab">Encaissé sur la période</div>
                    <div class="val">{{ Recouvrement::fr($ligne['encaisse']) }}</div>
                    <div class="sub">
                        @if ($ligne['dernier_encaissement'])
                            dernier le {{ $ligne['dernier_encaissement']->format('d/m/Y') }}
                        @else
                            aucun règlement sur la période
                        @endif
                    </div>
                </div>
                <div class="rec-kpi {{ ($ligne['silence'] ?? 0) > PortefeuilleDeRecouvrement::SILENCE_ALERTE ? 'rouge' : '' }}">
                    <div class="lab">Silence</div>
                    <div class="val">{{ $ligne['silence'] !== null ? $ligne['silence'].' j' : '—' }}</div>
                    <div class="sub">depuis le dernier geste connu</div>
                </div>
            </div>

            <div class="rec-actions">
                <a href="{{ route('recouvrement.saisie', ['relTiers' => $d['tiers']]) }}"
                   class="rec-btn r" style="text-decoration:none;">Tracer une relance</a>
                <a href="{{ route('recouvrement.extrait', ['tiers' => $d['tiers']]) }}"
                   class="rec-btn o" style="text-decoration:none;">Extrait de compte</a>
                <a href="{{ route('recouvrement.telecharger', ['document' => 'extrait', 'tiers' => $d['tiers'], 'format' => 'pdf']) }}"
                   class="rec-btn o" style="text-decoration:none;">Extrait en PDF</a>
            </div>
        </div>

        {{-- ─────────────────────────── les factures ouvertes ─────────────────────────── --}}
        <div class="rec-carte" style="margin-bottom:15px;">
            <h2>Factures ouvertes <span class="chip">{{ $d['factures']->count() }}</span></h2>

            <div class="rec-tbl-wrap">
                <table class="rec-tbl">
                    <thead>
                        <tr>
                            <th>N° facture</th>
                            <th>Date</th>
                            <th class="num">Ancienneté</th>
                            <th>Activité</th>
                            <th>Client porté</th>
                            <th class="num">Montant</th>
                            <th class="num">Réglé</th>
                            <th class="num">Reste</th>
                            <th>Niveau</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['factures'] as $facture)
                            @php
                                $reste = Recouvrement::reste($facture);
                                $age = Recouvrement::anciennete($facture, $this->arrete);
                                $niveau = Recouvrement::niveau($facture, $this->arrete);
                            @endphp
                            <tr>
                                <td><b>{{ $facture->n_facture }}</b></td>
                                <td>{{ $facture->date?->format('d/m/Y') ?? '—' }}</td>
                                <td class="num">{{ $age !== null ? $age.' j' : '·' }}</td>
                                <td>{{ $facture->activite ?? '·' }}</td>
                                <td>{{ $facture->client ?? '·' }}</td>
                                <td class="num">{{ number_format((int) $facture->montant, 0, ',', ' ') }}</td>
                                <td class="num">{{ number_format((int) ($facture->encaissements_sum_montant ?? 0), 0, ',', ' ') }}</td>
                                <td class="num"><b>{{ number_format($reste, 0, ',', ' ') }}</b></td>
                                <td><span class="pill {{ $niveau['classe'] }}">{{ $niveau['libelle'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="tot">
                            <td colspan="7">TOTAL — {{ $d['factures']->count() }} facture(s)</td>
                            <td class="num">{{ number_format($ligne['reste'], 0, ',', ' ') }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- ─────────────────────────── ce qui a été fait ─────────────────────────── --}}
    <div class="rec-g2">
        <div class="rec-carte">
            <h2>Relances <span class="chip">{{ $d['relances']->count() }}</span></h2>

            @if ($d['relances']->isEmpty())
                <div class="rec-hint warn">
                    <strong>Aucune relance n'a jamais été tracée sur ce tiers.</strong>
                    Sans historique de relance, une procédure d'injonction de payer n'a pas de
                    point de départ&nbsp;: c'est cet historique qu'un tribunal regarde en premier.
                </div>
            @else
                <div class="rec-tbl-wrap">
                    <table class="rec-tbl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Niveau</th>
                                <th>Canal</th>
                                <th>Par</th>
                                <th>Interlocuteur</th>
                                <th>Résultat</th>
                                <th class="num">Promis</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['relances'] as $relance)
                                <tr>
                                    <td>{{ $relance->date?->format('d/m/Y') }}</td>
                                    <td><span class="pill pN{{ $relance->niveau }}">N{{ $relance->niveau }}</span></td>
                                    <td>{{ $relance->canal }}</td>
                                    <td>{{ $relance->responsable }}</td>
                                    <td>{{ $relance->interlocuteur ?: '·' }}</td>
                                    <td>{{ $relance->resultat ?: '·' }}</td>
                                    <td class="num">
                                        {{ $relance->montant_promis ? number_format((int) $relance->montant_promis, 0, ',', ' ') : '·' }}
                                    </td>
                                    <td>{{ $relance->statut }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="rec-carte">
            <h2>Règlements reçus <span class="chip">{{ $d['encaissements']->count() }}</span></h2>

            @if ($d['encaissements']->isEmpty())
                <div class="rec-hint">Aucun règlement rattaché aux factures de ce tiers.</div>
            @else
                <div class="rec-tbl-wrap">
                    <table class="rec-tbl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>N° pièce</th>
                                <th>Facture</th>
                                <th>Moyen</th>
                                <th>Saisi par</th>
                                <th class="num">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['encaissements'] as $encaissement)
                                <tr>
                                    <td>{{ $encaissement->date?->format('d/m/Y') }}</td>
                                    <td>{{ $encaissement->numero }}</td>
                                    <td>{{ $encaissement->facture?->n_facture ?? '·' }}</td>
                                    <td>{{ $encaissement->moyen }}</td>
                                    {{-- « Import » et non un nom : un règlement repris d'un fichier
                                         n'a pas d'auteur ici, et lui en prêter un serait attribuer
                                         à quelqu'un un travail qu'il n'a pas fait. --}}
                                    <td>{{ $encaissement->code_auteur ?: ($encaissement->lot_import_id ? 'Import' : '·') }}</td>
                                    <td class="num">{{ number_format((int) $encaissement->montant, 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="tot">
                                <td colspan="5">TOTAL</td>
                                <td class="num">{{ number_format((int) $d['encaissements']->sum('montant'), 0, ',', ' ') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-recouvrement::pleine-page>
