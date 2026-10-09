{{--
    La panne : ce que voit un utilisateur quand quelque chose casse.

    **Ce qu'elle remplace.** Laravel affichait jusqu'ici sa page de diagnostic — trace
    complète, nom de la base, chemins du serveur, en-têtes de la requête. C'est un outil
    de développement, et il était public. Un visiteur y lisait la carte du serveur.

    **Ce qu'elle dit désormais.** Une phrase à l'utilisateur, qui n'a rien à faire d'une
    trace d'exécution, et une référence courte. Cette référence est écrite au journal avec
    la panne : elle est le fil qui relie ce que la personne a vu à ce que le serveur a
    enregistré. « J'ai eu ERR-4F2A9C » vaut mieux qu'une capture d'écran.

    **Et le détail, sous un bouton.** Replié, jamais affiché d'emblée, et montré aux seuls
    yeux qui doivent le voir — voir `bootstrap/app.php`, qui décide de `$detail`.
--}}
@extends('errors.enveloppe')

@section('titre', 'Page en maintenance')

@section('contenu')
    <span class="code">Interruption momentanée</span>

    <h1>Cette page est en maintenance</h1>

    {{-- La phrase « Une opération n'a pas abouti et nous avons préféré interrompre
         l'affichage… » a été retirée le 09/10, à la demande du propriétaire : elle
         inquiétait sans rien apprendre à personne. --}}
    <p class="gris">
        Rien de ce que vous avez enregistré n'est perdu&nbsp;: une saisie n'est écrite que
        lorsqu'elle a abouti entièrement. Si vous étiez en train de remplir un formulaire,
        reprenez-le depuis l'écran précédent.
    </p>

    @if (! empty($reference))
        <div class="reference">
            Référence de l'incident&nbsp;: <b>{{ $reference }}</b><br>
            <span class="gris">
                Communiquez-la à votre administrateur&nbsp;: il la retrouve dans la page
                Maintenance, rubrique «&nbsp;Incidents&nbsp;».
            </span>
        </div>
    @endif

    <div class="actions">
        <a class="bouton" href="{{ url('/') }}">Revenir à l'accueil</a>
        <a class="bouton discret" href="{{ url()->current() }}">Réessayer cette page</a>
    </div>

    @if (! empty($detail) && ! empty($reference) && \Illuminate\Support\Facades\Route::has('super-admin.incident'))
        <p style="margin-top:12px;"><a class="bouton discret" href="{{ route('super-admin.incident', $reference) }}">Ouvrir la fiche de l'incident</a></p>
    @endif

    @if (! empty($detail) && ! empty($exception))
        <details class="detail">
            <summary>Voir le détail technique</summary>
            <div class="corps-detail">
                <dl>
                    <dt>Type</dt>
                    <dd>{{ get_class($exception) }}</dd>

                    <dt>Message</dt>
                    <dd>{{ $exception->getMessage() ?: '—' }}</dd>

                    <dt>Origine</dt>
                    <dd>{{ $exception->getFile() }}&nbsp;: ligne {{ $exception->getLine() }}</dd>

                    <dt>Pile d'appels</dt>
                    <dd><pre class="trace">{{ $trace ?? '' }}</pre></dd>
                </dl>
            </div>
        </details>
    @endif
@endsection
