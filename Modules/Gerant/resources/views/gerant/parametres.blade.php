<?php

use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\EnregistreurLogo;
use Modules\Noyau\Entreprises\Services\ReaffecterUnEmploye;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use App\Models\User;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{state, computed, mount, protect, usesFileUploads};

usesFileUploads();

// Lié à l'URL : un lien externe (ex. le badge Exercice de l'en-tête) peut ouvrir
// directement le bon onglet via /parametres?onglet=exercices.
state(['onglet' => 'entreprise'])->url(except: 'entreprise');

state([
    'nouvelleValeur' => [],
    'message' => null,

    // Fiche entreprise
    'nom' => '', 'gerantNom' => '', 'gerantPrenom' => '', 'gerantFonction' => '', 'gerantEmail' => '',
    'adresse' => '', 'telephone' => '', 'email' => '', 'rccm' => '',
    'ncc' => '', 'regimeImposition' => '', 'centreImpots' => '', 'compteContribuable' => '',
    'idu' => '', 'commune' => '', 'quartier' => '', 'referenceCadastrale' => '', 'proprietaireLocal' => '',
    'codeEntreprise' => '',
    'logo' => null,

    // Mon compte
    'monNom' => '', 'monEmail' => '', 'monTelephone' => '',

    // Nouvelle ville : elle crée son premier lieu, du même nom qu'elle.
    'villeNom' => '', 'villeCommune' => '', 'villeTelephone' => '', 'villeAdresse' => '',

    // Second lieu d'une ville qui en compte plusieurs, comme Abidjan.
    'lieuVilleId' => null, 'lieuNom' => '',

    // Nouvel exercice
    'exerciceAnnee' => (int) date('Y'),

    // Réaffectation d'un commercial à une nouvelle ville
    'reaffectVilleId' => [],

    'pageVilles' => 1,
    'pagePersonnel' => 1,
]);

$entreprise = computed(fn () => Entreprise::find(auth()->user()->entreprise_id));

$villes = computed(fn () => Ville::where('entreprise_id', auth()->user()->entreprise_id)
    ->with(['sites' => fn ($q) => $q->orderBy('nom')])->orderBy('code')->get());

$sites = computed(fn () => Site::where('entreprise_id', auth()->user()->entreprise_id)->orderBy('nom')->get());

$exercices = computed(fn () => Exercice::where('entreprise_id', auth()->user()->entreprise_id)
    ->with('villes')->orderByDesc('annee')->get());

$villesActives = computed(fn () => Ville::where('entreprise_id', auth()->user()->entreprise_id)->where('est_actif', true)->orderBy('nom')->get());

$personnel = computed(function () {
    $utilisateurs = User::where('entreprise_id', auth()->user()->entreprise_id)->orderByDesc('id')->get();
    $roles = User::nomsRolesParUtilisateur($utilisateurs->pluck('id'));

    return $utilisateurs->map(fn ($u) => ['utilisateur' => $u, 'role' => $roles[$u->id] ?? '—']);
});

/**
 * Les personnes qu'on peut déplacer.
 *
 * Le gérant n'y figure pas, et ce n'est pas un oubli : il ne saisit dans aucun atelier, il
 * n'a donc ni lieu à quitter ni lieu à rejoindre. L'y proposer inviterait à un geste qui
 * n'a pas de sens et que le service refuserait de toute façon.
 */
$employesDeplacables = computed(function () {
    return $this->personnel
        ->reject(fn (array $ligne) => in_array(
            $ligne['utilisateur']->roles()->pluck('name')->first(),
            ReaffecterUnEmploye::ROLES_INTERDITS,
            true,
        ))
        ->values();
});

/** L'histoire des mutations, la plus récente d'abord — lecture seule, toujours. */
$reaffectations = computed(fn () => Reaffectation::where('entreprise_id', auth()->user()->entreprise_id)
    ->with(['utilisateur', 'villeAvant', 'villeApres', 'siteAvant', 'siteApres', 'decideur'])
    ->latest()
    ->limit(200)
    ->get());

/** Les rôles vers lesquels on peut muter quelqu'un — le gérant n'en est pas. */
$rolesDeMutation = computed(fn () => collect(LibellesRoles::TOUS ?? [])
    ->reject(fn ($libelle, $cle) => in_array($cle, ReaffecterUnEmploye::ROLES_INTERDITS, true))
    ->all());

/** Commerciaux nommés de l'entreprise, pour la réaffectation de ville (Gérant uniquement). */
$commerciauxReaffectation = computed(fn () => Commercial::where('entreprise_id', auth()->user()->entreprise_id)
    ->where('est_spontane', false)->with('ville')->orderBy('nom')->get());

mount(function () {
    $e = $this->entreprise;

    $this->nom = $e->nom;
    $this->gerantNom = $e->gerant_nom ?? '';
    $this->gerantPrenom = $e->gerant_prenom ?? '';
    $this->gerantFonction = $e->gerant_fonction ?? 'Gérant';
    $this->gerantEmail = $e->gerant_email ?? '';
    $this->adresse = $e->adresse ?? '';
    $this->telephone = $e->telephone ?? '';
    $this->email = $e->email ?? '';
    $this->rccm = $e->rccm ?? '';
    $this->ncc = $e->ncc ?? '';
    $this->regimeImposition = $e->regime_imposition ?? array_key_first(Entreprise::REGIMES);
    $this->centreImpots = $e->centre_impots ?? '';
    $this->compteContribuable = $e->compte_contribuable ?? '';
    $this->idu = $e->idu ?? '';
    $this->commune = $e->commune ?? '';
    $this->quartier = $e->quartier ?? '';
    $this->referenceCadastrale = $e->reference_cadastrale ?? '';
    $this->proprietaireLocal = $e->proprietaire_local ?? '';
    $this->codeEntreprise = $e->code_entreprise ?? '';

    $this->monNom = auth()->user()->name;
    $this->monEmail = auth()->user()->email;
    $this->monTelephone = auth()->user()->telephone ?? '';

    $this->persSiteId = $this->sites->first()->id ?? '';
});

$enregistrerEntreprise = function (EnregistreurLogo $enregistreurLogo) {
    $donnees = $this->validate([
        'nom' => ['required', 'string', 'max:255'],
        'gerantNom' => ['nullable', 'string', 'max:255'],
        'gerantPrenom' => ['nullable', 'string', 'max:255'],
        'gerantFonction' => ['nullable', 'string', 'max:255'],
        'gerantEmail' => ['nullable', 'email', 'max:255'],
        'adresse' => ['nullable', 'string', 'max:255'],
        'telephone' => ['nullable', 'string', 'max:40'],
        'email' => ['nullable', 'email', 'max:255'],
        'rccm' => ['nullable', 'string', 'max:60'],
        'ncc' => ['nullable', 'string', 'max:40'],
        'regimeImposition' => ['nullable', 'string', 'max:255'],
        'centreImpots' => ['nullable', 'string', 'max:255'],
        'compteContribuable' => ['nullable', 'string', 'max:40'],
        'idu' => ['nullable', 'string', 'max:60'],
        'commune' => ['nullable', 'string', 'max:255'],
        'quartier' => ['nullable', 'string', 'max:255'],
        'referenceCadastrale' => ['nullable', 'string', 'max:255'],
        'proprietaireLocal' => ['nullable', 'string', 'max:255'],
        'logo' => EnregistreurLogo::REGLES,
    ]);

    $this->entreprise->update([
        'nom' => $donnees['nom'],
        'gerant_nom' => $donnees['gerantNom'],
        'gerant_prenom' => $donnees['gerantPrenom'],
        'gerant_fonction' => $donnees['gerantFonction'],
        'gerant_email' => $donnees['gerantEmail'],
        'adresse' => $donnees['adresse'],
        'telephone' => $donnees['telephone'],
        'email' => $donnees['email'],
        'rccm' => $donnees['rccm'],
        'ncc' => $donnees['ncc'],
        'regime_imposition' => $donnees['regimeImposition'],
        'centre_impots' => $donnees['centreImpots'],
        'compte_contribuable' => $donnees['compteContribuable'],
        'idu' => $donnees['idu'],
        'commune' => $donnees['commune'],
        'quartier' => $donnees['quartier'],
        'reference_cadastrale' => $donnees['referenceCadastrale'],
        'proprietaire_local' => $donnees['proprietaireLocal'],
    ]);

    if ($this->logo) {
        $this->entreprise->update([
            'logo_chemin' => $enregistreurLogo->enregistrer($this->logo, $this->entreprise),
        ]);
        $this->reset('logo');
    }

    unset($this->entreprise);
    $this->message = 'Fiche entreprise enregistrée.';
};

$regenererCode = function () {
    $this->codeEntreprise = Entreprise::genererCode($this->nom);
    $this->entreprise->update(['code_entreprise' => $this->codeEntreprise]);
    unset($this->entreprise);
    $this->message = 'Nouveau code entreprise généré. L\'ancien code ne fonctionne plus.';
};

$enregistrerMonCompte = function () {
    $donnees = $this->validate([
        'monNom' => ['required', 'string', 'max:255'],
        'monEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore(auth()->id())],
        'monTelephone' => ['nullable', 'string', 'max:40'],
    ], [], ['monNom' => 'nom', 'monEmail' => 'adresse e-mail', 'monTelephone' => 'téléphone']);

    auth()->user()->update([
        'name' => $donnees['monNom'],
        'email' => $donnees['monEmail'],
        'telephone' => $donnees['monTelephone'] ?: null,
    ]);

    $this->message = 'Vos informations ont été mises à jour.';
};

/*
|--------------------------------------------------------------------------
| Villes et lieux
|--------------------------------------------------------------------------
| Une ville tient un ou plusieurs lieux ; un lieu accueille les deux activités,
| Mécanique et Sinistre, qui se saisissent ligne par ligne. Ce n'est donc pas
| le lieu qui porte l'activité.
|
| L'écran créait encore deux sites « — Mécanique » et « — Sinistre » : l'ancien
| modèle, abandonné quand le site est devenu un lieu physique. La colonne
| `activite` n'existant plus sur les sites, elle était silencieusement écartée —
| il ne restait que deux lieux fantômes, sans code, là où un seul avait lieu
| d'être. Le texte de l'écran, lui, décrivait déjà le bon fonctionnement.
*/
$ajouterVille = function () {
    $donnees = $this->validate([
        'villeNom' => ['required', 'string', 'max:255'],
        'villeCommune' => ['nullable', 'string', 'max:255'],
        'villeTelephone' => ['nullable', 'string', 'max:40'],
        'villeAdresse' => ['nullable', 'string', 'max:255'],
    ], [], ['villeNom' => 'nom de la ville']);

    $rang = $this->villes->count() + 1;
    $couleurs = ['#2563EB', '#0E9F6E', '#D97706', '#C8102E', '#7C3AED', '#0891B2'];
    $code = $this->codeDeVille($donnees['villeNom']);

    $ville = Ville::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'code' => $code,
        'nom' => $donnees['villeNom'],
        'commune' => $donnees['villeCommune'] ?: null,
        'telephone' => $donnees['villeTelephone'] ?: null,
        'adresse' => $donnees['villeAdresse'] ?: null,
        'couleur' => $couleurs[($rang - 1) % count($couleurs)],
        'est_actif' => true,
    ]);

    // Un seul lieu à la création, qui se confond avec la ville : c'est le cas de
    // Bouaké et de San Pédro. Une ville qui en compte plusieurs, comme Abidjan,
    // reçoit les suivants un par un — on ne devine pas combien d'ateliers elle a.
    Site::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'ville_id' => $ville->id,
        'code' => $code,
        'nom' => $donnees['villeNom'],
        'est_actif' => true,
    ]);

    $this->reset(['villeNom', 'villeCommune', 'villeTelephone', 'villeAdresse']);
    unset($this->villes, $this->sites);
    $this->message = "Ville « {$ville->nom} » créée avec son lieu. Ajoutez-en un second si elle en compte plusieurs.";
};

/**
 * Un code court et parlant, tiré du nom : Bouaké donne BOU, San Pédro SAN.
 *
 * Il se lit dans les codes de lieu (BOU, BOU-2) et sur les écrans de filtre ;
 * « V1 », « V2 » n'apprenaient rien à personne. En cas de collision — deux villes
 * commençant pareil — un chiffre départage.
 */
// protect() : Volt fait de chaque closure une méthode publique, appelable depuis le
// navigateur. Un simple calculateur n'a rien à y faire — appelé avec un argument
// inattendu, il répondrait 500 sur une requête forgée.
$codeDeVille = protect(function (string $nom): string {
    $base = str(\Illuminate\Support\Str::ascii($nom))->replaceMatches('/[^A-Za-z]/', '')->upper()->substr(0, 3)->value();
    $base = $base ?: 'VIL';

    $pris = Ville::where('entreprise_id', auth()->user()->entreprise_id)->pluck('code')->all();
    $code = $base;
    $suffixe = 2;

    while (in_array($code, $pris, true)) {
        $code = $base.$suffixe++;
    }

    return $code;
});

/**
 * Ajoute un lieu à une ville qui en compte plusieurs.
 *
 * Abidjan tient deux ateliers ; sans ce geste, l'écran promettait un second lieu
 * qu'aucun bouton ne permettait de créer.
 */
$ajouterLieu = function (int $villeId) {
    $ville = Ville::where('entreprise_id', auth()->user()->entreprise_id)->find($villeId);

    if (! $ville) {
        return;
    }

    $donnees = $this->validate([
        'lieuNom' => ['required', 'string', 'max:255'],
    ], [], ['lieuNom' => 'nom du lieu']);

    $existants = Site::where('ville_id', $ville->id)->count();

    Site::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'ville_id' => $ville->id,
        'code' => $ville->code.'-'.($existants + 1),
        'nom' => $donnees['lieuNom'],
        'est_actif' => true,
    ]);

    $this->reset(['lieuNom', 'lieuVilleId']);
    unset($this->villes, $this->sites);
    $this->message = "Lieu « {$donnees['lieuNom']} » ajouté à {$ville->nom}.";
};

$ouvrirAjoutLieu = function (int $villeId) {
    $this->lieuVilleId = $villeId;
    $this->lieuNom = '';
    $this->resetValidation();
};

$annulerAjoutLieu = function () {
    $this->lieuVilleId = null;
    $this->resetValidation();
};

/**
 * Réaffecte un commercial à une nouvelle ville : un commercial travaille dans une
 * ville, jamais figé sur une activité, et peut être déplacé au besoin (mutation,
 * renfort d'une nouvelle ville…). Réservé au Gérant.
 */
$reaffecterCommercial = function (int $commercialId) {
    if (! auth()->user()->hasRole('gerant')) {
        return;
    }

    $nouvelleVilleId = $this->reaffectVilleId[$commercialId] ?? null;
    if (! $nouvelleVilleId) {
        return;
    }

    $commercial = Commercial::where('entreprise_id', auth()->user()->entreprise_id)->findOrFail($commercialId);
    $ville = Ville::where('entreprise_id', auth()->user()->entreprise_id)->findOrFail($nouvelleVilleId);

    $commercial->update(['ville_id' => $ville->id]);

    unset($this->commerciauxReaffectation);
    $this->message = "{$commercial->nom} réaffecté à {$ville->nom}.";
};

$creerExercice = function () {
    $donnees = $this->validate([
        'exerciceAnnee' => ['required', 'integer', 'min:2000', 'max:2100', Rule::unique('exercices', 'annee')->where('entreprise_id', auth()->user()->entreprise_id)],
    ], [], ['exerciceAnnee' => 'année']);

    Exercice::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'annee' => $donnees['exerciceAnnee'],
        'statut' => 'Ouvert',
        // Le tout premier exercice de l'entreprise devient le défaut d'office, sinon le
        // badge d'en-tête n'aurait rien à afficher tant que le gérant n'a rien choisi.
        'est_defaut' => ! Exercice::where('entreprise_id', auth()->user()->entreprise_id)->exists(),
    ]);

    $this->exerciceAnnee = (int) $donnees['exerciceAnnee'] + 1;
    unset($this->exercices);
    $this->message = 'Exercice créé.';
};

$definirExerciceParDefaut = function (int $exerciceId) {
    $exercice = Exercice::where('entreprise_id', auth()->user()->entreprise_id)->findOrFail($exerciceId);
    $exercice->definirParDefaut();
    unset($this->exercices);
    $this->message = "Exercice {$exercice->annee} marqué par défaut — c'est celui-ci que toute l'équipe voit dans l'en-tête.";
};

/**
 * Combien de factures porte une année, ville par ville.
 *
 * Le tableau ne montre plus de statut de clôture, puisqu'il n'y a plus de clôture. Ce qui
 * reste utile à voir, c'est le contenu : un exercice vide se remarque en une seconde, et
 * c'est presque toujours un import qu'on a oublié de faire.
 */
$facturesDeLAnnee = function (int $annee, int $villeId): int {
    // Une facture ne porte pas de ville : elle porte un **atelier**, et c'est l'atelier
    // qui dit la ville. Passer par `ville_id` ici échouait en base — la colonne n'existe
    // pas sur cette table, et le raccourci était le mien.
    return Facture::where('entreprise_id', auth()->user()->entreprise_id)
        ->whereYear('date', $annee)
        ->whereIn('site_id', Site::where('ville_id', $villeId)->pluck('id'))
        ->count();
};

/*
|--------------------------------------------------------------------------
| Listes déroulantes de l'entreprise
|--------------------------------------------------------------------------
| Les valeurs proposées à la saisie — activités, moyens de paiement, libellés
| d'opération — se définissent ici et nulle part ailleurs. Une valeur inventée
| sur le terrain ne serait connue que d'un seul poste ; définie ici, elle
| s'impose à tous et les indicateurs restent comparables d'une ville à l'autre.
*/
$referentiels = computed(fn () => collect(Referentiel::LIBELLES)
    ->map(fn ($libelle, $type) => [
        'libelle' => $libelle,
        'defauts' => Referentiel::DEFAUTS[$type] ?? [],
        'ajoutees' => Referentiel::where('type', $type)->orderBy('rang')->orderBy('valeur')->get(),
    ]));

$ajouterValeurReferentiel = function (string $type) {
    if (! array_key_exists($type, Referentiel::LIBELLES)) {
        return;
    }

    $valeur = trim((string) ($this->nouvelleValeur[$type] ?? ''));

    $this->validate(
        ['nouvelleValeur.'.$type => ['required', 'string', 'max:60']],
        [],
        ['nouvelleValeur.'.$type => 'valeur']
    );

    $existe = Referentiel::estValeurParDefaut($type, $valeur)
        || Referentiel::where('type', $type)->where('valeur', $valeur)->exists();

    if ($existe) {
        $this->message = "« $valeur » figure déjà dans cette liste.";

        return;
    }

    Referentiel::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'type' => $type,
        'valeur' => $valeur,
        'rang' => (int) Referentiel::where('type', $type)->max('rang') + 1,
        'est_actif' => true,
    ]);

    $this->nouvelleValeur[$type] = '';
    unset($this->referentiels);
    $this->message = "« $valeur » ajoutée à « ".Referentiel::LIBELLES[$type].' ».';
};

/** Bascule une valeur ajoutée : désactivée, elle disparaît des saisies à venir sans effacer l'historique. */
$basculerValeurReferentiel = function (int $id) {
    $valeur = Referentiel::findOrFail($id);
    $valeur->update(['est_actif' => ! $valeur->est_actif]);
    unset($this->referentiels);
    $this->message = "« {$valeur->valeur} » ".($valeur->est_actif ? 'réactivée.' : 'désactivée.');
};

/**
 * Suppression définitive : réservée aux valeurs ajoutées, jamais aux valeurs livrées
 * avec l'application, dont dépendent les calculs par activité.
 */
$supprimerValeurReferentiel = function (int $id) {
    $valeur = Referentiel::findOrFail($id);
    $intitule = $valeur->valeur;
    $valeur->delete();
    unset($this->referentiels);
    $this->message = "« $intitule » supprimée.";
};

?>

<div>
    <x-titre-ecran titre="Paramètres de l'entreprise"
        sous-titre="Villes, ateliers, personnel, exercices et référentiels." />

    <div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
        @foreach (['entreprise' => 'Fiche entreprise', 'compte' => 'Mon compte', 'villes' => 'Villes', 'personnel' => 'Personnel', 'acces' => 'Ajouter un accès', 'reaffectations' => 'Réaffectations', 'exercices' => 'Exercices', 'referentiels' => 'Listes déroulantes'] as $cle => $libelle)
            <button type="button" wire:click="$set('onglet', '{{ $cle }}')"
                class="onglet {{ $onglet === $cle ? 'est-actif' : '' }}">{{ $libelle }}</button>
        @endforeach
    </div>

    @if ($message)
        <div class="encart encart-succes">{{ $message }}</div>
    @endif

    {{-- ------------------------------------------------ Fiche entreprise --}}
    @if ($onglet === 'entreprise')
        <x-carte-section titre="Code entreprise">
            <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <div style="font-family:'Barlow Condensed',sans-serif; font-size:34px; font-weight:700; letter-spacing:3px; color:var(--th-accent,#C8102E);">
                    {{ $codeEntreprise ?: '— non généré —' }}
                </div>
                <button type="button" wire:click="regenererCode"
                    wire:confirm="Générer un nouveau code ? L'ancien code ne permettra plus de s'inscrire."
                    class="bouton bouton-secondaire">Régénérer</button>
                <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0; flex:1; min-width:240px;">
                    Communiquez ce code à vos commerciaux et responsables : ils s'inscrivent seuls sur
                    <b>{{ route('inscription.personnel') }}</b> et sont rattachés automatiquement.
                </p>
            </div>
        </x-carte-section>

        <form wire:submit="enregistrerEntreprise">
            <x-carte-section titre="Informations générales">
                <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                    <x-champ label="Nom de l'entreprise" model="nom" requis="true" />
                    <x-champ label="Nom du Gérant / Représentant" model="gerantNom" />
                    <x-champ label="Prénom du Gérant" model="gerantPrenom" />
                    <x-champ label="Fonction du Gérant" model="gerantFonction" />
                    <x-champ label="E-mail du gérant" model="gerantEmail" type="email" />
                    <x-champ label="Adresse physique" model="adresse" />
                    <x-champ label="Téléphone" model="telephone" />
                    <x-champ label="E-mail" model="email" type="email" />
                    <x-champ label="RCCM" model="rccm" />
                </div>

                <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--th-ligne,#E2E0D8);">
                    <label class="champ-libelle">Logo principal de l'entreprise</label>
                    <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                        @if ($this->entreprise?->logoUrl())
                            <div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; padding:8px 14px;">
                                <img src="{{ $this->entreprise->logoUrl() }}" alt="Logo actuel" style="height:46px; display:block;">
                            </div>
                            <span style="font-size:12.5px; color:var(--th-gris,#6B6E76);">Logo actuel</span>
                        @endif
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="champ" style="max-width:320px;">
                        <div wire:loading wire:target="logo" style="font-size:13px; color:var(--th-gris,#6B6E76);">Chargement…</div>
                        @if ($logo)
                            <div style="background:#fff; border:1px solid var(--th-accent,#C8102E); border-radius:8px; padding:8px 14px;">
                                <img src="{{ $logo->temporaryUrl() }}" alt="Nouveau logo" style="height:46px; display:block;">
                            </div>
                            <span style="font-size:12.5px; color:var(--th-accent,#C8102E); font-weight:600;">Nouveau — enregistrez pour appliquer</span>
                        @endif
                    </div>
                    @error('logo') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </x-carte-section>

            <x-carte-section titre="Informations fiscales">
                <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                    <x-champ label="NCC — N° Compte Contribuable" model="ncc" />
                    <x-champ label="Régime d'imposition" model="regimeImposition" type="select"
                        :options="\Modules\Noyau\Entreprises\Modeles\Entreprise::REGIMES" />
                    <x-champ label="Centre des impôts" model="centreImpots" />
                    <x-champ label="N° Compte Contribuable (CC)" model="compteContribuable" />
                </div>
            </x-carte-section>

            <x-carte-section titre="DGI & local professionnel">
                <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                    <x-champ label="IDU — Identifiant Unique DGI" model="idu" />
                    <x-champ label="Commune" model="commune" />
                    <x-champ label="Quartier" model="quartier" />
                    <x-champ label="Référence cadastrale" model="referenceCadastrale" />
                    <x-champ label="Propriétaire du local professionnel" model="proprietaireLocal" />
                </div>
            </x-carte-section>

            <button type="submit" class="bouton">Enregistrer la fiche entreprise</button>
        </form>
    @endif

    {{-- ------------------------------------------------------- Mon compte --}}
    @if ($onglet === 'compte')
        <form wire:submit="enregistrerMonCompte">
            <x-carte-section titre="Mes informations">
                <div class="bloc-saisie" style="background:transparent; border:0; padding:0;">
                    <x-champ label="Nom et prénoms" model="monNom" requis="true" />
                    <x-champ label="Adresse e-mail" model="monEmail" type="email" requis="true" />
                    <x-champ label="Téléphone" model="monTelephone" />
                </div>
                <div style="margin-top:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <button type="submit" class="bouton">Enregistrer</button>
                    <a href="{{ route('mot-de-passe.modifier') }}" wire:navigate
                        style="font-size:14px; color:var(--th-accent,#C8102E); font-weight:700; text-decoration:none;">
                        Changer mon mot de passe
                    </a>
                </div>
            </x-carte-section>
        </form>
    @endif

    {{-- ----------------------------------------------------------- Villes --}}
    @if ($onglet === 'villes')
        <x-carte-section titre="Création d'une ville">
            <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px;">
                Créer une ville crée aussitôt son lieu, du même nom qu'elle : c'est là que se tiennent
                les deux activités, Mécanique et Sinistre, qui se saisissent ligne par ligne. Une ville
                qui compte plusieurs ateliers reçoit les suivants avec « + Ajouter un lieu ».
            </p>
            <div class="bloc-saisie">
                <x-champ label="Nom de la ville" model="villeNom" requis="true" placeholder="Ex : Bouaké" />
                <x-champ label="Commune" model="villeCommune" placeholder="Ex : Koko" />
                <x-champ label="Téléphone" model="villeTelephone" placeholder="+225 07 ..." />
                <x-champ label="Adresse" model="villeAdresse" />
                <button type="button" wire:click="ajouterVille" class="bouton bouton-sombre">+ Ajouter la ville</button>
            </div>

            <div class="tableau-conteneur" style="margin-top:16px;">
                <table class="tableau">
                    <thead><tr><th>Code</th><th>Ville</th><th>Commune</th><th>Téléphone</th><th>Responsable</th><th>Lieux</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                        @forelse ($this->villes->forPage($pageVilles, 10) as $ville)
                            <tr wire:key="ville-{{ $ville->id }}">
                                <td style="font-weight:700;">{{ $ville->code }}</td>
                                <td>
                                    <span style="display:inline-block; width:9px; height:9px; border-radius:99px; background:{{ $ville->couleur }}; margin-right:7px;"></span>
                                    {{ $ville->nom }}
                                </td>
                                <td>{{ $ville->commune ?? '—' }}</td>
                                <td>{{ $ville->telephone ?? '—' }}</td>
                                <td>{{ $ville->responsable?->name ?? '— à nommer —' }}</td>
                                <td style="white-space:normal; min-width:220px;">
                                    @foreach ($ville->sites as $site)
                                        <div style="font-size:13px;">
                                            <span style="font-weight:600;">{{ $site->code ?? '—' }}</span>
                                            · {{ $site->nom }}
                                            <span style="color:var(--th-gris,#6B6E76);">({{ $site->responsable?->name ?? 'hérite de la ville' }})</span>
                                        </div>
                                    @endforeach

                                    @if ($lieuVilleId === $ville->id)
                                        <div style="display:flex; gap:6px; align-items:center; margin-top:6px; flex-wrap:wrap;">
                                            <input type="text" wire:model="lieuNom" value="{{ $lieuNom }}" class="champ" style="min-width:170px;"
                                                placeholder="Ex : {{ $ville->nom }} — Site 2">
                                            <button type="button" wire:click="ajouterLieu({{ $ville->id }})"
                                                class="bouton bouton-petit bouton-vert">Ajouter</button>
                                            <button type="button" wire:click="annulerAjoutLieu"
                                                class="bouton bouton-petit bouton-secondaire">Annuler</button>
                                        </div>
                                        @error('lieuNom')
                                            <div style="font-size:11.5px; color:var(--th-accent,#C8102E); margin-top:4px;">{{ $message }}</div>
                                        @enderror
                                    @endif
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    @if ($lieuVilleId !== $ville->id)
                                        <button type="button" wire:click="ouvrirAjoutLieu({{ $ville->id }})"
                                            class="bouton bouton-petit bouton-secondaire">+ Ajouter un lieu</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune ville enregistrée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageVilles" :total="$this->villes->count()" prop="pageVilles" />
        </x-carte-section>
    @endif

    {{-- -------------------------------------------------------- Personnel --}}
    @if ($onglet === 'personnel')
        <x-carte-section titre="Personnel de l'entreprise">
            <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px;">
                Pour créer un Responsable de site, un Commercial ou un accès Comptabilité, direction l'onglet
                <button type="button" wire:click="$set('onglet', 'acces')" style="background:none; border:0; padding:0; color:var(--th-accent,#C8102E); font-weight:700; cursor:pointer; font-size:13px;">Ajouter un accès</button>.
            </p>

            <div class="tableau-conteneur" style="margin-top:16px;">
                <table class="tableau">
                    <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Affectation</th><th>Statut</th></tr></thead>
                    <tbody>
                        @forelse ($this->personnel->forPage($pagePersonnel, 10) as $ligne)
                            @php
                                $u = $ligne['utilisateur'];
                                $sonSite = $u->site_id ? $this->sites->firstWhere('id', $u->site_id) : null;
                                $saVille = $u->ville_id ? $this->villes->firstWhere('id', $u->ville_id) : null;
                            @endphp
                            <tr>
                                <td style="font-weight:600;">{{ $u->name }}</td>
                                <td>{{ $u->email }}</td>
                                <td>{{ $ligne['role'] }}</td>
                                {{-- Où travaille cette personne aujourd'hui. La colonne manquait, et
                                     son absence rendait la mutation impossible à préparer : on ne
                                     savait pas d'où l'on partait. --}}
                                <td>
                                    @if ($sonSite)
                                        {{ $sonSite->nom }}
                                    @elseif ($saVille)
                                        {{ $saVille->nom }}
                                    @else
                                        <span style="color:#6B6E76;">toute l'entreprise</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="pastille {{ $u->est_actif ? 'pastille-vert' : 'pastille-rouge' }}">
                                        {{ $u->est_actif ? 'Actif' : 'Révoqué' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Aucun membre du personnel." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pagePersonnel" :total="$this->personnel->count()" prop="pagePersonnel" />
        </x-carte-section>

        {{-- ------------------------------------------------------ muter un employé

             Le geste passe par un **formulaire qui poste**, pas par une action interactive :
             il change ce qu'une personne voit et où elle saisit, et il ne doit pas pouvoir
             échouer en silence parce qu'un script ne s'est pas chargé.

             Ce qu'il fait, et ce qu'il ne fait pas : la personne change de lieu, son code du
             logiciel d'atelier la suit, et **rien de son travail ne bouge**. Une facture faite
             au Site 1 reste au Site 1 : c'est là que le chiffre d'affaires a eu lieu. --}}
        @if (auth()->user()->hasRole('gerant'))
            <x-carte-section titre="Réaffecter un employé" icone="liste" couleur="#2563EB">
                <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px; line-height:1.65;">
                    Une mutation déplace la <strong>personne</strong>, jamais son <strong>travail</strong>.
                    Ses fiches et ses factures restent dans l'atelier où elles ont été faites — les déplacer
                    fausserait deux ateliers d'un coup, celui qu'on vide et celui qu'on gonfle.
                    Elle continue de <strong>consulter</strong> son ancien lieu, sans plus pouvoir y saisir.
                </p>

                @error('employe')
                    <div class="encart encart-alerte" style="margin-bottom:12px;">{{ $message }}</div>
                @enderror

                <form method="POST" action="{{ route('reaffecter') }}">
                    @csrf

                    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; align-items:end;">
                        <div>
                            <label for="ra-employe" class="champ-libelle">Qui</label>
                            <select id="ra-employe" name="employe" class="champ" required>
                                <option value="">— choisir la personne —</option>
                                @foreach ($this->employesDeplacables as $ligne)
                                    <option value="{{ $ligne['utilisateur']->id }}"
                                        @selected((string) old('employe') === (string) $ligne['utilisateur']->id)>
                                        {{ $ligne['utilisateur']->name }} — {{ $ligne['role'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="ra-role" class="champ-libelle">Rôle après la mutation</label>
                            <select id="ra-role" name="role" class="champ">
                                <option value="">— conserver son rôle actuel —</option>
                                @foreach ($this->rolesDeMutation as $cle => $libelle)
                                    <option value="{{ $cle }}" @selected((string) old('role') === (string) $cle)>{{ $libelle }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="ra-ville" class="champ-libelle">Ville de destination</label>
                            <select id="ra-ville" name="ville" class="champ" required>
                                <option value="">— choisir —</option>
                                @foreach ($this->villesActives as $ville)
                                    <option value="{{ $ville->id }}" @selected((string) old('ville') === (string) $ville->id)>{{ $ville->nom }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- L'atelier n'a de sens que là où la ville en compte plusieurs. Tous
                             sont rendus ; le navigateur ne montre que ceux de la ville retenue,
                             et masque le champ quand il n'y a pas de choix à faire. --}}
                        <div id="ra-bloc-site">
                            <label for="ra-site" class="champ-libelle">Atelier</label>
                            <select id="ra-site" name="site" class="champ">
                                <option value="" data-ville="">— toute la ville —</option>
                                @foreach ($this->villes as $ville)
                                    @if ($ville->sites->count() > 1)
                                        @foreach ($ville->sites as $site)
                                            <option value="{{ $site->id }}" data-ville="{{ $ville->id }}"
                                                @selected((string) old('site') === (string) $site->id)>{{ $site->nom }}</option>
                                        @endforeach
                                    @endif
                                @endforeach
                            </select>
                        </div>

                        <div style="grid-column:1 / -1;">
                            <label for="ra-motif" class="champ-libelle">Motif</label>
                            <input type="text" id="ra-motif" name="motif" class="champ" maxlength="255"
                                   value="{{ old('motif') }}"
                                   placeholder="Renfort à San Pédro, ouverture d'un poste, retour de congé…">
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <button type="submit" class="bouton bouton-sombre">Réaffecter</button>
                    </div>
                </form>

                <script data-navigate-once>
                    (function () {
                        var ville = document.getElementById('ra-ville');
                        var site = document.getElementById('ra-site');
                        var bloc = document.getElementById('ra-bloc-site');

                        if (! ville || ! site || ! bloc) { return; }

                        var filtrer = function () {
                            var choisie = ville.value;
                            var visibles = 0;

                            Array.prototype.forEach.call(site.options, function (option) {
                                var sienne = option.getAttribute('data-ville');
                                var garder = sienne === '' || sienne === choisie;
                                option.hidden = ! garder;
                                option.disabled = ! garder;
                                if (garder && sienne !== '') { visibles++; }
                            });

                            if (site.selectedOptions.length && site.selectedOptions[0].disabled) {
                                site.value = '';
                            }

                            bloc.style.display = visibles > 0 ? '' : 'none';
                        };

                        ville.addEventListener('change', filtrer);
                        filtrer();
                    })();
                </script>
            </x-carte-section>
        @endif

        @if (auth()->user()->hasRole('gerant'))
            <x-carte-section titre="Réaffecter un commercial">
                <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px;">
                    Un commercial travaille dans une ville entière, pas sur une seule activité : il peut être
                    déplacé vers une autre ville à tout moment, par exemple en cas de mutation.
                </p>
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead><tr><th>Commercial</th><th>Ville actuelle</th><th>Nouvelle ville</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($this->commerciauxReaffectation as $commercial)
                                <tr wire:key="reaffect-{{ $commercial->id }}">
                                    <td style="font-weight:600;">{{ $commercial->numero }} — {{ $commercial->nom }}</td>
                                    <td>{{ $commercial->ville->nom }}</td>
                                    <td>
                                        <select wire:model="reaffectVilleId.{{ $commercial->id }}" class="champ" style="width:auto;">
                                            @foreach ($this->villesActives as $ville)
                                                <option value="{{ $ville->id }}" @selected($ville->id === $commercial->ville_id)>{{ $ville->nom }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <button type="button" wire:click="reaffecterCommercial({{ $commercial->id }})"
                                            wire:confirm="Réaffecter {{ $commercial->nom }} à cette ville ?"
                                            class="bouton bouton-secondaire bouton-petit">Réaffecter</button>
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="4" texte="Aucun commercial à réaffecter." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-carte-section>
        @endif
    @endif

    {{-- ------------------------------------------------- Ajouter un accès --}}
    @if ($onglet === 'acces')
        <livewire:pilotage.acces-creer />
    @endif

    {{-- -------------------------------------------------------- Exercices --}}
    {{-- ---------------------------------------------- Réaffectations (lecture seule)

         L'écran ne propose aucun geste : il raconte. C'est ce qu'on vient y chercher six
         mois plus tard, quand le chiffre d'affaires d'un atelier baisse et qu'on se demande
         qui l'a quitté. Le geste, lui, est dans l'onglet Personnel — là où l'on a la liste
         des gens sous les yeux. --}}
    @if ($onglet === 'reaffectations')
        @if (session('message-reaffectation'))
            <div class="encart encart-succes">{{ session('message-reaffectation') }}</div>
        @endif

        <x-carte-section titre="Historique des réaffectations" icone="liste" couleur="#6B6E76">
            <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px; line-height:1.65;">
                Chaque mutation, telle qu'elle a été décidée. <strong>Rien ne se modifie ici</strong> —
                une mutation est un fait daté, pas un réglage : la corriger reviendrait à réécrire
                l'histoire d'un atelier. Pour déplacer quelqu'un, passez par l'onglet
                <button type="button" wire:click="$set('onglet', 'personnel')"
                        style="background:none; border:0; padding:0; color:var(--th-accent,#C8102E); font-weight:700; cursor:pointer; font-size:13px;">Personnel</button>.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th><th>Qui</th><th>Depuis</th><th>Vers</th>
                            <th>Rôle</th><th>Motif</th><th>Décidé par</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->reaffectations as $mouvement)
                            <tr wire:key="ra-{{ $mouvement->id }}">
                                <td>{{ $mouvement->created_at?->format('d/m/Y') }}</td>
                                <td style="font-weight:600;">{{ $mouvement->utilisateur?->name ?? 'compte supprimé' }}</td>
                                <td>{{ $mouvement->siteAvant?->nom ?? $mouvement->villeAvant?->nom ?? '—' }}</td>
                                <td>{{ $mouvement->siteApres?->nom ?? $mouvement->villeApres?->nom ?? '—' }}</td>
                                <td>
                                    @if ($mouvement->role_avant !== $mouvement->role_apres)
                                        {{ LibellesRoles::de($mouvement->role_avant) }}
                                        → <strong>{{ LibellesRoles::de($mouvement->role_apres) }}</strong>
                                    @else
                                        <span style="color:#6B6E76;">{{ LibellesRoles::de($mouvement->role_apres) }} — inchangé</span>
                                    @endif
                                </td>
                                <td>{{ $mouvement->motif ?: '—' }}</td>
                                <td>{{ $mouvement->decideur?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7"
                                texte="Aucune réaffectation à ce jour. Elles apparaîtront ici dès la première mutation." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-carte-section>
    @endif

    @if ($onglet === 'exercices')
        {{-- **Les exercices ne se clôturent plus.**

             La page proposait encore de clore une année, ville par ville puis en bloc. Le
             moteur, lui, avait déjà changé de règle : au passage d'année, le système crée
             l'exercice suivant et y bascule tout seul, sans rien fermer derrière. Les deux
             se contredisaient, et c'est l'écran qui avait tort — un bouton « Clôturer » sur
             une mécanique qui ne clôture rien ne pouvait que tromper.

             Ce qui reste : la création manuelle, qui sert à ouvrir une année *antérieure*
             pour y importer l'historique. Une année future, elle, s'ouvrira d'elle-même le
             jour venu — inutile de la préparer. --}}
        <x-carte-section titre="Comment fonctionnent les exercices" icone="liste" couleur="#0E9F6E">
            <div style="font-size:13.5px; color:var(--th-gris,#6B6E76); margin:0 0 14px; line-height:1.7;">
                <p style="margin:0 0 8px;">
                    <strong style="color:var(--th-ink,#191B20);">Aucun exercice ne se clôture.</strong>
                    Au passage d'une année à l'autre — le 1<sup>er</sup> janvier, à la première connexion —
                    le système crée l'exercice suivant et bascule dessus. L'année précédente reste
                    <strong>ouverte</strong>&nbsp;: consultable, corrigeable, et une facture de décembre
                    qui arrive le 8 janvier se saisit à sa date sans qu'on ait à rouvrir quoi que ce soit.
                </p>
                <p style="margin:0;">
                    Pour <strong>consulter</strong> une autre année, il n'y a rien à faire ici&nbsp;:
                    le sélecteur « Exercice&nbsp;{{ now()->year }} » du bandeau, en haut de chaque écran,
                    change l'année regardée sans rien modifier en base.
                </p>
            </div>
        </x-carte-section>

        <x-carte-section titre="Ouvrir une année antérieure" icone="liste" couleur="#2563EB">
            <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px; line-height:1.6;">
                Utile pour <strong>reprendre un historique</strong>&nbsp;: créez l'année, puis importez-y
                les fichiers de cette période. Les années à venir n'ont pas besoin d'être préparées —
                elles s'ouvrent d'elles-mêmes le jour où elles commencent.
            </p>
            <div class="bloc-saisie">
                <x-champ label="Année" model="exerciceAnnee" type="number" width="140" />
                <button type="button" wire:click="creerExercice" class="bouton bouton-sombre">+ Créer l'exercice</button>
            </div>
        </x-carte-section>

        @foreach ($this->exercices as $exercice)
            <x-carte-section titre="Exercice {{ $exercice->annee }}" icone="liste"
                             couleur="{{ $exercice->est_defaut ? '#0E9F6E' : '#6B6E76' }}">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
                    <span class="pastille pastille-vert">Ouvert</span>

                    @if ($exercice->est_defaut)
                        <span class="pastille pastille-bleu">★ Exercice en cours — celui où l'on saisit</span>
                    @else
                        <span style="font-size:12.5px; color:#6B6E76;">
                            Année antérieure — consultable et corrigeable.
                        </span>
                    @endif
                </div>

                {{-- Les villes n'ont plus de statut à afficher : rien ne se ferme. Ce qui
                     reste utile, c'est de savoir ce que cette année contient — un exercice
                     vide se remarque tout de suite, et c'est souvent un import oublié. --}}
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead><tr><th>Ville</th><th>Saisie</th><th style="text-align:right;">Factures de l'année</th></tr></thead>
                        <tbody>
                            @forelse ($this->villesActives as $ville)
                                <tr wire:key="exercice-{{ $exercice->id }}-ville-{{ $ville->id }}">
                                    <td style="font-weight:600;">{{ $ville->nom }}</td>
                                    <td><span class="pastille pastille-vert">Ouverte</span></td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">
                                        {{ number_format($this->facturesDeLAnnee($exercice->annee, $ville->id), 0, ',', ' ') }}
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="3" texte="Aucune ville active." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-carte-section>
        @endforeach

        @if ($this->exercices->isEmpty())
            <p class="legende-vide">Aucun exercice créé pour le moment.</p>
        @endif
    @endif

    {{-- ---------------------------------------------- Listes déroulantes --}}
    @if ($onglet === 'referentiels')
        <div class="carte" style="margin-bottom:16px;">
            <h3 class="titre-section">Ce que règle cette page</h3>
            <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0; line-height:1.55;">
                Les valeurs proposées à la saisie se définissent ici, et seulement ici. Une valeur
                créée sur le terrain ne serait connue que d'un poste ; définie depuis la direction,
                elle s'impose à toutes les villes et les indicateurs restent comparables.
                Les valeurs livrées avec l'application ne sont ni modifiables ni supprimables :
                les calculs par activité s'appuient dessus.
            </p>
        </div>

        @foreach ($this->referentiels as $type => $liste)
            <x-carte-section titre="{{ $liste['libelle'] }}" icone="liste" couleur="#2A2E35">
                <div class="bloc-saisie">
                    <x-champ label="Nouvelle valeur" model="nouvelleValeur.{{ $type }}" width="240"
                        placeholder="Ex : Wave, Orange Money…" />
                    <button type="button" wire:click="ajouterValeurReferentiel('{{ $type }}')"
                        class="bouton bouton-sombre">+ Ajouter</button>
                </div>

                <div class="tableau-conteneur" style="margin-top:14px;">
                    <table class="tableau">
                        <thead><tr><th>Valeur</th><th>Origine</th><th>État</th><th style="text-align:right;">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($liste['defauts'] as $valeur)
                                <tr wire:key="def-{{ $type }}-{{ $valeur }}">
                                    <td style="font-weight:600;">{{ $valeur }}</td>
                                    <td><span class="pastille pastille-bleu">Livrée</span></td>
                                    <td><span class="pastille pastille-vert">Active</span></td>
                                    <td style="text-align:right; color:var(--th-gris,#6B6E76); font-size:12.5px;">Non modifiable</td>
                                </tr>
                            @endforeach

                            @foreach ($liste['ajoutees'] as $valeur)
                                <tr wire:key="ref-{{ $valeur->id }}">
                                    <td style="font-weight:600;">{{ $valeur->valeur }}</td>
                                    <td><span class="pastille">Ajoutée</span></td>
                                    <td>
                                        <span class="pastille {{ $valeur->est_actif ? 'pastille-vert' : 'pastille-rouge' }}">
                                            {{ $valeur->est_actif ? 'Active' : 'Désactivée' }}
                                        </span>
                                    </td>
                                    <td style="text-align:right; display:flex; gap:8px; justify-content:flex-end;">
                                        <button type="button" wire:click="basculerValeurReferentiel({{ $valeur->id }})"
                                            class="bouton bouton-secondaire bouton-petit">
                                            {{ $valeur->est_actif ? 'Désactiver' : 'Réactiver' }}
                                        </button>
                                        <button type="button" wire:click="supprimerValeurReferentiel({{ $valeur->id }})"
                                            wire:confirm="Supprimer « {{ $valeur->valeur }} » ? Les écritures déjà saisies avec cette valeur la conservent."
                                            class="bouton bouton-secondaire bouton-petit">Supprimer</button>
                                    </td>
                                </tr>
                            @endforeach

                            @if ($liste['ajoutees']->isEmpty() && empty($liste['defauts']))
                                <x-table-vide :colspan="4" texte="Aucune valeur." />
                            @endif
                        </tbody>
                    </table>
                </div>
            </x-carte-section>
        @endforeach
    @endif
</div>
