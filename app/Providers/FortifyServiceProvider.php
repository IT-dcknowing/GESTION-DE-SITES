<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.connexion'));

        // Sans ces deux déclarations, Fortify ne sait pas résoudre les vues de
        // réinitialisation et lève une BindingResolutionException sur /mot-de-passe-oublie.
        // Fortify publie `/user/confirm-password` dès que les vues sont activées. Sans
        // celle-ci, l'adresse rendait une erreur 500 pour tous les rôles.
        Fortify::confirmPasswordView(fn () => view('auth.confirmer-mot-de-passe'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.mot-de-passe-oublie'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reinitialiser-mot-de-passe', ['request' => $request]));

        Fortify::authenticateUsing(function (Request $request) {
            $utilisateur = User::where('email', $request->email)->first();

            if (! $utilisateur || ! Hash::check($request->password, $utilisateur->password)) {
                return null;
            }

            if (! $utilisateur->est_actif || ($utilisateur->entreprise_id && ! $utilisateur->entreprise?->est_active)) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Cet accès a été désactivé. Contactez votre administrateur.',
                ]);
            }

            $utilisateur->forceFill(['derniere_connexion_le' => now()])->save();

            return $utilisateur;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
