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

     **La forme du champ suit le type de la donnée**, comme demandé :

       - valeur fixe → une liste déroulante. Taper « Mécanique » avec une faute ne trouve
         rien, et l'on croit qu'il n'y a pas de lignes ;
       - texte → un champ, recherche qui contient ;
       - date et nombre → **deux** champs, parce qu'on cherche une tranche et non une
         valeur exacte.

     **Tout se passe dans le navigateur** jusqu'au moment de filtrer : ajouter une ligne,
     choisir sa colonne et refermer le panneau ne demandent rien au serveur. Seule la
     valeur saisie part, et elle part une fois. --}}

@php
    $poses = \Modules\Noyau\Commun\Services\FiltreLibre::compter($colonnes, (array) $actifs);
@endphp

<div x-data="{
        ouvert: false,
        colonnes: {{ Illuminate\Support\Js::from(collect($colonnes)->map(fn ($c, $cle) => [
            'cle' => $cle, 'libelle' => $c['libelle'], 'type' => $c['type'],
        ])->values()) }},
        /* Les lignes posées : une colonne choisie par ligne. Semées avec ce que le serveur
           détient déjà, pour qu'un retour sur la page ne les perde pas. */
        lignes: {{ Illuminate\Support\Js::from(array_keys((array) $actifs)) }},
        get disponibles() {
            return this.colonnes.filter(c => this.lignes.indexOf(c.cle) === -1);
        },
        typeDe(cle) {
            const trouve = this.colonnes.find(c => c.cle === cle);

            return trouve ? trouve.type : 'texte';
        },
        ajouter(cle) {
            if (! cle || this.lignes.indexOf(cle) !== -1) { return; }

            this.lignes.push(cle);
        },
        retirer(cle) {
            this.lignes = this.lignes.filter(l => l !== cle);
            /* La valeur part avec la ligne : une condition invisible qui filtrerait encore
               est la pire des deux — on cherche la panne ailleurs. */
            $wire.set('{{ $propriete }}.' + cle, null);
        },
     }"
     x-on:keydown.escape.window="ouvert = false"
     style="position:relative;">

    <button type="button" x-on:click="ouvert = ! ouvert"
        class="bouton bouton-secondaire"
        style="padding:9px 14px; font-size:14px; white-space:nowrap;">
        Autre filtre
        @if ($poses > 0)
            <span style="display:inline-block; margin-left:5px; background:#C8102E; color:#fff;
                         border-radius:999px; padding:1px 7px; font-size:11px; font-weight:700;">{{ $poses }}</span>
        @endif
    </button>

    <div x-show="ouvert" x-cloak
         x-on:click.outside="ouvert = false"
         style="position:absolute; z-index:70; right:0; top:100%; margin-top:5px; width:min(460px, 90vw);
                background:#fff; border:1px solid var(--th-ligne,#E3E0D8); border-radius:10px;
                box-shadow:0 12px 30px rgba(25,27,32,.18); padding:14px;">

        <div style="font-size:13px; color:#6B6E76; line-height:1.55; margin-bottom:10px;">
            Filtrer sur une colonne du tableau qui n'a pas son propre filtre.
            Les dates et les montants ouvrent <b>deux champs</b> — c'est une tranche.
        </div>

        {{-- Les lignes déjà posées. --}}
        <template x-for="cle in lignes" :key="cle">
            <div style="border:1px solid var(--th-ligne,#E3E0D8); border-radius:8px; padding:9px 10px; margin-bottom:8px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px;">
                    <b style="font-size:12.5px;"
                       x-text="(colonnes.find(c => c.cle === cle) || {}).libelle"></b>
                    <button type="button" x-on:click="retirer(cle)"
                        style="border:0; background:none; cursor:pointer; color:#C8102E; font-size:12px; font-weight:700;">
                        retirer
                    </button>
                </div>

                {{-- Un champ, ou deux, selon le type. Tous rendus par le serveur et
                     masqués : la page reste lisible sans JavaScript, et la règle qui
                     applique le filtre reste où elle doit être — au serveur. --}}
                @foreach ($colonnes as $cle => $colonne)
                    <div x-show="cle === '{{ $cle }}'">
                        @if ($colonne['type'] === 'liste')
                            <select wire:model.live="{{ $propriete }}.{{ $cle }}.valeur"
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
                                    <input type="date" wire:model.live="{{ $propriete }}.{{ $cle }}.de"
                                        style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                               border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                                </label>
                                <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                    Au
                                    <input type="date" wire:model.live="{{ $propriete }}.{{ $cle }}.a"
                                        style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                               border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                                </label>
                            </div>
                        @elseif ($colonne['type'] === 'nombre')
                            <div style="display:flex; gap:7px;">
                                <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                    Au moins
                                    <input type="number" wire:model.live.debounce.500ms="{{ $propriete }}.{{ $cle }}.de"
                                        style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                               border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                                </label>
                                <label style="flex:1; font-size:11.5px; color:#6B6E76;">
                                    Au plus
                                    <input type="number" wire:model.live.debounce.500ms="{{ $propriete }}.{{ $cle }}.a"
                                        style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:13px;
                                               border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                                </label>
                            </div>
                        @else
                            <input type="search" wire:model.live.debounce.500ms="{{ $propriete }}.{{ $cle }}.valeur"
                                placeholder="Contient…"
                                style="width:100%; box-sizing:border-box; padding:7px 9px; font-size:13px;
                                       border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px;">
                        @endif
                    </div>
                @endforeach
            </div>
        </template>

        {{-- Choisir une colonne de plus. Le `select` se vide après le choix : il sert à
             ajouter, pas à retenir. --}}
        <div x-show="disponibles.length > 0">
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

        <div x-show="disponibles.length === 0" style="font-size:12.5px; color:#6B6E76;">
            Toutes les colonnes filtrables sont déjà posées.
        </div>
    </div>
</div>
