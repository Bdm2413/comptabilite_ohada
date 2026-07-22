<?php
/**
 * Liste des réceptions de bons de commande
 * Permet de visualiser et gérer les réceptions/factures liées aux BC
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

// Filtres
$bc_id = isset($_GET['bc_id']) ? (int)$_GET['bc_id'] : 0;
$fournisseur_id = isset($_GET['fournisseur_id']) ? (int)$_GET['fournisseur_id'] : 0;
$type_reception = isset($_GET['type_reception']) ? $_GET['type_reception'] : '';
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-12-31');

// Construire la requête
$sql = "
    SELECT r.*,
           bc.numero_bc,
           bc.objet as bc_objet,
           pt.nom as nom_fournisseur
    FROM receptions_bc r
    JOIN bons_commande bc ON r.id_bon_commande = bc.id AND bc.societe_id = ?
    JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
    WHERE r.date_reception BETWEEN ? AND ?
";
$params = [$societe_id, $date_debut, $date_fin];

if ($bc_id > 0) {
    $sql .= " AND r.id_bon_commande = ?";
    $params[] = $bc_id;
}

if ($fournisseur_id > 0) {
    $sql .= " AND bc.id_fournisseur = ?";
    $params[] = $fournisseur_id;
}

if (!empty($type_reception)) {
    $sql .= " AND r.type_reception = ?";
    $params[] = $type_reception;
}

$sql .= " ORDER BY r.date_reception DESC, r.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$receptions = $stmt->fetchAll();

// Récupérer les fournisseurs pour le filtre
$stmtF = $db->prepare("SELECT id, nom as nom_fournisseur FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtF->execute([$societe_id]);
$fournisseurs = $stmtF->fetchAll();

// Récupérer les BC pour le filtre
$stmtBcs = $db->prepare("SELECT id, numero_bc, objet FROM bons_commande WHERE statut IN ('Validé', 'En cours', 'Partiellement reçu') AND societe_id = ? ORDER BY numero_bc DESC");
$stmtBcs->execute([$societe_id]);
$bcs = $stmtBcs->fetchAll();

// Statistiques
$stmtStats = $db->prepare("
    SELECT
        COUNT(*) as total_receptions,
        COUNT(CASE WHEN r.type_reception = 'Livraison' THEN 1 END) as nb_livraisons,
        COUNT(CASE WHEN r.type_reception = 'Facture' THEN 1 END) as nb_factures,
        COALESCE(SUM(r.montant_total_ht), 0) as total_ht,
        COALESCE(SUM(r.net_a_payer), 0) as total_net
    FROM receptions_bc r
    JOIN bons_commande bc ON r.id_bon_commande = bc.id AND bc.societe_id = ?
    WHERE r.date_reception BETWEEN ? AND ?
");
$stmtStats->execute([$societe_id, $date_debut, $date_fin]);
$stats = $stmtStats->fetch();

$page_title = "Réceptions BC";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Comptabilité OHADA</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); min-height: 100vh; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(71, 85, 105, 0.5); }
    </style>
</head>
<body class="text-slate-200">
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-white">
                    <i class="fas fa-truck-loading text-cyan-400 mr-3"></i>
                    Réceptions BC
                </h1>
                <p class="text-slate-400 mt-1">Gestion des livraisons et factures fournisseurs</p>
            </div>
            <a href="reception_form.php" class="bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl font-semibold flex items-center gap-2 transition-all shadow-lg">
                <i class="fas fa-plus"></i>
                Nouvelle réception
            </a>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
            <div class="glass rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Total réceptions</div>
                <div class="text-2xl font-bold text-white"><?= $stats['total_receptions'] ?></div>
            </div>
            <div class="glass rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Livraisons</div>
                <div class="text-2xl font-bold text-green-400"><?= $stats['nb_livraisons'] ?></div>
            </div>
            <div class="glass rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Factures</div>
                <div class="text-2xl font-bold text-blue-400"><?= $stats['nb_factures'] ?></div>
            </div>
            <div class="glass rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Total HT</div>
                <div class="text-xl font-bold text-cyan-400"><?= safe_number_format($stats['total_ht']) ?></div>
            </div>
            <div class="glass rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Total Net</div>
                <div class="text-xl font-bold text-amber-400"><?= safe_number_format($stats['total_net']) ?></div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="glass rounded-xl p-6 mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div>
                    <label class="block text-slate-400 text-sm mb-1">Bon de commande</label>
                    <select name="bc_id" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white">
                        <option value="">Tous les BC</option>
                        <?php foreach ($bcs as $bc): ?>
                            <option value="<?= $bc['id'] ?>" <?= $bc_id == $bc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bc['numero_bc']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 text-sm mb-1">Fournisseur</label>
                    <select name="fournisseur_id" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white">
                        <option value="">Tous</option>
                        <?php foreach ($fournisseurs as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $fournisseur_id == $f['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['nom_fournisseur']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 text-sm mb-1">Type</label>
                    <select name="type_reception" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white">
                        <option value="">Tous</option>
                        <option value="Livraison" <?= $type_reception == 'Livraison' ? 'selected' : '' ?>>Livraison</option>
                        <option value="Facture" <?= $type_reception == 'Facture' ? 'selected' : '' ?>>Facture</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 text-sm mb-1">Date début</label>
                    <input type="date" name="date_debut" value="<?= $date_debut ?>" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 text-sm mb-1">Date fin</label>
                    <input type="date" name="date_fin" value="<?= $date_fin ?>" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="bg-cyan-600 hover:bg-cyan-700 text-white px-4 py-2 rounded-lg flex-1">
                        <i class="fas fa-filter mr-2"></i>Filtrer
                    </button>
                    <a href="receptions.php" class="bg-slate-600 hover:bg-slate-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste des réceptions -->
        <div class="glass rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-900/50 border-b border-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">N° Réception</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">N° BC</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">Fournisseur</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase">N° Document</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-slate-400 uppercase">Montant HT</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-slate-400 uppercase">Net à payer</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-slate-400 uppercase">Comptabilisé</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-slate-400 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                        <?php if (empty($receptions)): ?>
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-slate-400">
                                    <i class="fas fa-inbox text-4xl mb-4 opacity-50"></i>
                                    <p>Aucune réception trouvée</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($receptions as $r): ?>
                                <tr class="hover:bg-slate-700/20 transition-colors">
                                    <td class="px-4 py-3">
                                        <span class="font-semibold text-white"><?= htmlspecialchars($r['numero_reception']) ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="bc_form.php?id=<?= $r['id_bon_commande'] ?>" class="text-cyan-400 hover:underline">
                                            <?= htmlspecialchars($r['numero_bc']) ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($r['nom_fournisseur']) ?></td>
                                    <td class="px-4 py-3">
                                        <?php if ($r['type_reception'] == 'Livraison'): ?>
                                            <span class="px-2 py-1 rounded-full text-xs bg-green-500/20 text-green-400">
                                                <i class="fas fa-truck mr-1"></i>Livraison
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded-full text-xs bg-blue-500/20 text-blue-400">
                                                <i class="fas fa-file-invoice mr-1"></i>Facture
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-300"><?= date('d/m/Y', strtotime($r['date_reception'])) ?></td>
                                    <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($r['numero_document'] ?? '-') ?></td>
                                    <td class="px-4 py-3 text-right text-cyan-400 font-medium"><?= safe_number_format($r['montant_total_ht']) ?></td>
                                    <td class="px-4 py-3 text-right text-amber-400 font-medium"><?= safe_number_format($r['net_a_payer']) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($r['id_ecriture']): ?>
                                            <a href="../ecritures/voir.php?id=<?= $r['id_ecriture'] ?>" class="text-green-400 hover:text-green-300">
                                                <i class="fas fa-check-circle"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-500"><i class="fas fa-times-circle"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex justify-center gap-2">
                                            <a href="reception_form.php?id=<?= $r['id'] ?>" class="text-cyan-400 hover:text-cyan-300" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if (!$r['id_ecriture']): ?>
                                                <button onclick="comptabiliser(<?= $r['id'] ?>)" class="text-green-400 hover:text-green-300" title="Comptabiliser">
                                                    <i class="fas fa-calculator"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button onclick="supprimer(<?= $r['id'] ?>)" class="text-red-400 hover:text-red-300" title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function supprimer(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette réception ?')) {
                fetch('reception_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'supprimer', id: id})
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Erreur lors de la suppression');
                    }
                });
            }
        }

        function comptabiliser(id) {
            if (confirm('Voulez-vous comptabiliser cette réception ?')) {
                fetch('reception_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'comptabiliser', id: id})
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Erreur lors de la comptabilisation');
                    }
                });
            }
        }
    </script>
</body>
</html>
