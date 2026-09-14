@extends('errors.enveloppe')
@section('titre', 'Session expirée')
@section('contenu')
    <span class="code">Session expirée</span>
    <h1>Votre session a expiré</h1>
    <p>
        Cette page est restée ouverte trop longtemps sans activité. Par sécurité,
        l'application a refusé d'enregistrer une action venue d'une session périmée.
    </p>
    <p class="gris">
        C'est volontaire&nbsp;: sans cela, un formulaire laissé ouvert sur un poste partagé
        resterait actionnable par la personne suivante. Reconnectez-vous et reprenez.
    </p>
    <div class="actions">
        <a class="bouton" href="{{ url('/') }}">Se reconnecter</a>
    </div>
@endsection
