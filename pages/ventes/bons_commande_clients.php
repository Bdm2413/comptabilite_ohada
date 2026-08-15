<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'annuler') {
        $db->prepare("UPDATE bons_commande_clients SET statut='Annule' WHERE id=? AND societe_id=? AND statut='En cours'")->execute([$id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'BC annule.'];
    } elseif ($action === 'marquer_livre') {
        $db->prepare("UPDATE bons_commande_clients SET statut='Livre' WHERE id=? AND societe_id=? AND statut IN ('En cours','Livre partiellement')")->execute([$id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'BC marque comme livre.'];
    } elseif ($action === 'marquer_partiel') {
        $db->prepare("UPDATE bons_commande_clients SET statut='Livre partiellement' WHERE id=? AND societe_id=? AND statut='En cours'")->execute([$id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'BC marque livre partiellement.'];
    }
    header('Location: bons_commande_clients.php'); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$filterClient = $_GET['client'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$dateDebut    = $_GET['date_debut'] ?? date('Y-01-01');
$dateFin      = $_GET['date_fin']   ?? date('Y-12-31');

$stmtCli = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Client' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtCli->execute([$societe_id]);
$clients = $stmtCli->fetchAll();

$where  = ['b.societe_id=?', 'b.date_bc BETWEEN ? AND ?'];
$params = [$societe_id, $dateDebut, $dateFin];
if ($filterClient) { $where[] = 'b.id_client=?'; $params[] = (int)$filterClient; }
if ($filterStatut) { $where[] = 'b.statut=?';    $params[] = $filterStatut; }

$stmt = $db->prepare("SELECT b.*, pt.nom AS client_nom FROM bons_commande_clients b JOIN plan_tiers pt ON b.id_client=pt.id WHERE " . implode(' AND ', $where) . " ORDER BY b.date_bc DESC, b.id DESC");
$stmt->execute($params);
$bcs = $stmt->fetchAll();

$statsStmt = $db->prepare("SELECT COUNT(*) AS total, SUM(statut='En cours') AS en_cours, SUM(statut='Livre partiellement') AS partiel, SUM(statut='Livre') AS livre, SUM(statut='Facture') AS facture, SUM(CASE WHEN statut NOT IN ('Annule','Facture') THEN montant_ttc ELSE 0 END) AS montant_actif FROM bons_commande_clients WHERE societe_id=?");
$statsStmt->execute([$societe_id]);
$stats = $statsStmt->fetch();

function statutBadgeBC(string $s): string {
    return match($s) {
        'En cours'            => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-500/20 text-blue-300">En cours</span>',
        'Livre partiellement' => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/20 text-yellow-300">Livre partiel.</span>',
        'Livre'               => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-500/20 text-green-300">Livre</span>',
        'Annule'              => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-400">Annule</span>',
        'Facture'             => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-purple-500/20 text-purple-300">Facture</span>',
        default               => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-300">' . htmlspecialchars($s) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bons de commande clients</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100 h-full">
<div class="flex h-screen overflow-hidden">
  <?php include '../../includes/sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto p-8">

    <?php if ($flash): ?>
    <div class="mb-4 px-4 py-3 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30' ?>">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">Bons de commande clients</h1>
        <p class="text-slate-400 mt-1">Commandes recues des clients (BC recu)</p>
      </div>
      <a href="bc_client_form.php" class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors text-sm font-medium">
        <i class="fas fa-plus text-xs"></i> Nouveau BC
      </a>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Total BCs</p>
        <p class="text-2xl font-bold text-slate-100"><?= (int)$stats['total'] ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">En cours</p>
        <p class="text-2xl font-bold text-blue-400"><?= (int)$stats['en_cours'] ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Livres</p>
        <p class="text-2xl font-bold text-green-400"><?= (int)$stats['livre'] ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Montant actif</p>
        <p class="text-2xl font-bold text-slate-100"><?= number_format((float)$stats['montant_actif'], 0, ',', ' ') ?> <span class="text-sm text-slate-400">FCFA</span></p>
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-slate-800 rounded-xl p-4 border border-slate-700 mb-6 flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-40">
        <label class="text-slate-400 text-xs block mb-1">Client</label>
        <select name="client" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
          <option value="">Tous les clients</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex-1 min-w-36">
        <label class="text-slate-400 text-xs block mb-1">Statut</label>
        <select name="statut" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
          <option value="">Tous</option>
          <?php foreach (['En cours', 'Livre partiellement', 'Livre', 'Annule', 'Facture'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatut === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-slate-400 text-xs block mb-1">Du</label>
        <input type="date" name="date_debut" value="<?= $dateDebut ?>" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
      </div>
      <div>
        <label class="text-slate-400 text-xs block mb-1">Au</label>
        <input type="date" name="date_fin" value="<?= $dateFin ?>" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
      </div>
      <div>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">Filtrer</button>
      </div>
    </form>

    <!-- Table -->
    <div class="bg-slate-800 rounded-xl border border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase">
            <th class="px-4 py-3 text-left">Numero BC</th>
            <th class="px-4 py-3 text-left">Client</th>
            <th class="px-4 py-3 text-left">Ref. client</th>
            <th class="px-4 py-3 text-left">Date BC</th>
            <th class="px-4 py-3 text-left">Date livraison</th>
            <th class="px-4 py-3 text-right">Montant TTC</th>
            <th class="px-4 py-3 text-center">Statut</th>
            <th class="px-4 py-3 text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($bcs)): ?>
          <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">Aucun bon de commande trouve.</td></tr>
          <?php endif; ?>
          <?php foreach ($bcs as $bc): ?>
          <tr class="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 font-mono font-semibold text-indigo-400"><?= htmlspecialchars($bc['numero_bc']) ?></td>
            <td class="px-4 py-3 text-slate-200"><?= htmlspecialchars($bc['client_nom']) ?></td>
            <td class="px-4 py-3 text-slate-400"><?= $bc['reference_client'] ? htmlspecialchars($bc['reference_client']) : '-' ?></td>
            <td class="px-4 py-3 text-slate-300"><?= date('d/m/Y', strtotime($bc['date_bc'])) ?></td>
            <td class="px-4 py-3 text-slate-300"><?= $bc['date_livraison'] ? date('d/m/Y', strtotime($bc['date_livraison'])) : '-' ?></td>
            <td class="px-4 py-3 text-right font-semibold text-slate-200"><?= number_format((float)$bc['montant_ttc'], 0, ',', ' ') ?></td>
            <td class="px-4 py-3 text-center"><?= statutBadgeBC($bc['statut']) ?></td>
            <td class="px-4 py-3 text-center">
              <div class="flex items-center justify-center gap-1">
                <a href="bc_client_form.php?id=<?= $bc['id'] ?>&view=1" class="p-1.5 rounded text-slate-400 hover:text-white hover:bg-slate-600 transition-colors" title="Voir"><i class="fas fa-eye text-xs"></i></a>
                <?php if ($bc['statut'] === 'En cours'): ?>
                <a href="bc_client_form.php?id=<?= $bc['id'] ?>" class="p-1.5 rounded text-slate-400 hover:text-white hover:bg-slate-600 transition-colors" title="Modifier"><i class="fas fa-edit text-xs"></i></a>
                <button onclick="openAction(<?= $bc['id'] ?>, 'partiel')" class="p-1.5 rounded text-slate-400 hover:text-yellow-300 hover:bg-yellow-500/10 transition-colors" title="Livre partiellement"><i class="fas fa-truck-loading text-xs"></i></button>
                <button onclick="openAction(<?= $bc['id'] ?>, 'livre')" class="p-1.5 rounded text-slate-400 hover:text-green-300 hover:bg-green-500/10 transition-colors" title="Marquer livre"><i class="fas fa-check text-xs"></i></button>
                <button onclick="openAction(<?= $bc['id'] ?>, 'annuler')" class="p-1.5 rounded text-slate-400 hover:text-red-400 hover:bg-red-500/10 transition-colors" title="Annuler"><i class="fas fa-times text-xs"></i></button>
                <?php elseif ($bc['statut'] === 'Livre partiellement'): ?>
                <button onclick="openAction(<?= $bc['id'] ?>, 'livre')" class="p-1.5 rounded text-slate-400 hover:text-green-300 hover:bg-green-500/10 transition-colors" title="Marquer livre"><i class="fas fa-check text-xs"></i></button>
                <?php elseif ($bc['statut'] === 'Livre'): ?>
                <a href="facture_client_form.php?from_bc=<?= $bc['id'] ?>" class="p-1.5 rounded text-slate-400 hover:text-purple-300 hover:bg-purple-500/10 transition-colors" title="Creer facture"><i class="fas fa-file-invoice text-xs"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

<!-- Action Modal -->
<div id="modalAction" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden">
  <div class="bg-slate-800 rounded-xl p-6 w-full max-w-sm border border-slate-700 shadow-xl">
    <h3 id="modalTitle" class="text-lg font-semibold text-slate-100 mb-3"></h3>
    <p id="modalText" class="text-slate-400 mb-6 text-sm"></p>
    <form method="POST" id="formAction">
      <input type="hidden" name="id" id="actionId">
      <input type="hidden" name="action" id="actionType">
      <div class="flex gap-3 justify-end">
        <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm transition-colors">Annuler</button>
        <button type="submit" id="modalSubmit" class="px-4 py-2 rounded-lg text-white text-sm font-medium transition-colors">Confirmer</button>
      </div>
    </form>
  </div>
</div>

<script>
const actionCfg = {
  livre:   { title: 'Marquer comme livre',     text: 'Confirmer la livraison complete de ce BC ?',         cls: 'bg-green-600 hover:bg-green-700',  act: 'marquer_livre'   },
  partiel: { title: 'Livraison partielle',      text: 'Marquer ce BC comme partiellement livre ?',          cls: 'bg-yellow-600 hover:bg-yellow-700',act: 'marquer_partiel' },
  annuler: { title: 'Annuler le BC',            text: 'Annuler definitivement ce bon de commande ?',        cls: 'bg-red-600 hover:bg-red-700',      act: 'annuler'         },
};
function openAction(id, type) {
  const c = actionCfg[type];
  document.getElementById('actionId').value   = id;
  document.getElementById('actionType').value  = c.act;
  document.getElementById('modalTitle').textContent = c.title;
  document.getElementById('modalText').textContent  = c.text;
  document.getElementById('modalSubmit').className  = 'px-4 py-2 rounded-lg text-white text-sm font-medium transition-colors ' + c.cls;
  document.getElementById('modalAction').classList.remove('hidden');
}
function closeModal() { document.getElementById('modalAction').classList.add('hidden'); }
document.getElementById('modalAction').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
</script>
</body>
</html>
