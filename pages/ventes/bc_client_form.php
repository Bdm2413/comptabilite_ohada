<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$isView    = isset($_GET['view']);
$editId    = isset($_GET['id'])         ? (int)$_GET['id']         : 0;
$fromDevis = isset($_GET['from_devis']) ? (int)$_GET['from_devis'] : 0;

$bc           = null;
$lignes       = [];
$devisSource  = null;

if ($editId) {
    $stmt = $db->prepare("SELECT b.*, pt.nom AS client_nom FROM bons_commande_clients b JOIN plan_tiers pt ON b.id_client=pt.id WHERE b.id=? AND b.societe_id=?");
    $stmt->execute([$editId, $societe_id]);
    $bc = $stmt->fetch();
    if (!$bc) { header('Location: bons_commande_clients.php'); exit; }
    if ($bc['statut'] !== 'En cours') $isView = true;
    $stmtL = $db->prepare("SELECT * FROM lignes_bc_client WHERE bc_id=? ORDER BY ordre");
    $stmtL->execute([$editId]);
    $lignes = $stmtL->fetchAll();
}

if ($fromDevis && !$editId) {
    $stmt = $db->prepare("SELECT d.*, pt.nom AS client_nom FROM devis_clients d JOIN plan_tiers pt ON d.id_client=pt.id WHERE d.id=? AND d.societe_id=? AND d.statut='Accepte'");
    $stmt->execute([$fromDevis, $societe_id]);
    $devisSource = $stmt->fetch();
    if ($devisSource) {
        $stmtL = $db->prepare("SELECT * FROM lignes_devis_client WHERE id_devis=? ORDER BY ordre");
        $stmtL->execute([$fromDevis]);
        $lignes = $stmtL->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isView) {
    $data       = $_POST;
    $lignesPost = json_decode($data['lignes_json'] ?? '[]', true) ?: [];

    $montant_ht = 0; $montant_tva = 0;
    foreach ($lignesPost as $l) {
        $montant_ht  += (float)($l['montant_ht']  ?? 0);
        $montant_tva += (float)($l['montant_tva'] ?? 0);
    }
    $montant_ttc = $montant_ht + $montant_tva;

    if ($editId) {
        $stmt = $db->prepare("UPDATE bons_commande_clients SET id_client=?, date_bc=?, date_livraison=?, objet=?, reference_client=?, notes=?, montant_ht=?, montant_tva=?, montant_ttc=? WHERE id=? AND societe_id=? AND statut='En cours'");
        $stmt->execute([(int)$data['id_client'], $data['date_bc'], $data['date_livraison'] ?: null, $data['objet'], $data['reference_client'] ?: null, $data['notes'] ?: null, $montant_ht, $montant_tva, $montant_ttc, $editId, $societe_id]);
        $db->prepare("DELETE FROM lignes_bc_client WHERE bc_id=?")->execute([$editId]);
        $bcId = $editId;
    } else {
        $year = date('Y');
        $stmtNum = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_bc, 10) AS UNSIGNED)) AS mx FROM bons_commande_clients WHERE societe_id=? AND numero_bc LIKE ?");
        $stmtNum->execute([$societe_id, "BCC-$year-%"]);
        $row = $stmtNum->fetch();
        $seq = (int)($row['mx'] ?? 0) + 1;
        $numero_bc = "BCC-$year-" . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("INSERT INTO bons_commande_clients (societe_id, numero_bc, id_client, id_devis_client, date_bc, date_livraison, objet, reference_client, notes, montant_ht, montant_tva, montant_ttc, statut, createur) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'En cours',?)");
        $stmt->execute([$societe_id, $numero_bc, (int)$data['id_client'], $fromDevis ?: null, $data['date_bc'], $data['date_livraison'] ?: null, $data['objet'], $data['reference_client'] ?: null, $data['notes'] ?: null, $montant_ht, $montant_tva, $montant_ttc, $_SESSION['user_name'] ?? 'Systeme']);
        $bcId = $db->lastInsertId();

        if ($fromDevis) {
            $db->prepare("UPDATE devis_clients SET statut='Converti', id_bc_client=? WHERE id=? AND societe_id=?")->execute([$bcId, $fromDevis, $societe_id]);
        }
    }

    $stmtLigne = $db->prepare("INSERT INTO lignes_bc_client (bc_id, article_id, designation, description, type_ligne, quantite, unite, prix_unitaire_ht, type_taxe, montant_ht, montant_tva, montant_ttc, compte_produit, ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($lignesPost as $i => $l) {
        $stmtLigne->execute([$bcId, $l['article_id'] ?: null, $l['designation'], $l['description'] ?? null, $l['type_ligne'] ?? 'Service', (float)$l['quantite'], $l['unite'] ?? 'unite', (float)$l['prix_unitaire_ht'], $l['type_taxe'] ?? 'TVA', (float)$l['montant_ht'], (float)$l['montant_tva'], (float)$l['montant_ttc'], $l['compte_produit'] ?: null, $i + 1]);
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => $editId ? 'BC mis a jour.' : 'BC cree avec succes.'];
    header('Location: bons_commande_clients.php'); exit;
}

$stmtCli = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Client' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtCli->execute([$societe_id]);
$clients = $stmtCli->fetchAll();

$pageTitle   = $editId ? ($isView ? 'BC ' . $bc['numero_bc'] : 'Modifier ' . $bc['numero_bc']) : ($fromDevis ? 'Nouveau BC depuis devis' : 'Nouveau bon de commande client');
$preClient   = $bc['id_client']       ?? ($devisSource['id_client'] ?? '');
$preDateBC   = $bc['date_bc']         ?? date('Y-m-d');
$preDateLiv  = $bc['date_livraison']  ?? '';
$preObjet    = $bc['objet']           ?? ($devisSource['objet']  ?? '');
$preRefCli   = $bc['reference_client'] ?? '';
$preNotes    = $bc['notes']           ?? ($devisSource['notes']  ?? '');
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100 h-full">
<div class="flex h-screen overflow-hidden">
  <?php include '../../includes/sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto p-8">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-8">
      <a href="bons_commande_clients.php" class="text-slate-400 hover:text-white transition-colors"><i class="fas fa-arrow-left"></i></a>
      <div>
        <h1 class="text-2xl font-bold text-slate-100"><?= htmlspecialchars($pageTitle) ?></h1>
        <?php if ($devisSource): ?>
        <p class="text-slate-400 text-sm mt-0.5">Converti depuis devis <?= htmlspecialchars($devisSource['numero_devis']) ?> - <?= htmlspecialchars($devisSource['client_nom']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isView && $bc): ?>
    <div class="mb-6 px-4 py-3 rounded-lg bg-slate-700/50 border border-slate-600 text-slate-300 text-sm flex items-center gap-2">
      <i class="fas fa-lock text-slate-400"></i>
      Lecture seule - statut : <strong class="text-slate-200"><?= htmlspecialchars($bc['statut']) ?></strong>
      <?php if ($bc['statut'] === 'Livre'): ?>
      <a href="facture_client_form.php?from_bc=<?= $bc['id'] ?>" class="ml-auto flex items-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
        <i class="fas fa-file-invoice text-xs"></i> Creer facture
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="formBC">
      <input type="hidden" name="lignes_json" id="lignesJson">

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Left: form fields -->
        <div class="lg:col-span-2 bg-slate-800 rounded-xl p-6 border border-slate-700">
          <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-5">Informations du BC</h2>
          <div class="grid grid-cols-2 gap-4">

            <div class="col-span-2">
              <label class="text-slate-400 text-xs block mb-1">Client *</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= htmlspecialchars($bc['client_nom']) ?></div>
              <?php else: ?>
              <select name="id_client" required class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <option value="">- Choisir un client -</option>
                <?php foreach ($clients as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $preClient == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Date BC *</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= date('d/m/Y', strtotime($preDateBC)) ?></div>
              <?php else: ?>
              <input type="date" name="date_bc" value="<?= $preDateBC ?>" required class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Date de livraison prevue</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= $preDateLiv ? date('d/m/Y', strtotime($preDateLiv)) : '-' ?></div>
              <?php else: ?>
              <input type="date" name="date_livraison" value="<?= $preDateLiv ?>" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Reference client (n° BC de votre client)</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= $preRefCli ?: '-' ?></div>
              <?php else: ?>
              <input type="text" name="reference_client" value="<?= htmlspecialchars($preRefCli) ?>" placeholder="Ex: BC-2026-0042" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Objet *</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= htmlspecialchars($preObjet) ?></div>
              <?php else: ?>
              <input type="text" name="objet" value="<?= htmlspecialchars($preObjet) ?>" required placeholder="Objet de la commande" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
              <?php endif; ?>
            </div>

          </div>
        </div>

        <!-- Right: summary -->
        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 flex flex-col gap-3">
          <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Recapitulatif</h2>
          <?php if ($bc): ?>
          <div class="text-xs text-slate-500 font-mono">N° <?= htmlspecialchars($bc['numero_bc']) ?></div>
          <?php endif; ?>
          <div class="flex justify-between text-sm mt-1">
            <span class="text-slate-400">Total HT</span>
            <span id="sumHT" class="text-slate-200 font-mono">0</span>
          </div>
          <div class="flex justify-between text-sm">
            <span class="text-slate-400">TVA 18%</span>
            <span id="sumTVA" class="text-slate-400 font-mono">0</span>
          </div>
          <div class="border-t border-slate-700 pt-3 flex justify-between">
            <span class="font-semibold text-slate-200">Total TTC</span>
            <span id="sumTTC" class="font-bold text-indigo-400 font-mono text-lg">0</span>
          </div>
          <div class="text-xs text-slate-500">FCFA</div>
          <?php if ($bc): ?>
          <div class="mt-3 pt-3 border-t border-slate-700 space-y-1.5">
            <p class="text-xs text-slate-500">Createur : <?= htmlspecialchars($bc['createur'] ?? '-') ?></p>
            <p class="text-xs text-slate-500">Cree le : <?= date('d/m/Y', strtotime($bc['date_creation'])) ?></p>
            <?php if ($bc['id_devis_client']): ?>
            <a href="devis_client_form.php?id=<?= $bc['id_devis_client'] ?>&view=1" class="mt-1 text-xs text-indigo-400 hover:text-indigo-300 flex items-center gap-1">
              <i class="fas fa-link text-xs"></i> Voir le devis source
            </a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Notes -->
      <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 mb-6">
        <label class="text-slate-400 text-xs block mb-1">Notes internes</label>
        <?php if ($isView): ?>
        <div class="text-slate-300 text-sm whitespace-pre-line min-h-6"><?= htmlspecialchars($preNotes ?: '-') ?></div>
        <?php else: ?>
        <textarea name="notes" rows="2" placeholder="Notes internes..." class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 text-sm focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($preNotes) ?></textarea>
        <?php endif; ?>
      </div>

      <!-- Lines -->
      <div class="bg-slate-800 rounded-xl border border-slate-700 mb-6 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-700">
          <h2 class="font-semibold text-slate-200">Lignes de commande</h2>
          <?php if (!$isView): ?>
          <button type="button" onclick="addLigne()" class="flex items-center gap-1.5 text-sm text-indigo-400 hover:text-indigo-300 transition-colors">
            <i class="fas fa-plus text-xs"></i> Ajouter une ligne
          </button>
          <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm min-w-[900px]">
            <thead>
              <tr class="text-slate-400 text-xs uppercase border-b border-slate-700 bg-slate-800/80">
                <th class="px-3 py-2 text-left w-8">#</th>
                <th class="px-3 py-2 text-left">Designation</th>
                <th class="px-3 py-2 text-left w-24">Type</th>
                <th class="px-3 py-2 text-right w-20">Qte</th>
                <th class="px-3 py-2 text-left w-20">Unite</th>
                <th class="px-3 py-2 text-right w-28">P.U. HT</th>
                <th class="px-3 py-2 text-center w-24">Taxe</th>
                <th class="px-3 py-2 text-right w-28">Total HT</th>
                <th class="px-3 py-2 text-right w-24">TVA</th>
                <th class="px-3 py-2 text-right w-28">TTC</th>
                <?php if (!$isView): ?><th class="px-3 py-2 w-8"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody id="linesBody"></tbody>
          </table>
        </div>
        <div id="noLines" class="px-4 py-8 text-center text-slate-500 text-sm hidden">Aucune ligne - cliquez sur "Ajouter une ligne".</div>
      </div>

      <?php if (!$isView): ?>
      <div class="flex justify-end gap-3">
        <a href="bons_commande_clients.php" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm transition-colors">Annuler</a>
        <button type="submit" class="px-6 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
          <i class="fas fa-save mr-1.5"></i> <?= $editId ? 'Mettre a jour' : 'Enregistrer le BC' ?>
        </button>
      </div>
      <?php endif; ?>
    </form>

  </main>
</div>

<!-- Articles panel -->
<div id="articlesPanel" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden">
  <div class="bg-slate-800 rounded-xl p-6 w-full max-w-lg border border-slate-700 shadow-xl">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-semibold text-slate-100">Choisir un article</h3>
      <button type="button" onclick="closeArticles()" class="text-slate-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <input type="text" id="searchArticle" placeholder="Rechercher..." class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 mb-4 focus:ring-2 focus:ring-indigo-500">
    <div id="articlesList" class="space-y-1 max-h-64 overflow-y-auto"></div>
  </div>
</div>

<script>
const TVA_RATE = 0.18;
const VIEW_MODE = <?= $isView ? 'true' : 'false' ?>;
let lineIdx = 0;
let allArticles = [];
let currentLine = null;
const initLines = <?= json_encode(array_values($lignes)) ?>;

function fmt(n) {
  return Math.round(parseFloat(n) || 0).toLocaleString('fr-FR');
}
function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function calcLine(row) {
  const qte  = parseFloat(row.querySelector('.inp-qte').value) || 0;
  const pu   = parseFloat(row.querySelector('.inp-pu').value)  || 0;
  const taxe = row.querySelector('.inp-taxe').value;
  const ht   = qte * pu;
  const tva  = taxe === 'TVA' ? ht * TVA_RATE : 0;
  const ttc  = ht + tva;
  row.querySelector('.disp-ht').textContent  = fmt(ht);
  row.querySelector('.disp-tva').textContent = fmt(tva);
  row.querySelector('.disp-ttc').textContent = fmt(ttc);
  row.dataset.ht  = ht;
  row.dataset.tva = tva;
  row.dataset.ttc = ttc;
  updateTotals();
}

function updateTotals() {
  let ht = 0, tva = 0, ttc = 0;
  document.querySelectorAll('#linesBody tr[data-idx]').forEach(r => {
    ht  += parseFloat(r.dataset.ht  || 0);
    tva += parseFloat(r.dataset.tva || 0);
    ttc += parseFloat(r.dataset.ttc || 0);
  });
  document.getElementById('sumHT').textContent  = fmt(ht);
  document.getElementById('sumTVA').textContent = fmt(tva);
  document.getElementById('sumTTC').textContent = fmt(ttc);
  document.getElementById('noLines').classList.toggle('hidden', document.querySelectorAll('#linesBody tr[data-idx]').length > 0);
}

function addLigne(data) {
  data = data || {};
  const idx = lineIdx++;
  const body = document.getElementById('linesBody');
  const tr = document.createElement('tr');
  tr.dataset.idx = idx;
  tr.dataset.ht = 0; tr.dataset.tva = 0; tr.dataset.ttc = 0;
  tr.className = 'border-b border-slate-700/50';

  const typeLigne = data.type_ligne || 'Service';
  const taxe      = data.type_taxe  || 'TVA';

  if (VIEW_MODE) {
    const ht  = parseFloat(data.montant_ht  || 0);
    const tva = parseFloat(data.montant_tva || 0);
    const ttc = parseFloat(data.montant_ttc || 0);
    tr.innerHTML = `
      <td class="px-3 py-2.5 text-slate-500 text-xs">${idx + 1}</td>
      <td class="px-3 py-2.5">
        <div class="font-medium text-slate-200">${esc(data.designation)}</div>
        ${data.description ? `<div class="text-xs text-slate-500 mt-0.5">${esc(data.description)}</div>` : ''}
      </td>
      <td class="px-3 py-2.5 text-slate-400 text-xs">${esc(typeLigne)}</td>
      <td class="px-3 py-2.5 text-right text-slate-200">${parseFloat(data.quantite || 1).toLocaleString('fr-FR', {maximumFractionDigits: 3})}</td>
      <td class="px-3 py-2.5 text-slate-400">${esc(data.unite || 'unite')}</td>
      <td class="px-3 py-2.5 text-right text-slate-200">${fmt(data.prix_unitaire_ht || 0)}</td>
      <td class="px-3 py-2.5 text-center text-slate-400 text-xs">${esc(taxe)}</td>
      <td class="px-3 py-2.5 text-right text-slate-200 disp-ht">${fmt(ht)}</td>
      <td class="px-3 py-2.5 text-right text-slate-400 disp-tva">${fmt(tva)}</td>
      <td class="px-3 py-2.5 text-right font-semibold text-indigo-400 disp-ttc">${fmt(ttc)}</td>
    `;
    tr.dataset.ht  = ht;
    tr.dataset.tva = tva;
    tr.dataset.ttc = ttc;
    body.appendChild(tr);
    updateTotals();
  } else {
    tr.innerHTML = `
      <td class="px-2 py-2 text-slate-500 text-xs">${idx + 1}</td>
      <td class="px-2 py-2 min-w-[220px]">
        <div class="flex items-center gap-1 mb-1">
          <input class="inp-desg flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-slate-100 text-sm" placeholder="Designation *" value="${esc(data.designation || '')}">
          <button type="button" class="shrink-0 text-indigo-400 hover:text-indigo-300 px-1.5" onclick="openArticles(${idx})" title="Choisir article"><i class="fas fa-search text-xs"></i></button>
        </div>
        <input type="hidden" class="inp-article" value="${esc(data.article_id || '')}">
        <input type="hidden" class="inp-compte" value="${esc(data.compte_produit || '')}">
        <input class="inp-desc w-full bg-slate-600 border border-slate-600 rounded px-2 py-1 text-slate-400 text-xs" placeholder="Description..." value="${esc(data.description || '')}">
      </td>
      <td class="px-2 py-2">
        <select class="inp-type w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-slate-100">
          <option value="Service" ${typeLigne === 'Service' ? 'selected' : ''}>Service</option>
          <option value="Bien" ${typeLigne === 'Bien' ? 'selected' : ''}>Bien</option>
        </select>
      </td>
      <td class="px-2 py-2">
        <input type="number" class="inp-qte w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-right text-slate-100 text-sm" value="${parseFloat(data.quantite || 1)}" min="0.001" step="0.001">
      </td>
      <td class="px-2 py-2">
        <input class="inp-unite w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-slate-100 text-xs" value="${esc(data.unite || 'unite')}">
      </td>
      <td class="px-2 py-2">
        <input type="number" class="inp-pu w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-right text-slate-100 text-sm" value="${parseFloat(data.prix_unitaire_ht || 0).toFixed(2)}" min="0" step="0.01">
      </td>
      <td class="px-2 py-2">
        <select class="inp-taxe w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-slate-100">
          <option value="TVA" ${taxe === 'TVA' ? 'selected' : ''}>TVA 18%</option>
          <option value="Aucune" ${taxe === 'Aucune' ? 'selected' : ''}>Aucune</option>
        </select>
      </td>
      <td class="px-2 py-2 text-right disp-ht text-slate-200 text-sm font-mono">0</td>
      <td class="px-2 py-2 text-right disp-tva text-slate-400 text-sm font-mono">0</td>
      <td class="px-2 py-2 text-right disp-ttc text-indigo-400 font-semibold text-sm font-mono">0</td>
      <td class="px-2 py-2 text-center">
        <button type="button" onclick="this.closest('tr').remove(); updateTotals();" class="text-slate-500 hover:text-red-400 transition-colors"><i class="fas fa-trash text-xs"></i></button>
      </td>
    `;
    body.appendChild(tr);
    tr.querySelector('.inp-qte').addEventListener('input',  () => calcLine(tr));
    tr.querySelector('.inp-pu').addEventListener('input',   () => calcLine(tr));
    tr.querySelector('.inp-taxe').addEventListener('change',() => calcLine(tr));
    calcLine(tr);
  }
}

async function loadArticles() {
  if (allArticles.length) return;
  try {
    const r = await fetch('../settings/api_catalogue.php?action=liste_vente');
    const d = await r.json();
    allArticles = d.articles || [];
  } catch(e) { allArticles = []; }
}

async function openArticles(idx) {
  currentLine = idx;
  await loadArticles();
  renderArticles('');
  document.getElementById('searchArticle').value = '';
  document.getElementById('articlesPanel').classList.remove('hidden');
  setTimeout(() => document.getElementById('searchArticle').focus(), 50);
}

function closeArticles() { document.getElementById('articlesPanel').classList.add('hidden'); }

function renderArticles(q) {
  const list = document.getElementById('articlesList');
  const q_l = q.toLowerCase();
  const filtered = allArticles.filter(a => !q || (a.designation || '').toLowerCase().includes(q_l) || (a.reference || '').toLowerCase().includes(q_l));
  if (!filtered.length) { list.innerHTML = '<div class="text-slate-500 text-sm px-3 py-2">Aucun resultat.</div>'; return; }
  list.innerHTML = filtered.slice(0, 30).map(a => `
    <div class="px-3 py-2 rounded-lg hover:bg-slate-700 cursor-pointer transition-colors" onclick='selectArticle(${JSON.stringify(a)})'>
      <div class="text-slate-200 text-sm">${esc(a.designation)}</div>
      <div class="text-slate-500 text-xs">${esc(a.reference || '')}${a.prix_vente_ht ? ' - ' + fmt(a.prix_vente_ht) + ' FCFA HT' : ''}</div>
    </div>
  `).join('');
}

function selectArticle(a) {
  const rows = document.querySelectorAll('#linesBody tr[data-idx]');
  let row = null;
  rows.forEach(r => { if (parseInt(r.dataset.idx) === currentLine) row = r; });
  if (!row) return;
  row.querySelector('.inp-desg').value   = a.designation || '';
  row.querySelector('.inp-article').value= String(a.id || '');
  row.querySelector('.inp-compte').value = a.compte_produit || '';
  row.querySelector('.inp-pu').value     = parseFloat(a.prix_vente_ht || 0).toFixed(2);
  if (a.type_article && row.querySelector('.inp-type')) {
    row.querySelector('.inp-type').value = a.type_article === 'Bien' ? 'Bien' : 'Service';
  }
  calcLine(row);
  closeArticles();
}

document.getElementById('searchArticle').addEventListener('input', function() { renderArticles(this.value); });
document.getElementById('articlesPanel').addEventListener('click', e => { if (e.target === e.currentTarget) closeArticles(); });

document.getElementById('formBC')?.addEventListener('submit', function(e) {
  const lines = [];
  document.querySelectorAll('#linesBody tr[data-idx]').forEach(row => {
    const desg = row.querySelector('.inp-desg');
    if (!desg) return;
    lines.push({
      article_id:       row.querySelector('.inp-article')?.value || null,
      designation:      desg.value,
      description:      row.querySelector('.inp-desc')?.value   || '',
      type_ligne:       row.querySelector('.inp-type')?.value   || 'Service',
      quantite:         row.querySelector('.inp-qte')?.value    || 1,
      unite:            row.querySelector('.inp-unite')?.value  || 'unite',
      prix_unitaire_ht: row.querySelector('.inp-pu')?.value     || 0,
      type_taxe:        row.querySelector('.inp-taxe')?.value   || 'TVA',
      montant_ht:       row.dataset.ht  || 0,
      montant_tva:      row.dataset.tva || 0,
      montant_ttc:      row.dataset.ttc || 0,
      compte_produit:   row.querySelector('.inp-compte')?.value || null,
    });
  });
  if (!lines.length) { e.preventDefault(); alert('Ajoutez au moins une ligne de commande.'); return; }
  document.getElementById('lignesJson').value = JSON.stringify(lines);
});

// Init
initLines.forEach(l => addLigne(l));
</script>
</body>
</html>
