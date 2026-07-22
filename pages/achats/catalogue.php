<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

// Fonction helper pour number_format
function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

// Récupérer les fournisseurs
$stmtFournisseurs = $db->prepare("SELECT id, nom, abreviation FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtFournisseurs->execute([$societe_id]);
$fournisseurs = $stmtFournisseurs->fetchAll();

// Traitement du formulaire
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $stmt = $db->prepare("
                INSERT INTO catalogues_fournisseurs
                (societe_id, id_fournisseur, reference, designation, description, type_article, unite, prix_unitaire_ht, actif)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $societe_id,
                $_POST['id_fournisseur'],
                trim($_POST['reference']),
                trim($_POST['designation']),
                trim($_POST['description'] ?? ''),
                $_POST['type_article'],
                trim($_POST['unite']),
                floatval(str_replace([' ', ','], ['', '.'], $_POST['prix_unitaire_ht'])),
                $_POST['actif'] ?? 'Oui'
            ]);
            $message = 'Article ajouté au catalogue avec succès';
            $messageType = 'success';

        } elseif ($action === 'edit') {
            $stmt = $db->prepare("
                UPDATE catalogues_fournisseurs SET
                    id_fournisseur = ?,
                    reference = ?,
                    designation = ?,
                    description = ?,
                    type_article = ?,
                    unite = ?,
                    prix_unitaire_ht = ?,
                    actif = ?
                WHERE id = ? AND societe_id = ?
            ");
            $stmt->execute([
                $_POST['id_fournisseur'],
                trim($_POST['reference']),
                trim($_POST['designation']),
                trim($_POST['description'] ?? ''),
                $_POST['type_article'],
                trim($_POST['unite']),
                floatval(str_replace([' ', ','], ['', '.'], $_POST['prix_unitaire_ht'])),
                $_POST['actif'] ?? 'Oui',
                $_POST['id'],
                $societe_id
            ]);
            $message = 'Article modifié avec succès';
            $messageType = 'success';

        } elseif ($action === 'delete') {
            // Vérifier si l'article est utilisé dans des devis ou BC
            $stmtCheck = $db->prepare("
                SELECT COUNT(*) as nb FROM (
                    SELECT id FROM lignes_devis WHERE id_article_catalogue = ?
                    UNION ALL
                    SELECT id FROM lignes_bon_commande WHERE id_article_catalogue = ?
                ) t
            ");
            $stmtCheck->execute([$_POST['id'], $_POST['id']]);
            $usage = $stmtCheck->fetch();

            if ($usage['nb'] > 0) {
                $message = 'Impossible de supprimer : cet article est utilisé dans des devis ou bons de commande';
                $messageType = 'error';
            } else {
                $stmt = $db->prepare("DELETE FROM catalogues_fournisseurs WHERE id = ? AND societe_id = ?");
                $stmt->execute([$_POST['id'], $societe_id]);
                $message = 'Article supprimé du catalogue';
                $messageType = 'success';
            }
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $message = 'Cette référence existe déjà pour ce fournisseur';
        } else {
            $message = 'Erreur: ' . $e->getMessage();
        }
        $messageType = 'error';
    }
}

// Filtres
$filterFournisseur = $_GET['fournisseur'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterActif = $_GET['actif'] ?? 'Oui';
$search = $_GET['search'] ?? '';

// Construire la requête
$sql = "
    SELECT c.*, pt.nom as fournisseur_nom, pt.abreviation as fournisseur_abrev
    FROM catalogues_fournisseurs c
    JOIN plan_tiers pt ON c.id_fournisseur = pt.id
    WHERE c.societe_id = ?
";
$params = [$societe_id];

if (!empty($filterFournisseur)) {
    $sql .= " AND c.id_fournisseur = ?";
    $params[] = $filterFournisseur;
}
if (!empty($filterType)) {
    $sql .= " AND c.type_article = ?";
    $params[] = $filterType;
}
if ($filterActif !== '') {
    $sql .= " AND c.actif = ?";
    $params[] = $filterActif;
}
if (!empty($search)) {
    $sql .= " AND (c.reference LIKE ? OR c.designation LIKE ? OR c.description LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= " ORDER BY pt.nom, c.reference";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// Regrouper les articles par fournisseur
$articlesParFournisseur = [];
foreach ($articles as $article) {
    $fournisseurId = $article['id_fournisseur'];
    if (!isset($articlesParFournisseur[$fournisseurId])) {
        $articlesParFournisseur[$fournisseurId] = [
            'nom' => $article['fournisseur_nom'],
            'abrev' => $article['fournisseur_abrev'],
            'articles' => []
        ];
    }
    $articlesParFournisseur[$fournisseurId]['articles'][] = $article;
}

$pageTitle = "Catalogue Fournisseurs";
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
    <style>
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.7);
        }
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
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-600 mb-2">
                            <i class="fas fa-book-open mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400">Gérez les articles et services de vos fournisseurs</p>
                    </div>
                    <button onclick="openModal('add')" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        Nouvel article
                    </button>
                </div>
            </div>

            <!-- Message flash -->
            <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400' ?>">
                    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Filtres -->
            <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4 mb-6">
                <form method="GET" class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Fournisseur</label>
                        <select name="fournisseur" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                            <option value="">Tous</option>
                            <?php foreach ($fournisseurs as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= $filterFournisseur == $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Type</label>
                        <select name="type" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                            <option value="">Tous</option>
                            <option value="Bien" <?= $filterType === 'Bien' ? 'selected' : '' ?>>Bien</option>
                            <option value="Service" <?= $filterType === 'Service' ? 'selected' : '' ?>>Service</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Statut</label>
                        <select name="actif" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                            <option value="">Tous</option>
                            <option value="Oui" <?= $filterActif === 'Oui' ? 'selected' : '' ?>>Actif</option>
                            <option value="Non" <?= $filterActif === 'Non' ? 'selected' : '' ?>>Inactif</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Recherche</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Référence, désignation..."
                               class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="catalogue.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg transition-colors">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </form>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-400"><?= count($articles) ?></div>
                    <div class="text-slate-400 text-sm">Articles affichés</div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-400"><?= count(array_filter($articles, fn($a) => $a['type_article'] === 'Bien')) ?></div>
                    <div class="text-slate-400 text-sm">Biens</div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-400"><?= count(array_filter($articles, fn($a) => $a['type_article'] === 'Service')) ?></div>
                    <div class="text-slate-400 text-sm">Services</div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-cyan-400"><?= count(array_unique(array_column($articles, 'id_fournisseur'))) ?></div>
                    <div class="text-slate-400 text-sm">Fournisseurs</div>
                </div>
            </div>

            <!-- Articles groupés par fournisseur -->
            <?php if (empty($articles)): ?>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-8 text-center text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>Aucun article dans le catalogue</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($articlesParFournisseur as $fournisseurId => $groupe): ?>
                        <div class="bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
                            <!-- En-tête fournisseur (cliquable pour déplier/replier) -->
                            <button onclick="toggleFournisseur(<?= $fournisseurId ?>)" class="w-full px-4 py-3 bg-slate-700/50 hover:bg-slate-700/70 flex items-center justify-between transition-colors">
                                <div class="flex items-center gap-3">
                                    <i id="icon-<?= $fournisseurId ?>" class="fas fa-chevron-down text-slate-400 transition-transform"></i>
                                    <div class="text-left">
                                        <span class="font-semibold text-slate-200"><?= htmlspecialchars($groupe['nom']) ?></span>
                                        <?php if ($groupe['abrev']): ?>
                                            <span class="ml-2 text-xs text-slate-500">(<?= htmlspecialchars($groupe['abrev']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="px-3 py-1 bg-blue-500/20 text-blue-400 rounded-full text-sm font-medium">
                                    <?= count($groupe['articles']) ?> article<?= count($groupe['articles']) > 1 ? 's' : '' ?>
                                </span>
                            </button>

                            <!-- Table des articles du fournisseur -->
                            <div id="articles-<?= $fournisseurId ?>" class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-700/30">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">Référence</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">Désignation</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Type</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Unité</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-slate-400 uppercase">Prix HT</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Statut</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700">
                                        <?php foreach ($groupe['articles'] as $article): ?>
                                            <tr class="hover:bg-slate-700/30 transition-colors">
                                                <td class="px-4 py-3 font-mono text-blue-400"><?= htmlspecialchars($article['reference']) ?></td>
                                                <td class="px-4 py-3">
                                                    <div class="text-slate-200"><?= htmlspecialchars($article['designation']) ?></div>
                                                    <?php if ($article['description']): ?>
                                                        <div class="text-xs text-slate-500 truncate max-w-xs"><?= htmlspecialchars($article['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2 py-1 rounded-full text-xs <?= $article['type_article'] === 'Bien' ? 'bg-green-500/20 text-green-400' : 'bg-purple-500/20 text-purple-400' ?>">
                                                        <?= $article['type_article'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center text-slate-400"><?= htmlspecialchars($article['unite']) ?></td>
                                                <td class="px-4 py-3 text-right font-mono text-slate-200"><?= safe_number_format($article['prix_unitaire_ht']) ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2 py-1 rounded-full text-xs <?= $article['actif'] === 'Oui' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' ?>">
                                                        <?= $article['actif'] === 'Oui' ? 'Actif' : 'Inactif' ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <button onclick='openModal("edit", <?= json_encode($article) ?>)' class="p-1.5 text-blue-400 hover:bg-blue-500/20 rounded transition-colors" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick='confirmDelete(<?= $article["id"] ?>, "<?= htmlspecialchars(addslashes($article["designation"])) ?>")' class="p-1.5 text-red-400 hover:bg-red-500/20 rounded transition-colors" title="Supprimer">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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

    <!-- Modal -->
    <div id="modal" class="fixed inset-0 z-50 hidden">
        <div class="modal-backdrop absolute inset-0" onclick="closeModal()"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4">
            <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-2xl relative">
                <div class="p-6 border-b border-slate-700">
                    <h3 id="modalTitle" class="text-lg font-semibold text-white"></h3>
                </div>
                <form id="articleForm" method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="articleId">

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Fournisseur *</label>
                            <select name="id_fournisseur" id="id_fournisseur" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500" onchange="generateReference()">
                                <option value="">Sélectionner...</option>
                                <?php foreach ($fournisseurs as $f): ?>
                                    <option value="<?= $f['id'] ?>" data-abrev="<?= htmlspecialchars($f['abreviation'] ?? strtoupper(substr($f['nom'], 0, 3))) ?>"><?= htmlspecialchars($f['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Référence *</label>
                            <div class="flex gap-2">
                                <input type="text" name="reference" id="reference" required class="flex-1 px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                                <button type="button" onclick="generateReference()" class="px-3 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg transition-colors" title="Générer une référence">
                                    <i class="fas fa-magic"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Désignation *</label>
                        <input type="text" name="designation" id="designation" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Description</label>
                        <textarea name="description" id="description" rows="2" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Type *</label>
                            <select name="type_article" id="type_article" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                                <option value="Bien">Bien</option>
                                <option value="Service">Service</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Unité *</label>
                            <input type="text" name="unite" id="unite" required value="unité" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500" list="unites">
                            <datalist id="unites">
                                <option value="unité">
                                <option value="pcs">
                                <option value="kg">
                                <option value="m">
                                <option value="m²">
                                <option value="m³">
                                <option value="litre">
                                <option value="forfait">
                                <option value="heure">
                                <option value="jour">
                                <option value="mois">
                            </datalist>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Prix unitaire HT *</label>
                            <input type="text" name="prix_unitaire_ht" id="prix_unitaire_ht" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500 text-right">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Statut</label>
                        <select name="actif" id="actif" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                            <option value="Oui">Actif</option>
                            <option value="Non">Inactif</option>
                        </select>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
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
        function openModal(action, data = null) {
            document.getElementById('modal').classList.remove('hidden');
            document.getElementById('formAction').value = action;

            if (action === 'add') {
                document.getElementById('modalTitle').textContent = 'Nouvel article au catalogue';
                document.getElementById('articleForm').reset();
                document.getElementById('articleId').value = '';
                document.getElementById('unite').value = 'unité';
            } else if (action === 'edit' && data) {
                document.getElementById('modalTitle').textContent = 'Modifier l\'article';
                document.getElementById('articleId').value = data.id;
                document.getElementById('id_fournisseur').value = data.id_fournisseur;
                document.getElementById('reference').value = data.reference;
                document.getElementById('designation').value = data.designation;
                document.getElementById('description').value = data.description || '';
                document.getElementById('type_article').value = data.type_article;
                document.getElementById('unite').value = data.unite;
                document.getElementById('prix_unitaire_ht').value = parseFloat(data.prix_unitaire_ht).toLocaleString('fr-FR');
                document.getElementById('actif').value = data.actif;
            }
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        function confirmDelete(id, designation) {
            if (confirm('Êtes-vous sûr de vouloir supprimer l\'article "' + designation + '" ?')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // Fermer modal avec Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // Toggle fournisseur section
        function toggleFournisseur(id) {
            const content = document.getElementById('articles-' + id);
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

        // Génération automatique de référence
        const articleCounts = <?= json_encode(
            array_reduce($articles, function($acc, $art) {
                $fid = $art['id_fournisseur'];
                $acc[$fid] = ($acc[$fid] ?? 0) + 1;
                return $acc;
            }, [])
        ) ?>;

        function generateReference() {
            const select = document.getElementById('id_fournisseur');
            const refInput = document.getElementById('reference');
            const formAction = document.getElementById('formAction').value;

            // Ne pas générer si en mode édition et référence déjà remplie
            if (formAction === 'edit' && refInput.value) return;

            if (!select.value) {
                refInput.value = '';
                return;
            }

            const option = select.options[select.selectedIndex];
            const abrev = option.dataset.abrev || 'ART';
            const count = (articleCounts[select.value] || 0) + 1;
            const ref = abrev.toUpperCase() + '-' + String(count).padStart(4, '0');
            refInput.value = ref;
        }
    </script>
</body>
</html>
