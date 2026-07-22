<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Vérifier que la table existe
$tableExists = false;
try {
    $db->query("SELECT 1 FROM ohada_plan_comptable LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    $tableExists = false;
}

$search    = trim($_GET['search'] ?? '');
$classe    = $_GET['classe'] ?? '';
$niveau    = $_GET['niveau'] ?? '';

$stats = [];
if ($tableExists) {
    $stats['total']   = $db->query("SELECT COUNT(*) FROM ohada_plan_comptable")->fetchColumn();
    $stats['classes'] = $db->query("SELECT COUNT(DISTINCT classe) FROM ohada_plan_comptable")->fetchColumn();
    $rows_by_niveau   = $db->query("SELECT niveau, COUNT(*) as cnt FROM ohada_plan_comptable GROUP BY niveau ORDER BY niveau")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows_by_niveau as $r) $stats['n'.$r['niveau']] = $r['cnt'];
    $stats['avec_rubriques'] = $db->query("SELECT COUNT(*) FROM ohada_plan_comptable WHERE bd IS NOT NULL OR bc IS NOT NULL OR rd IS NOT NULL OR rc IS NOT NULL")->fetchColumn();

    // Requête principale
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = "(compte_2 LIKE ? OR compte_3 LIKE ? OR compte_4 LIKE ?
                     OR libelle_2 LIKE ? OR libelle_3 LIKE ? OR libelle_4 LIKE ?
                     OR libelle_classe LIKE ?)";
        $s = '%'.$search.'%';
        $params = array_merge($params, [$s,$s,$s,$s,$s,$s,$s]);
    }
    if ($classe !== '') { $where[] = "classe = ?"; $params[] = $classe; }
    if ($niveau !== '') { $where[] = "niveau = ?"; $params[] = $niveau; }

    // Pagination
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    // Total filtré
    $sqlCount = "SELECT COUNT(*) FROM ohada_plan_comptable WHERE ".implode(' AND ', $where);
    $stmtC = $db->prepare($sqlCount);
    $stmtC->execute($params);
    $totalFiltre = (int)$stmtC->fetchColumn();
    $totalPages  = max(1, (int)ceil($totalFiltre / $perPage));
    $page        = min($page, $totalPages);

    $sql = "SELECT * FROM ohada_plan_comptable WHERE ".implode(' AND ', $where)
         . " ORDER BY classe, compte_2, compte_3, compte_4 LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));
    $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$classeLabels = [
    '1' => 'Ressources durables',
    '2' => 'Actif immobilisé',
    '3' => 'Stocks',
    '4' => 'Tiers',
    '5' => 'Trésorerie',
    '6' => 'Charges AO',
    '7' => 'Produits AO',
    '8' => 'Charges/Produits HAO',
];
$niveauLabels = [1=>'Classe',2=>'Principal (2 ch.)',3=>'Divisionnaire (3 ch.)',4=>'Sous-compte (4 ch.)'];
$niveauColors = [1=>'bg-violet-900/40 text-violet-300',2=>'bg-sky-900/40 text-sky-300',3=>'bg-emerald-900/40 text-emerald-300',4=>'bg-slate-700/60 text-slate-300'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Référentiel OHADA — Plan Comptable SYSCOHADA 2017</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        .row-n1 td { background: rgba(139,92,246,0.1); font-weight: 700; font-size: 13px; }
        .row-n2 td { background: rgba(14,165,233,0.07); font-weight: 600; }
        .row-n3 td { background: rgba(16,185,129,0.05); }
        .row-n4 td { background: transparent; }
        .row-n4:hover td { background: rgba(255,255,255,0.03); }
        .compte-badge { font-family: monospace; font-size: 12px; font-weight: 700; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-sky-400 mb-1">
                <i class="fas fa-book mr-3"></i>Référentiel OHADA — Plan Comptable SYSCOHADA Révisé 2017
            </h1>
            <p class="text-slate-400 text-sm">Base de référence officielle des comptes — Classes 1 à 8</p>
        </div>
        <?php if (!$tableExists): ?>
        <a href="../../setup/migration_ohada_referentiel.php"
           class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800 text-white rounded-lg text-sm inline-flex items-center gap-2">
            <i class="fas fa-database"></i>Initialiser le référentiel
        </a>
        <?php endif; ?>
    </div>

    <?php if (!$tableExists): ?>
    <!-- Table absente -->
    <div class="bg-amber-900/20 border border-amber-700/40 rounded-xl p-8 text-center">
        <i class="fas fa-exclamation-triangle text-4xl text-amber-400 mb-4 block"></i>
        <h2 class="text-amber-300 font-semibold text-lg mb-2">Référentiel non initialisé</h2>
        <p class="text-slate-400 text-sm mb-4">La table <code class="bg-slate-800 px-1 rounded">ohada_plan_comptable</code> n'existe pas encore.</p>
        <a href="../../setup/migration_ohada_referentiel.php"
           class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800 text-white rounded-xl font-semibold transition-all">
            <i class="fas fa-play"></i>Lancer la migration
        </a>
    </div>
    <?php else: ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Total entrées</p>
            <p class="text-2xl font-bold text-violet-400"><?= number_format($stats['total']) ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Classes</p>
            <p class="text-2xl font-bold text-sky-400"><?= $stats['classes'] ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Comptes 2 ch.</p>
            <p class="text-2xl font-bold text-emerald-400"><?= $stats['n2'] ?? 0 ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Comptes 3 ch.</p>
            <p class="text-2xl font-bold text-amber-400"><?= $stats['n3'] ?? 0 ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Sous-comptes 4 ch.</p>
            <p class="text-2xl font-bold text-rose-400"><?= $stats['n4'] ?? 0 ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Avec bd/bc/rd/rc</p>
            <p class="text-2xl font-bold text-amber-400"><?= $stats['avec_rubriques'] ?? 0 ?></p>
        </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="bg-slate-800 border border-slate-700 rounded-xl p-5 mb-6">
        <div class="flex flex-wrap items-end gap-3">
            <!-- Recherche -->
            <div class="flex-1 min-w-56">
                <label class="block text-xs font-medium text-slate-400 mb-1"><i class="fas fa-search mr-1"></i>Rechercher</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Numéro ou libellé..."
                       class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg focus:ring-2 focus:ring-violet-500 text-slate-100 text-sm">
            </div>
            <!-- Classe -->
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1"><i class="fas fa-layer-group mr-1"></i>Classe</label>
                <select name="classe" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg focus:ring-2 focus:ring-violet-500 text-slate-100 text-sm">
                    <option value="">Toutes</option>
                    <?php foreach ($classeLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $classe==$k?'selected':'' ?>>Classe <?= $k ?> — <?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Niveau -->
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1"><i class="fas fa-sitemap mr-1"></i>Niveau</label>
                <select name="niveau" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg focus:ring-2 focus:ring-violet-500 text-slate-100 text-sm">
                    <option value="">Tous</option>
                    <?php foreach ($niveauLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $niveau==$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-gradient-to-r from-violet-600 to-violet-700 hover:from-violet-700 hover:to-violet-800 text-white rounded-lg text-sm inline-flex items-center gap-2 transition-all">
                <i class="fas fa-filter"></i>Filtrer
            </button>
            <a href="referentiel_ohada.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2 transition-all">
                <i class="fas fa-times"></i>Réinit.
            </a>
        </div>
    </form>

    <!-- Résultats -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="px-6 py-3 border-b border-slate-700 flex items-center justify-between">
            <p class="text-sm text-slate-400">
                <span class="text-slate-200 font-medium"><?= number_format($totalFiltre) ?></span> entrée(s)
                — page <span class="text-slate-200 font-medium"><?= $page ?></span> / <?= $totalPages ?>
                (<?= $perPage ?> par page)
            </p>
            <div class="flex gap-2 text-xs">
                <?php foreach ($niveauColors as $n => $cls): ?>
                <span class="px-2 py-0.5 rounded <?= $cls ?>">N<?= $n ?> <?= $niveauLabels[$n] ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-20">Niveau</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-56">Classe</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-32">Compte 2 ch.</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-32">Compte 3 ch.</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-32">Compte 4 ch.</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs">Libellé</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BC</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RC</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($comptes)): ?>
                    <tr><td colspan="10" class="px-6 py-8 text-center text-slate-500"><i class="fas fa-search mr-2"></i>Aucun résultat</td></tr>
                <?php endif; ?>
                <?php foreach ($comptes as $r):
                    $n = (int)$r['niveau'];
                    $rowClass = 'row-n'.$n;

                    // Libellé et compte à afficher selon le niveau
                    switch ($n) {
                        case 1: $compte = $r['classe']; $libelle = $r['libelle_classe']; break;
                        case 2: $compte = $r['compte_2']; $libelle = $r['libelle_2']; break;
                        case 3: $compte = $r['compte_3']; $libelle = $r['libelle_3']; break;
                        case 4: $compte = $r['compte_4']; $libelle = $r['libelle_4']; break;
                        default: $compte=''; $libelle='';
                    }
                    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $n - 1);
                    $badgeColor = ['','bg-violet-600','bg-sky-600','bg-emerald-600','bg-slate-600'][$n];
                ?>
                <tr class="<?= $rowClass ?> border-b border-slate-700/30">
                    <td class="px-4 py-1.5">
                        <span class="<?= $niveauColors[$n] ?> text-xs px-1.5 py-0.5 rounded font-medium">N<?= $n ?></span>
                    </td>
                    <td class="px-4 py-1.5">
                        <span class="text-xs text-slate-400"><?= $r['classe'] ?> — <?= $classeLabels[$r['classe']] ?? '' ?></span>
                    </td>
                    <td class="px-4 py-1.5">
                        <?php if ($r['compte_2']): ?>
                        <span class="compte-badge <?= $n==2?'text-sky-300':'text-slate-500' ?>"><?= htmlspecialchars($r['compte_2']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-1.5">
                        <?php if ($r['compte_3']): ?>
                        <span class="compte-badge <?= $n==3?'text-emerald-300':'text-slate-500' ?>"><?= htmlspecialchars($r['compte_3']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-1.5">
                        <?php if ($r['compte_4']): ?>
                        <span class="compte-badge text-amber-300"><?= htmlspecialchars($r['compte_4']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-1.5 text-slate-200">
                        <?= $indent ?><?= htmlspecialchars($libelle ?? '') ?>
                    </td>
                    <td class="px-3 py-1.5 text-center">
                        <?php if ($r['bd']): ?>
                        <span class="text-xs font-mono font-semibold text-sky-300"><?= htmlspecialchars($r['bd']) ?></span>
                        <?php else: ?><span class="text-slate-700">—</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-1.5 text-center">
                        <?php if ($r['bc']): ?>
                        <span class="text-xs font-mono font-semibold text-emerald-300"><?= htmlspecialchars($r['bc']) ?></span>
                        <?php else: ?><span class="text-slate-700">—</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-1.5 text-center">
                        <?php if ($r['rd']): ?>
                        <span class="text-xs font-mono font-semibold text-amber-300"><?= htmlspecialchars($r['rd']) ?></span>
                        <?php else: ?><span class="text-slate-700">—</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-1.5 text-center">
                        <?php if ($r['rc']): ?>
                        <span class="text-xs font-mono font-semibold text-rose-300"><?= htmlspecialchars($r['rc']) ?></span>
                        <?php else: ?><span class="text-slate-700">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $baseUrl = 'referentiel_ohada.php?';
        $qp = [];
        if ($search  !== '') $qp[] = 'search='  . urlencode($search);
        if ($classe  !== '') $qp[] = 'classe='  . urlencode($classe);
        if ($niveau  !== '') $qp[] = 'niveau='  . urlencode($niveau);
        $baseUrl .= implode('&', $qp) . (empty($qp) ? '' : '&');
    ?>
    <div class="flex items-center justify-between mt-4 p-4 bg-slate-800/30 border border-slate-700/50 rounded-lg">
        <div class="text-sm text-slate-400">
            Page <?= $page ?> sur <?= $totalPages ?> (<?= number_format($totalFiltre) ?> entrées au total)
        </div>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
                <a href="<?= $baseUrl ?>page=1" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition" title="Première page">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path></svg>
                </a>
                <a href="<?= $baseUrl ?>page=<?= $page - 1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition">
                    Précédent
                </a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 2);
            $endPage   = min($totalPages, $page + 2);
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="<?= $baseUrl ?>page=<?= $i ?>"
                   class="px-3 py-1.5 rounded text-sm transition <?= $i === $page ? 'bg-violet-600 text-white' : 'bg-slate-700 hover:bg-slate-600 text-white' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= $baseUrl ?>page=<?= $page + 1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition">
                    Suivant
                </a>
                <a href="<?= $baseUrl ?>page=<?= $totalPages ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition" title="Dernière page">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Note -->
    <p class="text-xs text-slate-600 mt-3 text-right">
        Source : Plan Comptable SYSCOHADA Révisé — OHADA 2017
    </p>

    <?php endif; ?>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
