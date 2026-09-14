<?php

use Modules\Noyau\Messagerie\Modeles\Conversation;
use Modules\Noyau\Messagerie\Services\AnnuaireMessagerie;
use Modules\Noyau\Messagerie\Services\Messagerie;
use function Livewire\Volt\{state, computed, mount, usesFileUploads};

usesFileUploads();

state([
    'conversationId' => null,
    'message' => null,

    // Nouvelle conversation
    'compose' => false,
    'destinataires' => [],
    'sujet' => '',
    'corpsNouveau' => '',
    'fichiersNouveau' => [],

    // Réponse
    'corps' => '',
    'fichiers' => [],
]);

// L'adresse porte la conversation ouverte : le lien d'une notification arrive directement
// dessus. La valeur initiale est une chaîne vide, et non null, pour que « except » la
// reconnaisse et retire le paramètre de l'adresse au lieu d'afficher « ?conversation= ».
state(['conversation' => ''])->url(except: '');

mount(function () {
    $this->conversationId = $this->conversation ? (int) $this->conversation : null;

    if ($this->conversationId) {
        $this->ouvrirConversation($this->conversationId);
    }
});

$moi = computed(fn () => auth()->user());

$annuaire = computed(fn () => AnnuaireMessagerie::destinatairesGroupes(auth()->user()));

$nonLues = computed(fn () => Messagerie::conversationsNonLues(auth()->user()));

$conversations = computed(fn () => Conversation::query()
    ->visiblePour(auth()->user())
    ->with(['participants:id,name,photo_chemin', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
    ->orderByDesc('dernier_message_le')
    ->orderByDesc('id')
    ->limit(60)
    ->get());

$courante = computed(function () {
    if (! $this->conversationId) {
        return null;
    }

    return Conversation::query()
        ->visiblePour(auth()->user())
        ->with(['participants:id,name,photo_chemin', 'messages.expediteur:id,name,photo_chemin', 'messages.piecesJointes'])
        ->find($this->conversationId);
});

$ouvrirConversation = function (int $id) {
    $conversation = Conversation::query()->visiblePour(auth()->user())->find($id);

    if (! $conversation) {
        $this->conversationId = null;
        $this->conversation = '';

        return;
    }

    $this->conversationId = $id;
    $this->conversation = (string) $id;
    $this->compose = false;
    $this->corps = '';
    $this->fichiers = [];

    Messagerie::marquerLue($conversation, auth()->user());

    unset($this->conversations, $this->courante, $this->nonLues);
    $this->dispatch('notifications-actualisees');
};

/**
 * Remet le formulaire d'envoi à zéro.
 *
 * Les tableaux sont réaffectés explicitement plutôt que via reset() : sur un composant
 * Volt, reset() peut les ramener à null, et une case à cocher liée à null bascule en
 * booléen au lieu d'alimenter la liste des destinataires.
 */
$viderFormulaire = function () {
    $this->destinataires = [];
    $this->fichiersNouveau = [];
    $this->sujet = '';
    $this->corpsNouveau = '';
};

$nouvelleConversation = function () {
    $this->compose = true;
    $this->conversationId = null;
    $this->conversation = '';
    $this->viderFormulaire();
};

$envoyerNouvelle = function () {
    // Une case cochée alors que la propriété valait null arriverait en booléen : on
    // normalise avant de valider pour que le message d'erreur reste compréhensible.
    $this->destinataires = array_values(array_filter((array) $this->destinataires, 'is_numeric'));

    $this->validate([
        'destinataires' => ['required', 'array', 'min:1'],
        'sujet' => ['nullable', 'string', 'max:180'],
        'corpsNouveau' => ['required', 'string', 'max:5000'],
        'fichiersNouveau.*' => ['file', 'max:8192'],
    ], [], [
        'destinataires' => 'destinataires',
        'corpsNouveau' => 'message',
        'fichiersNouveau.*' => 'pièce jointe',
    ]);

    $conversation = Messagerie::ouvrir(
        auth()->user(),
        $this->destinataires,
        $this->sujet,
        $this->corpsNouveau,
        $this->fichiersNouveau ?: [],
    );

    $this->viderFormulaire();
    $this->compose = false;
    $this->message = 'Message envoyé.';
    $this->ouvrirConversation($conversation->id);
};

$repondre = function () {
    $this->validate([
        'corps' => ['required', 'string', 'max:5000'],
        'fichiers.*' => ['file', 'max:8192'],
    ], [], ['corps' => 'message', 'fichiers.*' => 'pièce jointe']);

    $conversation = $this->courante;
    abort_unless($conversation, 404);

    Messagerie::repondre($conversation, auth()->user(), $this->corps, $this->fichiers ?: []);

    $this->corps = '';
    $this->fichiers = [];
    unset($this->conversations, $this->courante, $this->nonLues);
    $this->dispatch('notifications-actualisees');
};

?>

<div>
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
        <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:23px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:0;">
            Messages
        </h1>
        <button type="button" wire:click="nouvelleConversation" class="bouton">+ Nouveau message</button>
    </div>

    @if ($message)
        <div class="encart encart-succes">{{ $message }}</div>
    @endif

    <div class="messagerie">
        {{-- ------------------------------------------------ Liste des fils --}}
        <div class="carte messagerie-liste">
            <h2 class="titre-section">Conversations</h2>

            @forelse ($this->conversations as $fil)
                @php $aDuNonLu = in_array($fil->id, $this->nonLues, true); @endphp
                <button type="button" wire:key="fil-{{ $fil->id }}" wire:click="ouvrirConversation({{ $fil->id }})"
                    class="messagerie-fil {{ $conversationId === $fil->id ? 'est-actif' : '' }}">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="flex:1; font-weight:{{ $aDuNonLu ? '800' : '600' }}; font-size:14px;">
                            {{ $fil->intitulePour($this->moi) }}
                        </span>
                        @if ($aDuNonLu)
                            <span class="pastille pastille-rouge">Nouveau</span>
                        @endif
                    </div>
                    <div style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin-top:3px;">
                        {{ str($fil->messages->first()?->corps ?? '')->limit(58) }}
                    </div>
                    <div style="font-size:11.5px; color:var(--th-gris,#6B6E76); margin-top:2px;">
                        {{ $fil->dernier_message_le?->translatedFormat('d/m/Y à H:i') ?? '' }}
                    </div>
                </button>
            @empty
                <p class="legende-vide">Aucune conversation pour le moment.</p>
            @endforelse
        </div>

        {{-- ------------------------------------------------------- Panneau --}}
        <div class="carte messagerie-panneau">
            @if ($compose)
                <h2 class="titre-section">Nouveau message</h2>

                <label class="champ-libelle">Destinataires <span style="color:var(--th-accent,#C8102E);">*</span></label>
                <p style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin:0 0 8px;">
                    Seules les personnes que vous êtes autorisé à joindre apparaissent ici.
                </p>

                <div class="messagerie-annuaire">
                    @forelse ($this->annuaire as $groupe => $membres)
                        <div style="margin-bottom:10px;">
                            <div style="font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:.6px; color:var(--th-gris,#6B6E76); margin-bottom:5px;">
                                {{ $groupe }}
                            </div>
                            @foreach ($membres as $membre)
                                <label wire:key="dest-{{ $membre->id }}" class="messagerie-destinataire">
                                    <input type="checkbox" wire:model="destinataires" value="{{ $membre->id }}">
                                    <x-avatar :utilisateur="$membre" :taille="26" />
                                    <span>{{ $membre->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @empty
                        <p class="legende-vide">Aucun destinataire disponible.</p>
                    @endforelse
                </div>
                @error('destinataires') <span class="champ-erreur">{{ $message }}</span> @enderror

                <div style="margin-top:12px;">
                    <label class="champ-libelle">Objet</label>
                    <input type="text" wire:model="sujet" value="{{ $sujet }}" class="champ" placeholder="Ex : Objectifs de la semaine">
                    @error('sujet') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>

                <div style="margin-top:12px;">
                    <label class="champ-libelle">Message <span style="color:var(--th-accent,#C8102E);">*</span></label>
                    <textarea wire:model="corpsNouveau" class="champ" rows="5" placeholder="Votre message…"></textarea>
                    @error('corpsNouveau') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>

                <div style="margin-top:12px;">
                    <label class="champ-libelle">Pièces jointes</label>
                    <input type="file" wire:model="fichiersNouveau" multiple class="champ">
                    <div wire:loading wire:target="fichiersNouveau" style="font-size:12.5px; color:var(--th-gris,#6B6E76);">Chargement…</div>
                    @error('fichiersNouveau.*') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>

                <div style="margin-top:14px; display:flex; gap:10px;">
                    <button type="button" wire:click="envoyerNouvelle" class="bouton">Envoyer</button>
                    <button type="button" wire:click="$set('compose', false)" class="bouton bouton-secondaire">Annuler</button>
                </div>
            @elseif ($this->courante)
                @php $fil = $this->courante; @endphp

                <h2 class="titre-section">{{ $fil->intitulePour($this->moi) }}</h2>
                <div style="font-size:12.5px; color:var(--th-gris,#6B6E76); margin:-6px 0 12px;">
                    {{ $fil->participants->pluck('name')->implode(' · ') }}
                </div>

                <div class="messagerie-flux">
                    @foreach ($fil->messages->sortBy('id') as $msg)
                        @php $deMoi = $msg->expediteur_id === $this->moi->id; @endphp
                        <div wire:key="msg-{{ $msg->id }}" class="messagerie-bulle {{ $deMoi ? 'est-moi' : '' }}">
                            <div style="display:flex; align-items:center; gap:7px; margin-bottom:5px;">
                                <x-avatar :utilisateur="$msg->expediteur" :taille="24" />
                                <span style="font-weight:700; font-size:12.5px;">{{ $deMoi ? 'Vous' : $msg->expediteur?->name }}</span>
                                <span style="font-size:11.5px; color:var(--th-gris,#6B6E76);">
                                    {{ $msg->created_at->translatedFormat('d/m/Y à H:i') }}
                                </span>
                            </div>
                            <div style="white-space:pre-wrap; font-size:14px; line-height:1.5;">{{ $msg->corps }}</div>

                            @foreach ($msg->piecesJointes as $piece)
                                <a href="{{ $piece->url() }}" target="_blank" rel="noopener" class="messagerie-piece">
                                    {{ $piece->nom_original }} <span style="color:var(--th-gris,#6B6E76);">({{ $piece->tailleLisible() }})</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div style="margin-top:14px; border-top:1px solid var(--th-ligne,#E2E0D8); padding-top:12px;">
                    <textarea wire:model="corps" class="champ" rows="3" placeholder="Votre réponse…"></textarea>
                    @error('corps') <span class="champ-erreur">{{ $message }}</span> @enderror

                    <div style="margin-top:8px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="file" wire:model="fichiers" multiple class="champ" style="max-width:280px;">
                        <button type="button" wire:click="repondre" class="bouton">Répondre</button>
                        <span wire:loading wire:target="fichiers" style="font-size:12.5px; color:var(--th-gris,#6B6E76);">Chargement…</span>
                    </div>
                    @error('fichiers.*') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            @else
                <div class="etat-vide">
                    <p class="legende-vide">Choisissez une conversation à gauche, ou démarrez un nouveau message.</p>
                </div>
            @endif
        </div>
    </div>
</div>
