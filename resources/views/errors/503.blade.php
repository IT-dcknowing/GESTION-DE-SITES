@extends('errors.enveloppe')
@section('titre', 'Maintenance en cours')
@section('contenu')
    <span class="code">Maintenance</span>
    <h1>Cette page est en maintenance</h1>
    <p>
        <b>Notre équipe technique s'en charge.</b> L'accès revient de lui-même dès
        l'intervention terminée&nbsp;; il n'y a rien à faire de votre côté.
    </p>
    <p class="gris">
        Vos données ne sont pas touchées pendant une maintenance&nbsp;: seule la
        consultation est suspendue.
    </p>
    <div class="actions">
        <a class="bouton discret" href="{{ url()->current() }}">Réessayer</a>
    </div>
@endsection
