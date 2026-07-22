<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Fonction helper pour number_format
function safe_number_format($number, $decimals = 0, $decimal_separator = ' ', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');

// Calculer automatiquement les dates N-1 (période comparable année précédente)
$date_debut_n1 = date('Y-m-d', strtotime($date_debut . ' -1 year'));
$date_fin_n1 = date('Y-m-d', strtotime($date_fin . ' -1 year'));

// Initialiser les tableaux pour le compte de résultat
$charges = [];
$produits = [];
$charges_n1 = [];
$produits_n1 = [];

try {
    // Récupérer tous les comptes de résultat avec leurs soldes
    $sql = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.tableau,
            pc.rd,
            pc.rc,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui' AND pc.tableau = 'Résultat'
        GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.rd, pc.rc
        ORDER BY pc.compte
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$date_debut, $date_fin, $date_debut, $date_fin]);
    $comptes = $stmt->fetchAll();

    // Organiser les comptes par rubrique avec détails
    $details_produits = [];
    $details_charges = [];

    foreach ($comptes as $compte) {
        $solde_debit = $compte['total_debit'];
        $solde_credit = $compte['total_credit'];

        // Calculer le solde net du compte (crédit - débit)
        $solde_net = $solde_credit - $solde_debit;

        // Ne traiter que les comptes avec un solde non nul
        if (abs($solde_net) < 0.01) {
            continue;
        }

        // Si le solde est créditeur (positif), on utilise la rubrique RC (Produit)
        if ($solde_net > 0 && !empty($compte['rc'])) {
            $ref = $compte['rc'];
            if (!isset($produits[$ref])) {
                $produits[$ref] = 0;
                $details_produits[$ref] = [];
            }
            $produits[$ref] += abs($solde_net);

            // Stocker les détails du compte
            $details_produits[$ref][] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'solde' => abs($solde_net)
            ];
        }

        // Si le solde est débiteur (négatif), on utilise la rubrique RD (Charge)
        elseif ($solde_net < 0 && !empty($compte['rd'])) {
            $ref = $compte['rd'];
            if (!isset($charges[$ref])) {
                $charges[$ref] = 0;
                $details_charges[$ref] = [];
            }
            $charges[$ref] += abs($solde_net);

            // Stocker les détails du compte
            $details_charges[$ref][] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'solde' => abs($solde_net)
            ];
        }
    }

    // Calculer les Soldes Intermédiaires de Gestion (SIG) selon SYSCOHADA
    // XA = TA + RA + RB (Marge commerciale)
    $XA = ($produits['TA'] ?? 0) - ($charges['RA'] ?? 0) - ($charges['RB'] ?? 0);

    // XB = TA + TB + TC + TD (Chiffre d'affaires)
    $XB = ($produits['TA'] ?? 0) + ($produits['TB'] ?? 0) + ($produits['TC'] ?? 0) + ($produits['TD'] ?? 0);

    // XC = RA + RB + XB + TE + TF + TG + TH + TI + RC + RD + RE + RF + RG + RH + RI + RJ (Valeur ajoutée)
    $XC = -($charges['RA'] ?? 0) - ($charges['RB'] ?? 0) + $XB + ($produits['TE'] ?? 0) + ($produits['TF'] ?? 0)
          + ($produits['TG'] ?? 0) + ($produits['TH'] ?? 0) + ($produits['TI'] ?? 0)
          - ($charges['RC'] ?? 0) - ($charges['RD'] ?? 0) - ($charges['RE'] ?? 0) - ($charges['RF'] ?? 0)
          - ($charges['RG'] ?? 0) - ($charges['RH'] ?? 0) - ($charges['RI'] ?? 0) - ($charges['RJ'] ?? 0);

    // XD = XC + RK (Excédent Brut d'Exploitation)
    $XD = $XC - ($charges['RK'] ?? 0);

    // XE = XD + TJ + RL (Résultat d'exploitation)
    $XE = $XD + ($produits['TJ'] ?? 0) - ($charges['RL'] ?? 0);

    // XF = TK + TL + TM + RM + RN (Résultat financier)
    $XF = ($produits['TK'] ?? 0) + ($produits['TL'] ?? 0) + ($produits['TM'] ?? 0)
          - ($charges['RM'] ?? 0) - ($charges['RN'] ?? 0);

    // XG = XE + XF (Résultat des Activités Ordinaires)
    $XG = $XE + $XF;

    // XH = TN + TO + RO + RP (Résultat HAO)
    $XH = ($produits['TN'] ?? 0) + ($produits['TO'] ?? 0) - ($charges['RO'] ?? 0) - ($charges['RP'] ?? 0);

    // XI = XG + XH + RQ + RS (Résultat Net)
    $XI = $XG + $XH - ($charges['RQ'] ?? 0) - ($charges['RS'] ?? 0);

    // Ajouter les SIG aux tableaux pour l'affichage
    $soldes = [
        'XA' => $XA,
        'XB' => $XB,
        'XC' => $XC,
        'XD' => $XD,
        'XE' => $XE,
        'XF' => $XF,
        'XG' => $XG,
        'XH' => $XH,
        'XI' => $XI
    ];

    // ============================================================
    // CALCUL PÉRIODE N-1 (Année précédente)
    // ============================================================

    // Récupérer tous les comptes de résultat avec leurs soldes pour N-1
    $stmt_n1 = $db->prepare($sql);
    $stmt_n1->execute([$date_debut_n1, $date_fin_n1, $date_debut_n1, $date_fin_n1]);
    $comptes_n1 = $stmt_n1->fetchAll();

    // Tableaux pour stocker les détails des comptes par rubrique (N-1)
    $details_produits_n1 = [];
    $details_charges_n1 = [];

    // Organiser les comptes par rubrique pour N-1
    foreach ($comptes_n1 as $compte) {
        $solde_debit = $compte['total_debit'];
        $solde_credit = $compte['total_credit'];

        // Calculer le solde net du compte (crédit - débit)
        $solde_net = $solde_credit - $solde_debit;

        // Ignorer les comptes avec un solde nul
        if (abs($solde_net) < 0.01) {
            continue;
        }

        // Si le solde est créditeur (positif), on utilise la rubrique RC (Produit)
        if ($solde_net > 0 && !empty($compte['rc'])) {
            $ref = $compte['rc'];
            if (!isset($produits_n1[$ref])) {
                $produits_n1[$ref] = 0;
                $details_produits_n1[$ref] = [];
            }
            $produits_n1[$ref] += abs($solde_net);

            // Stocker les détails du compte
            $details_produits_n1[$ref][] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'solde' => abs($solde_net)
            ];
        }

        // Si le solde est débiteur (négatif), on utilise la rubrique RD (Charge)
        elseif ($solde_net < 0 && !empty($compte['rd'])) {
            $ref = $compte['rd'];
            if (!isset($charges_n1[$ref])) {
                $charges_n1[$ref] = 0;
                $details_charges_n1[$ref] = [];
            }
            $charges_n1[$ref] += abs($solde_net);

            // Stocker les détails du compte
            $details_charges_n1[$ref][] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'solde' => abs($solde_net)
            ];
        }
    }

    // Calculer les SIG pour N-1
    $XA_n1 = ($produits_n1['TA'] ?? 0) - ($charges_n1['RA'] ?? 0) - ($charges_n1['RB'] ?? 0);
    $XB_n1 = ($produits_n1['TA'] ?? 0) + ($produits_n1['TB'] ?? 0) + ($produits_n1['TC'] ?? 0) + ($produits_n1['TD'] ?? 0);
    $XC_n1 = -($charges_n1['RA'] ?? 0) - ($charges_n1['RB'] ?? 0) + $XB_n1 + ($produits_n1['TE'] ?? 0) + ($produits_n1['TF'] ?? 0)
          + ($produits_n1['TG'] ?? 0) + ($produits_n1['TH'] ?? 0) + ($produits_n1['TI'] ?? 0)
          - ($charges_n1['RC'] ?? 0) - ($charges_n1['RD'] ?? 0) - ($charges_n1['RE'] ?? 0) - ($charges_n1['RF'] ?? 0)
          - ($charges_n1['RG'] ?? 0) - ($charges_n1['RH'] ?? 0) - ($charges_n1['RI'] ?? 0) - ($charges_n1['RJ'] ?? 0);
    $XD_n1 = $XC_n1 - ($charges_n1['RK'] ?? 0);
    $XE_n1 = $XD_n1 + ($produits_n1['TJ'] ?? 0) - ($charges_n1['RL'] ?? 0);
    $XF_n1 = ($produits_n1['TK'] ?? 0) + ($produits_n1['TL'] ?? 0) + ($produits_n1['TM'] ?? 0)
          - ($charges_n1['RM'] ?? 0) - ($charges_n1['RN'] ?? 0);
    $XG_n1 = $XE_n1 + $XF_n1;
    $XH_n1 = ($produits_n1['TN'] ?? 0) + ($produits_n1['TO'] ?? 0) - ($charges_n1['RO'] ?? 0) - ($charges_n1['RP'] ?? 0);
    $XI_n1 = $XG_n1 + $XH_n1 - ($charges_n1['RQ'] ?? 0) - ($charges_n1['RS'] ?? 0);

    $soldes_n1 = [
        'XA' => $XA_n1,
        'XB' => $XB_n1,
        'XC' => $XC_n1,
        'XD' => $XD_n1,
        'XE' => $XE_n1,
        'XF' => $XF_n1,
        'XG' => $XG_n1,
        'XH' => $XH_n1,
        'XI' => $XI_n1
    ];

} catch (Exception $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors du calcul: ' . $e->getMessage()];
}

// Définir les libellés selon SYSCOHADA
$libelles_compte_resultat = [
    // Activité commerciale
    'TA' => ['libelle' => 'Ventes de marchandises', 'type' => 'produit', 'signe' => '+'],
    'RA' => ['libelle' => 'Achats de marchandises', 'type' => 'charge', 'signe' => '-'],
    'RB' => ['libelle' => 'Variation de stocks de marchandises', 'type' => 'variable', 'signe' => '(+/-)'],
    'XA' => ['libelle' => 'MARGE COMMERCIALE (SOMME TA à RB)', 'type' => 'solde', 'signe' => '='],

    // Production
    'TB' => ['libelle' => 'Vente de produits fabriqués', 'type' => 'produit', 'signe' => '+'],
    'TC' => ['libelle' => 'Travaux, services vendus', 'type' => 'produit', 'signe' => '+'],
    'TD' => ['libelle' => 'Produits accessoires', 'type' => 'produit', 'signe' => '+'],
    'XB' => ['libelle' => 'CHIFFRE D\'AFFAIRES (TA + TB + TC + TD)', 'type' => 'solde', 'signe' => '='],

    'TE' => ['libelle' => 'Production stockée (ou déstockage)', 'type' => 'variable', 'signe' => '(+/-)'],
    'TF' => ['libelle' => 'Production immobilisée', 'type' => 'produit', 'signe' => '+'],
    'TG' => ['libelle' => 'Subventions d\'exploitation', 'type' => 'produit', 'signe' => '+'],
    'TH' => ['libelle' => 'Autres produits', 'type' => 'produit', 'signe' => '+'],
    'TI' => ['libelle' => 'Transfert de charges d\'exploitation', 'type' => 'produit', 'signe' => '+'],

    // Consommations
    'RC' => ['libelle' => 'Achats de matières premières et fournitures liées', 'type' => 'charge', 'signe' => '-'],
    'RD' => ['libelle' => 'Variation de stocks de matières premières et fournitures liées', 'type' => 'variable', 'signe' => '(+/-)'],
    'RE' => ['libelle' => 'Autres achats', 'type' => 'charge', 'signe' => '-'],
    'RF' => ['libelle' => 'Variation de stocks d\'autres approvisionnements', 'type' => 'variable', 'signe' => '(+/-)'],
    'RG' => ['libelle' => 'Transports', 'type' => 'charge', 'signe' => '-'],
    'RH' => ['libelle' => 'Services extérieurs', 'type' => 'charge', 'signe' => '-'],
    'RI' => ['libelle' => 'Impôts et taxes', 'type' => 'charge', 'signe' => '-'],
    'RJ' => ['libelle' => 'Autres charges', 'type' => 'charge', 'signe' => '-'],

    'XC' => ['libelle' => 'VALEUR AJOUTEE (XB + RA + RB) + (somme TE à RJ)', 'type' => 'solde', 'signe' => '='],

    'RK' => ['libelle' => 'Charges de personnel', 'type' => 'charge', 'signe' => '-'],
    'XD' => ['libelle' => 'EXCÉDENT BRUT D\'EXPLOITATION (XC + RK)', 'type' => 'solde', 'signe' => '='],

    'TJ' => ['libelle' => 'Reprise d\'amortissements, provisions et dépréciations', 'type' => 'produit', 'signe' => '+'],
    'RL' => ['libelle' => 'Dotations aux amortissements et aux provisions', 'type' => 'charge', 'signe' => '-'],
    'XE' => ['libelle' => 'RÉSULTAT D\'EXPLOITATION (XD+TJ+ RL)', 'type' => 'solde', 'signe' => '='],

    // Activité financière
    'TK' => ['libelle' => 'Revenus financiers et assimilés', 'type' => 'produit', 'signe' => '+'],
    'TL' => ['libelle' => 'Reprises de provisions et dépréciations financières', 'type' => 'produit', 'signe' => '+'],
    'TM' => ['libelle' => 'Transferts de charges financières', 'type' => 'produit', 'signe' => '+'],
    'RM' => ['libelle' => 'Frais financiers et charges assimilées', 'type' => 'charge', 'signe' => '-'],
    'RN' => ['libelle' => 'Dotations aux provisions et dépréciations financières', 'type' => 'charge', 'signe' => '-'],
    'XF' => ['libelle' => 'RESULTAT FINANCIER (somme TK à RN)', 'type' => 'solde', 'signe' => '='],

    'XG' => ['libelle' => 'RESULTAT DES ACTIVITES ORDINAIRES (XE+XF)', 'type' => 'solde', 'signe' => '='],

    // HAO
    'TN' => ['libelle' => 'Produit des cessions d\'immobilisations', 'type' => 'produit', 'signe' => '+'],
    'TO' => ['libelle' => 'Autres produits HAO', 'type' => 'produit', 'signe' => '+'],
    'RO' => ['libelle' => 'Valeurs comptables des cessions d\'immobilisations', 'type' => 'charge', 'signe' => '-'],
    'RP' => ['libelle' => 'Autres Charges HAO', 'type' => 'charge', 'signe' => '-'],
    'XH' => ['libelle' => 'RESULTAT HORS ACTIVITES ORDINAIRES (somme TN à RP)', 'type' => 'solde', 'signe' => '='],

    'RQ' => ['libelle' => 'Participation des travailleurs', 'type' => 'charge', 'signe' => '-'],
    'RS' => ['libelle' => 'Impôts sur le résultat', 'type' => 'charge', 'signe' => '-'],

    'XI' => ['libelle' => 'RESULTAT NET (XG + XH + RQ + RS)', 'type' => 'solde', 'signe' => '=']
];

$pageTitle = "Compte de Résultat OHADA";
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

        /* Colonnes de référence */
        .col-ref {
            font-size: var(--font-size-xs);
            font-family: 'Courier New', monospace;
        }

        /* Colonnes de libellés */
        .col-libelle {
            font-size: var(--font-size-sm);
        }

        /* Colonnes de signes */
        .col-signe {
            font-size: var(--font-size-xs);
            font-family: 'Courier New', monospace;
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

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-emerald-600 mb-2">
                            <i class="fas fa-chart-line mr-3"></i>Compte de Résultat OHADA
                        </h1>
                        <p class="text-slate-400" style="font-size: var(--font-size-base);">État de la performance financière selon le référentiel SYSCOHADA</p>
                    </div>
                    <a href="../rapports/index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
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
                                <i class="fas fa-calendar-alt mr-2"></i>Début d'exercice
                            </label>
                            <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-slate-100">
                        </div>

                        <!-- Date fin -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Date de clôture
                            </label>
                            <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                                   class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-slate-100">
                        </div>

                        <!-- Bouton Afficher -->
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-search"></i>
                            Afficher
                        </button>

                        <!-- Bouton Réinitialiser -->
                        <a href="compte_resultat.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-redo"></i>
                            Réinitialiser
                        </a>

                        <!-- Séparateur visuel -->
                        <div class="border-l border-slate-600 h-10 mx-2"></div>

                        <!-- Bouton PDF -->
                        <button type="button" onclick="exportPDF()" class="px-6 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>

                        <!-- Bouton Excel -->
                        <button type="button" onclick="exportExcel()" class="px-6 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>

                        <!-- Séparateur vertical -->
                        <div class="h-10 w-px bg-slate-600"></div>

                        <!-- Bouton Balance -->
                        <button type="button" onclick="openBalanceModal()" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-list"></i>
                            Balance
                        </button>
                    </div>
                </form>
            </div>

            <!-- En-tête de la période -->
            <div class="bg-gradient-to-r from-green-900/30 to-emerald-900/30 border border-green-800/30 rounded-lg p-6 mb-6">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-white mb-1">
                        COMPTE DE RÉSULTAT
                    </h2>
                    <p class="text-slate-300">
                        Exercice du <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?>
                    </p>
                </div>
            </div>

            <!-- Tableau du compte de résultat -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4">
                    <h3 class="section-title font-bold text-white">COMPTE DE RÉSULTAT</h3>
                </div>

                <div class="overflow-x-auto overflow-y-auto max-h-[700px]">
                    <table class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-slate-700 to-slate-800 sticky top-0">
                            <tr>
                                <th class="px-2 py-2 text-left table-header font-semibold text-slate-300 uppercase">REF</th>
                                <th class="px-2 py-2 text-left table-header font-semibold text-slate-300 uppercase">LIBELLES</th>
                                <th class="px-2 py-2 text-center table-header font-semibold text-slate-300 uppercase">+/-</th>
                                <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">NET (N)</th>
                                <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">NET (N-1)</th>
                                <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">VAR</th>
                                <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">%</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <?php foreach ($libelles_compte_resultat as $ref => $info):
                                $is_solde = $info['type'] === 'solde';
                                $is_uppercase = strtoupper($info['libelle']) === $info['libelle'];

                                // Calculer le montant N
                                $montant_n = 0;
                                if ($info['type'] === 'solde' && isset($soldes[$ref])) {
                                    $montant_n = $soldes[$ref];
                                } elseif ($info['type'] === 'charge' && isset($charges[$ref])) {
                                    $montant_n = $charges[$ref];
                                } elseif ($info['type'] === 'produit' && isset($produits[$ref])) {
                                    $montant_n = $produits[$ref];
                                } elseif ($info['type'] === 'variable') {
                                    if (isset($charges[$ref])) {
                                        $montant_n = -$charges[$ref];
                                    } elseif (isset($produits[$ref])) {
                                        $montant_n = $produits[$ref];
                                    }
                                }

                                // Calculer le montant N-1
                                $montant_n1 = 0;
                                if ($info['type'] === 'solde' && isset($soldes_n1[$ref])) {
                                    $montant_n1 = $soldes_n1[$ref];
                                } elseif ($info['type'] === 'charge' && isset($charges_n1[$ref])) {
                                    $montant_n1 = $charges_n1[$ref];
                                } elseif ($info['type'] === 'produit' && isset($produits_n1[$ref])) {
                                    $montant_n1 = $produits_n1[$ref];
                                } elseif ($info['type'] === 'variable') {
                                    if (isset($charges_n1[$ref])) {
                                        $montant_n1 = -$charges_n1[$ref];
                                    } elseif (isset($produits_n1[$ref])) {
                                        $montant_n1 = $produits_n1[$ref];
                                    }
                                }

                                // Calcul variation et taux
                                $variation = $montant_n - $montant_n1;
                                $taux = ($montant_n1 != 0) ? (($variation / $montant_n1) * 100) : 0;

                                // Déterminer la couleur selon le type et le montant
                                $color_class_n = 'text-slate-500';
                                if ($montant_n != 0) {
                                    if ($is_solde) {
                                        $color_class_n = $montant_n > 0 ? 'text-green-400' : 'text-red-400';
                                    } elseif ($info['type'] === 'charge') {
                                        $color_class_n = 'text-red-400';
                                    } elseif ($info['type'] === 'produit') {
                                        $color_class_n = 'text-green-400';
                                    } elseif ($info['type'] === 'variable') {
                                        $color_class_n = $montant_n > 0 ? 'text-green-400' : 'text-red-400';
                                    }
                                }

                                // Vérifier si cette rubrique a des détails (comptes détaillés)
                                $has_details = false;
                                if ($info['type'] === 'charge' && isset($details_charges[$ref]) && count($details_charges[$ref]) > 0) {
                                    $has_details = true;
                                } elseif ($info['type'] === 'produit' && isset($details_produits[$ref]) && count($details_produits[$ref]) > 0) {
                                    $has_details = true;
                                }
                            ?>
                                <tr class="hover:bg-slate-700/30 transition-colors <?= $is_solde ? 'bg-slate-700/50 font-bold' : '' ?>">
                                    <td class="px-2 py-2 text-slate-300 col-ref"><?= $ref ?></td>
                                    <td class="px-2 py-2 text-slate-300 col-libelle <?= $is_uppercase ? 'font-bold' : '' ?>">
                                        <?php if ($has_details): ?>
                                            <button onclick="toggleDetails('<?= $ref ?>')" class="inline-flex items-center gap-1 hover:text-blue-400 transition-colors">
                                                <i class="fas fa-chevron-right text-xs transition-transform" id="icon-<?= $ref ?>"></i>
                                                <?= htmlspecialchars($info['libelle']) ?>
                                            </button>
                                        <?php else: ?>
                                            <?= htmlspecialchars($info['libelle']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 py-2 text-center text-slate-400 col-signe">
                                        <?= $info['signe'] ?>
                                    </td>
                                    <td class="col-montant <?= $color_class_n ?>">
                                        <?php if ($montant_n != 0): ?>
                                            <?= $montant_n < 0 ? '-' : '' ?><?= safe_number_format(abs($montant_n), 2) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-montant text-slate-400">
                                        <?php if ($montant_n1 != 0): ?>
                                            <?= $montant_n1 < 0 ? '-' : '' ?><?= safe_number_format(abs($montant_n1), 2) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-montant <?= $variation > 0 ? 'text-green-400' : ($variation < 0 ? 'text-red-400' : 'text-slate-500') ?>">
                                        <?php if ($variation != 0): ?>
                                            <?= $variation > 0 ? '+' : '' ?><?= safe_number_format($variation, 2) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-montant <?= $taux > 0 ? 'text-green-400' : ($taux < 0 ? 'text-red-400' : 'text-slate-500') ?>">
                                        <?php if ($montant_n1 != 0 && $variation != 0): ?>
                                            <?= $taux > 0 ? '+' : '' ?><?= number_format($taux, 1) ?>%
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php if ($has_details): ?>
                                <!-- Ligne de détails (initialement cachée) -->
                                <tr id="details-<?= $ref ?>" class="hidden bg-slate-800/50">
                                    <td colspan="7" class="px-0 py-0">
                                        <div class="py-3">
                                            <table class="w-full text-xs">
                                                <colgroup>
                                                    <col style="width: 80px;">
                                                    <col>
                                                    <col class="col-montant">
                                                    <col class="col-montant">
                                                    <col class="col-montant">
                                                    <col class="col-montant">
                                                </colgroup>
                                                <thead>
                                                    <tr class="text-slate-400 border-b border-slate-600">
                                                        <th class="text-left py-1 px-2 font-semibold">COMPTE</th>
                                                        <th class="text-left py-1 px-2 font-semibold">INTITULÉ</th>
                                                        <th class="text-right py-1 px-2 font-semibold col-montant-header">N</th>
                                                        <th class="text-right py-1 px-2 font-semibold col-montant-header">N-1</th>
                                                        <th class="py-1 px-2"></th>
                                                        <th class="py-1 px-2"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    // Obtenir les détails selon le type
                                                    $details = ($info['type'] === 'charge') ? $details_charges[$ref] : $details_produits[$ref];
                                                    $details_n1 = ($info['type'] === 'charge') ? ($details_charges_n1[$ref] ?? []) : ($details_produits_n1[$ref] ?? []);

                                                    // Créer un tableau associatif pour les détails N-1 par compte
                                                    $details_n1_by_compte = [];
                                                    foreach ($details_n1 as $d) {
                                                        $details_n1_by_compte[$d['compte']] = $d['solde'];
                                                    }

                                                    // Afficher tous les comptes (N et N-1 combinés)
                                                    foreach ($details as $detail):
                                                        $solde_n1 = $details_n1_by_compte[$detail['compte']] ?? 0;
                                                    ?>
                                                        <tr class="hover:bg-slate-700/30 border-b border-slate-700/30">
                                                            <td class="py-1 px-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($detail['compte']) ?></td>
                                                            <td class="py-1 px-2 text-slate-300 text-xs"><?= htmlspecialchars($detail['intitule']) ?></td>
                                                            <td class="col-montant text-slate-200">
                                                                <?= safe_number_format($detail['solde'], 2) ?>
                                                            </td>
                                                            <td class="col-montant text-slate-400">
                                                                <?= $solde_n1 > 0 ? safe_number_format($solde_n1, 2) : '' ?>
                                                            </td>
                                                            <td class="col-montant"></td>
                                                            <td class="col-montant"></td>
                                                        </tr>
                                                    <?php endforeach;

                                                    // Afficher les comptes qui existent seulement en N-1
                                                    foreach ($details_n1 as $detail_n1):
                                                        // Skip si déjà affiché dans la boucle précédente
                                                        $already_shown = false;
                                                        foreach ($details as $d) {
                                                            if ($d['compte'] === $detail_n1['compte']) {
                                                                $already_shown = true;
                                                                break;
                                                            }
                                                        }
                                                        if ($already_shown) continue;
                                                    ?>
                                                        <tr class="hover:bg-slate-700/30 border-b border-slate-700/30">
                                                            <td class="py-1 px-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($detail_n1['compte']) ?></td>
                                                            <td class="py-1 px-2 text-slate-300 text-xs"><?= htmlspecialchars($detail_n1['intitule']) ?></td>
                                                            <td class="col-montant text-slate-400"></td>
                                                            <td class="col-montant text-slate-400">
                                                                <?= safe_number_format($detail_n1['solde'], 2) ?>
                                                            </td>
                                                            <td class="col-montant"></td>
                                                            <td class="col-montant"></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
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
                date_fin: '<?= $date_fin ?>'
            });
            window.location.href = 'export_compte_resultat_pdf.php?' + params.toString();
        }

        function exportExcel() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>'
            });
            window.location.href = 'export_compte_resultat_excel.php?' + params.toString();
        }

        function toggleDetails(ref) {
            const detailsRow = document.getElementById('details-' + ref);
            const icon = document.getElementById('icon-' + ref);

            if (detailsRow.classList.contains('hidden')) {
                // Afficher les détails
                detailsRow.classList.remove('hidden');
                icon.classList.add('rotate-90');
            } else {
                // Cacher les détails
                detailsRow.classList.add('hidden');
                icon.classList.remove('rotate-90');
            }
        }
    </script>
    <?php include '../../includes/balance_popup.php'; ?>
</body>
</html>
