<?php
require_once '../../config/config.php';
requireLogin();

$db         = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$createur = $_SESSION['user_name'] ?? 'Systeme';
$isView   = isset($_GET['view']);
$editId   = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$fromBcId = isset($_GET['from_bc'])? (int)$_GET['from_bc']: 0;

$facture  = null;
$lignes   = [];
$bcSource = null;

// Load existing facture
if ($editId) {
    $stmt = $db->prepare("SELECT f.*, pt.nom AS fourn_nom, pt.compte_tiers AS fourn_compte FROM factures_fournisseurs f JOIN plan_tiers pt ON f.id_fournisseur=pt.id WHERE f.id=? AND f.societe_id=?");
    $stmt->execute([$editId, $societe_id]);
    $facture = $stmt->fetch();
    if (!$facture) { header('Location: factures_fournisseurs.php'); exit; }
    if (!$isView && $facture['statut'] !== 'brouillon') $isView = true;
    $stmtL = $db->prepare("SELECT * FROM lignes_facture_fournisseur WHERE facture_id=? ORDER BY ordre");
    $stmtL->execute([$editId]);
    $lignes = $stmtL->fetchAll();
}

// Load BC source for conversion
if ($fromBcId && !$editId) {
    $stmt = $db->prepare("SELECT b.*, pt.nom AS fourn_nom FROM bons_commande b JOIN plan_tiers pt ON b.id_fournisseur=pt.id WHERE b.id=? AND b.societe_id=? AND b.statut NOT IN ('Brouillon','Annule','Cloture')");
    $stmt->execute([$fromBcId, $societe_id]);
    $bcSource = $stmt->fetch();
    if ($bcSource) {
        $stmtL = $db->prepare("SELECT * FROM lignes_bon_commande WHERE id_bon_commande=? ORDER BY ordre");
        $stmtL->execute([$fromBcId]);
        $bcLignes = $stmtL->fetchAll();
        foreach ($bcLignes as $bl) {
            $lignes[] = [
                'article_id'      => $bl['id_article_catalogue'] ?? null,
                'designation'     => $bl['designation'],
                'description'     => $bl['description'] ?? '',
                'type_ligne'      => $bl['type_ligne'],
                'quantite'        => $bl['quantite'],
                'unite'           => $bl['unite'],
                'prix_unitaire_ht'=> $bl['prix_unitaire_ht'],
                'type_remise'     => $bl['type_remise'] ?? 'Aucune',
                'valeur_remise'   => $bl['valeur_remise'] ?? 0,
                'type_taxe'       => $bl['type_taxe'] ?? 'Aucune',
                'montant_ht'      => $bl['montant_ht'] ?? 0,
                'montant_tva'     => 0,
                'montant_retenue' => 0,
                'montant_ttc'     => $bl['montant_ht'] ?? 0,
                'net_ligne'       => $bl['montant_ht'] ?? 0,
                'compte_charge'   => null,
            ];
        }
    }
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isView) {
    $action    = $_POST['action'] ?? 'enregistrer';
    $lignesPost = json_decode($_POST['lignes_json'] ?? '[]', true) ?: [];

    $id_fournisseur = (int)$_POST['id_fournisseur'];
    $date_facture   = $_POST['date_facture'] ?? date('Y-m-d');
    $delai_paiement = (int)($_POST['delai_paiement'] ?? 30);
    $date_echeance  = date('Y-m-d', strtotime("$date_facture + $delai_paiement days"));
    $num_fourn      = trim($_POST['numero_facture_fournisseur'] ?? '');
    $objet          = trim($_POST['objet'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    // Compute totals from lines
    $montant_ht = $montant_tva = $montant_ppssi = $montant_bnc = 0;
    foreach ($lignesPost as $l) {
        $montant_ht  += (float)($l['montant_ht']      ?? 0);
        $montant_tva += (float)($l['montant_tva']     ?? 0);
        if (($l['type_taxe'] ?? '') === 'PPSSI') $montant_ppssi += (float)($l['montant_retenue'] ?? 0);
        if (($l['type_taxe'] ?? '') === 'BNC')   $montant_bnc   += (float)($l['montant_retenue'] ?? 0);
    }
    $montant_ttc      = $montant_ht + $montant_tva;
    $montant_retenues = $montant_ppssi + $montant_bnc;
    $net_a_payer      = $montant_ttc - $montant_retenues;

    if ($editId) {
        $stmt = $db->prepare("UPDATE factures_fournisseurs SET id_fournisseur=?, numero_facture_fournisseur=?, date_facture=?, date_echeance=?, delai_paiement=?, objet=?, notes=?, montant_ht=?, montant_tva=?, montant_ppssi=?, montant_bnc=?, montant_ttc=?, montant_retenues=?, net_a_payer=? WHERE id=? AND societe_id=? AND statut='brouillon'");
        $stmt->execute([$id_fournisseur, $num_fourn ?: null, $date_facture, $date_echeance, $delai_paiement, $objet, $notes ?: null, $montant_ht, $montant_tva, $montant_ppssi, $montant_bnc, $montant_ttc, $montant_retenues, $net_a_payer, $editId, $societe_id]);
        $db->prepare("DELETE FROM lignes_facture_fournisseur WHERE facture_id=?")->execute([$editId]);
        $factureId = $editId;
        $numero_facture_interne = $facture['numero_facture_interne'];
    } else {
        // Auto-number FAF-YYYY-NNNN
        $year = date('Y');
        $stmtNum = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_facture_interne, 10) AS UNSIGNED)) AS mx FROM factures_fournisseurs WHERE societe_id=? AND numero_facture_interne LIKE ?");
        $stmtNum->execute([$societe_id, "FAF-$year-%"]);
        $row = $stmtNum->fetch();
        $seq = (int)($row['mx'] ?? 0) + 1;
        $numero_facture_interne = "FAF-$year-" . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("INSERT INTO factures_fournisseurs (societe_id, numero_facture_interne, numero_facture_fournisseur, id_fournisseur, id_bc, date_facture, date_echeance, delai_paiement, objet, notes, montant_ht, montant_tva, montant_ppssi, montant_bnc, montant_ttc, montant_retenues, net_a_payer, statut, createur) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'brouillon',?)");
        $stmt->execute([$societe_id, $numero_facture_interne, $num_fourn ?: null, $id_fournisseur, $fromBcId ?: null, $date_facture, $date_echeance, $delai_paiement, $objet, $notes ?: null, $montant_ht, $montant_tva, $montant_ppssi, $montant_bnc, $montant_ttc, $montant_retenues, $net_a_payer, $createur]);
        $factureId = (int)$db->lastInsertId();
    }

    // Insert lines
    $stmtL = $db->prepare("INSERT INTO lignes_facture_fournisseur (facture_id, article_id, designation, description, type_ligne, quantite, unite, prix_unitaire_ht, type_remise, valeur_remise, type_taxe, montant_ht, montant_tva, montant_retenue, montant_ttc, net_ligne, compte_charge, ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($lignesPost as $i => $l) {
        $stmtL->execute([$factureId, $l['article_id'] ?: null, $l['designation'], $l['description'] ?? null, $l['type_ligne'] ?? 'Service', (float)$l['quantite'], $l['unite'] ?? 'unite', (float)$l['prix_unitaire_ht'], $l['type_remise'] ?? 'Aucune', (float)($l['valeur_remise'] ?? 0), $l['type_taxe'] ?? 'Aucune', (float)$l['montant_ht'], (float)$l['montant_tva'], (float)$l['montant_retenue'], (float)$l['montant_ttc'], (float)$l['net_ligne'], $l['compte_charge'] ?: null, $i + 1]);
    }

    // Comptabilisation
    if ($action === 'comptabiliser') {
        try {
            $db->beginTransaction();

            requirePeriodeOuverte($date_facture, $societe_id);

            // Fournisseur account
            $stmtFourn = $db->prepare("SELECT compte_tiers FROM plan_tiers WHERE id=? AND societe_id=?");
            $stmtFourn->execute([$id_fournisseur, $societe_id]);
            $fourn_row   = $stmtFourn->fetch();
            $compte_fourn_str = $fourn_row['compte_tiers'] ?? '40110000';
            $compte_fourn_int = (int)$compte_fourn_str;

            // Numero ecriture ACH
            $prefix = 'ACH' . date('m', strtotime($date_facture)) . date('y', strtotime($date_facture));
            $stmtN  = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_ecriture, -4) AS UNSIGNED)) FROM ecritures WHERE numero_ecriture LIKE ? AND societe_id=?");
            $stmtN->execute([$prefix . '%', $societe_id]);
            $num_ecriture = $prefix . str_pad((int)$stmtN->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);

            // Create ecriture header
            $stmtE = $db->prepare("INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle, id_tiers, compte_tiers, num_piece, num_facture, type_document, montant_total, statut, createur, date_creation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmtE->execute([$societe_id, $num_ecriture, $date_facture, (int)date('m', strtotime($date_facture)), (int)date('Y', strtotime($date_facture)), 'ACH', $objet, $id_fournisseur, $compte_fourn_str, $numero_facture_interne, $num_fourn ?: $numero_facture_interne, 'Facture fournisseur', $montant_ttc, 'Valide', $createur]);
            $ecriture_id = (int)$db->lastInsertId();

            $stmtLig = $db->prepare("INSERT INTO lignes_ecriture (societe_id, id_ecriture, compte, compte_tiers, numero_facture, libelle, debit, credit, date_ligne, createur) VALUES (?,?,?,?,?,?,?,?,?,?)");

            // Credit fournisseur (net a payer)
            $stmtLig->execute([$societe_id, $ecriture_id, $compte_fourn_int, $compte_fourn_str, $numero_facture_interne, $objet, 0, round($net_a_payer, 2), $date_facture, $createur]);

            // Debit charges par compte
            $chargesGroupees = [];
            foreach ($lignesPost as $l) {
                $cp = $l['compte_charge'] ?: '60610000';
                $cp_int = (int)$cp;
                if (!isset($chargesGroupees[$cp_int])) $chargesGroupees[$cp_int] = 0;
                $chargesGroupees[$cp_int] += (float)$l['montant_ht'];
            }
            foreach ($chargesGroupees as $cp_int => $ht) {
                $stmtLig->execute([$societe_id, $ecriture_id, $cp_int, null, $numero_facture_interne, $objet, round($ht, 2), 0, $date_facture, $createur]);
            }

            // Debit TVA deductible
            if ($montant_tva > 0) {
                $stmtLig->execute([$societe_id, $ecriture_id, 44510000, null, $numero_facture_interne, 'TVA deductible - ' . $numero_facture_interne, round($montant_tva, 2), 0, $date_facture, $createur]);
            }

            // Credit retenue PPSSI
            if ($montant_ppssi > 0) {
                $stmtLig->execute([$societe_id, $ecriture_id, 44730000, null, $numero_facture_interne, 'PPSSI 2% retenue - ' . $numero_facture_interne, 0, round($montant_ppssi, 2), $date_facture, $createur]);
            }

            // Credit retenue BNC
            if ($montant_bnc > 0) {
                $stmtLig->execute([$societe_id, $ecriture_id, 44740000, null, $numero_facture_interne, 'BNC/RIBNC 7.5% retenue - ' . $numero_facture_interne, 0, round($montant_bnc, 2), $date_facture, $createur]);
            }

            // Mark as comptabilisee
            $db->prepare("UPDATE factures_fournisseurs SET statut_compta='comptabilisee', id_ecriture=?, statut=IF(statut='brouillon','recue',statut) WHERE id=?")->execute([$ecriture_id, $factureId]);

            $db->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => "Facture $numero_facture_interne comptabilisee - ecriture $num_ecriture creee."];
            header("Location: facture_fournisseur_form.php?id=$factureId&view=1"); exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur comptabilisation : ' . $e->getMessage()];
            header("Location: facture_fournisseur_form.php?id=$factureId"); exit;
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => $editId ? 'Facture mise a jour.' : "Facture $numero_facture_interne enregistree (brouillon)."];
    header("Location: facture_fournisseur_form.php?id=$factureId"); exit;
}

// Fournisseurs list
$stmtF = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type='Fournisseur' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtF->execute([$societe_id]);
$fournisseurs = $stmtF->fetchAll();

$pageTitle = $editId
    ? ($isView ? 'Facture ' . $facture['numero_facture_interne'] : 'Modifier ' . $facture['numero_facture_interne'])
    : ($fromBcId && $bcSource ? 'Nouvelle facture - BC ' . $bcSource['numero_bc'] : 'Nouvelle facture fournisseur');

$preForun   = $facture['id_fournisseur']            ?? ($bcSource['id_fournisseur'] ?? '');
$preDate    = $facture['date_facture']               ?? date('Y-m-d');
$preDelai   = $facture['delai_paiement']             ?? 30;
$preNumFourn= $facture['numero_facture_fournisseur'] ?? '';
$preObjet   = $facture['objet']                      ?? ($bcSource['objet'] ?? '');
$preNotes   = $facture['notes']                      ?? '';
$preEchec   = $facture['date_echeance']              ?? '';
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

    <?php if (!empty($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg <?= $flash['type']==='success' ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30' ?>">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

    <?php if ($bcSource): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-indigo-500/10 border border-indigo-500/30 text-indigo-300 text-sm flex items-center gap-2">
      <i class="fas fa-link text-xs"></i>
      Facture depuis le BC <strong><?= htmlspecialchars($bcSource['numero_bc']) ?></strong> - <?= htmlspecialchars($bcSource['fourn_nom']) ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex items-center gap-3 mb-8">
      <a href="factures_fournisseurs.php" class="text-slate-400 hover:text-white transition-colors"><i class="fas fa-arrow-left"></i></a>
      <div>
        <h1 class="text-2xl font-bold text-slate-100"><?= htmlspecialchars($pageTitle) ?></h1>
        <?php if ($isView && $facture): ?>
        <div class="mt-1 flex items-center gap-2 text-sm">
          <?php if ($facture['statut_compta'] === 'comptabilisee'): ?>
          <span class="text-green-400 text-xs"><i class="fas fa-check-circle mr-1"></i>Comptabilisee</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isView && $facture && $facture['statut'] === 'brouillon'): ?>
    <div class="mb-6 px-4 py-3 rounded-lg bg-slate-700/50 border border-slate-600 text-slate-300 text-sm flex items-center gap-2">
      <i class="fas fa-lock text-slate-400"></i>
      Lecture seule - statut : <strong class="text-slate-200"><?= htmlspecialchars($facture['statut']) ?></strong>
    </div>
    <?php endif; ?>

    <form method="POST" id="formFF">
      <input type="hidden" name="lignes_json" id="lignesJson">

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Left: form fields -->
        <div class="lg:col-span-2 bg-slate-800 rounded-xl p-6 border border-slate-700">
          <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-5">Informations de la facture</h2>
          <div class="grid grid-cols-2 gap-4">

            <div class="col-span-2">
              <label class="text-slate-400 text-xs block mb-1">Fournisseur *</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= htmlspecialchars($facture['fourn_nom'] ?? '') ?></div>
              <?php else: ?>
              <select name="id_fournisseur" required class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
                <option value="">- Choisir un fournisseur -</option>
                <?php foreach ($fournisseurs as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $preForun == $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nom']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">N° facture fournisseur</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= $preNumFourn ?: '-' ?></div>
              <?php else: ?>
              <input type="text" name="numero_facture_fournisseur" value="<?= htmlspecialchars($preNumFourn) ?>" placeholder="Numero de facture du fournisseur" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Date de reception *</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= date('d/m/Y', strtotime($preDate)) ?></div>
              <?php else: ?>
              <input type="date" name="date_facture" id="dateFacture" value="<?= $preDate ?>" required class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500" onchange="updateEcheance()">
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Delai de paiement</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= $preDelai ?> jours</div>
              <?php else: ?>
              <select name="delai_paiement" id="delaiPaiement" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500" onchange="updateEcheance()">
                <?php foreach ([0,15,30,45,60,90] as $d): ?>
                <option value="<?= $d ?>" <?= $preDelai == $d ? 'selected' : '' ?>><?= $d === 0 ? 'Comptant' : "$d jours" ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>

            <div>
              <label class="text-slate-400 text-xs block mb-1">Date d'echeance</label>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-400" id="lblEcheance">
                <?= $preEchec ? date('d/m/Y', strtotime($preEchec)) : '-' ?>
              </div>
            </div>

            <div class="col-span-2">
              <label class="text-slate-400 text-xs block mb-1">Objet *</label>
              <?php if ($isView): ?>
              <div class="bg-slate-700/40 border border-slate-600 rounded-lg px-3 py-2 text-slate-200"><?= htmlspecialchars($preObjet) ?></div>
              <?php else: ?>
              <input type="text" name="objet" value="<?= htmlspecialchars($preObjet) ?>" required placeholder="Objet de la facture" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:ring-2 focus:ring-indigo-500">
              <?php endif; ?>
            </div>

          </div>
        </div>

        <!-- Right: summary -->
        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 flex flex-col gap-2">
          <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Recapitulatif</h2>
          <?php if ($facture): ?>
          <div class="text-xs text-slate-500 font-mono mb-2">N° <?= htmlspecialchars($facture['numero_facture_interne']) ?></div>
          <?php endif; ?>
          <div class="flex justify-between text-sm">
            <span class="text-slate-400">Total HT</span>
            <span id="sumHT" class="text-slate-200 font-mono">0</span>
          </div>
          <div class="flex justify-between text-sm">
            <span class="text-slate-400">TVA 18%</span>
            <span id="sumTVA" class="text-slate-400 font-mono">0</span>
          </div>
          <div class="border-t border-slate-700 pt-2 flex justify-between text-sm">
            <span class="text-slate-300 font-medium">Total TTC</span>
            <span id="sumTTC" class="text-slate-200 font-mono font-medium">0</span>
          </div>
          <div class="flex justify-between text-sm">
            <span class="text-amber-400 text-xs">Ret. PPSSI 2%</span>
            <span id="sumPPSSI" class="text-amber-400 font-mono text-xs">0</span>
          </div>
          <div class="flex justify-between text-sm">
            <span class="text-amber-400 text-xs">Ret. BNC 7.5%</span>
            <span id="sumBNC" class="text-amber-400 font-mono text-xs">0</span>
          </div>
          <div class="border-t border-indigo-700 pt-2 flex justify-between">
            <span class="font-semibold text-slate-200">Net a payer</span>
            <span id="sumNAP" class="font-bold text-indigo-400 font-mono text-lg">0</span>
          </div>
          <div class="text-xs text-slate-500 mt-1">FCFA</div>
          <?php if ($facture): ?>
          <div class="mt-3 pt-3 border-t border-slate-700 space-y-1">
            <p class="text-xs text-slate-500">Createur : <?= htmlspecialchars($facture['createur'] ?? '-') ?></p>
            <p class="text-xs text-slate-500">Cree le : <?= date('d/m/Y', strtotime($facture['date_creation'])) ?></p>
            <?php if ($facture['id_ecriture']): ?>
            <p class="text-xs text-green-400"><i class="fas fa-check-circle mr-1"></i>Ecriture #<?= $facture['id_ecriture'] ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Notes -->
      <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 mb-6">
        <label class="text-slate-400 text-xs block mb-1">Notes</label>
        <?php if ($isView): ?>
        <div class="text-slate-300 text-sm whitespace-pre-line min-h-5"><?= htmlspecialchars($preNotes ?: '-') ?></div>
        <?php else: ?>
        <textarea name="notes" rows="2" placeholder="Notes internes..." class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 text-sm focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($preNotes) ?></textarea>
        <?php endif; ?>
      </div>

      <!-- Lines -->
      <div class="bg-slate-800 rounded-xl border border-slate-700 mb-6 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-700">
          <h2 class="font-semibold text-slate-200">Lignes de facture</h2>
          <?php if (!$isView): ?>
          <button type="button" onclick="addLigne()" class="flex items-center gap-1.5 text-sm text-indigo-400 hover:text-indigo-300 transition-colors">
            <i class="fas fa-plus text-xs"></i> Ajouter une ligne
          </button>
          <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm min-w-[1050px]">
            <thead>
              <tr class="text-slate-400 text-xs uppercase border-b border-slate-700 bg-slate-800/80">
                <th class="px-2 py-2 text-left w-6">#</th>
                <th class="px-2 py-2 text-left">Designation</th>
                <th class="px-2 py-2 text-left w-20">Type</th>
                <th class="px-2 py-2 text-right w-16">Qte</th>
                <th class="px-2 py-2 text-right w-24">PU HT</th>
                <th class="px-2 py-2 text-left w-28">Remise</th>
                <th class="px-2 py-2 text-center w-24">Taxe</th>
                <th class="px-2 py-2 text-right w-24">HT net</th>
                <th class="px-2 py-2 text-right w-24">TVA</th>
                <th class="px-2 py-2 text-right w-24">Ret.</th>
                <th class="px-2 py-2 text-right w-28">Net ligne</th>
                <th class="px-2 py-2 w-10 text-left">Cpt.</th>
                <?php if (!$isView): ?><th class="px-2 py-2 w-6"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody id="linesBody"></tbody>
          </table>
        </div>
        <div id="noLines" class="px-4 py-8 text-center text-slate-500 text-sm hidden">Aucune ligne - cliquez sur "Ajouter une ligne".</div>
      </div>

      <?php if (!$isView): ?>
      <div class="flex justify-end gap-3">
        <a href="factures_fournisseurs.php" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm transition-colors">Annuler</a>
        <button type="submit" name="action" value="enregistrer" class="px-5 py-2 rounded-lg bg-slate-600 hover:bg-slate-500 text-slate-100 text-sm font-medium transition-colors">
          <i class="fas fa-save mr-1.5"></i> Enregistrer (brouillon)
        </button>
        <?php if (!$editId || $facture['statut_compta'] === 'non_comptabilisee'): ?>
        <button type="submit" name="action" value="comptabiliser" class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
          <i class="fas fa-calculator mr-1.5"></i> Comptabiliser
        </button>
        <?php endif; ?>
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
const VIEW_MODE = <?= $isView ? 'true' : 'false' ?>;
const initLines = <?= json_encode(array_values($lignes)) ?>;
let lineIdx = 0;
let allArticles = [];
let currentLine = null;

const TAXES = {
  TVA:    { rate: 0.18,   isTVA: true,  isRetenue: false, label: 'TVA 18%'      },
  PPSSI:  { rate: 0.02,   isTVA: false, isRetenue: true,  label: 'PPSSI 2%'     },
  BNC:    { rate: 0.075,  isTVA: false, isRetenue: true,  label: 'BNC 7.5%'     },
  Aucune: { rate: 0,      isTVA: false, isRetenue: false, label: 'Aucune'        },
};

function fmt(n) { return Math.round(parseFloat(n) || 0).toLocaleString('fr-FR'); }
function esc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function calcLine(row) {
  const qte  = parseFloat(row.querySelector('.inp-qte').value)  || 0;
  const pu   = parseFloat(row.querySelector('.inp-pu').value)   || 0;
  const tr   = row.querySelector('.sel-remise').value;
  const vr   = parseFloat(row.querySelector('.inp-remise').value) || 0;
  const taxe = row.querySelector('.sel-taxe').value;

  const brut  = qte * pu;
  const remise= tr === 'Pourcentage' ? brut * vr / 100 : (tr === 'Montant' ? vr : 0);
  const ht    = brut - remise;
  const cfg   = TAXES[taxe] || TAXES.Aucune;
  const tva   = cfg.isTVA    ? ht * cfg.rate : 0;
  const ret   = cfg.isRetenue? ht * cfg.rate : 0;
  const ttc   = ht + tva;
  const net   = ttc - ret;

  row.querySelector('.disp-ht').textContent   = fmt(ht);
  row.querySelector('.disp-tva').textContent  = tva > 0  ? fmt(tva)  : '-';
  row.querySelector('.disp-ret').textContent  = ret > 0  ? fmt(ret)  : '-';
  row.querySelector('.disp-ttc').textContent  = fmt(ttc);
  row.querySelector('.disp-net').textContent  = fmt(net);
  row.dataset.ht  = ht; row.dataset.tva = tva; row.dataset.ret = ret;
  row.dataset.ttc = ttc; row.dataset.net = net; row.dataset.taxe = taxe;
  row.dataset.ppssi = taxe === 'PPSSI' ? ret : 0;
  row.dataset.bnc   = taxe === 'BNC'   ? ret : 0;
  updateTotals();
}

function updateTotals() {
  let ht=0, tva=0, ttc=0, ppssi=0, bnc=0, net=0;
  document.querySelectorAll('#linesBody tr[data-idx]').forEach(r => {
    ht   += parseFloat(r.dataset.ht   || 0);
    tva  += parseFloat(r.dataset.tva  || 0);
    ttc  += parseFloat(r.dataset.ttc  || 0);
    ppssi+= parseFloat(r.dataset.ppssi|| 0);
    bnc  += parseFloat(r.dataset.bnc  || 0);
    net  += parseFloat(r.dataset.net  || 0);
  });
  document.getElementById('sumHT').textContent    = fmt(ht);
  document.getElementById('sumTVA').textContent   = fmt(tva);
  document.getElementById('sumTTC').textContent   = fmt(ttc);
  document.getElementById('sumPPSSI').textContent = fmt(ppssi);
  document.getElementById('sumBNC').textContent   = fmt(bnc);
  document.getElementById('sumNAP').textContent   = fmt(net);
  const hasLines = document.querySelectorAll('#linesBody tr[data-idx]').length > 0;
  document.getElementById('noLines').classList.toggle('hidden', hasLines);
}

function addLigne(data) {
  data = data || {};
  const idx = lineIdx++;
  const body = document.getElementById('linesBody');
  const tr   = document.createElement('tr');
  tr.dataset.idx = idx;
  tr.dataset.ht = 0; tr.dataset.tva = 0; tr.dataset.ret = 0;
  tr.dataset.ttc = 0; tr.dataset.net = 0; tr.dataset.ppssi = 0; tr.dataset.bnc = 0;
  tr.className = 'border-b border-slate-700/50';

  const taxe     = data.type_taxe || 'Aucune';
  const typeLigne= data.type_ligne || 'Service';
  const tr_type  = data.type_remise || 'Aucune';

  if (VIEW_MODE) {
    const ht  = parseFloat(data.montant_ht      || 0);
    const tva = parseFloat(data.montant_tva     || 0);
    const ret = parseFloat(data.montant_retenue || 0);
    const ttc = parseFloat(data.montant_ttc     || 0);
    const net = parseFloat(data.net_ligne       || 0);
    tr.innerHTML = `
      <td class="px-2 py-2.5 text-slate-500 text-xs">${idx+1}</td>
      <td class="px-2 py-2.5">
        <div class="font-medium text-slate-200">${esc(data.designation)}</div>
        ${data.description ? `<div class="text-xs text-slate-500 mt-0.5">${esc(data.description)}</div>` : ''}
      </td>
      <td class="px-2 py-2.5 text-slate-400 text-xs">${esc(typeLigne)}</td>
      <td class="px-2 py-2.5 text-right text-slate-200">${parseFloat(data.quantite||1).toLocaleString('fr-FR',{maximumFractionDigits:3})}</td>
      <td class="px-2 py-2.5 text-right text-slate-200">${fmt(data.prix_unitaire_ht||0)}</td>
      <td class="px-2 py-2.5 text-slate-400 text-xs">${tr_type !== 'Aucune' ? esc(tr_type) + ' ' + parseFloat(data.valeur_remise||0) : '-'}</td>
      <td class="px-2 py-2.5 text-center text-xs ${taxe==='TVA'?'text-blue-300':taxe!=='Aucune'?'text-amber-300':'text-slate-500'}">${esc(taxe)}</td>
      <td class="px-2 py-2.5 text-right text-slate-200 disp-ht font-mono">${fmt(ht)}</td>
      <td class="px-2 py-2.5 text-right text-blue-400 disp-tva font-mono">${tva>0?fmt(tva):'-'}</td>
      <td class="px-2 py-2.5 text-right text-amber-400 disp-ret font-mono">${ret>0?fmt(ret):'-'}</td>
      <td class="px-2 py-2.5 text-right font-semibold text-indigo-400 disp-net font-mono">${fmt(net)}</td>
      <td class="px-2 py-2.5 text-slate-500 text-xs font-mono">${esc(data.compte_charge||'')}</td>
      <td class="px-2 py-2.5 disp-ttc hidden">${fmt(ttc)}</td>
    `;
    tr.dataset.ht = ht; tr.dataset.tva = tva; tr.dataset.ret = ret;
    tr.dataset.ttc = ttc; tr.dataset.net = net; tr.dataset.taxe = taxe;
    tr.dataset.ppssi = taxe === 'PPSSI' ? ret : 0;
    tr.dataset.bnc   = taxe === 'BNC'   ? ret : 0;
    body.appendChild(tr);
    updateTotals();
  } else {
    tr.innerHTML = `
      <td class="px-1 py-1.5 text-slate-500 text-xs">${idx+1}</td>
      <td class="px-1 py-1.5 min-w-[200px]">
        <div class="flex gap-1 mb-1">
          <input class="inp-desg flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-slate-100 text-sm" placeholder="Designation *" value="${esc(data.designation||'')}">
          <button type="button" onclick="openArticles(${idx})" class="text-indigo-400 hover:text-indigo-300 px-1.5" title="Choisir"><i class="fas fa-search text-xs"></i></button>
        </div>
        <input type="hidden" class="inp-article" value="${esc(data.article_id||'')}">
        <input type="hidden" class="inp-compte" value="${esc(data.compte_charge||'')}">
        <input class="inp-desc w-full bg-slate-600 border border-slate-600 rounded px-2 py-1 text-slate-400 text-xs" placeholder="Description..." value="${esc(data.description||'')}">
      </td>
      <td class="px-1 py-1.5">
        <select class="inp-type w-full bg-slate-700 border border-slate-600 rounded px-1 py-1 text-xs text-slate-100">
          <option value="Service" ${typeLigne==='Service'?'selected':''}>Service</option>
          <option value="Bien" ${typeLigne==='Bien'?'selected':''}>Bien</option>
        </select>
      </td>
      <td class="px-1 py-1.5">
        <input type="number" class="inp-qte w-full bg-slate-700 border border-slate-600 rounded px-1 py-1 text-right text-slate-100 text-sm" value="${parseFloat(data.quantite||1)}" min="0.001" step="0.001">
      </td>
      <td class="px-1 py-1.5">
        <input type="number" class="inp-pu w-full bg-slate-700 border border-slate-600 rounded px-1 py-1 text-right text-slate-100 text-sm" value="${parseFloat(data.prix_unitaire_ht||0).toFixed(2)}" min="0" step="0.01">
      </td>
      <td class="px-1 py-1.5">
        <div class="flex gap-1">
          <select class="sel-remise w-14 bg-slate-700 border border-slate-600 rounded px-1 py-1 text-xs text-slate-100">
            <option value="Aucune" ${tr_type==='Aucune'?'selected':''}>-</option>
            <option value="Pourcentage" ${tr_type==='Pourcentage'?'selected':''}>%</option>
            <option value="Montant" ${tr_type==='Montant'?'selected':''}>Mt</option>
          </select>
          <input type="number" class="inp-remise w-14 bg-slate-700 border border-slate-600 rounded px-1 py-1 text-right text-xs text-slate-100" value="${parseFloat(data.valeur_remise||0)}" min="0">
        </div>
      </td>
      <td class="px-1 py-1.5">
        <select class="sel-taxe w-full bg-slate-700 border border-slate-600 rounded px-1 py-1 text-xs text-slate-100">
          <option value="Aucune" ${taxe==='Aucune'?'selected':''}>Aucune</option>
          <option value="TVA"    ${taxe==='TVA'   ?'selected':''}>TVA 18%</option>
          <option value="PPSSI"  ${taxe==='PPSSI' ?'selected':''}>PPSSI 2%</option>
          <option value="BNC"    ${taxe==='BNC'   ?'selected':''}>BNC 7.5%</option>
        </select>
      </td>
      <td class="px-1 py-1.5 text-right disp-ht text-slate-200 text-xs font-mono">0</td>
      <td class="px-1 py-1.5 text-right disp-tva text-blue-400 text-xs font-mono">-</td>
      <td class="px-1 py-1.5 text-right disp-ret text-amber-400 text-xs font-mono">-</td>
      <td class="px-1 py-1.5 text-right disp-net text-indigo-400 font-semibold text-xs font-mono">0</td>
      <td class="px-1 py-1.5">
        <input class="inp-cpt w-full bg-slate-700 border border-slate-600 rounded px-1 py-1 text-slate-400 text-xs font-mono" placeholder="60xxx" value="${esc(data.compte_charge||'')}">
        <span class="disp-ttc hidden"></span>
      </td>
      <td class="px-1 py-1.5 text-center">
        <button type="button" onclick="this.closest('tr').remove();updateTotals();" class="text-slate-500 hover:text-red-400 transition-colors"><i class="fas fa-trash text-xs"></i></button>
      </td>
    `;
    body.appendChild(tr);
    tr.querySelector('.inp-qte').addEventListener('input',    () => calcLine(tr));
    tr.querySelector('.inp-pu').addEventListener('input',     () => calcLine(tr));
    tr.querySelector('.sel-remise').addEventListener('change',() => calcLine(tr));
    tr.querySelector('.inp-remise').addEventListener('input', () => calcLine(tr));
    tr.querySelector('.sel-taxe').addEventListener('change',  () => calcLine(tr));
    calcLine(tr);
  }
}

async function loadArticles() {
  if (allArticles.length) return;
  try {
    const r = await fetch('../settings/api_catalogue.php?action=liste');
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
  const q_l  = q.toLowerCase();
  const f = allArticles.filter(a => !q || (a.designation||'').toLowerCase().includes(q_l) || (a.reference||'').toLowerCase().includes(q_l));
  if (!f.length) { list.innerHTML = '<div class="text-slate-500 text-sm px-3 py-2">Aucun resultat.</div>'; return; }
  list.innerHTML = f.slice(0,30).map(a => `
    <div class="px-3 py-2 rounded-lg hover:bg-slate-700 cursor-pointer transition-colors" onclick='selectArticle(${JSON.stringify(a)})'>
      <div class="text-slate-200 text-sm">${esc(a.designation)}</div>
      <div class="text-slate-500 text-xs">${esc(a.reference||'')}${a.prix_unitaire_ht ? ' - ' + fmt(a.prix_unitaire_ht) + ' FCFA HT' : ''}</div>
    </div>
  `).join('');
}

function selectArticle(a) {
  let row = null;
  document.querySelectorAll('#linesBody tr[data-idx]').forEach(r => { if (parseInt(r.dataset.idx) === currentLine) row = r; });
  if (!row) return;
  row.querySelector('.inp-desg').value   = a.designation || '';
  row.querySelector('.inp-article').value= String(a.id || '');
  row.querySelector('.inp-compte').value = a.compte_charge || '';
  row.querySelector('.inp-cpt').value    = a.compte_charge || '';
  row.querySelector('.inp-pu').value     = parseFloat(a.prix_unitaire_ht || 0).toFixed(2);
  if (a.type_taxe_defaut && row.querySelector('.sel-taxe')) row.querySelector('.sel-taxe').value = a.type_taxe_defaut;
  if (a.type_article     && row.querySelector('.inp-type')) row.querySelector('.inp-type').value  = a.type_article;
  calcLine(row);
  closeArticles();
}

document.getElementById('searchArticle').addEventListener('input', function() { renderArticles(this.value); });
document.getElementById('articlesPanel').addEventListener('click', e => { if (e.target === e.currentTarget) closeArticles(); });

function updateEcheance() {
  const d = new Date(document.getElementById('dateFacture').value);
  const delay = parseInt(document.getElementById('delaiPaiement').value) || 0;
  if (isNaN(d.getTime())) { document.getElementById('lblEcheance').textContent = '-'; return; }
  d.setDate(d.getDate() + delay);
  document.getElementById('lblEcheance').textContent = d.toLocaleDateString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric'});
}

document.getElementById('formFF')?.addEventListener('submit', function(e) {
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
      unite:            'unite',
      prix_unitaire_ht: row.querySelector('.inp-pu')?.value     || 0,
      type_remise:      row.querySelector('.sel-remise')?.value || 'Aucune',
      valeur_remise:    row.querySelector('.inp-remise')?.value || 0,
      type_taxe:        row.querySelector('.sel-taxe')?.value   || 'Aucune',
      montant_ht:       row.dataset.ht  || 0,
      montant_tva:      row.dataset.tva || 0,
      montant_retenue:  row.dataset.ret || 0,
      montant_ttc:      row.dataset.ttc || 0,
      net_ligne:        row.dataset.net || 0,
      compte_charge:    row.querySelector('.inp-cpt')?.value   || null,
    });
  });
  if (!lines.length) { e.preventDefault(); alert('Ajoutez au moins une ligne.'); return; }
  document.getElementById('lignesJson').value = JSON.stringify(lines);
});

// Init
updateEcheance();
initLines.forEach(l => addLigne(l));
</script>
</body>
</html>
