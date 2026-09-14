@props(['page', 'actions' => null])

@php
    use Modules\Import\Support\AccesImport;
    use Modules\Noyau\Entreprises\Support\LibellesRoles;
    use Modules\Noyau\Imports\Modeles\CodeAgent;

    $utilisateur = auth()->user();
    $ouvertes = AccesImport::pagesDe($utilisateur);
    $roleLisible = LibellesRoles::de(AccesImport::role($utilisateur));

    // Un seul compteur : les codes dont on ne sait pas encore où travaille la personne.
    // C'est de l'information, plus une tâche bloquante — l'atelier vient du dépôt depuis
    // qu'on filtre l'extraction dans le logiciel — mais un annuaire à moitié rempli finit
    // par être un annuaire faux.
    $enAttente = ['codes' => CodeAgent::whereNull('ville_id')->count()];
@endphp

{{--
    La barre latérale de la section.

    Elle reprend la grammaire visuelle du recouvrement — même largeur, même noir, même
    typographie — parce que les deux sections s'ouvrent depuis le même bandeau et qu'une
    section qui n'écrit pas comme le reste se lit comme un autre logiciel.

    Les styles sont posés ici plutôt que dans la feuille globale : ils ne servent qu'à ce
    module, et une classe lâchée dans le style commun finit toujours par repeindre un écran
    qui ne l'avait pas demandé.
--}}


{{-- **La feuille de style est à l'intérieur du bloc, et elle doit y rester.**

     Livewire prend le **premier élément** du rendu comme racine du composant : c'est lui
     qui porte `wire:id`, et c'est à l'intérieur de lui seul que `wire:model`, `wire:click`
     et `wire:navigate` sont branchés. Posée avant le bloc, cette balise `<style>` devenait
     cette racine — et tout l'écran se retrouvait dehors, donc inerte. Mesuré : les listes
     déroulantes ne remontaient plus leur valeur au serveur, la liste des factures d'un
     tiers restait vide quel que soit le tiers choisi, et les boutons d'enregistrement ne
     déclenchaient rien. Quinze écrans étaient dans ce cas, tout le module Recouvrement et
     tout le module Import.

     Rien ne le signalait : la page s'affichait parfaitement, elle ne répondait simplement
     pas. Un test le garde désormais — voir RacineDesComposantsTest. --}}
<div class="imp">
    <style>
    .imp { display:grid; grid-template-columns:236px 1fr; gap:0; align-items:start;
           background:#F4F2EC; border:1px solid var(--th-ligne,#E3E0D8); border-radius:12px; overflow:hidden;
           font-family:var(--font-sans); font-size:16px; }
    .imp-side { background:#191B20; color:#EDEBE4; min-height:100%; padding-bottom:14px; }
    .imp-badge { margin:14px; border:1px solid #343945; border-radius:10px; padding:12px;
                 background:linear-gradient(160deg,#22252C,#191B20); }
    .imp-badge .role { font-family:'Barlow Condensed',sans-serif; font-weight:700;
                       text-transform:uppercase; letter-spacing:1px; font-size:18px; }
    .imp-badge .tag { display:inline-block; margin-top:6px; font-size:9.5px; letter-spacing:1px;
                      text-transform:uppercase; padding:3px 8px; border-radius:20px;
                      background:#C8102E; color:#fff; font-weight:700; }
    .imp-badge .nom { color:#9AA0AB; font-size:12px; margin-top:6px; }
    .imp-nav { padding:4px 10px; display:flex; flex-direction:column; gap:2px; }
    .imp-nav a, .imp-nav span { display:flex; align-items:center; gap:9px; padding:9px 14px; border-radius:7px;
                                font-size:14.5px; font-weight:600; color:#C7C9CF; text-decoration:none; }
    .imp-nav a:hover { background:#23262E; }
    .imp-nav a.on { background:var(--th-accent,#C8102E); color:#fff; }
    .imp-nav .ic { width:16px; text-align:center; }
    .imp-nav .cpt { margin-left:auto; background:#C8102E; color:#fff; font-size:11px; font-weight:700;
                    border-radius:20px; padding:1px 7px; min-width:16px; text-align:center; }
    .imp-nav a.on .cpt { background:#fff; color:#C8102E; }
    .imp-foot { padding:12px 16px; font-size:11.5px; color:#7C828D; border-top:1px solid #2E323B;
                line-height:1.55; margin-top:10px; }
    .imp-main { padding:22px 24px 30px; min-width:0; }
    .imp-top { display:flex; justify-content:space-between; align-items:flex-end; gap:16px;
               margin-bottom:18px; flex-wrap:wrap; }
    .imp-top h1 { font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;
                  text-transform:uppercase; letter-spacing:1px; margin:0; color:var(--th-steel,#2A2E35); }
    .imp-top .sub { color:var(--th-gris,#6B6E76); font-size:13.5px; margin-top:3px; }

    .imp-carte { background:#fff; border:1px solid #E3E0D8; border-radius:12px; padding:17px; }
    .imp-carte + .imp-carte { margin-top:15px; }
    .imp-carte h2 { font-family:'Barlow Condensed',sans-serif; font-size:17px; font-weight:700;
                    letter-spacing:1px; text-transform:uppercase; color:var(--th-steel,#2A2E35);
                    border-bottom:2px solid #191B20; padding-bottom:5px; margin:0 0 14px;
                    display:flex; justify-content:space-between; align-items:center; gap:10px; }
    .imp-carte h2 .chip { font-family:var(--font-sans); font-weight:700; font-size:11px; letter-spacing:.6px;
                          background:#F4F2EC; border:1px solid #E3E0D8; border-radius:20px; padding:3px 9px;
                          color:var(--th-gris,#6B6E76); text-transform:uppercase; white-space:nowrap; }
    .imp-g2 { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
    /* Même principe pour les cartes de chiffres : un nombre fixe par palier, et une hauteur
       égale imposée, pour qu'une carte à deux lignes de texte ne dépasse pas ses voisines. */
    .imp-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:17px;
                align-items:stretch; }
    .imp-kpis.trois { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .imp-kpis.cinq { grid-template-columns:repeat(5,minmax(0,1fr)); }
    @media (max-width:1100px) { .imp-kpis, .imp-kpis.cinq { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:560px)  { .imp-kpis, .imp-kpis.trois, .imp-kpis.cinq { grid-template-columns:1fr; } }
    .imp-kpi { background:#fff; border:1px solid #E3E0D8; border-left:4px solid #191B20; border-radius:10px;
               padding:12px 14px; display:flex; flex-direction:column; min-width:0; }
    /* Le sous-titre est collé en bas : les cartes d'une même rangée alignent alors leurs
       chiffres et leurs légendes, même quand un libellé tient sur deux lignes et pas l'autre. */
    .imp-kpi .sub { margin-top:auto; padding-top:4px; }
    .imp-kpi.rouge { border-left-color:#C8102E; }
    .imp-kpi.vert { border-left-color:#1E7B34; }
    .imp-kpi .lab { font-size:11px; text-transform:uppercase; letter-spacing:.6px;
                    color:var(--th-gris,#6B6E76); font-weight:700; margin-bottom:4px; }
    .imp-kpi .val { font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:700;
                    line-height:1.05; font-variant-numeric:tabular-nums; color:var(--th-ink,#191B20); }
    .imp-kpi .sub { font-size:11.5px; color:var(--th-gris,#6B6E76); }

    /* Grille de formulaire à nombre de colonnes FIXE.

       L'ancienne version utilisait `repeat(auto-fit, minmax(190px, 1fr))` : le nombre de
       colonnes changeait avec la largeur de la fenêtre, si bien qu'un champ occupant toute
       la ligne était suivi de trois champs dans une grille de quatre — d'où des cadres qui
       ne tombaient jamais en face les uns des autres. Un nombre de colonnes fixe par palier
       règle le problème définitivement : ce qui est sur la même ligne le reste. */
    .imp-frm { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; align-items:end; }
    .imp-frm.deux { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .imp-frm.quatre { grid-template-columns:repeat(4,minmax(0,1fr)); }
    .imp-frm .large { grid-column:1 / -1; }
    @media (max-width:1100px) { .imp-frm, .imp-frm.quatre { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:660px)  { .imp-frm, .imp-frm.deux, .imp-frm.quatre { grid-template-columns:1fr; } }
    .imp-fld label { display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px; }
    .imp-fld input, .imp-fld select, .imp-fld textarea {
        width:100%; box-sizing:border-box; font-family:inherit;
        border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px; padding:8px 10px; font-size:14px;
        background:var(--th-champ,#FFFBEA); color:var(--th-ink,#191B20); }
    .imp-fld input[type=file] { background:#fff; padding:7px; }
    .imp-fld input:focus, .imp-fld select:focus { outline:2px solid #C8102E; outline-offset:1px; border-color:#C8102E; }
    .imp-hint { font-size:12.5px; margin-top:9px; padding:8px 10px; border-radius:7px; background:#F4F2EC;
                border:1px dashed #E3E0D8; color:var(--th-gris,#6B6E76); line-height:1.5; }
    .imp-hint.ok { color:#1E7B34; border-color:#1E7B34; background:#F0F7F1; }
    .imp-hint.warn { color:#C8102E; border-color:#C8102E; background:#FCF0F2; font-weight:600; }
    .imp-lock { background:#FCF0F2; border:1px solid #F2C6CD; color:#C8102E; border-radius:9px;
                padding:11px 13px; font-size:13.5px; font-weight:600; line-height:1.5; }
    .imp-btn { font-family:inherit; border:0; border-radius:7px; padding:9px 15px; font-weight:600; font-size:14px;
               cursor:pointer; }
    .imp-btn.r { background:#C8102E; color:#fff; }
    .imp-btn.n { background:#191B20; color:#fff; }
    .imp-btn.o { background:none; border:1.5px solid #191B20; color:#191B20; }
    .imp-btn.p { background:none; border:1px solid #E3E0D8; color:#4B4E55; font-size:12.5px; padding:5px 11px; }
    .imp-btn[disabled] { opacity:.4; cursor:not-allowed; }
    .imp-actions { margin-top:13px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .imp-tbl-wrap { overflow:auto; border:1px solid #E3E0D8; border-radius:9px; background:#fff; }
    .imp-tbl { width:100%; border-collapse:collapse; font-size:13px; }
    .imp-tbl th { font-size:12px; font-weight:600; color:#fff; background:var(--th-ink,#191B20);
                  padding:7px 9px; text-align:left; white-space:nowrap; position:sticky; top:0; z-index:1; }
    /* `nowrap` : « 9 004 » coupé en deux se lit « 9 ». Le tableau défile, pas le nombre. */
    .imp-tbl th.num, .imp-tbl td.num { text-align:right; font-variant-numeric:tabular-nums;
                                       white-space:nowrap; }
    .imp-kpi .val { white-space:nowrap; }
    .imp-tbl td.num { font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:14px; }
    .imp-tbl td { padding:6px 9px; border-bottom:1px solid var(--th-ligne,#E3E0D8); vertical-align:top; }
    .imp-tbl tr:hover td { background:#FAF8F2; }
    .imp-tbl td.mono { font-family:ui-monospace,'Cascadia Mono',Consolas,monospace; font-size:12px; }

    .pastille { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600;
                white-space:nowrap; }
    .pDepose { background:#EEF0F3; color:#5A6472; }
    .pControle { background:#E8ECF5; color:#3A5A8C; }
    .pEnCours { background:#FFF4DE; color:#B87A00; }
    .pTermine { background:#E5F2E8; color:#1E7B34; }
    .pEchec { background:#C8102E; color:#fff; }
    .pAnnule { background:#EEF0F3; color:#9AA0AB; }
    .pPresume { background:#FFE9D6; color:#C05A12; }
    .pEtabli { background:#E5F2E8; color:#1E7B34; }
    .pVide { background:#FBD9DE; color:#C8102E; }

    /* La zone de dépôt.

       L'input reste dans le document — c'est lui que Livewire surveille — mais il est
       étalé, transparent et posé par-dessus toute la zone : le clic tombe forcément
       dessus, quel que soit l'endroit du cadre. On n'a donc pas deux mécanismes de
       téléversement à faire coexister, seulement une présentation différente du même. */
    .imp-zone { position:relative; border:2px dashed #C7C9CF; border-radius:12px; background:#FAF8F2;
                min-height:132px; display:flex; align-items:center; justify-content:center;
                text-align:center; padding:18px; transition:border-color .15s, background .15s; }
    .imp-zone:hover, .imp-zone.survol { border-color:#C8102E; background:#FCF4F5; }
    /* Le champ reste natif et visible : c'est lui qui affiche le nom du fichier retenu,
       sans attendre le serveur. On l'habille, on ne le cache pas. */
    .imp-zone-champ { margin-top:9px; max-width:100%; padding:7px 9px; background:#fff; cursor:pointer;
                      border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:13px; }
    .imp-zone-champ::file-selector-button { margin-right:10px; padding:6px 12px; cursor:pointer;
                      border:0; border-radius:6px; background:#191B20; color:#fff; font-size:12.5px;
                      font-weight:700; letter-spacing:.3px; }
    .imp-zone-champ:hover::file-selector-button { background:#C8102E; }
    .imp-zone-vide { display:flex; flex-direction:column; align-items:center; gap:3px; cursor:pointer; }
    .imp-zone-vide .fleche { font-size:30px; color:#C8102E; line-height:1; margin-bottom:4px; }
    .imp-zone-vide .titre { font-family:'Barlow Condensed',sans-serif; font-size:19px; font-weight:700;
                            letter-spacing:.5px; text-transform:uppercase; color:var(--th-steel,#2A2E35); }
    .imp-zone-vide .det { font-size:12.5px; color:var(--th-gris,#6B6E76); }
    .imp-zone-fait { display:flex; align-items:center; gap:12px; text-align:left; }
    .imp-zone-fait .ic { flex:0 0 34px; height:34px; border-radius:50%; background:#1E7B34; color:#fff;
                         display:flex; align-items:center; justify-content:center; font-size:17px; }
    .imp-zone-fait .nom { font-weight:700; font-size:15px; word-break:break-all; }
    .imp-zone-fait .det { font-size:12.5px; color:var(--th-gris,#6B6E76); }

    .imp-etape { display:flex; align-items:center; gap:10px; margin:0 0 13px; }
    .imp-etape .n { flex:0 0 26px; height:26px; border-radius:50%; background:#191B20; color:#fff;
                    font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:15px;
                    display:flex; align-items:center; justify-content:center; }
    .imp-etape .t { font-family:'Barlow Condensed',sans-serif; font-size:17px; font-weight:700;
                    letter-spacing:1px; text-transform:uppercase; color:var(--th-steel,#2A2E35); }
    .imp-etape .d { font-size:12.5px; color:var(--th-gris,#6B6E76); }
    .imp-etape.off .n { background:#C7C9CF; }
    .imp-etape.off .t { color:#9AA0AB; }

    .imp-jauge { height:8px; background:#E3E0D8; border-radius:20px; overflow:hidden; margin-top:7px; }
    .imp-jauge > i { display:block; height:100%; background:#C8102E; transition:width .4s ease; }

    @media (max-width: 900px) {
        .imp { grid-template-columns:1fr; }
        .imp-g2 { grid-template-columns:1fr; }
    }
</style>

    <aside class="imp-side">
        <div class="imp-badge">
            <div class="role">Import</div>
            <span class="tag">{{ $roleLisible }}</span>
            <div class="nom">{{ $utilisateur?->name }}</div>
        </div>

        <nav class="imp-nav">
            @foreach (AccesImport::PAGES as $cle => $meta)
                @php $compte = $enAttente[$cle] ?? 0; @endphp

                {{-- Seules les pages ouvertes sont listées : voir la coquille du
                     recouvrement pour le raisonnement. Un menu à moitié inerte se lit
                     comme une panne, pas comme une habilitation. --}}
                @if (in_array($cle, $ouvertes, true))
                    <a href="{{ route('import.'.$cle) }}" wire:navigate
                       class="{{ $page === $cle ? 'on' : '' }}">
                        <span class="ic">{{ $meta['icone'] }}</span>
                        <span>{{ $meta['libelle'] }}</span>
                        @if ($compte > 0)<span class="cpt">{{ $compte }}</span>@endif
                    </a>
                @endif
            @endforeach
        </nav>

        {{-- Trois phrases, et chacune répond à une question qu'on se pose vraiment devant
             un écran qui va toucher des milliers de lignes : où va mon fichier, que se
             passe-t-il si ça casse, et comment je reviens en arrière. La troisième manquait,
             et son absence rendait les deux premières inquiétantes plutôt que rassurantes. --}}
        <div class="imp-foot">
            <strong style="color:#B9BDC6;">Où va le fichier.</strong>
            Dans un dossier fermé, hors du serveur web : aucune adresse ne permet de le
            télécharger. Il y reste, pour qu'on puisse rejouer un import sans le redemander.
            <br><br>
            <strong style="color:#B9BDC6;">Si le traitement casse.</strong>
            Rien n'est écrit. La lecture des neuf mille lignes tient dans une seule
            transaction : une panne au milieu ramène la base exactement où elle était
            avant le dépôt. Il n'existe pas d'import à moitié fait.
            <br><br>
            <strong style="color:#B9BDC6;">Si l'import réussit et que c'était le mauvais fichier.</strong>
            Ouvrez son dépôt dans le journal : <em>Annuler cet import</em> retire les lignes
            qu'il a créées, dit d'abord combien, et laisse en place celles qui ont été
            retouchées depuis.
        </div>
    </aside>

    <section class="imp-main">
        @if (session('refus-import'))
            <div class="imp-lock" style="margin-bottom:14px;">{{ session('refus-import') }}</div>
        @endif

        <div class="imp-top">
            <div>
                <h1>{{ AccesImport::PAGES[$page]['libelle'] ?? 'Import' }}</h1>
                <div class="sub">{{ AccesImport::SOUS_TITRES[$page] ?? '' }}</div>
            </div>
            @if ($actions)
                <div style="display:flex; gap:9px; align-items:center; flex-wrap:wrap;">{{ $actions }}</div>
            @endif
        </div>

        {{ $slot }}
    </section>
</div>
