@extends('errors.enveloppe')
@section('titre', 'Maintenance en cours')
@section('contenu')
    <span class="code">Maintenance planifiée</span>
    <h1>L'application est en cours de mise à jour</h1>
    <p>
        Une intervention est en cours sur le serveur. L'accès revient de lui-même dès
        qu'elle est terminée&nbsp;; il n'y a rien à faire de votre côté.
    </p>
    <p class="gris">
        Vos données ne sont pas touchées pendant une mise à jour&nbsp;: seule la
        consultation est suspendue.
    </p>
    <div class="actions">
        <a class="bouton discret" href="{{ url()->current() }}">Réessayer</a>
    </div>
@endsection
