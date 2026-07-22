<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Gestion des Versions de Budget";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer toutes les versions
$stmt = $db->prepare("SELECT bv.*, COUNT(bl.id) as nb_lignes
                    FROM budget_versions bv
                    LEFT JOIN budget_lignes bl ON bv.id = bl.id_budget_version
                    WHERE bv.societe_id = ?
                    GROUP BY bv.id
                    ORDER BY bv.annee DESC, bv.created_at DESC");
$stmt->execute([$societe_id]);
$versions = $stmt->fetchAll();
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
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-600 mb-2">
                            <i class="fas fa-code-branch mr-3"></i>Gestion des Versions de Budget
                        </h1>
                        <p class="text-slate-400">Gérer les différentes versions de budgets (initial, révisé, etc.)</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="saisie_budget.php" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            Nouvelle version
                        </a>
                        <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>
                            Retour
                        </a>
                    </div>
                </div>
            </div>

            <?php if (empty($versions)): ?>
                <div class="bg-yellow-900/20 border border-yellow-700/50 rounded-lg p-8 text-center">
                    <i class="fas fa-folder-open text-yellow-400 text-5xl mb-4"></i>
                    <p class="text-yellow-300 text-lg mb-4">Aucune version de budget n'a encore été créée.</p>
                    <a href="saisie_budget.php" class="inline-block px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-plus mr-2"></i>Créer la première version
                    </a>
                </div>
            <?php else: ?>
                <!-- Liste des versions -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($versions as $version): ?>
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 hover:border-cyan-500 transition-all">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-1"><?= htmlspecialchars($version['version']) ?></h3>
                                    <p class="text-2xl font-bold text-cyan-400">Année <?= $version['annee'] ?></p>
                                </div>
                                <div>
                                    <?php
                                    $statut_colors = [
                                        'Brouillon' => 'bg-gray-900/50 text-gray-300',
                                        'En cours' => 'bg-blue-900/50 text-blue-300',
                                        'Validé' => 'bg-green-900/50 text-green-300',
                                        'Archivé' => 'bg-slate-900/50 text-slate-400'
                                    ];
                                    $color_class = $statut_colors[$version['statut']] ?? 'bg-gray-900/50 text-gray-300';
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $color_class ?>">
                                        <?= $version['statut'] ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($version['description']): ?>
                                <p class="text-slate-400 text-sm mb-4"><?= htmlspecialchars(substr($version['description'], 0, 100)) ?><?= strlen($version['description']) > 100 ? '...' : '' ?></p>
                            <?php endif; ?>

                            <div class="border-t border-slate-700 pt-4 mb-4">
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <p class="text-slate-400">Lignes budgétaires</p>
                                        <p class="text-white font-semibold"><?= $version['nb_lignes'] ?> comptes</p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400">Créé le</p>
                                        <p class="text-white font-semibold"><?= date('d/m/Y', strtotime($version['created_at'])) ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <a href="dashboard.php?annee=<?= $version['annee'] ?>&version=<?= $version['id'] ?>"
                                   class="flex-1 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-lg transition-colors text-center">
                                    <i class="fas fa-eye mr-1"></i>Voir
                                </a>
                                <a href="saisie_budget.php?id_version=<?= $version['id'] ?>"
                                   class="flex-1 px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg transition-colors text-center">
                                    <i class="fas fa-edit mr-1"></i>Modifier
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
