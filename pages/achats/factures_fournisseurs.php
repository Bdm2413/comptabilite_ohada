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
        $db->prepare("UPDATE factures_fournisseurs SET statut='annulee' WHERE id=? AND societe_id=? AND statut IN ('brouillon','recue')")->execute([$id, $societe_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Facture annulee.'];
    }
    header('Location: factures_fournisseurs.php'); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$filterFourn  = $_GET['fournisseur'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$filterCompta = $_GET['compta'] ?? '';
$dateDebut    = $_GET['date_debut'] ?? date('Y-01-01');
$dateFin      = $_GET['date_fin']   ?? date('Y-12-31');

$stmtF = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Fournisseur' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtF->execute([$societe_id]);
$fournisseurs = $stmtF->fetchAll();

$where  = ['f.societe_id=?', 'f.date_facture BETWEEN ? AND ?'];
$params = [$societe_id, $dateDebut, $dateFin];
if ($filterFourn)  { $where[] = 'f.id_fournisseur=?'; $params[] = (int)$filterFourn; }
if ($filterStatut) { $where[] = 'f.statut=?';         $params[] = $filterStatut; }
if ($filterCompta) { $where[] = 'f.statut_compta=?';  $params[] = $filterCompta; }

$stmt = $db->prepare("SELECT f.*, pt.nom AS fourn_nom FROM factures_fournisseurs f JOIN plan_tiers pt ON f.id_fournisseur=pt.id WHERE " . implode(' AND ', $where) . " ORDER BY f.date_facture DESC, f.id DESC");
$stmt->execute($params);
$factures = $stmt->fetchAll();

$statsStmt = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(statut='brouillon') AS brouillons,
    SUM(statut='recue') AS recues,
    SUM(statut='partiellement_payee') AS part_payees,
    SUM(statut='payee') AS payees,
    SUM(CASE WHEN statut NOT IN ('annulee','payee') THEN net_a_payer - montant_regle ELSE 0 END) AS a_payer
FROM factures_fournisseurs WHERE societe_id=?");
$statsStmt->execute([$societe_id]);
$stats = $statsStmt->fetch();

function statutBadgeFF(string $s): string {
    return match($s) {
        'brouillon'          => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-300">Brouillon</span>',
        'recue'              => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-500/20 text-blue-300">Recue</span>',
        'partiellement_payee'=> '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/20 text-amber-300">Part. payee</span>',
        'payee'              => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-500/20 text-green-300">Payee</span>',
        'annulee'            => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-400">Annulee</span>',
        default              => '<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-300">' . htmlspecialchars($s) . '</span>',
    };
}

function ageBadgeFF(string $echeance, string $statut): string {
    if (in_array($statut, ['payee','annulee'])) return '';
    $diff = (int)((new DateTime())->diff(new DateTime($echeance))->days * ((new DateTime()) > new DateTime($echeance) ? 1 : -1));
    if ($diff <= 0) return '';
    if ($diff <= 30) return '<span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-yellow-500/20 text-yellow-300">+' . $diff . 'j</span>';
    if ($diff <= 60) return '<span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-orange-500/20 text-orange-300">+' . $diff . 'j</span>';
    return '<span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-red-500/20 text-red-400">+' . $diff . 'j</span>';
}
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Factures fournisseurs</title>
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
        <h1 class="text-2xl font-bold text-slate-100">Factures fournisseurs</h1>
        <p class="text-slate-400 mt-1">Factures recues des fournisseurs (AP)</p>
      </div>
      <a href="facture_fournisseur_form.php" class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors text-sm font-medium">
        <i class="fas fa-plus text-xs"></i> Saisir une facture
      </a>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Total factures</p>
        <p class="text-2xl font-bold text-slate-100"><?= (int)$stats['total'] ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Recues (non payees)</p>
        <p class="text-2xl font-bold text-blue-400"><?= (int)$stats['recues'] + (int)$stats['part_payees'] ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Payees</p>
        <p class="text-2xl font-bold text-green-400"><?= (int)$stats['payees'] ?></p>
      </div>
      <div class="bg-slate-800 rounded-xl p-5 border border-slate-700">
        <p class="text-slate-400 text-sm mb-1">Restant a payer</p>
        <p class="text-2xl font-bold text-slate-100"><?= number_format((float)$stats['a_payer'], 0, ',', ' ') ?> <span class="text-sm text-slate-400">FCFA</span></p>
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-slate-800 rounded-xl p-4 border border-slate-700 mb-6 flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-40">
        <label class="text-slate-400 text-xs block mb-1">Fournisseur</label>
        <select name="fournisseur" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
          <option value="">Tous</option>
          <?php foreach ($fournisseurs as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $filterFourn == $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex-1 min-w-36">
        <label class="text-slate-400 text-xs block mb-1">Statut</label>
        <select name="statut" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
          <option value="">Tous</option>
          <?php foreach (['brouillon' => 'Brouillon','recue' => 'Recue','partiellement_payee' => 'Part. payee','payee' => 'Payee','annulee' => 'Annulee'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $filterStatut === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex-1 min-w-36">
        <label class="text-slate-400 text-xs block mb-1">Comptabilite</label>
        <select name="compta" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100">
          <option value="">Toutes</option>
          <option value="non_comptabilisee" <?= $filterCompta === 'non_comptabilisee' ? 'selected' : '' ?>>Non comptabilisee</option>
          <option value="comptabilisee" <?= $filterCompta === 'comptabilisee' ? 'selected' : '' ?>>Comptabilisee</option>
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
      <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">Filtrer</button>
    </form>

    <!-- Table -->
    <div class="bg-slate-800 rounded-xl border border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase">
            <th class="px-4 py-3 text-left">Ref. interne</th>
            <th class="px-4 py-3 text-left">N° fournisseur</th>
            <th class="px-4 py-3 text-left">Fournisseur</th>
            <th class="px-4 py-3 text-left">Date</th>
            <th class="px-4 py-3 text-left">Echeance</th>
            <th class="px-4 py-3 text-right">HT</th>
            <th class="px-4 py-3 text-right">Net a payer</th>
            <th class="px-4 py-3 text-center">Compta</th>
            <th class="px-4 py-3 text-center">Statut</th>
            <th class="px-4 py-3 text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($factures)): ?>
          <tr><td colspan="10" class="px-4 py-12 text-center text-slate-500">Aucune facture trouvee.</td></tr>
          <?php endif; ?>
          <?php foreach ($factures as $f): ?>
          <tr class="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 font-mono font-semibold text-indigo-400"><?= htmlspecialchars($f['numero_facture_interne']) ?></td>
            <td class="px-4 py-3 text-slate-400 text-xs"><?= $f['numero_facture_fournisseur'] ? htmlspecialchars($f['numero_facture_fournisseur']) : '-' ?></td>
            <td class="px-4 py-3 text-slate-200"><?= htmlspecialchars($f['fourn_nom']) ?></td>
            <td class="px-4 py-3 text-slate-300"><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
            <td class="px-4 py-3 text-slate-300">
              <?= $f['date_echeance'] ? date('d/m/Y', strtotime($f['date_echeance'])) : '-' ?>
              <?= $f['date_echeance'] ? ageBadgeFF($f['date_echeance'], $f['statut']) : '' ?>
            </td>
            <td class="px-4 py-3 text-right text-slate-400"><?= number_format((float)$f['montant_ht'], 0, ',', ' ') ?></td>
            <td class="px-4 py-3 text-right font-semibold text-slate-200"><?= number_format((float)$f['net_a_payer'], 0, ',', ' ') ?></td>
            <td class="px-4 py-3 text-center">
              <?php if ($f['statut_compta'] === 'comptabilisee'): ?>
              <span class="text-green-400 text-xs"><i class="fas fa-check-circle"></i></span>
              <?php else: ?>
              <span class="text-slate-600 text-xs"><i class="fas fa-circle"></i></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-center"><?= statutBadgeFF($f['statut']) ?></td>
            <td class="px-4 py-3 text-center">
              <div class="flex items-center justify-center gap-1">
                <a href="facture_fournisseur_form.php?id=<?= $f['id'] ?>&view=1" class="p-1.5 rounded text-slate-400 hover:text-white hover:bg-slate-600 transition-colors" title="Voir"><i class="fas fa-eye text-xs"></i></a>
                <?php if ($f['statut'] === 'brouillon'): ?>
                <a href="facture_fournisseur_form.php?id=<?= $f['id'] ?>" class="p-1.5 rounded text-slate-400 hover:text-white hover:bg-slate-600 transition-colors" title="Modifier"><i class="fas fa-edit text-xs"></i></a>
                <button onclick="openAnnuler(<?= $f['id'] ?>)" class="p-1.5 rounded text-slate-400 hover:text-red-400 hover:bg-red-500/10 transition-colors" title="Annuler"><i class="fas fa-times text-xs"></i></button>
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

<!-- Modal annulation -->
<div id="modalAnnuler" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden">
  <div class="bg-slate-800 rounded-xl p-6 w-full max-w-sm border border-slate-700 shadow-xl">
    <h3 class="text-lg font-semibold text-slate-100 mb-3">Annuler la facture</h3>
    <p class="text-slate-400 mb-6 text-sm">Confirmer l'annulation de cette facture fournisseur ?</p>
    <form method="POST">
      <input type="hidden" name="action" value="annuler">
      <input type="hidden" name="id" id="annulerId">
      <div class="flex gap-3 justify-end">
        <button type="button" onclick="document.getElementById('modalAnnuler').classList.add('hidden')" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm transition-colors">Fermer</button>
        <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition-colors">Annuler la facture</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAnnuler(id) {
  document.getElementById('annulerId').value = id;
  document.getElementById('modalAnnuler').classList.remove('hidden');
}
document.getElementById('modalAnnuler').addEventListener('click', e => { if (e.target === e.currentTarget) e.currentTarget.classList.add('hidden'); });
</script>
</body>
</html>
