@props(['lot', 'peutArreter' => false])

@php
    use Modules\Noyau\Imports\Services\SuiviDuTraitement;

    $suivi = SuiviDuTraitement::etat($lot);
@endphp

{{-- L'avancée d'un import en cours, et le seul geste qu'on y pose : l'arrêter.

     **Il n'y a plus de bouton pour lancer la lecture.** Elle démarre d'elle-même au dépôt
     (voir LanceurDeTraitement) : « Traiter maintenant » et « Tout traiter » servaient à faire
     ce qu'on venait déjà de demander, et ils ont disparu avec le défaut qui les rendait
     nécessaires.

     La barre avance vers la longueur que le classeur annonce dans son en-tête. Quand elle n'est
     pas connue, elle bouge sans proportion plutôt que d'en inventer une. --}}
<div>
    @if ($lot->etat === 'depose')
        <div style="font-family:'Barlow Condensed',sans-serif; font-size:24px; font-weight:700;">
            La lecture démarre…
        </div>
        <div class="imp-jauge grande sans-fin"><i></i></div>

        @if ($suivi['attente'] > 90)
            {{-- Le lancement immédiat a échoué sur ce serveur (processus interdit, PHP en ligne
                 de commande introuvable). Deux secours s'en occupent sans qu'on ait rien à faire :
                 la tâche planifiée, et la relance que cet écran vient de tenter lui-même — voir
                 SuiviDuTraitement::reveiller(). On le dit plutôt que d'offrir un bouton qui ferait
                 semblant de régler le problème. --}}
            <div class="imp-hint warn">
                Le fichier attend depuis {{ (int) ceil($suivi['attente'] / 60) }} minute(s). La lecture n'a pas
                démarré d'elle-même : cet écran vient de la relancer, et la tâche planifiée du serveur
                passe de son côté chaque minute. Laissez la page ouverte quelques instants. Si rien ne
                bouge, prévenez l'administrateur — le réglage <code>IMPORT_PHP_CLI</code> est décrit dans
                MISE-A-JOUR-SERVEUR.md — ou annulez ce dépôt et redéposez le fichier.
            </div>
        @endif
    @elseif ($lot->etat === 'en_cours')
        @php
            $pourcentage = $suivi['pourcentage'];
        @endphp

        <div style="display:flex; justify-content:space-between; align-items:baseline; gap:10px; flex-wrap:wrap;">
            <div style="font-family:'Barlow Condensed',sans-serif; font-size:24px; font-weight:700;">
                {{ number_format($suivi['lues'], 0, ',', ' ') }}
                @if ($suivi['estimees'])
                    <span style="color:#6B6E76; font-weight:600;">/ {{ number_format($suivi['estimees'], 0, ',', ' ') }}</span>
                @endif
                ligne(s) lue(s)
            </div>
            @if ($pourcentage !== null)
                <div style="font-family:'Barlow Condensed',sans-serif; font-size:24px; font-weight:700; color:#C8102E;">
                    {{ $pourcentage }} %
                </div>
            @endif
        </div>

        <div class="imp-jauge grande {{ $pourcentage === null ? 'sans-fin' : '' }}"
             role="progressbar" aria-valuemin="0" aria-valuemax="100"
             @if ($pourcentage !== null) aria-valuenow="{{ $pourcentage }}" @endif>
            <i @if ($pourcentage !== null) style="width:{{ max(2, $pourcentage) }}%;" @endif></i>
        </div>

        @if ($suivi['arret_demande'])
            <div class="imp-hint warn">
                Arrêt demandé : la lecture s'interrompt à la centaine de lignes suivante, et tout ce
                qu'elle avait commencé d'écrire est défait.
            </div>
        @elseif ($suivi['interrompu'])
            <div class="imp-hint warn">
                La lecture ne donne plus signe de vie depuis plus de dix minutes : le processus a
                probablement été coupé par le serveur. <strong>Rien n'a été écrit</strong> — un import
                ne s'enregistre qu'en entier. Réimportez le fichier depuis son détail.
            </div>
        @else
            <div class="imp-hint">
                Le total est celui que le classeur annonce ; les lignes vides de fin de feuille ne sont
                pas comptées, la barre peut donc finir avant cent pour cent. Vous pouvez quitter la
                page : la lecture continue. <strong>Rien n'est écrit en base avant la dernière ligne</strong>
                — un import s'enregistre en entier ou pas du tout.
            </div>
        @endif
    @endif

    @if ($peutArreter && $lot->estEnTravail() && ! $suivi['arret_demande'])
        <form method="POST" action="{{ route('import.lot.agir', $lot->id) }}" class="imp-actions">
            @csrf
            <input type="hidden" name="geste" value="arreter">
            <button type="submit" class="imp-btn o"
                    data-confirmer-titre="Annuler cet import"
                    data-confirmer="La lecture s'arrête, et rien de ce fichier n'est enregistré."
                    data-confirmer-detail="Tout ce que la lecture avait commencé d'écrire est défait : la base revient à son état d'avant le dépôt. Le fichier reste déposé, vous pourrez le relancer depuis son détail."
                    data-confirmer-libelle="Annuler l'import"
                    data-confirmer-ton="alerte">
                Annuler l'import
            </button>
        </form>
    @endif
</div>
