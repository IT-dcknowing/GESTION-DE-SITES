@php
    $navigation = auth()->user() ? \Modules\Noyau\Commun\Services\MenuNavigation::pour(auth()->user()) : [];
    $exerciceActuel = auth()->user()?->entreprise_id
        ? \Modules\Noyau\Entreprises\Modeles\Exercice::actuel(auth()->user()->entreprise_id)
        : null;
@endphp
<!DOCTYPE html>
<html lang="fr" style="{{ collect(auth()->user()?->entreprise?->theme() ?? [])->map(fn ($v, $k) => "$k:$v")->implode(';') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- **Le titre nomme l'écran, pas seulement l'entreprise.**

         Les cinquante et un écrans portaient tous le même titre d'onglet. Avec une
         vingtaine d'onglets ouverts — c'est le cas ici — ils deviennent indiscernables :
         on les rouvre un par un pour retrouver celui qu'on cherchait, et l'historique du
         navigateur ne sert plus à rien.

         Le nom vient du registre qui sert déjà au journal de navigation : un seul endroit
         nomme les écrans, et un écran ajouté demain reçoit un nom lisible sans qu'on ait
         pensé à revenir ici. --}}
    @php
        $nomDeLEcran = \Modules\Noyau\Tracabilite\Services\NomDEcran::pour(
            request()->route()?->getName(),
            request()->path(),
        );
        $maison = auth()->user()?->entreprise?->nom ?? config('app.name');
    @endphp
    <title>{{ $title ?? $nomDeLEcran.' — '.$maison }}</title>
    @if (auth()->user()?->entreprise?->logoUrl())
        <link rel="icon" type="image/png" href="{{ auth()->user()->entreprise->logoUrl() }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="antialiased" style="background:var(--th-paper, #F4F3EF); color:var(--th-ink, #191B20); font-family:var(--font-sans); min-height:100vh; margin:0;">

    {{-- Avant tout le reste : quand on assiste quelqu'un sous son identité, on voit
         exactement ce qu'il voit, et l'on oublie où l'on est. L'avertissement doit être
         le premier élément de la page, et il ne se replie pas. --}}
    <x-bandeau-switch />

    <header style="background:var(--th-ink, #191B20); color:#fff;">
        <div style="max-width:1680px; margin:0 auto; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; min-height:56px;">
            <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
                <a href="{{ route('redirection') }}" wire:navigate style="display:flex; align-items:center; gap:10px; text-decoration:none;">
                    @if (auth()->user()?->entreprise?->logoUrl())
                        <img src="{{ auth()->user()->entreprise->logoUrl() }}" alt="" style="height:34px; background:#fff; border-radius:5px; padding:3px 8px;">
                    @else
                        <span style="color:#fff; font-weight:800; font-size:17px;">{{ config('app.name') }}</span>
                    @endif
                </a>
                <nav style="display:flex; gap:4px; flex-wrap:wrap;">
                    @foreach ($navigation as $item)
                        @if (isset($item['groupe']))
                            <details class="nav-groupe" name="nav-groupe">
                                <summary class="{{ $item['actif'] ? 'nav-actif' : '' }}"
                                   style="display:flex; align-items:center; gap:6px; padding:9px 14px; border-radius:7px; font-size:14.5px; font-weight:600; white-space:nowrap; cursor:pointer; list-style:none;">
                                    {{ $item['label'] }} <span style="font-size:10px;">▾</span>
                                </summary>
                                <div class="nav-groupe-panneau">
                                    @foreach ($item['groupe'] as $sousItem)
                                        <a href="{{ $sousItem['route'] }}" wire:navigate class="{{ $sousItem['actif'] ? 'nav-actif' : '' }}"
                                           style="display:block; padding:9px 14px; font-size:14px; font-weight:600; text-decoration:none; white-space:nowrap;
                                                  color:{{ $sousItem['actif'] ? 'var(--th-accent, #C8102E)' : 'var(--th-ink, #191B20)' }};">
                                            {{ $sousItem['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            <a href="{{ $item['route'] }}" wire:navigate class="{{ $item['actif'] ? 'nav-actif' : '' }}"
                               style="display:flex; align-items:center; gap:6px; padding:9px 14px; border-radius:7px; font-size:14.5px; font-weight:600; text-decoration:none; white-space:nowrap;
                                      color:{{ $item['actif'] ? '#fff' : '#C7C9CF' }};
                                      background:{{ $item['actif'] ? 'var(--th-accent, #C8102E)' : 'transparent' }};">
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </nav>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                {{-- Le badge d'exercice est devenu un sélecteur d'année : c'est lui qui
                     décide, pour toute l'application, l'exercice qu'on regarde. Ouvert à
                     tous les rôles, parce que consulter une année passée n'est pas un acte
                     d'administration — cela ne modifie rien. --}}
                @if ($exerciceActuel)
                    <livewire:commun.selecteur-exercice />
                @endif
                <livewire:cloche-notifications />
                <a href="{{ route('mon-profil') }}" wire:navigate
                   style="font-size:13.5px; color:#C7C9CF; text-decoration:none; display:flex; align-items:center; gap:8px;">
                    <x-avatar :utilisateur="auth()->user()" :taille="28" />
                    {{ auth()->user()?->name }}
                    @if (auth()->user()?->entreprise)
                        — {{ auth()->user()->entreprise->nom }}
                    @endif
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" style="background:transparent; border:1px solid #4B4E55; color:#fff; border-radius:7px; padding:8px 16px; font-size:13.5px; font-weight:600; cursor:pointer;">
                        Déconnexion
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main style="max-width:1680px; margin:0 auto; padding:20px 16px;">
        <livewire:rappel-notifications />
        {{ $slot }}
    </main>

    {{-- Posés une seule fois : tout écran peut annoncer un geste ou poser une question
         sans embarquer sa propre boîte. --}}
    <x-annonce-ephemere />
    <x-confirmation />

    {{-- Un import se termine pendant qu'on travaille ailleurs : la veille l'annonce là où
         la personne se trouve, et se tait quand il n'y a rien à dire. --}}
    <x-veille-imports />

    {{-- « Est-ce bien votre code dans l'atelier ? » Posée là où la personne se trouve, une
         fois, parce qu'un code mal attribué envoie son travail dans le mauvais atelier sans
         que rien ne le signale. Elle se tait dès qu'on y a répondu. --}}
    <x-code-atelier />

    @livewireScripts

    {{-- Après le script : si celui-ci manque, ce témoin est le seul à pouvoir le dire. --}}
    <x-veille-livewire />
</body>
</html>
