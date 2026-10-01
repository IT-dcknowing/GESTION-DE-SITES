<?php

use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Courtiers — qui doit réellement l'argent
|--------------------------------------------------------------------------
| Une facture de sinistre apportée par un courtier n'est pas due par la compagnie
| d'assurance : elle est due par le courtier, qui encaisse de son mandant et reverse.
| Relancer la compagnie, c'est écrire à quelqu'un qui n'a rien à payer pendant que le
| vrai débiteur n'entend parler de rien.
|
| Cette page tire les conséquences de cette règle en deux temps :
|
| ① ce que chaque courtier porte en tout — facturé, réglé, reste à payer, et le niveau
|    de relance qu'appelle sa plus vieille facture ;
| ② pour le compte de qui il le porte — une ligne par couple courtier × compagnie, avec
|    le poids de chaque compagnie dans sa dette.
|
| Le second tableau n'est pas un supplément d'information. Une relance qui annonce un
| montant global se fait renvoyer aux mandants et le dossier repart de zéro ; une relance
| qui ventile par compagnie se traite.
|
| Rien ne s'écrit ici : l'écran lit les factures et leurs encaissements avec le même
| calcul que la balance âgée, et c'est ce qui garantit que les deux ne se contredisent
| jamais.
*/

/*
 * La période remplace la date libre. Trois appels séparés à `state()` et non un seul :
 * `->url(except: ...)` attend une chaîne, pas un tableau — un seul appel pour trois
 * propriétés ne compile pas.
 */
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

// Lié à l'adresse : « Ventiler » devient un lien, et il agit sans le moindre script.
state(['courtier' => ''])->url(except: '');

/** La période regardée, et l'arrêté qu'elle commande — voir PeriodeDeTravail. */
$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

/**
 * Toutes les créances, soldées comprises : un courtier à jour doit s'afficher à zéro, pas
 * disparaître. Lues telles quelles, sans en faire des objets — l'écran ne montre aucune
 * facture ligne à ligne, seulement leurs totaux. Voir `Recouvrement::lignesDeCreance()`.
 */
$toutes = computed(fn () => Recouvrement::lignesDeCreance($this->arrete));

$courtiers = computed(fn () => Recouvrement::courtiers());

$consolide = computed(fn () => Recouvrement::parCourtier($this->toutes, $this->arrete));

$detail = computed(fn () => Recouvrement::parCourtierEtAssurance($this->toutes));

/*
 * Le filtre est ramené à la liste connue avant de servir.
 *
 * Il ne compose aucune requête — le tri se fait sur une collection déjà chargée, donc
 * rien n'est injectable. Mais une valeur fantaisiste envoyée à la main afficherait un
 * tableau vide sous un titre qui annonce un courtier : mieux vaut ignorer le filtre que
 * laisser croire qu'un courtier existe et ne doit rien.
 */
$filtre = computed(fn () => in_array($this->courtier, $this->courtiers, true) ? $this->courtier : '');

$detailAffiche = computed(fn () => $this->filtre === ''
    ? $this->detail
    : $this->detail->where('courtier', $this->filtre)->values());

$totaux = computed(fn () => [
    'facture' => $this->consolide->sum('facture'),
    'regle' => $this->consolide->sum('regle'),
    'reste' => $this->consolide->sum('reste'),
    'ouvertes' => $this->consolide->sum('ouvertes'),
]);

/*
 * Le contrôle de recoupement : le détail par compagnie doit refaire le consolidé, au
 * franc près.
 *
 * Il n'est pas décoratif. Les deux tableaux sont calculés séparément, sur les mêmes
 * factures mais par deux chemins différents ; s'ils divergent, c'est qu'une facture est
 * comptée deux fois ou pas du tout, et les deux erreurs se voient mal à l'œil sur trente
 * lignes. Le contrôle les rend visibles avant qu'une relance ne parte sur un chiffre faux.
 */
$recoupe = computed(function () {
    $ligne = $this->filtre === '' ? null : $this->consolide->firstWhere('courtier', $this->filtre);

    $consolide = $this->filtre === ''
        ? $this->consolide->sum('reste')
        : (int) ($ligne['reste'] ?? 0);

    return ['detail' => $this->detailAffiche->sum('reste'), 'consolide' => $consolide];
});

/** Ce que les factures sans courtier représentent : le reste de l'encours, pour situer. */
$horsCourtage = computed(fn () => $this->toutes
    ->filter(fn ($ligne) => trim((string) $ligne->courtier) === '')
    ->sum(fn ($ligne) => Recouvrement::resteDe($ligne->montant, $ligne->encaissements_sum_montant)));

?>

<x-recouvrement::coquille page="courtiers">
    <x-slot:actions>
        <x-recouvrement::periode route="recouvrement.courtiers" :periode="$this->periode" />

        {{-- Le fichier emporté contient exactement ce que l'écran montre :
             mêmes filtres, même arrêté, même ligne de totaux. --}}
        <x-telecharger route="recouvrement.telecharger"
            :parametres="['document' => 'courtiers', 'arrete' => $this->periode->arreteIso()]" />
    </x-slot:actions>

    @if ($this->consolide->isEmpty())
        <div class="rec-carte">
            <h2>Courtiers</h2>
            <div class="rec-hint">
                Aucune facture ne porte de courtier à cette date. Le champ « Courtier » se renseigne
                à la création de la facture, dans l'écran de saisie : dès qu'il l'est, la créance est
                suivie, relancée et encaissée au nom du courtier plutôt qu'au nom de la compagnie
                qu'il représente.
            </div>
        </div>
    @else
        {{-- ─────────────── ① Le point consolidé ─────────────── --}}
        <div class="rec-carte">
            <h2>
                ① Point consolidé par courtier
                <span class="chip">Le courtier est le tiers payant</span>
            </h2>

            <div class="rec-hint" style="margin-top:0; margin-bottom:12px;">
                Lorsqu'un courtier est renseigné sur une facture, c'est lui qui est suivi, relancé et
                encaissé — la compagnie représentée reste inscrite à côté, pour la ventilation.
                À titre de repère, l'encours sans courtier s'élève à
                <b>{{ Recouvrement::fr($this->horsCourtage) }}</b>.
            </div>

            <div class="rec-tbl-wrap">
                <table class="rec-tbl">
                    <thead>
                        <tr>
                            <th>Courtier</th>
                            <th class="num">Facturé</th>
                            <th class="num">Réglé</th>
                            <th class="num">Reste à payer</th>
                            <th class="num">Fact. ouvertes</th>
                            <th class="num">Assurances</th>
                            <th>Niveau max</th>
                            {{-- La colonne n'avait pas d'intitulé : « Ventiler » ne se devine
                                 pas, et ce qu'il montre encore moins. --}}
                            <th class="no-print">Pour le compte de qui</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->consolide as $ligne)
                            <tr>
                                <td><b>{{ $ligne['courtier'] }}</b>@if ($this->filtre === $ligne['courtier'])
                                        {{-- La ligne d'où l'on vient de partir, pour la retrouver
                                             en remontant. --}}
                                        <span class="chip" style="margin-left:6px;">ventilé ci-dessous</span>
                                    @endif</td>
                                <td class="num">{{ number_format($ligne['facture'], 0, ',', ' ') }}</td>
                                <td class="num">{{ number_format($ligne['regle'], 0, ',', ' ') }}</td>
                                <td class="num"><b>{{ number_format($ligne['reste'], 0, ',', ' ') }}</b></td>
                                <td class="num">{{ $ligne['ouvertes'] }}</td>
                                <td class="num">{{ $ligne['assurances'] }}</td>
                                <td><span class="pill {{ $ligne['niveau']['classe'] }}">{{ $ligne['niveau']['libelle'] }}</span></td>
                                <td class="no-print" style="white-space:nowrap;">
                                    {{-- **Trois essais sur ce seul geste, et voici pourquoi.**

                                         1. Il ne répondait pas du tout : rien n'atteignait le serveur.
                                         2. Devenu un lien `wire:navigate` avec une ancre, il agissait —
                                            mais `wire:navigate` **remonte toujours en haut** après la
                                            navigation, et reprend la main sur l'ancre. On revoyait donc
                                            le haut de page, et le clic paraissait mort une seconde fois.
                                         3. Il ne navigue plus. Le filtre est un simple changement d'état :
                                            seul le tableau ② se redessine, la page ne bouge pas, et
                                            `scrollIntoView` emmène le lecteur là où son clic a agi.

                                         **Le `x-on:click` n'attend pas le serveur, et c'est voulu** : le
                                         tableau ② est déjà à l'écran — plus bas — et l'on descend pendant
                                         que la requête part. Attendre le retour ferait un trajet saccadé
                                         pour rien. --}}
                                    <button type="button"
                                        wire:click="$set('courtier', '{{ addslashes($ligne['courtier']) }}')"
                                        x-on:click="document.getElementById('ventilation')
                                            ?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                                        class="rec-btn o"
                                        style="padding:3px 9px; font-size:11px;">
                                        Ventiler
                                    </button>
                                    <a href="{{ route('recouvrement.extrait', ['tiers' => $ligne['courtier']]) }}"
                                        wire:navigate class="rec-btn o"
                                        style="padding:3px 9px; font-size:11px; text-decoration:none; display:inline-block;">
                                        Extrait
                                    </a>
                                </td>
                            </tr>
                        @endforeach

                        <tr class="tot">
                            <td>TOTAL COURTAGE</td>
                            <td class="num">{{ number_format($this->totaux['facture'], 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format($this->totaux['regle'], 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format($this->totaux['reste'], 0, ',', ' ') }}</td>
                            <td class="num">{{ $this->totaux['ouvertes'] }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ─────────────── ② Le détail par compagnie ─────────────── --}}
        {{-- `scroll-margin-top` : sans elle, l'ancre colle le titre au bord haut de la
             fenêtre, sous le bandeau fixe, et l'on arrive sur un tableau dont on ne voit plus
             l'intitulé — donc sans savoir ce qu'on regarde. --}}
        <div class="rec-carte" id="ventilation" style="margin-top:16px; scroll-margin-top:90px;">
            <h2>
                ② État par assurance représentée
                @if ($this->filtre !== '')
                    <span class="chip">Ventilation de {{ $this->filtre }}</span>
                @endif
                <span class="chip">{{ $this->detailAffiche->count() }} couple(s)</span>
            </h2>

            @if ($this->filtre !== '')
                {{-- Dire quel filtre est posé, et offrir de l'ôter au même endroit. Un tableau
                     réduit sans que rien ne l'annonce se lit comme un tableau incomplet. --}}
                <div class="rec-hint" style="margin-top:0; margin-bottom:12px;">
                    Ce tableau ne montre que <b>{{ $this->filtre }}</b> : pour le compte de quelles
                    compagnies il porte son encours, et ce que chacune pèse dans son reste à payer.
                    <button type="button" wire:click="$set('courtier', '')"
                        style="border:0; background:none; padding:0; cursor:pointer; font:inherit;
                               font-weight:700; color:inherit; text-decoration:underline;">Voir tous les courtiers</button>.
                </div>
            @endif

            <div class="rec-frm no-print" style="grid-template-columns:2fr 1fr; margin-bottom:13px;">
                <div class="rec-fld">
                    <label>Filtrer sur un courtier</label>
                    <select wire:model.live="courtier">
                        <option value="" @selected($courtier === '')>— Tous les courtiers —</option>
                        @foreach ($this->courtiers as $nom)
                            <option value="{{ $nom }}" @selected((string) $courtier === (string) $nom)>{{ $nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rec-fld">
                    <label>&nbsp;</label>
                    @if ($this->recoupe['detail'] === $this->recoupe['consolide'])
                        <div class="rec-hint ok" style="margin:0;">
                            ✓ Détail = consolidé : {{ Recouvrement::fr($this->recoupe['detail']) }}
                        </div>
                    @else
                        <div class="rec-hint warn" style="margin:0;">
                            ⚠ Écart de {{ Recouvrement::fr(abs($this->recoupe['detail'] - $this->recoupe['consolide'])) }}
                            entre le détail et le consolidé.
                        </div>
                    @endif
                </div>
            </div>

            <div class="rec-tbl-wrap">
                <table class="rec-tbl">
                    <thead>
                        <tr>
                            <th>Courtier</th>
                            <th>Assurance représentée</th>
                            <th class="num">Facturé</th>
                            <th class="num">Reste à payer</th>
                            <th class="num">Fact. ouvertes</th>
                            <th class="num">% du reste du courtier</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->detailAffiche as $ligne)
                            <tr>
                                <td><b>{{ $ligne['courtier'] }}</b></td>
                                <td>{{ $ligne['assurance'] }}</td>
                                <td class="num">{{ number_format($ligne['facture'], 0, ',', ' ') }}</td>
                                <td class="num"><b>{{ number_format($ligne['reste'], 0, ',', ' ') }}</b></td>
                                <td class="num">{{ $ligne['ouvertes'] }}</td>
                                <td class="num">{{ round($ligne['part'] * 100) }} %</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rec-hint" style="margin-top:11px;">
                Une compagnie peut apparaître derrière plusieurs courtiers, et aussi en direct dans la
                balance âgée : ce sont des créances distinctes, portées par des débiteurs distincts.
                La mention « {{ Recouvrement::SANS_ASSURANCE }} » désigne les dossiers qu'un courtier a
                apportés sans compagnie derrière lui.
            </div>
        </div>
    @endif
</x-recouvrement::coquille>
