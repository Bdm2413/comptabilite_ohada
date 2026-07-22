<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();

// Récupérer l'année sélectionnée
$annee = $_GET['annee'] ?? date('Y');

// Statistiques CCA
$stmt = $db->prepare("
    SELECT
        COUNT(*) as nb_total,
        SUM(CASE WHEN statut = 'Actif' THEN 1 ELSE 0 END) as nb_actif,
        SUM(CASE WHEN statut = 'Actif' THEN montant_total ELSE 0 END) as montant_actif,
        SUM(CASE WHEN statut = 'Actif' THEN montant_mensuel ELSE 0 END) as montant_mensuel_moyen
    FROM charges_constatees_avance
    WHERE societe_id = ?
");
$stmt->execute([$societe_id]);
$stats_cca = $stmt->fetch();

// Statistiques PCA
$stmt = $db->prepare("
    SELECT
        COUNT(*) as nb_total,
        SUM(CASE WHEN statut = 'Actif' THEN 1 ELSE 0 END) as nb_actif,
        SUM(CASE WHEN statut = 'Actif' THEN montant_total ELSE 0 END) as montant_actif,
        SUM(CASE WHEN statut = 'Actif' THEN montant_mensuel ELSE 0 END) as montant_mensuel_moyen
    FROM produits_constates_avance
    WHERE societe_id = ?
");
$stmt->execute([$societe_id]);
$stats_pca = $stmt->fetch();

// Données mensuelles pour graphique comparatif
$recap_cca = [];
$recap_pca = [];
for ($mois = 1; $mois <= 12; $mois++) {
    $recap_cca[$mois] = 0;
    $recap_pca[$mois] = 0;
}

// Calculer CCA mensuels
$stmt = $db->prepare("
    SELECT * FROM charges_constatees_avance
    WHERE societe_id = ? AND statut = 'Actif'
    AND (YEAR(date_debut) <= ? AND YEAR(date_fin) >= ?)
");
$stmt->execute([$societe_id, $annee, $annee]);
$charges = $stmt->fetchAll();

foreach ($charges as $charge) {
    $date_debut = new DateTime($charge['date_debut']);
    $date_fin = new DateTime($charge['date_fin']);
    $date_courante = clone $date_debut;

    while ($date_courante <= $date_fin) {
        if ((int)$date_courante->format('Y') == $annee) {
            $mois = (int)$date_courante->format('n');
            $recap_cca[$mois] += $charge['montant_mensuel'];
        }
        $date_courante->modify('+1 month');
    }
}

// Calculer PCA mensuels
$stmt = $db->prepare("
    SELECT * FROM produits_constates_avance
    WHERE societe_id = ? AND statut = 'Actif'
    AND (YEAR(date_debut) <= ? AND YEAR(date_fin) >= ?)
");
$stmt->execute([$societe_id, $annee, $annee]);
$produits = $stmt->fetchAll();

foreach ($produits as $produit) {
    $date_debut = new DateTime($produit['date_debut']);
    $date_fin = new DateTime($produit['date_fin']);
    $date_courante = clone $date_debut;

    while ($date_courante <= $date_fin) {
        if ((int)$date_courante->format('Y') == $annee) {
            $mois = (int)$date_courante->format('n');
            $recap_pca[$mois] += $produit['montant_mensuel'];
        }
        $date_courante->modify('+1 month');
    }
}

$pageTitle = "Tableau de Bord PCA/CCA";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité SYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto">
            <div class="p-6">
                <!-- En-tête -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-white mb-2">📈 Tableau de Bord - Charges & Produits Constatés d'Avance</h1>
                        <p class="text-slate-400 text-sm">Vue d'ensemble et analyses</p>
                    </div>
                    <div class="flex gap-3">
                        <select onchange="window.location.href='?annee='+this.value" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                            <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $annee == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- Statistiques globales -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <!-- CCA Actives -->
                    <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-blue-200 text-sm">CCA Actives</span>
                            <svg class="w-6 h-6 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"></path>
                            </svg>
                        </div>
                        <p class="text-3xl font-bold text-white"><?= $stats_cca['nb_actif'] ?></p>
                        <p class="text-xs text-blue-200 mt-1">sur <?= $stats_cca['nb_total'] ?> total</p>
                    </div>

                    <!-- Montant CCA -->
                    <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-purple-200 text-sm">Total CCA</span>
                            <svg class="w-6 h-6 text-purple-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-white"><?= number_format($stats_cca['montant_actif'] ?? 0, 0, ',', ' ') ?> F</p>
                        <p class="text-xs text-purple-200 mt-1">à répartir</p>
                    </div>

                    <!-- PCA Actifs -->
                    <div class="bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-emerald-200 text-sm">PCA Actifs</span>
                            <svg class="w-6 h-6 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <p class="text-3xl font-bold text-white"><?= $stats_pca['nb_actif'] ?></p>
                        <p class="text-xs text-emerald-200 mt-1">sur <?= $stats_pca['nb_total'] ?> total</p>
                    </div>

                    <!-- Montant PCA -->
                    <div class="bg-gradient-to-br from-teal-600 to-teal-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-teal-200 text-sm">Total PCA</span>
                            <svg class="w-6 h-6 text-teal-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-white"><?= number_format($stats_pca['montant_actif'] ?? 0, 0, ',', ' ') ?> F</p>
                        <p class="text-xs text-teal-200 mt-1">à répartir</p>
                    </div>
                </div>

                <!-- Graphiques -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Comparaison mensuelle -->
                    <div class="bg-slate-800 rounded-lg p-6">
                        <h2 class="text-lg font-semibold text-white mb-4">Comparaison Mensuelle <?= $annee ?></h2>
                        <div style="height: 300px;">
                            <canvas id="chartComparaison"></canvas>
                        </div>
                    </div>

                    <!-- Répartition globale -->
                    <div class="bg-slate-800 rounded-lg p-6">
                        <h2 class="text-lg font-semibold text-white mb-4">Répartition Globale</h2>
                        <div style="height: 300px;">
                            <canvas id="chartRepartition"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Solde net mensuel -->
                <div class="bg-slate-800 rounded-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Impact Net Mensuel (PCA - CCA)</h2>
                    <div style="height: 250px;">
                        <canvas id="chartImpact"></canvas>
                    </div>
                </div>

                <!-- Liens rapides -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                    <a href="charges_avance.php" class="bg-blue-600 hover:bg-blue-700 rounded-lg p-4 transition text-center">
                        <p class="font-semibold text-white">Gérer les CCA</p>
                    </a>
                    <a href="produits_avance.php" class="bg-emerald-600 hover:bg-emerald-700 rounded-lg p-4 transition text-center">
                        <p class="font-semibold text-white">Gérer les PCA</p>
                    </a>
                    <a href="recap_mensuel_cca.php" class="bg-purple-600 hover:bg-purple-700 rounded-lg p-4 transition text-center">
                        <p class="font-semibold text-white">Récap Mensuel CCA</p>
                    </a>
                    <a href="recap_mensuel_pca.php" class="bg-teal-600 hover:bg-teal-700 rounded-lg p-4 transition text-center">
                        <p class="font-semibold text-white">Récap Mensuel PCA</p>
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Graphique de comparaison mensuelle
    new Chart(document.getElementById('chartComparaison').getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
            datasets: [{
                label: 'CCA',
                data: [<?php echo implode(',', $recap_cca); ?>],
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 1
            }, {
                label: 'PCA',
                data: [<?php echo implode(',', $recap_pca); ?>],
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
                borderColor: 'rgba(16, 185, 129, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#94a3b8' } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString('fr-FR') + ' F';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#94a3b8',
                        callback: (value) => value.toLocaleString('fr-FR') + ' F'
                    },
                    grid: { color: '#334155' }
                },
                x: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } }
            }
        }
    });

    // Graphique de répartition
    new Chart(document.getElementById('chartRepartition').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['CCA', 'PCA'],
            datasets: [{
                data: [
                    <?= $stats_cca['montant_actif'] ?? 0 ?>,
                    <?= $stats_pca['montant_actif'] ?? 0 ?>
                ],
                backgroundColor: ['rgba(59, 130, 246, 0.7)', 'rgba(16, 185, 129, 0.7)'],
                borderColor: ['rgba(59, 130, 246, 1)', 'rgba(16, 185, 129, 1)'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#94a3b8' } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toLocaleString('fr-FR') + ' F';
                        }
                    }
                }
            }
        }
    });

    // Graphique d'impact net
    const impactData = [<?php
        for ($i = 1; $i <= 12; $i++) {
            echo ($recap_pca[$i] - $recap_cca[$i]) . ',';
        }
    ?>];

    new Chart(document.getElementById('chartImpact').getContext('2d'), {
        type: 'line',
        data: {
            labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
            datasets: [{
                label: 'Impact Net (F)',
                data: impactData,
                backgroundColor: function(context) {
                    return context.raw >= 0 ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)';
                },
                borderColor: function(context) {
                    return context.raw >= 0 ? 'rgba(16, 185, 129, 1)' : 'rgba(239, 68, 68, 1)';
                },
                borderWidth: 2,
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
                        label: function(context) {
                            const value = context.parsed.y;
                            return (value >= 0 ? '+' : '') + value.toLocaleString('fr-FR') + ' F';
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        color: '#94a3b8',
                        callback: (value) => (value >= 0 ? '+' : '') + value.toLocaleString('fr-FR') + ' F'
                    },
                    grid: { color: '#334155' }
                },
                x: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } }
            }
        }
    });
    </script>
</body>
</html>
