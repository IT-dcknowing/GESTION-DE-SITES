<?php

namespace Modules\Noyau\Commun\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;

/**
 * De qui vient ce document — la maison, sa marque, et ce qu'il annonce.
 *
 * **Le défaut auquel cette classe répond.** Les fichiers emportés sortaient nus : un titre,
 * deux lignes grises, un tableau. Remis à un assureur, ils ne disaient pas qui réclame.
 * Un état de créance anonyme se classe mal, se conteste facilement, et ne ressemble pas à
 * la maison qui l'envoie. Il fallait un en-tête, et le même dans les trois formats — sans
 * quoi le PDF, le tableur et le courrier auraient trois identités différentes.
 *
 * **Elle se résout toute seule.** L'entreprise vient de la personne connectée : aucun écran
 * n'a à transmettre son logo à un export, et un écran nouveau hérite de l'en-tête sans que
 * personne y pense. C'est la même règle que l'alignement des montants — ce qui doit être
 * vrai partout ne se déclare pas à chaque appel.
 *
 * Le chemin du logo est rendu **absolu et vérifié** : un fichier supprimé ou déplacé donne
 * un en-tête sans image, jamais un document qui n'existe pas.
 */
final class EnTeteDeDocument
{
    public function __construct(
        public readonly string $maison,
        public readonly ?string $logo,
        public readonly string $titre,
        public readonly string $message,
        public readonly string $mention,
    ) {}

    /** L'en-tête d'un document, déduit de l'entreprise de la personne connectée. */
    public static function pour(string $titre, string $message = '', string $mention = ''): self
    {
        $entreprise = auth()->user()?->entreprise;

        return new self(
            maison: (string) ($entreprise?->nom ?? config('app.name', 'Gestion de sites')),
            logo: self::logo($entreprise),
            titre: $titre,
            message: trim($message),
            mention: $mention !== '' ? $mention : 'Édité le '.now()->format('d/m/Y à H\hi'),
        );
    }

    /** Le chemin absolu du logo sur le disque, ou null s'il n'est pas lisible. */
    private static function logo(?Entreprise $entreprise): ?string
    {
        $chemin = $entreprise?->logo_chemin;

        if (! $chemin) {
            return null;
        }

        // Deux rangements coexistent : les logos livrés avec l'application, sous `public/`,
        // et ceux téléversés, sur le disque public. Le préfixe les distingue.
        $absolu = str_starts_with($chemin, 'public:')
            ? public_path(substr($chemin, 7))
            : Storage::disk('public')->path($chemin);

        return is_file($absolu) ? $absolu : null;
    }

    /** Le logo en source de données, pour les formats qui embarquent l'image dans le texte. */
    public function logoEnLigne(): ?string
    {
        if ($this->logo === null) {
            return null;
        }

        $type = @mime_content_type($this->logo) ?: 'image/png';

        return 'data:'.$type.';base64,'.base64_encode((string) file_get_contents($this->logo));
    }

    /** Le message, découpé en lignes — il en porte souvent deux. */
    public function lignesDuMessage(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $this->message) ?: []),
            fn (string $ligne) => $ligne !== '',
        ));
    }
}
