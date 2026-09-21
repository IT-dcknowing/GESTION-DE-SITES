<?php

use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Extrait de compte — le document que le client reçoit
|--------------------------------------------------------------------------
| Les six autres écrans servent à l'entreprise ; celui-ci sort de la maison. C'est
| ce qui explique sa mise en page : en-tête de société, mention légale, totaux qui
| se recoupent, et une phrase qui borne ce que le document affirme.
|
| Cette phrase compte autant que les chiffres. Un extrait qui ne dit pas à quelle
| date il a été établi laisse penser qu'un règlement fait la veille a été ignoré —
| et la discussion repart sur la bonne foi plutôt que sur la créance.
|
| L'impression est laissée au navigateur : la feuille de style masque la barre
| latérale et les boutons, il ne reste que le document.
*/

/*
 * La période remplace la date libre. Trois appels séparés à `state()` et non un seul :
 * `->url(except: ...)` attend une chaîne, pas un tableau — un seul appel pour trois
 * propriétés ne compile pas.
 */
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

/*
 * Le tiers vit dans l'adresse, et c'était le correctif.
 *
 * Il arrivait déjà par un lien depuis la balance âgée — cela marchait. Mais **changer de
 * tiers une fois sur la page ne faisait rien** : la liste déroulante attendait un
 * aller-retour interactif qui n'arrivait jamais. Or un extrait de compte est exactement le
 * genre de vue qu'on transmet : « regarde le compte de GNA » doit être un lien.
 */
state(['tiers' => ''])->url(except: '');

mount(function () {
    // Un tiers inconnu est écarté plutôt que subi : afficher l'en-tête de l'entreprise
    // au-dessus d'un tableau vide laisse croire que le compte est soldé.
    if ($this->tiers !== '' && ! array_key_exists($this->tiers, Recouvrement::tiers())) {
        $this->tiers = '';
    }
});

/** La période regardée, et l'arrêté qu'elle commande — voir PeriodeDeTravail. */
$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

$tousLesTiers = computed(fn () => Recouvrement::tiers());

/**
 * Toutes les factures du tiers, soldées comprises : un extrait retrace un compte entier.
 *
 * Au sens du tiers payant — un courtier se consulte donc comme n'importe quel autre
 * tiers, et son extrait porte les factures qu'il règle pour le compte de ses compagnies.
 */
$factures = computed(fn () => Recouvrement::facturesDuTiers(Recouvrement::factures($this->arrete), $this->tiers));

/**
 * Vrai si ce tiers règle pour le compte d'un autre — l'extrait dit alors pour qui.
 *
 * Deux cas le font : le courtier, qui porte les factures des compagnies qu'il représente ;
 * et le dépositaire, chez qui des factures établies au nom de leurs clients ont été
 * déposées. Dans les deux cas, un relevé qui n'aligne que des numéros ne se vérifie pas :
 * celui qui le reçoit doit retrouver de quel dossier chaque ligne vient.
 */
$estCourtier = computed(fn () => $this->factures->contains(
    fn (Facture $f) => trim((string) $f->courtier) !== '' || trim((string) $f->depose_chez) !== ''
));

$ouvertes = computed(fn () => $this->factures->filter(fn (Facture $f) => Recouvrement::reste($f) >= Recouvrement::SEUIL_SOLDE));

$totaux = computed(fn () => [
    'ttc' => $this->factures->sum('montant'),
    'regle' => $this->factures->sum(fn (Facture $f) => (int) $f->montant - Recouvrement::reste($f)),
    'reste' => $this->factures->sum(fn (Facture $f) => Recouvrement::reste($f)),
]);

?>

<x-recouvrement::coquille page="extrait">
    <x-slot:actions>
        <div class="no-print">
            <x-recouvrement::periode route="recouvrement.extrait" :periode="$this->periode"
                :parametres="['tiers' => $tiers]" />
        </div>

        @if ($this->tiers !== '' && $this->factures->isNotEmpty())
            <div class="no-print">
                <x-telecharger route="recouvrement.telecharger"
                    :parametres="['document' => 'extrait', 'tiers' => $this->tiers, 'arrete' => $this->periode->arreteIso()]" />
            </div>
        @endif
    </x-slot:actions>

    <div class="rec-carte">
        <h2>
            Extrait de compte client
            <span class="chip no-print">L'impression donne le document remis au client</span>
        </h2>

        {{-- Un formulaire GET, et un bouton « Voir ». Changer de tiers dans la liste ne
             faisait rien : le changement attendait un aller-retour interactif qui n'arrivait
             jamais. Une adresse, elle, part toujours — et se transmet. --}}
        <form method="GET" action="{{ route('recouvrement.extrait') }}"
              class="rec-frm no-print" style="grid-template-columns:2fr auto 1fr; margin-bottom:15px; align-items:end;">
            @foreach ($this->periode->parametres() as $nom => $valeur)
                <input type="hidden" name="{{ $nom }}" value="{{ $valeur }}">
            @endforeach

            <div class="rec-fld">
                <x-select-cherchable id="ex-tiers" label="Tiers / assurance"
                    name="tiers" :valeur="$tiers"
                    :options="$this->tousLesTiers"
                    vide="— Sélectionner —" placeholder="Taper le nom du tiers…" />
            </div>

            <div class="rec-fld">
                <label>&nbsp;</label>
                <button type="submit" class="rec-btn n" style="white-space:nowrap;">Voir l'extrait</button>
            </div>

            <div class="rec-fld">
                <label>&nbsp;</label>
                <div class="rec-hint" style="margin:0;">
                    {{ $this->tiers === '' ? '—' : $this->ouvertes->count().' facture(s) ouverte(s)' }}
                </div>
            </div>
        </form>

        @if ($this->tiers === '')
            <div class="rec-hint">
                Sélectionner un tiers : son extrait complet — factures, règlements, restes à payer,
                anciennetés — se compose instantanément.
            </div>
        @elseif ($this->factures->isEmpty())
            {{-- Un tiers sans aucune facture existe : il a été déclaré au référentiel, ou
                 il n'est payeur d'aucun dossier. Le dire vaut mieux que rendre un cadre
                 vide, qui se lit comme une panne — c'est ce qui s'est produit. --}}
            <div class="rec-hint warn">
                <strong>Aucune facture pour « {{ $this->tiers }} » à cette date.</strong>
                <div style="margin-top:5px; font-weight:400;">
                    Trois raisons possibles, dans l'ordre de fréquence&nbsp;: ce tiers a été déclaré au
                    référentiel sans avoir encore été facturé&nbsp;; ses dossiers sont réglés par un
                    courtier, et c'est alors sous le nom du courtier que l'extrait se trouve&nbsp;; ou
                    ses factures sont postérieures au {{ $this->arrete->format('d/m/Y') }} — reculez
                    l'arrêté pour les voir.
                </div>
            </div>
        @else
            @php $entreprise = auth()->user()->entreprise; @endphp

            <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap;
                        border-bottom:3px solid #191B20; padding-bottom:10px; margin-bottom:12px;">
                <div>
                    <div style="font-weight:800; font-size:17px;">{{ mb_strtoupper($entreprise?->nom ?? '') }}</div>
                    <div style="font-size:12px; color:#5A6472;">
                        @if ($entreprise?->ncc) NCC : {{ $entreprise->ncc }} @endif
                        @if ($entreprise?->adresse) · {{ $entreprise->adresse }} @endif
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:800; color:#C8102E;">EXTRAIT DE COMPTE</div>
                    <div style="font-size:12px;">
                        <b>{{ $this->tiers }}</b> · arrêté au {{ $this->arrete->format('d/m/Y') }}
                    </div>
                </div>
            </div>

            <div class="rec-tbl-wrap" style="max-height:560px;">
                <table class="rec-tbl">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° facture</th>
                            @if ($this->estCourtier)
                                <th>Pour le compte de</th>
                            @endif
                            <th>Véhicule</th>
                            <th>Immat.</th>
                            <th class="num">Montant TTC</th>
                            <th class="num">Réglé</th>
                            <th class="num">Reste à payer</th>
                            <th>Ancienneté</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures as $facture)
                            @php
                                $reste = Recouvrement::reste($facture);
                                $niveau = Recouvrement::niveau($facture, $this->arrete);
                                $age = Recouvrement::anciennete($facture, $this->arrete);

                                /*
                                 * Pour le compte de qui la facture est portée. Déposée chez ce
                                 * tiers : c'est le client facturé qui est derrière ; portée par
                                 * un courtier : c'est la compagnie. Calculé ici et non en tête
                                 * du composant — une fonction posée là-haut deviendrait une
                                 * action appelable depuis le navigateur.
                                 */
                                $pourLeCompte = trim((string) $facture->depose_chez) !== ''
                                    ? ($facture->client ?: Recouvrement::assuranceDe($facture))
                                    : Recouvrement::assuranceDe($facture);
                            @endphp
                            <tr>
                                <td>{{ $facture->date?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $facture->n_facture }}</td>
                                @if ($this->estCourtier)
                                    <td>{{ $pourLeCompte }}</td>
                                @endif
                                <td>{{ $facture->vehicule }}</td>
                                <td>{{ $facture->immatriculation }}</td>
                                <td class="num">{{ number_format((int) $facture->montant, 0, ',', ' ') }}</td>
                                <td class="num">{{ number_format((int) $facture->montant - $reste, 0, ',', ' ') }}</td>
                                <td class="num"><b>{{ number_format($reste, 0, ',', ' ') }}</b></td>
                                <td>
                                    <span class="pill {{ $niveau['classe'] }}">{{ $niveau['libelle'] }}</span>
                                    @if ($age !== null && $reste >= Recouvrement::SEUIL_SOLDE)
                                        <span style="color:#5A6472; font-size:11px;"> · {{ $age }} j</span>
                                    @endif
                                    @if ($facture->date_reception)
                                        <div style="color:#5A6472; font-size:10.5px;">depuis le dépôt du {{ $facture->date_reception->format('d/m/Y') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $this->estCourtier ? 9 : 8 }}" style="text-align:center; color:#5A6472; padding:26px;">
                                    Aucune facture pour ce tiers à cette date.
                                </td>
                            </tr>
                        @endforelse

                        @if ($this->factures->isNotEmpty())
                            <tr class="tot">
                                <td colspan="{{ $this->estCourtier ? 5 : 4 }}">TOTAUX</td>
                                <td class="num">{{ number_format($this->totaux['ttc'], 0, ',', ' ') }}</td>
                                <td class="num">{{ number_format($this->totaux['regle'], 0, ',', ' ') }}</td>
                                <td class="num">{{ number_format($this->totaux['reste'], 0, ',', ' ') }}</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="rec-hint" style="margin-top:10px;">
                Extrait établi sur les écritures enregistrées à la date d'arrêté ci-dessus. Tout règlement
                postérieur à cette date n'y figure pas — le signaler évite de faire porter à la bonne foi
                du client ce qui n'est qu'une question de date.
            </div>
        @endif
    </div>
</x-recouvrement::coquille>
