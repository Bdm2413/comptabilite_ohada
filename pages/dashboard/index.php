<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer quelques statistiques
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();

// Exercice actif
$exerciceActif = getExerciceActif();
// Si aucun exercice n'est actif, utiliser des valeurs par défaut
if (!$exerciceActif || !is_array($exerciceActif)) {
    $exerciceActif = [
        'annee' => date('Y'),
        'statut' => 'Non défini'
    ];
}

// Statistiques écritures
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM ecritures WHERE societe_id = ?");
    $stmt->execute([$societe_id]);
    $totalEcritures = $stmt->fetch()['total'] ?? 0;
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Brouillon' AND societe_id = ?");
    $stmt->execute([$societe_id]);
    $ecrituresBrouillon = $stmt->fetch()['total'] ?? 0;
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Validé' AND societe_id = ?");
    $stmt->execute([$societe_id]);
    $ecrituresValidees = $stmt->fetch()['total'] ?? 0;
} catch (Exception $e) {
    $totalEcritures = 0;
    $ecrituresBrouillon = 0;
    $ecrituresValidees = 0;
}

// Statistiques plan comptable
try {
    if ($societe_id === null) {
        $totalComptes = 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM plan_comptable WHERE actif = 'Oui' AND societe_id = ?");
        $stmt->execute([$societe_id]);
        $totalComptes = $stmt->fetch()['total'] ?? 0;
    }
} catch (Exception $e) {
    $totalComptes = 0;
}

// Statistiques tiers
try {
    if ($societe_id === null) {
        $totalTiers = 0;
        $totalClients = 0;
        $totalFournisseurs = 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM plan_tiers WHERE actif = 1 AND societe_id = ?");
        $stmt->execute([$societe_id]);
        $totalTiers = $stmt->fetch()['total'] ?? 0;
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM plan_tiers WHERE type = 'Client' AND actif = 1 AND societe_id = ?");
        $stmt->execute([$societe_id]);
        $totalClients = $stmt->fetch()['total'] ?? 0;
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ?");
        $stmt->execute([$societe_id]);
        $totalFournisseurs = $stmt->fetch()['total'] ?? 0;
    }
} catch (Exception $e) {
    $totalTiers = 0;
    $totalClients = 0;
    $totalFournisseurs = 0;
}

// Total trésorerie (comptes de type Banque ou Caisse dans le plan comptable)
try {
    $stmtTresorerie = $db->prepare("
        SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE pc.type IN ('Banque', 'Caisse')
        AND pc.actif = 'Oui'
        AND e.statut = 'Validé'
        AND e.societe_id = ?
    ");
    $stmtTresorerie->execute([$societe_id]);
    $tresorerie = $stmtTresorerie->fetch()['solde'] ?? 0;
} catch (Exception $e) {
    $tresorerie = 0;
}

// Calcul Produits et Charges pour comparaison rapide (Classes 6, 7 et 8)
try {
    $annee = intval($exerciceActif['annee']);

    // Produits Classe 7 (Produits d'exploitation)
    $stmtProduits7 = $db->prepare("
        SELECT SUM(le.credit) - SUM(le.debit) as total
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE le.compte >= 7000000 AND le.compte < 8000000
        AND e.statut = 'Validé' AND e.societe_id = ? AND e.annee = ?
    ");
    $stmtProduits7->execute([$societe_id, $annee]);
    $totalProduits7 = $stmtProduits7->fetch()['total'] ?? 0;

    // Produits HAO Classe 8 (deuxième chiffre pair : 82, 84, 86, 88)
    $stmtProduitsHAO = $db->prepare("
        SELECT SUM(le.credit) - SUM(le.debit) as total
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE (
            (le.compte >= 8200000 AND le.compte < 8300000) OR
            (le.compte >= 8400000 AND le.compte < 8500000) OR
            (le.compte >= 8600000 AND le.compte < 8700000) OR
            (le.compte >= 8800000 AND le.compte < 8900000)
        )
        AND e.statut = 'Validé' AND e.societe_id = ? AND e.annee = ?
    ");
    $stmtProduitsHAO->execute([$societe_id, $annee]);
    $totalProduitsHAO = $stmtProduitsHAO->fetch()['total'] ?? 0;

    // Total Produits (Classe 7 + Produits HAO Classe 8)
    $totalProduits = $totalProduits7 + $totalProduitsHAO;

    // Charges Classe 6 (Charges d'exploitation)
    $stmtCharges6 = $db->prepare("
        SELECT SUM(le.debit) - SUM(le.credit) as total
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE le.compte >= 6000000 AND le.compte < 7000000
        AND e.statut = 'Validé' AND e.societe_id = ? AND e.annee = ?
    ");
    $stmtCharges6->execute([$societe_id, $annee]);
    $totalCharges6 = $stmtCharges6->fetch()['total'] ?? 0;

    // Charges HAO Classe 8 (deuxième chiffre impair : 81, 83, 85, 87, 89)
    $stmtChargesHAO = $db->prepare("
        SELECT SUM(le.debit) - SUM(le.credit) as total
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE (
            (le.compte >= 8100000 AND le.compte < 8200000) OR
            (le.compte >= 8300000 AND le.compte < 8400000) OR
            (le.compte >= 8500000 AND le.compte < 8600000) OR
            (le.compte >= 8700000 AND le.compte < 8800000) OR
            (le.compte >= 8900000 AND le.compte < 9000000)
        )
        AND e.statut = 'Validé' AND e.societe_id = ? AND e.annee = ?
    ");
    $stmtChargesHAO->execute([$societe_id, $annee]);
    $totalChargesHAO = $stmtChargesHAO->fetch()['total'] ?? 0;

    // Total Charges (Classe 6 + Charges HAO Classe 8)
    $totalCharges = $totalCharges6 + $totalChargesHAO;

    // Résultat comptable = Total Produits - Total Charges
    $resultat = $totalProduits - $totalCharges;
} catch (Exception $e) {
    $totalProduits = 0;
    $totalCharges = 0;
    $resultat = 0;
}

// Dernières écritures
try {
    $stmtDernieres = $db->prepare("
        SELECT e.*, cj.libelle as journal_libelle
        FROM ecritures e
        LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
        WHERE e.societe_id = ?
        ORDER BY e.date_creation DESC
        LIMIT 5
    ");
    $stmtDernieres->execute([$societe_id]);
    $dernieresEcritures = $stmtDernieres->fetchAll();
} catch (Exception $e) {
    $dernieresEcritures = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                            <i class="fas fa-tachometer-alt mr-3"></i>Tableau de bord
                        </h1>
                        <p class="text-slate-400 mt-1">
                            Exercice <?php echo htmlspecialchars($exerciceActif['annee']); ?> -
                            <?php echo htmlspecialchars($exerciceActif['statut']); ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-4">
                        <!-- Bouton de recherche globale -->
                        <button onclick="document.getElementById('searchModal').classList.remove('hidden'); document.getElementById('globalSearchInput').focus();" class="flex items-center gap-2 px-4 py-2 bg-slate-700/50 hover:bg-slate-700 border border-slate-600 rounded-lg transition group">
                            <svg class="w-4 h-4 text-slate-400 group-hover:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <span class="text-sm text-slate-400 group-hover:text-slate-300">Rechercher</span>
                            <kbd class="hidden sm:inline-block px-2 py-1 text-xs bg-slate-800 border border-slate-600 rounded text-slate-400">Ctrl K</kbd>
                        </button>
                        <div class="text-right">
                            <p class="text-sm text-slate-400">Bonjour,</p>
                            <p class="text-lg font-semibold text-white"><?php echo htmlspecialchars($_SESSION['nom_utilisateur'] ?? 'Utilisateur'); ?></p>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="p-6 space-y-6">
                <!-- Statistiques principales -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Écritures comptables -->
                    <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/10 border border-blue-500/30 rounded-xl p-6 hover:border-blue-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-blue-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-blue-500/20 text-blue-300 rounded">Écritures</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($totalEcritures); ?></h3>
                        <p class="text-sm text-slate-400">Écritures comptables</p>
                        <div class="mt-3 pt-3 border-t border-blue-500/20 text-xs text-slate-400">
                            <span class="text-yellow-400"><?php echo $ecrituresBrouillon; ?> brouillon(s)</span> •
                            <span class="text-green-400"><?php echo $ecrituresValidees; ?> validée(s)</span>
                        </div>
                    </div>

                    <!-- Plan comptable -->
                    <div class="bg-gradient-to-br from-emerald-500/10 to-emerald-600/10 border border-emerald-500/30 rounded-xl p-6 hover:border-emerald-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-emerald-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-emerald-500/20 text-emerald-300 rounded">Plan comptable</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($totalComptes); ?></h3>
                        <p class="text-sm text-slate-400">Comptes actifs</p>
                    </div>

                    <!-- Tiers -->
                    <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/10 border border-purple-500/30 rounded-xl p-6 hover:border-purple-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-purple-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-purple-500/20 text-purple-300 rounded">Tiers</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($totalTiers); ?></h3>
                        <p class="text-sm text-slate-400">Tiers actifs</p>
                        <div class="mt-3 pt-3 border-t border-purple-500/20 text-xs text-slate-400">
                            <span class="text-blue-400"><?php echo $totalClients; ?> client(s)</span> •
                            <span class="text-orange-400"><?php echo $totalFournisseurs; ?> fournisseur(s)</span>
                        </div>
                    </div>

                    <!-- Trésorerie -->
                    <div class="bg-gradient-to-br from-amber-500/10 to-amber-600/10 border border-amber-500/30 rounded-xl p-6 hover:border-amber-500/50 transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-amber-500/20 rounded-lg">
                                <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <span class="text-xs px-2 py-1 bg-amber-500/20 text-amber-300 rounded">Trésorerie</span>
                        </div>
                        <h3 class="text-3xl font-bold text-white mb-1"><?php echo number_format($tresorerie, 0, ',', ' '); ?></h3>
                        <p class="text-sm text-slate-400">Solde trésorerie (FCFA)</p>
                    </div>
                </div>

                <!-- Widgets Analytiques -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Widget Comparaison rapide -->
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                    Résultat Comptable
                                </h2>
                                <p class="text-xs text-slate-400 mt-0.5">Exercice <?php echo htmlspecialchars($exerciceActif['annee']); ?></p>
                            </div>
                            <a href="../analyses/comparaison.php" class="text-xs text-cyan-400 hover:text-cyan-300 transition">
                                Voir détails →
                            </a>
                        </div>
                        <div class="space-y-2">
                            <!-- Produits d'exploitation -->
                            <div class="flex items-center justify-between p-2.5 bg-slate-700/30 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 bg-green-500 rounded-full"></div>
                                    <span class="text-xs text-slate-300">Produits d'exploitation</span>
                                </div>
                                <span class="text-xs font-semibold text-green-400"><?php echo number_format($totalProduits7, 0, ',', ' '); ?></span>
                            </div>

                            <!-- Produits HAO -->
                            <div class="flex items-center justify-between p-2.5 bg-slate-700/30 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></div>
                                    <span class="text-xs text-slate-300">Produits HAO</span>
                                </div>
                                <span class="text-xs font-semibold text-emerald-400"><?php echo number_format($totalProduitsHAO, 0, ',', ' '); ?></span>
                            </div>

                            <!-- Charges d'exploitation -->
                            <div class="flex items-center justify-between p-2.5 bg-slate-700/30 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 bg-red-500 rounded-full"></div>
                                    <span class="text-xs text-slate-300">Charges d'exploitation</span>
                                </div>
                                <span class="text-xs font-semibold text-red-400"><?php echo number_format($totalCharges6, 0, ',', ' '); ?></span>
                            </div>

                            <!-- Charges HAO -->
                            <div class="flex items-center justify-between p-2.5 bg-slate-700/30 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 bg-orange-500 rounded-full"></div>
                                    <span class="text-xs text-slate-300">Charges HAO</span>
                                </div>
                                <span class="text-xs font-semibold text-orange-400"><?php echo number_format($totalChargesHAO, 0, ',', ' '); ?></span>
                            </div>

                            <!-- Séparateur -->
                            <div class="border-t border-slate-600 my-2"></div>

                            <!-- Résultat net comptable -->
                            <div class="flex items-center justify-between p-3 bg-cyan-900/20 border border-cyan-500/30 rounded-lg">
                                <span class="text-sm font-semibold text-white">Résultat net comptable</span>
                                <span class="text-sm font-bold <?php echo $resultat >= 0 ? 'text-green-400' : 'text-red-400'; ?>">
                                    <?php echo number_format($resultat, 0, ',', ' '); ?> FCFA
                                </span>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500 mt-3 text-center">Cliquez sur "Voir détails" pour une analyse complète</p>
                    </div>

                    <!-- Widget Heatmap Preview -->
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path>
                                </svg>
                                Activité Comptable
                            </h2>
                            <a href="../analyses/heatmap.php" class="text-xs text-orange-400 hover:text-orange-300 transition">
                                Heatmap complète →
                            </a>
                        </div>
                        <div class="space-y-3">
                            <div class="grid grid-cols-7 gap-1">
                                <?php
                                // Simuler une mini heatmap des 7 derniers jours
                                for ($i = 6; $i >= 0; $i--) {
                                    $date = date('d/m', strtotime("-$i days"));
                                    $random = rand(0, 100);
                                    $color = $random > 70 ? 'bg-green-500' : ($random > 40 ? 'bg-yellow-500' : 'bg-slate-600');
                                    echo "<div class='aspect-square {$color} rounded opacity-80 hover:opacity-100 transition cursor-pointer' title='{$date}'></div>";
                                }
                                ?>
                            </div>
                            <p class="text-xs text-slate-400 text-center">Activité des 7 derniers jours</p>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                            <div>
                                <div class="text-lg font-bold text-green-400"><?php echo $ecrituresValidees; ?></div>
                                <div class="text-xs text-slate-500">Validées</div>
                            </div>
                            <div>
                                <div class="text-lg font-bold text-yellow-400"><?php echo $ecrituresBrouillon; ?></div>
                                <div class="text-xs text-slate-500">Brouillons</div>
                            </div>
                            <div>
                                <div class="text-lg font-bold text-blue-400"><?php echo $totalEcritures; ?></div>
                                <div class="text-xs text-slate-500">Total</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dernières écritures -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-slate-700/50">
                        <h2 class="text-lg font-semibold text-white">Dernières écritures comptables</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-700/30">
                                <tr class="text-left text-xs text-slate-400">
                                    <th class="p-3 font-medium">Numéro</th>
                                    <th class="p-3 font-medium">Date</th>
                                    <th class="p-3 font-medium">Journal</th>
                                    <th class="p-3 font-medium">Libellé</th>
                                    <th class="p-3 font-medium">Statut</th>
                                    <th class="p-3 font-medium">Créateur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php if (empty($dernieresEcritures)): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-slate-500">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            <p>Aucune écriture comptable</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($dernieresEcritures as $ecriture): ?>
                                        <tr class="hover:bg-slate-700/20 transition text-sm">
                                            <td class="p-3">
                                                <a href="../ecritures/voir.php?id=<?php echo $ecriture['id']; ?>"
                                                   class="font-mono text-blue-400 hover:text-blue-300 hover:underline transition-colors">
                                                    <?php echo htmlspecialchars($ecriture['numero_ecriture']); ?>
                                                </a>
                                            </td>
                                            <td class="p-3 text-slate-300">
                                                <?php echo date('d/m/Y', strtotime($ecriture['date_ecriture'])); ?>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-purple-500/10 text-purple-400 rounded text-xs">
                                                    <?php echo htmlspecialchars($ecriture['journal_libelle'] ?? $ecriture['journal']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-slate-300">
                                                <?php echo htmlspecialchars(substr($ecriture['libelle'], 0, 60)) . (strlen($ecriture['libelle']) > 60 ? '...' : ''); ?>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 rounded text-xs <?php echo $ecriture['statut'] === 'Validé' ? 'bg-green-500/10 text-green-400' : 'bg-yellow-500/10 text-yellow-400'; ?>">
                                                    <?php echo htmlspecialchars($ecriture['statut']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-slate-400 text-xs">
                                                <?php echo htmlspecialchars($ecriture['createur']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Actions rapides -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="../ecritures/saisie.php" class="block p-6 bg-gradient-to-br from-blue-500/10 to-blue-600/10 border border-blue-500/30 rounded-xl hover:border-blue-500/50 transition group">
                        <div class="flex items-center gap-4">
                            <div class="p-3 bg-blue-500/20 rounded-lg group-hover:scale-110 transition">
                                <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-white">Nouvelle écriture</h3>
                                <p class="text-xs text-slate-400">Saisir une écriture comptable</p>
                            </div>
                        </div>
                    </a>

                    <a href="../settings/plan_comptable.php" class="block p-6 bg-gradient-to-br from-emerald-500/10 to-emerald-600/10 border border-emerald-500/30 rounded-xl hover:border-emerald-500/50 transition group">
                        <div class="flex items-center gap-4">
                            <div class="p-3 bg-emerald-500/20 rounded-lg group-hover:scale-110 transition">
                                <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-white">Plan comptable</h3>
                                <p class="text-xs text-slate-400">Gérer les comptes</p>
                            </div>
                        </div>
                    </a>

                    <a href="../settings/tiers.php" class="block p-6 bg-gradient-to-br from-purple-500/10 to-purple-600/10 border border-purple-500/30 rounded-xl hover:border-purple-500/50 transition group">
                        <div class="flex items-center gap-4">
                            <div class="p-3 bg-purple-500/20 rounded-lg group-hover:scale-110 transition">
                                <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-white">Tiers</h3>
                                <p class="text-xs text-slate-400">Gérer clients & fournisseurs</p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Animation au chargement
        anime({
            targets: '.bg-gradient-to-br',
            opacity: [0, 1],
            translateY: [20, 0],
            delay: anime.stagger(100),
            duration: 600,
            easing: 'easeOutQuad'
        });
    </script>

    <!-- Recherche Globale -->
    <?php include '../../components/search_global.php'; ?>

    <!-- Assistant IA -->
    <?php include '../../components/ai_assistant.php'; ?>
</body>
</html>
