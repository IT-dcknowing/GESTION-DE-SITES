{{--
    L'enveloppe commune à toutes les pages d'erreur.

    **Pourquoi elle ne ressemble à aucun autre écran.** Une page d'erreur s'affiche
    précisément quand l'application ne va pas bien — et parfois quand la base de données
    est injoignable. Tout ce qu'elle touche doit donc être inerte : pas de mise en page
    partagée, pas de composant, pas de `@vite`, pas de `auth()`. Le style est écrit ici,
    en clair. Une page d'erreur qui déclenche une erreur ne laisse plus aucune sortie.

    Elle n'a pas non plus de menu : quelqu'un qui tombe ici n'est peut-être pas connecté,
    et lui proposer des liens qui redemanderont la session ne ferait que rejouer la panne.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre') — L'Artisan Automobile</title>
    <style>
        :root {
            --ink: #191B20; --paper: #F4F3EF; --ligne: #E2E0D8;
            --gris: #6B6E76; --accent: #C8102E; --ambre: #D97706;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center;
            justify-content: center; padding: 24px;
            background: var(--paper); color: var(--ink);
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.55;
        }
        .feuille {
            width: 100%; max-width: 640px; background: #FFF;
            border: 1px solid var(--ligne); border-radius: 12px;
            padding: 34px 32px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .marque {
            font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase;
            color: var(--gris); margin: 0 0 18px;
        }
        .code {
            display: inline-block; font-size: 12px; font-weight: 700;
            letter-spacing: 1px; color: var(--ambre);
            background: #FFF7E6; border: 1px solid #F0DFB8;
            border-radius: 20px; padding: 3px 12px; margin-bottom: 14px;
        }
        h1 { font-size: 23px; margin: 0 0 12px; letter-spacing: -.3px; }
        p  { margin: 0 0 14px; font-size: 14.5px; }
        .gris { color: var(--gris); font-size: 13px; }
        .reference {
            margin: 20px 0 0; padding: 12px 14px; background: var(--paper);
            border: 1px solid var(--ligne); border-radius: 8px; font-size: 13px;
        }
        .reference b { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; letter-spacing: 1px; }
        .actions { margin-top: 22px; display: flex; flex-wrap: wrap; gap: 10px; }
        .bouton {
            display: inline-block; text-decoration: none; font-size: 13.5px; font-weight: 600;
            padding: 9px 18px; border-radius: 7px; border: 1px solid var(--ink);
            background: var(--ink); color: #FFF;
        }
        .bouton.discret { background: #FFF; color: var(--ink); border-color: var(--ligne); }

        /* Le détail technique : replié par défaut, ouvert d'un clic, sans une ligne de script. */
        details.detail {
            margin-top: 24px; border: 1px solid var(--ligne);
            border-radius: 8px; background: var(--paper); overflow: hidden;
        }
        details.detail > summary {
            cursor: pointer; padding: 11px 15px; font-size: 13px; font-weight: 600;
            list-style: none; user-select: none;
        }
        details.detail > summary::-webkit-details-marker { display: none; }
        details.detail > summary::before { content: "▸ "; color: var(--gris); }
        details.detail[open] > summary::before { content: "▾ "; }
        details.detail[open] > summary { border-bottom: 1px solid var(--ligne); background: #FFF; }
        .corps-detail { padding: 14px 15px; }
        .corps-detail dt { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--gris); margin-top: 12px; }
        .corps-detail dt:first-child { margin-top: 0; }
        .corps-detail dd {
            margin: 4px 0 0; font-size: 12.5px; word-break: break-word;
            font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        }
        pre.trace {
            margin: 6px 0 0; padding: 11px; background: #FFF; border: 1px solid var(--ligne);
            border-radius: 6px; font-size: 11.5px; line-height: 1.5;
            max-height: 300px; overflow: auto; white-space: pre-wrap; word-break: break-all;
        }
        @media (max-width: 480px) { .feuille { padding: 26px 20px; } h1 { font-size: 20px; } }
    </style>
</head>
<body>
    <main class="feuille">
        <p class="marque">L'Artisan Automobile</p>
        @yield('contenu')
    </main>
</body>
</html>
