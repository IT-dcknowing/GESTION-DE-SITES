@props([
    'route',
    'periode' => 'calendrier',
    'moisFiltre' => '',
    'dateTravail' => null,
    'villeFiltre' => '',
    'parametres' => [],
])

@php
    use Modules\Noyau\Commun\Services\PeriodeCalculateur;
    use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;
    use Modules\Noyau\Entreprises\Services\VilleDeTravail;

    $annee = ExerciceDeTravail::annee() ?? (int) date('Y');
    $mois = PeriodeCalculateur::moisDeLAnnee();
@endphp

{{-- Le filtre du recouvrement — et pourquoi il ne ressemble pas tout à fait à celui des
     indicateurs.

     Les écrans de pilotage mesurent un **flux** : un chiffre d'affaires se compte sur une
     période, et la période est la question. Le recouvrement mesure un **stock** : ce qui
     reste dû à un instant donné. Filtrer un stock « sur le mois de mars » ne veut rien
     dire — on l'arrête à une date, ce n'est pas pareil.

     D'où trois commandes, et pas cinq :

     - **l'arrêté**, qui fixe l'instant du stock — c'est lui qui commande le reste à payer,
       les anciennetés et donc les niveaux de relance ;
     - **la période**, qui ne touche qu'aux flux de l'écran : ce qui a été encaissé, ce qui
       a été relancé. Choisir un mois y déplace l'arrêté sur sa fin, pour que les deux
       parlent du même moment ;
     - **la ville**, qui est une loupe et non une restriction de droits : les rôles du
       recouvrement portent volontairement sur l'entreprise entière.

     C'est un formulaire GET : il agit sans script, et l'adresse obtenue se transmet. --}}

<form method="GET" action="{{ route($route) }}"
      style="background:#fff; border:1px solid #E3E0D8; border-radius:10px; padding:9px 12px;
             display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">

    @foreach ($parametres as $nom => $valeur)
        <input type="hidden" name="{{ $nom }}" value="{{ $valeur }}">
    @endforeach

    <div>
        <label for="f-mois" style="display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.7px; color:#5A6472; font-weight:700; margin-bottom:3px;">
            Période — exercice {{ $annee }}
        </label>
        <select id="f-mois" name="moisFiltre"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px; background:var(--th-champ,#FFFBEA);">
            <option value="" @selected((string) $moisFiltre === '')>Année entière</option>
            @foreach ($mois as $numero => $libelle)
                <option value="{{ $numero }}" @selected((string) $moisFiltre === (string) $numero)>{{ $libelle }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="f-ville" style="display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.7px; color:#5A6472; font-weight:700; margin-bottom:3px;">
            Ville
        </label>
        <select id="f-ville" name="villeFiltre"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px; background:var(--th-champ,#FFFBEA);">
            <option value="" @selected((string) $villeFiltre === '')>Toutes les villes (consolidé)</option>
            @foreach (VilleDeTravail::villes() as $id => $nom)
                <option value="{{ $id }}" @selected((string) $villeFiltre === (string) $id)>{{ $nom }}</option>
            @endforeach
        </select>
    </div>

    @if ($dateTravail !== null)
        <div>
            <label for="f-arrete" style="display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.7px; color:#5A6472; font-weight:700; margin-bottom:3px;">
                Arrêté au
            </label>
            <input type="date" id="f-arrete" name="dateTravail" value="{{ $dateTravail }}"
                   style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 8px; font-size:13px;">
        </div>
    @endif

    {{-- Le filtre part au changement : choisir un mois, c'est vouloir le voir. Le bouton
         ne subsiste que là où le script ne s'exécute pas — sinon le filtre ne partirait
         plus du tout. --}}
    <noscript>
        <button type="submit" class="rec-btn n" style="padding:7px 15px; font-size:13px;">Appliquer</button>
    </noscript>

    @if ($moisFiltre !== '' || $villeFiltre !== '')
        <a href="{{ route($route) }}" style="font-size:12px; color:#C8102E; font-weight:700; text-decoration:none; padding-bottom:8px;">
            Tout voir
        </a>
    @endif
</form>
