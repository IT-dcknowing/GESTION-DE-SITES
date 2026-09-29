@props(['variante' => 'clair'])

{{-- Le décor d'atelier — la voiture, la bougie, la clé plate et les clés de contact.

     **Pourquoi c'est dessiné et non photographié.** La maquette du 29/09 pose quatre
     objets autour du logo : une voiture en haut à gauche, une bougie d'allumage, une clé
     plate, un trousseau de clés en bas à droite. Le propriétaire a demandé de « compléter
     avec les outils qui manquent, les deux outils, plus la clé ».

     Dessinés en SVG plutôt que posés en images, pour trois raisons qui comptent ici :

     1. **La page de connexion est la première que l'on voit**, souvent sur un réseau lent.
        Quatre images, c'est quatre allers-retours avant que la page ne soit entière ; ce
        décor pèse quelques kilo-octets et arrive avec le HTML.
     2. **Il suit le thème de l'entreprise.** Les couleurs viennent des variables CSS :
        une entreprise qui change son accent voit le décor changer avec elle, sans qu'on
        refasse des fichiers.
     3. **Il reste net à toutes les tailles**, du téléphone au grand écran, sans qu'on ait
        à livrer trois tailles de chaque objet.

     Le décor est **purement ornemental** : `aria-hidden`, et jamais porteur d'information.
     Quelqu'un qui ne le voit pas ne perd rien. --}}

@php
    $accent = $variante === 'sombre' ? '#FF4A5E' : 'var(--th-accent,#C8102E)';
    $metal = $variante === 'sombre' ? '#8A8F99' : '#6B7280';
    $sombre = $variante === 'sombre' ? '#0E1014' : '#2B2F36';
@endphp

{{-- ─────────────────────────────── la voiture, en haut à gauche --}}
<svg class="decor decor-voiture" viewBox="0 0 120 64" aria-hidden="true" focusable="false">
    <path d="M10 44h100a6 6 0 0 0 6-6v-6a8 8 0 0 0-6-7.7l-16-3.6-11-9.4A14 14 0 0 0 74 8H44a14 14 0 0 0-9.6 3.8L22 24 10 27a8 8 0 0 0-6 7.7v3.3a6 6 0 0 0 6 6z"
          fill="{{ $accent }}" opacity=".92"/>
    <path d="M44 13h12v11H33l8-9a6 6 0 0 1 3-2zM60 13h14a8 8 0 0 1 5 1.9l9 9.1H60z" fill="#fff" opacity=".78"/>
    <circle cx="32" cy="46" r="9" fill="{{ $sombre }}"/>
    <circle cx="32" cy="46" r="3.6" fill="#D8DCE2"/>
    <circle cx="90" cy="46" r="9" fill="{{ $sombre }}"/>
    <circle cx="90" cy="46" r="3.6" fill="#D8DCE2"/>
</svg>

{{-- ─────────────────────────────── la bougie d'allumage --}}
<svg class="decor decor-bougie" viewBox="0 0 40 140" aria-hidden="true" focusable="false">
    <rect x="14" y="4" width="12" height="26" rx="3" fill="{{ $metal }}"/>
    <rect x="11" y="28" width="18" height="10" rx="2" fill="#B9BEC7"/>
    <path d="M9 38h22l-2 20H11z" fill="{{ $sombre }}"/>
    <rect x="10" y="58" width="20" height="30" rx="3" fill="#E7EAEF"/>
    <rect x="10" y="62" width="20" height="4" fill="#C7CCD4"/>
    <rect x="10" y="70" width="20" height="4" fill="#C7CCD4"/>
    <rect x="10" y="78" width="20" height="4" fill="#C7CCD4"/>
    <rect x="13" y="88" width="14" height="22" rx="2" fill="{{ $metal }}"/>
    <path d="M17 110h6v16h-6z" fill="#9AA0AA"/>
    <path d="M14 126h12v4H14z" fill="{{ $sombre }}"/>
</svg>

{{-- ─────────────────────────────── la clé plate --}}
<svg class="decor decor-cle" viewBox="0 0 190 54" aria-hidden="true" focusable="false">
    <path d="M28 27a17 17 0 0 1 17-17h4v7h-4a10 10 0 0 0 0 20h4v7h-4a17 17 0 0 1-17-17z" fill="#C7CCD4"/>
    <rect x="46" y="20" width="104" height="14" rx="7" fill="#D8DCE2"/>
    <rect x="46" y="20" width="104" height="5" rx="2.5" fill="#EFF1F4"/>
    <path d="M150 27a15 15 0 0 1 15-15h6v6h-6a9 9 0 0 0 0 18h6v6h-6a15 15 0 0 1-15-15z" fill="#C7CCD4"/>
</svg>

{{-- ─────────────────────────────── le trousseau, en bas à droite --}}
<svg class="decor decor-trousseau" viewBox="0 0 90 130" aria-hidden="true" focusable="false">
    <circle cx="45" cy="16" r="11" fill="none" stroke="#B9BEC7" stroke-width="4"/>
    <rect x="26" y="32" width="38" height="52" rx="9" fill="{{ $accent }}"/>
    <rect x="33" y="41" width="24" height="15" rx="4" fill="#fff" opacity=".85"/>
    <circle cx="39" cy="68" r="4" fill="#fff" opacity=".8"/>
    <circle cx="51" cy="68" r="4" fill="#fff" opacity=".8"/>
    <rect x="41" y="84" width="8" height="34" fill="#C7CCD4"/>
    <path d="M49 100h8v6h-8zM49 110h6v6h-6z" fill="#C7CCD4"/>
</svg>
