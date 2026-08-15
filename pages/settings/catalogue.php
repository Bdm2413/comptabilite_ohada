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

// Récupérer les clients
$stmtClients = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type = 'Client' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtClients->execute([$societe_id]);
$clients = $stmtClients->fetchAll();

// Comptes comptables pour les selects (comptes de charge 6x et de produit 7x)
$stmtComptes = $db->prepare("
    SELECT compte, intitule_compte
    FROM plan_comptable
    WHERE societe_id = ? AND LENGTH(compte) >= 4
      AND (LEFT(compte,1) = '6' OR LEFT(compte,1) = '7')
    ORDER BY compte
");
$stmtComptes->execute([$societe_id]);
$comptes_compta = $stmtComptes->fetchAll();

// Traitement du formulaire
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $parseDecimal = fn($v) => floatval(str_replace([' ', ',', "\xc2\xa0"], ['', '.', ''], $v ?? '0'));

        if ($action === 'add') {
            $stmt = $db->prepare("
                INSERT INTO catalogues_fournisseurs
                (societe_id, id_fournisseur, id_client, reference, designation, description,
                 type_article, usage_article, compte_achat, compte_vente, type_taxe_defaut,
                 unite, prix_unitaire_ht, prix_vente_ht, actif)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $societe_id,
                $_POST['id_fournisseur'] ?: null,
                $_POST['id_client'] ?: null,
                trim($_POST['reference']),
                trim($_POST['designation']),
                trim($_POST['description'] ?? ''),
                $_POST['type_article'],
                $_POST['usage_article'] ?? 'achat',
                $_POST['compte_achat']  ?: null,
                $_POST['compte_vente']  ?: null,
                $_POST['type_taxe_defaut'] ?? 'Aucune',
                trim($_POST['unite']),
                $parseDecimal($_POST['prix_unitaire_ht']),
                $parseDecimal($_POST['prix_vente_ht']),
                $_POST['actif'] ?? 'Oui'
            ]);
            $message = 'Article ajouté au catalogue avec succès';
            $messageType = 'success';

        } elseif ($action === 'edit') {
            $stmt = $db->prepare("
                UPDATE catalogues_fournisseurs SET
                    id_fournisseur = ?,
                    id_client = ?,
                    reference = ?,
                    designation = ?,
                    description = ?,
                    type_article = ?,
                    usage_article = ?,
                    compte_achat = ?,
                    compte_vente = ?,
                    type_taxe_defaut = ?,
                    unite = ?,
                    prix_unitaire_ht = ?,
                    prix_vente_ht = ?,
                    actif = ?
                WHERE id = ? AND societe_id = ?
            ");
            $stmt->execute([
                $_POST['id_fournisseur'] ?: null,
                $_POST['id_client'] ?: null,
                trim($_POST['reference']),
                trim($_POST['designation']),
                trim($_POST['description'] ?? ''),
                $_POST['type_article'],
                $_POST['usage_article'] ?? 'achat',
                $_POST['compte_achat']  ?: null,
                $_POST['compte_vente']  ?: null,
                $_POST['type_taxe_defaut'] ?? 'Aucune',
                trim($_POST['unite']),
                $parseDecimal($_POST['prix_unitaire_ht']),
                $parseDecimal($_POST['prix_vente_ht']),
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

// Onglet actif
$tab = in_array($_GET['tab'] ?? '', ['achat','vente']) ? $_GET['tab'] : 'achat';

// Filtres
$filterFournisseur = $_GET['fournisseur'] ?? '';
$filterClient      = $_GET['client']      ?? '';
$filterType        = $_GET['type']        ?? '';
$filterActif       = $_GET['actif']       ?? 'Oui';
$search            = $_GET['search']      ?? '';

// Requête adaptée à l'onglet
$sql = "
    SELECT c.*,
           ptf.nom as fournisseur_nom, ptf.abreviation as fournisseur_abrev,
           ptc.nom as client_nom
    FROM catalogues_fournisseurs c
    LEFT JOIN plan_tiers ptf ON c.id_fournisseur = ptf.id
    LEFT JOIN plan_tiers ptc ON c.id_client = ptc.id
    WHERE c.societe_id = ?
";
$params = [$societe_id];

if ($tab === 'achat') {
    $sql .= " AND c.usage_article IN ('achat','les_deux')";
} else {
    $sql .= " AND c.usage_article IN ('vente','les_deux')";
}
if (!empty($filterFournisseur)) {
    $sql .= " AND c.id_fournisseur = ?";
    $params[] = $filterFournisseur;
}
if (!empty($filterClient)) {
    $sql .= " AND c.id_client = ?";
    $params[] = $filterClient;
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

$sql .= $tab === 'achat'
    ? " ORDER BY ptf.nom, c.reference"
    : " ORDER BY c.designation";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// Groupement par fournisseur uniquement pour l'onglet Achat
$articlesParFournisseur = [];
if ($tab === 'achat') {
    foreach ($articles as $article) {
        $key = $article['id_fournisseur'] ?? 0;
        if (!isset($articlesParFournisseur[$key])) {
            $articlesParFournisseur[$key] = [
                'nom'      => $article['fournisseur_nom'] ?? 'Catalogue général',
                'abrev'    => $article['fournisseur_abrev'] ?? '',
                'articles' => []
            ];
        }
        $articlesParFournisseur[$key]['articles'][] = $article;
    }
}

$pageTitle = "Catalogue Articles";
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
            <div class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-600 mb-1">
                            <i class="fas fa-book-open mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400 text-sm">Articles et services - référentiel pour la comptabilisation automatique</p>
                    </div>
                    <button onclick="openModal('add')" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2">
                        <i class="fas fa-plus"></i>Nouvel article
                    </button>
                </div>

                <!-- Onglets Achat / Vente -->
                <div class="flex gap-1 bg-slate-800/60 p-1 rounded-lg w-fit border border-slate-700">
                    <a href="?tab=achat" class="px-5 py-2 rounded-md text-sm font-medium transition-all <?= $tab === 'achat' ? 'bg-blue-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' ?>">
                        <i class="fas fa-shopping-cart mr-2"></i>Catalogue Achat
                    </a>
                    <a href="?tab=vente" class="px-5 py-2 rounded-md text-sm font-medium transition-all <?= $tab === 'vente' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' ?>">
                        <i class="fas fa-tag mr-2"></i>Catalogue Vente
                    </a>
                </div>
            </div>

            <!-- Message flash -->
            <?php if ($message): ?>
                <div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400' ?>">
                    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Filtres -->
            <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4 mb-5">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="tab" value="<?= $tab ?>">
                    <?php if ($tab === 'achat'): ?>
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
                    <?php else: ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Client</label>
                        <select name="client" class="px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-emerald-500">
                            <option value="">Tous</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
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
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="?tab=<?= $tab ?>" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </form>
            </div>

            <!-- Stats -->
            <?php
                $nbBiens    = count(array_filter($articles, fn($a) => $a['type_article'] === 'Bien'));
                $nbServices = count(array_filter($articles, fn($a) => $a['type_article'] === 'Service'));
                $nbAvecCompte = $tab === 'achat'
                    ? count(array_filter($articles, fn($a) => !empty($a['compte_achat'])))
                    : count(array_filter($articles, fn($a) => !empty($a['compte_vente'])));
                $nbFourn = count(array_unique(array_filter(array_column($articles, 'id_fournisseur'))));
            ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold <?= $tab === 'achat' ? 'text-blue-400' : 'text-emerald-400' ?>"><?= count($articles) ?></div>
                    <div class="text-slate-400 text-sm">Articles</div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-400"><?= $nbBiens ?></div>
                    <div class="text-slate-400 text-sm">Biens</div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-400"><?= $nbServices ?></div>
                    <div class="text-slate-400 text-sm">Services</div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                    <div class="text-2xl font-bold text-amber-400"><?= $nbAvecCompte ?></div>
                    <div class="text-slate-400 text-sm">Avec compte comptable</div>
                </div>
            </div>

            <!-- Articles -->
            <?php if (empty($articles)): ?>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-8 text-center text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>Aucun article dans ce catalogue</p>
                    <p class="text-sm mt-1">Cliquez sur "Nouvel article" et choisissez l'usage "<?= $tab === 'achat' ? 'Achat' : 'Vente' ?>" ou "Les deux".</p>
                </div>

            <?php elseif ($tab === 'achat'): ?>
                <!-- Vue Achat : groupée par fournisseur -->
                <div class="space-y-4">
                    <?php foreach ($articlesParFournisseur as $fournisseurId => $groupe): ?>
                        <div class="bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
                            <button onclick="toggleFournisseur('<?= $fournisseurId ?>')" class="w-full px-4 py-3 bg-slate-700/50 hover:bg-slate-700/70 flex items-center justify-between transition-colors">
                                <div class="flex items-center gap-3">
                                    <i id="icon-<?= $fournisseurId ?>" class="fas fa-chevron-down text-slate-400"></i>
                                    <span class="font-semibold text-slate-200"><?= htmlspecialchars($groupe['nom']) ?></span>
                                    <?php if ($groupe['abrev']): ?>
                                        <span class="text-xs text-slate-500">(<?= htmlspecialchars($groupe['abrev']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <span class="px-3 py-1 bg-blue-500/20 text-blue-400 rounded-full text-sm font-medium">
                                    <?= count($groupe['articles']) ?> article<?= count($groupe['articles']) > 1 ? 's' : '' ?>
                                </span>
                            </button>
                            <div id="articles-<?= $fournisseurId ?>" class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-700/30">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs text-slate-400 uppercase">Référence</th>
                                            <th class="px-4 py-2 text-left text-xs text-slate-400 uppercase">Désignation</th>
                                            <th class="px-4 py-2 text-center text-xs text-slate-400 uppercase">Type</th>
                                            <th class="px-4 py-2 text-center text-xs text-slate-400 uppercase">Taxe défaut</th>
                                            <th class="px-4 py-2 text-left text-xs text-slate-400 uppercase">Compte charge</th>
                                            <th class="px-4 py-2 text-center text-xs text-slate-400 uppercase">Unité</th>
                                            <th class="px-4 py-2 text-right text-xs text-slate-400 uppercase">Prix achat HT</th>
                                            <th class="px-4 py-2 text-center text-xs text-slate-400 uppercase">Statut</th>
                                            <th class="px-4 py-2 text-center text-xs text-slate-400 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700">
                                        <?php foreach ($groupe['articles'] as $article): ?>
                                            <tr class="hover:bg-slate-700/30 transition-colors">
                                                <td class="px-4 py-3 font-mono text-blue-400 text-xs"><?= htmlspecialchars($article['reference']) ?></td>
                                                <td class="px-4 py-3">
                                                    <div class="text-slate-200"><?= htmlspecialchars($article['designation']) ?></div>
                                                    <?php if ($article['description']): ?>
                                                        <div class="text-xs text-slate-500 truncate max-w-xs"><?= htmlspecialchars($article['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2 py-0.5 rounded-full text-xs <?= $article['type_article'] === 'Bien' ? 'bg-green-500/20 text-green-400' : 'bg-purple-500/20 text-purple-400' ?>">
                                                        <?= $article['type_article'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <?php $taxeColors = ['TVA'=>'text-green-400','PPSSI'=>'text-amber-400','BNC'=>'text-orange-400','Aucune'=>'text-slate-500']; ?>
                                                    <span class="text-xs <?= $taxeColors[$article['type_taxe_defaut']] ?? 'text-slate-500' ?>">
                                                        <?= $article['type_taxe_defaut'] === 'Aucune' ? '-' : $article['type_taxe_defaut'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-xs font-mono text-slate-300">
                                                    <?= $article['compte_achat'] ? htmlspecialchars($article['compte_achat']) : '<span class="text-slate-600">-</span>' ?>
                                                </td>
                                                <td class="px-4 py-3 text-center text-slate-400 text-xs"><?= htmlspecialchars($article['unite']) ?></td>
                                                <td class="px-4 py-3 text-right font-mono text-slate-200 text-xs"><?= safe_number_format($article['prix_unitaire_ht']) ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2 py-0.5 rounded-full text-xs <?= $article['actif'] === 'Oui' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' ?>">
                                                        <?= $article['actif'] === 'Oui' ? 'Actif' : 'Inactif' ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                                    <button onclick='openModal("edit", <?= json_encode($article) ?>)' class="p-1.5 text-blue-400 hover:bg-blue-500/20 rounded" title="Modifier"><i class="fas fa-edit"></i></button>
                                                    <button onclick='confirmDelete(<?= $article["id"] ?>, "<?= htmlspecialchars(addslashes($article["designation"])) ?>")' class="p-1.5 text-red-400 hover:bg-red-500/20 rounded" title="Supprimer"><i class="fas fa-trash"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- Vue Vente : tableau plat avec colonnes vente -->
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-700/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Référence</th>
                                    <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Désignation</th>
                                    <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Client</th>
                                    <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Type</th>
                                    <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Taxe défaut</th>
                                    <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Compte produit</th>
                                    <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Unité</th>
                                    <th class="px-4 py-3 text-right text-xs text-slate-400 uppercase">Prix vente HT</th>
                                    <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Usage</th>
                                    <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Statut</th>
                                    <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php foreach ($articles as $article): ?>
                                    <?php $taxeColors = ['TVA'=>'text-green-400','PPSSI'=>'text-amber-400','BNC'=>'text-orange-400','Aucune'=>'text-slate-500']; ?>
                                    <tr class="hover:bg-slate-700/30 transition-colors">
                                        <td class="px-4 py-3 font-mono text-emerald-400 text-xs"><?= htmlspecialchars($article['reference']) ?></td>
                                        <td class="px-4 py-3">
                                            <div class="text-slate-200"><?= htmlspecialchars($article['designation']) ?></div>
                                            <?php if ($article['description']): ?>
                                                <div class="text-xs text-slate-500 truncate max-w-xs"><?= htmlspecialchars($article['description']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-300">
                                            <?= $article['client_nom'] ? htmlspecialchars($article['client_nom']) : '<span class="text-slate-600">-</span>' ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="px-2 py-0.5 rounded-full text-xs <?= $article['type_article'] === 'Bien' ? 'bg-green-500/20 text-green-400' : 'bg-purple-500/20 text-purple-400' ?>">
                                                <?= $article['type_article'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center text-xs <?= $taxeColors[$article['type_taxe_defaut']] ?? 'text-slate-500' ?>">
                                            <?= $article['type_taxe_defaut'] === 'Aucune' ? '-' : $article['type_taxe_defaut'] ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs font-mono text-slate-300">
                                            <?= $article['compte_vente'] ? htmlspecialchars($article['compte_vente']) : '<span class="text-slate-600">-</span>' ?>
                                        </td>
                                        <td class="px-4 py-3 text-center text-slate-400 text-xs"><?= htmlspecialchars($article['unite']) ?></td>
                                        <td class="px-4 py-3 text-right font-mono text-slate-200 text-xs"><?= safe_number_format($article['prix_vente_ht']) ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($article['usage_article'] === 'les_deux'): ?>
                                                <span class="px-2 py-0.5 rounded-full text-xs bg-indigo-500/20 text-indigo-400">Les deux</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full text-xs bg-emerald-500/20 text-emerald-400">Vente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="px-2 py-0.5 rounded-full text-xs <?= $article['actif'] === 'Oui' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' ?>">
                                                <?= $article['actif'] === 'Oui' ? 'Actif' : 'Inactif' ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center whitespace-nowrap">
                                            <button onclick='openModal("edit", <?= json_encode($article) ?>)' class="p-1.5 text-blue-400 hover:bg-blue-500/20 rounded" title="Modifier"><i class="fas fa-edit"></i></button>
                                            <button onclick='confirmDelete(<?= $article["id"] ?>, "<?= htmlspecialchars(addslashes($article["designation"])) ?>")' class="p-1.5 text-red-400 hover:bg-red-500/20 rounded" title="Supprimer"><i class="fas fa-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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

                    <!-- Ligne 1 : Fournisseur + Client + Référence -->
                    <div class="grid grid-cols-3 gap-4">
                        <div id="bloc_fournisseur">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Fournisseur</label>
                            <select name="id_fournisseur" id="id_fournisseur" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500" onchange="generateReference()">
                                <option value="">- Général -</option>
                                <?php foreach ($fournisseurs as $f): ?>
                                    <option value="<?= $f['id'] ?>" data-abrev="<?= htmlspecialchars($f['abreviation'] ?? strtoupper(substr($f['nom'], 0, 3))) ?>"><?= htmlspecialchars($f['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="bloc_client">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Client</label>
                            <select name="id_client" id="id_client" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-emerald-500" onchange="generateReference()">
                                <option value="">- Général -</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>" data-abrev="<?= htmlspecialchars(strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $c['nom']), 0, 3))) ?>"><?= htmlspecialchars($c['nom']) ?></option>
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

                    <!-- Désignation -->
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Désignation *</label>
                        <input type="text" name="designation" id="designation" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Description</label>
                        <textarea name="description" id="description" rows="2" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>

                    <!-- Ligne 2 : Type + Usage + Taxe par défaut + Unité -->
                    <div class="grid grid-cols-4 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Type *</label>
                            <select name="type_article" id="type_article" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                                <option value="Bien">Bien</option>
                                <option value="Service">Service</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Usage *</label>
                            <select name="usage_article" id="usage_article" required class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500" onchange="toggleUsageFields()">
                                <option value="achat">Achat</option>
                                <option value="vente">Vente</option>
                                <option value="les_deux">Les deux</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Taxe par défaut</label>
                            <select name="type_taxe_defaut" id="type_taxe_defaut" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                                <option value="Aucune">Aucune</option>
                                <option value="TVA">TVA 18%</option>
                                <option value="PPSSI">PPSSI 2%</option>
                                <option value="BNC">BNC 7.5%</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-400 mb-1">Unité *</label>
                            <input type="text" name="unite" id="unite" required value="unité" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500" list="unites">
                            <datalist id="unites">
                                <option value="unité"><option value="pcs"><option value="kg">
                                <option value="m"><option value="m²"><option value="m³">
                                <option value="litre"><option value="forfait">
                                <option value="heure"><option value="jour"><option value="mois">
                            </datalist>
                        </div>
                    </div>

                    <!-- Ligne 3 : Prix achat + Prix vente -->
                    <div class="grid grid-cols-2 gap-4">
                        <div id="bloc_prix_achat">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Prix achat HT</label>
                            <input type="text" name="prix_unitaire_ht" id="prix_unitaire_ht" value="0" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500 text-right">
                        </div>
                        <div id="bloc_prix_vente">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Prix vente HT</label>
                            <input type="text" name="prix_vente_ht" id="prix_vente_ht" value="0" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500 text-right">
                        </div>
                    </div>

                    <!-- Ligne 4 : Compte achat + Compte vente -->
                    <div class="grid grid-cols-2 gap-4">
                        <div id="bloc_compte_achat">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Compte de charge (achat)</label>
                            <select name="compte_achat" id="compte_achat" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                                <option value="">- Non défini -</option>
                                <?php foreach ($comptes_compta as $c): if (substr($c['compte'], 0, 1) !== '6') continue; ?>
                                    <option value="<?= $c['compte'] ?>"><?= $c['compte'] ?> - <?= htmlspecialchars(substr($c['intitule_compte'], 0, 40)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="bloc_compte_vente">
                            <label class="block text-sm font-medium text-slate-400 mb-1">Compte de produit (vente)</label>
                            <select name="compte_vente" id="compte_vente" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100 focus:ring-2 focus:ring-blue-500">
                                <option value="">- Non défini -</option>
                                <?php foreach ($comptes_compta as $c): if (substr($c['compte'], 0, 1) !== '7') continue; ?>
                                    <option value="<?= $c['compte'] ?>"><?= $c['compte'] ?> - <?= htmlspecialchars(substr($c['intitule_compte'], 0, 40)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Statut -->
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
        const CURRENT_TAB = '<?= $tab ?>';

        function toggleUsageFields() {
            const usage    = document.getElementById('usage_article').value;
            const isAchat  = usage === 'achat'    || usage === 'les_deux';
            const isVente  = usage === 'vente'    || usage === 'les_deux';

            // Tier : afficher/masquer Fournisseur ou Client
            document.getElementById('bloc_fournisseur').style.display = isAchat ? '' : 'none';
            document.getElementById('bloc_client').style.display      = isVente ? '' : 'none';

            // Prix achat + compte charge
            document.getElementById('bloc_prix_achat').style.opacity   = isAchat ? '1' : '0.3';
            document.getElementById('bloc_compte_achat').style.opacity = isAchat ? '1' : '0.3';

            // Prix vente + compte produit
            document.getElementById('bloc_prix_vente').style.opacity   = isVente ? '1' : '0.3';
            document.getElementById('bloc_compte_vente').style.opacity = isVente ? '1' : '0.3';
        }

        function openModal(action, data = null) {
            document.getElementById('modal').classList.remove('hidden');
            document.getElementById('formAction').value = action;

            if (action === 'add') {
                document.getElementById('modalTitle').textContent = 'Nouvel article au catalogue';
                document.getElementById('articleForm').reset();
                document.getElementById('articleId').value = '';
                document.getElementById('unite').value = 'unité';
                document.getElementById('prix_unitaire_ht').value = '0';
                document.getElementById('prix_vente_ht').value = '0';
                // Pré-sélectionner l'usage selon l'onglet actif
                document.getElementById('usage_article').value = CURRENT_TAB === 'vente' ? 'vente' : 'achat';
                toggleUsageFields();
            } else if (action === 'edit' && data) {
                document.getElementById('modalTitle').textContent = 'Modifier l\'article';
                document.getElementById('articleId').value        = data.id;
                document.getElementById('id_fournisseur').value   = data.id_fournisseur || '';
                document.getElementById('id_client').value        = data.id_client || '';
                document.getElementById('reference').value        = data.reference;
                document.getElementById('designation').value      = data.designation;
                document.getElementById('description').value      = data.description || '';
                document.getElementById('type_article').value     = data.type_article;
                document.getElementById('usage_article').value    = data.usage_article || 'achat';
                document.getElementById('type_taxe_defaut').value = data.type_taxe_defaut || 'Aucune';
                document.getElementById('unite').value            = data.unite;
                document.getElementById('prix_unitaire_ht').value = parseFloat(data.prix_unitaire_ht || 0).toLocaleString('fr-FR');
                document.getElementById('prix_vente_ht').value    = parseFloat(data.prix_vente_ht || 0).toLocaleString('fr-FR');
                document.getElementById('compte_achat').value     = data.compte_achat || '';
                document.getElementById('compte_vente').value     = data.compte_vente || '';
                document.getElementById('actif').value            = data.actif;
                toggleUsageFields();
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
        const clientArticleCounts = <?= json_encode(
            array_reduce($articles, function($acc, $art) {
                $cid = $art['id_client'];
                if ($cid) $acc[$cid] = ($acc[$cid] ?? 0) + 1;
                return $acc;
            }, [])
        ) ?>;
        const venteGeneralCount = <?= count(array_filter($articles, fn($a) => empty($a['id_client']))) ?>;

        function generateReference() {
            const usage     = document.getElementById('usage_article').value;
            const refInput  = document.getElementById('reference');
            const formAction = document.getElementById('formAction').value;

            // Ne pas écraser une référence existante en mode édition
            if (formAction === 'edit' && refInput.value) return;

            const useVente = usage === 'vente' || (usage === 'les_deux' && CURRENT_TAB === 'vente');

            if (!useVente) {
                // Achat : basé sur fournisseur
                const sel = document.getElementById('id_fournisseur');
                if (!sel.value) { refInput.value = ''; return; }
                const abrev = sel.options[sel.selectedIndex].dataset.abrev || 'ART';
                const count = (articleCounts[sel.value] || 0) + 1;
                refInput.value = abrev.toUpperCase() + '-' + String(count).padStart(4, '0');
            } else {
                // Vente : basé sur client
                const sel = document.getElementById('id_client');
                if (!sel.value) {
                    const count = venteGeneralCount + 1;
                    refInput.value = 'VTE-' + String(count).padStart(4, '0');
                } else {
                    const abrev = sel.options[sel.selectedIndex].dataset.abrev || 'CLI';
                    const count = (clientArticleCounts[sel.value] || 0) + 1;
                    refInput.value = abrev.toUpperCase() + '-' + String(count).padStart(4, '0');
                }
            }
        }
    </script>
</body>
</html>
