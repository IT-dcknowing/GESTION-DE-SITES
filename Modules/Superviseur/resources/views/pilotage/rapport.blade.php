<?php

use Livewire\WithFileUploads;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\IA\Modeles\ProductionIA;
use Modules\Noyau\IA\Services\IAIndisponible;
use Modules\Noyau\Rapport\Modeles\DepotRapport;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Services\AgentDuRapport;
use Modules\Noyau\Rapport\Services\AssistantDuRapport;
use Modules\Noyau\Rapport\Services\DepotDeRapport;
use Modules\Noyau\Rapport\Services\DepotEnDoubleDeRapport;
use Modules\Noyau\Rapport\Services\RapportFinancier;
use Modules\Noyau\Rapport\Support\LectureDesPieces;
use Modules\Noyau\Rapport\Support\PeriodeDuRapport;
use function Livewire\Volt\{state, computed, mount, protect, uses};

/*
 * Le module Rapport — 06/11. L'Excel « Rapport financier », dans l'application, et vivant.
 *
 * « Dès que le bouton Rapport est cliqué, la première page est la présentation, et les autres boutons
 * sont alignés en haut, comme les feuilles de l'Excel. » Chaque bouton est une page ; l'onglet se lit
 * dans l'adresse (`?vue=charges`), donc se partage.
 *
 * **Le style est celui de l'Excel et du PowerPoint** : bandeau sombre à liseré rouge, barres de section
 * rouges, en-têtes de tableau sombres, lignes de total grises, montants en francs entiers. Seul l'onglet
 * ouvert est rouge ; les autres sont neutres.
 *
 * Tout vient des services que les autres écrans lisent déjà (`RapportFinancier`) : le rapport bouge
 * avec les données, sur l'exercice en cours — et la page « Année précédente » rappelle N-1.
 *
 * **Habilitation** : `rapport.voir` ouvre la page, `rapport.deposer` le dépôt — accordées par le Super admin,
 * jamais par défaut. Le dépôt de fichiers (impôts, CNPS, récapitulatifs) ne crée jamais de doublon : voir `DepotDeRapport`.
 * L'assistant IA ne parle qu'au clic, et seulement si une clé est posée (`AssistantDuRapport`).
 *
 * **La période et le lieu (25/11).** « Une semaine du jeudi au mercredi, comme l'Excel de trésorerie,
 * un mois, ou des dates libres ; la ville, l'atelier, l'activité. » Tout est dans l'adresse, comme
 * `vue`, pour qu'un rapport filtré se partage. La ville et l'atelier sont revérifiés au serveur
 * (`RapportFinancier::lieuAutorise`) : un identifiant hors du périmètre du compte est refusé, jamais
 * élargi. L'activité ne choisit qu'entre Mécanique et Sinistre.
 */
uses(WithFileUploads::class);

state(['vue' => 'presentation'])->url(except: 'presentation');
state(['exercice' => null])->url(except: null);
state(['periode' => 'exercice'])->url(except: 'exercice');
state(['jour' => ''])->url(except: '');
state(['mois' => ''])->url(except: '');
state(['du' => ''])->url(except: '');
state(['au' => ''])->url(except: '');
state(['ville' => ''])->url(except: '');
state(['atelier' => ''])->url(except: '');
state(['activite' => ''])->url(except: '');
state([
    'fichier' => null, 'natureDepot' => '', 'messageDepot' => '', 'erreurDepot' => '',
    'commentaireIa' => '', 'erreurIa' => '',
    'pieceNature' => 'impot', 'pieceStatut' => 'du', 'pieceDate' => '', 'pieceLibelle' => '', 'pieceTiers' => '', 'pieceReference' => '', 'pieceMontant' => '',
    'propositions' => [], 'depotLu' => null,
    'messageChat' => '', 'fichierChat' => null, 'consigneAnalyse' => '', 'erreurChat' => '', 'infoIa' => '',
]);

// Par défaut, personne : seul le Super admin accorde `rapport.voir` (lire) et `rapport.deposer` (écrire).
mount(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'rapport', 'voir'), 403);
    $this->lieuDansLePerimetre();
});

// Le lieu vient de l'adresse ou d'un sélecteur : il se revérifie à chaque changement, jamais sur la
// seule foi des options que la page a proposées.
$lieuDansLePerimetre = protect(function () {
    abort_unless(RapportFinancier::lieuAutorise(auth()->user(), (int) $this->ville ?: null, (int) $this->atelier ?: null), 403, 'Ce lieu est hors de votre périmètre.');
});

$updatedVille = function () {
    $this->atelier = '';
    $this->lieuDansLePerimetre();
};

$updatedAtelier = function () {
    $this->lieuDansLePerimetre();
};

$updatedActivite = function () {
    $this->activite = in_array($this->activite, RapportFinancier::ACTIVITES, true) ? $this->activite : '';
};

// Changer de lecture pose une date de départ : un champ vide ne dirait pas quelle semaine on lit.
$updatedPeriode = function () {
    $this->periode = array_key_exists($this->periode, PeriodeDuRapport::MODES) ? $this->periode : 'exercice';
    $jour = PeriodeDuRapport::jourParDefaut($this->exerciceChoisi);
    $this->jour = $this->periode === 'semaine' && $this->jour === '' ? $jour->toDateString() : $this->jour;
    $this->mois = $this->periode === 'mois' && $this->mois === '' ? $jour->format('Y-m') : $this->mois;
    $this->du = $this->periode === 'dates' && $this->du === '' ? $this->exerciceChoisi.'-01-01' : $this->du;
    $this->au = $this->periode === 'dates' && $this->au === '' ? $jour->toDateString() : $this->au;
};

// Un tableau local ne passe pas à la vue d'un composant Volt : il faut un `computed` (lu par `$this->onglets`).
$onglets = computed(fn () => array_filter([
    'presentation' => 'Présentation', 'ca' => 'Chiffre d\'affaires', 'recouvrement' => 'Recouvrement', 'charges' => 'Charges',
    'conclusion' => 'Conclusion', 'commerciaux' => 'Commerciaux', 'precedent' => 'Année précédente', 'depot' => 'Dépôt de fichiers',
    // Le dernier « slide » : l'assistant IA — sur habilitation `rapport.ia`, personne au départ.
    'ia' => HabilitationsDActions::accorde(auth()->user(), 'rapport', 'ia') ? 'Assistant IA' : null,
]));

$exerciceChoisi = computed(fn () => (int) ($this->exercice ?: now()->year));
$annees = computed(fn () => range((int) now()->year, (int) now()->year - 4));
$criteres = computed(fn () => [
    'periode' => $this->periode, 'jour' => $this->jour, 'mois' => $this->mois, 'du' => $this->du, 'au' => $this->au,
    'ville' => $this->ville, 'atelier' => $this->atelier, 'activite' => $this->activite,
]);
$rapport = computed(fn () => RapportFinancier::pour(auth()->user(), $this->exerciceChoisi, $this->criteres));
$villes = computed(fn () => PerimetreSites::villesVisibles(auth()->user()));
$ateliers = computed(fn () => PerimetreSites::sitesConsultables(auth()->user())
    ->when($this->ville !== '', fn ($s) => $s->where('ville_id', (int) $this->ville))->values());
// Ce qui est isolé, en clair, pour le titre de chaque onglet : « Abidjan, Mécanique ».
$lieuChoisi = computed(fn () => collect([
    $this->ville !== '' ? $this->villes->firstWhere('id', (int) $this->ville)?->nom : null,
    $this->atelier !== '' ? $this->ateliers->firstWhere('id', (int) $this->atelier)?->nom : null,
    $this->activite ?: null,
])->filter()->implode(', '));
$donnees = computed(fn () => $this->rapport->tout());
$commentaires = computed(fn () => AssistantDuRapport::commentaires($this->donnees));
$peutDeposer = computed(fn () => HabilitationsDActions::accorde(auth()->user(), 'rapport', 'deposer'));
$peutIa = computed(fn () => HabilitationsDActions::accorde(auth()->user(), 'rapport', 'ia') && HabilitationsDActions::accorde(auth()->user(), 'rapport', 'voir'));
$iaDispo = computed(fn () => AssistantDuRapport::iaDisponible() && $this->peutIa);
$depots = computed(fn () => DepotRapport::query()->with('deposant')->latest()->limit(15)->get());
$pieces = computed(fn () => PieceRapport::query()->where('exercice', $this->exerciceChoisi)->latest('id')->limit(60)->get());
$logo = computed(fn () => auth()->user()->entreprise?->logoUrl());

$aller = function (string $vue) {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'rapport', 'voir'), 403);
    $this->vue = array_key_exists($vue, $this->onglets) ? $vue : 'presentation';
};

$commenterParIA = function () {
    abort_unless($this->peutIa, 403);
    $this->commentaireIa = '';
    $this->erreurIa = '';
    $texte = AssistantDuRapport::commenterParIA($this->rapport->resumePourIA());

    if ($texte === null) {
        $this->erreurIa = 'L\'assistant n\'a pas répondu (clé absente ou service indisponible). Les commentaires de calcul restent affichés.';

        return;
    }

    $this->commentaireIa = $texte;
};

$deposer = function () {
    abort_unless($this->peutDeposer, 403);
    $this->messageDepot = $this->erreurDepot = '';
    $this->validate(['fichier' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt,pdf,jpg,jpeg,png,webp']]);

    try {
        $depot = DepotDeRapport::deposer($this->fichier, auth()->user(), $this->natureDepot ?: null, $this->exerciceChoisi);
        $this->messageDepot = $depot->nom_original.' : '.($depot->message ?: 'lu.');
    } catch (DepotEnDoubleDeRapport $e) {
        $this->erreurDepot = $e->getMessage();
    }

    $this->fichier = null;
};

$saisir = function () {
    abort_unless($this->peutDeposer, 403);
    $this->messageDepot = $this->erreurDepot = '';
    $d = $this->validate([
        'pieceNature' => ['required', 'in:impot,cnps,salaire,recap,autre'], 'pieceStatut' => ['required', 'in:du,paye,info'],
        'pieceDate' => ['nullable', 'date'], 'pieceLibelle' => ['required', 'string', 'max:200'], 'pieceTiers' => ['nullable', 'string', 'max:200'],
        'pieceReference' => ['nullable', 'string', 'max:80'], 'pieceMontant' => ['required', 'numeric', 'min:1'],
    ]);

    $bilan = DepotDeRapport::saisir([
        'nature' => $d['pieceNature'], 'statut' => $d['pieceNature'] === 'recap' ? 'info' : $d['pieceStatut'], 'date' => $d['pieceDate'] ?: null,
        'libelle' => $d['pieceLibelle'], 'tiers' => $d['pieceTiers'] ?: null, 'reference' => $d['pieceReference'] ?: null,
        'montant' => (int) round((float) $d['pieceMontant']),
    ], auth()->user(), $this->exerciceChoisi);

    if ($bilan['doublons'] > 0) {
        $this->erreurDepot = 'Cette pièce existe déjà (même nature, statut, référence et montant) : rien n\'est recréé.';

        return;
    }

    $this->messageDepot = 'Pièce enregistrée'.($bilan['dejaDansLApplication'] > 0 ? ', déjà comptée par la caisse ou la banque : gardée, non recomptée.' : '.');
    $this->reset('pieceLibelle', 'pieceTiers', 'pieceReference', 'pieceMontant');
};

$lireParIA = function (int $depotId) {
    abort_unless($this->peutDeposer, 403);
    $this->erreurDepot = '';
    $depot = DepotRapport::query()->findOrFail($depotId);
    $mime = match (strtolower(pathinfo($depot->nom_original, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };
    $pieces = AssistantDuRapport::lireUneImage(\Illuminate\Support\Facades\Storage::disk('local')->path($depot->chemin), $mime);

    if ($pieces === null || $pieces === []) {
        $this->erreurDepot = 'L\'assistant n\'a rien pu lire de cette image : saisissez les montants à la main.';

        return;
    }

    $this->depotLu = $depot->id;
    $this->propositions = $pieces;
};

$confirmerLesPropositions = function () {
    abort_unless($this->peutDeposer, 403);
    $depot = $this->depotLu ? DepotRapport::query()->find($this->depotLu) : null;
    $nouvelles = $doublons = 0;

    foreach ($this->propositions as $p) {
        $b = DepotDeRapport::saisir($p, auth()->user(), $this->exerciceChoisi, $depot);
        $nouvelles += $b['nouvelles'];
        $doublons += $b['doublons'];
    }

    $this->messageDepot = "Lecture confirmée : {$nouvelles} pièce(s) enregistrée(s), {$doublons} déjà connue(s).";
    $this->propositions = [];
    $this->depotLu = null;
};

$retirerLesPropositions = function () {
    $this->propositions = [];
    $this->depotLu = null;
};

$agent = function () {
    return new AgentDuRapport(auth()->user(), $this->exerciceChoisi);
};

$analyserParIA = function () {
    abort_unless($this->peutIa, 403);
    $this->erreurChat = $this->infoIa = '';
    set_time_limit(240);

    try {
        $this->agent()->analyserLeRapport(trim($this->consigneAnalyse));
        $this->infoIa = 'Analyse globale rédigée par l\'assistant.';
    } catch (IAIndisponible $e) {
        $this->erreurChat = $e->getMessage();
    }
};

// « Revenir à l'état » : on retire les commentaires et l'analyse de l'IA ; le rapport reprend celui du système.
$revenirALEtat = function () {
    abort_unless($this->peutIa, 403);
    $retirees = AgentDuRapport::revenirALEtat($this->exerciceChoisi);
    $this->commentaireIa = $this->erreurIa = $this->consigneAnalyse = $this->erreurChat = '';
    $this->infoIa = $retirees > 0
        ? 'L\'analyse et les commentaires de l\'IA sont retirés : le rapport est revenu à l\'analyse du système.'
        : 'Rien à retirer : le rapport affiche déjà l\'analyse du système.';
};

$envoyerChat = function () {
    abort_unless($this->peutIa, 403);
    $this->erreurChat = $this->infoIa = '';
    $this->validate(['messageChat' => ['required', 'string', 'max:4000'], 'fichierChat' => ['nullable', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt,pdf,jpg,jpeg,png,webp']]);
    set_time_limit(240);

    $message = trim($this->messageChat);

    // Le chat est la seconde porte du dépôt : même règle, même refus des doublons.
    if ($this->fichierChat) {
        if (HabilitationsDActions::accorde(auth()->user(), 'rapport', 'deposer')) {
            try {
                $depot = DepotDeRapport::deposer($this->fichierChat, auth()->user(), null, $this->exerciceChoisi);
                $message .= "\n\n[Fichier déposé par l'utilisateur : {$depot->nom_original}, ".($depot->message ?: 'rangé').'. Les pièces lues sont visibles avec l\'outil pieces_deposees.]';
            } catch (DepotEnDoubleDeRapport $e) {
                $message .= "\n\n[Fichier déjà déposé auparavant : ".$e->getMessage().']';
            }
        } else {
            $message .= "\n\n[Un fichier a été joint mais ce compte n'a pas l'habilitation de dépôt : il n'a pas été déposé.]";
        }
    }

    $this->agent()->repondre($message);
    $this->messageChat = '';
    $this->fichierChat = null;
};

$nouvelleConversation = function () {
    abort_unless($this->peutIa, 403);
    $this->agent()->nouvelleConversation();
    $this->erreurChat = $this->infoIa = '';
};

?>

<div class="rp">
<style>
.rp .rp-onglets{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:10px 0 16px}
.rp .rp-onglet{padding:9px 16px;font-weight:800;font-size:13px;border:1px solid #D4D1C7;background:#fff;color:#191B20;border-radius:6px;cursor:pointer;letter-spacing:.2px;transition:background .15s,border-color .15s}
.rp .rp-onglet:hover{background:#F4F3EF;border-color:#191B20}
.rp .rp-onglet.actif{background:#C8102E;border-color:#C8102E;color:#fff}
.rp .rp-annee{margin-left:auto;padding:8px 12px;border:1px solid #D4D1C7;border-radius:6px;background:#fff;font-weight:700}
.rp .rp-titre{background:#191B20;color:#fff;padding:15px 20px;border-radius:8px 8px 0 0;border-bottom:4px solid #C8102E;font-weight:800;font-size:19px;text-transform:uppercase;letter-spacing:.5px}
.rp .rp-section{background:#C8102E;color:#fff;font-weight:800;padding:8px 14px;margin:20px 0 10px;border-radius:4px;font-size:13px;letter-spacing:.3px}
.rp .rp-grille{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.rp .rp-deux{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));align-items:start}
.rp .rp-kpi{background:#fff;border:1px solid #E2E0D8;border-left:6px solid #C8102E;padding:13px 15px;border-radius:6px}
.rp .rp-kpi-v{font-size:27px;font-weight:800;line-height:1.1}
.rp .rp-kpi-l{font-size:12px;font-weight:800;color:#191B20;margin-top:5px;text-transform:uppercase;letter-spacing:.3px}
.rp .rp-kpi-s{font-size:11px;color:#6B6F78;margin-top:2px}
.rp .rp-boite{overflow:auto;border-radius:6px;border:1px solid #E2E0D8;background:#fff}
.rp .rp-table{width:100%;border-collapse:collapse;font-size:13px}
.rp .rp-table th{background:#191B20;color:#fff;font-weight:800;padding:9px 10px;border:1px solid #191B20;text-align:center;white-space:nowrap}
.rp .rp-table td{padding:7px 10px;border:1px solid #E2E0D8}
.rp .rp-table tbody tr:nth-child(even) td{background:#F4F3EF}
.rp .rp-table .d{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rp .rp-table .c{text-align:center}
.rp .rp-table tr.rp-total td{background:#EDEBE4 !important;font-weight:800}
.rp .rp-table tr.rp-alerte td{color:#C8102E;font-weight:800}
.rp .rp-table .rp-rouge{color:#C8102E;font-weight:800}
.rp .rp-notes{background:#fff;border:1px solid #E2E0D8;border-radius:6px;padding:8px 14px;margin-top:10px}
.rp .rp-note{border-left:5px solid #2563EB;padding:4px 12px;margin:6px 0;font-size:13px}
.rp .rp-note.alerte{border-left-color:#D97706}.rp .rp-note.bon{border-left-color:#0E9F6E}
.rp .rp-aide{color:#555;font-style:italic;font-size:12px;margin:8px 2px}
.rp .rp-couv{background:#191B20;color:#fff;border-left:12px solid #C8102E;border-radius:8px;padding:30px 34px}
.rp .rp-couv .rp-logo{display:inline-block;background:#fff;padding:12px 18px;border-radius:10px}
.rp .rp-couv h2{font-size:40px;font-weight:800;margin:20px 0 4px;letter-spacing:1px;color:#fff}
.rp .rp-couv .rp-ss{color:#E2E0D8;font-size:16px}
.rp .rp-couv .rp-per{font-weight:800;margin-top:10px;font-size:14px}
.rp .rp-couv-kpi{background:#262A31;border:1px solid #3A3D45;border-left:6px solid #C8102E;padding:14px 16px;border-radius:6px}
.rp .rp-couv-kpi b{display:block;font-size:26px;color:#fff}.rp .rp-couv-kpi span{color:#C9CBD0;font-size:12px}
.rp .rp-champ{padding:8px 10px;border:1px solid #D4D1C7;border-radius:6px;background:#fff}
.rp .rp-btn{padding:9px 16px;font-weight:800;font-size:13px;border:1px solid #C8102E;background:#C8102E;color:#fff;border-radius:6px;cursor:pointer}
.rp .rp-btn.neutre{background:#fff;color:#191B20;border-color:#D4D1C7}
.rp .rp-chat{background:#fff;border:1px solid #E2E0D8;border-radius:6px;padding:12px;max-height:520px;overflow:auto}
.rp .rp-msg{max-width:88%;padding:9px 13px;border-radius:10px;margin:8px 0;font-size:13px;line-height:1.5}
.rp .rp-msg.user{margin-left:auto;background:#FDECEE;border:1px solid #F3C5CB;white-space:pre-wrap}
.rp .rp-msg.assistant{background:#F4F3EF;border:1px solid #E2E0D8}
.rp .rp-msg table{border-collapse:collapse;margin:6px 0}.rp .rp-msg th{background:#191B20;color:#fff;padding:4px 8px}.rp .rp-msg td{border:1px solid #E2E0D8;padding:4px 8px}
.rp .rp-prod{background:var(--fond,#F4F3EF);border-left:8px solid var(--acc,#C8102E);border-radius:6px;padding:16px 22px}
.rp .rp-prod h1,.rp .rp-prod h2,.rp .rp-prod h3{color:var(--acc,#C8102E);margin:14px 0 6px}
.rp .rp-prod table{border-collapse:collapse;width:100%;margin:8px 0}.rp .rp-prod th{background:var(--acc,#C8102E);color:#fff;padding:6px 10px}.rp .rp-prod td{border:1px solid #D4D1C7;padding:6px 10px;background:#fff}
.rp .rp-prod.colonnes .rp-prod-corps{column-count:2;column-gap:32px}
.rp .rp-prod.sobre{border-left:0;background:#fff;border:1px solid #E2E0D8}
.rp .rp-prod.sobre h1,.rp .rp-prod.sobre h2,.rp .rp-prod.sobre h3{color:#191B20}
.rp .rp-puce{display:inline-block;background:#191B20;color:#fff;font-size:11px;font-weight:800;padding:3px 9px;border-radius:12px;margin-bottom:8px}
.rp .rp-puce.systeme{background:#0E9F6E}
/* 24/11 : « il n'est pas bien visible, on oublie de défiler » : la page se resserre. Les onglets
   restent en haut pendant le défilement, un sommaire mène à chaque section, les cartes et les
   bandeaux perdent leur épaisseur, et un tableau étroit ne s'étire plus sur toute la largeur. */
.rp .rp-onglets{position:sticky;top:0;z-index:20;background:var(--th-fond,#F4F3EF);padding:8px 0;margin:6px 0 10px;box-shadow:0 6px 8px -8px rgba(0,0,0,.25)}
.rp .rp-onglet{padding:7px 12px;font-size:12.5px}
.rp .rp-titre{padding:10px 16px;font-size:15.5px;border-bottom-width:3px}
.rp .rp-section{margin:14px 0 8px;padding:6px 12px;font-size:12.5px;scroll-margin-top:110px}
.rp .rp-kpi{padding:9px 12px}.rp .rp-kpi-v{font-size:21px}.rp .rp-kpi-l{font-size:11px}
.rp .rp-grille{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px}
.rp .rp-table{font-size:12.5px}.rp .rp-table th{padding:6px 8px}.rp .rp-table td{padding:4px 8px}
.rp .rp-boite{width:fit-content;max-width:100%;min-width:min(100%,560px)}
.rp .rp-deux .rp-boite{width:auto;min-width:0}
.rp .rp-notes{padding:4px 12px}.rp .rp-note{margin:3px 0;padding:2px 10px;font-size:12.5px}
.rp .rp-sommaire{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 0;font-size:12px}
.rp .rp-sommaire a{padding:3px 9px;border:1px solid #D4D1C7;border-radius:12px;background:#fff;color:#191B20;text-decoration:none;font-weight:700}
.rp .rp-sommaire a:hover{border-color:#C8102E;color:#C8102E}
.rp .rp-haut{position:fixed;right:18px;bottom:18px;z-index:30;padding:8px 12px;border-radius:20px;background:#191B20;color:#fff;font-weight:800;font-size:12px;border:0;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25)}
@media print{.rp .rp-onglets,.rp .rp-sommaire,.rp .rp-haut{display:none}}
/* `overflow-x: hidden` (app.css) fait du corps un conteneur de défilement : rien ne s'y colle.
   `clip` garde la règle (rien ne déborde) sans casser `position: sticky`. */
@supports (overflow-x: clip){html,body{overflow-x:clip}}
.rp .rp-flux{columns:2 560px;column-gap:16px}
.rp .rp-flux > *, .rp .rp-flux .rp-boite, .rp .rp-flux .rp-notes{break-inside:avoid}
.rp .rp-flux .rp-section{break-after:avoid;margin-top:0;display:block}
.rp .rp-flux .rp-section:not(:first-child){margin-top:4px}
.rp .rp-flux .rp-grille, .rp .rp-flux .rp-deux, .rp .rp-flux .rp-notes{column-span:all;margin-bottom:12px}
.rp .rp-flux .rp-boite{width:auto;min-width:0;margin-bottom:12px}
.rp .rp-flux .rp-section:has(+ .rp-grille), .rp .rp-flux .rp-section:has(+ .rp-deux){column-span:all}
/* Un tableau de treize colonnes ne tient pas dans une demi-largeur : il défilait de côté dans sa colonne. */
.rp .rp-flux .rp-large{column-span:all}
/* 25/11 : la période et le lieu, sous les onglets, sur toute la largeur de la barre. */
.rp .rp-filtres{display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex-basis:100%;font-size:12.5px}
.rp .rp-filtres .rp-champ{padding:6px 8px;width:auto}
</style>

<x-titre-ecran titre="Rapport financier"
    sous-titre="{{ ucfirst($this->donnees['periode']->libelle()) }}{{ $this->lieuChoisi !== '' ? ', '.$this->lieuChoisi : '' }}. Le rapport se met à jour avec les données de l'application." />

@php
    $d = $this->donnees;
    $cm = $this->commentaires;
    // Un montant s'écrit tel qu'il est, centimes compris quand il en a (règle du 13/10, piège 17).
    $f = fn ($v) => montant_nombre($v);
    // Un pourcentage s'arrondit au dixième (ce n'est pas de l'argent) ; sans base, il n'est pas disponible.
    $pct = fn ($v) => $v === null ? 'n.d.' : number_format($v * 100, 1, ',', ' ').' %';
    $per = $d['periode'];
    $periode = $per->libelle().($this->lieuChoisi !== '' ? ', '.$this->lieuChoisi : '');
    // Une tranche (mois, semaine) dit si elle est en cours ou coupée par les bornes de la période.
    $etatTranche = fn (array $t) => $t['fin']->gte(today()) ? ' (en cours)' : ((($t['complet'] ?? true) && ! ($t['partielle'] ?? false)) ? '' : ' (partielle)');
    $kpi = fn (string $libelle, string $valeur, ?string $sous = null, string $couleur = '#C8102E') =>
        '<div class="rp-kpi" style="border-left-color:'.$couleur.'"><div class="rp-kpi-v" style="color:'.$couleur.'">'.e($valeur).'</div><div class="rp-kpi-l">'.e($libelle).'</div>'.($sous ? '<div class="rp-kpi-s">'.e($sous).'</div>' : '').'</div>';
    $remarques = $cm[$this->vue] ?? [];
@endphp

{{-- Les boutons alignés en haut : les feuilles de l'Excel. Seul l'onglet ouvert est rouge. --}}
<div class="rp-onglets">
    @foreach ($this->onglets as $cle => $libelle)
        <button type="button" wire:click="aller('{{ $cle }}')" class="rp-onglet {{ $this->vue === $cle ? 'actif' : '' }}">{{ $libelle }}</button>
    @endforeach
    @if ($this->periode === 'exercice')
        <select wire:model.live="exercice" class="rp-annee" aria-label="Exercice">
            @foreach ($this->annees as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
        </select>
    @endif
    {{-- 25/11 : la période et le lieu que lisent tous les onglets chiffrés. --}}
    @if (! in_array($this->vue, ['depot', 'ia'], true))
        <div class="rp-filtres">
            <select wire:model.live="periode" class="rp-champ" aria-label="Période">
                @foreach (PeriodeDuRapport::MODES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select>
            @if ($this->periode === 'semaine')
                <label>Un jour de la semaine <input type="date" wire:model.live="jour" class="rp-champ"></label>
                <span class="rp-aide" style="margin:0;">{{ ucfirst($d['periode']->libelle()) }}</span>
            @elseif ($this->periode === 'mois')
                <input type="month" wire:model.live="mois" class="rp-champ" aria-label="Mois">
            @elseif ($this->periode === 'dates')
                <label>Du <input type="date" wire:model.live="du" class="rp-champ"></label>
                <label>au <input type="date" wire:model.live="au" class="rp-champ"></label>
            @endif
            @if ($this->villes->count() > 1)
                <select wire:model.live="ville" class="rp-champ" aria-label="Ville">
                    <option value="">Toutes les villes</option>
                    @foreach ($this->villes as $v)<option value="{{ $v->id }}">{{ $v->nom }}</option>@endforeach
                </select>
            @endif
            @if ($this->ateliers->count() > 1)
                <select wire:model.live="atelier" class="rp-champ" aria-label="Atelier">
                    <option value="">Tous les ateliers</option>
                    @foreach ($this->ateliers as $s)<option value="{{ $s->id }}">{{ $s->nom }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="activite" class="rp-champ" aria-label="Activité">
                <option value="">Toutes activités</option>
                @foreach (\Modules\Noyau\Rapport\Services\RapportFinancier::ACTIVITES as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
            </select>
        </div>
    @endif
    {{-- Rempli par le navigateur (wire:ignore : Livewire ne le vide pas, piège 7). --}}
    <nav class="rp-sommaire" wire:ignore data-rp-sommaire aria-label="Sections de l'onglet" style="flex-basis:100%"></nav>
</div>
<button type="button" class="rp-haut" onclick="window.scrollTo({top:0,behavior:'smooth'})" aria-label="Revenir en haut">↑ Haut</button>
<script>
    (function () {
        function construire() {
            var nav = document.querySelector('[data-rp-sommaire]');
            if (!nav) { return; }
            nav.innerHTML = '';
            document.querySelectorAll('.rp .rp-section').forEach(function (bandeau, i) {
                bandeau.id = 'rp-s' + i;
                var lien = document.createElement('a');
                lien.href = '#rp-s' + i;
                lien.textContent = bandeau.textContent.trim().replace(/\s+/g, ' ').slice(0, 48);
                nav.appendChild(lien);
            });
            nav.style.display = nav.children.length > 1 ? 'flex' : 'none';
        }
        document.addEventListener('DOMContentLoaded', construire);
        document.addEventListener('livewire:navigated', construire);
        document.addEventListener('livewire:init', function () {
            window.Livewire.hook('commit', function (c) { c.succeed(function () { setTimeout(construire, 0); }); });
        });
        setTimeout(construire, 0);
    })();
</script>

@if ($this->vue !== 'presentation' && $this->vue !== 'depot')
    <div class="rp-titre">
        @switch($this->vue)
            @case('ca') Chiffre d'affaires : {{ $periode }} @break
            @case('recouvrement') Recouvrement : @if ($per->estLExercice()) état des impayés au {{ now()->format('d/m/Y') }}{{ $this->lieuChoisi !== '' ? ', '.$this->lieuChoisi : '' }} @else impayés des factures émises, {{ $periode }} @endif @break
            @case('charges') Charges : {{ $periode }} @break
            @case('conclusion') Conclusion : trésorerie, engagements et recouvrement à réaliser, {{ $periode }} @break
            @case('commerciaux') Commerciaux : récapitulatif, {{ $periode }} @break
            @case('precedent') Année précédente : @if ($per->estLExercice()) grandes lignes de {{ $d['exercice'] - 1 }} @else la même période un an plus tôt @endif @break
            @case('ia') Assistant IA : analyse globale et conversation @break
        @endswitch
    </div>
    @if ($remarques !== [])
        <div class="rp-notes">
            @foreach ($remarques as $r)<div class="rp-note {{ $r['niveau'] }}">{{ $r['texte'] }}</div>@endforeach
        </div>
    @endif
@endif

{{-- ═══════════════════ Présentation (la page de garde du PowerPoint) ═══════════════════ --}}
@if ($this->vue === 'presentation')
    <div class="rp-couv">
        @if ($this->logo)<div class="rp-logo"><img src="{{ $this->logo }}" alt="Logo de l'entreprise" style="height:76px;"></div>@endif
        <h2>RAPPORT FINANCIER</h2>
        <div class="rp-ss">Chiffre d'affaires · Recouvrement · Charges · Conclusion</div>
        <div class="rp-per">{{ auth()->user()->entreprise?->nom }} &nbsp;|&nbsp; {{ ucfirst($periode) }}</div>
        {{-- 25/11 : « écrire correctement les montants ». Les cartes disaient « 1 701,2 M » ; elles
             écrivent le montant entier, exact, comme les tableaux. --}}
        <div class="rp-grille" style="margin-top:26px;">
            <div class="rp-couv-kpi" style="border-left-color:#C8102E"><b>{{ $f($d['ca']['total']) }} F</b><span>Chiffre d'affaires</span></div>
            <div class="rp-couv-kpi" style="border-left-color:#D97706"><b>{{ $f($d['recouvrement']['reste']) }} F</b><span>Impayés à recouvrer</span></div>
            <div class="rp-couv-kpi" style="border-left-color:#2563EB"><b>{{ $f($d['charges']['totalCompte']) }} F</b><span>Charges comptées</span></div>
            <div class="rp-couv-kpi" style="border-left-color:#0E9F6E"><b>{{ $f($d['conclusion']['engagements']) }} F</b><span>Engagements à couvrir</span></div>
        </div>
    </div>

    <div class="rp-section">Chiffres clés (FCFA)</div>
    <div class="rp-boite"><table class="rp-table"><tbody>
        <tr><td><b>Chiffre d'affaires</b></td><td class="d rp-rouge">{{ $f($d['ca']['total']) }}</td></tr>
        <tr><td><b>Impayés clients à recouvrer</b></td><td class="d rp-rouge">{{ $f($d['recouvrement']['reste']) }}</td></tr>
        <tr><td><b>Charges comptées</b></td><td class="d rp-rouge">{{ $f($d['charges']['totalCompte']) }}</td></tr>
        <tr><td><b>Engagements à couvrir</b> (impôts, salaires, fournisseurs, CNPS)</td><td class="d rp-rouge">{{ $f($d['conclusion']['engagements']) }}</td></tr>
        <tr><td><b>Solde de fonctionnement de la période</b></td><td class="d rp-rouge">{{ $f($d['tresorerie']['net']) }}</td></tr>
    </tbody></table></div>

    <div class="rp-section">Commentaires de l'assistant</div>
    <div class="rp-notes">
        @foreach (collect($cm)->flatten(1)->take(8) as $r)<div class="rp-note {{ $r['niveau'] }}">{{ $r['texte'] }}</div>@endforeach
        @if ($this->iaDispo)
            <div style="margin-top:10px;">
                <button type="button" wire:click="commenterParIA" wire:loading.attr="disabled" class="rp-btn">
                    <span wire:loading.remove wire:target="commenterParIA">Commenter avec l'IA</span><span wire:loading wire:target="commenterParIA">Lecture en cours…</span>
                </button>
                <div class="rp-aide">Seuls des chiffres agrégés sont transmis (aucun nom de client, de fournisseur ou de salarié), et seulement à ce clic.</div>
            </div>
        @endif
        @if ($this->commentaireIa !== '')
            <div style="margin-top:10px; padding:10px 12px; background:#F4F3EF; border-radius:6px;">
                <div style="font-size:11px; font-weight:800; color:#6B6F78; text-transform:uppercase;">Rédigé par l'assistant IA</div>
                <div style="white-space:pre-line;">{{ $this->commentaireIa }}</div>
            </div>
        @endif
        @if ($this->erreurIa !== '')<div style="color:#C8102E; margin-top:8px;">{{ $this->erreurIa }}</div>@endif
    </div>

    <div class="rp-section">Contenu du rapport</div>
    <div class="rp-boite"><table class="rp-table"><tbody>
        <tr><td><b>Chiffre d'affaires</b></td><td>CA global, par ville, par activité, par ville et activité, par mois, par semaine ; factures sans code éditeur</td></tr>
        <tr><td><b>Recouvrement</b></td><td>Impayés : dix premiers clients, autres clients, ancienneté</td></tr>
        <tr><td><b>Charges</b></td><td>Fournisseurs, autres fournisseurs, salaires, impôts, CNPS, autres ; classement des dépenses ; charges par semaine et par mois</td></tr>
        <tr><td><b>Conclusion</b></td><td>Trésorerie, engagements, délais, recouvrement à réaliser, synthèse</td></tr>
        <tr><td><b>Commerciaux</b></td><td>Récapitulatif par commercial : prospections, devis, factures, réalisé, objectif, encours</td></tr>
        <tr><td><b>Année précédente</b></td><td>Les grandes lignes de l'exercice précédent</td></tr>
        <tr><td><b>Dépôt de fichiers</b></td><td>Impôts, CNPS, récapitulatifs, sans doublon</td></tr>
    </tbody></table></div>
@endif

{{-- ═══════════════════ Chiffre d'affaires ═══════════════════ --}}
{{-- Les onglets chiffrés coulent sur deux colonnes (24/11) : une section ne se coupe pas. --}}
<div class="rp-flux">
@if ($this->vue === 'ca')
    @php $ca = $d['ca']; @endphp
    <div class="rp-section">A. Chiffre d'affaires de l'entreprise (global)</div>
    <div class="rp-grille">
        {!! $kpi("CA de l'entreprise", $f($ca['total']).' F', $ca['nombre'].' factures') !!}
        {!! $kpi('CA moyen mensuel', $f($ca['moyenneMensuelle']).' F', 'Mois pleins uniquement', '#191B20') !!}
        @if ($ca['nonRattache']['nombre'] > 0){!! $kpi('Sans code éditeur', $f($ca['nonRattache']['montant']).' F', $ca['nonRattache']['nombre'].' factures : la ville est inconnue', '#D97706') !!}@endif
    </div>

    <div class="rp-section">B. CA par ville</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Ville</th><th>Nb factures</th><th>CA (FCFA)</th><th>% du CA</th></tr></thead><tbody>
        @foreach ($ca['parVille'] as $l)
            <tr><td>{{ $l['ville'] }}</td><td class="d">{{ $f($l['nombre']) }}</td><td class="d">{{ $f($l['montant']) }}</td><td class="d">{{ $ca['total'] > 0 ? $pct($l['montant'] / $ca['total']) : 'n.d.' }}</td></tr>
        @endforeach
        @if ($ca['nonRattache']['nombre'] > 0)
            <tr class="rp-alerte"><td>Non rattaché (sans code éditeur)</td><td class="d">{{ $f($ca['nonRattache']['nombre']) }}</td><td class="d">{{ $f($ca['nonRattache']['montant']) }}</td><td class="d">{{ $ca['total'] > 0 ? $pct($ca['nonRattache']['montant'] / $ca['total']) : 'n.d.' }}</td></tr>
        @endif
        <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($ca['nombre']) }}</td><td class="d">{{ $f($ca['total']) }}</td><td class="d">{{ $ca['total'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
    </tbody></table></div>
    @if ($ca['nonRattache']['nombre'] > 0)
        <div class="rp-aide">Les factures « Non rattaché » n'ont plus de code éditeur dans leur fiche de réception : le code employé, qui donne la ville, a sauté. Elles sont listées en section G.</div>
    @endif

    <div class="rp-section">C. CA par activité</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Activité</th><th>Nb factures</th><th>CA (FCFA)</th><th>% du CA</th></tr></thead><tbody>
        @foreach ($ca['parActivite'] as $l)
            <tr><td>{{ $l['activite'] }}</td><td class="d">{{ $f($l['nombre']) }}</td><td class="d">{{ $f($l['montant']) }}</td><td class="d">{{ $ca['total'] > 0 ? $pct($l['montant'] / $ca['total']) : 'n.d.' }}</td></tr>
        @endforeach
        <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($ca['nombre']) }}</td><td class="d">{{ $f($ca['total']) }}</td><td class="d">{{ $ca['total'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
    </tbody></table></div>

    <div class="rp-section">D. CA par ville et par activité (FCFA)</div>
    @php $acts = collect($ca['parActivite'])->pluck('activite'); @endphp
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Ville</th>@foreach ($acts as $a)<th>{{ $a }}</th>@endforeach<th>Total ville</th><th>% du CA</th></tr></thead><tbody>
        @foreach ($ca['matrice'] as $ville => $parAct)
            @php $totalVille = array_sum($parAct); @endphp
            <tr><td>{{ $ville }}</td>@foreach ($acts as $a)<td class="d">{{ $f($parAct[$a] ?? 0) }}</td>@endforeach<td class="d"><b>{{ $f($totalVille) }}</b></td><td class="d">{{ $ca['total'] > 0 ? $pct($totalVille / $ca['total']) : 'n.d.' }}</td></tr>
        @endforeach
        <tr class="rp-total"><td>TOTAL</td>@foreach ($acts as $a)<td class="d">{{ $f(collect($ca['matrice'])->sum(fn ($x) => $x[$a] ?? 0)) }}</td>@endforeach<td class="d">{{ $f($ca['total']) }}</td><td class="d">{{ $ca['total'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
    </tbody></table></div>

    <div class="rp-section">E. CA par mois</div>
    @php $cumul = 0; @endphp
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Mois</th><th>Nb factures</th><th>CA (FCFA)</th><th>% du CA</th><th>CA cumulé (FCFA)</th></tr></thead><tbody>
        @foreach ($ca['parMois'] as $l)
            @php $cumul += $l['montant']; @endphp
            <tr><td>{{ $l['libelle'] }}{{ $etatTranche($l) }}</td><td class="d">{{ $f($l['nombre']) }}</td><td class="d">{{ $f($l['montant']) }}</td><td class="d">{{ $ca['total'] > 0 ? $pct($l['montant'] / $ca['total']) : 'n.d.' }}</td><td class="d">{{ $f($cumul) }}</td></tr>
        @endforeach
        <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($ca['nombre']) }}</td><td class="d">{{ $f($ca['total']) }}</td><td class="d">{{ $ca['total'] > 0 ? '100,0 %' : 'n.d.' }}</td><td></td></tr>
    </tbody></table></div>

    {{-- 25/11 : « le CA par semaine », du jeudi au mercredi comme l'Excel de trésorerie. --}}
    <div class="rp-section">F. CA par semaine (du jeudi au mercredi)</div>
    @php $cumul = 0; @endphp
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Semaine</th><th>Nb factures</th><th>CA (FCFA)</th><th>% du CA</th><th>CA cumulé (FCFA)</th></tr></thead><tbody>
        @foreach ($ca['parSemaine'] as $l)
            @php $cumul += $l['montant']; @endphp
            <tr><td>{{ ucfirst($l['libelle']) }}{{ $etatTranche($l) }}</td><td class="d">{{ $f($l['nombre']) }}</td><td class="d">{{ $f($l['montant']) }}</td><td class="d">{{ $ca['total'] > 0 ? $pct($l['montant'] / $ca['total']) : 'n.d.' }}</td><td class="d">{{ $f($cumul) }}</td></tr>
        @endforeach
        <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($ca['nombre']) }}</td><td class="d">{{ $f($ca['total']) }}</td><td class="d">{{ $ca['total'] > 0 ? '100,0 %' : 'n.d.' }}</td><td></td></tr>
    </tbody></table></div>

    @if ($ca['nonRattacheLignes'] !== [])
        <div class="rp-section">G. Factures sans code éditeur (ville impossible à déterminer)</div>
        <div class="rp-boite"><table class="rp-table"><thead><tr><th>N° facture</th><th>Date</th><th>Client</th><th>Activité</th><th>Montant (FCFA)</th><th>Fiche de réception</th></tr></thead><tbody>
            @foreach ($ca['nonRattacheLignes'] as $l)
                <tr><td>{{ $l['numero'] }}</td><td class="c">{{ $l['date'] ? \Illuminate\Support\Carbon::parse($l['date'])->format('d/m/Y') : '' }}</td><td>{{ $l['client'] }}</td><td>{{ $l['activite'] }}</td><td class="d">{{ $f($l['montant']) }}</td><td>{{ $l['fiche'] }}</td></tr>
            @endforeach
            <tr class="rp-total"><td colspan="4">TOTAL</td><td class="d">{{ $f($ca['nonRattache']['montant']) }}</td><td></td></tr>
        </tbody></table></div>
    @endif
@endif

{{-- ═══════════════════ Recouvrement ═══════════════════ --}}
@if ($this->vue === 'recouvrement')
    @php $rec = $d['recouvrement']; @endphp
    <div class="rp-section">A. Synthèse</div>
    <div class="rp-grille">
        {!! $kpi('Total des impayés', $f($rec['reste']).' F', $rec['ouvertes'].' factures ouvertes · '.$rec['nombreClients'].' clients') !!}
        {!! $kpi('Dont exercice '.$d['exercice'], $f($rec['resteExercice']).' F', 'Le reste vient des exercices antérieurs', '#D97706') !!}
        {!! $kpi('Dix premiers débiteurs', $rec['reste'] > 0 ? $pct($rec['top10']->sum('reste') / $rec['reste']) : 'n.d.', 'de l\'encours total', '#191B20') !!}
    </div>

    <div class="rp-section">B. Les 10 premiers clients débiteurs (du plus grand montant au plus petit)</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Rang</th><th>Client</th><th>Nb factures</th><th>Reste dû (FCFA)</th><th>Dont {{ $d['exercice'] }} (FCFA)</th><th>Facture la plus ancienne</th><th>Dernier règlement</th><th>% du total</th></tr></thead><tbody>
        @foreach ($rec['top10'] as $i => $t)
            <tr><td class="c">{{ $i + 1 }}</td><td><b>{{ $t['client'] }}</b></td><td class="d">{{ $t['nb'] }}</td><td class="d"><b>{{ $f($t['reste']) }}</b></td><td class="d">{{ $f($t['resteExercice']) }}</td>
                <td class="c">{{ $t['plusAncienne']?->format('d/m/Y') ?? '' }}</td>
                <td class="c">@if ($t['dernierReglement']){{ $t['dernierReglement']->format('d/m/Y') }}@else<span class="rp-rouge">Aucun règlement</span>@endif</td>
                <td class="d">{{ $rec['reste'] > 0 ? $pct($t['reste'] / $rec['reste']) : 'n.d.' }}</td></tr>
        @endforeach
        <tr class="rp-total"><td></td><td>Sous-total des 10 premiers</td><td class="d">{{ $rec['top10']->sum('nb') }}</td><td class="d">{{ $f($rec['top10']->sum('reste')) }}</td><td class="d">{{ $f($rec['top10']->sum('resteExercice')) }}</td><td></td><td></td><td class="d">{{ $rec['reste'] > 0 ? $pct($rec['top10']->sum('reste') / $rec['reste']) : 'n.d.' }}</td></tr>
        <tr><td></td><td><b>AUTRES CLIENTS ({{ $rec['autres']['nombreClients'] }} clients)</b></td><td></td><td class="d"><b>{{ $f($rec['autres']['reste']) }}</b></td><td class="d">{{ $f($rec['autres']['resteExercice']) }}</td><td></td><td></td><td class="d">{{ $rec['reste'] > 0 ? $pct($rec['autres']['reste'] / $rec['reste']) : 'n.d.' }}</td></tr>
        <tr class="rp-total"><td></td><td class="rp-rouge">TOTAL IMPAYÉS</td><td></td><td class="d rp-rouge">{{ $f($rec['reste']) }}</td><td class="d">{{ $f($rec['resteExercice']) }}</td><td></td><td></td><td class="d">{{ $rec['reste'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
    </tbody></table></div>
    <div class="rp-aide">Dernier règlement = dernier encaissement enregistré pour le client. « Aucun règlement » : aucun encaissement enregistré pour ce client.</div>

    <div class="rp-deux">
        <div>
            <div class="rp-section">C. Ancienneté des impayés (depuis la date de facture)</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Tranche</th><th>Reste dû (FCFA)</th><th>% du total</th></tr></thead><tbody>
                @foreach ($rec['anciennete'] as $tr => $m)<tr><td>{{ $tr }}</td><td class="d">{{ $f($m) }}</td><td class="d">{{ $rec['reste'] > 0 ? $pct($m / $rec['reste']) : 'n.d.' }}</td></tr>@endforeach
                <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($rec['reste']) }}</td><td class="d">{{ $rec['reste'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
            </tbody></table></div>
        </div>
        <div>
            <div class="rp-section">D. Impayés par exercice de facture</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Exercice</th><th>Reste dû (FCFA)</th><th>% du total</th></tr></thead><tbody>
                @foreach ($rec['parExercice'] as $an => $m)<tr><td class="c">{{ $an }}</td><td class="d">{{ $f($m) }}</td><td class="d">{{ $rec['reste'] > 0 ? $pct($m / $rec['reste']) : 'n.d.' }}</td></tr>@endforeach
                <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($rec['reste']) }}</td><td class="d">{{ $rec['reste'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
            </tbody></table></div>
        </div>
    </div>
@endif

{{-- ═══════════════════ Charges ═══════════════════ --}}
@if ($this->vue === 'charges')
    @php $ch = $d['charges']; $fo = $ch['fournisseurs']; @endphp
    <div class="rp-section">A. Chiffres clés</div>
    <div class="rp-grille">
        {!! $kpi('Charges comptées', $f($ch['totalCompte']).' F', 'Comme sur la page Charges') !!}
        {!! $kpi('Fournisseurs : reste à payer', $f($fo['reste']).' F', 'Facturé '.$f($fo['facture']).' · réglé '.$f($fo['regle']), '#D97706') !!}
        {!! $kpi('Impôts à régler', $f($ch['impots']['aRegler']).' F', 'Payé : '.$f($ch['impots']['paye']), '#191B20') !!}
        {!! $kpi('Salaires non réglés', $f($ch['salaires']['nonRegle']).' F', 'Payés : '.$f($ch['salaires']['paye']), '#2563EB') !!}
    </div>

    <div class="rp-section">B. Classement : où dépense-t-on le plus ?</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Rang</th><th>Poste</th><th>Dû / dépensé (FCFA)</th><th>% du total</th></tr></thead><tbody>
        @foreach ($ch['classement'] as $i => $l)
            <tr><td class="c">{{ $i + 1 }}</td><td>{{ $l['poste'] }}</td><td class="d">{{ $f($l['montant']) }}</td><td class="d">{{ $ch['totalRapport'] > 0 ? $pct($l['montant'] / $ch['totalRapport']) : 'n.d.' }}</td></tr>
        @endforeach
        <tr class="rp-total"><td></td><td>TOTAL DES CHARGES DE LA PÉRIODE</td><td class="d">{{ $f($ch['totalRapport']) }}</td><td class="d">{{ $ch['totalRapport'] > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
    </tbody></table></div>

    <div class="rp-section">C. Fournisseurs : facturé / réglé / reste à payer</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Famille</th><th>Facturé (FCFA)</th><th>Réglé (FCFA)</th><th>Reste à payer (FCFA)</th></tr></thead><tbody>
        @foreach (['pieces' => 'Pièces de rechange', 'consommables' => 'Consommables (peinture, lubrifiants)', 'autres' => 'Autres fournisseurs (frais généraux, loyer, CIE…)'] as $k => $lib)
            <tr><td>{{ $lib }}</td><td class="d">{{ $f($fo['familles'][$k]['facture']) }}</td><td class="d">{{ $f($fo['familles'][$k]['regle']) }}</td><td class="d">{{ $f($fo['familles'][$k]['reste']) }}</td></tr>
        @endforeach
        <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($fo['facture']) }}</td><td class="d">{{ $f($fo['regle']) }}</td><td class="d">{{ $f($fo['reste']) }}</td></tr>
    </tbody></table></div>
    @if ($fo['fne']['nombre'] > 0)
        <div class="rp-aide">Ces familles comprennent {{ $fo['fne']['nombre'] }} facture(s) FNE que le suivi ne porte pas encore ({{ $f($fo['fne']['facture']) }} F), rangées d'après la nature de l'achat : pièces {{ $f($fo['fne']['parFamille']['pieces']) }}, consommables {{ $f($fo['fne']['parFamille']['consommables']) }}, autres {{ $f($fo['fne']['parFamille']['autres']) }}.</div>
    @endif

    <div class="rp-section">D. Fournisseurs que l'on doit le plus</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Fournisseur</th><th>Facturé (FCFA)</th><th>Réglé (FCFA)</th><th>Reste à payer (FCFA)</th></tr></thead><tbody>
        @foreach ($fo['top'] as $t)<tr><td>{{ $t['fournisseur'] }}</td><td class="d">{{ $f($t['facture']) }}</td><td class="d">{{ $f($t['regle']) }}</td><td class="d"><b>{{ $f($t['reste']) }}</b></td></tr>@endforeach
    </tbody></table></div>

    <div class="rp-deux">
        <div>
            <div class="rp-section">E. Ancienneté des dettes fournisseurs</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Tranche</th><th>Reste (FCFA)</th><th>%</th></tr></thead><tbody>
                @php $totAge = array_sum($fo['anciennete']); @endphp
                @foreach ($fo['anciennete'] as $tr => $m)<tr><td>{{ $tr }}</td><td class="d">{{ $f($m) }}</td><td class="d">{{ $totAge > 0 ? $pct($m / $totAge) : 'n.d.' }}</td></tr>@endforeach
                <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f($totAge) }}</td><td class="d">{{ $totAge > 0 ? '100,0 %' : 'n.d.' }}</td></tr>
            </tbody></table></div>
        </div>
        <div>
            <div class="rp-section">F. Caisse administrative et saisies, par catégorie</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Catégorie</th><th>Nb</th><th>Montant (FCFA)</th></tr></thead><tbody>
                @foreach ($ch['categories'] as $l)<tr><td>{{ $l['categorie'] ?: 'Sans catégorie' }}</td><td class="d">{{ $l['nombre'] }}</td><td class="d">{{ $f($l['montant']) }}</td></tr>@endforeach
            </tbody></table></div>
        </div>
    </div>

    <div class="rp-section">G. Impôts et CNPS à payer (pièces déposées), {{ $per->libelle() }}</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Pièce</th><th>Date</th><th>Statut</th><th>Montant (FCFA)</th></tr></thead><tbody>
        @forelse ($ch['impots']['pieces']->concat($ch['cnps']['pieces']) as $p)
            <tr><td>{{ $p->libelle }}{{ $p->reference ? ' · '.$p->reference : '' }}</td><td class="c">{{ $p->date_piece?->format('d/m/Y') ?? 'Sans date' }}</td><td class="c {{ $p->statut === 'paye' ? '' : 'rp-rouge' }}">{{ $p->statut === 'paye' ? 'Payé' : 'À régler' }}</td><td class="d">{{ $f($p->montant) }}</td></tr>
        @empty
            <tr><td colspan="4" style="color:#6B6F78;">Aucune pièce sur la période : déposez l'avis des impôts ou l'appel CNPS dans « Dépôt de fichiers ».</td></tr>
        @endforelse
        <tr class="rp-total"><td colspan="3">À PAYER (impôts {{ $f($ch['impots']['aRegler']) }} F, CNPS {{ $f($ch['cnps']['aRegler']) }} F)</td><td class="d rp-rouge">{{ $f($ch['impots']['aRegler'] + $ch['cnps']['aRegler']) }}</td></tr>
    </tbody></table></div>
    <div class="rp-aide">Les impôts et la CNPS sont ceux de l'entreprise : une pièce ne porte ni ville ni activité.@if ($ch['piecesSansDate'] > 0) {{ $ch['piecesSansDate'] }} pièce(s) de l'exercice sans date ne se placent dans aucune période : elles restent sur l'exercice entier.@endif</div>

    {{-- 25/11 : « les charges par semaine et par mois ». Les charges comptées (celles de la carte A),
         rangées à leur date : la somme des lignes redonne le total. --}}
    <div class="rp-deux">
        <div>
            <div class="rp-section">H. Charges comptées par semaine (du jeudi au mercredi)</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Semaine</th><th>Nb</th><th>Montant (FCFA)</th></tr></thead><tbody>
                @foreach ($ch['parSemaine'] as $l)<tr><td>{{ ucfirst($l['libelle']) }}{{ $etatTranche($l) }}</td><td class="d">{{ $f($l['nombre']) }}</td><td class="d">{{ $f($l['montant']) }}</td></tr>@endforeach
                <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f(collect($ch['parSemaine'])->sum('nombre')) }}</td><td class="d">{{ $f($ch['totalCompte']) }}</td></tr>
            </tbody></table></div>
        </div>
        <div>
            <div class="rp-section">I. Charges comptées par mois</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Mois</th><th>Nb</th><th>Montant (FCFA)</th></tr></thead><tbody>
                @foreach ($ch['parMois'] as $l)<tr><td>{{ $l['libelle'] }}{{ $etatTranche($l) }}</td><td class="d">{{ $f($l['nombre']) }}</td><td class="d">{{ $f($l['montant']) }}</td></tr>@endforeach
                <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $f(collect($ch['parMois'])->sum('nombre')) }}</td><td class="d">{{ $f($ch['totalCompte']) }}</td></tr>
            </tbody></table></div>
        </div>
    </div>
@endif

{{-- ═══════════════════ Conclusion ═══════════════════ --}}
@if ($this->vue === 'conclusion')
    @php $co = $d['conclusion']; $tr = $d['tresorerie']; @endphp
    <div class="rp-section">A. Trésorerie</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Banque</th><th>Au {{ $d['debut']->format('d/m/Y') }} (FCFA)</th><th>Situation à la fin de la période (FCFA)</th></tr></thead><tbody>
        @forelse ($tr['banques'] as $b)
            <tr><td>{{ $b['banque'] }}</td><td class="d">{{ $b['depart'] !== null ? $f($b['depart']) : 'Aucun relevé' }}</td><td class="d">{{ $b['actuel'] !== null ? $f($b['actuel']) : 'Aucun relevé' }}{{ $b['au'] ? ' (au '.$b['au']->format('d/m').')' : '' }}</td></tr>
        @empty
            <tr><td colspan="3" style="color:#6B6F78;">Aucun relevé bancaire.</td></tr>
        @endforelse
        <tr><td>Entrées de la période (journal et relevés)</td><td></td><td class="d">{{ $f($tr['entrees']) }}</td></tr>
        <tr><td>Sorties de la période</td><td></td><td class="d">{{ $f($tr['sorties']) }}</td></tr>
        <tr class="rp-total"><td>Solde de fonctionnement</td><td></td><td class="d">{{ $f($tr['net']) }}</td></tr>
    </tbody></table></div>

    <div class="rp-section">B. Engagements à couvrir (ce que l'entreprise doit)</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Poste</th><th>Montant à régler (FCFA)</th><th>Délai / échéance</th></tr></thead><tbody>
        @foreach ($co['lignes'] as $l)<tr><td>{{ $l['poste'] }}</td><td class="d">{{ $f($l['montant']) }}</td><td>{{ $l['delai'] }}</td></tr>@endforeach
        <tr class="rp-total"><td class="rp-rouge">TOTAL DES ENGAGEMENTS IDENTIFIÉS</td><td class="d rp-rouge">{{ $f($co['engagements']) }}</td><td></td></tr>
    </tbody></table></div>

    <div class="rp-section">C. Recouvrement à réaliser pour couvrir les engagements</div>
    <div class="rp-grille">
        {!! $kpi('Impayés clients à recouvrer', $f($d['recouvrement']['reste']).' F', 'Dont '.$f($d['recouvrement']['resteExercice']).' sur '.$d['exercice'], '#D97706') !!}
        {!! $kpi('Recouvrement nécessaire', $pct($co['recouvrementNecessaire']), $pct($co['recouvrementNecessaire26']).' des créances '.$d['exercice']) !!}
        {!! $kpi("Rythme d'encaissement", $f($co['encaissementHebdo']).' F', $co['semaines'] !== null ? 'par semaine sur les 4 dernières, soit environ '.number_format($co['semaines'], 1, ',', ' ').' semaines pour couvrir' : 'par semaine sur les 4 dernières', '#191B20') !!}
    </div>

    <div class="rp-section">D. Synthèse générale</div>
    <div class="rp-boite"><table class="rp-table"><tbody>
        <tr><td><b>Chiffre d'affaires</b></td><td class="d rp-rouge">{{ $f($d['ca']['total']) }}</td></tr>
        <tr><td><b>Charges comptées</b></td><td class="d rp-rouge">{{ $f($d['charges']['totalCompte']) }}</td></tr>
        <tr><td><b>Impayés clients</b></td><td class="d rp-rouge">{{ $f($d['recouvrement']['reste']) }}</td></tr>
        <tr><td><b>Engagements à couvrir</b></td><td class="d rp-rouge">{{ $f($co['engagements']) }}</td></tr>
        <tr><td><b>Solde de fonctionnement</b></td><td class="d rp-rouge">{{ $f($tr['net']) }}</td></tr>
    </tbody></table></div>
@endif

{{-- ═══════════════════ Commerciaux ═══════════════════ --}}
@if ($this->vue === 'commerciaux')
    @php $lignesC = $this->rapport->commerciaux(); $tc = $this->rapport->totalDesCommerciaux(); @endphp
    {{-- 24/11 — « un récapitulatif de l'activité des commerciaux » : l'amont (prospections, devis)
         avant le réalisé. Les règles de comptage sont celles des écrans Prospects et Devis, citées
         sur `RapportFinancier::commerciaux()`. --}}
    <div class="rp-section">A. Activité de l'équipe commerciale (totaux)</div>
    <div class="rp-grille">
        {!! $kpi('Prospections saisies', (string) $tc['prospectionsSaisies'], $tc['prospections'].' transmises ou validées', '#191B20') !!}
        {!! $kpi('Passées en devis', (string) $tc['passeesEnDevis'], $pct($tc['tauxProspectionDevis']).' des prospections ; '.$tc['attenteDuDevis'].' en attente du devis', '#2563EB') !!}
        {!! $kpi('Devis émis', (string) $tc['devisEmis'], $tc['devisEnAttente'].' en attente', '#D97706') !!}
        {!! $kpi('Devis transformés', (string) $tc['devisTransformes'], $pct($tc['tauxDevisFacture']).' des devis ; '.$tc['devisFactures'].' suivis d\'une facture', '#0E9F6E') !!}
        {!! $kpi('Factures', (string) $tc['factures'], $f($tc['realisation']).' F facturés') !!}
    </div>

    <div class="rp-section rp-large">B. De la prospection à la facture, par commercial</div>
    <div class="rp-boite rp-large"><table class="rp-table"><thead><tr><th>Commercial</th><th>Prospections saisies</th><th>Transmises ou validées</th><th>Passées en devis</th><th>En attente du devis</th><th>Taux prosp. → devis</th><th>Devis émis</th><th>Devis en attente</th><th>Devis transformés</th><th>dont facturés</th><th>Taux devis → facture</th><th>Factures</th><th>CA facturé</th></tr></thead><tbody>
        @forelse ($lignesC as $l)
            <tr><td><b>{{ $l['commercial'] }}</b></td><td class="d">{{ $l['prospectionsSaisies'] }}</td><td class="d">{{ $l['prospections'] }}</td><td class="d">{{ $l['passeesEnDevis'] }}</td><td class="d">{{ $l['attenteDuDevis'] }}</td><td class="d">{{ $pct($l['tauxProspectionDevis']) }}</td><td class="d">{{ $l['devisEmis'] }}</td><td class="d">{{ $l['devisEnAttente'] }}</td><td class="d">{{ $l['devisTransformes'] }}</td><td class="d">{{ $l['devisFactures'] }}</td><td class="d">{{ $pct($l['tauxDevisFacture']) }}</td><td class="d">{{ $l['factures'] }}</td><td class="d"><b>{{ $f($l['realisation']) }}</b></td></tr>
        @empty
            <tr><td colspan="13" style="color:#6B6F78;">Aucun commercial actif dans votre périmètre.</td></tr>
        @endforelse
        @if ($lignesC !== [])
            <tr class="rp-total"><td>TOTAL</td><td class="d">{{ $tc['prospectionsSaisies'] }}</td><td class="d">{{ $tc['prospections'] }}</td><td class="d">{{ $tc['passeesEnDevis'] }}</td><td class="d">{{ $tc['attenteDuDevis'] }}</td><td class="d">{{ $pct($tc['tauxProspectionDevis']) }}</td><td class="d">{{ $tc['devisEmis'] }}</td><td class="d">{{ $tc['devisEnAttente'] }}</td><td class="d">{{ $tc['devisTransformes'] }}</td><td class="d">{{ $tc['devisFactures'] }}</td><td class="d">{{ $pct($tc['tauxDevisFacture']) }}</td><td class="d">{{ $tc['factures'] }}</td><td class="d">{{ $f($tc['realisation']) }}</td></tr>
        @endif
    </tbody></table></div>
    <div class="rp-notes">
        <div class="rp-note"><b>Prospections saisies</b> : toutes celles de la période, brouillons et refusées compris. <b>Transmises ou validées</b> : celles que comptent les pages Prospects et Affichage général.</div>
        <div class="rp-note"><b>Passées en devis</b> : prospections transmises ou validées cochées « devis après passage » ; <b>en attente du devis</b> : celles dont le devis n'est pas encore relié (import à venir ou numéro manquant).</div>
        <div class="rp-note"><b>Devis transformés</b> : validés à l'écran ou suivis d'une facture (même devis ou même fiche de réception), comme sur la page Devis ; <b>en attente</b> : « En attente » et non transformés. Les devis sont ceux émis dans la période au nom du commercial.</div>
        <div class="rp-note"><b>Factures, CA facturé</b> : factures de la période portant le commercial, comme sur la page Commerciaux. Les taux du TOTAL sont recalculés sur les totaux. L'objectif est pris au prorata de la période.</div>
    </div>

    <div class="rp-section rp-large">C. Réalisé, objectif et encours par commercial</div>
    <div class="rp-boite rp-large"><table class="rp-table"><thead><tr><th>Commercial</th><th>Ville</th><th>Factures</th><th>CA réalisé</th><th>Mécanique</th><th>Sinistre</th><th>Objectif (prorata)</th><th>Taux</th><th>Encours clients</th></tr></thead><tbody>
        @forelse ($lignesC as $l)
            <tr><td><b>{{ $l['commercial'] }}</b></td><td>{{ $l['ville'] }}</td><td class="d">{{ $l['factures'] }}</td><td class="d"><b>{{ $f($l['realisation']) }}</b></td><td class="d">{{ $f($l['mecanique']) }}</td><td class="d">{{ $f($l['sinistre']) }}</td><td class="d">{{ $f($l['objectif']) }}</td><td class="d">{{ $pct($l['taux']) }}</td><td class="d">{{ $f($l['encours']) }}</td></tr>
        @empty
            <tr><td colspan="9" style="color:#6B6F78;">Aucun commercial actif dans votre périmètre.</td></tr>
        @endforelse
        @if ($lignesC !== [])
            <tr class="rp-total"><td colspan="2">TOTAL</td><td class="d">{{ $tc['factures'] }}</td><td class="d">{{ $f($tc['realisation']) }}</td><td class="d">{{ $f($tc['mecanique']) }}</td><td class="d">{{ $f($tc['sinistre']) }}</td><td class="d">{{ $f($tc['objectif']) }}</td><td class="d">{{ $pct($tc['taux']) }}</td><td class="d">{{ $f($tc['encours']) }}</td></tr>
        @endif
    </tbody></table></div>
@endif

{{-- ═══════════════════ Année précédente ═══════════════════ --}}
@if ($this->vue === 'precedent')
    @php $ap = $this->rapport->anneePrecedente(); @endphp
    <div class="rp-section">A. Grandes lignes</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Grandes lignes</th><th>{{ ucfirst($ap['passe']['titre']) }} (FCFA)</th><th>{{ ucfirst($ap['actuel']['titre']) }}{{ $per->estLExercice() ? ' en cours' : '' }} (FCFA)</th><th>Variation</th></tr></thead><tbody>
        @foreach (['ca' => "Chiffre d'affaires", 'charges' => 'Charges comptées', 'resultat' => 'Résultat (CA − charges)', 'encaisse' => 'Encaissements clients', 'encours' => 'Encours clients (impayés)'] as $k => $lib)
            <tr><td>{{ $lib }}</td><td class="d">{{ $f($ap['passe'][$k]) }}</td><td class="d">{{ $f($ap['actuel'][$k]) }}</td><td class="d">{{ isset($ap['variations'][$k]) ? $pct($ap['variations'][$k]) : 'n.d.' }}</td></tr>
        @endforeach
    </tbody></table></div>
    @if ($per->estLExercice())
        <div class="rp-aide">Les chiffres de {{ $ap['actuel']['exercice'] }} vont jusqu'à aujourd'hui : la variation compare une année entière à une année en cours. L'encours est l'état actuel des créances de l'exercice.</div>
    @else
        <div class="rp-aide">La même période, un an plus tôt, sur le même lieu et la même activité. L'encours est celui des factures émises dans chaque période.</div>
    @endif
    <div class="rp-deux">
        <div>
            <div class="rp-section">B. CA par ville, {{ $ap['passe']['titre'] }}</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Ville</th><th>CA (FCFA)</th></tr></thead><tbody>@foreach ($ap['passe']['parVille'] as $l)<tr><td>{{ $l['ville'] }}</td><td class="d">{{ $f($l['montant']) }}</td></tr>@endforeach</tbody></table></div>
        </div>
        <div>
            <div class="rp-section">C. CA par activité, {{ $ap['passe']['titre'] }}</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Activité</th><th>CA (FCFA)</th></tr></thead><tbody>@foreach ($ap['passe']['parActivite'] as $l)<tr><td>{{ $l['activite'] }}</td><td class="d">{{ $f($l['montant']) }}</td></tr>@endforeach</tbody></table></div>
        </div>
    </div>
@endif
</div>

{{-- ═══════════════════ Dépôt de fichiers ═══════════════════ --}}
@if ($this->vue === 'depot')
    <div class="rp-titre">Dépôt de fichiers : impôts, CNPS, salaires, récapitulatifs</div>
    <div class="rp-aide">Un fichier déjà déposé est refusé ; une ligne déjà connue n'est pas recréée ; un paiement déjà compté par la caisse n'est pas recompté ; un récapitulatif ne sert qu'au contrôle.</div>

    <div class="rp-section">A. Déposer un fichier</div>
    <div class="rp-notes">
        @if ($this->peutDeposer)
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="file" wire:model="fichier" accept=".xlsx,.xls,.csv,.txt,.pdf,.jpg,.jpeg,.png,.webp" class="rp-champ">
                <select wire:model="natureDepot" class="rp-champ"><option value="">Nature : la deviner</option>@foreach (\Modules\Noyau\Rapport\Support\LectureDesPieces::NATURES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <button type="button" wire:click="deposer" wire:loading.attr="disabled" class="rp-btn">Déposer</button>
            </div>
            @error('fichier')<div style="color:#C8102E; margin-top:6px;">{{ $message }}</div>@enderror
        @else
            <div style="color:#D97706; font-weight:700;">Le dépôt n'est pas ouvert à votre compte : il est accordé par le Super admin (Maintenance › Habilitations).</div>
        @endif
        @if ($this->messageDepot !== '')<div style="color:#0E9F6E; margin-top:8px; font-weight:700;">{{ $this->messageDepot }}</div>@endif
        @if ($this->erreurDepot !== '')<div style="color:#C8102E; margin-top:8px; font-weight:700;">{{ $this->erreurDepot }}</div>@endif
    </div>

    @if ($this->peutDeposer)
        <div class="rp-section">B. Saisir une pièce à la main</div>
        <div class="rp-notes">
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                <select wire:model="pieceNature" class="rp-champ">@foreach (\Modules\Noyau\Rapport\Support\LectureDesPieces::NATURES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <select wire:model="pieceStatut" class="rp-champ"><option value="du">À régler</option><option value="paye">Payé</option></select>
                <input type="date" wire:model="pieceDate" class="rp-champ">
                <input type="text" wire:model="pieceLibelle" placeholder="Libellé (ex. Droits simples)" class="rp-champ" style="min-width:220px;">
                <input type="text" wire:model="pieceReference" placeholder="Référence (ex. avis n° 329)" class="rp-champ">
                <input type="number" wire:model="pieceMontant" placeholder="Montant FCFA" class="rp-champ" style="width:160px;">
                <button type="button" wire:click="saisir" class="rp-btn">Enregistrer</button>
            </div>
            @foreach (['pieceLibelle', 'pieceMontant', 'pieceDate'] as $champ)@error($champ)<div style="color:#C8102E; margin-top:4px;">{{ $message }}</div>@enderror @endforeach
        </div>
    @endif

    @if ($this->propositions !== [])
        <div class="rp-section" style="background:#D97706;">Lecture de l'assistant, à confirmer (rien n'est encore enregistré)</div>
        <div class="rp-boite"><table class="rp-table"><thead><tr><th>Nature</th><th>Statut</th><th>Libellé</th><th>Référence</th><th>Montant (FCFA)</th></tr></thead><tbody>
            @foreach ($this->propositions as $p)<tr><td>{{ $p['nature'] }}</td><td>{{ $p['statut'] === 'paye' ? 'Payé' : 'À régler' }}</td><td>{{ $p['libelle'] }}</td><td>{{ $p['reference'] }}</td><td class="d">{{ $f($p['montant']) }}</td></tr>@endforeach
        </tbody></table></div>
        <div style="margin-top:8px; display:flex; gap:8px;"><button type="button" wire:click="confirmerLesPropositions" class="rp-btn">Confirmer et enregistrer</button><button type="button" wire:click="retirerLesPropositions" class="rp-btn neutre">Écarter</button></div>
    @endif

    <div class="rp-deux">
        <div>
            <div class="rp-section">C. Fichiers déposés</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Fichier</th><th>Résultat</th><th></th></tr></thead><tbody>
                @forelse ($this->depots as $dep)
                    <tr><td>{{ $dep->nom_original }}<div style="font-size:11px; color:#6B6F78;">{{ $dep->created_at?->format('d/m/Y H:i') }}{{ $dep->deposant ? ' · '.$dep->deposant->name : '' }}</div></td>
                        <td>{{ $dep->message }}</td>
                        <td>@if ($this->peutDeposer && $this->iaDispo && $dep->statut === 'a_completer' && preg_match('/\.(jpe?g|png|webp)$/i', $dep->nom_original))<button type="button" wire:click="lireParIA({{ $dep->id }})" class="rp-btn neutre" style="padding:4px 10px; font-size:12px;">Lire avec l'IA</button>@endif</td></tr>
                @empty
                    <tr><td colspan="3" style="color:#6B6F78;">Aucun fichier déposé.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
        <div>
            <div class="rp-section">D. Pièces de {{ $this->exerciceChoisi }}</div>
            <div class="rp-boite"><table class="rp-table"><thead><tr><th>Pièce</th><th>Statut</th><th>Montant (FCFA)</th><th>Compte ?</th></tr></thead><tbody>
                @forelse ($this->pieces as $p)
                    <tr><td>{{ $p->libelle }}<div style="font-size:11px; color:#6B6F78;">{{ \Modules\Noyau\Rapport\Support\LectureDesPieces::NATURES[$p->nature] ?? $p->nature }}{{ $p->reference ? ' · '.$p->reference : '' }}</div></td>
                        <td class="c">{{ ['du' => 'À régler', 'paye' => 'Payé', 'info' => 'Info'][$p->statut] ?? $p->statut }}</td><td class="d">{{ $f($p->montant) }}</td>
                        <td class="c">{{ $p->compte ? 'Oui' : 'Non' }}</td></tr>
                @empty
                    <tr><td colspan="4" style="color:#6B6F78;">Aucune pièce.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
@endif

{{-- ═══════════════════ Assistant IA (le dernier « slide ») ═══════════════════ --}}
@if ($this->vue === 'ia' && $this->peutIa)
    @php
        $analyseIa = ProductionIA::query()->where('usage', 'rapport')->where('type', 'analyse')->where('exercice', $this->exerciceChoisi)->latest('id')->first();
        $tours = $this->agent()->conversation()->tours();
        $documents = ProductionIA::query()->where('usage', 'rapport')->where('type', 'document')->latest('id')->limit(15)->get();
        $md = fn (string $t) => \Illuminate\Support\Str::markdown($t, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    @endphp

    <div class="rp-aide">L'assistant lit les données de l'application sous vos habilitations ; il n'écrit que ses analyses, ses documents et les pièces du dépôt.</div>
    @if (! AssistantDuRapport::iaDisponible())
        <div class="rp-notes"><div class="rp-note alerte">L'IA du rapport n'est pas configurée : le Super admin doit renseigner une clé et affecter un fournisseur à l'usage « Rapport » (Super admin › IA). En attendant, le rapport garde l'analyse du système.</div></div>
    @endif

    <div class="rp-section">A. Analyse globale</div>
    @if ($analyseIa)
        @php $st = $analyseIa->styleSur(); @endphp
        <span class="rp-puce">Rédigée par l'assistant IA le {{ $analyseIa->created_at->format('d/m/Y à H\hi') }}</span>
        <div class="rp-prod {{ $st['mise_en_page'] }}" style="--acc:{{ $st['accent'] }}; --fond:{{ $st['fond'] }};">
            <h2 style="margin-top:0;">{{ $analyseIa->titre }}</h2>
            <div class="rp-prod-corps">{!! $analyseIa->html() !!}</div>
        </div>
    @else
        <span class="rp-puce systeme">Analyse du système (règles de calcul)</span>
        <div class="rp-notes">
            @foreach (collect($cm)->flatten(1) as $r)<div class="rp-note {{ $r['niveau'] }}">{{ $r['texte'] }}</div>@endforeach
        </div>
    @endif

    <div class="rp-notes" style="margin-top:10px;">
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <input type="text" wire:model="consigneAnalyse" class="rp-champ" style="flex:1; min-width:280px;" placeholder="Consigne de style facultative, par exemple « sobre, en deux colonnes, accent bleu #2563EB »">
            <button type="button" wire:click="analyserParIA" wire:loading.attr="disabled" class="rp-btn" @disabled(! AssistantDuRapport::iaDisponible())>
                <span wire:loading.remove wire:target="analyserParIA">Analyser avec l'IA</span><span wire:loading wire:target="analyserParIA">L'assistant analyse…</span>
            </button>
            <button type="button" wire:click="revenirALEtat" wire:confirm="Retirer l'analyse et les commentaires de l'IA et revenir à l'analyse du système ?" class="rp-btn neutre">Revenir à l'état du système</button>
        </div>
        @if ($this->infoIa !== '')<div style="color:#0E9F6E; margin-top:8px; font-weight:700;">{{ $this->infoIa }}</div>@endif
        @if ($this->erreurChat !== '')<div style="color:#C8102E; margin-top:8px; font-weight:700;">{{ $this->erreurChat }}</div>@endif
    </div>

    <div class="rp-section">B. Conversation avec l'assistant</div>
    <div class="rp-chat" id="rp-chat">
        @forelse ($tours as $t)
            <div class="rp-msg {{ $t['role'] }}">@if ($t['role'] === 'assistant'){!! $md($t['content']) !!}@else{{ $t['content'] }}@endif</div>
        @empty
            <div style="color:#6B6F78;">Posez une question (« Quels clients nous doivent le plus et depuis quand ? »), demandez un état (« Fais-moi un PDF des impayés de plus de 90 jours »), une statistique, ou déposez un fichier.</div>
        @endforelse
        <div wire:loading wire:target="envoyerChat" class="rp-msg assistant" style="color:#6B6F78;">L'assistant réfléchit…</div>
    </div>
    <div class="rp-notes" style="margin-top:10px;">
        <textarea wire:model="messageChat" rows="3" class="rp-champ" style="width:100%;" placeholder="Votre message à l'assistant…"></textarea>
        @error('messageChat')<div style="color:#C8102E;">{{ $message }}</div>@enderror
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:8px;">
            <input type="file" wire:model="fichierChat" class="rp-champ" accept=".xlsx,.xls,.csv,.txt,.pdf,.jpg,.jpeg,.png,.webp">
            <button type="button" wire:click="envoyerChat" wire:loading.attr="disabled" class="rp-btn" @disabled(! AssistantDuRapport::iaDisponible())>Envoyer</button>
            <button type="button" wire:click="nouvelleConversation" class="rp-btn neutre">Nouvelle conversation</button>
        </div>
        <div class="rp-aide">Un fichier joint est déposé comme dans « Dépôt de fichiers » (mêmes règles, sans doublon), si votre compte a l'habilitation de dépôt. Les données que l'assistant lit sont envoyées au fournisseur d'IA affecté par le Super admin.</div>
    </div>

    <div class="rp-section">C. Documents produits par l'assistant</div>
    <div class="rp-boite"><table class="rp-table"><thead><tr><th>Document</th><th>Créé le</th></tr></thead><tbody>
        @forelse ($documents as $doc)
            <tr><td><a href="{{ route('rapport.document', $doc->id) }}" target="_blank" style="font-weight:700; color:#C8102E;">{{ $doc->titre }}</a></td><td class="c">{{ $doc->created_at->format('d/m/Y H:i') }}</td></tr>
        @empty
            <tr><td colspan="2" style="color:#6B6F78;">Aucun document : demandez-en un à l'assistant (« fais-moi un état en PDF de… »).</td></tr>
        @endforelse
    </tbody></table></div>
@endif
</div>
