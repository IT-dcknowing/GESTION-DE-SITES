@props(['titre' => true])

@php
    use Modules\Noyau\Commun\Services\CodeAuteur;
    use Modules\Noyau\Imports\Modeles\CodeAgent;
    use Modules\Noyau\Imports\Services\RapprochementDesCodes;

    $moi = auth()->user();

    /*
     * Le code de liaison déjà rattaché à ce compte, s'il y en a un. Lu sans les filtres
     * d'entreprise : la table des codes appartient au module d'import, et le rattachement
     * se lit de partout.
     */
    $codeActuel = CodeAgent::withoutGlobalScopes()
        ->where('entreprise_id', $moi->entreprise_id)
        ->where('user_id', $moi->id)
        ->first();

    /*
     * Le code de saisie de cette personne — en entier, chiffres compris.
     *
     * Il s'affichait en pointillés, « A-C-KY-···· », parce que le dernier bloc comptait
     * alors les documents : il n'y avait pas un code mais une série, et on ne pouvait en
     * montrer aucun. Ce bloc désigne maintenant la personne et ne bouge plus, donc on le
     * montre. C'est le nombre qu'elle donnera au téléphone quand on lui demandera qui a
     * saisi une ligne.
     */
    $codeDeSaisie = CodeAuteur::pour($moi);

    $propositions = (new RapprochementDesCodes((int) $moi->entreprise_id))
        ->codesPossiblesPour($moi->name);

    // La proposition cliquée revient par l'adresse de la page courante, quelle qu'elle
    // soit : le bloc sert sur « Mon profil » comme sur « Mon espace ».
    $propose = mb_strtoupper((string) request()->query('liaison', ''));
    $valeur = preg_match('/^[A-Z]{2}$/', $propose) ? $propose : (string) ($codeActuel?->code ?? '');

    $mono = 'font-family:ui-monospace,Consolas,monospace;';
@endphp

{{-- Les deux codes qui désignent une personne, côte à côte.

     **Pourquoi ce bloc est partagé.** Il n'existait que sur « Mon profil ». Or c'est
     « Mon espace » que la plupart des rôles ouvrent depuis leur menu Paramètres, et ceux
     qui saisissent réellement dans le logiciel d'atelier — les responsables, la
     comptabilité, les commerciaux — y arrivaient sans jamais voir leur identifiant de
     liaison, donc sans pouvoir le corriger. Le bloc est écrit une fois et servi aux deux :
     recopié, il aurait fini par diverger, et l'un des deux écrans aurait menti.

     **Les deux codes n'ont ni la même origine ni le même rôle**, et les confondre coûte
     cher. Le premier est produit ici et ne se modifie pas ; le second vient de l'autre
     logiciel et se modifie. L'écran le dit, plutôt que de laisser deviner.

     Le formulaire poste vers une adresse ordinaire : on ne dépend pas d'un script pour
     relier son propre travail. --}}

<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:14px;">

    {{-- ------------------------------------------------------- le code de la plateforme --}}
    <div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:15px;">
        <div style="font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.7px;
                    color:#4B4E55; margin-bottom:9px;">
            Code de saisie — cette plateforme
        </div>

        <input type="text" value="{{ $codeDeSaisie }}" disabled
               aria-label="Code de saisie sur la plateforme"
               style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8);
                      border-radius:8px; font-size:16px; background:#F1EFE9; color:#6B6E76; cursor:not-allowed;
                      {{ $mono }} letter-spacing:.5px;">

        <div style="font-size:12.5px; color:#6B6E76; line-height:1.55; margin-top:9px;">
            Ville, rôle, initiales, puis votre numéro dans l'entreprise.
            <strong>Il ne se modifie pas</strong> : il vous est attribué à l'ouverture de votre accès et
            se fige sur chaque ligne au moment où vous la saisissez, pour qu'un changement de ville ou
            de fonction ne réécrive jamais ce que vous avez déjà fait.
        </div>
    </div>

    {{-- ------------------------------------------------------- le code du logiciel --}}
    <div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:15px;">
        <div style="font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.7px;
                    color:#4B4E55; margin-bottom:9px;">
            Identifiant de liaison — logiciel d'atelier
        </div>

        <form method="POST" action="{{ route('mon-profil.liaison') }}"
              style="display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap;">
            @csrf

            <input type="text" name="liaison" value="{{ $valeur }}" maxlength="2" placeholder="KZ"
                   aria-label="Identifiant de liaison"
                   style="width:110px; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px;
                          font-size:16px; text-transform:uppercase; letter-spacing:2px;
                          {{ $mono }} background:var(--th-champ,#FFFBEA);">

            <button type="submit"
                    style="background:#191B20; color:#fff; border:0; border-radius:8px; padding:10px 18px;
                           font-size:14px; font-weight:700; cursor:pointer; font-family:inherit;">
                Enregistrer
            </button>
        </form>

        <div style="font-size:12.5px; color:#6B6E76; line-height:1.55; margin-top:9px;">
            Les deux lettres qui vous désignent dans le logiciel de l'atelier, au milieu d'un numéro
            de fiche&nbsp;: <span style="{{ $mono }}">FR-<strong>KZ</strong>N° 010669</span>.
            <strong>Modifiable</strong>, et facultatif&nbsp;: tout le monde ne saisit pas dans ce
            logiciel. Il sert à la traçabilité, et à rattacher les prospections à leur commercial.
        </div>

        @if ($codeActuel)
            <div style="font-size:12.5px; color:#4B4E55; margin-top:8px;">
                Actuellement rattaché&nbsp;:
                <b style="{{ $mono }}">{{ $codeActuel->code }}</b>
                — {{ number_format((int) $codeActuel->occurrences, 0, ',', ' ') }} ligne(s) importée(s)
            </div>
        @endif

        <div style="font-size:12px; color:#6B6E76; margin-top:6px; line-height:1.5;">
            Champ vidé puis enregistré&nbsp;: le code est <strong>détaché</strong>. C'est le geste
            attendu quand il a été rattaché à la mauvaise personne.
        </div>

        @if (session('refus-profil'))
            <div style="margin-top:10px; background:#FDF2F4; border:1px solid #C8102E55; border-radius:8px;
                        padding:9px 12px; color:#C8102E; font-size:13px;">{{ session('refus-profil') }}</div>
        @endif

        @if ($propositions->isNotEmpty())
            <div style="margin-top:12px; background:#F4F2EC; border:1px dashed var(--th-ligne,#E2E0D8);
                        border-radius:8px; padding:10px 12px;">
                <div style="font-size:12px; font-weight:700; color:#4B4E55; margin-bottom:7px;">
                    Codes libres dont les initiales collent à votre nom — à vérifier, pas à croire&nbsp;:
                </div>
                <div style="display:flex; gap:7px; flex-wrap:wrap;">
                    @foreach ($propositions as $candidat)
                        <a href="{{ request()->fullUrlWithQuery(['liaison' => $candidat->code]) }}"
                           style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px;
                                  padding:5px 11px; font-size:13px; font-weight:600; color:#4B4E55;
                                  text-decoration:none; display:inline-block;">
                            <span style="{{ $mono }} font-weight:800;">{{ $candidat->code }}</span>
                            <span style="color:#6B6E76; font-weight:400;">
                                — {{ number_format((int) $candidat->occurrences, 0, ',', ' ') }} fiche(s)
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
