<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer l'ID de l'écriture
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Écriture introuvable'];
    header('Location: liste.php');
    exit;
}

$ecriture_id = intval($_GET['id']);

// Récupérer l'écriture
try {
    // Vérifier si la colonne id_bon_commande existe
    $columnExists = false;
    try {
        $checkColumn = $db->query("SHOW COLUMNS FROM ecritures LIKE 'id_bon_commande'");
        $columnExists = $checkColumn->rowCount() > 0;
    } catch (Exception $e) {
        $columnExists = false;
    }

    if ($columnExists) {
        $stmt = $db->prepare("
            SELECT e.*, cj.libelle as journal_libelle, pt.nom as tiers_nom, pt.type as tiers_type,
                   bc.numero_bc, bc.objet as bc_objet, bc.montant_ttc as bc_montant_ttc
            FROM ecritures e
            LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
            LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
            LEFT JOIN bons_commande bc ON e.id_bon_commande = bc.id
            WHERE e.id = ?
        ");
    } else {
        $stmt = $db->prepare("
            SELECT e.*, cj.libelle as journal_libelle, pt.nom as tiers_nom, pt.type as tiers_type
            FROM ecritures e
            LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
            LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
            WHERE e.id = ?
        ");
    }
    $stmt->execute([$ecriture_id]);
    $ecriture = $stmt->fetch();

    // Si un BC est associé, calculer sa consommation
    // Consommation basée sur les crédits des comptes 4011xxx (Fournisseurs) et 4812xxx (Fournisseurs d'immobilisations)
    $bcInfo = null;
    if ($columnExists && !empty($ecriture['id_bon_commande'])) {
        $stmtBC = $db->prepare("
            SELECT
                bc.id,
                bc.numero_bc,
                bc.objet,
                bc.montant_ttc,
                bc.statut as bc_statut,
                COALESCE((
                    SELECT SUM(le.credit)
                    FROM lignes_ecriture le
                    INNER JOIN ecritures e ON le.id_ecriture = e.id
                    WHERE e.id_bon_commande = bc.id
                      AND e.statut = 'Validé'
                      AND (le.compte LIKE '4011%' OR le.compte LIKE '4812%')
                      AND le.credit > 0
                ), 0) as montant_consomme
            FROM bons_commande bc
            WHERE bc.id = ?
        ");
        $stmtBC->execute([$ecriture['id_bon_commande']]);
        $bcInfo = $stmtBC->fetch();
        if ($bcInfo) {
            $bcInfo['reste'] = floatval($bcInfo['montant_ttc']) - floatval($bcInfo['montant_consomme']);
            $bcInfo['pourcentage'] = floatval($bcInfo['montant_ttc']) > 0
                ? round(floatval($bcInfo['montant_consomme']) / floatval($bcInfo['montant_ttc']) * 100, 1)
                : 0;
        }
    }

    if (!$ecriture) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Écriture introuvable'];
        header('Location: liste.php');
        exit;
    }

    // Récupérer les lignes
    $stmtLignes = $db->prepare("
        SELECT le.*, le.numero_facture, pc.intitule_compte as compte_intitule
        FROM lignes_ecriture le
        LEFT JOIN plan_comptable pc ON le.compte = pc.compte
        WHERE le.id_ecriture = ?
        ORDER BY le.id
    ");
    $stmtLignes->execute([$ecriture_id]);
    $lignes = $stmtLignes->fetchAll();

    // Calculer les totaux
    $total_debit = 0;
    $total_credit = 0;
    foreach ($lignes as $ligne) {
        $total_debit += $ligne['debit'];
        $total_credit += $ligne['credit'];
    }
    $equilibre = abs($total_debit - $total_credit) < 0.01;

} catch (Exception $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors du chargement: ' . $e->getMessage()];
    header('Location: liste.php');
    exit;
}

// Traitement de la validation rapide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'validate') {
    if ($ecriture['statut'] !== 'Brouillon') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Cette écriture est déjà validée'];
        header('Location: voir.php?id=' . $ecriture_id);
        exit;
    }

    // Vérifier l'équilibre avant validation
    if (!$equilibre) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Impossible de valider une écriture déséquilibrée'];
        header('Location: voir.php?id=' . $ecriture_id);
        exit;
    }

    // Vérifier que l'écriture n'est pas dans un exercice clôturé
    blockIfEcritureInClosedExercice($ecriture_id, $db, 'voir.php?id=' . $ecriture_id);

    try {
        $db->beginTransaction();

        // Mettre à jour le statut
        $stmt = $db->prepare("UPDATE ecritures SET statut = 'Validé' WHERE id = ?");
        $stmt->execute([$ecriture_id]);

        $db->commit();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Écriture validée avec succès'];
        header('Location: voir.php?id=' . $ecriture_id);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors de la validation: ' . $e->getMessage()];
    }
}

// Traitement de la suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if ($ecriture['statut'] !== 'Brouillon') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Seules les écritures en brouillon peuvent être supprimées'];
        header('Location: voir.php?id=' . $ecriture_id);
        exit;
    }

    try {
        $db->beginTransaction();

        // Supprimer les lignes
        $db->prepare("DELETE FROM lignes_ecriture WHERE id_ecriture = ?")->execute([$ecriture_id]);

        // Supprimer l'écriture
        $db->prepare("DELETE FROM ecritures WHERE id = ?")->execute([$ecriture_id]);

        $db->commit();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Écriture supprimée avec succès'];
        header('Location: liste.php');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression: ' . $e->getMessage()];
    }
}

function safe_number_format($number, $decimals = 2) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Écriture <?= htmlspecialchars($ecriture['numero_ecriture']) ?> - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast {
            min-width: 300px;
            max-width: 500px;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(400px);
            opacity: 0;
        }
        .toast-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .toast-error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .toast-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .toast-info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <header class="bg-slate-800/30 border-b border-slate-700/50 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-600 mb-2">
                            <i class="fas fa-eye mr-3"></i>Écriture <?= htmlspecialchars($ecriture['numero_ecriture']) ?>
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">
                            Créée le <?= date('d/m/Y à H:i', strtotime($ecriture['date_creation'])) ?>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($ecriture['extournee'] !== 'Oui'): ?>
                            <button onclick="confirmExtourne()" class="bg-gradient-to-r from-orange-600 to-orange-700 hover:from-orange-700 hover:to-orange-800 text-white px-4 py-2 rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                <i class="fas fa-undo"></i>
                                Extourner
                            </button>
                        <?php else: ?>
                            <span class="bg-orange-500/20 text-orange-400 px-4 py-2 rounded-lg inline-flex items-center gap-2 border border-orange-500/30">
                                <i class="fas fa-check-circle"></i>
                                Extournée
                            </span>
                        <?php endif; ?>

                        <button onclick="dupliquerEcriture(<?= $ecriture['id'] ?>)" class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white px-4 py-2 rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-copy"></i>
                            Dupliquer
                        </button>

                        <?php if ($ecriture['statut'] === 'Brouillon'): ?>
                            <?php if ($equilibre): ?>
                                <button onclick="confirmValidation()" class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-4 py-2 rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                    <i class="fas fa-check-circle"></i>
                                    Valider l'écriture
                                </button>
                            <?php endif; ?>
                            <a href="saisie.php?id=<?= $ecriture['id'] ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-edit"></i>
                                Modifier
                            </a>
                        <?php elseif (isAdmin()): ?>
                            <!-- Admin peut modifier les écritures validées -->
                            <a href="saisie.php?id=<?= $ecriture['id'] ?>" class="bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white px-4 py-2 rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2" title="Modification admin d'une écriture validée">
                                <i class="fas fa-user-shield mr-1"></i>
                                <i class="fas fa-edit"></i>
                                Modifier (Admin)
                            </a>
                        <?php endif; ?>
                        <a href="saisie.php" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-4 py-2 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            Nouvelle écriture
                        </a>
                        <a href="liste.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>
                            Retour à la liste
                        </a>
                    </div>
                </div>
            </header>

            <!-- Flash Message -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="m-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400' ?>">
                    <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
                    <?= $_SESSION['flash']['message'] ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Contenu -->
            <div class="p-6 space-y-6">
                <!-- Informations générales -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-6">
                    <div class="flex items-start justify-between mb-6">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <i class="fas fa-info-circle text-blue-400"></i>
                            Informations générales
                        </h3>
                        <div>
                            <?php if ($ecriture['statut'] === 'Validé'): ?>
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium bg-green-500/10 text-green-400 border border-green-500/50">
                                    <i class="fas fa-check-circle mr-2"></i>Validé
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium bg-orange-500/10 text-orange-400 border border-orange-500/50">
                                    <i class="fas fa-edit mr-2"></i>Brouillon
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 uppercase mb-1">Date de l'écriture</label>
                            <p class="text-white font-medium">
                                <i class="fas fa-calendar text-blue-400 mr-2"></i>
                                <?= date('d/m/Y', strtotime($ecriture['date_ecriture'])) ?>
                            </p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 uppercase mb-1">Journal</label>
                            <p class="text-white font-medium">
                                <i class="fas fa-book text-blue-400 mr-2"></i>
                                <?= htmlspecialchars($ecriture['journal']) ?> - <?= htmlspecialchars($ecriture['journal_libelle'] ?? '') ?>
                            </p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 uppercase mb-1">Période</label>
                            <p class="text-white font-medium">
                                <i class="fas fa-calendar-alt text-blue-400 mr-2"></i>
                                <?= htmlspecialchars($ecriture['mois']) ?> <?= htmlspecialchars($ecriture['annee']) ?>
                            </p>
                        </div>

                        <?php if ($ecriture['id_tiers']): ?>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 uppercase mb-1">Tiers</label>
                                <p class="text-white font-medium">
                                    <i class="fas fa-user text-blue-400 mr-2"></i>
                                    <?= htmlspecialchars($ecriture['tiers_nom']) ?>
                                    <span class="text-slate-400 text-sm">(<?= htmlspecialchars($ecriture['tiers_type']) ?>)</span>
                                </p>
                            </div>
                        <?php endif; ?>

                        <?php if ($ecriture['num_piece']): ?>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 uppercase mb-1">N° de pièce</label>
                                <p class="text-white font-medium">
                                    <i class="fas fa-hashtag text-blue-400 mr-2"></i>
                                    <?= htmlspecialchars($ecriture['num_piece']) ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <?php if ($ecriture['reference_piece']): ?>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 uppercase mb-1">Référence pièce</label>
                                <p class="text-white font-medium">
                                    <i class="fas fa-file-invoice text-blue-400 mr-2"></i>
                                    <?= htmlspecialchars($ecriture['reference_piece']) ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 uppercase mb-1">Montant total</label>
                            <p class="text-white font-bold text-lg">
                                <i class="fas fa-coins text-yellow-400 mr-2"></i>
                                <?= safe_number_format($ecriture['montant_total']) ?> FCFA
                            </p>
                        </div>
                    </div>

                    <?php if ($bcInfo): ?>
                        <div class="mt-6 pt-6 border-t border-slate-700">
                            <label class="block text-xs font-medium text-slate-500 uppercase mb-3">Bon de commande associé</label>
                            <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4">
                                <div class="flex items-start justify-between mb-3">
                                    <div>
                                        <a href="../achats/bc_form.php?id=<?= $bcInfo['id'] ?>" class="text-purple-300 font-semibold hover:text-purple-200 hover:underline">
                                            <i class="fas fa-file-invoice mr-2"></i><?= htmlspecialchars($bcInfo['numero_bc']) ?>
                                        </a>
                                        <p class="text-slate-400 text-sm mt-1"><?= htmlspecialchars($bcInfo['objet']) ?></p>
                                    </div>
                                    <span class="text-purple-400 font-bold text-lg"><?= $bcInfo['pourcentage'] ?>%</span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-3 mb-3">
                                    <div class="h-3 rounded-full transition-all duration-300 <?= $bcInfo['pourcentage'] >= 100 ? 'bg-gradient-to-r from-red-500 to-red-600' : ($bcInfo['pourcentage'] >= 80 ? 'bg-gradient-to-r from-orange-500 to-red-500' : 'bg-gradient-to-r from-purple-500 to-pink-500') ?>" style="width: <?= min($bcInfo['pourcentage'], 100) ?>%"></div>
                                </div>
                                <div class="grid grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <span class="text-slate-400">Montant BC:</span>
                                        <span class="text-white font-medium ml-1"><?= number_format($bcInfo['montant_ttc'], 0, ',', ' ') ?> FCFA</span>
                                    </div>
                                    <div>
                                        <span class="text-slate-400">Consommé:</span>
                                        <span class="text-pink-400 font-medium ml-1"><?= number_format($bcInfo['montant_consomme'], 0, ',', ' ') ?> FCFA</span>
                                    </div>
                                    <div>
                                        <span class="text-slate-400">Reste:</span>
                                        <span class="text-green-400 font-medium ml-1"><?= number_format($bcInfo['reste'], 0, ',', ' ') ?> FCFA</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6 pt-6 border-t border-slate-700">
                        <label class="block text-xs font-medium text-slate-500 uppercase mb-2">Libellé</label>
                        <p class="text-white"><?= nl2br(htmlspecialchars($ecriture['libelle'])) ?></p>

                        <?php if ($ecriture['id_ecriture_extourne']): ?>
                            <?php
                            // Récupérer l'écriture d'origine
                            $stmt_origine = $db->prepare("SELECT numero_ecriture FROM ecritures WHERE id = ?");
                            $stmt_origine->execute([$ecriture['id_ecriture_extourne']]);
                            $ecriture_origine = $stmt_origine->fetch();
                            ?>
                            <div class="mt-3 p-3 bg-orange-500/10 border border-orange-500/30 rounded-lg">
                                <p class="text-sm text-orange-300">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Cette écriture est une extourne de l'écriture
                                    <a href="voir.php?id=<?= $ecriture['id_ecriture_extourne'] ?>" class="font-semibold hover:underline">
                                        <?= htmlspecialchars($ecriture_origine['numero_ecriture']) ?>
                                    </a>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Lignes d'écriture -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg overflow-hidden">
                    <div class="p-6 border-b border-slate-700">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <i class="fas fa-list text-blue-400"></i>
                            Lignes d'écriture
                            <span class="ml-2 text-sm font-normal text-slate-400">(<?= count($lignes) ?> ligne<?= count($lignes) > 1 ? 's' : '' ?>)</span>
                        </h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-900/50 border-b border-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">Compte</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">Intitulé</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">N° Facture</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">Libellé</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-slate-400 uppercase">Débit</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-slate-400 uppercase">Crédit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/50">
                                <?php foreach ($lignes as $index => $ligne): ?>
                                    <tr class="hover:bg-slate-700/20 transition-colors">
                                        <td class="px-4 py-3 text-slate-400 font-mono text-sm"><?= $index + 1 ?></td>
                                        <td class="px-4 py-3">
                                            <div class="font-mono font-semibold">
                                                <a href="../rapports/grand_livre.php?compte=<?= urlencode($ligne['compte']) ?>"
                                                   class="text-blue-400 hover:text-blue-300 hover:underline transition-colors"
                                                   title="Voir le grand livre du compte <?= htmlspecialchars($ligne['compte']) ?>">
                                                    <?= htmlspecialchars($ligne['compte']) ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-white text-sm"><?= htmlspecialchars($ligne['compte_intitule'] ?? '') ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-slate-300 text-sm"><?= !empty($ligne['numero_facture']) ? htmlspecialchars($ligne['numero_facture']) : '-' ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-white"><?= htmlspecialchars($ligne['libelle']) ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <?php if ($ligne['debit'] > 0): ?>
                                                <span class="font-semibold text-cyan-400"><?= safe_number_format($ligne['debit']) ?></span>
                                                <span class="text-slate-500 text-xs ml-1">FCFA</span>
                                            <?php else: ?>
                                                <span class="text-slate-600">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <?php if ($ligne['credit'] > 0): ?>
                                                <span class="font-semibold text-pink-400"><?= safe_number_format($ligne['credit']) ?></span>
                                                <span class="text-slate-500 text-xs ml-1">FCFA</span>
                                            <?php else: ?>
                                                <span class="text-slate-600">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-900/50 border-t-2 border-slate-700">
                                <tr>
                                    <td colspan="5" class="px-4 py-4 text-right font-bold text-white text-lg">TOTAUX:</td>
                                    <td class="px-4 py-4 text-right">
                                        <div class="font-bold text-cyan-400 text-xl"><?= safe_number_format($total_debit) ?></div>
                                        <div class="text-slate-400 text-xs">FCFA</div>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <div class="font-bold text-pink-400 text-xl"><?= safe_number_format($total_credit) ?></div>
                                        <div class="text-slate-400 text-xs">FCFA</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="7" class="px-4 py-3 text-center">
                                        <?php if ($equilibre): ?>
                                            <div class="inline-flex items-center px-4 py-2 rounded-lg bg-green-500/10 border border-green-500/50 text-green-400">
                                                <i class="fas fa-check-circle mr-2 text-lg"></i>
                                                <span class="font-semibold">Écriture équilibrée</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="inline-flex items-center px-4 py-2 rounded-lg bg-red-500/10 border border-red-500/50 text-red-400">
                                                <i class="fas fa-exclamation-triangle mr-2 text-lg"></i>
                                                <span class="font-semibold">Déséquilibre de <?= safe_number_format(abs($total_debit - $total_credit)) ?> FCFA</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Actions -->
                <?php if ($ecriture['statut'] === 'Brouillon'): ?>
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-cog text-blue-400"></i>
                            Actions
                        </h3>
                        <div class="flex items-center gap-3">
                            <?php if ($equilibre): ?>
                                <button onclick="confirmValidation()" class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-6 py-2.5 rounded-lg font-medium transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                    <i class="fas fa-check-circle"></i>
                                    Valider l'écriture
                                </button>
                            <?php else: ?>
                                <div class="bg-red-500/10 border border-red-500/50 text-red-400 px-6 py-2.5 rounded-lg font-medium inline-flex items-center gap-2">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Impossible de valider (écriture déséquilibrée)
                                </div>
                            <?php endif; ?>
                            <a href="saisie.php?id=<?= $ecriture['id'] ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-edit"></i>
                                Modifier l'écriture
                            </a>
                            <button onclick="confirmDelete()" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2.5 rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-trash"></i>
                                Supprimer l'écriture
                            </button>
                        </div>
                    </div>

                    <!-- Formulaires cachés -->
                    <form id="validateForm" method="POST" class="hidden">
                        <input type="hidden" name="action" value="validate">
                    </form>
                    <form id="deleteForm" method="POST" class="hidden">
                        <input type="hidden" name="action" value="delete">
                    </form>
                <?php elseif (isAdmin()): ?>
                    <!-- Section Admin pour modifier les écritures validées -->
                    <div class="bg-amber-900/20 border border-amber-500/50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-amber-400 mb-4 flex items-center gap-2">
                            <i class="fas fa-user-shield"></i>
                            Actions Administrateur
                        </h3>
                        <div class="mb-4 p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg text-amber-300 text-sm">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Attention :</strong> Cette écriture est validée. En tant qu'administrateur, vous pouvez la modifier, mais cela peut affecter les rapports et la comptabilité.
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="saisie.php?id=<?= $ecriture['id'] ?>" class="bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white px-6 py-2.5 rounded-lg font-medium transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                <i class="fas fa-user-shield mr-1"></i>
                                <i class="fas fa-edit"></i>
                                Modifier l'écriture (Admin)
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Fonction pour afficher un toast moderne
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            toast.innerHTML = `
                <div style="font-size: 20px;">
                    <i class="fas ${icons[type]}"></i>
                </div>
                <div style="flex: 1; color: white;">
                    <div style="font-weight: 600; margin-bottom: 4px;">${type === 'success' ? 'Succès' : type === 'error' ? 'Erreur' : type === 'warning' ? 'Attention' : 'Information'}</div>
                    <div style="font-size: 14px; opacity: 0.95;">${message}</div>
                </div>
                <button onclick="this.parentElement.remove()" style="color: white; opacity: 0.7; hover:opacity: 1; padding: 4px 8px; font-size: 18px;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(toast);

            // Animation d'entrée
            anime({
                targets: toast,
                translateX: [400, 0],
                opacity: [0, 1],
                duration: 500,
                easing: 'easeOutElastic(1, .6)'
            });

            // Auto-suppression après la durée spécifiée
            setTimeout(() => {
                anime({
                    targets: toast,
                    translateX: [0, 400],
                    opacity: [1, 0],
                    duration: 300,
                    easing: 'easeInQuad',
                    complete: () => toast.remove()
                });
            }, duration);
        }

        // Fonction de confirmation moderne
        function showConfirm(message, onConfirm) {
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0;';

            const dialog = document.createElement('div');
            dialog.style.cssText = 'background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border: 1px solid #334155; border-radius: 16px; padding: 24px; max-width: 500px; width: 90%; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); transform: scale(0.9);';

            dialog.innerHTML = `
                <div style="color: #f59e0b; font-size: 48px; text-align: center; margin-bottom: 16px;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 style="color: white; font-size: 20px; font-weight: 600; margin-bottom: 12px; text-align: center;">Confirmation requise</h3>
                <p style="color: #cbd5e1; margin-bottom: 24px; text-align: center; line-height: 1.6;">${message}</p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button id="cancelBtn" style="flex: 1; padding: 12px 24px; background: #334155; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.2s;">
                        <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                    <button id="confirmBtn" style="flex: 1; padding: 12px 24px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.2s;">
                        <i class="fas fa-check mr-2"></i>Confirmer
                    </button>
                </div>
            `;

            overlay.appendChild(dialog);
            document.body.appendChild(overlay);

            // Animation d'entrée
            anime({
                targets: overlay,
                opacity: [0, 1],
                duration: 200
            });

            anime({
                targets: dialog,
                scale: [0.9, 1],
                duration: 300,
                easing: 'easeOutElastic(1, .6)'
            });

            const close = () => {
                anime({
                    targets: overlay,
                    opacity: [1, 0],
                    duration: 200,
                    complete: () => overlay.remove()
                });
            };

            dialog.querySelector('#cancelBtn').onclick = close;
            dialog.querySelector('#confirmBtn').onclick = () => {
                close();
                onConfirm();
            };

            overlay.onclick = (e) => {
                if (e.target === overlay) close();
            };
        }

        function confirmValidation() {
            showConfirm(
                'Êtes-vous sûr de vouloir valider cette écriture ? Une fois validée, elle ne pourra plus être modifiée.',
                () => document.getElementById('validateForm').submit()
            );
        }

        function confirmDelete() {
            showConfirm(
                'Êtes-vous sûr de vouloir supprimer cette écriture ? Cette action est irréversible.',
                () => document.getElementById('deleteForm').submit()
            );
        }

        function confirmExtourne() {
            showConfirm(
                'Êtes-vous sûr de vouloir extourner cette écriture ?<br><br>Cela créera une écriture inverse (débits ↔ crédits) pour annuler l\'écriture actuelle.',
                () => {
                    // Afficher un loader
                    const btn = window.event.target.closest('button');
                    const originalContent = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Extourne en cours...';

                    // Envoyer la requête AJAX
                    fetch('extourner.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'id_ecriture=<?= $ecriture_id ?>'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Écriture extournée avec succès ! Numéro : ' + data.numero_extourne, 'success');
                            // Rediriger vers la nouvelle écriture extournée après 2 secondes
                            setTimeout(() => {
                                window.location.href = 'voir.php?id=' + data.id_extourne;
                            }, 2000);
                        } else {
                            showToast(data.message, 'error');
                            btn.disabled = false;
                            btn.innerHTML = originalContent;
                        }
                    })
                    .catch(error => {
                        showToast('Erreur lors de l\'extourne : ' + error.message, 'error');
                        btn.disabled = false;
                        btn.innerHTML = originalContent;
                    });
                }
            );
        }

        /**
         * Dupliquer une écriture comptable
         */
        function dupliquerEcriture(id) {
            // Afficher un loader
            const button = event.target.closest('button');
            const originalContent = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Chargement...';
            button.disabled = true;

            // Récupérer les données de l'écriture via API
            fetch(`/comptabilite_ohada/api/v1/ecritures/get.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Stocker dans localStorage pour pré-remplir le formulaire
                    localStorage.setItem('ecriture_duplicate', JSON.stringify({
                        ecriture: data.ecriture,
                        lignes: data.lignes,
                        original_numero: data.ecriture.numero_ecriture
                    }));

                    // Rediriger vers le formulaire de création
                    window.location.href = 'saisie.php?duplicate=1';
                } else {
                    showToast(`Erreur : ${data.error}`, 'error');
                    button.innerHTML = originalContent;
                    button.disabled = false;
                }
            })
            .catch(error => {
                showToast(`Erreur réseau : ${error.message}`, 'error');
                button.innerHTML = originalContent;
                button.disabled = false;
            });
        }
    </script>
</body>
</html>
