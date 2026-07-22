<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Import Budget depuis Excel";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les versions de budget existantes
$stmt = $db->prepare("SELECT id, annee, version FROM budget_versions WHERE societe_id = ? ORDER BY annee DESC, version");
$stmt->execute([$societe_id]);
$versions = $stmt->fetchAll();

// Récupérer les rubriques budgétaires
$stmt = $db->query("SELECT id, code, libelle FROM budget_rubriques WHERE actif = 'Oui' ORDER BY type, ordre_affichage");
$rubriques = $stmt->fetchAll();

$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;
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
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-600 mb-2">
                            <i class="fas fa-file-upload mr-3"></i>Import Budget depuis Excel
                        </h1>
                        <p class="text-slate-400">Importer un budget annuel depuis un fichier Excel</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="bg-green-900/20 border border-green-700/50 rounded-lg p-4 mb-6">
                    <p class="text-green-300">
                        <i class="fas fa-check-circle mr-2"></i>Budget importé avec succès !
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-900/20 border border-red-700/50 rounded-lg p-4 mb-6">
                    <p class="text-red-300">
                        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Formulaire Import -->
            <form method="POST" action="import_budget_process.php" id="importForm" enctype="multipart/form-data">
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-8">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Année <span class="text-red-400">*</span>
                            </label>
                            <input type="number" name="annee" id="annee" required min="2020" max="2100" value="<?= date('Y') ?>"
                                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Version <span class="text-red-400">*</span>
                            </label>
                            <input type="text" name="version" id="version" required maxlength="50" placeholder="Ex: Budget Initial"
                                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Statut <span class="text-red-400">*</span>
                            </label>
                            <select name="statut" id="statut" required
                                    class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500">
                                <option value="Brouillon">Brouillon</option>
                                <option value="En cours">En cours</option>
                                <option value="Validé">Validé</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-8">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Description (optionnel)</label>
                        <textarea name="description" id="description" rows="2"
                                  class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500"
                                  placeholder="Description du budget importé"></textarea>
                    </div>

                    <div class="max-w-2xl mx-auto">
                        <div id="dropZone" class="bg-blue-900/20 border-2 border-dashed border-blue-700/50 rounded-lg p-12 text-center hover:border-blue-500 transition-colors cursor-pointer">
                            <i class="fas fa-cloud-upload-alt text-6xl text-blue-400 mb-4"></i>
                            <p class="text-white font-semibold mb-2">Glissez-déposez votre fichier Excel ici</p>
                            <p class="text-slate-400 text-sm mb-4">ou cliquez pour parcourir</p>
                            <input type="file" name="fichier_excel" accept=".xlsx,.xls" class="hidden" id="fileInput" required>
                            <label for="fileInput" class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg cursor-pointer transition-colors">
                                <i class="fas fa-folder-open mr-2"></i>Parcourir les fichiers
                            </label>
                            <p id="fileName" class="mt-4 text-green-400 font-semibold hidden"></p>
                        </div>

                        <div class="mt-8 bg-yellow-900/20 border border-yellow-700/50 rounded-lg p-4">
                            <h3 class="text-yellow-300 font-semibold mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Format du fichier Excel
                            </h3>
                            <ul class="text-slate-300 text-sm space-y-1">
                                <li>• Première ligne : En-têtes de colonnes</li>
                                <li>• Colonne A : Numéro de compte (ex: 601100) <span class="text-red-400">*</span></li>
                                <li>• Colonne B : Intitulé du compte (optionnel)</li>
                                <li>• Colonne C : Code rubrique budgétaire (ex: ACHATS, CA, PERSONNEL)</li>
                                <li>• Colonne D : Numéro de compte Oracle (optionnel)</li>
                                <li>• Colonnes E à P : Montants mensuels (Janvier à Décembre)</li>
                                <li>• Formats acceptés : .xlsx, .xls</li>
                            </ul>
                        </div>

                        <div class="mt-6 flex gap-4 justify-center">
                            <button type="submit" class="px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all font-semibold">
                                <i class="fas fa-upload mr-2"></i>Importer le budget
                            </button>
                            <a href="generer_modele_excel.php" class="px-8 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-download"></i>Télécharger un modèle
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script>
        // Gestion du drag & drop
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileName = document.getElementById('fileName');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.add('border-blue-500');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.remove('border-blue-500');
            }, false);
        });

        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateFileName(files[0].name);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                updateFileName(e.target.files[0].name);
            }
        });

        function updateFileName(name) {
            fileName.textContent = '📄 Fichier sélectionné : ' + name;
            fileName.classList.remove('hidden');
        }
    </script>
</body>
</html>
