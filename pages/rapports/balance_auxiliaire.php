<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$type_tiers = isset($_GET['type_tiers']) ? $_GET['type_tiers'] : '';
$show_zero = isset($_GET['show_zero']) ? true : false;

// Récupérer la balance auxiliaire
$balance = [];
$totaux = [
    'debit_anterieur' => 0,
    'credit_anterieur' => 0,
    'debit_periode' => 0,
    'credit_periode' => 0,
    'debit_final' => 0,
    'credit_final' => 0
];

try {
    // Requête pour récupérer tous les tiers avec leurs mouvements
    $sql = "
        SELECT
            pt.id,
            pt.nom,
            pt.type,
            pt.compte_tiers,
            -- Mouvements antérieurs (avant date_debut)
            COALESCE(SUM(CASE WHEN e.date_ecriture < ? THEN le.debit ELSE 0 END), 0) as debit_anterieur,
            COALESCE(SUM(CASE WHEN e.date_ecriture < ? THEN le.credit ELSE 0 END), 0) as credit_anterieur,
            -- Mouvements période
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? THEN le.debit ELSE 0 END), 0) as debit_periode,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? THEN le.credit ELSE 0 END), 0) as credit_periode
        FROM plan_tiers pt
        LEFT JOIN lignes_ecriture le ON le.compte_tiers = pt.compte_tiers AND le.societe_id = pt.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut = 'Validé' AND e.societe_id = pt.societe_id
        WHERE pt.actif = 1
        AND pt.societe_id = ?
    ";

    $params = [$date_debut, $date_debut, $date_debut, $date_fin, $date_debut, $date_fin, $societe_id];

    if (!empty($type_tiers)) {
        $sql .= " AND pt.type = ?";
        $params[] = $type_tiers;
    }

    $sql .= " GROUP BY pt.id, pt.nom, pt.type, pt.compte_tiers ORDER BY pt.type, pt.nom";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $resultats = $stmt->fetchAll();

    foreach ($resultats as $row) {
        // Calculer les soldes
        $solde_anterieur = $row['debit_anterieur'] - $row['credit_anterieur'];
        $solde_final = ($row['debit_anterieur'] + $row['debit_periode']) - ($row['credit_anterieur'] + $row['credit_periode']);

        // Filtrer les tiers à solde nul si demandé
        if (!$show_zero && abs($solde_final) < 0.01 && abs($row['debit_periode']) < 0.01 && abs($row['credit_periode']) < 0.01) {
            continue;
        }

        $ligne = [
            'id' => $row['id'],
            'nom' => $row['nom'],
            'type' => $row['type'],
            'compte_tiers' => $row['compte_tiers'],
            'debit_anterieur' => $solde_anterieur > 0 ? $solde_anterieur : 0,
            'credit_anterieur' => $solde_anterieur < 0 ? abs($solde_anterieur) : 0,
            'debit_periode' => $row['debit_periode'],
            'credit_periode' => $row['credit_periode'],
            'debit_final' => $solde_final > 0 ? $solde_final : 0,
            'credit_final' => $solde_final < 0 ? abs($solde_final) : 0,
        ];

        $balance[] = $ligne;

        // Cumuler les totaux
        $totaux['debit_anterieur'] += $ligne['debit_anterieur'];
        $totaux['credit_anterieur'] += $ligne['credit_anterieur'];
        $totaux['debit_periode'] += $ligne['debit_periode'];
        $totaux['credit_periode'] += $ligne['credit_periode'];
        $totaux['debit_final'] += $ligne['debit_final'];
        $totaux['credit_final'] += $ligne['credit_final'];
    }

} catch (Exception $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors du calcul de la balance: ' . $e->getMessage()];
}

$pageTitle = "Balance Auxiliaire";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        /* ============================================ */
        /* SYSTÈME TYPOGRAPHIQUE HARMONISÉ             */
        /* Conforme au GUIDE_TYPOGRAPHIQUE.md          */
        /* ============================================ */
        :root {
            --font-size-xs: 10px;      /* Extra small - labels secondaires, références */
            --font-size-sm: 11px;      /* Small - données tableau */
            --font-size-base: 12px;    /* Base - texte normal */
            --font-size-md: 13px;      /* Medium - en-têtes tableau */
            --font-size-lg: 16px;      /* Large - titres sections */
            --font-size-xl: 20px;      /* Extra large - titre principal */
        }

        body {
            font-size: var(--font-size-base);
        }

        /* Classes pour les colonnes de montants */
        .col-montant {
            min-width: 100px;
            max-width: 100px;
            font-size: var(--font-size-sm);
            font-family: 'Courier New', monospace;
            white-space: nowrap;
            overflow: visible;
            padding: 8px 4px !important;
            text-align: right;
        }

        .col-montant-header {
            font-size: var(--font-size-xs);
            min-width: 100px;
            max-width: 100px;
            padding: 8px 4px !important;
        }

        /* Colonnes de compte */
        .col-compte {
            font-size: var(--font-size-sm);
            font-family: 'Courier New', monospace;
            min-width: 90px;
            max-width: 90px;
        }

        /* Colonnes de libellés */
        .col-libelle {
            font-size: var(--font-size-sm);
        }

        /* Titre principal de page */
        .page-title {
            font-size: var(--font-size-xl);
        }

        /* Titres de sections */
        .section-title {
            font-size: var(--font-size-lg);
        }

        /* En-têtes de tableau */
        .table-header {
            font-size: var(--font-size-xs);
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 flex flex-col overflow-hidden p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                            <i class="fas fa-users mr-3"></i>Balance Auxiliaire
                        </h1>
                        <p class="text-slate-400" style="font-size: var(--font-size-base);">Balance détaillée par tiers (clients, fournisseurs)</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 mb-6">
                <form method="GET">
                    <div class="flex flex-wrap items-end gap-3">
                        <!-- Date début -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Date début
                            </label>
                            <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-slate-100">
                        </div>

                        <!-- Date fin -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Date fin
                            </label>
                            <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-slate-100">
                        </div>

                        <!-- Type de tiers -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-filter mr-2"></i>Type
                            </label>
                            <select name="type_tiers" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-slate-100">
                                <option value="">Tous</option>
                                <option value="Client" <?= $type_tiers === 'Client' ? 'selected' : '' ?>>Clients</option>
                                <option value="Fournisseur" <?= $type_tiers === 'Fournisseur' ? 'selected' : '' ?>>Fournisseurs</option>
                                <option value="Autre" <?= $type_tiers === 'Autre' ? 'selected' : '' ?>>Autres</option>
                            </select>
                        </div>

                        <!-- Afficher soldes nuls -->
                        <div>
                            <label class="flex items-center gap-2 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg cursor-pointer hover:bg-slate-750">
                                <input type="checkbox" name="show_zero" value="1" <?= $show_zero ? 'checked' : '' ?>
                                       class="w-4 h-4 text-purple-600 bg-slate-700 border-slate-600 rounded focus:ring-purple-500">
                                <span class="text-slate-300 text-sm">Soldes nuls</span>
                            </label>
                        </div>

                        <!-- Boutons d'action -->
                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-search"></i>
                            Afficher
                        </button>
                        <a href="balance_auxiliaire.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-redo"></i>
                            Réinitialiser
                        </a>

                        <?php if (!empty($balance)): ?>
                            <!-- Séparateur visuel -->
                            <div class="border-l border-slate-600 h-10 mx-1"></div>

                            <button type="button" onclick="exportPDF()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                <i class="fas fa-file-pdf"></i>
                                PDF
                            </button>
                            <button type="button" onclick="exportExcel()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                <i class="fas fa-file-excel"></i>
                                Excel
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- En-tête de la période -->
            <div class="bg-gradient-to-r from-purple-900/30 to-pink-900/30 border border-purple-800/30 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-bold text-white mb-1">
                            Balance auxiliaire au <?= date('d/m/Y', strtotime($date_fin)) ?>
                        </h2>
                        <p class="text-slate-300">
                            Période du <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-slate-400 mb-1">Nombre de tiers</p>
                        <p class="text-2xl font-bold text-white"><?= count($balance) ?></p>
                    </div>
                </div>
            </div>

            <!-- Tableau de la balance -->
            <div class="flex-1 flex flex-col bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden min-h-0">
                <div class="flex-1 overflow-x-auto overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                            <tr>
                                <th rowspan="2" class="px-4 py-3 text-left table-header font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Compte
                                </th>
                                <th rowspan="2" class="px-4 py-3 text-left table-header font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Nom
                                </th>
                                <th rowspan="2" class="px-4 py-3 text-left table-header font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Type
                                </th>
                                <th colspan="2" class="px-4 py-2 text-center table-header font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">
                                    Soldes Antérieurs
                                </th>
                                <th colspan="2" class="px-4 py-2 text-center table-header font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/30">
                                    Mouvements Période
                                </th>
                                <th colspan="2" class="px-4 py-2 text-center table-header font-semibold text-slate-300 uppercase tracking-wider bg-slate-700/50">
                                    Soldes Finaux
                                </th>
                            </tr>
                            <tr>
                                <th class="px-4 py-2 text-right col-montant-header font-semibold text-green-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">Débit</th>
                                <th class="px-4 py-2 text-right col-montant-header font-semibold text-red-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">Crédit</th>
                                <th class="px-4 py-2 text-right col-montant-header font-semibold text-green-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/30">Débit</th>
                                <th class="px-4 py-2 text-right col-montant-header font-semibold text-red-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/30">Crédit</th>
                                <th class="px-4 py-2 text-right col-montant-header font-semibold text-green-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">Débit</th>
                                <th class="px-4 py-2 text-right col-montant-header font-semibold text-red-300 uppercase tracking-wider bg-slate-700/50">Crédit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <?php if (empty($balance)): ?>
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-400">
                                        <i class="fas fa-inbox text-3xl mb-2"></i>
                                        <p>Aucun mouvement pour cette période</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $current_type = '';
                                foreach ($balance as $ligne):
                                    // Ajouter une ligne de séparation pour chaque type
                                    if ($current_type !== $ligne['type'] && $type_tiers === ''):
                                        $current_type = $ligne['type'];
                                ?>
                                        <tr class="bg-slate-700/50">
                                            <td colspan="9" class="px-4 py-2 font-bold text-white">
                                                <i class="fas fa-tag mr-2"></i><?= htmlspecialchars($current_type) ?>s
                                            </td>
                                        </tr>
                                <?php endif; ?>

                                    <tr class="hover:bg-slate-700/30 transition-colors">
                                        <td class="px-4 py-3 col-compte text-slate-300 border-r border-slate-700">
                                            <?= htmlspecialchars($ligne['compte_tiers']) ?>
                                        </td>
                                        <td class="px-4 py-3 col-libelle text-slate-300 border-r border-slate-700">
                                            <?= htmlspecialchars($ligne['nom']) ?>
                                        </td>
                                        <td class="px-4 py-3 border-r border-slate-700">
                                            <span class="text-xs px-2 py-1 rounded
                                                <?= $ligne['type'] === 'Client' ? 'bg-blue-900/50 text-blue-300' :
                                                   ($ligne['type'] === 'Fournisseur' ? 'bg-orange-900/50 text-orange-300' : 'bg-slate-700 text-slate-300') ?>">
                                                <?= htmlspecialchars($ligne['type']) ?>
                                            </span>
                                        </td>
                                        <td class="col-montant text-green-400 border-r border-slate-700">
                                            <?= $ligne['debit_anterieur'] > 0 ? safe_number_format($ligne['debit_anterieur'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant text-red-400 border-r border-slate-700">
                                            <?= $ligne['credit_anterieur'] > 0 ? safe_number_format($ligne['credit_anterieur'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant text-green-400 border-r border-slate-700">
                                            <?= $ligne['debit_periode'] > 0 ? safe_number_format($ligne['debit_periode'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant text-red-400 border-r border-slate-700">
                                            <?= $ligne['credit_periode'] > 0 ? safe_number_format($ligne['credit_periode'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant text-green-400 border-r border-slate-700">
                                            <?= $ligne['debit_final'] > 0 ? safe_number_format($ligne['debit_final'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant text-red-400">
                                            <?= $ligne['credit_final'] > 0 ? safe_number_format($ligne['credit_final'], 2) : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Ligne totaux -->
                                <tr class="bg-gradient-to-r from-slate-700 to-slate-800 font-bold text-white">
                                    <td colspan="3" class="px-4 py-3 col-libelle border-r border-slate-600">
                                        <i class="fas fa-calculator mr-2"></i>TOTAUX
                                    </td>
                                    <td class="col-montant text-green-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['debit_anterieur'], 2) ?>
                                    </td>
                                    <td class="col-montant text-red-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['credit_anterieur'], 2) ?>
                                    </td>
                                    <td class="col-montant text-green-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['debit_periode'], 2) ?>
                                    </td>
                                    <td class="col-montant text-red-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['credit_periode'], 2) ?>
                                    </td>
                                    <td class="col-montant text-green-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['debit_final'], 2) ?>
                                    </td>
                                    <td class="col-montant text-red-300">
                                        <?= safe_number_format($totaux['credit_final'], 2) ?>
                                    </td>
                                </tr>

                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        function exportPDF() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                type_tiers: '<?= $type_tiers ?>',
                show_zero: '<?= $show_zero ? '1' : '0' ?>'
            });
            window.location.href = 'export_balance_auxiliaire_pdf.php?' + params.toString();
        }

        function exportExcel() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                type_tiers: '<?= $type_tiers ?>',
                show_zero: '<?= $show_zero ? '1' : '0' ?>'
            });
            window.location.href = 'export_balance_auxiliaire_excel.php?' + params.toString();
        }
    </script>
</body>
</html>
