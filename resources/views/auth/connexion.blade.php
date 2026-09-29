@php
    $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first();
    $logo = $entreprise?->logoUrl() ?? asset('logos/artisan-automobile.png');
@endphp
{{--
    L'écran de connexion — refait le 29/09 sur la maquette du propriétaire.

    **Ce qui change, et pourquoi.** L'ancien écran posait un panneau noir à gauche et une
    carte blanche à droite : correct, mais anonyme — c'était l'écran de n'importe quelle
    application. La maquette fait l'inverse : elle met la **marque** en premier, sur un fond
    clair quadrillé, avec le logo en grand et les objets de l'atelier autour. On sait où
    l'on arrive avant d'avoir lu un mot.

    **Le décor est dessiné, pas photographié** — voir `partials/decor-atelier`. C'est la
    première page que l'on voit, souvent sur un réseau lent : quatre images, c'est quatre
    allers-retours avant que la page ne soit entière.

    **Ce qui n'a pas changé, et ne devait pas.** Le formulaire poste toujours vers Fortify,
    le jeton CSRF est là, l'œil du mot de passe aussi, et la connexion Google et l'entrée
    par code entreprise restent offertes. Une page de connexion qui perd une de ses portes
    enferme quelqu'un dehors.

    **Le champ reste l'adresse électronique.** La maquette écrit « Email ou identifiant » ;
    l'authentification, elle, ne reconnaît que l'adresse (`FortifyServiceProvider`). Écrire
    « ou identifiant » ferait essayer un code qui ne marchera pas, et l'on chercherait la
    panne du côté du mot de passe. Le jour où la connexion par code sera ouverte, ce libellé
    suivra.
--}}
<!DOCTYPE html>
<html lang="fr" style="{{ collect($entreprise?->theme() ?? [])->map(fn ($v, $k) => "$k:$v")->implode(';') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — {{ $entreprise?->nom ?? config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ $logo }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .cnx {
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            min-height: 100vh;
            position: relative;
            overflow: hidden;
            /* Le quadrillage de la maquette, et la lueur rouge en haut à gauche. Deux
               dégradés superposés : rien à télécharger. */
            background:
                radial-gradient(900px 620px at 0% 0%, rgba(200,16,46,.13), rgba(200,16,46,0) 62%),
                linear-gradient(0deg, rgba(0,0,0,.035) 1px, transparent 1px) 0 0 / 100% 78px,
                linear-gradient(90deg, rgba(0,0,0,.035) 1px, transparent 1px) 0 0 / 78px 100%,
                #FBFAF9;
        }

        .cnx-gauche {
            position: relative;
            padding: 64px 56px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .cnx-titre {
            font-family: 'Barlow Condensed', sans-serif;
            font-weight: 800;
            font-size: clamp(38px, 5.2vw, 68px);
            line-height: .98;
            letter-spacing: -1px;
            margin: 0 0 18px;
            color: var(--th-ink, #191B20);
            max-width: 13ch;
        }
        /* « intelligence » en italique sérif, comme la maquette. */
        .cnx-titre em {
            font-family: Georgia, 'Times New Roman', serif;
            font-style: italic;
            font-weight: 400;
            letter-spacing: -1px;
        }

        .cnx-accroche {
            font-style: italic;
            color: #55585F;
            font-size: clamp(14px, 1.15vw, 16.5px);
            line-height: 1.6;
            max-width: 46ch;
            margin: 0;
        }

        .cnx-logo { margin-top: clamp(26px, 6vh, 64px); width: min(430px, 72%); }
        .cnx-logo img { width: 100%; display: block; }

        .cnx-droite {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 32px;
        }
        .cnx-carte { width: 100%; max-width: 470px; }

        .cnx-bonjour {
            font-family: 'Barlow Condensed', sans-serif;
            font-weight: 800;
            font-size: clamp(32px, 3.4vw, 46px);
            letter-spacing: -.5px;
            margin: 0 0 4px;
            color: var(--th-ink, #191B20);
        }
        .cnx-sous { color: #2563EB; font-size: 15px; margin: 0 0 26px; }

        .cnx-label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: var(--th-ink, #191B20);
            margin-bottom: 7px;
        }
        .cnx-champ {
            width: 100%;
            box-sizing: border-box;
            padding: 14px 15px;
            font-size: 15px;
            font-family: inherit;
            border: 1px solid #D9DCE1;
            border-radius: 9px;
            background: #fff;
            color: var(--th-ink, #191B20);
        }
        .cnx-champ:focus { outline: 2px solid var(--th-accent, #C8102E); outline-offset: 1px; border-color: transparent; }

        .cnx-bouton {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 15px;
            margin-top: 22px;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: .6px;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(90deg, #8E0A1F 0%, var(--th-accent, #C8102E) 45%, #E01B34 100%);
            box-shadow: 0 10px 24px rgba(200,16,46,.28);
        }
        .cnx-bouton:hover { filter: brightness(1.06); }

        /* Le décor : posé en absolu, et jamais sous le texte. */
        .decor { position: absolute; pointer-events: none; }
        .decor-voiture { top: 26px; left: 26px; width: 108px; }
        .decor-bougie { left: 4%; bottom: 20%; width: 42px; transform: rotate(-16deg); }
        .decor-cle { left: 26%; bottom: 7%; width: 210px; transform: rotate(-6deg); }
        .decor-trousseau { right: 3%; bottom: 5%; width: 62px; transform: rotate(12deg); }

        @media (max-width: 1000px) {
            .cnx { grid-template-columns: 1fr; }
            /* Le décor disparaît avant le contenu : il est ornemental, et la place
               manquante doit servir au formulaire. */
            .cnx-gauche { padding: 40px 26px 8px; }
            .cnx-logo { width: min(320px, 68%); }
            .decor-bougie, .decor-cle, .decor-trousseau { display: none; }
            .decor-voiture { width: 76px; top: 16px; left: 16px; }
        }
        @media (max-width: 560px) {
            .cnx-gauche { display: none; }
            .cnx-droite { padding: 28px 20px; }
        }
    </style>
</head>
<body class="antialiased" style="margin:0; font-family:var(--font-sans); background:#FBFAF9;">

<div class="cnx">

    {{-- ─────────────────────────────── la marque --}}
    <div class="cnx-gauche">
        @include('auth.partials.decor-atelier')

        <h1 class="cnx-titre">Pilotez votre entreprise avec <em>intelligence</em></h1>

        <p class="cnx-accroche">
            La plateforme intelligente pour vos prospections, devis, facturations,
            charges et trésorerie en temps réel, sur tous vos sites.
        </p>

        <div class="cnx-logo">
            <img src="{{ $logo }}" alt="{{ $entreprise?->nom ?? "L'Artisan Automobile" }}">
        </div>
    </div>

    {{-- ─────────────────────────────── le formulaire --}}
    <div class="cnx-droite">
        <div class="cnx-carte">

            <h2 class="cnx-bonjour">Ravis de vous revoir&nbsp;!</h2>
            <p class="cnx-sous">Connectez-vous pour accéder à votre logiciel</p>

            @if ($errors->any())
                <div class="encart encart-alerte" style="margin-bottom:18px;">
                    @foreach ($errors->all() as $erreur)
                        <div>{{ $erreur }}</div>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div class="encart encart-succes" style="margin-bottom:18px;">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf

                <label for="email" class="cnx-label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username" placeholder="vous@entreprise.ci" class="cnx-champ">

                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin:18px 0 7px;">
                    <label for="password" class="cnx-label" style="margin:0;">Mot de passe</label>
                    <a href="{{ route('password.request') }}"
                       style="font-size:13.5px; font-style:italic; color:#55585F; text-decoration:none;">Mot de passe oublié&nbsp;?</a>
                </div>

                <div class="champ-mot-de-passe">
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password" placeholder="••••••••" class="cnx-champ">
                    <x-oeil-mot-de-passe />
                </div>

                <label style="display:flex; align-items:center; gap:8px; margin-top:14px; font-size:13.5px; color:#4B4E55; cursor:pointer;">
                    <input type="checkbox" name="remember"> Se souvenir de moi
                </label>

                <button type="submit" class="cnx-bouton">Se connecter</button>
            </form>

            <div style="display:flex; align-items:center; gap:12px; margin:20px 0;">
                <span style="flex:1; height:1px; background:#E2E0D8;"></span>
                <span style="font-size:12.5px; color:#9A9DA5;">ou</span>
                <span style="flex:1; height:1px; background:#E2E0D8;"></span>
            </div>

            <a href="{{ route('auth.google') }}"
               style="display:flex; align-items:center; justify-content:center; gap:10px; width:100%; box-sizing:border-box; padding:12px; border:1px solid #D9DCE1; border-radius:9px; text-decoration:none; color:var(--th-ink,#191B20); font-size:14.5px; font-weight:600; background:#fff;">
                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.5 13.2l7.9 6.1C12.3 13.2 17.7 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-2.8-.4-4.1H24v7.4h12.7c-.3 2.1-1.6 5.3-4.7 7.4l7.6 5.9c4.5-4.2 7.1-10.4 7.1-16.6z"/>
                    <path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3.1-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.8l7.9-6.1z"/>
                    <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2 1.4-4.8 2.4-8.3 2.4-6.3 0-11.7-3.7-13.6-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/>
                </svg>
                Se connecter avec Google
            </a>

            <p style="text-align:center; font-size:14px; color:#55585F; margin:24px 0 0;">
                Pas encore de compte&nbsp;?
                <a href="{{ route('inscription.personnel') }}"
                   style="color:var(--th-ink,#191B20); font-weight:800; text-decoration:none;">Créez-en un&nbsp;!</a>
            </p>
        </div>
    </div>
</div>

</body>
</html>
