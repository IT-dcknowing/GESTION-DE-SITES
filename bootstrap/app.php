<?php

use App\Http\Middleware\DefinirEquipePermissions;
use App\Http\Middleware\EnregistreLaVisite;
use App\Http\Middleware\ForcerChangementMotDePasse;
use App\Http\Middleware\VerifieHabilitation;
use App\Http\Middleware\VerifierCompteActif;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Hébergement mutualisé (LWS, cPanel, OVH…) : le certificat HTTPS est porté par
         * un serveur frontal qui transmet ensuite la requête à Apache en clair. Sans cette
         * déclaration, Laravel croit être en http, fabrique des liens et des redirections
         * en http, que le frontal renvoie en https — d'où la boucle « cette page vous a
         * redirigé un trop grand nombre de fois ».
         *
         * L'en-tête X-Forwarded-Host est volontairement exclu : c'est celui qui permettrait
         * à un visiteur de forger l'adresse d'un lien de réinitialisation de mot de passe.
         * Le nom de domaine reste donc celui de APP_URL, quoi qu'annonce le frontal.
         *
         * Le « ?: » retombe sur « * » aussi bien quand la variable est absente que
         * lorsqu'elle est présente mais vide : une ligne « PROXYS_DE_CONFIANCE= »
         * oubliée dans un .env ne peut donc pas ramener la boucle de redirection.
         */
        $middleware->trustProxies(
            at: env('PROXYS_DE_CONFIANCE') ?: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        $middleware->appendToGroup('web', [
            DefinirEquipePermissions::class,
            VerifierCompteActif::class,
            ForcerChangementMotDePasse::class,
            // Placé en dernier : son écriture a lieu après l'envoi de la réponse, et il
            // ne doit en aucun cas s'interposer entre l'utilisateur et sa page.
            EnregistreLaVisite::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'habilitation' => VerifieHabilitation::class,
        ]);

        /*
         * Le middleware "guest" natif de Laravel (utilisé par les routes de connexion
         * de Fortify) ignore config('fortify.home') : celui-ci ne joue qu'après la
         * soumission du formulaire. Sans ce réglage, un utilisateur déjà connecté qui
         * rouvre /login dans un second onglet (session partagée) retombe sur le repli
         * par défaut de Laravel — ici la racine "/", qui renvoie elle-même vers
         * /connexion puis /login : boucle infinie (ERR_TOO_MANY_REDIRECTS).
         */
        $middleware->redirectUsersTo(fn () => route('redirection'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Deux onglets du même navigateur partagent un seul cookie de session : se
         * connecter à un second compte dans l'onglet B remplace aussi la session de
         * l'onglet A, resté ouvert sur une page dont le rôle ne correspond plus à qui
         * est réellement connecté. La prochaine action dans cet onglet A (rafraîchir,
         * cliquer un lien) déclenche alors un 403 "User does not have the right roles"
         * — techniquement correct, mais incompréhensible pour l'utilisateur, qui n'a
         * rien fait de mal. On le renvoie plutôt vers l'espace du compte réellement
         * connecté, comme le fait déjà /redirection après une connexion normale.
         */
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, Request $request) {
            if ($request->expectsJson() || ! auth()->check()) {
                return null;
            }

            return redirect()->route('redirection');
        });

        /*
         * Toute panne imprévue s'affiche derrière une page de maintenance.
         *
         * **Ce qu'on ferme.** Laravel affiche, quand APP_DEBUG vaut true, une page de
         * diagnostic superbe et parfaitement indiscrète : trace complète, chemins du
         * serveur, nom de la base, en-têtes de la requête. C'est un outil d'atelier, et
         * il se retrouvait exposé à qui passait sur l'adresse publique.
         *
         * **Pourquoi ce rendu ne consulte ni APP_ENV ni APP_DEBUG.** Ce serait le réflexe
         * — ne reprendre la main qu'en production. Mais un .env se remplace : le nôtre l'a
         * été, et le serveur s'est retrouvé à annoncer APP_ENV=local sur son adresse
         * publique. Une protection qui dépend d'une variable qu'un envoi malheureux peut
         * écraser n'est pas une protection. La page prend donc la main toujours, et c'est
         * le *détail* qui est sous condition, jamais la page elle-même.
         */
        $peutVoirLeDetail = function (): bool {
            try {
                $utilisateur = auth()->user();

                return $utilisateur !== null
                    && ((bool) $utilisateur->est_fondateur || $utilisateur->estSuperAdmin());
            } catch (Throwable) {
                /*
                 * Base ou session injoignables — c'est justement le cas le plus fréquent
                 * quand cette page s'affiche. Dans le doute on ne montre rien : un doute
                 * sur l'identité de celui qui regarde se tranche toujours en sa défaveur.
                 */
                return false;
            }
        };

        $exceptions->render(function (Throwable $e, Request $request) use ($peutVoirLeDetail) {
            /*
             * Les tests doivent voir l'exception nue. La masquer derrière une page de
             * maintenance transformerait chaque régression en « 500 » muet, et la suite
             * cesserait de dire ce qui ne va pas.
             */
            if (app()->runningUnitTests() && ! config('app.rendre_la_page_de_panne_en_test')) {
                return null;
            }

            /*
             * Les erreurs HTTP — 404, 403, 419, 503 — ont déjà leur page dans
             * resources/views/errors, et Laravel les y envoie seul. Elles ne sont pas des
             * pannes : elles disent une adresse inconnue, un droit manquant, une session
             * expirée. Les habiller en incident inquiéterait pour rien.
             */
            /*
             * Trois familles ne sont pas des pannes, et les confondre casserait
             * l'application plus sûrement que la panne qu'on voulait couvrir.
             *
             * Le piège est réel : `renderViaCallbacks()` s'exécute **avant** que Laravel
             * ne traite l'authentification et la validation. Sans cette garde, un visiteur
             * non connecté recevrait une page de panne au lieu d'être renvoyé vers la
             * connexion, et le moindre formulaire refusé annoncerait un incident au lieu
             * de revenir avec ses messages. On ne s'en apercevrait qu'en production.
             */
            $pasUnePanne = $e instanceof HttpExceptionInterface     // 404, 403, 419, 503
                || $e instanceof AuthenticationException            // « connectez-vous »
                || $e instanceof ValidationException                // « ce champ est requis »
                || $e instanceof HttpResponseException;             // une réponse déjà prête

            if ($pasUnePanne || $request->expectsJson()) {
                return null;
            }

            $reference = 'ERR-'.Str::upper(Str::random(6));

            /*
             * La même référence des deux côtés : celle que l'utilisateur lit à l'écran, et
             * celle qu'on retrouve dans le journal. « J'ai eu ERR-4F2A9C » vaut toutes les
             * captures d'écran du monde.
             */
            try {
                Log::error($reference.' — '.$e->getMessage(), [
                    'exception' => $e::class,
                    'origine' => $e->getFile().':'.$e->getLine(),
                    'url' => $request->fullUrl(),
                    'utilisateur' => optional($request->user())->id,
                ]);
            } catch (Throwable) {
                // Journal injoignable lui aussi : la page doit sortir quand même.
            }

            return response()->view('errors.500', [
                'exception' => $e,
                'reference' => $reference,
                'detail' => $peutVoirLeDetail(),
                // Le chemin du projet est retiré et la pile écourtée : les vingt premières
                // lignes disent d'où vient une panne, les cent suivantes sont du vendor.
                'trace' => Str::of($e->getTraceAsString())
                    ->replace(base_path(), '')
                    ->explode("\n")
                    ->take(20)
                    ->implode("\n"),
            ], 500);
        });
    })->create();
