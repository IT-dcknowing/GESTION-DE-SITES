<?php

use Modules\Noyau\Entreprises\Modeles\Entreprise;
use App\Models\User;
use function Livewire\Volt\{computed, state};

state(['pageRepartition' => 1]);

$stats = computed(fn () => [
    'entreprises' => Entreprise::count(),
    'entreprises_actives' => Entreprise::where('est_active', true)->count(),
    'utilisateurs' => User::count(),
    'sites' => \Modules\Noyau\Entreprises\Modeles\Site::count(),
]);

$repartition = computed(function () {
    $entreprises = Entreprise::withCount('sites')->orderBy('nom')->get();
    $roles = User::nomsRolesParUtilisateur(User::pluck('id'));

    return $entreprises->map(function ($entreprise) use ($roles) {
        $utilisateurs = User::where('entreprise_id', $entreprise->id)->pluck('id');
        $comptage = ['gerant' => 0, 'responsable_site' => 0, 'commercial' => 0];

        foreach ($utilisateurs as $id) {
            foreach (explode(', ', $roles[$id] ?? '') as $role) {
                if (array_key_exists($role, $comptage)) {
                    $comptage[$role]++;
                }
            }
        }

        return [
            'entreprise' => $entreprise,
            'gerants' => $comptage['gerant'],
            'responsables' => $comptage['responsable_site'],
            'commerciaux' => $comptage['commercial'],
            'total' => $utilisateurs->count(),
        ];
    });
});

?>

<div>
    <x-titre-ecran titre="Tableau de bord de la plateforme"
        sous-titre="Les entreprises hébergées et leur activité." />

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px;">
        <x-kpi-card label="Entreprises" :value="$this->stats['entreprises']" :sub="$this->stats['entreprises_actives'].' actives'" couleur="#0E9F6E" />
        <x-kpi-card label="Utilisateurs" :value="$this->stats['utilisateurs']" />
        <x-kpi-card label="Sites" :value="$this->stats['sites']" />
        <x-kpi-card label="Moyenne utilisateurs / entreprise" :value="$this->stats['entreprises'] > 0 ? round($this->stats['utilisateurs'] / $this->stats['entreprises'], 1) : 0" />
    </div>

    <div class="carte">
        <h2 style="font-size:16.5px; font-weight:800; margin:0 0 4px;">Répartition par entreprise</h2>
        <p style="color:#6B6E76; font-size:14px; margin:0 0 16px;">Nombre de personnes par catégorie, pour chaque entreprise cliente de la plateforme.</p>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Entreprise</th>
                        <th>Sites</th>
                        <th>Gérants</th>
                        <th>Responsables de site</th>
                        <th>Commerciaux</th>
                        <th>Total utilisateurs</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->repartition->forPage($pageRepartition, 10) as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="font-weight:700;">
                                <a href="{{ route('super-admin.entreprises.show', $ligne['entreprise']) }}" wire:navigate style="color:inherit;">{{ $ligne['entreprise']->nom }}</a>
                            </td>
                            <td>{{ $ligne['entreprise']->sites_count }}</td>
                            <td>{{ $ligne['gerants'] }}</td>
                            <td>{{ $ligne['responsables'] }}</td>
                            <td>{{ $ligne['commerciaux'] }}</td>
                            <td style="font-weight:700;">{{ $ligne['total'] }}</td>
                            <td>
                                @if ($ligne['entreprise']->est_active)
                                    <span style="color:#0E9F6E; font-weight:600;">Active</span>
                                @else
                                    <span style="color:#C8102E; font-weight:600;">Suspendue</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="7" texte="Aucune entreprise inscrite sur la plateforme pour le moment." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageRepartition" :total="$this->repartition->count()" prop="pageRepartition" />
    </div>
</div>
