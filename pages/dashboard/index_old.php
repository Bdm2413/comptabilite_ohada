<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer quelques statistiques
$db = Database::getInstance()->getConnection();

// Exercice actif
$exerciceActif = getExerciceActif();

// Nombre de pièces
$stmtPieces = $db->query("SELECT COUNT(*) as total FROM pieces_comptables");
$totalPieces = $stmtPieces->fetch()['total'];

// Nombre de pièces non validées
$stmtPiecesNonValidees = $db->query("SELECT COUNT(*) as total FROM pieces_comptables WHERE valide = 0");
$piecesNonValidees = $stmtPiecesNonValidees->fetch()['total'];

// Total trésorerie (compte 57 - Caisse et 521 - Banques)
$stmtTresorerie = $db->query("
    SELECT
        SUM(le.debit) - SUM(le.credit) as solde
    FROM lignes_ecriture le
    JOIN pieces_comptables pc ON le.id_piece = pc.id_piece
    WHERE (le.numero_compte LIKE '57%' OR le.numero_compte LIKE '521%')
    AND pc.valide = 1
");
$tresorerie = $stmtTresorerie->fetch()['solde'] ?? 0;

// Dernières pièces
$stmtDernieresPieces = $db->query("
    SELECT pc.*, j.libelle as journal_libelle, u.nom_utilisateur
    FROM pieces_comptables pc
    JOIN journaux j ON pc.id_journal = j.id_journal
    JOIN utilisateurs u ON pc.id_utilisateur = u.id_utilisateur
    ORDER BY pc.date_saisie DESC
    LIMIT 5
");
$dernieresPieces = $stmtDernieresPieces->fetchAll();
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
                        <h1 class="text-xl font-semibold text-white">Tableau de bord</h1>
                        <p class="text-sm text-slate-400 mt-0.5">
                            <?php if ($exerciceActif): ?>
                                Exercice: <?php echo htmlspecialchars($exerciceActif['libelle']); ?> (<?php echo formatDate($exerciceActif['date_debut']); ?> - <?php echo formatDate($exerciceActif['date_fin']); ?>)
                            <?php else: ?>
                                Aucun exercice actif
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-right text-sm text-slate-400">
                        <p><?php echo formatDateLong(); ?></p>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-4 space-y-4">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    <!-- Card 1 -->
                    <div class="stat-card bg-gradient-to-br from-emerald-500/10 to-emerald-600/5 border border-emerald-500/20 rounded-xl p-4 opacity-0">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-medium text-emerald-400">Trésorerie</p>
                            <div class="w-8 h-8 bg-emerald-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-white"><?php echo formatMontant($tresorerie); ?></p>
                    </div>

                    <!-- Card 2 -->
                    <div class="stat-card bg-gradient-to-br from-blue-500/10 to-blue-600/5 border border-blue-500/20 rounded-xl p-4 opacity-0">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-medium text-blue-400">Total pièces</p>
                            <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-white"><?php echo number_format($totalPieces, 0, ',', ' '); ?></p>
                    </div>

                    <!-- Card 3 -->
                    <div class="stat-card bg-gradient-to-br from-amber-500/10 to-amber-600/5 border border-amber-500/20 rounded-xl p-4 opacity-0">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-medium text-amber-400">Non validées</p>
                            <div class="w-8 h-8 bg-amber-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-white"><?php echo number_format($piecesNonValidees, 0, ',', ' '); ?></p>
                    </div>

                    <!-- Card 4 -->
                    <div class="stat-card bg-gradient-to-br from-purple-500/10 to-purple-600/5 border border-purple-500/20 rounded-xl p-4 opacity-0">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-medium text-purple-400">Exercice</p>
                            <div class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-lg font-bold text-white"><?php echo $exerciceActif ? htmlspecialchars($exerciceActif['libelle']) : 'Aucun'; ?></p>
                    </div>
                </div>

                <!-- Dernières pièces -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl opacity-0" id="dernieres-pieces">
                    <div class="p-4 border-b border-slate-700/50">
                        <h2 class="text-base font-semibold text-white">Dernières pièces comptables</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-700/20">
                                <tr class="text-left text-slate-400 text-xs">
                                    <th class="p-3 font-medium">Numéro</th>
                                    <th class="p-3 font-medium">Date</th>
                                    <th class="p-3 font-medium">Journal</th>
                                    <th class="p-3 font-medium">Libellé</th>
                                    <th class="p-3 font-medium">Montant</th>
                                    <th class="p-3 font-medium">Par</th>
                                    <th class="p-3 font-medium">Statut</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php if (empty($dernieresPieces)): ?>
                                    <tr>
                                        <td colspan="7" class="p-4 text-center text-slate-500">Aucune pièce enregistrée</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($dernieresPieces as $piece): ?>
                                        <tr class="hover:bg-slate-700/20 transition">
                                            <td class="p-3 text-white font-medium"><?php echo htmlspecialchars($piece['numero_piece']); ?></td>
                                            <td class="p-3 text-slate-300"><?php echo formatDate($piece['date_piece']); ?></td>
                                            <td class="p-3 text-slate-300"><?php echo htmlspecialchars($piece['journal_libelle']); ?></td>
                                            <td class="p-3 text-slate-300"><?php echo htmlspecialchars($piece['libelle']); ?></td>
                                            <td class="p-3 text-white font-medium"><?php echo formatMontant($piece['montant_total']); ?></td>
                                            <td class="p-3 text-slate-400 text-xs"><?php echo htmlspecialchars($piece['nom_utilisateur']); ?></td>
                                            <td class="p-3">
                                                <?php if ($piece['valide']): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded text-xs">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                        </svg>
                                                        Validée
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-amber-500/10 text-amber-400 rounded text-xs">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                                        </svg>
                                                        Brouillon
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>

    <script>
        // Animation des cartes statistiques
        anime({
            targets: '.stat-card',
            opacity: [0, 1],
            translateY: [20, 0],
            delay: anime.stagger(100, {start: 300}),
            duration: 600,
            easing: 'easeOutQuad'
        });

        // Animation du tableau
        anime({
            targets: '#dernieres-pieces',
            opacity: [0, 1],
            translateY: [30, 0],
            delay: 700,
            duration: 600,
            easing: 'easeOutQuad'
        });
    </script>
</body>
</html>
