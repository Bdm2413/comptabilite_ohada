<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Déterminer le mode (création ou modification)
$editMode = isset($_GET['id']) && !empty($_GET['id']);
$ecriture_id = $editMode ? intval($_GET['id']) : null;
$ecriture = null;
$lignes = [];

if ($editMode) {
    // Charger l'écriture existante
    $stmt = $db->prepare("SELECT * FROM ecritures WHERE id = ? AND societe_id = ?");
    $stmt->execute([$ecriture_id, $societe_id]);
    $ecriture = $stmt->fetch();

    if (!$ecriture) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Écriture introuvable'];
        header('Location: liste.php');
        exit;
    }

    // Vérifier que c'est un brouillon (sauf pour les admins qui peuvent modifier les écritures validées)
    $isAdminEditingValidated = false;
    if ($ecriture['statut'] !== 'Brouillon') {
        if (isAdmin()) {
            // Admin peut modifier les écritures validées
            $isAdminEditingValidated = true;
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Seules les écritures en brouillon peuvent être modifiées'];
            header('Location: liste.php');
            exit;
        }
    }

    // Charger les lignes
    $stmtLignes = $db->prepare("SELECT * FROM lignes_ecriture WHERE id_ecriture = ? AND societe_id = ? ORDER BY id");
    $stmtLignes->execute([$ecriture_id, $societe_id]);
    $lignes = $stmtLignes->fetchAll();
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    // Déterminer le statut à appliquer
    // Si admin modifie une écriture validée, conserver le statut Validé
    $nouveauStatut = 'Brouillon';
    if ($action === 'validate') {
        $nouveauStatut = 'Validé';
    } elseif ($editMode && $ecriture && $ecriture['statut'] === 'Validé' && isAdmin()) {
        // Admin modifie une écriture validée : conserver le statut Validé
        $nouveauStatut = 'Validé';
    }

    $data = [
        'date_ecriture' => $_POST['date_ecriture'] ?? '',
        'journal' => $_POST['journal'] ?? '',
        'libelle' => trim($_POST['libelle'] ?? ''),
        'id_tiers' => !empty($_POST['id_tiers']) ? intval($_POST['id_tiers']) : null,
        'compte_tiers' => !empty($_POST['compte_tiers']) ? trim($_POST['compte_tiers']) : null,
        'num_piece' => !empty($_POST['num_piece']) ? trim($_POST['num_piece']) : null,
        'reference_piece' => !empty($_POST['reference_piece']) ? trim($_POST['reference_piece']) : null,
        'type_facture' => !empty($_POST['type_facture']) ? $_POST['type_facture'] : null,
        'facture_initiale' => !empty($_POST['facture_initiale']) ? trim($_POST['facture_initiale']) : null,
        'id_bon_commande' => !empty($_POST['id_bon_commande']) ? intval($_POST['id_bon_commande']) : null,
        'statut' => $nouveauStatut
    ];

    // Vérifier que la date d'écriture n'est pas dans un exercice clôturé
    if (!empty($data['date_ecriture'])) {
        blockIfExerciceClosed($data['date_ecriture'], $db, 'saisie.php' . ($editMode ? '?id=' . $ecriture_id : ''));
    }

    // Générer automatiquement le mois et l'année depuis la date
    if (!empty($data['date_ecriture'])) {
        $date = new DateTime($data['date_ecriture']);
        $mois_fr = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $data['mois'] = $mois_fr[(int)$date->format('n') - 1];
        $data['annee'] = $date->format('Y');
    }

    // Validation
    $errors = [];
    if (empty($data['date_ecriture'])) $errors[] = "La date est requise";
    if (empty($data['journal'])) $errors[] = "Le journal est requis";
    if (empty($data['libelle'])) $errors[] = "Le libellé est requis";

    // Validation des lignes
    $details = [];
    $total_debit = 0;
    $total_credit = 0;

    if (!empty($_POST['details']) && is_array($_POST['details'])) {
        foreach ($_POST['details'] as $index => $detail) {
            if (!empty($detail['compte']) && !empty($detail['libelle'])) {
                $debit = floatval($detail['debit'] ?? 0);
                $credit = floatval($detail['credit'] ?? 0);

                if ($debit > 0 || $credit > 0) {
                    if ($debit > 0 && $credit > 0) {
                        $errors[] = "Ligne " . ($index + 1) . ": Un compte ne peut pas avoir à la fois un débit et un crédit";
                        continue;
                    }

                    $details[] = [
                        'compte' => intval($detail['compte']),
                        'compte_tiers' => !empty($detail['compte_tiers']) ? trim($detail['compte_tiers']) : null,
                        'numero_facture' => !empty($detail['numero_facture']) ? trim($detail['numero_facture']) : null,
                        'date_ligne' => !empty($detail['date_ligne']) ? $detail['date_ligne'] : null,
                        'libelle' => trim($detail['libelle']),
                        'debit' => $debit,
                        'credit' => $credit
                    ];

                    $total_debit += $debit;
                    $total_credit += $credit;
                }
            }
        }
    }

    if (empty($details)) {
        $errors[] = "Au moins une ligne d'écriture est requise";
    } else {
        // Vérifier l'équilibre
        if (abs($total_debit - $total_credit) > 0.01) {
            $errors[] = "Les écritures doivent être équilibrées (Débit: " . number_format($total_debit, 2, ',', ' ') . " FCFA, Crédit: " . number_format($total_credit, 2, ',', ' ') . " FCFA)";
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Vérifier si la colonne id_bon_commande existe
            $bcColExists = false;
            try {
                $checkCol = $db->query("SHOW COLUMNS FROM ecritures LIKE 'id_bon_commande'");
                $bcColExists = $checkCol->rowCount() > 0;
            } catch (Exception $e) {
                $bcColExists = false;
            }

            if ($editMode) {
                // Mise à jour de l'écriture existante
                if ($bcColExists) {
                    $sql = "UPDATE ecritures SET
                            date_ecriture = ?, journal = ?, libelle = ?, mois = ?, annee = ?,
                            id_tiers = ?, compte_tiers = ?, num_piece = ?, reference_piece = ?,
                            type_facture = ?, facture_initiale = ?, id_bon_commande = ?, statut = ?, montant_total = ?
                            WHERE id = ? AND societe_id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $data['date_ecriture'],
                        $data['journal'],
                        $data['libelle'],
                        $data['mois'],
                        $data['annee'],
                        $data['id_tiers'],
                        $data['compte_tiers'],
                        $data['num_piece'],
                        $data['reference_piece'],
                        $data['type_facture'],
                        $data['facture_initiale'],
                        $data['id_bon_commande'],
                        $data['statut'],
                        max($total_debit, $total_credit),
                        $ecriture_id,
                        $societe_id
                    ]);
                } else {
                    $sql = "UPDATE ecritures SET
                            date_ecriture = ?, journal = ?, libelle = ?, mois = ?, annee = ?,
                            id_tiers = ?, compte_tiers = ?, num_piece = ?, reference_piece = ?,
                            type_facture = ?, facture_initiale = ?, statut = ?, montant_total = ?
                            WHERE id = ? AND societe_id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $data['date_ecriture'],
                        $data['journal'],
                        $data['libelle'],
                        $data['mois'],
                        $data['annee'],
                        $data['id_tiers'],
                        $data['compte_tiers'],
                        $data['num_piece'],
                        $data['reference_piece'],
                        $data['type_facture'],
                        $data['facture_initiale'],
                        $data['statut'],
                        max($total_debit, $total_credit),
                        $ecriture_id,
                        $societe_id
                    ]);
                }

                // Supprimer les anciennes lignes
                $db->prepare("DELETE FROM lignes_ecriture WHERE id_ecriture = ? AND societe_id = ?")->execute([$ecriture_id, $societe_id]);
                $new_id = $ecriture_id;
            } else {
                // Création d'une nouvelle écriture
                // Générer le numéro d'écriture
                // Trouver le dernier numéro utilisé pour ce journal ce mois
                $prefix = $data["journal"] . date("m", strtotime($data["date_ecriture"])) . date("y", strtotime($data["date_ecriture"]));
                $stmtCount = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_ecriture, -4) AS UNSIGNED)) as dernier_num FROM ecritures WHERE numero_ecriture LIKE ? AND societe_id = ?");
                $stmtCount->execute([$prefix . "%", $societe_id]);
                $result = $stmtCount->fetch();
                $dernier_num = $result["dernier_num"] ?? 0;
                $prochain_num = $dernier_num + 1;
                $numero_ecriture = $prefix . str_pad($prochain_num, 4, "0", STR_PAD_LEFT);

                if ($bcColExists) {
                    $sql = "INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, journal, libelle, mois, annee, id_tiers, compte_tiers, num_piece, reference_piece, type_facture, facture_initiale, id_bon_commande, statut, montant_total, date_creation)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $societe_id,
                        $numero_ecriture,
                        $data['date_ecriture'],
                        $data['journal'],
                        $data['libelle'],
                        $data['mois'],
                        $data['annee'],
                        $data['id_tiers'],
                        $data['compte_tiers'],
                        $data['num_piece'],
                        $data['reference_piece'],
                        $data['type_facture'],
                        $data['facture_initiale'],
                        $data['id_bon_commande'],
                        $data['statut'],
                        max($total_debit, $total_credit)
                    ]);
                } else {
                    $sql = "INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, journal, libelle, mois, annee, id_tiers, compte_tiers, num_piece, reference_piece, type_facture, facture_initiale, statut, montant_total, date_creation)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $societe_id,
                        $numero_ecriture,
                        $data['date_ecriture'],
                        $data['journal'],
                        $data['libelle'],
                        $data['mois'],
                        $data['annee'],
                        $data['id_tiers'],
                        $data['compte_tiers'],
                        $data['num_piece'],
                        $data['reference_piece'],
                        $data['type_facture'],
                        $data['facture_initiale'],
                        $data['statut'],
                        max($total_debit, $total_credit)
                    ]);
                }
                $new_id = $db->lastInsertId();
            }

            // Insérer les nouvelles lignes
            $sqlLigne = "INSERT INTO lignes_ecriture (societe_id, id_ecriture, compte, compte_tiers, numero_facture, date_ligne, libelle, debit, credit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtLigne = $db->prepare($sqlLigne);

            foreach ($details as $detail) {
                $stmtLigne->execute([
                    $societe_id,
                    $new_id,
                    $detail['compte'],
                    $detail['compte_tiers'],
                    $detail['numero_facture'],
                    $detail['date_ligne'],
                    $detail['libelle'],
                    $detail['debit'],
                    $detail['credit']
                ]);
            }

            $db->commit();

            $message = $editMode ? 'Écriture modifiée avec succès' : 'Écriture créée avec succès';
            $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
            header('Location: voir.php?id=' . $new_id);
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Erreur lors de l\'enregistrement: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => implode('<br>', $errors)];
    }
}

// Récupérer les données pour les sélecteurs
try {
    $stmt_j = $db->prepare("SELECT code_journal as code, libelle as journal, type_journal as type FROM journaux WHERE societe_id = ? AND actif = 1 ORDER BY code_journal");
    $stmt_j->execute([$societe_id]);
    $journaux = $stmt_j->fetchAll();
    $stmt_c = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE actif = 'Oui' AND societe_id = ? ORDER BY compte");
    $stmt_c->execute([$societe_id]);
    $comptes = $stmt_c->fetchAll();

    $stmt_t = $db->prepare("SELECT id, compte_tiers, nom, abreviation, type FROM plan_tiers WHERE actif = 1 AND societe_id = ? ORDER BY nom");
    $stmt_t->execute([$societe_id]);
    $tiers = $stmt_t->fetchAll();

    // Vérifier si la colonne id_bon_commande existe dans la table ecritures
    $bcColumnExists = false;
    try {
        $checkColumn = $db->query("SHOW COLUMNS FROM ecritures LIKE 'id_bon_commande'");
        $bcColumnExists = $checkColumn->rowCount() > 0;
    } catch (Exception $e) {
        $bcColumnExists = false;
    }

    // Récupérer les BC validés avec leur consommation pour le suivi
    // Consommation basée sur les crédits des comptes 4011xxx (Fournisseurs) et 4812xxx (Fournisseurs d'immobilisations)
    $bonsCommande = [];
    try {
        if ($bcColumnExists) {
            // Avec suivi de consommation (migration exécutée)
            $bonsCommande = $db->query("
                SELECT
                    bc.id,
                    bc.numero_bc,
                    bc.objet,
                    bc.montant_ttc,
                    bc.id_fournisseur,
                    pt.nom as fournisseur_nom,
                    COALESCE((
                        SELECT SUM(le.credit)
                        FROM lignes_ecriture le
                        INNER JOIN ecritures e ON le.id_ecriture = e.id
                        WHERE e.id_bon_commande = bc.id
                          AND e.statut = 'Validé'
                          AND (le.compte LIKE '4011%' OR le.compte LIKE '4812%')
                          AND le.credit > 0
                    ), 0) as montant_consomme
                FROM bons_commande bc
                JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
                WHERE bc.statut = 'Validé'
                ORDER BY bc.date_bc DESC, bc.numero_bc
            ")->fetchAll();
        } else {
            // Sans suivi de consommation (migration pas encore exécutée)
            // On charge quand même les BC pour permettre la sélection
            $bonsCommande = $db->query("
                SELECT
                    bc.id,
                    bc.numero_bc,
                    bc.objet,
                    bc.montant_ttc,
                    bc.id_fournisseur,
                    pt.nom as fournisseur_nom,
                    0 as montant_consomme
                FROM bons_commande bc
                JOIN plan_tiers pt ON bc.id_fournisseur = pt.id
                WHERE bc.statut = 'Validé'
                ORDER BY bc.date_bc DESC, bc.numero_bc
            ")->fetchAll();
        }
    } catch (Exception $e) {
        // Si la table bons_commande n'existe pas encore
        $bonsCommande = [];
    }
} catch (Exception $e) {
    $journaux = [];
    $comptes = [];
    $tiers = [];
    $bonsCommande = [];
}

// Liste des mois
$mois_list = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editMode ? 'Modifier' : 'Nouvelle' ?> Écriture - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .select2-container {
            width: 100% !important;
        }
    </style>
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
                            <i class="fas fa-edit mr-3"></i><?= $editMode ? 'Modifier l\'écriture' : 'Saisie d\'Écriture' ?>
                        </h1>
                        <p class="text-sm text-slate-400 mt-1">
                            <?= $editMode ? 'Modification de l\'écriture ' . htmlspecialchars($ecriture['numero_ecriture']) : 'Saisie d\'une nouvelle écriture en partie double' ?>
                        </p>
                    </div>
                    <div>
                        <a href="liste.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-arrow-left"></i>
                            Retour à la liste
                        </a>
                    </div>
                </div>
            </header>

            <!-- Flash Message -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="m-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400' ?>">
                    <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i>
                    <?= $_SESSION['flash']['message'] ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Message de duplication -->
            <?php if ($editMode && strpos($ecriture['libelle'], 'Copie -') === 0): ?>
                <div class="m-6 p-4 rounded-lg bg-purple-500/10 border border-purple-500/50 text-purple-300">
                    <i class="fas fa-copy mr-2"></i>
                    <strong>Écriture dupliquée</strong> - Cette écriture a été créée par duplication. Modifiez les informations selon vos besoins avant de valider.
                </div>
            <?php endif; ?>

            <!-- Formulaire -->
            <div class="p-6">
                <form method="POST" id="ecritureForm" class="space-y-6">
                    <!-- En-tête de l'écriture -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-file-alt text-blue-400"></i>
                            Informations générales
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Date (mois et année seront générés automatiquement) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-calendar text-blue-400 mr-1"></i>Date de l'écriture *
                                    <span class="text-xs text-slate-500 ml-2">(Mois et année automatiques)</span>
                                </label>
                                <input type="date" name="date_ecriture" required
                                       value="<?= $editMode ? htmlspecialchars($ecriture['date_ecriture']) : date('Y-m-d') ?>"
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                            </div>

                            <!-- Journal -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-book text-blue-400 mr-1"></i>Journal *
                                </label>
                                <select name="journal" required onchange="toggleTypeFacture()"
                                        class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Sélectionner un journal</option>
                                    <?php foreach ($journaux as $j): ?>
                                        <option value="<?= htmlspecialchars($j['code']) ?>"
                                                <?= ($editMode && $ecriture['journal'] === $j['code']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($j['code']) ?> - <?= htmlspecialchars($j['journal']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Tiers (optionnel) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-user text-blue-400 mr-1"></i>Tiers (optionnel)
                                </label>
                                <select name="id_tiers" id="selectTiers" onchange="updateCodeTiers()"
                                        class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">Aucun tiers</option>
                                    <?php foreach ($tiers as $t): ?>
                                        <option value="<?= $t['id'] ?>"
                                                data-code-tiers="<?= htmlspecialchars($t['compte_tiers']) ?>"
                                                data-abreviation="<?= htmlspecialchars($t['abreviation'] ?? $t['nom']) ?>"
                                                <?= ($editMode && $ecriture['id_tiers'] == $t['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t['nom']) ?> (<?= htmlspecialchars($t['type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Code tiers (généré automatiquement) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-barcode text-blue-400 mr-1"></i>Code tiers
                                    <span class="text-xs text-slate-500 ml-2">(Automatique)</span>
                                </label>
                                <input type="text" id="codeTiers" name="compte_tiers" readonly
                                       value="<?= $editMode ? htmlspecialchars($ecriture['compte_tiers'] ?? '') : '' ?>"
                                       placeholder="Sélectionnez un tiers"
                                       class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-400 cursor-not-allowed">
                            </div>

                            <!-- N° pièce (optionnel) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-hashtag text-blue-400 mr-1"></i>N° de pièce (optionnel)
                                </label>
                                <input type="text" name="num_piece"
                                       value="<?= $editMode ? htmlspecialchars($ecriture['num_piece'] ?? '') : '' ?>"
                                       placeholder="Ex: FAC-2024-001"
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors">
                            </div>

                            <!-- Référence pièce (optionnel) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-file-invoice text-blue-400 mr-1"></i>Référence pièce (optionnel)
                                </label>
                                <input type="text" name="reference_piece" id="reference_piece"
                                       value="<?= $editMode ? htmlspecialchars($ecriture['reference_piece'] ?? '') : '' ?>"
                                       placeholder="Ex: Facture n°123, Chèque n°456..."
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors">
                            </div>

                            <!-- Type de facture (optionnel - visible uniquement pour ACH/VTE) -->
                            <div id="type_facture_container" style="display: none;">
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-file-alt text-blue-400 mr-1"></i>Type de facture
                                    <span class="text-xs text-slate-500 ml-2">(Optionnel)</span>
                                </label>
                                <select name="type_facture" id="type_facture" onchange="toggleFactureInitiale()"
                                        class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-blue-500 transition-colors">
                                    <option value="">-- Non spécifié --</option>
                                    <option value="DOIT" <?= ($editMode && ($ecriture['type_facture'] ?? '') === 'DOIT') ? 'selected' : '' ?>>DOIT (Facture normale)</option>
                                    <option value="AVOIR" <?= ($editMode && ($ecriture['type_facture'] ?? '') === 'AVOIR') ? 'selected' : '' ?>>AVOIR (Annulation)</option>
                                    <option value="NORMALE" <?= ($editMode && ($ecriture['type_facture'] ?? '') === 'NORMALE') ? 'selected' : '' ?>>NORMALE (Autre)</option>
                                </select>
                            </div>

                            <!-- Facture initiale (visible uniquement si type = AVOIR) -->
                            <div id="facture_initiale_container" style="display: none;">
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-link text-orange-400 mr-1"></i>Facture DOIT annulée
                                    <span class="text-xs text-orange-400 ml-2">(Requis pour AVOIR)</span>
                                </label>
                                <input type="text" name="facture_initiale" id="facture_initiale"
                                       value="<?= $editMode ? htmlspecialchars($ecriture['facture_initiale'] ?? '') : '' ?>"
                                       placeholder="Ex: FACT-2025-001"
                                       class="w-full px-4 py-2 bg-slate-900/50 border border-orange-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-orange-500 transition-colors">
                                <p class="text-xs text-slate-500 mt-1">
                                    <i class="fas fa-info-circle"></i> Numéro de la facture DOIT que cet AVOIR annule
                                </p>
                            </div>

                            <!-- Bon de commande (visible pour journal ACH) -->
                            <div id="bon_commande_container" style="display: none;">
                                <label class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-file-invoice text-purple-400 mr-1"></i>Bon de commande
                                    <span class="text-xs text-slate-500 ml-2">(Optionnel - pour suivi)</span>
                                </label>
                                <select name="id_bon_commande" id="selectBonCommande" onchange="updateBCInfo()"
                                        class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-purple-500 transition-colors">
                                    <option value="">-- Aucun BC associé --</option>
                                    <?php foreach ($bonsCommande as $bc):
                                        $reste = floatval($bc['montant_ttc']) - floatval($bc['montant_consomme']);
                                        $pourcent = floatval($bc['montant_ttc']) > 0 ? round(floatval($bc['montant_consomme']) / floatval($bc['montant_ttc']) * 100, 1) : 0;
                                    ?>
                                        <option value="<?= $bc['id'] ?>"
                                                data-fournisseur-id="<?= $bc['id_fournisseur'] ?>"
                                                data-montant-ttc="<?= $bc['montant_ttc'] ?>"
                                                data-montant-consomme="<?= $bc['montant_consomme'] ?>"
                                                data-reste="<?= $reste ?>"
                                                data-pourcent="<?= $pourcent ?>"
                                                <?= ($editMode && isset($ecriture['id_bon_commande']) && $ecriture['id_bon_commande'] == $bc['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($bc['numero_bc']) ?> - <?= htmlspecialchars(substr($bc['objet'], 0, 30)) ?>
                                            (Reste: <?= number_format($reste, 0, ',', ' ') ?> FCFA - <?= $pourcent ?>% utilisé)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Infos BC sélectionné -->
                            <div id="bc_info_container" class="md:col-span-2" style="display: none;">
                                <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-purple-300 font-medium">
                                            <i class="fas fa-chart-pie mr-2"></i>Progression du BC
                                        </span>
                                        <span id="bc_pourcent" class="text-purple-400 font-bold">0%</span>
                                    </div>
                                    <div class="w-full bg-slate-700 rounded-full h-3 mb-3">
                                        <div id="bc_progress_bar" class="bg-gradient-to-r from-purple-500 to-pink-500 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                                    </div>
                                    <div class="grid grid-cols-3 gap-4 text-sm">
                                        <div>
                                            <span class="text-slate-400">Montant BC:</span>
                                            <span id="bc_montant_total" class="text-white font-medium ml-1">0</span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400">Consommé:</span>
                                            <span id="bc_montant_consomme" class="text-pink-400 font-medium ml-1">0</span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400">Reste:</span>
                                            <span id="bc_montant_reste" class="text-green-400 font-medium ml-1">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Libellé -->
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-align-left text-blue-400 mr-1"></i>Libellé de l'écriture *
                            </label>
                            <textarea name="libelle" required rows="2"
                                      placeholder="Description de l'écriture comptable..."
                                      class="w-full px-4 py-2 bg-slate-900/50 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors"><?= $editMode ? htmlspecialchars($ecriture['libelle']) : '' ?></textarea>
                        </div>
                    </div>

                    <!-- Lignes d'écriture -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                <i class="fas fa-list text-blue-400"></i>
                                Lignes d'écriture (partie double)
                            </h3>
                            <button type="button" onclick="addLine()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-plus"></i>
                                Ajouter une ligne
                            </button>
                        </div>

                        <!-- En-têtes du tableau -->
                        <div class="overflow-x-auto">
                            <table class="w-full" id="lignesTable">
                                <thead class="bg-slate-900/50 border-b border-slate-700">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-400 uppercase" style="width: 16%">Compte</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-400 uppercase" style="width: 8%">Cpte Tiers</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-400 uppercase" style="width: 9%">N° Facture</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-400 uppercase" style="width: 9%">Date Facture</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-400 uppercase" style="width: 18%">Libellé</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-400 uppercase" style="width: 12%">Débit</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-400 uppercase" style="width: 12%">Crédit</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-slate-400 uppercase" style="width: 6%">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="lignesBody">
                                    <?php if ($editMode && !empty($lignes)): ?>
                                        <?php foreach ($lignes as $index => $ligne): ?>
                                            <tr class="border-b border-slate-700/50 ligne-row">
                                                <td class="px-3 py-2">
                                                    <select name="details[<?= $index ?>][compte]" required class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                                        <option value="">Sélectionner</option>
                                                        <?php foreach ($comptes as $c): ?>
                                                            <option value="<?= $c['compte'] ?>" <?= ($ligne['compte'] == $c['compte']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($c['compte']) ?> - <?= htmlspecialchars(substr($c['intitule_compte'], 0, 20)) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <select name="details[<?= $index ?>][compte_tiers]"
                                                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                                        <option value="">--</option>
                                                        <?php foreach ($tiers as $t): ?>
                                                            <option value="<?= $t['compte_tiers'] ?>" <?= ($ligne['compte_tiers'] == $t['compte_tiers']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($t['compte_tiers']) ?> - <?= htmlspecialchars(substr($t['nom'], 0, 15)) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="text" name="details[<?= $index ?>][numero_facture]"
                                                           value="<?= htmlspecialchars($ligne['numero_facture'] ?? '') ?>"
                                                           placeholder="Ex: FA2025001"
                                                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="date" name="details[<?= $index ?>][date_ligne]"
                                                           value="<?= htmlspecialchars($ligne['date_ligne'] ?? '') ?>"
                                                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="text" name="details[<?= $index ?>][libelle]" required
                                                           value="<?= htmlspecialchars($ligne['libelle']) ?>"
                                                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="number" name="details[<?= $index ?>][debit]" step="0.01" min="0"
                                                           value="<?= $ligne['debit'] ?>"
                                                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm text-right focus:outline-none focus:border-blue-500 debit-input"
                                                           onchange="calculateTotals()">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="number" name="details[<?= $index ?>][credit]" step="0.01" min="0"
                                                           value="<?= $ligne['credit'] ?>"
                                                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm text-right focus:outline-none focus:border-blue-500 credit-input"
                                                           onchange="calculateTotals()">
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-300 transition-colors">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="bg-slate-900/50 border-t-2 border-slate-700">
                                    <tr>
                                        <td colspan="4" class="px-3 py-3 text-right font-semibold text-white">TOTAUX:</td>
                                        <td class="px-3 py-3 text-right">
                                            <span id="totalDebit" class="font-bold text-cyan-400 text-lg">0</span>
                                            <span class="text-slate-400 text-sm ml-1">FCFA</span>
                                        </td>
                                        <td class="px-3 py-3 text-right">
                                            <span id="totalCredit" class="font-bold text-pink-400 text-lg">0</span>
                                            <span class="text-slate-400 text-sm ml-1">FCFA</span>
                                        </td>
                                        <td class="px-3 py-3 text-center">
                                            <span id="equilibreIcon"></span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Message d'équilibre -->
                        <div id="equilibreMessage" class="mt-4 p-3 rounded-lg hidden"></div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex items-center justify-between bg-slate-800/50 border border-slate-700/50 rounded-lg p-6">
                        <a href="liste.php" class="text-slate-400 hover:text-white transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </a>
                        <div class="flex gap-3">
                            <button type="submit" name="action" value="save" class="bg-slate-600 hover:bg-slate-700 text-white px-6 py-2.5 rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-save"></i>
                                Enregistrer en brouillon
                            </button>
                            <button type="submit" name="action" value="validate" class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-6 py-2.5 rounded-lg font-medium transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                <i class="fas fa-check-circle"></i>
                                Valider l'écriture
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        let lineIndex = <?= $editMode ? count($lignes) : 0 ?>;
        const comptes = <?= json_encode($comptes) ?>;
        const tiers = <?= json_encode($tiers) ?>;
        const bonsCommande = <?= json_encode($bonsCommande) ?>;

        // Fonction pour afficher/masquer le champ BC selon le journal
        function toggleBonCommande() {
            const journal = document.querySelector('select[name="journal"]').value;
            const container = document.getElementById('bon_commande_container');

            // Afficher uniquement pour le journal ACH (Achats)
            if (journal === 'ACH') {
                container.style.display = 'block';
                filterBCByFournisseur();
            } else {
                container.style.display = 'none';
                document.getElementById('selectBonCommande').value = '';
                document.getElementById('bc_info_container').style.display = 'none';
            }
        }

        // Fonction pour filtrer les BC selon le fournisseur sélectionné
        function filterBCByFournisseur() {
            const selectTiers = document.getElementById('selectTiers');
            const selectBC = document.getElementById('selectBonCommande');
            const selectedFournisseurId = selectTiers.value;
            const currentBCValue = selectBC.value;

            // Réinitialiser les options
            selectBC.innerHTML = '<option value="">-- Aucun BC associé --</option>';

            // Filtrer les BC par fournisseur
            bonsCommande.forEach(bc => {
                // Si un fournisseur est sélectionné, filtrer par ce fournisseur
                // Sinon, afficher tous les BC
                if (!selectedFournisseurId || bc.id_fournisseur == selectedFournisseurId) {
                    const reste = parseFloat(bc.montant_ttc) - parseFloat(bc.montant_consomme);
                    const pourcent = parseFloat(bc.montant_ttc) > 0 ? (parseFloat(bc.montant_consomme) / parseFloat(bc.montant_ttc) * 100).toFixed(1) : 0;

                    const option = document.createElement('option');
                    option.value = bc.id;
                    option.setAttribute('data-fournisseur-id', bc.id_fournisseur);
                    option.setAttribute('data-montant-ttc', bc.montant_ttc);
                    option.setAttribute('data-montant-consomme', bc.montant_consomme);
                    option.setAttribute('data-reste', reste);
                    option.setAttribute('data-pourcent', pourcent);

                    const objetTronque = bc.objet.length > 30 ? bc.objet.substring(0, 30) + '...' : bc.objet;
                    option.textContent = bc.numero_bc + ' - ' + objetTronque + ' (Reste: ' + formatNumber(reste) + ' FCFA - ' + pourcent + '% utilisé)';

                    if (bc.id == currentBCValue) {
                        option.selected = true;
                    }

                    selectBC.appendChild(option);
                }
            });

            updateBCInfo();
        }

        // Fonction pour formater un nombre
        function formatNumber(num) {
            return Math.round(num).toLocaleString('fr-FR');
        }

        // Fonction pour afficher les infos du BC sélectionné
        function updateBCInfo() {
            const selectBC = document.getElementById('selectBonCommande');
            const infoContainer = document.getElementById('bc_info_container');

            if (!selectBC.value) {
                infoContainer.style.display = 'none';
                return;
            }

            const option = selectBC.options[selectBC.selectedIndex];
            const montantTTC = parseFloat(option.getAttribute('data-montant-ttc')) || 0;
            const montantConsomme = parseFloat(option.getAttribute('data-montant-consomme')) || 0;
            const reste = parseFloat(option.getAttribute('data-reste')) || 0;
            const pourcent = parseFloat(option.getAttribute('data-pourcent')) || 0;

            document.getElementById('bc_montant_total').textContent = formatNumber(montantTTC) + ' FCFA';
            document.getElementById('bc_montant_consomme').textContent = formatNumber(montantConsomme) + ' FCFA';
            document.getElementById('bc_montant_reste').textContent = formatNumber(reste) + ' FCFA';
            document.getElementById('bc_pourcent').textContent = pourcent + '%';

            // Mettre à jour la barre de progression
            const progressBar = document.getElementById('bc_progress_bar');
            progressBar.style.width = Math.min(pourcent, 100) + '%';

            // Changer la couleur selon le pourcentage
            if (pourcent >= 100) {
                progressBar.className = 'bg-gradient-to-r from-red-500 to-red-600 h-3 rounded-full transition-all duration-300';
            } else if (pourcent >= 80) {
                progressBar.className = 'bg-gradient-to-r from-orange-500 to-red-500 h-3 rounded-full transition-all duration-300';
            } else {
                progressBar.className = 'bg-gradient-to-r from-purple-500 to-pink-500 h-3 rounded-full transition-all duration-300';
            }

            infoContainer.style.display = 'block';
        }

        // Détecter le mode duplication et pré-remplir le formulaire
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser l'affichage des champs conditionnels
            toggleTypeFacture();
            toggleFactureInitiale();
            toggleBonCommande();


            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('duplicate') === '1') {
                const duplicateData = localStorage.getItem('ecriture_duplicate');
                if (duplicateData) {
                    const data = JSON.parse(duplicateData);

                    // Pré-remplir les champs de l'écriture
                    document.querySelector('input[name="date_ecriture"]').value = new Date().toISOString().split('T')[0];
                    document.querySelector('select[name="journal"]').value = data.ecriture.journal || '';
                    document.querySelector('textarea[name="libelle"]').value = 'Copie - ' + (data.ecriture.libelle || '');

                    if (data.ecriture.id_tiers) {
                        document.querySelector('select[name="id_tiers"]').value = data.ecriture.id_tiers;
                    }

                    // Supprimer TOUTES les lignes existantes (y compris les lignes par défaut)
                    const tbody = document.getElementById('lignesBody');
                    tbody.innerHTML = ''; // Vider complètement le tbody

                    // Ajouter les lignes dupliquées
                    lineIndex = 0;
                    data.lignes.forEach((ligne, index) => {
                        const tr = document.createElement('tr');
                        tr.className = 'border-b border-slate-700/50 ligne-row';

                        tr.innerHTML = `
                            <td class="px-3 py-2">
                                <select name="details[${index}][compte]" required class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 select-compte">
                                    <option value="">Sélectionner</option>
                                    ${comptes.map(c => `<option value="${c.compte}" ${c.compte === ligne.compte ? 'selected' : ''}>${c.compte} - ${c.intitule_compte.substring(0, 20)}</option>`).join('')}
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <select name="details[${index}][compte_tiers]" class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                    <option value="">--</option>
                                    ${tiers.map(t => `<option value="${t.compte_tiers}" ${t.compte_tiers === ligne.compte_tiers ? 'selected' : ''}>${t.compte_tiers} - ${t.nom.substring(0, 15)}</option>`).join('')}
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input type="text" name="details[${index}][numero_facture]" value="${ligne.numero_facture || ''}"
                                       placeholder="Ex: FA2025001"
                                       class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                            </td>
                            <td class="px-3 py-2">
                                <input type="date" name="details[${index}][date_ligne]" value="${new Date().toISOString().split('T')[0]}"
                                       class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                            </td>
                            <td class="px-3 py-2">
                                <input type="text" name="details[${index}][libelle]" required value="${ligne.libelle || ''}"
                                       class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" name="details[${index}][debit]" step="0.01" min="0" value="${ligne.debit || 0}"
                                       class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm text-right focus:outline-none focus:border-blue-500 debit-input"
                                       onchange="calculateTotals()">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" name="details[${index}][credit]" step="0.01" min="0" value="${ligne.credit || 0}"
                                       class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm text-right focus:outline-none focus:border-blue-500 credit-input"
                                       onchange="calculateTotals()">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-300 transition-colors">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        `;

                        tbody.appendChild(tr);
                        lineIndex++;
                    });

                    // Recalculer les totaux
                    calculateTotals();

                    // Nettoyer le localStorage
                    localStorage.removeItem('ecriture_duplicate');

                    // Afficher un message
                    const header = document.querySelector('h1');
                    if (header) {
                        header.innerHTML = 'Nouvelle écriture (copie de ' + data.original_numero + ')';
                    }
                }
            }
        });

        function addLine() {
            const tbody = document.getElementById('lignesBody');
            const tr = document.createElement('tr');
            tr.className = 'border-b border-slate-700/50 ligne-row';

            // Récupérer les valeurs automatiques
            const dateEcriture = document.querySelector('input[name="date_ecriture"]').value || '';
            const autoLibelle = generateAutoLibelle() || '';

            tr.innerHTML = `
                <td class="px-3 py-2">
                    <select name="details[${lineIndex}][compte]" required class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 select-compte">
                        <option value="">Sélectionner</option>
                        ${comptes.map(c => `<option value="${c.compte}">${c.compte} - ${c.intitule_compte.substring(0, 20)}</option>`).join('')}
                    </select>
                </td>
                <td class="px-3 py-2">
                    <select name="details[${lineIndex}][compte_tiers]"
                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                        <option value="">--</option>
                        ${tiers.map(t => `<option value="${t.compte_tiers}">${t.compte_tiers} - ${t.nom.substring(0, 15)}</option>`).join('')}
                    </select>
                </td>
                <td class="px-3 py-2">
                    <input type="text" name="details[${lineIndex}][numero_facture]"
                           placeholder="Ex: FA2025001"
                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                </td>
                <td class="px-3 py-2">
                    <input type="date" name="details[${lineIndex}][date_ligne]" value="${dateEcriture}"
                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                </td>
                <td class="px-3 py-2">
                    <input type="text" name="details[${lineIndex}][libelle]" required value="${autoLibelle}"
                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                </td>
                <td class="px-3 py-2">
                    <input type="number" name="details[${lineIndex}][debit]" step="0.01" min="0" value="0"
                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm text-right focus:outline-none focus:border-blue-500 debit-input"
                           onchange="calculateTotals()">
                </td>
                <td class="px-3 py-2">
                    <input type="number" name="details[${lineIndex}][credit]" step="0.01" min="0" value="0"
                           class="w-full px-2 py-1.5 bg-slate-900/50 border border-slate-700 rounded text-white text-sm text-right focus:outline-none focus:border-blue-500 credit-input"
                           onchange="calculateTotals()">
                </td>
                <td class="px-3 py-2 text-center">
                    <button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-300 transition-colors">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);

            // Ajouter un écouteur sur le select du compte pour détecter les changements
            const newRow = tbody.lastElementChild;
            const selectCompte = newRow.querySelector('.select-compte');
            if (selectCompte) {
                selectCompte.addEventListener('change', function() {
                    updateLineAutoFields(newRow);
                });
            }

            lineIndex++;
            calculateTotals();
        }

        function removeLine(button) {
            if (document.querySelectorAll('.ligne-row').length <= 1) {
                alert('Il doit y avoir au moins une ligne d\'écriture');
                return;
            }
            button.closest('tr').remove();
            calculateTotals();
        }

        function calculateTotals() {
            let totalDebit = 0;
            let totalCredit = 0;

            document.querySelectorAll('.debit-input').forEach(input => {
                totalDebit += parseFloat(input.value) || 0;
            });

            document.querySelectorAll('.credit-input').forEach(input => {
                totalCredit += parseFloat(input.value) || 0;
            });

            document.getElementById('totalDebit').textContent = totalDebit.toLocaleString('fr-FR', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            document.getElementById('totalCredit').textContent = totalCredit.toLocaleString('fr-FR', {minimumFractionDigits: 0, maximumFractionDigits: 0});

            const equilibre = Math.abs(totalDebit - totalCredit) < 0.01;
            const equilibreIcon = document.getElementById('equilibreIcon');
            const equilibreMessage = document.getElementById('equilibreMessage');

            if (totalDebit === 0 && totalCredit === 0) {
                equilibreIcon.innerHTML = '';
                equilibreMessage.className = 'mt-4 p-3 rounded-lg hidden';
            } else if (equilibre) {
                equilibreIcon.innerHTML = '<i class="fas fa-check-circle text-green-400 text-xl"></i>';
                equilibreMessage.className = 'mt-4 p-3 rounded-lg bg-green-500/10 border border-green-500/50 text-green-400';
                equilibreMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Les écritures sont équilibrées';
            } else {
                const ecart = Math.abs(totalDebit - totalCredit);
                equilibreIcon.innerHTML = '<i class="fas fa-exclamation-triangle text-red-400 text-xl"></i>';
                equilibreMessage.className = 'mt-4 p-3 rounded-lg bg-red-500/10 border border-red-500/50 text-red-400';
                equilibreMessage.innerHTML = `<i class="fas fa-exclamation-triangle mr-2"></i>Déséquilibre de ${ecart.toLocaleString('fr-FR', {minimumFractionDigits: 0, maximumFractionDigits: 0})} FCFA`;
            }
        }

        // Fonction pour afficher/masquer le champ Type de facture selon le journal
        function toggleTypeFacture() {
            const journal = document.querySelector('select[name="journal"]').value;
            const container = document.getElementById('type_facture_container');

            // Afficher uniquement pour les journaux ACH (Achats) et VTE (Ventes)
            if (journal === 'ACH' || journal === 'VTE') {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
                // Réinitialiser les valeurs
                document.getElementById('type_facture').value = '';
                document.getElementById('facture_initiale').value = '';
                document.getElementById('facture_initiale_container').style.display = 'none';
            }

            // Mettre à jour aussi le champ BC
            toggleBonCommande();
        }

        // Fonction pour afficher/masquer le champ Facture initiale selon le type
        function toggleFactureInitiale() {
            const typeFacture = document.getElementById('type_facture').value;
            const container = document.getElementById('facture_initiale_container');

            // Afficher uniquement si type = AVOIR
            if (typeFacture === 'AVOIR') {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
                document.getElementById('facture_initiale').value = '';
            }
        }

        // Fonction pour mettre à jour le code tiers automatiquement
        function updateCodeTiers() {
            const selectTiers = document.getElementById('selectTiers');
            const codeTiersInput = document.getElementById('codeTiers');

            if (selectTiers.value) {
                const selectedOption = selectTiers.options[selectTiers.selectedIndex];
                const codeTiers = selectedOption.getAttribute('data-code-tiers');
                codeTiersInput.value = codeTiers || '';
            } else {
                codeTiersInput.value = '';
            }

            // Mettre à jour les lignes existantes avec le nouveau code tiers
            updateAllLinesAutoFields();

            // Filtrer les BC par le fournisseur sélectionné
            filterBCByFournisseur();
        }

        // Fonction pour générer le libellé automatique
        function generateAutoLibelle() {
            const journal = document.querySelector('select[name="journal"]').value;
            const dateEcriture = document.querySelector('input[name="date_ecriture"]').value;
            const selectTiers = document.getElementById('selectTiers');
            const libelleEcriture = document.querySelector('textarea[name="libelle"]').value;

            if (!journal || !dateEcriture || !libelleEcriture) {
                return '';
            }

            // Convertir la date en format MOISAA (ex: AVR25 pour avril 2025)
            const date = new Date(dateEcriture);
            const mois = ['JAN', 'FEV', 'MAR', 'AVR', 'MAI', 'JUN', 'JUL', 'AOU', 'SEP', 'OCT', 'NOV', 'DEC'];
            const moisAbrege = mois[date.getMonth()];
            const anneeAbrege = date.getFullYear().toString().substr(-2);
            const moisAA = moisAbrege + anneeAbrege;

            // Récupérer l'abréviation ou le nom du tiers sélectionné
            let nomTiers = '';
            if (selectTiers && selectTiers.value && selectTiers.selectedIndex > 0) {
                // Utiliser l'abréviation si disponible, sinon le nom complet
                const selectedOption = selectTiers.options[selectTiers.selectedIndex];
                nomTiers = selectedOption.getAttribute('data-abreviation') || '';
                // Si pas d'abréviation, extraire le nom depuis le texte de l'option
                if (!nomTiers) {
                    const selectedText = selectedOption.text;
                    nomTiers = selectedText.split('(')[0].trim();
                }
            }

            // Construire le libellé selon si un tiers est sélectionné ou non
            let libelle;
            if (nomTiers && nomTiers !== '') {
                // Avec tiers: JOURNAL/MOISAA/ABREVIATION_TIERS/LIBELLE
                libelle = journal + '/' + moisAA + '/' + nomTiers + '/' + libelleEcriture;
            } else {
                // Sans tiers: JOURNAL/MOISAA/LIBELLE
                libelle = journal + '/' + moisAA + '/' + libelleEcriture;
            }

            return libelle;
        }

        // Fonction pour vérifier si un compte nécessite un tiers/facture
        function needsTiersAndFacture(compte) {
            if (!compte) return false;
            const compteStr = compte.toString();
            return compteStr.startsWith('4011') ||
                   compteStr.startsWith('4012') ||
                   compteStr.startsWith('4111') ||
                   compteStr.startsWith('4112') ||
                   compteStr.startsWith('422');
        }

        // Fonction pour mettre à jour les champs d'une ligne selon le compte sélectionné
        function updateLineAutoFields(row) {
            const selectCompte = row.querySelector('select[name*="[compte]"]');
            const compteValue = selectCompte ? selectCompte.value : '';

            const dateEcriture = document.querySelector('input[name="date_ecriture"]').value;
            const codeTiers = document.getElementById('codeTiers').value;
            const referencePiece = document.querySelector('input[name="reference_piece"]').value;
            const autoLibelle = generateAutoLibelle();

            // Vérifier si ce compte nécessite tiers et facture
            const needsAuto = needsTiersAndFacture(compteValue);

            // Mettre à jour le compte tiers
            const selectCompteTiers = row.querySelector('select[name*="[compte_tiers]"]');
            if (selectCompteTiers) {
                if (needsAuto && codeTiers) {
                    // Chercher l'option qui correspond au code tiers
                    const option = Array.from(selectCompteTiers.options).find(opt => opt.value === codeTiers);
                    if (option) {
                        selectCompteTiers.value = codeTiers;
                    }
                }
            }

            // Mettre à jour le numéro de facture
            const inputNumeroFacture = row.querySelector('input[name*="[numero_facture]"]');
            if (inputNumeroFacture) {
                if (needsAuto && referencePiece) {
                    inputNumeroFacture.value = referencePiece;
                }
            }

            // Mettre à jour la date facture
            const inputDateLigne = row.querySelector('input[name*="[date_ligne]"]');
            if (inputDateLigne && dateEcriture) {
                inputDateLigne.value = dateEcriture;
            }

            // Mettre à jour le libellé
            const inputLibelle = row.querySelector('input[name*="[libelle]"]');
            if (inputLibelle && autoLibelle) {
                // Vérifier si le libellé actuel semble être généré automatiquement (contient des "/")
                // ou s'il est vide
                const currentLibelle = inputLibelle.value;
                const seemsAutoGenerated = currentLibelle === '' || currentLibelle.includes('/');

                if (seemsAutoGenerated) {
                    inputLibelle.value = autoLibelle;
                }
            }
        }

        // Fonction pour mettre à jour tous les champs automatiques des lignes
        function updateAllLinesAutoFields() {
            // Mettre à jour toutes les lignes
            document.querySelectorAll('.ligne-row').forEach(row => {
                updateLineAutoFields(row);
            });
        }

        // Initialiser le code tiers si un tiers est déjà sélectionné (mode édition)
        <?php if ($editMode && $ecriture['id_tiers']): ?>
        updateCodeTiers();
        <?php endif; ?>

        // Initialiser avec 2 lignes si mode création
        <?php if (!$editMode): ?>
        addLine();
        addLine();
        <?php else: ?>
        calculateTotals();
        <?php endif; ?>

        // Ajouter des écouteurs d'événements pour mettre à jour automatiquement les lignes
        document.querySelector('select[name="journal"]').addEventListener('change', updateAllLinesAutoFields);
        document.querySelector('input[name="date_ecriture"]').addEventListener('change', updateAllLinesAutoFields);
        document.querySelector('textarea[name="libelle"]').addEventListener('input', updateAllLinesAutoFields);
        document.querySelector('input[name="reference_piece"]').addEventListener('input', updateAllLinesAutoFields);

        // Ajouter des écouteurs sur les lignes existantes (mode édition)
        document.querySelectorAll('.ligne-row').forEach(row => {
            const selectCompte = row.querySelector('select[name*="[compte]"]');
            if (selectCompte) {
                selectCompte.addEventListener('change', function() {
                    updateLineAutoFields(row);
                });
            }
        });

        // Validation du formulaire
        document.getElementById('ecritureForm').addEventListener('submit', function(e) {
            const totalDebit = parseFloat(document.getElementById('totalDebit').textContent.replace(/\s/g, '')) || 0;
            const totalCredit = parseFloat(document.getElementById('totalCredit').textContent.replace(/\s/g, '')) || 0;

            if (Math.abs(totalDebit - totalCredit) > 0.01) {
                if (!confirm('Les écritures ne sont pas équilibrées. Voulez-vous quand même continuer ?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    </script>
</body>
</html>
