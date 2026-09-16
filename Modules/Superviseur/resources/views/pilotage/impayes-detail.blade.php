<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
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

    return EtatDesImpayes::dansLePerimetre(
        Facture::query()->whereNotNull('exercice_impayes')->withSum('encaissements', 'montant'),
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
                <a href="{{ route('impayes', ['exercice' => $f->exercice_impayes]) }}" wire:navigate
                   style="color:#6B6E76; text-decoration:none; font-size:12.5px; font-weight:600;">
                    ‹ État des impayés {{ $f->exercice_impayes }}
                </a>
                <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:800;
                           margin:3px 0 0; letter-spacing:.5px;">
                    {{ $f->numero }} — facture n° {{ $f->n_facture }}
                </h1>
                <div style="color:#6B6E76; font-size:13px; margin-top:2px;">
                    {{ $f->tiersPayant() }} · {{ EtatDesImpayes::origine($f) }}
                </div>
            </div>

            <a href="{{ route('impayes', ['exercice' => $f->exercice_impayes, 'modifier' => $f->id]) }}" wire:navigate
               class="bouton" style="padding:9px 16px; text-decoration:none;">Modifier</a>
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
                    <tr><td style="{{ $intitule }}">Tiers payant (celui qu'on relance)</td><td style="{{ $cellule }}">{{ $f->tiersPayant() }}</td></tr>
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

        {{-- ---------------------------------------------------------------- l'historique --}}
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Qui l'a touchée, et ce qui a changé</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Quand</th><th>Qui</th><th>Geste</th><th>Avant → après</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->historique as $trace)
                            @php
                                $avant = $trace->properties['old'] ?? [];
                                $apres = $trace->properties['attributes'] ?? [];
                            @endphp
                            <tr>
                                <td style="white-space:nowrap;">{{ $trace->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $trace->causer?->name ?? 'import' }}</td>
                                <td>{{ $trace->description }}</td>
                                <td style="font-size:12.5px;">
                                    @forelse ($avant as $champ => $valeur)
                                        @if (($apres[$champ] ?? null) !== $valeur)
                                            <div>
                                                <strong>{{ $champ }}</strong> :
                                                {{ is_scalar($valeur) ? \Illuminate\Support\Str::limit((string) $valeur, 60) : '—' }}
                                                → {{ is_scalar($apres[$champ] ?? null) ? \Illuminate\Support\Str::limit((string) $apres[$champ], 60) : '—' }}
                                            </div>
                                        @endif
                                    @empty
                                        —
                                    @endforelse
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
