<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Analyse Comparative";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les versions de budget disponibles
$stmt = $db->prepare("SELECT id, annee, version, statut FROM budget_versions WHERE societe_id = ? ORDER BY annee DESC, version");
$stmt->execute([$societe_id]);
$versions_disponibles = $stmt->fetchAll();

// Paramètres de comparaison
$version1_id = $_GET['version1'] ?? null;
$version2_id = $_GET['version2'] ?? null;
$mode_comparaison = $_GET['mode'] ?? 'global'; // global, par_classe, par_compte

$donnees_comparaison = null;
$version1_info = null;
$version2_info = null;

if ($version1_id && $version2_id) {
    // Récupérer les infos des versions
    $stmt = $db->prepare("SELECT id, annee, version, statut FROM budget_versions WHERE id = ? AND societe_id = ?");
    $stmt->execute([$version1_id, $societe_id]);
    $version1_info = $stmt->fetch();

    $stmt = $db->prepare("SELECT id, annee, version, statut FROM budget_versions WHERE id = ? AND societe_id = ?");
    $stmt->execute([$version2_id, $societe_id]);
    $version2_info = $stmt->fetch();

    if ($version1_info && $version2_info) {
        // Récupérer les données selon le mode
        if ($mode_comparaison === 'par_classe') {
            // Comparaison par classe de compte
            $sql = "SELECT
                        SUBSTRING(bl.compte, 1, 1) as classe,
                        SUM(bl.total_annuel) as total
                    FROM budget_lignes bl
                    WHERE bl.id_budget_version = ?
                    GROUP BY SUBSTRING(bl.compte, 1, 1)
                    ORDER BY classe";

            $stmt = $db->prepare($sql);
            $stmt->execute([$version1_id]);
            $data_v1 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $stmt->execute([$version2_id]);
            $data_v2 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $donnees_comparaison = ['mode' => 'classe', 'v1' => $data_v1, 'v2' => $data_v2];

        } elseif ($mode_comparaison === 'par_compte') {
            // Comparaison détaillée par compte
            $sql = "SELECT bl.compte, bl.total_annuel, bl.janvier, bl.fevrier, bl.mars, bl.avril,
                           bl.mai, bl.juin, bl.juillet, bl.aout, bl.septembre, bl.octobre, bl.novembre, bl.decembre
                    FROM budget_lignes bl
                    WHERE bl.id_budget_version = ?
                    ORDER BY bl.compte";

            $stmt = $db->prepare($sql);
            $stmt->execute([$version1_id]);
            $data_v1 = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

            $stmt->execute([$version2_id]);
            $data_v2 = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

            // Fusionner tous les comptes
            $tous_comptes = array_unique(array_merge(array_keys($data_v1), array_keys($data_v2)));
            sort($tous_comptes);

            $donnees_comparaison = ['mode' => 'compte', 'comptes' => $tous_comptes, 'v1' => $data_v1, 'v2' => $data_v2];

        } else {
            // Comparaison globale (Charges vs Produits)
            $sql = "SELECT
                        CASE
                            WHEN SUBSTRING(bl.compte, 1, 1) = '6' THEN 'Charges'
                            WHEN SUBSTRING(bl.compte, 1, 1) = '7' THEN 'Produits'
                            ELSE 'Autre'
                        END as categorie,
                        SUM(bl.total_annuel) as total
                    FROM budget_lignes bl
                    WHERE bl.id_budget_version = ?
                    GROUP BY categorie
                    ORDER BY categorie DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute([$version1_id]);
            $data_v1 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $stmt->execute([$version2_id]);
            $data_v2 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $donnees_comparaison = ['mode' => 'global', 'v1' => $data_v1, 'v2' => $data_v2];
        }
    }
}

function formatMontantBudget($montant) {
    return number_format($montant, 0, ',', ' ');
}

function calculerEvolution($v1, $v2) {
    if ($v1 == 0) return $v2 > 0 ? 100 : 0;
    return (($v2 - $v1) / abs($v1)) * 100;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-orange-400 to-red-600 mb-2">
                            <i class="fas fa-chart-bar mr-3"></i>Analyse Comparative
                        </h1>
                        <p class="text-slate-400">Comparer les budgets entre différentes années ou versions</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Sélection des versions à comparer -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 mb-6">
                <form method="GET" id="formComparaison" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Version 1 (Base)</label>
                        <select name="version1" id="version1" required onchange="this.form.submit()"
                                class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500">
                            <option value="">Sélectionner...</option>
                            <?php foreach ($versions_disponibles as $v): ?>
                                <option value="<?= $v['id'] ?>" <?= $version1_id == $v['id'] ? 'selected' : '' ?>>
                                    <?= $v['annee'] ?> - <?= htmlspecialchars($v['version']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Version 2 (Comparaison)</label>
                        <select name="version2" id="version2" required onchange="this.form.submit()"
                                class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500">
                            <option value="">Sélectionner...</option>
                            <?php foreach ($versions_disponibles as $v): ?>
                                <option value="<?= $v['id'] ?>" <?= $version2_id == $v['id'] ? 'selected' : '' ?>>
                                    <?= $v['annee'] ?> - <?= htmlspecialchars($v['version']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Mode de comparaison</label>
                        <select name="mode" id="mode" onchange="this.form.submit()"
                                class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500">
                            <option value="global" <?= $mode_comparaison === 'global' ? 'selected' : '' ?>>Vue Globale</option>
                            <option value="par_classe" <?= $mode_comparaison === 'par_classe' ? 'selected' : '' ?>>Par Classe</option>
                            <option value="par_compte" <?= $mode_comparaison === 'par_compte' ? 'selected' : '' ?>>Par Compte</option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2 bg-gradient-to-r from-orange-600 to-orange-700 hover:from-orange-700 hover:to-orange-800 text-white rounded-lg transition-all">
                            <i class="fas fa-search mr-2"></i>Comparer
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($donnees_comparaison): ?>
                <!-- Résultats de comparaison -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 mb-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-white">
                            <i class="fas fa-exchange-alt mr-2 text-orange-400"></i>
                            Comparaison : <?= $version1_info['annee'] ?> <?= htmlspecialchars($version1_info['version']) ?>
                            <span class="text-slate-500 mx-2">vs</span>
                            <?= $version2_info['annee'] ?> <?= htmlspecialchars($version2_info['version']) ?>
                        </h2>
                    </div>

                    <?php if ($mode_comparaison === 'global'): ?>
                        <!-- Vue Globale -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div style="position: relative; height: 300px;">
                                <canvas id="chartComparaison"></canvas>
                            </div>
                            <div class="space-y-4">
                                <?php
                                $categories = array_unique(array_merge(array_keys($donnees_comparaison['v1']), array_keys($donnees_comparaison['v2'])));
                                foreach ($categories as $cat):
                                    $v1 = $donnees_comparaison['v1'][$cat] ?? 0;
                                    $v2 = $donnees_comparaison['v2'][$cat] ?? 0;
                                    $ecart = $v2 - $v1;
                                    $evolution = calculerEvolution($v1, $v2);
                                    $couleur = $cat === 'Produits' ? 'green' : 'red';
                                ?>
                                    <div class="bg-slate-800 rounded-lg p-4 border border-slate-700">
                                        <div class="flex items-center justify-between mb-2">
                                            <h3 class="text-<?= $couleur ?>-400 font-semibold"><?= $cat ?></h3>
                                            <span class="text-sm px-2 py-1 rounded <?= $evolution >= 0 ? 'bg-green-900/30 text-green-400' : 'bg-red-900/30 text-red-400' ?>">
                                                <?= $evolution >= 0 ? '+' : '' ?><?= number_format($evolution, 1) ?>%
                                            </span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <p class="text-slate-400">Version 1</p>
                                                <p class="text-white font-semibold"><?= formatMontantBudget($v1) ?> FCFA</p>
                                            </div>
                                            <div>
                                                <p class="text-slate-400">Version 2</p>
                                                <p class="text-white font-semibold"><?= formatMontantBudget($v2) ?> FCFA</p>
                                            </div>
                                        </div>
                                        <div class="mt-2 pt-2 border-t border-slate-700">
                                            <p class="text-slate-400 text-sm">Écart: <span class="text-white font-semibold"><?= formatMontantBudget(abs($ecart)) ?> FCFA</span></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php elseif ($mode_comparaison === 'par_classe'): ?>
                        <!-- Vue Par Classe -->
                        <div style="position: relative; height: 400px; margin-bottom: 2rem;">
                            <canvas id="chartClasses"></canvas>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-slate-300">Classe</th>
                                        <th class="px-4 py-3 text-right text-slate-300">Version 1</th>
                                        <th class="px-4 py-3 text-right text-slate-300">Version 2</th>
                                        <th class="px-4 py-3 text-right text-slate-300">Écart</th>
                                        <th class="px-4 py-3 text-center text-slate-300">Évolution</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php
                                    $classes_all = array_unique(array_merge(array_keys($donnees_comparaison['v1']), array_keys($donnees_comparaison['v2'])));
                                    sort($classes_all);
                                    foreach ($classes_all as $classe):
                                        $v1 = $donnees_comparaison['v1'][$classe] ?? 0;
                                        $v2 = $donnees_comparaison['v2'][$classe] ?? 0;
                                        $ecart = $v2 - $v1;
                                        $evolution = calculerEvolution($v1, $v2);
                                        $classe_nom = [
                                            '1' => 'Comptes de ressources durables',
                                            '2' => 'Comptes d\'actif immobilisé',
                                            '3' => 'Comptes de stocks',
                                            '4' => 'Comptes de tiers',
                                            '5' => 'Comptes de trésorerie',
                                            '6' => 'Comptes de charges',
                                            '7' => 'Comptes de produits',
                                            '8' => 'Comptes de résultat'
                                        ][$classe] ?? "Classe $classe";
                                    ?>
                                        <tr class="hover:bg-slate-700/30 transition-colors">
                                            <td class="px-4 py-3">
                                                <span class="font-semibold text-indigo-400">Classe <?= $classe ?></span>
                                                <br><span class="text-xs text-slate-400"><?= $classe_nom ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-right text-white"><?= formatMontantBudget($v1) ?></td>
                                            <td class="px-4 py-3 text-right text-white"><?= formatMontantBudget($v2) ?></td>
                                            <td class="px-4 py-3 text-right font-semibold <?= $ecart >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                                <?= $ecart >= 0 ? '+' : '' ?><?= formatMontantBudget($ecart) ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="px-3 py-1 rounded-full text-sm font-semibold <?= $evolution >= 0 ? 'bg-green-900/30 text-green-400' : 'bg-red-900/30 text-red-400' ?>">
                                                    <?= $evolution >= 0 ? '+' : '' ?><?= number_format($evolution, 1) ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php else: ?>
                        <!-- Vue Par Compte -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-slate-300">Compte</th>
                                        <th class="px-3 py-2 text-right text-slate-300">Version 1</th>
                                        <th class="px-3 py-2 text-right text-slate-300">Version 2</th>
                                        <th class="px-3 py-2 text-right text-slate-300">Écart</th>
                                        <th class="px-3 py-2 text-center text-slate-300">Évolution</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php
                                    $comptes_affiches = 0;
                                    $limite_affichage = 50;
                                    foreach ($donnees_comparaison['comptes'] as $compte):
                                        if ($comptes_affiches >= $limite_affichage) break;
                                        $v1 = $donnees_comparaison['v1'][$compte]['total_annuel'] ?? 0;
                                        $v2 = $donnees_comparaison['v2'][$compte]['total_annuel'] ?? 0;
                                        if ($v1 == 0 && $v2 == 0) continue;
                                        $ecart = $v2 - $v1;
                                        $evolution = calculerEvolution($v1, $v2);
                                        $comptes_affiches++;
                                    ?>
                                        <tr class="hover:bg-slate-700/30 transition-colors">
                                            <td class="px-3 py-2 font-mono text-indigo-400 font-semibold"><?= $compte ?></td>
                                            <td class="px-3 py-2 text-right text-white"><?= formatMontantBudget($v1) ?></td>
                                            <td class="px-3 py-2 text-right text-white"><?= formatMontantBudget($v2) ?></td>
                                            <td class="px-3 py-2 text-right font-semibold <?= $ecart >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                                <?= $ecart >= 0 ? '+' : '' ?><?= formatMontantBudget($ecart) ?>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <span class="px-2 py-1 rounded text-xs font-semibold <?= $evolution >= 0 ? 'bg-green-900/30 text-green-400' : 'bg-red-900/30 text-red-400' ?>">
                                                    <?= $evolution >= 0 ? '+' : '' ?><?= number_format($evolution, 1) ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($donnees_comparaison['comptes']) > $limite_affichage): ?>
                                <p class="text-center text-slate-400 text-sm mt-4">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Affichage limité aux <?= $limite_affichage ?> premiers comptes. Total: <?= count($donnees_comparaison['comptes']) ?> comptes.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Graphiques Chart.js -->
                <script>
                    <?php if ($mode_comparaison === 'global'): ?>
                        const dataGlobal = {
                            labels: <?= json_encode(array_keys($donnees_comparaison['v1'])) ?>,
                            datasets: [
                                {
                                    label: '<?= $version1_info['annee'] ?> - <?= addslashes($version1_info['version']) ?>',
                                    data: <?= json_encode(array_values($donnees_comparaison['v1'])) ?>,
                                    backgroundColor: 'rgba(99, 102, 241, 0.5)',
                                    borderColor: 'rgba(99, 102, 241, 1)',
                                    borderWidth: 2
                                },
                                {
                                    label: '<?= $version2_info['annee'] ?> - <?= addslashes($version2_info['version']) ?>',
                                    data: <?= json_encode(array_values($donnees_comparaison['v2'])) ?>,
                                    backgroundColor: 'rgba(251, 146, 60, 0.5)',
                                    borderColor: 'rgba(251, 146, 60, 1)',
                                    borderWidth: 2
                                }
                            ]
                        };

                        new Chart(document.getElementById('chartComparaison'), {
                            type: 'bar',
                            data: dataGlobal,
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'top', labels: { color: '#cbd5e1' } },
                                    title: { display: true, text: 'Comparaison Charges vs Produits', color: '#fff' }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: { color: '#cbd5e1' },
                                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                                    },
                                    x: {
                                        ticks: { color: '#cbd5e1' },
                                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                                    }
                                }
                            }
                        });
                    <?php elseif ($mode_comparaison === 'par_classe'): ?>
                        const dataClasses = {
                            labels: <?= json_encode(array_map(fn($c) => "Classe $c", array_keys($donnees_comparaison['v1']))) ?>,
                            datasets: [
                                {
                                    label: '<?= $version1_info['annee'] ?> - <?= addslashes($version1_info['version']) ?>',
                                    data: <?= json_encode(array_values($donnees_comparaison['v1'])) ?>,
                                    backgroundColor: 'rgba(99, 102, 241, 0.5)',
                                    borderColor: 'rgba(99, 102, 241, 1)',
                                    borderWidth: 2
                                },
                                {
                                    label: '<?= $version2_info['annee'] ?> - <?= addslashes($version2_info['version']) ?>',
                                    data: <?= json_encode(array_values($donnees_comparaison['v2'])) ?>,
                                    backgroundColor: 'rgba(251, 146, 60, 0.5)',
                                    borderColor: 'rgba(251, 146, 60, 1)',
                                    borderWidth: 2
                                }
                            ]
                        };

                        new Chart(document.getElementById('chartClasses'), {
                            type: 'bar',
                            data: dataClasses,
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'top', labels: { color: '#cbd5e1' } },
                                    title: { display: true, text: 'Comparaison par Classe de Compte', color: '#fff' }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: { color: '#cbd5e1' },
                                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                                    },
                                    x: {
                                        ticks: { color: '#cbd5e1' },
                                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                                    }
                                }
                            }
                        });
                    <?php endif; ?>
                </script>

            <?php else: ?>
                <!-- Message initial -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-12">
                    <div class="max-w-2xl mx-auto text-center">
                        <div class="w-32 h-32 bg-orange-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-chart-line text-6xl text-orange-400"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-4">Sélectionnez deux versions à comparer</h2>
                        <p class="text-slate-400 mb-6">
                            Choisissez deux versions de budget dans les listes déroulantes ci-dessus pour commencer l'analyse comparative.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
