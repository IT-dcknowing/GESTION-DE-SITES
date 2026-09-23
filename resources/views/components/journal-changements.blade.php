@props(['changements', 'creation' => false])

{{--
    Ce qu'une trace du journal a changé, dans une cellule de tableau.

    **Pourquoi un composant plutôt que trois copies.** Trois écrans affichent le même
    journal — le détail d'une créance, une pièce fournisseur, le référentiel — et la règle
    d'affichage vient de se corriger le 24/09. Écrite trois fois, elle se serait corrigée
    une fois et demie.

    **La règle.** Une création se lit comme un état posé, « Ville : Abidjan », sans flèche
    et sans « vide » à gauche : personne n'a remplacé un vide, la fiche est née ainsi.
    Une modification se lit en avant → après, parce que là, quelque chose a bien été
    remplacé. Et quand une trace ne montre aucun changement, on le dit — au lieu de
    laisser une cellule vide qu'on prendrait pour un défaut d'affichage.
--}}
@forelse ($changements as $changement)
    <div>
        <strong>{{ $changement['champ'] }}</strong> :
        @if ($changement['pose'] ?? false)
            {{ $changement['apres'] }}
        @else
            {{ $changement['avant'] }} → {{ $changement['apres'] }}
        @endif
    </div>
@empty
    <span style="color:#9A9DA5;">
        {{ $creation ? 'créée sans valeur renseignée' : "rien n'a été modifié" }}
    </span>
@endforelse
