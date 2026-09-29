{{--
    L'enveloppe commune à toutes les pages d'erreur.

    **Pourquoi elle ne ressemble à aucun autre écran.** Une page d'erreur s'affiche
    précisément quand l'application ne va pas bien — et parfois quand la base de données
    est injoignable. Tout ce qu'elle touche doit donc être inerte : pas de mise en page
    partagée, pas de composant, pas de `@vite`, pas de `auth()`, pas une requête. Le style
    est écrit ici, en clair, et l'illustration est dessinée dans le fichier. Une page
    d'erreur qui déclenche une erreur ne laisse plus aucune sortie.

    Elle n'a pas non plus de menu : quelqu'un qui tombe ici n'est peut-être pas connecté,
    et lui proposer des liens qui redemanderont la session ne ferait que rejouer la panne.

    ─────────────────────────────────────────────────────────────────────────────────

    **Refaite le 29/09 : « je n'aime pas cette présentation ».** Elle était juste et
    muette — une feuille blanche, un titre, un paragraphe. Le propriétaire a demandé qu'elle
    ressemble à la page de connexion, avec un ouvrier au casque jaune et des outils, le
    message que l'équipe technique s'en charge, et les coordonnées pour joindre le service.

    Ce n'est pas de la décoration. Une page d'erreur a deux choses à faire, et elle n'en
    faisait qu'une : **dire ce qui se passe**, et **dire à qui s'adresser**. La seconde
    manquait, et c'est celle qu'on cherche quand on est bloqué à neuf heures du matin.

    **Les coordonnées viennent de la configuration**, jamais de la base — voir
    `config/assistance.php`. Les y chercher reviendrait à rejouer la panne au moment
    précis où l'on demande de l'aide. Non renseignées, la page renvoie au responsable de
    l'application plutôt que d'afficher un numéro inventé.
--}}
@php
    $courriel = config('assistance.courriel');
    $telephones = (array) config('assistance.telephones', []);
    $horaires = config('assistance.horaires');
@endphp
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
            color: var(--ink);
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.55;
            /* Le même fond que l'écran de connexion : la lueur rouge et le quadrillage.
               Deux dégradés superposés, rien à télécharger — ce qui compte double sur une
               page qui s'affiche quand le serveur va mal. */
            background:
                radial-gradient(760px 520px at 0% 0%, rgba(200,16,46,.13), rgba(200,16,46,0) 62%),
                linear-gradient(0deg, rgba(0,0,0,.035) 1px, transparent 1px) 0 0 / 100% 70px,
                linear-gradient(90deg, rgba(0,0,0,.035) 1px, transparent 1px) 0 0 / 70px 100%,
                #FBFAF9;
        }
        .feuille {
            width: 100%; max-width: 720px; background: #FFF;
            border: 1px solid var(--ligne); border-radius: 16px;
            padding: 34px 32px; box-shadow: 0 18px 44px rgba(25,27,32,.10);
        }

        /* L'ouvrier : dessiné, pour la même raison que le reste — la page doit tenir
           debout quand tout le reste est tombé. */
        .scene { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; margin-bottom: 20px; }
        .scene svg { flex: 0 0 auto; width: 138px; height: auto; }
        .scene-mots { flex: 1 1 260px; min-width: 240px; }

        .assistance {
            margin-top: 22px; padding: 16px 18px; border-radius: 10px;
            background: #FFF7E6; border: 1px solid #F0DFB8;
        }
        .assistance h2 { margin: 0 0 8px; font-size: 14.5px; letter-spacing: .2px; }
        .assistance a { color: var(--accent); font-weight: 700; text-decoration: none; }
        .assistance ul { margin: 0; padding-left: 18px; font-size: 14px; }
        .assistance li { margin: 3px 0; }
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

        <div class="scene">
            {{-- L'ouvrier au casque jaune, une clé à la main. Purement ornemental :
                 `aria-hidden`, et jamais porteur d'information. --}}
            <svg viewBox="0 0 150 170" aria-hidden="true" focusable="false">
                <ellipse cx="75" cy="160" rx="52" ry="7" fill="#000" opacity=".07"/>
                <!-- le casque -->
                <path d="M39 48a36 36 0 0 1 72 0v5H39z" fill="#F5B301"/>
                <path d="M69 13h12v34H69z" fill="#E0A200"/>
                <rect x="31" y="47" width="88" height="9" rx="4.5" fill="#FFC71F"/>
                <!-- le visage -->
                <path d="M52 56h46v22a23 23 0 0 1-46 0z" fill="#C98B5E"/>
                <circle cx="65" cy="70" r="3" fill="#2B2F36"/>
                <circle cx="86" cy="70" r="3" fill="#2B2F36"/>
                <path d="M68 82a10 10 0 0 0 15 0" stroke="#2B2F36" stroke-width="2.4" fill="none" stroke-linecap="round"/>
                <!-- la tenue -->
                <path d="M46 100h58a14 14 0 0 1 14 14v34H32v-34a14 14 0 0 1 14-14z" fill="#C8102E"/>
                <path d="M62 100h26l-6 16h-14z" fill="#A40C24"/>
                <rect x="32" y="126" width="86" height="7" fill="#8E0A1F"/>
                <!-- le bras et la clé -->
                <path d="M114 110c12 4 20 12 22 22" stroke="#C98B5E" stroke-width="12" fill="none" stroke-linecap="round"/>
                <g transform="translate(120 124) rotate(28)">
                    <path d="M0 9a9 9 0 0 1 9-9h3v4H9a5 5 0 0 0 0 10h3v4H9a9 9 0 0 1-9-9z" fill="#B9BEC7"/>
                    <rect x="10" y="5" width="34" height="8" rx="4" fill="#D8DCE2"/>
                    <path d="M44 9a8 8 0 0 1 8-8h4v3.5h-4a4.5 4.5 0 0 0 0 9h4V17h-4a8 8 0 0 1-8-8z" fill="#B9BEC7"/>
                </g>
            </svg>

            <div class="scene-mots">
                @yield('contenu')
            </div>
        </div>

        {{-- **Dire à qui s'adresser**, et c'est la moitié qui manquait. Une page d'erreur
             qui explique sans donner de sortie laisse seul celui qui est bloqué. --}}
        <div class="assistance">
            <h2>Besoin d'aide tout de suite&nbsp;?</h2>

            @if ($courriel || $telephones)
                <ul>
                    @if ($courriel)
                        <li>Écrivez à <a href="mailto:{{ $courriel }}">{{ $courriel }}</a></li>
                    @endif
                    @foreach ($telephones as $telephone)
                        <li>Appelez le <a href="tel:{{ preg_replace('/[^0-9+]/', '', $telephone) }}">{{ $telephone }}</a></li>
                    @endforeach
                </ul>
                @if ($horaires)
                    <p class="gris" style="margin:8px 0 0;">Service joignable {{ $horaires }}.</p>
                @endif
            @else
                {{-- Renseignées nulle part : on renvoie au responsable de l'application
                     plutôt que d'afficher un numéro inventé. Un numéro faux sur une page
                     d'erreur fait perdre un appel au moment où il compte le plus. --}}
                <p style="margin:0; font-size:14px;">
                    Adressez-vous au <b>responsable de l'application</b> de votre entreprise.
                    <span class="gris">(Les coordonnées de l'assistance se renseignent dans la
                    configuration du serveur — voir <code>config/assistance.php</code>.)</span>
                </p>
            @endif
        </div>
    </main>
</body>
</html>
