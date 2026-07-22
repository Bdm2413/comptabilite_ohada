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

// Récupérer la liste des exercices
$stmt = $db->prepare("SELECT * FROM exercices WHERE societe_id = ? ORDER BY annee DESC");
$stmt->execute([$societe_id]);
$exercices = $stmt->fetchAll();

// Récupérer les paramètres de filtrage
$exercice_id = isset($_GET['exercice_id']) ? intval($_GET['exercice_id']) : null;
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : null;
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : null;
$classe_filter = isset($_GET['classe']) ? $_GET['classe'] : '';
$compte_debut = isset($_GET['compte_debut']) ? $_GET['compte_debut'] : '';
$compte_fin = isset($_GET['compte_fin']) ? $_GET['compte_fin'] : '';
$comptes_specifiques = isset($_GET['comptes_specifiques']) ? $_GET['comptes_specifiques'] : '';
$show_zero = isset($_GET['show_zero']) ? true : false;

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

// Récupérer la balance
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
    // Extraire l'année de début pour la logique des comptes de résultat
    $annee_debut = date('Y', strtotime($date_debut));
    $date_debut_annee = $annee_debut . '-01-01';

    // Requête pour récupérer tous les comptes avec leurs mouvements
    $sql = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.tableau,
            pc.bd,
            pc.bc,
            pc.rd,
            pc.rc,
            -- Mouvements antérieurs (pour comptes de bilan uniquement, sinon depuis début d'année)
            COALESCE(SUM(CASE
                WHEN e.statut = 'Validé'
                    AND e.societe_id = ?
                    AND e.date_ecriture < ?
                    AND (LEFT(pc.compte, 1) NOT IN ('6', '7', '8') OR e.date_ecriture >= ?)
                THEN le.debit
                ELSE 0
            END), 0) as debit_anterieur,
            COALESCE(SUM(CASE
                WHEN e.statut = 'Validé'
                    AND e.societe_id = ?
                    AND e.date_ecriture < ?
                    AND (LEFT(pc.compte, 1) NOT IN ('6', '7', '8') OR e.date_ecriture >= ?)
                THEN le.credit
                ELSE 0
            END), 0) as credit_anterieur,
            -- Mouvements période
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' AND e.societe_id = ? THEN le.debit ELSE 0 END), 0) as debit_periode,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' AND e.societe_id = ? THEN le.credit ELSE 0 END), 0) as credit_periode
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND pc.societe_id = ?
    ";

    $params = [$societe_id, $date_debut, $date_debut_annee, $societe_id, $date_debut, $date_debut_annee, $date_debut, $date_fin, $societe_id, $date_debut, $date_fin, $societe_id, $societe_id];

    // Filtre par classe
    if (!empty($classe_filter)) {
        $sql .= " AND LEFT(pc.compte, 1) = ?";
        $params[] = $classe_filter;
    }

    // Filtre par plage de comptes
    if (!empty($compte_debut)) {
        $sql .= " AND pc.compte >= ?";
        $params[] = $compte_debut;
    }
    if (!empty($compte_fin)) {
        $sql .= " AND pc.compte <= ?";
        $params[] = $compte_fin;
    }

    // Filtre par comptes spécifiques (séparés par des virgules)
    if (!empty($comptes_specifiques)) {
        $comptes_array = array_map('trim', explode(',', $comptes_specifiques));
        $placeholders = implode(',', array_fill(0, count($comptes_array), '?'));
        $sql .= " AND pc.compte IN ($placeholders)";
        $params = array_merge($params, $comptes_array);
    }

    $sql .= " GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc ORDER BY pc.compte";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $resultats = $stmt->fetchAll();

    foreach ($resultats as $row) {
        // Calculer les soldes
        $solde_anterieur = $row['debit_anterieur'] - $row['credit_anterieur'];
        $solde_final = ($row['debit_anterieur'] + $row['debit_periode']) - ($row['credit_anterieur'] + $row['credit_periode']);

        // Filtrer les comptes à solde nul si demandé
        if (!$show_zero && abs($solde_final) < 0.01 && abs($row['debit_periode']) < 0.01 && abs($row['credit_periode']) < 0.01) {
            continue;
        }

        // Déterminer le tableau et la rubrique
        $tableau = $row['tableau'] ?? '';
        $rubrique = '';

        // Logique pour déterminer la rubrique selon le tableau et le solde
        if ($tableau === 'Bilan') {
            // Pour les comptes de Bilan
            if ($solde_final > 0) {
                // Solde débiteur => BD
                $rubrique = $row['bd'] ?? '';
            } else if ($solde_final < 0) {
                // Solde créditeur => BC
                $rubrique = $row['bc'] ?? '';
            }
        } else if ($tableau === 'Résultat') {
            // Pour les comptes de Résultat
            if ($solde_final > 0) {
                // Solde débiteur => RD
                $rubrique = $row['rd'] ?? '';
            } else if ($solde_final < 0) {
                // Solde créditeur => RC
                $rubrique = $row['rc'] ?? '';
            }
        }

        $ligne = [
            'compte' => $row['compte'],
            'intitule' => $row['intitule_compte'],
            'tableau' => $tableau,
            'rubrique' => $rubrique,
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

$pageTitle = "Balance Générale";
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

        /* Colonnes figées pour le tableau mensuel */
        .col-compte {
            position: sticky;
            left: 0;
            z-index: 10;
            background-color: rgb(30, 41, 59);
            min-width: 90px;
            max-width: 90px;
            font-size: var(--font-size-sm);
            white-space: nowrap;
            padding: 8px 6px !important;
        }
        .col-compte-header {
            position: sticky;
            left: 0;
            z-index: 20;
            background-color: rgb(51, 65, 85);
            min-width: 90px;
            max-width: 90px;
            font-size: var(--font-size-xs);
            padding: 8px 6px !important;
        }
        .col-intitule {
            position: sticky;
            left: 90px;
            z-index: 10;
            background-color: rgb(30, 41, 59);
            min-width: 300px;
            max-width: 300px;
            font-size: var(--font-size-sm);
            padding: 8px 6px !important;
        }
        .col-intitule-header {
            position: sticky;
            left: 90px;
            z-index: 20;
            background-color: rgb(51, 65, 85);
            min-width: 300px;
            max-width: 300px;
            font-size: var(--font-size-xs);
            padding: 8px 6px !important;
        }
        .col-tableau {
            position: sticky;
            left: 390px;
            z-index: 10;
            background-color: rgb(30, 41, 59);
            min-width: 70px;
            max-width: 70px;
            font-size: var(--font-size-sm);
            padding: 8px 4px !important;
        }
        .col-tableau-header {
            position: sticky;
            left: 390px;
            z-index: 20;
            background-color: rgb(51, 65, 85);
            min-width: 70px;
            max-width: 70px;
            font-size: var(--font-size-xs);
            padding: 8px 4px !important;
        }

        /* Colonnes de montants - OPTIMISÉES pour gros montants (milliards) */
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

        /* Colonne rubrique */
        .col-rubrique {
            position: sticky;
            left: 460px;
            z-index: 10;
            background-color: rgb(30, 41, 59);
            min-width: 50px;
            max-width: 50px;
            font-size: var(--font-size-xs);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding: 8px 2px !important;
        }

        .col-rubrique-header {
            position: sticky;
            left: 460px;
            z-index: 20;
            background-color: rgb(51, 65, 85);
            font-size: var(--font-size-xs);
            min-width: 50px;
            max-width: 50px;
            padding: 8px 2px !important;
        }

        /* Ligne de totaux fixe en bas */
        .totaux-row-sticky {
            position: sticky;
            bottom: 0;
            z-index: 15;
            box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.3);
            font-size: var(--font-size-sm);
        }

        .totaux-col-fixe {
            position: sticky;
            z-index: 16;
            background: linear-gradient(to right, rgb(51, 65, 85), rgb(30, 41, 59));
        }

        .totaux-compte {
            left: 0;
        }

        /* En-têtes de mois */
        .mois-header {
            font-size: var(--font-size-md);
        }

        /* Style pour page-title */
        .page-title {
            font-size: var(--font-size-xl);
        }

        /* Style pour table-header */
        .table-header {
            font-size: var(--font-size-xs);
        }

        /* Colonne libelle */
        .col-libelle {
            font-size: var(--font-size-sm);
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-emerald-600 mb-2">
                            <i class="fas fa-balance-scale mr-3"></i>Balance Générale
                        </h1>
                        <p style="font-size: var(--font-size-base);" class="text-slate-400">Synthèse des soldes de tous les comptes</p>
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
                        <!-- Exercice -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-check mr-2"></i>Exercice
                            </label>
                            <select name="exercice_id" id="exercice_select" onchange="toggleDatesCustom()"
                                    class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-slate-100">
                                <option value="">Période personnalisée</option>
                                <?php foreach ($exercices as $ex): ?>
                                    <option value="<?= $ex['id'] ?>" <?= $exercice_id == $ex['id'] ? 'selected' : '' ?>>
                                        Exercice <?= $ex['annee'] ?> (<?= date('d/m/Y', strtotime($ex['date_debut'])) ?> - <?= date('d/m/Y', strtotime($ex['date_fin'])) ?>)
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
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-slate-100"
                                   <?= $mode_exercice ? 'disabled' : '' ?>>
                        </div>

                        <!-- Date fin -->
                        <div id="date-fin-container">
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Date fin
                            </label>
                            <input type="date" name="date_fin" id="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-slate-100"
                                   <?= $mode_exercice ? 'disabled' : '' ?>>
                        </div>

                        <!-- Classe de compte -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-filter mr-2"></i>Classe
                            </label>
                            <select name="classe" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-slate-100">
                                <option value="">Toutes</option>
                                <option value="1" <?= $classe_filter === '1' ? 'selected' : '' ?>>Classe 1</option>
                                <option value="2" <?= $classe_filter === '2' ? 'selected' : '' ?>>Classe 2</option>
                                <option value="3" <?= $classe_filter === '3' ? 'selected' : '' ?>>Classe 3</option>
                                <option value="4" <?= $classe_filter === '4' ? 'selected' : '' ?>>Classe 4</option>
                                <option value="5" <?= $classe_filter === '5' ? 'selected' : '' ?>>Classe 5</option>
                                <option value="6" <?= $classe_filter === '6' ? 'selected' : '' ?>>Classe 6</option>
                                <option value="7" <?= $classe_filter === '7' ? 'selected' : '' ?>>Classe 7</option>
                                <option value="8" <?= $classe_filter === '8' ? 'selected' : '' ?>>Classe 8</option>
                            </select>
                        </div>

                        <!-- Filtre par intervalle de comptes -->
                        <div class="flex items-center gap-2">
                            <input type="text" name="compte_debut" placeholder="Compte début"
                                   value="<?= htmlspecialchars($compte_debut) ?>"
                                   class="px-3 py-2 bg-slate-800 border border-slate-700 text-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-sm w-32">
                            <span class="text-slate-400">à</span>
                            <input type="text" name="compte_fin" placeholder="Compte fin"
                                   value="<?= htmlspecialchars($compte_fin) ?>"
                                   class="px-3 py-2 bg-slate-800 border border-slate-700 text-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-sm w-32">
                        </div>

                        <!-- Filtre par comptes spécifiques -->
                        <div>
                            <input type="text" name="comptes_specifiques" placeholder="Ex: 411000, 421000, 521000"
                                   value="<?= htmlspecialchars($comptes_specifiques) ?>"
                                   class="px-3 py-2 bg-slate-800 border border-slate-700 text-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-sm w-64"
                                   title="Séparez les comptes par des virgules">
                        </div>

                        <!-- Afficher soldes nuls -->
                        <div>
                            <label class="flex items-center gap-2 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg cursor-pointer hover:bg-slate-750">
                                <input type="checkbox" name="show_zero" value="1" <?= $show_zero ? 'checked' : '' ?>
                                       class="w-4 h-4 text-green-600 bg-slate-700 border-slate-600 rounded focus:ring-green-500">
                                <span class="text-slate-300 text-sm">Soldes nuls</span>
                            </label>
                        </div>

                        <!-- Bouton Afficher -->
                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-search"></i>
                            Afficher
                        </button>

                        <!-- Bouton Réinitialiser -->
                        <a href="balance_generale.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-redo"></i>
                            Réinit.
                        </a>

                        <?php if (!empty($balance)): ?>
                            <!-- Séparateur vertical -->
                            <div class="border-l border-slate-600 h-8"></div>

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

            <!-- En-tête de la période -->
            <div class="bg-gradient-to-r from-green-900/30 to-emerald-900/30 border border-green-800/30 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <?php if ($mode_exercice && $exercice_selectionne): ?>
                            <h2 class="text-xl font-bold text-white mb-1">
                                <i class="fas fa-calendar-check mr-2 text-purple-400"></i>Balance Exercice <?= $exercice_selectionne['annee'] ?>
                            </h2>
                            <p class="text-slate-300">
                                Du <?= date('d/m/Y', strtotime($exercice_selectionne['date_debut'])) ?> au <?= date('d/m/Y', strtotime($exercice_selectionne['date_fin'])) ?>
                            </p>
                            <?php if ($exercice_selectionne['statut'] === 'Clôturé'): ?>
                                <span class="inline-block mt-2 px-3 py-1 bg-slate-700/50 text-slate-300 rounded-full text-xs">
                                    <i class="fas fa-lock mr-1"></i>Exercice clôturé
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <h2 class="text-xl font-bold text-white mb-1">
                                Balance au <?= date('d/m/Y', strtotime($date_fin)) ?>
                            </h2>
                            <p class="text-slate-300">
                                Période du <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-slate-400 mb-1">Nombre de comptes</p>
                        <p class="text-2xl font-bold text-white"><?= count($balance) ?></p>
                    </div>
                </div>
            </div>

            <!-- Tableau de la balance -->
            <div class="flex-1 flex flex-col bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden min-h-0">
                <div class="flex-1 overflow-x-auto overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-slate-700 to-slate-800 sticky top-0 z-30">
                            <tr>
                                <th rowspan="2" class="col-compte-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Compte
                                </th>
                                <th rowspan="2" class="col-intitule-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Intitulé
                                </th>
                                <th rowspan="2" class="col-tableau-header px-4 py-3 text-center font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Tableau
                                </th>
                                <th rowspan="2" class="col-rubrique-header px-4 py-3 text-left font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600">
                                    Rubrique
                                </th>
                                <th colspan="2" class="col-montant-header px-4 py-2 text-center font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">
                                    Soldes Antérieurs
                                </th>
                                <th colspan="2" class="col-montant-header px-4 py-2 text-center font-semibold text-slate-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/30">
                                    Mouvements Période
                                </th>
                                <th colspan="2" class="col-montant-header px-4 py-2 text-center font-semibold text-slate-300 uppercase tracking-wider bg-slate-700/50">
                                    Soldes Finaux
                                </th>
                            </tr>
                            <tr>
                                <th class="col-montant-header px-4 py-2 text-right font-semibold text-green-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">Débit</th>
                                <th class="col-montant-header px-4 py-2 text-right font-semibold text-red-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">Crédit</th>
                                <th class="col-montant-header px-4 py-2 text-right font-semibold text-green-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/30">Débit</th>
                                <th class="col-montant-header px-4 py-2 text-right font-semibold text-red-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/30">Crédit</th>
                                <th class="col-montant-header px-4 py-2 text-right font-semibold text-green-300 uppercase tracking-wider border-r border-slate-600 bg-slate-700/50">Débit</th>
                                <th class="col-montant-header px-4 py-2 text-right font-semibold text-red-300 uppercase tracking-wider bg-slate-700/50">Crédit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <?php if (empty($balance)): ?>
                                <tr>
                                    <td colspan="10" class="px-4 py-8 text-center text-slate-400">
                                        <i class="fas fa-inbox text-3xl mb-2"></i>
                                        <p>Aucun mouvement pour cette période</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($balance as $ligne): ?>
                                    <tr class="hover:bg-slate-700/30 transition-colors">
                                        <td class="col-compte px-4 py-3 font-mono text-slate-300 border-r border-slate-700">
                                            <a href="grand_livre.php?compte=<?= urlencode($ligne['compte']) ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>"
                                               class="text-blue-400 hover:text-blue-300">
                                                <?= htmlspecialchars($ligne['compte']) ?>
                                            </a>
                                        </td>
                                        <td class="col-libelle px-4 py-3 text-slate-300 border-r border-slate-700">
                                            <?= htmlspecialchars($ligne['intitule']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-center text-slate-300 border-r border-slate-700">
                                            <?= htmlspecialchars($ligne['tableau']) ?>
                                        </td>
                                        <td class="col-rubrique px-4 py-3 text-slate-300 border-r border-slate-700">
                                            <?= htmlspecialchars($ligne['rubrique']) ?>
                                        </td>
                                        <td class="col-montant px-4 py-3 text-right text-green-400 border-r border-slate-700">
                                            <?= $ligne['debit_anterieur'] > 0 ? safe_number_format($ligne['debit_anterieur'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant px-4 py-3 text-right text-red-400 border-r border-slate-700">
                                            <?= $ligne['credit_anterieur'] > 0 ? safe_number_format($ligne['credit_anterieur'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant px-4 py-3 text-right text-green-400 border-r border-slate-700">
                                            <?= $ligne['debit_periode'] > 0 ? safe_number_format($ligne['debit_periode'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant px-4 py-3 text-right text-red-400 border-r border-slate-700">
                                            <?= $ligne['credit_periode'] > 0 ? safe_number_format($ligne['credit_periode'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant px-4 py-3 text-right text-green-400 border-r border-slate-700">
                                            <?= $ligne['debit_final'] > 0 ? safe_number_format($ligne['debit_final'], 2) : '-' ?>
                                        </td>
                                        <td class="col-montant px-4 py-3 text-right text-red-400">
                                            <?= $ligne['credit_final'] > 0 ? safe_number_format($ligne['credit_final'], 2) : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Ligne totaux -->
                                <tr class="bg-gradient-to-r from-slate-700 to-slate-800 font-bold text-white">
                                    <td colspan="4" class="px-4 py-3 border-r border-slate-600">
                                        <i class="fas fa-calculator mr-2"></i>TOTAUX
                                    </td>
                                    <td class="col-montant px-4 py-3 text-right text-green-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['debit_anterieur'], 2) ?>
                                    </td>
                                    <td class="col-montant px-4 py-3 text-right text-red-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['credit_anterieur'], 2) ?>
                                    </td>
                                    <td class="col-montant px-4 py-3 text-right text-green-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['debit_periode'], 2) ?>
                                    </td>
                                    <td class="col-montant px-4 py-3 text-right text-red-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['credit_periode'], 2) ?>
                                    </td>
                                    <td class="col-montant px-4 py-3 text-right text-green-300 border-r border-slate-600">
                                        <?= safe_number_format($totaux['debit_final'], 2) ?>
                                    </td>
                                    <td class="col-montant px-4 py-3 text-right text-red-300">
                                        <?= safe_number_format($totaux['credit_final'], 2) ?>
                                    </td>
                                </tr>

                                <!-- Vérification équilibre -->
                                <?php
                                $ecart_anterieur = $totaux['debit_anterieur'] - $totaux['credit_anterieur'];
                                $ecart_periode = $totaux['debit_periode'] - $totaux['credit_periode'];
                                $ecart_final = $totaux['debit_final'] - $totaux['credit_final'];
                                $equilibre_anterieur = abs($ecart_anterieur) < 0.01;
                                $equilibre_periode = abs($ecart_periode) < 0.01;
                                $equilibre_final = abs($ecart_final) < 0.01;
                                ?>
                                <tr class="bg-slate-800/50">
                                    <td colspan="4" class="px-4 py-3 font-semibold border-r border-slate-700">
                                        <i class="fas fa-check-circle mr-2"></i>Équilibre
                                    </td>
                                    <td colspan="2" class="px-4 py-3 text-center border-r border-slate-700">
                                        <?php if ($equilibre_anterieur): ?>
                                            <span class="text-green-400"><i class="fas fa-check"></i> Équilibré</span>
                                        <?php else: ?>
                                            <span class="text-red-400">
                                                <i class="fas fa-times"></i> Écart: <?= safe_number_format(abs($ecart_anterieur), 2) ?>
                                                <span class="text-xs">(<?= $ecart_anterieur > 0 ? 'D' : 'C' ?>)</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td colspan="2" class="px-4 py-3 text-center border-r border-slate-700">
                                        <?php if ($equilibre_periode): ?>
                                            <span class="text-green-400"><i class="fas fa-check"></i> Équilibré</span>
                                        <?php else: ?>
                                            <span class="text-red-400">
                                                <i class="fas fa-times"></i> Écart: <?= safe_number_format(abs($ecart_periode), 2) ?>
                                                <span class="text-xs">(<?= $ecart_periode > 0 ? 'D' : 'C' ?>)</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td colspan="2" class="px-4 py-3 text-center">
                                        <?php if ($equilibre_final): ?>
                                            <span class="text-green-400"><i class="fas fa-check"></i> Équilibré</span>
                                        <?php else: ?>
                                            <span class="text-red-400">
                                                <i class="fas fa-times"></i> Écart: <?= safe_number_format(abs($ecart_final), 2) ?>
                                                <span class="text-xs">(<?= $ecart_final > 0 ? 'D' : 'C' ?>)</span>
                                            </span>
                                        <?php endif; ?>
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
            const params = new URLSearchParams({
                exercice_id: '<?= $exercice_id ?? '' ?>',
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                classe: '<?= $classe_filter ?>',
                show_zero: '<?= $show_zero ? '1' : '0' ?>'
            });
            window.location.href = 'export_balance_generale_pdf.php?' + params.toString();
        }

        function exportExcel() {
            const params = new URLSearchParams({
                exercice_id: '<?= $exercice_id ?? '' ?>',
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>',
                classe: '<?= $classe_filter ?>',
                show_zero: '<?= $show_zero ? '1' : '0' ?>'
            });
            window.location.href = 'export_balance_generale_excel.php?' + params.toString();
        }

        // Initialiser l'état des champs au chargement
        document.addEventListener('DOMContentLoaded', toggleDatesCustom);
    </script>
</body>
</html>
