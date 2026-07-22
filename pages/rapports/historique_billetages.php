<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Historique des Billetages";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres
$compte_caisse = $_GET['compte_caisse'] ?? '5711000';
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

// Récupérer l'intitulé du compte
$stmt = $db->prepare("SELECT intitule_compte FROM plan_comptable WHERE compte = ?");
$stmt->execute([$compte_caisse]);
$compte_info = $stmt->fetch();
$intitule_caisse = $compte_info['intitule_compte'] ?? 'Caisse';

// Récupérer les comptes de caisse disponibles
$stmt = $db->query("SELECT compte, intitule_compte FROM plan_comptable WHERE compte LIKE '571%' AND actif = 'Oui' ORDER BY compte");
$comptes_caisse = $stmt->fetchAll();

// Récupérer l'historique des billetages
$stmt = $db->prepare("
    SELECT *
    FROM billetages
    WHERE compte_caisse = ? AND date_billetage BETWEEN ? AND ? AND societe_id = ?
    ORDER BY date_billetage DESC
");
$stmt->execute([$compte_caisse, $date_debut, $date_fin, $societe_id]);
$billetages = $stmt->fetchAll();

// Calculer les statistiques
$total_ecarts = 0;
$nb_billetages = count($billetages);
foreach ($billetages as $b) {
    $total_ecarts += abs($b['ecart']);
}
$ecart_moyen = $nb_billetages > 0 ? $total_ecarts / $nb_billetages : 0;
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
<body class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 min-h-screen">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- En-tête -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border-b border-slate-700 rounded-xl p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                            <i class="fas fa-history mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400">Suivi des comptages de caisse et écarts constatés</p>
                    </div>
                    <a href="rapport_caisse.php?compte_caisse=<?= urlencode($compte_caisse) ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>"
                       class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour au rapport
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Compte Caisse</label>
                        <select name="compte_caisse" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($comptes_caisse as $compte): ?>
                                <option value="<?= $compte['compte'] ?>" <?= $compte['compte'] == $compte_caisse ? 'selected' : '' ?>>
                                    <?= $compte['compte'] ?> - <?= $compte['intitule_compte'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Date Début</label>
                        <input type="date" name="date_debut" value="<?= $date_debut ?>"
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Date Fin</label>
                        <input type="date" name="date_fin" value="<?= $date_fin ?>"
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all">
                            <i class="fas fa-search mr-2"></i>Filtrer
                        </button>
                    </div>
                </form>
            </div>

            <!-- Statistiques -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-gradient-to-br from-blue-900/50 to-blue-800/30 border border-blue-700/50 rounded-xl p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-blue-300 font-semibold">Nombre de billetages</h3>
                        <i class="fas fa-calculator text-2xl text-blue-400"></i>
                    </div>
                    <p class="text-3xl font-bold text-white"><?= $nb_billetages ?></p>
                </div>

                <div class="bg-gradient-to-br from-purple-900/50 to-purple-800/30 border border-purple-700/50 rounded-xl p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-purple-300 font-semibold">Écart moyen</h3>
                        <i class="fas fa-chart-line text-2xl text-purple-400"></i>
                    </div>
                    <p class="text-3xl font-bold text-white"><?= number_format($ecart_moyen, 2, ',', ' ') ?> F</p>
                </div>

                <div class="bg-gradient-to-br from-orange-900/50 to-orange-800/30 border border-orange-700/50 rounded-xl p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-orange-300 font-semibold">Total écarts absolus</h3>
                        <i class="fas fa-exclamation-triangle text-2xl text-orange-400"></i>
                    </div>
                    <p class="text-3xl font-bold text-white"><?= number_format($total_ecarts, 2, ',', ' ') ?> F</p>
                </div>
            </div>

            <!-- Liste des billetages -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
                <div class="p-6 border-b border-slate-700">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-list mr-2 text-purple-400"></i>
                        Historique des billetages
                    </h2>
                </div>

                <?php if (empty($billetages)): ?>
                    <div class="p-12 text-center">
                        <i class="fas fa-inbox text-6xl text-slate-600 mb-4"></i>
                        <p class="text-slate-400 text-lg">Aucun billetage enregistré pour cette période</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-slate-300 font-semibold">Date</th>
                                    <th class="px-4 py-3 text-left text-slate-300 font-semibold">Compte</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Total Billets</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Total Pièces</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Solde Physique</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Solde Comptable</th>
                                    <th class="px-4 py-3 text-right text-slate-300 font-semibold">Écart</th>
                                    <th class="px-4 py-3 text-left text-slate-300 font-semibold">Par</th>
                                    <th class="px-4 py-3 text-center text-slate-300 font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php foreach ($billetages as $billetage): ?>
                                    <tr class="hover:bg-slate-700/30 transition-colors">
                                        <td class="px-4 py-3 text-slate-300">
                                            <i class="fas fa-calendar mr-2 text-blue-400"></i>
                                            <?= date('d/m/Y', strtotime($billetage['date_billetage'])) ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-300"><?= $billetage['compte_caisse'] ?></td>
                                        <td class="px-4 py-3 text-right font-mono text-green-400">
                                            <?= number_format($billetage['total_billets'], 2, ',', ' ') ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-green-400">
                                            <?= number_format($billetage['total_pieces'], 2, ',', ' ') ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-blue-400">
                                            <?= number_format($billetage['solde_physique'], 2, ',', ' ') ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-slate-300">
                                            <?= number_format($billetage['solde_comptable'], 2, ',', ' ') ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono font-bold <?= $billetage['ecart'] == 0 ? 'text-green-400' : ($billetage['ecart'] > 0 ? 'text-orange-400' : 'text-red-400') ?>">
                                            <?= $billetage['ecart'] > 0 ? '+' : '' ?><?= number_format($billetage['ecart'], 2, ',', ' ') ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-400 text-sm">
                                            <?= htmlspecialchars($billetage['createur']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <button onclick="voirDetail(<?= $billetage['id'] ?>)"
                                                    class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded transition-colors">
                                                <i class="fas fa-eye mr-1"></i>Détails
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal pour les détails -->
    <div id="modalDetail" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-slate-700 flex items-center justify-between">
                <h3 class="text-xl font-bold text-white">
                    <i class="fas fa-file-invoice-dollar mr-2 text-purple-400"></i>
                    Détail du billetage
                </h3>
                <button onclick="closeModal()" class="text-slate-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="modalContent" class="p-6">
                <!-- Le contenu sera chargé dynamiquement -->
            </div>
        </div>
    </div>

    <script>
        function voirDetail(id) {
            // Récupérer les données du billetage
            fetch('get_billetage.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        afficherDetail(data.billetage);
                    }
                });
        }

        function afficherDetail(b) {
            const html = `
                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-slate-400">Date:</span>
                            <span class="text-white ml-2 font-semibold">${new Date(b.date_billetage).toLocaleDateString('fr-FR')}</span>
                        </div>
                        <div>
                            <span class="text-slate-400">Compte:</span>
                            <span class="text-white ml-2 font-semibold">${b.compte_caisse}</span>
                        </div>
                        <div>
                            <span class="text-slate-400">Enregistré par:</span>
                            <span class="text-white ml-2">${b.createur}</span>
                        </div>
                        <div>
                            <span class="text-slate-400">Le:</span>
                            <span class="text-white ml-2">${new Date(b.date_creation).toLocaleString('fr-FR')}</span>
                        </div>
                    </div>

                    <div class="border-t border-slate-700 pt-4">
                        <h4 class="text-lg font-semibold text-green-400 mb-3">
                            <i class="fas fa-money-bill-wave mr-2"></i>Billets
                        </h4>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-slate-700">
                                ${b.bill_10000 > 0 ? `<tr><td class="py-2 text-slate-300">10 000 F × ${b.bill_10000}</td><td class="py-2 text-right text-green-400 font-mono">${(10000 * b.bill_10000).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.bill_5000 > 0 ? `<tr><td class="py-2 text-slate-300">5 000 F × ${b.bill_5000}</td><td class="py-2 text-right text-green-400 font-mono">${(5000 * b.bill_5000).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.bill_2000 > 0 ? `<tr><td class="py-2 text-slate-300">2 000 F × ${b.bill_2000}</td><td class="py-2 text-right text-green-400 font-mono">${(2000 * b.bill_2000).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.bill_1000 > 0 ? `<tr><td class="py-2 text-slate-300">1 000 F × ${b.bill_1000}</td><td class="py-2 text-right text-green-400 font-mono">${(1000 * b.bill_1000).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.bill_500 > 0 ? `<tr><td class="py-2 text-slate-300">500 F × ${b.bill_500}</td><td class="py-2 text-right text-green-400 font-mono">${(500 * b.bill_500).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                <tr class="font-bold border-t-2 border-slate-600">
                                    <td class="py-2 text-white">TOTAL BILLETS</td>
                                    <td class="py-2 text-right text-green-400 font-mono">${parseFloat(b.total_billets).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} F</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-700 pt-4">
                        <h4 class="text-lg font-semibold text-yellow-400 mb-3">
                            <i class="fas fa-coins mr-2"></i>Pièces
                        </h4>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-slate-700">
                                ${b.coin_500 > 0 ? `<tr><td class="py-2 text-slate-300">500 F × ${b.coin_500}</td><td class="py-2 text-right text-yellow-400 font-mono">${(500 * b.coin_500).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_250 > 0 ? `<tr><td class="py-2 text-slate-300">250 F × ${b.coin_250}</td><td class="py-2 text-right text-yellow-400 font-mono">${(250 * b.coin_250).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_200 > 0 ? `<tr><td class="py-2 text-slate-300">200 F × ${b.coin_200}</td><td class="py-2 text-right text-yellow-400 font-mono">${(200 * b.coin_200).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_100 > 0 ? `<tr><td class="py-2 text-slate-300">100 F × ${b.coin_100}</td><td class="py-2 text-right text-yellow-400 font-mono">${(100 * b.coin_100).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_50 > 0 ? `<tr><td class="py-2 text-slate-300">50 F × ${b.coin_50}</td><td class="py-2 text-right text-yellow-400 font-mono">${(50 * b.coin_50).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_25 > 0 ? `<tr><td class="py-2 text-slate-300">25 F × ${b.coin_25}</td><td class="py-2 text-right text-yellow-400 font-mono">${(25 * b.coin_25).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_10 > 0 ? `<tr><td class="py-2 text-slate-300">10 F × ${b.coin_10}</td><td class="py-2 text-right text-yellow-400 font-mono">${(10 * b.coin_10).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                ${b.coin_5 > 0 ? `<tr><td class="py-2 text-slate-300">5 F × ${b.coin_5}</td><td class="py-2 text-right text-yellow-400 font-mono">${(5 * b.coin_5).toLocaleString('fr-FR')} F</td></tr>` : ''}
                                <tr class="font-bold border-t-2 border-slate-600">
                                    <td class="py-2 text-white">TOTAL PIÈCES</td>
                                    <td class="py-2 text-right text-yellow-400 font-mono">${parseFloat(b.total_pieces).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} F</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-700 pt-4">
                        <table class="w-full text-base">
                            <tbody class="divide-y divide-slate-700">
                                <tr class="font-bold">
                                    <td class="py-3 text-blue-400">SOLDE PHYSIQUE</td>
                                    <td class="py-3 text-right text-blue-400 font-mono text-lg">${parseFloat(b.solde_physique).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} F</td>
                                </tr>
                                <tr>
                                    <td class="py-3 text-slate-300">Solde Comptable</td>
                                    <td class="py-3 text-right text-slate-300 font-mono">${parseFloat(b.solde_comptable).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} F</td>
                                </tr>
                                <tr class="font-bold border-t-2 border-slate-600">
                                    <td class="py-3 text-white">ÉCART</td>
                                    <td class="py-3 text-right font-mono text-lg ${parseFloat(b.ecart) === 0 ? 'text-green-400' : (parseFloat(b.ecart) > 0 ? 'text-orange-400' : 'text-red-400')}">
                                        ${parseFloat(b.ecart) > 0 ? '+' : ''}${parseFloat(b.ecart).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} F
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    ${parseFloat(b.ecart) !== 0 ? `
                        <div class="bg-orange-900/20 border border-orange-700/50 rounded-lg p-4">
                            <p class="text-orange-300 text-sm">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                ${parseFloat(b.ecart) > 0 ? 'Excédent de caisse détecté' : 'Manque de caisse détecté'}
                            </p>
                        </div>
                    ` : `
                        <div class="bg-green-900/20 border border-green-700/50 rounded-lg p-4">
                            <p class="text-green-300 text-sm">
                                <i class="fas fa-check-circle mr-2"></i>
                                Caisse conforme, aucun écart
                            </p>
                        </div>
                    `}
                </div>
            `;
            document.getElementById('modalContent').innerHTML = html;
            document.getElementById('modalDetail').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('modalDetail').classList.add('hidden');
        }
    </script>
</body>
</html>
