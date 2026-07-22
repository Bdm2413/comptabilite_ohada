<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Date de référence pour le calcul de l'ancienneté
$date_reference = $_GET['date_reference'] ?? date('Y-m-d');
$afficher_lettrees = isset($_GET['afficher_lettrees']) ? true : false;
$type_fournisseur = $_GET['type_fournisseur'] ?? 'tous'; // tous, externe, interne

// Filtre par sous-type de fournisseur (Externe / Interne)
$typeFilter = "";
if ($type_fournisseur === 'externe') {
    $typeFilter = "AND t.sous_type = 'Externe'";
} elseif ($type_fournisseur === 'interne') {
    $typeFilter = "AND t.sous_type = 'Interne'";
}

// Récupérer les lignes d'écritures avec crédit (factures fournisseurs)
// Pour fournisseurs: on utilise date_ligne (date de la facture fournisseur)
// IMPORTANT : Exclure les factures DOIT qui ont un AVOIR associé
$sql_factures = "
    SELECT
        le.id as id_ligne,
        e.id as id_ecriture,
        le.numero_facture,
        le.compte_tiers,
        le.compte as compte_general,
        le.libelle,
        le.credit as montant,
        le.date_ligne,
        e.numero_ecriture,
        e.date_ecriture,
        e.reference_piece,
        e.type_facture,
        e.facture_initiale,
        e.lettrage,
        e.statut_lettrage,
        t.nom as nom_fournisseur,
        t.compte_tiers,
        t.compte_gle as compte_collectif,
        CASE
            WHEN t.compte_gle = '4011000' THEN 'Externe'
            WHEN t.compte_gle = '4012000' THEN 'Interne'
            ELSE 'Autre'
        END as type_tiers,
        COALESCE(le.date_ligne, e.date_ecriture) as date_facture,
        DATEDIFF(?, COALESCE(le.date_ligne, e.date_ecriture)) as jours_echus
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    INNER JOIN plan_tiers t ON le.compte_tiers = t.compte_tiers AND t.societe_id = e.societe_id
    INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id AND pc.type = 'Fournisseur'
    WHERE t.type = 'Fournisseur'
    AND le.credit > 0
    AND e.statut = 'Validé'
    AND e.societe_id = ?
    AND e.journal != 'AN'
    AND e.numero_ecriture NOT LIKE 'AN-%'
    AND e.numero_ecriture NOT LIKE '%À-NOUVEAU%'
    AND COALESCE(le.date_ligne, e.date_ecriture) <= ?
    -- Exclure UNIQUEMENT les factures DOIT qui ont un AVOIR associé
    -- Condition : reference_piece doit être non-NULL et correspondre à facture_initiale d'un AVOIR
    AND NOT (
        e.reference_piece IS NOT NULL
        AND EXISTS (
            SELECT 1 FROM ecritures e_avoir
            WHERE e_avoir.type_facture = 'AVOIR'
            AND e_avoir.facture_initiale = e.reference_piece
            AND e_avoir.statut = 'Validé'
            AND e_avoir.societe_id = ?
        )
    )
    $typeFilter
    ORDER BY t.nom, e.date_ecriture
";

$stmt = $db->prepare($sql_factures);
$stmt->execute([$date_reference, $societe_id, $date_reference, $societe_id]);
$factures = $stmt->fetchAll();

// Récupérer les règlements (débits) - uniquement ceux avant ou à la date de référence
// ✅ IMPORTANT : Exclure les lignes lettrées pour éviter de compter les extournes
$sql_reglements = "
    SELECT
        le.numero_facture,
        le.compte_tiers,
        SUM(le.debit) as total_regle
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    INNER JOIN plan_tiers t ON le.compte_tiers = t.compte_tiers AND t.societe_id = e.societe_id
    INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id AND pc.type = 'Fournisseur'
    WHERE t.type = 'Fournisseur'
    AND le.debit > 0
    AND e.statut = 'Validé'
    AND e.societe_id = ?
    AND e.journal != 'AN'
    AND e.numero_ecriture NOT LIKE 'AN-%'
    AND e.numero_ecriture NOT LIKE '%À-NOUVEAU%'
    AND COALESCE(le.date_ligne, e.date_ecriture) <= ?
    AND (e.statut_lettrage != 'Lettré' OR e.statut_lettrage IS NULL)
    $typeFilter
    GROUP BY le.numero_facture, le.compte_tiers
";

$stmt_regl = $db->prepare($sql_reglements);
$stmt_regl->execute([$societe_id, $date_reference]);
$reglements_data = $stmt_regl->fetchAll();

// Indexer les règlements par numéro de facture + compte tiers (pour gérer les doublons de numéros)
$reglements = [];
foreach ($reglements_data as $reg) {
    $cle = $reg['numero_facture'] . '_' . $reg['compte_tiers'];
    $reglements[$cle] = $reg;
}

// Définir les tranches d'ancienneté (8 tranches)
$tranches = [
    '0_30' => ['label' => '0-30 j', 'min' => 0, 'max' => 30, 'color' => 'green'],
    '31_60' => ['label' => '31-60 j', 'min' => 31, 'max' => 60, 'color' => 'blue'],
    '61_90' => ['label' => '61-90 j', 'min' => 61, 'max' => 90, 'color' => 'cyan'],
    '91_120' => ['label' => '91-120 j', 'min' => 91, 'max' => 120, 'color' => 'yellow'],
    '121_150' => ['label' => '121-150 j', 'min' => 121, 'max' => 150, 'color' => 'orange'],
    '151_180' => ['label' => '151-180 j', 'min' => 151, 'max' => 180, 'color' => 'red'],
    '181_365' => ['label' => '181-365 j', 'min' => 181, 'max' => 365, 'color' => 'purple'],
    'plus_365' => ['label' => '> 365 j', 'min' => 366, 'max' => null, 'color' => 'pink']
];

// Initialiser les structures de données
$balance_par_fournisseur = [];
$totaux_generaux = array_fill_keys(array_keys($tranches), 0);
$totaux_generaux['total'] = 0;

$totaux_externes = array_fill_keys(array_keys($tranches), 0);
$totaux_externes['total'] = 0;

$totaux_internes = array_fill_keys(array_keys($tranches), 0);
$totaux_internes['total'] = 0;

// Traiter les factures
foreach ($factures as $facture) {
    $fournisseur_key = $facture['nom_fournisseur'] ?? 'Fournisseur inconnu';
    $type_tiers = $facture['type_tiers'];

    // ✅ NOUVEAU : Vérifier le statut de lettrage (priorité sur le calcul manuel)
    $est_lettree = !empty($facture['statut_lettrage']) && $facture['statut_lettrage'] === 'Lettré';

    // Si la facture est lettrée et qu'on ne veut pas voir les lettrées → ignorer
    if ($est_lettree && !$afficher_lettrees) {
        continue; // Passer à la facture suivante
    }

    // Calculer le solde restant (pour affichage dans le détail)
    // Note: Ce calcul reste pour information, mais le lettrage a la priorité
    $montant_facture = $facture['montant'];
    $cle_reglement = $facture['numero_facture'] . '_' . $facture['compte_tiers'];
    $total_regle = isset($reglements[$cle_reglement]) ? $reglements[$cle_reglement]['total_regle'] : 0;
    $solde_restant = $est_lettree ? 0 : ($montant_facture - $total_regle);

    // Toujours afficher si on arrive ici (soit non lettrée, soit on veut voir les lettrées)
    if (true) {
        if (!isset($balance_par_fournisseur[$fournisseur_key])) {
            $balance_par_fournisseur[$fournisseur_key] = [
                'nom' => $fournisseur_key,
                'code' => $facture['compte_tiers'] ?? '',
                'compte' => $facture['compte_general'],
                'type' => $type_tiers,
                'factures' => []
            ];

            // Initialiser toutes les tranches à 0
            foreach ($tranches as $key => $tranche) {
                $balance_par_fournisseur[$fournisseur_key][$key] = 0;
            }
            $balance_par_fournisseur[$fournisseur_key]['total'] = 0;
        }

        $jours = $facture['jours_echus'];

        // Déterminer la tranche
        $tranche = '';
        if ($jours <= 30) {
            $tranche = '0_30';
        } elseif ($jours <= 60) {
            $tranche = '31_60';
        } elseif ($jours <= 90) {
            $tranche = '61_90';
        } elseif ($jours <= 120) {
            $tranche = '91_120';
        } elseif ($jours <= 150) {
            $tranche = '121_150';
        } elseif ($jours <= 180) {
            $tranche = '151_180';
        } elseif ($jours <= 365) {
            $tranche = '181_365';
        } else {
            $tranche = 'plus_365';
        }

        $balance_par_fournisseur[$fournisseur_key][$tranche] += $solde_restant;
        $balance_par_fournisseur[$fournisseur_key]['total'] += $solde_restant;
        $balance_par_fournisseur[$fournisseur_key]['factures'][] = array_merge($facture, [
            'montant_facture' => $montant_facture,
            'montant_regle' => $total_regle,
            'solde_restant' => $solde_restant
        ]);

        // Ajouter aux totaux généraux
        $totaux_generaux[$tranche] += $solde_restant;
        $totaux_generaux['total'] += $solde_restant;

        // Ajouter aux totaux par type
        if ($type_tiers === 'Externe') {
            $totaux_externes[$tranche] += $solde_restant;
            $totaux_externes['total'] += $solde_restant;
        } elseif ($type_tiers === 'Interne') {
            $totaux_internes[$tranche] += $solde_restant;
            $totaux_internes['total'] += $solde_restant;
        }
    }
}

// Statistiques globales
$stats = [
    'nb_fournisseurs' => count($balance_par_fournisseur),
    'nb_fournisseurs_externes' => count(array_filter($balance_par_fournisseur, fn($f) => $f['type'] === 'Externe')),
    'nb_fournisseurs_internes' => count(array_filter($balance_par_fournisseur, fn($f) => $f['type'] === 'Interne')),
    'nb_factures' => count($factures),
    'total_dettes' => $totaux_generaux['total'],
    'total_externes' => $totaux_externes['total'],
    'total_internes' => $totaux_internes['total'],
    'plus_ancien' => 0
];

if (!empty($factures)) {
    $stats['plus_ancien'] = max(array_column($factures, 'jours_echus'));
}

$pageTitle = "Balance Âgée Fournisseurs";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <header class="bg-slate-800/30 border-b border-slate-700/50 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-emerald-600 mb-2">
                            <i class="fas fa-building mr-3"></i>Balance Âgée Fournisseurs
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">Analyse des dettes fournisseurs par ancienneté (basée sur les lignes d'écritures)</p>
                    </div>
                    <div>
                        <a href="index.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-all inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>
                            Retour
                        </a>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-6">
                <!-- Paramètres -->
                <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl shadow-2xl border border-slate-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-sliders-h text-blue-400 mr-2"></i>Paramètres
                    </h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar text-blue-400 mr-1"></i>Date de référence
                            </label>
                            <input type="date"
                                   name="date_reference"
                                   value="<?= htmlspecialchars($date_reference) ?>"
                                   class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-filter text-purple-400 mr-1"></i>Type de fournisseur
                            </label>
                            <select name="type_fournisseur"
                                    class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500">
                                <option value="tous" <?= $type_fournisseur === 'tous' ? 'selected' : '' ?>>Tous</option>
                                <option value="externe" <?= $type_fournisseur === 'externe' ? 'selected' : '' ?>>Externes uniquement</option>
                                <option value="interne" <?= $type_fournisseur === 'interne' ? 'selected' : '' ?>>Internes uniquement</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       name="afficher_lettrees"
                                       <?= $afficher_lettrees ? 'checked' : '' ?>
                                       class="w-4 h-4 text-blue-600 bg-slate-700 border-slate-600 rounded focus:ring-blue-500">
                                <span class="text-sm text-slate-300">Inclure les réglées</span>
                            </label>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-filter"></i>
                                Appliquer les filtres
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Statistiques principales -->
                <div class="grid grid-cols-1 md:grid-cols-6 gap-4 mb-6">
                    <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl p-6 border border-slate-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-slate-400 text-sm mb-1">Fournisseurs</p>
                                <p class="text-2xl font-bold text-white"><?= $stats['nb_fournisseurs'] ?></p>
                            </div>
                            <i class="fas fa-truck text-3xl text-blue-400"></i>
                        </div>
                    </div>

                    <div class="bg-green-500/10 backdrop-blur-sm rounded-xl p-6 border border-green-500/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-green-300 text-sm mb-1">Externes</p>
                                <p class="text-2xl font-bold text-green-400"><?= $stats['nb_fournisseurs_externes'] ?></p>
                            </div>
                            <i class="fas fa-industry text-3xl text-green-400"></i>
                        </div>
                    </div>

                    <div class="bg-purple-500/10 backdrop-blur-sm rounded-xl p-6 border border-purple-500/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-purple-300 text-sm mb-1">Internes</p>
                                <p class="text-2xl font-bold text-purple-400"><?= $stats['nb_fournisseurs_internes'] ?></p>
                            </div>
                            <i class="fas fa-handshake text-3xl text-purple-400"></i>
                        </div>
                    </div>

                    <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl p-6 border border-slate-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-slate-400 text-sm mb-1">Factures</p>
                                <p class="text-2xl font-bold text-white"><?= $stats['nb_factures'] ?></p>
                            </div>
                            <i class="fas fa-file-invoice text-3xl text-slate-400"></i>
                        </div>
                    </div>

                    <div class="bg-red-500/10 backdrop-blur-sm rounded-xl p-6 border border-red-500/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-red-300 text-sm mb-1">Total dettes</p>
                                <p class="text-xl font-bold text-red-400"><?= number_format($stats['total_dettes'], 2, ',', ' ') ?></p>
                            </div>
                            <i class="fas fa-money-bill-wave text-3xl text-red-400"></i>
                        </div>
                    </div>

                    <div class="bg-yellow-500/10 backdrop-blur-sm rounded-xl p-6 border border-yellow-500/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-yellow-300 text-sm mb-1">Plus ancien</p>
                                <p class="text-2xl font-bold text-yellow-400"><?= $stats['plus_ancien'] ?> j</p>
                            </div>
                            <i class="fas fa-clock text-3xl text-yellow-400"></i>
                        </div>
                    </div>
                </div>

                <!-- Statistiques par type -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-green-500/10 backdrop-blur-sm rounded-xl p-6 border border-green-500/30">
                        <h4 class="font-semibold text-green-300 mb-3 flex items-center">
                            <i class="fas fa-industry mr-2"></i>Fournisseurs Externes (4011000)
                        </h4>
                        <div class="text-3xl font-bold text-green-400 mb-2">
                            <?= number_format($totaux_externes['total'], 2, ',', ' ') ?> FCFA
                        </div>
                        <p class="text-sm text-green-300"><?= $stats['nb_fournisseurs_externes'] ?> fournisseur(s)</p>
                    </div>

                    <div class="bg-purple-500/10 backdrop-blur-sm rounded-xl p-6 border border-purple-500/30">
                        <h4 class="font-semibold text-purple-300 mb-3 flex items-center">
                            <i class="fas fa-handshake mr-2"></i>Fournisseurs Internes (4012000)
                        </h4>
                        <div class="text-3xl font-bold text-purple-400 mb-2">
                            <?= number_format($totaux_internes['total'], 2, ',', ' ') ?> FCFA
                        </div>
                        <p class="text-sm text-purple-300"><?= $stats['nb_fournisseurs_internes'] ?> fournisseur(s)</p>
                    </div>
                </div>

                <!-- Graphiques -->
                <?php if (!empty($balance_par_fournisseur)): ?>
                    <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl shadow-2xl border border-slate-700 p-4 mb-6">
                        <h3 class="text-sm font-semibold mb-3 flex items-center">
                            <i class="fas fa-chart-pie text-purple-400 mr-2"></i>Graphiques d'analyse
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div style="height: 200px;">
                                <canvas id="chartAnciennete"></canvas>
                            </div>
                            <div style="height: 200px;">
                                <canvas id="chartTypes"></canvas>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tableau de balance âgée -->
                <?php if (!empty($balance_par_fournisseur)): ?>
                    <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl shadow-2xl border border-slate-700 mb-6 overflow-hidden">
                        <div class="p-6 border-b border-slate-700">
                            <h3 class="text-xl font-semibold flex items-center">
                                <i class="fas fa-table text-red-400 mr-2"></i>
                                Balance Âgée par Fournisseur
                            </h3>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-900/50 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Fournisseur</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-300 uppercase">Type</th>
                                        <?php foreach ($tranches as $key => $tranche): ?>
                                            <th class="px-4 py-3 text-right text-xs font-medium text-slate-300 uppercase bg-<?= $tranche['color'] ?>-500/10">
                                                <?= $tranche['label'] ?>
                                            </th>
                                        <?php endforeach; ?>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-300 uppercase bg-slate-700">Total</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-300 uppercase">Détail</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php foreach ($balance_par_fournisseur as $fournisseur): ?>
                                        <tr class="hover:bg-slate-700/50 transition-colors">
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-white"><?= htmlspecialchars($fournisseur['nom']) ?></div>
                                                <div class="text-xs text-slate-400">Aux: <?= htmlspecialchars($fournisseur['code']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($fournisseur['type'] === 'Externe'): ?>
                                                    <span class="px-2 py-1 bg-green-500/20 text-green-300 rounded text-xs">Externe</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 bg-purple-500/20 text-purple-300 rounded text-xs">Interne</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($tranches as $key => $tranche): ?>
                                                <td class="px-4 py-3 text-right font-mono <?= $fournisseur[$key] > 0 ? 'text-' . $tranche['color'] . '-400' : 'text-slate-500' ?>">
                                                    <?= $fournisseur[$key] > 0 ? number_format($fournisseur[$key], 2, ',', ' ') : '-' ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="px-4 py-3 text-right font-mono font-bold text-white bg-slate-700/30">
                                                <?= number_format($fournisseur['total'], 2, ',', ' ') ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <button onclick="toggleDetails('<?= md5($fournisseur['nom']) ?>')"
                                                        class="text-blue-400 hover:text-blue-300 transition-colors"
                                                        title="Voir le détail">
                                                    <i class="fas fa-chevron-down"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <!-- Ligne de détail (cachée par défaut) -->
                                        <tr id="details-<?= md5($fournisseur['nom']) ?>" class="hidden bg-slate-900/50">
                                            <td colspan="12" class="px-6 py-4">
                                                <div class="bg-slate-800/50 rounded-lg p-4">
                                                    <h4 class="font-semibold mb-3 text-white">Détail des factures</h4>
                                                    <table class="w-full text-sm">
                                                        <thead>
                                                            <tr class="border-b border-slate-700">
                                                                <th class="text-left py-2 text-slate-400">ID Écriture</th>
                                                                <th class="text-left py-2 text-slate-400">N° Facture</th>
                                                                <th class="text-left py-2 text-slate-400">Date facture</th>
                                                                <th class="text-left py-2 text-slate-400">Libellé</th>
                                                                <th class="text-right py-2 text-slate-400">Montant</th>
                                                                <th class="text-right py-2 text-slate-400">Réglé</th>
                                                                <th class="text-right py-2 text-slate-400">Restant</th>
                                                                <th class="text-right py-2 text-slate-400">Jours</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($fournisseur['factures'] as $fact): ?>
                                                                <tr class="border-b border-slate-800 hover:bg-slate-700/30">
                                                                    <td class="py-2 font-mono text-xs">
                                                                        <a href="../ecritures/voir.php?id=<?= $fact['id_ecriture'] ?? $fact['id'] ?? 0 ?>"
                                                                           class="text-blue-400 hover:text-blue-300 hover:underline transition-colors"
                                                                           title="Voir le détail de l'écriture (ID: <?= $fact['id_ecriture'] ?? 'N/A' ?>)">
                                                                            <?= htmlspecialchars($fact['numero_ecriture'] ?? 'N/A') ?>
                                                                        </a>
                                                                    </td>
                                                                    <td class="py-2 font-mono"><?= htmlspecialchars($fact['numero_facture'] ?? 'N/A') ?></td>
                                                                    <td class="py-2"><?= date('d/m/Y', strtotime($fact['date_facture'])) ?></td>
                                                                    <td class="py-2"><?= htmlspecialchars($fact['libelle']) ?></td>
                                                                    <td class="py-2 text-right font-mono text-white">
                                                                        <?= number_format($fact['montant_facture'], 2, ',', ' ') ?>
                                                                    </td>
                                                                    <td class="py-2 text-right font-mono text-green-400">
                                                                        <?= number_format($fact['montant_regle'], 2, ',', ' ') ?>
                                                                    </td>
                                                                    <td class="py-2 text-right font-mono text-red-400 font-bold">
                                                                        <?= number_format($fact['solde_restant'], 2, ',', ' ') ?>
                                                                    </td>
                                                                    <td class="py-2 text-right">
                                                                        <span class="px-2 py-1 rounded text-xs <?= $fact['jours_echus'] > 365 ? 'bg-pink-500/20 text-pink-300' : ($fact['jours_echus'] > 180 ? 'bg-purple-500/20 text-purple-300' : ($fact['jours_echus'] > 150 ? 'bg-red-500/20 text-red-300' : ($fact['jours_echus'] > 120 ? 'bg-orange-500/20 text-orange-300' : ($fact['jours_echus'] > 90 ? 'bg-yellow-500/20 text-yellow-300' : ($fact['jours_echus'] > 60 ? 'bg-cyan-500/20 text-cyan-300' : ($fact['jours_echus'] > 30 ? 'bg-blue-500/20 text-blue-300' : 'bg-green-500/20 text-green-300')))))) ?>">
                                                                            <?= $fact['jours_echus'] ?> j
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <!-- Totaux par type -->
                                <tbody class="bg-slate-900/60">
                                    <tr class="font-bold border-t-2 border-green-500/30">
                                        <td class="px-4 py-3 text-green-300" colspan="2">TOTAUX EXTERNES (4011000)</td>
                                        <?php foreach ($tranches as $key => $tranche): ?>
                                            <td class="px-4 py-3 text-right font-mono text-green-400">
                                                <?= number_format($totaux_externes[$key], 2, ',', ' ') ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="px-4 py-3 text-right font-mono text-green-400 bg-slate-700">
                                            <?= number_format($totaux_externes['total'], 2, ',', ' ') ?>
                                        </td>
                                        <td></td>
                                    </tr>
                                    <tr class="font-bold border-t-2 border-purple-500/30">
                                        <td class="px-4 py-3 text-purple-300" colspan="2">TOTAUX INTERNES (4012000)</td>
                                        <?php foreach ($tranches as $key => $tranche): ?>
                                            <td class="px-4 py-3 text-right font-mono text-purple-400">
                                                <?= number_format($totaux_internes[$key], 2, ',', ' ') ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="px-4 py-3 text-right font-mono text-purple-400 bg-slate-700">
                                            <?= number_format($totaux_internes['total'], 2, ',', ' ') ?>
                                        </td>
                                        <td></td>
                                    </tr>
                                    <tr class="font-bold border-t-4 border-red-500">
                                        <td class="px-4 py-4 text-white text-lg" colspan="2">TOTAUX GÉNÉRAUX</td>
                                        <?php foreach ($tranches as $key => $tranche): ?>
                                            <td class="px-4 py-4 text-right font-mono text-white text-lg">
                                                <?= number_format($totaux_generaux[$key], 2, ',', ' ') ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="px-4 py-4 text-right font-mono text-white text-lg bg-slate-700">
                                            <?= number_format($totaux_generaux['total'], 2, ',', ' ') ?>
                                        </td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl shadow-2xl border border-slate-700 p-12 text-center">
                        <i class="fas fa-inbox text-6xl text-slate-600 mb-4"></i>
                        <h3 class="text-xl font-semibold mb-2">Aucune dette fournisseur</h3>
                        <p class="text-slate-400">Aucune dette fournisseur trouvée pour la période sélectionnée.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Toggle details
        function toggleDetails(id) {
            const row = document.getElementById('details-' + id);
            row.classList.toggle('hidden');
        }

        <?php if (!empty($balance_par_fournisseur)): ?>
        // Graphique de répartition par ancienneté
        const ctxAnciennete = document.getElementById('chartAnciennete').getContext('2d');
        new Chart(ctxAnciennete, {
            type: 'bar',
            data: {
                labels: [<?php foreach ($tranches as $t) echo "'{$t['label']}',"; ?>],
                datasets: [{
                    label: 'Montant',
                    data: [<?php foreach ($tranches as $key => $t) echo "{$totaux_generaux[$key]},"; ?>],
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(6, 182, 212, 0.8)',
                        'rgba(234, 179, 8, 0.8)',
                        'rgba(249, 115, 22, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(236, 72, 153, 0.8)'
                    ],
                    borderWidth: 2,
                    borderColor: 'rgba(15, 23, 42, 0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    title: {
                        display: true,
                        text: 'Répartition par ancienneté',
                        color: '#f1f5f9',
                        font: { size: 14, weight: 'bold' }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#cbd5e1' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#cbd5e1' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    }
                }
            }
        });

        // Graphique Externes vs Internes
        const ctxTypes = document.getElementById('chartTypes').getContext('2d');
        new Chart(ctxTypes, {
            type: 'doughnut',
            data: {
                labels: ['Fournisseurs Externes', 'Fournisseurs Internes'],
                datasets: [{
                    data: [<?= $totaux_externes['total'] ?>, <?= $totaux_internes['total'] ?>],
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(168, 85, 247, 0.8)'
                    ],
                    borderWidth: 2,
                    borderColor: 'rgba(15, 23, 42, 0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#cbd5e1',
                            padding: 15,
                            font: { size: 12 }
                        }
                    },
                    title: {
                        display: true,
                        text: 'Externes vs Internes',
                        color: '#f1f5f9',
                        font: { size: 14, weight: 'bold' }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
