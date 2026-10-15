<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Exploitation\Services\AccesAuxObjectifs;
use function Livewire\Volt\{state, computed, mount};

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Accès › Rattrapage — 15/10.
 *
 * « Pour ceux qui ont déjà été créés sans ça, dans la page accès du Super admin, ajoute une section
 * appelée Rattrapage : rattraper ce qui n'a pas été fait pour les anciens. Pour l'instant, juste le
 * rattrapage des objectifs. »
 *
 * La page liste les accès d'une entreprise dont personne n'a dit s'ils sont concernés par les
 * objectifs de l'entreprise (`AccesAuxObjectifs::aRattraper`), et permet de répondre accès par
 * accès — la même case qu'à la création. « Tous les accès » montre aussi ceux déjà décidés, pour
 * corriger. D'autres rattrapages viendront s'ajouter ici, chacun dans sa section.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
state([
    'entrepriseId' => '',
    'seulementARattraper' => true,
    'enCours' => null,
    'concerneObjectifs' => false,
    'objectifsTous' => '1',
    'objectifsChoisis' => [],
]);

mount(function () {
    $this->entrepriseId = (string) (request()->integer('entreprise') ?: (Entreprise::query()->orderBy('nom')->value('id') ?? ''));
});

$disponible = computed(fn () => AccesAuxObjectifs::disponible());

$entreprises = computed(fn () => Entreprise::query()->orderBy('nom')->pluck('nom', 'id')->all());

$options = computed(fn () => $this->entrepriseId === '' ? [] : AccesAuxObjectifs::options((int) $this->entrepriseId));

$acces = computed(function () {
    if ($this->entrepriseId === '' || ! $this->disponible) {
        return collect();
    }

    return $this->seulementARattraper
        ? AccesAuxObjectifs::aRattraper((int) $this->entrepriseId)
        : User::query()->where('entreprise_id', (int) $this->entrepriseId)->orderBy('name')->get();
});

$nombreARattraper = computed(fn () => $this->entrepriseId === '' || ! $this->disponible ? 0
    : AccesAuxObjectifs::aRattraper((int) $this->entrepriseId)->count());

$roles = computed(fn () => User::nomsRolesParUtilisateur($this->acces->pluck('id')));

$decisions = computed(fn () => ! $this->disponible ? collect() : \Illuminate\Support\Facades\DB::table('objectifs_des_acces')
    ->whereIn('user_id', $this->acces->pluck('id'))->get()->keyBy('user_id'));

$updatedEntrepriseId = function () { $this->enCours = null; };

$regler = function (int $id) {
    $compte = User::query()->where('entreprise_id', (int) $this->entrepriseId)->findOrFail($id);
    $decision = AccesAuxObjectifs::decisionDe($compte);

    $this->enCours = $compte->id;
    $this->concerneObjectifs = (bool) ($decision->concerne ?? false);
    $this->objectifsTous = ($decision === null || $decision->tous || ! $decision->concerne) ? '1' : '0';
    $this->objectifsChoisis = array_map('strval', (array) json_decode((string) ($decision->objectifs ?? '[]'), true));
    $this->resetErrorBag();
};

/** Enregistre la réponse ; l'accès est relu sous l'entreprise choisie, jamais pris tel quel du navigateur. */
$enregistrer = function () {
    abort_unless(auth()->user()?->hasRole('super_admin'), 403);

    $compte = User::query()->where('entreprise_id', (int) $this->entrepriseId)->findOrFail((int) $this->enCours);

    try {
        AccesAuxObjectifs::enregistrer($compte, (bool) $this->concerneObjectifs,
            (string) $this->objectifsTous !== '0' || $this->options === [], (array) $this->objectifsChoisis, auth()->id());
    } catch (\RuntimeException $refus) {
        $this->addError('objectifsChoisis', $refus->getMessage());

        return;
    }

    $this->enCours = null;
    unset($this->acces, $this->nombreARattraper, $this->decisions, $this->roles);
    $this->dispatch('annonce', ton: 'succes', texte: 'Objectifs de '.$compte->name.' enregistrés.');
};

/** Les accès cochés comme « non concernés » d'un geste — le cas le plus courant chez les anciens. */
$nonConcerne = function (int $id) {
    abort_unless(auth()->user()?->hasRole('super_admin'), 403);

    $compte = User::query()->where('entreprise_id', (int) $this->entrepriseId)->findOrFail($id);
    AccesAuxObjectifs::enregistrer($compte, false, false, [], auth()->id());
    unset($this->acces, $this->nombreARattraper, $this->decisions);
};

?>

<div>
    <x-titre-ecran titre="Accès — Rattrapage"
        sous-titre="Ce qui n'a pas été renseigné pour les accès créés avant une nouveauté. Aujourd'hui : les objectifs de l'entreprise." />

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
        <a href="{{ route('super-admin.acces.index') }}" wire:navigate class="bouton bouton-secondaire" style="padding:8px 14px; text-decoration:none;">← Accès</a>
        <select wire:model.live="entrepriseId" class="champ" style="width:auto;">
            @foreach ($this->entreprises as $id => $nom)
                <option value="{{ $id }}">{{ $nom }}</option>
            @endforeach
        </select>
        <label style="display:flex; gap:6px; align-items:center; font-size:14px;">
            <input type="checkbox" wire:model.live="seulementARattraper"> Seulement les accès à rattraper ({{ $this->nombreARattraper }})
        </label>
    </div>

    @if (! $this->disponible)
        <div class="carte" style="border-left:4px solid #C8102E;">Les tables des objectifs n'existent pas encore sur ce serveur : lancez <code>php artisan app:deployer</code>.</div>
    @else
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Rattrapage des objectifs</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
                Pour chaque accès : est-il concerné par les objectifs de l'entreprise, et par lesquels — un, plusieurs ou tous ?
                C'est la question posée depuis le 15/10 à la création d'un accès ; ceux d'avant n'y ont jamais répondu.
            </p>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Accès</th><th>Rôle</th><th>Objectifs</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($this->acces as $compte)
                            @php $d = $this->decisions[$compte->id] ?? null; @endphp
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;" wire:key="acces-{{ $compte->id }}">
                                <td><b>{{ $compte->name }}</b><div style="font-size:12px; color:#6B6E76;">{{ $compte->email }}</div></td>
                                <td style="font-size:12.5px;">{{ implode(', ', (array) ($this->roles[$compte->id] ?? [])) ?: '—' }}</td>
                                <td style="font-size:12.5px;">
                                    @if ($d === null)
                                        <span style="color:#B45309; font-weight:700;">À rattraper</span>
                                    @elseif (! $d->concerne)
                                        Non concerné
                                    @elseif ($d->tous)
                                        Concerné — tous les objectifs
                                    @else
                                        Concerné — {{ collect(json_decode((string) $d->objectifs, true))->map(fn ($id) => $this->options[$id] ?? '#'.$id)->implode(' ; ') }}
                                    @endif

                                    @if ($enCours === $compte->id)
                                        <x-objectifs-de-l-acces :options="$this->options" :concerne="$concerneObjectifs" :tous="(string) $objectifsTous !== '0'" />
                                        <button type="button" wire:click="enregistrer" class="bouton" style="padding:6px 12px;">Enregistrer</button>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;">
                                    @if ($enCours !== $compte->id)
                                        <button type="button" wire:click="regler({{ $compte->id }})" class="bouton" style="padding:6px 12px;">Régler</button>
                                        @if ($d === null)
                                            <button type="button" wire:click="nonConcerne({{ $compte->id }})" class="bouton bouton-secondaire" style="padding:6px 12px;">Non concerné</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Rien à rattraper : chaque accès de cette entreprise a sa réponse." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
