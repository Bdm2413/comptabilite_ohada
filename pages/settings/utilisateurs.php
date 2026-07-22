<?php
require_once '../../config/config.php';
requireLogin();

// Vérifier que l'utilisateur est admin
if (!hasRole('admin')) {
    header('Location: ../../pages/dashboard/index.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add':
                    // Vérifier que l'email n'existe pas déjà
                    $checkStmt = $db->prepare("SELECT id_utilisateur FROM utilisateurs WHERE email = ?");
                    $checkStmt->execute([cleanInput($_POST['email'])]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Un utilisateur avec cet email existe déjà");
                    }

                    // Valider le mot de passe
                    if (strlen($_POST['mot_de_passe']) < 8) {
                        throw new Exception("Le mot de passe doit contenir au moins 8 caractères");
                    }

                    $stmt = $db->prepare("INSERT INTO utilisateurs (nom_utilisateur, email, mot_de_passe, role, actif, date_creation) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        cleanInput($_POST['nom_utilisateur']),
                        cleanInput($_POST['email']),
                        hashPassword($_POST['mot_de_passe']),
                        $_POST['role'],
                        isset($_POST['actif']) ? 1 : 0
                    ]);
                    $message = 'Utilisateur ajouté avec succès';
                    $messageType = 'success';
                    logActivity('Ajout utilisateur', 'utilisateurs', $db->lastInsertId(), $_POST['nom_utilisateur']);
                    break;

                case 'edit':
                    $updateFields = [
                        cleanInput($_POST['nom_utilisateur']),
                        cleanInput($_POST['email']),
                        $_POST['role'],
                        isset($_POST['actif']) ? 1 : 0
                    ];

                    // Si un nouveau mot de passe est fourni
                    if (!empty($_POST['mot_de_passe'])) {
                        if (strlen($_POST['mot_de_passe']) < 8) {
                            throw new Exception("Le mot de passe doit contenir au moins 8 caractères");
                        }
                        $stmt = $db->prepare("UPDATE utilisateurs SET nom_utilisateur = ?, email = ?, mot_de_passe = ?, role = ?, actif = ? WHERE id_utilisateur = ?");
                        $updateFields[] = hashPassword($_POST['mot_de_passe']);
                    } else {
                        $stmt = $db->prepare("UPDATE utilisateurs SET nom_utilisateur = ?, email = ?, role = ?, actif = ? WHERE id_utilisateur = ?");
                    }

                    $updateFields[] = $_POST['id_utilisateur'];
                    $stmt->execute($updateFields);

                    $message = 'Utilisateur modifié avec succès';
                    $messageType = 'success';
                    logActivity('Modification utilisateur', 'utilisateurs', $_POST['id_utilisateur']);
                    break;

                case 'toggle':
                    $stmt = $db->prepare("UPDATE utilisateurs SET actif = IF(actif = 1, 0, 1) WHERE id_utilisateur = ?");
                    $stmt->execute([$_POST['id_utilisateur']]);
                    $message = 'Statut modifié avec succès';
                    $messageType = 'success';
                    logActivity('Toggle actif utilisateur', 'utilisateurs', $_POST['id_utilisateur']);
                    break;

                case 'delete':
                    // Empêcher la suppression du dernier admin
                    $checkAdmin = $db->query("SELECT COUNT(*) as count FROM utilisateurs WHERE role = 'admin' AND actif = 1")->fetch();
                    $userToDelete = $db->prepare("SELECT role FROM utilisateurs WHERE id_utilisateur = ?");
                    $userToDelete->execute([$_POST['id_utilisateur']]);
                    $user = $userToDelete->fetch();

                    if ($user['role'] === 'admin' && $checkAdmin['count'] <= 1) {
                        throw new Exception("Impossible de supprimer le dernier administrateur actif");
                    }

                    $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id_utilisateur = ?");
                    $stmt->execute([$_POST['id_utilisateur']]);
                    $message = 'Utilisateur supprimé avec succès';
                    $messageType = 'success';
                    logActivity('Suppression utilisateur', 'utilisateurs', $_POST['id_utilisateur']);
                    break;

                case 'change_password':
                    if (strlen($_POST['nouveau_mot_de_passe']) < 8) {
                        throw new Exception("Le mot de passe doit contenir au moins 8 caractères");
                    }

                    $stmt = $db->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id_utilisateur = ?");
                    $stmt->execute([
                        hashPassword($_POST['nouveau_mot_de_passe']),
                        $_POST['id_utilisateur']
                    ]);
                    $message = 'Mot de passe modifié avec succès';
                    $messageType = 'success';
                    logActivity('Changement mot de passe utilisateur', 'utilisateurs', $_POST['id_utilisateur']);
                    break;
            }

            // Redirection PRG (Post-Redirect-Get)
            $_SESSION['utilisateurs_message'] = $message;
            $_SESSION['utilisateurs_message_type'] = $messageType;
            header('Location: ' . $_SERVER['PHP_SELF'] . ($_GET ? '?' . http_build_query($_GET) : ''));
            exit;

        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
            $_SESSION['utilisateurs_message'] = $message;
            $_SESSION['utilisateurs_message_type'] = $messageType;
            header('Location: ' . $_SERVER['PHP_SELF'] . ($_GET ? '?' . http_build_query($_GET) : ''));
            exit;
        }
    }
}

// Récupérer les messages de la session
if (isset($_SESSION['utilisateurs_message'])) {
    $message = $_SESSION['utilisateurs_message'];
    $messageType = $_SESSION['utilisateurs_message_type'];
    unset($_SESSION['utilisateurs_message']);
    unset($_SESSION['utilisateurs_message_type']);
} else {
    $message = '';
    $messageType = '';
}

// Filtres
$roleFilter = $_GET['role'] ?? '';
$statutFilter = $_GET['statut'] ?? '';
$searchFilter = $_GET['search'] ?? '';

// Construire la requête avec les filtres
$where = [];
$params = [];

if ($roleFilter) {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}

if ($statutFilter !== '') {
    $where[] = "actif = ?";
    $params[] = $statutFilter;
}

if ($searchFilter) {
    $where[] = "(nom_utilisateur LIKE ? OR email LIKE ?)";
    $params[] = "%$searchFilter%";
    $params[] = "%$searchFilter%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Récupérer les utilisateurs
$sql = "SELECT * FROM utilisateurs $whereClause ORDER BY actif DESC, nom_utilisateur ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$utilisateurs = $stmt->fetchAll();

// Statistiques
$stats = [
    'total' => $db->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn(),
    'actifs' => $db->query("SELECT COUNT(*) FROM utilisateurs WHERE actif = 1")->fetchColumn(),
    'admins' => $db->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'")->fetchColumn(),
    'comptables' => $db->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'comptable'")->fetchColumn(),
    'consultants' => $db->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'consultant'")->fetchColumn()
];

$pageTitle = "Gestion des Utilisateurs";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                        <h1 class="text-xl font-semibold text-white">
                            <i class="fas fa-users mr-2"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-sm text-slate-400 mt-0.5">Gérez les utilisateurs et leurs accès au système</p>
                    </div>
                    <button onclick="openAddModal()"
                            class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-plus mr-2"></i>Nouvel utilisateur
                    </button>
                </div>
            </header>

            <!-- Content -->
            <div class="p-4">
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="mb-4 p-3 rounded-lg text-sm <?= $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/50 text-emerald-400' : 'bg-red-500/10 border border-red-500/50 text-red-400' ?>">
                        <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Statistiques -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <div class="text-slate-400 text-xs mb-1">Total</div>
                        <div class="text-xl font-bold text-blue-400"><?= $stats['total'] ?></div>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <div class="text-slate-400 text-xs mb-1">Actifs</div>
                        <div class="text-xl font-bold text-green-400"><?= $stats['actifs'] ?></div>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <div class="text-slate-400 text-xs mb-1">Admins</div>
                        <div class="text-xl font-bold text-purple-400"><?= $stats['admins'] ?></div>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <div class="text-slate-400 text-xs mb-1">Comptables</div>
                        <div class="text-xl font-bold text-cyan-400"><?= $stats['comptables'] ?></div>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <div class="text-slate-400 text-xs mb-1">Consultants</div>
                        <div class="text-xl font-bold text-yellow-400"><?= $stats['consultants'] ?></div>
                    </div>
                </div>

                <!-- Filtres et actions -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4 mb-4">
                    <div class="flex flex-col md:flex-row gap-3">
                        <!-- Formulaire de recherche -->
                        <form method="GET" class="flex-1 flex flex-col md:flex-row gap-3">
                            <div class="flex-1">
                                <input type="text"
                                       name="search"
                                       value="<?= htmlspecialchars($searchFilter) ?>"
                                       placeholder="Rechercher par nom ou email..."
                                       class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <select name="role"
                                        class="w-full md:w-auto px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Tous les rôles</option>
                                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="comptable" <?= $roleFilter === 'comptable' ? 'selected' : '' ?>>Comptable</option>
                                    <option value="consultant" <?= $roleFilter === 'consultant' ? 'selected' : '' ?>>Consultant</option>
                                </select>
                            </div>
                            <div>
                                <select name="statut"
                                        class="w-full md:w-auto px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Tous les statuts</option>
                                    <option value="1" <?= $statutFilter === '1' ? 'selected' : '' ?>>Actifs</option>
                                    <option value="0" <?= $statutFilter === '0' ? 'selected' : '' ?>>Inactifs</option>
                                </select>
                            </div>
                            <button type="submit"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition-colors">
                                <i class="fas fa-search mr-2"></i>Filtrer
                            </button>
                            <?php if ($roleFilter || $statutFilter || $searchFilter): ?>
                                <a href="utilisateurs.php"
                                   class="px-4 py-2 bg-slate-600 hover:bg-slate-700 rounded-lg text-sm transition-colors text-center">
                                    <i class="fas fa-times mr-2"></i>Réinitialiser
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Liste des utilisateurs -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-900/50">
                                <tr class="border-b border-slate-700">
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase">Nom</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase">Email</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase">Rôle</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase">Statut</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase">Dernière connexion</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase">Date création</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-slate-400 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/50">
                                <?php if (empty($utilisateurs)): ?>
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                            <i class="fas fa-user-slash text-4xl mb-2"></i>
                                            <p>Aucun utilisateur trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($utilisateurs as $user): ?>
                                        <tr class="hover:bg-slate-700/20 transition-colors text-sm">
                                            <td class="px-3 py-2">
                                                <div class="flex items-center">
                                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold mr-2">
                                                        <?= strtoupper(substr($user['nom_utilisateur'], 0, 2)) ?>
                                                    </div>
                                                    <div class="font-medium text-white"><?= htmlspecialchars($user['nom_utilisateur']) ?></div>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-slate-300"><?= htmlspecialchars($user['email']) ?></td>
                                            <td class="px-3 py-2">
                                                <?php
                                                $roleColors = [
                                                    'admin' => 'bg-purple-500/20 text-purple-300 border-purple-500/50',
                                                    'comptable' => 'bg-cyan-500/20 text-cyan-300 border-cyan-500/50',
                                                    'consultant' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50'
                                                ];
                                                $roleIcons = [
                                                    'admin' => 'fa-user-shield',
                                                    'comptable' => 'fa-calculator',
                                                    'consultant' => 'fa-user-tie'
                                                ];
                                                ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border <?= $roleColors[$user['role']] ?>">
                                                    <i class="fas <?= $roleIcons[$user['role']] ?> mr-1"></i>
                                                    <?= ucfirst($user['role']) ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $user['actif'] ? 'bg-green-500/20 text-green-300 border border-green-500/50' : 'bg-red-500/20 text-red-300 border border-red-500/50' ?>">
                                                    <i class="fas fa-circle text-xs mr-1"></i>
                                                    <?= $user['actif'] ? 'Actif' : 'Inactif' ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-slate-400 text-xs">
                                                <?= $user['derniere_connexion'] ? date('d/m/Y H:i', strtotime($user['derniere_connexion'])) : 'Jamais' ?>
                                            </td>
                                            <td class="px-3 py-2 text-slate-400 text-xs">
                                                <?= date('d/m/Y', strtotime($user['date_creation'])) ?>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex items-center justify-center gap-1">
                                                    <button onclick='openEditModal(<?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>)'
                                                            class="p-1.5 bg-blue-600/20 hover:bg-blue-600/40 text-blue-400 rounded transition-colors text-xs"
                                                            title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick='openPasswordModal(<?= $user['id_utilisateur'] ?>, "<?= htmlspecialchars($user['nom_utilisateur']) ?>")'
                                                            class="p-1.5 bg-yellow-600/20 hover:bg-yellow-600/40 text-yellow-400 rounded transition-colors text-xs"
                                                            title="Changer le mot de passe">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Confirmer le changement de statut ?')">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="id_utilisateur" value="<?= $user['id_utilisateur'] ?>">
                                                        <button type="submit"
                                                                class="p-1.5 <?= $user['actif'] ? 'bg-orange-600/20 hover:bg-orange-600/40 text-orange-400' : 'bg-green-600/20 hover:bg-green-600/40 text-green-400' ?> rounded transition-colors text-xs"
                                                                title="<?= $user['actif'] ? 'Désactiver' : 'Activer' ?>">
                                                            <i class="fas fa-<?= $user['actif'] ? 'ban' : 'check' ?>"></i>
                                                        </button>
                                                    </form>
                                                    <button onclick='openActivityModal(<?= $user['id_utilisateur'] ?>, "<?= htmlspecialchars($user['nom_utilisateur']) ?>")'
                                                            class="p-1.5 bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 rounded transition-colors text-xs"
                                                            title="Voir les logs">
                                                        <i class="fas fa-history"></i>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id_utilisateur" value="<?= $user['id_utilisateur'] ?>">
                                                        <button type="submit"
                                                                class="p-1.5 bg-red-600/20 hover:bg-red-600/40 text-red-400 rounded transition-colors text-xs"
                                                                title="Supprimer">
                                                            <i class="fas fa-trash"></i>
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
            </div>
        </main>
    </div>

    <!-- Modal Ajouter -->
    <div id="addModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 rounded-lg border border-slate-700 w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-user-plus mr-2 text-green-400"></i>Nouvel utilisateur
                    </h2>
                    <button onclick="closeAddModal()" class="text-slate-400 hover:text-white transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add">

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Nom complet <span class="text-red-400">*</span>
                        </label>
                        <input type="text"
                               name="nom_utilisateur"
                               required
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Ex: Jean Dupont">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Email <span class="text-red-400">*</span>
                        </label>
                        <input type="email"
                               name="email"
                               required
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="exemple@email.com">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Mot de passe <span class="text-red-400">*</span>
                        </label>
                        <input type="password"
                               name="mot_de_passe"
                               required
                               minlength="8"
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Minimum 8 caractères">
                        <p class="text-xs text-slate-400 mt-1">Le mot de passe doit contenir au moins 8 caractères</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Rôle <span class="text-red-400">*</span>
                        </label>
                        <select name="role"
                                required
                                class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="consultant">Consultant (lecture seule)</option>
                            <option value="comptable" selected>Comptable (gestion complète)</option>
                            <option value="admin">Admin (administration système)</option>
                        </select>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox"
                               name="actif"
                               id="add_actif"
                               checked
                               class="w-4 h-4 bg-slate-900/50 border border-slate-600 rounded text-blue-600 focus:ring-2 focus:ring-blue-500">
                        <label for="add_actif" class="ml-2 text-sm text-slate-300">
                            Compte actif
                        </label>
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="button"
                                onclick="closeAddModal()"
                                class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Ajouter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifier -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 rounded-lg border border-slate-700 w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-edit mr-2 text-blue-400"></i>Modifier l'utilisateur
                    </h2>
                    <button onclick="closeEditModal()" class="text-slate-400 hover:text-white transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id_utilisateur" id="edit_id_utilisateur">

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Nom complet <span class="text-red-400">*</span>
                        </label>
                        <input type="text"
                               name="nom_utilisateur"
                               id="edit_nom_utilisateur"
                               required
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Email <span class="text-red-400">*</span>
                        </label>
                        <input type="email"
                               name="email"
                               id="edit_email"
                               required
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Nouveau mot de passe
                        </label>
                        <input type="password"
                               name="mot_de_passe"
                               id="edit_mot_de_passe"
                               minlength="8"
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Laisser vide pour ne pas changer">
                        <p class="text-xs text-slate-400 mt-1">Laisser vide pour conserver le mot de passe actuel</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Rôle <span class="text-red-400">*</span>
                        </label>
                        <select name="role"
                                id="edit_role"
                                required
                                class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="consultant">Consultant (lecture seule)</option>
                            <option value="comptable">Comptable (gestion complète)</option>
                            <option value="admin">Admin (administration système)</option>
                        </select>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox"
                               name="actif"
                               id="edit_actif"
                               class="w-4 h-4 bg-slate-900/50 border border-slate-600 rounded text-blue-600 focus:ring-2 focus:ring-blue-500">
                        <label for="edit_actif" class="ml-2 text-sm text-slate-300">
                            Compte actif
                        </label>
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="button"
                                onclick="closeEditModal()"
                                class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Changement de mot de passe -->
    <div id="passwordModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 rounded-lg border border-slate-700 w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-key mr-2 text-yellow-400"></i>Changer le mot de passe
                    </h2>
                    <button onclick="closePasswordModal()" class="text-slate-400 hover:text-white transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="id_utilisateur" id="password_id_utilisateur">

                    <div class="bg-slate-900/50 border border-slate-700 rounded-lg p-3 mb-4">
                        <p class="text-sm text-slate-300">
                            Utilisateur: <span class="font-semibold text-white" id="password_nom_utilisateur"></span>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Nouveau mot de passe <span class="text-red-400">*</span>
                        </label>
                        <input type="password"
                               name="nouveau_mot_de_passe"
                               required
                               minlength="8"
                               class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Minimum 8 caractères">
                        <p class="text-xs text-slate-400 mt-1">Le mot de passe doit contenir au moins 8 caractères</p>
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="button"
                                onclick="closePasswordModal()"
                                class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-yellow-600 hover:bg-yellow-700 rounded-lg transition-colors">
                            <i class="fas fa-key mr-2"></i>Changer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Logs d'activité -->
    <div id="activityModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 rounded-lg border border-slate-700 w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="p-6 border-b border-slate-700">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-history mr-2 text-indigo-400"></i>Logs d'activité
                    </h2>
                    <button onclick="closeActivityModal()" class="text-slate-400 hover:text-white transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-slate-400 mt-2">
                    Utilisateur: <span class="font-semibold text-white" id="activity_nom_utilisateur"></span>
                </p>
            </div>

            <div class="flex-1 overflow-y-auto p-6">
                <div id="activity_logs" class="space-y-3">
                    <div class="text-center text-slate-400 py-8">
                        <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                        <p>Chargement des logs...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Modals
        function openAddModal() {
            document.getElementById('addModal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
        }

        function openEditModal(user) {
            document.getElementById('edit_id_utilisateur').value = user.id_utilisateur;
            document.getElementById('edit_nom_utilisateur').value = user.nom_utilisateur;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_actif').checked = user.actif == 1;
            document.getElementById('edit_mot_de_passe').value = '';
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function openPasswordModal(userId, userName) {
            document.getElementById('password_id_utilisateur').value = userId;
            document.getElementById('password_nom_utilisateur').textContent = userName;
            document.getElementById('passwordModal').classList.remove('hidden');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
        }

        function openActivityModal(userId, userName) {
            document.getElementById('activity_nom_utilisateur').textContent = userName;
            document.getElementById('activityModal').classList.remove('hidden');

            // Charger les logs via AJAX
            fetch(`../../api/get_user_logs.php?id_utilisateur=${userId}`)
                .then(response => response.json())
                .then(data => {
                    const logsContainer = document.getElementById('activity_logs');

                    if (data.success && data.logs.length > 0) {
                        logsContainer.innerHTML = data.logs.map(log => `
                            <div class="bg-slate-900/50 border border-slate-700 rounded-lg p-4">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="px-2 py-1 rounded text-xs font-medium bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                                ${log.action}
                                            </span>
                                            ${log.table_affectee ? `<span class="text-xs text-slate-400">${log.table_affectee}</span>` : ''}
                                        </div>
                                        ${log.details ? `<p class="text-sm text-slate-300 mb-2">${log.details}</p>` : ''}
                                        <div class="flex items-center gap-4 text-xs text-slate-500">
                                            <span><i class="fas fa-calendar mr-1"></i>${log.date_action}</span>
                                            <span><i class="fas fa-network-wired mr-1"></i>${log.ip_address || 'N/A'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        logsContainer.innerHTML = `
                            <div class="text-center text-slate-400 py-8">
                                <i class="fas fa-inbox text-4xl mb-2"></i>
                                <p>Aucun log d'activité trouvé</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('activity_logs').innerHTML = `
                        <div class="text-center text-red-400 py-8">
                            <i class="fas fa-exclamation-circle text-4xl mb-2"></i>
                            <p>Erreur lors du chargement des logs</p>
                        </div>
                    `;
                });
        }

        function closeActivityModal() {
            document.getElementById('activityModal').classList.add('hidden');
        }

        // Fermer les modals avec Echap
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closePasswordModal();
                closeActivityModal();
            }
        });

        // Fermer les modals en cliquant à l'extérieur
        document.querySelectorAll('[id$="Modal"]').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>
