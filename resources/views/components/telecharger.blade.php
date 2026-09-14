@props([
    'route',
    'parametres' => [],
    'libelle' => 'Télécharger',
])

{{-- Les trois façons d'emporter un document.

     Le bouton disait « Imprimer » et ouvrait la boîte d'impression du navigateur. C'était
     honnête mais étroit : on veut aussi bien remettre un PDF à un client, retravailler des
     chiffres dans un tableur, ou coller un tableau dans un courrier. Trois besoins, trois
     formats — et c'est cette forme-là qui vaudra pour tous les téléchargements à venir.

     Ce sont des liens, pas des boutons interactifs. Ils portent les paramètres de l'écran
     dans leur adresse, ce qui donne deux garanties : le fichier contient exactement ce que
     la page montrait, et le lien se transmet tel quel à quelqu'un d'autre. --}}

<div style="display:flex; align-items:center; gap:7px; flex-wrap:wrap;">
    <span style="font-size:11px; text-transform:uppercase; letter-spacing:.7px; color:#5A6472; font-weight:700;">
        {{ $libelle }}
    </span>

    @foreach (\Modules\Noyau\Commun\Services\Exportateur::FORMATS as $cle => $nom)
        <a href="{{ route($route, array_merge($parametres, ['format' => $cle])) }}"
           style="text-decoration:none; display:inline-block; border:1.5px solid #191B20; color:#191B20;
                  background:{{ $loop->first ? '#191B20' : 'none' }}; {{ $loop->first ? 'color:#fff;' : '' }}
                  border-radius:7px; padding:5px 12px; font-size:12.5px; font-weight:700;">
            {{ $nom }}
        </a>
    @endforeach
</div>
