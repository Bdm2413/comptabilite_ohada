<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

// Récupérer les fournisseurs
$stmtFournisseurs = $db->prepare("SELECT id, nom, abreviation FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtFournisseurs->execute([$societe_id]);
$fournisseurs = $stmtFournisseurs->fetchAll();

// Mode édition ou création
$devis = null;
$lignes = [];
$isEdit = false;

if (isset($_GET['id'])) {
    $isEdit = true;
    $stmt = $db->prepare("SELECT * FROM devis_fournisseurs WHERE id = ? AND societe_id = ?");
    $stmt->execute([$_GET['id'], $societe_id]);
    $devis = $stmt->fetch();

    if (!$devis) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Devis introuvable'];
        header('Location: devis.php');
        exit;
    }

    // Récupérer les lignes
    $stmtLignes = $db->prepare("SELECT * FROM lignes_devis WHERE id_devis = ? ORDER BY ordre, id");
    $stmtLignes->execute([$_GET['id']]);
    $lignes = $stmtLignes->fetchAll();
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $data = [
            'numero_devis' => trim($_POST['numero_devis']),
            'id_fournisseur' => $_POST['id_fournisseur'],
            'date_devis' => $_POST['date_devis'],
            'date_validite' => $_POST['date_validite'] ?: null,
            'objet' => trim($_POST['objet']),
            'notes' => trim($_POST['notes'] ?? '')
        ];

        if ($isEdit) {
            // Mise à jour
            $stmt = $db->prepare("
                UPDATE devis_fournisseurs SET
                    numero_devis = ?, id_fournisseur = ?, date_devis = ?,
                    date_validite = ?, objet = ?, notes = ?
                WHERE id = ? AND societe_id = ?
            ");
            $stmt->execute([
                $data['numero_devis'], $data['id_fournisseur'], $data['date_devis'],
                $data['date_validite'], $data['objet'], $data['notes'], $_GET['id'], $societe_id
            ]);
            $devisId = $_GET['id'];

            // Supprimer les anciennes lignes
            $db->prepare("DELETE FROM lignes_devis WHERE id_devis = ?")->execute([$devisId]);
        } else {
            // Création
            $stmt = $db->prepare("
                INSERT INTO devis_fournisseurs
                (numero_devis, id_fournisseur, date_devis, date_validite, objet, notes, societe_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['numero_devis'], $data['id_fournisseur'], $data['date_devis'],
                $data['date_validite'], $data['objet'], $data['notes'], $societe_id
            ]);
            $devisId = $db->lastInsertId();
        }

        // Insérer les lignes
        if (isset($_POST['lignes']) && is_array($_POST['lignes'])) {
            $stmtLigne = $db->prepare("
                INSERT INTO lignes_devis
                (id_devis, reference, designation, description, type_ligne, quantite, unite, prix_unitaire_ht, type_remise, valeur_remise, type_taxe, ordre)
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
                    $devisId,
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
        $_SESSION['flash'] = ['type' => 'success', 'message' => $isEdit ? 'Devis modifié avec succès' : 'Devis créé avec succès'];
        header('Location: devis_form.php?id=' . $devisId);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        $error = 'Erreur: ' . $e->getMessage();
    }
}

$pageTitle = $isEdit ? "Devis " . htmlspecialchars($devis['numero_devis']) : "Nouveau devis";
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
    <style>
        .ligne-devis:nth-child(even) { background-color: rgba(51, 65, 85, 0.3); }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-amber-400 to-orange-600 mb-2">
                            <i class="fas fa-file-invoice mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <?php if ($isEdit && $devis): ?>
                            <div class="flex items-center gap-3">
                                <?php
                                $statusColors = [
                                    'En attente' => 'bg-amber-500/20 text-amber-400 border-amber-500/50',
                                    'Approuvé' => 'bg-green-500/20 text-green-400 border-green-500/50',
                                    'Rejeté' => 'bg-red-500/20 text-red-400 border-red-500/50',
                                    'Expiré' => 'bg-slate-500/20 text-slate-400 border-slate-500/50'
                                ];
                                ?>
                                <span class="px-3 py-1 rounded-full text-sm border <?= $statusColors[$devis['statut']] ?>">
                                    <?= $devis['statut'] ?>
                                </span>
                                <?php if ($devis['decide_par']): ?>
                                    <span class="text-slate-400 text-sm">
                                        par <?= htmlspecialchars($devis['decide_par']) ?>
                                        le <?= date('d/m/Y', strtotime($devis['date_decision'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($isEdit && $devis['statut'] === 'En attente'): ?>
                            <button type="button" onclick="openStatusModal('Approuvé')" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg inline-flex items-center gap-2">
                                <i class="fas fa-check"></i>Approuver
                            </button>
                            <button type="button" onclick="openStatusModal('Rejeté')" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg inline-flex items-center gap-2">
                                <i class="fas fa-times"></i>Rejeter
                            </button>
                        <?php endif; ?>
                        <?php if ($isEdit && $devis['statut'] === 'Approuvé'): ?>
                            <a href="bc_form.php?from_devis=<?= $devis['id'] ?>" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg inline-flex items-center gap-2">
                                <i class="fas fa-file-alt"></i>Créer un BC
                            </a>
                        <?php endif; ?>
                        <a href="devis.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>Retour
                        </a>
                    </div>
                </div>
            </div>

            <!-- Erreur -->
            <?php if (isset($error)): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-500/10 border border-red-500/50 text-red-400">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="devisForm">
                <!-- Infos générales -->
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4">
                        <i class="fas fa-info-circle text-blue-400 mr-2"></i>Informations générales
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">N° Devis *</label>
                            <input type="text" name="numero_devis" required value="<?= htmlspecialchars($devis['numero_devis'] ?? '') ?>"
                                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-amber-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Fournisseur *</label>
                            <select name="id_fournisseur" id="selectFournisseur" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-amber-500" onchange="loadCatalogue()">
                                <option value="">Sélectionner...</option>
                                <?php foreach ($fournisseurs as $f): ?>
                                    <option value="<?= $f['id'] ?>" <?= ($devis['id_fournisseur'] ?? '') == $f['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($f['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Date du devis *</label>
                            <input type="date" name="date_devis" required value="<?= $devis['date_devis'] ?? date('Y-m-d') ?>"
                                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-amber-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Validité jusqu'au</label>
                            <input type="date" name="date_validite" value="<?= $devis['date_validite'] ?? '' ?>"
                                   class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-amber-500">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Objet *</label>
                        <input type="text" name="objet" required value="<?= htmlspecialchars($devis['objet'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-amber-500"
                               placeholder="Ex: Fourniture d'équipements de protection individuel">
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-amber-500"><?= htmlspecialchars($devis['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Lignes -->
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white">
                            <i class="fas fa-list text-blue-400 mr-2"></i>Lignes du devis
                        </h3>
                        <button type="button" onclick="addLigne()" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>Ajouter une ligne
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-700/50">
                                <tr>
                                    <th class="px-2 py-2 text-left text-xs text-slate-400 w-24">Référence</th>
                                    <th class="px-2 py-2 text-left text-xs text-slate-400">Désignation *</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-20">Type</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-20">Qté</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-16">Unité</th>
                                    <th class="px-2 py-2 text-right text-xs text-slate-400 w-28">Prix HT</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-32">Remise</th>
                                    <th class="px-2 py-2 text-center text-xs text-slate-400 w-24">Taxe</th>
                                    <th class="px-2 py-2 text-right text-xs text-slate-400 w-28">Net à payer</th>
                                    <th class="px-2 py-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="lignesContainer">
                                <!-- Les lignes seront ajoutées ici -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Totaux -->
                    <div class="mt-6 flex justify-end">
                        <div class="w-80 space-y-2 text-sm">
                            <div class="flex justify-between text-slate-400">
                                <span>Montant brut:</span>
                                <span id="totalBrut" class="font-mono">0</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Remises:</span>
                                <span id="totalRemise" class="font-mono text-red-400">-0</span>
                            </div>
                            <div class="flex justify-between text-slate-300 border-t border-slate-600 pt-2">
                                <span>Total HT:</span>
                                <span id="totalHT" class="font-mono">0</span>
                            </div>
                            <div class="flex justify-between text-green-400">
                                <span>TVA (18%):</span>
                                <span id="totalTVA" class="font-mono">+0</span>
                            </div>
                            <div class="flex justify-between text-amber-400">
                                <span>Retenues (PPSSI/BNC):</span>
                                <span id="totalRetenue" class="font-mono">-0</span>
                            </div>
                            <div class="flex justify-between text-white text-lg font-bold border-t border-slate-600 pt-2">
                                <span>Net à payer:</span>
                                <span id="totalTTC" class="font-mono">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3">
                    <a href="devis.php" class="px-6 py-2.5 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">Annuler</a>
                    <button type="submit" class="px-6 py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg inline-flex items-center gap-2">
                        <i class="fas fa-save"></i>Enregistrer
                    </button>
                </div>
            </form>
        </main>
    </div>

    <!-- Template ligne -->
    <template id="ligneTemplate">
        <tr class="ligne-devis hover:bg-slate-700/30">
            <td class="px-2 py-2">
                <input type="text" name="lignes[INDEX][reference]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs input-reference" readonly>
                <input type="hidden" name="lignes[INDEX][id_article]" class="input-article-id">
            </td>
            <td class="px-2 py-2">
                <select name="lignes[INDEX][article_select]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs select-article" onchange="selectArticle(this)">
                    <option value="">-- Choisir un article --</option>
                </select>
                <input type="hidden" name="lignes[INDEX][designation]" class="input-designation">
            </td>
            <td class="px-2 py-2">
                <select name="lignes[INDEX][type_ligne]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs select-type-ligne">
                    <option value="Bien">Bien</option>
                    <option value="Service">Service</option>
                </select>
            </td>
            <td class="px-2 py-2">
                <input type="text" name="lignes[INDEX][quantite]" value="1" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center input-quantite" onchange="calculateLigne(this)">
            </td>
            <td class="px-2 py-2">
                <input type="text" name="lignes[INDEX][unite]" value="unité" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-center input-unite" readonly>
            </td>
            <td class="px-2 py-2">
                <input type="text" name="lignes[INDEX][prix_unitaire_ht]" value="0" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right input-prix" onchange="calculateLigne(this)">
            </td>
            <td class="px-2 py-2">
                <div class="flex gap-1">
                    <select name="lignes[INDEX][type_remise]" class="w-16 px-1 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs select-type-remise" onchange="calculateLigne(this)">
                        <option value="Aucune">-</option>
                        <option value="Pourcentage">%</option>
                        <option value="Montant">Mnt</option>
                    </select>
                    <input type="text" name="lignes[INDEX][valeur_remise]" value="0" class="w-14 px-1 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs text-right input-remise" onchange="calculateLigne(this)">
                </div>
            </td>
            <td class="px-2 py-2">
                <select name="lignes[INDEX][type_taxe]" class="w-full px-2 py-1.5 bg-slate-700 border border-slate-600 rounded text-slate-100 text-xs select-taxe" onchange="calculateLigne(this)">
                    <option value="Aucune">Aucune</option>
                    <option value="TVA">TVA 18%</option>
                    <option value="PPSSI">PPSSI 2%</option>
                    <option value="BNC">BNC 7.5%</option>
                </select>
            </td>
            <td class="px-2 py-2 text-right">
                <span class="net-a-payer font-mono text-slate-200">0</span>
            </td>
            <td class="px-2 py-2">
                <button type="button" onclick="removeLigne(this)" class="p-1 text-red-400 hover:bg-red-500/20 rounded">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        </tr>
    </template>

    <script>
        let ligneIndex = 0;
        let catalogueArticles = [];

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Charger le catalogue si fournisseur déjà sélectionné
            const fournisseurId = document.getElementById('selectFournisseur').value;
            if (fournisseurId) {
                loadCatalogue().then(() => {
                    // Charger les lignes existantes après le catalogue
                    <?php if (!empty($lignes)): ?>
                        <?php foreach ($lignes as $ligne): ?>
                        addLigne(<?= json_encode($ligne) ?>);
                        <?php endforeach; ?>
                    <?php else: ?>
                    addLigne();
                    <?php endif; ?>
                });
            } else {
                <?php if (!empty($lignes)): ?>
                    <?php foreach ($lignes as $ligne): ?>
                    addLigne(<?= json_encode($ligne) ?>);
                    <?php endforeach; ?>
                <?php else: ?>
                addLigne();
                <?php endif; ?>
            }
        });

        // Charger le catalogue du fournisseur sélectionné
        async function loadCatalogue() {
            const fournisseurId = document.getElementById('selectFournisseur').value;
            if (!fournisseurId) {
                catalogueArticles = [];
                updateAllArticleSelects();
                return;
            }

            try {
                const response = await fetch(`api_catalogue.php?action=liste&fournisseur_id=${fournisseurId}`);
                const data = await response.json();
                if (data.success) {
                    catalogueArticles = data.articles;
                    updateAllArticleSelects();
                }
            } catch (error) {
                console.error('Erreur chargement catalogue:', error);
            }
        }

        // Mettre à jour tous les selects d'articles
        function updateAllArticleSelects() {
            document.querySelectorAll('.select-article').forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Choisir un article --</option>';
                catalogueArticles.forEach(art => {
                    const opt = document.createElement('option');
                    opt.value = art.id;
                    opt.textContent = art.designation;
                    opt.dataset.reference = art.reference || '';
                    opt.dataset.type = art.type_article || 'Bien';
                    opt.dataset.unite = art.unite || 'unité';
                    opt.dataset.prix = art.prix_unitaire_ht || 0;
                    select.appendChild(opt);
                });
                if (currentValue) select.value = currentValue;
            });
        }

        // Sélection d'un article du catalogue
        function selectArticle(select) {
            const row = select.closest('tr');
            const option = select.options[select.selectedIndex];

            if (option.value) {
                row.querySelector('.input-article-id').value = option.value;
                row.querySelector('.input-reference').value = option.dataset.reference;
                row.querySelector('.input-designation').value = option.textContent;
                row.querySelector('.select-type-ligne').value = option.dataset.type;
                row.querySelector('.input-unite').value = option.dataset.unite;
                row.querySelector('.input-prix').value = parseFloat(option.dataset.prix).toLocaleString('fr-FR');
            } else {
                row.querySelector('.input-article-id').value = '';
                row.querySelector('.input-reference').value = '';
                row.querySelector('.input-designation').value = '';
                row.querySelector('.input-prix').value = '0';
            }
            calculateLigne(select);
        }

        function addLigne(data = null) {
            const template = document.getElementById('ligneTemplate');
            const container = document.getElementById('lignesContainer');
            const clone = template.content.cloneNode(true);

            // Remplacer INDEX par le numéro réel
            clone.querySelectorAll('[name*="INDEX"]').forEach(el => {
                el.name = el.name.replace('INDEX', ligneIndex);
            });

            // Remplir le select avec les articles du catalogue
            const selectArticle = clone.querySelector('.select-article');
            selectArticle.innerHTML = '<option value="">-- Choisir un article --</option>';
            catalogueArticles.forEach(art => {
                const opt = document.createElement('option');
                opt.value = art.id;
                opt.textContent = art.designation;
                opt.dataset.reference = art.reference || '';
                opt.dataset.type = art.type_article || 'Bien';
                opt.dataset.unite = art.unite || 'unité';
                opt.dataset.prix = art.prix_unitaire_ht || 0;
                selectArticle.appendChild(opt);
            });

            // Pré-remplir si données fournies
            if (data) {
                clone.querySelector('.input-reference').value = data.reference || '';
                clone.querySelector('.input-designation').value = data.designation || '';
                clone.querySelector('.select-type-ligne').value = data.type_ligne || 'Bien';
                clone.querySelector('.input-quantite').value = parseFloat(data.quantite || 1).toLocaleString('fr-FR');
                clone.querySelector('.input-unite').value = data.unite || 'unité';
                clone.querySelector('.input-prix').value = parseFloat(data.prix_unitaire_ht || 0).toLocaleString('fr-FR');
                clone.querySelector('.select-type-remise').value = data.type_remise || 'Aucune';
                clone.querySelector('.input-remise').value = parseFloat(data.valeur_remise || 0).toLocaleString('fr-FR');
                clone.querySelector('.select-taxe').value = data.type_taxe || 'Aucune';
                // Trouver et sélectionner l'article si il correspond
                if (data.designation) {
                    for (let opt of selectArticle.options) {
                        if (opt.textContent === data.designation) {
                            selectArticle.value = opt.value;
                            break;
                        }
                    }
                }
            }

            container.appendChild(clone);
            ligneIndex++;

            // Calculer après ajout
            calculateAllTotals();
        }

        function removeLigne(btn) {
            const row = btn.closest('tr');
            if (document.querySelectorAll('.ligne-devis').length > 1) {
                row.remove();
                calculateAllTotals();
            } else {
                alert('Le devis doit contenir au moins une ligne');
            }
        }

        function parseNumber(str) {
            if (!str) return 0;
            return parseFloat(String(str).replace(/\s/g, '').replace(',', '.')) || 0;
        }

        function formatNumber(num) {
            return num.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        function calculateLigne(input) {
            const row = input.closest('tr');
            const quantite = parseNumber(row.querySelector('.input-quantite').value);
            const prix = parseNumber(row.querySelector('.input-prix').value);
            const typeRemise = row.querySelector('.select-type-remise').value;
            const valeurRemise = parseNumber(row.querySelector('.input-remise').value);
            const typeTaxe = row.querySelector('.select-taxe').value;

            // Montant brut
            const montantBrut = quantite * prix;

            // Calcul remise
            let montantRemise = 0;
            if (typeRemise === 'Pourcentage') {
                montantRemise = montantBrut * valeurRemise / 100;
            } else if (typeRemise === 'Montant') {
                montantRemise = valeurRemise;
            }

            // Montant HT
            const montantHT = montantBrut - montantRemise;

            // Calcul taxe
            let montantTaxe = 0;
            if (typeTaxe === 'TVA') {
                montantTaxe = montantHT * 18 / 100;
            } else if (typeTaxe === 'PPSSI') {
                montantTaxe = -montantHT * 2 / 100;
            } else if (typeTaxe === 'BNC') {
                montantTaxe = -montantHT * 7.5 / 100;
            }

            // Net à payer
            const netAPayer = montantHT + montantTaxe;

            // Afficher
            row.querySelector('.net-a-payer').textContent = formatNumber(Math.round(netAPayer));

            // Recalculer tous les totaux
            calculateAllTotals();
        }

        function calculateAllTotals() {
            let totalBrut = 0;
            let totalRemise = 0;
            let totalHT = 0;
            let totalTVA = 0;
            let totalRetenue = 0;
            let totalTTC = 0;

            document.querySelectorAll('.ligne-devis').forEach(row => {
                const quantite = parseNumber(row.querySelector('.input-quantite').value);
                const prix = parseNumber(row.querySelector('.input-prix').value);
                const typeRemise = row.querySelector('.select-type-remise').value;
                const valeurRemise = parseNumber(row.querySelector('.input-remise').value);
                const typeTaxe = row.querySelector('.select-taxe').value;

                const montantBrut = quantite * prix;
                totalBrut += montantBrut;

                let montantRemise = 0;
                if (typeRemise === 'Pourcentage') {
                    montantRemise = montantBrut * valeurRemise / 100;
                } else if (typeRemise === 'Montant') {
                    montantRemise = valeurRemise;
                }
                totalRemise += montantRemise;

                const montantHT = montantBrut - montantRemise;
                totalHT += montantHT;

                if (typeTaxe === 'TVA') {
                    totalTVA += montantHT * 18 / 100;
                } else if (typeTaxe === 'PPSSI') {
                    totalRetenue += montantHT * 2 / 100;
                } else if (typeTaxe === 'BNC') {
                    totalRetenue += montantHT * 7.5 / 100;
                }
            });

            totalTTC = totalHT + totalTVA - totalRetenue;

            document.getElementById('totalBrut').textContent = formatNumber(Math.round(totalBrut));
            document.getElementById('totalRemise').textContent = '-' + formatNumber(Math.round(totalRemise));
            document.getElementById('totalHT').textContent = formatNumber(Math.round(totalHT));
            document.getElementById('totalTVA').textContent = '+' + formatNumber(Math.round(totalTVA));
            document.getElementById('totalRetenue').textContent = '-' + formatNumber(Math.round(totalRetenue));
            document.getElementById('totalTTC').textContent = formatNumber(Math.round(totalTTC));
        }
    </script>

    <?php if ($isEdit && $devis['statut'] === 'En attente'): ?>
    <!-- Modal changement statut -->
    <div id="statusModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/70" onclick="closeStatusModal()"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4">
            <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md relative">
                <div class="p-6 border-b border-slate-700">
                    <h3 id="statusModalTitle" class="text-lg font-semibold text-white"></h3>
                </div>
                <form method="POST" action="devis.php" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $devis['id'] ?>">
                    <input type="hidden" name="statut" id="statusValue">

                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Décidé par</label>
                        <input type="text" name="decide_par" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100" placeholder="Nom de la personne">
                    </div>

                    <div id="rejetMotifContainer" class="hidden">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Motif du rejet</label>
                        <textarea name="motif_rejet" rows="3" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100" placeholder="Raison du rejet..."></textarea>
                    </div>

                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeStatusModal()" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">Annuler</button>
                        <button type="submit" id="statusSubmitBtn" class="px-4 py-2 text-white rounded-lg"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openStatusModal(statut) {
            document.getElementById('statusModal').classList.remove('hidden');
            document.getElementById('statusValue').value = statut;

            const btn = document.getElementById('statusSubmitBtn');
            const motifContainer = document.getElementById('rejetMotifContainer');

            if (statut === 'Approuvé') {
                document.getElementById('statusModalTitle').textContent = 'Approuver le devis';
                btn.textContent = 'Approuver';
                btn.className = 'px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg';
                motifContainer.classList.add('hidden');
            } else {
                document.getElementById('statusModalTitle').textContent = 'Rejeter le devis';
                btn.textContent = 'Rejeter';
                btn.className = 'px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg';
                motifContainer.classList.remove('hidden');
            }
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeStatusModal(); });
    </script>
    <?php endif; ?>
</body>
</html>
