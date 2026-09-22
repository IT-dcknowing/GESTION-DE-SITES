<?php

use Modules\Noyau\Exploitation\Services\PisteDeLaFiche;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\MouvementVehicule;
use Modules\Noyau\Entreprises\Support\PerimetreSites;

use function Livewire\Volt\{computed, mount, state};

/**
 * Une fiche de réception, en entier, sur sa propre page.
 *
 * **Pourquoi une page et non un volet sous le tableau.** Un volet oblige à faire défiler
 * pour lire, perd son contenu au moindre changement de page, et ne s'ouvre pas deux fois
 * côte à côte. Une adresse propre se met en favori, se rouvre dans un autre onglet, et se
 * transmet à un collègue — ce qui est le geste naturel devant une fiche d'atelier.
 *
 * **Les intitulés sont ceux du logiciel, pas les nôtres.** « MARQUE », « MODELE »,
 * « DATE THEORIQUE ATELIER » : quiconque a la fiche sous les yeux dans le logiciel d'atelier
 * doit retrouver ici les mêmes mots, dans le même ordre. Traduire aurait obligé chacun à
 * faire la correspondance de tête, et aurait fini par créer deux vocabulaires pour une seule
 * réalité. Les libellés viennent donc du format d'import lui-même : ils ne peuvent pas
 * diverger.
 */
state(['id' => null]);

mount(function (int $dossier) {
    $this->id = $dossier;
});

/**
 * La fiche, filtrée par le périmètre.
 *
 * Le contrôle est fait ici et non à l'affichage : une adresse devinée ne doit pas ouvrir la
 * fiche d'une ville qu'on n'a pas le droit de voir.
 */
$fiche = computed(function () {
    $dossier = DossierVehicule::with(['ville', 'site', 'lot'])->find($this->id);

    if ($dossier === null) {
        return null;
    }

    // Une fiche sans atelier reste lisible par qui voit sa ville : c'est justement celle
    // qu'il faut pouvoir examiner pour trancher.
    $villes = PerimetreSites::idsVillesRetenus(auth()->user(), null);

    if ($dossier->ville_id !== null && ! in_array($dossier->ville_id, $villes, true)) {
        return null;
    }

    return $dossier;
});

/**
 * Tout ce que ce numéro de fiche relie — le devis, la facture, l'entrée, la sortie.
 *
 * **C'est la seule clé commune aux fichiers du logiciel d'atelier**, et jusqu'ici chacun
 * était lu de son côté : on voyait qu'un devis existait sans pouvoir dire si le véhicule
 * était ressorti, ni si la facture avait suivi. Le rapprochement se fait sur l'égalité du
 * numéro — mesuré le 24/09, elle donne exactement le même résultat qu'une forme
 * normalisée, parce que le logiciel écrit le numéro de la même façon partout.
 *
 * Le périmètre est relu de l'identité du lecteur, jamais de l'adresse.
 */
$piste = computed(fn () => $this->fiche === null
    ? null
    : PisteDeLaFiche::pour(
        (int) auth()->user()->entreprise_id,
        $this->fiche->numero_fiche,
        PerimetreSites::idsVillesRetenus(auth()->user(), null),
    ));

?>

<div>
    @if (! $this->fiche)
        <x-carte-section titre="Fiche introuvable" icone="atelier" couleur="#C8102E">
            <p style="margin:0 0 14px; color:#4B4E55;">
                Cette fiche n'existe pas, ou elle relève d'une ville qui n'est pas dans votre périmètre.
            </p>
            <a href="{{ route('parc-vehicules') }}" wire:navigate
               style="display:inline-block; padding:7px 14px; border-radius:7px; background:#191B20; color:#fff;
                      text-decoration:none; font-size:13px; font-weight:700;">Retour au parc</a>
        </x-carte-section>
    @else
        @php $f = $this->fiche; @endphp

        {{-- ------------------------------------------------------------------ l'en-tête --}}
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:14px;
                    flex-wrap:wrap; margin:0 0 16px;">
            <div>
                <a href="{{ route('parc-vehicules') }}" wire:navigate
                   style="color:#6B6E76; text-decoration:none; font-size:12.5px; font-weight:600;">
                    ‹ Parc véhicules
                </a>
                <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:800;
                           margin:3px 0 0; letter-spacing:.5px;">
                    {{ $f->numero_fiche }}
                </h1>
                <div style="color:#6B6E76; font-size:13px; margin-top:2px;">
                    {{ $f->ville?->nom ?? 'Ville indéterminée' }}
                    ·
                    @if ($f->site)
                        {{ $f->site->nom }}
                    @else
                        <span style="color:#C8102E; font-weight:700;">atelier non affecté</span>
                    @endif
                    @if ($f->code_agent)
                        · code employé <b>{{ $f->code_agent }}</b>
                    @endif
                </div>
            </div>

            <span style="display:inline-block; padding:5px 13px; border-radius:20px; font-size:12.5px;
                         font-weight:700; color:#fff; background:{{ $f->estTerminee() ? '#1E7B34' : '#B9791C' }};">
                {{ $f->statut ?? 'Statut inconnu' }}
            </span>
        </div>

        {{-- --------------------------------------------- la fiche, dans les mots du logiciel --}}
        <x-carte-section titre="Fiche de réception" icone="atelier">
            <p style="margin:0 0 14px; color:#6B6E76; font-size:12.5px;">
                Les intitulés et l'ordre sont ceux du logiciel d'atelier. Les colonnes vides sont
                conservées&nbsp;: une case vide y est une information, pas une absence de champ.
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:2px;">
                @foreach ($f->champsDuFichier() as $intitule => $valeur)
                    @php
                        // Les deux champs de texte long tiennent sur toute la largeur : les
                        // travaux à effectuer courent parfois sur plusieurs lignes.
                        $large = in_array($intitule, ['TRAVAUX A EFFECTUER', 'INFORMATIONS SUR LA SITUATION'], true);
                    @endphp
                    <div style="padding:9px 11px; background:{{ $loop->even ? '#FAF8F2' : '#fff' }};
                                border-bottom:1px solid var(--th-ligne,#E2E0D8);
                                {{ $large ? 'grid-column:1 / -1;' : '' }}">
                        <div style="font-size:11px; font-weight:700; letter-spacing:.4px; color:#6B6E76;
                                    text-transform:uppercase;">{{ $intitule }}</div>
                        <div style="font-size:13.5px; margin-top:2px; {{ $valeur === '—' ? 'color:#9A9DA5;' : 'font-weight:600;' }}
                                    {{ $large ? 'white-space:pre-line;' : '' }}">{{ $valeur }}</div>
                    </div>
                @endforeach
            </div>
        </x-carte-section>

        {{-- ------------------------------------------- ce que ce numéro relie ailleurs --}}
        <x-carte-section titre="Ce que cette fiche a produit" icone="liste">
            <p style="margin:0 0 14px; color:#6B6E76; font-size:12.5px;">
                Le n° de fiche est la seule clé commune aux états du logiciel d'atelier : il
                se retrouve sur le devis, sur la facture, et dans les entrées et sorties de
                véhicules. Ce qui manque est dit en toutes lettres — une page vide se
                confondrait avec une panne.
            </p>

            @php $piste = $this->piste; @endphp

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px;">
                <div>
                    <div style="font-size:11px; font-weight:700; letter-spacing:.4px; color:#6B6E76;
                                text-transform:uppercase; margin-bottom:6px;">Devis ({{ $piste['devis']->count() }})</div>
                    @forelse ($piste['devis'] as $devis)
                        <div style="padding:6px 0; border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:12.5px;">
                            <b>{{ $devis->numero ?: '—' }}</b>
                            <span style="color:#6B6E76;"> · {{ $devis->date_emission?->format('d/m/Y') ?? $devis->date_reception?->format('d/m/Y') ?? '—' }}</span>
                            <div style="color:#6B6E76;">
                                {{ $devis->statut ?: 'statut inconnu' }} ·
                                <b style="font-variant-numeric:tabular-nums;">{{ ae((int) $devis->montant_devis) }}</b>
                            </div>
                        </div>
                    @empty
                        <div style="font-size:12.5px; color:#9A9DA5;">Aucun devis ne cite cette fiche.</div>
                    @endforelse
                </div>

                <div>
                    <div style="font-size:11px; font-weight:700; letter-spacing:.4px; color:#6B6E76;
                                text-transform:uppercase; margin-bottom:6px;">Factures ({{ $piste['factures']->count() }})</div>
                    @forelse ($piste['factures'] as $facture)
                        <div style="padding:6px 0; border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:12.5px;">
                            <b>{{ $facture->n_facture ?: ($facture->numero ?: '—') }}</b>
                            <span style="color:#6B6E76;"> · {{ $facture->date?->format('d/m/Y') ?? '—' }}</span>
                            <div style="color:#6B6E76;">
                                {{ $facture->client ?: '—' }} ·
                                <b style="font-variant-numeric:tabular-nums;">{{ ae((int) $facture->montant) }}</b>
                            </div>
                        </div>
                    @empty
                        <div style="font-size:12.5px; color:#9A9DA5;">Aucune facture ne cite cette fiche.</div>
                    @endforelse
                </div>

                <div>
                    <div style="font-size:11px; font-weight:700; letter-spacing:.4px; color:#6B6E76;
                                text-transform:uppercase; margin-bottom:6px;">Entrées & sorties ({{ $piste['mouvements']->count() }})</div>
                    @forelse ($piste['mouvements'] as $mouvement)
                        <div style="padding:6px 0; border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:12.5px;">
                            <b>{{ $mouvement->sens === MouvementVehicule::ENTREE ? 'Entrée' : 'Sortie' }}</b>
                            <span style="color:#6B6E76;"> · {{ $mouvement->date?->format('d/m/Y') ?? '—' }}</span>
                            @if ($mouvement->date_livraison_prevue)
                                <div style="color:{{ $mouvement->sens === MouvementVehicule::ENTREE && $mouvement->date_livraison_prevue->isPast() ? '#C8102E' : '#6B6E76' }};">
                                    Livraison prévue : {{ $mouvement->date_livraison_prevue->format('d/m/Y') }}
                                </div>
                            @endif
                        </div>
                    @empty
                        <div style="font-size:12.5px; color:#9A9DA5;">Aucun mouvement n'est enregistré pour cette fiche.</div>
                    @endforelse
                </div>
            </div>

            @php $manques = PisteDeLaFiche::cequiManque($piste); @endphp

            @if ($manques !== [])
                <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--th-ligne,#E2E0D8);">
                    <div style="font-size:11px; font-weight:700; letter-spacing:.4px; color:#6B6E76;
                                text-transform:uppercase; margin-bottom:5px;">Ce qui manque à la piste</div>
                    @foreach ($manques as $manque)
                        <div style="font-size:12.5px; color:#4B4E55;">· {{ $manque }}</div>
                    @endforeach
                    <p style="margin:9px 0 0; font-size:12px; color:#9A9DA5;">
                        Un manque n'est pas forcément une anomalie : chaque état est une
                        extraction à une date, et deux extractions ne couvrent pas la même
                        période. Sur les 3 318 fiches reprises, 628 n'ont encore ni devis,
                        ni facture, ni mouvement.
                    </p>
                </div>
            @endif

        </x-carte-section>

        {{-- ------------------------------------------------------- d'où vient cette ligne --}}
        <x-carte-section titre="Origine de la ligne" icone="liste" couleur="#2A2E35">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; font-size:13px;">
                <div>
                    <div style="font-size:11px; font-weight:700; color:#6B6E76; text-transform:uppercase;">Rattachement</div>
                    <div style="margin-top:2px;">
                        {{ \Modules\Noyau\Imports\Services\Rattachement::SOURCES[$f->source_rattachement] ?? '—' }}
                        @if ($f->rattachement_presume)
                            <span style="color:#C8102E; font-weight:700;"> — présumé</span>
                        @endif
                    </div>
                </div>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#6B6E76; text-transform:uppercase;">Fichier d'origine</div>
                    <div style="margin-top:2px;">{{ $f->lot?->nom_fichier ?? 'Saisie manuelle' }}</div>
                </div>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#6B6E76; text-transform:uppercase;">Importée le</div>
                    <div style="margin-top:2px;">{{ $f->lot?->created_at?->format('d/m/Y à H:i') ?? '—' }}</div>
                </div>
            </div>
        </x-carte-section>

        <div style="margin-top:14px;">
            <a href="{{ route('parc-vehicules') }}" wire:navigate
               style="display:inline-block; padding:7px 14px; border-radius:7px; background:#191B20; color:#fff;
                      text-decoration:none; font-size:13px; font-weight:700;">Retour au parc</a>
        </div>
    @endif
</div>
