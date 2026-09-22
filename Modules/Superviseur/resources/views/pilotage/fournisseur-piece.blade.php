<?php

use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\{computed, mount, state};

/**
 * Une pièce fournisseur, en entier, sur sa propre page.
 *
 * **Pourquoi une page.** Le suivi fournisseur porte une quarantaine de colonnes ; le tableau
 * d'ensemble en montre dix, celles qui servent à décider. Les trente autres existent — la
 * refacturation, la marge, les quantités, le n° de chèque, les commentaires — et n'étaient
 * visibles nulle part. Une adresse propre se rouvre dans un autre onglet, se met en favori
 * et se transmet : c'est le geste qu'on fait devant une facture qu'on discute avec son
 * fournisseur.
 *
 * **Le périmètre est vérifié ici, pas au tableau.** L'identifiant vient de l'adresse, donc
 * de n'importe qui. Une pièce hors du périmètre du compte répond comme une pièce qui
 * n'existe pas, sans laisser entendre qu'elle existe ailleurs.
 *
 * **Ce qui se corrige, et ce qui ne se corrige pas.** Une ligne venue d'un fichier garde
 * quatre champs verrouillés — fournisseur, n° de pièce, date et montant — parce qu'ils
 * forment sa clé d'import : les retoucher ferait qu'un prochain dépôt du même fichier ne
 * reconnaîtrait plus la ligne et la recréerait. La dette compterait double, et rien ne le
 * dirait. Une ligne saisie à la main n'a pas cette contrainte : aucun fichier ne viendra la
 * revendiquer.
 */
state(['id' => null, 'enModification' => false]);

state([
    'fournisseur' => '', 'numeroPiece' => '', 'naturePiece' => '',
    'dateFacture' => '', 'dateEcheance' => '', 'dateReglement' => '',
    'montant' => '', 'montantRegle' => '',
    'modeReglement' => '', 'imputation' => '', 'immatriculation' => '',
    'numeroFacture' => '', 'observations' => '',
]);

mount(function (int $piece) {
    $this->id = $piece;
});

$piece = computed(function () {
    return EtatDesFournisseurs::dansLePerimetre(
        FactureFournisseur::query(),
        PerimetreSites::idsVillesRetenus(auth()->user(), ''),
    )->with(['ville', 'site', 'lot', 'auteur'])->find((int) $this->id);
});

$peutEcrire = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

$verrouilles = computed(fn () => $this->piece === null
    ? []
    : EtatDesFournisseurs::champsVerrouilles($this->piece));

$historique = computed(fn () => $this->piece === null ? collect() : Activity::query()
    ->with('causer')
    ->where('subject_type', $this->piece->getMorphClass())
    ->where('subject_id', $this->piece->id)
    ->latest('id')
    ->limit(30)
    ->get());

$modifier = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user()) || $this->piece === null) {
        abort(403);
    }

    $p = $this->piece;
    $this->fournisseur = (string) $p->fournisseur;
    $this->numeroPiece = (string) $p->numero_piece;
    $this->naturePiece = (string) $p->nature_piece;
    $this->dateFacture = $p->date_facture?->format('Y-m-d') ?? '';
    $this->dateEcheance = $p->date_echeance?->format('Y-m-d') ?? '';
    $this->dateReglement = $p->date_reglement?->format('Y-m-d') ?? '';
    $this->montant = (string) (int) $p->montant;
    $this->montantRegle = (string) (int) $p->montant_regle;
    $this->modeReglement = (string) $p->mode_reglement;
    $this->imputation = (string) $p->imputation;
    $this->immatriculation = (string) $p->immatriculation;
    $this->numeroFacture = (string) $p->numero_facture_client;
    $this->observations = (string) $p->observations;
    $this->enModification = true;
};

$annuler = function () {
    $this->enModification = false;
    $this->resetValidation();
};

$enregistrer = function () {
    /*
     * L'autorisation se revérifie ici, et non seulement à la route.
     *
     * Une route protégée ne protège que l'entrée : l'action, elle, est appelable par tout
     * ce qui sait parler à Livewire. Et le périmètre se relit à chaque geste, sur
     * l'identité du lecteur — jamais sur l'identifiant reçu.
     */
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $p = $this->piece;

    if ($p === null) {
        abort(404);
    }

    $verrouilles = EtatDesFournisseurs::champsVerrouilles($p);

    $regles = [
        'naturePiece' => ['nullable', 'string', 'max:60'],
        'dateEcheance' => ['nullable', 'date'],
        'dateReglement' => ['nullable', 'date'],
        'montantRegle' => ['required', 'integer', 'min:0'],
        'modeReglement' => ['nullable', 'string', 'max:200'],
        'imputation' => ['nullable', 'string', 'max:120'],
        'immatriculation' => ['nullable', 'string', 'max:40'],
        'numeroFacture' => ['nullable', 'string', 'max:60'],
        'observations' => ['nullable', 'string', 'max:2000'],
    ];

    if (! in_array('fournisseur', $verrouilles, true)) {
        $regles['fournisseur'] = ['required', 'string', 'max:200'];
        $regles['numeroPiece'] = ['required', 'string', 'max:60'];
        $regles['dateFacture'] = ['required', 'date'];
        $regles['montant'] = ['required', 'integer', 'min:1'];
    }

    $donnees = $this->validate($regles);

    $montant = in_array('montant', $verrouilles, true)
        ? (int) $p->montant
        : (int) $donnees['montant'];

    // Un règlement supérieur au montant dû est refusé à la frappe : dans le classeur,
    // 29 lignes le font, et le trop-perçu vient en déduction du total — il masque donc la
    // dette d'autres fournisseurs.
    if ((int) $donnees['montantRegle'] > $montant) {
        $this->addError('montantRegle', 'Le réglé ne peut pas dépasser le montant de la facture.');

        return;
    }

    $valeurs = [
        'nature_piece' => $donnees['naturePiece'] ?: null,
        'date_echeance' => $donnees['dateEcheance'] ?: null,
        'date_reglement' => $donnees['dateReglement'] ?: null,
        'montant_regle' => (int) $donnees['montantRegle'],
        // Le reste n'est pas saisi : il se déduit, comme partout ailleurs. Une colonne
        // qu'on peut contredire à la main finit toujours par l'être.
        'reste_a_payer' => $montant - (int) $donnees['montantRegle'],
        'mode_reglement' => $donnees['modeReglement'] ?: null,
        'imputation' => $donnees['imputation'] ?: null,
        'immatriculation' => $donnees['immatriculation'] ?: null,
        'numero_facture_client' => $donnees['numeroFacture'] ?: null,
        'observations' => $donnees['observations'] ?: null,
    ];

    if ($verrouilles === []) {
        $valeurs += [
            'fournisseur' => $donnees['fournisseur'],
            'numero_piece' => $donnees['numeroPiece'],
            'date_facture' => $donnees['dateFacture'],
            'montant' => $montant,
        ];
    }

    $avant = $p->only(array_keys($valeurs));
    $p->fill($valeurs)->save();

    activity()
        ->performedOn($p)
        ->causedBy(auth()->user())
        ->withProperties(['avant' => $avant, 'apres' => $valeurs])
        ->log('Pièce fournisseur corrigée');

    $this->enModification = false;
    unset($this->piece, $this->historique);

    session()->flash('message', 'La pièce est à jour.');
};

?>

<div>
    @if (! $this->piece)
        <x-carte-section titre="Pièce introuvable" icone="atelier" couleur="#C8102E">
            <p style="margin:0 0 14px; color:#4B4E55;">
                Cette pièce n'existe pas, ou elle relève d'un lieu qui n'est pas dans votre périmètre.
            </p>
            <a href="{{ route('fournisseurs') }}" wire:navigate
               style="display:inline-block; padding:7px 14px; border-radius:7px; background:#191B20; color:#fff;
                      text-decoration:none; font-size:13px; font-weight:700;">Retour aux fournisseurs</a>
        </x-carte-section>
    @else
        @php
            $p = $this->piece;
            $jours = $p->date_facture ? NombreDeJours::entre($p->date_facture, now()) : null;
            $reste = (int) $p->reste_a_payer;
        @endphp

        <x-titre-ecran :titre="$p->fournisseur ?: 'Pièce fournisseur'"
            :sous-titre="'Pièce '.($p->numero_piece ?: '—').' — '.EtatDesFournisseurs::origine($p)">
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Retour au suivi</a>
                @if ($this->peutEcrire && ! $enModification)
                    <button type="button" wire:click="modifier" class="bouton">Modifier</button>
                @endif
            </div>
        </x-titre-ecran>

        @if (session('message'))
            <div class="carte" style="margin-bottom:16px; border-left:3px solid #0E9F6E;">
                <p style="margin:0; font-size:13px; color:#1E7B34; font-weight:600;">{{ session('message') }}</p>
            </div>
        @endif

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Montant de la facture" :value="ae((int) $p->montant)" />
            <x-kpi-card label="Déjà réglé" :value="ae((int) $p->montant_regle)" couleur="#0E9F6E" />
            <x-kpi-card label="Reste à payer" :value="ae($reste)"
                :couleur="$reste > 0 ? '#C8102E' : '#6B6E76'"
                :sub="$reste > 0 ? 'Dette ouverte' : 'Pièce soldée'" />
            <x-kpi-card label="Ancienneté"
                :value="$jours === null ? '—' : number_format((int) $jours, 0, ',', ' ').' j'"
                :sub="$p->date_echeance
                    ? 'Échéance au '.$p->date_echeance->format('d/m/Y')
                    : 'Le fichier ne porte pas d\'échéance pour cette pièce'"
                :accent="$reste > 0 && $p->date_echeance?->isPast()" />
        </div>

        @if ($enModification)
            <div class="carte" style="margin-bottom:16px;">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Corriger la pièce</h3>

                @if ($this->verrouilles !== [])
                    {{-- On dit pourquoi, et pas seulement que c'est interdit : un champ grisé
                         sans explication passe pour une panne. --}}
                    <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                        Fournisseur, n° de pièce, date et montant viennent du fichier et forment sa clé :
                        les corriger ici ferait recréer la ligne au prochain dépôt. Corrigez-les dans le
                        fichier, et redéposez-le.
                    </p>
                @endif

                <form wire:submit="enregistrer">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px;">
                        @if ($this->verrouilles === [])
                            <x-champ label="Fournisseur" model="fournisseur" requis />
                            <x-champ label="N° de pièce" model="numeroPiece" requis />
                            <x-champ label="Date de facture" model="dateFacture" type="date" requis />
                            <x-champ label="Montant" model="montant" type="number" requis />
                        @endif
                        <x-champ label="Nature de la pièce" model="naturePiece" />
                        <x-champ label="Échéance" model="dateEcheance" type="date" />
                        <x-champ label="Date de règlement" model="dateReglement" type="date" />
                        <x-champ label="Déjà réglé" model="montantRegle" type="number" requis />
                        <x-champ label="Mode de règlement" model="modeReglement" />
                        <x-champ label="Imputation" model="imputation" />
                        <x-champ label="Immatriculation" model="immatriculation" />
                        <x-champ label="N° de facture client" model="numeroFacture" />
                    </div>

                    <div style="margin-top:12px;">
                        <x-champ label="Observations" model="observations" type="textarea" />
                    </div>

                    <div style="display:flex; gap:9px; margin-top:14px;">
                        <button type="submit" class="bouton">Enregistrer</button>
                        <button type="button" wire:click="annuler" class="bouton bouton-secondaire">Annuler</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Ce que le fichier dit de cette pièce</h3>

            @php
                /*
                 * Toutes les colonnes, y compris vides — et c'est voulu sur cette page.
                 *
                 * Le tableau d'ensemble cache ce que la source ne porte pas ; ici on répond à
                 * « qu'est-ce qu'on sait de cette pièce ? », et « rien » est une réponse.
                 */
                $blocs = [
                    'Identité' => [
                        'Fournisseur' => $p->fournisseur,
                        'N° de pièce' => $p->numero_piece,
                        'Nature' => $p->nature_piece,
                        'N° de bon de commande' => $p->numero_bc,
                        'N° de facture client' => $p->numero_facture_client,
                        'N° FEB' => $p->numero_feb,
                        'Code pièce' => $p->code_piece,
                    ],
                    'Dates' => [
                        'Facture' => $p->date_facture?->format('d/m/Y'),
                        'Réception' => $p->date_reception?->format('d/m/Y'),
                        'Échéance' => $p->date_echeance?->format('d/m/Y'),
                        'Règlement' => $p->date_reglement?->format('d/m/Y'),
                        'Délai de règlement' => $p->delai_reglement,
                        'Arrivé à échéance' => $p->arrive_a_echeance,
                    ],
                    'Montants' => [
                        'Montant' => $p->montant === null ? null : ae((int) $p->montant),
                        'Montant HT' => $p->montant_ht === null ? null : ae((int) $p->montant_ht),
                        'TVA' => $p->tva === null ? null : ae((int) $p->tva),
                        'Réglé' => $p->montant_regle === null ? null : ae((int) $p->montant_regle),
                        'Reste à payer' => ae((int) $p->reste_a_payer),
                        'Mode de règlement' => $p->mode_reglement,
                        'N° de chèque' => $p->numero_cheque,
                    ],
                    'Refacturation' => [
                        'Montant refacturé' => $p->montant_refacture === null ? null : ae((int) $p->montant_refacture),
                        'Marge' => $p->marge === null ? null : ae((int) $p->marge),
                        'Quantité totale' => $p->quantite_totale,
                        'Quantité refacturée' => $p->quantite_refacturee,
                        'Taux' => $p->taux,
                        'Résultat indicatif' => $p->resultat_indicatif === null ? null : ae((int) $p->resultat_indicatif),
                    ],
                    'Rattachement' => [
                        'Ville' => $p->ville?->nom,
                        'Atelier' => $p->site?->nom,
                        'Section' => $p->section,
                        'Imputation' => $p->imputation,
                        'Véhicule' => $p->vehicule,
                        'Immatriculation' => $p->immatriculation,
                        'N° de fiche' => $p->numero_fiche,
                        'Type de transaction' => $p->type_transaction,
                    ],
                ];
            @endphp

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:18px;">
                @foreach ($blocs as $titre => $champs)
                    <div>
                        <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
                                    color:#6B6E76; margin-bottom:7px;">{{ $titre }}</div>
                        @foreach ($champs as $intitule => $valeur)
                            <div style="display:flex; justify-content:space-between; gap:12px; padding:3px 0;
                                        border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:12.5px;">
                                <span style="color:#6B6E76;">{{ $intitule }}</span>
                                <b style="text-align:right; font-variant-numeric:tabular-nums;">{{ $valeur !== null && $valeur !== '' ? $valeur : '—' }}</b>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            @if ($p->observations || $p->commentaires || $p->actions_a_mener)
                <div style="margin-top:16px;">
                    @foreach (['Observations' => $p->observations, 'Commentaires' => $p->commentaires, 'Actions à mener' => $p->actions_a_mener] as $titre => $texte)
                        @if ($texte)
                            <div style="margin-bottom:10px;">
                                <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
                                            color:#6B6E76; margin-bottom:4px;">{{ $titre }}</div>
                                <p style="margin:0; font-size:13px; color:#4B4E55; white-space:pre-line;">{{ $texte }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        @if ($this->historique->isNotEmpty())
            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Ce qui a été fait sur cette pièce</h3>
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr><th>Quand</th><th>Qui</th><th>Quoi</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->historique as $trace)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                    <td style="white-space:nowrap;">{{ $trace->created_at?->format('d/m/Y H:i') }}</td>
                                    <td>{{ $trace->causer?->name ?? '—' }}</td>
                                    <td>{{ $trace->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
