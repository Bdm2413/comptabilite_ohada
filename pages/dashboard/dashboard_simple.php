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

// Récupérer les données directement (sans API)
function getKPIsData($db) {
    $anneeActuelle = date('Y');
    $moisActuel = getMoisActuel();

    // Écritures
    $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures");
    $totalEcritures = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Brouillon'");
    $brouillon = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Validé'");
    $valides = $stmt->fetch()['total'];

    // Trésorerie
    $stmt = $db->query("
        SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE (LEFT(le.compte, 2) = '57' OR LEFT(le.compte, 3) = '521')
          AND e.statut = 'Validé'
    ");
    $tresorerie = $stmt->fetch()['solde'];

    // CA du mois
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE LEFT(le.compte, 1) = '7'
          AND e.statut = 'Validé'
          AND e.annee = ?
          AND e.mois = ?
    ");
    $stmt->execute([$anneeActuelle, $moisActuel]);
    $caMois = $stmt->fetch()['ca'];

    // Charges du mois
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as charges
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE LEFT(le.compte, 1) = '6'
          AND e.statut = 'Validé'
          AND e.annee = ?
          AND e.mois = ?
    ");
    $stmt->execute([$anneeActuelle, $moisActuel]);
    $chargesMois = $stmt->fetch()['charges'];

    return [
        'ecritures' => ['total' => $totalEcritures, 'brouillon' => $brouillon, 'valides' => $valides],
        'tresorerie' => $tresorerie,
        'ca_mois' => $caMois,
        'charges_mois' => $chargesMois,
        'resultat_mois' => $caMois - $chargesMois,
        'mois' => $moisActuel,
        'annee' => $anneeActuelle
    ];
}

function getCAMensuelData($db) {
    $stmt = $db->query("
        SELECT
            e.annee,
            e.mois,
            COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
        FROM ecritures e
        INNER JOIN lignes_ecriture le ON e.id = le.id_ecriture
        WHERE LEFT(le.compte, 1) = '7'
          AND e.statut = 'Validé'
          AND e.date_ecriture >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY e.annee, e.mois
        ORDER BY e.annee DESC,
                 FIELD(e.mois, 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                               'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre') DESC
        LIMIT 12
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Inverser l'ordre pour avoir du plus ancien au plus récent
    return array_reverse($data);
}

function getChargesData($db) {
    $annee = date('Y');
    $mois = getMoisActuel();

    $stmt = $db->prepare("
        SELECT
            LEFT(le.compte, 2) as classe_compte,
            CASE LEFT(le.compte, 2)
                WHEN '60' THEN 'Achats'
                WHEN '61' THEN 'Transports'
                WHEN '62' THEN 'Services ext.'
                WHEN '63' THEN 'Autres services'
                WHEN '64' THEN 'Impôts'
                WHEN '65' THEN 'Autres charges'
                WHEN '66' THEN 'Charges fin.'
                WHEN '68' THEN 'Amortissements'
                ELSE 'Autres'
            END as categorie,
            COALESCE(SUM(le.debit) - SUM(le.credit), 0) as montant
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE LEFT(le.compte, 1) = '6'
          AND e.statut = 'Validé'
          AND e.annee = ?
          AND e.mois = ?
        GROUP BY LEFT(le.compte, 2)
        HAVING montant > 0
        ORDER BY montant DESC
    ");
    $stmt->execute([$annee, $mois]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMoisActuel() {
    $mois = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    return $mois[(int)date('n')];
}

$kpis = getKPIsData($db);
$caMensuel = getCAMensuelData($db);
$charges = getChargesData($db);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Interactif - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
                    <button onclick="location.reload()" class="px-4 py-2 bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 rounded-lg transition">
                        🔄 Actualiser
                    </button>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="p-6 space-y-6">
                <!-- KPIs Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Trésorerie -->
                    <div class="bg-gradient-to-br from-emerald-500/10 to-emerald-600/10 border border-emerald-500/30 rounded-xl p-6 hover:border-emerald-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-emerald-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-emerald-500/20 text-emerald-300 rounded">Trésorerie</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($kpis['tresorerie'], 0, ',', ' '); ?> FCFA</h3>
                        <p class="text-sm text-slate-400">Disponible</p>
                    </div>

                    <!-- CA du mois -->
                    <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/10 border border-blue-500/30 rounded-xl p-6 hover:border-blue-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-blue-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-blue-500/20 text-blue-300 rounded">CA Mois</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($kpis['ca_mois'], 0, ',', ' '); ?> FCFA</h3>
                        <p class="text-sm text-slate-400"><?php echo $kpis['mois'] . ' ' . $kpis['annee']; ?></p>
                    </div>

                    <!-- Résultat -->
                    <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/10 border border-purple-500/30 rounded-xl p-6 hover:border-purple-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-purple-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 <?php echo $kpis['resultat_mois'] >= 0 ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'; ?> rounded">Résultat</span>
                        </div>
                        <h3 class="text-3xl font-bold <?php echo $kpis['resultat_mois'] >= 0 ? 'text-emerald-400' : 'text-red-400'; ?> mb-1"><?php echo number_format($kpis['resultat_mois'], 0, ',', ' '); ?> FCFA</h3>
                        <p class="text-sm text-slate-400"><?php echo $kpis['mois'] . ' ' . $kpis['annee']; ?></p>
                    </div>

                    <!-- Écritures -->
                    <div class="bg-gradient-to-br from-orange-500/10 to-orange-600/10 border border-orange-500/30 rounded-xl p-6 hover:border-orange-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-orange-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-orange-500/20 text-orange-300 rounded">Écritures</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($kpis['ecritures']['total']); ?></h3>
                        <p class="text-sm text-slate-400">
                            <span class="text-yellow-400"><?php echo $kpis['ecritures']['brouillon']; ?> brouillon(s)</span> •
                            <span class="text-green-400"><?php echo $kpis['ecritures']['valides']; ?> validée(s)</span>
                        </p>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Widget: CA Mensuel -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6">
                        <h3 class="text-lg font-semibold text-white mb-4">📈 Chiffre d'Affaires Mensuel</h3>
                        <canvas id="chartCAMensuel" height="250"></canvas>
                    </div>

                    <!-- Widget: Charges -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6">
                        <h3 class="text-lg font-semibold text-white mb-4">💰 Charges par Catégorie</h3>
                        <canvas id="chartCharges" height="250"></canvas>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    // Configuration Chart.js
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = '#334155';

    // Données PHP vers JavaScript
    const caMensuelData = <?php echo json_encode($caMensuel); ?>;
    const chargesData = <?php echo json_encode($charges); ?>;

    // Chart CA Mensuel
    const ctxCA = document.getElementById('chartCAMensuel').getContext('2d');
    new Chart(ctxCA, {
        type: 'line',
        data: {
            labels: caMensuelData.map(d => d.mois.substring(0, 3) + ' ' + d.annee),
            datasets: [{
                label: 'Chiffre d\'Affaires',
                data: caMensuelData.map(d => parseFloat(d.ca)),
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
                        label: (context) => {
                            return new Intl.NumberFormat('fr-FR').format(context.parsed.y) + ' FCFA';
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: (value) => {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                            return value;
                        }
                    }
                }
            }
        }
    });

    // Chart Charges
    const ctxCharges = document.getElementById('chartCharges').getContext('2d');
    new Chart(ctxCharges, {
        type: 'bar',
        data: {
            labels: chargesData.map(d => d.categorie),
            datasets: [{
                label: 'Charges',
                data: chargesData.map(d => parseFloat(d.montant)),
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
                        label: (context) => {
                            return new Intl.NumberFormat('fr-FR').format(context.parsed.y) + ' FCFA';
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: (value) => {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                            return value;
                        }
                    }
                }
            }
        }
    });
    </script>
</body>
</html>
