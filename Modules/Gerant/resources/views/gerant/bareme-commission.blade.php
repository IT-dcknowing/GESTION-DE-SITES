<?php

use Illuminate\Support\Carbon;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;
use Modules\Noyau\Exploitation\Modeles\BaremeCommission;
use Modules\Noyau\Exploitation\Services\CommissionCommerciale;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Barème de commission — les deux grilles, telles qu'elles sont au document
|--------------------------------------------------------------------------
| **Réservé au gérant.** Un barème décide de ce que quelqu'un touche à la fin du mois ; il
| n'appartient ni au commercial qu'il rémunère, ni au responsable qui l'anime.
|
| **Deux sections, deux grilles**, comme dans le document : celle des commerciaux, celle du
| responsable commercial et de son adjoint. Elles sont toujours visibles — tant que rien
| n'est enregistré pour l'exercice, on affiche la grille de référence, prête à être
| corrigée. L'écran dit laquelle des deux il montre.
|
| **Deux gestes, et ils ne font pas la même chose.** Arrêté par le propriétaire le
| 24/09/2026, et c'est la clé de cet écran.
|
| *Première activation* pose la grille au **1er janvier de l'exercice**. C'est le geste
| qu'on fait une fois : le barème n'est pas une décision du jour, c'est la règle sur
| laquelle on se base depuis le début de l'année, et les fichiers qu'on importe couvrent
| l'année entière. La poser à la date du jour laisserait les mois déjà importés sans
| barème — donc sans commission — alors que le barème existait, écrit dans un document,
| bien avant qu'on l'ait saisi ici.
|
| *Enregistrer* pose la grille **à la date du jour**. C'est le geste de la correction : un
| barème peut changer en cours d'année, et ce jour-là il ne doit pas recalculer les mois
| déjà annoncés aux commerciaux. La grille d'avant reste derrière elle.
|
| Le bouton de première activation disparaît dès que le début de l'exercice est couvert :
| il n'a plus rien à faire, et un bouton qui ne fait rien se clique quand même.
|
| **Tout se modifie ici, rien n'est écrit dans le code** : les tranches, les taux, la façon
| de compter. Un changement enregistré agit dès l'affichage suivant de l'écran des
| commerciaux, sans cache et sans déploiement.
*/

state(['exercice' => null]);

// Les tranches affichées, section par section. Elles vivent dans l'état du composant le
// temps de la saisie : c'est ce qui permet d'ajouter une ligne, d'en corriger trois, et de
// n'enregistrer qu'une fois. Rien n'est écrit avant le clic sur « Enregistrer ».
state(['grilles' => []]);

// Le formulaire d'ajout, ouvert sous le tableau d'une section et d'une seule.
state(['ajoutOuvert' => null]);
state(['nouveauPlancher' => '']);
state(['nouveauPlafond' => '']);
state(['nouveauTaux' => '']);

// Les notes, repliées ; elles s'ouvrent section par section.

state(['message' => '']);
state(['erreur' => '']);

mount(function () {
    $this->exercice = ExerciceDeTravail::annee(auth()->user()) ?? now()->year;
    $this->chargerLesGrilles();
});

/** Recharge les deux grilles depuis la base — ou, à défaut, depuis le document. */
$chargerLesGrilles = protect(function () {
    $this->grilles = [];

    foreach (array_keys(BaremeCommission::CIBLES) as $cible) {
        $this->grilles[$cible] = CommissionCommerciale::grilleAMontrer(
            auth()->user()->entreprise_id, $cible, (int) $this->exercice,
        );
    }
});

/** Ce qui est réellement enregistré, par catégorie — pour dire si l'on montre le document. */
$enregistrees = computed(function () {
    $trouvees = [];

    foreach (array_keys(BaremeCommission::CIBLES) as $cible) {
        $trouvees[$cible] = CommissionCommerciale::grilleDeLaCible(
            auth()->user()->entreprise_id,
            $cible,
            CommissionCommerciale::jourDeReference((int) $this->exercice),
        );
    }

    return $trouvees;
});

/**
 * Ce qui couvre le **premier jour** de l'exercice regardé, catégorie par catégorie.
 *
 * C'est ce qui décide de la présence du bouton « Première activation ». La question n'est
 * pas « une grille existe-t-elle ? » mais « l'année est-elle couverte depuis son premier
 * jour ? » — parce que c'est ce dont on a besoin pour que les imports de janvier comptent.
 *
 * Un barème court jusqu'à ce qu'un autre le remplace : une grille posée au 1er janvier
 * 2026 couvre aussi le 1er janvier 2027. Le bouton ne revient donc pas chaque année, et
 * c'est voulu — l'activation est un geste de démarrage, pas un rituel.
 *
 * @return array<string, bool>
 */
$couvrentLeDebutDeLExercice = computed(function () {
    $premierJour = Carbon::create((int) $this->exercice, 1, 1)->startOfDay();
    $rendu = [];

    foreach (array_keys(BaremeCommission::CIBLES) as $cible) {
        $rendu[$cible] = CommissionCommerciale::grilleDeLaCible(
            auth()->user()->entreprise_id, $cible, $premierJour,
        ) !== null;
    }

    return $rendu;
});

$exercices = computed(function () {
    $annees = ExerciceDeTravail::disponibles(auth()->user()->entreprise_id)
        ->pluck('annee')->map(fn ($a) => (int) $a)->sortDesc()->values()->all();

    return $annees === [] ? [now()->year => now()->year] : array_combine($annees, $annees);
});

$updatedExercice = function () {
    $this->ajoutOuvert = null;
    $this->message = '';
    $this->erreur = '';
    unset($this->enregistrees);
    $this->chargerLesGrilles();
};


$ouvrirLAjout = function (string $cible) {
    $this->ajoutOuvert = $this->ajoutOuvert === $cible ? null : $cible;
    $this->reset(['nouveauPlancher', 'nouveauPlafond', 'nouveauTaux']);
    $this->resetValidation();
};

$annulerLAjout = function () {
    $this->ajoutOuvert = null;
    $this->reset(['nouveauPlancher', 'nouveauPlafond', 'nouveauTaux']);
    $this->resetValidation();
};

/** Valide la tranche saisie sous le tableau et l'y ajoute — sans rien enregistrer encore. */
$validerLAjout = function () {
    $cible = $this->ajoutOuvert;

    if (! isset($this->grilles[$cible])) {
        return;
    }

    $donnees = $this->validate([
        'nouveauPlancher' => ['required', 'numeric', 'min:0'],
        'nouveauPlafond' => ['nullable', 'numeric', 'gt:nouveauPlancher'],
        'nouveauTaux' => ['required', 'numeric', 'min:0', 'max:100'],
    ], [], [
        'nouveauPlancher' => 'plancher', 'nouveauPlafond' => 'plafond', 'nouveauTaux' => 'taux',
    ]);

    $lignes = $this->grilles[$cible];
    $lignes[] = [
        'plancher' => (int) $donnees['nouveauPlancher'],
        'plafond' => ($donnees['nouveauPlafond'] === '' || $donnees['nouveauPlafond'] === null)
            ? null
            : (int) $donnees['nouveauPlafond'],
        'taux' => (float) $donnees['nouveauTaux'],
    ];

    // Les tranches se lisent de la plus basse à la plus haute : on les y remet tout de
    // suite, plutôt que de laisser le tableau dans un ordre que personne n'a voulu.
    usort($lignes, fn ($a, $b) => $a['plancher'] <=> $b['plancher']);

    $this->grilles[$cible] = $lignes;
    $this->ajoutOuvert = null;
    $this->reset(['nouveauPlancher', 'nouveauPlafond', 'nouveauTaux']);
    $this->message = 'Tranche ajoutée au tableau. Cliquez « Enregistrer » pour la conserver.';
};

$retirerLaTranche = function (string $cible, int $rang) {
    if (! isset($this->grilles[$cible][$rang])) {
        return;
    }

    $lignes = $this->grilles[$cible];
    unset($lignes[$rang]);

    $this->grilles[$cible] = array_values($lignes);
    $this->message = 'Tranche retirée du tableau. Cliquez « Enregistrer » pour la conserver.';
};

/**
 * Enregistre la grille d'une section, en bloc.
 *
 * Les contrôles sont faits ici et non à la saisie de chaque case : on corrige un tableau,
 * pas une cellule, et refuser une valeur à mi-chemin empêcherait d'en réécrire deux.
 */
/**
 * Enregistre une grille — et le geste dit lequel des trois on pose.
 *
 * **Trois gestes, arrêtés par le propriétaire les 24/09 matin et soir.**
 *
 * - `$premiereActivation` : la grille court depuis le **1er janvier de l'exercice**. C'est
 *   le démarrage, celui qui fait compter les imports déjà faits.
 * - `$rectification` : on **récrit la grille en vigueur à sa propre date**, sans en créer
 *   une seconde. C'est le geste du développeur ou du gérant qui s'aperçoit d'une faute de
 *   saisie : la grille devient ce qu'elle aurait dû être depuis le début, et il n'y a
 *   jamais eu deux barèmes. Rien n'est conservé de la version fautive, et c'est voulu —
 *   garder l'erreur à côté de sa correction ferait croire à deux décisions.
 * - Sans rien : c'est une **modification**, elle prend effet aujourd'hui, et la grille
 *   d'avant reste derrière elle pour les mois déjà arrêtés.
 */
$enregistrerLaGrille = function (string $cible, bool $premiereActivation = false, bool $rectification = false) {
    if (! isset($this->grilles[$cible])) {
        return;
    }

    $this->erreur = '';
    $lignes = [];

    foreach ($this->grilles[$cible] as $ligne) {
        $plancher = (int) ($ligne['plancher'] ?? 0);
        $plafond = ($ligne['plafond'] === null || $ligne['plafond'] === '') ? null : (int) $ligne['plafond'];
        $taux = (float) ($ligne['taux'] ?? 0);

        if ($plafond !== null && $plafond <= $plancher) {
            $this->erreur = "Une tranche dont le plafond n'est pas au-dessus du plancher ne veut rien dire.";

            return;
        }

        if ($taux < 0 || $taux > 100) {
            $this->erreur = 'Un taux se situe entre 0 et 100.';

            return;
        }

        $lignes[] = ['plancher' => $plancher, 'plafond' => $plafond, 'taux' => $taux];
    }

    if ($lignes === []) {
        $this->erreur = 'Une grille sans tranche ne calcule rien.';

        return;
    }

    usort($lignes, fn ($a, $b) => $a['plancher'] <=> $b['plancher']);

    /*
     * **La date d'effet, et pourquoi elle dépend du geste.**
     *
     * Une première activation pose la grille au 1er janvier de l'exercice : le barème est
     * la règle sur laquelle on se base depuis le début de l'année, et les fichiers qu'on
     * importe couvrent l'année entière. La poser à la date du jour laisserait les mois
     * déjà importés sans barème — donc sans commission — alors que le barème existait
     * avant qu'on le saisisse ici.
     *
     * Une correction, elle, prend effet aujourd'hui : les mois déjà arrêtés gardent la
     * grille sous laquelle ils l'ont été. C'est la règle du 24/09, et elle ne change pas.
     */
    $enVigueur = $this->enregistrees[$cible] ?? null;

    if ($premiereActivation) {
        $effet = Carbon::create((int) $this->exercice, 1, 1)->startOfDay();
    } elseif ($rectification && $enVigueur !== null) {
        // La date de la grille qu'on récrit : `enregistrer()` retrouve la ligne par sa
        // date d'effet et la remplace. Aucune seconde version n'apparaît.
        $effet = Carbon::parse($enVigueur->date_effet)->startOfDay();
    } else {
        $effet = null;
    }

    CommissionCommerciale::enregistrer(
        auth()->user(), $cible, (int) $this->exercice, $lignes, dateEffet: $effet,
    );

    unset($this->enregistrees, $this->couvrentLeDebutDeLExercice);
    $this->chargerLesGrilles();

    $entete = 'Grille « '.(BaremeCommission::CIBLES[$cible] ?? $cible)."\u{a0}» ";

    if ($premiereActivation) {
        $this->message = $entete.'activée au 1er janvier '.$this->exercice
            .'. Elle couvre l\'exercice entier, imports compris, et court jusqu\'à ce qu\'une '
            .'autre la remplace.';
    } elseif ($rectification && $enVigueur !== null) {
        $this->message = $entete.'rectifiée à sa date d\'origine, le '
            .Carbon::parse($enVigueur->date_effet)->format('d/m/Y')
            .'. Il n\'y a toujours qu\'une grille : c\'est celle-là, corrigée.';
    } else {
        $this->message = $entete.'modifiée, avec effet au '.now()->format('d/m/Y')
            .'. Les mois déjà arrêtés gardent la grille sous laquelle ils l\'ont été.';
    }
};

/**
 * Les notes du document, rendues à la section qu'elles concernent.
 *
 * Le propriétaire a relevé que les cinq phrases du document étaient mêlées et prêtaient à
 * confusion : deux d'entre elles ne valent que pour une seule grille, et le seuil qu'elles
 * annoncent n'est pas le même — 25 millions pour le responsable, 20 pour le commercial.
 * Elles sont donc rangées chacune sous sa section, et les deux phrases communes figurent
 * dans les deux.
 *
 * @return array<string, list<string>>
 */
$notes = computed(fn () => [
    'commercial' => [
        'À partir de 20 millions FCFA, une commission est versée avec un taux évolutif selon les tranches de CA.',
        'Le taux augmente progressivement de 1 % à 5 %, ce qui permet de récompenser davantage les performances les plus élevées.',
        "La commission estimée est calculée directement sur la tranche correspondante du chiffre d'affaires réalisé.",
    ],
    'responsable' => [
        "Aucune commission n'est appliquée pour un chiffre d'affaires inférieur à 25 millions FCFA — ce seuil est celui de ce poste, et non celui des commerciaux, qui commencent à 20 millions.",
        "Entre 25 et 30 millions, le document ne dit rien. Le taux d'entrée y est fixé à 1 %, "
        ."comme pour les commerciaux : sans lui, un responsable à 27 millions ne toucherait rien "
        ."tout en ayant dépassé le seuil écrit au-dessus de sa propre grille.",
        'Le taux augmente progressivement de 1 % à 5 %, ce qui permet de récompenser davantage les performances les plus élevées.',
        "La commission estimée est calculée directement sur la tranche correspondante du chiffre d'affaires réalisé.",
    ],
]);

?>

<div>
    <x-titre-ecran titre="Barème de commission"
        sous-titre="Les deux grilles du document. La première activation les pose au 1er janvier et couvre l’année entière, imports compris ; une correction, elle, ne vaut que pour la suite." />

    <div class="carte" style="margin-bottom:18px;">
        <div style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
            <x-champ label="Exercice" model="exercice" type="select" :options="$this->exercices" :live="true" width="140" />
            <p style="margin:0 0 9px; font-size:12.5px; color:#6B6E76; flex:1; min-width:260px;">
                Un barème court jusqu'à ce qu'un autre le remplace : celui de {{ $exercice }} vaut
                aussi pour les années suivantes tant que personne n'en pose d'autre. L'exercice
                choisi ici dit surtout <b>à quelle année une première activation s'applique</b>.
            </p>
        </div>

        @if ($message !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
        @endif

        @if ($erreur !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
        @endif
    </div>

    @foreach (\Modules\Noyau\Exploitation\Modeles\BaremeCommission::CIBLES as $cible => $libelleCible)
        @php
            $lignes = $this->grilles[$cible] ?? [];
            $estEnregistree = ($this->enregistrees[$cible] ?? null) !== null;
        @endphp

        <div class="carte" style="margin-bottom:22px;">
            {{-- Le titre rouge centré de la maquette, et la mention de la grille montrée. --}}
            <h2 style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:800;
                       color:#C8102E; text-align:center; margin:0 0 4px; letter-spacing:.01em;">
                Grille de commission* (CA mensuel)
            </h2>
            {{-- Les deux grilles portent le même titre : sans la catégorie juste en dessous,
                 on ne sait pas laquelle on corrige. L'astérisque du document renvoyait à une
                 mention placée tout en bas, trop loin pour servir. --}}
            <p style="text-align:center; margin:0 0 6px; font-size:13.5px; font-weight:700; color:#B45309;">
                *{{ $libelleCible }}
            </p>
            <p style="text-align:center; margin:0 0 16px; font-size:12.5px; color:#6B6E76;">
                @if ($estEnregistree)
                    {{-- « En vigueur depuis le … » se lisait comme une date de décision, et le
                         propriétaire n'aimait pas cette phrase : un barème n'est pas daté du
                         jour où on l'a tapé, il est la règle de la maison. La date reste — il
                         la faut pour savoir ce qui s'applique à un import de février — mais
                         elle est dite pour ce qu'elle est : le point à partir duquel cette
                         grille-là commande le calcul. --}}
                    Cette grille <b>commande le calcul à partir du
                    {{ ($this->enregistrees[$cible]->date_effet)->format('d/m/Y') }}</b>,
                    et jusqu'à ce qu'une autre la remplace.
                @else
                    {{-- On n'affiche jamais un écran vide : la grille de référence est là,
                         prête à être corrigée puis activée. --}}
                    <b style="color:#B45309;">Grille de référence du document</b> — rien n'est encore
                    enregistré pour {{ $exercice }}. Corrigez-la si besoin, puis
                    <b>« Première activation »</b> : elle vaudra depuis le 1er janvier {{ $exercice }},
                    pour tout ce qui a déjà été importé.
                @endif

                @if ($estEnregistree && ! ($this->couvrentLeDebutDeLExercice[$cible] ?? false))
                    {{-- Le cas qui se voyait le moins et qui coûtait le plus : une grille
                         existe, mais elle a été posée en cours d'année. Tout ce qui précède
                         sa date d'effet ne commissionne rien, et rien ne le disait. --}}
                    <br>
                    <b style="color:#B45309;">Le début de {{ $exercice }} n'est couvert par aucune
                    grille</b> : ce qui a été importé avant cette date ne commissionne rien.
                    « Première activation » la fait courir depuis le 1er janvier.
                @endif
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th style="width:32%;">Tranche CA (FCFA)</th>
                            <th style="width:16%;">Taux</th>
                            <th>Commission estimée</th>
                            <th class="colonne-collee" style="width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lignes as $rang => $ligne)
                            <tr wire:key="{{ $cible }}-{{ $rang }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:normal;">
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <input type="number" class="champ" style="width:112px;"
                                            wire:model.live.debounce.500ms="grilles.{{ $cible }}.{{ $rang }}.plancher">
                                        <span style="color:#6B6E76;">→</span>
                                        {{-- Laissé vide : « et au-delà », comme le « ≥ 61 M » du document. --}}
                                        <input type="number" class="champ" style="width:112px;" placeholder="et au-delà"
                                            wire:model.live.debounce.500ms="grilles.{{ $cible }}.{{ $rang }}.plafond">
                                    </div>
                                    <span style="font-size:11.5px; color:#6B6E76;">
                                        {{ \Modules\Noyau\Exploitation\Services\CommissionCommerciale::libelleTranche(
                                            (int) $ligne['plancher'],
                                            $ligne['plafond'] === null || $ligne['plafond'] === '' ? null : (int) $ligne['plafond'],
                                        ) }}
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <input type="number" step="0.1" class="champ" style="width:82px;"
                                            wire:model.live.debounce.500ms="grilles.{{ $cible }}.{{ $rang }}.taux">
                                        <span style="font-weight:700;">%</span>
                                    </div>
                                </td>
                                <td style="font-weight:700; font-variant-numeric:tabular-nums;">
                                    {{-- Recalculée, jamais saisie : la recopier à la main
                                         permettrait qu'elle contredise le taux d'à côté. --}}
                                    {{ \Modules\Noyau\Exploitation\Services\CommissionCommerciale::commissionEstimee(
                                        (int) $ligne['plancher'],
                                        $ligne['plafond'] === null || $ligne['plafond'] === '' ? null : (int) $ligne['plafond'],
                                        (float) $ligne['taux'],
                                    ) }}
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                        wire:click="retirerLaTranche('{{ $cible }}', {{ $rang }})">Retirer</button>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune tranche. Utilisez « + Ajouter » pour en poser une." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p style="margin:10px 0 0; font-size:12px; color:#C8102E; font-weight:700;">*{{ $libelleCible }}</p>

            {{-- Les trois gestes de la section, sur une seule ligne. --}}
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
                {{-- **Première activation.** Elle pose la grille au 1er janvier de
                     l'exercice, et c'est ce qui la fait valoir pour les données déjà
                     importées. Le bouton ne s'affiche que tant que le début de l'année
                     n'est couvert par aucune grille : une fois le barème posé, il n'a plus
                     rien à faire, et un bouton qui ne fait rien se clique quand même. --}}
                @unless ($this->couvrentLeDebutDeLExercice[$cible] ?? false)
                    <button type="button" class="bouton bouton-sombre"
                        wire:click="enregistrerLaGrille('{{ $cible }}', true)"
                        data-confirmer-titre="Activer la grille « {{ $libelleCible }} »"
                        data-confirmer="Faire courir cette grille depuis le 1er janvier {{ $exercice }} ?"
                        data-confirmer-detail="Elle couvrira l’exercice entier, y compris les mois déjà importés : c’est le but. Un barème n’est pas une décision du jour, c’est la règle sur laquelle on se base depuis le début de l’année. Ensuite, une vraie modification se posera à sa date.">
                        Première activation
                    </button>
                @endunless

                {{-- **Rectifier n'est pas modifier**, et les deux boutons le disent.

                     Rectifier récrit la grille en vigueur *à sa propre date* : on s'est
                     trompé en la saisissant, elle devient ce qu'elle aurait dû être depuis
                     le début, et il n'y a jamais eu deux barèmes. Modifier en pose une
                     seconde, datée d'aujourd'hui, et laisse la première derrière elle pour
                     les mois déjà arrêtés.

                     Chacun demande confirmation, et la question dit ce qui va arriver —
                     c'est une rémunération qui est au bout, et les deux gestes ne se
                     rattrapent pas de la même façon. --}}
                @if ($estEnregistree)
                    <button type="button" class="bouton bouton-secondaire"
                        wire:click="enregistrerLaGrille('{{ $cible }}', false, true)"
                        data-confirmer-titre="Rectifier la grille « {{ $libelleCible }} »"
                        data-confirmer="Récrire la grille en vigueur à sa date d’origine, le {{ ($this->enregistrees[$cible]->date_effet)->format('d/m/Y') }} ?"
                        data-confirmer-detail="Tout ce qui a été calculé depuis cette date sera recalculé avec les taux corrigés, y compris les mois déjà annoncés. Aucune seconde grille n’est créée : c’est une faute de saisie qu’on répare, pas une décision nouvelle. Si le barème a réellement changé, utilisez « Enregistrer la modification ».">
                        Rectification
                    </button>
                @endif

                <button type="button"
                    class="bouton {{ ($this->couvrentLeDebutDeLExercice[$cible] ?? false) ? 'bouton-sombre' : 'bouton-secondaire' }}"
                    wire:click="enregistrerLaGrille('{{ $cible }}')"
                    data-confirmer-titre="Modifier la grille « {{ $libelleCible }} »"
                    data-confirmer="Poser une nouvelle grille, applicable à partir d’aujourd’hui {{ now()->format('d/m/Y') }} ?"
                    data-confirmer-detail="Les mois déjà arrêtés gardent la grille sous laquelle ils l’ont été : rien de ce qui a été annoncé aux commerciaux ne sera recalculé. L’ancienne grille reste consultable, et l’écran dit depuis quand court la nouvelle.">
                    Enregistrer la modification
                </button>
                <button type="button" class="bouton bouton-secondaire" wire:click="ouvrirLAjout('{{ $cible }}')">
                    {{ $ajoutOuvert === $cible ? 'Fermer' : '+ Ajouter' }}
                </button>
                {{-- Les notes s'ouvrent en boîte et non en volet : dépliées sous le
                     tableau, elles le poussaient vers le bas au moment même où l'on
                     comparait les tranches. On les lit, on referme, le tableau n'a pas
                     bougé. --}}
                <button type="button" class="bouton bouton-secondaire"
                    data-confirmer-mode="information"
                    data-confirmer-titre="Notes — {{ $libelleCible }}"
                    data-confirmer="{{ $this->notes[$cible][0] ?? '' }}"
                    data-confirmer-detail="{{ implode(' ', array_slice($this->notes[$cible], 1)) }}">
                    Notes
                </button>
            </div>

            @if ($ajoutOuvert === $cible)
                {{-- Les champs apparaissent sous le tableau ; on valide, ou l'on annule. --}}
                <div style="margin-top:14px; background:#F7F5EF; border-radius:8px; padding:12px 14px;">
                    <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <x-champ label="Plancher (FCFA, atteint)" model="nouveauPlancher" type="number" width="190" />
                        {{-- La consigne est dans la case, et non sous elle : posée en
                             dessous, elle se lit après coup, quand on a déjà tapé. --}}
                        <x-champ label="Plafond (FCFA, exclu)" model="nouveauPlafond" type="number" width="190"
                            placeholder="Vide = et au-delà" />
                        <x-champ label="Taux (%)" model="nouveauTaux" type="number" width="120" />
                        <button type="button" class="bouton bouton-sombre" wire:click="validerLAjout">Valider</button>
                        <button type="button" class="bouton bouton-secondaire" wire:click="annulerLAjout">Annuler</button>
                    </div>
                </div>
            @endif

        </div>
    @endforeach

    {{-- La dernière phrase du document ne vise ni l'une ni l'autre grille : c'est l'intention
         d'ensemble, et elle reste donc hors des deux sections. --}}
    <p style="font-size:12.5px; color:#6B6E76; font-style:italic; margin:0;">
        Le système favorise ainsi une montée en performance tout en maintenant une logique de
        rémunération claire et motivante.
    </p>
</div>
