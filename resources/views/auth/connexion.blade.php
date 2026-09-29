@php
    $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first();
    $favicon = $entreprise?->logoUrl() ?? asset('logos/artisan-automobile.png');
@endphp
{{--
    L'écran de connexion, repris de la maquette fournie le 29/09
    (`VERSION-2-3/connexion-artisan-automobile (2).html`).

    **Ce qui avait raté au premier essai, et que le propriétaire a relevé.** J'avais redessiné
    le logo au lieu d'employer le rendu 3D, et posé les outils en absolu sur toute la
    colonne : au rendu, le logo passait par-dessus la bougie et la clé. Deux erreurs de
    nature différente —

    1. **Le rendu 3D n'était pas reproductible en SVG**, et ne devait pas l'être. Il est
       dans la maquette, et il en sort : `public/logos/logo-3d.jpg`.
    2. **Les outils étaient ancrés sur la colonne**, alors que le logo vit dans le flux. Un
       élément posé en absolu ne réserve aucune place : dès que le titre change de hauteur,
       il passe dessous ou dessus. Ils sont maintenant ancrés **sur le bloc du logo**, et à
       l'extérieur de sa boîte — ils ne peuvent plus le rencontrer.

    **Le rendu 3D est posé en `mix-blend-mode: multiply`**, comme dans la maquette. C'est
    ce qui fait disparaître son fond noir sur le papier clair, sans qu'on ait à détourer
    l'image. Le blanc du fond reste blanc, le noir couvre : le logo paraît flotter.

    **Il pèse 82 Ko au lieu de 2,3 Mo.** Il arrivait en PNG de 1 536 px. C'est la première
    image de la première page, souvent sur un réseau lent ; comme elle est posée en
    `multiply`, elle n'a aucune transparence à préserver, et le JPEG rend exactement la même
    chose. 900 px de large : le bloc ne dépasse jamais 400 px à l'écran, et l'on double pour
    les écrans à forte densité.

    **La typographie est celle de la maquette** — Plus Jakarta Sans, en 800 pour les titres,
    avec « intelligence » en italique. `display=swap` : le texte paraît immédiatement dans
    la police de repli plutôt que d'attendre le téléchargement.

    **Ce qui n'a pas changé, et ne devait pas.** Le formulaire poste vers Fortify, le jeton
    CSRF est là, l'œil du mot de passe aussi, et la connexion Google comme l'entrée par code
    entreprise restent offertes. Une page de connexion qui perd une de ses portes enferme
    quelqu'un dehors.
--}}
<!DOCTYPE html>
<html lang="fr" style="{{ collect($entreprise?->theme() ?? [])->map(fn ($v, $k) => "$k:$v")->implode(';') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — {{ $entreprise?->nom ?? config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ $favicon }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,800;1,800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Les valeurs de la maquette, reprises telles quelles. */
        .cnx {
            --red: #D7191F; --red-dk: #B01218; --ink: #161719;
            --muted: #6B6E73; --grid: rgba(22,23,25,.07);
            --police: 'Plus Jakarta Sans', var(--font-sans);
        }

        .cnx-fond, .cnx-halo { position: fixed; pointer-events: none; }

        /* Le quadrillage s'efface vers le bas : il tient le haut de page sans encombrer
           le formulaire. */
        .cnx-fond {
            inset: 0;
            background-image:
                linear-gradient(var(--grid) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid) 1px, transparent 1px);
            background-size: 36px 36px;
            -webkit-mask-image: linear-gradient(180deg, #000 0%, #000 55%, transparent 100%);
            mask-image: linear-gradient(180deg, #000 0%, #000 55%, transparent 100%);
        }
        .cnx-halo { border-radius: 50%; filter: blur(70px); }
        .cnx-halo.a { top: -260px; left: -240px; width: 640px; height: 640px;
            background: radial-gradient(circle, rgba(215,25,31,.18), transparent 68%); }
        .cnx-halo.b { bottom: -320px; right: -200px; width: 720px; height: 720px;
            background: radial-gradient(circle, rgba(42,44,47,.14), transparent 68%); }

        .cnx {
            position: relative; z-index: 2; min-height: 100vh;
            display: grid; grid-template-columns: 1.1fr 1fr;
            align-items: center; gap: 40px;
            padding: 48px clamp(24px, 6vw, 110px);
            font-family: var(--police);
            color: var(--ink);
        }

        /* ───────────────────────────────────────────────── la marque */
        .cnx-gauche h1 {
            font-family: var(--police);
            font-weight: 800;
            font-size: clamp(34px, 4.5vw, 66px);
            line-height: 1.04;
            letter-spacing: -.04em;
            max-width: 13ch;
            margin: 0;
        }
        .cnx-gauche h1 em { font-style: italic; }

        .cnx-accroche {
            color: var(--muted);
            font-size: clamp(14px, 1.1vw, 16.5px);
            line-height: 1.6;
            letter-spacing: -.01em;
            max-width: 44ch;
            margin: 16px 0 0;
        }

        /* Le bloc du logo, et les outils qui s'y accrochent. Ils sont posés **par rapport
           à lui** : un élément en absolu ne réserve aucune place, et ancré plus haut il
           finissait par passer sous le logo dès que le titre changeait de hauteur. */
        .cnx-visuel { position: relative; width: min(400px, 100%); margin: 30px 0 14px; }
        .cnx-visuel img {
            width: 100%; height: auto; display: block;
            mix-blend-mode: multiply;
            filter: drop-shadow(0 22px 26px rgba(22,23,25,.16));
        }

        .cnx-stat { margin: 6px 0 0; letter-spacing: -.02em; }
        .cnx-stat strong { display: block; font-weight: 800; font-size: 44px; line-height: 1; letter-spacing: -.04em; }
        .cnx-stat span { font-size: 18px; color: var(--muted); }

        .outil { position: absolute; pointer-events: none; }
        /* À gauche du logo, et entièrement hors de sa boîte : `right:100%` garantit qu'ils
           ne se rencontrent jamais, quelle que soit la largeur. */
        .outil-bougie { right: 100%; top: 22%; width: 38px; margin-right: 6px; transform: rotate(-14deg); }
        /* Sous le logo, décalée à droite : la maquette la pose dans le creux de la roue. */
        .outil-cle { left: 46%; top: 100%; width: 190px; margin-top: -26px; transform: rotate(-8deg); }
        /* Ces deux-là appartiennent à la page, pas au logo. */
        .outil-voiture { position: fixed; top: 24px; left: 24px; width: 96px; z-index: 3; }
        .outil-trousseau { position: fixed; right: 3vw; bottom: 4vh; width: 58px; z-index: 3; transform: rotate(12deg); }

        /* ───────────────────────────────────────────────── le formulaire */
        .cnx-droite { display: flex; justify-content: center; }
        .cnx-panneau { width: 100%; max-width: 470px; }

        .cnx-panneau h2 {
            font-weight: 800; font-size: clamp(30px, 3.3vw, 50px);
            line-height: 1.06; letter-spacing: -.04em; margin: 0 0 10px;
        }
        .cnx-sous { color: #2563EB; font-size: 17px; letter-spacing: -.02em; margin: 0 0 28px; }

        .cnx-label { display: block; font-size: 14.5px; font-weight: 700; margin-bottom: 8px; }

        .cnx .champ-connexion {
            width: 100%; height: 58px; box-sizing: border-box;
            border: 1.5px solid transparent; border-radius: 12px;
            background: #fff; box-shadow: 0 2px 14px rgba(22,23,25,.06);
            padding: 0 20px; font: 500 16.5px var(--police); color: var(--ink);
            transition: border-color .2s, box-shadow .2s;
        }
        .cnx .champ-connexion::placeholder { color: #9A9CA1; }
        .cnx .champ-connexion:focus {
            outline: none; border-color: var(--red);
            box-shadow: 0 0 0 4px rgba(215,25,31,.15);
        }

        .cnx-oubli { font-size: 14px; color: var(--muted); text-decoration: none; }
        .cnx-oubli:hover { color: var(--red); }

        .cnx-go {
            width: 100%; height: 58px; border: 0; border-radius: 12px; margin-top: 22px;
            background: var(--red); color: #fff;
            font: 800 18px var(--police); letter-spacing: -.02em; cursor: pointer;
            box-shadow: 0 10px 26px rgba(215,25,31,.28);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .cnx-go:hover { background: var(--red-dk); transform: translateY(-2px); box-shadow: 0 14px 32px rgba(215,25,31,.36); }

        .cnx-pied { text-align: center; margin: 30px 0 0; font-size: 16px; color: var(--muted); letter-spacing: -.02em; }
        .cnx-pied a { color: var(--ink); font-weight: 800; text-decoration: none; }
        .cnx-pied a:hover { color: var(--red); }

        @media (max-width: 900px) {
            .cnx { grid-template-columns: 1fr; gap: 34px; padding: 30px 22px 44px; }
            /* Le formulaire d'abord : c'est ce qu'on vient faire. */
            .cnx-droite { order: -1; }
            .cnx-gauche h1 { font-size: 30px; max-width: none; }
            /* Le décor est ornemental : la place manquante revient au formulaire. */
            .cnx-visuel, .cnx-stat, .outil-voiture, .outil-trousseau { display: none; }
        }
    </style>
</head>
<body class="antialiased" style="margin:0; background:#FBFAF9; overflow-x:hidden;">

<div class="cnx-fond" aria-hidden="true"></div>
<div class="cnx-halo a" aria-hidden="true"></div>
<div class="cnx-halo b" aria-hidden="true"></div>

<main class="cnx">

    {{-- ─────────────────────────────── la marque --}}
    <section class="cnx-gauche" aria-label="Présentation">
        <h1>Pilotez votre entreprise avec <em>intelligence</em></h1>

        <p class="cnx-accroche">
            La plateforme intelligente pour vos prospections, devis, facturations,
            charges et trésorerie en temps réel, sur tous vos sites.
        </p>

        <div class="cnx-visuel">
            {{-- Le rendu 3D de la maquette. `multiply` efface son fond noir sur le papier
                 clair, sans détourage. --}}
            <img src="{{ asset('logos/logo-3d.jpg') }}" alt="" aria-hidden="true">

            @include('auth.partials.decor-atelier', ['dans' => 'visuel'])
        </div>

        <p class="cnx-stat">
            <strong>+500</strong>
            <span>véhicules entretenus en 2026&nbsp;!</span>
        </p>
    </section>

    {{-- ─────────────────────────────── le formulaire --}}
    <section class="cnx-droite">
        <div class="cnx-panneau">

            <h2>Ravis de vous revoir&nbsp;!</h2>
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
                       autocomplete="username" placeholder="vous@entreprise.ci" class="champ-connexion">

                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin:20px 0 8px;">
                    <label for="password" class="cnx-label" style="margin:0;">Mot de passe</label>
                    <a href="{{ route('password.request') }}" class="cnx-oubli">Mot de passe oublié&nbsp;?</a>
                </div>

                <div class="champ-mot-de-passe">
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password" placeholder="••••••••" class="champ-connexion">
                    <x-oeil-mot-de-passe />
                </div>

                <label style="display:flex; align-items:center; gap:8px; margin-top:16px; font-size:14px; color:#4B4E55; cursor:pointer;">
                    <input type="checkbox" name="remember"> Se souvenir de moi
                </label>

                <button type="submit" class="cnx-go">Se connecter</button>
            </form>

            <div style="display:flex; align-items:center; gap:12px; margin:22px 0;">
                <span style="flex:1; height:1px; background:#E2E0D8;"></span>
                <span style="font-size:12.5px; color:#9A9DA5;">ou</span>
                <span style="flex:1; height:1px; background:#E2E0D8;"></span>
            </div>

            <a href="{{ route('auth.google') }}"
               style="display:flex; align-items:center; justify-content:center; gap:10px; width:100%; box-sizing:border-box; padding:13px; border:1px solid #E3E0D8; border-radius:12px; text-decoration:none; color:#161719; font-size:15px; font-weight:600; background:#fff; box-shadow:0 2px 14px rgba(22,23,25,.06);">
                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.5 13.2l7.9 6.1C12.3 13.2 17.7 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-2.8-.4-4.1H24v7.4h12.7c-.3 2.1-1.6 5.3-4.7 7.4l7.6 5.9c4.5-4.2 7.1-10.4 7.1-16.6z"/>
                    <path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3.1-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.8l7.9-6.1z"/>
                    <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2 1.4-4.8 2.4-8.3 2.4-6.3 0-11.7-3.7-13.6-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/>
                </svg>
                Se connecter avec Google
            </a>

            <p class="cnx-pied">
                Pas encore de compte&nbsp;?
                <a href="{{ route('inscription.personnel') }}">Créez-en un&nbsp;!</a>
            </p>
        </div>
    </section>
</main>

</body>
</html>
