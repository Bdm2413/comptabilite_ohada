<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction helper pour number_format
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les comptes bancaires (commençant par 521)
$comptes_banque = $db->query("
    SELECT compte, intitule_compte
    FROM plan_comptable
    WHERE compte LIKE '521%'
    ORDER BY compte
")->fetchAll();

// Paramètres de filtrage
$compte_filter = $_GET['compte'] ?? '';
$mois_filter = $_GET['mois'] ?? date('n');
$annee_filter = $_GET['annee'] ?? date('Y');

$rapprochement = null;
$lignes_rapprochement = [];
$solde_comptable = 0;

if (!empty($compte_filter) && !empty($mois_filter) && !empty($annee_filter)) {
    // Calculer les dates de début et fin du mois
    $date_debut = sprintf('%04d-%02d-01', $annee_filter, $mois_filter);
    $date_fin = date('Y-m-t', strtotime($date_debut));

    // Calculer le solde comptable au dernier jour du mois
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(le.debit), 0) as total_debit,
            COALESCE(SUM(le.credit), 0) as total_credit
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE le.compte = ?
        AND e.statut = 'Validé'
        AND e.date_ecriture <= ?
    ");
    $stmt->execute([$compte_filter, $date_fin]);
    $solde_data = $stmt->fetch();
    $solde_comptable = ($solde_data['total_debit'] ?? 0) - ($solde_data['total_credit'] ?? 0);

    // Récupérer le rapprochement existant
    $stmt = $db->prepare("
        SELECT * FROM rapprochements_bancaires
        WHERE compte_banque = ? AND mois = ? AND annee = ? AND societe_id = ?
    ");
    $stmt->execute([$compte_filter, $mois_filter, $annee_filter, $societe_id]);
    $rapprochement = $stmt->fetch();

    // Si le rapprochement existe, récupérer ses lignes
    if ($rapprochement) {
        $stmt = $db->prepare("
            SELECT * FROM rapprochements_lignes
            WHERE id_rapprochement = ?
            ORDER BY date_operation, id
        ");
        $stmt->execute([$rapprochement['id']]);
        $lignes_rapprochement = $stmt->fetchAll();
    }
}

$pageTitle = "Rapprochement Bancaire";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --font-size-xs: 10px;
            --font-size-sm: 11px;
            --font-size-base: 12px;
            --font-size-md: 13px;
            --font-size-lg: 16px;
            --font-size-xl: 20px;
        }
        body { font-size: var(--font-size-base); }
        .col-montant {
            font-family: 'Courier New', monospace;
            text-align: right;
            white-space: nowrap;
        }
        /* Fix sidebar visibility */
        #sidebar {
            opacity: 1 !important;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-600 mb-2">
                            <i class="fas fa-balance-scale mr-3"></i>Rapprochement Bancaire
                        </h1>
                        <p class="text-slate-400">Contrôle et justification des écarts entre comptabilité et relevé bancaire</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres de sélection -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 mb-6">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <!-- Compte bancaire -->
                    <div class="flex-1 min-w-[300px]">
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-university mr-2"></i>Compte bancaire
                        </label>
                        <select name="compte" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-transparent text-slate-100">
                            <option value="">Sélectionner un compte...</option>
                            <?php foreach ($comptes_banque as $c): ?>
                                <option value="<?= htmlspecialchars($c['compte']) ?>" <?= $compte_filter === $c['compte'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['compte']) ?> - <?= htmlspecialchars($c['intitule_compte']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Mois -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Mois
                        </label>
                        <select name="mois" required class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-transparent text-slate-100">
                            <?php
                            $mois_names = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
                            for ($m = 1; $m <= 12; $m++):
                            ?>
                                <option value="<?= $m ?>" <?= $mois_filter == $m ? 'selected' : '' ?>>
                                    <?= $mois_names[$m] ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- Année -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            <i class="fas fa-calendar mr-2"></i>Année
                        </label>
                        <select name="annee" required class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-transparent text-slate-100">
                            <?php for ($a = date('Y'); $a >= 2020; $a--): ?>
                                <option value="<?= $a ?>" <?= $annee_filter == $a ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- Bouton Afficher -->
                    <button type="submit" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                        <i class="fas fa-search"></i>
                        Afficher
                    </button>
                </form>
            </div>

            <?php if (!empty($compte_filter) && !empty($mois_filter) && !empty($annee_filter)): ?>
                <!-- Informations du compte -->
                <?php
                $compte_info = null;
                foreach ($comptes_banque as $c) {
                    if ($c['compte'] === $compte_filter) {
                        $compte_info = $c;
                        break;
                    }
                }

                // Si le compte n'est pas trouvé dans la liste, le récupérer de la base
                if (!$compte_info) {
                    $stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE compte = ?");
                    $stmt->execute([$compte_filter]);
                    $compte_info = $stmt->fetch();
                }

                $mois_names = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
                ?>

                <?php if ($compte_info): ?>
                    <div class="bg-gradient-to-r from-cyan-900/30 to-blue-900/30 border border-cyan-800/30 rounded-lg p-6 mb-6">
                        <h2 class="text-xl font-bold text-white mb-2">
                            <?= htmlspecialchars($compte_info['compte']) ?> - <?= htmlspecialchars($compte_info['intitule_compte']) ?>
                        </h2>
                        <p class="text-slate-300">
                            Période : <?= $mois_names[$mois_filter] ?> <?= $annee_filter ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Formulaire de rapprochement -->
                <form method="POST" action="rapprochement_bancaire_save.php" id="formRapprochement">
                    <input type="hidden" name="compte_banque" value="<?= htmlspecialchars($compte_filter) ?>">
                    <input type="hidden" name="mois" value="<?= $mois_filter ?>">
                    <input type="hidden" name="annee" value="<?= $annee_filter ?>">
                    <input type="hidden" name="date_debut" value="<?= $date_debut ?>">
                    <input type="hidden" name="date_fin" value="<?= $date_fin ?>">
                    <input type="hidden" name="solde_comptable" value="<?= $solde_comptable ?>">
                    <?php if ($rapprochement): ?>
                        <input type="hidden" name="id_rapprochement" value="<?= $rapprochement['id'] ?>">
                    <?php endif; ?>

                    <!-- Tableau récapitulatif -->
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden mb-6">
                        <div class="bg-gradient-to-r from-slate-700 to-slate-800 px-6 py-4">
                            <h3 class="text-lg font-bold text-white">
                                <i class="fas fa-calculator mr-2"></i>Tableau de Rapprochement
                            </h3>
                        </div>

                        <div class="p-6">
                            <table class="w-full">
                                <tbody class="divide-y divide-slate-700">
                                    <!-- Solde comptable -->
                                    <tr>
                                        <td class="py-3 px-4 font-semibold text-slate-300">
                                            <i class="fas fa-book mr-2 text-cyan-400"></i>Solde comptable au <?= date('d/m/Y', strtotime($date_fin)) ?>
                                        </td>
                                        <td class="py-3 px-4 col-montant text-xl font-bold text-cyan-400">
                                            <?= safe_number_format($solde_comptable, 2) ?> F CFA
                                        </td>
                                    </tr>

                                    <!-- Solde bancaire -->
                                    <tr>
                                        <td class="py-3 px-4 font-semibold text-slate-300">
                                            <i class="fas fa-university mr-2 text-blue-400"></i>Solde bancaire (relevé)
                                        </td>
                                        <td class="py-3 px-4">
                                            <input type="number"
                                                   name="solde_bancaire"
                                                   id="solde_bancaire"
                                                   step="0.01"
                                                   value="<?= $rapprochement ? $rapprochement['solde_bancaire'] : '' ?>"
                                                   placeholder="Entrer le solde du relevé bancaire"
                                                   class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 text-right font-mono text-lg"
                                                   onchange="calculerEcart()">
                                        </td>
                                    </tr>

                                    <!-- Écart -->
                                    <tr class="bg-slate-700/30">
                                        <td class="py-3 px-4 font-bold text-slate-200">
                                            <i class="fas fa-exclamation-triangle mr-2 text-yellow-400"></i>Écart à justifier
                                        </td>
                                        <td class="py-3 px-4 col-montant text-xl font-bold" id="ecart_display">
                                            <span class="text-slate-500">-</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Justifications et solde ajusté -->
                            <div class="mt-6 p-4 bg-slate-700/20 rounded-lg border border-slate-600">
                                <h4 class="text-sm font-semibold text-slate-300 mb-3">
                                    <i class="fas fa-calculator mr-2 text-purple-400"></i>Calcul du solde ajusté
                                </h4>
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-slate-700">
                                        <tr>
                                            <td class="py-2 px-3 text-slate-400">Total justifications au débit</td>
                                            <td class="py-2 px-3 col-montant font-mono text-green-400" id="total_debit_justif">0,00 F CFA</td>
                                        </tr>
                                        <tr>
                                            <td class="py-2 px-3 text-slate-400">Total justifications au crédit</td>
                                            <td class="py-2 px-3 col-montant font-mono text-red-400" id="total_credit_justif">0,00 F CFA</td>
                                        </tr>
                                        <tr class="bg-slate-600/30">
                                            <td class="py-3 px-3 font-bold text-white">
                                                <i class="fas fa-check-circle mr-2 text-purple-400"></i>Nouveau solde comptable ajusté
                                            </td>
                                            <td class="py-3 px-3 col-montant text-lg font-bold text-purple-400" id="solde_ajuste_display">
                                                <?= safe_number_format($solde_comptable, 2) ?> F CFA
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="py-2 px-3 text-slate-400">Différence avec solde bancaire</td>
                                            <td class="py-2 px-3 col-montant font-mono" id="diff_finale_display">
                                                <span class="text-slate-500">-</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div id="rapprochement_status" class="mt-3 p-3 rounded-lg hidden">
                                    <p class="text-sm font-semibold flex items-center gap-2">
                                        <i class="fas fa-info-circle"></i>
                                        <span id="status_message"></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Lignes de justification -->
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden mb-6">
                        <div class="bg-gradient-to-r from-slate-700 to-slate-800 px-6 py-4 flex items-center justify-between">
                            <h3 class="text-lg font-bold text-white">
                                <i class="fas fa-list-ul mr-2"></i>Lignes de Justification
                            </h3>
                            <button type="button" onclick="ajouterLigne()" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                                <i class="fas fa-plus"></i>
                                Ajouter une ligne
                            </button>
                        </div>

                        <div class="p-6">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="tableLignes">
                                    <thead class="bg-slate-700/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-300 uppercase">Date</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-300 uppercase">Libellé</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-300 uppercase">Catégorie</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-300 uppercase">Type</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-slate-300 uppercase">Montant</th>
                                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-300 uppercase">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="lignesContainer" class="divide-y divide-slate-700">
                                        <?php if (!empty($lignes_rapprochement)): ?>
                                            <?php foreach ($lignes_rapprochement as $index => $ligne): ?>
                                                <tr class="ligne-row">
                                                    <td class="px-3 py-2">
                                                        <input type="date" name="lignes[<?= $index ?>][date_operation]" value="<?= htmlspecialchars($ligne['date_operation']) ?>" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm">
                                                        <input type="hidden" name="lignes[<?= $index ?>][id]" value="<?= $ligne['id'] ?>">
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <input type="text" name="lignes[<?= $index ?>][libelle]" value="<?= htmlspecialchars($ligne['libelle']) ?>" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm" placeholder="Description...">
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <select name="lignes[<?= $index ?>][categorie]" class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm">
                                                            <option value="Chèque émis non encaissé" <?= $ligne['categorie'] === 'Chèque émis non encaissé' ? 'selected' : '' ?>>Chèque émis non encaissé</option>
                                                            <option value="Virement en cours" <?= $ligne['categorie'] === 'Virement en cours' ? 'selected' : '' ?>>Virement en cours</option>
                                                            <option value="Remise en cours" <?= $ligne['categorie'] === 'Remise en cours' ? 'selected' : '' ?>>Remise en cours</option>
                                                            <option value="Frais bancaires" <?= $ligne['categorie'] === 'Frais bancaires' ? 'selected' : '' ?>>Frais bancaires</option>
                                                            <option value="Intérêts" <?= $ligne['categorie'] === 'Intérêts' ? 'selected' : '' ?>>Intérêts</option>
                                                            <option value="Prélèvement" <?= $ligne['categorie'] === 'Prélèvement' ? 'selected' : '' ?>>Prélèvement</option>
                                                            <option value="Erreur bancaire" <?= $ligne['categorie'] === 'Erreur bancaire' ? 'selected' : '' ?>>Erreur bancaire</option>
                                                            <option value="Erreur comptable" <?= $ligne['categorie'] === 'Erreur comptable' ? 'selected' : '' ?>>Erreur comptable</option>
                                                            <option value="Autre" <?= $ligne['categorie'] === 'Autre' ? 'selected' : '' ?>>Autre</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <select name="lignes[<?= $index ?>][type_operation]" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm ligne-type" onchange="calculerJustifications()">
                                                            <option value="Débit" <?= $ligne['type_operation'] === 'Débit' ? 'selected' : '' ?>>Débit</option>
                                                            <option value="Crédit" <?= $ligne['type_operation'] === 'Crédit' ? 'selected' : '' ?>>Crédit</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <input type="number" name="lignes[<?= $index ?>][montant]" value="<?= $ligne['montant'] ?>" step="0.01" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm text-right font-mono ligne-montant" placeholder="0.00" oninput="calculerJustifications()">
                                                    </td>
                                                    <td class="px-3 py-2 text-center">
                                                        <button type="button" onclick="supprimerLigne(this)" class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr id="emptyRow">
                                                <td colspan="6" class="px-3 py-4 text-center text-slate-500">
                                                    <i class="fas fa-info-circle mr-2"></i>Aucune ligne de justification. Cliquez sur "Ajouter une ligne" pour commencer.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden mb-6">
                        <div class="bg-gradient-to-r from-slate-700 to-slate-800 px-6 py-4">
                            <h3 class="text-lg font-bold text-white">
                                <i class="fas fa-sticky-note mr-2"></i>Notes et Commentaires
                            </h3>
                        </div>
                        <div class="p-6">
                            <textarea name="notes" rows="4" class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-cyan-500 text-slate-100" placeholder="Ajoutez des notes ou commentaires sur ce rapprochement..."><?= $rapprochement ? htmlspecialchars($rapprochement['notes']) : '' ?></textarea>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-between items-center gap-3">
                        <?php if ($rapprochement): ?>
                            <div class="flex gap-3">
                                <a href="export_rapprochement_pdf.php?id=<?= $rapprochement['id'] ?>" target="_blank" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                    <i class="fas fa-file-pdf"></i>
                                    Export PDF
                                </a>
                                <a href="export_rapprochement_excel.php?id=<?= $rapprochement['id'] ?>" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                    <i class="fas fa-file-excel"></i>
                                    Export Excel
                                </a>
                            </div>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <div class="flex gap-3">
                            <button type="submit" name="action" value="enregistrer" class="px-6 py-3 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                <i class="fas fa-save"></i>
                                Enregistrer
                            </button>
                            <?php if ($rapprochement && $rapprochement['statut'] === 'En cours'): ?>
                                <button type="submit" name="action" value="valider" class="px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                    <i class="fas fa-check-circle"></i>
                                    Valider le rapprochement
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

            <?php endif; ?>

        </main>
    </div>

    <script>
        let ligneCounter = <?= !empty($lignes_rapprochement) ? count($lignes_rapprochement) : 0 ?>;

        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(montant);
        }

        function calculerEcart() {
            const soldeComptable = <?= $solde_comptable ?>;
            const soldeBancaire = parseFloat(document.getElementById('solde_bancaire').value) || 0;
            const ecart = soldeComptable - soldeBancaire;

            const ecartDisplay = document.getElementById('ecart_display');
            const formattedEcart = formatMontant(Math.abs(ecart));

            if (Math.abs(ecart) < 0.01) {
                ecartDisplay.innerHTML = '<span class="text-green-400">0,00 F CFA (Équilibré)</span>';
            } else if (ecart > 0) {
                ecartDisplay.innerHTML = '<span class="text-orange-400">+' + formattedEcart + ' F CFA</span>';
            } else {
                ecartDisplay.innerHTML = '<span class="text-red-400">-' + formattedEcart + ' F CFA</span>';
            }

            // Recalculer les justifications aussi
            calculerJustifications();
        }

        function calculerJustifications() {
            const soldeComptable = <?= $solde_comptable ?>;
            const soldeBancaire = parseFloat(document.getElementById('solde_bancaire').value) || 0;

            let totalDebit = 0;
            let totalCredit = 0;

            // Parcourir toutes les lignes de justification
            const rows = document.querySelectorAll('#lignesContainer .ligne-row');
            rows.forEach(row => {
                const typeSelect = row.querySelector('.ligne-type');
                const montantInput = row.querySelector('.ligne-montant');

                if (typeSelect && montantInput) {
                    const type = typeSelect.value;
                    const montant = parseFloat(montantInput.value) || 0;

                    if (type === 'Débit') {
                        totalDebit += montant;
                    } else if (type === 'Crédit') {
                        totalCredit += montant;
                    }
                }
            });

            // Afficher les totaux
            document.getElementById('total_debit_justif').textContent = formatMontant(totalDebit) + ' F CFA';
            document.getElementById('total_credit_justif').textContent = formatMontant(totalCredit) + ' F CFA';

            // Calculer le nouveau solde comptable ajusté
            // Débit augmente le solde, Crédit diminue le solde
            const soldeAjuste = soldeComptable + totalDebit - totalCredit;
            document.getElementById('solde_ajuste_display').textContent = formatMontant(soldeAjuste) + ' F CFA';

            // Calculer la différence finale avec le solde bancaire
            const diffFinale = soldeAjuste - soldeBancaire;
            const diffFinaleDisplay = document.getElementById('diff_finale_display');
            const statusDiv = document.getElementById('rapprochement_status');
            const statusMessage = document.getElementById('status_message');

            if (soldeBancaire === 0) {
                diffFinaleDisplay.innerHTML = '<span class="text-slate-500">-</span>';
                statusDiv.classList.add('hidden');
            } else {
                const formattedDiff = formatMontant(Math.abs(diffFinale));

                if (Math.abs(diffFinale) < 0.01) {
                    diffFinaleDisplay.innerHTML = '<span class="text-green-400 font-bold">0,00 F CFA</span>';
                    statusDiv.classList.remove('hidden');
                    statusDiv.className = 'mt-3 p-3 rounded-lg bg-green-900/30 border border-green-700';
                    statusMessage.innerHTML = '<i class="fas fa-check-circle text-green-400 mr-2"></i><span class="text-green-400">Rapprochement équilibré ! Le solde ajusté correspond au solde bancaire.</span>';
                } else if (diffFinale > 0) {
                    diffFinaleDisplay.innerHTML = '<span class="text-orange-400 font-bold">+' + formattedDiff + ' F CFA</span>';
                    statusDiv.classList.remove('hidden');
                    statusDiv.className = 'mt-3 p-3 rounded-lg bg-orange-900/30 border border-orange-700';
                    statusMessage.innerHTML = '<i class="fas fa-exclamation-triangle text-orange-400 mr-2"></i><span class="text-orange-400">Le solde ajusté est supérieur au solde bancaire de ' + formattedDiff + ' F CFA</span>';
                } else {
                    diffFinaleDisplay.innerHTML = '<span class="text-red-400 font-bold">-' + formattedDiff + ' F CFA</span>';
                    statusDiv.classList.remove('hidden');
                    statusDiv.className = 'mt-3 p-3 rounded-lg bg-red-900/30 border border-red-700';
                    statusMessage.innerHTML = '<i class="fas fa-times-circle text-red-400 mr-2"></i><span class="text-red-400">Le solde ajusté est inférieur au solde bancaire de ' + formattedDiff + ' F CFA</span>';
                }
            }
        }

        function ajouterLigne() {
            const emptyRow = document.getElementById('emptyRow');
            if (emptyRow) {
                emptyRow.remove();
            }

            const container = document.getElementById('lignesContainer');
            const newRow = document.createElement('tr');
            newRow.className = 'ligne-row';
            newRow.innerHTML = `
                <td class="px-3 py-2">
                    <input type="date" name="lignes[${ligneCounter}][date_operation]" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm">
                </td>
                <td class="px-3 py-2">
                    <input type="text" name="lignes[${ligneCounter}][libelle]" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm" placeholder="Description...">
                </td>
                <td class="px-3 py-2">
                    <select name="lignes[${ligneCounter}][categorie]" class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm">
                        <option value="Chèque émis non encaissé">Chèque émis non encaissé</option>
                        <option value="Virement en cours">Virement en cours</option>
                        <option value="Remise en cours">Remise en cours</option>
                        <option value="Frais bancaires">Frais bancaires</option>
                        <option value="Intérêts">Intérêts</option>
                        <option value="Prélèvement">Prélèvement</option>
                        <option value="Erreur bancaire">Erreur bancaire</option>
                        <option value="Erreur comptable">Erreur comptable</option>
                        <option value="Autre" selected>Autre</option>
                    </select>
                </td>
                <td class="px-3 py-2">
                    <select name="lignes[${ligneCounter}][type_operation]" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm ligne-type" onchange="calculerJustifications()">
                        <option value="Débit">Débit</option>
                        <option value="Crédit">Crédit</option>
                    </select>
                </td>
                <td class="px-3 py-2">
                    <input type="number" name="lignes[${ligneCounter}][montant]" step="0.01" required class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm text-right font-mono ligne-montant" placeholder="0.00" oninput="calculerJustifications()">
                </td>
                <td class="px-3 py-2 text-center">
                    <button type="button" onclick="supprimerLigne(this)" class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            container.appendChild(newRow);
            ligneCounter++;
        }

        function supprimerLigne(button) {
            const row = button.closest('tr');
            row.remove();

            // Si plus de lignes, afficher le message vide
            const container = document.getElementById('lignesContainer');
            if (container.children.length === 0) {
                container.innerHTML = `
                    <tr id="emptyRow">
                        <td colspan="6" class="px-3 py-4 text-center text-slate-500">
                            <i class="fas fa-info-circle mr-2"></i>Aucune ligne de justification. Cliquez sur "Ajouter une ligne" pour commencer.
                        </td>
                    </tr>
                `;
            }

            // Recalculer les justifications
            calculerJustifications();
        }

        // Calculer l'écart et les justifications au chargement si le solde bancaire est rempli
        <?php if ($rapprochement && $rapprochement['solde_bancaire']): ?>
            calculerEcart();
        <?php elseif (!empty($lignes_rapprochement)): ?>
            // Si des lignes existent mais pas encore de solde bancaire, calculer quand même les justifications
            calculerJustifications();
        <?php endif; ?>
    </script>
</body>
</html>
