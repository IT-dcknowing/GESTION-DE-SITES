<?php

use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;

use function Livewire\Volt\{computed, state};

state([
    'recherche' => '',
    'etatFiltre' => '',
    'villeFiltre' => '',
    'pageCourante' => 1,
]);

$parPage = computed(fn () => 20);

$villes = computed(fn () => Ville::where('est_actif', true)->orderBy('nom')->pluck('nom', 'id')->all());

/**
 * Tous les dépôts, du plus récent au plus ancien.
 *
 * Le filtrage se fait en base et non sur une collection chargée : le journal grossit d'un
 * lot par import et par ville, il n'y a aucune raison de tout ramener pour n'en montrer
 * vingt.
 */
$lots = computed(function () {
    $requete = LotImport::with('ville')->latest();

    if (trim($this->recherche) !== '') {
        $motif = '%'.trim($this->recherche).'%';
        $requete->where(fn ($q) => $q->where('nom_fichier', 'like', $motif)
            ->orWhere('deposant', 'like', $motif));
    }

    if ($this->etatFiltre !== '' && array_key_exists($this->etatFiltre, LotImport::ETATS)) {
        $requete->where('etat', $this->etatFiltre);
    }

    if ($this->villeFiltre !== '' && array_key_exists((int) $this->villeFiltre, $this->villes)) {
        $requete->where('ville_id', (int) $this->villeFiltre);
    }

    return $requete->get();
});

$total = computed(fn () => $this->lots->count());

/** La page demandée, ramenée dans les bornes : une page forgée à la main ne mène nulle part. */
$page = computed(function () {
    $dernier = max(1, (int) ceil($this->total / $this->parPage));

    return min(max(1, (int) $this->pageCourante), $dernier);
});

$lignes = computed(fn () => $this->lots->slice(($this->page - 1) * $this->parPage, $this->parPage)->values());

$enCours = computed(fn () => $this->lots->whereIn('etat', ['depose', 'en_cours'])->count());

$pastille = fn (string $etat) => match ($etat) {
    'termine' => 'pTermine',
    'controle' => 'pControle',
    'en_cours' => 'pEnCours',
    'echec' => 'pEchec',
    'annule' => 'pAnnule',
    default => 'pDepose',
};

?>

{{-- Le journal se rafraîchit tant qu'un traitement tourne, et cesse dès qu'il n'y en a
     plus : interroger le serveur toutes les cinq secondes pour un écran figé ne sert à
     personne, et un écran figé pendant qu'un import travaille non plus. --}}
<x-import::coquille page="lots">
    <div @if ($this->enCours > 0) wire:poll.5s @endif>

        <div class="imp-carte">
            <h2>
                Filtrer
                @if ($this->enCours > 0)
                    <span class="chip">{{ $this->enCours }} traitement(s) en cours</span>
                @endif
            </h2>

            <div class="imp-frm">
                <div class="imp-fld">
                    <label for="q">Nom du fichier ou déposant</label>
                    <input type="search" id="q" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}" placeholder="parc, CATTC, K. Désirée…">
                </div>
                <div class="imp-fld">
                    <label for="e">État</label>
                    <select id="e" wire:model.live="etatFiltre">
                        <option value="" @selected($etatFiltre === '')>Tous</option>
                        @foreach (LotImport::ETATS as $cle => $libelle)
                            <option value="{{ $cle }}" @selected((string) $etatFiltre === (string) $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="imp-fld">
                    <label for="v">Ville</label>
                    <select id="v" wire:model.live="villeFiltre">
                        <option value="" @selected($villeFiltre === '')>Toutes</option>
                        @foreach ($this->villes as $id => $nom)
                            <option value="{{ $id }}" @selected((string) $villeFiltre === (string) $id)>{{ $nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="imp-carte">
            <h2>Les dépôts <span class="chip">{{ $this->total }} au total</span></h2>

            @if ($this->total === 0)
                <div class="imp-hint">
                    Aucun dépôt ne correspond. Si c'est le tout premier import,
                    <a href="{{ route('import.depot') }}" wire:navigate style="font-weight:700; color:#C8102E;">
                        commencez par la situation du parc</a> — c'est la fiche de réception que tous les
                    autres fichiers citent.
                </div>
            @else
                <div class="imp-tbl-wrap">
                    <table class="imp-tbl">
                        <thead>
                            <tr>
                                <th>Déposé le</th>
                                <th>Fichier</th>
                                <th>Type</th>
                                <th>Ville</th>
                                <th>Par</th>
                                <th class="num">Lues</th>
                                <th class="num">Créées</th>
                                <th class="num">À jour</th>
                                <th class="num">Rejets</th>
                                <th>État</th>
                                <th>Pièce</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->lignes as $ligne)
                                <tr wire:key="lot-{{ $ligne->id }}">
                                    <td style="white-space:nowrap;">{{ $ligne->created_at?->format('d/m/Y H\hi') }}</td>
                                    <td style="max-width:260px;">
                                        <div style="word-break:break-word;">{{ $ligne->nom_fichier }}</div>
                                        @if ($ligne->periode)
                                            <div style="color:#6B6E76; font-size:11.5px;">{{ $ligne->periode }}</div>
                                        @endif
                                    </td>
                                    <td>{{ Registre::libelle($ligne->format) }}</td>
                                    <td>
                                        {{ $ligne->ville?->nom
                                            ?? '' }}
                                        @unless ($ligne->ville)
                                            <span style="color:#6B6E76;">toutes</span>
                                        @endunless
                                    </td>
                                    <td>{{ $ligne->deposant }}</td>
                                    <td class="num">{{ number_format((int) $ligne->lignes_lues, 0, ',', ' ') }}</td>
                                    <td class="num">{{ number_format((int) $ligne->lignes_creees, 0, ',', ' ') }}</td>
                                    <td class="num">{{ number_format((int) $ligne->lignes_majs, 0, ',', ' ') }}</td>
                                    <td class="num" @if ($ligne->lignes_rejetees > 0) style="color:#C8102E;" @endif>
                                        {{ number_format((int) $ligne->lignes_rejetees, 0, ',', ' ') }}
                                    </td>
                                    <td><span class="pastille {{ $this->pastille($ligne->etat) }}">{{ $ligne->etatLisible() }}</span></td>
                                    <td style="white-space:nowrap;">
                                        <a href="{{ route('import.lot', $ligne->id) }}" wire:navigate
                                           class="imp-btn p" style="text-decoration:none;">Détail</a>

                                        {{-- Le classeur tel qu'il a été reçu. Il n'a aucune
                                             adresse devinable : il est servi ici, après
                                             vérification, et sous le nom du déposant. --}}
                                        @if ($ligne->fichierPresent())
                                            <a href="{{ route('import.lot.fichier', $ligne->id) }}"
                                               class="imp-btn p" style="text-decoration:none; margin-left:5px;"
                                               title="Télécharger le fichier déposé">↓ Fichier</a>
                                        @else
                                            <span style="color:#B7B9BE; font-size:11.5px; margin-left:5px;"
                                                  title="Le fichier n'est plus conservé">— retiré —</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-pagination :page="$this->page" :total="$this->total" prop="pageCourante" :par-page="$this->parPage" />
            @endif
        </div>
    </div>
</x-import::coquille>
