<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'annuler') {
        $stmt = $db->prepare("UPDATE factures_clients SET statut='annulee' WHERE id=? AND societe_id=? AND statut IN ('brouillon','envoyee')");
        $stmt->execute([(int)$_POST['id'], $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Facture annulée.'];
        header('Location: factures_clients.php'); exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Filters
$filterClient = $_GET['client']  ?? '';
$filterStatut = $_GET['statut']  ?? '';
$filterCompta = $_GET['compta']  ?? '';
$dateDebut    = $_GET['date_debut'] ?? date('Y-01-01');
$dateFin      = $_GET['date_fin']   ?? date('Y-12-31');

// Clients for filter dropdown
$stmtCli = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Client' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtCli->execute([$societe_id]);
$clients = $stmtCli->fetchAll();

// Build query
$sql = "
    SELECT f.*, pt.nom AS client_nom, pt.compte_tiers AS compte_client
    FROM factures_clients f
    JOIN plan_tiers pt ON f.id_client = pt.id
    WHERE f.societe_id = ?
      AND f.date_facture BETWEEN ? AND ?
";
$params = [$societe_id, $dateDebut, $dateFin];
if ($filterClient) { $sql .= " AND f.id_client = ?"; $params[] = $filterClient; }
if ($filterStatut) { $sql .= " AND f.statut = ?"; $params[] = $filterStatut; }
if ($filterCompta) { $sql .= " AND f.statut_compta = ?"; $params[] = $filterCompta; }
$sql .= " ORDER BY f.date_facture DESC, f.numero_facture DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$factures = $stmt->fetchAll();

$today = date('Y-m-d');

// Stats
$totalTTC        = 0; $totalRegle = 0; $montantEnRetard = 0; $nbEnRetard = 0;
foreach ($factures as $f) {
    if ($f['statut'] === 'annulee') continue;
    $totalTTC   += $f['montant_ttc'];
    $totalRegle += $f['montant_regle'];
    $solde = $f['montant_ttc'] - $f['montant_regle'];
    if ($solde > 0.005 && $f['date_echeance'] && $f['date_echeance'] < $today) {
        $nbEnRetard++;
        $montantEnRetard += $solde;
    }
}
$totalRestant = $totalTTC - $totalRegle;

function agingBadge(array $f, string $today): string {
    if ($f['statut'] === 'annulee') return '';
    $solde = $f['montant_ttc'] - $f['montant_regle'];
    if ($solde <= 0.005 || !$f['date_echeance']) return '';
    $diff = (int)((strtotime($today) - strtotime($f['date_echeance'])) / 86400);
    if ($diff <= 0) return '';
    if ($diff <= 30)  return "<span class='ml-1 px-1.5 py-0.5 rounded text-xs font-semibold bg-yellow-500/20 text-yellow-300'>+{$diff}j</span>";
    if ($diff <= 60)  return "<span class='ml-1 px-1.5 py-0.5 rounded text-xs font-semibold bg-orange-500/20 text-orange-300'>+{$diff}j</span>";
    return "<span class='ml-1 px-1.5 py-0.5 rounded text-xs font-semibold bg-red-500/20 text-red-400'>+{$diff}j</span>";
}

$statutLabels = [
    'brouillon'           => ['label' => 'Brouillon',   'cls' => 'bg-slate-700 text-slate-300'],
    'envoyee'             => ['label' => 'Envoyée',      'cls' => 'bg-blue-500/20 text-blue-300'],
    'partiellement_payee' => ['label' => 'Part. payée',  'cls' => 'bg-amber-500/20 text-amber-300'],
    'payee'               => ['label' => 'Payée',        'cls' => 'bg-green-500/20 text-green-300'],
    'annulee'             => ['label' => 'Annulée',      'cls' => 'bg-red-500/20 text-red-400'],
];

$pageTitle = "Factures clients";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> - Comptabilité OHADA</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
  <?php include '../../includes/sidebar.php'; ?>

  <main class="flex-1 overflow-y-auto p-8">

  <!-- En-tête -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-400">
        <i class="fas fa-file-invoice-dollar mr-2"></i>Factures clients
      </h1>
      <p class="text-slate-400 text-sm mt-1">Module AR - Comptes clients</p>
    </div>
    <a href="facture_client_form.php"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-medium">
      <i class="fas fa-plus"></i>Nouvelle facture
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
      <p class="text-xs text-slate-400 mb-1">Total facturé (TTC)</p>
      <p class="text-lg font-bold text-slate-100"><?= number_format($totalTTC, 0, ',', ' ') ?> <span class="text-xs font-normal text-slate-500">F CFA</span></p>
    </div>
    <div class="bg-slate-800 rounded-xl p-4 border border-slate-700">
      <p class="text-xs text-slate-400 mb-1">Encaissé</p>
      <p class="text-lg font-bold text-green-400"><?= number_format($totalRegle, 0, ',', ' ') ?> <span class="text-xs font-normal text-slate-500">F CFA</span></p>
    </div>
    <div class="bg-slate-800 rounded-xl p-4 border border-slate-700">
      <p class="text-xs text-slate-400 mb-1">À encaisser</p>
      <p class="text-lg font-bold text-blue-400"><?= number_format($totalRestant, 0, ',', ' ') ?> <span class="text-xs font-normal text-slate-500">F CFA</span></p>
    </div>
    <div class="rounded-xl p-4 border <?= $nbEnRetard > 0 ? 'border-red-500/40 bg-red-500/10' : 'border-slate-700 bg-slate-800' ?>">
      <p class="text-xs <?= $nbEnRetard > 0 ? 'text-red-400' : 'text-slate-400' ?> mb-1">En retard (<?= $nbEnRetard ?>)</p>
      <p class="text-lg font-bold <?= $nbEnRetard > 0 ? 'text-red-400' : 'text-slate-500' ?>"><?= number_format($montantEnRetard, 0, ',', ' ') ?> <span class="text-xs font-normal text-slate-500">F CFA</span></p>
    </div>
  </div>

  <!-- Filtres -->
  <form method="GET" class="bg-slate-800 border border-slate-700 rounded-xl p-4 mb-5 flex flex-wrap gap-3 items-end">
    <div class="flex flex-col gap-1">
      <label class="text-xs text-slate-400 font-medium">Du</label>
      <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>"
             class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-blue-500 focus:outline-none">
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-slate-400 font-medium">Au</label>
      <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>"
             class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-blue-500 focus:outline-none">
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-slate-400 font-medium">Client</label>
      <select name="client" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-blue-500 focus:outline-none">
        <option value="">Tous les clients</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['nom']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-slate-400 font-medium">Statut</label>
      <select name="statut" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-blue-500 focus:outline-none">
        <option value="">Tous</option>
        <option value="brouillon"           <?= $filterStatut === 'brouillon'           ? 'selected' : '' ?>>Brouillon</option>
        <option value="envoyee"             <?= $filterStatut === 'envoyee'             ? 'selected' : '' ?>>Envoyée</option>
        <option value="partiellement_payee" <?= $filterStatut === 'partiellement_payee' ? 'selected' : '' ?>>Part. payée</option>
        <option value="payee"               <?= $filterStatut === 'payee'               ? 'selected' : '' ?>>Payée</option>
        <option value="annulee"             <?= $filterStatut === 'annulee'             ? 'selected' : '' ?>>Annulée</option>
      </select>
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-slate-400 font-medium">Comptabilisation</label>
      <select name="compta" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-slate-100 focus:ring-2 focus:ring-blue-500 focus:outline-none">
        <option value="">Toutes</option>
        <option value="non_comptabilisee" <?= $filterCompta === 'non_comptabilisee' ? 'selected' : '' ?>>Non comptabilisée</option>
        <option value="comptabilisee"     <?= $filterCompta === 'comptabilisee'     ? 'selected' : '' ?>>Comptabilisée</option>
      </select>
    </div>
    <button type="submit" class="px-4 py-1.5 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 transition">
      <i class="fas fa-filter mr-1"></i>Filtrer
    </button>
    <a href="factures_clients.php" class="px-4 py-1.5 border border-slate-600 text-slate-400 rounded-lg text-sm hover:bg-slate-700 transition">Reset</a>
  </form>

  <!-- Tableau -->
  <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
    <?php if (empty($factures)): ?>
      <div class="py-16 text-center">
        <i class="fas fa-file-invoice-dollar text-4xl text-slate-600 mb-3"></i>
        <p class="text-slate-400 text-sm">Aucune facture pour la période sélectionnée.</p>
        <a href="facture_client_form.php" class="mt-3 inline-block text-blue-400 text-sm hover:underline">Créer une facture</a>
      </div>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead class="border-b border-slate-700">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">N° Facture</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">Client</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">Objet</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">Date</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">Échéance</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wide">TTC</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wide">Solde</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wide">Statut</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wide">Compta</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wide">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-700/50">
          <?php foreach ($factures as $f):
            $solde  = $f['montant_ttc'] - $f['montant_regle'];
            $badge  = agingBadge($f, $today);
            $st     = $statutLabels[$f['statut']] ?? ['label' => $f['statut'], 'cls' => 'bg-slate-700 text-slate-400'];
            // Aging row highlight
            $diff = ($f['date_echeance'] && $solde > 0.005 && $f['statut'] !== 'annulee')
                    ? (int)((strtotime($today) - strtotime($f['date_echeance'])) / 86400) : 0;
            $rowBorder = $diff > 60 ? 'border-l-2 border-red-500' : ($diff > 30 ? 'border-l-2 border-orange-500' : ($diff > 0 ? 'border-l-2 border-yellow-500' : ''));
          ?>
          <tr class="hover:bg-slate-700/30 transition <?= $rowBorder ?>">
            <td class="px-4 py-3 font-mono font-semibold text-blue-300 text-xs">
              <?= htmlspecialchars($f['numero_facture']) ?>
            </td>
            <td class="px-4 py-3 text-slate-200">
              <?= htmlspecialchars($f['client_nom']) ?>
              <div class="text-xs text-slate-500"><?= htmlspecialchars($f['compte_client'] ?? '') ?></div>
            </td>
            <td class="px-4 py-3 text-slate-400 max-w-[180px] truncate"><?= htmlspecialchars($f['objet']) ?></td>
            <td class="px-4 py-3 text-slate-400 whitespace-nowrap text-xs"><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
            <td class="px-4 py-3 whitespace-nowrap text-xs">
              <span class="text-slate-400"><?= $f['date_echeance'] ? date('d/m/Y', strtotime($f['date_echeance'])) : '-' ?></span>
              <?= $badge ?>
            </td>
            <td class="px-4 py-3 text-right font-medium text-slate-200 whitespace-nowrap text-xs">
              <?= number_format($f['montant_ttc'], 0, ',', ' ') ?>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap text-xs <?= $solde > 0.005 ? 'font-semibold text-blue-400' : 'text-slate-600' ?>">
              <?= $solde > 0.005 ? number_format($solde, 0, ',', ' ') : '-' ?>
            </td>
            <td class="px-4 py-3 text-center">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $st['cls'] ?>">
                <?= $st['label'] ?>
              </span>
            </td>
            <td class="px-4 py-3 text-center">
              <?php if ($f['statut_compta'] === 'comptabilisee'): ?>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-400">
                  <i class="fas fa-check mr-0.5"></i>Compta
                </span>
              <?php else: ?>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-400">En attente</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <?php if ($f['statut'] === 'brouillon'): ?>
                  <a href="facture_client_form.php?id=<?= $f['id'] ?>"
                     class="p-1.5 rounded text-blue-400 hover:bg-slate-700 transition" title="Modifier">
                    <i class="fas fa-pen text-xs"></i>
                  </a>
                <?php else: ?>
                  <a href="facture_client_form.php?id=<?= $f['id'] ?>&view=1"
                     class="p-1.5 rounded text-slate-400 hover:bg-slate-700 transition" title="Voir">
                    <i class="fas fa-eye text-xs"></i>
                  </a>
                <?php endif; ?>
                <?php if (in_array($f['statut'], ['brouillon','envoyee'])): ?>
                  <button type="button" onclick="confirmerAnnulation(<?= $f['id'] ?>, '<?= htmlspecialchars($f['numero_facture'], ENT_QUOTES) ?>')"
                          class="p-1.5 rounded text-red-400 hover:bg-slate-700 transition" title="Annuler">
                    <i class="fas fa-times text-xs"></i>
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <p class="text-xs text-slate-500 mt-3"><?= count($factures) ?> facture(s) - Montants en F CFA</p>

  </main>
</div>

<!-- Modal annulation -->
<form id="form-annulation" method="POST" class="hidden">
  <input type="hidden" name="action" value="annuler">
  <input type="hidden" name="id" id="ann-id" value="">
</form>

<div id="modal-annulation" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-50" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl p-6 w-full max-w-sm mx-4">
    <h3 class="text-base font-semibold text-slate-100 mb-2">Annuler la facture ?</h3>
    <p class="text-sm text-slate-400 mb-4">La facture <strong id="ann-num" class="text-slate-200"></strong> sera marquée annulée. Cette action est irréversible.</p>
    <div class="flex gap-3 justify-end">
      <button type="button" onclick="document.getElementById('modal-annulation').classList.add('hidden')"
              class="px-4 py-2 border border-slate-600 rounded-lg text-sm text-slate-400 hover:bg-slate-700 transition">
        Fermer
      </button>
      <button type="button" onclick="document.getElementById('form-annulation').submit()"
              class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 transition">
        Confirmer
      </button>
    </div>
  </div>
</div>

<script>
function confirmerAnnulation(id, num) {
  document.getElementById('ann-id').value = id;
  document.getElementById('ann-num').textContent = num;
  const modal = document.getElementById('modal-annulation');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
}
</script>

</body>
</html>
