@props(['colspan' => 6, 'texte' => 'Aucune opération enregistrée sur cette période.'])

{{-- Les en-têtes de colonnes restent visibles au-dessus : seul le corps porte l'état vide. --}}
<tr>
    <td colspan="{{ $colspan }}" style="padding:0; border-bottom:0;">
        <div class="etat-vide" style="min-height:200px;">
            <span class="etat-vide-texte">{{ texte_d_attribut($texte) }}</span>
            <span class="etat-vide-sous">Les lignes apparaîtront ici dès la première saisie.</span>
        </div>
    </td>
</tr>
