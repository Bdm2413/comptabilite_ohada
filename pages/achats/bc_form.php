<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

$stmtFournisseurs = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtFournisseurs->execute([$societe_id]);
$fournisseurs = $stmtFournisseurs->fetchAll();

$bc = null;
$lignes = [];
$isEdit = false;
$fromDevis = null;

// Mode édition
if (isset($_GET['id'])) {
    $isEdit = true;
    $stmt = $db->prepare("SELECT * FROM bons_commande WHERE id = ? AND societe_id = ?");
    $stmt->execute([$_GET['id'], $societe_id]);
    $bc = $stmt->fetch();

    if (!$bc) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Bon de commande introuvable'];
        header('Location: bons_commande.php');
        exit;
    }

    // Vérifier si la colonne id_bon_commande existe dans la table ecritures
    $bcColumnExists = false;
    try {
        $checkColumn = $db->query("SHOW COLUMNS FROM ecritures LIKE 'id_bon_commande'");
        $bcColumnExists = $checkColumn->rowCount() > 0;
    } catch (Exception $e) {
        $bcColumnExists = false;
    }

    // Calculer la consommation du BC basée sur les crédits des comptes 4011xxx et 4812xxx
    // 4011xxx = Fournisseurs (achats de biens et services)
    // 4812xxx = Fournisseurs d'immobilisations (achats d'immobilisations)
    $bc['montant_facture'] = 0;
    $bc['pourcentage_consomme'] = 0;
    $bc['montant_reste'] = floatval($bc['montant_ttc']);

    if ($bcColumnExists) {
        $stmtConsommation = $db->prepare("
            SELECT COALESCE(SUM(le.credit), 0) as montant_facture
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.id_bon_commande = ?
              AND e.statut = 'Validé'
              AND (le.compte LIKE '4011%' OR le.compte LIKE '4812%')
              AND le.credit > 0
        ");
        $stmtConsommation->execute([$_GET['id']]);
        $consommation = $stmtConsommation->fetch();
        $bc['montant_facture'] = floatval($consommation['montant_facture']);
        $bc['pourcentage_consomme'] = floatval($bc['montant_ttc']) > 0
            ? round($bc['montant_facture'] / floatval($bc['montant_ttc']) * 100, 1)
            : 0;
        $bc['montant_reste'] = floatval($bc['montant_ttc']) - $bc['montant_facture'];
    }

    $stmtLignes = $db->prepare("SELECT * FROM lignes_bon_commande WHERE id_bon_commande = ? ORDER BY ordre, id");
    $stmtLignes->execute([$_GET['id']]);
    $lignes = $stmtLignes->fetchAll();
}

// Création depuis un devis
if (isset($_GET['from_devis']) && !$isEdit) {
    $stmtDevis = $db->prepare("SELECT * FROM devis_fournisseurs WHERE id = ? AND statut = 'Approuvé' AND societe_id = ?");
    $stmtDevis->execute([$_GET['from_devis'], $societe_id]);
    $fromDevis = $stmtDevis->fetch();

    if ($fromDevis) {
        $stmtLignesDevis = $db->prepare("SELECT * FROM lignes_devis WHERE id_devis = ? ORDER BY ordre, id");
        $stmtLignesDevis->execute([$_GET['from_devis']]);
        $lignes = $stmtLignesDevis->fetchAll();
    }
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $data = [
            'numero_bc' => trim($_POST['numero_bc']),
            'id_fournisseur' => $_POST['id_fournisseur'],
            'id_devis' => $_POST['id_devis'] ?: null,
            'date_bc' => $_POST['date_bc'],
            'date_livraison_prevue' => $_POST['date_livraison_prevue'] ?: null,
            'objet' => trim($_POST['objet']),
            'seuil_alerte' => intval($_POST['seuil_alerte'] ?? 80),
            'notes' => trim($_POST['notes'] ?? '')
        ];

        if ($isEdit) {
            $stmt = $db->prepare("
                UPDATE bons_commande SET
                    numero_bc = ?, id_fournisseur = ?, id_devis = ?, date_bc = ?,
                    date_livraison_prevue = ?, objet = ?, seuil_alerte = ?, notes = ?
                WHERE id = ? AND societe_id = ?
            ");
            $stmt->execute([
                $data['numero_bc'], $data['id_fournisseur'], $data['id_devis'], $data['date_bc'],
                $data['date_livraison_prevue'], $data['objet'], $data['seuil_alerte'], $data['notes'], $_GET['id'], $societe_id
            ]);
            $bcId = $_GET['id'];

            $db->prepare("DELETE FROM lignes_bon_commande WHERE id_bon_commande = ?")->execute([$bcId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO bons_commande
                (numero_bc, id_fournisseur, id_devis, date_bc, date_livraison_prevue, objet, seuil_alerte, notes, societe_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['numero_bc'], $data['id_fournisseur'], $data['id_devis'], $data['date_bc'],
                $data['date_livraison_prevue'], $data['objet'], $data['seuil_alerte'], $data['notes'], $societe_id
            ]);
            $bcId = $db->lastInsertId();
        }

        // Insérer les lignes
        if (isset($_POST['lignes']) && is_array($_POST['lignes'])) {
            $stmtLigne = $db->prepare("
                INSERT INTO lignes_bon_commande
                (id_bon_commande, reference, designation, description, type_ligne, quantite, unite, prix_unitaire_ht, type_remise, valeur_remise, type_taxe, ordre)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // Fonction pour parser les nombres au format français
            $parseNumber = function($value) {
                if (empty($value)) return 0;
                // Supprimer tous types d'espaces (normal, insécable, etc.)
                $value = preg_replace('/[\s\x{00A0}]+/u', '', $value);
                // Remplacer la virgule par un point
                $value = str_replace(',', '.', $value);
                return floatval($value);
            };

            $ordre = 0;
            foreach ($_POST['lignes'] as $ligne) {
                if (empty($ligne['designation'])) continue;

                $stmtLigne->execute([
                    $bcId,
                    trim($ligne['reference'] ?? ''),
                    trim($ligne['designation']),
                    trim($ligne['description'] ?? ''),
                    $ligne['type_ligne'] ?? 'Bien',
                    $parseNumber($ligne['quantite'] ?? 1),
                    trim($ligne['unite'] ?? 'unité'),
                    $parseNumber($ligne['prix_unitaire_ht'] ?? 0),
                    $ligne['type_remise'] ?? 'Aucune',
                    $parseNumber($ligne['valeur_remise'] ?? 0),
                    $ligne['type_taxe'] ?? 'Aucune',
                    $ordre++
                ]);
            }
        }

        $db->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => $isEdit ? 'BC modifié avec succès' : 'BC créé avec succès'];
        header('Location: bc_form.php?id=' . $bcId);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        $error = 'Erreur: ' . $e->getMessage();
    }
}

$pageTitle = $isEdit ? "BC " . htmlspecialchars($bc['numero_bc']) : "Nouveau bon de commande";
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
    <style>.ligne-bc:nth-child(even) { background-color: rgba(51, 65, 85, 0.3); }</style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <div class="mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                            <i class="fas fa-file-alt mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <?php if ($isEdit && $bc): ?>
                            <div class="flex items-center gap-3">
                                <?php
                                $statusColors = [
                                    'Brouillon' => 'bg-slate-500/20 text-slate-400 border-slate-500/50',
                                    'Validé' => 'bg-blue-500/20 text-blue-400 border-blue-500/50',
                                    'En cours' => 'bg-purple-500/20 text-purple-400 border-purple-500/50',
                                    'Partiellement livré' => 'bg-amber-500/20 text-amber-400 border-amber-500/50',
                                    'Livré' => 'bg-green-500/20 text-green-400 border-green-500/50',
                                    'Clôturé' => 'bg-slate-500/20 text-slate-400 border-slate-500/50'
                                ];
                                ?>
                                <span class="px-3 py-1 rounded-full text-sm border <?= $statusColors[$bc['statut']] ?>"><?= $bc['statut'] ?></span>
                                <?php if (!empty($bc['valide_par']) || !empty($bc['date_validation'])): ?>
                                    <span class="text-slate-400 text-sm">
                                        <?php if (!empty($bc['valide_par'])): ?>par <?= htmlspecialchars($bc['valide_par']) ?><?php endif; ?>
                                        <?php if (!empty($bc['date_validation'])): ?> le <?= date('d/m/Y', strtotime($bc['date_validation'])) ?><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($bc['montant_ttc'] > 0): ?>
                                    <span class="text-slate-400 text-sm">
                                        | Consommé: <?= number_format($bc['pourcentage_consomme'], 0) ?>%
                                        (<?= safe_number_format($bc['montant_facture']) ?> / <?= safe_number_format($bc['montant_ttc']) ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($fromDevis): ?>
                            <p class="text-slate-400">Depuis le devis <?= htmlspecialchars($fromDevis['numero_devis']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($isEdit && $bc['statut'] === 'Brouillon'): ?>
                            <button type="button" onclick="openValidateModal()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg inline-flex items-center gap-2">
                                <i class="fas fa-check"></i>Valider le BC
                            </button>
                        <?php endif; ?>
                        <a href="bons_commande.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>Retour
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-500/10 border border-red-500/50 text-red-400">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="bcForm">
                <input type="hidden" name="id_devis" value="<?= $bc['id_devis'] ?? ($fromDevis['id'] ?? '') ?>">

                <!-- Infos générales -->
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4"><i class="fas fa-info-circle text-blue-400 mr-2"></i>Informations générales</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">N° BC *</label>
                            <input type="text" name="numero_bc" required value="<?= htmlspecialchars($bc['numero_bc'] ?? '') ?>" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Fournisseur *</label>
                            <select name="id_fournisseur" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                                <option value="">Sélectionner...</option>
                                <?php foreach ($fournisseurs as $f): ?>
                                    <option value="<?= $f['id'] ?>" <?= ($bc['id_fournisseur'] ?? $fromDevis['id_fournisseur'] ?? '') == $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Date BC *</label>
                            <input type="date" name="date_bc" required value="<?= $bc['date_bc'] ?? date('Y-m-d') ?>" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Livraison prévue</label>
                            <input type="date" name="date_livraison_prevue" value="<?= $bc['date_livraison_prevue'] ?? '' ?>" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                        <div class="md:col-span-3">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Objet *</label>
                            <input type="text" name="objet" required value="<?= htmlspecialchars($bc['objet'] ?? $fromDevis['objet'] ?? '') ?>" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Seuil alerte %</label>
                            <input type="number" name="seuil_alerte" min="0" max="100" value="<?= $bc['seuil_alerte'] ?? 80 ?>" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100"><?= htmlspecialchars($bc['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Lignes -->
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white"><i class="fas fa-list text-blue-400 mr-2"></i>Lignes</h3>
                        <button type="button" onclick="addLigne()" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>Ajouter
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-700/50">
                                <tr>
                                    <th class="px-2 py-2 text-left text-xs text-slate-400 w-24">Réf</th>
                                    <th class="px-2 py-2 text-left text-xs text-slate-400">Désignation *</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-20">Type</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-20">Qté</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-16">Unité</th>
                                    <th class="px-2 py-2 text-right text-xs text-slate-400 w-28">Prix HT</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-32">Remise</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-24">Taxe</th>
                                    <th class="px-2 py-2 text-right text-xs text-slate-400 w-28">Net</th>
                                    <th class="px-2 py-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="lignesContainer"></tbody>
                        </table>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <div class="w-80 space-y-2 text-sm">
                            <div class="flex justify-between text-slate-400"><span>Montant brut:</span><span id="totalBrut" class="font-mono">0</span></div>
                            <div class="flex justify-between text-slate-400"><span>Remises:</span><span id="totalRemise" class="font-mono text-red-400">-0</span></div>
                            <div class="flex justify-between text-slate-300 border-t border-slate-600 pt-2"><span>Total HT:</span><span id="totalHT" class="font-mono">0</span></div>
                            <div class="flex justify-between text-green-400"><span>TVA:</span><span id="totalTVA" class="font-mono">+0</span></div>
                            <div class="flex justify-between text-amber-400"><span>Retenues:</span><span id="totalRetenue" class="font-mono">-0</span></div>
                            <div class="flex justify-between text-white text-lg font-bold border-t border-slate-600 pt-2"><span>Net à payer:</span><span id="totalTTC" class="font-mono">0</span></div>
                        </div>
                    </div>
                </div>

                <?php if ($isEdit && $bc && floatval($bc['montant_ttc']) > 0): ?>
                <!-- Section de suivi de la consommation du BC -->
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4">
                        <i class="fas fa-chart-pie text-purple-400 mr-2"></i>Suivi de consommation
                    </h3>

                    <div class="flex items-center justify-between mb-3">
                        <span class="text-slate-300">Progression</span>
                        <span class="font-bold <?= $bc['pourcentage_consomme'] >= 100 ? 'text-red-400' : ($bc['pourcentage_consomme'] >= $bc['seuil_alerte'] ? 'text-orange-400' : 'text-purple-400') ?>">
                            <?= $bc['pourcentage_consomme'] ?>%
                        </span>
                    </div>

                    <div class="w-full bg-slate-700 rounded-full h-4 mb-4">
                        <div class="h-4 rounded-full transition-all duration-300 <?= $bc['pourcentage_consomme'] >= 100 ? 'bg-gradient-to-r from-red-500 to-red-600' : ($bc['pourcentage_consomme'] >= $bc['seuil_alerte'] ? 'bg-gradient-to-r from-orange-500 to-red-500' : 'bg-gradient-to-r from-purple-500 to-pink-500') ?>"
                             style="width: <?= min($bc['pourcentage_consomme'], 100) ?>%"></div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div class="bg-slate-700/50 rounded-lg p-3">
                            <div class="text-slate-400 text-xs uppercase mb-1">Montant BC</div>
                            <div class="text-white font-bold text-lg"><?= safe_number_format($bc['montant_ttc']) ?></div>
                            <div class="text-slate-500 text-xs">FCFA</div>
                        </div>
                        <div class="bg-slate-700/50 rounded-lg p-3">
                            <div class="text-slate-400 text-xs uppercase mb-1">Consommé</div>
                            <div class="text-pink-400 font-bold text-lg"><?= safe_number_format($bc['montant_facture']) ?></div>
                            <div class="text-slate-500 text-xs">FCFA</div>
                        </div>
                        <div class="bg-slate-700/50 rounded-lg p-3">
                            <div class="text-slate-400 text-xs uppercase mb-1">Reste</div>
                            <div class="text-green-400 font-bold text-lg"><?= safe_number_format($bc['montant_reste']) ?></div>
                            <div class="text-slate-500 text-xs">FCFA</div>
                        </div>
                    </div>

                    <?php if ($bc['pourcentage_consomme'] >= $bc['seuil_alerte']): ?>
                        <div class="mt-4 p-3 rounded-lg <?= $bc['pourcentage_consomme'] >= 100 ? 'bg-red-500/10 border border-red-500/30 text-red-400' : 'bg-orange-500/10 border border-orange-500/30 text-orange-400' ?>">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php if ($bc['pourcentage_consomme'] >= 100): ?>
                                Ce bon de commande a été entièrement consommé.
                            <?php else: ?>
                                Attention : le seuil d'alerte de <?= $bc['seuil_alerte'] ?>% est atteint.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="flex justify-end gap-3">
                    <a href="bons_commande.php" class="px-6 py-2.5 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">Annuler</a>
                    <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg inline-flex items-center gap-2">
                        <i class="fas fa-save"></i>Enregistrer
                    </button>
                </div>
            </form>
        </main>
    </div>

    <template id="ligneTemplate">
        <tr class="ligne-bc hover:bg-slate-700/30">
            <td class="px-2 py-2"><input type="text" name="lignes[INDEX][reference]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs"></td>
            <td class="px-2 py-2"><input type="text" name="lignes[INDEX][designation]" required class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs"></td>
            <td class="px-2 py-2"><select name="lignes[INDEX][type_ligne]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs"><option value="Bien">Bien</option><option value="Service">Service</option></select></td>
            <td class="px-2 py-2"><input type="text" name="lignes[INDEX][quantite]" value="1" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center input-quantite" onchange="calculateLigne(this)"></td>
            <td class="px-2 py-2"><input type="text" name="lignes[INDEX][unite]" value="unité" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center"></td>
            <td class="px-2 py-2"><input type="text" name="lignes[INDEX][prix_unitaire_ht]" value="0" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right input-prix" onchange="calculateLigne(this)"></td>
            <td class="px-2 py-2"><div class="flex gap-1"><select name="lignes[INDEX][type_remise]" class="w-16 px-1 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs select-type-remise" onchange="calculateLigne(this)"><option value="Aucune">-</option><option value="Pourcentage">%</option><option value="Montant">Mnt</option></select><input type="text" name="lignes[INDEX][valeur_remise]" value="0" class="w-14 px-1 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right input-remise" onchange="calculateLigne(this)"></div></td>
            <td class="px-2 py-2"><select name="lignes[INDEX][type_taxe]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs select-taxe" onchange="calculateLigne(this)"><option value="Aucune">Aucune</option><option value="TVA">TVA 18%</option><option value="PPSSI">PPSSI 2%</option><option value="BNC">BNC 7.5%</option></select></td>
            <td class="px-2 py-2 text-right"><span class="net-a-payer font-mono text-slate-200">0</span></td>
            <td class="px-2 py-2"><button type="button" onclick="removeLigne(this)" class="p-1 text-red-400 hover:bg-red-500/20 rounded"><i class="fas fa-times"></i></button></td>
        </tr>
    </template>

    <script>
        let ligneIndex = 0;

        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($lignes)): ?>
                <?php foreach ($lignes as $ligne): ?>
                addLigne(<?= json_encode($ligne) ?>);
                <?php endforeach; ?>
            <?php else: ?>
            addLigne();
            <?php endif; ?>
        });

        function addLigne(data = null) {
            const template = document.getElementById('ligneTemplate');
            const container = document.getElementById('lignesContainer');
            const clone = template.content.cloneNode(true);

            clone.querySelectorAll('[name*="INDEX"]').forEach(el => { el.name = el.name.replace('INDEX', ligneIndex); });

            if (data) {
                clone.querySelector('[name$="[reference]"]').value = data.reference || '';
                clone.querySelector('[name$="[designation]"]').value = data.designation || '';
                clone.querySelector('[name$="[type_ligne]"]').value = data.type_ligne || 'Bien';
                clone.querySelector('[name$="[quantite]"]').value = parseFloat(data.quantite || 1).toLocaleString('fr-FR');
                clone.querySelector('[name$="[unite]"]').value = data.unite || 'unité';
                clone.querySelector('[name$="[prix_unitaire_ht]"]').value = parseFloat(data.prix_unitaire_ht || 0).toLocaleString('fr-FR');
                clone.querySelector('[name$="[type_remise]"]').value = data.type_remise || 'Aucune';
                clone.querySelector('[name$="[valeur_remise]"]').value = parseFloat(data.valeur_remise || 0).toLocaleString('fr-FR');
                clone.querySelector('[name$="[type_taxe]"]').value = data.type_taxe || 'Aucune';
            }

            container.appendChild(clone);
            ligneIndex++;
            calculateAllTotals();
        }

        function removeLigne(btn) {
            const row = btn.closest('tr');
            if (document.querySelectorAll('.ligne-bc').length > 1) { row.remove(); calculateAllTotals(); }
            else { alert('Le BC doit contenir au moins une ligne'); }
        }

        function parseNumber(str) { if (!str) return 0; return parseFloat(String(str).replace(/\s/g, '').replace(',', '.')) || 0; }
        function formatNumber(num) { return num.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }

        function calculateLigne(input) {
            const row = input.closest('tr');
            const quantite = parseNumber(row.querySelector('.input-quantite').value);
            const prix = parseNumber(row.querySelector('.input-prix').value);
            const typeRemise = row.querySelector('.select-type-remise').value;
            const valeurRemise = parseNumber(row.querySelector('.input-remise').value);
            const typeTaxe = row.querySelector('.select-taxe').value;

            const montantBrut = quantite * prix;
            let montantRemise = typeRemise === 'Pourcentage' ? montantBrut * valeurRemise / 100 : (typeRemise === 'Montant' ? valeurRemise : 0);
            const montantHT = montantBrut - montantRemise;
            let montantTaxe = typeTaxe === 'TVA' ? montantHT * 18 / 100 : (typeTaxe === 'PPSSI' ? -montantHT * 2 / 100 : (typeTaxe === 'BNC' ? -montantHT * 7.5 / 100 : 0));
            const netAPayer = montantHT + montantTaxe;

            row.querySelector('.net-a-payer').textContent = formatNumber(Math.round(netAPayer));
            calculateAllTotals();
        }

        function calculateAllTotals() {
            let totalBrut = 0, totalRemise = 0, totalHT = 0, totalTVA = 0, totalRetenue = 0;

            document.querySelectorAll('.ligne-bc').forEach(row => {
                const quantite = parseNumber(row.querySelector('.input-quantite').value);
                const prix = parseNumber(row.querySelector('.input-prix').value);
                const typeRemise = row.querySelector('.select-type-remise').value;
                const valeurRemise = parseNumber(row.querySelector('.input-remise').value);
                const typeTaxe = row.querySelector('.select-taxe').value;

                const montantBrut = quantite * prix;
                totalBrut += montantBrut;

                let montantRemise = typeRemise === 'Pourcentage' ? montantBrut * valeurRemise / 100 : (typeRemise === 'Montant' ? valeurRemise : 0);
                totalRemise += montantRemise;

                const montantHT = montantBrut - montantRemise;
                totalHT += montantHT;

                if (typeTaxe === 'TVA') totalTVA += montantHT * 18 / 100;
                else if (typeTaxe === 'PPSSI') totalRetenue += montantHT * 2 / 100;
                else if (typeTaxe === 'BNC') totalRetenue += montantHT * 7.5 / 100;
            });

            document.getElementById('totalBrut').textContent = formatNumber(Math.round(totalBrut));
            document.getElementById('totalRemise').textContent = '-' + formatNumber(Math.round(totalRemise));
            document.getElementById('totalHT').textContent = formatNumber(Math.round(totalHT));
            document.getElementById('totalTVA').textContent = '+' + formatNumber(Math.round(totalTVA));
            document.getElementById('totalRetenue').textContent = '-' + formatNumber(Math.round(totalRetenue));
            document.getElementById('totalTTC').textContent = formatNumber(Math.round(totalHT + totalTVA - totalRetenue));
        }
    </script>

    <?php if ($isEdit && $bc['statut'] === 'Brouillon'): ?>
    <!-- Modal validation -->
    <div id="validateModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/70" onclick="closeValidateModal()"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4">
            <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md relative">
                <div class="p-6 border-b border-slate-700">
                    <h3 class="text-lg font-semibold text-white">Valider le bon de commande</h3>
                </div>
                <form method="POST" action="bons_commande.php" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="valider">
                    <input type="hidden" name="id" value="<?= $bc['id'] ?>">

                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Validé par</label>
                        <input type="text" name="valide_par" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100" placeholder="Nom de la personne">
                    </div>

                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeValidateModal()" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">Annuler</button>
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Valider</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openValidateModal() {
            document.getElementById('validateModal').classList.remove('hidden');
        }

        function closeValidateModal() {
            document.getElementById('validateModal').classList.add('hidden');
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeValidateModal(); });
    </script>
    <?php endif; ?>
</body>
</html>
