<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Vérifier si les colonnes de lettrage existent dans lignes_ecriture
$lettrageParLigne = false;
try {
    $checkCol = $db->query("SHOW COLUMNS FROM lignes_ecriture LIKE 'lettrage'");
    $lettrageParLigne = $checkCol->rowCount() > 0;
} catch (Exception $e) {
    $lettrageParLigne = false;
}

// Traitement des actions de lettrage
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'lettrer') {
            // Lettrage manuel de LIGNES d'écritures sélectionnées
            $ligne_ids = $_POST['ligne_ids'] ?? [];

            if (count($ligne_ids) < 2) {
                throw new Exception("Veuillez sélectionner au moins 2 lignes à lettrer");
            }

            // Générer un code de lettrage unique
            $lettrage_code = strtoupper(substr(uniqid(), -6));

            $db->beginTransaction();

            // Vérifier les montants des LIGNES sélectionnées
            $placeholders = implode(',', array_fill(0, count($ligne_ids), '?'));
            $stmt = $db->prepare("
                SELECT le.id, le.id_ecriture, le.compte, le.debit, le.credit,
                       (le.debit - le.credit) as solde,
                       e.date_ecriture
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE le.id IN ({$placeholders})
                AND e.societe_id = ?
            ");
            $stmt->execute(array_merge($ligne_ids, [$societe_id]));
            $lignes = $stmt->fetchAll();

            $total_debits = 0;
            $total_credits = 0;

            foreach ($lignes as $ligne) {
                $total_debits += floatval($ligne['debit']);
                $total_credits += floatval($ligne['credit']);
            }

            // Déterminer le statut de lettrage
            $solde = $total_debits - $total_credits;
            $statut_lettrage = (abs($solde) < 0.01) ? 'Lettré' : 'Partiellement lettré';

            // Vérifier que les écritures ne sont pas dans un exercice clôturé
            $ecriture_ids_checked = [];
            foreach ($lignes as $ligne) {
                if (!in_array($ligne['id_ecriture'], $ecriture_ids_checked)) {
                    blockIfEcritureInClosedExercice($ligne['id_ecriture'], $db, 'lettrage.php?compte=' . ($_POST['compte'] ?? ''));
                    $ecriture_ids_checked[] = $ligne['id_ecriture'];
                }
            }

            if ($lettrageParLigne) {
                // Appliquer le code de lettrage aux LIGNES sélectionnées (nouvelle méthode)
                $updateStmt = $db->prepare("
                    UPDATE lignes_ecriture
                    SET lettrage = ?, statut_lettrage = ?
                    WHERE id = ?
                ");

                foreach ($ligne_ids as $id) {
                    $updateStmt->execute([$lettrage_code, $statut_lettrage, $id]);
                }
            } else {
                // Fallback: ancienne méthode (lettrage au niveau écriture)
                // ATTENTION: Cette méthode a le bug décrit - tous les comptes de l'écriture sont lettrés
                $ecriture_ids = array_unique(array_column($lignes, 'id_ecriture'));
                $updateStmt = $db->prepare("
                    UPDATE ecritures
                    SET lettrage = ?, statut_lettrage = ?
                    WHERE id = ?
                ");

                foreach ($ecriture_ids as $id) {
                    $updateStmt->execute([$lettrage_code, $statut_lettrage, $id]);
                }
            }

            $db->commit();

            $_SESSION['success'] = "Lettrage appliqué avec succès (Code: {$lettrage_code}) - Statut: {$statut_lettrage}";
            header('Location: lettrage.php?compte=' . ($_POST['compte'] ?? ''));
            exit;

        } elseif ($action === 'delettrer') {
            // Délettrage
            $lettrage_code = $_POST['lettrage_code'] ?? '';

            if (empty($lettrage_code)) {
                throw new Exception("Code de lettrage invalide");
            }

            $db->beginTransaction();

            if ($lettrageParLigne) {
                // Vérifier que les lignes avec ce code ne sont pas dans un exercice clôturé
                $stmtCheck = $db->prepare("
                    SELECT DISTINCT le.id_ecriture
                    FROM lignes_ecriture le
                    WHERE le.lettrage = ?
                ");
                $stmtCheck->execute([$lettrage_code]);
                $ecritures_to_check = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);

                foreach ($ecritures_to_check as $id) {
                    blockIfEcritureInClosedExercice($id, $db, 'lettrage.php?compte=' . ($_GET['compte'] ?? ''));
                }

                // Délettrer les lignes
                $stmt = $db->prepare("
                    UPDATE lignes_ecriture
                    SET lettrage = NULL, statut_lettrage = 'Non lettré'
                    WHERE lettrage = ?
                ");
                $stmt->execute([$lettrage_code]);
            } else {
                // Fallback: ancienne méthode
                $stmtCheck = $db->prepare("SELECT id FROM ecritures WHERE lettrage = ?");
                $stmtCheck->execute([$lettrage_code]);
                $ecritures_to_delettrer = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);

                foreach ($ecritures_to_delettrer as $id) {
                    blockIfEcritureInClosedExercice($id, $db, 'lettrage.php?compte=' . ($_GET['compte'] ?? ''));
                }

                $stmt = $db->prepare("
                    UPDATE ecritures
                    SET lettrage = NULL, statut_lettrage = 'Non lettré'
                    WHERE lettrage = ?
                ");
                $stmt->execute([$lettrage_code]);
            }

            $db->commit();

            $_SESSION['success'] = "Délettrage effectué avec succès pour le code: {$lettrage_code}";
            header('Location: lettrage.php?compte=' . ($_GET['compte'] ?? ''));
            exit;

        } elseif ($action === 'lettrage_auto') {
            // Lettrage automatique par compte
            $compte = $_POST['compte'] ?? '';

            if (empty($compte)) {
                throw new Exception("Veuillez sélectionner un compte");
            }

            $db->beginTransaction();

            if ($lettrageParLigne) {
                // Récupérer toutes les LIGNES non lettrées pour ce compte
                $stmt = $db->prepare("
                    SELECT le.id, le.id_ecriture, le.compte, le.debit, le.credit,
                           (le.debit - le.credit) as solde,
                           e.date_ecriture, e.numero_ecriture
                    FROM lignes_ecriture le
                    INNER JOIN ecritures e ON le.id_ecriture = e.id
                    WHERE le.compte = ?
                    AND e.societe_id = ?
                    AND e.statut = 'Validé'
                    AND (le.statut_lettrage = 'Non lettré' OR le.statut_lettrage IS NULL)
                    ORDER BY e.date_ecriture ASC, le.id ASC
                ");
                $stmt->execute([$compte, $societe_id]);
                $lignes = $stmt->fetchAll();

                $lettres = 0;
                $used = [];

                for ($i = 0; $i < count($lignes); $i++) {
                    if (in_array($i, $used)) continue;

                    $current = $lignes[$i];

                    // Chercher une contrepartie qui équilibre
                    for ($j = $i + 1; $j < count($lignes); $j++) {
                        if (in_array($j, $used)) continue;

                        $other = $lignes[$j];

                        // Vérifier si les montants s'équilibrent
                        if (abs(floatval($current['solde']) + floatval($other['solde'])) < 0.01) {
                            // Vérifier les exercices clôturés
                            blockIfEcritureInClosedExercice($current['id_ecriture'], $db, 'lettrage.php?compte=' . $compte);
                            blockIfEcritureInClosedExercice($other['id_ecriture'], $db, 'lettrage.php?compte=' . $compte);

                            // Lettrer ces deux LIGNES
                            $lettrage_code = strtoupper(substr(uniqid(), -6));

                            $updateStmt = $db->prepare("
                                UPDATE lignes_ecriture
                                SET lettrage = ?, statut_lettrage = 'Lettré'
                                WHERE id IN (?, ?)
                            ");
                            $updateStmt->execute([$lettrage_code, $current['id'], $other['id']]);

                            $used[] = $i;
                            $used[] = $j;
                            $lettres += 2;
                            break;
                        }
                    }
                }
            } else {
                // Fallback: ancienne méthode (problématique)
                $stmt = $db->prepare("
                    SELECT e.id, e.numero_ecriture, e.date_ecriture,
                           SUM(le.debit) as total_debit,
                           SUM(le.credit) as total_credit,
                           (SUM(le.debit) - SUM(le.credit)) as solde
                    FROM ecritures e
                    LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture
                    WHERE le.compte = ?
                    AND e.societe_id = ?
                    AND (e.statut_lettrage = 'Non lettré' OR e.statut_lettrage IS NULL)
                    GROUP BY e.id
                    ORDER BY e.date_ecriture ASC
                ");
                $stmt->execute([$compte, $societe_id]);
                $ecritures = $stmt->fetchAll();

                $lettres = 0;
                $i = 0;

                while ($i < count($ecritures)) {
                    $current = $ecritures[$i];
                    $matched = false;

                    for ($j = $i + 1; $j < count($ecritures); $j++) {
                        $other = $ecritures[$j];

                        if (abs($current['solde'] + $other['solde']) < 0.01) {
                            blockIfEcritureInClosedExercice($current['id'], $db, 'lettrage.php?compte=' . $compte);
                            blockIfEcritureInClosedExercice($other['id'], $db, 'lettrage.php?compte=' . $compte);

                            $lettrage_code = strtoupper(substr(uniqid(), -6));

                            $updateStmt = $db->prepare("
                                UPDATE ecritures
                                SET lettrage = ?, statut_lettrage = 'Lettré'
                                WHERE id IN (?, ?)
                            ");
                            $updateStmt->execute([$lettrage_code, $current['id'], $other['id']]);

                            array_splice($ecritures, $j, 1);
                            $matched = true;
                            $lettres += 2;
                            break;
                        }
                    }

                    $i++;
                }
            }

            $db->commit();

            $_SESSION['success'] = "Lettrage automatique terminé : {$lettres} lignes lettrées";
            header('Location: lettrage.php?compte=' . $compte);
            exit;
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = $e->getMessage();
        header('Location: lettrage.php?compte=' . ($_POST['compte'] ?? ''));
        exit;
    }
}

// Récupération des paramètres
$compte_filtre = $_GET['compte'] ?? '';
$statut_filtre = $_GET['statut'] ?? '';
$lettrage_filtre = $_GET['lettrage'] ?? '';

// Récupérer les comptes lettrables (clients, fournisseurs, banques)
$stmtLettrables = $db->prepare("
    SELECT DISTINCT le.compte, pc.intitule_compte
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    LEFT JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = ?
    WHERE e.societe_id = ?
    AND (le.compte LIKE '4%'
       OR le.compte LIKE '5%')
    ORDER BY le.compte
");
$stmtLettrables->execute([$societe_id, $societe_id]);
$comptes_lettrables = $stmtLettrables->fetchAll();

// Construire la requête avec filtres - BASÉE SUR LES LIGNES
$whereConditions = ["e.statut = 'Validé'", "e.societe_id = ?"];
$params = [$societe_id];

if (!empty($compte_filtre)) {
    $whereConditions[] = "le.compte = ?";
    $params[] = $compte_filtre;
}

if ($lettrageParLigne) {
    // Filtrer sur le statut de la LIGNE
    if (!empty($statut_filtre)) {
        $whereConditions[] = "le.statut_lettrage = ?";
        $params[] = $statut_filtre;
    }

    if (!empty($lettrage_filtre)) {
        $whereConditions[] = "le.lettrage = ?";
        $params[] = $lettrage_filtre;
    }
} else {
    // Fallback sur le statut de l'écriture
    if (!empty($statut_filtre)) {
        $whereConditions[] = "e.statut_lettrage = ?";
        $params[] = $statut_filtre;
    }

    if (!empty($lettrage_filtre)) {
        $whereConditions[] = "e.lettrage = ?";
        $params[] = $lettrage_filtre;
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Récupérer les LIGNES d'écritures avec leur statut de lettrage
if ($lettrageParLigne) {
    $sql = "
        SELECT e.id as ecriture_id, e.numero_ecriture, e.date_ecriture, e.libelle as ecriture_libelle,
               le.id as ligne_id,
               le.compte,
               pc.intitule_compte,
               le.debit,
               le.credit,
               (le.debit - le.credit) as solde,
               COALESCE(le.libelle, e.libelle) as libelle,
               le.lettrage,
               le.statut_lettrage
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        LEFT JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE {$whereClause}
        ORDER BY COALESCE(le.statut_lettrage, 'Non lettré') ASC, le.lettrage ASC, e.date_ecriture ASC, le.id ASC
    ";
} else {
    $sql = "
        SELECT e.id as ecriture_id, e.numero_ecriture, e.date_ecriture, e.libelle as ecriture_libelle,
               le.id as ligne_id,
               le.compte,
               pc.intitule_compte,
               le.debit,
               le.credit,
               (le.debit - le.credit) as solde,
               COALESCE(le.libelle, e.libelle) as libelle,
               e.lettrage,
               e.statut_lettrage
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        LEFT JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE {$whereClause}
        ORDER BY COALESCE(e.statut_lettrage, 'Non lettré') ASC, e.lettrage ASC, e.date_ecriture ASC, le.id ASC
    ";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

// Grouper les lignes par code de lettrage
$lignes_by_lettrage = [];
$lignes_non_lettrees = [];

foreach ($lignes as $ligne) {
    if (!empty($ligne['lettrage'])) {
        $lignes_by_lettrage[$ligne['lettrage']][] = $ligne;
    } else {
        $lignes_non_lettrees[] = $ligne;
    }
}

// Statistiques
$stats = [];
if (!empty($compte_filtre)) {
    if ($lettrageParLigne) {
        $statsStmt = $db->prepare("
            SELECT
                COUNT(*) as total_lignes,
                SUM(CASE WHEN le.statut_lettrage = 'Lettré' THEN 1 ELSE 0 END) as lettrees,
                SUM(CASE WHEN le.statut_lettrage = 'Partiellement lettré' THEN 1 ELSE 0 END) as partiellement_lettrees,
                SUM(CASE WHEN le.statut_lettrage = 'Non lettré' OR le.statut_lettrage IS NULL THEN 1 ELSE 0 END) as non_lettrees
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE le.compte = ? AND e.societe_id = ? AND e.statut = 'Validé'
        ");
    } else {
        $statsStmt = $db->prepare("
            SELECT
                COUNT(DISTINCT e.id) as total_lignes,
                SUM(CASE WHEN e.statut_lettrage = 'Lettré' THEN 1 ELSE 0 END) as lettrees,
                SUM(CASE WHEN e.statut_lettrage = 'Partiellement lettré' THEN 1 ELSE 0 END) as partiellement_lettrees,
                SUM(CASE WHEN e.statut_lettrage = 'Non lettré' OR e.statut_lettrage IS NULL THEN 1 ELSE 0 END) as non_lettrees
            FROM ecritures e
            LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture
            WHERE le.compte = ? AND e.societe_id = ? AND e.statut = 'Validé'
        ");
    }
    $statsStmt->execute([$compte_filtre, $societe_id]);
    $stats = $statsStmt->fetch();
}

$pageTitle = "Lettrage des écritures";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
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
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-600 mb-2">
                            <i class="fas fa-link mr-3"></i>Lettrage
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">
                            Rapprocher les lignes d'écritures comptables (factures, paiements, etc.)
                            <?php if (!$lettrageParLigne): ?>
                                <span class="text-amber-400 ml-2">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Mode dégradé - Exécutez la migration fix_lettrage_par_ligne.sql
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <a href="liste.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-all inline-flex items-center gap-2">
                            <i class="fas fa-list"></i>
                            Liste des écritures
                        </a>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-6">

        <!-- Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-6 bg-green-500/20 border border-green-500 text-green-300 px-6 py-4 rounded-lg flex items-center">
                <i class="fas fa-check-circle mr-3 text-xl"></i>
                <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-6 bg-red-500/20 border border-red-500 text-red-300 px-6 py-4 rounded-lg flex items-center">
                <i class="fas fa-exclamation-circle mr-3 text-xl"></i>
                <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Filtres -->
        <div class="bg-slate-800/50 backdrop-blur-sm rounded-xl shadow-2xl border border-slate-700 p-4 mb-4">
            <h3 class="text-base font-semibold mb-3 flex items-center">
                <i class="fas fa-filter text-blue-400 mr-2 text-sm"></i>Filtres
            </h3>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <!-- Sélection du compte -->
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">
                        <i class="fas fa-list-ol text-blue-400 mr-1 text-xs"></i>Compte à lettrer
                    </label>
                    <select name="compte"
                            class="w-full px-3 py-1.5 text-sm bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                            onchange="this.form.submit()">
                        <option value="">-- Sélectionner un compte --</option>
                        <?php foreach ($comptes_lettrables as $cpt): ?>
                            <option value="<?= htmlspecialchars($cpt['compte']) ?>"
                                    <?= $compte_filtre === $cpt['compte'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cpt['compte']) ?> - <?= htmlspecialchars($cpt['intitule_compte']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Statut de lettrage -->
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">
                        <i class="fas fa-tags text-purple-400 mr-1 text-xs"></i>Statut
                    </label>
                    <select name="statut"
                            class="w-full px-3 py-1.5 text-sm bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                            onchange="this.form.submit()">
                        <option value="">Tous les statuts</option>
                        <option value="Non lettré" <?= $statut_filtre === 'Non lettré' ? 'selected' : '' ?>>Non lettré</option>
                        <option value="Partiellement lettré" <?= $statut_filtre === 'Partiellement lettré' ? 'selected' : '' ?>>Partiellement lettré</option>
                        <option value="Lettré" <?= $statut_filtre === 'Lettré' ? 'selected' : '' ?>>Lettré</option>
                    </select>
                </div>

                <!-- Code de lettrage -->
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">
                        <i class="fas fa-code text-green-400 mr-1 text-xs"></i>Code lettrage
                    </label>
                    <input type="text"
                           name="lettrage"
                           value="<?= htmlspecialchars($lettrage_filtre) ?>"
                           placeholder="Ex: A1B2C3"
                           class="w-full px-3 py-1.5 text-sm bg-slate-900/50 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-blue-500">
                </div>

                <!-- Boutons -->
                <div class="flex items-end gap-2">
                    <button type="submit"
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 text-sm rounded-lg transition-colors flex items-center justify-center">
                        <i class="fas fa-search mr-1.5 text-xs"></i>Filtrer
                    </button>
                    <a href="lettrage.php"
                       class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1.5 text-sm rounded-lg transition-colors flex items-center justify-center">
                        <i class="fas fa-times text-xs"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Statistiques -->
        <?php if (!empty($stats)): ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
                <div class="bg-slate-800/50 backdrop-blur-sm rounded-lg p-3 border border-slate-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-400 text-xs mb-0.5">Total lignes</p>
                            <p class="text-xl font-bold text-white"><?= $stats['total_lignes'] ?></p>
                        </div>
                        <i class="fas fa-file-invoice text-2xl text-blue-400"></i>
                    </div>
                </div>

                <div class="bg-green-500/10 backdrop-blur-sm rounded-lg p-3 border border-green-500/30">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-green-300 text-xs mb-0.5">Lettrées</p>
                            <p class="text-xl font-bold text-green-400"><?= $stats['lettrees'] ?></p>
                        </div>
                        <i class="fas fa-check-circle text-2xl text-green-400"></i>
                    </div>
                </div>

                <div class="bg-yellow-500/10 backdrop-blur-sm rounded-lg p-3 border border-yellow-500/30">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-yellow-300 text-xs mb-0.5">Partiellement</p>
                            <p class="text-xl font-bold text-yellow-400"><?= $stats['partiellement_lettrees'] ?></p>
                        </div>
                        <i class="fas fa-exclamation-triangle text-2xl text-yellow-400"></i>
                    </div>
                </div>

                <div class="bg-red-500/10 backdrop-blur-sm rounded-lg p-3 border border-red-500/30">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-red-300 text-xs mb-0.5">Non lettrées</p>
                            <p class="text-xl font-bold text-red-400"><?= $stats['non_lettrees'] ?></p>
                        </div>
                        <i class="fas fa-times-circle text-2xl text-red-400"></i>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($compte_filtre)): ?>
            <!-- Actions groupées -->
            <div class="bg-slate-800/50 backdrop-blur-sm rounded-lg border border-slate-700 p-3 mb-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h3 class="text-base font-semibold flex items-center">
                        <i class="fas fa-magic text-purple-400 mr-2 text-sm"></i>Actions de lettrage
                        <span class="text-xs text-slate-400 ml-2">(compte <?= htmlspecialchars($compte_filtre) ?>)</span>
                    </h3>
                    <div class="flex gap-2">
                        <form method="POST" class="inline">
                            <input type="hidden" name="compte" value="<?= htmlspecialchars($compte_filtre) ?>">
                            <input type="hidden" name="action" value="lettrage_auto">
                            <button type="submit"
                                    class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 text-sm rounded-lg transition-colors flex items-center"
                                    onclick="return confirm('Lancer le lettrage automatique pour ce compte ?')">
                                <i class="fas fa-magic mr-1.5 text-xs"></i>Lettrage automatique
                            </button>
                        </form>

                        <button onclick="lettrerSelection()"
                                class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 text-sm rounded-lg transition-colors flex items-center">
                            <i class="fas fa-link mr-1.5 text-xs"></i>Lettrer la sélection
                        </button>
                    </div>
                </div>
            </div>

            <!-- Lignes non lettrées -->
            <?php if (!empty($lignes_non_lettrees)): ?>
                <div class="bg-slate-800/50 backdrop-blur-sm rounded-lg border border-slate-700 mb-4">
                    <div class="p-3 border-b border-slate-700">
                        <h3 class="text-base font-semibold flex items-center">
                            <i class="fas fa-clipboard-list text-red-400 mr-2 text-sm"></i>
                            Lignes non lettrées (<?= count($lignes_non_lettrees) ?>)
                        </h3>
                    </div>

                    <div class="overflow-x-auto">
                        <form id="lettrageForm" method="POST">
                            <input type="hidden" name="action" value="lettrer">
                            <input type="hidden" name="compte" value="<?= htmlspecialchars($compte_filtre) ?>">

                            <table class="w-full">
                                <thead class="bg-slate-900/50">
                                    <tr>
                                        <th class="px-3 py-2 text-left">
                                            <input type="checkbox" id="selectAll" class="w-3.5 h-3.5 text-blue-600 bg-slate-700 border-slate-600 rounded focus:ring-blue-500">
                                        </th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-300 uppercase">Date</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-300 uppercase">N° Écriture</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-300 uppercase">Libellé</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-300 uppercase">Débit</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-300 uppercase">Crédit</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-300 uppercase">Solde</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-slate-300 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php foreach ($lignes_non_lettrees as $ligne): ?>
                                        <tr class="hover:bg-slate-700/50 transition-colors" data-solde="<?= floatval($ligne['solde']) ?>">
                                            <td class="px-3 py-2">
                                                <input type="checkbox"
                                                       name="ligne_ids[]"
                                                       value="<?= $ligne['ligne_id'] ?>"
                                                       class="ligne-checkbox w-3.5 h-3.5 text-blue-600 bg-slate-700 border-slate-600 rounded focus:ring-blue-500">
                                            </td>
                                            <td class="px-3 py-2 text-xs"><?= date('d/m/Y', strtotime($ligne['date_ecriture'])) ?></td>
                                            <td class="px-3 py-2 text-xs font-mono">
                                                <a href="voir.php?id=<?= $ligne['ecriture_id'] ?>"
                                                   class="text-blue-400 hover:text-blue-300 hover:underline transition-colors"
                                                   title="Voir le détail de l'écriture">
                                                    <?= htmlspecialchars($ligne['numero_ecriture']) ?>
                                                </a>
                                            </td>
                                            <td class="px-3 py-2 text-xs max-w-xs truncate" title="<?= htmlspecialchars($ligne['libelle']) ?>"><?= htmlspecialchars($ligne['libelle']) ?></td>
                                            <td class="px-3 py-2 text-xs text-right font-mono">
                                                <?php if (floatval($ligne['debit']) > 0): ?>
                                                    <span class="text-green-400"><?= number_format($ligne['debit'], 0, ',', ' ') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2 text-xs text-right font-mono">
                                                <?php if (floatval($ligne['credit']) > 0): ?>
                                                    <span class="text-red-400"><?= number_format($ligne['credit'], 0, ',', ' ') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2 text-xs text-right font-mono font-bold">
                                                <span class="<?= floatval($ligne['solde']) > 0 ? 'text-green-400' : 'text-red-400' ?>">
                                                    <?= number_format($ligne['solde'], 0, ',', ' ') ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <a href="voir.php?id=<?= $ligne['ecriture_id'] ?>"
                                                   class="text-blue-400 hover:text-blue-300 transition-colors"
                                                   title="Voir le détail">
                                                    <i class="fas fa-eye text-xs"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-slate-900/50">
                                    <tr class="font-bold">
                                        <td colspan="4" class="px-3 py-2 text-right text-xs">TOTAL SÉLECTION:</td>
                                        <td class="px-3 py-2 text-right font-mono text-green-400 text-xs" id="totalDebitSelection">0</td>
                                        <td class="px-3 py-2 text-right font-mono text-red-400 text-xs" id="totalCreditSelection">0</td>
                                        <td class="px-3 py-2 text-right font-mono text-xs" id="totalSoldeSelection">0</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Lignes lettrées groupées par code -->
            <?php if (!empty($lignes_by_lettrage)): ?>
                <div class="space-y-3">
                    <h3 class="text-base font-semibold flex items-center">
                        <i class="fas fa-check-circle text-green-400 mr-2 text-sm"></i>
                        Lignes lettrées (<?= count($lignes_by_lettrage) ?> groupes)
                    </h3>

                    <?php foreach ($lignes_by_lettrage as $code => $lignes_lettrage): ?>
                        <?php
                        $total_debit_groupe = array_sum(array_map(function($l) { return floatval($l['debit']); }, $lignes_lettrage));
                        $total_credit_groupe = array_sum(array_map(function($l) { return floatval($l['credit']); }, $lignes_lettrage));
                        $solde_groupe = $total_debit_groupe - $total_credit_groupe;
                        $is_equilibre = abs($solde_groupe) < 0.01;
                        ?>

                        <div class="bg-slate-800/50 backdrop-blur-sm rounded-lg border border-slate-700">
                            <div class="p-3 border-b border-slate-700 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="px-3 py-1 bg-blue-500/20 border border-blue-500 text-blue-300 rounded-lg font-mono font-bold text-sm">
                                        <?= htmlspecialchars($code) ?>
                                    </span>
                                    <div>
                                        <p class="text-xs text-slate-400">
                                            <?= count($lignes_lettrage) ?> ligne(s)
                                        </p>
                                        <p class="text-xs font-semibold <?= $is_equilibre ? 'text-green-400' : 'text-yellow-400' ?>">
                                            <?= $is_equilibre ? 'Équilibré' : 'Déséquilibre: ' . number_format($solde_groupe, 0, ',', ' ') ?>
                                        </p>
                                    </div>
                                </div>

                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="delettrer">
                                    <input type="hidden" name="lettrage_code" value="<?= htmlspecialchars($code) ?>">
                                    <button type="submit"
                                            class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 text-sm rounded-lg transition-colors flex items-center"
                                            onclick="return confirm('Êtes-vous sûr de vouloir délettrer ces lignes ?')">
                                        <i class="fas fa-unlink mr-1.5 text-xs"></i>Délettrer
                                    </button>
                                </form>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-slate-900/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-slate-300 uppercase">Date</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-slate-300 uppercase">N° Écriture</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-slate-300 uppercase">Libellé</th>
                                            <th class="px-3 py-2 text-right text-xs font-medium text-slate-300 uppercase">Débit</th>
                                            <th class="px-3 py-2 text-right text-xs font-medium text-slate-300 uppercase">Crédit</th>
                                            <th class="px-3 py-2 text-right text-xs font-medium text-slate-300 uppercase">Solde</th>
                                            <th class="px-3 py-2 text-center text-xs font-medium text-slate-300 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700">
                                        <?php foreach ($lignes_lettrage as $ligne): ?>
                                            <tr class="hover:bg-slate-700/50 transition-colors">
                                                <td class="px-3 py-2 text-xs"><?= date('d/m/Y', strtotime($ligne['date_ecriture'])) ?></td>
                                                <td class="px-3 py-2 text-xs font-mono">
                                                    <a href="voir.php?id=<?= $ligne['ecriture_id'] ?>"
                                                       class="text-blue-400 hover:text-blue-300 hover:underline transition-colors"
                                                       title="Voir le détail de l'écriture">
                                                        <?= htmlspecialchars($ligne['numero_ecriture']) ?>
                                                    </a>
                                                </td>
                                                <td class="px-3 py-2 text-xs max-w-xs truncate" title="<?= htmlspecialchars($ligne['libelle']) ?>"><?= htmlspecialchars($ligne['libelle']) ?></td>
                                                <td class="px-3 py-2 text-xs text-right font-mono">
                                                    <?php if (floatval($ligne['debit']) > 0): ?>
                                                        <span class="text-green-400"><?= number_format($ligne['debit'], 0, ',', ' ') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2 text-xs text-right font-mono">
                                                    <?php if (floatval($ligne['credit']) > 0): ?>
                                                        <span class="text-red-400"><?= number_format($ligne['credit'], 0, ',', ' ') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2 text-xs text-right font-mono font-bold">
                                                    <span class="<?= floatval($ligne['solde']) > 0 ? 'text-green-400' : 'text-red-400' ?>">
                                                        <?= number_format($ligne['solde'], 0, ',', ' ') ?>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <a href="voir.php?id=<?= $ligne['ecriture_id'] ?>"
                                                       class="text-blue-400 hover:text-blue-300 transition-colors"
                                                       title="Voir le détail">
                                                        <i class="fas fa-eye text-xs"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-slate-900/50">
                                        <tr class="font-bold">
                                            <td colspan="3" class="px-3 py-2 text-right text-xs">TOTAL:</td>
                                            <td class="px-3 py-2 text-right font-mono text-green-400 text-xs">
                                                <?= number_format($total_debit_groupe, 0, ',', ' ') ?>
                                            </td>
                                            <td class="px-3 py-2 text-right font-mono text-red-400 text-xs">
                                                <?= number_format($total_credit_groupe, 0, ',', ' ') ?>
                                            </td>
                                            <td class="px-3 py-2 text-right font-mono <?= $is_equilibre ? 'text-green-400' : 'text-yellow-400' ?> text-xs">
                                                <?= number_format($solde_groupe, 0, ',', ' ') ?>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Message d'invitation -->
            <div class="bg-slate-800/50 backdrop-blur-sm rounded-lg border border-slate-700 p-8 text-center">
                <i class="fas fa-link text-5xl text-slate-600 mb-3"></i>
                <h3 class="text-base font-semibold mb-2">Sélectionnez un compte pour commencer</h3>
                <p class="text-slate-400 text-sm">Choisissez un compte à lettrer dans le filtre ci-dessus (clients, fournisseurs, banques, etc.)</p>
            </div>
        <?php endif; ?>

            </div>
        </main>
    </div>

    <script>
        // Sélection multiple
        document.getElementById('selectAll')?.addEventListener('change', function(e) {
            document.querySelectorAll('.ligne-checkbox').forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateTotals();
        });

        // Mise à jour des totaux quand on coche/décoche
        document.querySelectorAll('.ligne-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateTotals);
        });

        function updateTotals() {
            let totalDebit = 0;
            let totalCredit = 0;
            let totalSolde = 0;

            document.querySelectorAll('.ligne-checkbox:checked').forEach(checkbox => {
                const row = checkbox.closest('tr');
                const debitCell = row.querySelector('td:nth-child(5)');
                const creditCell = row.querySelector('td:nth-child(6)');
                const solde = parseFloat(row.dataset.solde) || 0;

                const debitText = debitCell.textContent.trim().replace(/\s/g, '').replace(',', '.');
                const creditText = creditCell.textContent.trim().replace(/\s/g, '').replace(',', '.');

                totalDebit += parseFloat(debitText) || 0;
                totalCredit += parseFloat(creditText) || 0;
                totalSolde += solde;
            });

            document.getElementById('totalDebitSelection').textContent = totalDebit.toLocaleString('fr-FR');
            document.getElementById('totalCreditSelection').textContent = totalCredit.toLocaleString('fr-FR');

            const soldeEl = document.getElementById('totalSoldeSelection');
            soldeEl.textContent = totalSolde.toLocaleString('fr-FR');
            soldeEl.className = 'px-3 py-2 text-right font-mono text-xs font-bold ' +
                (Math.abs(totalSolde) < 0.01 ? 'text-green-400' : (totalSolde > 0 ? 'text-green-400' : 'text-red-400'));
        }

        // Lettrer la sélection
        function lettrerSelection() {
            const checkedBoxes = document.querySelectorAll('.ligne-checkbox:checked');

            if (checkedBoxes.length < 2) {
                alert('Veuillez sélectionner au moins 2 lignes à lettrer');
                return;
            }

            // Calculer le solde total
            let totalSolde = 0;
            checkedBoxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                totalSolde += parseFloat(row.dataset.solde) || 0;
            });

            const message = Math.abs(totalSolde) < 0.01
                ? `Lettrer ${checkedBoxes.length} lignes (équilibre parfait) ?`
                : `Lettrer ${checkedBoxes.length} lignes (déséquilibre: ${Math.round(totalSolde).toLocaleString('fr-FR')}) ?\nCela créera un lettrage partiel.`;

            if (confirm(message)) {
                document.getElementById('lettrageForm').submit();
            }
        }
    </script>
</body>
</html>
