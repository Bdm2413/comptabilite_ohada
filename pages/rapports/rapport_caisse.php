<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Paramètres par défaut
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');

// Fonction de formatage sécurisé
function safe_number_format($number, $decimals = 2) {
    if ($number === null || $number === '') return '-';
    return number_format((float)$number, $decimals, ',', ' ');
}

// Récupérer tous les comptes de caisse disponibles pour cette société
$stmt_caisse = $db->prepare("
    SELECT compte, intitule_compte
    FROM plan_comptable
    WHERE compte LIKE '571%' AND actif = 'Oui' AND societe_id = ?
    ORDER BY compte
");
$stmt_caisse->execute([$societe_id]);
$comptes_caisse = $stmt_caisse->fetchAll();

// Compte caisse : celui demandé par l'URL, sinon le premier disponible pour cette société
$premier_compte = !empty($comptes_caisse) ? $comptes_caisse[0]['compte'] : '5711000';
$compte_caisse = isset($_GET['compte_caisse']) ? $_GET['compte_caisse'] : $premier_compte;

// Calculer le solde initial (avant la date de début)
$stmt = $db->prepare("
    SELECT
        COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0) as solde_initial
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE le.compte = ?
    AND e.date_ecriture < ?
    AND e.statut = 'Validé'
    AND e.societe_id = ?
");
$stmt->execute([$compte_caisse, $date_debut, $societe_id]);
$result = $stmt->fetch();
$solde_initial = $result['solde_initial'] ?? 0;

// Récupérer les transactions de la période
$stmt = $db->prepare("
    SELECT
        e.id as id_ecriture,
        e.date_ecriture,
        e.numero_ecriture,
        e.libelle as libelle_ecriture,
        le.libelle as libelle_ligne,
        e.reference_piece,
        le.compte,
        pc.intitule_compte,
        le.debit,
        le.credit,
        t.nom as tiers_nom,
        t.type as tiers_type
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
    LEFT JOIN plan_tiers t ON le.compte_tiers = t.compte_tiers AND t.societe_id = e.societe_id
    WHERE le.compte = ?
    AND e.date_ecriture BETWEEN ? AND ?
    AND e.statut = 'Validé'
    AND e.societe_id = ?
    ORDER BY e.date_ecriture ASC, e.numero_ecriture ASC, le.id ASC
");
$stmt->execute([$compte_caisse, $date_debut, $date_fin, $societe_id]);
$transactions = $stmt->fetchAll();

// Calculer le solde final
$solde_final = $solde_initial;
$total_entrees = 0;
$total_sorties = 0;

foreach ($transactions as &$transaction) {
    $transaction['solde_avant'] = $solde_final;
    if ($transaction['debit'] > 0) {
        $total_entrees += $transaction['debit'];
        $solde_final += $transaction['debit'];
    }
    if ($transaction['credit'] > 0) {
        $total_sorties += $transaction['credit'];
        $solde_final -= $transaction['credit'];
    }
    $transaction['solde_apres'] = $solde_final;
}

// Récupérer l'intitulé du compte de caisse
$stmt = $db->prepare("SELECT intitule_compte FROM plan_comptable WHERE compte = ?");
$stmt->execute([$compte_caisse]);
$compte_info = $stmt->fetch();
$intitule_caisse = $compte_info['intitule_compte'] ?? 'Caisse';

// Vérifier si un billetage existe déjà pour aujourd'hui
$stmt = $db->prepare("
    SELECT id, createur, date_creation
    FROM billetages
    WHERE date_billetage = CURDATE() AND compte_caisse = ? AND societe_id = ?
");
$stmt->execute([$compte_caisse, $societe_id]);
$billetage_aujourdhui = $stmt->fetch();

$pageTitle = "Rapport de Caisse";
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
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .print-full-width { width: 100% !important; max-width: 100% !important; }
        }

        .col-date { width: 90px; padding: 0.75rem 1rem; text-align: left; }
        .col-num { width: 80px; padding: 0.75rem 1rem; text-align: left; }
        .col-tiers { width: 150px; padding: 0.75rem 1rem; text-align: left; }
        .col-purpose { padding: 0.75rem 1rem; text-align: left; min-width: 250px; }
        .col-account { width: 100px; padding: 0.75rem 1rem; text-align: left; }
        .col-montant { width: 110px; padding: 0.75rem 1rem; text-align: right; font-family: 'Courier New', monospace; white-space: nowrap; }

        table { border-collapse: collapse; width: 100%; }
        th { position: sticky; top: 0; z-index: 10; }

        .billetage-cell { width: 120px; text-align: right; padding: 0.5rem; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border-b border-slate-700 p-6 no-print">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-blue-600 mb-2">
                            <i class="fas fa-cash-register mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400">Historique des transactions et billetage de caisse</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="historique_billetages.php?compte_caisse=<?= urlencode($compte_caisse) ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>"
                           class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-history"></i>
                            Historique
                        </a>
                        <button onclick="exportExcel()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            Export Excel
                        </button>
                        <button onclick="exportPDF()" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            Export PDF
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filtres -->
            <div class="p-6 no-print">
                <form method="GET" class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-wallet text-green-400 mr-2"></i>Compte de caisse
                            </label>
                            <select name="compte_caisse" class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500">
                                <?php foreach ($comptes_caisse as $c): ?>
                                    <option value="<?= $c['compte'] ?>" <?= $c['compte'] == $compte_caisse ? 'selected' : '' ?>>
                                        <?= $c['compte'] ?> - <?= htmlspecialchars($c['intitule_compte']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt text-blue-400 mr-2"></i>Date début
                            </label>
                            <input type="date" name="date_debut" value="<?= $date_debut ?>"
                                   class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-check text-purple-400 mr-2"></i>Date fin
                            </label>
                            <input type="date" name="date_fin" value="<?= $date_fin ?>"
                                   class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500">
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full px-6 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center justify-center gap-2">
                                <i class="fas fa-search"></i>
                                Afficher
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Informations de période -->
            <div class="px-6 pb-4">
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="text-center p-4 bg-blue-500/10 rounded-lg border border-blue-500/30">
                            <p class="text-sm text-blue-300 mb-1">Solde Initial</p>
                            <p class="text-2xl font-bold text-blue-400 font-mono"><?= safe_number_format($solde_initial, 2) ?></p>
                        </div>
                        <div class="text-center p-4 bg-green-500/10 rounded-lg border border-green-500/30">
                            <p class="text-sm text-green-300 mb-1">Total Entrées</p>
                            <p class="text-2xl font-bold text-green-400 font-mono"><?= safe_number_format($total_entrees, 2) ?></p>
                        </div>
                        <div class="text-center p-4 bg-red-500/10 rounded-lg border border-red-500/30">
                            <p class="text-sm text-red-300 mb-1">Total Sorties</p>
                            <p class="text-2xl font-bold text-red-400 font-mono"><?= safe_number_format($total_sorties, 2) ?></p>
                        </div>
                        <div class="text-center p-4 bg-purple-500/10 rounded-lg border border-purple-500/30">
                            <p class="text-sm text-purple-300 mb-1">Solde Final</p>
                            <p class="text-2xl font-bold text-purple-400 font-mono"><?= safe_number_format($solde_final, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tableau des transactions -->
            <div class="px-6 pb-6">
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gradient-to-r from-slate-700 to-slate-800 text-slate-200">
                                    <th class="col-date border-r border-slate-600">
                                        <i class="fas fa-calendar mr-1"></i>DATE
                                    </th>
                                    <th class="col-num border-r border-slate-600">
                                        <i class="fas fa-hashtag mr-1"></i>NUM
                                    </th>
                                    <th class="col-tiers border-r border-slate-600">
                                        <i class="fas fa-calendar-alt mr-1"></i>MOIS
                                    </th>
                                    <th class="col-purpose border-r border-slate-600">
                                        PURPOSE
                                    </th>
                                    <th class="col-account border-r border-slate-600">
                                        GL ACCOUNT CODES
                                    </th>
                                    <th class="col-montant border-r border-slate-600 text-green-300">
                                        <i class="fas fa-arrow-down mr-1"></i>CASH IN
                                    </th>
                                    <th class="col-montant border-r border-slate-600 text-red-300">
                                        <i class="fas fa-arrow-up mr-1"></i>CASH OUT
                                    </th>
                                    <th class="col-montant text-blue-300">
                                        <i class="fas fa-balance-scale mr-1"></i>BALANCE
                                    </th>
                                </tr>

                                <!-- Ligne solde initial -->
                                <tr class="bg-slate-800/50 font-semibold border-b-2 border-blue-500/30">
                                    <td colspan="5" class="px-4 py-3 text-slate-300">
                                        <i class="fas fa-info-circle mr-2 text-blue-400"></i>OPENING BALANCE
                                    </td>
                                    <td class="col-montant text-slate-400">-</td>
                                    <td class="col-montant text-slate-400">-</td>
                                    <td class="col-montant text-blue-400 font-bold">
                                        <?= safe_number_format($solde_initial, 2) ?>
                                    </td>
                                </tr>
                            </thead>
                            <tbody class="bg-slate-900/50">
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                            <i class="fas fa-inbox text-4xl mb-3 block"></i>
                                            Aucune transaction trouvée pour cette période
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $trans): ?>
                                        <tr class="border-b border-slate-800 hover:bg-slate-800/50 transition-colors">
                                            <td class="col-date border-r border-slate-700 text-slate-300">
                                                <?= date('d/m/y', strtotime($trans['date_ecriture'])) ?>
                                            </td>
                                            <td class="col-num border-r border-slate-700">
                                                <a href="../ecritures/voir.php?id=<?= $trans['id_ecriture'] ?>"
                                                   class="text-blue-400 hover:text-blue-300 hover:underline transition-colors"
                                                   title="Voir le détail de l'écriture">
                                                    <?= htmlspecialchars($trans['numero_ecriture']) ?>
                                                </a>
                                            </td>
                                            <td class="col-tiers border-r border-slate-700 text-slate-300">
                                                <?php
                                                    $mois_fr = [
                                                        '01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril',
                                                        '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août',
                                                        '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre'
                                                    ];
                                                    $date = new DateTime($trans['date_ecriture']);
                                                    $mois_num = $date->format('m');
                                                    $annee = $date->format('Y');
                                                    echo $mois_fr[$mois_num] . ' ' . $annee;
                                                ?>
                                            </td>
                                            <td class="col-purpose border-r border-slate-700 text-slate-300">
                                                <?= htmlspecialchars($trans['libelle_ligne'] ?: $trans['libelle_ecriture']) ?>
                                            </td>
                                            <td class="col-account border-r border-slate-700 text-slate-400 text-sm">
                                                <?= htmlspecialchars($trans['compte']) ?>
                                            </td>
                                            <td class="col-montant border-r border-slate-700 text-green-400">
                                                <?= $trans['debit'] > 0 ? safe_number_format($trans['debit'], 2) : '' ?>
                                            </td>
                                            <td class="col-montant border-r border-slate-700 text-red-400">
                                                <?= $trans['credit'] > 0 ? safe_number_format($trans['credit'], 2) : '' ?>
                                            </td>
                                            <td class="col-montant text-blue-400">
                                                <?= safe_number_format($trans['solde_apres'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-gradient-to-r from-slate-700 to-slate-800 font-bold text-white border-t-2 border-slate-600">
                                    <td colspan="5" class="px-4 py-3">
                                        <i class="fas fa-calculator mr-2"></i>TOTAL
                                    </td>
                                    <td class="col-montant text-green-300 border-l border-slate-600">
                                        <?= safe_number_format($total_entrees, 2) ?>
                                    </td>
                                    <td class="col-montant text-red-300 border-l border-slate-600">
                                        <?= safe_number_format($total_sorties, 2) ?>
                                    </td>
                                    <td class="col-montant text-blue-300 border-l border-slate-600">
                                        <?= safe_number_format($solde_final, 2) ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Billetage -->
            <div class="px-6 pb-6">
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-white flex items-center">
                            <i class="fas fa-money-bill-wave mr-3 text-green-400"></i>
                            PROCÈS-VERBAL DE CAISSE (BILLETAGE)
                        </h2>
                        <?php if ($billetage_aujourdhui): ?>
                            <div class="flex items-center gap-3">
                                <div class="px-4 py-2 bg-green-900/30 border border-green-700/50 rounded-lg">
                                    <i class="fas fa-check-circle text-green-400 mr-2"></i>
                                    <span class="text-green-300 text-sm">
                                        Billetage enregistré aujourd'hui par <?= htmlspecialchars($billetage_aujourdhui['createur']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <button onclick="enregistrerBilletage()" id="btnEnregistrer"
                                    class="px-6 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all inline-flex items-center gap-2 font-semibold">
                                <i class="fas fa-save"></i>
                                Enregistrer le billetage
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Billets -->
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                            <h3 class="text-lg font-semibold text-green-300 mb-4 flex items-center">
                                <i class="fas fa-money-bill mr-2"></i>Bank notes in the tin
                            </h3>
                            <p class="text-sm text-slate-400 mb-3">Please input bank notes amount in the highlighted area</p>

                            <table class="w-full">
                                <thead>
                                    <tr class="text-slate-400 text-sm border-b border-slate-700">
                                        <th class="text-left py-2">Coupure</th>
                                        <th class="text-right py-2">Quantité</th>
                                        <th class="text-right py-2">Montant</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">10 000 x</td>
                                        <td class="text-right">
                                            <input type="number" id="bill_10000" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-green-400" id="total_10000">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">5 000 x</td>
                                        <td class="text-right">
                                            <input type="number" id="bill_5000" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-green-400" id="total_5000">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">2 000 x</td>
                                        <td class="text-right">
                                            <input type="number" id="bill_2000" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-green-400" id="total_2000">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">1 000 x</td>
                                        <td class="text-right">
                                            <input type="number" id="bill_1000" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-green-400" id="total_1000">0</td>
                                    </tr>
                                    <tr class="border-b-2 border-slate-700">
                                        <td class="py-2 text-slate-300">500 x</td>
                                        <td class="text-right">
                                            <input type="number" id="bill_500" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-green-400" id="total_500">0</td>
                                    </tr>
                                    <tr class="font-bold bg-slate-700/50">
                                        <td colspan="2" class="py-3 text-white">Sous-total Billets</td>
                                        <td class="billetage-cell text-green-300 text-lg" id="subtotal_bills">0</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pièces -->
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                            <h3 class="text-lg font-semibold text-blue-300 mb-4 flex items-center">
                                <i class="fas fa-coins mr-2"></i>Coins in the tin
                            </h3>
                            <p class="text-sm text-slate-400 mb-3">Please input coins amount in the highlighted area</p>

                            <table class="w-full">
                                <thead>
                                    <tr class="text-slate-400 text-sm border-b border-slate-700">
                                        <th class="text-left py-2">Coupure</th>
                                        <th class="text-right py-2">Quantité</th>
                                        <th class="text-right py-2">Montant</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">500 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_500" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_500">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">200 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_200" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_200">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">100 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_100" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_100">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">50 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_50" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_50">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">25 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_25" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_25">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">10 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_10" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_10">0</td>
                                    </tr>
                                    <tr class="border-b border-slate-700">
                                        <td class="py-2 text-slate-300">5 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_5" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_5">0</td>
                                    </tr>
                                    <tr class="border-b-2 border-slate-700">
                                        <td class="py-2 text-slate-300">1 x</td>
                                        <td class="text-right">
                                            <input type="number" id="coin_1" value="0" min="0"
                                                   class="w-24 px-2 py-1 bg-yellow-500/20 border border-yellow-500/50 rounded text-right text-white focus:outline-none focus:border-yellow-400"
                                                   onchange="calculateBilletage()">
                                        </td>
                                        <td class="billetage-cell text-blue-400" id="total_coin_1">0</td>
                                    </tr>
                                    <tr class="font-bold bg-slate-700/50">
                                        <td colspan="2" class="py-3 text-white">Sous-total Pièces</td>
                                        <td class="billetage-cell text-blue-300 text-lg" id="subtotal_coins">0</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Total et Écart -->
                    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-purple-500/10 rounded-lg p-4 border border-purple-500/30">
                            <p class="text-sm text-purple-300 mb-2 uppercase">Total Balance in the Safe</p>
                            <p class="text-3xl font-bold text-purple-400 font-mono" id="total_billetage">0</p>
                        </div>
                        <div class="bg-blue-500/10 rounded-lg p-4 border border-blue-500/30">
                            <p class="text-sm text-blue-300 mb-2 uppercase">Solde Comptable</p>
                            <p class="text-3xl font-bold text-blue-400 font-mono"><?= safe_number_format($solde_final, 2) ?></p>
                        </div>
                        <div class="bg-yellow-500/10 rounded-lg p-4 border border-yellow-500/30">
                            <p class="text-sm text-yellow-300 mb-2 uppercase flex items-center">
                                Variance
                                <i class="fas fa-info-circle ml-2 text-xs" title="Différence entre solde physique et comptable"></i>
                            </p>
                            <p class="text-3xl font-bold text-yellow-400 font-mono" id="variance">0</p>
                        </div>
                    </div>

                    <!-- Commentaire sur l'écart -->
                    <div class="mt-4 bg-yellow-500/10 rounded-lg p-4 border border-yellow-500/30">
                        <label class="block text-sm font-semibold text-yellow-300 mb-2">COMMENTARY ON VARIANCE:</label>
                        <textarea id="commentary" rows="3"
                                  class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-yellow-500"
                                  placeholder="Expliquez les raisons de l'écart s'il y en a..."></textarea>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const soldeComptable = <?= $solde_final ?>;

        function exportExcel() {
            const params = new URLSearchParams({
                compte_caisse: '<?= $compte_caisse ?>',
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>'
            });
            window.location.href = 'export_rapport_caisse_excel.php?' + params.toString();
        }

        function exportPDF() {
            const params = new URLSearchParams({
                compte_caisse: '<?= $compte_caisse ?>',
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>'
            });
            window.location.href = 'export_rapport_caisse_pdf.php?' + params.toString();
        }

        function calculateBilletage() {
            // Billets
            const bill_10000 = parseInt(document.getElementById('bill_10000').value) || 0;
            const bill_5000 = parseInt(document.getElementById('bill_5000').value) || 0;
            const bill_2000 = parseInt(document.getElementById('bill_2000').value) || 0;
            const bill_1000 = parseInt(document.getElementById('bill_1000').value) || 0;
            const bill_500 = parseInt(document.getElementById('bill_500').value) || 0;

            // Calculer totaux billets
            const total_10000 = bill_10000 * 10000;
            const total_5000 = bill_5000 * 5000;
            const total_2000 = bill_2000 * 2000;
            const total_1000 = bill_1000 * 1000;
            const total_500 = bill_500 * 500;

            document.getElementById('total_10000').textContent = formatNumber(total_10000);
            document.getElementById('total_5000').textContent = formatNumber(total_5000);
            document.getElementById('total_2000').textContent = formatNumber(total_2000);
            document.getElementById('total_1000').textContent = formatNumber(total_1000);
            document.getElementById('total_500').textContent = formatNumber(total_500);

            const subtotal_bills = total_10000 + total_5000 + total_2000 + total_1000 + total_500;
            document.getElementById('subtotal_bills').textContent = formatNumber(subtotal_bills);

            // Pièces
            const coin_500 = parseInt(document.getElementById('coin_500').value) || 0;
            const coin_200 = parseInt(document.getElementById('coin_200').value) || 0;
            const coin_100 = parseInt(document.getElementById('coin_100').value) || 0;
            const coin_50 = parseInt(document.getElementById('coin_50').value) || 0;
            const coin_25 = parseInt(document.getElementById('coin_25').value) || 0;
            const coin_10 = parseInt(document.getElementById('coin_10').value) || 0;
            const coin_5 = parseInt(document.getElementById('coin_5').value) || 0;
            const coin_1 = parseInt(document.getElementById('coin_1').value) || 0;

            // Calculer totaux pièces
            const total_coin_500 = coin_500 * 500;
            const total_coin_200 = coin_200 * 200;
            const total_coin_100 = coin_100 * 100;
            const total_coin_50 = coin_50 * 50;
            const total_coin_25 = coin_25 * 25;
            const total_coin_10 = coin_10 * 10;
            const total_coin_5 = coin_5 * 5;
            const total_coin_1 = coin_1 * 1;

            document.getElementById('total_coin_500').textContent = formatNumber(total_coin_500);
            document.getElementById('total_coin_200').textContent = formatNumber(total_coin_200);
            document.getElementById('total_coin_100').textContent = formatNumber(total_coin_100);
            document.getElementById('total_coin_50').textContent = formatNumber(total_coin_50);
            document.getElementById('total_coin_25').textContent = formatNumber(total_coin_25);
            document.getElementById('total_coin_10').textContent = formatNumber(total_coin_10);
            document.getElementById('total_coin_5').textContent = formatNumber(total_coin_5);
            document.getElementById('total_coin_1').textContent = formatNumber(total_coin_1);

            const subtotal_coins = total_coin_500 + total_coin_200 + total_coin_100 + total_coin_50 +
                                   total_coin_25 + total_coin_10 + total_coin_5 + total_coin_1;
            document.getElementById('subtotal_coins').textContent = formatNumber(subtotal_coins);

            // Total général
            const total_billetage = subtotal_bills + subtotal_coins;
            document.getElementById('total_billetage').textContent = formatNumber(total_billetage);

            // Variance
            const variance = total_billetage - soldeComptable;
            const varianceEl = document.getElementById('variance');
            varianceEl.textContent = formatNumber(variance);

            // Changer la couleur selon l'écart
            varianceEl.className = 'text-3xl font-bold font-mono ' +
                (Math.abs(variance) < 0.01 ? 'text-green-400' : 'text-red-400');
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(num);
        }

        function enregistrerBilletage() {
            // Récupérer toutes les valeurs
            const data = {
                date_billetage: new Date().toISOString().split('T')[0],
                compte_caisse: '<?= $compte_caisse ?>',

                // Billets
                bill_10000: parseInt(document.getElementById('bill_10000').value) || 0,
                bill_5000: parseInt(document.getElementById('bill_5000').value) || 0,
                bill_2000: parseInt(document.getElementById('bill_2000').value) || 0,
                bill_1000: parseInt(document.getElementById('bill_1000').value) || 0,
                bill_500: parseInt(document.getElementById('bill_500').value) || 0,

                // Pièces
                coin_500: parseInt(document.getElementById('coin_500').value) || 0,
                coin_250: parseInt(document.getElementById('coin_25').value) || 0,
                coin_200: parseInt(document.getElementById('coin_200').value) || 0,
                coin_100: parseInt(document.getElementById('coin_100').value) || 0,
                coin_50: parseInt(document.getElementById('coin_50').value) || 0,
                coin_25: parseInt(document.getElementById('coin_25').value) || 0,
                coin_10: parseInt(document.getElementById('coin_10').value) || 0,
                coin_5: parseInt(document.getElementById('coin_5').value) || 0,

                // Totaux
                total_billets: parseFloat(document.getElementById('subtotal_bills').textContent.replace(/\s/g, '')) || 0,
                total_pieces: parseFloat(document.getElementById('subtotal_coins').textContent.replace(/\s/g, '')) || 0,
                solde_physique: parseFloat(document.getElementById('total_billetage').textContent.replace(/\s/g, '')) || 0,
                solde_comptable: soldeComptable,
                ecart: parseFloat(document.getElementById('variance').textContent.replace(/\s/g, '')) || 0
            };

            // Vérifier que des données ont été saisies
            if (data.solde_physique === 0) {
                showToast('Veuillez saisir au moins une valeur dans le billetage', 'warning');
                return;
            }

            // Confirmation
            showConfirm(
                'Voulez-vous vraiment enregistrer ce billetage ? Cette action ne peut pas être annulée et un seul billetage par jour est autorisé.',
                () => {
                    // Désactiver le bouton
                    const btn = document.getElementById('btnEnregistrer');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';

                    // Envoyer les données
                    fetch('enregistrer_billetage.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showToast('Billetage enregistré avec succès', 'success');
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            showToast(result.message, 'error');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer le billetage';
                        }
                    })
                    .catch(error => {
                        showToast('Erreur lors de l\'enregistrement', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer le billetage';
                    });
                }
            );
        }

        function showToast(message, type = 'info') {
            const colors = {
                success: 'from-green-600 to-green-700',
                error: 'from-red-600 to-red-700',
                warning: 'from-orange-600 to-orange-700',
                info: 'from-blue-600 to-blue-700'
            };

            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 bg-gradient-to-r ${colors[type]} text-white px-6 py-4 rounded-lg shadow-lg z-50 flex items-center gap-3`;
            toast.innerHTML = `
                <i class="fas ${icons[type]} text-xl"></i>
                <span>${message}</span>
            `;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.remove();
            }, 5000);
        }

        function showConfirm(message, onConfirm) {
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4';
            overlay.innerHTML = `
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl p-6 max-w-md w-full">
                    <div class="text-center mb-6">
                        <i class="fas fa-question-circle text-6xl text-orange-400 mb-4"></i>
                        <h3 class="text-xl font-bold text-white mb-2">Confirmation</h3>
                        <p class="text-slate-300">${message}</p>
                    </div>
                    <div class="flex gap-3">
                        <button onclick="this.closest('.fixed').remove()"
                                class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button id="confirmBtn"
                                class="flex-1 px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-colors">
                            Confirmer
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            document.getElementById('confirmBtn').onclick = () => {
                overlay.remove();
                onConfirm();
            };
        }

        // Initialiser les calculs
        calculateBilletage();
    </script>
</body>
</html>
