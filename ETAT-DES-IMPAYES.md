# État des impayés — où nous en sommes

*Dernière mise à jour : 15 septembre 2026. Branche `impayes`, commit `6013a36`.*

Ce document dit trois choses : **ce que contient le classeur** que nous reprenons, **ce qui a
été construit** autour, et **ce qui reste à trancher** — en particulier la question du
recouvrement, qui est la bonne question et à laquelle je réponds au § 5.

---

## 1. Ce qu'est ce fichier

`Etats des impayés L2A au 200826 actu.1.xlsx` n'est **pas** un export du logiciel d'atelier.
C'est un classeur Excel tenu à la main par le superviseur de veille : 8 961 créances sur un
seul onglet (« Détail »), cinq années mêlées de 2022 à 2026, vingt colonnes.

C'est aussi la pièce maîtresse du recouvrement de l'application : **c'est le seul fichier qui
apporte les règlements**, donc le seul depuis lequel un reste à payer soit calculable. Le
CATTC, lui, apporte les factures sans leurs paiements.

### Ses chiffres sont justes

| | Facturé | Réglé | Reste à payer |
|---|---:|---:|---:|
| Le classeur, recalculé ligne par ligne | 6 329 977 795 | 5 535 425 213 | **798 999 354** |
| Ce qu'il affiche lui-même en tête | 6 282 488 335 | 5 490 087 392 | 792 400 942 |
| La reprise en base de données | 6 274 729 898 | 5 483 461 508 | 791 268 390 |
| L'écran « État des impayés » pour 2026 | — | — | 795 715 161 |

1 332 créances encore ouvertes, 87,4 % de taux de règlement. Les quatre lectures tombent à
1 % les unes des autres : **ce classeur tient debout**, et l'application dit la même chose que
lui.

> **Correction.** Au cours de l'analyse, j'ai d'abord annoncé que ce fichier était faux — qu'il
> affichait 792 M de créances là où le vrai chiffre aurait été 34 M. C'était une erreur de ma
> part : mon lecteur de fichier Excel ignorait les cellules vides auto-fermantes, ce qui
> décalait les valeurs d'une colonne, et je lisais le « reste à payer » dans la colonne
> « déjà réglé ». La base de données contredisait mon calcul, c'est ce qui m'a mis sur la
> piste. Tout ce qui avait été écrit dans le code sur cette base a été réécrit.

### Ce qu'un tableur ne peut pas faire

Ce n'est donc pas la justesse qui justifie ce module. C'est qu'un tableur ne se défend pas
contre la main qui le tient :

- **La formule d'ancienneté se promène de colonne en colonne.** Elle est chez elle en R, mais
  on la trouve aussi en O (1 198 lignes), P (75) et Q (1 492) — soit **2 765 lignes** où l'on
  lit un nombre de jours sous un en-tête qui annonce « Mode de règlement » ou « banque ».
- **29 lignes portent un règlement supérieur à leur propre montant facturé**, pour
  4 446 771 F. Ce trop-perçu vient en déduction du total : il masque la dette d'autres clients.
- **45 lignes n'ont aucune date exploitable, 142 aucun numéro, 42 % aucun atelier.**
- **Rien ne signale un doublon** au moment où on le crée. Le classeur en repère après coup,
  par une formule `CONCATENATE` rangée sous l'en-tête « Commentaires ».

### Les quatre formules du classeur, et ce qu'on en a fait

| Formule | Ce que l'application en fait |
|---|---|
| `ResteàPayer = montantTTC − Montantréglé` | Se déduit des encaissements, jamais d'une colonne. Les totaux se somment **par ligne, à plancher zéro** — un client qui a trop payé ne rembourse pas la dette d'un autre. |
| `IF(N=0; 0; AUJOURDHUI − dateRéception)` | L'application garde **sa** règle d'ancienneté, celle de la balance âgée, qui part de la date d'édition. Deux règles voisines donneraient deux âges pour la même créance. |
| `SI(<30 … 30<>60 … 60<>90 … >90)` | Conservée telle quelle pour pouvoir confronter la tranche que le classeur annonçait à celle qu'on recalcule. L'application en a cinq — elle coupe le « >90 » à 180 jours. |
| `CONCATENATE(date; n°; immat; montant)` | **Confirme notre clé d'import.** Son auteur avait retenu exactement les quatre champs que l'import avait trouvés de son côté, en éprouvant les combinaisons sur le fichier entier. Un numéro de facture, seul, n'identifie rien ici. |

---

## 2. Ce qui a été construit

Trois écrans, dans la section **Indicateurs**, ouverts au gérant, au superviseur de ville et
au responsable de site. Fermés au responsable commercial, comme les charges et la trésorerie.

**État des impayés — FICORE** (`/impayes`) — l'état de l'année. Un bouton « Ajouter une
créance » ouvre les seize colonnes saisissables du classeur, dans son ordre et sous ses mots
exacts, sur deux rangées. Chacun des défauts du § 1 y est refusé à la frappe. Référence
générée `IMP-1509-0001`, avec le code de saisie et le nom dessous.

**Tableau état initial** (`/impayes/etat-initial`) — la reprise telle quelle, toutes années
mêlées, en lecture seule. Rien n'a été redressé d'office : ce serait réécrire quatre ans de
travail fait à la main. On y voit la tranche d'ancienneté que le classeur annonçait à côté de
celle qu'on recalcule.

**Rapprochement CA / impayés** (`/rapprochement-ca-impayes`) — la comparaison n'existait pas.

### La reconduction est une règle de lecture, jamais une écriture

Une créance non soldée **n'est pas recopiée** dans l'année suivante : elle garde son année
d'origine, et l'état de 2026 l'affiche parce qu'elle est encore ouverte.

- Recopier la ligne compterait la même créance **deux fois** dans un total.
- La déplacer **viderait** l'état de l'année passée, qui ne correspondrait plus à ce qu'on y
  avait arrêté.

Le report se défait donc de lui-même le jour où la facture est soldée. Rien à lancer au
1er janvier, aucune tâche planifiée à surveiller. Les deux colonnes affichées — « Année
antérieure » et « Reporté 2024 » — se déduisent toutes les deux de l'année de la créance :
aucune n'est stockée, sans quoi elles se tromperaient sur neuf mille lignes le 1er janvier.

Vérifié sur les données réelles : les 115 créances ouvertes de 2023 sont exactement les 115
reportées de 2024, les 269 de 2024 celles de 2025, les 487 de 2025 celles de 2026.

---

## 3. Les colonnes, côte à côte

### CATTC — 12 colonnes

| Colonne du fichier | Où elle va en base |
|---|---|
| DATE DE LA FACTURE | `factures.date` |
| N° FACTURE | `factures.n_facture` |
| N° STICKER | `factures.n_sticker` |
| FICHE DE RECEPTION | `factures.reference_devis` — et c'est elle qui donne la ville |
| N° SINISTRE | `factures.n_sinistre` |
| IMMAT. VEHICULE | `factures.immatriculation` |
| MARQUE | `factures.marque` |
| MODELE | `factures.modele` |
| CODE CLIENT | `factures.code_client` |
| CLIENTS | `factures.client` |
| MONTANT FACTURE | `factures.montant` |
| SITE | `factures.site_id` |

### État des impayés — 20 colonnes

| Colonne du fichier | Où elle va en base |
|---|---|
| *(A)* | non lue — colonne de travail, « A » ou rien |
| ASSUREUR | `factures.assureur` |
| Client | `factures.client` |
| SITE | `factures.site_id` |
| Courtier | `factures.courtier` |
| Date de reception de la facture | `factures.date_reception` |
| Date d'edition de la facture | `factures.date` |
| Numéro de la facture | `factures.n_facture` |
| Numéro Sinistre | `factures.n_sinistre` |
| Vehicule | `factures.vehicule` |
| Immatriculation | `factures.immatriculation` |
| montantTTC | `factures.montant` |
| Montantréglé | **→ un encaissement**, jamais une colonne |
| ResteàPayer | **non écrit** — calculé à la lecture |
| Modederèglement | `encaissements.moyen`, libellé brut gardé en `reference_origine` |
| Datederèglement | `encaissements.date` |
| banque | `factures.banque` |
| Anciennetéfactures1 *(jours)* | **non écrit** — recalculé |
| Anciennetéfactures *(tranche)* | `factures.anciennete_declaree`, pour confronter |
| Commentaires | `factures.observations` — 345 vraies consignes, plus une formule `CONCATENATE` sur 4 299 lignes |

### La différence, en une phrase

**Le CATTC dit ce qui a été facturé. L'état des impayés dit ce qui a été payé.**

| | Colonnes |
|---|---|
| **Communes aux deux** (7) | date de facture, n° de facture, n° de sinistre, immatriculation, client, montant, site |
| **CATTC seulement** (5) | n° sticker, fiche de réception, marque, modèle, code client — l'identité technique du dossier d'atelier |
| **Impayés seulement** (12) | assureur, courtier, date de réception, véhicule, montant réglé, reste à payer, mode et date de règlement, banque, ancienneté ×2, commentaires — **tout le suivi du paiement** |

Et ils ne se recouvrent ni dans le temps ni par leur clé : le CATTC repris ne porte que 2026
(2 375 lignes, 1 384 588 526 F), l'état des impayés remonte à 2022 ; et **aucun numéro de
facture n'est commun aux deux** — « FA -5713 » d'un côté, « 17 » de l'autre.

Le pont retenu est donc le couple **immatriculation + montant** : mesuré, il retrouve 71 % des
factures du CATTC dans l'état des impayés, et 78 % sur la seule plaque. Il répond à « cette
facture est-elle suivie ? », jamais à « c'est cette ligne-là » — un client de flotte ramène le
même véhicule au même tarif. Sur 2026, l'écart est de **95 176 169 F et 424 factures** dont
personne ne suit le règlement.

---

## 4. Le CATTC a enfin ses vraies colonnes

Cinq colonnes du fichier manquaient à l'écran du chiffre d'affaires : sticker, n° de sinistre
et code client étaient concaténés dans une phrase rangée en observation ; marque et modèle
fondus en une seule valeur. Une donnée rangée dans une phrase est conservée sans être
consultable — on ne trie pas dessus, on ne filtre pas dessus.

Elles ont désormais leur colonne, et un filtre d'origine distingue les lignes reprises du
logiciel d'atelier des lignes saisies sur la plateforme. **Ce filtre ne touche que le
tableau** : les totaux et le graphique comptent tout, sans quoi ce ne serait plus le chiffre
d'affaires de l'entreprise mais celui de ce qu'on a tapé.

---

## 5. La question du recouvrement — et elle est juste

> *« On a déjà le module de recouvrement qui fait les recouvrements. Avoir une page impayés où
> on ajoute, n'est-ce pas identique ? »*

**Presque, et là où ce n'est pas identique, il y a un trou.**

### Ce qui se recoupe

Le module Recouvrement a déjà un écran de saisie avec quatre gestes, dont **« Facture »**, qui
crée une créance. Ses dix champs sont, à un près, ceux de l'état des impayés :

| Champ | Recouvrement > Saisie | État des impayés |
|---|:---:|:---:|
| Client / tiers | ✔ (liste fermée) | ✔ (libre) |
| Assurance représentée | ✔ | ✔ |
| Courtier | ✔ | ✔ |
| Site | ✔ | ✔ |
| Date de la facture | ✔ | ✔ |
| N° de facture | ✔ | ✔ |
| Véhicule | ✔ | ✔ |
| Immatriculation | ✔ | ✔ |
| Montant | ✔ | ✔ |
| Activité | ✔ (choisie) | déduite du n° de sinistre |
| Date de réception | — | ✔ |
| N° de sinistre | — | ✔ |
| **Montant réglé + mode + date + banque** | — | ✔ |
| Commentaires | — | ✔ |

**Neuf champs sur dix sont les mêmes.** Ce que l'état des impayés ajoute, c'est le règlement
dans le même geste — là où le recouvrement demande un second passage par son bloc
« Encaissement ».

Et leurs comportements diffèrent sur quatre points :

| | Recouvrement | État des impayés |
|---|---|---|
| Le client | doit exister dans le référentiel des tiers | se tape librement |
| Le règlement | second geste, autre bloc | même geste |
| Doublon refusé sur | client + n° de facture | date + n° + immatriculation + montant (la clé du classeur) |
| Référence | `F-1509-0001` | `IMP-1509-0001` |

### Le trou, chiffré

La communication existe déjà **dans un sens** : une créance saisie à l'état des impayés entre
aussitôt dans la balance âgée, l'extrait de compte, les relances et la trésorerie. Rien à
rapprocher — c'est une facture comme une autre, et `avecHistoriqueDeReglement()` la retient.

**Dans l'autre sens, non.** Une facture créée depuis le recouvrement ne porte pas d'année
d'état des impayés, donc n'apparaît dans aucun état. Mesuré sur la base :

> **103 factures saisies sur la plateforme, pour 40 424 806 F, que le recouvrement compte et
> que l'état des impayés ignore.**

C'est exactement le genre d'écart qu'on ne découvre qu'en rapprochant deux totaux à la main.

### Trois façons de les faire communiquer

**A — La règle commune.** *(recommandée)*

L'état des impayés cesse de s'appuyer sur sa colonne `exercice_impayes` et reprend **la règle
qui gouverne déjà tout le recouvrement** : `avecHistoriqueDeReglement()` — toute facture
saisie sur la plateforme, d'où qu'elle vienne, plus toute facture reprise d'un fichier qui
apporte ses règlements. Les factures du CATTC restent dehors : elles arrivent sans un seul
règlement en face, et les compter en créance porterait la balance de 5 millions à 1,4
milliard. C'est mesuré, et c'est déjà écrit dans le code.

L'année d'une créance se lit alors sur la date de sa facture.

- Les deux écrans montrent **la même population, pour toujours**, sans marqueur à maintenir.
- Les 103 créances entrent d'elles-mêmes.
- Une seule règle décide de ce qu'est une créance, au lieu de deux.
- Coût : une requête à changer, un test à ajouter. **Aucune migration, aucune écriture de
  données.** La colonne reste en base, sans danger, et sert encore à distinguer la reprise.

**B — Le marqueur posé des deux côtés.**

Le formulaire du recouvrement pose `exercice_impayes` comme celui de l'état. Même résultat
immédiat, coût identique — mais la règle reste **recopiée à deux endroits**, et c'est
exactement ce qui a creusé le trou d'aujourd'hui. Le troisième écran qui créera une facture
l'oubliera à son tour.

**C — Une seule porte d'entrée.**

Le bloc « Facture » du recouvrement disparaît ; toute créance naît à l'état des impayés. Le
plus net conceptuellement, et le seul qui supprime vraiment le doublon de formulaire. Mais il
retire un geste au superviseur recouvrement, qui crée aujourd'hui ses créances depuis son
propre écran sans quitter son module. **À ne faire que s'il ne s'en sert pas** — la réponse
est dans le journal d'activité.

### Ma recommandation

**A, et garder les deux formulaires.** Deux portes, un seul registre.

Ce n'est pas un compromis : les deux écrans ne servent pas le même moment. Le recouvrement
saisit une créance qu'il découvre en relançant quelqu'un — il a le tiers sous les yeux, pas le
classeur. Le superviseur de veille, lui, reprend une ligne de tableur et la retape, avec son
règlement. Même table, même règle, deux gestes différents. Ce qui doit être unique, c'est la
définition d'une créance — pas la façon d'en saisir une.

**A est réversible et ne touche à aucune donnée. Dites un mot et je l'applique.**

---

## 6. Où nous en sommes

### Fait

- [x] Migration additive : 10 colonnes nullables sur `factures`, aucune donnée touchée
- [x] `EtatDesImpayes` — les formules du classeur, la reconduction, le pont vers le CATTC
- [x] Les trois écrans, et le formulaire de saisie sur deux rangées
- [x] Les colonnes du CATTC et le filtre d'origine sur l'écran du chiffre d'affaires
- [x] `impayes:ranger-les-colonnes` — commande de reprise, en constat par défaut
- [x] 17 tests dédiés ; 542 au total, 535 réussis, 0 échec

### À faire sur chaque serveur

```bash
git pull origin impayes        # ou main, après fusion
php artisan app:deployer
php artisan impayes:ranger-les-colonnes              # constat, n'écrit rien
php artisan impayes:ranger-les-colonnes --appliquer
```

Sans la commande, **les écrans restent vides** : les créances reprises n'ont pas d'année.
Éprouvée sur la base locale : 8 852 créances datées, 7 560 phrases défaites, 283 consignes de
travail gardées seules. Elle ne remplit que ce qui est vide et, relancée, annonce zéro.

Trois colonnes resteront vides — date de réception, banque, tranche annoncée : elles
n'existaient pas au moment du premier import. Redéposer le fichier des impayés depuis
**Import** les remplit. Rien ne l'exige.

### À trancher

1. **Le lien avec le recouvrement** — option A, B ou C du § 5.
2. **L'année d'une créance** est celle de sa facture, pas celle de l'écran depuis lequel on la
   saisit. Une facture de décembre 2025 relevée en 2026 apparaît donc d'emblée « Reporté 2025 ».
3. **L'état initial est en lecture seule** ; on corrige dans l'état de l'année.
4. **L'état des impayés est fermé au responsable commercial**, comme les charges.
5. **Les créances portent la série `IMP-`**, distincte du `F-` des factures d'atelier.

### Hors de ce module, et toujours en attente

La **rotation des secrets** : mot de passe du courriel, secret Google OAuth, mot de passe
MySQL, puis `APP_KEY` et les clés VAPID.
