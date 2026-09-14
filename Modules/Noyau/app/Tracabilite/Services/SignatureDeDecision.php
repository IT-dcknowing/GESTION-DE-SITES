<?php

namespace Modules\Noyau\Tracabilite\Services;

/**
 * Qui décide, quand, d'où, depuis quel poste et sur quel écran.
 *
 * **À quoi cela sert.** Valider une prospection est un acte d'encadrement : cela engage
 * l'atelier à établir le devis annoncé. Refuser en est un aussi, et c'est celui qu'on
 * conteste. Jusqu'ici la base ne gardait que le résultat — « Validée » — sans jamais dire
 * par qui. Le commercial voyait sa ligne passer au vert sans savoir à qui s'adresser, et
 * personne ne pouvait vérifier après coup qui avait engagé quoi.
 *
 * **Pourquoi une classe et non trois lignes recopiées.** Le geste se fait à trois endroits
 * — valider une ligne, en refuser une, tout valider d'un coup. Recopié trois fois, il
 * finit par diverger : c'est ainsi qu'on obtient des lignes validées sans signature, et une
 * trace à trous vaut moins qu'une trace absente, parce qu'on croit pouvoir s'y fier.
 *
 * **Le nom est recopié en clair.** Un accès fermé plus tard ne doit pas rendre anonyme une
 * décision prise sous ce nom-là.
 *
 * **Le « poste » est ce que le navigateur veut bien dire de lui.** On ne peut pas connaître
 * le nom de la machine depuis un serveur web — rien dans une requête HTTP ne le porte. Ce
 * qu'on garde est l'agent utilisateur : le navigateur et le système. C'est beaucoup moins
 * qu'un nom de poste, et c'est pourquoi l'écran l'annonce pour ce qu'il est plutôt que de
 * laisser croire à une identification de machine.
 */
final class SignatureDeDecision
{
    /**
     * Les colonnes à écrire avec la décision.
     *
     * @return array<string, mixed>
     */
    public static function colonnes(): array
    {
        $requete = request();
        $auteur = auth()->user();

        return [
            'valide_par' => $auteur?->id,
            'validateur' => $auteur?->name,
            'valide_le' => now(),
            'validation_ip' => $requete?->ip(),
            'validation_poste' => mb_substr((string) $requete?->userAgent(), 0, 255) ?: null,
            'validation_ecran' => mb_substr((string) $requete?->headers->get('referer', ''), 0, 255) ?: null,
        ];
    }

    /**
     * L'agent utilisateur, ramené à ce qui se lit : le navigateur et le système.
     *
     * Une chaîne d'agent complète fait deux cents caractères de numéros de version dont
     * aucun n'aide à savoir d'où l'on a cliqué. On garde les deux renseignements utiles et
     * l'on tient la chaîne entière à disposition pour qui veut vérifier.
     */
    public static function posteLisible(?string $agent): string
    {
        if ($agent === null || trim($agent) === '') {
            return 'Poste inconnu';
        }

        $navigateur = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };

        $systeme = match (true) {
            str_contains($agent, 'Windows NT 10') => 'Windows 10/11',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'système inconnu',
        };

        return $navigateur.' sur '.$systeme;
    }
}
