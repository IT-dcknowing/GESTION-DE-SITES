# État des imputations et des IA — 10/11/2026

Audit fait avant d'ajouter l'assistant du rapport. Ce qui existe, ce qui manque, ce qui vient ensuite.

## Ce qui existe déjà

| Brique | Où | Ce qu'elle fait |
|---|---|---|
| Liaison COMPTAFLOW | `LiaisonComptaflow`, `ComptaflowClient` (`provisionner`, `verifier`, `listerTiers`, `deverserReferentiel`, `deverser`, `deverserJustificatif` — 25/11), page Super admin « COMPTAFLOW liaison » | Relie une entreprise à son dossier COMPTAFLOW, vérifie l'accès, liste les tiers, déverse le référentiel |
| Suivi des déversements | `DeversementComptaflow`, `CorrespondanceTiersComptaflow` (migrations 08/11 et 09/11) | Trace ce qui est envoyé ; rattache les tiers GESTION-SITES aux tiers COMPTAFLOW |
| Plan comptable de l'application | `PlanComptable` (rôles → numéros de comptes), `CompteComptable`, page `ecritures.parametrage` (module Comptabilité ; depuis le 25/11 par habilitation `comptabilite.voir`, enregistrement `comptabilite.generer`, le gérant au départ) | Paramétrage des comptes par rôle : tiers, ventes, TVA, trésorerie, banques, charges, journaux |
| Clés d'IA | `configurations_ia`, page Super admin « Intelligence artificielle » | Clés chiffrées et modèle par fournisseur (OpenAI, Anthropic, Google, OpenRouter) |
| Plan de déversement | `PLAN-DEVERSEMENT-GESTION-SITES-RH-FLOW.md` | Le cadrage des étapes, dont l'IA d'imputation (étape 5) |

## Ce qui n'existait pas (constat)

- **Aucun appel à une IA** : la page IA ne faisait qu'enregistrer des clés. Pas de test de connexion.
- **Aucun moteur d'écritures** : `ComptaflowClient::deverser()` existe mais personne ne l'appelle ; rien ne transforme le CA, les encaissements ou les charges en écritures de journal.
- **Aucun écran de déversement** (génération, contrôle, envoi, rejeu).
- Le **guide d'écritures** (`GUIDE-ECRITURES-COMPTAFLOW.txt`, fourni le 10/11) n'était nulle part dans le dépôt.

## Ce qui est ajouté le 10/11 (assistant du rapport)

- `affectations_ia` : une IA par **usage** (`rapport`, `imputations`), choisie par le Super admin parmi les fournisseurs configurés, avec modèle et instructions propres. Bouton de test réel.
- `ClientIA` : un seul client pour les quatre fournisseurs, conversation avec **outils** (l'IA lit des données, l'application exécute sous les habilitations du compte). Il servira aussi à l'IA des imputations.
- Assistant du rapport (onglet « Assistant IA », habilitation `rapport.ia`) : chat, analyse globale, documents imprimables en PDF, dépôt de fichiers, retour à l'état du système.

## Ce qui reste pour l'IA des imputations (proposition)

1. **Générateur d'écritures déterministe** — CA (ventes, TVA collectée), encaissements (banque/caisse), charges (suivi fournisseurs, FNE, caisse) → lignes à 9 colonnes (voir le guide), équilibre contrôlé ligne par ligne et sur le fichier. Les règles du guide deviennent des contrôles de code, pas seulement des consignes.
2. **L'IA propose, le code vérifie** : l'IA choisit le compte et le journal de chaque opération à partir des libellés, du plan comptable paramétré et des règles de correspondance ; une proposition incertaine passe en « à valider ». Les comptes, journaux et tiers doivent exister dans le référentiel COMPTAFLOW.
3. **Écran de validation** puis déversement (`deverser()`), jamais automatique.
4. **Livrables** : les quatre fichiers du guide (`ecritures.txt`, `plan_comptable.txt`, `tiers.txt`, `journaux.txt`), en TXT, délimité par point-virgule, codage ANSI.
5. **Décisions à prendre avec vous** : sous-comptes de vente par nature (706100…), un journal par banque réelle (BGFI, AFG), traitement des chèques en attente (5112), gestion des proformas (jamais comptabilisées).
