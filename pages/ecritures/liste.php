<?php
require_once '../../config/config.php';
requireLogin();

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Récupération des paramètres de recherche et filtres
$search = $_GET['search'] ?? '';
$compte = $_GET['compte'] ?? '';
$journal = $_GET['journal'] ?? '';
$statut = $_GET['statut'] ?? '';
$mois = $_GET['mois'] ?? '';
$annee = $_GET['annee'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';

// Pagination
$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$per_page = 20;
$offset = ($page_num - 1) * $per_page;

// Construction de la requête avec filtres
$whereConditions = ['e.societe_id = ?'];
$params = [$societe_id];

if (!empty($search)) {
    $whereConditions[] = "(e.numero_ecriture LIKE ? OR e.libelle LIKE ? OR le.libelle LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($compte)) {
    $whereConditions[] = "le.compte LIKE ?";
    $params[] = "{$compte}%";
}

if (!empty($journal)) {
    $whereConditions[] = "e.journal = ?";
    $params[] = $journal;
}

if (!empty($statut)) {
    $whereConditions[] = "e.statut = ?";
    $params[] = $statut;
}

if (!empty($mois)) {
    $whereConditions[] = "e.mois = ?";
    $params[] = $mois;
}

if (!empty($annee)) {
    $whereConditions[] = "e.annee = ?";
    $params[] = $annee;
}

if (!empty($date_debut)) {
    $whereConditions[] = "e.date_ecriture >= ?";
    $params[] = $date_debut;
}

if (!empty($date_fin)) {
    $whereConditions[] = "e.date_ecriture <= ?";
    $params[] = $date_fin;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Compter le total pour la pagination
$countSql = "
    SELECT COUNT(DISTINCT e.id) as total
    FROM ecritures e
    LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture
    $whereClause
";

$countStmt = $db->prepare($countSql);
foreach ($params as $index => $param) {
    $countStmt->bindValue($index + 1, $param);
}
$countStmt->execute();
$total_items = $countStmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Requête principale pour les écritures
$sql = "
    SELECT e.*, j.libelle as journal_libelle,
           (SELECT COUNT(*) FROM lignes_ecriture WHERE id_ecriture = e.id AND societe_id = e.societe_id) as nb_lignes,
           (SELECT SUM(debit) FROM lignes_ecriture WHERE id_ecriture = e.id AND societe_id = e.societe_id) as total_debit,
           (SELECT SUM(credit) FROM lignes_ecriture WHERE id_ecriture = e.id AND societe_id = e.societe_id) as total_credit
    FROM ecritures e
    LEFT JOIN journaux j ON e.journal = j.code_journal AND e.societe_id = j.societe_id
    LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture AND e.societe_id = le.societe_id
    $whereClause
    GROUP BY e.id
    ORDER BY e.date_ecriture DESC, e.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);

// Bind les paramètres des filtres
foreach ($params as $index => $param) {
    $stmt->bindValue($index + 1, $param);
}

// Bind les paramètres de pagination
$stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);

$stmt->execute();
$ecritures = $stmt->fetchAll();

// Récupérer les options pour les filtres - Journaux
try {
    $stmt_j = $db->prepare("SELECT code_journal as code, libelle as journal, type_journal as type FROM journaux WHERE societe_id = ? AND actif = 1 ORDER BY code_journal");
    $stmt_j->execute([$societe_id]);
    $journaux = $stmt_j->fetchAll();
} catch (Exception $e) {
    $journaux = [];
}

// Récupérer les comptes disponibles
try {
    $comptesStmt = $db->query("
        SELECT DISTINCT le.compte, pc.intitule_compte
        FROM lignes_ecriture le
        LEFT JOIN plan_comptable pc ON le.compte = pc.compte
        ORDER BY le.compte
    ");
    $comptesDisponibles = $comptesStmt->fetchAll();
} catch (Exception $e) {
    $comptesDisponibles = [];
}

// Récupérer les mois disponibles
try {
    $moisStmt = $db->query("
        SELECT DISTINCT mois
        FROM ecritures
        ORDER BY mois DESC
    ");
    $moisDisponibles = $moisStmt->fetchAll();
} catch (Exception $e) {
    $moisDisponibles = [];
}

// Récupérer les années disponibles
try {
    $anneesStmt = $db->query("
        SELECT DISTINCT annee
        FROM ecritures
        ORDER BY annee DESC
    ");
    $anneesDisponibles = $anneesStmt->fetchAll();
} catch (Exception $e) {
    $anneesDisponibles = [];
}

// Statistiques des écritures
try {
    $statsStmt = $db->query("
        SELECT
            COUNT(DISTINCT e.id) as total_ecritures,
            COUNT(DISTINCT le.compte) as comptes_utilises,
            SUM(le.debit) as total_debit,
            SUM(le.credit) as total_credit,
            (SUM(le.debit) - SUM(le.credit)) as equilibre,
            SUM(CASE WHEN e.statut = 'Brouillon' THEN 1 ELSE 0 END) as brouillon_count,
            SUM(CASE WHEN e.statut = 'Validé' THEN 1 ELSE 0 END) as valide_count
        FROM ecritures e
        LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture
    ");
    $stats = $statsStmt->fetch();
} catch (Exception $e) {
    $stats = [
        'total_ecritures' => 0,
        'comptes_utilises' => 0,
        'total_debit' => 0,
        'total_credit' => 0,
        'equilibre' => 0,
        'brouillon_count' => 0,
        'valide_count' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Écritures Comptables - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-600 mb-2">
                            <i class="fas fa-file-invoice mr-3"></i>Écritures Comptables
                        </h1>
                        <p class="text-slate-400 mt-1">Gestion des écritures comptables</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="import.php" class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-6 py-2.5 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-import"></i>
                            Importer
                        </a>
                        <a href="saisie.php" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2.5 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            Nouvelle écriture
                        </a>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-6 space-y-6">
                <!-- Statistiques -->
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    <!-- Total écritures -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-4 hover:border-blue-500/50 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-blue-500/10 p-2 rounded-lg">
                                <i class="fas fa-list-alt text-blue-400 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-white"><?= safe_number_format($stats['total_ecritures']) ?></div>
                        <div class="text-xs text-slate-400 mt-1">Total écritures</div>
                    </div>

                    <!-- Brouillon -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-4 hover:border-orange-500/50 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-orange-500/10 p-2 rounded-lg">
                                <i class="fas fa-edit text-orange-400 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-white"><?= safe_number_format($stats['brouillon_count']) ?></div>
                        <div class="text-xs text-slate-400 mt-1">Brouillon</div>
                    </div>

                    <!-- Validé -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-4 hover:border-green-500/50 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-green-500/10 p-2 rounded-lg">
                                <i class="fas fa-check-circle text-green-400 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-white"><?= safe_number_format($stats['valide_count']) ?></div>
                        <div class="text-xs text-slate-400 mt-1">Validé</div>
                    </div>

                    <!-- Comptes utilisés -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-4 hover:border-purple-500/50 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-purple-500/10 p-2 rounded-lg">
                                <i class="fas fa-calculator text-purple-400 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-white"><?= safe_number_format($stats['comptes_utilises']) ?></div>
                        <div class="text-xs text-slate-400 mt-1">Comptes utilisés</div>
                    </div>

                    <!-- Total Débit -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-4 hover:border-cyan-500/50 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-cyan-500/10 p-2 rounded-lg">
                                <i class="fas fa-plus text-cyan-400 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-lg font-bold text-white"><?= safe_number_format($stats['total_debit']) ?></div>
                        <div class="text-xs text-slate-400 mt-1">Total Débit (FCFA)</div>
                    </div>

                    <!-- Total Crédit -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-4 hover:border-pink-500/50 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-pink-500/10 p-2 rounded-lg">
                                <i class="fas fa-minus text-pink-400 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-lg font-bold text-white"><?= safe_number_format($stats['total_credit']) ?></div>
                        <div class="text-xs text-slate-400 mt-1">Total Crédit (FCFA)</div>
                    </div>
                </div>

                <!-- Filtres -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-blue-400"></i>
                        Filtres et recherche
                    </h3>

                    <form method="GET" action="">
                        <!-- Ligne 1: Recherche + Compte + Journal -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <!-- Recherche globale -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-search text-blue-400 mr-1"></i>Recherche globale
                                </label>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                       placeholder="N° écriture, libellé..."
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors">
                            </div>

                            <!-- Compte -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-hashtag text-blue-400 mr-1"></i>Compte
                                </label>
                                <select name="compte" class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Tous les comptes</option>
                                    <?php foreach ($comptesDisponibles as $c): ?>
                                        <option value="<?= htmlspecialchars($c['compte']) ?>" <?= $compte === $c['compte'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['compte']) ?> - <?= htmlspecialchars($c['intitule_compte'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Journal -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-book text-blue-400 mr-1"></i>Journal
                                </label>
                                <select name="journal" class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Tous les journaux</option>
                                    <?php foreach ($journaux as $j): ?>
                                        <option value="<?= htmlspecialchars($j['code']) ?>" <?= $journal === $j['code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($j['code']) ?> - <?= htmlspecialchars($j['journal']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Ligne 2: Statut + Mois + Année -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                            <!-- Statut -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-flag text-blue-400 mr-1"></i>Statut
                                </label>
                                <select name="statut" class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Tous les statuts</option>
                                    <option value="Brouillon" <?= $statut === 'Brouillon' ? 'selected' : '' ?>>Brouillon</option>
                                    <option value="Validé" <?= $statut === 'Validé' ? 'selected' : '' ?>>Validé</option>
                                </select>
                            </div>

                            <!-- Mois -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-calendar text-blue-400 mr-1"></i>Mois
                                </label>
                                <select name="mois" class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Tous les mois</option>
                                    <?php foreach ($moisDisponibles as $m): ?>
                                        <option value="<?= htmlspecialchars($m['mois']) ?>" <?= $mois === $m['mois'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($m['mois']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Année -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-calendar-alt text-blue-400 mr-1"></i>Année
                                </label>
                                <select name="annee" class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Toutes les années</option>
                                    <?php foreach ($anneesDisponibles as $a): ?>
                                        <option value="<?= $a['annee'] ?>" <?= $annee == $a['annee'] ? 'selected' : '' ?>>
                                            <?= $a['annee'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Boutons -->
                            <div class="flex items-end gap-2">
                                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2">
                                    <i class="fas fa-search"></i>Filtrer
                                </button>
                                <a href="liste.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Ligne 3: Dates -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-700">
                            <!-- Date de début -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-calendar-day text-green-400 mr-1"></i>Date de début
                                </label>
                                <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-green-500 transition-colors">
                            </div>

                            <!-- Date de fin -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-calendar-day text-green-400 mr-1"></i>Date de fin
                                </label>
                                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-green-500 transition-colors">
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Tableau des écritures -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg overflow-hidden">
                    <?php if (empty($ecritures)): ?>
                        <div class="text-center py-16">
                            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-500/10 rounded-full mb-4">
                                <i class="fas fa-list-alt text-blue-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-white mb-2">Aucune écriture trouvée</h3>
                            <p class="text-slate-400">Essayez de modifier vos critères de recherche</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-slate-900/50 border-b border-slate-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">N° Écriture</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Journal</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Libellé</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Lignes</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider">Débit</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider">Crédit</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Statut</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700/50">
                                    <?php foreach ($ecritures as $ecriture):
                                        $equilibre = abs($ecriture['total_debit'] - $ecriture['total_credit']) < 0.01;
                                    ?>
                                        <tr class="hover:bg-slate-700/20 transition-colors">
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm text-white"><?= date('d/m/Y', strtotime($ecriture['date_ecriture'])) ?></div>
                                                <div class="text-xs text-slate-500"><?= htmlspecialchars($ecriture['mois']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm font-mono font-semibold text-blue-400"><?= htmlspecialchars($ecriture['numero_ecriture']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm text-white"><?= htmlspecialchars($ecriture['journal']) ?></div>
                                                <div class="text-xs text-slate-500"><?= htmlspecialchars($ecriture['journal_libelle'] ?? '') ?></div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="text-sm text-white max-w-xs truncate" title="<?= htmlspecialchars($ecriture['libelle']) ?>">
                                                    <?= htmlspecialchars($ecriture['libelle']) ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-500/10 text-purple-400">
                                                    <?= $ecriture['nb_lignes'] ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="text-sm font-semibold text-cyan-400"><?= safe_number_format($ecriture['total_debit']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="text-sm font-semibold text-pink-400"><?= safe_number_format($ecriture['total_credit']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($ecriture['statut'] === 'Validé'): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-500/10 text-green-400">
                                                        <i class="fas fa-check-circle mr-1"></i>Validé
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-500/10 text-orange-400">
                                                        <i class="fas fa-edit mr-1"></i>Brouillon
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($ecriture['extournee'] === 'Oui'): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-500/10 text-orange-400 mt-1">
                                                        <i class="fas fa-undo mr-1"></i>Extournée
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!$equilibre): ?>
                                                    <div class="text-xs text-red-400 mt-1" title="Déséquilibre">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <div class="flex items-center justify-center gap-2">
                                                    <a href="voir.php?id=<?= $ecriture['id'] ?>"
                                                       class="text-blue-400 hover:text-blue-300 transition-colors"
                                                       title="Voir les détails">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($ecriture['statut'] === 'Brouillon'): ?>
                                                        <a href="saisie.php?id=<?= $ecriture['id'] ?>"
                                                           class="text-green-400 hover:text-green-300 transition-colors"
                                                           title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php elseif (isAdmin()): ?>
                                                        <a href="saisie.php?id=<?= $ecriture['id'] ?>"
                                                           class="text-amber-400 hover:text-amber-300 transition-colors"
                                                           title="Modifier (Admin)">
                                                            <i class="fas fa-user-shield"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <button onclick="dupliquerEcriture(<?= $ecriture['id'] ?>)"
                                                            class="text-purple-400 hover:text-purple-300 transition-colors"
                                                            title="Dupliquer cette écriture">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="bg-slate-900/50 px-6 py-4 border-t border-slate-700 flex items-center justify-between">
                                <div class="text-sm text-slate-400">
                                    Affichage de <?= ($offset + 1) ?> à <?= min($offset + $per_page, $total_items) ?> sur <?= $total_items ?> écritures
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php
                                    $queryParams = $_GET;
                                    unset($queryParams['p']);
                                    $baseUrl = 'liste.php?' . http_build_query($queryParams);
                                    ?>

                                    <?php if ($page_num > 1): ?>
                                        <a href="<?= $baseUrl ?>&p=1" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded transition-colors">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="<?= $baseUrl ?>&p=<?= $page_num - 1 ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded transition-colors">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php
                                    $start_page = max(1, $page_num - 2);
                                    $end_page = min($total_pages, $page_num + 2);
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a href="<?= $baseUrl ?>&p=<?= $i ?>"
                                           class="px-3 py-1 <?= $i === $page_num ? 'bg-blue-600 text-white' : 'bg-slate-700 hover:bg-slate-600 text-white' ?> rounded transition-colors">
                                            <?= $i ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page_num < $total_pages): ?>
                                        <a href="<?= $baseUrl ?>&p=<?= $page_num + 1 ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded transition-colors">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="<?= $baseUrl ?>&p=<?= $total_pages ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded transition-colors">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
    /**
     * Dupliquer une écriture comptable
     */
    function dupliquerEcriture(id) {
        // Afficher un loader
        const button = event.target.closest('button');
        const originalContent = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        button.disabled = true;

        // Récupérer les données de l'écriture via API
        fetch(`/comptabilite_ohada/api/v1/ecritures/get.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Stocker dans localStorage pour pré-remplir le formulaire
                localStorage.setItem('ecriture_duplicate', JSON.stringify({
                    ecriture: data.ecriture,
                    lignes: data.lignes,
                    original_numero: data.ecriture.numero_ecriture
                }));

                // Rediriger vers le formulaire de création
                window.location.href = 'saisie.php?duplicate=1';
            } else {
                alert(`❌ Erreur : ${data.error}`);
                button.innerHTML = originalContent;
                button.disabled = false;
            }
        })
        .catch(error => {
            alert(`❌ Erreur réseau : ${error.message}`);
            button.innerHTML = originalContent;
            button.disabled = false;
        });
    }
    </script>
</body>
</html>
