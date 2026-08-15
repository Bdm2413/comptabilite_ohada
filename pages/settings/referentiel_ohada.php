<?php
require_once '../../config/config.php';
requireLogin();

$db        = Database::getInstance()->getConnection();
$isAdmin   = ($_SESSION['user_role'] ?? '') === 'admin';
$societeId = getCurrentSocieteId();

$message     = '';
$messageType = '';

// ─── POST : édition d'un compte du référentiel ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (($_POST['action'] ?? '') === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $libelle  = trim($_POST['libelle'] ?? '');
        $type     = $_POST['type_compte'] ?? null;
        $tableau  = trim($_POST['tableau'] ?? '');
        $bd       = trim($_POST['bd'] ?? '') ?: null;
        $bc       = trim($_POST['bc'] ?? '') ?: null;
        $rd       = trim($_POST['rd'] ?? '') ?: null;
        $rc       = trim($_POST['rc'] ?? '') ?: null;
        $propager = !empty($_POST['propager']);

        if ($id <= 0 || empty($libelle)) {
            $message = "Données invalides."; $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();
                $avant = $db->prepare("SELECT * FROM table_correspondance WHERE id = ?");
                $avant->execute([$id]);
                $avant = $avant->fetch();

                $db->prepare("UPDATE table_correspondance
                    SET libelle=?, type_compte=?, tableau=?, bd=?, bc=?, rd=?, rc=?, updated_at=NOW()
                    WHERE id=?")->execute([$libelle, $type ?: null, $tableau, $bd, $bc, $rd, $rc, $id]);

                if ($propager && $avant && $societeId) {
                    $db->prepare("UPDATE plan_comptable
                        SET intitule_compte=?, type=?, tableau=?, bd=?, bc=?, rd=?, rc=?, updated_at=NOW()
                        WHERE societe_id=? AND quatre_chiffres=?")
                        ->execute([$libelle, $type ?: null, $tableau, $bd, $bc, $rd, $rc, $societeId, (int)$avant['compte']]);
                }
                $db->commit();
                $message = "Compte mis à jour" . ($propager ? " et propagé au plan comptable." : "."); $messageType = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $message = "Erreur : " . $e->getMessage(); $messageType = 'error';
            }
        }
    }
}

// ─── Onglet actif ─────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'hierarchique';

// ─── Onglet 1 : Vue hiérarchique (ohada_plan_comptable) ──────────────────────
$search1 = trim($_GET['search'] ?? '');
$classe1 = $_GET['classe'] ?? '';
$niveau1 = $_GET['niveau'] ?? '';

$where1  = ['1=1']; $params1 = [];
if ($search1 !== '') {
    $where1[] = "(compte_2 LIKE ? OR compte_3 LIKE ? OR compte_4 LIKE ?
                  OR libelle_2 LIKE ? OR libelle_3 LIKE ? OR libelle_4 LIKE ?
                  OR libelle_classe LIKE ?)";
    $s = '%'.$search1.'%';
    $params1 = array_merge($params1, [$s,$s,$s,$s,$s,$s,$s]);
}
if ($classe1 !== '') { $where1[] = "classe = ?"; $params1[] = $classe1; }
if ($niveau1 !== '') { $where1[] = "niveau = ?"; $params1[] = $niveau1; }

$page1    = max(1, (int)($_GET['page'] ?? 1));
$perPage1 = 25;

$totalH   = (int)$db->prepare("SELECT COUNT(*) FROM ohada_plan_comptable WHERE ".implode(' AND ',$where1))
    ->execute($params1) ? $db->query("SELECT COUNT(*) FROM ohada_plan_comptable WHERE ".implode(' AND ',$where1)." LIMIT 1")->fetchColumn() : 0;

$stmtH = $db->prepare("SELECT COUNT(*) FROM ohada_plan_comptable WHERE ".implode(' AND ',$where1));
$stmtH->execute($params1);
$totalH = (int)$stmtH->fetchColumn();
$totalPagesH = max(1, (int)ceil($totalH / $perPage1));
$page1 = min($page1, $totalPagesH);

$stmtH2 = $db->prepare("SELECT * FROM ohada_plan_comptable WHERE ".implode(' AND ',$where1)
    ." ORDER BY classe, compte_2, compte_3, compte_4 LIMIT ? OFFSET ?");
$stmtH2->execute(array_merge($params1, [$perPage1, ($page1-1)*$perPage1]));
$comptesH = $stmtH2->fetchAll(PDO::FETCH_ASSOC);

$statsH = [
    'total'   => $db->query("SELECT COUNT(*) FROM ohada_plan_comptable")->fetchColumn(),
    'classes' => $db->query("SELECT COUNT(DISTINCT classe) FROM ohada_plan_comptable")->fetchColumn(),
    'n4'      => $db->query("SELECT COUNT(*) FROM ohada_plan_comptable WHERE niveau=4")->fetchColumn(),
    'bd_ok'   => $db->query("SELECT COUNT(*) FROM ohada_plan_comptable WHERE bd IS NOT NULL")->fetchColumn(),
];

// ─── Onglet 2 : Gestion référentiel (table_correspondance) ───────────────────
$search2 = trim($_GET['search2'] ?? '');
$classe2 = $_GET['classe2'] ?? '';
$type_f  = $_GET['type_f'] ?? '';

$where2 = ['1=1']; $params2 = [];
if ($search2 !== '') {
    $where2[] = "(compte LIKE ? OR libelle LIKE ?)";
    $s2 = '%'.$search2.'%'; $params2 = array_merge($params2, [$s2, $s2]);
}
if ($classe2 !== '') { $where2[] = "classe = ?"; $params2[] = $classe2; }
if ($type_f  !== '') { $where2[] = "type_compte = ?"; $params2[] = $type_f; }

$page2    = max(1, (int)($_GET['page2'] ?? 1));
$perPage2 = 25;

$stmtC2 = $db->prepare("SELECT COUNT(*) FROM table_correspondance WHERE ".implode(' AND ',$where2));
$stmtC2->execute($params2);
$totalC = (int)$stmtC2->fetchColumn();
$totalPagesC = max(1, (int)ceil($totalC / $perPage2));
$page2 = min($page2, $totalPagesC);

$stmtC3 = $db->prepare("SELECT * FROM table_correspondance WHERE ".implode(' AND ',$where2)
    ." ORDER BY compte LIMIT ? OFFSET ?");
$stmtC3->execute(array_merge($params2, [$perPage2, ($page2-1)*$perPage2]));
$comptesC = $stmtC3->fetchAll(PDO::FETCH_ASSOC);

$statsC = [
    'total'   => $db->query("SELECT COUNT(*) FROM table_correspondance")->fetchColumn(),
    'classes' => $db->query("SELECT COUNT(DISTINCT classe) FROM table_correspondance")->fetchColumn(),
    'bd_ok'   => $db->query("SELECT COUNT(*) FROM table_correspondance WHERE bd IS NOT NULL")->fetchColumn(),
];

// ─── Labels communs ───────────────────────────────────────────────────────────
$classeLabels = [
    1=>'Ressources durables', 2=>'Actif immobilisé', 3=>'Stocks', 4=>'Tiers',
    5=>'Trésorerie', 6=>'Charges AO', 7=>'Produits AO', 8=>'Charges/Produits HAO',
];
$niveauLabels = [1=>'Classe',2=>'Principal (2 ch.)',3=>'Divisionnaire (3 ch.)',4=>'Sous-compte (4 ch.)'];
$niveauColors = [
    1=>'bg-violet-900/40 text-violet-300',
    2=>'bg-sky-900/40 text-sky-300',
    3=>'bg-emerald-900/40 text-emerald-300',
    4=>'bg-slate-700/60 text-slate-300',
];
$typeOptions = [
    'Capitaux','Immobilisation','Amortis/Provision','Stock',
    'Client','Fournisseur','Salarié','CNPS','Etat',
    'Banque','Caisse','Titre',
    'Charge','Produit','Résultat-Gestion','Résultat-Bilan','Autres',
];
$typeColors = [
    'Capitaux'=>'bg-violet-900/40 text-violet-300','Immobilisation'=>'bg-sky-900/40 text-sky-300',
    'Amortis/Provision'=>'bg-slate-700/60 text-slate-400','Stock'=>'bg-amber-900/40 text-amber-300',
    'Client'=>'bg-emerald-900/40 text-emerald-300','Fournisseur'=>'bg-rose-900/40 text-rose-300',
    'Salarié'=>'bg-pink-900/40 text-pink-300','CNPS'=>'bg-orange-900/40 text-orange-300',
    'Etat'=>'bg-red-900/40 text-red-300','Banque'=>'bg-blue-900/40 text-blue-300',
    'Caisse'=>'bg-teal-900/40 text-teal-300','Titre'=>'bg-indigo-900/40 text-indigo-300',
    'Charge'=>'bg-red-900/30 text-red-400','Produit'=>'bg-green-900/30 text-green-400',
    'Résultat-Gestion'=>'bg-yellow-900/40 text-yellow-300','Résultat-Bilan'=>'bg-purple-900/40 text-purple-300',
    'Autres'=>'bg-slate-700/40 text-slate-400',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Référentiel SYSCOHADA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        .row-n1 td { background: rgba(139,92,246,0.1); font-weight:700; font-size:13px; }
        .row-n2 td { background: rgba(14,165,233,0.07); font-weight:600; }
        .row-n3 td { background: rgba(16,185,129,0.05); }
        .row-n4 td { background: transparent; }
        .row-n4:hover td { background: rgba(255,255,255,0.03); }
        .compte-badge { font-family:monospace; font-size:12px; font-weight:700; }
        .badge { display:inline-block; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600; }
        .tab-active { border-bottom: 2px solid #7c3aed; color: #c4b5fd; }
        .tab-inactive { border-bottom: 2px solid transparent; color: #94a3b8; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-6">

    <!-- En-tête -->
    <div class="mb-5">
        <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-sky-400 mb-1">
            <i class="fas fa-book mr-2"></i>Référentiel OHADA - Plan Comptable SYSCOHADA Révisé 2017
        </h1>
        <p class="text-slate-400 text-sm">Base de référence officielle des comptes - Classes 1 à 8</p>
    </div>

    <?php if ($message): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
        <?= $messageType==='success' ? 'bg-emerald-900/40 border border-emerald-700/50 text-emerald-300' : 'bg-rose-900/40 border border-rose-700/50 text-rose-300' ?>">
        <i class="fas fa-<?= $messageType==='success' ? 'check-circle' : 'exclamation-circle' ?> mr-2"></i>
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Onglets -->
    <div class="flex gap-1 border-b border-slate-700 mb-6">
        <a href="?tab=hierarchique"
           class="px-5 py-2.5 text-sm font-medium transition <?= $tab==='hierarchique' ? 'tab-active' : 'tab-inactive hover:text-slate-300' ?>">
            <i class="fas fa-sitemap mr-2"></i>Vue hiérarchique
        </a>
        <a href="?tab=gestion"
           class="px-5 py-2.5 text-sm font-medium transition <?= $tab==='gestion' ? 'tab-active' : 'tab-inactive hover:text-slate-300' ?>">
            <i class="fas fa-sliders-h mr-2"></i>Gestion BD/BC/RD/RC
            <?php if ($isAdmin): ?>
            <span class="ml-1 text-xs bg-amber-900/50 text-amber-300 px-1.5 py-0.5 rounded">Admin</span>
            <?php endif; ?>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($tab === 'hierarchique'): ?>
    <!-- ONGLET 1 : Vue hiérarchique ─────────────────────────────────────────── -->

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Total entrées</p>
            <p class="text-2xl font-bold text-violet-400"><?= number_format($statsH['total']) ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Classes</p>
            <p class="text-2xl font-bold text-sky-400"><?= $statsH['classes'] ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Sous-comptes 4 ch.</p>
            <p class="text-2xl font-bold text-rose-400"><?= number_format($statsH['n4']) ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Avec BD/BC/RD/RC</p>
            <p class="text-2xl font-bold text-amber-400"><?= number_format($statsH['bd_ok']) ?></p>
        </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="bg-slate-800 border border-slate-700 rounded-xl p-5 mb-6">
        <input type="hidden" name="tab" value="hierarchique">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-56">
                <label class="block text-xs font-medium text-slate-400 mb-1"><i class="fas fa-search mr-1"></i>Rechercher</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search1) ?>"
                       placeholder="Numéro ou libellé..."
                       class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg focus:ring-2 focus:ring-violet-500 text-slate-100 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Classe</label>
                <select name="classe" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg focus:ring-2 focus:ring-violet-500 text-slate-100 text-sm">
                    <option value="">Toutes</option>
                    <?php foreach ($classeLabels as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $classe1==$k?'selected':'' ?>>Classe <?= $k ?> - <?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Niveau</label>
                <select name="niveau" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg focus:ring-2 focus:ring-violet-500 text-slate-100 text-sm">
                    <option value="">Tous</option>
                    <?php foreach ($niveauLabels as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $niveau1==$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-gradient-to-r from-violet-600 to-violet-700 hover:from-violet-700 hover:to-violet-800 text-white rounded-lg text-sm inline-flex items-center gap-2 transition-all">
                <i class="fas fa-filter"></i>Filtrer
            </button>
            <a href="?tab=hierarchique" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2 transition-all">
                <i class="fas fa-times"></i>Réinit.
            </a>
        </div>
    </form>

    <!-- Légende niveaux -->
    <div class="flex gap-2 text-xs mb-3">
        <?php foreach ($niveauColors as $n=>$cls): ?>
        <span class="px-2 py-0.5 rounded <?= $cls ?>">N<?= $n ?> <?= $niveauLabels[$n] ?></span>
        <?php endforeach; ?>
    </div>

    <!-- Tableau hiérarchique -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="px-6 py-3 border-b border-slate-700 flex items-center justify-between">
            <p class="text-sm text-slate-400">
                <span class="text-slate-200 font-medium"><?= number_format($totalH) ?></span> entrée(s)
                - page <span class="text-slate-200 font-medium"><?= $page1 ?></span> / <?= $totalPagesH ?>
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-20">Niveau</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-56">Classe</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-28">2 ch.</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-28">3 ch.</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-28">4 ch.</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs">Libellé</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BC</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RC</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($comptesH)): ?>
                    <tr><td colspan="10" class="px-6 py-8 text-center text-slate-500"><i class="fas fa-search mr-2"></i>Aucun résultat</td></tr>
                <?php endif; ?>
                <?php foreach ($comptesH as $r):
                    $n = (int)$r['niveau'];
                    switch ($n) {
                        case 1: $compte=$r['classe'];   $libelle=$r['libelle_classe']; break;
                        case 2: $compte=$r['compte_2']; $libelle=$r['libelle_2'];      break;
                        case 3: $compte=$r['compte_3']; $libelle=$r['libelle_3'];      break;
                        default:$compte=$r['compte_4']; $libelle=$r['libelle_4'];      break;
                    }
                    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $n - 1);
                ?>
                <tr class="row-n<?= $n ?> border-b border-slate-700/30">
                    <td class="px-4 py-1.5">
                        <span class="<?= $niveauColors[$n] ?> text-xs px-1.5 py-0.5 rounded font-medium">N<?= $n ?></span>
                    </td>
                    <td class="px-4 py-1.5 text-xs text-slate-400"><?= $r['classe'] ?> - <?= $classeLabels[(int)$r['classe']] ?? '' ?></td>
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
                    <td class="px-4 py-1.5 text-slate-200"><?= $indent ?><?= htmlspecialchars($libelle ?? '') ?></td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-sky-300">
                        <?= $r['bd'] ? htmlspecialchars($r['bd']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-emerald-300">
                        <?= $r['bc'] ? htmlspecialchars($r['bc']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-amber-300">
                        <?= $r['rd'] ? htmlspecialchars($r['rd']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-rose-300">
                        <?= $r['rc'] ? htmlspecialchars($r['rc']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination onglet 1 -->
    <?php if ($totalPagesH > 1):
        $qpH = ['tab=hierarchique'];
        if ($search1!='') $qpH[]='search='.urlencode($search1);
        if ($classe1!='') $qpH[]='classe='.urlencode($classe1);
        if ($niveau1!='') $qpH[]='niveau='.urlencode($niveau1);
        $baseH = 'referentiel_ohada.php?'.implode('&',$qpH).'&';
    ?>
    <div class="flex items-center justify-between mt-4 p-4 bg-slate-800/30 border border-slate-700/50 rounded-lg">
        <p class="text-sm text-slate-400">Page <?= $page1 ?> / <?= $totalPagesH ?></p>
        <div class="flex gap-2">
            <?php if ($page1>1): ?>
            <a href="<?= $baseH ?>page=1" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">&laquo;</a>
            <a href="<?= $baseH ?>page=<?= $page1-1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">Préc.</a>
            <?php endif; ?>
            <?php for ($i=max(1,$page1-2);$i<=min($totalPagesH,$page1+2);$i++): ?>
            <a href="<?= $baseH ?>page=<?= $i ?>"
               class="px-3 py-1.5 rounded text-sm <?= $i===$page1?'bg-violet-600 text-white':'bg-slate-700 hover:bg-slate-600 text-white' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
            <?php if ($page1<$totalPagesH): ?>
            <a href="<?= $baseH ?>page=<?= $page1+1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">Suiv.</a>
            <a href="<?= $baseH ?>page=<?= $totalPagesH ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">&raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-xs text-slate-600 mt-3 text-right">Source : Plan Comptable SYSCOHADA Révisé - OHADA 2017</p>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- ONGLET 2 : Gestion BD/BC/RD/RC ─────────────────────────────────────── -->

    <?php if (!$isAdmin): ?>
    <div class="bg-amber-900/20 border border-amber-700/40 rounded-xl p-6 text-center">
        <i class="fas fa-lock text-3xl text-amber-400 mb-3 block"></i>
        <p class="text-amber-300 font-semibold">Accès réservé à l'administrateur</p>
        <p class="text-slate-400 text-sm mt-1">Connectez-vous avec un compte admin pour modifier le référentiel.</p>
    </div>
    <?php else: ?>

    <div class="flex items-center gap-3 mb-5">
        <span class="px-3 py-1 bg-amber-900/40 text-amber-300 rounded-lg text-sm font-medium">
            <i class="fas fa-shield-alt mr-1"></i>Mode admin - édition activée
        </span>
        <span class="text-slate-400 text-sm">Cliquez sur un compte pour modifier ses valeurs.</span>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Comptes référentiel</p>
            <p class="text-2xl font-bold text-violet-400"><?= number_format($statsC['total']) ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Classes couvertes</p>
            <p class="text-2xl font-bold text-sky-400"><?= $statsC['classes'] ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Avec BD/BC/RD/RC</p>
            <p class="text-2xl font-bold text-emerald-400"><?= number_format($statsC['bd_ok']) ?></p>
        </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="bg-slate-800 border border-slate-700 rounded-xl p-4 mb-5">
        <input type="hidden" name="tab" value="gestion">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-48">
                <label class="block text-xs text-slate-400 mb-1"><i class="fas fa-search mr-1"></i>Rechercher</label>
                <input type="text" name="search2" value="<?= htmlspecialchars($search2) ?>"
                       placeholder="Numéro ou libellé..."
                       class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-100 focus:ring-2 focus:ring-violet-500">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Classe</label>
                <select name="classe2" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-100 focus:ring-2 focus:ring-violet-500">
                    <option value="">Toutes</option>
                    <?php foreach ($classeLabels as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $classe2==$k?'selected':'' ?>>Classe <?= $k ?> - <?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Type</label>
                <select name="type_f" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-100 focus:ring-2 focus:ring-violet-500">
                    <option value="">Tous</option>
                    <?php foreach ($typeOptions as $t): ?>
                    <option value="<?= $t ?>" <?= $type_f===$t?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-violet-700 hover:bg-violet-600 text-white rounded-lg text-sm transition">
                <i class="fas fa-filter mr-1"></i>Filtrer
            </button>
            <a href="?tab=gestion" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm transition">
                <i class="fas fa-times mr-1"></i>Réinit.
            </a>
        </div>
    </form>

    <!-- Tableau gestion -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-700 flex items-center justify-between">
            <p class="text-sm text-slate-400">
                <span class="text-slate-200 font-medium"><?= number_format($totalC) ?></span> compte(s)
                - page <span class="text-slate-200 font-medium"><?= $page2 ?></span> / <?= $totalPagesC ?>
            </p>
            <p class="text-xs text-amber-400"><i class="fas fa-pencil mr-1"></i>Cliquez sur un compte pour le modifier</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-20">Compte</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-20">Classe</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs">Libellé</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-32">Type</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-20">Tableau</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BC</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RC</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($comptesC)): ?>
                    <tr><td colspan="10" class="px-6 py-8 text-center text-slate-500"><i class="fas fa-search mr-2"></i>Aucun résultat</td></tr>
                <?php endif; ?>
                <?php foreach ($comptesC as $c):
                    $tc = $c['type_compte'] ?? '';
                    $tClass = $typeColors[$tc] ?? 'bg-slate-700/40 text-slate-400';
                ?>
                <tr class="border-b border-slate-700/30 cursor-pointer hover:bg-slate-700/20 transition"
                    onclick="openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">
                    <td class="px-4 py-1.5">
                        <span class="font-mono font-bold text-amber-300"><?= $c['compte'] ?></span>
                    </td>
                    <td class="px-4 py-1.5 text-xs text-slate-400">
                        <?= $c['classe'] ?> - <?= $classeLabels[(int)$c['classe']] ?? '' ?>
                    </td>
                    <td class="px-4 py-1.5 text-slate-200"><?= htmlspecialchars($c['libelle']) ?></td>
                    <td class="px-4 py-1.5">
                        <?php if ($tc): ?>
                        <span class="badge <?= $tClass ?>"><?= htmlspecialchars($tc) ?></span>
                        <?php else: ?><span class="text-slate-600 text-xs">-</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs text-slate-400 font-mono">
                        <?= htmlspecialchars($c['tableau'] ?? '') ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-sky-300">
                        <?= $c['bd'] ? htmlspecialchars($c['bd']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-emerald-300">
                        <?= $c['bc'] ? htmlspecialchars($c['bc']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-amber-300">
                        <?= $c['rd'] ? htmlspecialchars($c['rd']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs font-mono font-semibold text-rose-300">
                        <?= $c['rc'] ? htmlspecialchars($c['rc']) : '<span class="text-slate-700">-</span>' ?>
                    </td>
                    <td class="px-2 py-1.5 text-center">
                        <i class="fas fa-pencil text-slate-600 hover:text-violet-400 text-xs transition"></i>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination onglet 2 -->
    <?php if ($totalPagesC > 1):
        $qpC = ['tab=gestion'];
        if ($search2!='') $qpC[]='search2='.urlencode($search2);
        if ($classe2!='') $qpC[]='classe2='.urlencode($classe2);
        if ($type_f!='')  $qpC[]='type_f='.urlencode($type_f);
        $baseC = 'referentiel_ohada.php?'.implode('&',$qpC).'&';
    ?>
    <div class="flex items-center justify-between mt-4 px-4 py-3 bg-slate-800/30 border border-slate-700/50 rounded-lg">
        <p class="text-sm text-slate-400">Page <?= $page2 ?> / <?= $totalPagesC ?></p>
        <div class="flex gap-2">
            <?php if ($page2>1): ?>
            <a href="<?= $baseC ?>page2=1" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm text-white">&laquo;</a>
            <a href="<?= $baseC ?>page2=<?= $page2-1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm text-white">Préc.</a>
            <?php endif; ?>
            <?php for ($i=max(1,$page2-2);$i<=min($totalPagesC,$page2+2);$i++): ?>
            <a href="<?= $baseC ?>page2=<?= $i ?>"
               class="px-3 py-1.5 rounded text-sm <?= $i===$page2?'bg-violet-600 text-white':'bg-slate-700 hover:bg-slate-600 text-white' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
            <?php if ($page2<$totalPagesC): ?>
            <a href="<?= $baseC ?>page2=<?= $page2+1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm text-white">Suiv.</a>
            <a href="<?= $baseC ?>page2=<?= $totalPagesC ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm text-white">&raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // isAdmin ?>
    <?php endif; // tab ?>

</main>
</div>

<?php if ($isAdmin): ?>
<!-- Modal édition -->
<div id="editModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-slate-800 border border-slate-600 rounded-2xl w-full max-w-lg mx-4 shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-700">
            <h2 class="font-semibold text-slate-100">
                <i class="fas fa-pencil text-violet-400 mr-2"></i>
                Modifier le compte <span id="modalCompte" class="font-mono text-amber-300"></span>
            </h2>
            <button onclick="closeEdit()" class="text-slate-400 hover:text-white text-xl transition">&times;</button>
        </div>
        <form method="POST" class="px-6 py-5 space-y-4">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">

            <div>
                <label class="block text-xs text-slate-400 mb-1">Libellé <span class="text-rose-400">*</span></label>
                <input type="text" name="libelle" id="editLibelle" required
                       class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-violet-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Type de compte</label>
                    <select name="type_compte" id="editType"
                            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-violet-500">
                        <option value="">- Aucun -</option>
                        <?php foreach ($typeOptions as $t): ?>
                        <option value="<?= $t ?>"><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Tableau</label>
                    <select name="tableau" id="editTableau"
                            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-violet-500">
                        <option value="Bilan">Bilan</option>
                        <option value="Compte de résultat">Compte de résultat</option>
                        <option value="ND">ND</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-2">Rubriques BD / BC / RD / RC</label>
                <div class="grid grid-cols-4 gap-2">
                    <div>
                        <label class="block text-xs text-sky-400 mb-1">BD</label>
                        <input type="text" name="bd" id="editBd" maxlength="10"
                               class="w-full px-2 py-1.5 bg-slate-900 border border-slate-600 rounded text-sky-300 text-sm font-mono text-center focus:ring-2 focus:ring-sky-500"
                               placeholder="ex: CA">
                    </div>
                    <div>
                        <label class="block text-xs text-emerald-400 mb-1">BC</label>
                        <input type="text" name="bc" id="editBc" maxlength="10"
                               class="w-full px-2 py-1.5 bg-slate-900 border border-slate-600 rounded text-emerald-300 text-sm font-mono text-center focus:ring-2 focus:ring-emerald-500"
                               placeholder="ex: CA">
                    </div>
                    <div>
                        <label class="block text-xs text-amber-400 mb-1">RD</label>
                        <input type="text" name="rd" id="editRd" maxlength="10"
                               class="w-full px-2 py-1.5 bg-slate-900 border border-slate-600 rounded text-amber-300 text-sm font-mono text-center focus:ring-2 focus:ring-amber-500"
                               placeholder="ex: RA">
                    </div>
                    <div>
                        <label class="block text-xs text-rose-400 mb-1">RC</label>
                        <input type="text" name="rc" id="editRc" maxlength="10"
                               class="w-full px-2 py-1.5 bg-slate-900 border border-slate-600 rounded text-rose-300 text-sm font-mono text-center focus:ring-2 focus:ring-rose-500"
                               placeholder="ex: RA">
                    </div>
                </div>
            </div>

            <div class="flex items-start gap-3 bg-amber-900/20 border border-amber-700/30 rounded-lg p-3">
                <input type="checkbox" name="propager" id="editPropager" value="1"
                       class="mt-0.5 w-4 h-4 rounded accent-amber-500">
                <label for="editPropager" class="text-sm text-amber-200 cursor-pointer">
                    <strong>Propager au plan comptable</strong><br>
                    <span class="text-xs text-amber-400">Met à jour les comptes existants dans le plan comptable de la société courante qui partagent la même racine 4 chiffres.</span>
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-1">
                <button type="button" onclick="closeEdit()"
                        class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm transition">
                    Annuler
                </button>
                <button type="submit"
                        class="px-5 py-2 bg-gradient-to-r from-violet-600 to-violet-700 hover:from-violet-700 hover:to-violet-800 text-white rounded-lg text-sm font-semibold transition">
                    <i class="fas fa-save mr-1"></i>Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function openEdit(c) {
    document.getElementById('editId').value      = c.id;
    document.getElementById('modalCompte').textContent = c.compte;
    document.getElementById('editLibelle').value = c.libelle || '';
    document.getElementById('editType').value    = c.type_compte || '';
    document.getElementById('editTableau').value = c.tableau || 'Bilan';
    document.getElementById('editBd').value      = c.bd || '';
    document.getElementById('editBc').value      = c.bc || '';
    document.getElementById('editRd').value      = c.rd || '';
    document.getElementById('editRc').value      = c.rc || '';
    document.getElementById('editPropager').checked = false;
    document.getElementById('editModal').classList.remove('hidden');
}
function closeEdit() {
    document.getElementById('editModal').classList.add('hidden');
}
document.getElementById('editModal').addEventListener('click', function(e) { if(e.target===this) closeEdit(); });
document.addEventListener('keydown', function(e) { if(e.key==='Escape') closeEdit(); });
</script>
<?php endif; ?>

<script>
anime({ targets: '#sidebar', opacity:[0,1], duration:600, easing:'easeOutQuad' });
</script>
</body>
</html>
