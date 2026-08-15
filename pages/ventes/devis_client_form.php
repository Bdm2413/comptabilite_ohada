<?php
require_once '../../config/config.php';
requireLogin();

$db         = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$createur = $_SESSION['user_name'] ?? 'Système';
$viewOnly = isset($_GET['view']) && $_GET['view'] === '1';

// Génération numéro devis client
function genererNumeroDevisClient(PDO $db, int $societe_id): string {
    $annee = date('Y');
    $stmt  = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(numero_devis,'-',-1) AS UNSIGNED)) FROM devis_clients WHERE societe_id=? AND numero_devis LIKE ?");
    $stmt->execute([$societe_id, "DVC-$annee-%"]);
    return "DVC-$annee-" . str_pad((int)$stmt->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
}

$parseNum = fn(string $v): float => (float)str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $v ?: '0');

// Clients
$stmtCli = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Client' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtCli->execute([$societe_id]);
$clients = $stmtCli->fetchAll();

$devis  = null;
$lignes = [];
$isEdit = isset($_GET['id']);

if ($isEdit) {
    $stmt = $db->prepare("SELECT d.*, pt.nom AS client_nom FROM devis_clients d JOIN plan_tiers pt ON d.id_client=pt.id WHERE d.id=? AND d.societe_id=?");
    $stmt->execute([(int)$_GET['id'], $societe_id]);
    $devis = $stmt->fetch();
    if (!$devis) { $_SESSION['flash'] = ['type'=>'error','message'=>'Devis introuvable.']; header('Location: devis_clients.php'); exit; }
    if (!$viewOnly && $devis['statut'] !== 'Brouillon') $viewOnly = true;
    $stmtL = $db->prepare("SELECT * FROM lignes_devis_client WHERE id_devis=? ORDER BY ordre, id");
    $stmtL->execute([(int)$_GET['id']]);
    $lignes = $stmtL->fetchAll();
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewOnly) {
    $id_client     = (int)($_POST['id_client']     ?? 0);
    $date_devis    = $_POST['date_devis']    ?? date('Y-m-d');
    $date_validite = $_POST['date_validite'] ?? null;
    $objet         = trim($_POST['objet']    ?? '');
    $notes         = trim($_POST['notes']    ?? '');

    if (!$id_client) $errors[] = 'Veuillez sélectionner un client.';
    if (!$objet)     $errors[] = "L'objet est requis.";

    $lignesPost = [];
    foreach ($_POST['lignes'] ?? [] as $i => $lg) {
        if (empty(trim($lg['designation'] ?? ''))) continue;
        $qte    = $parseNum($lg['quantite']        ?? '1');
        $pu     = $parseNum($lg['prix_unitaire_ht'] ?? '0');
        $tr     = $lg['type_remise'] ?? 'Aucune';
        $vr     = $parseNum($lg['valeur_remise']   ?? '0');
        $tt     = $lg['type_taxe']  ?? 'TVA';
        $brut   = $qte * $pu;
        $remise = ($tr === 'Pourcentage') ? $brut * $vr / 100 : (($tr === 'Montant') ? $vr : 0);
        $ht     = $brut - $remise;
        $tva    = ($tt === 'TVA') ? round($ht * 18 / 100, 2) : 0;
        $lignesPost[] = [
            'ordre'           => $i,
            'article_id'      => (int)($lg['article_id'] ?? 0) ?: null,
            'reference'       => trim($lg['reference']   ?? ''),
            'designation'     => trim($lg['designation']),
            'description'     => trim($lg['description'] ?? ''),
            'type_ligne'      => $lg['type_ligne']  ?? 'Service',
            'quantite'        => $qte,
            'unite'           => trim($lg['unite']   ?? 'unité'),
            'prix_unitaire_ht'=> $pu,
            'type_remise'     => $tr,
            'valeur_remise'   => $vr,
            'montant_ht'      => round($ht, 2),
            'type_taxe'       => $tt,
            'montant_tva'     => $tva,
            'montant_ttc'     => round($ht + $tva, 2),
            'compte_produit'  => trim($lg['compte_produit'] ?? ''),
        ];
    }
    if (empty($lignesPost)) $errors[] = 'Ajoutez au moins une ligne.';

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $mbrut   = array_sum(array_map(fn($l) => $l['quantite'] * $l['prix_unitaire_ht'], $lignesPost));
            $mremise = array_sum(array_map(fn($l) => $l['montant_ht'] - ($l['quantite'] * $l['prix_unitaire_ht'] - ($l['valeur_remise'] * ($l['type_remise'] === 'Pourcentage' ? $l['quantite'] * $l['prix_unitaire_ht'] / 100 : 1))), $lignesPost)); // simplified
            $mht     = round(array_sum(array_column($lignesPost, 'montant_ht')),  2);
            $mtva    = round(array_sum(array_column($lignesPost, 'montant_tva')), 2);
            $mttc    = round(array_sum(array_column($lignesPost, 'montant_ttc')), 2);
            $mbrut   = round(array_sum(array_map(fn($l) => $l['quantite'] * $l['prix_unitaire_ht'], $lignesPost)), 2);
            $mremise = round($mbrut - $mht, 2);

            if ($isEdit) {
                $db->prepare("UPDATE devis_clients SET id_client=?,date_devis=?,date_validite=?,objet=?,notes=?,montant_brut=?,montant_remise=?,montant_ht=?,montant_tva=?,montant_ttc=?,date_modification=NOW() WHERE id=? AND societe_id=? AND statut='Brouillon'")->execute([$id_client,$date_devis,$date_validite?:null,$objet,$notes,$mbrut,$mremise,$mht,$mtva,$mttc,(int)$_GET['id'],$societe_id]);
                $devisId = (int)$_GET['id'];
                $numero  = $devis['numero_devis'];
                $db->prepare("DELETE FROM lignes_devis_client WHERE id_devis=?")->execute([$devisId]);
            } else {
                $numero = genererNumeroDevisClient($db, $societe_id);
                $db->prepare("INSERT INTO devis_clients (societe_id,numero_devis,id_client,date_devis,date_validite,objet,notes,montant_brut,montant_remise,montant_ht,montant_tva,montant_ttc,createur) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$societe_id,$numero,$id_client,$date_devis,$date_validite?:null,$objet,$notes,$mbrut,$mremise,$mht,$mtva,$mttc,$createur]);
                $devisId = (int)$db->lastInsertId();
            }

            $stmtL = $db->prepare("INSERT INTO lignes_devis_client (id_devis,article_id,reference,designation,description,type_ligne,quantite,unite,prix_unitaire_ht,type_remise,valeur_remise,montant_ht,type_taxe,montant_tva,montant_ttc,compte_produit,ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($lignesPost as $lg) {
                $stmtL->execute([$devisId,$lg['article_id'],$lg['reference'],$lg['designation'],$lg['description'],$lg['type_ligne'],$lg['quantite'],$lg['unite'],$lg['prix_unitaire_ht'],$lg['type_remise'],$lg['valeur_remise'],$lg['montant_ht'],$lg['type_taxe'],$lg['montant_tva'],$lg['montant_ttc'],$lg['compte_produit']?:null,$lg['ordre']]);
            }

            $db->commit();
            $_SESSION['flash'] = ['type'=>'success','message'=>"Devis $numero enregistré."];
            header("Location: devis_client_form.php?id=$devisId"); exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$f_client  = $devis['id_client']      ?? ($_POST['id_client']      ?? '');
$f_date    = $devis['date_devis']     ?? ($_POST['date_devis']     ?? date('Y-m-d'));
$f_valid   = $devis['date_validite']  ?? ($_POST['date_validite']  ?? date('Y-m-d', strtotime('+30 days')));
$f_objet   = $devis['objet']          ?? ($_POST['objet']          ?? '');
$f_notes   = $devis['notes']          ?? ($_POST['notes']          ?? '');

$pageTitle = $isEdit ? 'Devis ' . htmlspecialchars($devis['numero_devis'] ?? '') : 'Nouveau devis client';

$statutCls = ['Brouillon'=>'bg-slate-700 text-slate-300','Envoye'=>'bg-blue-500/20 text-blue-300','Accepte'=>'bg-green-500/20 text-green-300','Refuse'=>'bg-red-500/20 text-red-400','Expire'=>'bg-slate-600 text-slate-400','Converti'=>'bg-purple-500/20 text-purple-300'];
$statutLbl = ['Brouillon'=>'Brouillon','Envoye'=>'Envoyé','Accepte'=>'Accepté','Refuse'=>'Refusé','Expire'=>'Expiré','Converti'=>'Converti'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> - OHADA ERP</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
  <?php include '../../includes/sidebar.php'; ?>

  <main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6 flex items-start justify-between">
      <div>
        <div class="flex items-center gap-2 text-slate-400 text-sm mb-2">
          <a href="devis_clients.php" class="hover:text-teal-400 transition">Devis clients</a>
          <span>/</span>
          <span class="text-slate-200"><?= $pageTitle ?></span>
        </div>
        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-teal-400 to-cyan-400">
          <i class="fas fa-file-alt mr-2"></i><?= $pageTitle ?>
        </h1>
        <?php if ($isEdit && $devis): ?>
          <div class="flex items-center gap-3 mt-2">
            <span class="px-3 py-1 rounded-full text-xs border border-slate-600 <?= $statutCls[$devis['statut']] ?? '' ?>">
              <?= $statutLbl[$devis['statut']] ?? $devis['statut'] ?>
            </span>
            <?php if ($devis['statut'] === 'Converti' && $devis['id_bc_client']): ?>
              <a href="bc_client_form.php?id=<?= $devis['id_bc_client'] ?>&view=1"
                 class="text-xs text-purple-400 hover:underline">
                <i class="fas fa-arrow-right mr-1"></i>Voir le BC client
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="flex gap-2">
        <?php if ($isEdit && $devis && $devis['statut'] === 'Accepte' && !$devis['id_bc_client']): ?>
          <a href="bc_client_form.php?from_devis=<?= $devis['id'] ?>"
             class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm flex items-center gap-2 transition">
            <i class="fas fa-arrow-right"></i>Convertir en BC
          </a>
        <?php endif; ?>
        <?php if (!$viewOnly): ?>
          <button type="submit" form="form-devis"
                  class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm flex items-center gap-2 transition">
            <i class="fas fa-save"></i>Enregistrer
          </button>
        <?php endif; ?>
        <a href="devis_clients.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm flex items-center gap-2 transition">
          <i class="fas fa-arrow-left"></i>Retour
        </a>
      </div>
    </div>

    <!-- Erreurs -->
    <?php if (!empty($errors)): ?>
      <div class="mb-5 p-4 bg-red-500/10 border border-red-500/50 rounded-xl text-red-300 text-sm space-y-1">
        <?php foreach ($errors as $e): ?><p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="mb-5 p-4 rounded-xl text-sm <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-500/10 border border-green-500/40 text-green-300' : 'bg-red-500/10 border border-red-500/40 text-red-300' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
      </div>
      <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <form id="form-devis" method="POST">

      <!-- En-tête devis -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 mb-6">
        <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-4">Informations générales</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-xs text-slate-400 mb-1">Client *</label>
            <select name="id_client" required <?= $viewOnly ? 'disabled' : '' ?>
                    class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
              <option value="">-- Sélectionner --</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $f_client == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs text-slate-400 mb-1">Date devis *</label>
            <input type="date" name="date_devis" value="<?= htmlspecialchars($f_date) ?>" required
                   <?= $viewOnly ? 'readonly' : '' ?>
                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
          </div>
          <div>
            <label class="block text-xs text-slate-400 mb-1">Date de validité</label>
            <input type="date" name="date_validite" value="<?= htmlspecialchars($f_valid) ?>"
                   <?= $viewOnly ? 'readonly' : '' ?>
                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
          </div>
          <div class="md:col-span-3">
            <label class="block text-xs text-slate-400 mb-1">Objet *</label>
            <input type="text" name="objet" value="<?= htmlspecialchars($f_objet) ?>" required
                   placeholder="Objet du devis"
                   <?= $viewOnly ? 'readonly' : '' ?>
                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
          </div>
          <div class="md:col-span-3">
            <label class="block text-xs text-slate-400 mb-1">Notes / Conditions</label>
            <textarea name="notes" rows="2" <?= $viewOnly ? 'readonly' : '' ?>
                      class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none resize-none"><?= htmlspecialchars($f_notes) ?></textarea>
          </div>
        </div>
      </div>

      <!-- Lignes -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Lignes du devis</h2>
          <?php if (!$viewOnly): ?>
            <button type="button" onclick="addLigne()"
                    class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-xs flex items-center gap-1.5 transition">
              <i class="fas fa-plus"></i>Ajouter
            </button>
          <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr class="border-b border-slate-700 text-slate-400">
                <th class="pb-2 text-left pr-2 w-52">Article</th>
                <th class="pb-2 text-left pr-2 w-16">Type</th>
                <th class="pb-2 text-center pr-2 w-16">Qté</th>
                <th class="pb-2 text-center pr-2 w-14">Unité</th>
                <th class="pb-2 text-right pr-2 w-28">Prix HT</th>
                <th class="pb-2 text-center pr-2 w-32">Remise</th>
                <th class="pb-2 text-center pr-2 w-16">Taxe</th>
                <th class="pb-2 text-right pr-2 w-24">HT net</th>
                <th class="pb-2 text-right pr-2 w-20">TVA</th>
                <th class="pb-2 text-right w-28">TTC</th>
                <?php if (!$viewOnly): ?><th class="pb-2 w-8"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody id="lignes-container"></tbody>
          </table>
        </div>
      </div>

      <!-- Totaux -->
      <div class="flex justify-end mb-6">
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 w-full md:w-80 space-y-2 text-sm">
          <div class="flex justify-between text-slate-400"><span>Montant brut HT</span><span id="tot-brut" class="font-mono">0</span></div>
          <div class="flex justify-between text-red-400"><span>Remises</span><span id="tot-remise" class="font-mono">-0</span></div>
          <div class="flex justify-between text-slate-200 border-t border-slate-700 pt-2"><span>Total HT</span><span id="tot-ht" class="font-mono font-semibold">0</span></div>
          <div class="flex justify-between text-slate-400"><span>TVA (18%)</span><span id="tot-tva" class="font-mono">+0</span></div>
          <div class="flex justify-between text-teal-300 border-t border-slate-700 pt-2 text-base"><span class="font-semibold">Total TTC</span><span id="tot-ttc" class="font-mono font-bold">0 F CFA</span></div>
        </div>
      </div>

    </form>
  </main>
</div>

<!-- Template ligne -->
<template id="ligne-tpl">
  <tr class="ligne-devis border-b border-slate-700/40">
    <td class="pr-2 py-1.5 align-top">
      <select class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs sel-article" onchange="onSelectArt(this)">
        <option value="">-- Article / libre --</option>
      </select>
      <input type="hidden" name="lignes[IDX][article_id]"    class="inp-art-id">
      <input type="hidden" name="lignes[IDX][designation]"   class="inp-desig">
      <input type="hidden" name="lignes[IDX][reference]"     class="inp-ref">
      <input type="hidden" name="lignes[IDX][compte_produit]" class="inp-compte">
    </td>
    <td class="pr-2 py-1.5 align-top">
      <select name="lignes[IDX][type_ligne]" class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs">
        <option>Bien</option><option selected>Service</option>
      </select>
    </td>
    <td class="pr-2 py-1.5 align-top">
      <input type="text" name="lignes[IDX][quantite]" value="1" onchange="calcLigne(this)"
             class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center inp-qte">
    </td>
    <td class="pr-2 py-1.5 align-top">
      <input type="text" name="lignes[IDX][unite]" value="unité"
             class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center inp-unite">
    </td>
    <td class="pr-2 py-1.5 align-top">
      <input type="text" name="lignes[IDX][prix_unitaire_ht]" value="0" onchange="calcLigne(this)"
             class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right inp-prix">
    </td>
    <td class="pr-2 py-1.5 align-top">
      <div class="flex gap-1">
        <select name="lignes[IDX][type_remise]" onchange="calcLigne(this)"
                class="w-14 px-1 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs sel-remise">
          <option>Aucune</option><option>Pourcentage</option><option>Montant</option>
        </select>
        <input type="text" name="lignes[IDX][valeur_remise]" value="0" onchange="calcLigne(this)"
               class="w-12 px-1 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right inp-remise">
      </div>
    </td>
    <td class="pr-2 py-1.5 align-top">
      <select name="lignes[IDX][type_taxe]" onchange="calcLigne(this)"
              class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs sel-taxe">
        <option>Aucune</option><option selected>TVA</option>
      </select>
    </td>
    <td class="pr-2 py-1.5 align-top text-right"><span class="font-mono text-slate-300 lbl-ht">0</span></td>
    <td class="pr-2 py-1.5 align-top text-right"><span class="font-mono text-slate-400 lbl-tva">0</span></td>
    <td class="py-1.5 align-top text-right"><span class="font-mono text-slate-200 font-medium lbl-ttc">0</span></td>
    <td class="py-1.5 align-top">
      <button type="button" onclick="removeLigne(this)" class="p-1 text-red-400 hover:text-red-300 transition">
        <i class="fas fa-times"></i>
      </button>
    </td>
  </tr>
</template>

<script>
let idx = 0, catalogue = [];
const viewOnly       = <?= $viewOnly ? 'true' : 'false' ?>;
const lignesInit     = <?= json_encode($lignes, JSON_UNESCAPED_UNICODE) ?>;

async function loadCatalogue() {
  try {
    const r = await fetch('../settings/api_catalogue.php?action=liste_vente');
    const d = await r.json();
    if (d.success) catalogue = d.articles;
  } catch(e) {}
}

function pN(s) { return parseFloat(String(s||0).replace(/[\s ]/g,'').replace(',','.')) || 0; }
function fN(n) { return Math.round(n).toLocaleString('fr-FR'); }

function calcLigne(el) {
  const row  = el.closest('tr');
  const qte  = pN(row.querySelector('.inp-qte').value);
  const prix = pN(row.querySelector('.inp-prix').value);
  const tr   = row.querySelector('.sel-remise').value;
  const vr   = pN(row.querySelector('.inp-remise').value);
  const taxe = row.querySelector('.sel-taxe').value;
  const brut = qte * prix;
  const rem  = tr==='Pourcentage' ? brut*vr/100 : (tr==='Montant' ? vr : 0);
  const ht   = brut - rem;
  const tva  = taxe==='TVA' ? ht*18/100 : 0;
  row.querySelector('.lbl-ht').textContent  = fN(ht);
  row.querySelector('.lbl-tva').textContent = fN(tva);
  row.querySelector('.lbl-ttc').textContent = fN(ht+tva);
  calcTotaux();
}

function calcTotaux() {
  let brut=0,remise=0,ht=0,tva=0;
  document.querySelectorAll('#lignes-container tr.ligne-devis').forEach(row => {
    const qte=pN(row.querySelector('.inp-qte').value), prix=pN(row.querySelector('.inp-prix').value);
    const tr=row.querySelector('.sel-remise').value, vr=pN(row.querySelector('.inp-remise').value);
    const b=qte*prix, r=tr==='Pourcentage'?b*vr/100:(tr==='Montant'?vr:0), h=b-r;
    brut+=b; remise+=r; ht+=h;
    tva += row.querySelector('.sel-taxe').value==='TVA' ? h*18/100 : 0;
  });
  document.getElementById('tot-brut').textContent   = fN(brut);
  document.getElementById('tot-remise').textContent = '-'+fN(remise);
  document.getElementById('tot-ht').textContent     = fN(ht);
  document.getElementById('tot-tva').textContent    = '+'+fN(tva);
  document.getElementById('tot-ttc').textContent    = fN(ht+tva)+' F CFA';
}

function fillSelect(sel, selId) {
  sel.innerHTML = '<option value="">-- Article / saisie libre --</option>';
  catalogue.forEach(a => {
    const o = document.createElement('option');
    o.value=a.id; o.textContent=a.designation;
    o.dataset.ref=a.reference||''; o.dataset.type=a.type_article||'Service';
    o.dataset.unite=a.unite||'unité'; o.dataset.prix=a.prix_vente_ht||0;
    o.dataset.compte=a.compte_vente||''; o.dataset.taxe=a.type_taxe_defaut||'TVA';
    if (String(a.id)===String(selId)) o.selected=true;
    sel.appendChild(o);
  });
}

function onSelectArt(sel) {
  const row=sel.closest('tr'), opt=sel.options[sel.selectedIndex];
  if (opt.value) {
    row.querySelector('.inp-art-id').value = opt.value;
    row.querySelector('.inp-desig').value  = opt.textContent;
    row.querySelector('.inp-ref').value    = opt.dataset.ref||'';
    row.querySelector('.inp-compte').value = opt.dataset.compte||'';
    row.querySelector('.inp-unite').value  = opt.dataset.unite||'unité';
    row.querySelector('.inp-prix').value   = parseFloat(opt.dataset.prix||0).toLocaleString('fr-FR');
    row.querySelector('.sel-taxe').value   = opt.dataset.taxe==='TVA'?'TVA':'Aucune';
    const st=row.querySelector('select[name*="type_ligne"]');
    if(st) st.value=opt.dataset.type||'Service';
  } else {
    row.querySelector('.inp-art-id').value='';
    row.querySelector('.inp-desig').value='';
    row.querySelector('.inp-ref').value='';
    row.querySelector('.inp-compte').value='';
  }
  calcLigne(sel);
}

function addLigne(data=null) {
  const tpl=document.getElementById('ligne-tpl'), clone=tpl.content.cloneNode(true);
  const tr=clone.querySelector('tr');
  tr.querySelectorAll('[name*="IDX"]').forEach(el => el.name=el.name.replace('IDX',idx));
  const sel=tr.querySelector('.sel-article, .sel-article, select.sel-article') || tr.querySelector('select:first-child');
  // find the article select
  const artSel = tr.querySelector('.sel-article') || tr.querySelector('select[onchange*="onSelectArt"]');
  if (artSel) fillSelect(artSel, data?.article_id||'');
  if (viewOnly) { tr.querySelectorAll('input,select,textarea').forEach(el=>el.setAttribute('disabled','')); const b=tr.querySelector('button'); if(b)b.remove(); }
  if (data) {
    tr.querySelector('.inp-art-id').value = data.article_id||'';
    tr.querySelector('.inp-desig').value  = data.designation||'';
    tr.querySelector('.inp-ref').value    = data.reference||'';
    tr.querySelector('.inp-compte').value = data.compte_produit||'';
    const st=tr.querySelector('select[name*="type_ligne"]'); if(st) st.value=data.type_ligne||'Service';
    tr.querySelector('.inp-qte').value    = parseFloat(data.quantite||1).toLocaleString('fr-FR');
    tr.querySelector('.inp-unite').value  = data.unite||'unité';
    tr.querySelector('.inp-prix').value   = parseFloat(data.prix_unitaire_ht||0).toLocaleString('fr-FR');
    tr.querySelector('.sel-remise').value = data.type_remise||'Aucune';
    tr.querySelector('.inp-remise').value = parseFloat(data.valeur_remise||0).toLocaleString('fr-FR');
    tr.querySelector('.sel-taxe').value   = data.type_taxe||'TVA';
  }
  document.getElementById('lignes-container').appendChild(clone);
  idx++;
  calcLigne(tr.querySelector('.inp-qte') || document.querySelector('#lignes-container tr:last-child .inp-qte'));
}

function removeLigne(btn) {
  if (document.querySelectorAll('#lignes-container tr.ligne-devis').length<=1) { alert('Au moins une ligne requise.'); return; }
  btn.closest('tr').remove(); calcTotaux();
}

document.addEventListener('DOMContentLoaded', async () => {
  await loadCatalogue();
  lignesInit.length ? lignesInit.forEach(l=>addLigne(l)) : addLigne();
});

// Sync designations libres avant submit
document.getElementById('form-devis')?.addEventListener('submit', () => {
  document.querySelectorAll('#lignes-container tr.ligne-devis').forEach(row => {
    const artSel = row.querySelector('select[onchange*="onSelectArt"]');
    const inp = row.querySelector('.inp-desig');
    if (!inp.value && artSel && artSel.selectedIndex>0) inp.value = artSel.options[artSel.selectedIndex].textContent;
  });
});
</script>
</body>
</html>
