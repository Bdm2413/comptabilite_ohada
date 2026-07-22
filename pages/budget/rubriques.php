<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = "Rubriques Budgétaires";
$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();

// Gérer les actions (ajout, modification, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter') {
        $code = $_POST['code'];
        $libelle = $_POST['libelle'];
        $type = $_POST['type'];
        $description = $_POST['description'] ?? null;
        $ordre = $_POST['ordre_affichage'] ?? 0;

        $stmt = $db->prepare("INSERT INTO budget_rubriques (code, libelle, type, description, ordre_affichage, societe_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$code, $libelle, $type, $description, $ordre, $societe_id]);
        $success_msg = "Rubrique ajoutée avec succès !";
    } elseif ($action === 'modifier') {
        $id = $_POST['id'];
        $code = $_POST['code'];
        $libelle = $_POST['libelle'];
        $type = $_POST['type'];
        $description = $_POST['description'] ?? null;
        $ordre = $_POST['ordre_affichage'] ?? 0;
        $actif = $_POST['actif'];

        $stmt = $db->prepare("UPDATE budget_rubriques SET code = ?, libelle = ?, type = ?, description = ?, ordre_affichage = ?, actif = ? WHERE id = ? AND societe_id = ?");
        $stmt->execute([$code, $libelle, $type, $description, $ordre, $actif, $id, $societe_id]);
        $success_msg = "Rubrique modifiée avec succès !";
    } elseif ($action === 'supprimer') {
        $id = $_POST['id'];
        $stmt = $db->prepare("DELETE FROM budget_rubriques WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id, $societe_id]);
        $success_msg = "Rubrique supprimée avec succès !";
    }
}

// Récupérer toutes les rubriques de la société courante
$stmt = $db->prepare("SELECT * FROM budget_rubriques WHERE societe_id = ? ORDER BY type, ordre_affichage, libelle");
$stmt->execute([$societe_id]);
$rubriques = $stmt->fetchAll();

// Grouper par type
$rubriques_par_type = [];
foreach ($rubriques as $r) {
    $rubriques_par_type[$r['type']][] = $r;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        #sidebar { opacity: 1 !important; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-pink-400 to-purple-600 mb-2">
                            <i class="fas fa-tags mr-3"></i>Rubriques Budgétaires
                        </h1>
                        <p class="text-slate-400">Gérer les rubriques pour regrouper les comptes dans le budget</p>
                    </div>
                    <div class="flex gap-3">
                        <button onclick="ouvrirModalAjout()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            Nouvelle rubrique
                        </button>
                        <a href="index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>
                            Retour
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($success_msg)): ?>
                <div class="bg-green-900/20 border border-green-700/50 rounded-lg p-4 mb-6">
                    <p class="text-green-300">
                        <i class="fas fa-check-circle mr-2"></i><?= $success_msg ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Rubriques par type -->
            <?php foreach (['Produits', 'Charges', 'Autre'] as $type): ?>
                <?php if (isset($rubriques_par_type[$type])): ?>
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden mb-6">
                        <div class="p-6 border-b border-slate-700">
                            <h2 class="text-xl font-bold text-white">
                                <i class="fas fa-<?= $type === 'Produits' ? 'arrow-up' : ($type === 'Charges' ? 'arrow-down' : 'circle') ?> mr-2 text-<?= $type === 'Produits' ? 'green' : ($type === 'Charges' ? 'red' : 'gray') ?>-400"></i>
                                <?= $type ?>
                            </h2>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gradient-to-r from-slate-700 to-slate-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-slate-300 font-semibold">Code</th>
                                        <th class="px-4 py-3 text-left text-slate-300 font-semibold">Libellé</th>
                                        <th class="px-4 py-3 text-left text-slate-300 font-semibold">Description</th>
                                        <th class="px-4 py-3 text-center text-slate-300 font-semibold">Ordre</th>
                                        <th class="px-4 py-3 text-center text-slate-300 font-semibold">Statut</th>
                                        <th class="px-4 py-3 text-center text-slate-300 font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php foreach ($rubriques_par_type[$type] as $rubrique): ?>
                                        <tr class="hover:bg-slate-700/30 transition-colors">
                                            <td class="px-4 py-3">
                                                <span class="font-mono font-semibold text-indigo-400"><?= htmlspecialchars($rubrique['code']) ?></span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-white font-semibold"><?= htmlspecialchars($rubrique['libelle']) ?></span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-slate-400 text-sm"><?= htmlspecialchars($rubrique['description'] ?? '-') ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="text-slate-300"><?= $rubrique['ordre_affichage'] ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($rubrique['actif'] === 'Oui'): ?>
                                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-900/50 text-green-300">
                                                        <i class="fas fa-check mr-1"></i>Actif
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-900/50 text-gray-400">
                                                        <i class="fas fa-times mr-1"></i>Inactif
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <button onclick='ouvrirModalModif(<?= json_encode($rubrique) ?>)' class="text-blue-400 hover:text-blue-300 mr-3">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="confirmerSuppression(<?= $rubrique['id'] ?>, '<?= htmlspecialchars($rubrique['libelle']) ?>')" class="text-red-400 hover:text-red-300">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (empty($rubriques)): ?>
                <div class="bg-yellow-900/20 border border-yellow-700/50 rounded-lg p-8 text-center">
                    <i class="fas fa-tags text-yellow-400 text-5xl mb-4"></i>
                    <p class="text-yellow-300 text-lg mb-4">Aucune rubrique budgétaire n'a encore été créée.</p>
                    <button onclick="ouvrirModalAjout()" class="inline-block px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-plus mr-2"></i>Créer la première rubrique
                    </button>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal Ajout/Modification -->
    <div id="modalRubrique" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl max-w-2xl w-full">
            <div class="p-6 border-b border-slate-700 flex items-center justify-between">
                <h3 class="text-xl font-bold text-white" id="modalTitle">
                    <i class="fas fa-plus-circle mr-2 text-green-400"></i>
                    <span id="modalTitleText">Nouvelle Rubrique</span>
                </h3>
                <button onclick="fermerModal()" class="text-slate-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" id="formRubrique" class="p-6">
                <input type="hidden" name="action" id="action" value="ajouter">
                <input type="hidden" name="id" id="rubrique_id">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Code <span class="text-red-400">*</span>
                        </label>
                        <input type="text" name="code" id="code" required maxlength="20"
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500"
                               placeholder="Ex: CA, PERSONNEL">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Type <span class="text-red-400">*</span>
                        </label>
                        <select name="type" id="type" required
                                class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                            <option value="Produits">Produits</option>
                            <option value="Charges">Charges</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-300 mb-2">
                        Libellé <span class="text-red-400">*</span>
                    </label>
                    <input type="text" name="libelle" id="libelle" required maxlength="255"
                           class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500"
                           placeholder="Ex: Chiffre d'affaires, Charges de personnel">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Description</label>
                    <textarea name="description" id="description" rows="2"
                              class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500"
                              placeholder="Description optionnelle de la rubrique"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Ordre d'affichage</label>
                        <input type="number" name="ordre_affichage" id="ordre_affichage" value="0" min="0"
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div id="divActif" class="hidden">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Statut</label>
                        <select name="actif" id="actif"
                                class="w-full bg-slate-700 border border-slate-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-indigo-500">
                            <option value="Oui">Actif</option>
                            <option value="Non">Inactif</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" onclick="fermerModal()" class="px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                        Annuler
                    </button>
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all">
                        <i class="fas fa-save mr-2"></i>
                        <span id="btnSubmitText">Ajouter</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Form suppression caché -->
    <form method="POST" id="formSupprimer" class="hidden">
        <input type="hidden" name="action" value="supprimer">
        <input type="hidden" name="id" id="id_supprimer">
    </form>

    <script>
        function ouvrirModalAjout() {
            document.getElementById('action').value = 'ajouter';
            document.getElementById('modalTitleText').textContent = 'Nouvelle Rubrique';
            document.getElementById('btnSubmitText').textContent = 'Ajouter';
            document.getElementById('formRubrique').reset();
            document.getElementById('divActif').classList.add('hidden');
            document.getElementById('modalRubrique').classList.remove('hidden');
        }

        function ouvrirModalModif(rubrique) {
            document.getElementById('action').value = 'modifier';
            document.getElementById('modalTitleText').textContent = 'Modifier la Rubrique';
            document.getElementById('btnSubmitText').textContent = 'Modifier';
            document.getElementById('rubrique_id').value = rubrique.id;
            document.getElementById('code').value = rubrique.code;
            document.getElementById('libelle').value = rubrique.libelle;
            document.getElementById('type').value = rubrique.type;
            document.getElementById('description').value = rubrique.description || '';
            document.getElementById('ordre_affichage').value = rubrique.ordre_affichage;
            document.getElementById('actif').value = rubrique.actif;
            document.getElementById('divActif').classList.remove('hidden');
            document.getElementById('modalRubrique').classList.remove('hidden');
        }

        function fermerModal() {
            document.getElementById('modalRubrique').classList.add('hidden');
        }

        function confirmerSuppression(id, libelle) {
            if (confirm(`Êtes-vous sûr de vouloir supprimer la rubrique "${libelle}" ?\n\nAttention : Cette action est irréversible.`)) {
                document.getElementById('id_supprimer').value = id;
                document.getElementById('formSupprimer').submit();
            }
        }
    </script>
</body>
</html>
