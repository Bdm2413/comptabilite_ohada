<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Import d'écritures";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-blue-600 mb-2">
                            <i class="fas fa-file-import mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400">Importez vos écritures depuis des fichiers Excel</p>
                    </div>
                    <a href="liste.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mb-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-900/20 border border-green-800/30 text-green-300' : 'bg-red-900/20 border border-red-800/30 text-red-300' ?>">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-<?= $_SESSION['flash']['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> text-xl mt-0.5"></i>
                        <div class="flex-1">
                            <?= $_SESSION['flash']['message'] ?>
                            <?php if (isset($_SESSION['flash']['details'])): ?>
                                <ul class="mt-2 ml-4 list-disc">
                                    <?php foreach ($_SESSION['flash']['details'] as $detail): ?>
                                        <li><?= htmlspecialchars($detail) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Instructions -->
            <div class="bg-blue-900/20 border border-blue-800/30 rounded-xl p-6 mb-6">
                <h3 class="text-lg font-semibold text-blue-300 mb-3 flex items-center">
                    <i class="fas fa-info-circle mr-2"></i>Instructions
                </h3>
                <div class="text-slate-300 space-y-2">
                    <p><strong>Format attendu :</strong></p>
                    <ul class="ml-6 list-disc space-y-1">
                        <li>Les fichiers doivent être placés à la racine : <code class="bg-slate-800 px-2 py-1 rounded">C:\wamp64\www\comptabilite_ohada\</code></li>
                        <li><strong>ecritures.xlsx</strong> : Fichier des en-têtes d'écritures</li>
                        <li><strong>lignes_ecriture.xlsx</strong> : Fichier des lignes d'écritures</li>
                    </ul>
                    <p class="mt-4"><strong>Colonnes :</strong></p>
                    <div class="grid grid-cols-2 gap-4 mt-2">
                        <div>
                            <p class="font-semibold text-blue-300 mb-2">ecritures.xlsx :</p>
                            <div class="text-sm space-y-1">
                                <p class="font-semibold text-green-300">Obligatoires :</p>
                                <ul class="ml-6 list-disc">
                                    <li>numero_ecriture</li>
                                    <li>date_ecriture</li>
                                    <li>journal</li>
                                    <li>libelle</li>
                                </ul>
                                <p class="font-semibold text-yellow-300 mt-2">Optionnelles :</p>
                                <ul class="ml-6 list-disc">
                                    <li>statut (Brouillon/Validé)</li>
                                    <li>id_tiers</li>
                                    <li>compte_tiers</li>
                                    <li>num_piece</li>
                                    <li>reference_piece</li>
                                    <li>num_facture</li>
                                    <li>type_document</li>
                                    <li>type_facture (DOIT/AVOIR/NORMALE)</li>
                                    <li>facture_initiale</li>
                                    <li>montant_total</li>
                                    <li>lettrage</li>
                                    <li>statut_lettrage</li>
                                    <li>extournee (Non/Oui)</li>
                                    <li>id_ecriture_extourne</li>
                                </ul>
                            </div>
                        </div>
                        <div>
                            <p class="font-semibold text-blue-300 mb-2">lignes_ecriture.xlsx :</p>
                            <div class="text-sm space-y-1">
                                <p class="font-semibold text-green-300">Obligatoires :</p>
                                <ul class="ml-6 list-disc">
                                    <li>numero_ecriture</li>
                                    <li>compte</li>
                                    <li>debit</li>
                                    <li>credit</li>
                                </ul>
                                <p class="font-semibold text-yellow-300 mt-2">Optionnelles :</p>
                                <ul class="ml-6 list-disc">
                                    <li>libelle</li>
                                    <li>compte_tiers</li>
                                    <li>numero_facture</li>
                                    <li>date_ligne</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <p class="mt-4 text-slate-400 text-sm">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note :</strong> Les colonnes <code class="bg-slate-800 px-1 rounded">mois</code> et <code class="bg-slate-800 px-1 rounded">annee</code> sont calculées automatiquement à partir de <code class="bg-slate-800 px-1 rounded">date_ecriture</code>.
                    </p>
                    <p class="mt-4 text-yellow-300">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Attention :</strong> L'import va supprimer toutes les écritures existantes pour l'exercice 2025 avant d'importer les nouvelles.
                    </p>
                </div>
            </div>

            <!-- Formulaire d'import -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-upload mr-2 text-green-400"></i>Lancer l'import
                </h3>

                <form method="POST" action="traiter_import.php" id="import-form">
                    <div class="space-y-4">
                        <!-- Confirmation -->
                        <div class="bg-yellow-900/20 border border-yellow-800/30 rounded-lg p-4">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="confirmer" id="confirmer" required
                                       class="mt-1 w-5 h-5 text-green-600 bg-slate-700 border-slate-600 rounded focus:ring-green-500">
                                <span class="text-slate-300">
                                    Je confirme vouloir importer les écritures depuis les fichiers Excel.
                                    <span class="text-red-400 font-semibold">Toutes les écritures existantes de l'exercice 2025 seront supprimées.</span>
                                </span>
                            </label>
                        </div>

                        <!-- Options d'import -->
                        <div class="bg-slate-800/50 rounded-lg p-4 space-y-3">
                            <h4 class="font-semibold text-slate-300 mb-3">Options d'import</h4>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="creer_comptes" value="1" checked
                                       class="w-4 h-4 text-blue-600 bg-slate-700 border-slate-600 rounded focus:ring-blue-500">
                                <span class="text-slate-300">Créer automatiquement les comptes inexistants dans le plan comptable</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="creer_tiers" value="1" checked
                                       class="w-4 h-4 text-blue-600 bg-slate-700 border-slate-600 rounded focus:ring-blue-500">
                                <span class="text-slate-300">Créer automatiquement les tiers inexistants</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="mode_simulation" value="1"
                                       class="w-4 h-4 text-purple-600 bg-slate-700 border-slate-600 rounded focus:ring-purple-500">
                                <span class="text-slate-300">Mode simulation (analyse sans import réel)</span>
                            </label>
                        </div>

                        <!-- Boutons -->
                        <div class="flex items-center gap-3 pt-4">
                            <button type="submit" id="btn-import"
                                    class="px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                <i class="fas fa-play"></i>
                                Lancer l'import
                            </button>
                            <a href="liste.php" class="px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-times"></i>
                                Annuler
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Aide -->
            <div class="mt-6 bg-slate-800/50 rounded-lg p-4">
                <h4 class="font-semibold text-slate-300 mb-2">
                    <i class="fas fa-question-circle mr-2 text-blue-400"></i>Besoin d'aide ?
                </h4>
                <p class="text-slate-400 text-sm">
                    Assurez-vous que vos fichiers Excel sont bien nommés <strong>ecritures.xlsx</strong> et <strong>lignes_ecriture.xlsx</strong>
                    et qu'ils se trouvent dans le dossier racine de l'application.
                </p>
            </div>
        </main>
    </div>

    <script>
        // Activer/désactiver le bouton selon la confirmation
        const confirmer = document.getElementById('confirmer');
        const btnImport = document.getElementById('btn-import');

        confirmer.addEventListener('change', function() {
            btnImport.disabled = !this.checked;
        });

        // Désactiver le bouton au submit
        document.getElementById('import-form').addEventListener('submit', function() {
            btnImport.disabled = true;
            btnImport.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Import en cours...';
        });
    </script>
</body>
</html>
