@extends('errors.enveloppe')
@section('titre', 'Page introuvable')
@section('contenu')
    <span class="code">Adresse inconnue</span>
    <h1>Cette page n'existe pas</h1>
    <p>
        L'adresse demandée ne correspond à aucun écran de l'application. Elle a peut-être
        été modifiée, ou le lien que vous avez suivi est ancien.
    </p>
    <div class="actions">
        <a class="bouton" href="{{ url('/') }}">Revenir à l'accueil</a>
    </div>
@endsection
