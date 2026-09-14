<?php

use Modules\Noyau\Commun\Modeles\DossierNote;
use Modules\Noyau\Commun\Modeles\Note;
use function Livewire\Volt\{state, computed};

state([
    'message' => null,

    // Dossiers
    'nouveauDossier' => '',
    'couleurDossier' => '#2563EB',

    // Note en cours d'édition
    'noteId' => null,
    'titre' => '',
    'corps' => '',
    'rappel' => '',
    'dossierNote' => '',
]);

// Le dossier sélectionné et la recherche vivent dans l'adresse : le filtre survit au rechargement.
state(['dossier' => '', 'recherche' => ''])->url(except: '');

$dossiers = computed(fn () => DossierNote::query()
    ->where('user_id', auth()->id())
    ->withCount('notes')
    ->orderBy('nom')
    ->get());

$notes = computed(fn () => Note::query()
    ->where('user_id', auth()->id())
    ->when($this->dossier !== '', fn ($q) => $q->where('dossier_note_id', (int) $this->dossier))
    ->when($this->recherche !== '', fn ($q) => $q->where(fn ($s) => $s
        ->where('titre', 'like', '%'.$this->recherche.'%')
        ->orWhere('corps', 'like', '%'.$this->recherche.'%')))
    ->with('dossier')
    ->orderByDesc('est_epinglee')
    ->orderByDesc('updated_at')
    ->get());

$total = computed(fn () => Note::where('user_id', auth()->id())->count());

$creerDossier = function () {
    $donnees = $this->validate([
        'nouveauDossier' => ['required', 'string', 'max:80'],
        'couleurDossier' => ['required', 'string', 'max:9'],
    ], [], ['nouveauDossier' => 'nom du dossier', 'couleurDossier' => 'couleur']);

    DossierNote::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'user_id' => auth()->id(),
        'nom' => trim($donnees['nouveauDossier']),
        'couleur' => $donnees['couleurDossier'],
    ]);

    $this->reset('nouveauDossier');
    unset($this->dossiers);
    $this->message = 'Dossier créé.';
};

$supprimerDossier = function (int $id) {
    // Les notes du dossier ne sont pas perdues : elles retournent dans « Sans dossier ».
    DossierNote::where('user_id', auth()->id())->where('id', $id)->delete();

    if ((string) $id === (string) $this->dossier) {
        $this->dossier = '';
    }

    unset($this->dossiers, $this->notes);
    $this->message = 'Dossier supprimé, ses notes sont conservées.';
};

$nouvelleNote = function () {
    $this->reset('noteId', 'titre', 'corps', 'rappel');
    $this->dossierNote = $this->dossier;
};

$editerNote = function (int $id) {
    $note = Note::where('user_id', auth()->id())->findOrFail($id);

    $this->noteId = $note->id;
    $this->titre = $note->titre;
    $this->corps = $note->corps ?? '';
    $this->rappel = $note->rappel_le?->format('Y-m-d') ?? '';
    $this->dossierNote = (string) ($note->dossier_note_id ?? '');
};

$enregistrerNote = function () {
    $donnees = $this->validate([
        'titre' => ['required', 'string', 'max:180'],
        'corps' => ['nullable', 'string', 'max:8000'],
        'rappel' => ['nullable', 'date'],
        'dossierNote' => ['nullable'],
    ], [], ['titre' => 'titre', 'corps' => 'contenu', 'rappel' => 'rappel']);

    // Un dossier ne peut être choisi que parmi les siens : jamais d'identifiant venu d'ailleurs.
    $dossierId = $donnees['dossierNote'] !== ''
        ? DossierNote::where('user_id', auth()->id())->whereKey((int) $donnees['dossierNote'])->value('id')
        : null;

    $valeurs = [
        'titre' => $donnees['titre'],
        'corps' => $donnees['corps'] ?: null,
        'rappel_le' => $donnees['rappel'] ?: null,
        'dossier_note_id' => $dossierId,
    ];

    if ($this->noteId) {
        Note::where('user_id', auth()->id())->where('id', $this->noteId)->firstOrFail()->update($valeurs);
        $this->message = 'Note mise à jour.';
    } else {
        Note::create([
            ...$valeurs,
            'entreprise_id' => auth()->user()->entreprise_id,
            'user_id' => auth()->id(),
        ]);
        $this->message = 'Note enregistrée.';
    }

    $this->reset('noteId', 'titre', 'corps', 'rappel');
    unset($this->notes, $this->dossiers, $this->total);
};

$epingler = function (int $id) {
    $note = Note::where('user_id', auth()->id())->findOrFail($id);
    $note->update(['est_epinglee' => ! $note->est_epinglee]);
    unset($this->notes);
};

$supprimerNote = function (int $id) {
    Note::where('user_id', auth()->id())->where('id', $id)->delete();

    if ($this->noteId === $id) {
        $this->reset('noteId', 'titre', 'corps', 'rappel');
    }

    unset($this->notes, $this->dossiers, $this->total);
    $this->message = 'Note supprimée.';
};

?>

<div>
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
        <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:23px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:0;">
            Mes notes
        </h1>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}" class="champ"
                placeholder="Rechercher…" style="width:220px;">
            <button type="button" wire:click="nouvelleNote" class="bouton">+ Nouvelle note</button>
        </div>
    </div>

    @if ($message)
        <div class="encart encart-succes">{{ $message }}</div>
    @endif

    <div style="display:grid; grid-template-columns:270px 1fr; gap:16px; align-items:start;">

        {{-- ---------------------------------------------------- Dossiers --}}
        <x-carte-section titre="Dossiers" icone="liste" couleur="#2563EB">
            <button type="button" wire:click="$set('dossier', '')"
                class="messagerie-fil {{ $dossier === '' ? 'est-actif' : '' }}" style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:700; font-size:13.5px;">Toutes les notes</span>
                <span class="pastille pastille-bleu">{{ $this->total }}</span>
            </button>

            @foreach ($this->dossiers as $d)
                <div wire:key="dossier-{{ $d->id }}" style="display:flex; align-items:center; gap:6px;">
                    <button type="button" wire:click="$set('dossier', '{{ $d->id }}')"
                        class="messagerie-fil {{ (string) $dossier === (string) $d->id ? 'est-actif' : '' }}"
                        style="flex:1; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <span style="display:flex; align-items:center; gap:7px; min-width:0;">
                            <span style="width:10px; height:10px; border-radius:3px; background:{{ $d->couleur }}; flex:0 0 auto;"></span>
                            <span style="font-weight:600; font-size:13.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $d->nom }}</span>
                        </span>
                        <span style="font-size:12px; color:var(--th-gris,#6B6E76);">{{ $d->notes_count }}</span>
                    </button>
                    <button type="button" wire:click="supprimerDossier({{ $d->id }})"
                        wire:confirm="Supprimer ce dossier ? Ses notes seront conservées."
                        class="bouton bouton-secondaire bouton-petit" title="Supprimer le dossier">×</button>
                </div>
            @endforeach

            <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--th-ligne,#E2E0D8);">
                <label class="champ-libelle">Nouveau dossier</label>
                <input type="text" wire:model="nouveauDossier" value="{{ $nouveauDossier }}" class="champ" placeholder="Ex : Relances clients">
                @error('nouveauDossier') <span class="champ-erreur">{{ $message }}</span> @enderror
                <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
                    <input type="color" wire:model="couleurDossier" style="width:44px; height:34px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:7px; background:#fff; cursor:pointer;">
                    <button type="button" wire:click="creerDossier" class="bouton bouton-sombre" style="flex:1;">+ Créer</button>
                </div>
            </div>
        </x-carte-section>

        {{-- ------------------------------------------------------- Notes --}}
        <div>
            <x-carte-section titre="{{ $noteId ? 'Modifier la note' : 'Nouvelle note' }}" icone="commercial" couleur="#0E9F6E">
                <div class="bloc-saisie">
                    <div style="flex:2; min-width:220px;">
                        <label class="champ-libelle">Titre <span style="color:var(--th-accent,#C8102E);">*</span></label>
                        <input type="text" wire:model="titre" value="{{ $titre }}" class="champ" placeholder="Ex : Rappeler M. Koffi">
                        @error('titre') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                    <div style="min-width:180px;">
                        <label class="champ-libelle">Dossier</label>
                        <select wire:model="dossierNote" class="champ">
                            <option value="" @selected($dossierNote === '')>Sans dossier</option>
                            @foreach ($this->dossiers as $d)
                                <option value="{{ $d->id }}" @selected((string) $dossierNote === (string) $d->id)>{{ $d->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="min-width:150px;">
                        <label class="champ-libelle">Rappel</label>
                        <input type="date" wire:model="rappel" value="{{ $rappel }}" class="champ" style="width:158px;">
                        @error('rappel') <span class="champ-erreur">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label class="champ-libelle">Contenu</label>
                    <textarea wire:model="corps" class="champ" rows="4" placeholder="Vos observations…"></textarea>
                    @error('corps') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>

                <div style="margin-top:12px; display:flex; gap:10px;">
                    <button type="button" wire:click="enregistrerNote" class="bouton">
                        {{ $noteId ? 'Mettre à jour' : 'Enregistrer' }}
                    </button>
                    @if ($noteId)
                        <button type="button" wire:click="nouvelleNote" class="bouton bouton-secondaire">Annuler</button>
                    @endif
                </div>
            </x-carte-section>

            <x-carte-section titre="Mes notes" icone="liste" couleur="#D97706">
                @forelse ($this->notes as $note)
                    <div wire:key="note-{{ $note->id }}" class="messagerie-bulle" style="max-width:100%; margin-bottom:10px;
                        border-left-color:{{ $note->dossier?->couleur ?? 'var(--th-steel,#2A2E35)' }};">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            @if ($note->est_epinglee)
                                <span class="pastille pastille-ambre">Épinglée</span>
                            @endif
                            <span style="flex:1; font-weight:700; font-size:14.5px;">{{ $note->titre }}</span>
                            @if ($note->dossier)
                                <span class="pastille pastille-bleu">{{ $note->dossier->nom }}</span>
                            @endif
                            @if ($note->rappel_le)
                                <span class="pastille {{ $note->rappel_le->isPast() ? 'pastille-rouge' : 'pastille-vert' }}">
                                    Rappel {{ $note->rappel_le->format('d/m/Y') }}
                                </span>
                            @endif
                        </div>

                        @if ($note->corps)
                            <div style="white-space:pre-wrap; font-size:13.5px; line-height:1.5; margin-top:6px;">{{ $note->corps }}</div>
                        @endif

                        <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                            <span style="flex:1; font-size:11.5px; color:var(--th-gris,#6B6E76);">
                                Modifiée le {{ $note->updated_at->format('d/m/Y à H:i') }}
                            </span>
                            <button type="button" wire:click="epingler({{ $note->id }})" class="bouton bouton-secondaire bouton-petit">
                                {{ $note->est_epinglee ? 'Désépingler' : 'Épingler' }}
                            </button>
                            <button type="button" wire:click="editerNote({{ $note->id }})" class="bouton bouton-secondaire bouton-petit">Modifier</button>
                            <button type="button" wire:click="supprimerNote({{ $note->id }})"
                                wire:confirm="Supprimer définitivement cette note ?"
                                class="bouton bouton-danger bouton-petit">Supprimer</button>
                        </div>
                    </div>
                @empty
                    <p class="legende-vide">
                        {{ $recherche !== '' ? 'Aucune note ne correspond à cette recherche.' : 'Aucune note pour le moment.' }}
                    </p>
                @endforelse
            </x-carte-section>
        </div>
    </div>
</div>
