@php
    use Modules\Noyau\Imports\Services\CodeDeLAtelier;
    use Modules\SuperAdmin\Services\ModeSwitch;

    $moi = auth()->user();

    /*
     * On ne pose la question qu'à la personne elle-même.
     *
     * Pendant un mode switch, c'est un administrateur qui regarde l'écran de quelqu'un
     * d'autre : sa réponse serait enregistrée au nom de l'absent, et la seule vérification
     * qui vaille — celle de l'intéressé — serait perdue sans que personne ne le sache.
     */
    $aDemander = ! ModeSwitch::enCours() && CodeDeLAtelier::aConfirmer($moi);
    $code = $aDemander ? CodeDeLAtelier::de($moi) : null;
@endphp

@if ($code)
    {{-- La question du code d'atelier, posée une fois et sur n'importe quel écran.

         **Pourquoi elle est posée à tout le monde plutôt qu'arbitrée par le gérant seul.**
         Le code de deux lettres décide de quel atelier reçoit quelle fiche, donc quel
         chiffre d'affaires. Attribué de travers, il ne se voit pas : les totaux restent
         plausibles, simplement faux. La seule personne capable de repérer l'erreur du
         premier coup d'œil est celle qui porte le code — elle le lit sur chacune de ses
         fiches. On lui demande donc, une fois, et la question ne revient qu'au prochain
         changement.

         **Elle n'est pas fermable sans répondre.** Une croix en coin aurait fait disparaître
         la question pour de bon chez ceux qui n'ont pas le temps — c'est-à-dire chez tout le
         monde. Répondre prend un clic ; c'est le prix d'un chiffre juste.

         **Aucun script.** Le « oui » est un formulaire, le « non » un `<details>` du langage
         lui-même. Dans un navigateur où la couche interactive ne démarre pas, la boîte
         s'ouvre, se répond et se ferme exactement pareil. --}}

    <div style="position:fixed; right:16px; bottom:16px; z-index:60; max-width:420px; width:calc(100% - 32px);
                background:#fff; border:1px solid #E2E0D8; border-left:4px solid #C8102E; border-radius:10px;
                box-shadow:0 10px 30px rgba(0,0,0,.18); padding:15px 16px; font-size:13.5px; line-height:1.6;">

        <div style="font-family:'Barlow Condensed',sans-serif; font-size:17px; font-weight:700;
                    text-transform:uppercase; letter-spacing:.6px; color:#2A2E35; margin-bottom:6px;">
            Votre code dans l'atelier
        </div>

        <p style="margin:0 0 10px;">
            Le logiciel de l'atelier vous désigne par deux lettres. Nous avons retenu
            <strong style="font-size:16px; letter-spacing:1px;">{{ $code->code }}</strong>.
            Est-ce bien le vôtre&nbsp;?
        </p>

        {{-- Où le trouver. Sans cette ligne, la question est impossible à trancher pour
             quelqu'un qui n'a jamais regardé son numéro de fiche de près. --}}
        <p style="margin:0 0 12px; color:#6B6E76; font-size:12.5px;">
            Il est inscrit dans le numéro de chacune de vos fiches&nbsp;:
            <span style="font-family:var(--font-mono,monospace); color:#2A2E35;">FR-<strong
                style="background:#FFF3B0; padding:0 2px;">{{ $code->code }}</strong>N° 010669</span>
            — juste après «&nbsp;FR-&nbsp;», et avant le «&nbsp;N°&nbsp;».
        </p>

        <form method="POST" action="{{ route('code-atelier.confirmer') }}" style="margin:0 0 8px;">
            @csrf
            <button type="submit"
                style="width:100%; background:#1E7B34; color:#fff; border:0; border-radius:7px;
                       padding:9px 14px; font-size:13.5px; font-weight:700; cursor:pointer; font-family:inherit;">
                Oui, c'est bien mon code
            </button>
        </form>

        <details>
            <summary style="cursor:pointer; font-size:12.5px; color:#C8102E; font-weight:600; list-style:revert;">
                Non, ce n'est pas le mien
            </summary>

            <form method="POST" action="{{ route('mon-profil.liaison') }}"
                  style="margin-top:9px; display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                @csrf

                <div style="flex:1; min-width:120px;">
                    <label for="code-atelier-saisi" style="display:block; font-size:11px; text-transform:uppercase;
                           letter-spacing:.6px; color:#5A6472; font-weight:700; margin-bottom:3px;">
                        Vos deux lettres
                    </label>
                    <input type="text" id="code-atelier-saisi" name="liaison" maxlength="2" required
                           pattern="[A-Za-z]{2}" autocomplete="off" placeholder="KZ"
                           style="width:100%; box-sizing:border-box; text-transform:uppercase; letter-spacing:2px;
                                  border:1px solid #E3E0D8; border-radius:7px; padding:8px 10px;
                                  font-size:15px; font-weight:700; font-family:inherit; background:var(--th-champ,#FFFBEA);">
                </div>

                <button type="submit"
                    style="background:#2A2E35; color:#fff; border:0; border-radius:7px; padding:9px 14px;
                           font-size:13px; font-weight:700; cursor:pointer; font-family:inherit;">
                    Enregistrer
                </button>
            </form>

            <p style="margin:8px 0 0; font-size:11.5px; color:#6B6E76;">
                Si vous n'en avez aucun, laissez ce champ tel quel et prévenez votre responsable&nbsp;:
                reprendre le code d'un collègue rattacherait votre travail au sien.
            </p>
        </details>
    </div>
@endif
