<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Suivi Détaillé par Compte";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres
$annee = $_GET['annee'] ?? date('Y');
$id_version = $_GET['id_version'] ?? null;
$classe_filter = $_GET['classe'] ?? '';

// Récupérer les versions disponibles
$stmt = $db->prepare("SELECT id, annee, version, statut FROM budget_versions WHERE societe_id = ? ORDER BY annee DESC, created_at DESC");
$stmt->execute([$societe_id]);
$versions = $stmt->fetchAll();

// Récupérer la version active si non spécifiée
if (empty($id_version) && !empty($versions)) {
    foreach ($versions as $v) {
        if ($v['annee'] == $annee && $v['statut'] === 'Validé') {
            $id_version = $v['id'];
            break;
        }
    }
    if (empty($id_version)) {
        foreach ($versions as $v) {
            if ($v['annee'] == $annee) {
                $id_version = $v['id'];
                break;
            }
        }
    }
}

// Fonction pour calculer le réalisé
function getRealiseComptable($db, $compte, $annee, $societe_id = null) {
    $date_debut = "$annee-01-01";
    $date_fin = "$annee-12-31";
    $classe = substr($compte, 0, 1);

    if ($classe == '6') {
        $sql = "SELECT SUM(debit) - SUM(credit) as montant
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE le.compte = ? AND e.statut = 'Validé' AND e.date_ecriture BETWEEN ? AND ?
                AND e.societe_id = ?";
    } else if ($classe == '7') {
        $sql = "SELECT SUM(credit) - SUM(debit) as montant
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE le.compte = ? AND e.statut = 'Validé' AND e.date_ecriture BETWEEN ? AND ?
                AND e.societe_id = ?";
    } else {
        return 0;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([$compte, $date_debut, $date_fin, $societe_id]);
    $result = $stmt->fetch();
    return abs($result['montant'] ?? 0);
}

// Récupérer les lignes budgétaires
$lignes_budget = [];
if ($id_version) {
    $sql = "SELECT bl.*, pc.intitule_compte
            FROM budget_lignes bl
            LEFT JOIN plan_comptable pc ON bl.compte = pc.compte AND pc.societe_id = ?
            WHERE bl.id_budget_version = ?";

    if ($classe_filter) {
        $sql .= " AND bl.compte LIKE ?";
        $params = [$societe_id, $id_version, $classe_filter . '%'];
    } else {
        $params = [$societe_id, $id_version];
    }

    $sql .= " ORDER BY bl.compte";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $lignes_budget = $stmt->fetchAll();

    // Ajouter le réalisé
    foreach ($lignes_budget as &$ligne) {
        $ligne['realise'] = getRealiseComptable($db, $ligne['compte'], $annee, $societe_id);
        $ligne['ecart'] = $ligne['realise'] - $ligne['total_annuel'];
        $ligne['taux'] = $ligne['total_annuel'] > 0 ? ($ligne['realise'] / $ligne['total_annuel']) * 100 : 0;
    }
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
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                            <i class="fas fa-list-alt mr-3"></i>Suivi Détaillé par Compte
                        </h1>
                        <p class="text-slate-400">Analyse détaillée Budget vs Réalisé par compte</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Année</label>
                        <select name="annee" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-purple-500">
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
                        <select name="id_version" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-purple-500">
                            <?php foreach ($versions as $v): ?>
                                <?php if ($v['annee'] == $annee): ?>
                                    <option value="<?= $v['id'] ?>" <?= $v['id'] == $id_version ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($v['version']) ?> (<?= $v['statut'] ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Classe de compte</label>
                        <select name="classe" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-purple-500">
                            <option value="">Toutes les classes</option>
                            <option value="6" <?= $classe_filter === '6' ? 'selected' : '' ?>>Classe 6 - Charges</option>
                            <option value="7" <?= $classe_filter === '7' ? 'selected' : '' ?>>Classe 7 - Produits</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white rounded-lg transition-all">
                            <i class="fas fa-filter mr-2"></i>Filtrer
                        </button>
                    </div>
                </form>
            </div>

            <?php if (empty($lignes_budget)): ?>
                <div class="bg-yellow-900/20 border border-yellow-700/50 rounded-lg p-6 text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-400 text-4xl mb-3"></i>
                    <p class="text-yellow-300 text-lg">Aucune donnée budgétaire disponible.</p>
                    <a href="saisie_budget.php" class="inline-block mt-4 px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-plus mr-2"></i>Créer un budget
                    </a>
                </div>
            <?php else: ?>
                <!-- Tableau détaillé -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="p-6 border-b border-slate-700 flex items-center justify-between">
                        <h2 class="text-xl font-bold text-white">
                            <i class="fas fa-table mr-2 text-purple-400"></i>
                            Détail par Compte
                        </h2>
                        <a href="export_suivi_excel.php?id_version=<?= $id_version ?>&classe=<?= $classe_filter ?>" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-file-excel mr-2"></i>Export Excel
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-slate-300 font-semibold">Compte</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Budget Annuel</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Réalisé</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Écart</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Taux %</th>
                                    <th class="px-4 py-3 text-center text-slate-300 font-semibold">Statut</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php foreach ($lignes_budget as $ligne): ?>
                                    <tr class="hover:bg-slate-700/30 transition-colors">
                                        <td class="px-4 py-3">
                                            <div class="font-semibold text-white"><?= htmlspecialchars($ligne['compte']) ?></div>
                                            <div class="text-sm text-slate-400"><?= htmlspecialchars($ligne['intitule_compte']) ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-slate-300">
                                            <?= number_format($ligne['total_annuel'], 0, ',', ' ') ?> F
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-white font-semibold">
                                            <?= number_format($ligne['realise'], 0, ',', ' ') ?> F
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono font-bold <?= $ligne['ecart'] > 0 ? 'text-red-400' : 'text-green-400' ?>">
                                            <?= $ligne['ecart'] > 0 ? '+' : '' ?><?= number_format($ligne['ecart'], 0, ',', ' ') ?> F
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono">
                                            <span class="<?= $ligne['taux'] > 100 ? 'text-red-400' : ($ligne['taux'] < 80 ? 'text-yellow-400' : 'text-green-400') ?>">
                                                <?= number_format($ligne['taux'], 1) ?>%
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($ligne['taux'] > 100): ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-red-900/50 text-red-300">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>Dépassement
                                                </span>
                                            <?php elseif ($ligne['taux'] < 80): ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-yellow-900/50 text-yellow-300">
                                                    <i class="fas fa-arrow-down mr-1"></i>Sous-consommé
                                                </span>
                                            <?php else: ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-900/50 text-green-300">
                                                    <i class="fas fa-check mr-1"></i>Conforme
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-800 border-t-2 border-purple-600">
                                <tr>
                                    <td class="px-4 py-3 font-bold text-white">TOTAL</td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-purple-400">
                                        <?= number_format(array_sum(array_column($lignes_budget, 'total_annuel')), 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-white">
                                        <?= number_format(array_sum(array_column($lignes_budget, 'realise')), 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-purple-400">
                                        <?php
                                        $total_ecart = array_sum(array_column($lignes_budget, 'ecart'));
                                        echo ($total_ecart > 0 ? '+' : '') . number_format($total_ecart, 0, ',', ' ') . ' F';
                                        ?>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
