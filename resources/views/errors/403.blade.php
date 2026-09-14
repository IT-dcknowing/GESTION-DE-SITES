@extends('errors.enveloppe')
@section('titre', 'Accès refusé')
@section('contenu')
    <span class="code">Accès refusé</span>
    <h1>Cet écran ne vous est pas ouvert</h1>
    <p>
        Votre compte n'a pas les droits nécessaires pour consulter cette page. Ce n'est pas
        une panne&nbsp;: l'application protège chaque écran selon le rôle de chacun.
    </p>
    <p class="gris">
        Si vous pensez devoir y accéder, demandez à votre responsable de faire ouvrir ce
        droit&nbsp;: cela se règle en une minute depuis la gestion des accès.
    </p>
    <div class="actions">
        <a class="bouton" href="{{ url('/') }}">Revenir à mon espace</a>
    </div>
@endsection
