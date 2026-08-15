<?php
require_once '../../config/config.php';
requireLogin();

$db         = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

// POST : changer statut
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'envoyer') {
        $db->prepare("UPDATE devis_clients SET statut='Envoye' WHERE id=? AND societe_id=? AND statut='Brouillon'")->execute([$id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Devis marqué comme envoyé.'];
    } elseif ($action === 'accepter') {
        $db->prepare("UPDATE devis_clients SET statut='Accepte', date_decision=CURDATE(), decide_par=? WHERE id=? AND societe_id=? AND statut='Envoye'")->execute([trim($_POST['decide_par'] ?? ''), $id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Devis accepté.'];
    } elseif ($action === 'refuser') {
        $db->prepare("UPDATE devis_clients SET statut='Refuse', date_decision=CURDATE(), decide_par=?, motif_refus=? WHERE id=? AND societe_id=? AND statut='Envoye'")->execute([trim($_POST['decide_par'] ?? ''), trim($_POST['motif_refus'] ?? ''), $id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Devis marqué refusé.'];
    } elseif ($action === 'supprimer') {
        $db->prepare("DELETE FROM devis_clients WHERE id=? AND societe_id=? AND statut='Brouillon'")->execute([$id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Devis supprimé.'];
    }
    header('Location: devis_clients.php'); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Filtres
$filterClient = $_GET['client']  ?? '';
$filterStatut = $_GET['statut']  ?? '';
$dateDebut    = $_GET['date_debut'] ?? date('Y-01-01');
$dateFin      = $_GET['date_fin']   ?? date('Y-12-31');

$stmtCli = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Client' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtCli->execute([$societe_id]);
$clients = $stmtCli->fetchAll();

$sql    = "SELECT d.*, pt.nom AS client_nom FROM devis_clients d JOIN plan_tiers pt ON d.id_client=pt.id WHERE d.societe_id=? AND d.date_devis BETWEEN ? AND ?";
$params = [$societe_id, $dateDebut, $dateFin];
if ($filterClient) { $sql .= " AND d.id_client=?"; $params[] = $filterClient; }
if ($filterStatut) { $sql .= " AND d.statut=?";   $params[] = $filterStatut; }
$sql .= " ORDER BY d.date_devis DESC, d.numero_devis DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$devis = $stmt->fetchAll();

// Stats
$nbBrouillon = $nbEnvoye = $nbAccepte = 0;
$montantEnCours = 0;
foreach ($devis as $d) {
    if ($d['statut'] === 'Brouillon') $nbBrouillon++;
    if ($d['statut'] === 'Envoye')    { $nbEnvoye++; $montantEnCours += $d['montant_ttc']; }
    if ($d['statut'] === 'Accepte')   { $nbAccepte++; $montantEnCours += $d['montant_ttc']; }
}

$statutCls = [
    'Brouillon' => 'bg-slate-700 text-slate-300',
    'Envoye'    => 'bg-blue-500/20 text-blue-300',
    'Accepte'   => 'bg-green-500/20 text-green-300',
    'Refuse'    => 'bg-red-500/20 text-red-400',
    'Expire'    => 'bg-slate-600 text-slate-400',
    'Converti'  => 'bg-purple-500/20 text-purple-300',
];
$statutLbl = [
    'Brouillon' => 'Brouillon', 'Envoye' => 'Envoyé', 'Accepte' => 'Accepté',
    'Refuse' => 'Refusé', 'Expire' => 'Expiré', 'Converti' => 'Converti BC',
];

$pageTitle = "Devis clients";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> - OHADA ERP</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
  <?php include '../../includes/sidebar.php'; ?>

  <main class="flex-1 overflow-y-auto p-8">

    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-teal-400 to-cyan-400">
          <i class="fas fa-file-alt mr-2"></i>Devis clients
        </h1>
        <p class="text-slate-400 text-sm mt-1">Propositions commerciales - Module AR</p>
      </div>
      <a href="devis_client_form.php" class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium transition">
        <i class="fas fa-plus"></i>Nouveau devis
      </a>
    </div>

    <?php if ($flash): ?>
      <div class="mb-5 p-4 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-500/10 border border-green-500/50 text-green-300' : 'bg-red-500/10 border border-red-500/50 text-red-300' ?>">
        <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-slate-800 rounded-xl p-4 border border-slate-700">
        <p class="text-xs text-slate-400 mb-1">Brouillons</p>
        <p class="text-2xl font-bold text-slate-300"><?= $nbBrouillon ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-4 border border-slate-700">
        <p class="text-xs text-slate-400 mb-1">Envoyés</p>
        <p class="text-2xl font-bold text-blue-400"><?= $nbEnvoye ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-4 border border-slate-700">
        <p class="text-xs text-slate-400 mb-1">Acceptés</p>
        <p class="text-2xl font-bold text-green-400"><?= $nbAccepte ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-4 border border-slate-700">
        <p class="text-xs text-slate-400 mb-1">Montant en cours</p>
        <p class="text-lg font-bold text-teal-400"><?= number_format($montantEnCours, 0, ',', ' ') ?> <span class="text-xs font-normal text-slate-500">F CFA</span></p>
      </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="bg-slate-800 border border-slate-700 rounded-xl p-4 mb-5 flex flex-wrap gap-3 items-end">
      <div class="flex flex-col gap-1">
        <label class="text-xs text-slate-400">Du</label>
        <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>"
               class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-teal-500 focus:outline-none">
      </div>
      <div class="flex flex-col gap-1">
        <label class="text-xs text-slate-400">Au</label>
        <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>"
               class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-teal-500 focus:outline-none">
      </div>
      <div class="flex flex-col gap-1">
        <label class="text-xs text-slate-400">Client</label>
        <select name="client" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-teal-500 focus:outline-none">
          <option value="">Tous</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex flex-col gap-1">
        <label class="text-xs text-slate-400">Statut</label>
        <select name="statut" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-teal-500 focus:outline-none">
          <option value="">Tous</option>
          <?php foreach ($statutLbl as $k => $v): ?>
            <option value="<?= $k ?>" <?= $filterStatut === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="px-4 py-1.5 bg-teal-600 text-white rounded-lg text-sm hover:bg-teal-700 transition">
        <i class="fas fa-filter mr-1"></i>Filtrer
      </button>
      <a href="devis_clients.php" class="px-4 py-1.5 border border-slate-600 text-slate-400 rounded-lg text-sm hover:bg-slate-700 transition">Reset</a>
    </form>

    <!-- Tableau -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
      <?php if (empty($devis)): ?>
        <div class="py-16 text-center">
          <i class="fas fa-file-alt text-4xl text-slate-600 mb-3"></i>
          <p class="text-slate-400 text-sm">Aucun devis pour la période.</p>
          <a href="devis_client_form.php" class="mt-3 inline-block text-teal-400 text-sm hover:underline">Créer un devis</a>
        </div>
      <?php else: ?>
        <table class="w-full text-sm">
          <thead class="border-b border-slate-700">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">N° Devis</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Client</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Objet</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Date</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Validité</th>
              <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase">TTC</th>
              <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Statut</th>
              <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/50">
            <?php foreach ($devis as $d): ?>
            <tr class="hover:bg-slate-700/30 transition">
              <td class="px-4 py-3 font-mono text-teal-300 text-xs font-semibold"><?= htmlspecialchars($d['numero_devis']) ?></td>
              <td class="px-4 py-3 text-slate-200"><?= htmlspecialchars($d['client_nom']) ?></td>
              <td class="px-4 py-3 text-slate-400 max-w-[180px] truncate"><?= htmlspecialchars($d['objet']) ?></td>
              <td class="px-4 py-3 text-slate-400 text-xs"><?= date('d/m/Y', strtotime($d['date_devis'])) ?></td>
              <td class="px-4 py-3 text-xs <?= $d['date_validite'] && $d['date_validite'] < date('Y-m-d') && !in_array($d['statut'], ['Accepte','Refuse','Converti','Expire']) ? 'text-red-400 font-semibold' : 'text-slate-400' ?>">
                <?= $d['date_validite'] ? date('d/m/Y', strtotime($d['date_validite'])) : '-' ?>
              </td>
              <td class="px-4 py-3 text-right text-slate-200 text-xs font-medium"><?= number_format($d['montant_ttc'], 0, ',', ' ') ?></td>
              <td class="px-4 py-3 text-center">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statutCls[$d['statut']] ?? 'bg-slate-700 text-slate-300' ?>">
                  <?= $statutLbl[$d['statut']] ?? $d['statut'] ?>
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-1">
                  <!-- Modifier (brouillon) ou Voir -->
                  <a href="devis_client_form.php?id=<?= $d['id'] ?><?= $d['statut'] !== 'Brouillon' ? '&view=1' : '' ?>"
                     class="p-1.5 rounded text-slate-400 hover:bg-slate-700 transition" title="<?= $d['statut'] === 'Brouillon' ? 'Modifier' : 'Voir' ?>">
                    <i class="fas <?= $d['statut'] === 'Brouillon' ? 'fa-pen' : 'fa-eye' ?> text-xs"></i>
                  </a>
                  <!-- Envoyer (brouillon) -->
                  <?php if ($d['statut'] === 'Brouillon'): ?>
                    <button type="button" onclick="submitAction(<?= $d['id'] ?>,'envoyer')"
                            class="p-1.5 rounded text-blue-400 hover:bg-slate-700 transition" title="Marquer envoyé">
                      <i class="fas fa-paper-plane text-xs"></i>
                    </button>
                    <button type="button" onclick="submitAction(<?= $d['id'] ?>,'supprimer')"
                            class="p-1.5 rounded text-red-400 hover:bg-slate-700 transition" title="Supprimer">
                      <i class="fas fa-trash text-xs"></i>
                    </button>
                  <?php endif; ?>
                  <!-- Accepter/Refuser (envoyé) -->
                  <?php if ($d['statut'] === 'Envoye'): ?>
                    <button type="button" onclick="openDecision(<?= $d['id'] ?>, 'accepter')"
                            class="p-1.5 rounded text-green-400 hover:bg-slate-700 transition" title="Accepter">
                      <i class="fas fa-check text-xs"></i>
                    </button>
                    <button type="button" onclick="openDecision(<?= $d['id'] ?>, 'refuser')"
                            class="p-1.5 rounded text-red-400 hover:bg-slate-700 transition" title="Refuser">
                      <i class="fas fa-times text-xs"></i>
                    </button>
                  <?php endif; ?>
                  <!-- Convertir en BC (accepté) -->
                  <?php if ($d['statut'] === 'Accepte'): ?>
                    <a href="bc_client_form.php?from_devis=<?= $d['id'] ?>"
                       class="p-1.5 rounded text-purple-400 hover:bg-slate-700 transition" title="Convertir en BC">
                      <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <p class="text-xs text-slate-500 mt-3"><?= count($devis) ?> devis - Montants en F CFA</p>

  </main>
</div>

<!-- Formulaires actions rapides -->
<form id="form-action" method="POST" class="hidden">
  <input type="hidden" name="action" id="fa-action">
  <input type="hidden" name="id"     id="fa-id">
</form>

<!-- Modal décision (accepter/refuser) -->
<div id="modal-decision" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-50">
  <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl p-6 w-full max-w-sm mx-4">
    <h3 id="md-title" class="text-base font-semibold text-slate-100 mb-4"></h3>
    <form method="POST">
      <input type="hidden" name="action" id="md-action">
      <input type="hidden" name="id"     id="md-id">
      <div class="mb-3">
        <label class="text-xs text-slate-400 mb-1 block">Décidé par</label>
        <input type="text" name="decide_par" placeholder="Nom du contact client"
               class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
      </div>
      <div id="md-motif-wrap" class="mb-3 hidden">
        <label class="text-xs text-slate-400 mb-1 block">Motif du refus</label>
        <textarea name="motif_refus" rows="2"
                  class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none resize-none"></textarea>
      </div>
      <div class="flex gap-3 justify-end">
        <button type="button" onclick="document.getElementById('modal-decision').classList.replace('flex','hidden')"
                class="px-4 py-2 border border-slate-600 rounded-lg text-sm text-slate-400 hover:bg-slate-700 transition">Annuler</button>
        <button type="submit" id="md-submit" class="px-4 py-2 text-white rounded-lg text-sm transition"></button>
      </div>
    </form>
  </div>
</div>

<script>
function submitAction(id, action) {
  if (action === 'supprimer' && !confirm('Supprimer ce devis ?')) return;
  document.getElementById('fa-id').value     = id;
  document.getElementById('fa-action').value = action;
  document.getElementById('form-action').submit();
}
function openDecision(id, action) {
  document.getElementById('md-id').value     = id;
  document.getElementById('md-action').value = action;
  const isRefus = action === 'refuser';
  document.getElementById('md-title').textContent   = isRefus ? 'Marquer le devis refusé' : 'Marquer le devis accepté';
  document.getElementById('md-motif-wrap').classList.toggle('hidden', !isRefus);
  const btn = document.getElementById('md-submit');
  btn.textContent  = isRefus ? 'Confirmer refus' : 'Confirmer acceptation';
  btn.className    = 'px-4 py-2 text-white rounded-lg text-sm transition ' + (isRefus ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700');
  const modal = document.getElementById('modal-decision');
  modal.classList.replace('hidden','flex');
}
</script>
</body>
</html>
