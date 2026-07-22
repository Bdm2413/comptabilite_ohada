<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Rapports Comptables";
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
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-600 mb-2">
                    <i class="fas fa-chart-bar mr-3"></i>Rapports Comptables
                </h1>
                <p class="text-slate-400">Consultez les différents états et rapports comptables</p>
            </div>

            <!-- Rapports Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                <!-- Grand Livre -->
                <a href="grand_livre.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-blue-500 transition-all duration-300 hover:shadow-xl hover:shadow-blue-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg">
                                <i class="fas fa-book text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-blue-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Grand Livre</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Consultez tous les mouvements d'un compte pour une période donnée
                        </p>
                        <div class="flex items-center text-blue-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Balance Générale -->
                <a href="balance_generale.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-green-500 transition-all duration-300 hover:shadow-xl hover:shadow-green-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-green-600 to-green-700 rounded-lg">
                                <i class="fas fa-balance-scale text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-green-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Balance Générale</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Vue d'ensemble de tous les comptes avec soldes débiteurs et créditeurs
                        </p>
                        <div class="flex items-center text-green-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Balance Auxiliaire -->
                <a href="balance_auxiliaire.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-purple-500 transition-all duration-300 hover:shadow-xl hover:shadow-purple-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg">
                                <i class="fas fa-users text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-purple-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Balance Auxiliaire</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Balance détaillée par tiers (clients, fournisseurs)
                        </p>
                        <div class="flex items-center text-purple-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Journal Général -->
                <a href="journal_general.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-orange-500 transition-all duration-300 hover:shadow-xl hover:shadow-orange-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-orange-600 to-orange-700 rounded-lg">
                                <i class="fas fa-journal-whills text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-orange-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Journal Général</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Toutes les écritures comptables par ordre chronologique
                        </p>
                        <div class="flex items-center text-orange-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Rapprochement Bancaire -->
                <a href="rapprochement_bancaire.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-cyan-500 transition-all duration-300 hover:shadow-xl hover:shadow-cyan-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-cyan-600 to-cyan-700 rounded-lg">
                                <i class="fas fa-university text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-cyan-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Rapprochement Bancaire</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Contrôle et justification des écarts entre comptabilité et relevés bancaires
                        </p>
                        <div class="flex items-center text-cyan-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Balance Âgée Clients -->
                <a href="balance_agee_clients.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-yellow-500 transition-all duration-300 hover:shadow-xl hover:shadow-yellow-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-yellow-600 to-yellow-700 rounded-lg">
                                <i class="fas fa-clock text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-yellow-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Balance Âgée Clients</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Analyse des créances clients par ancienneté et suivi des impayés
                        </p>
                        <div class="flex items-center text-yellow-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Balance Âgée Fournisseurs -->
                <a href="balance_agee_fournisseurs.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-pink-500 transition-all duration-300 hover:shadow-xl hover:shadow-pink-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-pink-600 to-pink-700 rounded-lg">
                                <i class="fas fa-clock text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-pink-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Balance Âgée Fournisseurs</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Analyse des dettes fournisseurs par ancienneté et suivi des paiements
                        </p>
                        <div class="flex items-center text-pink-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Rapport de Caisse -->
                <a href="rapport_caisse.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-teal-500 transition-all duration-300 hover:shadow-xl hover:shadow-teal-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-teal-600 to-teal-700 rounded-lg">
                                <i class="fas fa-cash-register text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-teal-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Rapport de Caisse</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Suivi des mouvements de caisse et état de la trésorerie
                        </p>
                        <div class="flex items-center text-teal-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

                <!-- Évolution Report à Nouveau -->
                <a href="evolution_report_nouveau.php" class="group">
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 hover:border-indigo-500 transition-all duration-300 hover:shadow-xl hover:shadow-indigo-500/20">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-lg">
                                <i class="fas fa-chart-line text-2xl text-white"></i>
                            </div>
                            <i class="fas fa-arrow-right text-slate-600 group-hover:text-indigo-400 transition-colors"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Évolution Report à Nouveau</h3>
                        <p class="text-slate-400 text-sm mb-4">
                            Analyse cumulative année par année du report à nouveau et des résultats
                        </p>
                        <div class="flex items-center text-indigo-400 text-sm font-medium">
                            <span>Accéder au rapport</span>
                            <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </div>
                    </div>
                </a>

            </div>

            <!-- Info Section -->
            <div class="mt-8 bg-gradient-to-r from-blue-900/20 to-purple-900/20 border border-blue-800/30 rounded-lg p-6">
                <div class="flex items-start gap-4">
                    <div class="p-2 bg-blue-500/20 rounded-lg">
                        <i class="fas fa-info-circle text-blue-400 text-xl"></i>
                    </div>
                    <div>
                        <h4 class="text-white font-semibold mb-2">À propos des rapports</h4>
                        <p class="text-slate-400 text-sm">
                            Ces rapports sont conformes au système comptable OHADA (Organisation pour l'Harmonisation en Afrique du Droit des Affaires).
                            Tous les rapports peuvent être filtrés par période et exportés en PDF ou Excel.
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
