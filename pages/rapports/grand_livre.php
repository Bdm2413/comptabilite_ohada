<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Récupérer la liste des exercices
$stmt = $db->prepare("SELECT * FROM exercices WHERE societe_id = ? ORDER BY annee DESC");
$stmt->execute([$societe_id]);
$exercices = $stmt->fetchAll();

// Récupérer les paramètres de filtrage
$compte_filter = isset($_GET['compte']) ? $_GET['compte'] : '';
$exercice_id = isset($_GET['exercice_id']) ? intval($_GET['exercice_id']) : null;
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : null;
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : null;
$journal_filter = isset($_GET['journal']) ? $_GET['journal'] : '';
$lettrage_filter = isset($_GET['lettrage']) ? $_GET['lettrage'] : 'tous'; // tous, lettrees, non_lettrees

// Déterminer les dates à utiliser
if ($exercice_id) {
    // Mode exercice : utiliser les dates de l'exercice sélectionné
    $stmt = $db->prepare("SELECT * FROM exercices WHERE id = ? AND societe_id = ?");
    $stmt->execute([$exercice_id, $societe_id]);
    $exercice_selectionne = $stmt->fetch();

    if ($exercice_selectionne) {
        $date_debut = $exercice_selectionne['date_debut'];
        $date_fin = $exercice_selectionne['date_fin'];
        $mode_exercice = true;
    } else {
        $mode_exercice = false;
    }
} else {
    // Mode date libre : utiliser les dates fournies ou par défaut l'année en cours
    if (!$date_debut) $date_debut = date('Y-01-01');
    if (!$date_fin) $date_fin = date('Y-m-d');
    $mode_exercice = false;
    $exercice_selectionne = null;
}

// Récupérer la liste des comptes
$stmt_c = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE societe_id = ? AND actif = 'Oui' ORDER BY compte");
$stmt_c->execute([$societe_id]);
$comptes = $stmt_c->fetchAll();

// Récupérer la liste des journaux
$stmt_j = $db->prepare("SELECT code_journal as code, libelle as journal FROM journaux WHERE societe_id = ? AND actif = 1 ORDER BY code_journal");
$stmt_j->execute([$societe_id]);
$journaux = $stmt_j->fetchAll();

// Données du grand livre
$lignes = [];
$solde_initial = 0;
$compte_info = null;

if (!empty($compte_filter)) {
    // Récupérer les informations du compte
    $stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE compte = ? AND societe_id = ?");
    $stmt->execute([$compte_filter, $societe_id]);
    $compte_info = $stmt->fetch();

    if ($compte_info) {
        // Déterminer si c'est un compte de résultat (classes 6, 7, 8)
        $premiere_classe = substr($compte_filter, 0, 1);
        $est_compte_resultat = in_array($premiere_classe, ['6', '7', '8']);

        // Pour les comptes de résultat, le solde initial commence au début de l'année
        // Pour les comptes de bilan, le solde initial cumule tout l'historique
        $annee_debut = date('Y', strtotime($date_debut));
        $date_debut_calcul = $est_compte_resultat ? $annee_debut . '-01-01' : '1900-01-01';

        // Calculer le solde initial (avant la date de début)
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(le.debit), 0) as total_debit,
                COALESCE(SUM(le.credit), 0) as total_credit
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE le.compte = ?
            AND e.societe_id = ?
            AND e.statut = 'Validé'
            AND e.date_ecriture >= ?
            AND e.date_ecriture < ?
        ");
        $stmt->execute([$compte_filter, $societe_id, $date_debut_calcul, $date_debut]);
        $solde_data = $stmt->fetch();
        $solde_initial = ($solde_data['total_debit'] ?? 0) - ($solde_data['total_credit'] ?? 0);

        // Récupérer les lignes du grand livre pour la période
        $sql = "
            SELECT
                e.id as id_ecriture,
                e.date_ecriture,
                e.numero_ecriture,
                e.journal,
                cj.libelle as journal_libelle,
                e.num_piece,
                e.libelle,
                le.libelle as libelle_ligne,
                le.numero_facture,
                le.debit,
                le.credit,
                COALESCE(pt.abreviation, pt.nom) as tiers_nom,
                e.lettrage,
                e.statut_lettrage
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
            LEFT JOIN plan_tiers pt ON le.compte_tiers = pt.compte_tiers
            WHERE le.compte = ?
            AND e.societe_id = ?
            AND e.statut = 'Validé'
            AND e.date_ecriture BETWEEN ? AND ?
        ";

        $params = [$compte_filter, $societe_id, $date_debut, $date_fin];

        if (!empty($journal_filter)) {
            $sql .= " AND e.journal = ?";
            $params[] = $journal_filter;
        }

        // Filtre de lettrage
        if ($lettrage_filter === 'lettrees') {
            $sql .= " AND e.statut_lettrage = 'Lettré'";
        } elseif ($lettrage_filter === 'non_lettrees') {
            $sql .= " AND (e.statut_lettrage != 'Lettré' OR e.statut_lettrage IS NULL)";
        }

        $sql .= " ORDER BY e.date_ecriture, e.numero_ecriture";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $lignes = $stmt->fetchAll();
    }
}

$pageTitle = "Grand Livre";
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
        /* ========================================
           SYSTÈME TYPOGRAPHIQUE HARMONISÉ
           ======================================== */
        :root {
            --font-size-xs: 10px;      /* Extra small - labels secondaires */
            --font-size-sm: 11px;      /* Small - données tableau */
            --font-size-base: 12px;    /* Base - texte normal */
            --font-size-md: 13px;      /* Medium - en-têtes tableau */
            --font-size-lg: 16px;      /* Large - titres sections */
            --font-size-xl: 20px;      /* Extra large - titre principal */
        }

        body {
            font-size: var(--font-size-base);
        }

        .page-title {
            font-size: var(--font-size-xl);
        }

        /* Colonnes pour le tableau du grand livre */
        .col-compte {
            font-size: var(--font-size-sm);
            white-space: nowrap;
            padding: 8px 6px !important;
        }

        .col-libelle {
            font-size: var(--font-size-sm);
            padding: 8px 6px !important;
        }

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

        .table-header {
            font-size: var(--font-size-xs);
        }
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-600 mb-2">
                            <i class="fas fa-book mr-3"></i>Grand Livre
                        </h1>
                        <p style="font-size: var(--font-size-base);" class="text-slate-400">Mouvements détaillés d'un compte</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 mb-6">
                <form method="GET" id="form-filtres">
                    <div class="flex flex-wrap items-end gap-3">
                        <!-- Compte -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-hashtag mr-2"></i>Compte *
                            </label>
                            <select name="compte" required class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100">
                                <option value="">-- Sélectionner un compte --</option>
                                <?php foreach ($comptes as $c): ?>
                                    <option value="<?= htmlspecialchars($c['compte']) ?>" <?= $compte_filter === $c['compte'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['compte']) ?> - <?= htmlspecialchars(substr($c['intitule_compte'], 0, 30)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Exercice -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-check mr-2"></i>Exercice
                            </label>
                            <select name="exercice_id" id="exercice_select" onchange="toggleDatesCustom()"
                                    class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100">
                                <option value="">Période personnalisée</option>
                                <?php foreach ($exercices as $ex): ?>
                                    <option value="<?= $ex['id'] ?>" <?= $exercice_id == $ex['id'] ? 'selected' : '' ?>>
                                        Exercice <?= $ex['annee'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date début -->
                        <div id="date-debut-container">
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Date début
                            </label>
                            <input type="date" name="date_debut" id="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100"
                                   <?= $mode_exercice ? 'disabled' : '' ?>>
                        </div>

                        <!-- Date fin -->
                        <div id="date-fin-container">
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Date fin
                            </label>
                            <input type="date" name="date_fin" id="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100"
                                   <?= $mode_exercice ? 'disabled' : '' ?>>
                        </div>

                        <!-- Filtre Lettrage -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-link mr-2"></i>Lettrage
                            </label>
                            <select name="lettrage" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100">
                                <option value="tous" <?= $lettrage_filter === 'tous' ? 'selected' : '' ?>>Tous</option>
                                <option value="lettrees" <?= $lettrage_filter === 'lettrees' ? 'selected' : '' ?>>Lettrées uniquement</option>
                                <option value="non_lettrees" <?= $lettrage_filter === 'non_lettrees' ? 'selected' : '' ?>>Non lettrées uniquement</option>
                            </select>
                        </div>

                        <!-- Bouton Afficher -->
                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-search"></i>
                            Afficher
                        </button>

                        <!-- Bouton Réinitialiser -->
                        <a href="grand_livre.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-redo"></i>
                            Réinitialiser
                        </a>

                        <?php if (!empty($compte_filter) && $compte_info): ?>
                            <!-- Séparateur visuel -->
                            <div class="border-l border-slate-600 h-10 mx-1"></div>

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
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (!empty($compte_filter) && $compte_info): ?>
                <!-- En-tête du compte -->
                <div class="bg-gradient-to-r from-blue-900/30 to-purple-900/30 border border-blue-800/30 rounded-lg p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-white mb-1">
                                Compte <?= htmlspecialchars($compte_info['compte']) ?>
                            </h2>
                            <p class="text-slate-300"><?= htmlspecialchars($compte_info['intitule_compte']) ?></p>
                        </div>
                        <div class="text-right">
                            <?php if ($mode_exercice && $exercice_selectionne): ?>
                                <p class="text-sm text-slate-400 mb-1">
                                    <i class="fas fa-calendar-check mr-1"></i>Exercice <?= $exercice_selectionne['annee'] ?>
                                </p>
                                <p class="text-lg font-semibold text-white">
                                    <?= date('d/m/Y', strtotime($exercice_selectionne['date_debut'])) ?> au <?= date('d/m/Y', strtotime($exercice_selectionne['date_fin'])) ?>
                                </p>
                                <?php if ($exercice_selectionne['statut'] === 'Clôturé'): ?>
                                    <span class="inline-block mt-1 px-2 py-1 bg-slate-700/50 text-slate-300 rounded text-xs">
                                        <i class="fas fa-lock mr-1"></i>Clôturé
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-sm text-slate-400 mb-1">Période</p>
                                <p class="text-lg font-semibold text-white">
                                    <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tableau du grand livre -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                <tr>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">Date</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">N° Écriture</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">Journal</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">Compte</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">N° Pièce</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">N° Facture</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">Libellé</th>
                                    <th class="table-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider">Tiers</th>
                                    <th class="table-header px-4 py-3 text-center font-semibold text-slate-300 uppercase tracking-wider">Lettrage</th>
                                    <th class="col-montant-header text-right font-semibold text-slate-300 uppercase tracking-wider">Débit</th>
                                    <th class="col-montant-header text-right font-semibold text-slate-300 uppercase tracking-wider">Crédit</th>
                                    <th class="col-montant-header text-right font-semibold text-slate-300 uppercase tracking-wider">Solde</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <!-- Ligne solde initial -->
                                <tr class="bg-slate-800/50 font-semibold">
                                    <td colspan="9" class="px-4 py-3 text-slate-300">
                                        <i class="fas fa-info-circle mr-2"></i>Solde au <?= date('d/m/Y', strtotime($date_debut)) ?>
                                    </td>
                                    <td class="col-montant text-right text-slate-300">-</td>
                                    <td class="col-montant text-right text-slate-300">-</td>
                                    <td class="col-montant text-right <?= $solde_initial >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                        <?= safe_number_format(abs($solde_initial), 2) ?> <?= $solde_initial >= 0 ? 'D' : 'C' ?>
                                    </td>
                                </tr>

                                <?php
                                $solde_courant = $solde_initial;
                                $total_debit = 0;
                                $total_credit = 0;

                                if (empty($lignes)):
                                ?>
                                    <tr>
                                        <td colspan="12" class="px-4 py-8 text-center text-slate-400">
                                            <i class="fas fa-inbox text-3xl mb-2"></i>
                                            <p>Aucun mouvement pour cette période</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($lignes as $ligne):
                                        $solde_courant += ($ligne['debit'] - $ligne['credit']);
                                        $total_debit += $ligne['debit'];
                                        $total_credit += $ligne['credit'];
                                    ?>
                                        <tr class="hover:bg-slate-700/30 transition-colors">
                                            <td class="px-4 py-3 text-slate-300">
                                                <?= date('d/m/Y', strtotime($ligne['date_ecriture'])) ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <a href="../ecritures/voir.php?id=<?= $ligne['id_ecriture'] ?>"
                                                   class="text-blue-400 hover:text-blue-300"
                                                   title="ID: <?= $ligne['id_ecriture'] ?>">
                                                    <?= htmlspecialchars($ligne['numero_ecriture']) ?>
                                                </a>
                                            </td>
                                            <td class="px-4 py-3 text-slate-300">
                                                <span class="text-xs bg-slate-700 px-2 py-1 rounded">
                                                    <?= htmlspecialchars($ligne['journal']) ?>
                                                </span>
                                            </td>
                                            <td class="col-compte text-slate-300 font-mono">
                                                <?= htmlspecialchars($compte_filter) ?>
                                            </td>
                                            <td class="px-4 py-3 text-slate-300">
                                                <?= htmlspecialchars($ligne['num_piece'] ?? '-') ?>
                                            </td>
                                            <td class="px-4 py-3 text-slate-300">
                                                <?= htmlspecialchars($ligne['numero_facture'] ?? '-') ?>
                                            </td>
                                            <td class="col-libelle text-slate-300">
                                                <?= htmlspecialchars($ligne['libelle_ligne'] ?? '-') ?>
                                            </td>
                                            <td class="px-4 py-3 text-slate-300 text-sm">
                                                <?= htmlspecialchars($ligne['tiers_nom'] ?? '-') ?>
                                            </td>
                                            <td class="px-4 py-3 text-center text-sm">
                                                <?php if (!empty($ligne['lettrage'])): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded <?= $ligne['statut_lettrage'] === 'Lettré' ? 'bg-green-900/30 text-green-400' : 'bg-yellow-900/30 text-yellow-400' ?>">
                                                        <?= htmlspecialchars($ligne['lettrage']) ?>
                                                        <?php if ($ligne['statut_lettrage'] === 'Lettré'): ?>
                                                            <i class="fas fa-check-circle text-xs"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-slate-500">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-montant text-right text-green-400">
                                                <?= $ligne['debit'] > 0 ? safe_number_format($ligne['debit'], 2) : '-' ?>
                                            </td>
                                            <td class="col-montant text-right text-red-400">
                                                <?= $ligne['credit'] > 0 ? safe_number_format($ligne['credit'], 2) : '-' ?>
                                            </td>
                                            <td class="col-montant text-right <?= $solde_courant >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                                <?= safe_number_format(abs($solde_courant), 2) ?> <?= $solde_courant >= 0 ? 'D' : 'C' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <!-- Ligne totaux -->
                                    <tr class="bg-gradient-to-r from-slate-700 to-slate-800 font-bold">
                                        <td colspan="9" class="px-4 py-3 text-slate-200">
                                            <i class="fas fa-calculator mr-2"></i>Total des mouvements
                                        </td>
                                        <td class="col-montant text-right text-green-400">
                                            <?= safe_number_format($total_debit, 2) ?>
                                        </td>
                                        <td class="col-montant text-right text-red-400">
                                            <?= safe_number_format($total_credit, 2) ?>
                                        </td>
                                        <td class="col-montant text-right text-slate-200"></td>
                                    </tr>

                                    <!-- Ligne solde final -->
                                    <tr class="bg-slate-800/50 font-bold">
                                        <td colspan="9" class="px-4 py-3 text-slate-200">
                                            <i class="fas fa-check-circle mr-2"></i>Solde au <?= date('d/m/Y', strtotime($date_fin)) ?>
                                        </td>
                                        <td class="col-montant text-right text-slate-200">-</td>
                                        <td class="col-montant text-right text-slate-200">-</td>
                                        <td class="col-montant text-right <?= $solde_courant >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                            <?= safe_number_format(abs($solde_courant), 2) ?> <?= $solde_courant >= 0 ? 'D' : 'C' ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>


            <?php elseif (!empty($compte_filter)): ?>
                <div class="bg-yellow-900/20 border border-yellow-800/30 rounded-lg p-6 text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-400 text-3xl mb-3"></i>
                    <p class="text-yellow-300">Compte introuvable</p>
                </div>
            <?php else: ?>
                <div class="bg-blue-900/20 border border-blue-800/30 rounded-lg p-8 text-center">
                    <i class="fas fa-hand-pointer text-blue-400 text-4xl mb-4"></i>
                    <p class="text-blue-300 text-lg">Sélectionnez un compte pour afficher le grand livre</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleDatesCustom() {
            const exerciceSelect = document.getElementById('exercice_select');
            const dateDebut = document.getElementById('date_debut');
            const dateFin = document.getElementById('date_fin');

            if (exerciceSelect.value) {
                // Mode exercice : désactiver les dates
                dateDebut.disabled = true;
                dateFin.disabled = true;
                dateDebut.classList.add('opacity-50');
                dateFin.classList.add('opacity-50');
            } else {
                // Mode personnalisé : activer les dates
                dateDebut.disabled = false;
                dateFin.disabled = false;
                dateDebut.classList.remove('opacity-50');
                dateFin.classList.remove('opacity-50');
            }
        }

        function exportPDF() {
            const compte = '<?= htmlspecialchars($compte_filter) ?>';
            const exerciceId = '<?= $exercice_id ?? '' ?>';
            const dateDebut = '<?= htmlspecialchars($date_debut) ?>';
            const dateFin = '<?= htmlspecialchars($date_fin) ?>';
            const journal = '<?= htmlspecialchars($journal_filter) ?>';
            const lettrage = '<?= htmlspecialchars($lettrage_filter) ?>';

            const url = `export_grand_livre_pdf.php?compte=${compte}&exercice_id=${exerciceId}&date_debut=${dateDebut}&date_fin=${dateFin}&journal=${journal}&lettrage=${lettrage}`;
            window.location.href = url;
        }

        function exportExcel() {
            const compte = '<?= htmlspecialchars($compte_filter) ?>';
            const exerciceId = '<?= $exercice_id ?? '' ?>';
            const dateDebut = '<?= htmlspecialchars($date_debut) ?>';
            const dateFin = '<?= htmlspecialchars($date_fin) ?>';
            const journal = '<?= htmlspecialchars($journal_filter) ?>';
            const lettrage = '<?= htmlspecialchars($lettrage_filter) ?>';

            const url = `export_grand_livre_excel.php?compte=${compte}&exercice_id=${exerciceId}&date_debut=${dateDebut}&date_fin=${dateFin}&journal=${journal}&lettrage=${lettrage}`;
            window.location.href = url;
        }

        // Initialiser l'état des champs au chargement
        document.addEventListener('DOMContentLoaded', toggleDatesCustom);
    </script>
</body>
</html>
