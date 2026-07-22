<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

// Récupérer les fournisseurs pour le filtre
$stmtFournisseurs = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtFournisseurs->execute([$societe_id]);
$fournisseurs = $stmtFournisseurs->fetchAll();

// Traitement suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        try {
            // Vérifier si le devis n'a pas de BC associé
            $stmtCheck = $db->prepare("SELECT COUNT(*) as nb FROM bons_commande WHERE id_devis = ? AND societe_id = ?");
            $stmtCheck->execute([$_POST['id'], $societe_id]);
            if ($stmtCheck->fetch()['nb'] > 0) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Impossible de supprimer : ce devis a des bons de commande associés'];
            } else {
                $db->prepare("DELETE FROM devis_fournisseurs WHERE id = ? AND societe_id = ?")->execute([$_POST['id'], $societe_id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Devis supprimé avec succès'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
        header('Location: devis.php');
        exit;
    } elseif ($_POST['action'] === 'change_status') {
        try {
            $stmt = $db->prepare("UPDATE devis_fournisseurs SET statut = ?, date_decision = ?, decide_par = ?, motif_rejet = ? WHERE id = ? AND societe_id = ?");
            $stmt->execute([
                $_POST['statut'],
                date('Y-m-d'),
                $_POST['decide_par'] ?? null,
                $_POST['statut'] === 'Rejeté' ? ($_POST['motif_rejet'] ?? null) : null,
                $_POST['id'],
                $societe_id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Statut du devis mis à jour'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
        header('Location: devis.php');
        exit;
    }
}

// Filtres
$filterFournisseur = $_GET['fournisseur'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$filterDateDebut = $_GET['date_debut'] ?? '';
$filterDateFin = $_GET['date_fin'] ?? '';
$search = $_GET['search'] ?? '';

// Construire la requête
$sql = "
    SELECT d.*, pt.nom as fournisseur_nom,
           (SELECT COUNT(*) FROM lignes_devis WHERE id_devis = d.id) as nb_lignes,
           (SELECT COUNT(*) FROM bons_commande WHERE id_devis = d.id AND societe_id = d.societe_id) as nb_bc
    FROM devis_fournisseurs d
    JOIN plan_tiers pt ON d.id_fournisseur = pt.id
    WHERE d.societe_id = ?
";
$params = [$societe_id];

if (!empty($filterFournisseur)) {
    $sql .= " AND d.id_fournisseur = ?";
    $params[] = $filterFournisseur;
}
if (!empty($filterStatut)) {
    $sql .= " AND d.statut = ?";
    $params[] = $filterStatut;
}
if (!empty($filterDateDebut)) {
    $sql .= " AND d.date_devis >= ?";
    $params[] = $filterDateDebut;
}
if (!empty($filterDateFin)) {
    $sql .= " AND d.date_devis <= ?";
    $params[] = $filterDateFin;
}
if (!empty($search)) {
    $sql .= " AND (d.numero_devis LIKE ? OR d.objet LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY pt.nom, d.date_devis DESC, d.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$devisList = $stmt->fetchAll();

// Regrouper les devis par fournisseur
$devisParFournisseur = [];
foreach ($devisList as $devis) {
    $fournisseurId = $devis['id_fournisseur'];
    if (!isset($devisParFournisseur[$fournisseurId])) {
        $devisParFournisseur[$fournisseurId] = [
            'nom' => $devis['fournisseur_nom'],
            'devis' => [],
            'total' => 0
        ];
    }
    $devisParFournisseur[$fournisseurId]['devis'][] = $devis;
    $devisParFournisseur[$fournisseurId]['total'] += floatval($devis['montant_ttc'] ?? 0);
}

// Statistiques
$stats = [
    'total' => count($devisList),
    'en_attente' => count(array_filter($devisList, fn($d) => $d['statut'] === 'En attente')),
    'approuve' => count(array_filter($devisList, fn($d) => $d['statut'] === 'Approuvé')),
    'rejete' => count(array_filter($devisList, fn($d) => $d['statut'] === 'Rejeté'))
];

$pageTitle = "Devis Fournisseurs";
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
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-amber-400 to-orange-600 mb-2">
                            <i class="fas fa-file-invoice mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400">Gérez les devis reçus de vos fournisseurs</p>
                    </div>
                    <a href="devis_form.php" class="px-4 py-2 bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        Nouveau devis
                    </a>
                </div>
            </div>

            <!-- Message flash -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mb-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400' ?>">
                    <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
                    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Filtres -->
            <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4 mb-6">
                <form method="GET" class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Fournisseur</label>
                        <select name="fournisseur" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                            <option value="">Tous</option>
                            <?php foreach ($fournisseurs as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= $filterFournisseur == $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Statut</label>
                        <select name="statut" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                            <option value="">Tous</option>
                            <option value="En attente" <?= $filterStatut === 'En attente' ? 'selected' : '' ?>>En attente</option>
                            <option value="Approuvé" <?= $filterStatut === 'Approuvé' ? 'selected' : '' ?>>Approuvé</option>
                            <option value="Rejeté" <?= $filterStatut === 'Rejeté' ? 'selected' : '' ?>>Rejeté</option>
                            <option value="Expiré" <?= $filterStatut === 'Expiré' ? 'selected' : '' ?>>Expiré</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Du</label>
                        <input type="date" name="date_debut" value="<?= $filterDateDebut ?>" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Au</label>
                        <input type="date" name="date_fin" value="<?= $filterDateFin ?>" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Recherche</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="N° devis, objet..." class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="devis.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </form>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-slate-200"><?= $stats['total'] ?></div>
                    <div class="text-slate-400 text-sm">Total devis</div>
                </div>
                <div class="bg-slate-800/50 border border-amber-700/50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-amber-400"><?= $stats['en_attente'] ?></div>
                    <div class="text-slate-400 text-sm">En attente</div>
                </div>
                <div class="bg-slate-800/50 border border-green-700/50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-400"><?= $stats['approuve'] ?></div>
                    <div class="text-slate-400 text-sm">Approuvés</div>
                </div>
                <div class="bg-slate-800/50 border border-red-700/50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-red-400"><?= $stats['rejete'] ?></div>
                    <div class="text-slate-400 text-sm">Rejetés</div>
                </div>
            </div>

            <!-- Devis groupés par fournisseur -->
            <?php if (empty($devisList)): ?>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-8 text-center text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>Aucun devis trouvé</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($devisParFournisseur as $fournisseurId => $groupe): ?>
                        <div class="bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
                            <!-- En-tête fournisseur -->
                            <button onclick="toggleFournisseur(<?= $fournisseurId ?>)" class="w-full px-4 py-3 bg-slate-700/50 hover:bg-slate-700/70 flex items-center justify-between transition-colors">
                                <div class="flex items-center gap-3">
                                    <i id="icon-<?= $fournisseurId ?>" class="fas fa-chevron-down text-slate-400 transition-transform"></i>
                                    <span class="font-semibold text-slate-200"><?= htmlspecialchars($groupe['nom']) ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="px-3 py-1 bg-amber-500/20 text-amber-400 rounded-full text-sm font-medium">
                                        <?= count($groupe['devis']) ?> devis
                                    </span>
                                    <span class="text-slate-400 text-sm font-mono">
                                        Total: <?= safe_number_format($groupe['total']) ?> FCFA
                                    </span>
                                </div>
                            </button>

                            <!-- Table des devis du fournisseur -->
                            <div id="devis-<?= $fournisseurId ?>" class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-700/30">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">N° Devis</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">Date</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">Objet</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-slate-400 uppercase">Montant TTC</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Lignes</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Statut</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700">
                                        <?php foreach ($groupe['devis'] as $devis): ?>
                                            <tr class="hover:bg-slate-700/30 transition-colors">
                                                <td class="px-4 py-3">
                                                    <a href="devis_form.php?id=<?= $devis['id'] ?>" class="font-mono text-amber-400 hover:text-amber-300">
                                                        <?= htmlspecialchars($devis['numero_devis']) ?>
                                                    </a>
                                                </td>
                                                <td class="px-4 py-3 text-slate-400">
                                                    <?= date('d/m/Y', strtotime($devis['date_devis'])) ?>
                                                    <?php if ($devis['date_validite']): ?>
                                                        <div class="text-xs <?= strtotime($devis['date_validite']) < time() ? 'text-red-400' : 'text-slate-500' ?>">
                                                            Valide jusqu'au <?= date('d/m/Y', strtotime($devis['date_validite'])) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-200 max-w-xs truncate"><?= htmlspecialchars($devis['objet']) ?></td>
                                                <td class="px-4 py-3 text-right font-mono text-slate-200"><?= safe_number_format($devis['montant_ttc']) ?></td>
                                                <td class="px-4 py-3 text-center text-slate-400"><?= $devis['nb_lignes'] ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <?php
                                                    $statusColors = [
                                                        'En attente' => 'bg-amber-500/20 text-amber-400',
                                                        'Approuvé' => 'bg-green-500/20 text-green-400',
                                                        'Rejeté' => 'bg-red-500/20 text-red-400',
                                                        'Expiré' => 'bg-slate-500/20 text-slate-400'
                                                    ];
                                                    $color = $statusColors[$devis['statut']] ?? 'bg-slate-500/20 text-slate-400';
                                                    ?>
                                                    <span class="px-2 py-1 rounded-full text-xs <?= $color ?>">
                                                        <?= $devis['statut'] ?>
                                                    </span>
                                                    <?php if ($devis['nb_bc'] > 0): ?>
                                                        <span class="ml-1 px-2 py-1 rounded-full text-xs bg-blue-500/20 text-blue-400" title="<?= $devis['nb_bc'] ?> BC créé(s)">
                                                            <?= $devis['nb_bc'] ?> BC
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($devis['decide_par']) || !empty($devis['date_decision'])): ?>
                                                        <div class="text-xs text-slate-500 mt-1">
                                                            <?php if (!empty($devis['decide_par'])): ?>
                                                                par <?= htmlspecialchars($devis['decide_par']) ?>
                                                            <?php endif; ?>
                                                            <?php if (!empty($devis['date_decision'])): ?>
                                                                <br>le <?= date('d/m/Y', strtotime($devis['date_decision'])) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <div class="flex items-center justify-center gap-1">
                                                        <a href="devis_form.php?id=<?= $devis['id'] ?>" class="p-1.5 text-blue-400 hover:bg-blue-500/20 rounded" title="Voir/Modifier">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($devis['statut'] === 'En attente'): ?>
                                                            <button onclick="openStatusModal(<?= $devis['id'] ?>, 'Approuvé')" class="p-1.5 text-green-400 hover:bg-green-500/20 rounded" title="Approuver">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button onclick="openStatusModal(<?= $devis['id'] ?>, 'Rejeté')" class="p-1.5 text-red-400 hover:bg-red-500/20 rounded" title="Rejeter">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($devis['statut'] === 'Approuvé'): ?>
                                                            <a href="bc_form.php?from_devis=<?= $devis['id'] ?>" class="p-1.5 text-purple-400 hover:bg-purple-500/20 rounded" title="Créer BC">
                                                                <i class="fas fa-file-alt"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($devis['nb_bc'] == 0): ?>
                                                            <button onclick="confirmDelete(<?= $devis['id'] ?>, '<?= htmlspecialchars(addslashes($devis['numero_devis'])) ?>')" class="p-1.5 text-red-400 hover:bg-red-500/20 rounded" title="Supprimer">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal changement statut -->
    <div id="statusModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/70" onclick="closeStatusModal()"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4">
            <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md relative">
                <div class="p-6 border-b border-slate-700">
                    <h3 id="statusModalTitle" class="text-lg font-semibold text-white"></h3>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" id="statusDevisId">
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

    <!-- Form suppression -->
    <form id="deleteForm" method="POST" class="hidden">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script>
        function openStatusModal(id, statut) {
            document.getElementById('statusModal').classList.remove('hidden');
            document.getElementById('statusDevisId').value = id;
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

        function confirmDelete(id, numero) {
            if (confirm('Êtes-vous sûr de vouloir supprimer le devis "' + numero + '" ?')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeStatusModal(); });

        // Toggle fournisseur section
        function toggleFournisseur(id) {
            const content = document.getElementById('devis-' + id);
            const icon = document.getElementById('icon-' + id);
            if (content.style.display === 'none') {
                content.style.display = '';
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-down');
            } else {
                content.style.display = 'none';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-right');
            }
        }
    </script>
</body>
</html>
