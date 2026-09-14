<?php

use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;
use Modules\Noyau\Exploitation\Services\AnnuaireDesClients;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Clients de l'entreprise
|--------------------------------------------------------------------------
| Qui vient chez nous, où, combien de fois, et pour quel montant.
|
| **Ce n'est pas l'écran « Clients & tiers » du recouvrement**, et la différence
| mérite d'être dite : celui-là regarde les *payeurs* et ce qu'ils doivent — une
| compagnie dont tous les dossiers passent par un courtier y figure à zéro. Celui-ci
| regarde le *client*, celui dont le véhicule est entré à l'atelier.
|
| Les filtres s'arrêtent à la ville et au site, comme demandé. Deux filtres qu'on
| sait expliquer valent mieux que six dont on ne sait plus lequel a vidé le tableau.
*/

state([
    'recherche' => '',
    'villeFiltre' => '',
    'siteFiltre' => '',
    'page' => 1,
]);

$villes = computed(fn () => Ville::where('est_actif', true)->orderBy('nom')->pluck('nom', 'id')->all());

/** Les ateliers de la ville choisie — la liste reste vide tant qu'aucune ville ne l'est. */
$sites = computed(fn () => $this->villeFiltre === ''
    ? []
    : Site::where('ville_id', (int) $this->villeFiltre)->where('est_actif', true)
        ->orderBy('nom')->pluck('nom', 'id')->all());

$parPage = computed(fn () => 30);

$annuaire = computed(fn () => (new AnnuaireDesClients((int) auth()->user()->entreprise_id))->lignes(
    $this->villeFiltre === '' ? null : (int) $this->villeFiltre,
    $this->siteFiltre === '' ? null : (int) $this->siteFiltre,
    ExerciceDeTravail::annee(),
));

$lignes = computed(function () {
    $cherche = trim(mb_strtolower($this->recherche));

    return $this->annuaire
        ->when($cherche !== '', fn ($lignes) => $lignes->filter(
            fn (array $c) => str_contains(mb_strtolower($c['nom']), $cherche),
        ))
        ->values();
});

$pageCourante = computed(fn () => min(
    max(1, (int) $this->page),
    max(1, (int) ceil($this->lignes->count() / $this->parPage)),
));

$affichees = computed(fn () => $this->lignes
    ->slice(($this->pageCourante - 1) * $this->parPage, $this->parPage));

$totaux = computed(fn () => [
    'clients' => $this->lignes->count(),
    'montant' => $this->lignes->sum('montant'),
    'vehicules' => $this->lignes->sum('vehicules'),
]);

// Un filtre qui laisse la pagination sur la page sept affiche un tableau vide sous un
// titre qui annonce trois cents clients.
$updatedRecherche = function () { $this->page = 1; };
$updatedVilleFiltre = function () { $this->siteFiltre = ''; $this->page = 1; };
$updatedSiteFiltre = function () { $this->page = 1; };

?>

<div>
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;
                       text-transform:uppercase; letter-spacing:1px; margin:0; color:var(--th-steel,#2A2E35);">
                Clients de l'entreprise
            </h1>
            <div style="color:var(--th-gris,#6B6E76); font-size:13.5px; margin-top:3px;">
                Tous les clients connus — facturés ou simplement reçus à l'atelier.
                @if (ExerciceDeTravail::annee())
                    Exercice {{ ExerciceDeTravail::annee() }}.
                @endif
            </div>
        </div>
    </div>

    <div class="cartes-kpi" style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:16px;">
        <div class="carte" style="padding:13px 15px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; font-weight:700;">Clients</div>
            <div style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:700; font-variant-numeric:tabular-nums;">
                {{ number_format($this->totaux['clients'], 0, ',', ' ') }}
            </div>
        </div>
        <div class="carte" style="padding:13px 15px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; font-weight:700;">Facturé</div>
            <div style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap;">
                {{ number_format($this->totaux['montant'], 0, ',', ' ') }}
            </div>
            <div style="font-size:11.5px; color:#6B6E76;">F CFA</div>
        </div>
        <div class="carte" style="padding:13px 15px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; font-weight:700;">Véhicules distincts</div>
            <div style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:700; font-variant-numeric:tabular-nums;">
                {{ number_format($this->totaux['vehicules'], 0, ',', ' ') }}
            </div>
        </div>
    </div>

    <div class="carte">
        <h3 class="titre-section">
            Filtrer
        </h3>

        {{-- Les trois champs portent leur valeur : une page qui affiche « Toutes » alors
             que le serveur tient « San Pédro » fait chercher un défaut là où il n'est pas. --}}
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:12px; align-items:end; margin-bottom:14px;">
            <div>
                <label for="q" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">
                    Rechercher un client
                </label>
                <input type="search" id="q" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}"
                       placeholder="Nom du client, de l'assurance…"
                       style="width:100%; box-sizing:border-box; border:1px solid #E3E0D8; border-radius:6px;
                              padding:8px 10px; font-size:14px; background:var(--th-champ,#FFFBEA); font-family:inherit;">
            </div>
            <div>
                <label for="v" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Ville</label>
                <select id="v" wire:model.live="villeFiltre"
                        style="width:100%; box-sizing:border-box; border:1px solid #E3E0D8; border-radius:6px;
                               padding:8px 10px; font-size:14px; background:var(--th-champ,#FFFBEA); font-family:inherit;">
                    <option value="" @selected($villeFiltre === '')>Toutes les villes</option>
                    @foreach ($this->villes as $id => $nom)
                        <option value="{{ $id }}" @selected((string) $villeFiltre === (string) $id)>{{ $nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="s" style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px;">Atelier</label>
                <select id="s" wire:model.live="siteFiltre"
                        style="width:100%; box-sizing:border-box; border:1px solid #E3E0D8; border-radius:6px;
                               padding:8px 10px; font-size:14px; background:var(--th-champ,#FFFBEA); font-family:inherit;">
                    @if ($villeFiltre === '')
                        <option value="">Choisissez d'abord une ville</option>
                    @else
                        <option value="" @selected($siteFiltre === '')>Tous les ateliers</option>
                        @foreach ($this->sites as $id => $nom)
                            <option value="{{ $id }}" @selected((string) $siteFiltre === (string) $id)>{{ $nom }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
        </div>

        @if ($villeFiltre !== '' || $siteFiltre !== '')
            <div style="font-size:12.5px; color:#6B6E76; margin-bottom:12px; padding:8px 10px; background:#F4F2EC;
                        border:1px dashed #E3E0D8; border-radius:7px; line-height:1.55;">
                Filtré sur un lieu&nbsp;: <strong>les clients dont les factures n'ont pas encore d'atelier
                déterminé n'apparaissent pas</strong>. C'est le cas des imports faits sans filtrer
                l'extraction — « À traiter », dans le module Import, dit combien.
            </div>
        @endif

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th style="text-align:right; white-space:nowrap;">Factures</th>
                        <th style="text-align:right; white-space:nowrap;">Facturé (F CFA)</th>
                        <th style="text-align:right; white-space:nowrap;">Fiches</th>
                        <th style="text-align:right; white-space:nowrap;">Véhicules</th>
                        <th>Ateliers</th>
                        <th style="white-space:nowrap;">Dernière facture</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->affichees as $client)
                        <tr wire:key="cli-{{ md5($client['nom']) }}">
                            <td style="font-weight:600;">{{ $client['nom'] }}</td>
                            {{-- `white-space:nowrap` et chiffres tabulaires : un montant qui se
                                 coupe en deux lignes ne se lit plus, il se devine. --}}
                            <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">
                                {{ $client['factures'] ?: '·' }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:700;">
                                {{ $client['montant'] ? number_format($client['montant'], 0, ',', ' ') : '·' }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">
                                {{ $client['fiches'] ?: '·' }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">
                                {{ $client['vehicules'] ?: '·' }}
                            </td>
                            <td style="font-size:12px; color:#6B6E76;">
                                {{ $client['sites'] ? implode(' · ', $client['sites']) : '—' }}
                            </td>
                            <td style="white-space:nowrap;">
                                {{ $client['derniere']?->format('d/m/Y') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="7" texte="Aucun client ne correspond à ce filtre." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->lignes->count() > $this->parPage)
            <div style="display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap;">
                <button type="button" class="bouton bouton-secondaire bouton-petit"
                        wire:click="$set('page', {{ max(1, $this->pageCourante - 1) }})"
                        @disabled($this->pageCourante <= 1)>Précédent</button>
                <span style="font-size:12.5px; color:#6B6E76;">
                    Page {{ $this->pageCourante }} sur {{ max(1, (int) ceil($this->lignes->count() / $this->parPage)) }}
                    — {{ number_format($this->lignes->count(), 0, ',', ' ') }} client(s)
                </span>
                <button type="button" class="bouton bouton-secondaire bouton-petit"
                        wire:click="$set('page', {{ $this->pageCourante + 1 }})"
                        @disabled($this->pageCourante >= (int) ceil($this->lignes->count() / $this->parPage))>Suivant</button>
            </div>
        @endif
    </div>
</div>
