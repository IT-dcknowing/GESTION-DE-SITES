<?php

use Illuminate\Validation\Rule;
use Modules\Noyau\Exploitation\Modeles\ClientCommercial;
use Modules\Noyau\Exploitation\Modeles\RelanceCommerciale;
use Modules\Noyau\Exploitation\Services\PerimetreCommercialDuRecouvrement;
use Modules\Noyau\Exploitation\Services\PortefeuilleCommercial;
use Modules\Recouvrement\Support\AccesRecouvrement;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Mes clients — 26/10
|--------------------------------------------------------------------------
| « On affecte à chaque commercial un ou plusieurs clients — ceux qu'il a prospectés aussi. Ils
| pourront se faire des relances directement, comme le recouvrement : une nouvelle page où ils
| voient les clients qu'ils suivent, et font leurs relances. »
|
| Le commercial lit ici ses clients (affectés par son responsable, ou repris de ses prospections),
| leurs chiffres, et la relance à faire ; il en saisit une d'un clic. Il ne voit que les siens : le
| périmètre se relit à chaque action sur sa fiche commercial, jamais sur un identifiant reçu.
| Voir `PortefeuilleCommercial`.
*/

state([
    'recherche' => '',
    'filtre' => '',
    // La relance en cours de saisie : l'identifiant de la ligne du portefeuille, ou null.
    'relanceDe' => null,
    'rDate' => '', 'rObjet' => '', 'rCanal' => 'Téléphone', 'rInterlocuteur' => '',
    'rResultat' => '', 'rProchaine' => '', 'rStatut' => 'À suivre',
    // L'historique déplié d'un client.
    'historiqueDe' => null,
]);

mount(function () {
    $this->rDate = now()->toDateString();
    $this->rObjet = RelanceCommerciale::OBJETS[0];
});

$commercial = computed(fn () => PortefeuilleCommercial::commercialDe(auth()->user()));

$clients = computed(function () {
    if ($this->commercial === null || ! PortefeuilleCommercial::disponible()) {
        return collect();
    }

    $lignes = PortefeuilleCommercial::clients([$this->commercial->id], (string) $this->recherche);

    return match ($this->filtre) {
        'a_relancer' => $lignes->filter(fn ($c) => PortefeuilleCommercial::aRelancer($c))->values(),
        'jamais' => $lignes->filter(fn ($c) => $c->nb_relances === 0)->values(),
        default => $lignes,
    };
});

$compteurs = computed(function () {
    if ($this->commercial === null || ! PortefeuilleCommercial::disponible()) {
        return ['clients' => 0, 'a_relancer' => 0, 'jamais' => 0, 'mois' => 0];
    }

    $tous = PortefeuilleCommercial::clients([$this->commercial->id]);

    return [
        'clients' => $tous->count(),
        'a_relancer' => $tous->filter(fn ($c) => PortefeuilleCommercial::aRelancer($c))->count(),
        'jamais' => $tous->where('nb_relances', 0)->count(),
        'mois' => RelanceCommerciale::query()->where('commercial_id', $this->commercial->id)
            ->where('date', '>=', now()->startOfMonth()->toDateString())->count(),
    ];
});

/*
 * **Leurs impayés — 28/10** : « le but d'affecter des clients à un commercial est de voir leurs
 * impayés ». Une passe sur les créances ouvertes pour toute la liste (`impayesDesClients`), et deux
 * boutons par client : « Impayés » ouvre son dossier du recouvrement, « Relancer » la saisie, sur la
 * relance, le tiers déjà choisi. Seulement si le module lui est ouvert.
 */
$recouvrementOuvert = computed(fn () => AccesRecouvrement::ouvertA(auth()->user()));

$impayes = computed(fn () => ! $this->recouvrementOuvert || $this->clients->isEmpty() ? []
    : PerimetreCommercialDuRecouvrement::impayesDesClients($this->clients->pluck('client')->all()));

/** Ses clients prospectés que personne ne suit encore : il peut les reprendre. */
$prospectes = computed(fn () => $this->commercial === null || ! PortefeuilleCommercial::disponible()
    ? []
    : PortefeuilleCommercial::prospectesNonSuivis($this->commercial));

$historique = computed(fn () => $this->historiqueDe === null || $this->commercial === null ? collect()
    : RelanceCommerciale::query()
        ->where('commercial_id', $this->commercial->id)
        ->where('client', optional($this->ligneDuPortefeuille((int) $this->historiqueDe))->client ?? '')
        ->orderByDesc('date')->orderByDesc('id')->limit(30)->get());

/** Une ligne de son portefeuille, relue sur sa fiche : un identifiant reçu ne suffit pas. */
$ligneDuPortefeuille = protect(function (int $id): ?ClientCommercial {
    return $this->commercial === null ? null
        : ClientCommercial::query()->where('commercial_id', $this->commercial->id)->find($id);
});

$suivre = function (string $client) {
    if ($this->commercial === null) {
        abort(403);
    }

    // Seulement un de ses clients prospectés, et que personne ne suit : il ne prend pas celui d'un collègue.
    if (! in_array($client, $this->prospectes, true)) {
        $this->dispatch('annonce', texte: 'Ce client est déjà suivi, ou ne vient pas de vos prospections.');

        return;
    }

    PortefeuilleCommercial::affecter($this->commercial, $client, auth()->user(), 'prospection');
    unset($this->clients, $this->compteurs, $this->prospectes);
    $this->dispatch('annonce', ton: 'succes', texte: '« '.$client.' » rejoint vos clients.');
};

$ouvrirRelance = function (int $id) {
    if ($this->ligneDuPortefeuille($id) === null) {
        return;
    }

    $this->relanceDe = $id;
    $this->rDate = now()->toDateString();
    $this->rInterlocuteur = '';
    $this->rResultat = '';
    $this->rProchaine = '';
    $this->rStatut = 'À suivre';
    $this->resetValidation();
};

$fermerRelance = function () {
    $this->relanceDe = null;
    $this->resetValidation();
};

$basculerHistorique = function (int $id) {
    $this->historiqueDe = $this->historiqueDe === $id ? null : $id;
    unset($this->historique);
};

$enregistrerRelance = function () {
    $ligne = $this->relanceDe === null ? null : $this->ligneDuPortefeuille((int) $this->relanceDe);

    if ($ligne === null) {
        abort(403);
    }

    $donnees = $this->validate([
        'rDate' => ['required', 'date', 'before_or_equal:today'],
        'rObjet' => ['required', Rule::in(RelanceCommerciale::OBJETS)],
        'rCanal' => ['required', Rule::in(RelanceCommerciale::CANAUX)],
        'rInterlocuteur' => ['nullable', 'string', 'max:120'],
        'rResultat' => ['nullable', 'string', 'max:2000'],
        'rProchaine' => ['nullable', 'date', 'after_or_equal:rDate'],
        'rStatut' => ['required', Rule::in(RelanceCommerciale::STATUTS)],
    ], [], [
        'rDate' => 'date', 'rObjet' => 'objet', 'rCanal' => 'canal', 'rInterlocuteur' => 'interlocuteur',
        'rResultat' => 'résultat', 'rProchaine' => 'prochaine relance', 'rStatut' => 'statut',
    ]);

    RelanceCommerciale::create([
        'entreprise_id' => $ligne->entreprise_id,
        'commercial_id' => $this->commercial->id,
        'user_id' => auth()->id(),
        'responsable' => auth()->user()->name,
        'client' => $ligne->client,
        'date' => $donnees['rDate'],
        'objet' => $donnees['rObjet'],
        'canal' => $donnees['rCanal'],
        'interlocuteur' => $donnees['rInterlocuteur'] ?: null,
        'resultat' => $donnees['rResultat'] ?: null,
        'prochaine_relance' => $donnees['rProchaine'] ?: null,
        'statut' => $donnees['rStatut'],
    ]);

    $this->relanceDe = null;
    unset($this->clients, $this->compteurs, $this->historique);
    $this->dispatch('annonce', ton: 'succes', texte: 'Relance de « '.$ligne->client.' » enregistrée.');
};

?>

<div>
    <x-titre-ecran titre="Mes clients"
        sous-titre="Les clients que vous suivez — affectés par votre responsable ou repris de vos prospections — et vos relances." />

    @if ($this->commercial === null)
        <div class="carte"><p style="margin:0; color:#6B6E76;">Votre compte n'a pas de fiche commercial : demandez à votre responsable de la créer.</p></div>
    @elseif (! PortefeuilleCommercial::disponible())
        <div class="carte"><p style="margin:0; color:#B45309;">La mise à jour du serveur n'est pas encore passée (migration du 26/10).</p></div>
    @else
        @php $k = $this->compteurs; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Clients suivis" :value="number_format($k['clients'], 0, ',', ' ')" />
            <x-kpi-card label="À relancer aujourd'hui" :value="number_format($k['a_relancer'], 0, ',', ' ')"
                :couleur="$k['a_relancer'] > 0 ? '#C8102E' : '#6B6E76'" sub="La date de prochaine relance est arrivée" />
            <x-kpi-card label="Jamais relancés" :value="number_format($k['jamais'], 0, ',', ' ')" couleur="#B45309" />
            <x-kpi-card label="Relances ce mois-ci" :value="number_format($k['mois'], 0, ',', ' ')" couleur="#0E9F6E" />
        </div>

        <div class="carte" style="margin-bottom:16px;">
            <div class="bloc-saisie" style="margin-bottom:12px;">
                <x-champ label="Rechercher un client" model="recherche" :live="true" width="260" placeholder="Nom du client…" />
                <x-champ label="Afficher" model="filtre" type="select" :live="true" width="220"
                    :options="['' => 'Tous mes clients', 'a_relancer' => 'À relancer aujourd\'hui', 'jamais' => 'Jamais relancés']" />
            </div>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Client</th><th>Origine</th><th style="text-align:right;">Factures</th><th style="text-align:right;">CA facturé</th>
                            <th style="text-align:right;">Devis</th>
                            @if ($this->recouvrementOuvert)<th style="text-align:right;">Impayés</th>@endif
                            <th>Dernière relance</th><th>Prochaine relance</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->clients as $c)
                            @php $derniere = $c->derniere_relance; $urgent = PortefeuilleCommercial::aRelancer($c); @endphp
                            <tr wire:key="client-{{ $c->id }}">
                                <td>
                                    <b>{{ $c->client }}</b>
                                    @if ($c->telephone)<div style="font-size:11.5px; color:#6B6E76;">{{ $c->telephone }}</div>@endif
                                    @if ($c->note)<div style="font-size:11.5px; color:#6B6E76;">{{ $c->note }}</div>@endif
                                </td>
                                <td style="font-size:12px;">{{ ClientCommercial::ORIGINES[$c->origine] ?? $c->origine }}</td>
                                <td style="text-align:right;">{{ number_format($c->nb_factures, 0, ',', ' ') }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae($c->ca) }}</td>
                                <td style="text-align:right;">{{ number_format($c->nb_devis, 0, ',', ' ') }}</td>
                                @php $imp = $this->impayes[PortefeuilleCommercial::cle($c->client)] ?? null; @endphp
                                @if ($this->recouvrementOuvert)
                                    <td style="text-align:right; white-space:nowrap; {{ ($imp['reste'] ?? 0) > 0 ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                        {{ ($imp['factures'] ?? 0) > 0 ? ae($imp['reste']) : '—' }}
                                        @if (($imp['factures'] ?? 0) > 0)<div style="font-size:11px; font-weight:400; color:#6B6E76;">{{ $imp['factures'] }} facture(s) ouverte(s)</div>@endif
                                    </td>
                                @endif
                                <td style="font-size:12px;">
                                    @if ($derniere)
                                        {{ $derniere->date?->format('d/m/Y') }} — {{ $derniere->objet }}
                                        <div style="color:#6B6E76;">{{ $derniere->statut }}</div>
                                    @else
                                        <span style="color:#B45309;">jamais</span>
                                    @endif
                                </td>
                                <td style="font-size:12px; {{ $urgent ? 'color:#C8102E; font-weight:700;' : '' }}">
                                    {{ $derniere?->prochaine_relance?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td style="white-space:nowrap; text-align:right;">
                                    {{-- « Relancer » ouvre la saisie du recouvrement sur la relance, le tiers choisi
                                         (28/10). Le tiers est le client s'il paie, sinon le payeur de ses factures. --}}
                                    @if ($this->recouvrementOuvert)
                                        @if ($imp['tiers'] ?? null)
                                            <a href="{{ route('recouvrement.dossier', ['tiers' => $imp['tiers']]) }}" wire:navigate class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;"
                                                title="Le recouvrement de {{ $imp['tiers'] }}">Impayés</a>
                                            <a href="{{ route('recouvrement.saisie', ['relance' => $imp['tiers']]) }}" wire:navigate class="bouton" style="padding:4px 10px; font-size:12px;">Relancer</a>
                                        @else
                                            <a href="{{ route('recouvrement.tableau-de-bord') }}" wire:navigate class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;"
                                                title="{{ ($imp['factures'] ?? 0) > 0 ? 'Plusieurs tiers paient pour ce client : choisissez-le au tableau de bord' : 'Aucune facture ouverte' }}">Recouvrement</a>
                                            <a href="{{ route('recouvrement.saisie') }}" wire:navigate class="bouton" style="padding:4px 10px; font-size:12px;">Relancer</a>
                                        @endif
                                    @endif
                                    <button type="button" wire:click="ouvrirRelance({{ $c->id }})" class="bouton {{ $this->recouvrementOuvert ? 'bouton-secondaire' : '' }}" style="padding:4px 10px; font-size:12px;">
                                        {{ $this->recouvrementOuvert ? 'Relance commerciale' : 'Relancer' }}
                                    </button>
                                    @if ($c->nb_relances > 0)
                                        <button type="button" wire:click="basculerHistorique({{ $c->id }})" class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;">
                                            Historique ({{ $c->nb_relances }})
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            @if ((int) $relanceDe === (int) $c->id)
                                <tr wire:key="relance-{{ $c->id }}">
                                    <td colspan="{{ $this->recouvrementOuvert ? 9 : 8 }}" style="background:#FDF2F4;">
                                        <form wire:submit="enregistrerRelance">
                                            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                                                <x-champ label="Date" model="rDate" type="date" width="155" requis />
                                                <x-champ label="Objet" model="rObjet" type="select" width="250" requis
                                                    :options="array_combine(RelanceCommerciale::OBJETS, RelanceCommerciale::OBJETS)" />
                                                <x-champ label="Canal" model="rCanal" type="select" width="160" requis
                                                    :options="array_combine(RelanceCommerciale::CANAUX, RelanceCommerciale::CANAUX)" />
                                                <x-champ label="Interlocuteur" model="rInterlocuteur" width="180" />
                                                <x-champ label="Statut" model="rStatut" type="select" width="170" requis
                                                    :options="array_combine(RelanceCommerciale::STATUTS, RelanceCommerciale::STATUTS)" />
                                                <x-champ label="Prochaine relance" model="rProchaine" type="date" width="160" />
                                                <x-champ label="Résultat" model="rResultat" type="textarea" placeholder="Ce qui a été dit, ce qui est promis…" />
                                            </div>
                                            <div style="margin-top:10px; display:flex; gap:8px;">
                                                <button type="submit" class="bouton">Enregistrer la relance</button>
                                                <button type="button" wire:click="fermerRelance" class="bouton bouton-secondaire">Annuler</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif

                            @if ((int) $historiqueDe === (int) $c->id)
                                <tr wire:key="historique-{{ $c->id }}">
                                    <td colspan="{{ $this->recouvrementOuvert ? 9 : 8 }}" style="background:#F7F6F2;">
                                        @foreach ($this->historique as $r)
                                            <div style="font-size:12.5px; padding:4px 0; border-bottom:1px solid #ECEAE3;">
                                                <b>{{ $r->date?->format('d/m/Y') }}</b> · {{ $r->objet }} · {{ $r->canal }}
                                                @if ($r->interlocuteur) · {{ $r->interlocuteur }} @endif
                                                · <i>{{ $r->statut }}</i>
                                                @if ($r->prochaine_relance) · à revoir le {{ $r->prochaine_relance->format('d/m/Y') }} @endif
                                                <span style="color:#9A9DA5;">— {{ $r->responsable }}</span>
                                                @if ($r->resultat)<div style="color:#4B4E55;">{{ $r->resultat }}</div>@endif
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <x-table-vide :colspan="$this->recouvrementOuvert ? 9 : 8" texte="Aucun client suivi pour l'instant : votre responsable vous en affecte, ou reprenez vos clients prospectés ci-dessous." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- « Leurs clients qu'ils ont prospectés, on peut aussi les affecter » : il les reprend lui-même. --}}
        @if ($this->prospectes !== [])
            <x-carte-section titre="Mes clients prospectés, pas encore suivis ({{ count($this->prospectes) }})">
                <p style="margin:0 0 10px; font-size:12.5px; color:#6B6E76;">
                    Les clients de vos prospections que personne ne suit encore. <b>Suivre</b> les ajoute à vos clients.
                </p>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    @foreach (array_slice($this->prospectes, 0, 60) as $nom)
                        <button type="button" wire:click="suivre(@js($nom))" wire:key="prospecte-{{ md5($nom) }}"
                            class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px;">+ {{ $nom }}</button>
                    @endforeach
                </div>
            </x-carte-section>
        @endif
    @endif
</div>
