@props([
    /** Le préfixe des propriétés Livewire : « enc », « p » ou « f ». */
    'prefixe',
    /** Les valeurs courantes, passées depuis l'écran. */
    'support' => '',
    'compte' => '',
    'mode' => '',
    'reference' => '',
    /** Les comptes déclarés de l'entreprise, banques et portefeuilles mêlés. */
    'comptes' => null,
    /** Le cadre du formulaire d'accueil : « rec » pour le recouvrement, « champ » ailleurs. */
    'cadre' => 'champ',
    /** Faut-il une référence de transaction ? Facultative, et seulement pour une banque. */
    'referenceOfferte' => true,
])

{{-- Par où le règlement est passé — et comment.

     **Demandé le 01/10**, après constat que les deux saisies ne proposaient pas la même
     chose : *« à la place de banque mets moyen de paiement ; voici la liste déroulante qu'on
     doit avoir : caisse, banque (celles créées), mobile money. Si caisse est sélectionné, le
     mode de règlement ne doit pas apparaître ; si banque est sélectionné, fais apparaître un
     champ qui listera les banques créées, et dès que la banque est sélectionnée, un champ
     pour le mode précis (virement, chèque, carte), et une colonne pour la référence de la
     transaction, facultative. Si mobile money est sélectionné, alors un champ pour choisir. »*

     **Un composant et non trois formulaires**, et c'est la correction de fond : les listes du
     recouvrement et des impayés n'étaient pas identiques — l'une proposait « VIREMENT — BGFI »
     et l'autre un champ de texte libre. Deux écrans qui posent la même question doivent la
     poser avec les mêmes mots, sans quoi la même opération s'enregistre de deux façons et
     aucun total ne tombe juste.

     **La cascade vient de la structure, pas d'un script.** Le support commande ce qui s'ouvre
     en dessous, et ce qui ne s'ouvre pas **n'existe pas** dans la page : il n'y a rien à
     désactiver, donc rien à contourner. Les règles sont posées ici *et* à la validation — un
     champ absent de l'écran part quand même dans la requête si quelqu'un le remet.

     | Support | Compte | Mode | Référence |
     |---|---|---|---|
     | Caisse | — | — | — |
     | Banque | les banques déclarées | virement · chèque · carte | facultative |
     | Mobile money | les portefeuilles déclarés | — | — |

     **Pourquoi le mobile n'a pas de mode** : *« pour les mobiles MTN, Orange, Moov, pas besoin
     de savoir le mode de paiement »*. Un transfert mobile est un transfert mobile ; il ne se
     règle pas « par chèque ». --}}

@php
    $S = \Modules\Noyau\Exploitation\Services\SupportDeReglement::class;
    $B = \Modules\Noyau\Exploitation\Modeles\Banque::class;

    $comptes ??= $B::query()->where('est_active', true)->orderBy('nom')->get();

    // Les comptes offerts suivent le support : une banque ne se choisit pas dans la liste
    // des portefeuilles, et l'inverse non plus.
    $offerts = $support === $S::MOBILE
        ? $comptes->where('type', $B::MOBILE)
        : $comptes->where('type', $B::BANQUE);

    $options = $offerts->pluck('nom', 'id')->all();
    $modes = array_combine($S::MODES_BANCAIRES, $S::MODES_BANCAIRES);
@endphp

@if ($cadre === 'rec')
    <div class="rec-fld">
        <label>Moyen de paiement</label>
        <select wire:model.live="{{ $prefixe }}Support">
            <option value="">— Caisse / banque / mobile money —</option>
            @foreach ($S::supportsSaisissables() as $valeur => $libelle)
                <option value="{{ $valeur }}" @selected((string) $support === (string) $valeur)>{{ $libelle }}</option>
            @endforeach
        </select>
    </div>

    @if ($S::demandeUnCompte($support))
        <div class="rec-fld">
            <label>{{ $support === $S::MOBILE ? 'Portefeuille' : 'Banque' }}</label>
            @if ($options === [])
                {{-- Aucun compte de cette sorte : on le dit plutôt que d'offrir une liste
                     vide, qui se lit comme une panne. --}}
                <div class="rec-hint warn" style="margin:0;">
                    Aucun {{ $support === $S::MOBILE ? 'portefeuille' : 'compte bancaire' }} déclaré.
                    Déclarez-le sur l'écran <b>Banques</b>, depuis la trésorerie.
                </div>
            @else
                <select wire:model.live="{{ $prefixe }}Compte">
                    <option value="">— à choisir —</option>
                    @foreach ($options as $id => $nom)
                        <option value="{{ $id }}" @selected((string) $compte === (string) $id)>{{ $nom }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    @endif

    @if ($S::demandeUnMode($support))
        <div class="rec-fld">
            <label>Mode précis</label>
            <select wire:model="{{ $prefixe }}Mode">
                <option value="">— à choisir —</option>
                @foreach ($modes as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected((string) $mode === (string) $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>

        @if ($referenceOfferte)
            <div class="rec-fld">
                <label>Référence <span style="font-weight:400; opacity:.7;">(facultatif)</span></label>
                <input type="text" wire:model="{{ $prefixe }}Reference" value="{{ $reference }}"
                    placeholder="N° de chèque, de virement…">
            </div>
        @endif
    @endif
@else
    <x-champ label="Moyen de paiement" model="{{ $prefixe }}Support" type="select" :live="true"
        :options="$S::supportsSaisissables()" vide="— à choisir —" width="170" />

    @if ($S::demandeUnCompte($support))
        @if ($options === [])
            <x-champ-fige :label="$support === $S::MOBILE ? 'Portefeuille' : 'Banque'"
                valeur="aucun déclaré" width="170"
                aide="à déclarer sur l’écran Banques" />
        @else
            <x-champ :label="$support === $S::MOBILE ? 'Portefeuille' : 'Banque'"
                model="{{ $prefixe }}Compte" type="select" :live="true"
                :options="$options" vide="— à choisir —" width="170" />
        @endif
    @endif

    @if ($S::demandeUnMode($support))
        <x-champ label="Mode précis" model="{{ $prefixe }}Mode" type="select"
            :options="$modes" vide="— à choisir —" width="150" />

        @if ($referenceOfferte)
            <x-champ label="Référence" model="{{ $prefixe }}Reference" width="170"
                placeholder="N° de chèque, de virement…" />
        @endif
    @endif
@endif
