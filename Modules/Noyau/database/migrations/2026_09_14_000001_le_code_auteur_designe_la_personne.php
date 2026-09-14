<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Commun\Services\CodeAuteur;

/**
 * Le code auteur cesse de compter les saisies pour désigner la personne.
 *
 * **Ce qui n'allait pas.** Le dernier bloc du code — « A-C-KY-0007 » — comptait le rang du
 * document dans le travail de son auteur : la septième prospection de Koffi Yao. Une même
 * personne portait donc un numéro différent sur chaque ligne qu'elle saisissait, et deux
 * numéros différents le même jour sur deux écrans. Ce n'est pas un identifiant, c'est un
 * compteur : un identifiant qui change n'identifie plus rien, et ne peut ni se retenir, ni
 * se dicter au téléphone, ni s'écrire sur une fiche papier.
 *
 * **Ce qui le remplace.** Un rang attribué une fois pour toutes à la personne, à la
 * création de son accès, unique dans son entreprise et jamais réutilisé. Koffi Yao est
 * « A-C-KY-0007 » sur tout ce qu'il saisit, aujourd'hui et dans dix ans.
 *
 * Le rang du document dans l'entreprise, lui, n'est pas perdu : c'est le numéro de la pièce
 * — « P-0565 » — qui l'a toujours porté. Les deux blocs disaient la même chose de deux
 * façons ; il en reste un de chaque.
 *
 * **Ce que cette migration touche en base.** Une colonne ajoutée, et les codes auteur
 * recalculés sur les lignes dont on connaît l'auteur — trente et une lignes en tout
 * aujourd'hui. Aucun montant, aucune date, aucun rattachement : le code auteur est un
 * libellé déduit de `cree_par`, et il se refait exactement de la même source. Les lignes
 * venues d'un import, qui n'ont pas d'auteur, ne sont pas touchées ; celles dont le compte
 * a été supprimé gardent le code écrit de son vivant, qui est la seule trace qu'il en reste.
 */
return new class extends Migration
{
    /** Les tables qui portent un code auteur. */
    private const SERIES = ['prospections', 'devis', 'factures', 'encaissements', 'charges'];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'rang_auteur')) {
            Schema::table('users', function (Blueprint $table) {
                // Nullable : un compte de service, ou un compte créé par une commande, peut
                // ne jamais rien saisir. Le rang se pose alors à la première saisie plutôt
                // que de bloquer la création.
                $table->unsignedInteger('rang_auteur')->nullable()->after('entreprise_id');
                $table->index(['entreprise_id', 'rang_auteur'], 'users_rang_auteur_index');
            });
        }

        $this->attribuerLesRangs();
        $this->reecrireLesCodes();
    }

    /**
     * Chaque compte reçoit son rang, par entreprise, dans l'ordre où les comptes ont été
     * créés. L'ancienneté est le seul ordre qui ne demande l'avis de personne.
     *
     * Les comptes de la plateforme n'appartiennent à aucune entreprise : ils forment leur
     * propre série, ce qui est cohérent avec leur lettre de ville — celle de l'entreprise
     * qu'ils assistent, ou un point quand il n'y en a pas.
     */
    private function attribuerLesRangs(): void
    {
        $parEntreprise = [];

        DB::table('users')->orderBy('id')->select('id', 'entreprise_id', 'rang_auteur')->get()
            ->each(function ($compte) use (&$parEntreprise) {
                if ($compte->rang_auteur !== null) {
                    $cle = (string) ($compte->entreprise_id ?? 0);
                    $parEntreprise[$cle] = max($parEntreprise[$cle] ?? 0, (int) $compte->rang_auteur);

                    return;
                }

                $cle = (string) ($compte->entreprise_id ?? 0);
                $parEntreprise[$cle] = ($parEntreprise[$cle] ?? 0) + 1;

                DB::table('users')->where('id', $compte->id)
                    ->update(['rang_auteur' => $parEntreprise[$cle]]);
            });
    }

    /**
     * Les codes de l'historique sont refaits depuis `cree_par`, la source dont ils ont
     * toujours été tirés. Ce qui change, c'est le dernier bloc : le rang du document
     * devient le rang de la personne.
     */
    private function reecrireLesCodes(): void
    {
        $auteurs = User::withoutGlobalScopes()->with(['ville', 'site.ville', 'entreprise'])->get()->keyBy('id');

        foreach (self::SERIES as $table) {
            if (! Schema::hasColumn($table, 'code_auteur')) {
                continue;
            }

            DB::table($table)->whereNotNull('cree_par')->whereNotNull('code_auteur')
                ->orderBy('id')->select('id', 'cree_par')->get()
                ->each(function ($ligne) use ($table, $auteurs) {
                    $auteur = $auteurs->get($ligne->cree_par);

                    // Compte supprimé depuis : le code écrit de son vivant reste, c'est
                    // tout ce qui désigne encore la personne qui a saisi cette ligne.
                    if (! $auteur || ! $auteur->rang_auteur) {
                        return;
                    }

                    DB::table($table)->where('id', $ligne->id)->update([
                        'code_auteur' => CodeAuteur::composer($auteur, (int) $auteur->rang_auteur),
                    ]);
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'rang_auteur')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_rang_auteur_index');
                $table->dropColumn('rang_auteur');
            });
        }
    }
};
