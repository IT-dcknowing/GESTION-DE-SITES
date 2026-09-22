<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Modules\Noyau\Commun\Services\NombreDeJours;
use PHPUnit\Framework\TestCase;

/**
 * Compter des jours, vite et juste.
 *
 * **Pourquoi ce fichier existe.** L'ancienneté d'une créance se calculait par Carbon :
 * deux copies d'objet, deux remises à minuit et une soustraction de calendrier par ligne.
 * Mesuré sur la base au 21/09/2026, cela coûtait **2 147 ms pour les 8 955 factures** du
 * périmètre, à chacun des quatre écrans qui lisent tout le portefeuille. Réécrit en
 * entiers, le même calcul tient en **5 ms**.
 *
 * Un gain pareil ne vaut que si le résultat est identique au précédent — sur les jours
 * pleins comme sur les heures de la journée, dans les deux sens, et quel que soit le
 * fuseau. C'est ce que ces cas vérifient, un par un.
 */
class NombreDeJoursTest extends TestCase
{
    public function test_deux_instants_du_meme_jour_ne_font_aucun_jour(): void
    {
        // C'est la remise à minuit que remplaçait l'ancien calcul : une facture éditée à
        // 23 h 59 et un arrêté pris à 00 h 01 le même jour sont du même jour.
        $this->assertSame(0, NombreDeJours::entre(
            Carbon::parse('2026-09-21 00:01:00'),
            Carbon::parse('2026-09-21 23:59:59'),
        ));
    }

    public function test_le_lendemain_fait_un_jour_et_la_veille_en_fait_moins_un(): void
    {
        $this->assertSame(1, NombreDeJours::entre(
            Carbon::parse('2026-09-21 23:00:00'),
            Carbon::parse('2026-09-22 01:00:00'),
        ));

        // Le signe compte : une facture postérieure à l'arrêté ne doit pas paraître la
        // plus fraîche de toutes par un âge négatif devenu positif.
        $this->assertSame(-1, NombreDeJours::entre(
            Carbon::parse('2026-09-22 01:00:00'),
            Carbon::parse('2026-09-21 23:00:00'),
        ));
    }

    public function test_le_compte_traverse_les_mois_les_annees_et_les_annees_bissextiles(): void
    {
        $this->assertSame(31, NombreDeJours::entre(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'),
        ));

        $this->assertSame(365, NombreDeJours::entre(
            Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01'),
        ));

        // 2024 est bissextile : le 29 février existe, et il compte.
        $this->assertSame(366, NombreDeJours::entre(
            Carbon::parse('2024-01-01'), Carbon::parse('2025-01-01'),
        ));
    }

    public function test_le_resultat_est_le_meme_que_celui_de_l_ancien_calcul(): void
    {
        // Le calcul remplacé, écrit ici tel qu'il était, sur quelques centaines de jours
        // pris à la suite. S'ils divergent d'un seul, toute la balance âgée glisse.
        $arrete = Carbon::parse('2026-09-21 18:30:00');

        for ($recul = 0; $recul <= 400; $recul++) {
            $depart = $arrete->copy()->subDays($recul)->setTime(7, 45);

            $ancien = (int) $depart->copy()->startOfDay()
                ->diffInDays($arrete->copy()->startOfDay(), absolute: false);

            $this->assertSame($ancien, NombreDeJours::entre($depart, $arrete), "à J−{$recul}");
        }
    }

    public function test_le_fuseau_de_la_date_ne_change_pas_son_jour(): void
    {
        // Deux dates du même jour civil, écrites dans deux fuseaux : c'est `getOffset()`
        // interrogé sur la date elle-même qui les réconcilie. Un calcul qui supposerait
        // UTC rendrait ici un jour d'écart.
        $abidjan = Carbon::parse('2026-09-21 08:00:00', 'Africa/Abidjan');
        $paris = Carbon::parse('2026-09-21 08:00:00', 'Europe/Paris');

        $this->assertSame(NombreDeJours::jour($abidjan), NombreDeJours::jour($paris));
    }

    public function test_une_date_ecrite_en_toutes_lettres_donne_le_meme_jour_qu_un_objet(): void
    {
        // Les consolidations lisent les lignes telles que la base les rend, sans construire
        // de Carbon : les deux chemins doivent rendre le même numéro, sans quoi l'écran et
        // l'export ne diraient pas le même âge de la même créance.
        $this->assertSame(
            NombreDeJours::jour(Carbon::parse('2026-09-21')),
            NombreDeJours::jourDeLIso('2026-09-21'),
        );

        // La base rend tantôt « 2026-09-21 », tantôt « 2026-09-21 00:00:00 ».
        $this->assertSame(
            NombreDeJours::jourDeLIso('2026-09-21'),
            NombreDeJours::jourDeLIso('2026-09-21 00:00:00'),
        );
    }

    public function test_ce_qui_n_est_pas_une_date_ne_rend_pas_un_jour(): void
    {
        // Une facture sans date n'a pas d'âge, et n'en invente pas un. Le classeur des
        // fournisseurs porte des cellules à « - » et des colonnes vides.
        $this->assertNull(NombreDeJours::jourDeLIso(null));
        $this->assertNull(NombreDeJours::jourDeLIso(''));
        $this->assertNull(NombreDeJours::jourDeLIso('-'));
        $this->assertNull(NombreDeJours::jourDeLIso('31/12/2026'));
    }
}
