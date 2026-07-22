<?php
/**
 * Formulaire de réception/facture pour un bon de commande
 * Permet d'enregistrer les quantités/montants reçus ou facturés
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

$reception_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bc_id = isset($_GET['bc_id']) ? (int)$_GET['bc_id'] : 0;
$reception = null;
$lignes = [];
$bc = null;
$lignes_bc = [];

// Mode édition
if ($reception_id > 0) {
    $stmt = $db->prepare("SELECT * FROM receptions_bc WHERE id = ?");
    $stmt->execute([$reception_id]);
    $reception = $stmt->fetch();

    if (!$reception) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Réception introuvable'];
        header('Location: receptions.php');
        exit;
    }

    $bc_id = $reception['id_bon_commande'];

    // Récupérer les lignes de réception
    $stmt = $db->prepare("
        SELECT lr.*, lbc.designation, lbc.unite, lbc.prix_unitaire, lbc.quantite_commandee,
               lbc.montant_commandee, lbc.type_taxe, lbc.taux_taxe
        FROM lignes_reception_bc lr
        JOIN lignes_bon_commande lbc ON lr.id_ligne_bc = lbc.id
        WHERE lr.id_reception = ?
    ");
    $stmt->execute([$reception_id]);
    $lignes = $stmt->fetchAll();
}

// Récupérer le BC
if ($bc_id > 0) {
    $stmt = $db->prepare("
        SELECT bc.*, pt.nom as nom_fournisseur
        FROM bons_commande bc
        JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
        WHERE bc.id = ? AND bc.societe_id = ?
    ");
    $stmt->execute([$bc_id, $societe_id]);
    $bc = $stmt->fetch();

    if ($bc) {
        // Récupérer les lignes du BC avec quantités/montants restants
        $stmt = $db->prepare("
            SELECT lbc.*,
                   lbc.quantite_commandee - lbc.quantite_recue as quantite_restante,
                   lbc.montant_commandee - lbc.montant_facture as montant_restant
            FROM lignes_bon_commande lbc
            WHERE lbc.id_bon_commande = ?
            ORDER BY lbc.id
        ");
        $stmt->execute([$bc_id]);
        $lignes_bc = $stmt->fetchAll();
    }
}

// Récupérer tous les BC validés pour le sélecteur
$stmtBcs = $db->prepare("
    SELECT bc.id, bc.numero_bc, bc.objet, pt.nom as nom_fournisseur
    FROM bons_commande bc
    JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
    WHERE bc.statut IN ('Validé', 'En cours', 'Partiellement reçu') AND bc.societe_id = ?
    ORDER BY bc.numero_bc DESC
");
$stmtBcs->execute([$societe_id]);
$bcs = $stmtBcs->fetchAll();

$page_title = $reception_id ? "Modifier réception" : "Nouvelle réception";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Comptabilité OHADA</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); min-height: 100vh; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(71, 85, 105, 0.5); }
        .input-field {
            @apply w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-cyan-500 focus:border-transparent;
        }
        input[type="number"] { -moz-appearance: textfield; }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body class="text-slate-200">
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- En-tête -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <a href="receptions.php" class="text-slate-400 hover:text-white mb-2 inline-block">
                    <i class="fas fa-arrow-left mr-2"></i>Retour à la liste
                </a>
                <h1 class="text-3xl font-bold text-white">
                    <i class="fas fa-truck-loading text-cyan-400 mr-3"></i>
                    <?= $page_title ?>
                </h1>
            </div>
        </div>

        <form id="receptionForm" method="POST" action="reception_save.php">
            <input type="hidden" name="id" value="<?= $reception_id ?>">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Colonne principale -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Informations générales -->
                    <div class="glass rounded-xl p-6">
                        <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-info-circle text-cyan-400 mr-3"></i>
                            Informations générales
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-slate-400 text-sm mb-2">Bon de commande *</label>
                                <select name="id_bon_commande" id="selectBC" required
                                        class="input-field" <?= $reception_id ? 'disabled' : '' ?>
                                        onchange="chargerBC(this.value)">
                                    <option value="">-- Sélectionner un BC --</option>
                                    <?php foreach ($bcs as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $bc_id == $b['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['numero_bc'] . ' - ' . $b['nom_fournisseur']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($reception_id): ?>
                                    <input type="hidden" name="id_bon_commande" value="<?= $bc_id ?>">
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="block text-slate-400 text-sm mb-2">Type de réception *</label>
                                <select name="type_reception" id="typeReception" required class="input-field">
                                    <option value="Livraison" <?= ($reception['type_reception'] ?? '') == 'Livraison' ? 'selected' : '' ?>>
                                        Livraison (quantités)
                                    </option>
                                    <option value="Facture" <?= ($reception['type_reception'] ?? '') == 'Facture' ? 'selected' : '' ?>>
                                        Facture (montants)
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-slate-400 text-sm mb-2">N° Réception *</label>
                                <input type="text" name="numero_reception" required
                                       value="<?= htmlspecialchars($reception['numero_reception'] ?? '') ?>"
                                       placeholder="REC-2024-001"
                                       class="input-field">
                            </div>

                            <div>
                                <label class="block text-slate-400 text-sm mb-2">Date de réception *</label>
                                <input type="date" name="date_reception" required
                                       value="<?= $reception['date_reception'] ?? date('Y-m-d') ?>"
                                       class="input-field">
                            </div>

                            <div>
                                <label class="block text-slate-400 text-sm mb-2">N° Document (BL/Facture)</label>
                                <input type="text" name="numero_document"
                                       value="<?= htmlspecialchars($reception['numero_document'] ?? '') ?>"
                                       placeholder="Numéro du bordereau ou facture"
                                       class="input-field">
                            </div>

                            <div>
                                <label class="block text-slate-400 text-sm mb-2">Date document</label>
                                <input type="date" name="date_document"
                                       value="<?= $reception['date_document'] ?? '' ?>"
                                       class="input-field">
                            </div>
                        </div>

                        <div class="mt-6">
                            <label class="block text-slate-400 text-sm mb-2">Observations</label>
                            <textarea name="observations" rows="3" class="input-field"
                                      placeholder="Notes ou remarques..."><?= htmlspecialchars($reception['observations'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Lignes de réception -->
                    <div class="glass rounded-xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-semibold text-white flex items-center">
                                <i class="fas fa-list text-cyan-400 mr-3"></i>
                                Lignes de réception
                            </h2>
                        </div>

                        <div id="bcInfo" class="<?= $bc ? '' : 'hidden' ?>">
                            <?php if ($bc): ?>
                                <div class="bg-slate-800/50 rounded-lg p-4 mb-6">
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                        <div>
                                            <span class="text-slate-400">BC N°:</span>
                                            <span class="text-white font-medium ml-2"><?= htmlspecialchars($bc['numero_bc']) ?></span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400">Fournisseur:</span>
                                            <span class="text-white font-medium ml-2"><?= htmlspecialchars($bc['nom_fournisseur']) ?></span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400">Total BC:</span>
                                            <span class="text-cyan-400 font-medium ml-2"><?= safe_number_format($bc['net_a_payer']) ?> FCFA</span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400">Statut:</span>
                                            <span class="text-green-400 font-medium ml-2"><?= htmlspecialchars($bc['statut']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="overflow-x-auto">
                                <table class="w-full" id="tableLignes">
                                    <thead class="bg-slate-900/50 text-xs text-slate-400 uppercase">
                                        <tr>
                                            <th class="px-3 py-2 text-left">
                                                <input type="checkbox" id="checkAll" class="rounded bg-slate-700 border-slate-600">
                                            </th>
                                            <th class="px-3 py-2 text-left">Désignation</th>
                                            <th class="px-3 py-2 text-center">Commandé</th>
                                            <th class="px-3 py-2 text-center">Déjà reçu</th>
                                            <th class="px-3 py-2 text-center">Restant</th>
                                            <th class="px-3 py-2 text-center qty-col">Qté réceptionnée</th>
                                            <th class="px-3 py-2 text-center amount-col">Montant facturé</th>
                                            <th class="px-3 py-2 text-right">Net ligne</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700/50" id="lignesBody">
                                        <?php if ($reception_id && !empty($lignes)): ?>
                                            <?php foreach ($lignes as $index => $ligne): ?>
                                                <tr class="ligne-row" data-index="<?= $index ?>">
                                                    <td class="px-3 py-2">
                                                        <input type="checkbox" checked class="ligne-check rounded bg-slate-700 border-slate-600">
                                                        <input type="hidden" name="lignes[<?= $index ?>][id_ligne_bc]" value="<?= $ligne['id_ligne_bc'] ?>">
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <div class="text-white font-medium"><?= htmlspecialchars($ligne['designation']) ?></div>
                                                        <div class="text-xs text-slate-400">
                                                            <?= safe_number_format($ligne['prix_unitaire']) ?> FCFA/<?= htmlspecialchars($ligne['unite']) ?>
                                                            <?php if ($ligne['type_taxe'] != 'Aucune'): ?>
                                                                <span class="ml-2 px-1 py-0.5 rounded bg-<?= $ligne['type_taxe'] == 'TVA' ? 'green' : 'red' ?>-500/20 text-<?= $ligne['type_taxe'] == 'TVA' ? 'green' : 'red' ?>-400">
                                                                    <?= $ligne['type_taxe'] ?> <?= $ligne['taux_taxe'] ?>%
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-2 text-center text-slate-300"><?= safe_number_format($ligne['quantite_commandee'], 2) ?></td>
                                                    <td class="px-3 py-2 text-center text-slate-300">-</td>
                                                    <td class="px-3 py-2 text-center text-amber-400">-</td>
                                                    <td class="px-3 py-2 qty-col">
                                                        <input type="number" step="0.01" min="0"
                                                               name="lignes[<?= $index ?>][quantite_recue]"
                                                               value="<?= $ligne['quantite_recue'] ?>"
                                                               class="w-24 mx-auto block bg-slate-700/50 border border-slate-600 rounded px-2 py-1 text-white text-center input-qte"
                                                               data-prix="<?= $ligne['prix_unitaire'] ?>"
                                                               data-taxe-type="<?= $ligne['type_taxe'] ?>"
                                                               data-taxe-taux="<?= $ligne['taux_taxe'] ?>"
                                                               onchange="calculerLigne(this)">
                                                    </td>
                                                    <td class="px-3 py-2 amount-col">
                                                        <input type="number" step="0.01" min="0"
                                                               name="lignes[<?= $index ?>][montant_facture]"
                                                               value="<?= $ligne['montant_facture'] ?>"
                                                               class="w-28 mx-auto block bg-slate-700/50 border border-slate-600 rounded px-2 py-1 text-white text-center input-montant"
                                                               data-taxe-type="<?= $ligne['type_taxe'] ?>"
                                                               data-taxe-taux="<?= $ligne['taux_taxe'] ?>"
                                                               onchange="calculerLigne(this)">
                                                    </td>
                                                    <td class="px-3 py-2 text-right">
                                                        <span class="net-ligne text-cyan-400 font-medium">0</span>
                                                        <span class="text-slate-500 text-xs">FCFA</span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php elseif (!empty($lignes_bc)): ?>
                                            <?php foreach ($lignes_bc as $index => $ligne): ?>
                                                <tr class="ligne-row" data-index="<?= $index ?>">
                                                    <td class="px-3 py-2">
                                                        <input type="checkbox" checked class="ligne-check rounded bg-slate-700 border-slate-600">
                                                        <input type="hidden" name="lignes[<?= $index ?>][id_ligne_bc]" value="<?= $ligne['id'] ?>">
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <div class="text-white font-medium"><?= htmlspecialchars($ligne['designation']) ?></div>
                                                        <div class="text-xs text-slate-400">
                                                            <?= safe_number_format($ligne['prix_unitaire']) ?> FCFA/<?= htmlspecialchars($ligne['unite']) ?>
                                                            <?php if ($ligne['type_taxe'] != 'Aucune'): ?>
                                                                <span class="ml-2 px-1 py-0.5 rounded bg-<?= $ligne['type_taxe'] == 'TVA' ? 'green' : 'red' ?>-500/20 text-<?= $ligne['type_taxe'] == 'TVA' ? 'green' : 'red' ?>-400">
                                                                    <?= $ligne['type_taxe'] ?> <?= $ligne['taux_taxe'] ?>%
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-2 text-center text-slate-300"><?= safe_number_format($ligne['quantite_commandee'], 2) ?></td>
                                                    <td class="px-3 py-2 text-center text-slate-300"><?= safe_number_format($ligne['quantite_recue'], 2) ?></td>
                                                    <td class="px-3 py-2 text-center text-amber-400"><?= safe_number_format($ligne['quantite_restante'], 2) ?></td>
                                                    <td class="px-3 py-2 qty-col">
                                                        <input type="number" step="0.01" min="0"
                                                               max="<?= $ligne['quantite_restante'] ?>"
                                                               name="lignes[<?= $index ?>][quantite_recue]"
                                                               value="<?= $ligne['quantite_restante'] ?>"
                                                               class="w-24 mx-auto block bg-slate-700/50 border border-slate-600 rounded px-2 py-1 text-white text-center input-qte"
                                                               data-prix="<?= $ligne['prix_unitaire'] ?>"
                                                               data-taxe-type="<?= $ligne['type_taxe'] ?>"
                                                               data-taxe-taux="<?= $ligne['taux_taxe'] ?>"
                                                               data-remise-type="<?= $ligne['type_remise'] ?>"
                                                               data-remise-valeur="<?= $ligne['valeur_remise'] ?>"
                                                               onchange="calculerLigne(this)">
                                                    </td>
                                                    <td class="px-3 py-2 amount-col">
                                                        <input type="number" step="0.01" min="0"
                                                               max="<?= $ligne['montant_restant'] ?>"
                                                               name="lignes[<?= $index ?>][montant_facture]"
                                                               value="<?= $ligne['montant_restant'] ?>"
                                                               class="w-28 mx-auto block bg-slate-700/50 border border-slate-600 rounded px-2 py-1 text-white text-center input-montant"
                                                               data-taxe-type="<?= $ligne['type_taxe'] ?>"
                                                               data-taxe-taux="<?= $ligne['taux_taxe'] ?>"
                                                               onchange="calculerLigne(this)">
                                                    </td>
                                                    <td class="px-3 py-2 text-right">
                                                        <span class="net-ligne text-cyan-400 font-medium">0</span>
                                                        <span class="text-slate-500 text-xs">FCFA</span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr id="noLignesRow">
                                                <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                                    <i class="fas fa-box-open text-4xl mb-4 opacity-50"></i>
                                                    <p>Sélectionnez un bon de commande pour voir les lignes</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div id="noBCSelected" class="<?= $bc ? 'hidden' : '' ?> text-center py-8 text-slate-400">
                            <i class="fas fa-file-invoice text-4xl mb-4 opacity-50"></i>
                            <p>Sélectionnez un bon de commande pour commencer</p>
                        </div>
                    </div>
                </div>

                <!-- Colonne latérale -->
                <div class="space-y-6">
                    <!-- Récapitulatif -->
                    <div class="glass rounded-xl p-6 sticky top-4">
                        <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-calculator text-cyan-400 mr-3"></i>
                            Récapitulatif
                        </h2>

                        <div class="space-y-4">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">Lignes sélectionnées:</span>
                                <span id="nbLignes" class="text-white font-medium">0</span>
                            </div>

                            <div class="border-t border-slate-700 pt-4">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-slate-400">Sous-total HT:</span>
                                    <span id="totalHT" class="text-white font-medium">0 FCFA</span>
                                </div>
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-slate-400">TVA (+):</span>
                                    <span id="totalTVA" class="text-green-400 font-medium">0 FCFA</span>
                                </div>
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-slate-400">Retenues (-):</span>
                                    <span id="totalRetenues" class="text-red-400 font-medium">0 FCFA</span>
                                </div>
                            </div>

                            <div class="border-t border-slate-700 pt-4">
                                <div class="flex justify-between items-center">
                                    <span class="text-white font-semibold">Net à payer:</span>
                                    <span id="netAPayer" class="text-2xl font-bold text-cyan-400">0 FCFA</span>
                                </div>
                            </div>
                        </div>

                        <!-- Totaux cachés pour le formulaire -->
                        <input type="hidden" name="montant_total_ht" id="inputTotalHT">
                        <input type="hidden" name="montant_tva" id="inputTVA">
                        <input type="hidden" name="montant_retenue" id="inputRetenue">
                        <input type="hidden" name="net_a_payer" id="inputNetAPayer">

                        <div class="mt-8 space-y-3">
                            <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                                <i class="fas fa-save"></i>
                                <?= $reception_id ? 'Mettre à jour' : 'Enregistrer' ?>
                            </button>

                            <a href="receptions.php" class="w-full bg-slate-700 hover:bg-slate-600 text-white px-6 py-3 rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                                <i class="fas fa-times"></i>
                                Annuler
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Gestion du type de réception (quantité vs montant)
        const typeReception = document.getElementById('typeReception');
        const qtyCols = document.querySelectorAll('.qty-col');
        const amountCols = document.querySelectorAll('.amount-col');

        function updateColumnVisibility() {
            const isLivraison = typeReception.value === 'Livraison';
            qtyCols.forEach(col => col.classList.toggle('hidden', !isLivraison));
            amountCols.forEach(col => col.classList.toggle('hidden', isLivraison));
            recalculerTotaux();
        }

        typeReception.addEventListener('change', updateColumnVisibility);

        // Checkbox tout sélectionner
        document.getElementById('checkAll')?.addEventListener('change', function() {
            document.querySelectorAll('.ligne-check').forEach(cb => {
                cb.checked = this.checked;
            });
            recalculerTotaux();
        });

        // Chargement dynamique du BC
        function chargerBC(bcId) {
            if (!bcId) {
                document.getElementById('bcInfo').classList.add('hidden');
                document.getElementById('noBCSelected').classList.remove('hidden');
                return;
            }

            // Rediriger vers la même page avec le BC sélectionné
            window.location.href = 'reception_form.php?bc_id=' + bcId;
        }

        // Calcul d'une ligne
        function calculerLigne(input) {
            const row = input.closest('tr');
            const isLivraison = typeReception.value === 'Livraison';

            let montantHT = 0;
            let montantTaxe = 0;

            if (isLivraison) {
                const qte = parseFloat(row.querySelector('.input-qte').value) || 0;
                const prix = parseFloat(row.querySelector('.input-qte').dataset.prix) || 0;
                const taxeType = row.querySelector('.input-qte').dataset.taxeType;
                const taxeTaux = parseFloat(row.querySelector('.input-qte').dataset.taxeTaux) || 0;
                const remiseType = row.querySelector('.input-qte').dataset.remiseType;
                const remiseValeur = parseFloat(row.querySelector('.input-qte').dataset.remiseValeur) || 0;

                montantHT = qte * prix;

                // Appliquer remise
                if (remiseType === 'Pourcentage') {
                    montantHT = montantHT * (1 - remiseValeur / 100);
                } else if (remiseType === 'Montant') {
                    montantHT = montantHT - remiseValeur;
                }

                // Appliquer taxe
                if (taxeType === 'TVA') {
                    montantTaxe = montantHT * (taxeTaux / 100);
                } else if (taxeType === 'PPSSI' || taxeType === 'BNC') {
                    montantTaxe = -montantHT * (taxeTaux / 100);
                }
            } else {
                montantHT = parseFloat(row.querySelector('.input-montant').value) || 0;
                const taxeType = row.querySelector('.input-montant').dataset.taxeType;
                const taxeTaux = parseFloat(row.querySelector('.input-montant').dataset.taxeTaux) || 0;

                if (taxeType === 'TVA') {
                    montantTaxe = montantHT * (taxeTaux / 100);
                } else if (taxeType === 'PPSSI' || taxeType === 'BNC') {
                    montantTaxe = -montantHT * (taxeTaux / 100);
                }
            }

            const netLigne = montantHT + montantTaxe;
            row.querySelector('.net-ligne').textContent = formatNumber(netLigne);

            recalculerTotaux();
        }

        // Recalcul des totaux
        function recalculerTotaux() {
            const isLivraison = typeReception.value === 'Livraison';
            let totalHT = 0;
            let totalTVA = 0;
            let totalRetenues = 0;
            let nbLignes = 0;

            document.querySelectorAll('.ligne-row').forEach(row => {
                const checkbox = row.querySelector('.ligne-check');
                if (!checkbox || !checkbox.checked) return;

                nbLignes++;
                let montantHT = 0;

                if (isLivraison) {
                    const input = row.querySelector('.input-qte');
                    const qte = parseFloat(input.value) || 0;
                    const prix = parseFloat(input.dataset.prix) || 0;
                    const taxeType = input.dataset.taxeType;
                    const taxeTaux = parseFloat(input.dataset.taxeTaux) || 0;
                    const remiseType = input.dataset.remiseType;
                    const remiseValeur = parseFloat(input.dataset.remiseValeur) || 0;

                    montantHT = qte * prix;

                    if (remiseType === 'Pourcentage') {
                        montantHT = montantHT * (1 - remiseValeur / 100);
                    } else if (remiseType === 'Montant') {
                        montantHT = montantHT - remiseValeur;
                    }

                    if (taxeType === 'TVA') {
                        totalTVA += montantHT * (taxeTaux / 100);
                    } else if (taxeType === 'PPSSI' || taxeType === 'BNC') {
                        totalRetenues += montantHT * (taxeTaux / 100);
                    }
                } else {
                    const input = row.querySelector('.input-montant');
                    montantHT = parseFloat(input.value) || 0;
                    const taxeType = input.dataset.taxeType;
                    const taxeTaux = parseFloat(input.dataset.taxeTaux) || 0;

                    if (taxeType === 'TVA') {
                        totalTVA += montantHT * (taxeTaux / 100);
                    } else if (taxeType === 'PPSSI' || taxeType === 'BNC') {
                        totalRetenues += montantHT * (taxeTaux / 100);
                    }
                }

                totalHT += montantHT;
            });

            const netAPayer = totalHT + totalTVA - totalRetenues;

            document.getElementById('nbLignes').textContent = nbLignes;
            document.getElementById('totalHT').textContent = formatNumber(totalHT) + ' FCFA';
            document.getElementById('totalTVA').textContent = formatNumber(totalTVA) + ' FCFA';
            document.getElementById('totalRetenues').textContent = formatNumber(totalRetenues) + ' FCFA';
            document.getElementById('netAPayer').textContent = formatNumber(netAPayer) + ' FCFA';

            // Mettre à jour les champs cachés
            document.getElementById('inputTotalHT').value = totalHT.toFixed(2);
            document.getElementById('inputTVA').value = totalTVA.toFixed(2);
            document.getElementById('inputRetenue').value = totalRetenues.toFixed(2);
            document.getElementById('inputNetAPayer').value = netAPayer.toFixed(2);
        }

        function formatNumber(n) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(Math.round(n));
        }

        // Écouter les changements sur les checkboxes
        document.querySelectorAll('.ligne-check').forEach(cb => {
            cb.addEventListener('change', recalculerTotaux);
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            updateColumnVisibility();
            // Calculer chaque ligne
            document.querySelectorAll('.input-qte, .input-montant').forEach(input => {
                calculerLigne(input);
            });
        });
    </script>
</body>
</html>
