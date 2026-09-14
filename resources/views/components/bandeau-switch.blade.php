@php
    use Modules\Noyau\Entreprises\Support\LibellesRoles;
    use Modules\SuperAdmin\Services\ModeSwitch;

    $origine = ModeSwitch::enCours() ? ModeSwitch::origine() : null;
    $assiste = $origine ? auth()->user() : null;
    $depuis = session(ModeSwitch::CLE_DEPUIS);

    // Les rôles sont lus en base plutôt que par getRoleNames() : hors du contexte d'une
    // entreprise, Spatie filtre par équipe et ne renverrait rien — le bandeau afficherait
    // un tiret là où il doit nommer un rôle.
    $rolesDeLAssiste = $assiste
        ? (App\Models\User::nomsRolesParUtilisateur([$assiste->id])[$assiste->id] ?? null)
        : null;
@endphp

{{-- Le bandeau du mode switch.

     **Pourquoi il barre toute la largeur, sous le bandeau noir.** Un administrateur qui
     assiste quelqu'un voit exactement ce que cette personne voit : mêmes menus, mêmes
     chiffres, même ville de travail. C'est tout l'intérêt, et c'est aussi le danger — on
     oublie où l'on est, et l'on saisit dans un compte qui n'est pas le sien. L'avertissement
     doit donc être permanent, à hauteur d'œil, et impossible à replier.

     **Il reprend la grammaire de l'application** plutôt que d'être un objet étranger : le
     noir du bandeau principal, le rouge de l'accent, la Barlow Condensed en capitales des
     titres. Il annonce trois choses et pas une de plus — qui l'on est devenu, avec quel
     rôle, dans quelle entreprise — puisque ce sont exactement les trois qui changent ce
     qu'on voit à l'écran.

     La sortie est un formulaire qui poste : c'est le geste qu'il faut le moins voir
     échouer, et rien ici ne dépend de la couche interactive. --}}

@if ($origine && $assiste)
    <div style="background:linear-gradient(90deg,#8C1023,#C8102E); color:#fff;
                border-bottom:2px solid #7A0E20; position:sticky; top:0; z-index:60;">
        <div style="max-width:1680px; margin:0 auto; padding:8px 16px; display:flex; align-items:center;
                    justify-content:space-between; gap:14px; flex-wrap:wrap;">

            <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; min-width:0;">
                <span style="background:#fff; color:#C8102E; font-family:'Barlow Condensed',sans-serif;
                             font-weight:700; text-transform:uppercase; letter-spacing:1.2px; font-size:12.5px;
                             padding:3px 10px; border-radius:20px; white-space:nowrap;">
                    ◉ Mode switch actif
                </span>

                <span style="font-size:14px; line-height:1.4;">
                    Connecté en tant que
                    <strong style="font-weight:800;">{{ $assiste->name }}</strong>
                    <span style="opacity:.55; margin:0 7px;">|</span>
                    <strong style="font-weight:800;">{{ LibellesRoles::liste($rolesDeLAssiste) }}</strong>
                    <span style="opacity:.55; margin:0 7px;">|</span>
                    <strong style="font-weight:800;">{{ $assiste->entreprise?->nom ?? 'Plateforme' }}</strong>
                </span>
            </div>

            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <span style="font-size:12px; opacity:.85; white-space:nowrap;">
                    depuis {{ $depuis ? \Illuminate\Support\Carbon::parse($depuis)->diffForHumans(null, true) : 'un instant' }}
                    · vous êtes {{ $origine->name }}
                </span>

                <form method="POST" action="{{ route('super-admin.switch.sortir') }}" style="margin:0;">
                    @csrf
                    <button type="submit"
                            style="background:#fff; color:#191B20; border:0; border-radius:7px; padding:7px 15px;
                                   font-size:13.5px; font-weight:700; cursor:pointer; white-space:nowrap;
                                   font-family:inherit;">
                        ← Quitter le mode switch
                    </button>
                </form>
            </div>
        </div>

        {{-- Une ligne de plus, et elle a sa raison d'être : sans elle, on croit assister en
             lecture seule. Tout ce qui est saisi pendant le détour est saisi par ce compte,
             et portera son nom dans le journal. --}}
        <div style="max-width:1680px; margin:0 auto; padding:0 16px 7px; font-size:11.5px; opacity:.9;">
            Ce que vous saisissez ici sera enregistré au nom de {{ $assiste->name }}.
            Aucune connexion n'est inscrite à son compte&nbsp;: ce passage figure au journal d'audit, au vôtre.
        </div>
    </div>
@endif
