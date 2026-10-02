# Le solde de caisse — la règle, et les cinq pièges

*Écrit le 2 octobre 2026, à l'usage de qui relit un classeur de caisse : une personne, un
comptable, ou une IA à qui l'on confie le fichier. Ce document ne parle pas de code.*

Il existe parce que l'application a annoncé un écart de **652 917 F** sur un classeur dont le
solde de **696 075 F** était juste. L'erreur n'était pas dans l'addition. Elle était dans
**l'ordre des lignes** et dans le **périmètre** du cumul. Ce sont les deux pièges que
n'importe quelle relecture rencontre, et les trois suivants viennent du classeur lui-même.

---

## 1. La règle

Le solde est un **cumul, ligne après ligne**. Chaque ligne reprend le solde de la précédente :

```
solde(1) = solde annoncé sur la première ligne du feuillet
solde(n) = solde(n-1) + ENTRÉE(n) − SORTIE(n)

solde final = solde(1) + Σ ENTRÉES − Σ SORTIES        (sur les lignes 2 à n)
```

Trois précisions qui comptent :

- **Une cellule vide vaut zéro**, jamais une erreur et jamais une ligne à écarter.
- **Une ligne a soit une entrée, soit une sortie**, jamais les deux. Si les deux colonnes sont
  remplies sur une même ligne, c'est une anomalie du fichier : signalez-la, ne la tranchez pas.
- **Le solde annoncé sur une ligne comprend déjà le mouvement de cette ligne.** Une entrée de
  1 000 F sur une caisse à zéro affiche un solde de 1 000 F. Si vous repartez d'un solde
  annoncé, n'y rajoutez donc pas le mouvement de sa propre ligne : vous le compteriez deux fois.

### Le sens : D ou C

Une caisse est un compte d'actif. Le sens du solde se lit donc ainsi :

| Solde | Sens | Ce que cela veut dire |
|---|---|---|
| positif ou nul | **D** (débit) | la caisse tient de l'argent |
| négatif | **C** (crédit) | le cumul est passé sous zéro |

Un « C » n'est pas forcément une erreur — voir le piège 4.

---

## 2. Les cinq pièges

### Piège 1 — L'ordre est celui du fichier, jamais celui de la date

C'est le piège principal, et c'est celui qui a produit le faux écart.

Un cumul dépend de l'ordre dans lequel les lignes sont **écrites**. Or des dizaines de lignes
partagent la même date : les trier par date les rebat au hasard, et tous les soldes
intermédiaires deviennent faux.

Pire : **certaines dates n'ont pas été lues**. Mesuré sur le classeur en base :

- une ligne du feuillet « DEC 25 » porte la date du **31/12/1899** — le zéro d'Excel ;
- une ligne du feuillet « JANV 26 » porte la date du **15/10/2026**.

Triées par date, ces deux lignes du milieu deviennent la première et la dernière du classeur.
Le rapprochement partait de l'une et finissait sur l'autre.

**À faire :** conservez le numéro de ligne d'origine et travaillez dessus. Si vous devez trier,
ajoutez toujours le numéro de ligne comme second critère.

Le solde **final** reste juste quel que soit l'ordre, puisque c'est une somme. Seuls les soldes
intermédiaires changent — et c'est justement eux qu'on compare au fichier.

### Piège 2 — Un cumul ne traverse pas deux feuillets

Chaque feuillet d'un classeur repart de **son propre fonds de caisse**. Mis bout à bout, deux
cumuls indépendants ne s'additionnent pas : le second prétend démarrer d'un montant que le
premier n'a pas laissé.

Sur le même classeur, suivi d'un bout à l'autre, le résultat ne désignait rien. Repris feuillet
par feuillet :

| Feuillet | Lignes | Écart |
|---|---|---|
| DEC 25 | 441 | 1 000 F |
| JANV 26-CONGES | 332 | −271 425 F |
| FEV 26 | 212 | −2 000 000 F |
| **MARS 26** | 170 | **aucun** |

Un feuillet se rapproche exactement. Les trois autres deviennent trois questions précises,
chacune bornée à deux cents lignes — on sait où aller regarder.

**À faire :** une chaîne de solde par feuillet et par caisse. Jamais une pour tout le fichier.

### Piège 3 — Les annulations sont déjà négatives

Une annulation s'écrit en **montant négatif, dans la même colonne que la pièce qu'elle
annule**. Il suffit d'appliquer la formule telle quelle :

- annulation d'une **sortie** : `SORTIE = −150 000` → le solde **monte** de 150 000, car on
  retranche un nombre négatif ;
- annulation d'une **entrée** : `ENTRÉE = −103 500` → le solde **baisse** de 103 500.

Il ne faut donc **ni** les ignorer, **ni** inverser leur signe, **ni** les ranger dans l'autre
colonne. Lire « ANNULATION SORTIE » et ajouter le montant en entrée le compterait deux fois,
puisqu'il est déjà négatif.

Le classeur en contient huit. Elles pèsent −853 500 F (quatre annulations d'entrées) et
+255 000 F (quatre annulations de sorties), soit **−598 500 F** au net — déjà compris dans le
solde de 696 075 F.

**Et deux d'entre elles précèdent la pièce qu'elles annulent** (lignes 647 et 1385). Cela ne
change rien au cumul, mais si vous cherchez la pièce d'origine, cherchez-la **sur tout le
fichier**, pas seulement au-dessus.

### Piège 4 — Un solde négatif n'est pas une erreur de calcul

Il vient de l'ordre de saisie. Le 19/03, la sortie de 559 000 F est écrite **avant** les
entrées de 450 000 F et 550 000 F du même jour : le solde descend à −403 925 F pendant
quelques lignes, alors que la journée se termine en positif.

**À faire :** le signaler comme une alerte, et surtout **ne pas le corriger**. C'est un « C »
dans la colonne du sens, et rien de plus.

### Piège 5 — Un écart réel se situe, il ne s'annonce pas

Quand le cumul et le solde annoncé divergent, le chiffre de l'écart ne sert à rien tout seul.
Ce qui sert, c'est **la première ligne où les deux cessent d'être d'accord** : c'est là que le
solde du classeur a sauté — une ligne retouchée à la main, un apport d'espèces non noté, un
report entre deux feuillets.

C'est pourquoi l'écran affiche désormais, côte à côte et sur chaque ligne, **notre solde** et
**le solde annoncé** par le fichier, avec le désaccord marqué en rouge à l'endroit où il
commence.

---

## 3. Le pseudo-code

```
pour chaque feuillet du classeur :              # piège 2
    lignes = lignes du feuillet, dans l'ordre du fichier    # piège 1

    solde = lignes[1].solde_annoncé             # comprend déjà son propre mouvement
    lignes[1].solde_calculé = solde

    pour chaque ligne suivante :
        entrée = ligne.entrée ou 0              # vide -> 0
        sortie = ligne.sortie ou 0              # annulations déjà négatives — piège 3
        solde = solde + entrée - sortie
        ligne.solde_calculé = solde
        ligne.sens = "C" si solde < 0 sinon "D"

    écart = lignes[dernière].solde_annoncé - solde
    première_divergence = première ligne où solde_calculé ≠ solde_annoncé   # piège 5
```

Deux contrôles, et le second est le seul qui renseigne :

```
contrôle_1 = (écart == 0)                                     # le total
contrôle_2 = toutes les lignes ont solde_calculé == solde_annoncé   # ligne à ligne
```

---

## 4. Ce que l'application fait de cette règle

- `Modules/Noyau/app/Imports/Services/ChaineDeSolde.php` la porte, et dit ce qu'elle a coûté.
- `tests/Feature/LeSoldeDeCaisseEstUnCumulDansLOrdreDuFichierTest.php` tient les cinq pièges,
  chacun par un test, y compris les annulations écrites avant leur pièce.
- L'écran `/caisse` affiche notre solde, son sens D/C et le solde annoncé sur chaque ligne, et
  rapproche **feuillet par feuillet**.

---

## 5. Une question qui reste ouverte

Les dates mal lues (31/12/1899, 15/10/2026) viennent de la **lecture du classeur à l'import**,
pas du calcul. Le solde n'en dépend plus, mais ces lignes restent mal datées dans la base, donc
mal classées dans un filtre de période. C'est un défaut distinct, non corrigé à ce jour.
