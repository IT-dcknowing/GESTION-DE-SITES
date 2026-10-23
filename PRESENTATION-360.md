# GESTION-DE-SITES — présentation à 360°

*Document de présentation à transmettre à un tiers (prestataire, auditeur, partenaire, autre
équipe). Il dit ce qu'est l'application, comment elle fonctionne, et s'attarde sur les deux
sujets qui en font la difficulté : **les importations** et **les charges**. État au 23 octobre 2026.*

> Aucun identifiant, mot de passe ni adresse de serveur ne figure ici. Les noms de classes
> sont cités pour qu'un développeur retrouve le code ; un lecteur non technique peut les sauter.

---

## Sommaire

1. [L'application en une page](#1-lapplication-en-une-page)
2. [Le contexte métier](#2-le-contexte-métier)
3. [Le principe de fonctionnement](#3-le-principe-de-fonctionnement)
4. [Qui l'utilise, et ce que chacun voit](#4-qui-lutilise-et-ce-que-chacun-voit)
5. [Les écrans, par domaine](#5-les-écrans-par-domaine)
6. [**Les importations**](#6-les-importations)
7. [**Les charges**](#7-les-charges)
8. [Caisse, banques, trésorerie](#8-caisse-banques-trésorerie)
9. [Chiffre d'affaires, impayés, recouvrement](#9-chiffre-daffaires-impayés-recouvrement)
10. [Le commercial : prospections, correspondances, objectifs](#10-le-commercial--prospections-correspondances-objectifs)
11. [L'architecture technique](#11-larchitecture-technique)
12. [Les règles de conception qui ne se discutent pas](#12-les-règles-de-conception-qui-ne-se-discutent-pas)
13. [Où en est le projet](#13-où-en-est-le-projet)
14. [Glossaire](#14-glossaire)

---

## 1. L'application en une page

**GESTION-DE-SITES** est une application web de gestion pour des **ateliers de réparation
automobile répartis sur plusieurs villes**. Elle est conçue multi-entreprises ; son premier
client réel est **L'Artisan Automobile** (Côte d'Ivoire) : trois villes — **Abidjan (deux
ateliers)**, **Bouaké**, **San-Pédro**.

Elle répond à trois besoins :

1. **Rassembler en un seul endroit** ce que l'entreprise disperse aujourd'hui entre un logiciel
   d'atelier — **il n'y a pas de logiciel comptable** (précisé le 23/10) — et une dizaine de classeurs
   Excel tenus à la main.
2. **Saisir ce qu'aucun logiciel ne connaît** : la prospection commerciale, les relances de
   recouvrement, les règlements et les charges du jour, les commentaires.
3. **Montrer à chacun ce qui le concerne, ni plus ni moins** : le gérant voit l'entreprise, le
   superviseur sa ville, le responsable son atelier, le commercial ses affaires.

Le principe central : **on dépose les fichiers, l'application les lit, et tous les écrans se
remplissent.** Chiffre d'affaires, impayés, caisse, banques, fournisseurs, charges, trésorerie,
performance des commerciaux — tout part de là.

| | |
|---|---|
| Type | Application web (navigateur), responsive |
| Pile | PHP / Laravel 13, Livewire 4 + Volt, MySQL |
| Organisation du code | 9 modules (`nwidart/laravel-modules`) |
| Droits | Rôles et permissions par entreprise (Spatie) |
| Données | Fichiers Excel (`.xlsx`, `.xls`), un PDF, un JSON (factures FNE), et saisies |
| Tests automatisés | ≈ 1 200 tests |

---

## 2. Le contexte métier

### L'entreprise

Un garage reçoit un véhicule (**fiche de réception**), en chiffre la réparation (**devis /
proforma**), fait les travaux, **facture**, puis **encaisse** — souvent auprès d'une assurance
ou d'un courtier plutôt que du client lui-même (dossiers de **sinistre**). L'entreprise achète
des pièces à des **fournisseurs**, paie des salaires et des frais, tient une **caisse**
d'espèces par ville et des **comptes bancaires**.

### Les deux activités

- **Mécanique** — entretien et réparation courante.
- **Sinistre** — réparation après accident, payée par un assureur ; c'est là que naissent les
  longues créances et la chaîne client → assurance → courtier.

### Le chemin d'une affaire

```
PROSPECTION  →  DEVIS  →  FACTURE  →  ENCAISSEMENT
 (un contact)  (chiffré)  (LE chiffre   (l'argent
                           d'affaires)   reçu)
```

Le chiffre d'affaires naît **à la facture**, pas au devis ni à l'encaissement. Ce qui est
facturé et pas encore payé est une **créance** ; l'ensemble des créances forme **l'état des
impayés**, que le module Recouvrement poursuit.

### Pourquoi c'est difficile

**Les données ne naissent pas dans l'application.** Elles viennent :

- du **logiciel d'atelier** — le seul logiciel de la maison, qui tient aussi les caisses : parc,
  devis, factures, impayés, entrées/sorties de véhicules, balance et règlements fournisseurs,
  journal de caisse, banques. Il n'y a **pas de logiciel comptable** (23/10) ;
- de **classeurs tenus à la main** (caisse d'Abidjan, suivi des fournisseurs, relevé bancaire
  de la caissière) ;
- du **portail FNE** de l'administration fiscale (factures normalisées des fournisseurs).

Dans le logiciel d'atelier, **les trois villes vivent dans la même base** : un export n'est pas
« le fichier d'une ville », c'est une extraction filtrée puis renommée à la main. Les classeurs
manuels portent des dates illisibles, des montants négatifs, plusieurs feuillets, des lignes
répétées. **C'est normal, et toute l'application est construite pour le supporter sans jamais
deviner.**

---

## 3. Le principe de fonctionnement

```
  Logiciel d'atelier ─┐                ┌──────────────────────────────┐
  Classeurs manuels ──┼──► IMPORT ───► │                              │
  Portail FNE (JSON) ─┘   (contrôle,   │   BASE UNIQUE                │ ──► Écrans par rôle
                           simulation, │   (par entreprise, par ville,│     (tableaux de bord,
  Saisies à l'écran ──────────────────►│    par atelier, par exercice)│      KPI, listes,
  (prospections, règlements,           │                              │      exports Excel/PDF)
   charges, relances…)                 └──────────────────────────────┘
```

Trois idées tiennent l'ensemble :

1. **Une seule source de vérité par chiffre.** Chaque calcul d'argent (ce qu'est une charge, ce
   qu'est le chiffre d'affaires, ce qu'est la trésorerie, qui voit quelle ligne) est écrit **une
   fois**, dans un service du Noyau, et tous les écrans le lisent. Deux écrans ne peuvent pas
   donner deux chiffres différents pour la même question.
2. **Le périmètre.** Chaque ligne appartient à un **atelier** s'il est connu, sinon à une
   **ville**, sinon elle paraît **partout** — la cacher reviendrait à la perdre.
3. **L'exercice.** Les données se lisent par exercice (année) et par période ; un sélecteur
   (« loupe ») permet de changer d'exercice et de ville.

---

## 4. Qui l'utilise, et ce que chacun voit

| Rôle | Qui c'est | Périmètre | Ce qu'il fait |
|---|---|---|---|
| `super_admin` | l'éditeur de la plateforme | toutes les entreprises | crée les entreprises et les accès, attribue les codes employés, journal, traçabilité, maintenance, incidents |
| `gerant` | la direction | toute l'entreprise | tout voir, tout importer, paramétrer (barèmes, objectifs), arbitrer |
| `responsable_ville` | superviseur de ville | sa ville | pilotage : CA, charges, trésorerie, caisse, fournisseurs, impayés, accès sous lui |
| `responsable_site` | responsable d'atelier | son atelier | saisie du jour, prospections, pilotage limité à son lieu |
| `responsable_commercial` | chef des ventes | équipe commerciale | suivi des commerciaux, correspondances |
| `commercial` | le terrain | ses affaires | prospections, performance, notes, correspondances |
| `caissier` | comptabilité de ville | sa ville | encaissements, décaissements, caisse, reprise des sorties de caisse |
| `superviseur_recouvrement` | chef du recouvrement | — | relances jusqu'au niveau 4, synthèse |
| `agent_recouvrement` | agent | — | saisie des relances jusqu'au niveau 3 |
| `tresorerie` | trésorerie (22/10) | sa ville, ou toutes | lecture seule : trésorerie, caisse (et caisse par véhicule), banques |
| *rôle configuré* | défini par le Super admin (22/10) | sa ville, ou toutes | lecture seule des pages cochées |

**Les rôles configurés (22/10).** Le Super admin crée un rôle dans **Maintenance › Rôles et pages** :
un nom, puis les sections et les pages cochées (indicateurs, trésorerie, charges, fournisseurs,
tableau de bord de la direction) ; un bouton **Modifier** les reprend. Le rôle se choisit ensuite à
la création d'un accès, comme les autres. Il **lit** ses pages et n'y écrit rien : toute action qui
engage l'entreprise reste réservée aux rôles du métier. Le rôle **Trésorerie** est le premier de
ces rôles, défini d'office (`CatalogueDesPages`). Les **rôles du métier** figurent sur la même page,
avec ce qu'ils ouvrent, et leurs pages se **modifient** aussi (23/10) — ouvrir ou fermer une page de
lecture ; leurs droits d'écriture restent ceux du code.

Chaque accès est vérifié **deux fois** : à la route (qui entre dans le module) et dans l'action
(jusqu'où il va). Le périmètre est toujours lu sur **l'identité** de l'utilisateur, jamais sur un
paramètre envoyé par le navigateur.

---

## 5. Les écrans, par domaine

| Domaine | Écrans principaux |
|---|---|
| **Accueil** | tableau de bord par rôle ; pour le gérant : synthèse par atelier, **charges — synthèse par source**, objectifs du mois, CA, encaissé, charges, résultat |
| **Commercial** | prospects, affichage général des prospections, devis, commerciaux, ma performance, correspondances factures ↔ commerciaux |
| **Ventes** | chiffre d'affaires, rapprochement CA ↔ impayés, rapprochement prospections ↔ devis |
| **Créances** | état des impayés, état initial, détail d'une créance ; module Recouvrement (9 pages : saisie, synthèse, balance âgée, courtiers, clients & tiers, extrait de compte, relances, encaissements, piste d'audit) |
| **Argent** | **charges**, trésorerie, caisse, banques |
| **Charges fournisseurs** (depuis la page Charges) | toutes les factures (suivi et FNE pas encore au suivi) et « à qui nous devons le plus » en un seul tableau à deux boutons, liste (émetteurs FNE, noms à accepter), factures FNE (filtres tiers, n°, dates, montant), balance (importée / produite), **grand livre**, règlements, **décaissements fournisseurs de la caisse** (« Suivre », lettrage), tableau initial |
| **Atelier** | parc véhicules (fiche par dossier), clients, entrées / sorties de véhicules, saisie du jour |
| **Import** | dépôt, informations, traitements en cours, journal des imports (détail, corrections, traçabilité), codes employés |
| **Administration** | entreprises, accès (création, rattrapage), administrateurs, codes, journal, maintenance (dont **rôles et pages**), incidents |
| **Commun** | messagerie interne, notifications (y compris poussées), mon espace, mon profil |

---

## 6. Les importations

> C'est le cœur de l'application : **presque tout l'argent affiché à l'écran est arrivé par un
> fichier déposé.** Le module `Import` (écrans) et `Modules/Noyau/app/Imports/` (logique) en
> portent tout le mécanisme.

### 6.1 Les formats acceptés — et l'ordre dans lequel les déposer

L'ordre n'est pas arbitraire : il suit les dépendances. Le parc fonde la fiche de réception, que
le devis et la facture citent ; les impayés apportent des règlements qui n'ont de sens qu'en
regard d'une facture. Source unique : `Registre::DISPONIBLES`.

| # | Clé | Fichier | Origine | Ce qu'il alimente | Colonnes clés |
|---|---|---|---|---|---|
| 1 | `parc` | Situation du parc — fiches de réception | logiciel d'atelier | dossiers véhicules, statut, dates des travaux | N° fiche réception, immatriculation, client, statut, dates |
| 2 | `devis` | Devis et proformas | logiciel d'atelier | devis, taux de transformation | N° proforma, fiche, client, montant |
| 3 | `factures` | Chiffre d'affaires TTC (« CATTC ») | logiciel d'atelier | **chiffre d'affaires** | N° facture, fiche, sinistre, montant, site |
| 4 | `impayes` | État des impayés — créances clients | logiciel d'atelier | créances, **règlements reçus**, recouvrement | assureur, courtier, client, n° facture, TTC, réglé, reste, mode et date de règlement |
| 5 | `fournisseurs` | Suivi des factures fournisseurs | classeur manuel | **suivi fournisseurs** (dettes) | date facture, n° pièce, fournisseur, montant, réglé, reste |
| 6 | `balance-fournisseurs` | Balance fournisseurs | logiciel d'atelier | balance importée | fournisseur, débit, crédit, solde |
| 7 | `reglements-fournisseurs` | Règlements fournisseurs | logiciel d'atelier | paiements enregistrés | code règlement, fournisseur, mode, montant |
| 8 | `banque` | Pièces bancaires | logiciel d'atelier | banques | code pièce, type, bénéficiaire, montant |
| 9 | `releve-banque` | Relevé bancaire — suivi de la caissière (BGFI, AFG) | classeur manuel | **banques, trésorerie** | date, libellé, débit, crédit, solde |
| 10 | `caisse` | Classeur de caisse (Abidjan) | classeur manuel, un onglet par mois | **caisse, charges, trésorerie** | date, libellé, entrées, sorties, solde, bénéficiaire |
| 11 | `journal-caisse` | Journal de caisse imprimé (Bouaké, San-Pédro) | logiciel d'atelier, **PDF** | **caisse, charges, trésorerie** | date, libellé, entrée, sortie, solde, motif |
| 12 | `entrees` | Entrées de véhicules | logiciel d'atelier | mouvements du parc | n° fiche, immatriculation, motif, travaux |
| 13 | `sorties` | Sorties de véhicules | logiciel d'atelier | mouvements du parc | n° fiche, date de sortie |

**Hors de cette liste** : les **factures d'achat FNE**, relevées automatiquement sur le portail
fiscal (voir 6.11), et l'import de l'annuaire des **codes employés**.

Les deux relevés bancaires se déposent **par compte** (le fichier ne nomme pas la banque) ; les
autres fichiers se déposent avec une **ville** (ou « toutes villes »).

### 6.2 Le parcours d'un dépôt, étape par étape

```
 1. DÉPÔT         2. CONTRÔLE        3. SIMULATION      4. TRAITEMENT       5. RÉSULTAT
 ─────────        ──────────         ────────────       ─────────────       ──────────
 fichier +        le fichier         tout est lu,       processus à part,   créées / mises à
 type + ville  →  ressemble-t-il  →  rattaché, compté → barre de        →   jour / ignorées /
 (+ compte)       au type ? vient-   puis annulé        progression,        refusées / doublons
                  il de la ville ?   (rien n'est écrit) tout-ou-rien        → journal du lot
```

1. **Dépôt** (`Depot`). Un vrai formulaire POST, qui marche même sans JavaScript. La requête ne
   lit pas une ligne : elle vérifie, range le fichier, crée un **lot** et rend la main.
   - **Anti-doublon de fichier** : une empreinte **SHA-256 du contenu** est calculée. Le même
     fichier, même renommé, ne s'importe qu'une fois ; l'écran dit qui l'a déposé, quand, et ce
     que ça avait donné.
   - **Périmètre vérifié côté serveur** : un superviseur ne dépose que pour sa ville.
2. **Contrôle préalable** (`ControlePrealable`). Deux questions, qui **avertissent sans bloquer** :
   - *Ce fichier est-il du type annoncé ?* Ses en-têtes sont comparés à ceux de tous les formats ;
     si un autre format les reconnaît mieux, on le dit, en nommant les colonnes manquantes.
   - *Vient-il de la ville annoncée ?* Les **codes employés** contenus dans les numéros de fiche
     sont comparés à leur ville connue ; une incohérence est chiffrée.
   - Seul cas bloquant : aucun format ne reconnaît le fichier.
3. **Simulation** (`Executeur`). Le travail complet est fait — lecture, rattachement, comptage —
   puis **la transaction est annulée**. « Rien n'a été écrit » est garanti par la base elle-même,
   pas par la discipline du code.
4. **Traitement** (`LanceurDeTraitement`, `TraiterUnLot`). La lecture démarre immédiatement dans
   un **processus séparé** (`php artisan import:traiter-lot`) ; la page affiche une progression
   réelle. Filet de sécurité : file d'attente, puis traitement après réponse si l'hébergement
   interdit les processus. Un lot n'est jamais lu deux fois (prise atomique). L'import peut être
   **arrêté** en cours.
   - **Tout ou rien** : tout le parcours tient dans une transaction ; une panne à la ligne 4 212
     ramène la base exactement à l'état d'avant.
5. **Résultat**. Chaque lot garde ses compteurs (créées, mises à jour, ignorées, refusées,
   doublons), son fichier d'origine téléchargeable, et sa traçabilité.

### 6.3 Lire des fichiers tenus à la main

- **L'en-tête est cherché, pas supposé** : dans les 12 premières lignes, celle qui ressemble le
  plus à l'en-tête attendu (l'état des impayés commence en ligne 5 après un bloc d'adresse, le
  CATTC en ligne 2…). Un logo ajouté ne casse rien.
- **La bonne feuille est choisie par son en-tête**, pas par sa position (le suivi fournisseurs a
  5 feuilles, l'état des impayés 10, souvent des tableaux croisés en premier).
- **Plusieurs onglets** : le classeur de caisse est lu onglet mensuel par onglet mensuel ; le
  journal de caisse PDF est lu page par page (45 pages sur un état).
- **Formats de fichier** : `.xlsx`, `.xls`, `.pdf` (journal de caisse uniquement), JSON (FNE).
- **Ne jamais deviner** : une ligne illisible n'est ni corrigée ni écrasée ; elle est **mise de
  côté avec son motif et ses valeurs d'origine**.

### 6.4 Où va chaque ligne : le rattachement à une ville et un atelier

C'est la question centrale du module (`Rattachement`). Le nom du fichier ne prouve rien — on a
mesuré qu'un fichier « San-Pédro » de devis contenait 216 de ses 217 proformas en commun avec
celui d'Abidjan. Quatre sources sont interrogées, **la première qui répond gagne** :

1. **le dossier déjà en base** (une affaire ne change pas de ville entre deux dépôts) ;
2. **la colonne SITE** du fichier, quand elle existe (CATTC, impayés) ;
3. **le code employé** lu dans le numéro de fiche : `FR-KZN° 010669` → **KZ** → sa ville et son
   atelier ;
4. **la ville déclarée au dépôt**, en dernier recours — et la ligne est marquée « présumée ».

**L'atelier** : Bouaké et San-Pédro n'en ont qu'un. **Abidjan en a deux, et aucun fichier ne les
distingue.** Le seul discriminant est le **code de deux lettres** de l'employé qui a rédigé la
fiche. Ces codes sont attribués par le super administrateur, et **chaque employé confirme le
sien** (`CodeDeLAtelier`, `AffectationDesCodes`). Une ligne dont l'atelier est inconnu reste
rattachée à la ville et le dit : un chiffre d'affaires attribué au mauvais atelier est pire
qu'un chiffre en attente d'affectation.

Le code employé sert aussi à **attribuer devis et factures aux commerciaux**.

### 6.5 Les lignes refusées, et leur correction

Une ligne refusée (date illisible, montant absent…) est gardée avec **toutes ses valeurs**. Au
lieu de rouvrir le classeur et de tout redéposer, on la **corrige à l'écran, colonne par
colonne** (`CorrectionsDUnLot`), puis l'import **se rejoue** sur le même fichier, corrections
par-dessus, sans recréer ce qui existe déjà. Garde-fous : seules les lignes refusées par l'import
sont corrigeables (le numéro vient du journal des rejets, jamais du formulaire), seules les
colonnes du format, et chaque correction est tracée (avant / après / auteur / heure / adresse) —
elle se relit comme une écriture comptable.

### 6.6 Les doublons à l'intérieur d'un fichier

Règle du propriétaire (15/10) : **au premier import, rien n'est écarté.**

- La ligne répétée est **importée et comptée**, puis listée « **supposée doublon à preuve du
  contraire** », avec sa **raison** (même numéro, même date, même montant… ou ce qui diffère).
- Trois boutons : **garder la première**, **garder la seconde**, **garder les deux**.
- Une ligne retirée ne revient pas au dépôt suivant ; redéposer le même fichier ne réimporte rien.
- Chaque page concernée (CA, impayés, devis, caisse, banques, fournisseurs) affiche **Importé /
  Retenu / Écarté / Intégré** et un tableau « Voir les doublons ».
- Plus aucune fusion « approximative » de deux montants proches.

Origine de la règle : une facture répétée dans le CATTC réécrivait la première, et 10 000 F
disparaissaient sans trace du chiffre d'affaires.

### 6.7 Les redépôts, et les deux fichiers qui décrivent les mêmes factures

- **Redéposer un fichier enrichi** (le même mois avec des lignes en plus) met à jour les lignes
  existantes, retrouvées par leur clé (numéro de facture, de fiche, de pièce…), et crée les
  nouvelles. **Rien ne se double.**
- **CA ↔ impayés** (`PontDesFactures`) : le CATTC et l'état des impayés décrivent les mêmes
  factures **sans numéro commun** (« FA -5713 » d'un côté, « 17 » de l'autre). Une facture de
  l'état se reconnaît au CA par **même date + même montant + même plaque**, et seulement quand
  **un seul candidat** répond. Sinon on crée la ligne : un doublon visible vaut mieux qu'une
  fusion fausse invisible. La commande `factures:doublons` compte les cas, en lecture seule.

### 6.8 Défaire un import

Un import qui a **réussi alors qu'il n'aurait pas dû** (mauvais fichier, mauvaise ville, mauvais
mois) peut être annulé (`AnnulationDUnLot`). Chaque ligne porte le numéro de son lot :

- les lignes **créées** par le lot sont supprimées ;
- les lignes **mises à jour** ne peuvent pas être défaites (leur valeur d'avant n'est pas
  conservée) — et l'écran le dit ;
- une ligne **touchée depuis** (une facture importée puis encaissée à la main) est laissée en
  place et comptée à part, pour décision humaine.

Le compte des unes et des autres est annoncé **avant** de décider.

### 6.9 L'argent à l'import : jamais arrondi, et le signe décide

- **Aucun montant n'est arrondi** (règle du 13/10, « tel que donné ») : toutes les colonnes
  d'argent sont en `decimal(18,4)` ; les centimes vont du fichier à l'écran.
- **Trois natures de montant négatif**, reconnues par le signe et jamais par le texte
  (`NatureDeLaCreance`) :

| Nature | Reconnaissance | Traitement |
|---|---|---|
| **Avoir** | TTC < 0 | entre en négatif, diminue ce que le client doit et le CA |
| **Trop-perçu** | TTC ≥ 0 et reste ≤ −2 | signalé ; la facture reste due |
| **Arrondi** | reste = −1 | ignoré |

  Une facture de 19 819 280 F et son avoir de −19 819 280 F font **0 F** de chiffre d'affaires ;
  l'ancien import gardait la facture et jetait l'avoir (≈ 47 millions surestimés au total).

- **Le solde de caisse** est un **cumul ligne à ligne, dans l'ordre du fichier, à l'intérieur
  d'un feuillet** — jamais trié par date (une date mal lue déplacerait sa ligne). Une annulation
  est un montant négatif dans la colonne de la pièce annulée. Règle complète :
  `LE-SOLDE-DE-CAISSE.md`.

### 6.10 L'import parle de l'exercice

Un dépôt se fait sous un **exercice** ; les lignes vont dans l'exercice de leur date. Les soldes
fournisseurs, eux, se reportent d'un exercice à l'autre par construction.

### 6.11 Les factures d'achat FNE, relevées automatiquement

Le logiciel d'atelier n'exporte pas les factures fournisseurs. Les **factures normalisées (FNE)**
émises par les fournisseurs sont donc **relevées sur le portail fiscal** par un robot
(`SCRAPER-PORTAIL-FNE/`, Node + Chromium) :

- automatiquement **toutes les heures de 7 h à 19 h, du lundi au samedi** (35 derniers jours), et
  **tout l'historique chaque nuit** ; bouton **« Actualiser depuis la FNE »** pour le faire tout de
  suite ; dépôt manuel du JSON en secours ;
- chaque facture est **rapprochée** du suivi fournisseurs : d'abord par le **numéro FNE** cité dans
  une ligne importée, puis par date et montant ;
- une facture non rapprochée attend qu'on la **« Porte »** au suivi (formulaire prérempli, les
  données de la FNE en lecture seule, relues côté serveur) ;
- **aucune dette n'est créée d'office** : cela doublerait la ligne que le classeur de suivi
  apportera ;
- la clé d'API que le portail joint aux fiches émetteur **n'est pas conservée**.

La page **Factures FNE** se filtre par **tiers** (nom ou NCC), **n° FNE**, **dates** et **montant de A
à B** ; elle donne le nombre et le total TTC de ce qu'elle montre (22/10). Les numéros du suivi tenu à
la main ne collent pas, le plus souvent, à ceux de la FNE : les deux se lisent donc **côte à côte**,
sans lettrage forcé.

### 6.12 Les écrans du module Import

| Écran | Rôle |
|---|---|
| **Dépôt** | choisir le type, la ville (ou le compte), l'exercice ; voir le contrôle préalable ; simuler ; lancer |
| **Informations** | pour chaque format : le fichier attendu, ses colonnes, l'ordre de dépôt |
| **Traitements** | les lots en cours, avec leur progression ; arrêt possible |
| **Journal** | tous les lots : qui, quand, quoi, résultats ; télécharger le fichier d'origine ; annuler |
| **Corrections** | les lignes refusées d'un lot, à corriger puis rejouer |
| **Traçabilité** | ce que chaque lot a créé ou modifié |
| **Codes employés** | l'annuaire des codes de deux lettres : ville, atelier, personne |

Accès : gérant, superviseur de ville, responsable d'atelier, caissier — chacun dans son
périmètre ; les codes employés sont réservés à ceux qui en décident.

---

## 7. Les charges

> Une **charge** est ce que l'exploitation a coûté sur la période. La définition a changé
> plusieurs fois au fil des retours du propriétaire ; celle qui fait foi est celle du
> **20 octobre 2026**, écrite une seule fois dans `ChargesDeLaPeriode` et lue par tous les écrans.
> Le **22 octobre**, la caisse se partage en charges administratives et charges fournisseurs, et
> les autres sources (suivi, FNE, banque) se lisent à côté, sans s'additionner (§ 7.3 bis). Le
> **23 octobre**, les versements et transferts sortent des charges, et les débits de banque se
> lisent par leur libellé. Le propriétaire précise : « les vraies charges devraient être celles du
> suivi fournisseurs ; pour l'instant on garde la caisse » — un changement annoncé, pas fait.

### 7.1 La règle

**« Toutes les sorties de caisse sont des charges »** — **sauf les versements et transferts**
(23/10 : « ce ne sont pas des charges, ce sont des indications ; ils peuvent rester, avec un mot pour
dire qu'ils ne sont pas comptés »). Les fournisseurs, eux, « ne font que retracer : c'est un suivi ».

| Compte dans les charges | Ne compte pas |
|---|---|
| **chaque sortie du journal de caisse** (classeur d'Abidjan ou journal PDF de Bouaké / San-Pédro), qu'elle ait été reprise ou non | **le suivi fournisseurs** (une dette, suivie à côté) |
| **chaque charge saisie** dans l'application, de type « Charges », qui ne vient pas de la caisse | une charge **reprise** de la caisse : sa sortie compte déjà |
| | les **versements et transferts** sortis de la caisse (dépôt en banque, appro, caisse centrale, DG…) — montrés, non comptés (23/10) |
| | les **transferts de trésorerie** vers un autre site et les **décaissements DG** saisis |

Pourquoi cette règle : avec l'ancienne (18/10), les pièces du suivi fournisseurs entraient aussi
dans les charges, et **une facture payée en espèces comptait deux fois** — par sa pièce et par sa
sortie de caisse. Désormais, l'argent compte là où il est réellement sorti.

```
 CHARGES DE LA PÉRIODE  =  Σ sorties du journal de caisse          (importées)
                         + Σ charges saisies non reprises de la caisse

 Suivi fournisseurs ─────► affiché « à côté », n'entre dans aucun total
```

### 7.2 D'où viennent les charges

| Provenance | Comment elle arrive | Remarque |
|---|---|---|
| **Journal de caisse** | import `caisse` (Abidjan) ou `journal-caisse` (PDF, Bouaké / San-Pédro) | l'essentiel des charges en ligne : l'application n'a presque pas de saisie |
| **Saisie dans l'application** | écran **Décaissements** (caissier) : date, type d'opération, nature, montant, moyen, tiers, atelier, activité | type « Charges » = charge ; « Transfert » et « Décaissement DG » = mouvements de trésorerie, pas des charges |
| **Importée (fichier)** | charge portant un numéro de lot | rare |
| *Suivi fournisseurs* | import `fournisseurs`, saisie, ou facture FNE portée | **à côté**, jamais dans le total |

### 7.3 La nature d'une sortie de caisse, lue dans son libellé

Le journal ne dit pas « salaire » ou « pièce » dans une colonne : il écrit un libellé libre
(« ACHAT FILTRE A HUILE », « AVANCE SALAIRE KOUAME », « CARBURANT MISSION BOUAKE »…). La
catégorie est **lue par mots-clés** (`CategorieDeSortie`), dans cet ordre : le **motif** du journal
quand il existe, puis le **libellé**, puis le **bénéficiaire**. La première catégorie qui reconnaît
un mot gagne ; « ACHATS DIVERS », trop générique, cède la place à un libellé plus précis. Ce qui
n'est pas reconnu reste **« Autres »** et se voit — une catégorie inventée cacherait ce que la
règle ne sait pas lire.

| Catégorie lue | Exemples de mots | Carte de la page Charges |
|---|---|---|
| Achats pièces | pièce, filtre, pneu, huile, plaquette, batterie, peinture, vernis… | **Achats pièces** |
| Salaires & personnel | salaire, avance, acompte, prime, CNPS, stagiaire… | **Salaires & personnel** |
| Fonctionnement | électricité (CIE), eau (SODECI), internet, téléphone, loyer, fournitures, gardiennage… | **Fonctionnement** |
| Restauration & réception | repas, restaurant, boisson… | Autres décaissements |
| Carburant & transport | carburant, gasoil, taxi, péage, mission, livraison… | Autres décaissements |
| Main-d'œuvre & sous-traitance | tôlier, peintre, prestataire, dépannage, soudure… | Autres décaissements |
| Taxes & frais administratifs | taxe, impôt, timbre, vignette, frais bancaires, visite technique… | Autres décaissements |
| Règlements fournisseurs | règlement, fournisseur, facture… | Autres décaissements |
| Achats divers | achat, divers | Autres décaissements |
| Versements & transferts | versement, dépôt banque, transfert, appro, retrait, caisse centrale, DG… | **aucune — non comptés dans les charges** (23/10) |
| Autres | rien de reconnu | Autres décaissements |

Les quatre cartes (« natures ») sont : **Achats pièces**, **Salaires & personnel**,
**Fonctionnement**, **Autres décaissements**. Le détail par catégorie s'ouvre en cliquant la carte
de la caisse.

### 7.3 bis Charges administratives, charges fournisseurs, et les quatre sources (22/10)

La demande du propriétaire : « on a la caisse, le suivi des fournisseurs, la FNE et la banque ».

**La caisse se partage en deux par son libellé** (`CategorieDeSortie::volet`) :

| Volet | Catégories |
|---|---|
| **Charges fournisseurs** — tout ce qui remonte aux travaux | Achats pièces · Main-d'œuvre & sous-traitance (dont travaux, mécanique, sinistre, carrosserie) · Règlements fournisseurs · Achats divers |
| **Charges administratives** — ce qui ne concerne pas un fournisseur | salaires, transport, fonctionnement, taxes, restauration, et ce que la règle ne reconnaît pas |
| *Versements & transferts* | montrés à part sous la carte de la caisse, **non comptés** (23/10) |

**Les quatre sources se lisent côte à côte** (`ChargesParSource`), sur la période et le filtre :

| Source | Ce qui est lu | Dans le total des charges ? |
|---|---|---|
| Caisse | chaque sortie du journal (dont administratif / fournisseurs) | **oui** |
| Saisies | charges saisies non reprises de la caisse | **oui** |
| Banque | débits des relevés **dont le libellé dit une charge ou un fournisseur** (23/10) ; les autres (chèque sans objet, transfert…) montrés à part, non retenus | à côté |
| FNE | toutes les factures d'achat relevées au portail, TTC | à côté |
| Suivi fournisseurs | les pièces facturées, importées telles quelles (années, centimes) | à côté |

**Il n'y a pas encore de lettrage** entre ces sources : une même dépense payée en espèces peut être
au journal de caisse, au suivi et à la FNE. Les additionner la compterait trois fois ; elles sont
donc montrées, jamais additionnées, jusqu'au jour où un indicateur commun permettra de retirer les
doublons.

### 7.4 « Reprendre » une sortie de caisse

**Retiré de l'écran le 23/10** (« elle ne fait pas grand-chose : toutes les sorties comptent déjà ;
on la réformera ») — le code reste pour la refonte. Ce qu'elle faisait : ceux qui tiennent la caisse
(gérant, superviseur de ville, caissier) pouvaient **reprendre** une sortie, ligne à ligne : elle devient une charge **classée** — libellé d'opération choisi, atelier
précisé — liée à sa sortie (`charges.mouvement_caisse_id`). Depuis le 20/10 la reprise **ne fait
plus entrer** la sortie dans les charges (elle y est d'office) : elle la **classe** mieux, sans
jamais la compter deux fois. Rien ne se reprend tout seul.

### 7.5 Périmètre et activité

- **Lieu** : comme la caisse — l'atelier s'il est connu, sinon la ville, sinon la ligne paraît
  partout. Abidjan a une **caisse centralisée** pour ses deux ateliers.
- **Activité** (Mécanique / Sinistre) : le journal de caisse ne la dit pas. Sous un filtre
  d'activité, les sorties de caisse sont donc écartées ; sans filtre, elles vont à « Autres » de
  la ventilation par activité. Les charges saisies, elles, portent leur activité.

### 7.6 La page Charges

| Bloc | Ce qu'il montre |
|---|---|
| **D'où viennent les charges** (exercice) | **Charges selon la caisse** (dont charges administratives / charges fournisseurs) · Saisies dans l'application · *Suivi fournisseurs*, *Factures FNE*, *Banque — débits* (à côté) · Sorties de caisse par catégorie (cliquable) |
| **Total charges** du périmètre | décaissements de caisse + charges saisies, hors versements, transferts et décaissements DG |
| **Cartes par nature** | Achats pièces · Salaires & personnel · Fonctionnement · Autres décaissements |
| **Résultat net** | CA facturé − charges (vert si positif, rouge sinon) ; mécanique et sinistre portent un **« + »** (ils s'additionnent) |
| **« Autres »** (23/10) | sur chaque carte ventilée, une ligne dit ce que « Autres » recouvre (le plus souvent : les décaissements de caisse, dont le journal ne dit pas l'activité), et **« ⋯ »** mène aux lignes |
| **Bouton « + Ajouter une pièce »** | le formulaire des charges fournisseurs, ouvert |
| **Graphique** | charges par nature dans le temps |
| **Tableau de toutes les charges** | saisies + décaissements de caisse, paginé en base ; filtre **provenance** (23/10) : *Venant de la FNE* · *Décaissement de caisse (journal de caisse)* · *Saisie dans l'application* · *Suivi fournisseur initial (importé)* · *Suivi fournisseur* (toutes les lignes du suivi, importées et saisies) — les trois du suivi « à côté, non additionné » ; recherche libre ; colonne **Provenance** |
| **Sorties de caisse par catégorie** | la part de chaque catégorie, avec deux décimales sous 0,1 % (« 0 % » pour 115 000 F n'était pas possible) ; les versements marqués « non compté » |

Filtres communs de pilotage : période, ville, atelier, activité.

### 7.7 Où les charges sont lues

La même définition alimente : la **page Charges**, le **résultat net** du chiffre d'affaires,
l'**accueil du gérant** (avec, depuis le 22/10, la carte **« Charges — synthèse par source »** : les
charges retenues en grand, puis caisse — dont administratif / fournisseurs —, saisies, banque, FNE,
suivi) et la **synthèse par atelier** (charges par atelier, sinon par ville,
sinon « lieu non précisé »). *Reste à aligner* : le tableau de bord de la comptabilité lit encore
la seule table des charges saisies.

### 7.8 Les fournisseurs, à côté des charges

La page s'appelle **Charges fournisseurs** depuis le 23/10 ; elle n'est plus au menu et s'ouvre depuis
la page Charges. C'est **un suivi de la dette**, pas une source de charges :

- **Ajouter une pièce** (23/10) : deux voies — **choisir la facture FNE** dans une liste qu'on fouille
  (ses informations se reportent comme par « Porter ») ou **« Saisir une pièce »**.
- **Décaissements fournisseurs (caisse)** (23/10) : les sorties de caisse que leur libellé range en
  charges fournisseurs. Au bout de chaque ligne, **« Suivre »** ouvre le formulaire prérempli ; la
  pièce créée est **réglée par cette sortie** et les deux lignes sont **lettrées**, cliquables dans
  les deux sens (la pièce dit « payé par la caisse ›», la sortie dit sa pièce). Une sortie suivie ne
  se suit pas deux fois. Les **notifications** (à chaque décaissement fournisseur, à chaque facture
  FNE relevée) attendent le rôle « gestionnaire des fournisseurs », annoncé par le propriétaire.
- **Grand livre fournisseur** (23/10) : l'extrait de compte d'un fournisseur, trouvé par son nom —
  report, factures au crédit, règlements au débit, solde ligne à ligne ; la balance mène à lui.
- **Liste des fournisseurs** (23/10) : les émetteurs FNE y paraissent d'office (« connus — FNE ») ;
  un nom qui ne vient que du suivi est « à vérifier », avec l'émetteur FNE le plus proche proposé,
  et un bouton **Accepter**.
- **Tableau initial** : San-Pédro dans le fichier d'Abidjan (et l'inverse) n'est pas une erreur —
  commandé pour une ville, reçu par l'autre ; la ligne dit « commandée pour … · reçue par … ».
- Le bouton « Relancer le rapprochement » de la page FNE est retiré : les numéros du suivi ne
  collent pas à ceux de la FNE.

- **Un seul tableau, deux boutons** (22/10) : « Factures reçues » montre **toutes** les factures —
  les pièces du suivi **et** les factures FNE pas encore portées (filtre de provenance) — ; « À qui
  nous devons le plus » montre les dix plus grosses dettes. Une FNE hors suivi ne dit ni règlement
  ni ville : elle ne paraît que sous l'état « Toutes ».
- **Saisie d'une pièce** : l'essentiel en tête, les précisions du classeur repliées dessous (sans
  mois, code pièce ni résultat indicatif, retirés le 22/10).
- **Suivi des pièces** : ce que l'entreprise doit, par fournisseur, facturé / réglé / reste,
  échu (par l'échéance ou, à défaut, la date de facture) ; une facture reçue par une autre ville
  est rangée à la ville qui a commandé, avec la mention « reçue par ».
- **Factures FNE** relevées au portail, rapprochées, « Portées » au suivi (voir 6.11), KPI des
  normalisés (FNE reçues, au suivi, à porter, suivi normalisé).
- **Régler** une pièce fait le **lettrage** ; le règlement peut pointer la **sortie de caisse qui a
  payé** — la page dit alors « dont par la caisse ». C'est le pont entre les deux mondes : la
  sortie est la charge, la pièce dit à quoi elle correspondait.
- **Balance** en deux onglets : **importée** (logiciel d'atelier) et **produite par le système**
  (par fournisseur, facturé / réglé / reste, ancienneté par tranches, écart avec l'importée).

### 7.9 Points ouverts sur les charges

1. ~~Versements en banque et transferts~~ — **tranché le 23/10** : ils ne comptent pas.
2. **Dates mal lues** dans le classeur de caisse (une ligne au `31/12/1899`, zéro d'Excel) : sans
   effet sur le solde, mais la ligne tombe hors de la bonne période.
3. **Comptabilité › tableau de bord** à passer sur la définition commune.
4. **Le partage administratif / fournisseurs** (22/10) : « Achats divers » est compté en
   fournisseurs, le non-reconnu et les versements en administratif — choix à confirmer.
5. **Lettrage** entre caisse, suivi, FNE et banque : à faire quand un indicateur commun existera.
6. Un **écart de caisse** en ligne (2 603 mouvements affichés pour 2 070 dans le classeur de la
   période) reste à expliquer : deux classeurs qui se recouvrent, ou un dépôt lu deux fois.

---

## 8. Caisse, banques, trésorerie

- **Caisse** : le journal importé, feuillet par feuillet, solde recalculé dans l'ordre du fichier,
  colonne D/C par ligne ; saisie possible ; bloc « les classeurs de cette période » pour repérer
  des recouvrements.
- **Banques** : comptes déclarés (BGFI, AFG), relevés importés par compte, règlements face au
  relevé, libellés non rangés à **affecter** à une banque.
- **Trésorerie** (`TresorerieReelle`) — ce qui est réellement entré et sorti :

| Source | Compte | Pourquoi |
|---|---|---|
| journal de caisse (importé) | oui, entrées et sorties | la caisse telle qu'elle a bougé |
| relevés bancaires (importés) | oui, crédits et débits | la banque telle qu'elle a bougé |
| règlements **saisis** | oui | le logiciel d'atelier ne les connaît pas |
| charges **saisies** non reprises | oui | idem |
| règlements **importés** (état des impayés) | non, à côté | le même argent est déjà au journal ou au relevé |
| charge reprise de la caisse | non | sa sortie compte déjà |

  Tableau de bord par support (caisse / banque / mobile / non précisé), KPI par source,
  rapprochements **CA ↔ banque** et **CA ↔ caisse**.

---

## 9. Chiffre d'affaires, impayés, recouvrement

- **Chiffre d'affaires** : les factures du CATTC (ou saisies), avoirs déduits ; l'atelier est lu
  par la fiche puis par le devis ; KPI « CA selon les impayés » à côté. Une créance de l'état non
  retrouvée au CATTC n'est **pas** une vente.
- **État des impayés** : créances par client, assureur, courtier ; ancienneté recalculée à chaque
  lecture ; règlements ; avoirs — **qui se saisissent aussi à l'écran, en négatif** (23/10) ;
  extrait de compte exportable.
- **Le tiers payant** : qui doit vraiment l'argent — le **courtier** s'il y en a un, sinon
  l'**assurance**, sinon le **client**. Calculé à un seul endroit (`Facture::tiersPayant()`).
- **Recouvrement** : relances à niveaux (N1 à N5 ; N3 agent, N4 superviseur, N5 gérant =
  contentieux), revérifiés côté serveur ; balance âgée ; page Courtiers (« dont 36 % au titre
  d'AXA ») ; journal des encaissements ; piste d'audit. Un encaissement saisi ici entre aussitôt
  en trésorerie.

---

## 10. Le commercial : prospections, correspondances, objectifs

> 23/10 : même quand l'objectif n'est pas atteint, chaque écran dit **ce qui a été fait** et le
> **pourcentage réalisé** (page Commerciaux, y compris « hors tranche » du barème ; objectifs du mois
> sur l'accueil du gérant ; Ma performance).

- **Prospections** saisies par les commerciaux et les responsables ; affichage général pour tous
  (« déjà prospecté ? ») ; la prospection récupère seule son devis.
- **Correspondances** : chaque commercial **coche les factures nées de ses prospections** ; un
  responsable peut cocher pour le compte d'un commercial et seul un responsable annule. Cocher
  attribue la facture (chiffre et commission).
- **Objectifs** : objectifs mensuels de l'entreprise par tranches et période ; objectifs par
  employé concerné ; barème de commission annuel ; « Ma performance ».
- **Taux de transformation** des devis : devis validés **ou facturés** (un devis importé est
  toujours « En attente »).

---

## 11. L'architecture technique

| Couche | Choix |
|---|---|
| Langage / framework | PHP, **Laravel 13** |
| Interface | **Livewire 4 + Volt** (composants mono-fichier), Blade, Vite ; filtres instantanés ; aucun dialogue navigateur ; le chemin critique (dépôt d'import) marche sans JavaScript |
| Modules | `nwidart/laravel-modules` — 9 modules |
| Authentification | Fortify, connexion Google (OAuth) |
| Droits | `spatie/laravel-permission` **par équipe** (= entreprise) |
| Journal | `spatie/laravel-activitylog` + traçabilité des imports et des corrections |
| Lecture de fichiers | lecteurs maison `.xlsx`, `.xls`, PDF (journal de caisse) |
| Base | **MySQL** en production ; SQLite en mémoire pour les tests |
| Tâches | file d'attente + processus détaché pour les imports ; planificateur (`schedule:run`) pour le relevé FNE |
| Robot FNE | Node.js + Chromium (Playwright), dossier `SCRAPER-PORTAIL-FNE/` |
| Notifications | messagerie interne, notifications web poussées (VAPID) |
| Déploiement | `git pull` puis `php artisan app:deployer` (migrations, caches) ; serveur de développement d'abord |

### Les modules

```
Modules/
├── Noyau/           le socle : modèles, TOUTES les migrations, services métier, écrans communs
├── Import/          l'alimentation par fichiers
├── SuperAdmin/      la plateforme
├── Gerant/          la direction
├── Superviseur/     le pilotage d'une ville (CA, charges, trésorerie, caisse, fournisseurs, impayés…)
├── ResponsableSite/ la saisie du jour d'un atelier
├── Comptabilite/    la caisse : encaissements, décaissements
├── Commercial/      le terrain
└── Recouvrement/    la poursuite des créances
```

**Règle de dépendance** : un module de rôle peut appeler le Noyau, jamais un autre module de rôle ;
le Noyau ne dépend d'aucun d'eux. Ce qui est partagé appartient au Noyau.

### Les services métier qui « disent la vérité »

| Question | Service unique |
|---|---|
| Qu'est-ce qu'une charge ? | `ChargesDeLaPeriode` |
| Les charges source par source (caisse, saisies, banque, FNE, suivi) | `ChargesParSource` |
| Qui ouvre quelle page (rôles configurés) ? | `CatalogueDesPages` |
| Quelle catégorie pour une sortie de caisse ? | `CategorieDeSortie` |
| Qu'est-ce qui est entré et sorti ? | `TresorerieReelle` |
| Qu'est-ce que le chiffre d'affaires ? | `Facture::scopeDuChiffreDAffaires()` |
| Qui doit l'argent ? | `Facture::tiersPayant()` |
| Quelle nature pour un montant négatif ? | `NatureDeLaCreance` |
| Qui voit quelle ligne ? | `PerimetreSites`, `PerimetreDeTresorerie`, `EtatDesImpayes::dansLePerimetre()` |
| Le solde de caisse | `ChaineDeSolde` |
| Où va une ligne importée ? | `Rattachement` |
| Balance fournisseurs produite | `BalanceDesFournisseurs` |

### Commandes utiles

`app:deployer` (déploiement) · `app:diagnostic` (vitesse, imports, erreurs) ·
`import:traiter-lot` · `factures:doublons` (lecture seule) · `factures:situer` ·
`banques:declarer` · `codes:rattacher` · `fne:actualiser` / `fne:releve-achats`.
Toute commande qui écrit des données s'exécute **d'abord en constat**.

---

## 12. Les règles de conception qui ne se discutent pas

1. **La base en ligne porte des données réelles** : migrations additives seulement (tables
   neuves, colonnes nullables) ; jamais de purge ni de remise à zéro sur un serveur.
2. **Mesurer avant d'agir** : tout chiffre avancé vient d'une mesure.
3. **Ne jamais arrondir un montant** : colonnes décimales, centimes gardés de bout en bout.
4. **Ne jamais deviner** : une ligne douteuse est mise de côté avec son motif, pas corrigée en
   silence ; un doublon visible vaut mieux qu'une fusion fausse.
5. **Une seule définition par chiffre**, dans le Noyau.
6. **Sécurité** : autorisation à la route et dans l'action ; périmètre lu sur l'identité ; une
   valeur venue du navigateur ne commande rien d'irréversible sans être relue en base ; aucune
   donnée sensible dans les pages d'erreur.
7. **Tout ce qui déplace de l'argent est tracé** (imports, corrections, codes, reprises).
8. **Jamais de déploiement par zip** : `git pull` + `app:deployer`.
9. Code, écrans et commentaires **en français** ; les commentaires disent **pourquoi**.

---

## 13. Où en est le projet

**Fait** : les onze chantiers du plan initial ; les modules Import, Recouvrement, Commercial,
Caisse, Banques, Trésorerie, Charges et **Fournisseurs (terminé le 20/10)** ; relevé FNE
automatique en service sur le serveur de développement (≈ 2 700 pièces relevées) ; doublons
d'import ; montants au centime ; objectifs ; correspondances ; **charges par source**, rôle
**Trésorerie** et **rôles configurables** (22/10).

**Reste, côté code** :

- aligner l'« Encaissé » et la « Trésorerie nette » de l'accueil du gérant sur `TresorerieReelle` ;
- passer le tableau de bord de la comptabilité sur `ChargesDeLaPeriode` ;
- dates mal lues du classeur de caisse ;
- affichage des avoirs dans l'état des impayés (après le prochain dépôt) ;
- vitesse : agrégats restants de l'accueil du gérant ;
- lettrage entre les sources de charges (caisse, suivi, FNE, banque) — commencé le 23/10 pour la
  caisse et le suivi (« Suivre ») ;
- notifications au futur gestionnaire des fournisseurs (décaissements, factures FNE) ;
- faire du suivi fournisseurs « les vraies charges », comme le propriétaire l'annonce.

**Reste, côté exploitation (propriétaire)** : passer les dernières migrations en ligne ; redéposer
les fichiers d'origine pour retrouver les centimes ; trancher le cas des versements en banque dans
les charges ; régler l'hébergement (OPcache, cache fichier) ; rotation des secrets.

Le détail vivant est dans `REPRENDRE-ICI.md` ; l'historique de chaque décision dans
`ETAT-DES-LIEUX.md`.

---

## 14. Glossaire

| Terme | Sens |
|---|---|
| **Atelier / site** | lieu de travail ; Abidjan en a deux |
| **Avoir** | facture négative qui annule tout ou partie d'une facture |
| **Balance âgée** | créances rangées par ancienneté |
| **CATTC** | export « chiffre d'affaires TTC » du logiciel d'atelier |
| **Charge** | sortie de caisse ou charge saisie (voir § 7) |
| **Code employé** | deux lettres dans le numéro de fiche (`FR-KZN°…`) qui désignent son rédacteur, donc son atelier |
| **Correspondance** | lien entre une facture et le commercial qui l'a apportée |
| **Courtier** | intermédiaire d'assurance, souvent le vrai débiteur |
| **Exercice** | année comptable |
| **Feuillet** | onglet d'un classeur ; le solde de caisse ne le traverse pas |
| **Fiche de réception** | ouverture d'un dossier véhicule à l'atelier |
| **FNE** | facture normalisée électronique (administration fiscale ivoirienne) |
| **Lettrage** | association d'un règlement à la facture qu'il solde |
| **Lot** | un dépôt de fichier et tout ce qu'il a produit |
| **Périmètre** | ce qu'un utilisateur a le droit de voir (entreprise, ville, atelier) |
| **Porter** | inscrire une facture FNE au suivi fournisseurs |
| **Proforma** | devis |
| **Reprendre** | classer une sortie de caisse comme charge (libellé, atelier) |
| **Simulation** | import complet puis annulé, pour voir sans écrire |
| **Suivi fournisseurs** | ce que l'entreprise doit à ses fournisseurs — à côté des charges |
| **Tiers payant** | celui qui paie : courtier, sinon assurance, sinon client |
| **Trop-perçu** | règlement supérieur à la facture |
