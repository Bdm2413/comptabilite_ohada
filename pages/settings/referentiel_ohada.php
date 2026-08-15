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
    $action = $_POST['action'] ?? '';

    if ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $libelle   = trim($_POST['libelle'] ?? '');
        $type      = $_POST['type_compte'] ?? null;
        $tableau   = trim($_POST['tableau'] ?? '');
        $bd        = trim($_POST['bd'] ?? '') ?: null;
        $bc        = trim($_POST['bc'] ?? '') ?: null;
        $rd        = trim($_POST['rd'] ?? '') ?: null;
        $rc        = trim($_POST['rc'] ?? '') ?: null;
        $propager  = !empty($_POST['propager']);

        if ($id <= 0 || empty($libelle)) {
            $message     = "Données invalides.";
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Récupérer le compte avant modification
                $avant = $db->prepare("SELECT * FROM table_correspondance WHERE id = ?");
                $avant->execute([$id]);
                $avant = $avant->fetch();

                // Mettre à jour le référentiel
                $db->prepare("
                    UPDATE table_correspondance
                    SET libelle = ?, type_compte = ?, tableau = ?, bd = ?, bc = ?, rd = ?, rc = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$libelle, $type ?: null, $tableau, $bd, $bc, $rd, $rc, $id]);

                // Propagation optionnelle vers plan_comptable
                if ($propager && $avant && $societeId) {
                    $compte4 = (int)$avant['compte'];
                    $db->prepare("
                        UPDATE plan_comptable
                        SET intitule_compte = ?, type = ?, tableau = ?, bd = ?, bc = ?, rd = ?, rc = ?, updated_at = NOW()
                        WHERE societe_id = ? AND quatre_chiffres = ?
                    ")->execute([$libelle, $type ?: null, $tableau, $bd, $bc, $rd, $rc, $societeId, $compte4]);
                }

                $db->commit();
                $message     = "Compte mis à jour" . ($propager ? " et propagé au plan comptable." : ".");
                $messageType = 'success';

            } catch (Exception $e) {
                $db->rollBack();
                $message     = "Erreur : " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// ─── Filtres ──────────────────────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$classe  = $_GET['classe'] ?? '';
$type_f  = $_GET['type_f'] ?? '';

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = "(compte LIKE ? OR libelle LIKE ?)";
    $s        = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s]);
}
if ($classe !== '') { $where[] = "classe = ?"; $params[] = $classe; }
if ($type_f !== '') { $where[] = "type_compte = ?"; $params[] = $type_f; }

// Stats
$stats = [
    'total'   => $db->query("SELECT COUNT(*) FROM table_correspondance")->fetchColumn(),
    'bd_ok'   => $db->query("SELECT COUNT(*) FROM table_correspondance WHERE bd IS NOT NULL")->fetchColumn(),
    'classes' => $db->query("SELECT COUNT(DISTINCT classe) FROM table_correspondance")->fetchColumn(),
];

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$sqlCount = "SELECT COUNT(*) FROM table_correspondance WHERE " . implode(' AND ', $where);
$stmtC    = $db->prepare($sqlCount);
$stmtC->execute($params);
$totalFiltre = (int)$stmtC->fetchColumn();
$totalPages  = max(1, (int)ceil($totalFiltre / $perPage));
$page        = min($page, $totalPages);

$sql   = "SELECT * FROM table_correspondance WHERE " . implode(' AND ', $where)
       . " ORDER BY compte LIMIT ? OFFSET ?";
$stmt  = $db->prepare($sql);
$stmt->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classeLabels = [
    1 => 'Ressources durables',
    2 => 'Actif immobilisé',
    3 => 'Stocks',
    4 => 'Tiers',
    5 => 'Trésorerie',
    6 => 'Charges AO',
    7 => 'Produits AO',
    8 => 'Charges/Produits HAO',
];

$typeOptions = [
    'Capitaux','Immobilisation','Amortis/Provision','Stock',
    'Client','Fournisseur','Salarié','CNPS','Etat',
    'Banque','Caisse','Titre',
    'Charge','Produit','Résultat-Gestion','Résultat-Bilan','Autres',
];

$typeColors = [
    'Capitaux'          => 'bg-violet-900/40 text-violet-300',
    'Immobilisation'    => 'bg-sky-900/40 text-sky-300',
    'Amortis/Provision' => 'bg-slate-700/60 text-slate-400',
    'Stock'             => 'bg-amber-900/40 text-amber-300',
    'Client'            => 'bg-emerald-900/40 text-emerald-300',
    'Fournisseur'       => 'bg-rose-900/40 text-rose-300',
    'Salarié'           => 'bg-pink-900/40 text-pink-300',
    'CNPS'              => 'bg-orange-900/40 text-orange-300',
    'Etat'              => 'bg-red-900/40 text-red-300',
    'Banque'            => 'bg-blue-900/40 text-blue-300',
    'Caisse'            => 'bg-teal-900/40 text-teal-300',
    'Titre'             => 'bg-indigo-900/40 text-indigo-300',
    'Charge'            => 'bg-red-900/30 text-red-400',
    'Produit'           => 'bg-green-900/30 text-green-400',
    'Résultat-Gestion'  => 'bg-yellow-900/40 text-yellow-300',
    'Résultat-Bilan'    => 'bg-purple-900/40 text-purple-300',
    'Autres'            => 'bg-slate-700/40 text-slate-400',
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
    <style>
        tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { display:inline-block; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-6">

    <!-- En-tête -->
    <div class="mb-5 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-sky-400 mb-1">
                <i class="fas fa-book mr-2"></i>Référentiel SYSCOHADA Révisé
            </h1>
            <p class="text-slate-400 text-sm">
                Source canonique des BD/BC/RD/RC - utilisée lors de la création de nouveaux comptes
                <?php if ($isAdmin): ?>
                <span class="ml-2 px-2 py-0.5 bg-amber-900/40 text-amber-300 rounded text-xs font-medium">
                    <i class="fas fa-shield-alt mr-1"></i>Mode admin - édition activée
                </span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
        <?= $messageType === 'success' ? 'bg-emerald-900/40 border border-emerald-700/50 text-emerald-300' : 'bg-rose-900/40 border border-rose-700/50 text-rose-300' ?>">
        <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?> mr-2"></i>
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Comptes référentiel</p>
            <p class="text-2xl font-bold text-violet-400"><?= number_format($stats['total']) ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Classes couvertes</p>
            <p class="text-2xl font-bold text-sky-400"><?= $stats['classes'] ?></p>
        </div>
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p class="text-xs text-slate-400 mb-1">Avec BD/BC/RD/RC</p>
            <p class="text-2xl font-bold text-emerald-400"><?= number_format($stats['bd_ok']) ?></p>
        </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="bg-slate-800 border border-slate-700 rounded-xl p-4 mb-5">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-48">
                <label class="block text-xs text-slate-400 mb-1"><i class="fas fa-search mr-1"></i>Rechercher</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Numéro ou libellé..."
                       class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-100 focus:ring-2 focus:ring-violet-500">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Classe</label>
                <select name="classe" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-100 focus:ring-2 focus:ring-violet-500">
                    <option value="">Toutes</option>
                    <?php foreach ($classeLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $classe==$k?'selected':'' ?>>Classe <?= $k ?> - <?= $v ?></option>
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
            <a href="referentiel_ohada.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm transition">
                <i class="fas fa-times mr-1"></i>Réinit.
            </a>
        </div>
    </form>

    <!-- Tableau -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-700 flex items-center justify-between">
            <p class="text-sm text-slate-400">
                <span class="text-slate-200 font-medium"><?= number_format($totalFiltre) ?></span> compte(s)
                - page <span class="text-slate-200 font-medium"><?= $page ?></span> / <?= $totalPages ?>
            </p>
            <?php if ($isAdmin): ?>
            <p class="text-xs text-amber-400"><i class="fas fa-pencil mr-1"></i>Cliquez sur un compte pour le modifier</p>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-20">Compte</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-20">Classe</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs">Libellé</th>
                        <th class="text-left px-4 py-2 text-slate-400 text-xs w-32">Type</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-16">Tableau</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">BC</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RD</th>
                        <th class="text-center px-3 py-2 text-slate-400 text-xs w-12">RC</th>
                        <?php if ($isAdmin): ?>
                        <th class="w-10"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($comptes)): ?>
                    <tr><td colspan="<?= $isAdmin ? 10 : 9 ?>" class="px-6 py-8 text-center text-slate-500">
                        <i class="fas fa-search mr-2"></i>Aucun résultat
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($comptes as $c):
                    $tc     = $c['type_compte'] ?? '';
                    $tClass = $typeColors[$tc] ?? 'bg-slate-700/40 text-slate-400';
                    $cl     = (int)$c['classe'];
                ?>
                <tr class="border-b border-slate-700/30"
                    <?php if ($isAdmin): ?>
                    onclick="openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"
                    style="cursor:pointer"
                    <?php endif; ?>>
                    <td class="px-4 py-1.5">
                        <span class="font-mono font-bold text-amber-300"><?= $c['compte'] ?></span>
                    </td>
                    <td class="px-4 py-1.5 text-xs text-slate-400">
                        <?= $cl ?> - <?= $classeLabels[$cl] ?? '' ?>
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
                    <?php if ($isAdmin): ?>
                    <td class="px-2 py-1.5 text-center">
                        <i class="fas fa-pencil text-slate-600 hover:text-violet-400 text-xs transition"></i>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $qp = [];
        if ($search  !== '') $qp[] = 'search='  . urlencode($search);
        if ($classe  !== '') $qp[] = 'classe='  . urlencode($classe);
        if ($type_f  !== '') $qp[] = 'type_f='  . urlencode($type_f);
        $base = 'referentiel_ohada.php?' . implode('&', $qp) . (empty($qp)?'':'&');
    ?>
    <div class="flex items-center justify-between mt-4 px-4 py-3 bg-slate-800/30 border border-slate-700/50 rounded-lg">
        <p class="text-sm text-slate-400">Page <?= $page ?> / <?= $totalPages ?></p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="<?= $base ?>page=1" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm transition text-white">&laquo;</a>
            <a href="<?= $base ?>page=<?= $page-1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm transition text-white">Préc.</a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <a href="<?= $base ?>page=<?= $i ?>"
               class="px-3 py-1.5 rounded text-sm transition <?= $i===$page?'bg-violet-600 text-white':'bg-slate-700 hover:bg-slate-600 text-white' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= $base ?>page=<?= $page+1 ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm transition text-white">Suiv.</a>
            <a href="<?= $base ?>page=<?= $totalPages ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm transition text-white">&raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-xs text-slate-600 mt-3 text-right">
        Source : table_correspondance - SYSCOHADA Révisé 2017 - <?= number_format($stats['total']) ?> sous-comptes
    </p>

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
            <button onclick="closeEdit()" class="text-slate-400 hover:text-white transition text-xl">&times;</button>
        </div>

        <form method="POST" class="px-6 py-5 space-y-4">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">

            <!-- Libellé -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">Libellé <span class="text-rose-400">*</span></label>
                <input type="text" name="libelle" id="editLibelle" required
                       class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-violet-500">
            </div>

            <!-- Type + Tableau -->
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

            <!-- BD BC RD RC -->
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

            <!-- Propagation -->
            <div class="flex items-start gap-3 bg-amber-900/20 border border-amber-700/30 rounded-lg p-3">
                <input type="checkbox" name="propager" id="editPropager" value="1"
                       class="mt-0.5 w-4 h-4 rounded accent-amber-500">
                <label for="editPropager" class="text-sm text-amber-200 cursor-pointer">
                    <strong>Propager au plan comptable</strong><br>
                    <span class="text-xs text-amber-400">Met à jour les comptes existants dans le plan comptable de la société courante qui ont la même racine 4 chiffres.</span>
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
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEdit();
});
</script>
<?php endif; ?>

</body>
</html>
