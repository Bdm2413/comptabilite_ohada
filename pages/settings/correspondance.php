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
                    $stmt = $db->prepare("INSERT INTO table_correspondance (compte, classe, libelle, tableau, bd, bc, rd, rc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['compte'],
                        $_POST['classe'],
                        cleanInput($_POST['libelle']),
                        $_POST['tableau'],
                        !empty($_POST['bd']) ? cleanInput($_POST['bd']) : null,
                        !empty($_POST['bc']) ? cleanInput($_POST['bc']) : null,
                        !empty($_POST['rd']) ? cleanInput($_POST['rd']) : null,
                        !empty($_POST['rc']) ? cleanInput($_POST['rc']) : null
                    ]);
                    $message = 'Compte ajouté avec succès';
                    $messageType = 'success';
                    logActivity('Ajout table correspondance', 'table_correspondance', $db->lastInsertId(), $_POST['compte']);
                    break;

                case 'edit':
                    $stmt = $db->prepare("UPDATE table_correspondance SET libelle = ?, tableau = ?, bd = ?, bc = ?, rd = ?, rc = ? WHERE id = ?");
                    $stmt->execute([
                        cleanInput($_POST['libelle']),
                        $_POST['tableau'],
                        !empty($_POST['bd']) ? cleanInput($_POST['bd']) : null,
                        !empty($_POST['bc']) ? cleanInput($_POST['bc']) : null,
                        !empty($_POST['rd']) ? cleanInput($_POST['rd']) : null,
                        !empty($_POST['rc']) ? cleanInput($_POST['rc']) : null,
                        $_POST['id']
                    ]);
                    $message = 'Compte modifié avec succès';
                    $messageType = 'success';
                    logActivity('Modification table correspondance', 'table_correspondance', $_POST['id']);
                    break;

                case 'delete':
                    $stmt = $db->prepare("DELETE FROM table_correspondance WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $message = 'Compte supprimé avec succès';
                    $messageType = 'success';
                    logActivity('Suppression table correspondance', 'table_correspondance', $_POST['id']);
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
$tableauFilter = $_GET['tableau'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20; // 20 comptes par page
$offset = ($page - 1) * $perPage;

// Compter le total de comptes (pour la pagination)
$countSql = "SELECT COUNT(*) FROM table_correspondance WHERE 1=1";
$countParams = [];

if ($classeFilter) {
    $countSql .= " AND classe = ?";
    $countParams[] = $classeFilter;
}

if ($tableauFilter) {
    $countSql .= " AND tableau = ?";
    $countParams[] = $tableauFilter;
}

$stmtCount = $db->prepare($countSql);
$stmtCount->execute($countParams);
$totalComptes = $stmtCount->fetchColumn();
$totalPages = ceil($totalComptes / $perPage);

// Récupérer les comptes avec pagination
$sql = "SELECT * FROM table_correspondance WHERE 1=1";
$params = [];

if ($classeFilter) {
    $sql .= " AND classe = ?";
    $params[] = $classeFilter;
}

if ($tableauFilter) {
    $sql .= " AND tableau = ?";
    $params[] = $tableauFilter;
}

$sql .= " ORDER BY compte LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comptes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Correspondance - <?php echo APP_NAME; ?></title>
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
                            <i class="fas fa-exchange-alt mr-3"></i>Tableau de Correspondance
                        </h1>
                        <p class="text-sm text-slate-400 mt-0.5">Comptes racines à 4 chiffres pour le Bilan et Compte de résultat</p>
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
                    <div class="mb-4 p-3 rounded-lg <?php echo $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/50 text-emerald-400' : 'bg-red-500/10 border border-red-500/50 text-red-400'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Filtres -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4 mb-4">
                    <form method="GET" class="flex flex-wrap gap-3">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Classe</label>
                            <select name="classe" class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" onchange="this.form.submit()">
                                <option value="">Toutes</option>
                                <?php for($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $classeFilter == $i ? 'selected' : ''; ?>>Classe <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Tableau</label>
                            <select name="tableau" class="px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" onchange="this.form.submit()">
                                <option value="">Tous</option>
                                <option value="Bilan" <?php echo $tableauFilter === 'Bilan' ? 'selected' : ''; ?>>Bilan</option>
                                <option value="Résultat" <?php echo $tableauFilter === 'Résultat' ? 'selected' : ''; ?>>Compte de résultat</option>
                            </select>
                        </div>
                        <?php if ($classeFilter || $tableauFilter): ?>
                            <div class="flex items-end">
                                <a href="correspondance.php" class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm rounded-lg transition">
                                    Réinitialiser
                                </a>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Table -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-700/50">
                                <tr class="text-left text-slate-300 text-xs">
                                    <th class="p-3 font-medium">Compte</th>
                                    <th class="p-3 font-medium">Classe</th>
                                    <th class="p-3 font-medium">Libellé</th>
                                    <th class="p-3 font-medium">Tableau</th>
                                    <th class="p-3 font-medium">Positions</th>
                                    <th class="p-3 font-medium text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php if (empty($comptes)): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-slate-500">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            <p>Aucun compte trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($comptes as $compte): ?>
                                        <tr class="hover:bg-slate-700/20 transition">
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded font-mono font-semibold">
                                                    <?php echo htmlspecialchars($compte['compte']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-blue-500/10 text-blue-400 rounded text-xs">
                                                    Classe <?php echo htmlspecialchars($compte['classe']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-white"><?php echo htmlspecialchars($compte['libelle']); ?></td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 <?php echo $compte['tableau'] === 'Bilan' ? 'bg-purple-500/10 text-purple-400' : 'bg-amber-500/10 text-amber-400'; ?> rounded text-xs">
                                                    <?php echo htmlspecialchars($compte['tableau']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-slate-300 text-xs">
                                                <?php
                                                $positions = array_filter([
                                                    $compte['bd'] ? 'BD:' . $compte['bd'] : null,
                                                    $compte['bc'] ? 'BC:' . $compte['bc'] : null,
                                                    $compte['rd'] ? 'RD:' . $compte['rd'] : null,
                                                    $compte['rc'] ? 'RC:' . $compte['rc'] : null
                                                ]);
                                                echo $positions ? implode(', ', $positions) : '-';
                                                ?>
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
                            $baseUrl = 'correspondance.php?';
                            $queryParams = [];
                            if ($classeFilter) $queryParams[] = 'classe=' . $classeFilter;
                            if ($tableauFilter) $queryParams[] = 'tableau=' . urlencode($tableauFilter);
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
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3 mt-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Total comptes</p>
                        <p class="text-2xl font-bold text-white mt-1"><?php echo $totalComptes; ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Page actuelle</p>
                        <p class="text-2xl font-bold text-blue-400 mt-1"><?php echo count($comptes); ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Bilan</p>
                        <p class="text-2xl font-bold text-purple-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['tableau'] === 'Bilan')); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Résultat</p>
                        <p class="text-2xl font-bold text-amber-400 mt-1">
                            <?php echo count(array_filter($comptes, fn($c) => $c['tableau'] === 'Résultat')); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Pages</p>
                        <p class="text-2xl font-bold text-emerald-400 mt-1">
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

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Compte (4 chiffres) *</label>
                        <input type="number" name="compte" id="formCompte" required min="1000" max="9999"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ex: 1000, 6000">
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
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Libellé *</label>
                    <input type="text" name="libelle" id="formLibelle" required maxlength="255"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                           placeholder="Ex: CAPITAL ET RESERVES">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Tableau *</label>
                    <select name="tableau" id="formTableau" required
                            class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                        <option value="Bilan">Bilan</option>
                        <option value="Résultat">Compte de résultat</option>
                    </select>
                </div>

                <div class="border-t border-slate-700 pt-4 mt-4">
                    <p class="text-sm font-medium text-slate-300 mb-3">Positions dans les états financiers</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1.5">Bilan Débit (BD)</label>
                            <input type="text" name="bd" id="formBd" maxlength="10"
                                   class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                                   placeholder="Ex: AI, AC, TA">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1.5">Bilan Crédit (BC)</label>
                            <input type="text" name="bc" id="formBc" maxlength="10"
                                   class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                                   placeholder="Ex: PC, DF">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1.5">Résultat Débit (RD)</label>
                            <input type="text" name="rd" id="formRd" maxlength="10"
                                   class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                                   placeholder="Ex: RD">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1.5">Résultat Crédit (RC)</label>
                            <input type="text" name="rc" id="formRc" maxlength="10"
                                   class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                                   placeholder="Ex: RC">
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">
                        AI=Actif Immobilisé, AC=Actif Circulant, TA=Trésorerie Actif, PC=Passif Circulant, DF=Dettes Financières, RD=Résultat Débit (charges), RC=Résultat Crédit (produits)
                    </p>
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
            document.getElementById('formCompte').disabled = true;
            document.getElementById('formClasse').value = compte.classe;
            document.getElementById('formLibelle').value = compte.libelle;
            document.getElementById('formTableau').value = compte.tableau;
            document.getElementById('formBd').value = compte.bd || '';
            document.getElementById('formBc').value = compte.bc || '';
            document.getElementById('formRd').value = compte.rd || '';
            document.getElementById('formRc').value = compte.rc || '';
        }

        // Fermer le modal en cliquant à l'extérieur
        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
