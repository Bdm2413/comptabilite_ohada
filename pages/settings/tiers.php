<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add':
                    $sous_type_val = (in_array($_POST['type'], ['Client','Fournisseur']) && !empty($_POST['sous_type'])) ? $_POST['sous_type'] : null;
                    $stmt = $db->prepare("INSERT INTO plan_tiers (societe_id, compte_tiers, nom, abreviation, type, sous_type, regime_fiscal, adresse, telephone, email, compte_gle, matricule, ncc, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([
                        $societe_id,
                        cleanInput($_POST['compte_tiers']),
                        cleanInput($_POST['nom']),
                        !empty($_POST['abreviation']) ? cleanInput($_POST['abreviation']) : null,
                        $_POST['type'],
                        $sous_type_val,
                        !empty($_POST['regime_fiscal']) ? cleanInput($_POST['regime_fiscal']) : null,
                        !empty($_POST['adresse']) ? cleanInput($_POST['adresse']) : null,
                        !empty($_POST['telephone']) ? cleanInput($_POST['telephone']) : null,
                        !empty($_POST['email']) ? cleanInput($_POST['email']) : null,
                        !empty($_POST['compte_gle']) ? cleanInput($_POST['compte_gle']) : null,
                        !empty($_POST['matricule']) ? cleanInput($_POST['matricule']) : null,
                        !empty($_POST['ncc']) ? cleanInput($_POST['ncc']) : null
                    ]);
                    $message = 'Tiers ajouté avec succès';
                    $messageType = 'success';
                    logActivity('Ajout tiers', 'plan_tiers', $db->lastInsertId(), $_POST['nom']);
                    break;

                case 'edit':
                    // Note: compte_tiers n'est pas modifiable lors de l'édition (c'est la clé)
                    $sous_type_val = (in_array($_POST['type'], ['Client','Fournisseur']) && !empty($_POST['sous_type'])) ? $_POST['sous_type'] : null;
                    $stmt = $db->prepare("UPDATE plan_tiers SET nom = ?, abreviation = ?, type = ?, sous_type = ?, regime_fiscal = ?, adresse = ?, telephone = ?, email = ?, compte_gle = ?, matricule = ?, ncc = ? WHERE id = ? AND societe_id = ?");
                    $stmt->execute([
                        cleanInput($_POST['nom']),
                        !empty($_POST['abreviation']) ? cleanInput($_POST['abreviation']) : null,
                        $_POST['type'],
                        $sous_type_val,
                        !empty($_POST['regime_fiscal']) ? cleanInput($_POST['regime_fiscal']) : null,
                        !empty($_POST['adresse']) ? cleanInput($_POST['adresse']) : null,
                        !empty($_POST['telephone']) ? cleanInput($_POST['telephone']) : null,
                        !empty($_POST['email']) ? cleanInput($_POST['email']) : null,
                        !empty($_POST['compte_gle']) ? cleanInput($_POST['compte_gle']) : null,
                        !empty($_POST['matricule']) ? cleanInput($_POST['matricule']) : null,
                        !empty($_POST['ncc']) ? cleanInput($_POST['ncc']) : null,
                        $_POST['id'],
                        $societe_id
                    ]);
                    $message = 'Tiers modifié avec succès';
                    $messageType = 'success';
                    logActivity('Modification tiers', 'plan_tiers', $_POST['id']);
                    break;

                case 'toggle':
                    $stmt = $db->prepare("UPDATE plan_tiers SET actif = IF(actif = 1, 0, 1) WHERE id = ? AND societe_id = ?");
                    $stmt->execute([$_POST['id'], $societe_id]);
                    $message = 'Statut modifié avec succès';
                    $messageType = 'success';
                    logActivity('Toggle actif tiers', 'plan_tiers', $_POST['id']);
                    break;

                case 'delete':
                    $stmt = $db->prepare("DELETE FROM plan_tiers WHERE id = ? AND societe_id = ?");
                    $stmt->execute([$_POST['id'], $societe_id]);
                    $message = 'Tiers supprimé avec succès';
                    $messageType = 'success';
                    logActivity('Suppression tiers', 'plan_tiers', $_POST['id']);
                    break;
            }

            // Redirection PRG (Post-Redirect-Get) pour éviter la resoumission du formulaire
            $_SESSION['tiers_message'] = $message;
            $_SESSION['tiers_message_type'] = $messageType;
            header('Location: ' . $_SERVER['PHP_SELF'] . ($_GET ? '?' . http_build_query($_GET) : ''));
            exit;

        } catch (PDOException $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
            $_SESSION['tiers_message'] = $message;
            $_SESSION['tiers_message_type'] = $messageType;
            header('Location: ' . $_SERVER['PHP_SELF'] . ($_GET ? '?' . http_build_query($_GET) : ''));
            exit;
        }
    }
}

// Récupérer les messages de la session (après redirection)
if (isset($_SESSION['tiers_message'])) {
    $message = $_SESSION['tiers_message'];
    $messageType = $_SESSION['tiers_message_type'];
    unset($_SESSION['tiers_message']);
    unset($_SESSION['tiers_message_type']);
} else {
    $message = '';
    $messageType = '';
}

// Filtres
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Compter le total de tiers
$countSql = "SELECT COUNT(*) FROM plan_tiers t";
$countParams = [];
$whereConditions = ["t.societe_id = ?"];
$countParams[] = $societe_id;

if ($typeFilter) {
    $whereConditions[] = "t.type = ?";
    $countParams[] = $typeFilter;
}

if ($searchFilter) {
    $whereConditions[] = "(t.nom LIKE ? OR t.compte_tiers LIKE ? OR t.email LIKE ?)";
    $countParams[] = '%' . $searchFilter . '%';
    $countParams[] = '%' . $searchFilter . '%';
    $countParams[] = '%' . $searchFilter . '%';
}

if (!empty($whereConditions)) {
    $countSql .= " WHERE " . implode(" AND ", $whereConditions);
}

$stmtCount = $db->prepare($countSql);
$stmtCount->execute($countParams);
$totalTiers = $stmtCount->fetchColumn();
$totalPages = ceil($totalTiers / $perPage);

// Récupérer les tiers avec pagination
$sql = "SELECT t.id, t.compte_tiers, t.nom, t.abreviation, t.type, t.sous_type, t.regime_fiscal,
               t.adresse, t.telephone, t.email, t.compte_gle, t.matricule, t.ncc, t.actif,
               pc.intitule_compte as libelle_compte_gle
        FROM plan_tiers t
        LEFT JOIN plan_comptable pc ON t.compte_gle = pc.compte AND pc.societe_id = ?";
$params = [$societe_id];
$whereConditions = ["t.societe_id = ?"];
$params[] = $societe_id;

if ($typeFilter) {
    $whereConditions[] = "t.type = ?";
    $params[] = $typeFilter;
}

if ($searchFilter) {
    $whereConditions[] = "(t.nom LIKE ? OR t.compte_tiers LIKE ? OR t.email LIKE ?)";
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
}

if (!empty($whereConditions)) {
    $sql .= " WHERE " . implode(" AND ", $whereConditions);
}

$sql .= " ORDER BY t.type, t.nom LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tiers = $stmt->fetchAll();

// Récupérer les comptes généraux pour le select (comptes collectifs uniquement)
$stmtGle = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE actif = 'Oui' AND societe_id = ? ORDER BY compte");
$stmtGle->execute([$societe_id]);
$comptesGle = $stmtGle->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Tiers - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                        <h1 class="text-xl font-semibold text-white">Gestion des Tiers</h1>
                        <p class="text-sm text-slate-400 mt-0.5">Comptes auxiliaires : Clients, Fournisseurs, Salariés, etc.</p>
                    </div>
                    <button onclick="openModal('add')" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded-lg transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Nouveau tiers
                    </button>
                </div>
            </header>

            <!-- Content -->
            <div class="p-4">
                <?php if ($message): ?>
                    <div class="mb-4 p-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/50 text-emerald-400' : 'bg-red-500/10 border border-red-500/50 text-red-400'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Filtres -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4 mb-4">
                    <form method="GET" class="flex flex-wrap gap-3">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Recherche</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchFilter); ?>"
                                   placeholder="Nom, compte, matricule, email..."
                                   class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 w-80">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Type</label>
                            <select name="type" class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option value="">Tous</option>
                                <option value="Client" <?php echo $typeFilter === 'Client' ? 'selected' : ''; ?>>Client</option>
                                <option value="Fournisseur" <?php echo $typeFilter === 'Fournisseur' ? 'selected' : ''; ?>>Fournisseur</option>
                                <option value="Salarié" <?php echo $typeFilter === 'Salarié' ? 'selected' : ''; ?>>Salarié</option>
                                <option value="Etat" <?php echo $typeFilter === 'Etat' ? 'selected' : ''; ?>>État</option>
                                <option value="CNPS" <?php echo $typeFilter === 'CNPS' ? 'selected' : ''; ?>>CNPS</option>
                                <option value="Autre" <?php echo $typeFilter === 'Autre' ? 'selected' : ''; ?>>Autre</option>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm transition">
                                Filtrer
                            </button>
                            <?php if ($typeFilter || $searchFilter): ?>
                                <a href="tiers.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">
                                    Réinitialiser
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-700/50">
                                <tr class="text-left text-slate-300 text-xs">
                                    <th class="p-3 font-medium">Compte auxiliaire</th>
                                    <th class="p-3 font-medium">Nom / Raison sociale</th>
                                    <th class="p-3 font-medium">Type</th>
                                    <th class="p-3 font-medium">Compte général</th>
                                    <th class="p-3 font-medium">Contact</th>
                                    <th class="p-3 font-medium">Statut</th>
                                    <th class="p-3 font-medium text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php if (empty($tiers)): ?>
                                    <tr>
                                        <td colspan="7" class="p-8 text-center text-slate-500">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                            <p>Aucun tiers trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tiers as $tier): ?>
                                        <tr class="hover:bg-slate-700/20 transition">
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-purple-500/10 text-purple-400 rounded font-mono font-semibold text-xs">
                                                    <?php echo htmlspecialchars($tier['compte_tiers']); ?>
                                                </span>
                                                <?php if (!empty($tier['matricule'])): ?>
                                                    <p class="text-xs text-slate-500 mt-1">Mat: <?php echo htmlspecialchars($tier['matricule']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <p class="text-white font-medium"><?php echo htmlspecialchars($tier['nom']); ?></p>
                                                <?php if (!empty($tier['abreviation'])): ?>
                                                    <p class="text-xs text-blue-400 mt-0.5">
                                                        <i class="fas fa-tag text-xs"></i> <?php echo htmlspecialchars($tier['abreviation']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (!empty($tier['ncc'])): ?>
                                                    <p class="text-xs text-slate-500 mt-0.5">NCC: <?php echo htmlspecialchars($tier['ncc']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <?php
                                                $typeColors = [
                                                    'Client' => 'bg-blue-500/10 text-blue-400',
                                                    'Fournisseur' => 'bg-orange-500/10 text-orange-400',
                                                    'Salarié' => 'bg-green-500/10 text-green-400',
                                                    'Etat' => 'bg-red-500/10 text-red-400',
                                                    'CNPS' => 'bg-purple-500/10 text-purple-400',
                                                    'Autre' => 'bg-slate-500/10 text-slate-400'
                                                ];
                                                $colorClass = $typeColors[$tier['type']] ?? 'bg-slate-500/10 text-slate-400';
                                                ?>
                                                <span class="px-2 py-1 <?php echo $colorClass; ?> rounded text-xs">
                                                    <?php echo htmlspecialchars($tier['type']); ?>
                                                </span>
                                                <?php if (!empty($tier['sous_type'])): ?>
                                                    <span class="ml-1 px-2 py-1 <?php echo $tier['sous_type'] === 'Externe' ? 'bg-cyan-500/10 text-cyan-400' : 'bg-violet-500/10 text-violet-400'; ?> rounded text-xs">
                                                        <?php echo htmlspecialchars($tier['sous_type']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($tier['regime_fiscal'])): ?>
                                                    <p class="text-xs text-slate-400 mt-1">
                                                        <i class="fas fa-file-invoice text-xs"></i> <?php echo htmlspecialchars($tier['regime_fiscal']); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded font-mono font-semibold text-xs">
                                                    <?php echo htmlspecialchars($tier['compte_gle']); ?>
                                                </span>
                                                <?php if ($tier['libelle_compte_gle']): ?>
                                                    <p class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($tier['libelle_compte_gle']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <?php if ($tier['email']): ?>
                                                    <p class="text-xs text-slate-300">
                                                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                        </svg>
                                                        <?php echo htmlspecialchars($tier['email']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if ($tier['telephone']): ?>
                                                    <p class="text-xs text-slate-300 mt-1">
                                                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                                        </svg>
                                                        <?php echo htmlspecialchars($tier['telephone']); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Changer le statut ?')">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?php echo $tier['id']; ?>">
                                                    <button type="submit" class="text-xs px-2 py-1 rounded <?php echo $tier['actif'] == 1 ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'; ?>">
                                                        <?php echo $tier['actif'] == 1 ? 'Oui' : 'Non'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex items-center justify-end gap-2">
                                                    <button onclick='editTiers(<?php echo htmlspecialchars(json_encode($tier), ENT_QUOTES, 'UTF-8'); ?>)' class="p-1.5 text-blue-400 hover:bg-blue-500/10 rounded transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Supprimer ce tiers ?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $tier['id']; ?>">
                                                        <button type="submit" class="p-1.5 text-red-400 hover:bg-red-500/10 rounded transition">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-4 py-3 border-t border-slate-700/50 flex items-center justify-between">
                            <div class="text-sm text-slate-400">
                                Affichage de <?php echo $offset + 1; ?> à <?php echo min($offset + $perPage, $totalTiers); ?> sur <?php echo $totalTiers; ?> tiers
                            </div>
                            <div class="flex gap-1">
                                <?php
                                $queryParams = [];
                                if ($typeFilter) $queryParams['type'] = $typeFilter;
                                if ($searchFilter) $queryParams['search'] = $searchFilter;

                                // Bouton Première page
                                if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($queryParams, ['page' => 1])); ?>"
                                       class="px-3 py-1 bg-slate-700/50 hover:bg-slate-600 text-slate-300 rounded text-sm transition">
                                        Premier
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($queryParams, ['page' => $page - 1])); ?>"
                                       class="px-3 py-1 bg-slate-700/50 hover:bg-slate-600 text-slate-300 rounded text-sm transition">
                                        &laquo; Préc
                                    </a>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($queryParams, ['page' => $i])); ?>"
                                       class="px-3 py-1 <?php echo $i === $page ? 'bg-emerald-500 text-white' : 'bg-slate-700/50 hover:bg-slate-600 text-slate-300'; ?> rounded text-sm transition">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($queryParams, ['page' => $page + 1])); ?>"
                                       class="px-3 py-1 bg-slate-700/50 hover:bg-slate-600 text-slate-300 rounded text-sm transition">
                                        Suiv &raquo;
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($queryParams, ['page' => $totalPages])); ?>"
                                       class="px-3 py-1 bg-slate-700/50 hover:bg-slate-600 text-slate-300 rounded text-sm transition">
                                        Dernier
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Statistiques -->
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mt-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Total</p>
                        <p class="text-xl font-bold text-white mt-1"><?php echo $totalTiers; ?></p>
                        <p class="text-xs text-slate-500 mt-0.5"><?php echo count($tiers); ?> sur cette page</p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Clients</p>
                        <p class="text-xl font-bold text-blue-400 mt-1">
                            <?php
                            $stmtClients = $db->prepare("SELECT COUNT(*) FROM plan_tiers WHERE type = 'Client' AND societe_id = ?");
                            $stmtClients->execute([$societe_id]);
                            echo $stmtClients->fetchColumn();
                            ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Fournisseurs</p>
                        <p class="text-xl font-bold text-orange-400 mt-1">
                            <?php
                            $stmtFournisseurs = $db->prepare("SELECT COUNT(*) FROM plan_tiers WHERE type = 'Fournisseur' AND societe_id = ?");
                            $stmtFournisseurs->execute([$societe_id]);
                            echo $stmtFournisseurs->fetchColumn();
                            ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Salariés</p>
                        <p class="text-xl font-bold text-green-400 mt-1">
                            <?php
                            $stmtSalaries = $db->prepare("SELECT COUNT(*) FROM plan_tiers WHERE type = 'Salarié' AND societe_id = ?");
                            $stmtSalaries->execute([$societe_id]);
                            echo $stmtSalaries->fetchColumn();
                            ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Actifs</p>
                        <p class="text-xl font-bold text-emerald-400 mt-1">
                            <?php
                            $stmtActifs = $db->prepare("SELECT COUNT(*) FROM plan_tiers WHERE actif = 1 AND societe_id = ?");
                            $stmtActifs->execute([$societe_id]);
                            echo $stmtActifs->fetchColumn();
                            ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Pages</p>
                        <p class="text-xl font-bold text-purple-400 mt-1">
                            <?php echo $totalPages; ?>
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div id="modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl max-w-3xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <h3 id="modalTitle" class="text-lg font-semibold text-white mb-4">Nouveau tiers</h3>
            <form id="tiersForm" method="POST" class="space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Nom / Raison sociale *</label>
                        <input type="text" name="nom" id="formNom" required maxlength="255"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: SARL ENTREPRISE XYZ">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Abréviation</label>
                        <input type="text" name="abreviation" id="formAbreviation" maxlength="50"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: ENTR-XYZ">
                        <p class="text-xs text-slate-400 mt-1">Nom court pour faciliter l'identification</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Type *</label>
                        <select name="type" id="formType" required onchange="toggleSousType(this.value)"
                                class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                            <option value="Client">Client</option>
                            <option value="Fournisseur">Fournisseur</option>
                            <option value="Salarié">Salarié</option>
                            <option value="Etat">État</option>
                            <option value="CNPS">CNPS</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>

                    <div id="divSousType">
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Sous-type</label>
                        <select name="sous_type" id="formSousType"
                                class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                            <option value="">Non spécifié</option>
                            <option value="Externe">Externe</option>
                            <option value="Interne">Interne</option>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Pour clients et fournisseurs uniquement</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Régime fiscal</label>
                        <select name="regime_fiscal" id="formRegimeFiscal"
                                class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                            <option value="">Aucun</option>
                            <option value="RNI">RNI - Régime Normal d'Imposition</option>
                            <option value="RSI">RSI - Régime Simplifié d'Imposition</option>
                            <option value="RME">RME - Régime des Micro-Entreprises</option>
                            <option value="TEE">TEE - Taxe d'État de l'Entreprenant</option>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Régime d'imposition du tiers</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Compte général *</label>
                        <select name="compte_gle" id="formCompteGle" required
                                class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                            <option value="">Sélectionner...</option>
                            <?php foreach($comptesGle as $compte): ?>
                                <option value="<?php echo $compte['compte']; ?>"><?php echo $compte['compte']; ?> - <?php echo $compte['intitule_compte']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Compte auxiliaire *</label>
                        <input type="text" name="compte_tiers" id="formCompteTiers" required maxlength="20"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: 41100001">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Matricule</label>
                        <input type="text" name="matricule" id="formMatricule" maxlength="50"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: MAT-2025-001">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">NCC (Numéro compte contribuable)</label>
                        <input type="text" name="ncc" id="formNcc" maxlength="50"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: NCC123456">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
                        <input type="email" name="email" id="formEmail" maxlength="100"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="contact@exemple.com">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Téléphone</label>
                        <input type="tel" name="telephone" id="formTelephone" maxlength="20"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="+225 XX XX XX XX XX">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Adresse</label>
                    <textarea name="adresse" id="formAdresse" rows="2"
                              class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm resize-none"
                              placeholder="Adresse complète..."></textarea>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">
                        Annuler
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-sm transition">
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSousType(type) {
            const div = document.getElementById('divSousType');
            div.style.opacity = (type === 'Client' || type === 'Fournisseur') ? '1' : '0.4';
            div.querySelector('select').disabled = !(type === 'Client' || type === 'Fournisseur');
        }

        function openModal(action = 'add') {
            document.getElementById('modal').classList.remove('hidden');
            document.getElementById('formAction').value = action;
            document.getElementById('modalTitle').textContent = action === 'add' ? 'Nouveau tiers' : 'Modifier le tiers';

            if (action === 'add') {
                document.getElementById('tiersForm').reset();
                document.getElementById('formCompteTiers').disabled = false;
                toggleSousType(document.getElementById('formType').value);
            }
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        function editTiers(tiers) {
            openModal('edit');
            document.getElementById('formId').value = tiers.id;
            document.getElementById('formNom').value = tiers.nom;
            document.getElementById('formAbreviation').value = tiers.abreviation || '';
            document.getElementById('formType').value = tiers.type;
            document.getElementById('formSousType').value = tiers.sous_type || '';
            toggleSousType(tiers.type);
            document.getElementById('formRegimeFiscal').value = tiers.regime_fiscal || '';
            document.getElementById('formCompteGle').value = tiers.compte_gle;
            document.getElementById('formCompteTiers').value = tiers.compte_tiers;
            document.getElementById('formCompteTiers').disabled = true;
            document.getElementById('formMatricule').value = tiers.matricule || '';
            document.getElementById('formNcc').value = tiers.ncc || '';
            document.getElementById('formEmail').value = tiers.email || '';
            document.getElementById('formTelephone').value = tiers.telephone || '';
            document.getElementById('formAdresse').value = tiers.adresse || '';
        }

        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Animation au chargement
        anime({
            targets: 'tbody tr',
            translateX: [-20, 0],
            opacity: [0, 1],
            delay: anime.stagger(50),
            duration: 600,
            easing: 'easeOutQuad'
        });
    </script>
</body>
</html>
