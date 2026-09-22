@props(['prospection'])

@php
    use Modules\Noyau\Tracabilite\Services\SignatureDeDecision;

    $p = $prospection;
    $tranchee = in_array($p->statut_validation, ['Validée', 'Refusée'], true);
    $etiquette = 'width:190px; text-align:left; font-weight:600; color:#4B4E55;';
@endphp

{{-- Qui a tranché cette prospection, quand, d'où et depuis quel poste.

     **Ce qui manquait.** Le tableau affichait « Validée » et s'arrêtait là. Or valider une
     prospection qui annonce un devis, c'est engager l'atelier à l'établir : c'est un acte,
     et un acte se signe. Le commercial voyait sa ligne passer au vert sans savoir à qui
     s'adresser quand le devis tardait, et personne ne pouvait vérifier après coup qui avait
     engagé quoi.

     **Ce bloc est posé une fois et servi aux deux écrans** — celui du responsable et celui
     du commercial. Recopié deux fois, il aurait fini par ne plus dire la même chose aux
     deux, ce qui est exactement le contraire du but d'une traçabilité.

     **Rien n'y est reconstitué.** Une ligne tranchée avant la mise en place de la trace le
     dit en toutes lettres plutôt que d'afficher un nom déduit d'autre chose. --}}

<div {{ $attributes->merge(['class' => 'carte']) }}
     style="border-left:4px solid {{ $p->statut_validation === 'Refusée' ? '#C8102E' : '#1E7B34' }};">

    <h2 style="font-size:16px; font-weight:800; margin:0 0 10px;">Traçabilité de la décision</h2>

    @if (! $tranchee)
        <p style="color:#6B6E76; font-size:14px; margin:0;">
            Cette prospection n'a pas encore été tranchée&nbsp;: elle est
            <strong>{{ mb_strtolower($p->statut_validation) }}</strong>. Il n'y a donc rien à signer.
        </p>
    @elseif ($p->valide_le === null)
        <p style="color:#6B6E76; font-size:14px; margin:0; line-height:1.6;">
            Cette prospection a été <strong>{{ mb_strtolower($p->statut_validation) }}</strong>
            avant la mise en place de la traçabilité. Rien n'a été relevé à ce moment-là, et
            l'application ne reconstituera pas une signature qu'elle n'a pas prise&nbsp;: les
            décisions suivantes, elles, seront toutes signées.
        </p>
    @else
        <table class="tableau" style="width:100%;">
            <tbody>
                <tr>
                    <th style="{{ $etiquette }}">Décision</th>
                    <td>
                        <strong>{{ $p->statut_validation }}</strong>
                        @if ($p->motif_refus)
                            — <span style="color:#C8102E;">{{ $p->motif_refus }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th style="{{ $etiquette }}">Par qui</th>
                    <td>
                        <strong>{{ $p->validateur ?? '—' }}</strong>
                        @if ($p->valide_par === null)
                            <span style="color:#6B6E76;">(accès fermé depuis)</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th style="{{ $etiquette }}">Quand</th>
                    <td>
                        {{ $p->valide_le->format('d/m/Y à H\hi') }}
                        <span style="color:#6B6E76;">· {{ $p->valide_le->diffForHumans() }}</span>
                    </td>
                </tr>
                <tr>
                    <th style="{{ $etiquette }}">Où</th>
                    <td>
                        {{ $p->site?->nom ?? '—' }}
                        @if ($p->site?->ville)
                            <span style="color:#6B6E76;">· {{ $p->site->ville->nom }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th style="{{ $etiquette }}">Adresse IP</th>
                    <td><code>{{ $p->validation_ip ?? '—' }}</code></td>
                </tr>
                <tr>
                    <th style="{{ $etiquette }}">Poste</th>
                    <td>
                        {{ SignatureDeDecision::posteLisible($p->validation_poste) }}
                        @if ($p->validation_poste)
                            <div style="font-size:11px; color:#6B6E76; white-space:normal; margin-top:3px;">
                                {{ $p->validation_poste }}
                            </div>
                        @endif
                    </td>
                </tr>
                @if ($p->validation_ecran)
                    <tr>
                        <th style="{{ $etiquette }}">Écran d'origine</th>
                        <td style="white-space:normal; word-break:break-all; font-size:12.5px;">
                            {{ $p->validation_ecran }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Le paragraphe qui expliquait pourquoi le nom est recopié et ce qu'est le
             « poste » a été retiré le 24/09 à la demande du propriétaire : le tableau
             ci-dessus se lit seul, et une explication posée sous chaque fiche se lit une
             fois puis encombre. Le pourquoi reste écrit dans SignatureDeDecision. --}}
    @endif
</div>
