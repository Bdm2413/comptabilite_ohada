<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function safe_number_format($number, $decimals = 0) {
    return number_format($number ?: 0, $decimals, ',', ' ');
}

// Récupérer les fournisseurs
$stmtFournisseurs = $db->prepare("SELECT id, nom FROM plan_tiers WHERE type = 'Fournisseur' AND actif = 1 AND societe_id = ? ORDER BY nom");
$stmtFournisseurs->execute([$societe_id]);
$fournisseurs = $stmtFournisseurs->fetchAll();

// Traitement suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        try {
            $stmtCheck = $db->prepare("SELECT COUNT(*) as nb FROM receptions_bc WHERE id_bon_commande = ?");
            $stmtCheck->execute([$_POST['id']]);
            if ($stmtCheck->fetch()['nb'] > 0) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Impossible de supprimer : ce BC a des réceptions associées'];
            } else {
                $db->prepare("DELETE FROM bons_commande WHERE id = ? AND societe_id = ?")->execute([$_POST['id'], $societe_id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bon de commande supprimé'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
        header('Location: bons_commande.php');
        exit;
    } elseif ($_POST['action'] === 'valider') {
        try {
            $stmt = $db->prepare("UPDATE bons_commande SET statut = 'Validé', date_validation = ?, valide_par = ? WHERE id = ? AND statut = 'Brouillon' AND societe_id = ?");
            $stmt->execute([date('Y-m-d'), $_POST['valide_par'] ?? null, $_POST['id'], $societe_id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bon de commande validé'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
        header('Location: bons_commande.php');
        exit;
    }
}

// Filtres
$filterFournisseur = $_GET['fournisseur'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$search = $_GET['search'] ?? '';

// Vérifier si la colonne id_bon_commande existe dans la table ecritures
$bcColumnExists = false;
try {
    $checkColumn = $db->query("SHOW COLUMNS FROM ecritures LIKE 'id_bon_commande'");
    $bcColumnExists = $checkColumn->rowCount() > 0;
} catch (Exception $e) {
    $bcColumnExists = false;
}

// Construire la requête avec calcul de consommation basé sur les écritures comptables
// La consommation est basée sur les crédits des comptes 4011xxx (Fournisseurs) et 4812xxx (Fournisseurs d'immobilisations)
if ($bcColumnExists) {
    $sql = "
        SELECT bc.*, pt.nom as fournisseur_nom, d.numero_devis,
               (SELECT COUNT(*) FROM lignes_bon_commande WHERE id_bon_commande = bc.id) as nb_lignes,
               (SELECT COUNT(*) FROM receptions_bc WHERE id_bon_commande = bc.id) as nb_receptions,
               COALESCE((
                   SELECT SUM(le.credit)
                   FROM lignes_ecriture le
                   INNER JOIN ecritures e ON le.id_ecriture = e.id
                   WHERE e.id_bon_commande = bc.id
                     AND e.statut = 'Validé'
                     AND (le.compte LIKE '4011%' OR le.compte LIKE '4812%')
                     AND le.credit > 0
               ), 0) as montant_consomme_calcule,
               ROUND(COALESCE((
                   SELECT SUM(le.credit)
                   FROM lignes_ecriture le
                   INNER JOIN ecritures e ON le.id_ecriture = e.id
                   WHERE e.id_bon_commande = bc.id
                     AND e.statut = 'Validé'
                     AND (le.compte LIKE '4011%' OR le.compte LIKE '4812%')
                     AND le.credit > 0
               ), 0) / NULLIF(bc.montant_ttc, 0) * 100, 1) as pourcentage_consomme_calcule
        FROM bons_commande bc
        JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
        LEFT JOIN devis_fournisseurs d ON bc.id_devis = d.id
        WHERE bc.societe_id = ?
    ";
} else {
    $sql = "
        SELECT bc.*, pt.nom as fournisseur_nom, d.numero_devis,
               (SELECT COUNT(*) FROM lignes_bon_commande WHERE id_bon_commande = bc.id) as nb_lignes,
               (SELECT COUNT(*) FROM receptions_bc WHERE id_bon_commande = bc.id) as nb_receptions,
               0 as montant_consomme_calcule,
               0 as pourcentage_consomme_calcule
        FROM bons_commande bc
        JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
        LEFT JOIN devis_fournisseurs d ON bc.id_devis = d.id
        WHERE bc.societe_id = ?
    ";
}
$params = [$societe_id];

if (!empty($filterFournisseur)) {
    $sql .= " AND bc.id_fournisseur = ?";
    $params[] = $filterFournisseur;
}
if (!empty($filterStatut)) {
    $sql .= " AND bc.statut = ?";
    $params[] = $filterStatut;
}
if (!empty($search)) {
    $sql .= " AND (bc.numero_bc LIKE ? OR bc.objet LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY pt.nom, bc.date_bc DESC, bc.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bcList = $stmt->fetchAll();

// Regrouper les BC par fournisseur et calculer les totaux
$bcParFournisseur = [];
foreach ($bcList as &$bc) {
    // Utiliser les valeurs calculées depuis les écritures comptables
    $bc['montant_consomme'] = floatval($bc['montant_consomme_calcule'] ?? 0);
    $bc['pourcentage_consomme'] = floatval($bc['pourcentage_consomme_calcule'] ?? 0);
    $bc['montant_reste'] = floatval($bc['montant_ttc'] ?? 0) - $bc['montant_consomme'];

    $fournisseurId = $bc['id_fournisseur'];
    if (!isset($bcParFournisseur[$fournisseurId])) {
        $bcParFournisseur[$fournisseurId] = [
            'nom' => $bc['fournisseur_nom'],
            'bcs' => [],
            'total' => 0,
            'reste' => 0
        ];
    }
    $bcParFournisseur[$fournisseurId]['bcs'][] = $bc;
    $bcParFournisseur[$fournisseurId]['total'] += floatval($bc['montant_ttc'] ?? 0);
    $bcParFournisseur[$fournisseurId]['reste'] += $bc['montant_reste'];
}
unset($bc); // Libérer la référence

$pageTitle = "Bons de Commande";
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
                        <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                            <i class="fas fa-file-alt mr-3"></i><?= $pageTitle ?>
                        </h1>
                        <p class="text-slate-400">Suivez vos bons de commande et leur consommation</p>
                    </div>
                    <a href="bc_form.php" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2">
                        <i class="fas fa-plus"></i>Nouveau BC
                    </a>
                </div>
            </div>

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
                            <option value="Brouillon" <?= $filterStatut === 'Brouillon' ? 'selected' : '' ?>>Brouillon</option>
                            <option value="Validé" <?= $filterStatut === 'Validé' ? 'selected' : '' ?>>Validé</option>
                            <option value="En cours" <?= $filterStatut === 'En cours' ? 'selected' : '' ?>>En cours</option>
                            <option value="Partiellement livré" <?= $filterStatut === 'Partiellement livré' ? 'selected' : '' ?>>Part. livré</option>
                            <option value="Livré" <?= $filterStatut === 'Livré' ? 'selected' : '' ?>>Livré</option>
                            <option value="Clôturé" <?= $filterStatut === 'Clôturé' ? 'selected' : '' ?>>Clôturé</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-slate-400 mb-1">Recherche</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="N° BC, objet..." class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="bons_commande.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </form>
            </div>

            <!-- BC groupés par fournisseur -->
            <?php if (empty($bcList)): ?>
                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-8 text-center text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>Aucun bon de commande</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($bcParFournisseur as $fournisseurId => $groupe): ?>
                        <div class="bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
                            <!-- En-tête fournisseur -->
                            <button onclick="toggleFournisseur(<?= $fournisseurId ?>)" class="w-full px-4 py-3 bg-slate-700/50 hover:bg-slate-700/70 flex items-center justify-between transition-colors">
                                <div class="flex items-center gap-3">
                                    <i id="icon-<?= $fournisseurId ?>" class="fas fa-chevron-down text-slate-400 transition-transform"></i>
                                    <span class="font-semibold text-slate-200"><?= htmlspecialchars($groupe['nom']) ?></span>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span class="px-3 py-1 bg-purple-500/20 text-purple-400 rounded-full text-sm font-medium">
                                        <?= count($groupe['bcs']) ?> BC
                                    </span>
                                    <div class="text-right text-sm">
                                        <div class="text-slate-400 font-mono">Total: <?= safe_number_format($groupe['total']) ?></div>
                                        <div class="text-xs text-slate-500">Reste: <?= safe_number_format($groupe['reste']) ?></div>
                                    </div>
                                </div>
                            </button>

                            <!-- Table des BC du fournisseur -->
                            <div id="bc-<?= $fournisseurId ?>" class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-700/30">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">N° BC</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-400 uppercase">Objet</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-slate-400 uppercase">Montant</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Consommé</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Statut</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-slate-400 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700">
                                        <?php foreach ($groupe['bcs'] as $bc): ?>
                                            <?php
                                            $pourcent = floatval($bc['pourcentage_consomme'] ?? 0);
                                            $alerteClass = '';
                                            if ($pourcent >= $bc['seuil_alerte']) {
                                                $alerteClass = 'text-red-400';
                                            } elseif ($pourcent >= $bc['seuil_alerte'] - 10) {
                                                $alerteClass = 'text-amber-400';
                                            }
                                            ?>
                                            <tr class="hover:bg-slate-700/30">
                                                <td class="px-4 py-3">
                                                    <a href="bc_form.php?id=<?= $bc['id'] ?>" class="font-mono text-purple-400 hover:text-purple-300"><?= htmlspecialchars($bc['numero_bc']) ?></a>
                                                    <?php if ($bc['numero_devis']): ?>
                                                        <div class="text-xs text-slate-500">Devis: <?= htmlspecialchars($bc['numero_devis']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-200 max-w-xs truncate"><?= htmlspecialchars($bc['objet']) ?></td>
                                                <td class="px-4 py-3 text-right">
                                                    <div class="font-mono text-slate-200"><?= safe_number_format($bc['montant_ttc']) ?></div>
                                                    <div class="text-xs text-slate-500">Reste: <?= safe_number_format($bc['montant_reste']) ?></div>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <div class="w-full bg-slate-700 rounded-full h-2 mb-1">
                                                        <div class="h-2 rounded-full <?= $pourcent >= 100 ? 'bg-green-500' : ($pourcent >= $bc['seuil_alerte'] ? 'bg-red-500' : 'bg-purple-500') ?>" style="width: <?= min($pourcent, 100) ?>%"></div>
                                                    </div>
                                                    <span class="text-xs <?= $alerteClass ?>"><?= number_format($pourcent, 0) ?>%</span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <?php
                                                    $statusColors = [
                                                        'Brouillon' => 'bg-slate-500/20 text-slate-400',
                                                        'Validé' => 'bg-blue-500/20 text-blue-400',
                                                        'En cours' => 'bg-purple-500/20 text-purple-400',
                                                        'Partiellement livré' => 'bg-amber-500/20 text-amber-400',
                                                        'Livré' => 'bg-green-500/20 text-green-400',
                                                        'Clôturé' => 'bg-slate-500/20 text-slate-400',
                                                        'Annulé' => 'bg-red-500/20 text-red-400'
                                                    ];
                                                    ?>
                                                    <span class="px-2 py-1 rounded-full text-xs <?= $statusColors[$bc['statut']] ?? '' ?>"><?= $bc['statut'] ?></span>
                                                    <?php if (!empty($bc['valide_par']) || !empty($bc['date_validation'])): ?>
                                                        <div class="text-xs text-slate-500 mt-1">
                                                            <?php if (!empty($bc['valide_par'])): ?>
                                                                par <?= htmlspecialchars($bc['valide_par']) ?>
                                                            <?php endif; ?>
                                                            <?php if (!empty($bc['date_validation'])): ?>
                                                                <br>le <?= date('d/m/Y', strtotime($bc['date_validation'])) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <div class="flex items-center justify-center gap-1">
                                                        <a href="bc_form.php?id=<?= $bc['id'] ?>" class="p-1.5 text-blue-400 hover:bg-blue-500/20 rounded" title="Voir"><i class="fas fa-eye"></i></a>
                                                        <?php if ($bc['statut'] === 'Brouillon'): ?>
                                                            <button onclick="openValidateModal(<?= $bc['id'] ?>)" class="p-1.5 text-green-400 hover:bg-green-500/20 rounded" title="Valider"><i class="fas fa-check"></i></button>
                                                            <button onclick="confirmDelete(<?= $bc['id'] ?>, '<?= htmlspecialchars(addslashes($bc['numero_bc'])) ?>')" class="p-1.5 text-red-400 hover:bg-red-500/20 rounded" title="Supprimer"><i class="fas fa-trash"></i></button>
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

    <!-- Modal validation -->
    <div id="validateModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/70" onclick="closeValidateModal()"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4">
            <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md relative">
                <div class="p-6 border-b border-slate-700"><h3 class="text-lg font-semibold text-white">Valider le bon de commande</h3></div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="valider">
                    <input type="hidden" name="id" id="validateBcId">
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-1">Validé par</label>
                        <input type="text" name="valide_par" class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-100" placeholder="Nom">
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeValidateModal()" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg">Annuler</button>
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Valider</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" class="hidden">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script>
        function openValidateModal(id) {
            document.getElementById('validateModal').classList.remove('hidden');
            document.getElementById('validateBcId').value = id;
        }
        function closeValidateModal() { document.getElementById('validateModal').classList.add('hidden'); }
        function confirmDelete(id, numero) {
            if (confirm('Supprimer le BC "' + numero + '" ?')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeValidateModal(); });

        // Toggle fournisseur section
        function toggleFournisseur(id) {
            const content = document.getElementById('bc-' + id);
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
