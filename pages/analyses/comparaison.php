<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer la liste des comptes depuis le plan comptable
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
$comptes = [];
try {
    // Récupérer tous les comptes du plan comptable
    $stmt = $db->prepare("
        SELECT compte as numero, intitule_compte as libelle
        FROM plan_comptable
        WHERE compte IS NOT NULL AND compte != ''
        AND societe_id = ?
        ORDER BY compte ASC
    ");
    $stmt->execute([$societe_id]);
    $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erreur récupération comptes: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparaison de Comptes - Analyse</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <header class="bg-slate-800/30 border-b border-slate-700/50 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-red-400 to-orange-600 mb-2">
                            <i class="fas fa-chart-bar mr-3"></i>Comparaison de Comptes
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">Analysez et comparez l'évolution de deux comptes</p>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-6 space-y-6">
                <!-- Filtres -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-blue-400"></i> Paramètres de Comparaison
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Compte 1</label>
                            <select id="compte1" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= htmlspecialchars($compte['numero']) ?>">
                                        <?= htmlspecialchars($compte['numero']) ?><?= !empty($compte['libelle']) ? ' - ' . htmlspecialchars(substr($compte['libelle'], 0, 30)) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Compte 2</label>
                            <select id="compte2" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= htmlspecialchars($compte['numero']) ?>">
                                        <?= htmlspecialchars($compte['numero']) ?><?= !empty($compte['libelle']) ? ' - ' . htmlspecialchars(substr($compte['libelle'], 0, 30)) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Date début</label>
                            <input type="date" id="date_debut" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Date fin</label>
                            <input type="date" id="date_fin" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Période</label>
                            <select id="periode" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none">
                                <option value="mois">Par Mois</option>
                                <option value="trimestre">Par Trimestre</option>
                                <option value="annee">Par Année</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-3">
                        <button onclick="chargerComparaison()" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2 rounded-lg transition-all shadow-lg hover:shadow-xl">
                            <i class="fas fa-search mr-2"></i> Comparer
                        </button>
                        <button onclick="reinitialiser()" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg transition">
                            <i class="fas fa-redo mr-2"></i> Réinitialiser
                        </button>
                    </div>
                </div>

                <!-- Statistiques de comparaison -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="statsSection" style="display: none;">
                    <div class="bg-gradient-to-br from-green-900 to-green-800 rounded-lg p-5 text-center border border-green-700 shadow-lg">
                        <div class="text-xs text-green-300 mb-2 font-semibold uppercase tracking-wide">
                            <i class="fas fa-chart-line"></i> <span id="compte1Label">Compte 1</span>
                        </div>
                        <div class="text-3xl font-bold text-white" id="totalCompte1">0</div>
                    </div>
                    <div class="bg-gradient-to-br from-blue-900 to-blue-800 rounded-lg p-5 text-center border border-blue-700 shadow-lg">
                        <div class="text-xs text-blue-300 mb-2 font-semibold uppercase tracking-wide">
                            <i class="fas fa-chart-line"></i> <span id="compte2Label">Compte 2</span>
                        </div>
                        <div class="text-3xl font-bold text-white" id="totalCompte2">0</div>
                    </div>
                    <div class="bg-gradient-to-br from-purple-900 to-purple-800 rounded-lg p-5 text-center border border-purple-700 shadow-lg">
                        <div class="text-xs text-purple-300 mb-2 font-semibold uppercase tracking-wide">
                            <i class="fas fa-percentage"></i> Différence
                        </div>
                        <div class="text-3xl font-bold text-white" id="difference">0%</div>
                    </div>
                </div>

                <!-- Graphiques -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" id="chartsSection" style="display: none;">
                    <!-- Graphique en barres -->
                    <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-bar text-cyan-400"></i> Comparaison par Période
                        </h3>
                        <div style="position: relative; height: 400px;">
                            <canvas id="barChart"></canvas>
                        </div>
                    </div>

                    <!-- Graphique en ligne -->
                    <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-line text-green-400"></i> Évolution Temporelle
                        </h3>
                        <div style="position: relative; height: 400px;">
                            <canvas id="lineChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tableau détaillé -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6" id="tableSection" style="display: none;">
                    <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-table text-orange-400"></i> Détails par Période
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-700 sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase">Période</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-300 uppercase" id="headerCompte1">Compte 1</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-300 uppercase" id="headerCompte2">Compte 2</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-300 uppercase">Différence</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-300 uppercase">Variation</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody" class="divide-y divide-gray-700">
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Message si aucune donnée -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-8 text-center" id="emptyMessage">
                    <i class="fas fa-info-circle text-gray-500 text-5xl mb-4"></i>
                    <p class="text-gray-400">Sélectionnez deux comptes et une période pour visualiser la comparaison</p>
                </div>
            </div>
        </main>
    </div>

    <script>
        let barChart, lineChart;

        // Initialiser les dates par défaut (année en cours)
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), 0, 1);
            const lastDay = new Date(today.getFullYear(), 11, 31);

            document.getElementById('date_debut').value = firstDay.toISOString().split('T')[0];
            document.getElementById('date_fin').value = lastDay.toISOString().split('T')[0];
        });

        function chargerComparaison() {
            const compte1 = document.getElementById('compte1').value;
            const compte2 = document.getElementById('compte2').value;
            const dateDebut = document.getElementById('date_debut').value;
            const dateFin = document.getElementById('date_fin').value;
            const periode = document.getElementById('periode').value;

            if (!compte1 || !compte2 || !dateDebut || !dateFin) {
                alert('Veuillez remplir tous les champs');
                return;
            }

            if (compte1 === compte2) {
                alert('Veuillez sélectionner deux comptes différents');
                return;
            }

            // Appel API
            fetch(`/comptabilite_ohada/api/v1/analyses/comparaison_comptes.php?compte1=${compte1}&compte2=${compte2}&date_debut=${dateDebut}&date_fin=${dateFin}&periode=${periode}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        afficherComparaison(data);
                    } else {
                        alert('Erreur: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des données');
                });
        }

        function afficherComparaison(data) {
            // Masquer le message vide
            document.getElementById('emptyMessage').style.display = 'none';

            // Afficher les sections
            document.getElementById('statsSection').style.display = 'grid';
            document.getElementById('chartsSection').style.display = 'grid';
            document.getElementById('tableSection').style.display = 'block';

            // Mettre à jour les statistiques
            document.getElementById('compte1Label').textContent = data.compte1.numero;
            document.getElementById('compte2Label').textContent = data.compte2.numero;
            document.getElementById('totalCompte1').textContent = formatMontant(data.compte1.total);
            document.getElementById('totalCompte2').textContent = formatMontant(data.compte2.total);

            const diffPct = data.comparaison.pourcentage;
            const diffClass = diffPct > 0 ? 'text-green-400' : 'text-red-400';
            document.getElementById('difference').innerHTML = `<span class="${diffClass}">${diffPct > 0 ? '+' : ''}${diffPct}%</span>`;

            // Préparer les données pour les graphiques
            const labels = data.comparaison.periodes;
            const dataCompte1 = data.comparaison.series.map(s => s.compte1);
            const dataCompte2 = data.comparaison.series.map(s => s.compte2);

            // Créer le graphique en barres
            creerGraphiqueBarres(labels, dataCompte1, dataCompte2, data.compte1.numero, data.compte2.numero);

            // Créer le graphique en ligne
            creerGraphiqueLigne(labels, dataCompte1, dataCompte2, data.compte1.numero, data.compte2.numero);

            // Remplir le tableau
            remplirTableau(data);

            // Mise à jour des en-têtes du tableau
            document.getElementById('headerCompte1').textContent = data.compte1.numero;
            document.getElementById('headerCompte2').textContent = data.compte2.numero;
        }

        function creerGraphiqueBarres(labels, data1, data2, label1, label2) {
            const ctx = document.getElementById('barChart');
            if (!ctx) return;

            // Détruire l'ancien graphique s'il existe
            if (barChart) {
                barChart.destroy();
                barChart = null;
            }

            barChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: label1,
                            data: data1,
                            backgroundColor: 'rgba(34, 197, 94, 0.8)',
                            borderColor: 'rgba(34, 197, 94, 1)',
                            borderWidth: 1
                        },
                        {
                            label: label2,
                            data: data2,
                            backgroundColor: 'rgba(59, 130, 246, 0.8)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 750
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#e2e8f0',
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#e2e8f0',
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + new Intl.NumberFormat('fr-FR', {
                                        style: 'currency',
                                        currency: 'XOF',
                                        minimumFractionDigits: 0
                                    }).format(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#94a3b8',
                                callback: function(value) {
                                    return new Intl.NumberFormat('fr-FR', {
                                        style: 'currency',
                                        currency: 'XOF',
                                        minimumFractionDigits: 0
                                    }).format(value);
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#94a3b8'
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });
        }

        function creerGraphiqueLigne(labels, data1, data2, label1, label2) {
            const ctx = document.getElementById('lineChart');
            if (!ctx) return;

            // Détruire l'ancien graphique s'il existe
            if (lineChart) {
                lineChart.destroy();
                lineChart = null;
            }

            lineChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: label1,
                            data: data1,
                            borderColor: 'rgba(34, 197, 94, 1)',
                            backgroundColor: 'rgba(34, 197, 94, 0.1)',
                            tension: 0.4,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        },
                        {
                            label: label2,
                            data: data2,
                            borderColor: 'rgba(59, 130, 246, 1)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 750
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#e2e8f0',
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#e2e8f0',
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + new Intl.NumberFormat('fr-FR', {
                                        style: 'currency',
                                        currency: 'XOF',
                                        minimumFractionDigits: 0
                                    }).format(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#94a3b8',
                                callback: function(value) {
                                    return new Intl.NumberFormat('fr-FR', {
                                        style: 'currency',
                                        currency: 'XOF',
                                        minimumFractionDigits: 0
                                    }).format(value);
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#94a3b8'
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });
        }

        function remplirTableau(data) {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            data.comparaison.series.forEach(row => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-gray-700 transition-colors';

                const diff = row.compte1 - row.compte2;
                const variation = row.compte2 !== 0 ? ((diff / Math.abs(row.compte2)) * 100).toFixed(2) : 0;
                const variationClass = variation > 0 ? 'text-green-400' : 'text-red-400';
                const variationIcon = variation > 0 ? 'fa-arrow-up' : 'fa-arrow-down';

                tr.innerHTML = `
                    <td class="px-4 py-3 text-gray-300 font-medium">${row.periode}</td>
                    <td class="px-4 py-3 text-right text-green-400 font-semibold">${formatMontant(row.compte1)}</td>
                    <td class="px-4 py-3 text-right text-blue-400 font-semibold">${formatMontant(row.compte2)}</td>
                    <td class="px-4 py-3 text-right text-purple-400 font-semibold">${formatMontant(diff)}</td>
                    <td class="px-4 py-3 text-center ${variationClass} font-semibold">
                        <i class="fas ${variationIcon} mr-1"></i>${Math.abs(variation)}%
                    </td>
                `;

                tbody.appendChild(tr);
            });
        }

        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: 'XOF',
                minimumFractionDigits: 0
            }).format(montant);
        }

        function reinitialiser() {
            // Détruire les graphiques pour libérer la mémoire
            if (barChart) {
                barChart.destroy();
                barChart = null;
            }
            if (lineChart) {
                lineChart.destroy();
                lineChart = null;
            }

            // Réinitialiser les champs
            document.getElementById('compte1').value = '';
            document.getElementById('compte2').value = '';
            document.getElementById('emptyMessage').style.display = 'block';
            document.getElementById('statsSection').style.display = 'none';
            document.getElementById('chartsSection').style.display = 'none';
            document.getElementById('tableSection').style.display = 'none';
        }

        // Animation au chargement
        anime({
            targets: '.bg-gradient-to-br',
            opacity: [0, 1],
            translateY: [20, 0],
            delay: anime.stagger(100),
            duration: 600,
            easing: 'easeOutQuad'
        });
    </script>
</body>
</html>
