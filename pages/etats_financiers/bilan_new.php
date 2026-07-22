<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 0, $decimal_separator = ' ', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');

// Calculer automatiquement les dates N-1 (soldes de clôture de l'exercice précédent)
$date_fin_n1 = date('Y-m-d', strtotime($date_fin . ' -1 year'));

// Initialiser les tableaux pour stocker les données du bilan
$actif = [];
$passif = [];
$actif_n1 = [];
$passif_n1 = [];
$details_actif = [];
$details_passif = [];
$details_actif_n1 = [];
$details_passif_n1 = [];

// Définir les rubriques d'actif et de passif
$rubriques_actif = ['AF', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AS', 'AZ', 'BA', 'BB', 'BG', 'BH', 'BI', 'BJ', 'BK', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BZ'];
$rubriques_passif = ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM', 'CP', 'DA', 'DB', 'DC', 'DD', 'DF', 'DH', 'DI', 'DJ', 'DK', 'DM', 'DN', 'DP', 'DQ', 'DR', 'DT', 'DV', 'DZ'];

try {
    /**
     * NOUVELLE LOGIQUE SIMPLIFIÉE BASÉE SUR LA BALANCE
     * ===============================================
     * 1. On récupère uniquement les comptes de BILAN (classes 1-5)
     * 2. Solde débiteur (Débit > Crédit) → utilise BD (ACTIF)
     * 3. Solde créditeur (Crédit > Débit) → utilise BC (PASSIF)
     * 4. AMORT : comptes 28, 29, 39, 49, 59
     * 5. BRUT : tous les autres comptes
     * 6. Le résultat (CJ) sera calculé séparément depuis le compte de résultat
     */

    // =================================================================
    // PÉRIODE N : Balance de date_debut à date_fin
    // =================================================================

    $sql_balance_n = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.bd,
            pc.bc,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
            AND LEFT(pc.compte, 1) IN ('1', '2', '3', '4', '5')
        GROUP BY pc.compte, pc.intitule_compte, pc.bd, pc.bc
        HAVING ABS(total_debit - total_credit) > 0.01
        ORDER BY pc.compte
    ";

    $stmt = $db->prepare($sql_balance_n);
    $stmt->execute([$date_debut, $date_fin, $date_debut, $date_fin]);
    $comptes_n = $stmt->fetchAll();

    // Traiter les comptes de la période N
    foreach ($comptes_n as $compte) {
        $solde = $compte['total_debit'] - $compte['total_credit'];
        $prefix2 = substr($compte['compte'], 0, 2);

        // Vérifier si c'est un compte d'amortissement/provision
        $is_amort = in_array($prefix2, ['28', '29', '39', '49', '59']);

        if ($solde > 0) {
            // Solde DÉBITEUR → ACTIF (utilise BD)
            $ref = $compte['bd'];
            if (empty($ref)) continue;

            if (!isset($actif[$ref])) {
                $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $details_actif[$ref] = [];
            }

            if ($is_amort) {
                // Les amortissements vont en colonne AMORT et diminuent le NET
                $actif[$ref]['amort_deprec'] += abs($solde);
                $actif[$ref]['net'] -= abs($solde);

                $details_actif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => 0,
                    'amort_deprec' => abs($solde),
                    'net' => -abs($solde)
                ];
            } else {
                // Les autres comptes vont en BRUT et augmentent le NET
                $actif[$ref]['brut'] += $solde;
                $actif[$ref]['net'] += $solde;

                $details_actif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => $solde,
                    'amort_deprec' => 0,
                    'net' => $solde
                ];
            }

        } else {
            // Solde CRÉDITEUR → PASSIF (utilise BC)
            $ref = $compte['bc'];
            if (empty($ref)) continue;

            if (!isset($passif[$ref])) {
                $passif[$ref] = ['net' => 0];
                $details_passif[$ref] = [];
            }

            $passif[$ref]['net'] += abs($solde);

            $details_passif[$ref][] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'net' => abs($solde)
            ];
        }
    }

    // =================================================================
    // PÉRIODE N-1 : Balance jusqu'à date_fin_n1 (soldes de clôture)
    // =================================================================

    $sql_balance_n1 = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.bd,
            pc.bc,
            COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
            AND LEFT(pc.compte, 1) IN ('1', '2', '3', '4', '5')
        GROUP BY pc.compte, pc.intitule_compte, pc.bd, pc.bc
        HAVING ABS(total_debit - total_credit) > 0.01
        ORDER BY pc.compte
    ";

    $stmt = $db->prepare($sql_balance_n1);
    $stmt->execute([$date_fin_n1, $date_fin_n1]);
    $comptes_n1 = $stmt->fetchAll();

    // Traiter les comptes de la période N-1
    foreach ($comptes_n1 as $compte) {
        $solde = $compte['total_debit'] - $compte['total_credit'];
        $prefix2 = substr($compte['compte'], 0, 2);

        $is_amort = in_array($prefix2, ['28', '29', '39', '49', '59']);

        if ($solde > 0) {
            // Solde DÉBITEUR → ACTIF
            $ref = $compte['bd'];
            if (empty($ref)) continue;

            if (!isset($actif_n1[$ref])) {
                $actif_n1[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $details_actif_n1[$ref] = [];
            }

            if ($is_amort) {
                $actif_n1[$ref]['amort_deprec'] += abs($solde);
                $actif_n1[$ref]['net'] -= abs($solde);

                $details_actif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => 0,
                    'amort_deprec' => abs($solde),
                    'net' => -abs($solde)
                ];
            } else {
                $actif_n1[$ref]['brut'] += $solde;
                $actif_n1[$ref]['net'] += $solde;

                $details_actif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => $solde,
                    'amort_deprec' => 0,
                    'net' => $solde
                ];
            }

        } else {
            // Solde CRÉDITEUR → PASSIF
            $ref = $compte['bc'];
            if (empty($ref)) continue;

            if (!isset($passif_n1[$ref])) {
                $passif_n1[$ref] = ['net' => 0];
                $details_passif_n1[$ref] = [];
            }

            $passif_n1[$ref]['net'] += abs($solde);

            $details_passif_n1[$ref][] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'net' => abs($solde)
            ];
        }
    }

    // =================================================================
    // CALCUL DU RÉSULTAT NET (CJ) depuis le Compte de Résultat
    // =================================================================

    // Résultat N (classes 6, 7, 8 entre date_debut et date_fin)
    $sql_resultat_n = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_net
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 1) IN ('6', '7', '8')
        AND e.date_ecriture BETWEEN ? AND ?
        AND e.statut = 'Validé'
    ";
    $stmt = $db->prepare($sql_resultat_n);
    $stmt->execute([$date_debut, $date_fin]);
    $passif['CJ']['net'] = $stmt->fetchColumn();

    // Résultat N-1 : on prend le solde du compte 131 ou 139 au date_fin_n1
    // (le résultat a été affecté lors de la clôture de N-1)
    $sql_resultat_n1 = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_net
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 3) IN ('131', '139')
        AND e.date_ecriture <= ?
        AND e.statut = 'Validé'
    ";
    $stmt = $db->prepare($sql_resultat_n1);
    $stmt->execute([$date_fin_n1]);
    $passif_n1['CJ']['net'] = $stmt->fetchColumn();

    // =================================================================
    // CALCULS DES TOTAUX
    // =================================================================

    // Report à nouveau N (compte 12)
    $sql_report_n = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as report
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '12'
        AND e.date_ecriture < ?
        AND e.statut = 'Validé'
    ";
    $stmt = $db->prepare($sql_report_n);
    $stmt->execute([$date_debut]);
    $passif['CF']['net'] = $stmt->fetchColumn();

    // Report à nouveau N-1
    $date_debut_n1 = date('Y-m-d', strtotime($date_debut . ' -1 year'));
    $stmt = $db->prepare($sql_report_n);
    $stmt->execute([$date_debut_n1]);
    $passif_n1['CF']['net'] = $stmt->fetchColumn();

    // ACTIF - Totaux partiels
    $actif['AZ']['net'] = ($actif['AF']['net'] ?? 0) + ($actif['AJ']['net'] ?? 0) + ($actif['AK']['net'] ?? 0) +
                          ($actif['AL']['net'] ?? 0) + ($actif['AM']['net'] ?? 0) + ($actif['AN']['net'] ?? 0) + ($actif['AS']['net'] ?? 0);

    $actif['BK']['net'] = ($actif['BA']['net'] ?? 0) + ($actif['BB']['net'] ?? 0) + ($actif['BG']['net'] ?? 0) +
                          ($actif['BH']['net'] ?? 0) + ($actif['BI']['net'] ?? 0) + ($actif['BJ']['net'] ?? 0);

    $actif['BT']['net'] = ($actif['BQ']['net'] ?? 0) + ($actif['BR']['net'] ?? 0) + ($actif['BS']['net'] ?? 0);

    // Total Actif (BZ)
    $actif['BZ']['net'] = ($actif['AZ']['net'] ?? 0) + ($actif['BK']['net'] ?? 0) +
                          ($actif['BT']['net'] ?? 0) + ($actif['BU']['net'] ?? 0);

    // PASSIF - Capitaux Propres (CP)
    $passif['CP']['net'] = ($passif['CA']['net'] ?? 0) + ($passif['CB']['net'] ?? 0) + ($passif['CD']['net'] ?? 0) +
                           ($passif['CE']['net'] ?? 0) + ($passif['CF']['net'] ?? 0) + ($passif['CG']['net'] ?? 0) +
                           ($passif['CH']['net'] ?? 0) + ($passif['CJ']['net'] ?? 0) + ($passif['CL']['net'] ?? 0) +
                           ($passif['CM']['net'] ?? 0);

    // PASSIF - Dettes financières (DD)
    $passif['DD']['net'] = ($passif['DA']['net'] ?? 0) + ($passif['DB']['net'] ?? 0) + ($passif['DC']['net'] ?? 0);

    // PASSIF - Total ressources stables (DF)
    $passif['DF']['net'] = ($passif['CP']['net'] ?? 0) + ($passif['DD']['net'] ?? 0);

    // PASSIF - Dettes HAO et assimilées (DP)
    $passif['DP']['net'] = ($passif['DH']['net'] ?? 0) + ($passif['DI']['net'] ?? 0) + ($passif['DJ']['net'] ?? 0) +
                           ($passif['DK']['net'] ?? 0) + ($passif['DM']['net'] ?? 0) + ($passif['DN']['net'] ?? 0);

    // PASSIF - Trésorerie Passif (DT)
    $passif['DT']['net'] = ($passif['DQ']['net'] ?? 0) + ($passif['DR']['net'] ?? 0);

    // Total Passif (DZ)
    $passif['DZ']['net'] = ($passif['DF']['net'] ?? 0) + ($passif['DP']['net'] ?? 0) +
                           ($passif['DT']['net'] ?? 0) + ($passif['DV']['net'] ?? 0);

    // ===== MÊME CHOSE POUR N-1 =====

    $actif_n1['AZ']['net'] = ($actif_n1['AF']['net'] ?? 0) + ($actif_n1['AJ']['net'] ?? 0) + ($actif_n1['AK']['net'] ?? 0) +
                              ($actif_n1['AL']['net'] ?? 0) + ($actif_n1['AM']['net'] ?? 0) + ($actif_n1['AN']['net'] ?? 0) + ($actif_n1['AS']['net'] ?? 0);

    $actif_n1['BK']['net'] = ($actif_n1['BA']['net'] ?? 0) + ($actif_n1['BB']['net'] ?? 0) + ($actif_n1['BG']['net'] ?? 0) +
                              ($actif_n1['BH']['net'] ?? 0) + ($actif_n1['BI']['net'] ?? 0) + ($actif_n1['BJ']['net'] ?? 0);

    $actif_n1['BT']['net'] = ($actif_n1['BQ']['net'] ?? 0) + ($actif_n1['BR']['net'] ?? 0) + ($actif_n1['BS']['net'] ?? 0);

    $actif_n1['BZ']['net'] = ($actif_n1['AZ']['net'] ?? 0) + ($actif_n1['BK']['net'] ?? 0) +
                              ($actif_n1['BT']['net'] ?? 0) + ($actif_n1['BU']['net'] ?? 0);

    $passif_n1['CP']['net'] = ($passif_n1['CA']['net'] ?? 0) + ($passif_n1['CB']['net'] ?? 0) + ($passif_n1['CD']['net'] ?? 0) +
                               ($passif_n1['CE']['net'] ?? 0) + ($passif_n1['CF']['net'] ?? 0) + ($passif_n1['CG']['net'] ?? 0) +
                               ($passif_n1['CH']['net'] ?? 0) + ($passif_n1['CJ']['net'] ?? 0) + ($passif_n1['CL']['net'] ?? 0) +
                               ($passif_n1['CM']['net'] ?? 0);

    $passif_n1['DD']['net'] = ($passif_n1['DA']['net'] ?? 0) + ($passif_n1['DB']['net'] ?? 0) + ($passif_n1['DC']['net'] ?? 0);
    $passif_n1['DF']['net'] = ($passif_n1['CP']['net'] ?? 0) + ($passif_n1['DD']['net'] ?? 0);
    $passif_n1['DP']['net'] = ($passif_n1['DH']['net'] ?? 0) + ($passif_n1['DI']['net'] ?? 0) + ($passif_n1['DJ']['net'] ?? 0) +
                               ($passif_n1['DK']['net'] ?? 0) + ($passif_n1['DM']['net'] ?? 0) + ($passif_n1['DN']['net'] ?? 0);
    $passif_n1['DT']['net'] = ($passif_n1['DQ']['net'] ?? 0) + ($passif_n1['DR']['net'] ?? 0);
    $passif_n1['DZ']['net'] = ($passif_n1['DF']['net'] ?? 0) + ($passif_n1['DP']['net'] ?? 0) +
                               ($passif_n1['DT']['net'] ?? 0) + ($passif_n1['DV']['net'] ?? 0);

} catch (Exception $e) {
    die("Erreur lors du calcul du bilan : " . $e->getMessage());
}

$pageTitle = "Bilan (SYSCOHADA)";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .col-montant { width: 140px; }
        @media print {
            .no-print { display: none; }
            body { background: white; }
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <header class="bg-slate-800/30 border-b border-slate-700/50 p-4 no-print">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-xl font-semibold text-white">
                            <i class="fas fa-balance-scale mr-2"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-sm text-slate-400 mt-0.5">État de synthèse - Situation patrimoniale</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openBalanceModal()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition">
                            <i class="fas fa-list mr-2"></i>Voir la Balance
                        </button>
                        <button onclick="window.print()" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm rounded-lg transition">
                            <i class="fas fa-print mr-2"></i>Imprimer
                        </button>
                    </div>
                </div>

                <!-- Filtres de date -->
                <form method="GET" class="flex gap-3 items-end">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Date de début (N)</label>
                        <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                               class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Date de fin (N)</label>
                        <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                               class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition">
                        <i class="fas fa-sync-alt mr-2"></i>Actualiser
                    </button>
                </form>
            </header>

            <!-- Content -->
            <div class="p-4">
                <div class="text-sm text-slate-400 mb-4">
                    Période N : du <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?> |
                    Période N-1 : soldes au <?= date('d/m/Y', strtotime($date_fin_n1)) ?>
                </div>

                <!-- Info : Nouvelle logique basée sur la balance -->
                <div class="mb-4 p-3 bg-blue-900/20 border border-blue-700/30 rounded-lg text-sm text-blue-300 no-print">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Nouvelle logique basée sur la balance générale:</strong> Le bilan est désormais calculé directement à partir de la balance (classes 1-5).
                    Le résultat net (CJ) provient du compte de résultat.
                </div>

                <!-- Vérification de l'équilibre -->
                <?php
                $difference_n = ($actif['BZ']['net'] ?? 0) - ($passif['DZ']['net'] ?? 0);
                $difference_n1 = ($actif_n1['BZ']['net'] ?? 0) - ($passif_n1['DZ']['net'] ?? 0);
                ?>

                <?php if (abs($difference_n) > 1): ?>
                    <div class="mb-4 p-3 bg-red-900/20 border border-red-700/30 rounded-lg text-sm text-red-300">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Attention:</strong> Le bilan N n'est pas équilibré. Différence de <?= safe_number_format($difference_n, 2, ',', ' ') ?> FCFA
                    </div>
                <?php endif; ?>

                <?php if (abs($difference_n1) > 1): ?>
                    <div class="mb-4 p-3 bg-red-900/20 border border-red-700/30 rounded-lg text-sm text-red-300">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Attention:</strong> Le bilan N-1 n'est pas équilibré. Différence de <?= safe_number_format($difference_n1, 2, ',', ' ') ?> FCFA
                    </div>
                <?php endif; ?>

                <!-- Message de succès si équilibré -->
                <?php if (abs($difference_n) <= 1 && abs($difference_n1) <= 1): ?>
                    <div class="mb-4 p-3 bg-green-900/20 border border-green-700/30 rounded-lg text-sm text-green-300">
                        <i class="fas fa-check-circle mr-2"></i>
                        <strong>Bilan équilibré</strong> pour les périodes N et N-1
                    </div>
                <?php endif; ?>

                <!-- TODO: Ajouter les tableaux du bilan ici -->
                <div class="text-center text-slate-400 py-8">
                    Tableau du bilan en cours de développement...
                </div>
            </div>
        </main>
    </div>

    <script>
        function openBalanceModal() {
            alert('Fonction de la balance en popup à implémenter');
        }
    </script>
</body>
</html>
