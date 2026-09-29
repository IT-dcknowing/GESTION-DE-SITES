@php
    $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first();
    $favicon = $entreprise?->logoUrl() ?? asset('logos/artisan-automobile.png');
@endphp
{{--
    L'écran de connexion, repris de la maquette
    `VERSION-2-3/connexion-artisan-automobile (2).html` et de son rendu.

    ─────────────────────────────────────────────────────────────────────────────────────

    **Le premier essai était faux sur les deux points que le propriétaire a relevés**, et
    les deux venaient d'une même erreur de méthode : j'avais pris le fichier de maquette
    pour la cible, alors qu'il n'en est que la mise en page.

    **1. Le logo paraissait dans une boîte noire.** Le fichier de maquette pose son rendu 3D
    en `mix-blend-mode: multiply`, et j'ai recopié la règle sans vérifier ce qu'elle donne.
    `multiply` multiplie la source par le fond : sur un papier clair, un fond **noir** reste
    noir. La maquette elle-même affiche donc ce rectangle. Ce qu'il fallait, c'était
    **détourer** le logo. Voir plus bas.

    **2. Les outils n'étaient pas les bons, et ne pouvaient pas l'être.** « Les outils
    appliqués ne sont pas les vrais » — c'est exact : je les avais **redessinés en SVG**,
    faute de les trouver en fichier. Ils n'y sont pas : le fichier de maquette ne contient
    que deux images, le rendu du logo sur fond noir et un petit logo secondaire. La bougie,
    la clé plate, la voiture et le trousseau n'existent que dans le rendu que le
    propriétaire m'a montré.

    ─────────────────────────────────────────────────────────────────────────────────────

    **D'où viennent les images, et comment.** Elles sont découpées du rendu, par
    `outils/detourer-les-images-du-rendu.py` : un remplissage qui part des bords de l'image
    et n'avance que vers un voisin de couleur proche. Il traverse ainsi le quadrillage et les halos, qui
    varient doucement, et s'arrête net au bord d'un objet, qui est une rupture. Un seuil de
    clarté n'aurait pas marché : le chrome de la clé plate est presque aussi clair que le
    papier.

    - `atelier-logo.png` — la bougie, le logo et la clé **en un seul morceau**. Le rendu les
      a composés ; les séparer obligerait à replacer à la main ce qui est déjà en place.
    - `atelier-voiture.png` et `atelier-cles.png` — ces deux-là appartiennent à la page, pas
      au logo : ils tiennent les deux coins, en `fixed`.

    **Elles pèsent 107 Ko à elles trois**, ramenées à 256 couleurs : c'est la première image
    de la première page, souvent sur un réseau lent, et sur un rendu 3D l'écart avec le
    fichier plein ne se voit pas — comparé côte à côte avant de trancher.

    **Plus aucun outil dessiné, et plus rien en absolu sur la colonne.** C'est ce qui
    produisait les chevauchements : un élément en absolu ne réserve aucune place, et dès que
    le titre changeait de hauteur il passait sous le logo. Les trois outils du groupe sont
    maintenant *dans* l'image, à la place exacte que le rendu leur donne.

    ─────────────────────────────────────────────────────────────────────────────────────

    **Ce que je n'ai pas repris du rendu, et pourquoi.** Le libellé y est « Email ou
    identifiant ». L'application n'authentifie que sur l'adresse — `config/fortify.php`
    déclare `'username' => 'email'`, et `FortifyServiceProvider` cherche l'utilisateur par
    `where('email', …)`. Promettre un identifiant ferait essayer un code d'atelier, qui ne
    peut qu'échouer sans dire pourquoi.

    **Ce que j'ai gardé alors que le rendu ne les montre pas** : « Se souvenir de moi » et
    l'entrée par Google. Les retirer fermerait la porte à qui s'est inscrit par Google — une
    page de connexion qui perd une de ses portes enferme quelqu'un dehors. Ils sont posés
    sous le bouton, dans la même langue visuelle, et se retirent d'une ligne si vous les
    voulez dehors.
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

    {{-- Le logo ouvre la page : il est demandé avant que le CSS ne le réclame. --}}
    <link rel="preload" as="image" href="{{ asset('logos/atelier-logo.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Les valeurs viennent de la maquette ; les tailles qui s'en écartent viennent du
           rendu, mesurées dessus. */
        .cnx {
            --red: #D7191F; --red-dk: #B01218; --ink: #161719;
            --muted: #6B6E73; --grid: rgba(22,23,25,.07);
            --police: 'Plus Jakarta Sans', var(--font-sans, system-ui), sans-serif;
        }

        .cnx-fond, .cnx-halo, .cnx-coin { position: fixed; pointer-events: none; }

        /* Le quadrillage s'efface vers le bas : il tient le haut de page sans encombrer le
           formulaire. */
        .cnx-fond {
            inset: 0; z-index: 0;
            background-image:
                linear-gradient(var(--grid) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid) 1px, transparent 1px);
            background-size: 36px 36px;
            -webkit-mask-image: linear-gradient(180deg, #000 0%, #000 55%, transparent 100%);
            mask-image: linear-gradient(180deg, #000 0%, #000 55%, transparent 100%);
        }

        .cnx-halo { z-index: 0; border-radius: 50%; filter: blur(70px); }
        .cnx-halo.a { top: -260px; left: -240px; width: 640px; height: 640px;
            background: radial-gradient(circle, rgba(215,25,31,.18), transparent 68%); }
        .cnx-halo.b { bottom: -320px; right: -200px; width: 720px; height: 720px;
            background: radial-gradient(circle, rgba(42,44,47,.14), transparent 68%); }

        /* Les deux objets des coins. Découpés du rendu, donc posés tels quels : aucune
           rotation, aucune ombre ajoutée — le rendu les porte déjà. */
        .cnx-coin { z-index: 3; height: auto; }
        .cnx-coin-voiture { top: 22px; left: 20px; width: 104px; }
        .cnx-coin-cles { right: 22px; bottom: 26px; width: 62px; }

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
            font-style: italic;
            font-size: clamp(14px, 1.15vw, 16.5px);
            line-height: 1.6;
            letter-spacing: -.01em;
            max-width: 42ch;
            margin: 18px 0 0;
        }

        /* Le groupe d'outils est une seule image, dans le flux : rien à ancrer, donc rien
           qui puisse chevaucher quoi que ce soit. */
        .cnx-visuel {
            display: block;
            width: min(560px, 92%);
            height: auto;
            margin: 34px 0 0;
            filter: drop-shadow(0 22px 26px rgba(22,23,25,.14));
        }

        /* ───────────────────────────────────────────────── le formulaire */
        .cnx-droite { display: flex; justify-content: center; }
        .cnx-panneau { width: 100%; max-width: 540px; }

        .cnx-panneau h2 {
            font-weight: 800; font-size: clamp(30px, 3.6vw, 56px);
            line-height: 1.06; letter-spacing: -.04em; margin: 0 0 8px;
        }
        .cnx-sous { color: #2563EB; font-size: 18px; letter-spacing: -.02em; margin: 0 0 30px; }

        .cnx-label { display: block; font-size: 15px; font-weight: 700; margin: 0 0 9px; }

        /* `.champ-connexion` et non `.champ` : la classe commune de l'application porte
           `width:100%` avec d'autres hauteurs, et les deux se marcheraient dessus. */
        .cnx .champ-connexion {
            width: 100%; height: 60px; box-sizing: border-box;
            border: 1.5px solid transparent; border-radius: 12px;
            background: #fff; box-shadow: 0 2px 14px rgba(22,23,25,.06);
            padding: 0 20px; font: 500 17px var(--police); color: var(--ink);
            transition: border-color .2s, box-shadow .2s;
        }
        .cnx .champ-connexion::placeholder { color: #9A9CA1; }
        .cnx .champ-connexion:focus {
            outline: none; border-color: var(--red);
            box-shadow: 0 0 0 4px rgba(215,25,31,.15);
        }

        .cnx-ligne-label {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 10px; margin: 26px 0 9px;
        }
        .cnx-oubli { font-size: 14.5px; font-style: italic; color: var(--muted); text-decoration: none; }
        .cnx-oubli:hover { color: var(--red); }

        .cnx-go {
            width: 100%; height: 58px; border: 0; border-radius: 12px; margin-top: 26px;
            background: var(--red); color: #fff;
            font: 800 18px var(--police); letter-spacing: -.02em; cursor: pointer;
            box-shadow: 0 10px 26px rgba(215,25,31,.28);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .cnx-go:hover { background: var(--red-dk); transform: translateY(-2px); box-shadow: 0 14px 32px rgba(215,25,31,.36); }

        .cnx-memoire {
            display: flex; align-items: center; gap: 9px; margin-top: 16px;
            font-size: 14.5px; color: #4B4E55; cursor: pointer;
        }

        .cnx-ou { display: flex; align-items: center; gap: 12px; margin: 22px 0; }
        .cnx-ou span { flex: 1; height: 1px; background: #E4E1DA; }
        .cnx-ou b { font-weight: 500; font-size: 12.5px; color: #9A9DA5; }

        .cnx-google {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%; box-sizing: border-box; padding: 14px;
            border: 1px solid #E3E0D8; border-radius: 12px; background: #fff;
            box-shadow: 0 2px 14px rgba(22,23,25,.06);
            text-decoration: none; color: var(--ink); font-size: 15px; font-weight: 600;
        }
        .cnx-google:hover { border-color: #C9C5BB; }

        .cnx-pied { text-align: center; margin: 32px 0 0; font-size: 17px; color: var(--muted); letter-spacing: -.02em; }
        .cnx-pied a { color: var(--ink); font-weight: 800; text-decoration: none; }
        .cnx-pied a:hover { color: var(--red); }

        .cnx :focus-visible { outline: 3px solid var(--ink); outline-offset: 3px; }

        @media (max-width: 900px) {
            .cnx { grid-template-columns: 1fr; gap: 32px; padding: 30px 22px 44px; }
            /* Le formulaire d'abord : c'est ce qu'on vient faire. */
            .cnx-droite { order: -1; }
            .cnx-gauche h1 { font-size: 30px; max-width: none; }
            /* Le décor est ornemental : la place qu'il prend revient au formulaire, et les
               deux objets des coins tomberaient sur le texte à cette largeur. */
            .cnx-visuel, .cnx-coin { display: none; }
        }
    </style>
</head>
<body class="antialiased" style="margin:0; background:#FBFAF9; overflow-x:hidden;">

<div class="cnx-fond" aria-hidden="true"></div>
<div class="cnx-halo a" aria-hidden="true"></div>
<div class="cnx-halo b" aria-hidden="true"></div>

<img class="cnx-coin cnx-coin-voiture" src="{{ asset('logos/atelier-voiture.png') }}" alt="" aria-hidden="true">
<img class="cnx-coin cnx-coin-cles" src="{{ asset('logos/atelier-cles.png') }}" alt="" aria-hidden="true">

<main class="cnx">

    {{-- ─────────────────────────────── la marque --}}
    <section class="cnx-gauche" aria-label="Présentation">
        <h1>Pilotez votre entreprise avec <em>intelligence</em></h1>

        <p class="cnx-accroche">
            La plateforme intelligente pour vos prospections, devis, facturations,
            charges et trésorerie en temps réel, sur tous vos sites.
        </p>

        {{-- La bougie, le logo et la clé, détourés du rendu en un seul morceau. --}}
        <img class="cnx-visuel" src="{{ asset('logos/atelier-logo.png') }}" alt="" aria-hidden="true">
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

                <div class="cnx-ligne-label">
                    <label for="password" class="cnx-label" style="margin:0;">Mot de passe</label>
                    <a href="{{ route('password.request') }}" class="cnx-oubli">Mot de passe oublié&nbsp;?</a>
                </div>

                <div class="champ-mot-de-passe">
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password" placeholder="••••••••" class="champ-connexion">
                    <x-oeil-mot-de-passe />
                </div>

                <label class="cnx-memoire">
                    <input type="checkbox" name="remember"> Se souvenir de moi
                </label>

                <button type="submit" class="cnx-go">Se connecter</button>
            </form>

            <div class="cnx-ou" aria-hidden="true"><span></span><b>ou</b><span></span></div>

            <a href="{{ route('auth.google') }}" class="cnx-google">
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
