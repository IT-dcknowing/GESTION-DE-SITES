<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Fiche d'une prospection — et la signature de la décision prise dessus
|--------------------------------------------------------------------------
| **Ce que cette page ajoute.** Le tableau des prospections disait « Validée » et
| s'arrêtait là. Le commercial voyait sa ligne passer au vert sans savoir qui l'avait
| tranchée ni quand, et n'avait donc personne à qui s'adresser quand le devis promis
| tardait. Ici figurent le nom, la date, l'heure, l'adresse réseau, le poste et l'écran
| d'où la décision est partie.
|
| **Sur le « poste ».** Un serveur web ne connaît pas le nom de la machine qui l'appelle :
| rien dans une requête HTTP ne le porte. Ce qui est gardé est ce que le navigateur
| déclare — son nom et celui du système. La page l'écrit ainsi plutôt que d'appeler
| « poste » ce qui n'en est pas un.
|
| **Qui peut ouvrir cette page.** Le commercial, et sur ses propres lignes seulement. La
| règle est appliquée au chargement, avant tout affichage : une adresse se tape à la main.
| Le responsable a sa propre fiche, qui porte en plus l'historique des modifications — mais
| les deux montrent la même traçabilité, par le même composant.
*/

state(['id' => 0]);

mount(function (int $prospection) {
    $this->id = $prospection;

    // Relu tout de suite : mieux vaut un refus franc qu'une page qui se construit à moitié.
    abort_if($this->fiche === null, 404);
});

$fiche = computed(function () {
    $prospection = Prospection::with(['commercial', 'site.ville', 'validateur', 'donneesLibres'])
        ->find($this->id);

    if ($prospection === null) {
        return null;
    }

    // Le commercial ne sort pas de ses propres lignes. Le responsable a déjà sa fiche,
    // qui porte en plus l'historique des modifications : deux écrans pour deux métiers,
    // et un seul bloc de traçabilité, partagé.
    $sien = Commercial::where('user_id', auth()->id())->value('id');

    return (int) $prospection->commercial_id === (int) $sien ? $prospection : null;
});

$retour = computed(fn () => route('mes-prospections'));

?>

<div style="max-width:940px; margin:0 auto;">
    @php
        $p = $this->fiche;
        $pastille = [
            'Brouillon' => 'pastille-ambre', 'Transmise' => 'pastille-bleu',
            'Validée' => 'pastille-vert', 'Refusée' => 'pastille-rouge',
        ][$p->statut_validation] ?? 'pastille-ambre';
    @endphp

    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="font-size:22px; font-weight:800; margin:0 0 4px;">
                Prospection {{ $p->numero }}
                <span class="pastille {{ $pastille }}" style="vertical-align:middle; margin-left:6px;">
                    {{ $p->statut_validation }}
                </span>
            </h1>
            <p style="color:#6B6E76; font-size:14px; margin:0;">
                {{ $p->client }} — {{ $p->date?->format('d/m/Y') }} ·
                {{ $p->site?->nom ?? 'site inconnu' }}
            </p>
        </div>

        <a href="{{ $this->retour }}" class="bouton bouton-secondaire" style="text-decoration:none;">
            ← Retour à la liste
        </a>
    </div>

    {{-- La signature de la décision, posée par le composant partagé : le responsable
         voit exactement la même chose sur sa propre fiche. --}}
    <x-tracabilite-decision :prospection="$p" style="margin-bottom:14px;" />

    {{-- ───────────────────────── la prospection elle-même ───────────────────────── --}}
    <div class="carte">
        <h2 style="font-size:16px; font-weight:800; margin:0 0 10px;">La prospection</h2>

        <table class="tableau" style="width:100%;">
            <tbody>
                <tr><th style="width:200px; text-align:left;">Numéro</th><td>{{ $p->numero }}</td></tr>
                <tr><th style="text-align:left;">Code auteur</th><td>{{ $p->code_auteur ?? '—' }}</td></tr>
                <tr><th style="text-align:left;">Commercial</th><td>{{ $p->commercial?->nom ?? '—' }}</td></tr>
                <tr><th style="text-align:left;">Date</th><td>{{ $p->date?->format('d/m/Y') }}</td></tr>
                <tr><th style="text-align:left;">Clients visités</th><td>{{ $p->client }}</td></tr>
                <tr><th style="text-align:left;">Localisation</th><td>{{ $p->localisation ?? '—' }}</td></tr>
                <tr><th style="text-align:left;">Véhicule</th><td>{{ $p->immatriculation ?? '—' }}</td></tr>
                <tr><th style="text-align:left;">N° de fiche</th><td>{{ $p->n_fiche_reception ?? '—' }}</td></tr>
                <tr><th style="text-align:left;">Moyen</th><td>{{ $p->moyen }}</td></tr>
                <tr><th style="text-align:left;">Activité</th><td>{{ $p->activite }}</td></tr>
                <tr>
                    <th style="text-align:left;">Passage</th>
                    <td>{{ $p->passage ? 'Oui' : 'Non' }}
                        @if ($p->date_passage)
                            <span style="color:#6B6E76;">le {{ $p->date_passage->format('d/m/Y') }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th style="text-align:left;">Devis après passage</th>
                    <td>{{ $p->devis_apres_passage ? 'Oui' : 'Non' }}
                        @if ($p->date_devis)
                            <span style="color:#6B6E76;">le {{ $p->date_devis->format('d/m/Y') }}</span>
                        @endif
                    </td>
                </tr>
                <tr><th style="text-align:left;">Observations</th>
                    <td style="white-space:normal;">{{ $p->observations ?? '—' }}</td></tr>
                <tr>
                    <th style="text-align:left;">Transmise le</th>
                    <td>{{ $p->transmise_le?->format('d/m/Y à H\hi') ?? 'pas encore transmise' }}</td>
                </tr>
                <tr>
                    <th style="text-align:left;">Créée le</th>
                    <td>{{ $p->created_at?->format('d/m/Y à H\hi') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
