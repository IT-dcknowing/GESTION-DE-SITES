<?php

use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;

use function Livewire\Volt\{computed, state};

/**
 * Ce que l'entreprise doit à ses fournisseurs, et pour quand.
 *
 * **Pourquoi cet écran existe.** Le fichier « Suivi fournisseurs » était lu, ses mille huit
 * cent quarante-huit factures étaient en base — et aucun écran ne les montrait. Toute
 * l'application regardait ce qu'on nous doit ; personne ne regardait ce que nous devons.
 * Un compte d'exploitation qui ne voit qu'un côté de la balance n'est pas un compte
 * d'exploitation.
 *
 * **L'échéance existe désormais, et pour une partie des lignes seulement.** On avait écrit
 * ici que le fichier n'en portait aucune — c'était vrai de l'onglet qu'on lisait alors, et
 * faux du classeur. Depuis le 22/09, la feuille « DETAIL » est lue en entier : sur 7 350
 * lignes reprises, **1 480 portent une date d'échéance**. L'écran l'affiche donc, en disant
 * combien de lignes la connaissent — une pièce sans échéance n'est pas une pièce à jour.
 *
 * L'ancienneté depuis la date de facture reste affichée à côté, et c'est elle qui trie :
 * c'est la seule date que toutes les lignes portent. Remplacer l'une par l'autre aurait
 * fait disparaître du haut de la liste les dettes les plus vieilles, faute d'échéance.
 *
 * **Le périmètre se lit par ville.** Seule une ligne sur six porte un atelier ; les autres
 * n'ont que la ville. Filtrer par atelier aurait vidé l'écran de cinq lignes sur six, ce
 * qui aurait ressemblé à une panne alors que c'est le fichier qui ne le dit pas.
 *
 * Aucune période n'est proposée, et c'est délibéré : une dette ne s'arrête pas au
 * 31 décembre. On regarde ce qui reste dû aujourd'hui, quelle que soit la date de la
 * facture qui l'a créée.
 */
state([
    'villeFiltre' => '',
    'etatFiltre' => 'ouvertes',
    'recherche' => '',
    'pageDetail' => 1,
]);

$updatedVilleFiltre = function () { $this->pageDetail = 1; };
$updatedEtatFiltre = function () { $this->pageDetail = 1; };
$updatedRecherche = function () { $this->pageDetail = 1; };

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

/** Le périmètre, sans le filtre d'état : les totaux du bandeau ne doivent pas en dépendre. */
$perimetre = computed(fn () => FactureFournisseur::query()->whereIn('ville_id', $this->idsVilles));

$requete = computed(function () {
    $requete = (clone $this->perimetre)
        ->when(trim($this->recherche) !== '', function ($q) {
            $terme = '%'.trim($this->recherche).'%';

            $q->where(fn ($sous) => $sous->where('fournisseur', 'like', $terme)
                ->orWhere('numero_piece', 'like', $terme)
                ->orWhere('immatriculation', 'like', $terme)
                ->orWhere('imputation', 'like', $terme));
        });

    return match ($this->etatFiltre) {
        'ouvertes' => $requete->where('reste_a_payer', '>', 0),
        'anciennes' => $requete->where('reste_a_payer', '>', 0)
            ->whereDate('date_facture', '<', now()->subDays(90)),
        // Échue veut dire : l'échéance est connue, et elle est passée. Une ligne sans
        // échéance n'y figure pas — on ne la déclare ni à jour ni en retard.
        'echues' => $requete->where('reste_a_payer', '>', 0)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now()),
        'soldees' => $requete->where('reste_a_payer', '<=', 0),
        default => $requete,
    };
});

$kpis = computed(fn () => [
    'facture' => (int) (clone $this->perimetre)->sum('montant'),
    'regle' => (int) (clone $this->perimetre)->sum('montant_regle'),
    /*
     * La dette ne compte que les restes positifs.
     *
     * Six pièces portent un reste négatif, pour −312 737 F : ce sont des trop-payés ou des
     * avoirs, et ils ne s'effacent pas tout seuls contre ce qu'on doit à d'autres. Les
     * additionner faisait afficher une dette inférieure à la somme des factures réellement
     * ouvertes — un total plus petit que l'une de ses parts, ce qui ne peut que passer pour
     * une erreur de calcul. Le trop-payé est compté à part, là où on peut le réclamer.
     */
    'reste' => (int) (clone $this->perimetre)->where('reste_a_payer', '>', 0)->sum('reste_a_payer'),
    'avoirs' => (int) abs((clone $this->perimetre)->where('reste_a_payer', '<', 0)->sum('reste_a_payer')),
    // L'ancienneté se compte depuis la date de facture : le fichier ne porte aucune
    // échéance, et une dette de plus de trois mois est le seul signal disponible.
    'ancien' => (int) (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereDate('date_facture', '<', now()->subDays(90))
        ->sum('reste_a_payer'),
    'plusAncienne' => (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->min('date_facture'),
    'ouvertes' => (clone $this->perimetre)->where('reste_a_payer', '>', 0)->count(),
    'fournisseurs' => (clone $this->perimetre)->distinct()->count('fournisseur'),

    /*
     * L'échu, et ce qu'on en sait.
     *
     * Les deux nombres vont ensemble : « 12 M échus » ne veut rien dire sans « sur les
     * 1 480 pièces dont l'échéance est connue ». Annoncer le premier seul laisserait croire
     * que le reste est à jour, alors qu'il est simplement sans date.
     */
    'echu' => (int) (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereNotNull('date_echeance')->whereDate('date_echeance', '<', now())
        ->sum('reste_a_payer'),
    'avecEcheance' => (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereNotNull('date_echeance')->count(),
]);

/**
 * À qui l'on doit le plus : c'est par là qu'une négociation de délai commence.
 *
 * **Le déjà payé y figure, et ce n'est pas un ornement.** Le tableau ne montrait que la
 * dette. Or on n'aborde pas de la même façon un fournisseur à qui l'on doit deux millions
 * sur trois millions engagés et un autre à qui l'on doit les deux millions d'une première
 * commande : le premier est un compte qui tourne, le second un compte qui s'installe. Le
 * chiffre existait dans le détail d'un fournisseur ; il manquait là où l'on décide.
 *
 * Le réglé est additionné sur **toutes** les pièces du fournisseur, y compris soldées,
 * quand la dette ne compte que les pièces ouvertes : c'est ce que « déjà payé » veut dire.
 * D'où la jointure sur une seconde lecture plutôt qu'une colonne de plus dans la première.
 */
$principaux = computed(function () {
    $dus = (clone $this->perimetre)
        ->where('reste_a_payer', '>', 0)
        ->selectRaw('fournisseur, count(*) as pieces, sum(reste_a_payer) as du')
        ->groupBy('fournisseur')->orderByDesc('du')->limit(10)->get();

    if ($dus->isEmpty()) {
        return $dus;
    }

    $regles = (clone $this->perimetre)
        ->whereIn('fournisseur', $dus->pluck('fournisseur')->all())
        ->selectRaw('fournisseur, sum(montant_regle) as regle, sum(montant) as engage')
        ->groupBy('fournisseur')->get()->keyBy('fournisseur');

    return $dus->each(function ($ligne) use ($regles) {
        $ligne->regle = (int) ($regles[$ligne->fournisseur]->regle ?? 0);
        $ligne->engage = (int) ($regles[$ligne->fournisseur]->engage ?? 0);
    });
});

$detail = computed(fn () => (clone $this->requete)
    ->with('ville')
    // La plus vieille dette en tête : faute d'échéance dans le fichier, c'est
    // l'ancienneté de la facture qui dit ce qui presse.
    ->orderBy('date_facture')
    ->paginate(25, ['*'], 'pageDetail', $this->pageDetail));

?>

<div>
    <x-titre-ecran titre="Fournisseurs"
        sous-titre="Ce que l'entreprise doit, à qui, et depuis combien de temps — repris du suivi fournisseurs.">
        {{-- Les deux exports du logiciel comptable s'ouvrent d'ici : c'est la page où l'on
             se pose la question, et c'est donc là que doivent être les réponses. --}}
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance fournisseurs</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements fournisseurs</a>
        </div>
    </x-titre-ecran>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Reste à payer — {{ $this->libellePerimetre }}" :value="ae($this->kpis['reste'])"
            couleur="#C8102E" :sub="$this->kpis['ouvertes'].' facture(s) ouverte(s) · '.$this->kpis['fournisseurs'].' fournisseur(s)'
                .($this->kpis['avoirs'] > 0 ? ' · '.ae($this->kpis['avoirs']).' de trop-payé à réclamer' : '')" />
        <x-kpi-card label="Dû depuis plus de 90 jours" :value="ae($this->kpis['ancien'])"
            :accent="$this->kpis['ancien'] > 0"
            :sub="$this->kpis['plusAncienne']
                ? 'La plus ancienne date du '.\Illuminate\Support\Carbon::parse($this->kpis['plusAncienne'])->format('d/m/Y')
                : 'Aucune pièce ouverte'" />
        <x-kpi-card label="Échu" :value="ae($this->kpis['echu'])"
            :accent="$this->kpis['echu'] > 0"
            :sub="$this->kpis['avecEcheance'] > 0
                ? 'Sur les '.$this->kpis['avecEcheance'].' pièce(s) ouverte(s) dont l\'échéance est connue'
                : 'Aucune pièce ouverte ne porte d\'échéance'" />
        <x-kpi-card label="Total facturé" :value="ae($this->kpis['facture'])" sub="Toutes pièces reçues" />
        <x-kpi-card label="Déjà réglé" :value="ae($this->kpis['regle'])" couleur="#0E9F6E" />
    </div>

    @if ($this->mesVilles !== null && $this->mesVilles->count() > 1)
        <div class="carte" style="margin-bottom:16px; padding:12px 15px;">
            <label for="ville-fourn" style="font-size:12.5px; font-weight:600; color:#4B4E55; margin-right:9px;">Ville</label>
            <select id="ville-fourn" wire:model.live="villeFiltre" class="champ" style="max-width:280px;">
                <option value="" @selected($villeFiltre === '')>Toutes les villes</option>
                {{-- Cette liste rend des villes, pas des paires identifiant/nom : la parcourir
                     comme un tableau associatif affichait chaque ville sous sa forme JSON
                     complète, coordonnées et couleur comprises. --}}
                @foreach ($this->mesVilles as $ville)
                    <option value="{{ $ville->id }}" @selected((string) $villeFiltre === (string) $ville->id)>{{ $ville->nom }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($this->principaux->isNotEmpty())
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">À qui nous devons le plus</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th style="text-align:right;">Pièces ouvertes</th>
                            <th style="text-align:right;">Déjà payé</th>
                            <th style="text-align:right;">Reste à payer</th>
                            <th style="text-align:right;">Part de la dette</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->principaux as $f)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $f->fournisseur ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $f->pieces }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">
                                    {{ ae((int) $f->regle) }}
                                    @if ($f->engage > 0)
                                        {{-- La part réglée dit d'un coup d'œil si le compte tourne
                                             ou s'il s'installe. --}}
                                        <div style="font-size:11px; color:#6B6E76;">{{ round($f->regle / $f->engage * 100) }} % de l'engagé</div>
                                    @endif
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae((int) $f->du) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                    {{ $this->kpis['reste'] > 0 ? round($f->du / $this->kpis['reste'] * 100) : 0 }} %
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                Factures reçues ({{ number_format($this->detail->total(), 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Fournisseur, n° de pièce, immatriculation…" class="champ" style="min-width:280px;">

                <select wire:model.live="etatFiltre" class="champ">
                    <option value="ouvertes" @selected($etatFiltre === 'ouvertes')>Reste à payer</option>
                    <option value="anciennes" @selected($etatFiltre === 'anciennes')>Dues depuis plus de 90 jours</option>
                    <option value="echues" @selected($etatFiltre === 'echues')>Échues</option>
                    <option value="soldees" @selected($etatFiltre === 'soldees')>Soldées</option>
                    <option value="toutes" @selected($etatFiltre === 'toutes')>Toutes</option>
                </select>

                {{-- Les filtres voyagent dans l'adresse du lien : le fichier emporté contient
                     exactement ce que le tableau montre, et le lien se transmet tel quel. --}}
                <x-telecharger route="fournisseurs.telecharger"
                    :parametres="['ville' => $villeFiltre, 'etat' => $etatFiltre, 'recherche' => $recherche]" />
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Fournisseur</th>
                        <th>N° pièce</th>
                        <th>Facture</th>
                        <th>Échéance</th>
                        <th>Ancienneté</th>
                        <th>Section</th>
                        <th>Imputation</th>
                        <th style="text-align:right;">Montant</th>
                        <th style="text-align:right;">Réglé</th>
                        <th style="text-align:right;">Reste</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail as $ligne)
                        @php
                            $jours = $ligne->date_facture
                                ? NombreDeJours::entre($ligne->date_facture, now())
                                : null;
                            $vieille = $ligne->reste_a_payer > 0 && $jours !== null && $jours > 90;
                            $echue = $ligne->reste_a_payer > 0 && $ligne->date_echeance?->isPast();
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>{{ $ligne->fournisseur ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->numero_piece ?: '—' }}</td>
                            <td style="white-space:nowrap;">{{ $ligne->date_facture?->format('d/m/Y') ?? '—' }}</td>
                            {{-- Un tiret dit « le fichier ne le sait pas », jamais « à jour ». --}}
                            <td style="white-space:nowrap; {{ $echue ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                {{ $ligne->date_echeance?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td style="white-space:nowrap; {{ $vieille ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                {{ $jours === null ? '—' : number_format((int) $jours, 0, ',', ' ').' j' }}
                            </td>
                            <td style="color:#6B6E76;">{{ $ligne->section ?: '—' }}</td>
                            <td style="color:#6B6E76;">
                                {{ $ligne->imputation ?: ($ligne->immatriculation ?: '—') }}
                                @if ($ligne->vehicule)
                                    <div style="font-size:11px;">{{ $ligne->vehicule }}{{ $ligne->immatriculation ? ' · '.$ligne->immatriculation : '' }}</div>
                                @endif
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae((int) $ligne->montant) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ ae((int) $ligne->montant_regle) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne->reste_a_payer > 0 ? '#C8102E' : '#6B6E76' }};">
                                {{ ae((int) $ligne->reste_a_payer) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="10"
                            texte="Aucune facture fournisseur pour ce filtre. Le suivi fournisseurs se dépose depuis le module Import." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:12px;">{{ $this->detail->links() }}</div>
    </div>
</div>
