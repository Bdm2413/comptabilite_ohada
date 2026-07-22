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
                    $stmt = $db->prepare("INSERT INTO journaux (societe_id, code_journal, libelle, type_journal) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $societe_id,
                        strtoupper(cleanInput($_POST['code'])),
                        cleanInput($_POST['journal']),
                        strtolower($_POST['type'])
                    ]);
                    $message = 'Code journal ajouté avec succès';
                    $messageType = 'success';
                    logActivity('Ajout code journal', 'journaux', $db->lastInsertId(), $_POST['code']);
                    break;

                case 'edit':
                    $stmt = $db->prepare("UPDATE journaux SET libelle = ?, type_journal = ? WHERE id_journal = ? AND societe_id = ?");
                    $stmt->execute([
                        cleanInput($_POST['journal']),
                        strtolower($_POST['type']),
                        $_POST['id'],
                        $societe_id
                    ]);
                    $message = 'Code journal modifié avec succès';
                    $messageType = 'success';
                    logActivity('Modification code journal', 'journaux', $_POST['id']);
                    break;

                case 'toggle':
                    $stmt = $db->prepare("UPDATE journaux SET actif = NOT actif WHERE id_journal = ? AND societe_id = ?");
                    $stmt->execute([$_POST['id'], $societe_id]);
                    $message = 'Statut modifié avec succès';
                    $messageType = 'success';
                    logActivity('Toggle actif code journal', 'journaux', $_POST['id']);
                    break;

                case 'delete':
                    $stmt = $db->prepare("DELETE FROM journaux WHERE id_journal = ? AND societe_id = ?");
                    $stmt->execute([$_POST['id'], $societe_id]);
                    $message = 'Code journal supprimé avec succès';
                    $messageType = 'success';
                    logActivity('Suppression code journal', 'journaux', $_POST['id']);
                    break;
            }
        } catch (PDOException $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Récupérer tous les codes journaux
$stmt = $db->prepare("SELECT id_journal as id, code_journal as code, libelle as journal, type_journal as type, actif FROM journaux WHERE societe_id = ? ORDER BY code_journal");
$stmt->execute([$societe_id]);
$journaux = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code Journaux - <?php echo APP_NAME; ?></title>
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
                            <i class="fas fa-book mr-3"></i>Code Journaux
                        </h1>
                        <p class="text-sm text-slate-400 mt-0.5">Gestion des codes journaux comptables</p>
                    </div>
                    <button onclick="openModal('add')" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded-lg transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Nouveau journal
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

                <!-- Table -->
                <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-700/50">
                                <tr class="text-left text-slate-300 text-xs">
                                    <th class="p-3 font-medium">Code</th>
                                    <th class="p-3 font-medium">Libellé</th>
                                    <th class="p-3 font-medium">Type</th>
                                    <th class="p-3 font-medium">Statut</th>
                                    <th class="p-3 font-medium text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php if (empty($journaux)): ?>
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-slate-500">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                            </svg>
                                            <p>Aucun code journal enregistré</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($journaux as $journal): ?>
                                        <tr class="hover:bg-slate-700/20 transition">
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded text-xs font-mono font-semibold">
                                                    <?php echo htmlspecialchars($journal['code']); ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-white font-medium"><?php echo htmlspecialchars($journal['journal']); ?></td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 bg-blue-500/10 text-blue-400 rounded text-xs">
                                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $journal['type']))); ?>
                                                </span>
                                            </td>
                                            <td class="p-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Changer le statut ?')">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?php echo $journal['id']; ?>">
                                                    <button type="submit" class="text-xs px-2 py-1 rounded <?php echo $journal['actif'] ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'; ?>">
                                                        <?php echo $journal['actif'] ? 'Actif' : 'Inactif'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex items-center justify-end gap-2">
                                                    <button onclick='editJournal(<?php echo json_encode($journal); ?>)' class="p-1.5 text-blue-400 hover:bg-blue-500/10 rounded transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Supprimer ce journal ?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $journal['id']; ?>">
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
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Total journaux</p>
                        <p class="text-2xl font-bold text-white mt-1"><?php echo count($journaux); ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Actifs</p>
                        <p class="text-2xl font-bold text-emerald-400 mt-1">
                            <?php echo count(array_filter($journaux, fn($j) => $j['actif'])); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Banque/Caisse</p>
                        <p class="text-2xl font-bold text-blue-400 mt-1">
                            <?php echo count(array_filter($journaux, fn($j) => in_array($j['type'], ['banque', 'caisse']))); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Achats/Ventes</p>
                        <p class="text-2xl font-bold text-purple-400 mt-1">
                            <?php echo count(array_filter($journaux, fn($j) => in_array($j['type'], ['achat', 'vente']))); ?>
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div id="modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl max-w-md w-full p-6 shadow-2xl">
            <h3 id="modalTitle" class="text-lg font-semibold text-white mb-4">Nouveau code journal</h3>
            <form id="journalForm" method="POST" class="space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Code *</label>
                    <input type="text" name="code" id="formCode" required maxlength="10"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm uppercase"
                           placeholder="Ex: AC, VE, BQ">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Libellé *</label>
                    <input type="text" name="journal" id="formJournal" required maxlength="255"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                           placeholder="Ex: Journal des Achats">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Type *</label>
                    <select name="type" id="formType" required
                            class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                        <option value="achat">Achat</option>
                        <option value="vente">Vente</option>
                        <option value="banque">Banque</option>
                        <option value="caisse">Caisse</option>
                        <option value="operations_diverses">Opérations Diverses</option>
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
            document.getElementById('modalTitle').textContent = action === 'add' ? 'Nouveau code journal' : 'Modifier le code journal';

            if (action === 'add') {
                document.getElementById('journalForm').reset();
                document.getElementById('formCode').disabled = false;
            }
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        function editJournal(journal) {
            openModal('edit');
            document.getElementById('formId').value = journal.id;
            document.getElementById('formCode').value = journal.code;
            document.getElementById('formCode').disabled = true;
            document.getElementById('formJournal').value = journal.journal;
            document.getElementById('formType').value = journal.type;
        }

        // Fermer le modal en cliquant à l'extérieur
        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
