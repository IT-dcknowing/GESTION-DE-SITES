<?php

use Modules\Noyau\Exploitation\Modeles\CompteComptable;
use Modules\Noyau\Exploitation\Services\PlanComptable;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Écritures comptables — le paramétrage des comptes — 27/10
|--------------------------------------------------------------------------
| « On doit avoir un module Écriture comptable : dans cette partie, on va paramétrer les numéros
| de compte, faire la configuration. »
|
| Un numéro et un intitulé par rôle — clients, ventes, TVA, caisse, chaque banque, chaque nature
| de charge — et un code par journal. Tant qu'un rôle n'est pas enregistré, il porte la
| proposition du SYSCOHADA, marquée « proposé » : à valider par le comptable. Voir `PlanComptable`.
|
| Les lignes sont tenues par leur rang, pas par leur clé : une clé comme « charge:Achats pièces »
| ne passe pas dans un chemin `wire:model`. La clé se relit côté serveur à l'enregistrement.
*/

state([
    'cles' => [],
    'valeurs' => [],
]);

mount(function () {
    $this->garder();
    $this->charger();
});

$garder = protect(function () {
    abort_unless(auth()->user()?->hasAnyRole(['gerant', 'caissier']), 403);
});

$charger = protect(function () {
    $this->cles = [];
    $this->valeurs = [];

    foreach (PlanComptable::lignes() as $lignes) {
        foreach ($lignes as $cle => $l) {
            $this->cles[] = $cle;
            $this->valeurs[] = ['numero' => $l['numero'], 'libelle' => $l['libelle']];
        }
    }
});

$lignes = computed(fn () => PlanComptable::lignes());

/** Le rang de chaque clé dans le formulaire. */
$rangs = computed(fn () => array_flip($this->cles));

$proposer = function (int $rang) {
    $cle = $this->cles[$rang] ?? null;
    $definition = $cle === null ? null : (PlanComptable::definitions()[$cle] ?? null);

    if ($definition !== null) {
        $this->valeurs[$rang] = ['numero' => $definition['numero'], 'libelle' => $definition['libelle']];
    }
};

$enregistrer = function () {
    $this->garder();

    if (! PlanComptable::disponible()) {
        $this->dispatch('annonce', texte: "La mise à jour du serveur n'est pas encore passée (migration du 27/10).");

        return;
    }

    $definitions = PlanComptable::definitions();
    $regles = [];

    foreach ($this->cles as $rang => $cle) {
        if (! isset($definitions[$cle])) {
            continue;
        }

        // Un compte s'écrit en chiffres ; un journal, d'un code court.
        $regles["valeurs.{$rang}.numero"] = PlanComptable::type($cle) === 'journal'
            ? ['required', 'regex:/^[A-Za-z0-9]{1,6}$/']
            : ['required', 'regex:/^[0-9]{2,12}$/'];
        $regles["valeurs.{$rang}.libelle"] = ['nullable', 'string', 'max:160'];
    }

    $this->validate($regles, [
        'valeurs.*.numero.required' => 'Le numéro est obligatoire.',
        'valeurs.*.numero.regex' => 'Un compte : de 2 à 12 chiffres. Un journal : un code de 1 à 6 lettres ou chiffres.',
    ]);

    $n = 0;

    foreach ($this->cles as $rang => $cle) {
        if (! isset($definitions[$cle])) {
            continue;
        }

        $numero = trim((string) ($this->valeurs[$rang]['numero'] ?? ''));
        $compte = CompteComptable::query()->firstOrNew(['cle' => $cle]);
        $compte->fill([
            'entreprise_id' => auth()->user()->entreprise_id,
            'type' => PlanComptable::type($cle),
            'numero' => PlanComptable::type($cle) === 'journal' ? mb_strtoupper($numero) : $numero,
            'libelle' => trim((string) ($this->valeurs[$rang]['libelle'] ?? '')) ?: null,
            'modifie_par' => auth()->id(),
        ]);

        if ($compte->isDirty() || ! $compte->exists) {
            $compte->save();
            $n++;
        }
    }

    unset($this->lignes);
    $this->charger();
    $this->dispatch('annonce', ton: 'succes', texte: $n === 0 ? 'Rien n\'a changé.' : $n.' compte(s) ou journal(aux) enregistré(s).');
};

?>

<div>
    <x-titre-ecran titre="Écritures comptables — paramétrage"
        sous-titre="Le numéro de compte et l'intitulé de chaque rôle, et le code de chaque journal. Les propositions suivent le SYSCOHADA : à valider par votre comptable." />

    @if (! PlanComptable::disponible())
        <div class="carte"><p style="margin:0; color:#B45309;">La mise à jour du serveur n'est pas encore passée (migration du 27/10).</p></div>
    @else
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #2563EB;">
            <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.5;">
                Chaque montant de l'application passera en écriture sur ces comptes : une facture débite le <b>client</b> et
                crédite les <b>ventes</b> et la <b>TVA</b> ; une sortie de caisse crédite la <b>caisse</b> et débite la charge de sa
                <b>nature</b> ; un relevé mouvemente sa <b>banque</b>. Une ligne marquée <i>proposé</i> n'a pas encore été
                enregistrée. Les banques sont celles déclarées sur l'écran Banques.
            </p>
        </div>

        <form wire:submit="enregistrer">
            @foreach ($this->lignes as $groupe => $lignes)
                <x-carte-section :titre="$groupe">
                    <div class="tableau-conteneur">
                        <table class="tableau">
                            <thead><tr><th style="width:34%;">Rôle</th><th style="width:150px;">{{ $groupe === 'Journaux' ? 'Code' : 'N° de compte' }}</th><th>Intitulé</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($lignes as $cle => $l)
                                    @php $rang = $this->rangs[$cle] ?? null; @endphp
                                    @if ($rang !== null)
                                        <tr wire:key="cpt-{{ md5($cle) }}">
                                            <td>
                                                {{ $l['role'] }}
                                                @if ($l['propose'])<span style="font-size:11px; color:#B45309; font-weight:700;"> · proposé</span>@endif
                                            </td>
                                            <td>
                                                <input type="text" wire:model="valeurs.{{ $rang }}.numero" class="champ" style="width:130px;"
                                                    aria-label="Numéro — {{ $l['role'] }}">
                                                @error("valeurs.{$rang}.numero") <div class="champ-erreur">{{ $message }}</div> @enderror
                                            </td>
                                            <td><input type="text" wire:model="valeurs.{{ $rang }}.libelle" class="champ" aria-label="Intitulé — {{ $l['role'] }}"></td>
                                            <td style="white-space:nowrap; font-size:11.5px; color:#6B6E76;">
                                                proposé : {{ $l['numeroPropose'] }}
                                                <button type="button" wire:click="proposer({{ $rang }})" class="bouton bouton-secondaire" style="padding:2px 8px; font-size:11px;">Reprendre</button>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-carte-section>
            @endforeach

            <div style="position:sticky; bottom:0; padding:12px 0; background:var(--th-fond,#F4F3EF);">
                <button type="submit" class="bouton">Enregistrer le paramétrage</button>
            </div>
        </form>
    @endif
</div>
