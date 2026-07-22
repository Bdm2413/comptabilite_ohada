<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Tableau de Bord Budgétaire";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres
$annee = $_GET['annee'] ?? date('Y');
$version_filter = $_GET['version'] ?? '';

// Récupérer les versions disponibles
$stmt = $db->prepare("SELECT id, annee, version, statut FROM budget_versions WHERE societe_id = ? ORDER BY annee DESC, created_at DESC");
$stmt->execute([$societe_id]);
$versions = $stmt->fetchAll();

// Récupérer la version active si non spécifiée
if (empty($version_filter) && !empty($versions)) {
    foreach ($versions as $v) {
        if ($v['annee'] == $annee && $v['statut'] === 'Validé') {
            $version_filter = $v['id'];
            break;
        }
    }
    // Si aucune version validée, prendre la dernière en cours
    if (empty($version_filter)) {
        foreach ($versions as $v) {
            if ($v['annee'] == $annee) {
                $version_filter = $v['id'];
                break;
            }
        }
    }
}

// Récupérer les informations de la version sélectionnée
$budget_version = null;
if ($version_filter) {
    $stmt = $db->prepare("SELECT * FROM budget_versions WHERE id = ? AND societe_id = ?");
    $stmt->execute([$version_filter, $societe_id]);
    $budget_version = $stmt->fetch();
}

// Fonction pour calculer le réalisé comptable
function getRealiseComptable($db, $compte, $annee, $mois = null, $societe_id = null) {
    $date_debut = "$annee-01-01";
    $date_fin = $mois ? "$annee-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-31" : "$annee-12-31";

    $classe = substr($compte, 0, 1);

    if ($classe == '6') { // Charges
        $sql = "SELECT SUM(debit) - SUM(credit) as montant
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE le.compte LIKE ? AND e.statut = 'Validé' AND e.date_ecriture BETWEEN ? AND ?
                AND e.societe_id = ?";
    } else if ($classe == '7') { // Produits
        $sql = "SELECT SUM(credit) - SUM(debit) as montant
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE le.compte LIKE ? AND e.statut = 'Validé' AND e.date_ecriture BETWEEN ? AND ?
                AND e.societe_id = ?";
    } else {
        return 0;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([$compte . '%', $date_debut, $date_fin, $societe_id]);
    $result = $stmt->fetch();
    return abs($result['montant'] ?? 0);
}

// Calculer les statistiques globales
$stats = [
    'total_budget' => 0,
    'total_realise' => 0,
    'nb_comptes_depassement' => 0,
    'nb_comptes_sous_consommes' => 0
];

if ($budget_version) {
    // Récupérer toutes les lignes budgétaires
    $stmt = $db->prepare("SELECT * FROM budget_lignes WHERE id_budget_version = ?");
    $stmt->execute([$budget_version['id']]);
    $lignes = $stmt->fetchAll();

    $mois_actuel = (int)date('m');
    $annee_actuelle = (int)date('Y');

    foreach ($lignes as $ligne) {
        // Budget cumulé jusqu'au mois actuel si on est dans l'année budgétée
        if ($annee == $annee_actuelle && $mois_actuel <= 12) {
            $mois_names = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
                          'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
            $budget_cumule = 0;
            for ($i = 0; $i < $mois_actuel; $i++) {
                $budget_cumule += $ligne[$mois_names[$i]];
            }
        } else {
            $budget_cumule = $ligne['total_annuel'];
        }

        $stats['total_budget'] += $budget_cumule;

        // Réalisé cumulé
        $realise = getRealiseComptable($db, $ligne['compte'], $annee, ($annee == $annee_actuelle ? $mois_actuel : null), $societe_id);
        $stats['total_realise'] += $realise;

        // Alertes
        if ($budget_cumule > 0) {
            $taux = ($realise / $budget_cumule) * 100;
            if ($taux > 100) {
                $stats['nb_comptes_depassement']++;
            } else if ($taux < 80) {
                $stats['nb_comptes_sous_consommes']++;
            }
        }
    }
}

$taux_global = $stats['total_budget'] > 0 ? ($stats['total_realise'] / $stats['total_budget']) * 100 : 0;
$ecart_global = $stats['total_realise'] - $stats['total_budget'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        #sidebar { opacity: 1 !important; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-600 mb-2">
                            <i class="fas fa-tachometer-alt mr-3"></i>Tableau de Bord Budgétaire
                        </h1>
                        <p class="text-slate-400">Vue d'ensemble de l'exécution budgétaire</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Année</label>
                        <select name="annee" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                            <?php
                            $annees_disponibles = array_unique(array_column($versions, 'annee'));
                            rsort($annees_disponibles);
                            foreach ($annees_disponibles as $a):
                            ?>
                                <option value="<?= $a ?>" <?= $a == $annee ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Version</label>
                        <select name="version" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                            <?php foreach ($versions as $v): ?>
                                <?php if ($v['annee'] == $annee): ?>
                                    <option value="<?= $v['id'] ?>" <?= $v['id'] == $version_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($v['version']) ?> (<?= $v['statut'] ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white rounded-lg transition-all">
                            <i class="fas fa-filter mr-2"></i>Filtrer
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!$budget_version): ?>
                <div class="bg-yellow-900/20 border border-yellow-700/50 rounded-lg p-6 text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-400 text-4xl mb-3"></i>
                    <p class="text-yellow-300 text-lg">Aucun budget n'est disponible pour cette année.</p>
                    <a href="saisie_budget.php" class="inline-block mt-4 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-plus mr-2"></i>Créer un budget
                    </a>
                </div>
            <?php else: ?>

                <!-- Indicateurs clés -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <!-- Budget Total -->
                    <div class="bg-gradient-to-br from-blue-900/50 to-blue-800/30 border border-blue-700/50 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-blue-300 font-semibold">Budget Total</h3>
                            <i class="fas fa-wallet text-2xl text-blue-400"></i>
                        </div>
                        <p class="text-3xl font-bold text-white"><?= number_format($stats['total_budget'], 0, ',', ' ') ?> F</p>
                        <p class="text-sm text-blue-200 mt-1">Exercice <?= $annee ?></p>
                    </div>

                    <!-- Réalisé -->
                    <div class="bg-gradient-to-br from-green-900/50 to-green-800/30 border border-green-700/50 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-green-300 font-semibold">Réalisé</h3>
                            <i class="fas fa-check-circle text-2xl text-green-400"></i>
                        </div>
                        <p class="text-3xl font-bold text-white"><?= number_format($stats['total_realise'], 0, ',', ' ') ?> F</p>
                        <p class="text-sm text-green-200 mt-1">
                            Taux: <?= number_format($taux_global, 1) ?>%
                        </p>
                    </div>

                    <!-- Écart -->
                    <div class="bg-gradient-to-br from-<?= $ecart_global >= 0 ? 'orange' : 'purple' ?>-900/50 to-<?= $ecart_global >= 0 ? 'orange' : 'purple' ?>-800/30 border border-<?= $ecart_global >= 0 ? 'orange' : 'purple' ?>-700/50 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-<?= $ecart_global >= 0 ? 'orange' : 'purple' ?>-300 font-semibold">Écart</h3>
                            <i class="fas fa-<?= $ecart_global >= 0 ? 'exclamation-triangle' : 'minus-circle' ?> text-2xl text-<?= $ecart_global >= 0 ? 'orange' : 'purple' ?>-400"></i>
                        </div>
                        <p class="text-3xl font-bold text-white">
                            <?= $ecart_global > 0 ? '+' : '' ?><?= number_format($ecart_global, 0, ',', ' ') ?> F
                        </p>
                        <p class="text-sm text-<?= $ecart_global >= 0 ? 'orange' : 'purple' ?>-200 mt-1">
                            <?= $ecart_global >= 0 ? 'Dépassement' : 'Sous-consommation' ?>
                        </p>
                    </div>

                    <!-- Alertes -->
                    <div class="bg-gradient-to-br from-red-900/50 to-red-800/30 border border-red-700/50 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-red-300 font-semibold">Alertes</h3>
                            <i class="fas fa-bell text-2xl text-red-400"></i>
                        </div>
                        <p class="text-3xl font-bold text-white"><?= $stats['nb_comptes_depassement'] ?></p>
                        <p class="text-sm text-red-200 mt-1">Comptes en dépassement</p>
                    </div>
                </div>

                <!-- Graphiques -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Graphique Budget vs Réalisé par classe -->
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                        <h3 class="text-xl font-bold text-white mb-4">
                            <i class="fas fa-chart-bar mr-2 text-indigo-400"></i>
                            Budget vs Réalisé par Classe
                        </h3>
                        <div style="position: relative; height: 300px;">
                            <canvas id="chartClasses"></canvas>
                        </div>
                    </div>

                    <!-- Graphique évolution mensuelle -->
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                        <h3 class="text-xl font-bold text-white mb-4">
                            <i class="fas fa-chart-line mr-2 text-purple-400"></i>
                            Évolution Mensuelle Cumulée
                        </h3>
                        <div style="position: relative; height: 300px;">
                            <canvas id="chartMensuel"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top alertes -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                    <h3 class="text-xl font-bold text-white mb-4">
                        <i class="fas fa-exclamation-circle mr-2 text-orange-400"></i>
                        Comptes en Alerte
                    </h3>

                    <?php
                    // Récupérer les comptes en alerte
                    $alertes = [];
                    foreach ($lignes as $ligne) {
                        $realise = getRealiseComptable($db, $ligne['compte'], $annee, ($annee == date('Y') ? date('m') : null), $societe_id);
                        $budget = $ligne['total_annuel'];

                        if ($budget > 0) {
                            $taux = ($realise / $budget) * 100;
                            if ($taux > 100 || $taux < 80) {
                                // Récupérer l'intitulé du compte
                                $stmt_compte = $db->prepare("SELECT intitule_compte FROM plan_comptable WHERE compte = ? AND societe_id = ?");
                                $stmt_compte->execute([$ligne['compte'], $societe_id]);
                                $compte_info = $stmt_compte->fetch();

                                $alertes[] = [
                                    'compte' => $ligne['compte'],
                                    'intitule' => $compte_info['intitule_compte'] ?? 'Compte inconnu',
                                    'budget' => $budget,
                                    'realise' => $realise,
                                    'taux' => $taux,
                                    'ecart' => $realise - $budget,
                                    'type' => $taux > 100 ? 'Dépassement' : 'Sous-consommation'
                                ];
                            }
                        }
                    }

                    // Trier par écart absolu décroissant
                    usort($alertes, function($a, $b) {
                        return abs($b['ecart']) - abs($a['ecart']);
                    });

                    // Limiter aux 10 premiers
                    $alertes = array_slice($alertes, 0, 10);
                    ?>

                    <?php if (empty($alertes)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-check-circle text-green-400 text-4xl mb-3"></i>
                            <p class="text-green-300">Aucune alerte budgétaire pour le moment</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-slate-300 font-semibold">Compte</th>
                                        <th class="px-4 py-3 text-right text-slate-300 font-semibold">Budget</th>
                                        <th class="px-4 py-3 text-right text-slate-300 font-semibold">Réalisé</th>
                                        <th class="px-4 py-3 text-right text-slate-300 font-semibold">Taux</th>
                                        <th class="px-4 py-3 text-right text-slate-300 font-semibold">Écart</th>
                                        <th class="px-4 py-3 text-center text-slate-300 font-semibold">Type</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php foreach ($alertes as $alerte): ?>
                                        <tr class="hover:bg-slate-700/30 transition-colors">
                                            <td class="px-4 py-3">
                                                <div class="font-semibold text-white"><?= htmlspecialchars($alerte['compte']) ?></div>
                                                <div class="text-sm text-slate-400"><?= htmlspecialchars($alerte['intitule']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono text-slate-300">
                                                <?= number_format($alerte['budget'], 0, ',', ' ') ?>
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono text-white">
                                                <?= number_format($alerte['realise'], 0, ',', ' ') ?>
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono">
                                                <span class="<?= $alerte['taux'] > 100 ? 'text-red-400' : 'text-yellow-400' ?>">
                                                    <?= number_format($alerte['taux'], 1) ?>%
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono font-bold">
                                                <span class="<?= $alerte['ecart'] > 0 ? 'text-red-400' : 'text-yellow-400' ?>">
                                                    <?= $alerte['ecart'] > 0 ? '+' : '' ?><?= number_format($alerte['ecart'], 0, ',', ' ') ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $alerte['type'] === 'Dépassement' ? 'bg-red-900/50 text-red-300' : 'bg-yellow-900/50 text-yellow-300' ?>">
                                                    <?= $alerte['type'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </main>
    </div>

    <?php if ($budget_version): ?>
    <script>
        // Données pour les graphiques
        <?php
        // Préparer les données par classe
        $classes_data = [
            '6' => ['budget' => 0, 'realise' => 0, 'label' => 'Charges (Classe 6)'],
            '7' => ['budget' => 0, 'realise' => 0, 'label' => 'Produits (Classe 7)']
        ];

        foreach ($lignes as $ligne) {
            $classe = substr($ligne['compte'], 0, 1);
            if (isset($classes_data[$classe])) {
                $classes_data[$classe]['budget'] += $ligne['total_annuel'];
                $classes_data[$classe]['realise'] += getRealiseComptable($db, $ligne['compte'], $annee, null, $societe_id);
            }
        }

        // Données mensuelles
        $mois_names = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                      'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $mois_fields = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
                       'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];

        $mensuel_data = [];
        for ($i = 0; $i < 12; $i++) {
            $budget_cumule = 0;
            $realise_cumule = 0;

            foreach ($lignes as $ligne) {
                for ($j = 0; $j <= $i; $j++) {
                    $budget_cumule += $ligne[$mois_fields[$j]];
                }
                $realise_cumule += getRealiseComptable($db, $ligne['compte'], $annee, $i + 1, $societe_id);
            }

            $mensuel_data[] = [
                'mois' => $mois_names[$i],
                'budget' => $budget_cumule,
                'realise' => $realise_cumule
            ];
        }
        ?>

        const classesData = <?= json_encode(array_values($classes_data)) ?>;
        const mensuelData = <?= json_encode($mensuel_data) ?>;

        // Graphique par classe
        new Chart(document.getElementById('chartClasses'), {
            type: 'bar',
            data: {
                labels: classesData.map(d => d.label),
                datasets: [{
                    label: 'Budget',
                    data: classesData.map(d => d.budget),
                    backgroundColor: 'rgba(99, 102, 241, 0.7)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1
                }, {
                    label: 'Réalisé',
                    data: classesData.map(d => d.realise),
                    backgroundColor: 'rgba(34, 197, 94, 0.7)',
                    borderColor: 'rgba(34, 197, 94, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#e2e8f0' }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    },
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    }
                }
            }
        });

        // Graphique mensuel
        new Chart(document.getElementById('chartMensuel'), {
            type: 'line',
            data: {
                labels: mensuelData.map(d => d.mois),
                datasets: [{
                    label: 'Budget Cumulé',
                    data: mensuelData.map(d => d.budget),
                    borderColor: 'rgba(99, 102, 241, 1)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Réalisé Cumulé',
                    data: mensuelData.map(d => d.realise),
                    borderColor: 'rgba(34, 197, 94, 1)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#e2e8f0' }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    },
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
