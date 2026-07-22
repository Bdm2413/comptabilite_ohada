<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer quelques statistiques
$db = Database::getInstance()->getConnection();

// Exercice actif
$exerciceActif = getExerciceActif();

// Statistiques écritures
try {
    $totalEcritures = $db->query("SELECT COUNT(*) as total FROM ecritures")->fetch()['total'] ?? 0;
    $ecrituresBrouillon = $db->query("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Brouillon'")->fetch()['total'] ?? 0;
    $ecrituresValidees = $db->query("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Validé'")->fetch()['total'] ?? 0;
} catch (Exception $e) {
    $totalEcritures = 0;
    $ecrituresBrouillon = 0;
    $ecrituresValidees = 0;
}

// Statistiques plan comptable
try {
    $totalComptes = $db->query("SELECT COUNT(*) as total FROM plan_comptable WHERE actif = 'Oui'")->fetch()['total'] ?? 0;
} catch (Exception $e) {
    $totalComptes = 0;
}

// Statistiques tiers
try {
    $totalTiers = $db->query("SELECT COUNT(*) as total FROM plan_tiers WHERE actif = 1")->fetch()['total'] ?? 0;
    $totalClients = $db->query("SELECT COUNT(*) as total FROM plan_tiers WHERE type = 'Client' AND actif = 1")->fetch()['total'] ?? 0;
    $totalFournisseurs = $db->query("SELECT COUNT(*) as total FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1")->fetch()['total'] ?? 0;
} catch (Exception $e) {
    $totalTiers = 0;
    $totalClients = 0;
    $totalFournisseurs = 0;
}

// Total trésorerie (compte 57 - Caisse et 521 - Banques)
try {
    $stmtTresorerie = $db->query("
        SELECT SUM(le.debit) - SUM(le.credit) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE (le.compte >= 5700000 AND le.compte < 5800000) OR (le.compte >= 5210000 AND le.compte < 5220000)
        AND e.statut = 'Validé'
    ");
    $tresorerie = $stmtTresorerie->fetch()['solde'] ?? 0;
} catch (Exception $e) {
    $tresorerie = 0;
}

// Dernières écritures
try {
    $dernieresEcritures = $db->query("
        SELECT e.*, cj.journal as journal_libelle
        FROM ecritures e
        LEFT JOIN code_journal cj ON e.journal = cj.code
        ORDER BY e.date_creation DESC
        LIMIT 5
    ")->fetchAll();
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
                        <h1 class="text-2xl font-bold text-white">Tableau de bord</h1>
                        <p class="text-sm text-slate-400 mt-1">
                            Exercice <?php echo htmlspecialchars($exerciceActif['annee']); ?> -
                            <?php echo htmlspecialchars($exerciceActif['statut']); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-slate-400">Bonjour,</p>
                        <p class="text-lg font-semibold text-white"><?php echo htmlspecialchars($_SESSION['nom_utilisateur'] ?? 'Utilisateur'); ?></p>
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
                                                <span class="font-mono text-blue-400"><?php echo htmlspecialchars($ecriture['numero_ecriture']); ?></span>
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
</body>
</html>
