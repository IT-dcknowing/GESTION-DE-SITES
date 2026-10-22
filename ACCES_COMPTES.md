# Accès de démonstration — L'Artisan Automobile

Jeu installé par `php artisan demo:installer --fraiche`.
**Mot de passe pour tous les comptes : `password`**

## Plateforme

| E-mail | Rôle | Portée |
|---|---|---|
| `superadmin@plateforme.local` | Super Admin | Toutes les entreprises |

## Direction

| E-mail | Rôle | Portée |
|---|---|---|
| `direction@artisan-automobile.ci` | Gérant | Les 4 sites |

## Responsables de site

| E-mail | Site |
|---|---|
| `resp.site1@artisan-automobile.ci` | Abidjan — Site 1 |
| `resp.site2@artisan-automobile.ci` | Abidjan — Site 2 |
| `david.k@artisan-automobile.ci` | Bouaké |
| `rama.gaiho@artisan-automobile.ci` | San Pedro |

## Commerciaux

| E-mail | Site |
|---|---|
| `k-aya@artisan-automobile.ci` | Abidjan — Site 1 |
| `m-koffi@artisan-automobile.ci` | Abidjan — Site 1 |
| `r-nguessan@artisan-automobile.ci` | Abidjan — Site 2 |
| `f-toure@artisan-automobile.ci` | Abidjan — Site 2 |
| `commercial-1-bouake@artisan-automobile.ci` | Bouaké |
| `commercial-2-bouake@artisan-automobile.ci` | Bouaké |
| `y-kouame@artisan-automobile.ci` | San Pedro |
| `a-gnaore@artisan-automobile.ci` | San Pedro |

## Trésorerie et rôles configurés (22/10)

Le jeu de démonstration n'a pas de compte Trésorerie. En créer un depuis le gérant (Général ›
Ajouter un accès › rôle **Trésorerie**, une ville) : il ne voit que Trésorerie, Caisse et Banques.
Les autres rôles se créent dans Super admin › Maintenance › Rôles et pages.

## Code entreprise

`ART-2026CI` — permet au personnel de s'inscrire seul sur `/rejoindre`.

## Installation

```bash
php artisan demo:installer --fraiche   # repart d'une base vide (demande confirmation)
php artisan demo:installer             # applique les migrations, préserve les données existantes
```

La commande est rejouable : relancée sur une base déjà installée, elle ne duplique rien.
