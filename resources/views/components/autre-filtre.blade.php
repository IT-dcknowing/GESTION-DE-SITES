@props([
    'colonnes' => [],
    'actifs' => [],
    'propriete' => 'filtresLibres',
])

{{-- « Autre filtre » — chercher sur les colonnes qu'aucun filtre ne couvre.

     **La demande, du 28/09.** Chaque écran offre trois ou quatre filtres, ceux dont on se
     sert tous les jours ; les tableaux en portent quinze à vingt-cinq colonnes. Les autres
     ne se filtrent pas, et l'on exporte pour filtrer ailleurs — sur une question que
     l'application pourrait répondre.

     **Pourquoi un bouton, et non quinze champs.** Poser un filtre par colonne rendrait la
     barre illisible et ferait perdre les trois qui servent vraiment. Ici on choisit la
     colonne d'abord, et le champ paraît ensuite — un seul, ou deux pour une tranche.

     **La forme du champ suit le type de la donnée**, comme demandé : liste déroulante pour
     une valeur fixe, un champ pour un texte, **deux** pour une date ou un montant, parce
     qu'on cherche une tranche et non une valeur exacte.

     ─────────────────────────────────────────────────────────────────────────────────

     **Deux défauts corrigés le 29/09, relevés ensemble : « le filtre ne marche pas, et il
     est sur les éléments en barrant ».**

     1. **Il ne marchait pas, et la cause était invisible.** Les colonnes sont nommées
        `devis.client` — table et colonne, pour qu'une jointure ne rende pas la condition
        ambiguë. Or Livewire lit le point comme un **séparateur de chemin** :
        `wire:model="filtresLibres.devis.client.valeur"` écrivait dans
        `filtresLibres['devis']['client']['valeur']`, une structure à trois étages, quand le
        serveur allait chercher `filtresLibres['devis.client']`. La valeur arrivait, rangée
        là où personne ne la lisait — aucune erreur, aucun message, et un filtre muet.
        Le point devient un double blanc souligné dans l'état, et là seulement.

     2. **Il recouvrait le tableau.** Le panneau flottait par-dessus les premières lignes :
        on ne voyait plus ce qu'on filtrait pendant qu'on le filtrait. Il **pousse**
        maintenant le contenu au lieu de le masquer — le conteneur est en `display:contents`
        pour que le bouton et le panneau soient deux enfants directs de la barre de filtres,
        et le panneau prend toute la largeur sur sa propre ligne. --}}

@php
    $filtre = \Modules\Noyau\Commun\Services\FiltreLibre::class;
    $poses = $filtre::compter($colonnes, (array) $actifs);

    /* Les clés de l'état : le point du nom de colonne y devient « __ ». Calculées une
       fois ici, et employées partout dans ce fichier — deux façons de les écrire
       finiraient par se désaccorder, et le filtre redeviendrait muet. */
    $alias = collect($colonnes)->mapWithKeys(fn ($c, $cle) => [$cle => $filtre::alias($cle)]);
@endphp

<div x-data="{
        ouvert: false,
        colonnes: {{ Illuminate\Support\Js::from(collect($colonnes)->map(fn ($c, $cle) => [
            'cle' => $filtre::alias($cle), 'libelle' => $c['libelle'], 'type' => $c['type'],
        ])->values()) }},
        /* Les lignes posées : une colonne choisie par ligne. Semées avec ce que le serveur
           détient déjà, pour qu'un retour sur la page ne les perde pas. */
        lignes: {{ Illuminate\Support\Js::from(array_values(array_intersect(
            $alias->values()->all(),
            array_keys((array) $actifs),
        ))) }},
        get disponibles() {
            return this.colonnes.filter(c => this.lignes.indexOf(c.cle) === -1);
        },
        ajouter(cle) {
            if (! cle || this.lignes.indexOf(cle) !== -1) { return; }

            this.lignes.push(cle);
        },
        retirer(cle) {
            this.lignes = this.lignes.filter(l => l !== cle);
            /* La valeur part avec la ligne : une condition invisible qui filtrerait encore
               est la pire des deux — on chercherait la panne ailleurs. */
            $wire.set('{{ $propriete }}.' + cle, null);
        },
     }"
     x-on:keydown.escape.window="ouvert = false"
     style="display:contents;">

    <button type="button" x-on:click="ouvert = ! ouvert"
        class="bouton bouton-secondaire"
        x-bind:aria-expanded="ouvert ? 'true' : 'false'"
        style="padding:9px 14px; font-size:14px; white-space:nowrap;">
        Autre filtre
        @if ($poses > 0)
            <span style="display:inline-block; margin-left:5px; background:#C8102E; color:#fff;
                         border-radius:999px; padding:1px 7px; font-size:11px; font-weight:700;">{{ $poses }}</span>
        @endif
        <span aria-hidden="true" style="margin-left:5px; font-size:10px;"
              x-text="ouvert ? '▲' : '▼'">▼</span>
    </button>

    {{-- Sur sa propre ligne, et il pousse : le tableau reste lisible pendant qu'on le
         filtre. `flex-basis:100%` suffit dans une barre en `flex-wrap`, et `width:100%`
         couvre le cas d'une barre en grille. --}}
    <div x-show="ouvert" x-cloak
         style="flex-basis:100%; width:100%; margin-top:8px;
                background:#FBFAF7; border:1px solid var(--th-ligne,#E3E0D8); border-radius:10px; padding:14px;">

        <div style="font-size:13px; color:#6B6E76; line-height:1.55; margin-bottom:10px;">
            Filtrer sur une colonne du tableau qui n'a pas son propre filtre.
            Les dates et les montants ouvrent <b>deux champs</b> — c'est une tranche.
        </div>

        {{-- Les lignes déjà posées. Le serveur les a toutes rendues, masquées ; Alpine ne
             fait que montrer celles qu'on a choisies. La page reste donc lisible sans
             JavaScript, et la règle qui applique le filtre reste au serveur. --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px;">
            @foreach ($colonnes as $cle => $colonne)
                @php $clef = $alias[$cle]; @endphp
                <div x-show="lignes.indexOf('{{ $clef }}') !== -1" x-cloak
                     style="border:1px solid var(--th-ligne,#E3E0D8); border-radius:8px; padding:9px 10px; background:#fff;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px;">
                        <b style="font-size:12.5px;">{{ $colonne['libelle'] }}</b>
                        <button type="button" x-on:click="retirer('{{ $clef }}')"
                            style="border:0; background:none; cursor:pointer; color:#C8102E; font-size:12px; font-weight:700;">
                            retirer
                        </button>
                    </div>

                    @if ($colonne['type'] === 'liste')
                        <select wire:model.live="{{ $propriete }}.{{ $clef }}.valeur"
                            style="width:100%; box-sizing:border-box; padding:7px 9px; font-size:13px;
                                   border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                            <option value="">— toutes les valeurs —</option>
                            @foreach ($colonne['options'] as $valeur => $libelle)
                                <option value="{{ $valeur }}">{{ $libelle }}</option>
                            @endforeach
                            <option value="__vide__">— non renseigné —</option>
                        </select>
                    @elseif ($colonne['type'] === 'date')
                        <div style="display:flex; gap:7px;">
                            <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                Du
                                <input type="date" wire:model.live="{{ $propriete }}.{{ $clef }}.de"
                                    style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                           border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                            </label>
                            <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                Au
                                <input type="date" wire:model.live="{{ $propriete }}.{{ $clef }}.a"
                                    style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                           border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                            </label>
                        </div>
                    @elseif ($colonne['type'] === 'nombre')
                        <div style="display:flex; gap:7px;">
                            <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                Au moins
                                <input type="number" wire:model.live.debounce.500ms="{{ $propriete }}.{{ $clef }}.de"
                                    style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                           border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                            </label>
                            <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                Au plus
                                <input type="number" wire:model.live.debounce.500ms="{{ $propriete }}.{{ $clef }}.a"
                                    style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                           border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                            </label>
                        </div>
                    @else
                        <input type="search" wire:model.live.debounce.500ms="{{ $propriete }}.{{ $clef }}.valeur"
                            placeholder="Contient…"
                            style="width:100%; box-sizing:border-box; padding:7px 9px; font-size:13px;
                                   border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Choisir une colonne de plus. Le `select` se vide après le choix : il sert à
             ajouter, pas à retenir. --}}
        <div x-show="disponibles.length > 0" style="margin-top:10px; max-width:340px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#4B4E55; margin-bottom:4px;">
                <span x-text="lignes.length === 0 ? 'Choisir une colonne' : 'Ajouter une autre colonne'"></span>
            </label>
            <select x-on:change="ajouter($event.target.value); $event.target.value = ''"
                style="width:100%; box-sizing:border-box; padding:8px 10px; font-size:13.5px;
                       border:1px solid var(--th-ligne,#E3E0D8); border-radius:7px;">
                <option value="">— colonne du tableau —</option>
                <template x-for="c in disponibles" :key="c.cle">
                    <option :value="c.cle" x-text="c.libelle"></option>
                </template>
            </select>
        </div>

        <div x-show="disponibles.length === 0" x-cloak style="margin-top:10px; font-size:12.5px; color:#6B6E76;">
            Toutes les colonnes filtrables sont déjà posées.
        </div>
    </div>
</div>
