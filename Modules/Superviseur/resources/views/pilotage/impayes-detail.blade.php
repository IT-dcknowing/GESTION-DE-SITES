<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Tracabilite\Services\QuiAAgi;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\{computed, mount, state};

/**
 * Une créance de l'état des impayés, en entier, sur sa propre page.
 *
 * **Pourquoi une page et non un volet sous la ligne.** Le détail se dépliait dans le tableau :
 * il repoussait les autres lignes, obligeait à faire défiler un tableau de vingt-deux colonnes
 * pour le lire, et disparaissait au premier changement de page. Une adresse propre se rouvre
 * dans un autre onglet, se met en favori et se transmet — c'est le geste naturel devant une
 * créance qu'on discute avec quelqu'un.
 *
 * **Le périmètre est vérifié ici, pas au tableau.** L'identifiant vient de l'adresse, donc de
 * n'importe qui : une créance hors du périmètre du compte répond comme une créance qui
 * n'existe pas, sans dire qu'elle existe ailleurs.
 */
state(['id' => null]);

mount(function (int $creance) {
    $this->id = $creance;
});

$creance = computed(function () {
    $sites = PerimetreSites::idsRetenus(auth()->user(), '', '');

    /*
     * Toute facture s'ouvre ici, portée à l'état ou non.
     *
     * La page n'acceptait que les factures portées à l'état des impayés. Or c'est la même
     * facture des deux côtés : depuis le 24/09, le tableau du chiffre d'affaires ouvre lui
     * aussi cette page, et refuser une facture non portée aurait fait répondre « introuvable »
     * à une facture qui est sous les yeux. La page dit à la place qu'elle n'est pas à l'état.
     */
    return EtatDesImpayes::dansLePerimetre(
        Facture::query()->withSum('encaissements', 'montant'),
        $sites,
        EtatDesImpayes::villesDesSites($sites),
    )
        ->with(['site.ville', 'ville', 'encaissements' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id')])
        ->find((int) $this->id);
});

$historique = computed(fn () => $this->creance === null ? collect() : Activity::query()
    ->with('causer')
    ->where('subject_type', $this->creance->getMorphClass())
    ->where('subject_id', $this->creance->id)
    ->latest('id')
    ->limit(50)
    ->get());

$auteurs = computed(fn () => $this->creance === null ? collect() : User::query()
    ->whereIn('id', $this->creance->encaissements->pluck('cree_par')->filter()->unique())
    ->pluck('name', 'id'));

/**
 * Qui s'est chargé de cette créance, et **à quelles dates**.
 *
 * Demandé le 25/09, avec une précision qui commande la mise en page : le nom paraît dans
 * le tableau de l'état, les dates ne paraissent **que dans le détail**. C'est la bonne
 * répartition — un tableau répond à « qui suit ce dossier ? », un détail à « que s'est-il
 * passé, et quand ? ».
 *
 * À distinguer du journal plus bas : celui-ci dit **les personnes**, celui-là dit **les
 * gestes**. On regarde rarement les deux pour la même raison.
 *
 * @see \Modules\Noyau\Tracabilite\Services\QuiAAgi
 */
$traitants = computed(fn () => $this->creance === null ? collect() : QuiAAgi::detailDe($this->creance));

?>

<div>
    @if (! $this->creance)
        <x-carte-section titre="Créance introuvable" icone="atelier" couleur="#C8102E">
            <p style="margin:0 0 14px; color:#4B4E55;">
                Cette créance n'existe pas, ou elle relève d'un lieu qui n'est pas dans votre périmètre.
            </p>
            <a href="{{ route('impayes') }}" wire:navigate
               style="display:inline-block; padding:7px 14px; border-radius:7px; background:#191B20; color:#fff;
                      text-decoration:none; font-size:13px; font-weight:700;">Retour à l'état des impayés</a>
        </x-carte-section>
    @else
        @php
            $f = $this->creance;
            $encaisse = (int) ($f->encaissements_sum_montant ?? 0);
            $reste = Recouvrement::reste($f);
            $age = Recouvrement::anciennete($f, now());
            $niveau = Recouvrement::niveau($f, now());
            $cellule = 'padding:7px 10px; border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;';
            $intitule = $cellule.' color:#6B6E76; font-size:12px; text-transform:uppercase; letter-spacing:.4px; width:42%;';
        @endphp

        {{-- ------------------------------------------------------------------ l'en-tête --}}
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:14px;
                    flex-wrap:wrap; margin:0 0 16px;">
            <div>
                {{-- Le retour mène là d'où l'on vient : l'état des impayés quand la facture y
                     est portée, le chiffre d'affaires sinon — c'est le seul tableau qui
                     ouvre cette page pour une facture non portée. --}}
                @if ($f->exercice_impayes)
                    <a href="{{ route('impayes', ['exercice' => $f->exercice_impayes]) }}" wire:navigate
                       class="bouton bouton-secondaire" style="text-decoration:none; padding:5px 12px; font-size:12.5px;">
                        ← Retour à l'état des impayés {{ $f->exercice_impayes }}
                    </a>
                @else
                    <a href="{{ route('chiffre-affaires') }}" wire:navigate
                       class="bouton bouton-secondaire" style="text-decoration:none; padding:5px 12px; font-size:12.5px;">
                        ← Retour au chiffre d'affaires
                    </a>
                @endif
                <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:800;
                           margin:3px 0 0; letter-spacing:.5px;">
                    {{ $f->numero }} — facture n° {{ $f->n_facture }}
                </h1>
                <div style="color:#6B6E76; font-size:13px; margin-top:2px;">
                    {{ $f->tiersPayant() }} · {{ EtatDesImpayes::origine($f) }}
                </div>
            </div>

            @if ($f->exercice_impayes)
                <a href="{{ route('impayes', ['exercice' => $f->exercice_impayes, 'modifier' => $f->id]) }}" wire:navigate
                   class="bouton" style="padding:9px 16px; text-decoration:none;">Modifier</a>
            @else
                {{-- Une facture qui n'est pas à l'état ne se corrige pas d'ici : elle s'y
                     porte d'abord, et c'est un geste qui appartient à l'état des impayés. --}}
                <span style="font-size:12.5px; color:#B9791C; font-weight:700; align-self:center;">
                    Pas encore portée à l'état des impayés
                </span>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Montant TTC" :value="ae($f->montant)" />
            <x-kpi-card label="Déjà encaissé" :value="ae($encaisse)" :bon="true"
                :sub="$f->encaissements->count().' règlement(s)'" />
            <x-kpi-card label="Reste à payer" :value="ae($reste)" :accent="$reste >= Recouvrement::SEUIL_SOLDE" />
            <x-kpi-card label="Ancienneté"
                :value="$reste < Recouvrement::SEUIL_SOLDE ? 'Soldée' : ($age !== null ? $age.' j' : '—')"
                :sub="$reste < Recouvrement::SEUIL_SOLDE ? '' : EtatDesImpayes::trancheDuFichier($age).' · depuis '.($f->date_reception ? 'le dépôt' : 'l\'édition').' · '.$niveau['libelle']" />
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:16px; margin-bottom:16px;">
            {{-- Les colonnes du classeur, sous ses mots et dans son ordre. --}}
            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">La créance</h3>
                <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <tr><td style="{{ $intitule }}">ASSUREUR</td><td style="{{ $cellule }}">{{ $f->assureur ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Client</td><td style="{{ $cellule }}">{{ $f->client }}</td></tr>
                    <tr><td style="{{ $intitule }}">SITE</td><td style="{{ $cellule }}">{{ $f->site?->nom ?? 'atelier à préciser' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Ville</td><td style="{{ $cellule }}">{{ $f->site?->ville?->nom ?? $f->ville?->nom ?? 'à préciser' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Courtier</td><td style="{{ $cellule }}">{{ $f->courtier ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Date de réception (dépôt)</td><td style="{{ $cellule }}">{{ $f->date_reception?->format('d/m/Y') ?? 'non renseignée' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Date d'édition</td><td style="{{ $cellule }}">{{ $f->date?->format('d/m/Y') ?? '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Numéro de la facture</td><td style="{{ $cellule }}">{{ $f->n_facture ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Numéro Sinistre</td><td style="{{ $cellule }}">{{ $f->n_sinistre ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Véhicule</td><td style="{{ $cellule }}">{{ $f->vehicule ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Immatriculation</td><td style="{{ $cellule }}">{{ $f->immatriculation ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">banque</td><td style="{{ $cellule }}">{{ $f->banque ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Commentaires</td><td style="{{ $cellule }}">{{ $f->observations ?: '—' }}</td></tr>
                </table>
            </div>

            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Son suivi</h3>
                <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <tr><td style="{{ $intitule }}">Référence</td><td style="{{ $cellule }}"><x-numero-ligne :ligne="$f" /></td></tr>
                    <tr><td style="{{ $intitule }}">Origine</td><td style="{{ $cellule }}">{{ EtatDesImpayes::origine($f) }}</td></tr>
                    <tr><td style="{{ $intitule }}">Année de l'état</td><td style="{{ $cellule }}">{{ $f->exercice_impayes }}</td></tr>
                    <tr><td style="{{ $intitule }}">Report</td><td style="{{ $cellule }}">{{ EtatDesImpayes::libelleReport($f, (int) now()->year) }}</td></tr>
                    <tr><td style="{{ $intitule }}">Activité</td><td style="{{ $cellule }}">{{ $f->activite ?? '—' }}</td></tr>
                    @if ($f->depose_chez)
                        <tr><td style="{{ $intitule }}">Déposée chez</td><td style="{{ $cellule }}">{{ $f->depose_chez }}</td></tr>
                    @endif
                    {{-- Dire d'où vient le payeur évite la question suivante : pourquoi la
                         relance ne part-elle pas au nom inscrit sur la facture ? --}}
                    <tr><td style="{{ $intitule }}">Tiers payant (celui qu'on relance)</td><td style="{{ $cellule }}">{{ $f->tiersPayant() }}@if ($f->depose_chez)<span style="color:#6B6E76;"> — parce que la facture est déposée chez lui</span>@endif</td></tr>
                    <tr><td style="{{ $intitule }}">Niveau de relance</td><td style="{{ $cellule }}">{{ $niveau['libelle'] }}</td></tr>
                    @if ($f->anciennete_declaree)
                        <tr><td style="{{ $intitule }}">Tranche écrite dans le classeur</td><td style="{{ $cellule }}">{{ $f->anciennete_declaree }}</td></tr>
                    @endif
                    <tr><td style="{{ $intitule }}">Clé du classeur</td><td style="{{ $cellule }}"><code style="font-size:12px;">{{ EtatDesImpayes::cleDuFichier($f) }}</code></td></tr>
                </table>
            </div>
        </div>

        {{-- --------------------------------------------------------------- les règlements --}}
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">
                Règlements — {{ ae($encaisse) }} sur {{ ae($f->montant) }}
            </h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date de règlement</th>
                            <th style="text-align:right;">Montant</th>
                            <th>Mode de règlement</th>
                            <th>Référence</th>
                            <th>Enregistré par</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($f->encaissements as $e)
                            <tr>
                                <td>{{ $e->date?->format('d/m/Y') ?? '—' }}</td>
                                <td style="text-align:right; font-weight:700;">{{ ae($e->montant) }}</td>
                                <td>{{ $e->moyen ?? '—' }}</td>
                                <td>{{ $e->reference_origine ?? '—' }}</td>
                                <td>
                                    @if ($e->lot_import_id)
                                        <span style="color:#6B6E76;">repris du classeur</span>
                                    @else
                                        {{ $this->auteurs[$e->cree_par] ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Aucun règlement enregistré sur cette créance." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ------------------------------------------------------------- qui s'en est chargé --}}
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Qui s'en est chargé</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                Les personnes qui ont agi sur cette créance, et les dates auxquelles elles l'ont fait.
                Le tableau de l'état n'affiche que les noms ; les dates sont ici, où elles ont un sens.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Personne</th>
                            <th>Code de saisie</th>
                            <th>Fonction</th>
                            <th class="num">Gestes</th>
                            <th>Dates d'intervention</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->traitants as $personne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:600;">{{ $personne['nom'] }}</td>
                                <td style="font-family:ui-monospace,Consolas,monospace; font-size:12.5px;">
                                    {{ $personne['code'] ?? '—' }}
                                </td>
                                <td style="color:#6B6E76;">{{ $personne['fonction'] ?? '—' }}</td>
                                <td class="num">{{ $personne['gestes'] ?: '·' }}</td>
                                <td style="font-size:12.5px;">
                                    @forelse (collect($personne['dates'])->sortDesc()->take(8) as $date)
                                        <span style="display:inline-block; background:#F4F2EC; border-radius:4px;
                                                     padding:2px 7px; margin:0 4px 4px 0; white-space:nowrap;">
                                            {{ $date?->format('d/m/Y H:i') }}
                                        </span>
                                    @empty
                                        <span style="color:#9A9DA5;">—</span>
                                    @endforelse
                                    @if (count($personne['dates']) > 8)
                                        <span style="color:#6B6E76;">+ {{ count($personne['dates']) - 8 }} autre(s)</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            {{-- Une créance venue d'un import que personne n'a retouchée : c'est
                                 le cas le plus fréquent, et ce n'est pas une anomalie. --}}
                            <x-table-vide :colspan="5"
                                texte="Personne n'est encore intervenu sur cette créance — elle vient d'un dépôt et n'a pas été retouchée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- l'historique --}}
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Ce qui a changé, geste par geste</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Quand</th><th>Qui</th><th>Geste</th><th>Ce qui a changé</th></tr>
                    </thead>
                    <tbody>
                        @php $nomsDuJournal = \Modules\Noyau\Tracabilite\Services\JournalLisible::noms($this->historique); @endphp
                        @forelse ($this->historique as $trace)
                            @php
                                /* Le journal se lit sans connaître la base : « updated »
                                   devient « Modifiée », « ville_id : — → 1 » devient
                                   « Ville : Abidjan », « commercial_id : 41 » devient le
                                   nom du commercial, et les dates prennent le format
                                   d'ici. Voir JournalLisible. */
                                $creation = \Modules\Noyau\Tracabilite\Services\JournalLisible::estUneCreation($trace);
                                $changements = \Modules\Noyau\Tracabilite\Services\JournalLisible::changements($trace, $nomsDuJournal);
                            @endphp
                            <tr>
                                <td style="white-space:nowrap;">{{ $trace->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $trace->causer?->name ?? 'import' }}</td>
                                <td>{{ \Modules\Noyau\Tracabilite\Services\JournalLisible::geste($trace) }}</td>
                                <td style="font-size:12.5px;">
                                    <x-journal-changements :changements="$changements" :creation="$creation" />
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune trace enregistrée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
