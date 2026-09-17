# État des impayés — où nous en sommes

*Dernière mise à jour : 16 septembre 2026. Branche `impayes`.*

Ce document dit trois choses : **ce que contient le classeur** que nous reprenons, **ce qui a
été construit** autour, et **ce qui reste à trancher** — en particulier la question du
recouvrement, tranchée au § 5.

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
| `IF(N=0; 0; AUJOURDHUI − dateRéception)` | **Reprise depuis le 16/09** : l'ancienneté part du dépôt, et de l'édition quand le dépôt n'est pas connu — pour tout le recouvrement à la fois. Voir § 5. |
| `SI(<30 … 30<>60 … 60<>90 … >90)` | Conservée telle quelle pour pouvoir confronter la tranche que le classeur annonçait à celle qu'on recalcule. L'application en a cinq — elle coupe le « >90 » à 180 jours. |
| `CONCATENATE(date; n°; immat; montant)` | **Confirme notre clé d'import.** Son auteur avait retenu exactement les quatre champs que l'import avait trouvés de son côté, en éprouvant les combinaisons sur le fichier entier. Un numéro de facture, seul, n'identifie rien ici. |

---

## 2. Ce qui a été construit

Trois écrans, dans la section **Indicateurs**, ouverts au gérant, au superviseur de ville et
au responsable de site. Fermés au responsable commercial, comme les charges et la trésorerie.

**État des impayés** (`/impayes`) — l'état de l'année. Un bouton « Ajouter une
créance » ouvre les seize colonnes saisissables du classeur, dans son ordre et sous ses mots
exacts, sur deux rangées. Chacun des défauts du § 1 y est refusé à la frappe. Référence
générée `IMP-1509-0001`, avec le code de saisie et le nom dessous. Depuis le 16/09 : toutes
les colonnes du classeur au tableau, **Détail** et **Modifier** sur chaque ligne, et **Porter
une facture existante** — voir § 5 et § 6.

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

### Ce qui distingue les deux : le dépôt

**Décision du 15 septembre 2026 : on garde les deux.** Et la raison est venue avec elle :

> **L'état des impayés est basé sur les factures physiquement déposées chez le client.
> Tout s'y saisit, le numéro de facture compris.**

Cela change la lecture de l'écart relevé plus haut. Les deux écrans n'enregistrent pas le même
fait :

| | Recouvrement > Saisie > « Facture » | État des impayés |
|---|---|---|
| Ce qu'on enregistre | une créance découverte en relançant quelqu'un | **une facture remise au client** |
| Le moment | pendant la poursuite | au dépôt |
| La date qui compte | la date de la facture | **la date de réception = le dépôt** |
| Le numéro | celui qu'on connaît | celui lu sur la facture papier |

Une facture saisie à la main n'est donc pas, par le seul fait d'être saisie, une facture
déposée. C'est pourquoi l'état garde **son propre marqueur** (`exercice_impayes`) au lieu de
se régler sur l'origine de la facture.

### Ce qu'on ne fait donc pas

L'option que je recommandais — que l'état des impayés reprenne la règle du recouvrement et
compte toute facture saisie sur la plateforme — **est écartée**. Elle aurait versé dans l'état
les 103 factures du recouvrement (40 424 806 F) sans qu'aucune ait été déposée : l'état aurait
cessé de dire ce qu'il dit.

Ces 103 factures ne sont donc pas un « trou » : ce sont des créances poursuivies qui n'ont pas
(encore) été relevées comme déposées.

### Ce qui communique, dans les deux sens

- **État → Recouvrement : fait.** Une facture saisie à l'état entre aussitôt dans la balance
  âgée, l'extrait de compte, les relances et la trésorerie. Rien à rapprocher.
- **Reste de l'application → État : fait le 16/09, refait le 17/09.** Deux portes :
  - sur **l'état des impayés**, le bouton **« Porter une facture existante »** ouvre deux listes
    qu'on fouille par l'intérieur — le **client**, puis **ses factures** par **numéro de
    saisie** ;
  - sur **le chiffre d'affaires**, chaque facture hors de l'état porte un bouton **« Porter à
    l'état »** qui ouvre ce même panneau, facture déjà choisie.

  Ce que la facture connaît se remplit seul (atelier, ville, n° de sinistre, banque, date de
  réception si elle en a une) ; le reste s'affiche en lecture. On ne saisit que la **date de
  dépôt**, obligatoire, et ce qui a déjà été payé — sans retaper, sans renuméroter.

### D'où vient une facture qu'on porte

Une facture « existe déjà » quand l'un de ces trois gestes l'a créée :

| Où elle est née | Écran ou fichier | Ses règlements |
|---|---|---|
| **CATTC importé** | Import › Chiffre d'affaires TTC | **aucun** — le fichier n'en porte pas |
| **Saisie du jour** | Responsable de site › Saisie du jour › Factures | ceux saisis ensuite en caisse |
| **Recouvrement** | Recouvrement › Saisie › bloc « Facture » | ceux saisis au recouvrement ou en caisse |

**« Enregistrer un encaissement » ne crée jamais de facture** : il règle une facture qui existe
déjà. Il y a quatre écrans d'encaissement — Comptabilité › Encaissements, Saisie du jour,
Recouvrement › Saisie, et la saisie de l'état des impayés — plus l'import du classeur des
impayés, qui transforme sa colonne « Montantréglé » en encaissements.

**Les encaissements ne viennent pas du CATTC.** Ce fichier liste les factures émises, sans un
seul règlement. C'est pourquoi une facture du CATTC reste hors du recouvrement : comptée telle
quelle, elle se lirait intégralement due. La porter à l'état **demande donc ce qui a déjà été
payé** et l'enregistre en encaissement dans le même geste ; elle entre alors au recouvrement.

Garde-fous du geste :

- la date de dépôt est obligatoire et ne peut pas précéder l'édition ;
- le règlement déclaré ne peut pas dépasser le reste ;
- si une ligne de l'état porte **la même immatriculation et le même montant** — la même affaire
  reprise du classeur sous un autre numéro, le cas de 71 % du CATTC —, l'écran la montre et
  demande de cocher « ce n'est pas la même facture » avant d'accepter ;
- deux personnes qui portent la même facture au même instant : la seconde est refusée.

### L'âge d'une créance part du dépôt — décidé le 16/09

Le classeur comptait l'ancienneté **depuis la date de réception** ; l'application, depuis
l'édition. **Désormais, partout, depuis le dépôt**, et depuis l'édition quand le dépôt n'est pas
connu (`Recouvrement::dateDeDepart()`). Une seule règle pour tout le recouvrement : balance
âgée, relances, contentieux, tableau de bord, extrait.

Mesuré en local après relecture du classeur (8 850 dates de réception) : **8 factures ouvertes
sur 1 341 changent de niveau**. Le dossier et l'extrait de compte affichent « déposée le … » sous
l'âge, pour qu'on voie d'où il part.

**Lire « 34 j / 30<>60 »** : 34 jours entre la date de départ (dépôt, ou édition à défaut — la
mention « dépôt » ou « édition » est écrite à côté) et la date d'arrêté de l'état ; « 30<>60 »
est la tranche **du classeur** (<30, 30<>60, 60<>90, >90). La balance âgée du recouvrement
coupe autrement (0-30, 31-60, 61-90, 91-180, +180) : ce sont les mêmes jours, rangés dans deux
grilles.

### La ville d'une facture — 16/09

La colonne SITE des fichiers nomme une **ville**, pas un atelier, et Abidjan en a deux : le site
restait vide, la ville était perdue. Les factures ont maintenant `ville_id`. Le modèle la
déduit de l'atelier ; l'import l'écrit **quand le fichier la dit** (colonne SITE, code agent),
jamais d'après la seule ville déclarée au dépôt.

Colonne SITE du classeur, mesurée par l'import lui-même : ABIDJAN 5 097 lignes, SAN PEDRO 4,
**vide 3 771**. Ces dernières restent « à préciser » et s'affichent dans toutes les villes —
274 créances ouvertes, 134 509 255 F en local. Elles se situent une à une par **Modifier**.

---

## 6. Où nous en sommes

### Fait

- [x] Migration additive : 10 colonnes nullables sur `factures`, aucune donnée touchée
- [x] `EtatDesImpayes` — les formules du classeur, la reconduction, le pont vers le CATTC
- [x] Les trois écrans ; le formulaire de saisie sur deux rangées
- [x] Les colonnes du CATTC et le filtre d'origine sur l'écran du chiffre d'affaires
- [x] `impayes:ranger-les-colonnes` — commande de reprise, en constat par défaut
- [x] Nom de l'écran : « État des impayés » (FICORE retiré)
- [x] La définition « facture déposée chez le client » écrite dans le code et à l'écran
- [x] 16/09 — tableau : toutes les colonnes du classeur (commentaires, date de réception,
      véhicule, mode et date de règlement, banque) ; boutons **Détail** (page propre,
      `/impayes/creance/{id}`, avec « Modifier » en tête) et **Modifier**
- [x] 16/09 — **Porter une facture existante**
- [x] 17/09 — Porter : listes client → facture (numéro de saisie) avec recherche intégrée,
      champs préremplis ; bouton « Porter à l'état » sur le chiffre d'affaires
- [x] 17/09 — vitesse : solde, report, totaux et pagination de l'état calculés en base
- [x] 16/09 — date de réception **obligatoire** ; ancienneté **depuis le dépôt**
- [x] 16/09 — `factures.ville_id`, commande `factures:poser-la-ville` ; sélecteur de ville sur
      le tableau de bord du recouvrement ; encaissements filtrés par ville
- [x] 16/09 — corrigé : après une première créance, la suivante sans règlement réclamait un mode
      de règlement (`reset()` de Volt remet à null)

### Modifier — ce qu'on peut changer

Tout, sauf sur une **ligne reprise du classeur** : date d'édition, numéro, immatriculation et
montant y sont verrouillés (côté serveur, pas seulement à l'écran), parce que c'est la clé par
laquelle l'import reconnaît la ligne — les changer ferait créer un doublon au prochain dépôt. Le
montant ne peut pas descendre sous ce qui est encaissé. « Nouveau règlement » **ajoute** un
encaissement. Chaque modification est tracée avant/après, et se lit sur la page **Détail**. Une
créance hors du périmètre du compte ne s'ouvre pas, même en forgeant son identifiant.

### À faire sur chaque serveur

```bash
git pull origin main           # après fusion de impayes dans main, faite sur le poste
php artisan app:deployer
php artisan impayes:ranger-les-colonnes              # constat, n'écrit rien
php artisan impayes:ranger-les-colonnes --appliquer
php artisan factures:poser-la-ville                  # constat, n'écrit rien
php artisan factures:poser-la-ville --appliquer
```

Sans la première commande, **les écrans restent vides** : les créances reprises n'ont pas
d'année. Éprouvée sur la base locale : 8 852 créances datées, 7 560 phrases défaites, 283
consignes de travail gardées seules. Elle ne remplit que ce qui est vide et, relancée, annonce
zéro.

Date de réception, banque, tranche annoncée et **ville** des lignes reprises se remplissent en
**redéposant** le fichier des impayés depuis **Import**.

### Tranché

- On garde **les deux** formulaires ; l'état des impayés = factures déposées chez le client.
- Le nom de l'écran est **État des impayés**.
- **16/09** : l'âge part du dépôt ; « Porter une facture existante » construit ; date de
  réception obligatoire ; ville sur le tableau de bord du recouvrement.

### À confirmer (arbitrages pris sans confirmation)

1. L'année d'une créance est celle de sa facture, pas celle de l'écran de saisie.
2. L'état initial est en lecture seule ; on corrige dans l'état de l'année (par **Modifier**).
3. L'état des impayés est fermé au responsable commercial, comme les charges.
4. Les créances portent la série `IMP-`, distincte du `F-` des factures d'atelier.
5. En modification, l'atelier reste facultatif (les 3 771 lignes sans SITE) ; une ville seule
   peut être choisie.
6. Relances (journal) : elles restent comptées pour l'entreprise entière quand on regarde une
   ville — une relance vise un tiers, et un tiers travaille dans plusieurs villes.

### Hors de ce module, et toujours en attente

La **rotation des secrets** : mot de passe du courriel, secret Google OAuth, mot de passe
MySQL, puis `APP_KEY` et les clés VAPID.
