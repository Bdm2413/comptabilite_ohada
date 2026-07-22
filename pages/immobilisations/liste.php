<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Créer les tables si elles n'existent pas
$db->exec("
    CREATE TABLE IF NOT EXISTS immobilisations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        reference VARCHAR(50),
        designation VARCHAR(255) NOT NULL,
        categorie VARCHAR(50) NOT NULL,
        compte_immobilisation VARCHAR(20) NOT NULL,
        compte_amortissement VARCHAR(20),
        compte_dotation VARCHAR(20),
        date_acquisition DATE NOT NULL,
        valeur_brute DECIMAL(15,2) NOT NULL,
        valeur_residuelle DECIMAL(15,2) DEFAULT 0,
        duree_amortissement INT DEFAULT NULL,
        amortissable TINYINT(1) DEFAULT 1,
        statut ENUM('en_service','cede','rebute') DEFAULT 'en_service',
        date_cession DATE DEFAULT NULL,
        valeur_cession DECIMAL(15,2) DEFAULT NULL,
        fournisseur VARCHAR(255),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_societe (societe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$db->exec("
    CREATE TABLE IF NOT EXISTS dotations_amortissement (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        immobilisation_id INT NOT NULL,
        exercice YEAR NOT NULL,
        date_dotation DATE NOT NULL,
        montant DECIMAL(15,2) NOT NULL,
        id_ecriture INT DEFAULT NULL,
        statut ENUM('comptabilise') DEFAULT 'comptabilise',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_immo_exercice (immobilisation_id, exercice),
        INDEX idx_societe (societe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$message = '';
$messageType = '';

// Migrations : ajout des colonnes si elles n'existent pas encore
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN periodicite ENUM('annuelle','mensuelle') DEFAULT 'annuelle'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement ADD COLUMN mois TINYINT DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement DROP INDEX unique_immo_exercice"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement ADD UNIQUE KEY unique_immo_exercice_mois (immobilisation_id, exercice, mois)"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN parent_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN id_ecriture_cession INT NULL"); } catch (Exception $e) {}

// Catégories
$categories = [
    'incorporelle'        => 'Immobilisation incorporelle',
    'terrain'             => 'Terrain',
    'batiment'            => 'Bâtiment',
    'amenagement'         => 'Aménagement & installation',
    'materiel_mobilier'   => 'Matériel & mobilier',
    'materiel_transport'  => 'Matériel de transport',
    'materiel_info'       => 'Matériel informatique',
    'financiere'          => 'Immobilisation financière',
    'autre'               => 'Autre',
];
$non_amortissables = ['terrain', 'financiere'];

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $designation        = cleanInput($_POST['designation'] ?? '');
        $reference          = cleanInput($_POST['reference'] ?? '');
        $categorie          = cleanInput($_POST['categorie'] ?? '');
        $compte_immo        = cleanInput($_POST['compte_immobilisation'] ?? '');
        $compte_amort       = cleanInput($_POST['compte_amortissement'] ?? '');
        $compte_dotation    = cleanInput($_POST['compte_dotation'] ?? '');
        $date_acquisition   = cleanInput($_POST['date_acquisition'] ?? '');
        $valeur_brute       = (float)($_POST['valeur_brute'] ?? 0);
        $valeur_residuelle  = (float)($_POST['valeur_residuelle'] ?? 0);
        $duree              = (int)($_POST['duree_amortissement'] ?? 0);
        $amortissable       = in_array($categorie, $non_amortissables) ? 0 : 1;
        $periodicite        = in_array($_POST['periodicite'] ?? '', ['annuelle','mensuelle']) ? $_POST['periodicite'] : 'annuelle';
        $fournisseur        = cleanInput($_POST['fournisseur'] ?? '');
        $notes              = cleanInput($_POST['notes'] ?? '');
        $parent_id          = (int)($_POST['parent_id'] ?? 0) ?: null;

        if (!$designation || !$compte_immo || !$date_acquisition || $valeur_brute <= 0) {
            $message = 'Veuillez remplir tous les champs obligatoires.';
            $messageType = 'error';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("
                        INSERT INTO immobilisations
                        (societe_id, reference, designation, categorie, compte_immobilisation, compte_amortissement, compte_dotation,
                         date_acquisition, valeur_brute, valeur_residuelle, duree_amortissement, amortissable, periodicite, fournisseur, notes, parent_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$societe_id, $reference, $designation, $categorie, $compte_immo,
                        $compte_amort ?: null, $compte_dotation ?: null, $date_acquisition,
                        $valeur_brute, $valeur_residuelle, $amortissable ? ($duree ?: null) : null,
                        $amortissable, $amortissable ? $periodicite : 'annuelle', $fournisseur ?: null, $notes ?: null, $parent_id]);
                    $message = 'Immobilisation ajoutée avec succès.';
                } else {
                    $id = (int)$_POST['id'];
                    $stmt = $db->prepare("
                        UPDATE immobilisations SET
                        reference=?, designation=?, categorie=?, compte_immobilisation=?, compte_amortissement=?,
                        compte_dotation=?, date_acquisition=?, valeur_brute=?, valeur_residuelle=?,
                        duree_amortissement=?, amortissable=?, periodicite=?, fournisseur=?, notes=?, parent_id=?
                        WHERE id=? AND societe_id=?
                    ");
                    $stmt->execute([$reference, $designation, $categorie, $compte_immo,
                        $compte_amort ?: null, $compte_dotation ?: null, $date_acquisition,
                        $valeur_brute, $valeur_residuelle, $amortissable ? ($duree ?: null) : null,
                        $amortissable, $amortissable ? $periodicite : 'annuelle', $fournisseur ?: null, $notes ?: null, $parent_id, $id, $societe_id]);
                    $message = 'Immobilisation modifiée avec succès.';
                }
                $messageType = 'success';
                logActivity($action === 'add' ? 'Ajout immobilisation' : 'Modification immobilisation', 'immobilisations', $db->lastInsertId(), $designation);
            } catch (Exception $e) {
                $message = 'Erreur: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Vérifier qu'aucune dotation comptabilisée n'existe
        $stmt = $db->prepare("SELECT COUNT(*) FROM dotations_amortissement WHERE immobilisation_id=? AND societe_id=?");
        $stmt->execute([$id, $societe_id]);
        if ($stmt->fetchColumn() > 0) {
            $message = 'Impossible de supprimer : des dotations comptabilisées existent pour cette immobilisation.';
            $messageType = 'error';
        } else {
            $db->prepare("DELETE FROM immobilisations WHERE id=? AND societe_id=?")->execute([$id, $societe_id]);
            $message = 'Immobilisation supprimée.';
            $messageType = 'success';
        }
    }

    if ($action === 'change_statut') {
        $id     = (int)$_POST['id'];
        $statut = cleanInput($_POST['statut']);
        $date_cession    = cleanInput($_POST['date_cession'] ?? '');
        $valeur_cession  = (float)($_POST['valeur_cession'] ?? 0);
        $stmt = $db->prepare("UPDATE immobilisations SET statut=?, date_cession=?, valeur_cession=? WHERE id=? AND societe_id=?");
        $stmt->execute([$statut, $date_cession ?: null, $valeur_cession ?: null, $id, $societe_id]);
        $message = 'Statut mis à jour.';
        $messageType = 'success';
    }
}

// Récupérer les immobilisations avec amortissements cumulés
$stmt = $db->prepare("
    SELECT i.*,
           COALESCE(SUM(d.montant), 0) as amort_cumul_comptabilise
    FROM immobilisations i
    LEFT JOIN dotations_amortissement d ON i.id = d.immobilisation_id
    WHERE i.societe_id = ?
    GROUP BY i.id
    ORDER BY COALESCE(i.parent_id, i.id), i.parent_id IS NOT NULL, i.date_acquisition, i.designation
");
$stmt->execute([$societe_id]);
$immobilisations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construire l'arbre parents / composants
$immobilisations_map = array_column($immobilisations, null, 'id');
$composants_par_parent = [];
foreach ($immobilisations as $immo) {
    if ($immo['parent_id']) {
        $composants_par_parent[$immo['parent_id']][] = $immo;
    }
}
// Liste aplatie ordonnée : parent → ses composants → prochain parent
$immobilisations_ordonnees = [];
foreach ($immobilisations as $immo) {
    if ($immo['parent_id']) continue; // les composants seront insérés après leur parent
    $immobilisations_ordonnees[] = $immo;
    foreach ($composants_par_parent[$immo['id']] ?? [] as $composant) {
        $immobilisations_ordonnees[] = $composant;
    }
}

// Parents disponibles pour le select "Composant de"
$parents_possibles = array_filter($immobilisations, fn($i) => !$i['parent_id']);

// Comptes disponibles pour les selects
$comptes_immo = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE societe_id=? AND actif='Oui' AND LEFT(compte,1)='2' AND tableau='Bilan' ORDER BY compte");
$comptes_immo->execute([$societe_id]);
$comptes_immo = $comptes_immo->fetchAll(PDO::FETCH_ASSOC);

$comptes_amort = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE societe_id=? AND actif='Oui' AND LEFT(compte,2) IN ('28','29') ORDER BY compte");
$comptes_amort->execute([$societe_id]);
$comptes_amort = $comptes_amort->fetchAll(PDO::FETCH_ASSOC);

$comptes_dot = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE societe_id=? AND actif='Oui' AND LEFT(compte,2) IN ('68','69') ORDER BY compte");
$comptes_dot->execute([$societe_id]);
$comptes_dot = $comptes_dot->fetchAll(PDO::FETCH_ASSOC);

// Stats sur les éléments principaux uniquement (excluant les composants pour éviter les doubles)
$elements_principaux = array_filter($immobilisations, fn($i) => !$i['parent_id']);
$total_brut  = array_sum(array_column($elements_principaux, 'valeur_brute'));
$total_amort = array_sum(array_column($elements_principaux, 'amort_cumul_comptabilise'));
$total_vnc   = $total_brut - $total_amort;
$nb_actifs   = count(array_filter($elements_principaux, fn($i) => $i['statut'] === 'en_service'));
$filtre = $_GET['statut'] ?? 'tous';
$preselect_parent = (int)($_GET['parent_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Immobilisations - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
    <?php include '../../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <!-- Header -->
        <header class="bg-slate-800/30 border-b border-slate-700/50 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-amber-400 to-orange-500 flex items-center gap-2">
                        <i class="fas fa-building text-amber-400"></i>
                        Gestion des Immobilisations
                    </h1>
                    <p class="text-slate-400 text-sm mt-1">Registre des actifs immobilisés et amortissements</p>
                </div>
                <button onclick="openAddModal()" class="bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white px-6 py-2.5 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                    <i class="fas fa-plus"></i> Nouvelle immobilisation
                </button>
            </div>
        </header>

        <div class="p-6 space-y-6">

            <?php if ($message): ?>
            <div class="p-3 rounded-lg <?= $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/40 text-emerald-400' : 'bg-red-500/10 border border-red-500/40 text-red-400' ?>">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <!-- Cartes statistiques -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <p class="text-xs text-slate-400 mb-1">En service</p>
                    <p class="text-2xl font-bold text-white"><?= $nb_actifs ?></p>
                    <p class="text-xs text-slate-500 mt-1">immobilisation(s)</p>
                </div>
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <p class="text-xs text-slate-400 mb-1">Valeur brute totale</p>
                    <p class="text-xl font-bold text-blue-400"><?= number_format($total_brut, 0, ',', ' ') ?></p>
                    <p class="text-xs text-slate-500 mt-1">FCFA</p>
                </div>
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <p class="text-xs text-slate-400 mb-1">Amort. comptabilisés</p>
                    <p class="text-xl font-bold text-red-400"><?= number_format($total_amort, 0, ',', ' ') ?></p>
                    <p class="text-xs text-slate-500 mt-1">FCFA</p>
                </div>
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <p class="text-xs text-slate-400 mb-1">Valeur nette comptable</p>
                    <p class="text-xl font-bold text-emerald-400"><?= number_format($total_vnc, 0, ',', ' ') ?></p>
                    <p class="text-xs text-slate-500 mt-1">FCFA</p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex gap-2">
                <?php foreach (['tous' => 'Tous', 'en_service' => 'En service', 'cede' => 'Cédés', 'rebute' => 'Mis au rebut'] as $k => $v): ?>
                <a href="?statut=<?= $k ?>" class="px-3 py-1.5 rounded-lg text-xs transition <?= $filtre === $k ? 'bg-amber-500/20 border border-amber-500/50 text-amber-300' : 'bg-slate-800 border border-slate-700 text-slate-400 hover:text-slate-200' ?>">
                    <?= $v ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Tableau -->
            <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/50">
                        <tr class="border-b border-slate-700">
                            <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Réf / Désignation</th>
                            <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Catégorie</th>
                            <th class="px-4 py-3 text-left text-xs text-slate-400 uppercase">Date acq.</th>
                            <th class="px-4 py-3 text-right text-xs text-slate-400 uppercase">Valeur brute</th>
                            <th class="px-4 py-3 text-right text-xs text-slate-400 uppercase">Amort. cumulé</th>
                            <th class="px-4 py-3 text-right text-xs text-slate-400 uppercase">VNC</th>
                            <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Statut</th>
                            <th class="px-4 py-3 text-center text-xs text-slate-400 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                    <?php
                    $filtered = array_filter($immobilisations_ordonnees, fn($i) => $filtre === 'tous' || $i['statut'] === $filtre
                        // Si on filtre par statut, inclure quand même le parent si l'un de ses composants correspond
                        || (!$i['parent_id'] && !empty(array_filter($composants_par_parent[$i['id']] ?? [], fn($c) => $c['statut'] === $filtre)))
                    );
                    if (empty($filtered)):
                    ?>
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                                <i class="fas fa-building text-3xl mb-2 block text-slate-600"></i>
                                Aucune immobilisation trouvée
                            </td>
                        </tr>
                    <?php else:
                    $statusColors = ['en_service' => 'text-emerald-400 bg-emerald-500/10', 'cede' => 'text-amber-400 bg-amber-500/10', 'rebute' => 'text-red-400 bg-red-500/10'];
                    $statusLabels = ['en_service' => 'En service', 'cede' => 'Cédé', 'rebute' => 'Mis au rebut'];
                    foreach ($filtered as $immo):
                        $vnc = $immo['valeur_brute'] - $immo['amort_cumul_comptabilise'];
                        $isComposant = (bool)$immo['parent_id'];
                        $nbComposants = count($composants_par_parent[$immo['id']] ?? []);
                    ?>
                        <tr class="hover:bg-slate-700/30 transition-colors <?= $isComposant ? 'bg-slate-800/40' : '' ?>">
                            <td class="px-4 py-3">
                                <?php if ($isComposant): ?>
                                <span class="text-slate-600 mr-1 text-xs">↳</span>
                                <?php endif; ?>
                                <a href="voir.php?id=<?= $immo['id'] ?>" class="<?= $isComposant ? 'text-slate-300 text-sm' : 'text-white font-medium' ?> hover:text-amber-400 transition-colors">
                                    <?= htmlspecialchars($immo['designation']) ?>
                                </a>
                                <?php if ($isComposant): ?>
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-blue-500/10 border border-blue-500/20 text-blue-400">composant</span>
                                <?php elseif ($nbComposants > 0): ?>
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-purple-500/10 border border-purple-500/20 text-purple-400"><?= $nbComposants ?> composant<?= $nbComposants > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                                <?php if ($immo['reference']): ?>
                                <span class="block text-xs text-slate-500 font-mono <?= $isComposant ? 'ml-3' : '' ?>"><?= htmlspecialchars($immo['reference']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-xs"><?= $categories[$immo['categorie']] ?? $immo['categorie'] ?></td>
                            <td class="px-4 py-3 text-slate-300 font-mono text-xs"><?= date('d/m/Y', strtotime($immo['date_acquisition'])) ?></td>
                            <td class="px-4 py-3 text-right font-mono <?= $isComposant ? 'text-blue-300 text-xs' : 'text-blue-400' ?>"><?= number_format($immo['valeur_brute'], 0, ',', ' ') ?></td>
                            <td class="px-4 py-3 text-right font-mono <?= $isComposant ? 'text-red-300 text-xs' : 'text-red-400' ?>"><?= $immo['amort_cumul_comptabilise'] > 0 ? number_format($immo['amort_cumul_comptabilise'], 0, ',', ' ') : '-' ?></td>
                            <td class="px-4 py-3 text-right font-mono font-semibold <?= $isComposant ? 'text-emerald-300 text-xs' : 'text-emerald-400' ?>"><?= number_format(max(0, $vnc), 0, ',', ' ') ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 rounded text-xs <?= $statusColors[$immo['statut']] ?>">
                                    <?= $statusLabels[$immo['statut']] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <a href="voir.php?id=<?= $immo['id'] ?>" class="p-1.5 text-slate-400 hover:text-amber-400 hover:bg-amber-500/10 rounded transition" title="Voir détail">
                                        <i class="fas fa-eye text-xs"></i>
                                    </a>
                                    <button onclick="openEditModal(<?= htmlspecialchars(json_encode($immo)) ?>)" class="p-1.5 text-slate-400 hover:text-blue-400 hover:bg-blue-500/10 rounded transition" title="Modifier">
                                        <i class="fas fa-pencil text-xs"></i>
                                    </button>
                                    <?php if (!$isComposant): ?>
                                    <a href="?parent_id=<?= $immo['id'] ?>" onclick="event.preventDefault(); openAddModal(<?= $immo['id'] ?>)" class="p-1.5 text-slate-400 hover:text-purple-400 hover:bg-purple-500/10 rounded transition" title="Ajouter un composant">
                                        <i class="fas fa-puzzle-piece text-xs"></i>
                                    </a>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return confirm('Supprimer cette immobilisation ?')" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $immo['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded transition" title="Supprimer">
                                            <i class="fas fa-trash text-xs"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Modal Ajout / Modification -->
<div id="immoModal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-slate-800 rounded-xl border border-slate-700 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-5 border-b border-slate-700 flex items-center justify-between">
            <h2 class="text-lg font-bold text-white" id="modalTitle">Nouvelle immobilisation</h2>
            <button onclick="closeModal()" class="text-slate-400 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Désignation <span class="text-red-400">*</span></label>
                    <input type="text" name="designation" id="formDesignation" required class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Référence interne</label>
                    <input type="text" name="reference" id="formReference" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500 focus:border-transparent">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Catégorie <span class="text-red-400">*</span></label>
                    <select name="categorie" id="formCategorie" onchange="updateAmortissable(this.value)" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <?php foreach ($categories as $k => $v): ?>
                        <option value="<?= $k ?>"><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Date d'acquisition <span class="text-red-400">*</span></label>
                    <input type="date" name="date_acquisition" id="formDate" required class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Valeur brute (FCFA) <span class="text-red-400">*</span></label>
                    <input type="number" name="valeur_brute" id="formValeurBrute" step="1" min="1" required class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Valeur résiduelle (FCFA)</label>
                    <input type="number" name="valeur_residuelle" id="formValeurResiduelle" step="1" min="0" value="0" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <div id="amortZone">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Durée d'amortissement (années)</label>
                        <input type="number" name="duree_amortissement" id="formDuree" min="1" max="50" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500" placeholder="Ex: 5">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Périodicité des dotations</label>
                        <select name="periodicite" id="formPeriodicite" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                            <option value="annuelle">Annuelle (1 écriture / an)</option>
                            <option value="mensuelle">Mensuelle (1 écriture / mois)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Compte immobilisation</label>
                    <select name="compte_immobilisation" id="formCompteImmo" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <option value="">-- Choisir --</option>
                        <?php foreach ($comptes_immo as $c): ?>
                        <option value="<?= $c['compte'] ?>"><?= $c['compte'] ?> - <?= mb_substr($c['intitule_compte'], 0, 25) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="compteAmortZone">
                    <label class="block text-xs text-slate-400 mb-1">Compte amortissement (28x)</label>
                    <select name="compte_amortissement" id="formCompteAmort" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <option value="">-- Choisir --</option>
                        <?php foreach ($comptes_amort as $c): ?>
                        <option value="<?= $c['compte'] ?>"><?= $c['compte'] ?> - <?= mb_substr($c['intitule_compte'], 0, 20) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="compteDotZone">
                    <label class="block text-xs text-slate-400 mb-1">Compte dotation (68x)</label>
                    <select name="compte_dotation" id="formCompteDot" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <option value="">-- Choisir --</option>
                        <?php foreach ($comptes_dot as $c): ?>
                        <option value="<?= $c['compte'] ?>"><?= $c['compte'] ?> - <?= mb_substr($c['intitule_compte'], 0, 20) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Fournisseur</label>
                    <input type="text" name="fournisseur" id="formFournisseur" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Notes</label>
                    <input type="text" name="notes" id="formNotes" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <!-- Composant de -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">
                    <i class="fas fa-puzzle-piece mr-1 text-purple-400"></i>
                    Composant de <span class="text-slate-500">(optionnel — laisser vide si bien principal)</span>
                </label>
                <select name="parent_id" id="formParentId" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                    <option value="">— Bien principal (indépendant) —</option>
                    <?php foreach ($parents_possibles as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['designation']) ?><?= $p['reference'] ? ' (' . htmlspecialchars($p['reference']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">Annuler</button>
                <button type="submit" class="px-4 py-2 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-lg text-sm transition">
                    <i class="fas fa-save mr-1"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const nonAmortissables = <?= json_encode($non_amortissables) ?>;

function updateAmortissable(categorie) {
    const isAmort = !nonAmortissables.includes(categorie);
    document.getElementById('amortZone').style.display = isAmort ? '' : 'none';
    document.getElementById('compteAmortZone').style.display = isAmort ? '' : 'none';
    document.getElementById('compteDotZone').style.display = isAmort ? '' : 'none';
}

function openAddModal(parentId = null) {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('modalTitle').textContent = parentId ? 'Nouveau composant' : 'Nouvelle immobilisation';
    document.getElementById('formDesignation').value = '';
    document.getElementById('formReference').value = '';
    document.getElementById('formCategorie').value = 'materiel_mobilier';
    document.getElementById('formDate').value = '';
    document.getElementById('formValeurBrute').value = '';
    document.getElementById('formValeurResiduelle').value = '0';
    document.getElementById('formDuree').value = '';
    document.getElementById('formPeriodicite').value = 'annuelle';
    document.getElementById('formCompteImmo').value = '';
    document.getElementById('formCompteAmort').value = '';
    document.getElementById('formCompteDot').value = '';
    document.getElementById('formFournisseur').value = '';
    document.getElementById('formNotes').value = '';
    document.getElementById('formParentId').value = parentId || '';
    updateAmortissable('materiel_mobilier');
    document.getElementById('immoModal').classList.remove('hidden');
}

function openEditModal(immo) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = immo.id;
    document.getElementById('modalTitle').textContent = 'Modifier l\'immobilisation';
    document.getElementById('formDesignation').value = immo.designation;
    document.getElementById('formReference').value = immo.reference || '';
    document.getElementById('formCategorie').value = immo.categorie;
    document.getElementById('formDate').value = immo.date_acquisition;
    document.getElementById('formValeurBrute').value = immo.valeur_brute;
    document.getElementById('formValeurResiduelle').value = immo.valeur_residuelle;
    document.getElementById('formDuree').value = immo.duree_amortissement || '';
    document.getElementById('formPeriodicite').value = immo.periodicite || 'annuelle';
    document.getElementById('formCompteImmo').value = immo.compte_immobilisation || '';
    document.getElementById('formCompteAmort').value = immo.compte_amortissement || '';
    document.getElementById('formCompteDot').value = immo.compte_dotation || '';
    document.getElementById('formFournisseur').value = immo.fournisseur || '';
    document.getElementById('formNotes').value = immo.notes || '';
    document.getElementById('formParentId').value = immo.parent_id || '';
    updateAmortissable(immo.categorie);
    document.getElementById('immoModal').classList.remove('hidden');
}

// Auto-open modal si on vient de voir.php via "Ajouter un composant"
<?php if ($preselect_parent): ?>
document.addEventListener('DOMContentLoaded', () => openAddModal(<?= $preselect_parent ?>));
<?php endif; ?>

function closeModal() {
    document.getElementById('immoModal').classList.add('hidden');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
