<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$exerciceActif = getExerciceActif();

if (!$exerciceActif || !is_array($exerciceActif)) {
    $exerciceActif = [
        'annee' => date('Y'),
        'statut' => 'Non défini'
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Interactif - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <header class="bg-slate-800/30 border-b border-slate-700/50 p-4 sticky top-0 z-10 backdrop-blur-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white flex items-center gap-2">
                            📊 Dashboard Interactif
                            <span class="text-sm font-normal text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded">Temps réel</span>
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">
                            Exercice <?php echo htmlspecialchars($exerciceActif['annee']); ?> -
                            <?php echo htmlspecialchars($exerciceActif['statut']); ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="refreshAllData()" class="px-4 py-2 bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 rounded-lg transition flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            Actualiser
                        </button>
                        <button onclick="toggleSettings()" class="px-4 py-2 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg transition">
                            ⚙️ Widgets
                        </button>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="p-6 space-y-6" id="dashboardContent">
                <!-- KPIs Row -->
                <div id="kpis-section" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- KPIs will be loaded dynamically -->
                </div>

                <!-- Charts Grid (Sortable) -->
                <div id="widgets-container" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Widget: CA Mensuel -->
                    <div class="widget-card" data-widget="ca-mensuel">
                        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                    📈 Chiffre d'Affaires Mensuel
                                </h3>
                                <button onclick="toggleWidget('ca-mensuel')" class="text-slate-400 hover:text-slate-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <canvas id="chartCAMensuel" height="250"></canvas>
                        </div>
                    </div>

                    <!-- Widget: Charges -->
                    <div class="widget-card" data-widget="charges">
                        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                    💰 Charges par Catégorie
                                </h3>
                                <button onclick="toggleWidget('charges')" class="text-slate-400 hover:text-slate-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <canvas id="chartCharges" height="250"></canvas>
                        </div>
                    </div>

                    <!-- Widget: Trésorerie -->
                    <div class="widget-card" data-widget="tresorerie">
                        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                    💵 Évolution Trésorerie (30j)
                                </h3>
                                <button onclick="toggleWidget('tresorerie')" class="text-slate-400 hover:text-slate-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <canvas id="chartTresorerie" height="250"></canvas>
                        </div>
                    </div>

                    <!-- Widget: Top Clients -->
                    <div class="widget-card" data-widget="top-clients">
                        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                    👥 Top 10 Clients
                                </h3>
                                <button onclick="toggleWidget('top-clients')" class="text-slate-400 hover:text-slate-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <canvas id="chartTopClients" height="250"></canvas>
                        </div>
                    </div>

                    <!-- Widget: Top Fournisseurs -->
                    <div class="widget-card" data-widget="top-fournisseurs">
                        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                    🏢 Top 10 Fournisseurs
                                </h3>
                                <button onclick="toggleWidget('top-fournisseurs')" class="text-slate-400 hover:text-slate-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <canvas id="chartTopFournisseurs" height="250"></canvas>
                        </div>
                    </div>

                    <!-- Widget: Résultat -->
                    <div class="widget-card" data-widget="resultat">
                        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                    📊 Compte de Résultat
                                </h3>
                                <button onclick="toggleWidget('resultat')" class="text-slate-400 hover:text-slate-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <div id="resultatContent" class="space-y-4">
                                <!-- Chargement... -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center">
        <div class="bg-slate-800 rounded-xl p-6 max-w-md w-full mx-4 border border-slate-700">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-white">⚙️ Configuration des Widgets</h3>
                <button onclick="toggleSettings()" class="text-slate-400 hover:text-white">✕</button>
            </div>
            <p class="text-sm text-slate-400 mb-4">Glissez-déposez les widgets pour les réorganiser. Cliquez sur ✕ pour masquer un widget.</p>
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" id="auto-refresh" class="rounded" onchange="toggleAutoRefresh()">
                    <span>Actualisation automatique (5 min)</span>
                </label>
            </div>
            <button onclick="resetLayout()" class="mt-4 w-full px-4 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg transition">
                Réinitialiser la disposition
            </button>
        </div>
    </div>

    <script>
    // Configuration Chart.js globale
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = '#334155';
    Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';

    const API_BASE = window.location.origin + '/comptabilite_ohada/api/v1';
    const token = localStorage.getItem('api_token') || '';

    let charts = {};
    let autoRefreshInterval = null;

    // Charger toutes les données au démarrage
    document.addEventListener('DOMContentLoaded', () => {
        loadKPIs();
        loadAllCharts();
        initSortable();
        loadUserPreferences();
    });

    // ========================================================================
    // KPIs
    // ========================================================================
    async function loadKPIs() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/kpis`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const data = await response.json();

            if (data.success) {
                displayKPIs(data.data.kpis);
            }
        } catch (error) {
            console.error('Erreur chargement KPIs:', error);
        }
    }

    function displayKPIs(kpis) {
        const container = document.getElementById('kpis-section');
        container.innerHTML = `
            <!-- Trésorerie -->
            <div class="bg-gradient-to-br from-emerald-500/10 to-emerald-600/10 border border-emerald-500/30 rounded-xl p-6 hover:border-emerald-500/50 transition cursor-pointer" onclick="drillDownTresorerie()">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-emerald-500/20 rounded-lg">
                        <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <span class="text-xs px-2 py-1 bg-emerald-500/20 text-emerald-300 rounded">Trésorerie</span>
                </div>
                <h3 class="text-3xl font-bold text-white mb-1">${formatMontant(kpis.tresorerie.montant)}</h3>
                <p class="text-sm text-slate-400">Disponible</p>
            </div>

            <!-- CA du mois -->
            <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/10 border border-blue-500/30 rounded-xl p-6 hover:border-blue-500/50 transition cursor-pointer" onclick="drillDownCA()">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-blue-500/20 rounded-lg">
                        <svg class="w-8 h-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </div>
                    <span class="text-xs px-2 py-1 bg-blue-500/20 text-blue-300 rounded">CA Mois</span>
                </div>
                <h3 class="text-3xl font-bold text-white mb-1">${formatMontant(kpis.ca_mois.montant)}</h3>
                <p class="text-sm text-slate-400">${kpis.ca_mois.mois} ${kpis.ca_mois.annee}</p>
            </div>

            <!-- Résultat -->
            <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/10 border border-purple-500/30 rounded-xl p-6 hover:border-purple-500/50 transition cursor-pointer" onclick="drillDownResultat()">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-purple-500/20 rounded-lg">
                        <svg class="w-8 h-8 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <span class="text-xs px-2 py-1 ${kpis.resultat_mois.montant >= 0 ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'} rounded">Résultat</span>
                </div>
                <h3 class="text-3xl font-bold ${kpis.resultat_mois.montant >= 0 ? 'text-emerald-400' : 'text-red-400'} mb-1">${formatMontant(kpis.resultat_mois.montant)}</h3>
                <p class="text-sm text-slate-400">${kpis.resultat_mois.mois} ${kpis.resultat_mois.annee}</p>
            </div>

            <!-- Écritures -->
            <div class="bg-gradient-to-br from-orange-500/10 to-orange-600/10 border border-orange-500/30 rounded-xl p-6 hover:border-orange-500/50 transition cursor-pointer" onclick="drillDownEcritures()">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-orange-500/20 rounded-lg">
                        <svg class="w-8 h-8 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <span class="text-xs px-2 py-1 bg-orange-500/20 text-orange-300 rounded">Écritures</span>
                </div>
                <h3 class="text-3xl font-bold text-white mb-1">${kpis.ecritures.total}</h3>
                <p class="text-sm text-slate-400">
                    <span class="text-yellow-400">${kpis.ecritures.brouillon} brouillon(s)</span> •
                    <span class="text-green-400">${kpis.ecritures.validees} validée(s)</span>
                </p>
            </div>
        `;
    }

    // ========================================================================
    // Charts
    // ========================================================================
    async function loadAllCharts() {
        await loadCAMensuel();
        await loadCharges();
        await loadTresorerie();
        await loadTopClients();
        await loadTopFournisseurs();
        await loadResultat();
    }

    async function loadCAMensuel() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/ca-mensuel`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();

            if (result.success) {
                const data = result.data.data;

                if (charts.caMensuel) charts.caMensuel.destroy();

                const ctx = document.getElementById('chartCAMensuel').getContext('2d');
                charts.caMensuel = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Chiffre d\'Affaires',
                            data: data.values,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (context) => formatMontant(context.parsed.y)
                                }
                            }
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (value) => formatMontant(value, true)
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Erreur chargement CA mensuel:', error);
        }
    }

    async function loadCharges() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/charges`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();

            if (result.success) {
                const data = result.data.data;

                if (charts.charges) charts.charges.destroy();

                const ctx = document.getElementById('chartCharges').getContext('2d');
                charts.charges = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Charges',
                            data: data.values,
                            backgroundColor: [
                                'rgba(239, 68, 68, 0.7)',
                                'rgba(249, 115, 22, 0.7)',
                                'rgba(234, 179, 8, 0.7)',
                                'rgba(34, 197, 94, 0.7)',
                                'rgba(59, 130, 246, 0.7)',
                                'rgba(168, 85, 247, 0.7)',
                                'rgba(236, 72, 153, 0.7)',
                                'rgba(20, 184, 166, 0.7)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (context) => formatMontant(context.parsed.y)
                                }
                            }
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (value) => formatMontant(value, true)
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Erreur chargement charges:', error);
        }
    }

    async function loadTresorerie() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/tresorerie`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();

            if (result.success) {
                const data = result.data.data;

                if (charts.tresorerie) charts.tresorerie.destroy();

                const ctx = document.getElementById('chartTresorerie').getContext('2d');
                charts.tresorerie = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Trésorerie',
                            data: data.values,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (context) => formatMontant(context.parsed.y)
                                }
                            }
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (value) => formatMontant(value, true)
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Erreur chargement trésorerie:', error);
        }
    }

    async function loadTopClients() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/top-clients`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();

            if (result.success) {
                const data = result.data.data;

                if (charts.topClients) charts.topClients.destroy();

                const ctx = document.getElementById('chartTopClients').getContext('2d');
                charts.topClients = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.values,
                            backgroundColor: [
                                '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b',
                                '#10b981', '#14b8a6', '#f97316', '#ef4444',
                                '#6366f1', '#a855f7'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'right' },
                            tooltip: {
                                callbacks: {
                                    label: (context) => `${context.label}: ${formatMontant(context.parsed)}`
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Erreur chargement top clients:', error);
        }
    }

    async function loadTopFournisseurs() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/top-fournisseurs`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();

            if (result.success) {
                const data = result.data.data;

                if (charts.topFournisseurs) charts.topFournisseurs.destroy();

                const ctx = document.getElementById('chartTopFournisseurs').getContext('2d');
                charts.topFournisseurs = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.values,
                            backgroundColor: [
                                '#ef4444', '#f97316', '#f59e0b', '#10b981',
                                '#14b8a6', '#3b82f6', '#8b5cf6', '#ec4899',
                                '#6366f1', '#a855f7'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'right' },
                            tooltip: {
                                callbacks: {
                                    label: (context) => `${context.label}: ${formatMontant(context.parsed)}`
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Erreur chargement top fournisseurs:', error);
        }
    }

    async function loadResultat() {
        try {
            const response = await fetch(`${API_BASE}/dashboard/resultat`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();

            if (result.success) {
                const data = result.data.data;

                document.getElementById('resultatContent').innerHTML = `
                    <div class="flex items-center justify-between p-4 bg-blue-500/10 rounded-lg">
                        <span class="text-slate-300">Produits</span>
                        <span class="text-xl font-bold text-blue-400">${formatMontant(data.produits)}</span>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-red-500/10 rounded-lg">
                        <span class="text-slate-300">Charges</span>
                        <span class="text-xl font-bold text-red-400">${formatMontant(data.charges)}</span>
                    </div>
                    <div class="border-t border-slate-700 my-2"></div>
                    <div class="flex items-center justify-between p-4 ${data.resultat >= 0 ? 'bg-emerald-500/10' : 'bg-red-500/10'} rounded-lg">
                        <span class="text-slate-300 font-semibold">Résultat Net</span>
                        <span class="text-2xl font-bold ${data.resultat >= 0 ? 'text-emerald-400' : 'text-red-400'}">${formatMontant(data.resultat)}</span>
                    </div>
                    <div class="text-center text-sm text-slate-400 mt-4">
                        Marge: ${data.marge.toFixed(2)}%
                    </div>
                `;
            }
        } catch (error) {
            console.error('Erreur chargement résultat:', error);
        }
    }

    // ========================================================================
    // Drill-down (redirection vers détails)
    // ========================================================================
    function drillDownTresorerie() {
        window.location.href = '../rapports/grand_livre.php?compte=57';
    }

    function drillDownCA() {
        window.location.href = '../rapports/grand_livre.php?compte=7';
    }

    function drillDownResultat() {
        window.location.href = '../rapports/resultat.php';
    }

    function drillDownEcritures() {
        window.location.href = '../ecritures/liste.php';
    }

    // ========================================================================
    // Widgets Management
    // ========================================================================
    function initSortable() {
        const container = document.getElementById('widgets-container');
        Sortable.create(container, {
            animation: 150,
            handle: '.widget-card',
            ghostClass: 'opacity-50',
            onEnd: saveUserPreferences
        });
    }

    function toggleWidget(widgetName) {
        const widget = document.querySelector(`[data-widget="${widgetName}"]`);
        widget.classList.toggle('hidden');
        saveUserPreferences();
    }

    function toggleSettings() {
        document.getElementById('settingsModal').classList.toggle('hidden');
    }

    function resetLayout() {
        if (confirm('Réinitialiser la disposition des widgets ?')) {
            localStorage.removeItem('dashboard_preferences');
            location.reload();
        }
    }

    function toggleAutoRefresh() {
        const enabled = document.getElementById('auto-refresh').checked;
        if (enabled) {
            autoRefreshInterval = setInterval(refreshAllData, 5 * 60 * 1000); // 5 minutes
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
        saveUserPreferences();
    }

    function refreshAllData() {
        loadKPIs();
        loadAllCharts();
    }

    function saveUserPreferences() {
        const widgets = Array.from(document.querySelectorAll('.widget-card')).map(w => ({
            name: w.dataset.widget,
            hidden: w.classList.contains('hidden')
        }));

        const prefs = {
            widgets,
            autoRefresh: document.getElementById('auto-refresh')?.checked || false
        };

        localStorage.setItem('dashboard_preferences', JSON.stringify(prefs));
    }

    function loadUserPreferences() {
        const prefs = JSON.parse(localStorage.getItem('dashboard_preferences') || '{}');

        if (prefs.widgets) {
            prefs.widgets.forEach(w => {
                const widget = document.querySelector(`[data-widget="${w.name}"]`);
                if (widget && w.hidden) {
                    widget.classList.add('hidden');
                }
            });
        }

        if (prefs.autoRefresh) {
            document.getElementById('auto-refresh').checked = true;
            toggleAutoRefresh();
        }
    }

    // ========================================================================
    // Utilitaires
    // ========================================================================
    function formatMontant(montant, short = false) {
        const absVal = Math.abs(montant);
        if (short && absVal >= 1000000) {
            return (montant / 1000000).toFixed(1) + 'M';
        } else if (short && absVal >= 1000) {
            return (montant / 1000).toFixed(0) + 'K';
        }
        return new Intl.NumberFormat('fr-FR', {
            style: 'decimal',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(montant) + ' FCFA';
    }
    </script>
</body>
</html>
