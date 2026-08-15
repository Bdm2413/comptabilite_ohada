<?php
require_once '../../config/config.php';
requireLogin();

$db         = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$createur  = $_SESSION['user_name'] ?? 'Système';
$viewOnly  = isset($_GET['view']) && $_GET['view'] === '1';

// ── Génération numéro facture ─────────────────────────────────────────────────
function genererNumeroFacture(PDO $db, int $societe_id): string {
    $annee = date('Y');
    $stmt  = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(numero_facture, '-', -1) AS UNSIGNED)) as max_seq
        FROM factures_clients
        WHERE societe_id = ? AND numero_facture LIKE ?
    ");
    $stmt->execute([$societe_id, "FAC-$annee-%"]);
    $maxSeq = (int)$stmt->fetchColumn();
    return "FAC-$annee-" . str_pad($maxSeq + 1, 4, '0', STR_PAD_LEFT);
}

// ── Parse float (format fr) ──────────────────────────────────────────────────
$parseNum = function (string $v): float {
    return (float) str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $v ?: '0');
};

// ── Clients ───────────────────────────────────────────────────────────────────
$stmtCli = $db->prepare("SELECT id, nom, compte_tiers FROM plan_tiers WHERE type='Client' AND actif=1 AND societe_id=? ORDER BY nom");
$stmtCli->execute([$societe_id]);
$clients = $stmtCli->fetchAll();

// ── Mode édition ──────────────────────────────────────────────────────────────
$facture = null;
$lignes  = [];
$isEdit  = isset($_GET['id']);

if ($isEdit) {
    $stmt = $db->prepare("SELECT f.*, pt.nom AS client_nom, pt.compte_tiers AS client_compte FROM factures_clients f JOIN plan_tiers pt ON f.id_client=pt.id WHERE f.id=? AND f.societe_id=?");
    $stmt->execute([(int)$_GET['id'], $societe_id]);
    $facture = $stmt->fetch();
    if (!$facture) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Facture introuvable.'];
        header('Location: factures_clients.php'); exit;
    }
    // Verrou : seul brouillon peut être modifié (sauf vue)
    if (!$viewOnly && $facture['statut'] !== 'brouillon') {
        $viewOnly = true;
    }
    $stmtLig = $db->prepare("SELECT * FROM lignes_facture_client WHERE facture_id=? ORDER BY ordre, id");
    $stmtLig->execute([(int)$_GET['id']]);
    $lignes = $stmtLig->fetchAll();
}

// ── POST handler ──────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewOnly) {
    $action         = $_POST['action'] ?? 'enregistrer';
    $id_client      = (int)($_POST['id_client'] ?? 0);
    $date_facture   = $_POST['date_facture'] ?? date('Y-m-d');
    $delai_paiement = (int)($_POST['delai_paiement'] ?? 30);
    $reference_cli  = trim($_POST['reference_client'] ?? '');
    $objet          = trim($_POST['objet'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    // Date échéance
    $date_echeance = date('Y-m-d', strtotime($date_facture . " + $delai_paiement days"));

    // Validation
    if (!$id_client)  $errors[] = 'Veuillez sélectionner un client.';
    if (!$objet)      $errors[] = 'L\'objet est requis.';
    if (!$date_facture) $errors[] = 'La date est requise.';

    // Lignes
    $lignesPost = [];
    if (!empty($_POST['lignes']) && is_array($_POST['lignes'])) {
        foreach ($_POST['lignes'] as $i => $lg) {
            if (empty(trim($lg['designation'] ?? ''))) continue;
            $qte     = $parseNum($lg['quantite']       ?? '1');
            $pu      = $parseNum($lg['prix_unitaire_ht'] ?? '0');
            $tr      = $lg['type_remise'] ?? 'Aucune';
            $vr      = $parseNum($lg['valeur_remise']  ?? '0');
            $tt      = $lg['type_taxe']  ?? 'Aucune';
            $brut    = $qte * $pu;
            $remise  = ($tr === 'Pourcentage') ? $brut * $vr / 100 : (($tr === 'Montant') ? $vr : 0);
            $ht      = $brut - $remise;
            $tva     = ($tt === 'TVA') ? round($ht * 18 / 100, 2) : 0;
            $ttc     = $ht + $tva;
            $lignesPost[] = [
                'ordre'           => $i,
                'article_id'      => (int)($lg['article_id'] ?? 0) ?: null,
                'designation'     => trim($lg['designation']),
                'description'     => trim($lg['description'] ?? ''),
                'type_ligne'      => $lg['type_ligne']  ?? 'Service',
                'quantite'        => $qte,
                'unite'           => trim($lg['unite']   ?? 'unité'),
                'prix_unitaire_ht'=> $pu,
                'type_remise'     => $tr,
                'valeur_remise'   => $vr,
                'type_taxe'       => $tt,
                'montant_ht'      => round($ht, 2),
                'montant_tva'     => $tva,
                'montant_ttc'     => round($ttc, 2),
                'compte_produit'  => trim($lg['compte_produit'] ?? ''),
            ];
        }
    }
    if (empty($lignesPost)) $errors[] = 'Ajoutez au moins une ligne.';

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Totaux
            $montant_ht  = array_sum(array_column($lignesPost, 'montant_ht'));
            $montant_tva = array_sum(array_column($lignesPost, 'montant_tva'));
            $montant_ttc = array_sum(array_column($lignesPost, 'montant_ttc'));
            $montant_ht  = round($montant_ht, 2);
            $montant_tva = round($montant_tva, 2);
            $montant_ttc = round($montant_ttc, 2);

            if ($isEdit) {
                // Mise à jour
                $stmt = $db->prepare("
                    UPDATE factures_clients SET
                        id_client=?, date_facture=?, date_echeance=?, delai_paiement=?,
                        reference_client=?, objet=?, notes=?,
                        montant_ht=?, montant_tva=?, montant_ttc=?,
                        date_modification=NOW()
                    WHERE id=? AND societe_id=? AND statut='brouillon'
                ");
                $stmt->execute([
                    $id_client, $date_facture, $date_echeance, $delai_paiement,
                    $reference_cli, $objet, $notes,
                    $montant_ht, $montant_tva, $montant_ttc,
                    (int)$_GET['id'], $societe_id
                ]);
                $factureId = (int)$_GET['id'];
                $numero_facture = $facture['numero_facture'];
                $db->prepare("DELETE FROM lignes_facture_client WHERE facture_id=?")->execute([$factureId]);
            } else {
                // Création
                $numero_facture = genererNumeroFacture($db, $societe_id);
                $stmt = $db->prepare("
                    INSERT INTO factures_clients
                    (societe_id, numero_facture, id_client, date_facture, date_echeance, delai_paiement,
                     reference_client, objet, notes, montant_ht, montant_tva, montant_ttc, createur)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $societe_id, $numero_facture, $id_client, $date_facture, $date_echeance, $delai_paiement,
                    $reference_cli, $objet, $notes, $montant_ht, $montant_tva, $montant_ttc, $createur
                ]);
                $factureId = (int)$db->lastInsertId();
            }

            // Insérer les lignes
            $stmtLig = $db->prepare("
                INSERT INTO lignes_facture_client
                (facture_id, ordre, article_id, designation, description, type_ligne, quantite, unite,
                 prix_unitaire_ht, type_remise, valeur_remise, type_taxe,
                 montant_ht, montant_tva, montant_ttc, compte_produit)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($lignesPost as $lg) {
                $stmtLig->execute([
                    $factureId, $lg['ordre'], $lg['article_id'],
                    $lg['designation'], $lg['description'], $lg['type_ligne'],
                    $lg['quantite'], $lg['unite'], $lg['prix_unitaire_ht'],
                    $lg['type_remise'], $lg['valeur_remise'], $lg['type_taxe'],
                    $lg['montant_ht'], $lg['montant_tva'], $lg['montant_ttc'],
                    $lg['compte_produit'] ?: null,
                ]);
            }

            // ── Comptabilisation ──────────────────────────────────────────────
            if ($action === 'comptabiliser') {
                // Vérifier la période
                requirePeriodeOuverte($date_facture, $societe_id);

                // Client et son compte
                $stmtC = $db->prepare("SELECT compte_tiers FROM plan_tiers WHERE id=? AND societe_id=?");
                $stmtC->execute([$id_client, $societe_id]);
                $client_row     = $stmtC->fetch();
                $compte_cli_str = $client_row['compte_tiers'] ?? '41110000';
                $compte_cli_int = (int)$compte_cli_str;

                // Numéro écriture
                $prefix = 'VTE' . date('m', strtotime($date_facture)) . date('y', strtotime($date_facture));
                $stmtN  = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_ecriture, -4) AS UNSIGNED)) FROM ecritures WHERE numero_ecriture LIKE ? AND societe_id=?");
                $stmtN->execute([$prefix . '%', $societe_id]);
                $num_ecriture = $prefix . str_pad((int)$stmtN->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);

                // Créer l'écriture
                $stmtE = $db->prepare("
                    INSERT INTO ecritures
                    (societe_id, numero_ecriture, date_ecriture, mois, annee, journal,
                     libelle, id_tiers, compte_tiers, num_piece, num_facture, type_document,
                     montant_total, statut, createur, date_creation)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ");
                $stmtE->execute([
                    $societe_id, $num_ecriture, $date_facture,
                    (int)date('m', strtotime($date_facture)), (int)date('Y', strtotime($date_facture)),
                    'VTE', $objet, $id_client, $compte_cli_str,
                    $numero_facture, $numero_facture, 'Facture client',
                    $montant_ttc, 'Validé', $createur
                ]);
                $ecriture_id = (int)$db->lastInsertId();

                $stmtL = $db->prepare("
                    INSERT INTO lignes_ecriture
                    (societe_id, id_ecriture, compte, compte_tiers, numero_facture, libelle, debit, credit, date_ligne, createur)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");

                // Débit client (TTC)
                $stmtL->execute([
                    $societe_id, $ecriture_id, $compte_cli_int, $compte_cli_str,
                    $numero_facture, $objet, $montant_ttc, 0, $date_facture, $createur
                ]);

                // Crédit produits groupés par compte
                $cpGroupes = [];
                foreach ($lignesPost as $lg) {
                    $cp = $lg['compte_produit'] ?: '70610000';
                    $cp_int = (int)$cp;
                    if (!isset($cpGroupes[$cp_int])) $cpGroupes[$cp_int] = 0;
                    $cpGroupes[$cp_int] += $lg['montant_ht'];
                }
                foreach ($cpGroupes as $cp_int => $ht) {
                    $stmtL->execute([
                        $societe_id, $ecriture_id, $cp_int, null,
                        $numero_facture, $objet, 0, round($ht, 2), $date_facture, $createur
                    ]);
                }

                // Crédit TVA collectée
                if ($montant_tva > 0) {
                    $stmtL->execute([
                        $societe_id, $ecriture_id, 44310000, null,
                        $numero_facture, 'TVA collectée - ' . $numero_facture,
                        0, $montant_tva, $date_facture, $createur
                    ]);
                }

                // Mise à jour statut facture
                $db->prepare("
                    UPDATE factures_clients SET
                        statut_compta='comptabilisee', id_ecriture=?,
                        statut=IF(statut='brouillon','envoyee',statut)
                    WHERE id=?
                ")->execute([$ecriture_id, $factureId]);
            }

            $db->commit();

            $msg = ($action === 'comptabiliser')
                ? "Facture $numero_facture comptabilisée - écriture $num_ecriture créée."
                : "Facture $numero_facture enregistrée.";
            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
            header("Location: facture_client_form.php?id=$factureId" . ($action === 'comptabiliser' ? '&view=1' : ''));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

// ── Valeurs du formulaire ─────────────────────────────────────────────────────
$f_client  = $facture['id_client']        ?? ($_POST['id_client']        ?? '');
$f_date    = $facture['date_facture']     ?? ($_POST['date_facture']     ?? date('Y-m-d'));
$f_delai   = $facture['delai_paiement']  ?? ($_POST['delai_paiement']   ?? 30);
$f_refcli  = $facture['reference_client']?? ($_POST['reference_client'] ?? '');
$f_objet   = $facture['objet']           ?? ($_POST['objet']            ?? '');
$f_notes   = $facture['notes']           ?? ($_POST['notes']            ?? '');
$f_echec   = $facture['date_echeance']   ?? '';

$pageTitle = $isEdit ? 'Facture ' . htmlspecialchars($facture['numero_facture'] ?? '') : 'Nouvelle facture client';
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
  <style>
    .ligne-fac:nth-child(even) { background-color: rgba(51,65,85,.25); }
  </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
  <?php include '../../includes/sidebar.php'; ?>

  <main class="flex-1 overflow-y-auto p-8">

    <!-- Breadcrumb / titre -->
    <div class="mb-6 flex items-start justify-between">
      <div>
        <div class="flex items-center gap-2 text-slate-400 text-sm mb-2">
          <a href="factures_clients.php" class="hover:text-blue-400 transition">Factures clients</a>
          <span>/</span>
          <span class="text-slate-200"><?= $pageTitle ?></span>
        </div>
        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-400">
          <i class="fas fa-file-invoice-dollar mr-2"></i><?= $pageTitle ?>
        </h1>
        <?php if ($isEdit && $facture): ?>
          <div class="flex items-center gap-3 mt-2">
            <?php
            $stCls = [
              'brouillon'           => 'bg-gray-500/20 text-gray-300 border-gray-500/40',
              'envoyee'             => 'bg-blue-500/20 text-blue-300 border-blue-500/40',
              'partiellement_payee' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
              'payee'               => 'bg-green-500/20 text-green-300 border-green-500/40',
              'annulee'             => 'bg-red-500/20 text-red-300 border-red-500/40',
            ];
            $stLbl = [
              'brouillon'=>'Brouillon','envoyee'=>'Envoyée',
              'partiellement_payee'=>'Part. payée','payee'=>'Payée','annulee'=>'Annulée',
            ];
            $s = $facture['statut'];
            ?>
            <span class="px-3 py-1 rounded-full text-xs border <?= $stCls[$s] ?? 'border-slate-600' ?>">
              <?= $stLbl[$s] ?? $s ?>
            </span>
            <?php if ($facture['statut_compta'] === 'comptabilisee'): ?>
              <span class="px-3 py-1 rounded-full text-xs border bg-emerald-500/20 text-emerald-300 border-emerald-500/40">
                <i class="fas fa-check mr-1"></i>Comptabilisée
              </span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="flex gap-2">
        <?php if (!$viewOnly): ?>
          <button type="submit" form="form-facture" name="action" value="enregistrer"
                  class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg flex items-center gap-2 text-sm">
            <i class="fas fa-save"></i>Enregistrer (brouillon)
          </button>
          <button type="submit" form="form-facture" name="action" value="comptabiliser"
                  onclick="return confirm('Comptabiliser cette facture ? Cette action générera une écriture comptable.')"
                  class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center gap-2 text-sm">
            <i class="fas fa-calculator"></i>Comptabiliser
          </button>
        <?php elseif ($isEdit && $facture && $facture['statut_compta'] === 'comptabilisee' && $facture['id_ecriture']): ?>
          <a href="../ecritures/saisie.php?id=<?= $facture['id_ecriture'] ?>"
             class="px-4 py-2 bg-emerald-700 hover:bg-emerald-600 text-white rounded-lg flex items-center gap-2 text-sm">
            <i class="fas fa-external-link-alt"></i>Voir l'écriture
          </a>
        <?php endif; ?>
        <a href="factures_clients.php"
           class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg flex items-center gap-2 text-sm">
          <i class="fas fa-arrow-left"></i>Retour
        </a>
      </div>
    </div>

    <!-- Erreurs -->
    <?php if (!empty($errors)): ?>
      <div class="mb-5 p-4 bg-red-500/10 border border-red-500/50 rounded-xl text-red-300 text-sm space-y-1">
        <?php foreach ($errors as $e): ?>
          <p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Flash -->
    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="mb-5 p-4 rounded-xl text-sm <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-500/10 border border-green-500/40 text-green-300' : 'bg-red-500/10 border border-red-500/40 text-red-300' ?>">
        <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
      </div>
      <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <form id="form-facture" method="POST">

      <!-- Section 1 : En-tête -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 mb-6">
        <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wide mb-4">Informations générales</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

          <!-- Client -->
          <div class="md:col-span-1">
            <label class="block text-xs text-slate-400 mb-1">Client *</label>
            <select name="id_client" required id="sel-client" onchange="updateEcheance()"
                    <?= $viewOnly ? 'disabled' : '' ?>
                    class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:outline-none">
              <option value="">-- Sélectionner un client --</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $f_client == $c['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['nom']) ?> (<?= htmlspecialchars($c['compte_tiers']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Date facture -->
          <div>
            <label class="block text-xs text-slate-400 mb-1">Date facture *</label>
            <input type="date" name="date_facture" id="date-facture" value="<?= htmlspecialchars($f_date) ?>"
                   required onchange="updateEcheance()"
                   <?= $viewOnly ? 'readonly' : '' ?>
                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
          </div>

          <!-- Délai paiement -->
          <div>
            <label class="block text-xs text-slate-400 mb-1">Délai de paiement</label>
            <div class="flex gap-2 items-center">
              <select name="delai_paiement" id="sel-delai" onchange="updateEcheance()"
                      <?= $viewOnly ? 'disabled' : '' ?>
                      class="flex-1 px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <option value="0"  <?= $f_delai == 0  ? 'selected' : '' ?>>Immédiat</option>
                <option value="15" <?= $f_delai == 15 ? 'selected' : '' ?>>15 jours</option>
                <option value="30" <?= $f_delai == 30 ? 'selected' : '' ?>>30 jours</option>
                <option value="45" <?= $f_delai == 45 ? 'selected' : '' ?>>45 jours</option>
                <option value="60" <?= $f_delai == 60 ? 'selected' : '' ?>>60 jours</option>
                <option value="90" <?= $f_delai == 90 ? 'selected' : '' ?>>90 jours</option>
              </select>
              <span class="text-slate-400 text-xs whitespace-nowrap">Éch. : <span id="lbl-echeance" class="text-slate-200 font-medium">
                <?= $f_echec ? date('d/m/Y', strtotime($f_echec)) : '-' ?>
              </span></span>
            </div>
          </div>

          <!-- Objet -->
          <div class="md:col-span-2">
            <label class="block text-xs text-slate-400 mb-1">Objet *</label>
            <input type="text" name="objet" value="<?= htmlspecialchars($f_objet) ?>" required
                   placeholder="Objet de la facture"
                   <?= $viewOnly ? 'readonly' : '' ?>
                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
          </div>

          <!-- Référence client -->
          <div>
            <label class="block text-xs text-slate-400 mb-1">Réf. commande client</label>
            <input type="text" name="reference_client" value="<?= htmlspecialchars($f_refcli) ?>"
                   placeholder="N° bon de commande client"
                   <?= $viewOnly ? 'readonly' : '' ?>
                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
          </div>

          <!-- Notes -->
          <div class="md:col-span-3">
            <label class="block text-xs text-slate-400 mb-1">Notes / Conditions</label>
            <textarea name="notes" rows="2" placeholder="Conditions particulières, banque, mode de règlement..."
                      <?= $viewOnly ? 'readonly' : '' ?>
                      class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none resize-none"><?= htmlspecialchars($f_notes) ?></textarea>
          </div>
        </div>
      </div>

      <!-- Section 2 : Lignes -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wide">Lignes de facturation</h2>
          <?php if (!$viewOnly): ?>
            <button type="button" onclick="addLigne()"
                    class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs flex items-center gap-1.5 transition">
              <i class="fas fa-plus"></i>Ajouter une ligne
            </button>
          <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr class="border-b border-slate-700 text-slate-400">
                <th class="pb-2 text-left pr-2 w-48">Article / Désignation</th>
                <th class="pb-2 text-left pr-2 w-16">Type</th>
                <th class="pb-2 text-center pr-2 w-16">Qté</th>
                <th class="pb-2 text-center pr-2 w-14">Unité</th>
                <th class="pb-2 text-right pr-2 w-28">Prix HT</th>
                <th class="pb-2 text-center pr-2 w-32">Remise</th>
                <th class="pb-2 text-center pr-2 w-20">Taxe</th>
                <th class="pb-2 text-right pr-2 w-28">Net HT</th>
                <th class="pb-2 text-right pr-2 w-24">TVA</th>
                <th class="pb-2 text-right w-28">TTC</th>
                <?php if (!$viewOnly): ?><th class="pb-2 w-8"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody id="lignes-container">
              <!-- lignes injectées en JS -->
            </tbody>
          </table>
        </div>
        <?php if (!$viewOnly): ?>
          <p class="text-slate-500 text-xs mt-2">
            <i class="fas fa-info-circle mr-1"></i>Les articles sont chargés depuis le catalogue vente. Vous pouvez aussi saisir librement une désignation.
          </p>
        <?php endif; ?>
      </div>

      <!-- Section 3 : Totaux -->
      <div class="flex justify-end mb-6">
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 w-full md:w-80 space-y-2 text-sm">
          <div class="flex justify-between text-slate-400">
            <span>Montant brut HT</span>
            <span id="tot-brut" class="font-mono">0</span>
          </div>
          <div class="flex justify-between text-red-400">
            <span>Remises</span>
            <span id="tot-remise" class="font-mono">-0</span>
          </div>
          <div class="flex justify-between text-slate-200 border-t border-slate-700 pt-2">
            <span>Total HT net</span>
            <span id="tot-ht" class="font-mono font-semibold">0</span>
          </div>
          <div class="flex justify-between text-slate-400">
            <span>TVA (18%)</span>
            <span id="tot-tva" class="font-mono">+0</span>
          </div>
          <div class="flex justify-between text-blue-300 border-t border-slate-700 pt-2 text-base">
            <span class="font-semibold">Total TTC</span>
            <span id="tot-ttc" class="font-mono font-bold">0 F CFA</span>
          </div>
        </div>
      </div>

    </form><!-- #form-facture -->

  </main>
</div>

<!-- Template ligne -->
<template id="ligne-tpl">
  <tr class="ligne-fac border-b border-slate-700/40">
    <!-- Article select (hidden input pour désignation) -->
    <td class="pr-2 py-1.5 align-top">
      <select class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs sel-article" onchange="onSelectArticle(this)">
        <option value="">-- Article --</option>
      </select>
      <input type="hidden" name="lignes[IDX][article_id]"      class="inp-article-id">
      <input type="hidden" name="lignes[IDX][designation]"     class="inp-designation">
      <input type="hidden" name="lignes[IDX][compte_produit]"  class="inp-compte">
    </td>
    <!-- Type -->
    <td class="pr-2 py-1.5 align-top">
      <select name="lignes[IDX][type_ligne]" class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs">
        <option>Bien</option><option selected>Service</option>
      </select>
    </td>
    <!-- Qté -->
    <td class="pr-2 py-1.5 align-top">
      <input type="text" name="lignes[IDX][quantite]" value="1"
             class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center inp-qte"
             onchange="calcLigne(this)">
    </td>
    <!-- Unité -->
    <td class="pr-2 py-1.5 align-top">
      <input type="text" name="lignes[IDX][unite]" value="unité"
             class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center inp-unite">
    </td>
    <!-- Prix HT -->
    <td class="pr-2 py-1.5 align-top">
      <input type="text" name="lignes[IDX][prix_unitaire_ht]" value="0"
             class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right inp-prix"
             onchange="calcLigne(this)">
    </td>
    <!-- Remise -->
    <td class="pr-2 py-1.5 align-top">
      <div class="flex gap-1">
        <select name="lignes[IDX][type_remise]" class="w-14 px-1 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs sel-remise" onchange="calcLigne(this)">
          <option>Aucune</option><option>Pourcentage</option><option>Montant</option>
        </select>
        <input type="text" name="lignes[IDX][valeur_remise]" value="0"
               class="w-12 px-1 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right inp-remise"
               onchange="calcLigne(this)">
      </div>
    </td>
    <!-- Taxe -->
    <td class="pr-2 py-1.5 align-top">
      <select name="lignes[IDX][type_taxe]" class="w-full px-2 py-1 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs sel-taxe" onchange="calcLigne(this)">
        <option>Aucune</option><option>TVA</option>
      </select>
    </td>
    <!-- Net HT (computed) -->
    <td class="pr-2 py-1.5 align-top text-right">
      <span class="font-mono text-slate-300 lbl-ht">0</span>
    </td>
    <!-- TVA (computed) -->
    <td class="pr-2 py-1.5 align-top text-right">
      <span class="font-mono text-slate-400 lbl-tva">0</span>
    </td>
    <!-- TTC (computed) -->
    <td class="py-1.5 align-top text-right">
      <span class="font-mono text-slate-200 font-medium lbl-ttc">0</span>
    </td>
    <!-- Supprimer -->
    <td class="py-1.5 align-top">
      <button type="button" onclick="removeLigne(this)" class="p-1 text-red-400 hover:text-red-300 transition">
        <i class="fas fa-times"></i>
      </button>
    </td>
  </tr>
</template>

<script>
let ligneIdx = 0;
let catalogueArticles = [];
const viewOnly = <?= $viewOnly ? 'true' : 'false' ?>;

// Données lignes existantes (pour édition)
const lignesExistantes = <?= json_encode($lignes, JSON_UNESCAPED_UNICODE) ?>;

// ── Chargement catalogue ──────────────────────────────────────────────────────
async function loadCatalogue() {
  try {
    const res  = await fetch('../settings/api_catalogue.php?action=liste_vente');
    const data = await res.json();
    if (data.success) catalogueArticles = data.articles;
  } catch(e) {
    console.error('Catalogue non chargé', e);
  }
}

// ── Utilitaires ───────────────────────────────────────────────────────────────
function parseNum(s) {
  if (!s) return 0;
  return parseFloat(String(s).replace(/[\s ]/g,'').replace(',','.')) || 0;
}
function fmtNum(n) {
  return Math.round(n).toLocaleString('fr-FR');
}

// ── Calcul d'une ligne ────────────────────────────────────────────────────────
function calcLigne(el) {
  const row   = el.closest('tr');
  const qte   = parseNum(row.querySelector('.inp-qte').value);
  const prix  = parseNum(row.querySelector('.inp-prix').value);
  const tr    = row.querySelector('.sel-remise').value;
  const vr    = parseNum(row.querySelector('.inp-remise').value);
  const taxe  = row.querySelector('.sel-taxe').value;

  const brut   = qte * prix;
  const remise = tr === 'Pourcentage' ? brut * vr / 100 : (tr === 'Montant' ? vr : 0);
  const ht     = brut - remise;
  const tva    = taxe === 'TVA' ? ht * 18 / 100 : 0;
  const ttc    = ht + tva;

  row.querySelector('.lbl-ht').textContent  = fmtNum(ht);
  row.querySelector('.lbl-tva').textContent = fmtNum(tva);
  row.querySelector('.lbl-ttc').textContent = fmtNum(ttc);

  calcTotaux();
}

// ── Calcul des totaux ─────────────────────────────────────────────────────────
function calcTotaux() {
  let brut=0, remise=0, ht=0, tva=0;
  document.querySelectorAll('#lignes-container tr.ligne-fac').forEach(row => {
    const qte  = parseNum(row.querySelector('.inp-qte').value);
    const prix = parseNum(row.querySelector('.inp-prix').value);
    const tr   = row.querySelector('.sel-remise').value;
    const vr   = parseNum(row.querySelector('.inp-remise').value);
    const taxe = row.querySelector('.sel-taxe').value;
    const b    = qte * prix;
    const r    = tr === 'Pourcentage' ? b * vr / 100 : (tr === 'Montant' ? vr : 0);
    const h    = b - r;
    brut   += b;
    remise += r;
    ht     += h;
    tva    += taxe === 'TVA' ? h * 18 / 100 : 0;
  });
  document.getElementById('tot-brut').textContent   = fmtNum(brut);
  document.getElementById('tot-remise').textContent = '-' + fmtNum(remise);
  document.getElementById('tot-ht').textContent     = fmtNum(ht);
  document.getElementById('tot-tva').textContent    = '+' + fmtNum(tva);
  document.getElementById('tot-ttc').textContent    = fmtNum(ht + tva) + ' F CFA';
}

// ── Remplir les selects article ───────────────────────────────────────────────
function fillArticleSelect(sel, selectedId) {
  sel.innerHTML = '<option value="">-- Article / saisie libre --</option>';
  catalogueArticles.forEach(a => {
    const opt = document.createElement('option');
    opt.value           = a.id;
    opt.textContent     = a.designation;
    opt.dataset.ref     = a.reference        || '';
    opt.dataset.type    = a.type_article     || 'Service';
    opt.dataset.unite   = a.unite            || 'unité';
    opt.dataset.prix    = a.prix_vente_ht    || 0;
    opt.dataset.compte  = a.compte_vente     || '';
    opt.dataset.taxe    = a.type_taxe_defaut || 'Aucune';
    if (String(a.id) === String(selectedId)) opt.selected = true;
    sel.appendChild(opt);
  });
}

// ── Sélection d'un article ────────────────────────────────────────────────────
function onSelectArticle(sel) {
  const row = sel.closest('tr');
  const opt = sel.options[sel.selectedIndex];
  if (opt.value) {
    row.querySelector('.inp-article-id').value  = opt.value;
    row.querySelector('.inp-designation').value = opt.textContent;
    row.querySelector('.inp-compte').value      = opt.dataset.compte || '';
    row.querySelector('.inp-unite').value       = opt.dataset.unite  || 'unité';
    row.querySelector('.inp-prix').value        = parseFloat(opt.dataset.prix || 0).toLocaleString('fr-FR');
    // Type ligne
    const selType = row.querySelector('select[name*="type_ligne"]');
    if (selType) selType.value = opt.dataset.type || 'Service';
    // Taxe
    const taxeVal = opt.dataset.taxe === 'TVA' ? 'TVA' : 'Aucune';
    row.querySelector('.sel-taxe').value = taxeVal;
  } else {
    row.querySelector('.inp-article-id').value  = '';
    row.querySelector('.inp-designation').value = '';
    row.querySelector('.inp-compte').value      = '';
  }
  calcLigne(sel);
}

// ── Ajouter une ligne ─────────────────────────────────────────────────────────
function addLigne(data = null) {
  const tpl      = document.getElementById('ligne-tpl');
  const clone    = tpl.content.cloneNode(true);
  const tr       = clone.querySelector('tr');

  // Remplacer IDX
  tr.querySelectorAll('[name*="IDX"]').forEach(el => {
    el.name = el.name.replace('IDX', ligneIdx);
  });

  // Peupler le select article
  const sel = tr.querySelector('.sel-article');
  fillArticleSelect(sel, data ? (data.article_id || '') : '');

  if (viewOnly) {
    tr.querySelectorAll('input,select,textarea').forEach(el => el.setAttribute('disabled',''));
    const btnDel = tr.querySelector('button');
    if (btnDel) btnDel.remove();
  }

  // Pré-remplir si données
  if (data) {
    tr.querySelector('.inp-article-id').value  = data.article_id  || '';
    tr.querySelector('.inp-designation').value = data.designation || '';
    tr.querySelector('.inp-compte').value      = data.compte_produit || '';

    const selType = tr.querySelector('select[name*="type_ligne"]');
    if (selType) selType.value = data.type_ligne || 'Service';

    tr.querySelector('.inp-qte').value    = parseFloat(data.quantite         || 1).toLocaleString('fr-FR');
    tr.querySelector('.inp-unite').value  = data.unite                       || 'unité';
    tr.querySelector('.inp-prix').value   = parseFloat(data.prix_unitaire_ht || 0).toLocaleString('fr-FR');
    tr.querySelector('.sel-remise').value = data.type_remise                 || 'Aucune';
    tr.querySelector('.inp-remise').value = parseFloat(data.valeur_remise    || 0).toLocaleString('fr-FR');
    tr.querySelector('.sel-taxe').value   = data.type_taxe                   || 'Aucune';

    // Désignation libre : si article non trouvé dans select, montrer dans champ texte
    if (data.designation && !data.article_id) {
      // Remplacer le select par un texte de description dans la colonne
      sel.value = '';
    }
  }

  document.getElementById('lignes-container').appendChild(clone);
  ligneIdx++;
  calcLigne(tr.querySelector('.inp-qte'));
}

// ── Supprimer une ligne ───────────────────────────────────────────────────────
function removeLigne(btn) {
  const rows = document.querySelectorAll('#lignes-container tr.ligne-fac');
  if (rows.length <= 1) { alert('La facture doit contenir au moins une ligne.'); return; }
  btn.closest('tr').remove();
  calcTotaux();
}

// ── Date d'échéance ───────────────────────────────────────────────────────────
function updateEcheance() {
  const dateStr = document.getElementById('date-facture').value;
  const delai   = parseInt(document.getElementById('sel-delai').value) || 0;
  if (!dateStr) { document.getElementById('lbl-echeance').textContent = '-'; return; }
  const d = new Date(dateStr);
  d.setDate(d.getDate() + delai);
  document.getElementById('lbl-echeance').textContent =
    d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric'});
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  await loadCatalogue();
  if (lignesExistantes.length > 0) {
    lignesExistantes.forEach(lg => addLigne(lg));
  } else {
    addLigne(); // ligne vide par défaut
  }
  updateEcheance();
});

// ── Validation avant soumission ───────────────────────────────────────────────
document.getElementById('form-facture').addEventListener('submit', function(e) {
  // Synchroniser designations libres (si l'utilisateur a tapé dans un champ)
  document.querySelectorAll('#lignes-container tr.ligne-fac').forEach(row => {
    const sel = row.querySelector('.sel-article');
    const inp = row.querySelector('.inp-designation');
    if (!inp.value && sel && sel.options[sel.selectedIndex]) {
      inp.value = sel.options[sel.selectedIndex].textContent;
    }
  });
});
</script>

</body>
</html>
