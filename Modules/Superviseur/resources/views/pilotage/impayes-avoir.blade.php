<?php

use App\Support\SchemaDisponible;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| L'avoir, sur une ou plusieurs factures — 27/10
|--------------------------------------------------------------------------
| « Au lieu de mettre l'avoir avec les créances, un bouton Avoir : la liste des clients (avec
| filtre), puis la ou les factures sur lesquelles faire l'avoir ; les champs se préremplissent, on
| saisit le montant de l'avoir — jamais supérieur au montant des factures sélectionnées — avec un
| commentaire facultatif. Le signe négatif ne se saisit pas : il est là, visible. L'avoir diminue
| la créance, comme c'est le cas. »
|
| L'avoir reste une ligne de l'état, au montant négatif (`NatureDeLaCreance`) : c'est ainsi qu'il
| diminue ce que le client doit, partout. Il dit maintenant la facture qu'il corrige
| (`avoir_de_id`) ; c'est elle qui fixe le plafond — le montant de la facture, moins les avoirs déjà
| faits dessus. Sur plusieurs factures, le montant se répartit dans l'ordre des dates : une ligne
| d'avoir par facture, chacune à sa hauteur.
*/

state([
    'client' => '',
    'cochees' => [],
    'date' => '',
    'numero' => '',
    'montant' => '',
    'commentaire' => '',
]);

mount(function () {
    $this->garder();
    $this->date = now()->toDateString();
});

/** Faire un avoir, c'est écrire à l'état : il faut un atelier où l'écrire, comme pour une créance. */
$garder = protect(function () {
    abort_if(Site::visiblesPour(auth()->user())->isEmpty(), 403);
});

$disponible = computed(fn () => SchemaDisponible::colonne('factures', 'avoir_de_id'));

$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), '', ''));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));

/** Les créances de l'état du périmètre qui peuvent recevoir un avoir : des factures, pas des avoirs. */
$creances = protect(fn () => EtatDesImpayes::dansLePerimetre(
    Facture::query()->whereNotNull('factures.exercice_impayes')->where('factures.montant', '>', 0),
    $this->idsSites, $this->idsVilles,
));

$clients = computed(fn () => $this->creances()
    ->whereNotNull('client')->where('client', '!=', '')
    ->groupBy('client')->orderBy('client')
    ->selectRaw('client, count(*) as n')->toBase()->get()
    ->mapWithKeys(fn ($l) => [$l->client => $l->client.' — '.$l->n.' facture(s)'])->all());

/** Les factures du client, chacune avec ce que les avoirs déjà faits en ont retiré. */
$factures = computed(function () {
    if (trim((string) $this->client) === '' || ! $this->disponible) {
        return collect();
    }

    return $this->creances()->where('client', $this->client)
        ->withSum('avoirs as deja_avoir', 'montant')
        ->orderBy('date')->orderBy('id')->get()
        ->each(fn (Facture $f) => $f->setAttribute('avoirable', montant_de(max(0, montant_de($f->montant) - abs(montant_de($f->deja_avoir ?? 0))))));
});

$choisies = computed(fn () => $this->factures->whereIn('id', array_map('intval', (array) $this->cochees))->values());

/** Le plafond de l'avoir : ce qui reste avoirable sur les factures choisies. */
$plafond = computed(fn () => montant_de($this->choisies->sum('avoirable')));

/** « AV-n° de la facture », suivi d'un rang quand la facture a déjà reçu un avoir : AV-F12, AV-F12-2… */
$numeroDeLAvoir = protect(function (?Facture $facture): string {
    if ($facture === null) {
        return '';
    }

    $deja = Facture::query()->where('avoir_de_id', $facture->id)->count();

    return 'AV-'.$facture->n_facture.($deja > 0 ? '-'.($deja + 1) : '');
});

$updatedClient = function () {
    $this->cochees = [];
    $this->numero = '';
    unset($this->factures, $this->choisies, $this->plafond);
};

/** Les champs se préremplissent de la première facture choisie. */
$updatedCochees = function () {
    unset($this->choisies, $this->plafond);
    $premiere = $this->choisies->first();
    $this->numero = $premiere ? $this->numeroDeLAvoir($premiere) : '';
};

$enregistrer = function () {
    $this->garder();

    if (! $this->disponible) {
        $this->addError('montant', "La mise à jour du serveur n'est pas encore passée (migration du 27/10).");

        return;
    }

    $donnees = $this->validate([
        'client' => ['required', 'string'],
        'cochees' => ['required', 'array', 'min:1'],
        'date' => ['required', 'date'],
        // Positif à la saisie : le signe est affiché devant le champ, et posé à l'écriture.
        'montant' => ['required', 'numeric', 'gt:0'],
        'commentaire' => ['nullable', 'string', 'max:2000'],
    ], ['cochees.required' => "Cochez la ou les factures sur lesquelles porte l'avoir."],
        ['numero' => "n° de l'avoir", 'montant' => "montant de l'avoir"]);

    $choisies = $this->choisies;
    $montant = montant_de($donnees['montant']);
    // Le n° ne se saisit pas (27/10 : « le numéro de l'avoir ne doit pas être modifiable ») : il se
    // tire de la facture, relu ici — ce qu'envoie le navigateur n'y est pour rien.
    $donnees['numero'] = $this->numeroDeLAvoir($choisies->first());

    if ($choisies->isEmpty()) {
        $this->addError('cochees', "Cochez la ou les factures sur lesquelles porte l'avoir.");

        return;
    }

    if ($montant > $this->plafond) {
        $this->addError('montant', "L'avoir ne peut pas dépasser le montant des factures choisies (".ae($this->plafond).', avoirs déjà faits déduits).');

        return;
    }

    $date = Carbon::parse($donnees['date']);
    $entrepriseId = (int) auth()->user()->entreprise_id;
    $reste = $montant;
    $lignes = 0;

    DB::transaction(function () use ($choisies, $donnees, $date, $entrepriseId, &$reste, &$lignes) {
        foreach ($choisies as $facture) {
            if ($reste <= 0) {
                break;
            }

            $part = montant_de(min($reste, $facture->avoirable));

            if ($part <= 0) {
                continue;
            }

            $lignes++;
            $reste = montant_de($reste - $part);

            $avoir = Facture::create([
                'entreprise_id' => $entrepriseId,
                'site_id' => $facture->site_id,
                'ville_id' => $facture->site_id === null ? $facture->ville_id : null,
                'numero' => GenerateurNumero::suivant($entrepriseId, EtatDesImpayes::SERIE, $date),
                'n_facture' => trim($donnees['numero']).($lignes > 1 ? '-'.$lignes : ''),
                'date' => $date,
                'date_reception' => $date->toDateString(),
                'exercice_impayes' => (int) $date->format('Y'),
                'est_etat_initial' => false,
                'client' => $facture->client,
                'assureur' => $facture->assureur,
                'courtier' => $facture->courtier,
                'depose_chez' => $facture->depose_chez,
                'vehicule' => $facture->vehicule,
                'immatriculation' => $facture->immatriculation,
                'n_sinistre' => $facture->n_sinistre,
                'activite' => $facture->activite,
                'type' => 'FNE',
                'montant' => -$part,
                'est_avoir' => true,
                'avoir_de_id' => $facture->id,
                'observations' => trim('Avoir sur la facture '.$facture->n_facture.'. '.($donnees['commentaire'] ?? '')),
                'cree_par' => auth()->id(),
            ]);

            activity()->performedOn($avoir)->causedBy(auth()->user())
                ->withProperties(['facture' => $facture->id, 'n_facture' => $facture->n_facture, 'montant' => -$part])
                ->log('État des impayés — avoir saisi sur une facture');
        }
    });

    $this->dispatch('avoir-enregistre', exercice: (int) $date->format('Y'),
        texte: 'Avoir de -'.ae($montant).' enregistré'.($lignes > 1 ? ' sur '.$lignes.' factures' : '').' : la créance du client diminue d\'autant.');

    $this->reset('cochees', 'numero', 'montant', 'commentaire');
    unset($this->factures, $this->choisies, $this->plafond);
};

?>

<div class="carte" style="margin-bottom:16px; border-left:3px solid #B45309;">
    @include('components.select-cherchable-ressources')

    <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Avoir sur une ou plusieurs factures</h3>
    <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
        Choisissez le client, cochez la ou les factures ; le montant de l'avoir ne dépasse jamais celui des factures choisies
        (avoirs déjà faits déduits). Il s'inscrit en négatif et diminue ce que le client doit.
    </p>

    @if (! $this->disponible)
        <p style="margin:0; color:#B45309; font-size:13px;">La mise à jour du serveur n'est pas encore passée (migration du 27/10).</p>
    @else
        <div class="bloc-saisie" style="background:#fff; border-style:solid;">
            <div style="flex:1 1 320px; max-width:460px;">
                <x-select-cherchable id="avoir-client" label="Client" model="client" :valeur="$client"
                    :options="$this->clients" vide="— choisir le client —" placeholder="Rechercher un client…" :seuil="0" />
                @error('client') <span class="champ-erreur">{{ $message }}</span> @enderror
            </div>
        </div>

        @if (trim($client) !== '')
            <div class="tableau-conteneur" style="margin-top:12px;">
                <table class="tableau">
                    <thead>
                        <tr><th style="width:34px;"></th><th>N° facture</th><th>Date</th><th>Assureur</th>
                            <th style="text-align:right;">Montant</th><th style="text-align:right;">Avoirs déjà faits</th><th style="text-align:right;">Avoirable</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures as $f)
                            <tr wire:key="av-{{ $f->id }}">
                                <td><input type="checkbox" wire:model.live="cochees" value="{{ $f->id }}" @disabled($f->avoirable <= 0) aria-label="Choisir la facture {{ $f->n_facture }}"></td>
                                <td>{{ $f->n_facture }} <span style="font-size:11px; color:#9A9DA5;">{{ $f->numero }}</span></td>
                                <td>{{ $f->date?->format('d/m/Y') }}</td>
                                <td style="font-size:12px;">{{ $f->assureur ?: '—' }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->montant)) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->deja_avoir ?? 0)) }}</td>
                                <td style="text-align:right; white-space:nowrap; font-weight:700;">{{ ae($f->avoirable) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune facture de ce client dans votre périmètre." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            @error('cochees') <p class="champ-erreur" style="margin:6px 0 0;">{{ $message }}</p> @enderror
        @endif

        @if ($this->choisies->isNotEmpty())
            @php $premiere = $this->choisies->first(); @endphp
            <form wire:submit="enregistrer" style="margin-top:14px;">
                {{-- Ce que l'avoir reprend des factures choisies : lu, pas saisi. --}}
                <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:12.5px; color:#4B4E55; margin-bottom:10px;">
                    <span>Client : <b>{{ $premiere->client }}</b></span>
                    <span>Assureur : <b>{{ $premiere->assureur ?: '—' }}</b></span>
                    @if ($premiere->courtier)<span>Courtier : <b>{{ $premiere->courtier }}</b></span>@endif
                    <span>Factures : <b>{{ $this->choisies->pluck('n_facture')->implode(', ') }}</b></span>
                    <span>Plafond : <b>{{ ae($this->plafond) }}</b></span>
                </div>
                <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                    <x-champ label="Date de l'avoir" model="date" type="date" width="155" requis />
                    {{-- Tiré de la facture, non modifiable — 27/10. --}}
                    <x-champ label="N° de l'avoir" model="numero" width="170" :disabled="true" />
                    <div style="display:flex; flex-direction:column; width:190px;">
                        <label class="champ-libelle">Montant de l'avoir<span style="color:var(--th-accent);"> *</span></label>
                        {{-- Le signe est là, visible, et ne se tape pas : un avoir est toujours négatif. --}}
                        <div style="display:flex; align-items:stretch;">
                            <span style="display:flex; align-items:center; padding:0 10px; font-weight:800; color:#C8102E;
                                         background:#FDF2F4; border:1px solid var(--th-ligne,#E3E0D8); border-right:0; border-radius:6px 0 0 6px;">−</span>
                            <input type="number" step="any" min="0" wire:model="montant" class="champ" style="border-radius:0 6px 6px 0;" placeholder="{{ montant_nombre($this->plafond) }}">
                        </div>
                        @error('montant') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                    <x-champ label="Commentaire (facultatif)" model="commentaire" type="textarea" placeholder="Motif de l'avoir…" />
                </div>
                <div style="margin-top:10px;">
                    <button type="submit" class="bouton">Enregistrer l'avoir</button>
                </div>
            </form>
        @endif
    @endif
</div>
