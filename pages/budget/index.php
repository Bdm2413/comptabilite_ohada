<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Gestion Budgétaire";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
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
                <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-600 mb-2">
                    <i class="fas fa-chart-line mr-3"></i>Gestion Budgétaire
                </h1>
                <p class="text-slate-400">Planification, suivi et analyse budgétaire conforme OHADA</p>
            </div>

            <!-- Modules Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                <!-- Tableau de bord -->
                <a href="dashboard.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-indigo-500 transition-all duration-300 hover:shadow-xl hover:shadow-indigo-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-lg">
                                <i class="fas fa-tachometer-alt text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-indigo-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Tableau de Bord</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Vue d'ensemble des budgets avec indicateurs clés et graphiques
                        </p>
                        <div class="flex items-center text-indigo-400 text-sm font-medium">
                            <span>Accéder au tableau de bord</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Import Budget -->
                <a href="import_budget.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-blue-500 transition-all duration-300 hover:shadow-xl hover:shadow-blue-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg">
                                <i class="fas fa-file-upload text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-blue-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Import Budget</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Importer un budget depuis un fichier Excel
                        </p>
                        <div class="flex items-center text-blue-400 text-sm font-medium">
                            <span>Importer un fichier</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Suivi Détaillé -->
                <a href="suivi_detaille.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-purple-500 transition-all duration-300 hover:shadow-xl hover:shadow-purple-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg">
                                <i class="fas fa-list-alt text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-purple-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Suivi Détaillé</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Analyse détaillée par compte avec budget vs réalisé
                        </p>
                        <div class="flex items-center text-purple-400 text-sm font-medium">
                            <span>Voir le suivi détaillé</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Analyse Comparative -->
                <a href="analyse_comparative.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-orange-500 transition-all duration-300 hover:shadow-xl hover:shadow-orange-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-orange-600 to-orange-700 rounded-lg">
                                <i class="fas fa-chart-bar text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-orange-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Analyse Comparative</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Comparer les budgets entre différentes années
                        </p>
                        <div class="flex items-center text-orange-400 text-sm font-medium">
                            <span>Comparer les budgets</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Gestion Versions -->
                <a href="gestion_versions.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-cyan-500 transition-all duration-300 hover:shadow-xl hover:shadow-cyan-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-cyan-600 to-cyan-700 rounded-lg">
                                <i class="fas fa-code-branch text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-cyan-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Gestion Versions</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Gérer les versions de budget (initial, révisé, etc.)
                        </p>
                        <div class="flex items-center text-cyan-400 text-sm font-medium">
                            <span>Gérer les versions</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Rubriques Budgétaires -->
                <a href="rubriques.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-green-500 transition-all duration-300 hover:shadow-xl hover:shadow-green-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-green-600 to-green-700 rounded-lg">
                                <i class="fas fa-tags text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-green-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Rubriques Budgétaires</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Définir et gérer les catégories budgétaires personnalisées
                        </p>
                        <div class="flex items-center text-green-400 text-sm font-medium">
                            <span>Gérer les rubriques</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

            </div>

            <!-- Info Section -->
            <div class="mt-8 bg-gradient-to-r from-indigo-900/20 to-purple-900/20 border border-indigo-800/30 rounded-lg p-6">
                <div class="flex items-start gap-4">
                    <div class="p-2 bg-indigo-500/20 rounded-lg">
                        <i class="fas fa-info-circle text-indigo-400 text-xl"></i>
                    </div>
                    <div>
                        <h4 class="text-white font-semibold mb-2">À propos du module budgétaire</h4>
                        <p class="text-slate-400 text-sm">
                            Ce module permet de créer et suivre vos budgets annuels. Vous pouvez saisir manuellement les montants par compte et par mois,
                            ou importer un fichier Excel. Le système compare automatiquement les budgets avec les réalisations comptables et génère
                            des indicateurs de performance (taux de réalisation, écarts, alertes). Tous les rapports sont exportables en PDF et Excel.
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
