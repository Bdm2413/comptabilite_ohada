<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$journal_filter = isset($_GET['journal']) ? $_GET['journal'] : '';
$statut_filter = isset($_GET['statut']) ? $_GET['statut'] : 'Validé';

// Récupérer la liste des journaux
$stmt_j = $db->prepare("SELECT code_journal as code, libelle as journal FROM journaux WHERE societe_id = ? AND actif = 1 ORDER BY code_journal");
$stmt_j->execute([$societe_id]);
$journaux = $stmt_j->fetchAll();

// Récupérer les écritures
$ecritures = [];
$total_debit = 0;
$total_credit = 0;

try {
    $sql = "
        SELECT
            e.id,
            e.numero_ecriture,
            e.date_ecriture,
            e.journal,
            cj.libelle as journal_libelle,
            e.libelle,
            e.num_piece,
            e.reference_piece,
            e.statut,
            pt.nom as tiers_nom
        FROM ecritures e
        LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
        LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
        WHERE e.societe_id = ? AND e.date_ecriture BETWEEN ? AND ?
    ";

    $params = [$societe_id, $date_debut, $date_fin];

    if (!empty($journal_filter)) {
        $sql .= " AND e.journal = ?";
        $params[] = $journal_filter;
    }

    if (!empty($statut_filter)) {
        $sql .= " AND e.statut = ?";
        $params[] = $statut_filter;
    }

    $sql .= " ORDER BY e.date_ecriture, e.numero_ecriture";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $ecritures_data = $stmt->fetchAll();

    // Récupérer toutes les lignes d'écritures en une seule requête (optimisation)
    if (!empty($ecritures_data)) {
        $ids_ecritures = array_column($ecritures_data, 'id');
        $placeholders = str_repeat('?,', count($ids_ecritures) - 1) . '?';

        $stmt_lignes = $db->prepare("
            SELECT
                le.*,
                pc.intitule_compte
            FROM lignes_ecriture le
            LEFT JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = ?
            WHERE le.id_ecriture IN ($placeholders)
            ORDER BY le.id_ecriture, le.id
        ");
        $stmt_lignes->execute(array_merge([$societe_id], $ids_ecritures));
        $toutes_lignes = $stmt_lignes->fetchAll(PDO::FETCH_ASSOC);

        // Regrouper les lignes par id_ecriture
        $lignes_par_ecriture = [];
        foreach ($toutes_lignes as $ligne) {
            $lignes_par_ecriture[$ligne['id_ecriture']][] = $ligne;
        }

        // Construire le tableau final avec les lignes
        foreach ($ecritures_data as $ecriture) {
            $lignes = $lignes_par_ecriture[$ecriture['id']] ?? [];

            // Calculer les totaux de l'écriture
            $ecriture_debit = 0;
            $ecriture_credit = 0;
            foreach ($lignes as $ligne) {
                $ecriture_debit += $ligne['debit'];
                $ecriture_credit += $ligne['credit'];
            }

            $ecritures[] = [
                'ecriture' => $ecriture,
                'lignes' => $lignes,
                'total_debit' => $ecriture_debit,
                'total_credit' => $ecriture_credit
            ];

            $total_debit += $ecriture_debit;
            $total_credit += $ecriture_credit;
        }
    }

} catch (Exception $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors du chargement: ' . $e->getMessage()];
}

$pageTitle = "Journal Général";
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-orange-400 to-red-600 mb-2">
                            <i class="fas fa-journal-whills mr-3"></i>Journal Général
                        </h1>
                        <p class="text-slate-400" style="font-size: var(--font-size-base);">Toutes les écritures comptables par ordre chronologique</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 mb-6">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <!-- Date début -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Date début
                        </label>
                        <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                               class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent text-slate-100">
                    </div>

                    <!-- Date fin -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Date fin
                        </label>
                        <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                               class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent text-slate-100">
                    </div>

                    <!-- Journal -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-book-open mr-2"></i>Journal
                        </label>
                        <select name="journal" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent text-slate-100">
                            <option value="">Tous les journaux</option>
                            <?php foreach ($journaux as $j): ?>
                                <option value="<?= htmlspecialchars($j['code']) ?>" <?= $journal_filter === $j['code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($j['code']) ?> - <?= htmlspecialchars($j['journal']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Statut -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-filter mr-2"></i>Statut
                        </label>
                        <select name="statut" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent text-slate-100">
                            <option value="">Tous les statuts</option>
                            <option value="Brouillon" <?= $statut_filter === 'Brouillon' ? 'selected' : '' ?>>Brouillon</option>
                            <option value="Validé" <?= $statut_filter === 'Validé' ? 'selected' : '' ?>>Validé</option>
                        </select>
                    </div>

                    <!-- Bouton Afficher -->
                    <button type="submit" class="px-4 py-2 bg-gradient-to-r from-orange-600 to-orange-700 hover:from-orange-700 hover:to-orange-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                        <i class="fas fa-search"></i>
                        Afficher
                    </button>

                    <!-- Bouton Réinitialiser -->
                    <a href="journal_general.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-redo"></i>
                        Réinitialiser
                    </a>

                    <?php if (!empty($ecritures)): ?>
                        <!-- Séparateur visuel -->
                        <div class="border-l border-slate-600 h-8 mx-1"></div>

                        <!-- Bouton PDF -->
                        <button type="button" onclick="exportPDF()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>

                        <!-- Bouton Excel -->
                        <button type="button" onclick="exportExcel()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>

                        <!-- Bouton Excel Format Grand Livre -->
                        <button type="button" onclick="exportExcelGrandLivre()" class="px-4 py-2 bg-gradient-to-r from-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2" title="Export au format Grand Livre">
                            <i class="fas fa-file-excel"></i>
                            Excel (Grand Livre)
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- En-tête de la période -->
            <div class="bg-gradient-to-r from-orange-900/30 to-red-900/30 border border-orange-800/30 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-bold text-white mb-1">
                            Journal général
                        </h2>
                        <p class="text-slate-300">
                            Période du <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-slate-400 mb-1">Nombre d'écritures</p>
                        <p class="text-2xl font-bold text-white"><?= count($ecritures) ?></p>
                    </div>
                </div>
            </div>

            <!-- Journal -->
            <div class="flex-1 space-y-4 overflow-y-auto min-h-0">
                <?php if (empty($ecritures)): ?>
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-8 text-center">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">Aucune écriture pour cette période</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($ecritures as $item): ?>
                        <?php
                        $e = $item['ecriture'];
                        $lignes = $item['lignes'];
                        $equilibre = abs($item['total_debit'] - $item['total_credit']) < 0.01;
                        ?>
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
                            <!-- En-tête de l'écriture -->
                            <div class="bg-gradient-to-r from-slate-700 to-slate-800 px-6 py-4 flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <a href="../ecritures/voir.php?id=<?= $e['id'] ?>" class="text-lg font-bold text-blue-400 hover:text-blue-300">
                                        #<?= htmlspecialchars($e['numero_ecriture']) ?>
                                    </a>
                                    <span class="text-slate-300">
                                        <?= date('d/m/Y', strtotime($e['date_ecriture'])) ?>
                                    </span>
                                    <span class="text-xs bg-slate-600 px-3 py-1 rounded-full">
                                        <?= htmlspecialchars($e['journal']) ?>
                                    </span>
                                    <span class="text-xs px-3 py-1 rounded-full <?= $e['statut'] === 'Validé' ? 'bg-green-900/50 text-green-300' : 'bg-yellow-900/50 text-yellow-300' ?>">
                                        <?= htmlspecialchars($e['statut']) ?>
                                    </span>
                                    <?php if (!$equilibre): ?>
                                        <span class="text-xs bg-red-900/50 text-red-300 px-3 py-1 rounded-full">
                                            <i class="fas fa-exclamation-triangle"></i> Déséquilibrée
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right text-sm text-slate-400">
                                    <?php if ($e['num_piece']): ?>
                                        <div>N° Pièce: <?= htmlspecialchars($e['num_piece']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($e['reference_piece']): ?>
                                        <div>Réf: <?= htmlspecialchars($e['reference_piece']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Libellé -->
                            <div class="px-6 py-3 bg-slate-800/50 border-b border-slate-700">
                                <p class="text-slate-300">
                                    <i class="fas fa-comment-alt mr-2 text-slate-500"></i>
                                    <strong>Libellé :</strong> <?= htmlspecialchars($e['libelle']) ?>
                                </p>
                                <?php if ($e['tiers_nom']): ?>
                                    <p class="text-slate-400 text-sm mt-1">
                                        <i class="fas fa-user mr-2 text-slate-500"></i>
                                        <strong>Tiers :</strong> <?= htmlspecialchars($e['tiers_nom']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Lignes d'écriture -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-700/50">
                                        <tr>
                                            <th class="px-4 py-2 text-left table-header font-semibold text-slate-300 uppercase">Compte</th>
                                            <th class="px-4 py-2 text-left table-header font-semibold text-slate-300 uppercase">Intitulé</th>
                                            <th class="px-4 py-2 text-left table-header font-semibold text-slate-300 uppercase">Libellé ligne</th>
                                            <th class="px-4 py-2 text-right col-montant-header font-semibold text-green-300 uppercase">Débit</th>
                                            <th class="px-4 py-2 text-right col-montant-header font-semibold text-red-300 uppercase">Crédit</th>
                                            <th class="px-4 py-2 text-center table-header font-semibold text-slate-300 uppercase">Lettrage</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700">
                                        <?php foreach ($lignes as $ligne): ?>
                                            <tr class="hover:bg-slate-700/20">
                                                <td class="px-4 py-2 col-compte text-slate-300">
                                                    <?= htmlspecialchars($ligne['compte']) ?>
                                                </td>
                                                <td class="px-4 py-2 col-libelle text-slate-300">
                                                    <?= htmlspecialchars($ligne['intitule_compte']) ?>
                                                </td>
                                                <td class="px-4 py-2 col-libelle text-slate-400">
                                                    <?= htmlspecialchars($ligne['libelle'] ?? '-') ?>
                                                </td>
                                                <td class="col-montant text-green-400">
                                                    <?= $ligne['debit'] > 0 ? safe_number_format($ligne['debit'], 2) : '-' ?>
                                                </td>
                                                <td class="col-montant text-red-400">
                                                    <?= $ligne['credit'] > 0 ? safe_number_format($ligne['credit'], 2) : '-' ?>
                                                </td>
                                                <td class="px-4 py-2 text-center">
                                                    <?php if (!empty($ligne['lettrage'])): ?>
                                                        <span class="inline-block px-2 py-1 bg-blue-500/10 text-blue-400 rounded text-xs font-mono font-semibold">
                                                            <?= htmlspecialchars($ligne['lettrage']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-slate-500 text-xs">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <!-- Total de l'écriture -->
                                        <tr class="bg-slate-700/30 font-semibold">
                                            <td colspan="3" class="px-4 py-2 col-libelle text-slate-300">
                                                <i class="fas fa-calculator mr-2"></i>Total
                                            </td>
                                            <td class="col-montant text-green-400">
                                                <?= safe_number_format($item['total_debit'], 2) ?>
                                            </td>
                                            <td class="col-montant text-red-400">
                                                <?= safe_number_format($item['total_credit'], 2) ?>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Totaux généraux -->
                    <div class="bg-gradient-to-r from-slate-700 to-slate-800 rounded-xl border border-slate-600 p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <i class="fas fa-calculator text-2xl text-slate-400"></i>
                                <div>
                                    <h3 class="text-lg font-bold text-white">Totaux de la période</h3>
                                    <p class="text-sm text-slate-400">
                                        <?= count($ecritures) ?> écriture<?= count($ecritures) > 1 ? 's' : '' ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-8">
                                <div class="text-right">
                                    <p class="text-xs text-green-300 uppercase mb-1">Total Débit</p>
                                    <p class="text-2xl font-bold text-green-400 font-mono">
                                        <?= safe_number_format($total_debit, 2) ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-red-300 uppercase mb-1">Total Crédit</p>
                                    <p class="text-2xl font-bold text-red-400 font-mono">
                                        <?= safe_number_format($total_credit, 2) ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-slate-400 uppercase mb-1">Équilibre</p>
                                    <?php $equilibre_general = abs($total_debit - $total_credit) < 0.01; ?>
                                    <p class="text-2xl font-bold <?= $equilibre_general ? 'text-green-400' : 'text-red-400' ?>">
                                        <?php if ($equilibre_general): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php else: ?>
                                            <i class="fas fa-exclamation-triangle"></i>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <script>
        function exportPDF() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                journal: '<?= $journal_filter ?>',
                statut: '<?= $statut_filter ?>'
            });
            window.location.href = 'export_journal_general_pdf.php?' + params.toString();
        }

        function exportExcel() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                journal: '<?= $journal_filter ?>',
                statut: '<?= $statut_filter ?>'
            });
            window.location.href = 'export_journal_general_excel.php?' + params.toString();
        }

        function exportExcelGrandLivre() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                journal: '<?= $journal_filter ?>',
                statut: '<?= $statut_filter ?>'
            });
            window.location.href = 'export_journal_general_grandlivre.php?' + params.toString();
        }
    </script>
</body>
</html>
