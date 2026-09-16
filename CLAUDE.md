# GESTION-DE-SITES — consignes de séance

**Avant tout travail, lire [ETAT-DES-LIEUX.md](ETAT-DES-LIEUX.md).** Il dit où en est chaque
module, le chantier en cours, ce qui est décidé et ce qui attend une décision.

**À la fin de chaque séance, le mettre à jour** (§§ 4, 5, 6 et la date), puis le commiter avec
le travail.

Règles qui priment sur tout le reste (détail au § 2 d'ETAT-DES-LIEUX.md) :

- La base en ligne porte des données réelles : migrations additives seulement, aucune écriture
  de données sans constat préalable, rien qui écrive des données dans `app:deployer`.
- Une branche git par module ; commits sur cette branche ; le propriétaire pousse lui-même.
- Jamais de déploiement par zip.
- Écrire comme le code existant : français, commentaires qui disent pourquoi.
