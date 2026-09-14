@php $entreprise = \Modules\Noyau\Entreprises\Modeles\Entreprise::query()->where('est_active', true)->first(); @endphp

{{-- Confirmer son mot de passe avant un geste sensible.

     **Pourquoi cet écran existe.** Fortify enregistre la route `/user/confirm-password`
     dès que les vues sont activées, qu'on lui en fournisse une ou non. Sans vue déclarée,
     l'adresse répondait une erreur 500 — mesuré pour les huit rôles. Aucun geste de
     l'application n'exige aujourd'hui cette confirmation, mais une adresse publique qui
     rend une erreur serveur est une invitation à chercher pourquoi : elle expose la trace
     d'un conteneur mal câblé là où l'on attend une page.

     L'écran est donc réel et fonctionne. Le jour où une action méritera d'être reconfirmée
     — changer une adresse de connexion, ouvrir un accès — il suffira de poser le
     middleware `password.confirm` dessus. --}}

<x-coquille-auth :entreprise="$entreprise" titre="Confirmez votre mot de passe"
    sous-titre="Cette page demande une seconde vérification avant de continuer.">

    @if ($errors->any())
        <div class="encart encart-alerte">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <label for="password" class="champ-libelle">Mot de passe</label>
        <input id="password" name="password" type="password" required autofocus
               autocomplete="current-password" class="champ">

        <button type="submit" class="bouton bouton-sombre"
                style="width:100%; justify-content:center; padding:12px; margin-top:18px;">
            Confirmer
        </button>
    </form>

    <p style="text-align:center; font-size:13.5px; color:var(--th-gris,#6B6E76); margin:20px 0 0;">
        <a href="{{ route('redirection') }}"
           style="color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">← Revenir à mon espace</a>
    </p>
</x-coquille-auth>
