@php $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first(); @endphp
<x-coquille-auth :entreprise="$entreprise" titre="Nouveau mot de passe" sous-titre="Choisissez un mot de passe d'au moins 8 caractères.">
    @if ($errors->any())
        <div class="encart encart-alerte">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <label for="email" class="champ-libelle">Adresse e-mail</label>
        <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required class="champ" style="margin-bottom:14px;">

        <label for="password" class="champ-libelle">Nouveau mot de passe</label>
        <div class="champ-mot-de-passe" style="margin-bottom:14px;">
            <input id="password" name="password" type="password" required autocomplete="new-password" class="champ">
            <x-oeil-mot-de-passe />
        </div>

        <label for="password_confirmation" class="champ-libelle">Confirmer le mot de passe</label>
        <div class="champ-mot-de-passe">
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="champ">
            <x-oeil-mot-de-passe />
        </div>

        <button type="submit" class="bouton bouton-sombre" style="width:100%; justify-content:center; padding:12px; margin-top:18px;">
            Réinitialiser mon mot de passe
        </button>
    </form>
</x-coquille-auth>
