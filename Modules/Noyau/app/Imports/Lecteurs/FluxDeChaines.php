<?php

namespace Modules\Noyau\Imports\Lecteurs;

/**
 * Le dictionnaire de chaînes d'un `.xls`, lu bloc par bloc.
 *
 * Cette classe n'existe que pour une raison, et elle mérite d'être dite clairement : dans
 * un classeur `.xls`, les textes sont rangés dans un enregistrement SST qui déborde sur
 * autant d'enregistrements CONTINUE qu'il faut. **Une chaîne peut être coupée en plein
 * milieu par cette frontière**, et le morceau qui suit recommence par son propre octet de
 * drapeau — lequel peut basculer d'un encodage à l'autre au passage.
 *
 * La conséquence est brutale : recoller les blocs bout à bout avant de lire décale tout ce
 * qui suit la première coupure. Sur le parc d'Abidjan, cette erreur faisait lire 45 fiches
 * sur 2 204 — et sans lever d'exception, ce qui est pire.
 *
 * D'où ce flux, qui garde les blocs séparés et sait ce qu'une frontière veut dire selon
 * l'endroit où elle tombe : au milieu des caractères, un nouveau drapeau ; au milieu de la
 * mise en forme, rien du tout.
 */
class FluxDeChaines
{
    private int $bloc = 0;

    private int $position = 0;

    /** @param  list<string>  $blocs */
    public function __construct(private array $blocs) {}

    public function epuise(): bool
    {
        $this->cadrer();

        return $this->bloc >= count($this->blocs);
    }

    /** La chaîne suivante du dictionnaire. */
    public function chaine(): string
    {
        $longueur = $this->entier(2);
        $drapeau = $this->entier(1);

        $seize = ($drapeau & 1) !== 0;
        $morceaux = ($drapeau & 8) !== 0 ? $this->entier(2) : 0;
        $extension = ($drapeau & 4) !== 0 ? $this->entier(4) : 0;

        $texte = '';
        $restant = $longueur;

        // Volontairement sans `epuise()` : cette méthode recadre sur le bloc suivant, ce
        // qui ferait sauter l'octet de drapeau et casserait précisément ce qu'on protège.
        while ($restant > 0 && $this->bloc < count($this->blocs)) {
            $disponibles = intdiv($this->resteDuBloc(), $seize ? 2 : 1);

            if ($disponibles === 0) {
                // Le bloc s'arrête au milieu de la chaîne : le suivant s'ouvre sur un
                // drapeau neuf, qui redit l'encodage du reste. C'est tout le sujet.
                $this->bloc++;
                $this->position = 0;

                if ($this->bloc >= count($this->blocs)) {
                    break;
                }

                $seize = ($this->entier(1) & 1) !== 0;

                continue;
            }

            $combien = min($restant, $disponibles);
            $brut = $this->octets($combien * ($seize ? 2 : 1));

            $texte .= (string) mb_convert_encoding($brut, 'UTF-8', $seize ? 'UTF-16LE' : 'Windows-1252');
            $restant -= $combien;
        }

        // La mise en forme et les annotations phonétiques suivent les caractères. Elles
        // peuvent elles aussi traverser une frontière, mais sans octet de drapeau : on les
        // saute sans rien réinterpréter.
        $this->sauter($morceaux * 4 + $extension);

        return $texte;
    }

    private function entier(int $octets): int
    {
        $brut = $this->octets($octets);

        if (strlen($brut) < $octets) {
            return 0;
        }

        return match ($octets) {
            1 => ord($brut),
            2 => unpack('v', $brut)[1],
            default => unpack('V', $brut)[1],
        };
    }

    private function octets(int $combien): string
    {
        $this->cadrer();

        if ($this->bloc >= count($this->blocs)) {
            return '';
        }

        $brut = substr($this->blocs[$this->bloc], $this->position, $combien);
        $this->position += strlen($brut);

        return $brut;
    }

    private function sauter(int $combien): void
    {
        while ($combien > 0) {
            $this->cadrer();

            if ($this->bloc >= count($this->blocs)) {
                return;
            }

            $pas = min($combien, $this->resteDuBloc());

            if ($pas === 0) {
                $this->bloc++;
                $this->position = 0;

                continue;
            }

            $this->position += $pas;
            $combien -= $pas;
        }
    }

    private function resteDuBloc(): int
    {
        if ($this->bloc >= count($this->blocs)) {
            return 0;
        }

        return strlen($this->blocs[$this->bloc]) - $this->position;
    }

    /** Avance jusqu'au premier bloc qui a encore quelque chose à donner. */
    private function cadrer(): void
    {
        while ($this->bloc < count($this->blocs) && $this->position >= strlen($this->blocs[$this->bloc])) {
            $this->bloc++;
            $this->position = 0;
        }
    }
}
