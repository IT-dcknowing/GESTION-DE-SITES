<?php

namespace App\Models;

use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'entreprise_id', 'ville_id', 'site_id', 'couvre_toutes_les_villes', 'telephone', 'est_actif', 'doit_changer_mot_de_passe', 'email_verified_at', 'photo_chemin', 'cree_par_id', 'est_fondateur', 'habilitations'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'derniere_connexion_le' => 'datetime',
            // Le moment où la personne a reconnu son code d'atelier. Une date plutôt qu'un
            // oui : elle dit si la confirmation portait sur le code d'avant ou celui d'après.
            'code_atelier_confirme_le' => 'datetime',
            'password' => 'hashed',
            'est_actif' => 'boolean',
            'doit_changer_mot_de_passe' => 'boolean',
            'est_fondateur' => 'boolean',
            'couvre_toutes_les_villes' => 'boolean',
            'habilitations' => 'array',
        ];
    }

    /**
     * Sections de la plateforme ouvertes à ce Super Admin.
     * Le fondateur les a toutes, sans exception ni possibilité de retrait.
     *
     * @return array<int, string>
     */
    public function sectionsAutorisees(): array
    {
        if (! $this->estSuperAdmin()) {
            return [];
        }

        if ($this->est_fondateur) {
            return array_keys(self::SECTIONS_PLATEFORME);
        }

        return array_values(array_intersect(
            $this->habilitations ?? [],
            array_keys(self::SECTIONS_PLATEFORME),
        ));
    }

    /** Sections administrables de la plateforme, libellées pour l'interface. */
    public const SECTIONS_PLATEFORME = [
        'dashboard' => 'Tableau de bord',
        'entreprises' => 'Entreprises',
        'acces' => 'Accès et administrateurs',
        'journal' => "Journal d'activité",
        'maintenance' => 'Maintenance et purge',
    ];

    public function peutAccederA(string $section): bool
    {
        return in_array($section, $this->sectionsAutorisees(), true);
    }

    /**
     * Vrai si cet utilisateur peut modifier ou supprimer le compte visé.
     *
     * Deux garde-fous : le fondateur n'est jamais modifiable par un tiers, et un
     * secondaire ne gère que les comptes qu'il a lui-même créés.
     */
    public function peutGerer(self $cible): bool
    {
        if ($cible->est_fondateur) {
            return false;
        }

        if ($this->id === $cible->id) {
            return false;
        }

        if (! $this->estSuperAdmin()) {
            return false;
        }

        return $this->est_fondateur || $cible->cree_par_id === $this->id;
    }

    /** URL de la photo de profil, ou null si aucune photo servable. */
    public function photoUrl(): ?string
    {
        if (! $this->photo_chemin || ! Storage::disk('public')->exists($this->photo_chemin)) {
            return null;
        }

        return Storage::disk('public')->url($this->photo_chemin);
    }

    /** Initiales affichées à défaut de photo. */
    public function initiales(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()->take(2)
            ->map(fn ($m) => mb_strtoupper(mb_substr($m, 0, 1)))
            ->implode('');
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    /** Rattachement d'un caissier à une ville entière (les deux sites), s'il n'est pas rattaché à un site précis. */
    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    /** Rattachement d'un caissier à un site précis (une seule activité). */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Administrateur qui a créé ce compte, pour délimiter le périmètre des secondaires. */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cree_par_id');
    }

    /** Un utilisateur sans entreprise est un membre de l'équipe plateforme (Super Admin). */
    /**
     * Ce compte voit-il toutes les villes de son entreprise ?
     *
     * Le gérant, par construction. Et le responsable commercial d'un groupe qui n'a qu'un
     * animateur pour l'ensemble de ses villes — cas assez fréquent pour mériter sa colonne,
     * plutôt qu'un `ville_id` laissé à nul, qui veut déjà dire « pas encore rattaché ».
     */
    public function voitToutesLesVilles(): bool
    {
        return $this->hasRole('gerant') || (bool) $this->couvre_toutes_les_villes;
    }

    public function estSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Noms des rôles d'un ensemble d'utilisateurs, sans filtrage par équipe courante.
     * Utile pour les écrans Super Admin qui listent des utilisateurs de plusieurs entreprises à la fois.
     *
     * @return array<int, string> nom_role_1, nom_role_2 indexé par user_id
     */
    public static function nomsRolesParUtilisateur(iterable $userIds): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::class)
            ->whereIn('model_has_roles.model_id', collect($userIds)->all())
            ->select('model_has_roles.model_id as user_id', 'roles.name')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($lignes) => $lignes->pluck('name')->implode(', '))
            ->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'est_actif', 'entreprise_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
