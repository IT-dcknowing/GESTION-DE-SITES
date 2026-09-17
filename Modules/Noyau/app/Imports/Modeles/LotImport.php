<?php

namespace Modules\Noyau\Imports\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Un dépôt de fichier, et ce qu'il est devenu.
 *
 * Chaque import laisse une trace complète : qui, quand, quel fichier, combien de lignes
 * lues, créées, mises à jour, rejetées. Un import qu'on ne peut pas raconter après coup
 * n'est pas un import, c'est un accident — et quand deux chiffres divergeront dans six
 * mois, c'est ce journal qui dira lequel des deux dépôts a introduit l'écart.
 *
 * L'empreinte est unique par entreprise. Ce n'est pas un interdit : un fichier cumulatif
 * se redépose légitimement chaque semaine. C'est un avertissement — l'écran doit pouvoir
 * dire « ce fichier est déjà passé le 20/08, déposé par K. Désirée » avant qu'on relance
 * le traitement.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'user_id', 'deposant',
    'format', 'controle', 'nom_fichier', 'empreinte', 'taille', 'periode',
    'lignes_lues', 'lignes_estimees', 'lignes_creees', 'lignes_majs', 'lignes_ignorees', 'lignes_rejetees',
    'etat', 'message', 'demarre_le', 'termine_le', 'annule_le', 'annule_par',
])]
class LotImport extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'lots_import';

    /**
     * Les états d'un lot, dans l'ordre où ils s'enchaînent.
     *
     * `controle` mérite un mot : c'est la simulation. Le fichier est lu, analysé, chaque
     * ligne classée — et **rien n'est écrit**. C'est l'état dans lequel on répond à la
     * question « qu'est-ce que ça va faire ? » avant de le faire.
     */
    public const ETATS = [
        'depose' => 'Déposé',
        'controle' => 'Contrôlé — rien n\'a été écrit',
        'en_cours' => 'En cours',
        'termine' => 'Terminé',
        'echec' => 'Échec',
        'annule' => 'Annulé',
    ];

    protected function casts(): array
    {
        return [
            'demarre_le' => 'datetime',
            'termine_le' => 'datetime',
            'annule_le' => 'datetime',
            'taille' => 'integer',
            // Déposé « pour vérifier » : la relance, plus tard, doit s'y tenir.
            'controle' => 'boolean',
            'lignes_lues' => 'integer',
            'lignes_estimees' => 'integer',
            'lignes_creees' => 'integer',
            'lignes_majs' => 'integer',
            'lignes_ignorees' => 'integer',
            'lignes_rejetees' => 'integer',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    /** L'atelier déclaré au dépôt, quand l'extraction a été filtrée sur un seul. */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rejets(): HasMany
    {
        return $this->hasMany(LigneRejeteeImport::class, 'lot_import_id');
    }

    public function etatLisible(): string
    {
        return self::ETATS[$this->etat] ?? $this->etat;
    }

    /** Les états d'un lot dont le traitement n'est pas fini : c'est ce qu'on suit, et ce qu'on peut arrêter. */
    public const ETATS_EN_TRAVAIL = ['depose', 'en_cours'];

    public function estEnTravail(): bool
    {
        return in_array($this->etat, self::ETATS_EN_TRAVAIL, true);
    }

    /**
     * Le disque où dorment les fichiers déposés.
     *
     * Le disque `local` a pour racine `storage/app/private`, hors de la portée du serveur
     * web : aucune URL ne mène à ces fichiers. C'est important — un export de l'état des
     * impayés contient les noms, les immatriculations et les montants dus de neuf mille
     * affaires, et il n'a rien à faire derrière une adresse devinable.
     */
    public const DISQUE = 'local';

    /**
     * Où attend un fichier mis de côté par un contrôle préalable.
     *
     * Déclaré ici plutôt que dans le contrôleur qui l'écrit, parce que deux endroits en ont
     * besoin : celui qui range le fichier, et celui qui nettoie après une purge. Un nom
     * recopié dans les deux finirait par diverger, et le nettoyage raterait sa cible sans
     * rien dire.
     */
    public const DOSSIER_ATTENTE = 'controles-import';

    /**
     * Où est rangé le fichier de ce lot.
     *
     * Le chemin est **calculé, jamais stocké**. Un chemin conservé en base est un chemin
     * que quelqu'un peut avoir écrit, et un import qui lit le chemin qu'on lui donne finit
     * par lire `../../.env`. Ici le nom du fichier est son empreinte, qu'on vérifie être
     * bien soixante-quatre caractères hexadécimaux avant de s'en servir : aucune séquence
     * de remontée de répertoire ne peut passer ce filtre.
     *
     * L'extension est neutre parce qu'elle ne sert à rien : le format réel est reconnu à la
     * signature du contenu au moment de l'ouverture.
     */
    public function cheminRelatif(): string
    {
        if (! preg_match('/^[0-9a-f]{64}$/', (string) $this->empreinte)) {
            throw new RuntimeException("L'empreinte de ce lot n'est pas exploitable.");
        }

        return sprintf('imports/%d/%s.bin', (int) $this->entreprise_id, $this->empreinte);
    }

    public function chemin(): string
    {
        return Storage::disk(self::DISQUE)->path($this->cheminRelatif());
    }

    public function fichierPresent(): bool
    {
        return Storage::disk(self::DISQUE)->exists($this->cheminRelatif());
    }

    /**
     * Efface tous les fichiers déposés par une entreprise.
     *
     * **Pourquoi c'est ici et pas dans l'action qui purge.** Le disque, la racine et la
     * forme du chemin sont la connaissance de cette classe ; deux actions ont besoin de
     * nettoyer ces fichiers — la purge des données et la suppression d'une entreprise — et
     * si chacune recopiait le chemin, un jour l'une des deux se tromperait de dossier.
     *
     * **Pourquoi il faut le faire.** Un état des impayés contient les noms, les
     * immatriculations et les montants dus de neuf mille affaires. Effacer l'entreprise en
     * laissant ces fichiers derrière soi, c'est garder les données de quelqu'un qui n'est
     * plus client. Et pour une purge, l'empreinte du fichier interdit de redéposer le même
     * fichier tant qu'elle est connue : garder les fichiers, c'est s'interdire de rejouer
     * exactement le scénario qu'on voulait retester.
     *
     * Le dossier est nommé par le numéro de l'entreprise transtypé en entier : aucune
     * valeur venue d'un formulaire n'entre dans ce chemin.
     *
     * @return int nombre de fichiers effacés
     */
    public static function effacerLesFichiersDe(int $entrepriseId): int
    {
        $nombre = self::nombreDeFichiersDe($entrepriseId);
        $disque = Storage::disk(self::DISQUE);

        foreach (self::dossiersDe($entrepriseId) as $dossier) {
            $disque->deleteDirectory($dossier);
        }

        return $nombre;
    }

    /**
     * Combien de fichiers déposés dorment encore sur le disque.
     *
     * Montré avant une purge : annoncer « les fichiers seront effacés » sans dire combien
     * laisse la personne deviner l'ampleur du geste.
     */
    public static function nombreDeFichiersDe(int $entrepriseId): int
    {
        $disque = Storage::disk(self::DISQUE);
        $nombre = 0;

        foreach (self::dossiersDe($entrepriseId) as $dossier) {
            $nombre += $disque->exists($dossier) ? count($disque->files($dossier)) : 0;
        }

        return $nombre;
    }

    /**
     * Les deux dossiers où dorment les fichiers d'une entreprise, sur le disque privé.
     *
     * Le second mérite une explication. Quand un contrôle préalable soulève un doute — des
     * colonnes qui ne sont pas celles du type annoncé — le fichier est mis de côté en
     * attendant que la personne confirme, plutôt que de lui être redemandé pour une
     * question que nous avons posée. Ces mises de côté ne sont rattachées à aucun lot :
     * sans ce dossier-là, elles survivraient à une purge qui se dit complète.
     *
     * @return array<int, string>
     */
    private static function dossiersDe(int $entrepriseId): array
    {
        return [
            sprintf('imports/%d', $entrepriseId),
            sprintf('%s/%d', self::DOSSIER_ATTENTE, $entrepriseId),
        ];
    }

    /**
     * L'empreinte d'un fichier : deux dépôts identiques donnent la même.
     *
     * SHA-256 sur le contenu, pas sur le nom — puisque justement, le nom ment. Le fichier
     * « San Pédro » et le fichier « Abidjan » peuvent contenir la même chose ; à
     * l'inverse, deux extractions du même jour renommées pareil peuvent différer.
     */
    public static function empreinteDe(string $chemin): string
    {
        return hash_file('sha256', $chemin);
    }
}
