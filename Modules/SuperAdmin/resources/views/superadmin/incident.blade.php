<?php

use App\Models\User;
use App\Support\JournalDesIncidents;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Un incident, par sa référence — demandé le 09/10
|--------------------------------------------------------------------------
| « Dès que je dépose le code, je dois voir l'erreur sur une page. » La référence que
| l'utilisateur lit sur la page de panne (« ERR-XEYTWV ») ouvre ici ce que le serveur a
| enregistré : le message, l'endroit du code, l'adresse, le compte, la pile d'appels — et,
| quand on la reconnaît, la cause probable et ce qu'il faut faire.
*/

state(['reference' => '']);

mount(function (string $reference) {
    $this->reference = strtoupper(trim($reference));
});

$incident = computed(fn () => JournalDesIncidents::trouver($this->reference));

$explication = computed(fn () => JournalDesIncidents::explication($this->incident['message'] ?? null));

$migrationsEnAttente = computed(fn () => JournalDesIncidents::migrationsEnAttente());

$compte = computed(fn () => isset($this->incident['user_id']) && $this->incident['user_id']
    ? User::withoutGlobalScopes()->find($this->incident['user_id'])
    : null);

$assistant = computed(fn () => isset($this->incident['assistant_id']) && $this->incident['assistant_id']
    ? User::withoutGlobalScopes()->find($this->incident['assistant_id'])
    : null);

?>

<div>
    <x-titre-ecran titre="Incident {{ $reference }}"
        sous-titre="Ce que le serveur a enregistré au moment de la panne.">
        <div style="margin-top:10px;">
            <a href="{{ route('super-admin.maintenance') }}#incidents" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; text-decoration:none;">← Retour aux incidents</a>
        </div>
    </x-titre-ecran>

    @if ($this->incident === null)
        <div class="encart encart-alerte">
            Aucun incident <b>{{ $reference }}</b> n'est connu : ni en base, ni dans les dix
            derniers fichiers du journal du serveur. Vérifiez la référence (six caractères après
            « ERR- »), ou le journal a pu être vidé depuis.
        </div>
    @else
        @php $i = $this->incident; @endphp

        @if ($this->explication)
            <div class="encart encart-alerte" style="margin-bottom:14px;">
                <b>Cause probable :</b> {{ $this->explication }}
            </div>
        @endif

        @if ($this->migrationsEnAttente !== [])
            <div class="encart encart-alerte" style="margin-bottom:14px;">
                <b>{{ count($this->migrationsEnAttente) }} migration(s) en attente sur ce serveur</b> —
                c'est la cause la plus fréquente d'une panne juste après une mise à jour.
                Lancer <code>php artisan app:deployer</code>.
                <ul style="margin:8px 0 0; font-size:12.5px;">
                    @foreach ($this->migrationsEnAttente as $migration)
                        <li><code>{{ $migration }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-carte-section titre="Ce qui s'est passé">
            <dl style="display:grid; grid-template-columns:180px 1fr; gap:8px 14px; margin:0; font-size:13.5px;">
                <dt style="color:#6B6E76;">Date</dt><dd style="margin:0;">{{ $i['date'] ?? '—' }}</dd>
                <dt style="color:#6B6E76;">Adresse</dt>
                <dd style="margin:0; word-break:break-all;">{{ $i['methode'] ? $i['methode'].' ' : '' }}{{ $i['url'] ?? '—' }}</dd>
                <dt style="color:#6B6E76;">Compte</dt>
                <dd style="margin:0;">
                    {{ $this->compte?->name ?? ($i['user_id'] ? 'Compte n° '.$i['user_id'] : 'Non connecté') }}
                    @if ($this->assistant) <span style="color:#6B6E76;">— assisté par {{ $this->assistant->name }}</span> @endif
                </dd>
                <dt style="color:#6B6E76;">Type</dt><dd style="margin:0;"><code>{{ $i['exception'] ?? '—' }}</code></dd>
                <dt style="color:#6B6E76;">Message</dt>
                <dd style="margin:0; font-weight:700; word-break:break-word;">{{ $i['message'] ?: '—' }}</dd>
                <dt style="color:#6B6E76;">Endroit du code</dt><dd style="margin:0;"><code>{{ $i['origine'] ?? '—' }}</code></dd>
                <dt style="color:#6B6E76;">Source</dt><dd style="margin:0; color:#6B6E76;">{{ $i['source'] }}</dd>
            </dl>

            @if (! empty($i['trace']))
                <h4 style="font-size:13px; margin:16px 0 6px;">Pile d'appels</h4>
                <pre style="white-space:pre-wrap; font-size:11.5px; background:#F6F5F1; padding:12px; border-radius:8px; max-height:420px; overflow:auto;">{{ $i['trace'] }}</pre>
            @endif
        </x-carte-section>
    @endif
</div>
