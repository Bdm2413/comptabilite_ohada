<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer la liste des comptes pour le sélecteur
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
$stmt = $db->prepare("
    SELECT DISTINCT le.compte
    FROM lignes_ecriture le
    JOIN ecritures e ON le.id_ecriture = e.id
    WHERE e.societe_id = ?
    ORDER BY le.compte ASC
");
$stmt->execute([$societe_id]);
$comptes = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heatmap - Analyse des mouvements</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
            max-width: 600px;
        }

        .calendar-day {
            aspect-ratio: 1;
            border: 1px solid #374151;
            border-radius: 3px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.7rem;
            min-height: 45px;
        }

        .calendar-day:hover {
            transform: scale(1.15);
            z-index: 10;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.5);
            border-color: #60a5fa;
        }

        .calendar-day.empty {
            background: #1f2937;
            cursor: default;
            opacity: 0.3;
        }

        .calendar-day.empty:hover {
            transform: none;
            box-shadow: none;
        }

        .calendar-day.no-movement {
            background: #374151;
        }

        /* Échelle de couleur pour les mouvements - Plus contrastée */
        .calendar-day.level-1 { background: linear-gradient(135deg, #064e3b 0%, #065f46 100%); }
        .calendar-day.level-2 { background: linear-gradient(135deg, #065f46 0%, #047857 100%); }
        .calendar-day.level-3 { background: linear-gradient(135deg, #047857 0%, #059669 100%); }
        .calendar-day.level-4 { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
        .calendar-day.level-5 { background: linear-gradient(135deg, #10b981 0%, #34d399 100%); }

        /* Appliquer les mêmes couleurs à la légende */
        .legend-color.level-1 { background: linear-gradient(135deg, #064e3b 0%, #065f46 100%); }
        .legend-color.level-2 { background: linear-gradient(135deg, #065f46 0%, #047857 100%); }
        .legend-color.level-3 { background: linear-gradient(135deg, #047857 0%, #059669 100%); }
        .legend-color.level-4 { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
        .legend-color.level-5 { background: linear-gradient(135deg, #10b981 0%, #34d399 100%); }

        .calendar-day-number {
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        .calendar-day-ops {
            font-size: 0.65rem;
            color: #d1d5db;
            font-weight: 500;
        }

        .tooltip {
            display: none;
            position: fixed;
            background: #1f2937;
            border: 2px solid #60a5fa;
            border-radius: 10px;
            padding: 16px;
            min-width: 320px;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.7);
            z-index: 10000;
            pointer-events: none;
        }

        .tooltip.show {
            display: block;
        }

        .tooltip-header {
            font-size: 1rem;
            font-weight: 700;
            color: #60a5fa;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #374151;
        }

        .tooltip-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.9rem;
        }

        .tooltip-label {
            color: #9ca3af;
            font-weight: 500;
        }

        .tooltip-value {
            font-weight: 700;
        }

        .tooltip-operations {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #374151;
        }

        .tooltip-op-item {
            padding: 6px 0;
            font-size: 0.85rem;
            color: #d1d5db;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .day-header {
            font-weight: 700;
            text-align: center;
            padding: 6px;
            background: #374151;
            border-radius: 3px;
            font-size: 0.75rem;
            color: #e5e7eb;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .legend-color {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 2px solid #4b5563;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .legend-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #e5e7eb;
        }

        .month-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #60a5fa;
            margin-bottom: 12px;
            text-transform: capitalize;
        }
    </style>
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
                            <i class="fas fa-fire mr-3"></i>Heatmap des Mouvements
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">Visualisez les mouvements d'un compte sur une période donnée</p>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-6 space-y-6">
                <!-- Filtres -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Compte</label>
                            <select id="compte" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none">
                                <option value="">-- Sélectionner un compte --</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= htmlspecialchars($compte) ?>"><?= htmlspecialchars($compte) ?></option>
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
                        <div class="flex items-end">
                            <button onclick="chargerHeatmap()" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2 rounded-lg transition-all shadow-lg hover:shadow-xl">
                                <i class="fas fa-search mr-2"></i> Analyser
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Légende -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6" id="legendeSection" style="display: none;">
                    <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-palette text-purple-400"></i> Légende d'intensité
                    </h3>
                    <div class="flex flex-wrap gap-6">
                        <div class="legend-item">
                            <div class="legend-color bg-gray-600"></div>
                            <span class="legend-label">Aucun mouvement</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color level-1"></div>
                            <span class="legend-label">Très faible</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color level-2"></div>
                            <span class="legend-label">Faible</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color level-3"></div>
                            <span class="legend-label">Moyen</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color level-4"></div>
                            <span class="legend-label">Élevé</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color level-5"></div>
                            <span class="legend-label">Très élevé</span>
                        </div>
                    </div>
                </div>

                <!-- Statistiques -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6" id="statsSection" style="display: none;">
                    <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-blue-400"></i> Statistiques de la période
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4" id="statsContent">
                    </div>
                </div>

                <!-- Calendrier heatmap et tableau des opérations -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" id="calendarSection" style="display: none;">
                    <!-- Calendrier -->
                    <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2" id="calendarTitle">
                            <i class="fas fa-calendar-alt text-green-400"></i> Calendrier des mouvements
                        </h3>
                        <div id="calendarContainer"></div>
                    </div>

                    <!-- Tableau des opérations -->
                    <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-list text-orange-400"></i> Liste des opérations
                            <span class="text-sm font-normal text-gray-400 ml-auto" id="operationsCount">0 opération(s)</span>
                        </h3>
                        <div class="overflow-hidden rounded-lg border border-gray-700" style="max-height: 600px;">
                            <div class="overflow-y-auto" style="max-height: 600px;">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-700 sticky top-0 z-10">
                                        <tr>
                                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Date</th>
                                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Pièce</th>
                                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Libellé</th>
                                            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-300 uppercase tracking-wider">Type</th>
                                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-300 uppercase tracking-wider">Montant</th>
                                        </tr>
                                    </thead>
                                    <tbody id="operationsTableBody" class="divide-y divide-gray-700">
                                        <!-- Les lignes seront ajoutées ici par JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Message si aucune donnée -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-8 text-center" id="emptyMessage">
                    <i class="fas fa-info-circle text-gray-500 text-5xl mb-4"></i>
                    <p class="text-gray-400">Sélectionnez un compte et une période pour visualiser la heatmap</p>
                </div>

                <!-- Conteneur global pour le tooltip -->
                <div id="globalTooltip" class="tooltip"></div>

    <script>
        // Initialiser les dates par défaut
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);

            document.getElementById('date_debut').value = firstDay.toISOString().split('T')[0];
            document.getElementById('date_fin').value = lastDay.toISOString().split('T')[0];
        });

        let mouvementsData = {};
        let maxMouvement = 0;

        function chargerHeatmap() {
            const compte = document.getElementById('compte').value;
            const dateDebut = document.getElementById('date_debut').value;
            const dateFin = document.getElementById('date_fin').value;

            if (!compte) {
                alert('⚠️ Veuillez sélectionner un compte');
                return;
            }

            if (!dateDebut || !dateFin) {
                alert('⚠️ Veuillez sélectionner une période');
                return;
            }

            // Afficher un loader
            document.getElementById('emptyMessage').innerHTML = '<i class="fas fa-spinner fa-spin text-blue-500 text-5xl mb-4"></i><p class="text-gray-400">Chargement des données...</p>';
            document.getElementById('emptyMessage').style.display = 'block';
            document.getElementById('calendarSection').style.display = 'none';
            document.getElementById('statsSection').style.display = 'none';
            document.getElementById('legendeSection').style.display = 'none';

            fetch(`/comptabilite_ohada/api/v1/analyses/mouvements_compte.php?compte=${encodeURIComponent(compte)}&date_debut=${dateDebut}&date_fin=${dateFin}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        afficherHeatmap(data, dateDebut, dateFin);
                    } else {
                        alert('❌ Erreur : ' + data.error);
                        document.getElementById('emptyMessage').innerHTML = '<i class="fas fa-exclamation-triangle text-red-500 text-5xl mb-4"></i><p class="text-gray-400">' + data.error + '</p>';
                    }
                })
                .catch(error => {
                    alert('❌ Erreur réseau : ' + error.message);
                    document.getElementById('emptyMessage').innerHTML = '<i class="fas fa-exclamation-triangle text-red-500 text-5xl mb-4"></i><p class="text-gray-400">Erreur de chargement</p>';
                });
        }

        function afficherHeatmap(data, dateDebut, dateFin) {
            // Préparer les données
            mouvementsData = {};
            maxMouvement = 0;

            data.mouvements.forEach(mvt => {
                const montantAbs = Math.abs(mvt.mouvement_net);
                mouvementsData[mvt.date] = mvt;
                if (montantAbs > maxMouvement) {
                    maxMouvement = montantAbs;
                }
            });

            // Afficher les statistiques
            afficherStatistiques(data);

            // Générer le calendrier
            genererCalendrier(dateDebut, dateFin);

            // Remplir le tableau des opérations
            remplirTableauOperations(data);

            // Afficher les sections
            document.getElementById('emptyMessage').style.display = 'none';
            document.getElementById('calendarSection').style.display = 'grid';
            document.getElementById('statsSection').style.display = 'block';
            document.getElementById('legendeSection').style.display = 'block';

            document.getElementById('calendarTitle').innerHTML = `<i class="fas fa-calendar-alt text-green-400"></i> Compte ${data.compte} - ${formatDate(dateDebut)} au ${formatDate(dateFin)}`;
        }

        function afficherStatistiques(data) {
            let totalDebit = 0;
            let totalCredit = 0;
            let nbOperations = 0;

            data.mouvements.forEach(mvt => {
                totalDebit += mvt.total_debit;
                totalCredit += mvt.total_credit;
                nbOperations += mvt.nb_operations;
            });

            const statsHtml = `
                <div class="bg-gradient-to-br from-green-900 to-green-800 rounded-lg p-5 text-center border border-green-700 shadow-lg">
                    <div class="text-xs text-green-300 mb-2 font-semibold uppercase tracking-wide">
                        <i class="fas fa-plus-circle"></i> Total Débits
                    </div>
                    <div class="text-3xl font-bold text-white">${formatMontant(totalDebit)}</div>
                </div>
                <div class="bg-gradient-to-br from-red-900 to-red-800 rounded-lg p-5 text-center border border-red-700 shadow-lg">
                    <div class="text-xs text-red-300 mb-2 font-semibold uppercase tracking-wide">
                        <i class="fas fa-minus-circle"></i> Total Crédits
                    </div>
                    <div class="text-3xl font-bold text-white">${formatMontant(totalCredit)}</div>
                </div>
                <div class="bg-gradient-to-br from-blue-900 to-blue-800 rounded-lg p-5 text-center border border-blue-700 shadow-lg">
                    <div class="text-xs text-blue-300 mb-2 font-semibold uppercase tracking-wide">
                        <i class="fas fa-calendar-check"></i> Jours Actifs
                    </div>
                    <div class="text-3xl font-bold text-white">${data.total_jours_activite}</div>
                </div>
                <div class="bg-gradient-to-br from-purple-900 to-purple-800 rounded-lg p-5 text-center border border-purple-700 shadow-lg">
                    <div class="text-xs text-purple-300 mb-2 font-semibold uppercase tracking-wide">
                        <i class="fas fa-list"></i> Opérations
                    </div>
                    <div class="text-3xl font-bold text-white">${nbOperations}</div>
                </div>
            `;

            document.getElementById('statsContent').innerHTML = statsHtml;
        }

        function genererCalendrier(dateDebut, dateFin) {
            const debut = new Date(dateDebut);
            const fin = new Date(dateFin);

            const container = document.getElementById('calendarContainer');
            container.innerHTML = '';

            // Calculer le nombre de mois à afficher
            const mois = [];
            const currentDate = new Date(debut);

            while (currentDate <= fin) {
                mois.push({
                    annee: currentDate.getFullYear(),
                    mois: currentDate.getMonth()
                });
                currentDate.setMonth(currentDate.getMonth() + 1);
            }

            // Générer un calendrier pour chaque mois
            mois.forEach(m => {
                genererMoisCalendrier(m.annee, m.mois, debut, fin, container);
            });
        }

        function genererMoisCalendrier(annee, mois, dateDebut, dateFin, container) {
            const moisDiv = document.createElement('div');
            moisDiv.className = 'mb-10';

            const moisNom = new Date(annee, mois).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
            moisDiv.innerHTML = `<h4 class="month-title">${moisNom}</h4>`;

            const grid = document.createElement('div');
            grid.className = 'calendar-grid';

            // En-têtes des jours
            const jours = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
            jours.forEach(jour => {
                const header = document.createElement('div');
                header.className = 'day-header';
                header.textContent = jour;
                grid.appendChild(header);
            });

            // Premier jour du mois
            const premierJour = new Date(annee, mois, 1);
            let jourSemaine = premierJour.getDay();
            jourSemaine = jourSemaine === 0 ? 6 : jourSemaine - 1; // Lundi = 0

            // Ajouter des cases vides pour aligner le premier jour
            for (let i = 0; i < jourSemaine; i++) {
                const emptyDay = document.createElement('div');
                emptyDay.className = 'calendar-day empty';
                grid.appendChild(emptyDay);
            }

            // Dernier jour du mois
            const dernierJour = new Date(annee, mois + 1, 0).getDate();

            // Générer les jours du mois
            for (let jour = 1; jour <= dernierJour; jour++) {
                const date = new Date(annee, mois, jour);
                const dateStr = date.toISOString().split('T')[0];

                // Vérifier si la date est dans la période
                if (date >= dateDebut && date <= dateFin) {
                    const dayDiv = creerJourCalendrier(jour, dateStr);
                    grid.appendChild(dayDiv);
                }
            }

            moisDiv.appendChild(grid);
            container.appendChild(moisDiv);
        }

        function creerJourCalendrier(jour, dateStr) {
            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day';

            const mvt = mouvementsData[dateStr];

            if (mvt) {
                // Calculer le niveau de couleur (1-5)
                const montantAbs = Math.abs(mvt.mouvement_net);
                const niveau = maxMouvement > 0 ? Math.ceil((montantAbs / maxMouvement) * 5) : 1;
                dayDiv.classList.add(`level-${Math.max(1, Math.min(5, niveau))}`);

                // Contenu du jour
                dayDiv.innerHTML = `
                    <div class="calendar-day-number">${jour}</div>
                    <div class="calendar-day-ops">${mvt.nb_operations} op.</div>
                `;

                // Récupérer le tooltip global
                const tooltip = document.getElementById('globalTooltip');

                // Événements pour afficher/masquer le tooltip
                dayDiv.addEventListener('mouseenter', function(e) {
                    // Remplir le contenu du tooltip
                    tooltip.innerHTML = `
                        <div class="tooltip-header">
                            <i class="fas fa-calendar-day"></i> ${formatDate(dateStr)}
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label"><i class="fas fa-plus-circle text-green-400"></i> Débits:</span>
                            <span class="tooltip-value text-green-400">${formatMontant(mvt.total_debit)}</span>
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label"><i class="fas fa-minus-circle text-red-400"></i> Crédits:</span>
                            <span class="tooltip-value text-red-400">${formatMontant(mvt.total_credit)}</span>
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label"><i class="fas fa-equals text-blue-400"></i> Mouvement Net:</span>
                            <span class="tooltip-value text-blue-400">${formatMontant(mvt.mouvement_net)}</span>
                        </div>
                        <div class="tooltip-operations">
                            <div style="font-weight: 600; color: #9ca3af; margin-bottom: 8px;">
                                <i class="fas fa-list-ul"></i> ${mvt.nb_operations} opération(s)
                            </div>
                            ${mvt.operations.slice(0, 3).map(op => `
                                <div class="tooltip-op-item">
                                    <i class="fas fa-arrow-right" style="color: #6b7280; font-size: 0.7rem; margin-top: 2px;"></i>
                                    <span>${op.libelle.substring(0, 40)}${op.libelle.length > 40 ? '...' : ''}</span>
                                </div>
                            `).join('')}
                            ${mvt.operations.length > 3 ? `
                                <div style="color: #6b7280; font-size: 0.8rem; margin-top: 8px; font-style: italic;">
                                    <i class="fas fa-ellipsis-h"></i> + ${mvt.operations.length - 3} autre(s) opération(s)
                                </div>
                            ` : ''}
                        </div>
                    `;

                    // Positionner et afficher (au-dessus de la souris)
                    const tooltipHeight = 400; // Hauteur estimée du tooltip
                    tooltip.style.left = (e.clientX + 15) + 'px';
                    tooltip.style.top = (e.clientY - tooltipHeight) + 'px';
                    tooltip.classList.add('show');
                });

                dayDiv.addEventListener('mousemove', function(e) {
                    const tooltipHeight = 400; // Hauteur estimée du tooltip
                    tooltip.style.left = (e.clientX + 15) + 'px';
                    tooltip.style.top = (e.clientY - tooltipHeight) + 'px';
                });

                dayDiv.addEventListener('mouseleave', function() {
                    tooltip.classList.remove('show');
                });
            } else {
                dayDiv.classList.add('no-movement');
                dayDiv.innerHTML = `
                    <div class="calendar-day-number">${jour}</div>
                `;
            }

            return dayDiv;
        }

        function remplirTableauOperations(data) {
            const tbody = document.getElementById('operationsTableBody');
            tbody.innerHTML = '';

            let totalOperations = 0;
            const toutesOperations = [];

            // Collecter toutes les opérations
            data.mouvements.forEach(mvt => {
                mvt.operations.forEach(op => {
                    toutesOperations.push({
                        ...op,
                        date: mvt.date
                    });
                });
            });

            totalOperations = toutesOperations.length;
            document.getElementById('operationsCount').textContent = `${totalOperations} opération(s)`;

            // Trier par date décroissante
            toutesOperations.sort((a, b) => new Date(b.date) - new Date(a.date));

            // Générer les lignes du tableau
            toutesOperations.forEach(op => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-gray-700 transition-colors';

                const isDebit = op.debit > 0;
                const montant = isDebit ? op.debit : op.credit;
                const typeClass = isDebit ? 'text-green-400' : 'text-red-400';
                const typeIcon = isDebit ? 'fa-plus-circle' : 'fa-minus-circle';
                const typeText = isDebit ? 'Débit' : 'Crédit';

                // Déterminer la référence à afficher (priorité: reference_piece > num_piece > numero_ecriture)
                const reference = op.reference_piece || op.num_piece || op.numero_ecriture;

                tr.innerHTML = `
                    <td class="px-3 py-3 whitespace-nowrap text-gray-300">
                        ${new Date(op.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })}
                    </td>
                    <td class="px-3 py-3 whitespace-nowrap">
                        <a href="../ecritures/voir.php?id=${op.id_ecriture}"
                           class="text-blue-400 hover:text-blue-300 hover:underline font-medium"
                           title="Voir l'écriture ${op.numero_ecriture}">
                            ${reference || 'N/A'}
                        </a>
                        ${op.numero_facture ? `<div class="text-xs text-gray-500 mt-1">Facture: ${op.numero_facture}</div>` : ''}
                    </td>
                    <td class="px-3 py-3 text-gray-300">
                        <div class="max-w-xs truncate" title="${op.libelle}">
                            ${op.libelle}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            Compte: ${op.compte} | ${op.journal}
                        </div>
                    </td>
                    <td class="px-3 py-3 text-center">
                        <span class="${typeClass} inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-full bg-gray-700">
                            <i class="fas ${typeIcon}"></i>
                            ${typeText}
                        </span>
                    </td>
                    <td class="px-3 py-3 text-right font-semibold ${typeClass}">
                        ${formatMontant(montant)}
                    </td>
                `;

                tbody.appendChild(tr);
            });

            // Si aucune opération
            if (totalOperations === 0) {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td colspan="5" class="px-3 py-8 text-center text-gray-500">
                        <i class="fas fa-inbox text-3xl mb-2"></i>
                        <div>Aucune opération trouvée pour cette période</div>
                    </td>
                `;
                tbody.appendChild(tr);
            }
        }

        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: 'XOF',
                minimumFractionDigits: 0
            }).format(montant);
        }

        function formatDate(dateStr) {
            return new Date(dateStr).toLocaleDateString('fr-FR', {
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        }
    </script>
            </div>
        </main>
    </div>
</body>
</html>
