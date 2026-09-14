<?php

use Modules\Import\Support\AccesImport;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Services\AffectationDesCodes;
use Modules\Noyau\Imports\Services\RapprochementDesCodes;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| La fiche d'un employé du logiciel d'atelier
|--------------------------------------------------------------------------
| **Pourquoi une page, et non un volet au-dessus du tableau.** Le formulaire
| s'ouvrait en tête de la liste : on cliquait sur « Modifier » au milieu de
| trente-huit lignes, la page se rechargeait, et le formulaire apparaissait
| hors de l'écran — au-dessus, là où l'on ne regardait pas. On revenait sur la
| même page, apparemment inchangée, et l'on concluait que le bouton ne faisait rien.
|
| Une fiche a donc son adresse. Elle s'ouvre sur ce qu'on vient y faire, elle
| se met en favori, elle se transmet — et l'on sait toujours où l'on est.
|
| Le formulaire poste vers le même contrôleur qu'avant : rien ici ne dépend de la
| couche interactive.
*/

state(['code' => null]);

mount(function (string $code) {
    $this->code = mb_strtoupper($code);
});

$service = fn () => new AffectationDesCodes((int) auth()->user()->entreprise_id);

$peutArbitrer = computed(fn () => AccesImport::peutArbitrer(auth()->user()));

$villes = computed(fn () => Ville::where('est_actif', true)->orderBy('nom')->pluck('nom', 'id')->all());

$tousLesSites = computed(fn () => Site::where('est_actif', true)->orderBy('nom')->get());

$ligne = computed(fn () => $this->service()->observations()->firstWhere('code', $this->code));

/** Les comptes dont le nom pourrait produire ce code — un rapprochement, pas une conclusion. */
$suggestions = computed(fn () => (new RapprochementDesCodes((int) auth()->user()->entreprise_id))
    ->comptesPossiblesPour($this->code));

/** Les mutations de cette personne : c'est ce qui explique un code vu dans deux villes. */
$mutations = computed(fn () => Reaffectation::where('entreprise_id', auth()->user()->entreprise_id)
    ->whereHas('code', fn ($q) => $q->where('code', $this->code))
    ->with(['villeAvant', 'villeApres', 'siteAvant', 'siteApres'])
    ->latest()
    ->get());

?>

<x-import::coquille page="codes">

    @error('code')
        <div class="imp-hint warn" style="margin-bottom:14px;">{{ $message }}</div>
    @enderror

    @if (! $this->ligne)
        <div class="imp-carte">
            <h2>{{ $code }}</h2>
            <div class="imp-hint warn">
                Ce code ne figure pas dans l'annuaire de votre entreprise. Les codes y entrent tout seuls
                au premier import qui les porte.
            </div>
            <div class="imp-actions">
                <a href="{{ route('import.codes') }}" class="imp-btn o"
                   style="text-decoration:none; display:inline-block;">‹ Revenir à l'annuaire</a>
            </div>
        </div>
    @else
        @php $l = $this->ligne; @endphp

        <div class="imp-carte" style="border-left:4px solid #C8102E;">
            <h2>
                {{ $l['code'] }}
                <span class="chip">{{ $l['libelle'] ?: 'employé non nommé' }}</span>
            </h2>

            <div class="imp-kpis trois">
                <div class="imp-kpi">
                    <div class="lab">Fiches rédigées</div>
                    <div class="val">{{ number_format((int) $l['dossiers'], 0, ',', ' ') }}</div>
                    <div class="sub">dans le parc importé</div>
                </div>
                <div class="imp-kpi">
                    <div class="lab">Lignes croisées</div>
                    <div class="val">{{ number_format((int) $l['occurrences'], 0, ',', ' ') }}</div>
                    <div class="sub">tous imports confondus</div>
                </div>
                <div class="imp-kpi {{ $l['ville_id'] ? 'vert' : '' }}">
                    <div class="lab">Lieu</div>
                    <div class="val" style="font-size:19px;">
                        {{ $l['ville_id'] ? ($this->villes[$l['ville_id']] ?? '?') : 'à renseigner' }}
                    </div>
                    <div class="sub">
                        @if ($l['site_id'])
                            {{ $this->tousLesSites->firstWhere('id', $l['site_id'])?->nom ?? '—' }}
                        @else
                            atelier non précisé
                        @endif
                    </div>
                </div>
            </div>

            @if (! $this->peutArbitrer)
                <div class="imp-lock">
                    Tenir l'annuaire relève du gérant ou du superviseur de ville. Vous pouvez consulter
                    cette fiche, pas la modifier.
                </div>
            @else
                <form method="POST" action="{{ route('import.codes.enregistrer') }}">
                    @csrf
                    <input type="hidden" name="code" value="{{ $l['code'] }}">

                    <div class="imp-frm">
                        <div class="imp-fld">
                            <label for="lib">Nom de l'employé</label>
                            <input type="text" id="lib" name="libelle" maxlength="120"
                                   value="{{ old('libelle', $l['libelle']) }}" placeholder="Nom et prénoms">
                        </div>

                        <div class="imp-fld">
                            <label for="vil">Ville</label>
                            <select id="vil" name="ville">
                                <option value="">— non renseignée —</option>
                                @foreach ($this->villes as $id => $nom)
                                    <option value="{{ $id }}"
                                        @selected((string) old('ville', $l['ville_id'] ?? $l['ville_proposee']) === (string) $id)>
                                        {{ $nom }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="imp-fld" id="bloc-site-code">
                            <label for="sit">Atelier</label>
                            <select id="sit" name="site">
                                <option value="" data-ville="">— toute la ville —</option>
                                @foreach ($this->tousLesSites as $site)
                                    <option value="{{ $site->id }}" data-ville="{{ $site->ville_id }}"
                                        @selected((string) old('site', $l['site_id']) === (string) $site->id)>
                                        {{ $site->nom }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @if ($l['ville_proposee'] && ! $l['ville_id'])
                        <div class="imp-hint">
                            <strong>Le logiciel propose {{ $this->villes[$l['ville_proposee']] ?? '?' }}</strong> —
                            {{ number_format($l['part'] * 100, 0) }}&nbsp;% de ses
                            {{ number_format($l['dossiers'], 0, ',', ' ') }} fiches y sont arrivées. C'est une
                            mesure, pas une décision&nbsp;: elle pré-remplit le champ, elle ne le valide pas.
                        </div>
                    @endif

                    @if ($this->suggestions->isNotEmpty())
                        <div class="imp-hint">
                            <strong>Comptes dont le nom donnerait « {{ $l['code'] }} »</strong> —
                            rapprochement par initiales, à vérifier&nbsp;:
                            {{ $this->suggestions->pluck('name')->implode(' · ') }}
                        </div>
                    @endif

                    @if ($l['repartition'])
                        <div class="imp-hint">
                            <strong>Où sont arrivées ses fiches.</strong>
                            @foreach ($l['repartition'] as $nom => $combien)
                                <span style="display:inline-block; margin-right:14px;">
                                    {{ $nom }} — <strong>{{ number_format($combien, 0, ',', ' ') }}</strong>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="imp-actions">
                        <button type="submit" class="imp-btn r">Enregistrer</button>
                        <a href="{{ route('import.codes') }}" class="imp-btn o"
                           style="text-decoration:none; display:inline-block;">Revenir à l'annuaire</a>
                    </div>
                </form>
            @endif
        </div>

        @if ($this->mutations->isNotEmpty())
            <div class="imp-carte">
                <h2>Mutations de cette personne <span class="chip">lecture seule</span></h2>

                <div class="imp-hint">
                    Ses anciennes fiches restent dans la ville où le travail a eu lieu&nbsp;: seules les
                    suivantes partent au nouveau lieu. Une facture appartient à l'atelier qui l'a faite.
                </div>

                <div class="imp-tbl-wrap" style="margin-top:11px;">
                    <table class="imp-tbl">
                        <thead><tr><th>Date</th><th>Depuis</th><th>Vers</th><th>Motif</th></tr></thead>
                        <tbody>
                            @foreach ($this->mutations as $m)
                                <tr>
                                    <td style="white-space:nowrap;">{{ $m->created_at?->format('d/m/Y') }}</td>
                                    <td>{{ $m->siteAvant?->nom ?? $m->villeAvant?->nom ?? '—' }}</td>
                                    <td>{{ $m->siteApres?->nom ?? $m->villeApres?->nom ?? '—' }}</td>
                                    <td>{{ $m->motif ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="imp-carte">
            <div class="imp-hint ok">
                <strong>Ce que ce lieu décide, et ce qu'il ne décide pas.</strong>
                Il ne range pas les fiches&nbsp;: le rattachement d'une ligne importée vient du filtre
                déclaré au dépôt, par celui qui sort le fichier. Ce qui est renseigné ici sert à
                <strong>reconnaître</strong> la personne dans les tableaux, à rattacher ses devis pour les
                indicateurs, et à prévenir au dépôt quand les codes d'un fichier désignent une autre ville
                que celle annoncée.
            </div>
        </div>
    @endif

    {{-- Le champ Atelier ne montre que les ateliers de la ville retenue, et disparaît là où
         il n'y a pas de choix à faire. Du JavaScript nu, sur un formulaire qui marche déjà
         sans lui : chaque entrée porte sa ville, la liste complète reste utilisable. --}}
    <script data-navigate-once>
        (function () {
            var ville = document.getElementById('vil');
            var site = document.getElementById('sit');
            var bloc = document.getElementById('bloc-site-code');

            if (! ville || ! site || ! bloc) { return; }

            var filtrer = function () {
                var choisie = ville.value;
                var visibles = 0;

                Array.prototype.forEach.call(site.options, function (option) {
                    var sienne = option.getAttribute('data-ville');
                    var garder = sienne === '' || sienne === choisie;
                    option.hidden = ! garder;
                    option.disabled = ! garder;
                    if (garder && sienne !== '') { visibles++; }
                });

                if (site.selectedOptions.length && site.selectedOptions[0].disabled) {
                    site.value = '';
                }

                bloc.style.display = visibles > 1 ? '' : 'none';
            };

            ville.addEventListener('change', filtrer);
            filtrer();
        })();
    </script>

</x-import::coquille>
