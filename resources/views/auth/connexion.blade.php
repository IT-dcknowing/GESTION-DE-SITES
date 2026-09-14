@php
    $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first();
@endphp
<!DOCTYPE html>
<html lang="fr" style="{{ collect($entreprise?->theme() ?? [])->map(fn ($v, $k) => "$k:$v")->implode(';') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — {{ $entreprise?->nom ?? config('app.name') }}</title>
    @if ($entreprise?->logoUrl())
        <link rel="icon" type="image/png" href="{{ $entreprise->logoUrl() }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .ecran-connexion { display:grid; grid-template-columns:1fr 1fr; min-height:100vh; }
        @media (max-width: 900px) {
            .ecran-connexion { grid-template-columns:1fr; }
            .ecran-connexion .panneau-gauche { display:none; }
        }
    </style>
</head>
<body class="antialiased" style="margin:0; font-family:var(--font-sans); background:var(--th-paper,#F4F3EF);">

<div class="ecran-connexion">

    {{-- Panneau de présentation, aux couleurs de l'entreprise. --}}
    <div class="panneau-gauche" style="background:var(--th-ink,#191B20); color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:48px; text-align:center;">
        @if ($entreprise?->logoUrl())
            <div style="background:#fff; border-radius:12px; padding:14px 26px; display:inline-block; margin-bottom:22px;">
                <img src="{{ $entreprise->logoUrl() }}" alt="{{ $entreprise->nom }}" style="width:210px; display:block;">
            </div>
        @else
            <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:40px; font-weight:700; text-transform:uppercase; letter-spacing:2px; margin:0 0 22px;">
                {{ $entreprise?->nom ?? config('app.name') }}
            </h1>
        @endif

        <span style="background:var(--th-ambre,#D97706); color:#fff; font-size:13.5px; font-weight:700; border-radius:99px; padding:7px 18px;">
            La solution pour mieux gérer
        </span>

        <h2 style="font-family:'Barlow Condensed',sans-serif; font-size:42px; font-weight:700; line-height:1.15; margin:26px 0 16px; max-width:14ch;">
            Pilotez votre entreprise avec intelligence
        </h2>

        <p style="color:#C7C9CF; font-size:15.5px; line-height:1.6; max-width:44ch; margin:0;">
            La plateforme intelligente pour vos prospections, devis, facturations,
            charges et trésorerie en temps réel, sur tous vos sites.
        </p>
    </div>

    {{-- Panneau formulaire. --}}
    <div style="display:flex; align-items:center; justify-content:center; padding:40px 24px;">
        <div class="carte" style="width:100%; max-width:430px; padding:30px; box-shadow:0 18px 44px rgba(25,27,32,.10);">

            <h2 style="font-family:'Barlow Condensed',sans-serif; font-size:30px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin:0; text-align:center; color:var(--th-ink,#191B20);">
                Connexion
            </h2>
            <p style="color:var(--th-gris,#6B6E76); font-size:14px; margin:6px 0 24px; text-align:center;">
                Connectez-vous pour accéder à votre tableau de bord
            </p>

            @if ($errors->any())
                <div class="encart encart-alerte">
                    @foreach ($errors->all() as $erreur)
                        <div>{{ $erreur }}</div>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div class="encart encart-succes">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf

                <label for="email" class="champ-libelle">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username" placeholder="vous@entreprise.ci" class="champ" style="margin-bottom:16px;">

                <label for="password" class="champ-libelle">Mot de passe</label>
                <div class="champ-mot-de-passe">
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password" placeholder="••••••••" class="champ">
                    <x-oeil-mot-de-passe />
                </div>

                <div style="display:flex; align-items:center; justify-content:space-between; margin:16px 0 20px; font-size:13.5px; gap:10px; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:7px; color:#4B4E55; cursor:pointer;">
                        <input type="checkbox" name="remember"> Se souvenir de moi
                    </label>
                    <a href="{{ route('password.request') }}" style="color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">Mot de passe oublié ?</a>
                </div>

                <button type="submit" class="bouton bouton-sombre" style="width:100%; justify-content:center; padding:12px;">
                    Se connecter
                </button>
            </form>

            <div style="display:flex; align-items:center; gap:12px; margin:20px 0;">
                <span style="flex:1; height:1px; background:var(--th-ligne,#E2E0D8);"></span>
                <span style="font-size:12.5px; color:#9A9DA5;">ou</span>
                <span style="flex:1; height:1px; background:var(--th-ligne,#E2E0D8);"></span>
            </div>

            <a href="{{ route('auth.google') }}"
               style="display:flex; align-items:center; justify-content:center; gap:10px; width:100%; box-sizing:border-box; padding:11px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; text-decoration:none; color:var(--th-ink,#191B20); font-size:14.5px; font-weight:600; background:#fff;">
                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.5 13.2l7.9 6.1C12.3 13.2 17.7 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-2.8-.4-4.1H24v7.4h12.7c-.3 2.1-1.6 5.3-4.7 7.4l7.6 5.9c4.5-4.2 7.1-10.4 7.1-16.6z"/>
                    <path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3.1-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.8l7.9-6.1z"/>
                    <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2 1.4-4.8 2.4-8.3 2.4-6.3 0-11.7-3.7-13.6-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/>
                </svg>
                Se connecter avec Google
            </a>

            <p style="text-align:center; font-size:13.5px; color:var(--th-gris,#6B6E76); margin:22px 0 0;">
                Vous êtes un collaborateur ?
                <a href="{{ route('inscription.personnel') }}" style="color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">Rejoindre avec un code entreprise</a>
            </p>
        </div>
    </div>
</div>

</body>
</html>
