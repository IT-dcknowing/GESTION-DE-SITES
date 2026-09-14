{{-- Les styles du module, posés une fois et servis à ses deux mises en page.

     **Pourquoi ils ne sont pas dans la feuille globale.** Ils ne servent qu'ici, et une
     classe « .pill » lâchée dans le style commun finit par repeindre un écran qui ne l'a
     pas demandé. **Pourquoi ils ne sont plus dans la coquille.** Le tableau de bord se
     lit désormais en pleine page, sans barre latérale : il lui faut les mêmes couleurs,
     les mêmes pastilles et les mêmes tableaux, sans hériter du reste.

     Le module garde ses codes — les pastilles N1 à N5, le noir des en-têtes, le rouge du
     contentieux — parce que ce sont eux qui font lire un encours d'un coup d'œil. La
     typographie, elle, est celle du reste de l'application : une section qui n'écrit pas
     comme les autres se lit comme un autre logiciel. --}}

<style>
    .rec { display:grid; grid-template-columns:236px 1fr; gap:0; align-items:start;
           background:#F4F2EC; border:1px solid var(--th-ligne,#E3E0D8); border-radius:12px; overflow:hidden;
           font-family:var(--font-sans); font-size:16px; }
    .rec-titre { font-family:'Barlow Condensed',sans-serif; font-weight:700;
                 text-transform:uppercase; letter-spacing:1px; }
    .rec-chiffre { font-family:'Barlow Condensed',sans-serif; font-weight:700; font-variant-numeric:tabular-nums; }
    .rec-side { background:#191B20; color:#EDEBE4; min-height:100%; padding-bottom:14px; }
    .rec-badge { margin:14px; border:1px solid #343945; border-radius:10px; padding:12px;
                 background:linear-gradient(160deg,#22252C,#191B20); }
    .rec-badge .role { font-family:'Barlow Condensed',sans-serif; font-weight:700;
                       text-transform:uppercase; letter-spacing:1px; font-size:18px; }
    .rec-badge .tag { display:inline-block; margin-top:6px; font-size:9.5px; letter-spacing:1px;
                      text-transform:uppercase; padding:3px 8px; border-radius:20px;
                      background:#C8102E; color:#fff; font-weight:700; }
    .rec-badge .nom { color:#9AA0AB; font-size:12px; margin-top:6px; }
    .rec-nav { padding:4px 10px; display:flex; flex-direction:column; gap:2px; }
    .rec-nav a, .rec-nav span { display:flex; align-items:center; gap:9px; padding:9px 14px; border-radius:7px;
                                font-size:14.5px; font-weight:600; color:#C7C9CF; text-decoration:none; }
    .rec-nav a:hover { background:#23262E; }
    .rec-nav a.on { background:var(--th-accent,#C8102E); color:#fff; }
    .rec-nav .ic { width:16px; text-align:center; }
    .rec-foot { padding:12px 16px; font-size:11.5px; color:#7C828D; border-top:1px solid #2E323B;
                line-height:1.55; margin-top:10px; }
    .rec-main { padding:22px 24px 30px; min-width:0; }
    .rec-top { display:flex; justify-content:space-between; align-items:flex-end; gap:16px;
               margin-bottom:18px; flex-wrap:wrap; }
    .rec-top h1 { font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;
                  text-transform:uppercase; letter-spacing:1px; margin:0; color:var(--th-steel,#2A2E35); }
    .rec-top .sub { color:var(--th-gris,#6B6E76); font-size:13.5px; margin-top:3px; }

    .rec-carte { background:#fff; border:1px solid #E3E0D8; border-radius:12px; padding:17px; }
    .rec-carte h2 { font-family:'Barlow Condensed',sans-serif; font-size:17px; font-weight:700;
                    letter-spacing:1px; text-transform:uppercase;
                    color:var(--th-steel,#2A2E35); border-bottom:2px solid #191B20; padding-bottom:5px; margin:0 0 14px;
                    display:flex; justify-content:space-between; align-items:center; gap:10px; }
    .rec-carte h2 .chip { font-family:var(--font-sans); font-weight:700; font-size:11px; letter-spacing:.6px;
                          background:#F4F2EC; border:1px solid #E3E0D8; border-radius:20px; padding:3px 9px;
                          color:var(--th-gris,#6B6E76); text-transform:uppercase; white-space:nowrap; }
    .rec-g2 { display:grid; grid-template-columns:1fr 1fr; gap:15px; align-items:start; }
    /* Chaque colonne empile ses cartes pour son propre compte. Dans une grille à quatre
       cases, la hauteur d'une rangée vaut celle de sa carte la plus haute : la carte du bas
       de la colonne courte commençait deux cents points trop bas, et le milieu de l'écran
       restait vide. */
    .rec-col { display:flex; flex-direction:column; gap:15px; min-width:0; }
    .rec-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(168px,1fr)); gap:11px; margin-bottom:17px; }
    .rec-kpi { background:#fff; border:1px solid #E3E0D8; border-left:4px solid #191B20; border-radius:10px; padding:12px 14px; }
    .rec-kpi.rouge { border-left-color:#C8102E; }
    .rec-kpi .lab { font-size:11px; text-transform:uppercase; letter-spacing:.6px;
                   color:var(--th-gris,#6B6E76); font-weight:700; margin-bottom:4px; }
    .rec-kpi .val { font-family:'Barlow Condensed',sans-serif; font-size:27px; font-weight:700;
                   line-height:1.05; font-variant-numeric:tabular-nums; color:var(--th-ink,#191B20);
                   white-space:nowrap; }
    .rec-kpi .sub { font-size:11.5px; color:var(--th-gris,#6B6E76); margin-top:3px; }

    .rec-frm { display:grid; grid-template-columns:repeat(auto-fit,minmax(168px,1fr)); gap:11px; }
    .rec-fld label { display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:4px; }
    .rec-fld input, .rec-fld select, .rec-fld textarea {
        width:100%; box-sizing:border-box; font-family:inherit;
        border:1px solid var(--th-ligne,#E3E0D8); border-radius:6px; padding:8px 10px; font-size:14px;
        background:var(--th-champ,#FFFBEA); color:var(--th-ink,#191B20); }
    .rec-fld input:focus, .rec-fld select:focus { outline:2px solid #C8102E; outline-offset:1px; border-color:#C8102E; }
    .rec-hint { font-size:12.5px; margin-top:9px; padding:8px 10px; border-radius:7px; background:#F4F2EC;
                border:1px dashed #E3E0D8; color:var(--th-gris,#6B6E76); line-height:1.5; }
    .rec-hint.ok { color:#1E7B34; border-color:#1E7B34; background:#F0F7F1; }
    .rec-hint.warn { color:#C8102E; border-color:#C8102E; background:#FCF0F2; font-weight:600; }
    .rec-lock { background:#FCF0F2; border:1px solid #F2C6CD; color:#C8102E; border-radius:9px;
                padding:11px 13px; font-size:13.5px; font-weight:600; line-height:1.5; }
    .rec-btn { font-family:inherit; border:0; border-radius:7px; padding:9px 15px; font-weight:600; font-size:14px;
               cursor:pointer; }
    .rec-btn.r { background:#C8102E; color:#fff; }
    .rec-btn.n { background:#191B20; color:#fff; }
    .rec-btn.o { background:none; border:1.5px solid #191B20; color:#191B20; }
    .rec-btn[disabled] { opacity:.4; cursor:not-allowed; }
    .rec-actions { margin-top:13px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .rec-tbl-wrap { max-height:430px; overflow:auto; border:1px solid #E3E0D8; border-radius:9px; background:#fff; }
    .rec-tbl { width:100%; border-collapse:collapse; font-size:13px; }
    .rec-tbl th { font-size:12px; font-weight:600; letter-spacing:.02em; color:#fff; background:var(--th-ink,#191B20);
                  padding:7px 9px; text-align:left; white-space:nowrap; position:sticky; top:0; z-index:1; }
    /* `nowrap` : un montant coupé en deux ne se lit plus, il se devine. Le tableau
       défile s'il le faut ; le nombre, jamais. */
    .rec-tbl th.num, .rec-tbl td.num { text-align:right; font-variant-numeric:tabular-nums;
                                       white-space:nowrap; }
    /* Les montants dans la police des chiffres du reste de l'application : à colonne
       égale, la condensée en tient plus sans réduire le corps du texte. */
    .rec-tbl td.num { font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:14px; }
    .rec-tbl td { padding:6px 9px; border-bottom:1px solid var(--th-ligne,#E3E0D8); vertical-align:top; }
    .rec-tbl tr:hover td { background:#FAF8F2; }
    .rec-tbl tr.tot td { background:#191B20; color:#fff; font-weight:800; border:0; }

    .pill { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600;
            white-space:nowrap; }
    .pN0 { background:#EEF0F3; color:#5A6472; } .pN1 { background:#E8ECF5; color:#3A5A8C; }
    .pN2 { background:#FFF4DE; color:#B87A00; } .pN3 { background:#FFE9D6; color:#C05A12; }
    .pN4 { background:#FBD9DE; color:#C8102E; } .pN5 { background:#C8102E; color:#fff; }
    .pOK { background:#E5F2E8; color:#1E7B34; }

    .rec-bar { display:flex; height:26px; border-radius:6px; overflow:hidden; border:1px solid #E3E0D8; }
    .rec-leg { display:flex; gap:14px; flex-wrap:wrap; margin-top:10px; font-size:12.5px; color:var(--th-gris,#6B6E76); }
    .rec-sw { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:6px; vertical-align:-1px; }
    .rec-neg { color:#C8102E; font-weight:800; } .rec-pos { color:#1E7B34; font-weight:800; }
    .rec-cmt { width:100%; box-sizing:border-box; font-family:inherit; border:1px dashed #E3E0D8;
               background:#FFFDF5; border-radius:7px; padding:8px 10px; font-size:14px; min-height:38px; }

    @media (max-width:980px) {
        .rec { grid-template-columns:1fr; }
        .rec-side { min-height:0; }
        .rec-g2 { grid-template-columns:1fr; }
    }
    @media print {
        .rec-side, .rec-actions, .rec-top .rec-datebox, .no-print { display:none !important; }
        .rec { grid-template-columns:1fr; border:0; background:#fff; }
        .rec-main { padding:0; }
        .rec-carte { border:0; }
    }
</style>
