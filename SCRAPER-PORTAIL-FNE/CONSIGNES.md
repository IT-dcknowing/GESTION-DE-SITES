# Le relevé des achats FNE — consignes

Repris de Selflow le 12/10/2026 (`achats.js` et `fne.js`, inchangés), en attendant les accès
au portail. Il relève **toutes les pièces émises par un tiers** à l'entreprise sur la
plateforme FNE — factures et **avoirs** (`/ws/invoices?listing=received`) — et dépose un
constat JSON. Il n'écrit rien dans la base.

## Installer, une fois, sur un poste qui a Node 18+

    cd SCRAPER-PORTAIL-FNE
    npm install                       # Playwright et Chromium
    copy .env.exemple .env            # FNE_URL = page de connexion du portail
    copy identifiants.exemple.json identifiants.json   # NCC → mot de passe

## Relever

    node achats.js <NCC> <motDePasse>            # un compte
    node achats.js --tous                        # tous ceux de identifiants.json
    node achats.js --reconnaissance <NCC> <mdp>  # explore le portail et rapporte, sans déposer

**Toujours donner un login ou `--tous`.** Sans argument, le script de Selflow interroge une file
de demandes (`portail-fne:demandes`) qui n'existe pas ici, et s'arrête en le disant.

Le fichier tombe dans `storage/app/portail-fne/achats/<NCC>_<AAAAMMJJ>.json`.

## Faire entrer le relevé dans l'application

- à l'écran **Fournisseurs › Factures FNE**, bouton « Lire le relevé » (gérant, responsable
  de ville, caissier) ;
- ou sur le serveur : `php artisan fne:releve-achats` (tous les fichiers du dossier) ou
  `php artisan fne:releve-achats chemin/du/fichier.json --entreprise=1`.

Un relevé déjà lu (même empreinte) n'entre pas deux fois. Chaque facture va **dans son
exercice** (l'année de sa date) ; un avoir porte des montants négatifs. La clé d'API que le
portail joint à la fiche de l'émetteur n'est **pas** conservée.

## Ensuite, à l'écran

- les factures que le suivi fournisseurs confirme sont **rapprochées** d'elles-mêmes — par le
  numéro, ou à défaut fournisseur + date + montant TTC, et seulement s'il n'y a qu'une ligne
  possible ; le classeur peut venir avant ou après, « Relancer le rapprochement » refait le lien ;
- les autres attendent : « **Porter au suivi** » crée la dette, comme à l'état des impayés ;
- chaque paiement se saisit par « **Régler** » sur la pièce, qui la **lettre**.
