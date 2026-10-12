@php $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first(); @endphp
<x-coquille-auth :entreprise="$entreprise" titre="Mot de passe oublié" sous-titre="Nous vous enverrons un lien de réinitialisation.">
    @if (session('status'))
        <div class="encart encart-succes">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="encart encart-alerte">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email" class="champ-libelle">Adresse e-mail</label>
        <input id="email" name="email" type="text" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" value="{{ old('email') }}" required autofocus class="champ">

        <button type="submit" class="bouton bouton-sombre" style="width:100%; justify-content:center; padding:12px; margin-top:18px;">
            Envoyer le lien de réinitialisation
        </button>
    </form>

    <p style="text-align:center; font-size:13.5px; color:var(--th-gris,#6B6E76); margin:20px 0 0;">
        <a href="{{ route('login') }}" style="color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">← Retour à la connexion</a>
    </p>
</x-coquille-auth>
