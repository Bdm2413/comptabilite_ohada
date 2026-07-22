<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

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
                    // Auto-génération des champs
                    $compte = (int)cleanInput($_POST['compte']);
                    $classe = (int)substr($compte, 0, 1);
                    $quatreChiffres = (int)substr($compte, 0, 4);

                    // Récupérer les données de table_correspondance
                    $stmt = $db->prepare("SELECT * FROM table_correspondance WHERE compte = ?");
                    $stmt->execute([$quatreChiffres]);
                    $correspondance = $stmt->fetch();

                    if (!$correspondance) {
                        throw new Exception("Le code comptable '{$quatreChiffres}' n'existe pas dans la table de correspondance. Veuillez d'abord créer ce code ou vérifier le numéro de compte saisi.");
                    }

                    // Insérer avec les données héritées de table_correspondance
                    $stmt = $db->prepare("INSERT INTO plan_comptable (societe_id, compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $societe_id,
                        $compte,
                        cleanInput($_POST['intitule_compte']),
                        $classe,
                        $quatreChiffres,
                        $correspondance['tableau'],
                        $_POST['type'],
                        $correspondance['bd'],
                        $correspondance['bc'],
                        $correspondance['rd'],
                        $correspondance['rc'],
                        'Oui'
                    ]);

                    $message = 'Compte ajouté avec succès';
                    $messageType = 'success';
                    logActivity('Ajout plan comptable', 'plan_comptable', $db->lastInsertId(), $compte);
                    break;

                case 'edit':
                    // Pour l'édition, on peut modifier intitule_compte, type et actif
                    // Si le compte change, on doit recalculer classe et quatre_chiffres
                    if (isset($_POST['compte'])) {
                        $compte = (int)cleanInput($_POST['compte']);
                        $classe = (int)substr($compte, 0, 1);
                        $quatreChiffres = (int)substr($compte, 0, 4);

                        // Récupérer les données de table_correspondance
                        $stmt = $db->prepare("SELECT * FROM table_correspondance WHERE compte = ?");
                        $stmt->execute([$quatreChiffres]);
                        $correspondance = $stmt->fetch();

                        if (!$correspondance) {
                            throw new Exception("Le code comptable '{$quatreChiffres}' n'existe pas dans la table de correspondance.");
                        }

                        $stmt = $db->prepare("UPDATE plan_comptable SET compte = ?, intitule_compte = ?, classe = ?, quatre_chiffres = ?, tableau = ?, type = ?, bd = ?, bc = ?, rd = ?, rc = ? WHERE id = ? AND societe_id = ?");
                        $stmt->execute([
                            $compte,
                            cleanInput($_POST['intitule_compte']),
                            $classe,
                            $quatreChiffres,
                            $correspondance['tableau'],
                            $_POST['type'],
                            $correspondance['bd'],
                            $correspondance['bc'],
                            $correspondance['rd'],
                            $correspondance['rc'],
                            $_POST['id'],
                            $societe_id
                        ]);
                    } else {
                        // Si on ne change pas le compte, on met à jour seulement l'intitulé et le type
                        $stmt = $db->prepare("UPDATE plan_comptable SET intitule_compte = ?, type = ? WHERE id = ? AND societe_id = ?");
                        $stmt->execute([
                            cleanInput($_POST['intitule_compte']),
                            $_POST['type'],
                            $_POST['id'],
                            $societe_id
                        ]);
                    }

                    $message = 'Compte modifié avec succès';
                    $messageType = 'success';
                    logActivity('Modification plan comptable', 'plan_comptable', $_POST['id']);
                    break;

                case 'toggle':
                    $stmt = $db->prepare("UPDATE plan_comptable SET actif = IF(actif = 'Oui', 'Non', 'Oui') WHERE id = ? AND societe_id = ?");
                    $stmt->execute([$_POST['id'], $societe_id]);
                    $message = 'Statut modifié avec succès';
                    $messageType = 'success';
                    logActivity('Toggle actif plan comptable', 'plan_comptable', $_POST['id']);
                    break;

                case 'delete':
                    $stmt = $db->prepare("DELETE FROM plan_comptable WHERE id = ? AND societe_id = ?");
                    $stmt->execute([$_POST['id'], $societe_id]);
                    $message = 'Compte supprimé avec succès';
                    $messageType = 'success';
                    logActivity('Suppression plan comptable', 'plan_comptable', $_POST['id']);
                    break;
            }
        } catch (PDOException $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Filtres
$classeFilter = $_GET['classe'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20; // 20 comptes par page
$offset = ($page - 1) * $perPage;

// Compter le total de comptes (pour la pagination)
$countSql = "SELECT COUNT(*) FROM plan_comptable pc WHERE pc.societe_id = ?";
$countParams = [$societe_id];

if ($classeFilter) {
    $countSql .= " AND pc.classe = ?";
    $countParams[] = $classeFilter;
}

if ($typeFilter) {
    $countSql .= " AND pc.type = ?";
    $countParams[] = $typeFilter;
}

if ($searchFilter) {
    $countSql .= " AND (pc.compte LIKE ? OR pc.intitule_compte LIKE ?)";
    $countParams[] = '%' . $searchFilter . '%';
    $countParams[] = '%' . $searchFilter . '%';
}

$stmtCount = $db->prepare($countSql);
$stmtCount->execute($countParams);
$totalComptes = $stmtCount->fetchColumn();
$totalPages = ceil($totalComptes / $perPage);

// Récupérer les comptes avec pagination
$sql = "SELECT pc.*, tc.libelle as libelle_racine
        FROM plan_comptable pc
        LEFT JOIN table_correspondance tc ON pc.quatre_chiffres = tc.compte
        WHERE pc.societe_id = ?";
$params = [$societe_id];

if ($classeFilter) {
    $sql .= " AND pc.classe = ?";
    $params[] = $classeFilter;
}

if ($typeFilter) {
    $sql .= " AND pc.type = ?";
    $params[] = $typeFilter;
}

if ($searchFilter) {
    $sql .= " AND (pc.compte LIKE ? OR pc.intitule_compte LIKE ?)";
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
}

$sql .= " ORDER BY pc.compte LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comptes = $stmt->fetchAll();

// Types de comptes disponibles
$types = ['Client', 'Fournisseur', 'Salarié', 'Banque', 'Caisse', 'Amortis/Provision',
          'Résultat-Bilan', 'Charge', 'Produit', 'Résultat-Gestion', 'Immobilisation',
          'Capitaux', 'Stock', 'Titre', 'Etat', 'CNPS', 'Autres'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan Comptable - <?php echo APP_NAME; ?></title>
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-orange-400 to-red-600 mb-2">
                            <i class="fas fa-list-alt mr-3"></i>Plan Comptable
                        </h1>
                        <p class="text-sm text-slate-400 mt-0.5">Gestion détaillée du plan comptable SYSCOHADA</p>
                    </div>
                    <button onclick="openModal('add')" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded-lg transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Nouveau compte
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

                <!-- Info Box -->
                <div class="bg-blue-500/10 border border-blue-500/50 rounded-lg p-4 mb-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div class="text-sm text-blue-300">
                            <p class="font-semibold mb-1">Génération automatique des champs</p>
                            <ul class="list-disc list-inside space-y-0.5 text-xs text-blue-400">
                                <li>La <strong>classe</strong> est le 1er chiffre du compte (ex: 4111000 → classe 4)</li>
                                <li>Les <strong>4 premiers chiffres</strong> sont extraits automatiquement (ex: 4111000 → 4111)</li>
                                <li>Le <strong>tableau</strong> et les <strong>positions (BD, BC, RD, RC)</strong> sont hérités de la table de correspondance</li>
                                <li>Classes 1-5 : Bilan | Classes 6-8 : Résultat</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Filtres -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4 mb-4">
                    <form method="GET" class="flex flex-wrap gap-3">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Recherche</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchFilter); ?>"
                                   placeholder="Compte ou intitulé..."
                                   class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 w-64">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Classe</label>
                            <select name="classe" class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option value="">Toutes</option>
                                <?php for($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $classeFilter == $i ? 'selected' : ''; ?>>Classe <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Type</label>
                            <select name="type" class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option value="">Tous</option>
                                <?php foreach($types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm transition">
                                Filtrer
                            </button>
                            <?php if ($classeFilter || $typeFilter || $searchFilter): ?>
                                <a href="plan_comptable_new.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">
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
                                    <th class="p-3 font-medium">Compte</th>
                                    <th class="p-3 font-medium">Intitulé</th>
                                    <th class="p-3 font-medium">Type</th>
                                    <th class="p-3 font-medium">Tableau</th>
                                    <th class="p-3 font-medium">Positions</th>
                                    <th class="p-3 font-medium">Statut</th>
                                    <th class="p-3 font-medium text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php if (empty($comptes)): ?>
                                    <tr>
                                        <td colspan="7" class="p-8 text-center text-slate-500">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                            </svg>
                                            <p>Aucun compte trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($comptes as $compte): ?>
                                        <tr class="hover:bg-slate-700/20 transition">
                                            <td class="p-3">
                                                <div>
                                                    <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded font-mono font-semibold text-xs">
                                                        <?php echo htmlspecialchars($compte['compte']); ?>
                                                    </span>
                                                    <p class="text-xs text-slate-500 mt-1">
                                                        Classe <?php echo $compte['classe']; ?> →
                                                        <?php echo $compte['quatre_chiffres']; ?>
                                                    </p>
                                                </div>
                                            </td>
                                            <td class="p-3">
                                                <p class="text-white font-medium"><?php echo htmlspecialchars($compte['intitule_compte']); ?></p>
                                                <?php if ($compte['libelle_racine']): ?>
                                                    <p class="text-xs text-slate-500 mt-0.5">→ <?php echo htmlspecialchars($compte['libelle_racine']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-blue-500/10 text-blue-400 rounded text-xs">
                                                    <?php echo htmlspecialchars($compte['type']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 <?php echo $compte['tableau'] === 'Bilan' ? 'bg-purple-500/10 text-purple-400' : 'bg-amber-500/10 text-amber-400'; ?> rounded text-xs">
                                                    <?php echo htmlspecialchars($compte['tableau']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex gap-1 text-xs">
                                                    <?php if ($compte['bd']): ?>
                                                        <span class="px-1.5 py-0.5 bg-slate-700 rounded text-slate-300">BD:<?php echo $compte['bd']; ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($compte['bc']): ?>
                                                        <span class="px-1.5 py-0.5 bg-slate-700 rounded text-slate-300">BC:<?php echo $compte['bc']; ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($compte['rd']): ?>
                                                        <span class="px-1.5 py-0.5 bg-slate-700 rounded text-slate-300">RD:<?php echo $compte['rd']; ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($compte['rc']): ?>
                                                        <span class="px-1.5 py-0.5 bg-slate-700 rounded text-slate-300">RC:<?php echo $compte['rc']; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="p-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Changer le statut ?')">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?php echo $compte['id']; ?>">
                                                    <button type="submit" class="text-xs px-2 py-1 rounded <?php echo $compte['actif'] === 'Oui' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'; ?>">
                                                        <?php echo $compte['actif']; ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex items-center justify-end gap-2">
                                                    <button onclick='editCompte(<?php echo json_encode($compte); ?>)' class="p-1.5 text-blue-400 hover:bg-blue-500/10 rounded transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Supprimer ce compte ?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $compte['id']; ?>">
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
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-between mt-4 p-4 bg-slate-800/30 border border-slate-700/50 rounded-lg">
                        <div class="text-sm text-slate-400">
                            Page <?php echo $page; ?> sur <?php echo $totalPages; ?> (<?php echo $totalComptes; ?> comptes au total)
                        </div>
                        <div class="flex gap-2">
                            <?php
                            // Construire l'URL de base avec les filtres
                            $baseUrl = 'plan_comptable.php?';
                            $queryParams = [];
                            if ($classeFilter) $queryParams[] = 'classe=' . $classeFilter;
                            if ($typeFilter) $queryParams[] = 'type=' . urlencode($typeFilter);
                            if ($searchFilter) $queryParams[] = 'search=' . urlencode($searchFilter);
                            $baseUrl .= implode('&', $queryParams);
                            if (!empty($queryParams)) $baseUrl .= '&';
                            ?>

                            <!-- Première page -->
                            <?php if ($page > 1): ?>
                                <a href="<?php echo $baseUrl; ?>page=1" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                                    </svg>
                                </a>
                            <?php endif; ?>

                            <!-- Page précédente -->
                            <?php if ($page > 1): ?>
                                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition">
                                    Précédent
                                </a>
                            <?php endif; ?>

                            <!-- Pages numérotées -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>"
                                   class="px-3 py-1.5 rounded text-sm transition <?php echo $i === $page ? 'bg-emerald-500 text-white' : 'bg-slate-700 hover:bg-slate-600 text-white'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <!-- Page suivante -->
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition">
                                    Suivant
                                </a>
                            <?php endif; ?>

                            <!-- Dernière page -->
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Statistiques -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Total</p>
                        <p class="text-xl font-bold text-white mt-1"><?php echo $totalComptes; ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Page actuelle</p>
                        <p class="text-xl font-bold text-blue-400 mt-1"><?php echo count($comptes); ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Bilan</p>
                        <p class="text-xl font-bold text-purple-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['tableau'] === 'Bilan')); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Résultat</p>
                        <p class="text-xl font-bold text-amber-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['tableau'] === 'Résultat')); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Pages</p>
                        <p class="text-xl font-bold text-emerald-400 mt-1">
                            <?php echo $totalPages; ?>
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div id="modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl max-w-2xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <h3 id="modalTitle" class="text-lg font-semibold text-white mb-4">Nouveau compte</h3>
            <form id="compteForm" method="POST" class="space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Numéro de compte *</label>
                    <input type="text" name="compte" id="formCompte" required pattern="[0-9]+" maxlength="20"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm font-mono"
                           placeholder="Ex: 4111000, 6011000...">
                    <p class="text-xs text-slate-500 mt-1">
                        Saisissez le numéro complet. Les 4 premiers chiffres doivent exister dans la table de correspondance.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Intitulé *</label>
                    <textarea name="intitule_compte" id="formIntitule" required rows="2"
                              class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm resize-none"
                              placeholder="Ex: CLIENTS, FOURNISSEURS..."></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Type *</label>
                    <select name="type" id="formType" required
                            class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                        <?php foreach($types as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                        <?php endforeach; ?>
                    </select>
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
        function openModal(action = 'add') {
            document.getElementById('modal').classList.remove('hidden');
            document.getElementById('formAction').value = action;
            document.getElementById('modalTitle').textContent = action === 'add' ? 'Nouveau compte' : 'Modifier le compte';

            if (action === 'add') {
                document.getElementById('compteForm').reset();
                document.getElementById('formCompte').disabled = false;
            }
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        function editCompte(compte) {
            openModal('edit');
            document.getElementById('formId').value = compte.id;
            document.getElementById('formCompte').value = compte.compte;
            document.getElementById('formCompte').disabled = true; // Ne pas permettre de changer le numéro en édition
            document.getElementById('formIntitule').value = compte.intitule_compte;
            document.getElementById('formType').value = compte.type;
        }

        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Validation du compte en temps réel
        document.getElementById('formCompte').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
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
