# GESTION-DE-SITES — consignes de séance

**Avant tout travail, lire [REPRENDRE-ICI.md](REPRENDRE-ICI.md) en entier.** Deux cents lignes :
où l'on en est, ce qui reste et par qui, les règles métier de l'argent, et les seize pièges qui
ont déjà coûté une séance chacun.

[ETAT-DES-LIEUX.md](ETAT-DES-LIEUX.md) est la mémoire longue — 2 800 lignes, l'histoire de
chaque décision. On y va pour le détail d'un point précis, pas pour commencer.

**À la fin de chaque séance**, mettre à jour les deux (ETAT-DES-LIEUX §§ 4, 5, 6 et la date ;
REPRENDRE-ICI §§ 3, 4, 5), puis les commiter avec le travail.

Règles qui priment sur tout le reste (détail au § 2 d'ETAT-DES-LIEUX.md) :

- La base en ligne porte des données réelles : migrations additives seulement, aucune écriture
  de données sans constat préalable, rien qui écrive des données dans `app:deployer`.
- Une branche git par module ; commits sur cette branche ; en fin de travail, pousser la
  branche **puis la fusionner dans `main` et pousser `main`** (consignes du 05/10).
- Jamais de déploiement par zip.
- Écrire comme le code existant : français, commentaires qui disent pourquoi.
