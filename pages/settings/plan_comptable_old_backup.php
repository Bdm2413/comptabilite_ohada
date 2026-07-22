<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add':
                    $stmt = $db->prepare("INSERT INTO plan_comptable (numero_compte, intitule, classe, type_compte, collectif, auxiliaire, actif) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([
                        cleanInput($_POST['compte']),
                        cleanInput($_POST['intitule_compte']),
                        $_POST['classe'],
                        $_POST['type'],
                        isset($_POST['collectif']) ? 1 : 0,
                        isset($_POST['auxiliaire']) ? 1 : 0
                    ]);
                    $message = 'Compte ajouté avec succès';
                    $messageType = 'success';
                    logActivity('Ajout plan comptable', 'plan_comptable', $db->lastInsertId(), $_POST['compte']);
                    break;

                case 'edit':
                    $stmt = $db->prepare("UPDATE plan_comptable SET intitule = ?, type_compte = ?, collectif = ?, auxiliaire = ? WHERE id_compte = ?");
                    $stmt->execute([
                        cleanInput($_POST['intitule_compte']),
                        $_POST['type'],
                        isset($_POST['collectif']) ? 1 : 0,
                        isset($_POST['auxiliaire']) ? 1 : 0,
                        $_POST['id']
                    ]);
                    $message = 'Compte modifié avec succès';
                    $messageType = 'success';
                    logActivity('Modification plan comptable', 'plan_comptable', $_POST['id']);
                    break;

                case 'toggle':
                    $stmt = $db->prepare("UPDATE plan_comptable SET actif = IF(actif = 1, 0, 1) WHERE id_compte = ?");
                    $stmt->execute([$_POST['id']]);
                    $message = 'Statut modifié avec succès';
                    $messageType = 'success';
                    logActivity('Toggle actif plan comptable', 'plan_comptable', $_POST['id']);
                    break;

                case 'delete':
                    $stmt = $db->prepare("DELETE FROM plan_comptable WHERE id_compte = ?");
                    $stmt->execute([$_POST['id']]);
                    $message = 'Compte supprimé avec succès';
                    $messageType = 'success';
                    logActivity('Suppression plan comptable', 'plan_comptable', $_POST['id']);
                    break;
            }
        } catch (PDOException $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Filtres
$classeFilter = $_GET['classe'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';

// Récupérer les comptes (adaptation au schéma actuel)
$sql = "SELECT pc.id_compte as id, pc.numero_compte as compte, pc.intitule as intitule_compte,
        pc.type_compte as type, pc.classe, pc.collectif, pc.auxiliaire, pc.actif,
        NULL as libelle_racine, NULL as quatre_chiffres, 'Bilan' as tableau,
        NULL as bd, NULL as bc, NULL as rd, NULL as rc
        FROM plan_comptable pc
        WHERE 1=1";
$params = [];

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

$sql .= " ORDER BY pc.numero_compte";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comptes = $stmt->fetchAll();

// Récupérer les comptes racines pour le select (temporairement vide car table_correspondance peut ne pas avoir de données)
$comptesRacines = [];
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
                        <h1 class="text-xl font-semibold text-white">Plan Comptable</h1>
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
                                <?php
                                $types = ['Client', 'Fournisseur', 'Salarié', 'Banque', 'Caisse', 'Charge', 'Produit', 'Immobilisation', 'Capitaux', 'Stock', 'Autre'];
                                foreach($types as $type):
                                ?>
                                    <option value="<?php echo $type; ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm transition">
                                Filtrer
                            </button>
                            <?php if ($classeFilter || $typeFilter || $searchFilter): ?>
                                <a href="plan_comptable.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">
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
                                    <th class="p-3 font-medium">Collectif</th>
                                    <th class="p-3 font-medium">Auxiliaire</th>
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
                                                    <p class="text-xs text-slate-500 mt-1">Classe <?php echo $compte['classe']; ?></p>
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
                                            <td class="p-3 text-center">
                                                <?php if ($compte['collectif']): ?>
                                                    <span class="inline-block w-2 h-2 bg-emerald-400 rounded-full"></span>
                                                <?php else: ?>
                                                    <span class="text-slate-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3 text-center">
                                                <?php if ($compte['auxiliaire']): ?>
                                                    <span class="inline-block w-2 h-2 bg-purple-400 rounded-full"></span>
                                                <?php else: ?>
                                                    <span class="text-slate-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Changer le statut ?')">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?php echo $compte['id']; ?>">
                                                    <button type="submit" class="text-xs px-2 py-1 rounded <?php echo $compte['actif'] == 1 ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'; ?>">
                                                        <?php echo $compte['actif'] == 1 ? 'Oui' : 'Non'; ?>
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

                <!-- Statistiques -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Total</p>
                        <p class="text-xl font-bold text-white mt-1"><?php echo count($comptes); ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Actifs</p>
                        <p class="text-xl font-bold text-emerald-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['actif'] == 1)); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Collectifs</p>
                        <p class="text-xl font-bold text-blue-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['collectif'])); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Auxiliaires</p>
                        <p class="text-xl font-bold text-purple-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['auxiliaire'])); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-3">
                        <p class="text-xs text-slate-400">Classes</p>
                        <p class="text-xl font-bold text-amber-400 mt-1">
                            <?php echo count(array_unique(array_column($comptes, 'classe'))); ?>
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div id="modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl max-w-3xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <h3 id="modalTitle" class="text-lg font-semibold text-white mb-4">Nouveau compte</h3>
            <form id="compteForm" method="POST" class="space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Compte *</label>
                        <input type="text" name="compte" id="formCompte" required maxlength="20"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: 411001">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Classe *</label>
                        <select name="classe" id="formClasse" required
                                class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                            <?php for($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>">Classe <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Intitulé *</label>
                    <input type="text" name="intitule_compte" id="formIntitule" required maxlength="255"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                           placeholder="Ex: Clients divers">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Type *</label>
                    <select name="type" id="formType" required
                            class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                        <option value="actif">Actif</option>
                        <option value="passif">Passif</option>
                        <option value="charge">Charge</option>
                        <option value="produit">Produit</option>
                        <option value="classe_1">Classe 1</option>
                        <option value="classe_2">Classe 2</option>
                        <option value="classe_3">Classe 3</option>
                        <option value="classe_4">Classe 4</option>
                        <option value="classe_5">Classe 5</option>
                        <option value="classe_6">Classe 6</option>
                        <option value="classe_7">Classe 7</option>
                        <option value="classe_8">Classe 8</option>
                    </select>
                </div>

                <div class="flex gap-4">
                    <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                        <input type="checkbox" name="collectif" id="formCollectif" class="w-4 h-4 rounded bg-slate-900/50 border-slate-600 text-emerald-500 focus:ring-emerald-500">
                        <span>Compte collectif</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                        <input type="checkbox" name="auxiliaire" id="formAuxiliaire" class="w-4 h-4 rounded bg-slate-900/50 border-slate-600 text-emerald-500 focus:ring-emerald-500">
                        <span>Compte auxiliaire</span>
                    </label>
                </div>

                <div class="flex gap-2 pt-4">
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
            document.getElementById('formCompte').disabled = true;
            document.getElementById('formClasse').value = compte.classe;
            document.getElementById('formIntitule').value = compte.intitule_compte;
            document.getElementById('formType').value = compte.type;
            document.getElementById('formCollectif').checked = compte.collectif == 1;
            document.getElementById('formAuxiliaire').checked = compte.auxiliaire == 1;
        }

        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
