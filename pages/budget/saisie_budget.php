<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Saisie Budget";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres
$id_version = $_GET['id_version'] ?? null;
$annee = $_GET['annee'] ?? date('Y') + 1;

// Si modification, charger la version
$budget_version = null;
$lignes_budget = [];

if ($id_version) {
    $stmt = $db->prepare("SELECT * FROM budget_versions WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id_version, $societe_id]);
    $budget_version = $stmt->fetch();

    if ($budget_version) {
        $annee = $budget_version['annee'];
        // Charger les lignes
        $stmt = $db->prepare("SELECT * FROM budget_lignes WHERE id_budget_version = ? ORDER BY compte");
        $stmt->execute([$id_version]);
        $lignes_budget = $stmt->fetchAll();
    }
}

// Récupérer les versions existantes pour l'année
$stmt = $db->prepare("SELECT * FROM budget_versions WHERE annee = ? AND societe_id = ? ORDER BY created_at DESC");
$stmt->execute([$annee, $societe_id]);
$versions_existantes = $stmt->fetchAll();

// Récupérer les comptes de charges et produits
$stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable
                    WHERE (compte LIKE '6%' OR compte LIKE '7%')
                    AND actif = 'Oui'
                    AND societe_id = ?
                    ORDER BY compte");
$stmt->execute([$societe_id]);
$comptes = $stmt->fetchAll();

// Récupérer les rubriques budgétaires actives de la société courante
$stmt = $db->prepare("SELECT id, code, libelle, type FROM budget_rubriques WHERE actif = 'Oui' AND societe_id = ? ORDER BY type, ordre_affichage, libelle");
$stmt->execute([$societe_id]);
$rubriques = $stmt->fetchAll();
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
        #sidebar { opacity: 1 !important; }
        .col-montant {
            font-family: 'Courier New', monospace;
            text-align: right;
            white-space: nowrap;
        }
        input[type="number"] {
            text-align: right;
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
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-blue-600 mb-2">
                            <i class="fas fa-edit mr-3"></i><?= $budget_version ? 'Modification du Budget' : 'Nouveau Budget' ?>
                        </h1>
                        <p class="text-slate-400">Saisir les montants budgétaires par compte et par mois</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <form method="POST" action="saisie_budget_save.php" id="formBudget">
                <input type="hidden" name="id_version" value="<?= $id_version ?? '' ?>">

                <!-- Informations générales -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 mb-6">
                    <h2 class="text-xl font-bold text-white mb-4">
                        <i class="fas fa-info-circle mr-2 text-indigo-400"></i>
                        Informations Générales
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Année <span class="text-red-400">*</span>
                            </label>
                            <input type="number" name="annee" value="<?= $annee ?>" min="2020" max="2050" required
                                   <?= $budget_version ? 'readonly' : '' ?>
                                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Version <span class="text-red-400">*</span>
                            </label>
                            <input type="text" name="version" value="<?= $budget_version['version'] ?? 'Budget Initial' ?>" required
                                   placeholder="Ex: Budget Initial, Budget Révisé 1"
                                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                            <?php if (!empty($versions_existantes)): ?>
                                <p class="text-xs text-slate-400 mt-1">
                                    Versions existantes: <?= implode(', ', array_column($versions_existantes, 'version')) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Statut <span class="text-red-400">*</span>
                            </label>
                            <select name="statut" required
                                    class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                                <option value="Brouillon" <?= (!$budget_version || $budget_version['statut'] === 'Brouillon') ? 'selected' : '' ?>>Brouillon</option>
                                <option value="En cours" <?= ($budget_version && $budget_version['statut'] === 'En cours') ? 'selected' : '' ?>>En cours</option>
                                <option value="Validé" <?= ($budget_version && $budget_version['statut'] === 'Validé') ? 'selected' : '' ?>>Validé</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500"
                                  placeholder="Description ou notes sur cette version du budget"><?= $budget_version['description'] ?? '' ?></textarea>
                    </div>
                </div>

                <!-- Lignes budgétaires -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden mb-6">
                    <div class="p-6 border-b border-slate-700 flex items-center justify-between">
                        <h2 class="text-xl font-bold text-white">
                            <i class="fas fa-table mr-2 text-green-400"></i>
                            Lignes Budgétaires
                        </h2>
                        <button type="button" onclick="ajouterLigne()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Ajouter un compte
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="tableBudget">
                            <thead class="bg-gradient-to-r from-slate-700 to-slate-800 sticky top-0">
                                <tr>
                                    <th class="px-3 py-3 text-left text-slate-300 font-semibold" rowspan="2">Compte SAGE</th>
                                    <th class="px-3 py-3 text-left text-slate-300 font-semibold" rowspan="2">Rubrique</th>
                                    <th class="px-3 py-3 text-left text-slate-300 font-semibold" rowspan="2">Compte Oracle</th>
                                    <th class="px-3 py-2 text-center text-slate-300 font-semibold border-l border-slate-600" colspan="12">Montants Mensuels (en FCFA)</th>
                                    <th class="px-3 py-3 text-right text-slate-300 font-semibold border-l border-slate-600" rowspan="2">Total Annuel</th>
                                    <th class="px-3 py-3 text-center text-slate-300 font-semibold" rowspan="2">Actions</th>
                                </tr>
                                <tr>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs border-l border-slate-600">Jan</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Fév</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Mar</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Avr</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Mai</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Juin</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Juil</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Aoû</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Sep</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Oct</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Nov</th>
                                    <th class="px-2 py-2 text-center text-slate-400 text-xs">Déc</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700" id="lignesBudget">
                                <?php if (!empty($lignes_budget)): ?>
                                    <?php foreach ($lignes_budget as $index => $ligne): ?>
                                        <tr class="hover:bg-slate-700/30 transition-colors ligne-budget">
                                            <td class="px-3 py-2">
                                                <select name="lignes[<?= $index ?>][compte]" required
                                                        class="w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white text-sm compte-select">
                                                    <option value="">Sélectionner...</option>
                                                    <?php foreach ($comptes as $c): ?>
                                                        <option value="<?= $c['compte'] ?>" <?= $c['compte'] === $ligne['compte'] ? 'selected' : '' ?>>
                                                            <?= $c['compte'] ?> - <?= htmlspecialchars($c['intitule_compte']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <select name="lignes[<?= $index ?>][id_rubrique]"
                                                        class="w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white text-sm rubrique-select">
                                                    <option value="">Aucune</option>
                                                    <?php foreach ($rubriques as $r): ?>
                                                        <option value="<?= $r['id'] ?>" <?= $r['id'] == ($ligne['id_rubrique'] ?? '') ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($r['code']) ?> - <?= htmlspecialchars($r['libelle']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <input type="text" name="lignes[<?= $index ?>][compte_oracle]"
                                                       value="<?= htmlspecialchars($ligne['compte_oracle'] ?? '') ?>"
                                                       placeholder="Ex: 6053000"
                                                       class="w-32 bg-slate-800 border border-slate-600 rounded px-2 py-1 text-white text-sm">
                                            </td>
                                            <?php
                                            $mois_fields = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
                                                           'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
                                            foreach ($mois_fields as $mois):
                                            ?>
                                                <td class="px-2 py-2 <?= $mois === 'janvier' ? 'border-l border-slate-600' : '' ?>">
                                                    <input type="number" name="lignes[<?= $index ?>][<?= $mois ?>]"
                                                           value="<?= $ligne[$mois] ?>" step="0.01" min="0"
                                                           class="w-24 bg-slate-800 border border-slate-600 rounded px-2 py-1 text-white text-sm montant-input"
                                                           onchange="calculerTotal(this)">
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="px-3 py-2 text-right font-mono font-bold text-green-400 border-l border-slate-600 total-annuel">
                                                <?= number_format($ligne['total_annuel'], 0, ',', ' ') ?>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <button type="button" onclick="supprimerLigne(this)"
                                                        class="text-red-400 hover:text-red-300 transition-colors">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-slate-800 border-t-2 border-indigo-600">
                                <tr>
                                    <td class="px-3 py-3 font-bold text-white">TOTAL GÉNÉRAL</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400 border-l border-slate-600" id="totalJan">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalFev">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalMar">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalAvr">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalMai">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalJun">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalJul">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalAou">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalSep">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalOct">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalNov">0</td>
                                    <td class="px-2 py-3 text-right font-mono font-bold text-indigo-400" id="totalDec">0</td>
                                    <td class="px-3 py-3 text-right font-mono font-bold text-green-400 text-lg border-l border-slate-600" id="totalAnnuel">0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Boutons d'action -->
                <div class="flex justify-end gap-3">
                    <a href="index.php" class="px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                        Annuler
                    </a>
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                        <i class="fas fa-save"></i>
                        Enregistrer le budget
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        let ligneIndex = <?= count($lignes_budget) ?>;

        const comptesOptions = <?= json_encode(array_map(function($c) {
            return ['compte' => $c['compte'], 'intitule' => $c['intitule_compte']];
        }, $comptes)) ?>;

        const rubriquesOptions = <?= json_encode(array_map(function($r) {
            return ['id' => $r['id'], 'code' => $r['code'], 'libelle' => $r['libelle']];
        }, $rubriques)) ?>;

        function ajouterLigne() {
            const tbody = document.getElementById('lignesBudget');
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-slate-700/30 transition-colors ligne-budget';

            let optionsHTML = '<option value="">Sélectionner...</option>';
            comptesOptions.forEach(c => {
                optionsHTML += `<option value="${c.compte}">${c.compte} - ${c.intitule}</option>`;
            });

            let rubriquesHTML = '<option value="">Aucune</option>';
            rubriquesOptions.forEach(r => {
                rubriquesHTML += `<option value="${r.id}">${r.code} - ${r.libelle}</option>`;
            });

            const moisFields = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
                               'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];

            let html = `
                <td class="px-3 py-2">
                    <select name="lignes[${ligneIndex}][compte]" required
                            class="w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white text-sm compte-select">
                        ${optionsHTML}
                    </select>
                </td>
                <td class="px-3 py-2">
                    <select name="lignes[${ligneIndex}][id_rubrique]"
                            class="w-full bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white text-sm rubrique-select">
                        ${rubriquesHTML}
                    </select>
                </td>
                <td class="px-3 py-2">
                    <input type="text" name="lignes[${ligneIndex}][compte_oracle]"
                           placeholder="Ex: 6053000"
                           class="w-32 bg-slate-800 border border-slate-600 rounded px-2 py-1 text-white text-sm">
                </td>
            `;

            moisFields.forEach((mois, idx) => {
                html += `
                    <td class="px-2 py-2 ${idx === 0 ? 'border-l border-slate-600' : ''}">
                        <input type="number" name="lignes[${ligneIndex}][${mois}]"
                               value="0" step="0.01" min="0"
                               class="w-24 bg-slate-800 border border-slate-600 rounded px-2 py-1 text-white text-sm montant-input"
                               onchange="calculerTotal(this)">
                    </td>
                `;
            });

            html += `
                <td class="px-3 py-2 text-right font-mono font-bold text-green-400 border-l border-slate-600 total-annuel">0</td>
                <td class="px-3 py-2 text-center">
                    <button type="button" onclick="supprimerLigne(this)"
                            class="text-red-400 hover:text-red-300 transition-colors">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;

            tr.innerHTML = html;
            tbody.appendChild(tr);
            ligneIndex++;
            calculerTotaux();
        }

        function supprimerLigne(btn) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette ligne ?')) {
                btn.closest('tr').remove();
                calculerTotaux();
            }
        }

        function calculerTotal(input) {
            const tr = input.closest('tr');
            const inputs = tr.querySelectorAll('.montant-input');
            let total = 0;
            inputs.forEach(inp => {
                total += parseFloat(inp.value) || 0;
            });
            tr.querySelector('.total-annuel').textContent = total.toLocaleString('fr-FR', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            calculerTotaux();
        }

        function calculerTotaux() {
            const moisFields = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
            const lignes = document.querySelectorAll('.ligne-budget');

            let totalAnnuelGlobal = 0;

            moisFields.forEach((mois, idx) => {
                let totalMois = 0;
                lignes.forEach(ligne => {
                    const input = ligne.querySelectorAll('.montant-input')[idx];
                    totalMois += parseFloat(input.value) || 0;
                });
                document.getElementById(`total${mois}`).textContent = totalMois.toLocaleString('fr-FR', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            });

            lignes.forEach(ligne => {
                const totalLigne = ligne.querySelector('.total-annuel').textContent.replace(/\s/g, '');
                totalAnnuelGlobal += parseFloat(totalLigne) || 0;
            });

            document.getElementById('totalAnnuel').textContent = totalAnnuelGlobal.toLocaleString('fr-FR', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        }

        // Calculer les totaux au chargement
        document.addEventListener('DOMContentLoaded', function() {
            calculerTotaux();
        });
    </script>
</body>
</html>
