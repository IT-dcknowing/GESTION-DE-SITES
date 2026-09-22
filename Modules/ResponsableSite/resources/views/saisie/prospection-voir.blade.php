<?php

use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Entreprises\Modeles\Site;
use function Livewire\Volt\{state, mount, computed};

state(['prospectionId']);

mount(function (Prospection $prospection) {
    $mesSites = Site::visiblesPour(auth()->user())->pluck('id');

    abort_unless($mesSites->contains($prospection->site_id), 403);

    $this->prospectionId = $prospection->id;
});

$prospection = computed(fn () => Prospection::with(['commercial', 'site.ville'])->findOrFail($this->prospectionId));

$historique = computed(fn () => $this->prospection->activities()->with('causer')->latest('id')->get());

$libellesChamps = computed(fn () => [
    'client' => 'Client', 'localisation' => 'Localisation',
    'immatriculation' => 'Immatriculation', 'n_fiche_reception' => 'N° de fiche de réception',
    'moyen' => 'Moyen',
    'activite' => 'Activité', 'commercial_id' => 'Commercial',
    'passage' => 'Passage', 'date_passage' => 'Date de passage',
    'devis_apres_passage' => 'Devis après passage', 'date_devis' => 'Date du devis',
    'observations' => 'Observations', 'commentaire' => 'Commentaire au responsable',
    'statut_validation' => 'Statut', 'motif_refus' => 'Motif de refus',
]);

$formaterValeur = computed(fn () => fn ($v) => match (true) {
    is_bool($v) => $v ? 'Oui' : 'Non',
    $v === null => '—',
    default => (string) $v,
});

?>

<div>
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:23px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:0;">
            Prospection {{ $this->prospection->numero }}
        </h1>
        {{-- Le retour va à la **liste** des prospections, et non à l'écran de saisie :
             on arrive ici depuis une liste, et c'est là qu'on veut revenir pour ouvrir la
             suivante. Renvoyer vers la saisie obligeait à refaire le chemin à chaque fiche.
             « Prospects » est la bonne liste pour les quatre rôles qui peuvent ouvrir cette
             fiche : c'est exactement la même habilitation. --}}
        <a href="{{ route('prospects') }}" wire:navigate class="bouton bouton-secondaire">← Retour à la liste des prospections</a>
    </div>

    {{-- Qui a tranché, quand, d'où : le même bloc que sur la fiche du commercial. --}}
    <x-tracabilite-decision :prospection="$this->prospection" style="margin-bottom:14px;" />

    <x-carte-section titre="Détail">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px 20px;">
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">N°</span><b>{{ $this->prospection->numero }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Date</span><b>{{ $this->prospection->date->format('d/m/Y') }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Site</span><b>{{ $this->prospection->site->nom }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Client</span><b>{{ $this->prospection->client }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Localisation</span><b>{{ $this->prospection->localisation ?? '—' }}</b></div>
            {{-- Le véhicule visé : c'est par lui que le devis retrouvera cette visite. --}}
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Véhicule</span><b>{{ $this->prospection->immatriculation ?? '—' }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">N° de fiche</span><b>{{ $this->prospection->n_fiche_reception ?? '—' }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Moyen</span><b>{{ $this->prospection->moyen }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Commercial</span><b>{{ $this->prospection->commercial->nom }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Activité</span><b>{{ $this->prospection->activite }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Statut</span><b>{{ $this->prospection->statut_validation }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Passage</span><b>{{ $this->prospection->passage ? 'Oui — '.$this->prospection->date_passage?->format('d/m/Y') : 'Non' }}</b></div>
            <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Devis après passage</span><b>{{ $this->prospection->devis_apres_passage ? 'Oui — '.$this->prospection->date_devis?->format('d/m/Y') : 'Non' }}</b></div>
            @if ($this->prospection->statut_validation === 'Refusée' && $this->prospection->motif_refus)
                <div><span style="font-size:11.5px; color:#9A9DA5; display:block;">Motif de refus</span><b style="color:var(--th-accent,#C8102E);">{{ $this->prospection->motif_refus }}</b></div>
            @endif
            <div style="grid-column:1/-1;"><span style="font-size:11.5px; color:#9A9DA5; display:block;">Observations</span><b>{{ $this->prospection->observations ?? '—' }}</b></div>

            {{-- Le commentaire du commercial. Il était saisi à l'autre bout et n'arrivait
                 nulle part : la colonne n'existait pas. C'est pourtant lui qui explique la
                 ligne — « affluence en baisse, pluies » n'appelle pas le même arbitrage
                 qu'un client qui n'a pas répondu. --}}
            @if ($this->prospection->commentaire)
                <div style="grid-column:1/-1; background:#FFFBEA; border:1px dashed #E2E0D8; border-radius:8px; padding:10px 12px;">
                    <span style="font-size:11.5px; color:#9A9DA5; display:block;">
                        Commentaire du commercial à votre attention
                    </span>
                    <b style="white-space:pre-line;">{{ $this->prospection->commentaire }}</b>
                </div>
            @endif

            {{-- Les informations libres ajoutées sur la ligne, y compris après transmission :
                 c'est du renseignement qui arrive, et il doit arriver jusqu'ici. --}}
            @php $libres = $this->prospection->donneesLibres; @endphp
            @if ($libres->isNotEmpty())
                <div style="grid-column:1/-1;">
                    <span style="font-size:11.5px; color:#9A9DA5; display:block; margin-bottom:4px;">
                        Informations libres
                    </span>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        @foreach ($libres as $donnee)
                            <span class="pastille pastille-bleu" style="font-weight:600;">
                                {{ $donnee->intitule }} : {{ $donnee->valeur ?? '—' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </x-carte-section>

    <x-carte-section titre="Historique des modifications" icone="liste" couleur="#2A2E35">
        @if ($this->historique->isEmpty())
            <p style="font-size:13px; color:#9A9DA5; margin:0;">Aucune modification depuis la création.</p>
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Date</th><th>Par</th><th>Champ</th><th>Avant</th><th>Après</th></tr></thead>
                    <tbody>
                        @foreach ($this->historique as $entree)
                            @php $avant = $entree->properties->get('old', []); $apres = $entree->properties->get('attributes', []); @endphp
                            @foreach ($apres as $champ => $valeur)
                                <tr>
                                    <td>{{ $entree->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $entree->causer?->name ?? '—' }}</td>
                                    <td>{{ $this->libellesChamps[$champ] ?? $champ }}</td>
                                    <td style="color:#6B6E76;">{{ ($this->formaterValeur)($avant[$champ] ?? null) }}</td>
                                    <td style="font-weight:600;">{{ ($this->formaterValeur)($valeur) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-carte-section>
</div>
