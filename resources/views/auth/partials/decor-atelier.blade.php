{{-- Les outils de l'atelier — la bougie, la clé plate, la voiture et le trousseau.

     **Ce qui les accompagne, et ce qu'ils ne remplacent pas.** Le logo est le **rendu 3D**
     de la maquette (`public/logos/logo-3d.jpg`) : il ne se redessine pas, et il n'avait pas
     à l'être. Ces quatre objets-là, en revanche, ne sont nulle part sous forme de fichier —
     ils font partie d'un fond composé dont je n'ai que l'aperçu. Ils sont donc dessinés.

     **Pourquoi dessinés plutôt que découpés.** C'est la première page que l'on voit, souvent
     sur un réseau lent : quatre fichiers de plus, c'est quatre allers-retours avant que
     l'écran ne soit entier. Dessinés, ils pèsent quelques kilo-octets, arrivent avec le
     HTML, restent nets du téléphone au grand écran, et suivent la couleur d'accent de
     l'entreprise.

     **Le défaut corrigé le 29/09 : « il y a des éléments qui se chevauchent ».** Ils étaient
     posés en absolu sur toute la colonne, alors que le logo vit dans le flux. Un élément en
     absolu ne réserve aucune place : dès que le titre changeait de hauteur, la bougie et la
     clé passaient sous le logo. Les deux qui l'entourent sont maintenant ancrés **sur le
     bloc du logo** et posés **hors de sa boîte** — `right:100%` pour la bougie, `top:100%`
     pour la clé. Ils ne peuvent plus le rencontrer, quelle que soit la largeur.

     Les quatre sont `aria-hidden` : purement ornementaux, jamais porteurs d'information.
     Qui ne les voit pas ne perd rien. --}}

{{-- ─────────────────────────────── la bougie, à gauche du logo --}}
<svg class="outil outil-bougie" viewBox="0 0 40 140" aria-hidden="true" focusable="false">
    <rect x="14" y="4" width="12" height="26" rx="3" fill="#6B7280"/>
    <rect x="11" y="28" width="18" height="10" rx="2" fill="#B9BEC7"/>
    <path d="M9 38h22l-2 20H11z" fill="#2B2F36"/>
    <rect x="10" y="58" width="20" height="30" rx="3" fill="#E7EAEF"/>
    <rect x="10" y="62" width="20" height="4" fill="#C7CCD4"/>
    <rect x="10" y="70" width="20" height="4" fill="#C7CCD4"/>
    <rect x="10" y="78" width="20" height="4" fill="#C7CCD4"/>
    <rect x="13" y="88" width="14" height="22" rx="2" fill="#6B7280"/>
    <path d="M17 110h6v16h-6z" fill="#9AA0AA"/>
    <path d="M14 126h12v4H14z" fill="#2B2F36"/>
</svg>

{{-- ─────────────────────────────── la clé plate, sous le logo --}}
<svg class="outil outil-cle" viewBox="0 0 190 54" aria-hidden="true" focusable="false">
    <path d="M28 27a17 17 0 0 1 17-17h4v7h-4a10 10 0 0 0 0 20h4v7h-4a17 17 0 0 1-17-17z" fill="#C7CCD4"/>
    <rect x="46" y="20" width="104" height="14" rx="7" fill="#D8DCE2"/>
    <rect x="46" y="20" width="104" height="5" rx="2.5" fill="#EFF1F4"/>
    <path d="M150 27a15 15 0 0 1 15-15h6v6h-6a9 9 0 0 0 0 18h6v6h-6a15 15 0 0 1-15-15z" fill="#C7CCD4"/>
</svg>

{{-- ─────────────────────────────── la voiture, coin haut gauche de la page --}}
<svg class="outil outil-voiture" viewBox="0 0 120 64" aria-hidden="true" focusable="false">
    <path d="M10 44h100a6 6 0 0 0 6-6v-6a8 8 0 0 0-6-7.7l-16-3.6-11-9.4A14 14 0 0 0 74 8H44a14 14 0 0 0-9.6 3.8L22 24 10 27a8 8 0 0 0-6 7.7v3.3a6 6 0 0 0 6 6z"
          fill="#D7191F" opacity=".92"/>
    <path d="M44 13h12v11H33l8-9a6 6 0 0 1 3-2zM60 13h14a8 8 0 0 1 5 1.9l9 9.1H60z" fill="#fff" opacity=".78"/>
    <circle cx="32" cy="46" r="9" fill="#2B2F36"/>
    <circle cx="32" cy="46" r="3.6" fill="#D8DCE2"/>
    <circle cx="90" cy="46" r="9" fill="#2B2F36"/>
    <circle cx="90" cy="46" r="3.6" fill="#D8DCE2"/>
</svg>

{{-- ─────────────────────────────── le trousseau, coin bas droit de la page --}}
<svg class="outil outil-trousseau" viewBox="0 0 90 130" aria-hidden="true" focusable="false">
    <circle cx="45" cy="16" r="11" fill="none" stroke="#B9BEC7" stroke-width="4"/>
    <rect x="26" y="32" width="38" height="52" rx="9" fill="#D7191F"/>
    <rect x="33" y="41" width="24" height="15" rx="4" fill="#fff" opacity=".85"/>
    <circle cx="39" cy="68" r="4" fill="#fff" opacity=".8"/>
    <circle cx="51" cy="68" r="4" fill="#fff" opacity=".8"/>
    <rect x="41" y="84" width="8" height="34" fill="#C7CCD4"/>
    <path d="M49 100h8v6h-8zM49 110h6v6h-6z" fill="#C7CCD4"/>
</svg>
