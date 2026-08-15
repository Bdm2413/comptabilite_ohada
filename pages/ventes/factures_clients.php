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

function agingClass(array $f, string $today): string {
    if ($f['statut'] === 'annulee') return '';
    $solde = $f['montant_ttc'] - $f['montant_regle'];
    if ($solde <= 0.005 || !$f['date_echeance']) return '';
    $diff = (int)((strtotime($today) - strtotime($f['date_echeance'])) / 86400);
    if ($diff <= 0) return '';
    if ($diff <= 30)  return 'bg-yellow-50 border-l-4 border-yellow-400';
    if ($diff <= 60)  return 'bg-orange-50 border-l-4 border-orange-400';
    return 'bg-red-50 border-l-4 border-red-400';
}

function agingBadge(array $f, string $today): string {
    if ($f['statut'] === 'annulee') return '';
    $solde = $f['montant_ttc'] - $f['montant_regle'];
    if ($solde <= 0.005 || !$f['date_echeance']) return '';
    $diff = (int)((strtotime($today) - strtotime($f['date_echeance'])) / 86400);
    if ($diff <= 0) return '';
    if ($diff <= 30)  return "<span class='px-1.5 py-0.5 rounded text-xs font-semibold bg-yellow-100 text-yellow-800'>+{$diff}j</span>";
    if ($diff <= 60)  return "<span class='px-1.5 py-0.5 rounded text-xs font-semibold bg-orange-100 text-orange-800'>+{$diff}j</span>";
    return "<span class='px-1.5 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700'>+{$diff}j</span>";
}

$statutLabels = [
    'brouillon'           => ['label' => 'Brouillon',          'cls' => 'bg-gray-100 text-gray-600'],
    'envoyee'             => ['label' => 'Envoyée',             'cls' => 'bg-blue-100 text-blue-700'],
    'partiellement_payee' => ['label' => 'Part. payée',         'cls' => 'bg-amber-100 text-amber-700'],
    'payee'               => ['label' => 'Payée',               'cls' => 'bg-green-100 text-green-700'],
    'annulee'             => ['label' => 'Annulée',             'cls' => 'bg-red-100 text-red-600'],
];

$pageTitle = "Factures clients";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="main-content flex-1 p-6">

  <!-- En-tête -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">Factures clients</h1>
      <p class="text-sm text-gray-500 mt-1">Module AR - Comptes clients</p>
    </div>
    <a href="facture_client_form.php"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
      </svg>
      Nouvelle facture
    </a>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
      <p class="text-xs text-gray-500 mb-1">Total facturé (TTC)</p>
      <p class="text-lg font-bold text-gray-800"><?= number_format($totalTTC, 0, ',', ' ') ?> <span class="text-xs font-normal text-gray-400">F CFA</span></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
      <p class="text-xs text-gray-500 mb-1">Encaissé</p>
      <p class="text-lg font-bold text-green-600"><?= number_format($totalRegle, 0, ',', ' ') ?> <span class="text-xs font-normal text-gray-400">F CFA</span></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
      <p class="text-xs text-gray-500 mb-1">À encaisser</p>
      <p class="text-lg font-bold text-blue-600"><?= number_format($totalRestant, 0, ',', ' ') ?> <span class="text-xs font-normal text-gray-400">F CFA</span></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4 border <?= $nbEnRetard > 0 ? 'border-red-200 bg-red-50' : 'border-gray-100' ?>">
      <p class="text-xs <?= $nbEnRetard > 0 ? 'text-red-500' : 'text-gray-500' ?> mb-1">En retard (<?= $nbEnRetard ?>)</p>
      <p class="text-lg font-bold <?= $nbEnRetard > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= number_format($montantEnRetard, 0, ',', ' ') ?> <span class="text-xs font-normal text-gray-400">F CFA</span></p>
    </div>
  </div>

  <!-- Filtres -->
  <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-5 flex flex-wrap gap-3 items-end">
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500 font-medium">Du</label>
      <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>"
             class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none">
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500 font-medium">Au</label>
      <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>"
             class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none">
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500 font-medium">Client</label>
      <select name="client" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none">
        <option value="">Tous les clients</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['nom']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500 font-medium">Statut règlement</label>
      <select name="statut" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none">
        <option value="">Tous</option>
        <option value="brouillon"           <?= $filterStatut === 'brouillon'           ? 'selected' : '' ?>>Brouillon</option>
        <option value="envoyee"             <?= $filterStatut === 'envoyee'             ? 'selected' : '' ?>>Envoyée</option>
        <option value="partiellement_payee" <?= $filterStatut === 'partiellement_payee' ? 'selected' : '' ?>>Part. payée</option>
        <option value="payee"               <?= $filterStatut === 'payee'               ? 'selected' : '' ?>>Payée</option>
        <option value="annulee"             <?= $filterStatut === 'annulee'             ? 'selected' : '' ?>>Annulée</option>
      </select>
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500 font-medium">Comptabilisation</label>
      <select name="compta" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none">
        <option value="">Toutes</option>
        <option value="non_comptabilisee" <?= $filterCompta === 'non_comptabilisee' ? 'selected' : '' ?>>Non comptabilisée</option>
        <option value="comptabilisee"     <?= $filterCompta === 'comptabilisee'     ? 'selected' : '' ?>>Comptabilisée</option>
      </select>
    </div>
    <button type="submit" class="px-4 py-1.5 bg-gray-700 text-white rounded-lg text-sm hover:bg-gray-800 transition">Filtrer</button>
    <a href="factures_clients.php" class="px-4 py-1.5 border border-gray-200 text-gray-600 rounded-lg text-sm hover:bg-gray-50 transition">Reset</a>
  </form>

  <!-- Tableau -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <?php if (empty($factures)): ?>
      <div class="py-16 text-center">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p class="text-gray-400 text-sm">Aucune facture pour la période sélectionnée.</p>
        <a href="facture_client_form.php" class="mt-3 inline-block text-blue-600 text-sm hover:underline">Créer une facture</a>
      </div>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">N° Facture</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Client</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Objet</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Échéance</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Montant TTC</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Solde</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Statut</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Compta</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($factures as $f):
            $solde   = $f['montant_ttc'] - $f['montant_regle'];
            $rowCls  = agingClass($f, $today);
            $badge   = agingBadge($f, $today);
            $st      = $statutLabels[$f['statut']] ?? ['label' => $f['statut'], 'cls' => 'bg-gray-100 text-gray-600'];
          ?>
          <tr class="hover:bg-gray-50 transition <?= $rowCls ?>">
            <td class="px-4 py-3 font-mono font-semibold text-gray-800 text-xs">
              <?= htmlspecialchars($f['numero_facture']) ?>
            </td>
            <td class="px-4 py-3 text-gray-700">
              <?= htmlspecialchars($f['client_nom']) ?>
              <div class="text-xs text-gray-400"><?= htmlspecialchars($f['compte_client'] ?? '') ?></div>
            </td>
            <td class="px-4 py-3 text-gray-600 max-w-[200px] truncate"><?= htmlspecialchars($f['objet']) ?></td>
            <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
            <td class="px-4 py-3 whitespace-nowrap">
              <?= $f['date_echeance'] ? date('d/m/Y', strtotime($f['date_echeance'])) : '-' ?>
              <?= $badge ?>
            </td>
            <td class="px-4 py-3 text-right font-medium text-gray-800 whitespace-nowrap">
              <?= number_format($f['montant_ttc'], 0, ',', ' ') ?>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap <?= $solde > 0.005 ? 'font-semibold text-blue-700' : 'text-gray-400' ?>">
              <?= $solde > 0.005 ? number_format($solde, 0, ',', ' ') : '-' ?>
            </td>
            <td class="px-4 py-3 text-center">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $st['cls'] ?>">
                <?= $st['label'] ?>
              </span>
            </td>
            <td class="px-4 py-3 text-center">
              <?php if ($f['statut_compta'] === 'comptabilisee'): ?>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
                  <svg class="w-3 h-3 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  Compta
                </span>
              <?php else: ?>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">En attente</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <!-- Modifier / Voir -->
                <?php if ($f['statut'] === 'brouillon'): ?>
                  <a href="facture_client_form.php?id=<?= $f['id'] ?>"
                     class="p-1.5 rounded text-blue-600 hover:bg-blue-50 transition" title="Modifier">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </a>
                <?php else: ?>
                  <a href="facture_client_form.php?id=<?= $f['id'] ?>&view=1"
                     class="p-1.5 rounded text-gray-500 hover:bg-gray-100 transition" title="Voir">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </a>
                <?php endif; ?>

                <!-- Annuler -->
                <?php if (in_array($f['statut'], ['brouillon','envoyee'])): ?>
                  <button type="button" onclick="confirmerAnnulation(<?= $f['id'] ?>, '<?= htmlspecialchars($f['numero_facture'], ENT_QUOTES) ?>')"
                          class="p-1.5 rounded text-red-500 hover:bg-red-50 transition" title="Annuler">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
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

  <p class="text-xs text-gray-400 mt-3"><?= count($factures) ?> facture(s) - Montants en F CFA</p>
</div>

<!-- Modal annulation -->
<form id="form-annulation" method="POST" class="hidden">
  <input type="hidden" name="action" value="annuler">
  <input type="hidden" name="id" id="ann-id" value="">
</form>

<div id="modal-annulation" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm mx-4">
    <h3 class="text-base font-semibold text-gray-800 mb-2">Annuler la facture ?</h3>
    <p class="text-sm text-gray-500 mb-4">La facture <strong id="ann-num"></strong> sera marquée annulée. Cette action est irréversible.</p>
    <div class="flex gap-3 justify-end">
      <button type="button" onclick="document.getElementById('modal-annulation').classList.add('hidden')"
              class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition">
        Annuler
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
  document.getElementById('modal-annulation').classList.remove('hidden');
  document.getElementById('modal-annulation').classList.add('flex');
}
</script>

<?php include '../../includes/footer.php'; ?>
