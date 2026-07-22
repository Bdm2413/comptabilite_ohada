<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();

// Récupérer l'année sélectionnée
$annee = $_GET['annee'] ?? date('Y');

// Récupérer toutes les CCA actives qui concernent cette année
$stmt = $db->prepare("
    SELECT *
    FROM charges_constatees_avance
    WHERE societe_id = ?
    AND statut = 'Actif'
    AND (
        (YEAR(date_debut) <= ? AND YEAR(date_fin) >= ?)
        OR (YEAR(date_debut) = ? OR YEAR(date_fin) = ?)
    )
    ORDER BY date_debut
");
$stmt->execute([$societe_id, $annee, $annee, $annee, $annee]);
$charges = $stmt->fetchAll();

// Construire le récapitulatif mensuel
$recap_mensuel = [];
for ($mois = 1; $mois <= 12; $mois++) {
    $recap_mensuel[$mois] = [
        'mois' => $mois,
        'nom_mois' => date('F', mktime(0, 0, 0, $mois, 1)),
        'charges' => [],
        'total' => 0
    ];
}

// Répartir chaque charge dans les mois concernés
foreach ($charges as $charge) {
    $date_debut = new DateTime($charge['date_debut']);
    $date_fin = new DateTime($charge['date_fin']);
    $montant_mensuel = $charge['montant_mensuel'];

    // Parcourir tous les mois de la période
    $date_courante = clone $date_debut;
    while ($date_courante <= $date_fin) {
        $annee_courante = (int)$date_courante->format('Y');
        $mois_courant = (int)$date_courante->format('n');

        // Si ce mois est dans l'année sélectionnée
        if ($annee_courante == $annee) {
            $recap_mensuel[$mois_courant]['charges'][] = [
                'id' => $charge['id'],
                'numero_facture' => $charge['numero_facture'],
                'description' => $charge['description'],
                'compte_charge' => $charge['compte_charge'],
                'montant' => $montant_mensuel,
                'nb_mois_total' => $charge['nb_mois'],
                'montant_total' => $charge['montant_total']
            ];
            $recap_mensuel[$mois_courant]['total'] += $montant_mensuel;
        }

        // Passer au mois suivant
        $date_courante->modify('+1 month');
    }
}

$pageTitle = "Récapitulatif Mensuel CCA";
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
                        <h1 class="text-2xl font-bold text-white mb-2">📊 Récapitulatif Mensuel - Charges Constatées d'Avance</h1>
                        <p class="text-slate-400 text-sm">Vue par mois des montants à comptabiliser</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="export_recap_cca_excel.php?annee=<?= $annee ?>" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Export Excel
                        </a>
                        <a href="charges_avance.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                            Retour
                        </a>
                    </div>
                </div>

                <!-- Sélection année -->
                <div class="bg-slate-800 rounded-lg p-4 mb-6">
                    <form method="GET" class="flex gap-4 items-center">
                        <label class="text-sm font-medium text-slate-300">Année :</label>
                        <select name="annee" onchange="this.form.submit()" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                            <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $annee == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>

                <!-- Total annuel -->
                <?php
                $total_annuel = array_sum(array_column($recap_mensuel, 'total'));
                ?>
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg p-6 mb-6">
                    <div class="text-center">
                        <p class="text-blue-200 text-sm mb-2">Total des charges à répartir en <?= $annee ?></p>
                        <p class="text-4xl font-bold text-white"><?= number_format($total_annuel, 0, ',', ' ') ?> F</p>
                    </div>
                </div>

                <!-- Récapitulatif par mois -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($recap_mensuel as $mois_data): ?>
                    <?php
                    $mois_fr = [
                        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
                        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
                    ];
                    $nom_mois = $mois_fr[$mois_data['mois']];
                    $nb_charges = count($mois_data['charges']);
                    ?>
                    <div class="bg-slate-800 rounded-lg overflow-hidden hover:ring-2 hover:ring-blue-500 transition">
                        <!-- En-tête du mois -->
                        <div class="bg-slate-700 px-4 py-3 flex justify-between items-center">
                            <div>
                                <h3 class="font-semibold text-white"><?= $nom_mois ?></h3>
                                <p class="text-xs text-slate-400"><?= $nb_charges ?> charge(s)</p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-emerald-400"><?= number_format($mois_data['total'], 0, ',', ' ') ?> F</p>
                            </div>
                        </div>

                        <!-- Détail des charges -->
                        <?php if ($nb_charges > 0): ?>
                        <div class="p-3 space-y-2 max-h-64 overflow-y-auto">
                            <?php foreach ($mois_data['charges'] as $charge): ?>
                            <div class="bg-slate-700/50 rounded p-2 text-xs">
                                <div class="flex justify-between items-start mb-1">
                                    <span class="font-mono text-blue-400"><?= htmlspecialchars($charge['numero_facture']) ?></span>
                                    <span class="font-semibold text-white"><?= number_format($charge['montant'], 0, ',', ' ') ?> F</span>
                                </div>
                                <p class="text-slate-300 truncate mb-1" title="<?= htmlspecialchars($charge['description']) ?>">
                                    <?= htmlspecialchars($charge['description']) ?>
                                </p>
                                <div class="flex justify-between text-slate-500">
                                    <span><?= htmlspecialchars($charge['compte_charge']) ?></span>
                                    <span><?= $charge['nb_mois_total'] ?> mois</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="p-4 text-center text-slate-500 text-sm">
                            Aucune charge ce mois
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Graphique annuel -->
                <div class="bg-slate-800 rounded-lg p-6 mt-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Évolution mensuelle</h2>
                    <div style="height: 300px;">
                        <canvas id="chartEvolution"></canvas>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Graphique d'évolution
    const ctx = document.getElementById('chartEvolution').getContext('2d');
    const data = {
        labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
        datasets: [{
            label: 'Charges mensuelles (F)',
            data: [
                <?php foreach ($recap_mensuel as $m): ?>
                <?= $m['total'] ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: 'rgba(59, 130, 246, 0.2)',
            borderColor: 'rgba(59, 130, 246, 1)',
            borderWidth: 2,
            tension: 0.4,
            fill: true
        }]
    };

    new Chart(ctx, {
        type: 'line',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toLocaleString('fr-FR') + ' F';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#94a3b8',
                        callback: function(value) {
                            return value.toLocaleString('fr-FR') + ' F';
                        }
                    },
                    grid: {
                        color: '#334155'
                    }
                },
                x: {
                    ticks: {
                        color: '#94a3b8'
                    },
                    grid: {
                        color: '#334155'
                    }
                }
            }
        }
    });
    </script>
</body>
</html>
