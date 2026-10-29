<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\ClientCommercial;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\RelanceCommerciale;
use Modules\Noyau\Exploitation\Services\PerimetreCommercialDuRecouvrement;
use Modules\Noyau\Exploitation\Services\PortefeuilleCommercial;
use Modules\Recouvrement\Support\AccesRecouvrement;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Portefeuilles clients des commerciaux — 26/10
|--------------------------------------------------------------------------
| « On désire affecter à chaque commercial un ou plusieurs clients — leurs clients prospectés, on
| peut aussi les affecter. Ils pourront se faire des relances directement, comme le recouvrement. »
|
| Ici le gérant, le superviseur de ville et le responsable commercial affectent, réaffectent ou
| retirent un client, et lisent les relances de l'équipe. Les commerciaux proposés sont ceux du
| périmètre du compte (sa ville, ou toutes). Le commercial travaille, lui, sur « Mes clients ».
*/

state([
    'commercialChoisi' => '',
    'client' => '',
    'telephone' => '',
    'note' => '',
    'recherche' => '',
    // La ligne dont on change le commercial, et vers qui.
    'reaffectation' => null,
    'versCommercial' => '',
    // « Tous les clients » — 27/10 : recherche, filtre, page, et le commercial choisi ligne par ligne.
    'annuaireRecherche' => '',
    'annuaireFiltre' => '',
    'annuairePage' => 1,
    'choix' => [],
]);

$garder = protect(function () {
    abort_unless(PortefeuilleCommercial::peutAffecter(auth()->user()), 403);
});

/** Les commerciaux du périmètre : sa ville, ou toutes pour qui les voit toutes. */
$commerciaux = computed(fn () => Commercial::actifs()->where('est_spontane', false)
    ->when(! auth()->user()->voitToutesLesVilles(), fn ($q) => $q->whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), '')))
    ->orderBy('nom')->pluck('nom', 'id')->all());

$lignes = computed(function () {
    if (! PortefeuilleCommercial::disponible()) {
        return collect();
    }

    $ids = $this->commercialChoisi !== '' && isset($this->commerciaux[(int) $this->commercialChoisi])
        ? [(int) $this->commercialChoisi]
        : array_keys($this->commerciaux);

    return PortefeuilleCommercial::clients($ids, (string) $this->recherche);
});

/** Tous les clients, avec qui les a prospectés et qui les suit — voir `PortefeuilleCommercial::annuaire`. */
$annuaire = computed(fn () => ! PortefeuilleCommercial::disponible() ? ['lignes' => collect(), 'total' => 0]
    : PortefeuilleCommercial::annuaire((string) $this->annuaireRecherche, (string) $this->annuaireFiltre, (int) $this->annuairePage));

/*
 * **Les impayés des clients suivis — 28/10** : « le but est de voir les impayés de ces clients ;
 * au tableau où un client a quelqu'un qui le suit, un bouton qui ramène vers ses pages ». Une passe
 * sur les créances ouvertes pour les deux tableaux de la page (`impayesDesClients`) ; le bouton
 * ouvre le dossier du recouvrement du tiers à relancer, si le module est ouvert à qui regarde — et,
 * pour un responsable commercial, si le tiers est dans sa propre vue.
 */
$recouvrementOuvert = computed(fn () => AccesRecouvrement::ouvertA(auth()->user()));

$impayes = computed(function () {
    if (! $this->recouvrementOuvert) {
        return [];
    }

    $noms = [
        ...$this->lignes->pluck('client')->all(),
        ...$this->annuaire['lignes']->whereNotNull('suivi_id')->pluck('nom')->all(),
    ];

    return PerimetreCommercialDuRecouvrement::impayesDesClients($noms);
});

/** Le tiers à ouvrir pour ce client, s'il y en a un et que le lecteur peut l'ouvrir. */
$tiersAOuvrir = protect(function (string $client): ?string {
    $tiers = $this->impayes[PortefeuilleCommercial::cle($client)]['tiers'] ?? null;
    $perimetre = PerimetreCommercialDuRecouvrement::courant();

    return $tiers !== null && ($perimetre === null || $perimetre->retient($tiers)) ? $tiers : null;
});

$updatedAnnuaireRecherche = function () { $this->annuairePage = 1; };
$updatedAnnuaireFiltre = function () { $this->annuairePage = 1; };

/** Affecte un client de la liste à qui l'on veut — qu'il l'ait prospecté ou non, et même s'il est suivi ailleurs. */
$affecterA = function (string $client, string $cle) {
    $this->garder();

    $commercial = $this->commercialDuPerimetre($this->choix[$cle] ?? '');

    if ($commercial === null) {
        $this->dispatch('annonce', texte: 'Choisissez d\'abord, sur la ligne, le commercial de votre périmètre.');

        return;
    }

    $ligne = PortefeuilleCommercial::affecter($commercial, $client, auth()->user(), 'affectation');
    unset($this->choix[$cle]);
    unset($this->annuaire, $this->lignes, $this->prospectes);
    $this->dispatch('annonce', ton: 'succes', texte: '« '.$ligne->client.' » est affecté à '.$commercial->nom.'.');
};

$nomsConnus = computed(fn () => PortefeuilleCommercial::nomsConnus());

/** Les clients prospectés du commercial choisi que personne ne suit : à affecter d'un clic. */
$prospectes = computed(function () {
    if ($this->commercialChoisi === '' || ! isset($this->commerciaux[(int) $this->commercialChoisi]) || ! PortefeuilleCommercial::disponible()) {
        return [];
    }

    return PortefeuilleCommercial::prospectesNonSuivis(Commercial::query()->find((int) $this->commercialChoisi));
});

$relancesRecentes = computed(fn () => ! PortefeuilleCommercial::disponible() ? collect() : RelanceCommerciale::query()
    ->with('commercial:id,nom')
    ->whereIn('commercial_id', $this->commercialChoisi !== '' ? [(int) $this->commercialChoisi] : array_keys($this->commerciaux))
    ->orderByDesc('date')->orderByDesc('id')->limit(30)->get());

/** Le commercial visé, relu dans le périmètre : un identifiant reçu ne suffit pas. */
$commercialDuPerimetre = protect(function ($id): ?Commercial {
    return isset($this->commerciaux[(int) $id]) ? Commercial::query()->find((int) $id) : null;
});

$affecter = function () {
    $this->garder();

    $this->validate([
        'commercialChoisi' => ['required'],
        'client' => ['required', 'string', 'max:160'],
        'telephone' => ['nullable', 'string', 'max:40'],
        'note' => ['nullable', 'string', 'max:500'],
    ], ['commercialChoisi.required' => 'Choisissez d\'abord le commercial.'], ['client' => 'client']);

    $commercial = $this->commercialDuPerimetre($this->commercialChoisi);

    if ($commercial === null) {
        $this->addError('commercialChoisi', 'Ce commercial n\'est pas dans votre périmètre.');

        return;
    }

    $ligne = PortefeuilleCommercial::affecter($commercial, $this->client, auth()->user(), 'affectation', $this->telephone, $this->note);

    $this->reset('client', 'telephone', 'note');
    unset($this->lignes, $this->prospectes);
    $this->dispatch('annonce', ton: 'succes', texte: '« '.$ligne->client.' » est affecté à '.$commercial->nom.'.');
};

/** Affecte au commercial choisi un de ses clients prospectés — ou tous d'un coup. */
$affecterProspecte = function (?string $client = null) {
    $this->garder();

    $commercial = $this->commercialDuPerimetre($this->commercialChoisi);

    if ($commercial === null) {
        return;
    }

    $noms = $client === null ? $this->prospectes : array_values(array_intersect([$client], $this->prospectes));

    foreach ($noms as $nom) {
        PortefeuilleCommercial::affecter($commercial, $nom, auth()->user(), 'prospection');
    }

    unset($this->lignes, $this->prospectes);
    $this->dispatch('annonce', ton: 'succes', texte: count($noms).' client(s) prospecté(s) affecté(s) à '.$commercial->nom.'.');
};

$ouvrirReaffectation = function (int $id) {
    $this->reaffectation = $id;
    $this->versCommercial = '';
};

$reaffecter = function () {
    $this->garder();

    $ligne = ClientCommercial::query()->whereIn('commercial_id', array_keys($this->commerciaux))->find((int) $this->reaffectation);
    $vers = $this->commercialDuPerimetre($this->versCommercial);

    if ($ligne === null || $vers === null) {
        $this->addError('versCommercial', 'Choisissez un commercial de votre périmètre.');

        return;
    }

    PortefeuilleCommercial::affecter($vers, $ligne->client, auth()->user(), $ligne->origine);
    $this->reaffectation = null;
    unset($this->lignes);
    $this->dispatch('annonce', ton: 'succes', texte: '« '.$ligne->client.' » passe à '.$vers->nom.'.');
};

$retirer = function (int $id) {
    $this->garder();

    $ligne = ClientCommercial::query()->whereIn('commercial_id', array_keys($this->commerciaux))->find($id);

    if ($ligne === null) {
        return;
    }

    activity()->performedOn($ligne)->causedBy(auth()->user())
        ->withProperties(['client' => $ligne->client, 'commercial_id' => $ligne->commercial_id])
        ->log('Client retiré du portefeuille d\'un commercial');
    $ligne->delete();

    unset($this->lignes, $this->prospectes);
    // Ses relances restent : elles disent ce qui a été fait, sous le nom de qui l'a fait.
    $this->dispatch('annonce', ton: 'succes', texte: '« '.$ligne->client.' » n\'est plus suivi. Ses relances restent au journal.');
};

?>

<div>
    <x-titre-ecran titre="Portefeuilles clients"
        sous-titre="Les clients que suit chaque commercial, et ses relances. Un client n'a qu'un commercial à la fois." />

    @if (! PortefeuilleCommercial::disponible())
        <div class="carte"><p style="margin:0; color:#B45309;">La mise à jour du serveur n'est pas encore passée (migration du 26/10).</p></div>
    @else
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:14.5px; font-weight:700; margin:0 0 8px;">Affecter un client</h3>
            <form wire:submit="affecter">
                <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                    <x-champ label="Commercial" model="commercialChoisi" type="select" :live="true" width="240" vide="— à choisir —" :options="$this->commerciaux" requis />
                    <div style="display:flex; flex-direction:column; flex:1 1 280px; max-width:380px;">
                        <label class="champ-libelle">Client<span style="color:var(--th-accent);"> *</span></label>
                        {{-- Un nom des factures, devis ou prospections, ou un nom nouveau : la liste propose, elle n'oblige pas. --}}
                        <input type="text" wire:model="client" list="clients-connus" class="champ" placeholder="Nom du client…" autocomplete="off">
                        <datalist id="clients-connus">
                            @foreach ($this->nomsConnus as $nom)
                                <option value="{{ $nom }}"></option>
                            @endforeach
                        </datalist>
                        @error('client') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                    <x-champ label="Téléphone" model="telephone" width="160" />
                    <x-champ label="Note" model="note" width="240" placeholder="Interlocuteur, flotte…" />
                    <button type="submit" class="bouton">Affecter</button>
                </div>
            </form>

            @if ($this->prospectes !== [])
                <div style="margin-top:12px;">
                    <p style="margin:0 0 6px; font-size:12.5px; color:#6B6E76;">
                        Ses clients prospectés que personne ne suit ({{ count($this->prospectes) }}) :
                        <button type="button" wire:click="affecterProspecte" class="bouton bouton-secondaire" style="padding:3px 10px; font-size:12px;">Tous les affecter</button>
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        @foreach (array_slice($this->prospectes, 0, 60) as $nom)
                            <button type="button" wire:click="affecterProspecte(@js($nom))" wire:key="pp-{{ md5($nom) }}"
                                class="bouton bouton-secondaire" style="padding:3px 10px; font-size:12px;">+ {{ $nom }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- ════════════════ Tous les clients — 27/10
             « Une méthode où tous les clients sont, où l'on voit qui a prospecté chacun, mais où l'on peut
             l'affecter à qui l'on veut. » --}}
        @php $annuaire = $this->annuaire; @endphp
        <div class="carte" style="margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                <h3 style="font-size:14.5px; font-weight:700; margin:0;">Tous les clients ({{ number_format($annuaire['total'], 0, ',', ' ') }})</h3>
                <div class="bloc-saisie" style="margin:0;">
                    <x-champ label="Rechercher" model="annuaireRecherche" :live="true" width="220" placeholder="Nom du client…" />
                    <x-champ label="Afficher" model="annuaireFiltre" type="select" :live="true" width="210"
                        :options="['' => 'Tous les clients', 'prospectes' => 'Prospectés', 'non_suivis' => 'Pas encore affectés', 'suivis' => 'Déjà affectés']" />
                </div>
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Client</th><th>Prospecté par</th><th>Suivi par</th>@if ($this->recouvrementOuvert)<th style="text-align:right;">Impayés</th>@endif<th>Affecter à</th></tr></thead>
                    <tbody>
                        @forelse ($annuaire['lignes'] as $c)
                            @php $cle = md5($c->cle); @endphp
                            <tr wire:key="an-{{ $cle }}">
                                <td><b>{{ $c->nom }}</b></td>
                                <td style="font-size:12px;">{{ $c->prospecte_par === [] ? '—' : implode(', ', $c->prospecte_par) }}</td>
                                <td style="font-size:12px;">{{ $c->suivi_par ?? '—' }}</td>
                                @if ($this->recouvrementOuvert)
                                    @php $imp = $c->suivi_id ? ($this->impayes[PortefeuilleCommercial::cle($c->nom)] ?? null) : null; $t = $c->suivi_id ? $this->tiersAOuvrir($c->nom) : null; @endphp
                                    <td style="text-align:right; white-space:nowrap; font-size:12px;">
                                        @if (! $c->suivi_id)
                                            <span style="color:#9A9DA5;">—</span>
                                        @else
                                            <span style="{{ ($imp['reste'] ?? 0) > 0 ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">{{ ($imp['factures'] ?? 0) > 0 ? ae($imp['reste']) : 'aucun' }}</span>
                                            @if ($t)
                                                <a href="{{ route('recouvrement.dossier', ['tiers' => $t]) }}" wire:navigate class="bouton bouton-secondaire" style="padding:3px 8px; font-size:11.5px; margin-left:4px;">Recouvrement</a>
                                            @endif
                                        @endif
                                    </td>
                                @endif
                                <td style="white-space:nowrap;">
                                    <select wire:model="choix.{{ $cle }}" class="champ" style="width:auto; display:inline-block;" aria-label="Commercial pour {{ $c->nom }}">
                                        <option value="">— commercial —</option>
                                        @foreach ($this->commerciaux as $id => $nom)
                                            <option value="{{ $id }}">{{ $nom }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="affecterA(@js($c->nom), '{{ $cle }}')" class="bouton" style="padding:4px 10px; font-size:12px;">
                                        {{ $c->suivi_id ? 'Réaffecter' : 'Affecter' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="$this->recouvrementOuvert ? 5 : 4" texte="Aucun client ne répond à la recherche." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$annuairePage" :total="$annuaire['total']" prop="annuairePage" :par-page="30" />
        </div>

        <div class="carte" style="margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                <h3 style="font-size:14.5px; font-weight:700; margin:0;">
                    Clients suivis{{ $commercialChoisi !== '' && isset($this->commerciaux[(int) $commercialChoisi]) ? ' par '.$this->commerciaux[(int) $commercialChoisi] : ' — toute l\'équipe' }}
                    ({{ $this->lignes->count() }})
                </h3>
                <x-champ label="Rechercher" model="recherche" :live="true" width="240" placeholder="Nom du client…" />
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Client</th><th>Commercial</th><th>Origine</th><th style="text-align:right;">CA facturé</th>
                            <th style="text-align:right;">Relances</th><th>Dernière relance</th><th>Prochaine</th>
                            @if ($this->recouvrementOuvert)<th style="text-align:right;">Impayés</th>@endif<th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes as $l)
                            @php $d = $l->derniere_relance; @endphp
                            <tr wire:key="pf-{{ $l->id }}">
                                <td><b>{{ $l->client }}</b>@if ($l->telephone)<div style="font-size:11.5px; color:#6B6E76;">{{ $l->telephone }}</div>@endif</td>
                                <td>{{ $l->commercial?->nom }}</td>
                                <td style="font-size:12px;">{{ ClientCommercial::ORIGINES[$l->origine] ?? $l->origine }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae($l->ca) }}</td>
                                <td style="text-align:right;">{{ number_format($l->nb_relances, 0, ',', ' ') }}</td>
                                <td style="font-size:12px;">{{ $d ? $d->date?->format('d/m/Y').' — '.$d->statut : '—' }}</td>
                                <td style="font-size:12px; {{ PortefeuilleCommercial::aRelancer($l) ? 'color:#C8102E; font-weight:700;' : '' }}">{{ $d?->prochaine_relance?->format('d/m/Y') ?? '—' }}</td>
                                @if ($this->recouvrementOuvert)
                                    @php $imp = $this->impayes[PortefeuilleCommercial::cle($l->client)] ?? null; @endphp
                                    <td style="text-align:right; white-space:nowrap; {{ ($imp['reste'] ?? 0) > 0 ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                        {{ ($imp['factures'] ?? 0) > 0 ? ae($imp['reste']) : '—' }}
                                    </td>
                                @endif
                                <td style="white-space:nowrap; text-align:right;">
                                    {{-- Vers le recouvrement de ce client (28/10). --}}
                                    @if ($this->recouvrementOuvert && ($t = $this->tiersAOuvrir($l->client)))
                                        <a href="{{ route('recouvrement.dossier', ['tiers' => $t]) }}" wire:navigate class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;">Recouvrement</a>
                                        @if (AccesRecouvrement::peutSaisir(auth()->user()))
                                            <a href="{{ route('recouvrement.saisie', ['relance' => $t]) }}" wire:navigate class="bouton" style="padding:4px 10px; font-size:12px;">Relancer</a>
                                        @endif
                                    @endif
                                    @if ((int) $reaffectation === (int) $l->id)
                                        <select wire:model="versCommercial" class="champ" style="width:auto; display:inline-block;" aria-label="Nouveau commercial">
                                            <option value="">— vers —</option>
                                            @foreach ($this->commerciaux as $id => $nom)
                                                @if ((int) $id !== (int) $l->commercial_id)<option value="{{ $id }}">{{ $nom }}</option>@endif
                                            @endforeach
                                        </select>
                                        <button type="button" wire:click="reaffecter" class="bouton" style="padding:4px 10px; font-size:12px;">OK</button>
                                        @error('versCommercial') <div class="champ-erreur">{{ $message }}</div> @enderror
                                    @else
                                        <button type="button" wire:click="ouvrirReaffectation({{ $l->id }})" class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;">Réaffecter</button>
                                        <button type="button" wire:click="retirer({{ $l->id }})" wire:confirm="Retirer ce client du portefeuille ? Ses relances restent."
                                            class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px; color:#C8102E; border-color:#C8102E;">Retirer</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="$this->recouvrementOuvert ? 9 : 8" texte="Aucun client affecté." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <x-carte-section titre="Relances récentes de l'équipe">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Date</th><th>Commercial</th><th>Client</th><th>Objet</th><th>Canal</th><th>Statut</th><th>Prochaine</th><th>Résultat</th></tr></thead>
                    <tbody>
                        @forelse ($this->relancesRecentes as $r)
                            <tr wire:key="rr-{{ $r->id }}">
                                <td>{{ $r->date?->format('d/m/Y') }}</td>
                                <td>{{ $r->commercial?->nom ?? $r->responsable }}</td>
                                <td>{{ $r->client }}</td>
                                <td style="font-size:12px;">{{ $r->objet }}</td>
                                <td style="font-size:12px;">{{ $r->canal }}</td>
                                <td style="font-size:12px;">{{ $r->statut }}</td>
                                <td style="font-size:12px;">{{ $r->prochaine_relance?->format('d/m/Y') ?? '—' }}</td>
                                <td style="font-size:12px; color:#4B4E55;">{{ \Illuminate\Support\Str::limit((string) $r->resultat, 120) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="8" texte="Aucune relance pour l'instant." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-carte-section>
    @endif
</div>
