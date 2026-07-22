<?php
/**
 * Sauvegarde d'une réception de bon de commande
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: receptions.php');
    exit;
}

try {
    $db->beginTransaction();

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $id_bon_commande = (int)$_POST['id_bon_commande'];
    $type_reception = $_POST['type_reception'];
    $numero_reception = trim($_POST['numero_reception']);
    $date_reception = $_POST['date_reception'];
    $numero_document = trim($_POST['numero_document'] ?? '');
    $date_document = !empty($_POST['date_document']) ? $_POST['date_document'] : null;
    $observations = trim($_POST['observations'] ?? '');
    $montant_total_ht = (float)$_POST['montant_total_ht'];
    $montant_tva = (float)$_POST['montant_tva'];
    $montant_retenue = (float)$_POST['montant_retenue'];
    $net_a_payer = (float)$_POST['net_a_payer'];
    $lignes = $_POST['lignes'] ?? [];

    // Validation
    if (empty($id_bon_commande)) {
        throw new Exception('Veuillez sélectionner un bon de commande');
    }

    if (empty($numero_reception)) {
        throw new Exception('Le numéro de réception est obligatoire');
    }

    // Vérifier l'unicité du numéro
    $stmt = $db->prepare("SELECT id FROM receptions_bc WHERE numero_reception = ? AND id != ?");
    $stmt->execute([$numero_reception, $id]);
    if ($stmt->fetch()) {
        throw new Exception('Ce numéro de réception existe déjà');
    }

    // Vérifier que le BC existe et est dans un statut valide
    $stmt = $db->prepare("SELECT * FROM bons_commande WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id_bon_commande, $societe_id]);
    $bc = $stmt->fetch();

    if (!$bc) {
        throw new Exception('Bon de commande introuvable');
    }

    if (!in_array($bc['statut'], ['Validé', 'En cours', 'Partiellement reçu'])) {
        throw new Exception('Ce bon de commande ne peut pas recevoir de réceptions');
    }

    // Mode édition : supprimer les anciennes lignes et ajuster les compteurs
    if ($id > 0) {
        // Récupérer les anciennes lignes pour ajuster les quantités/montants du BC
        $stmt = $db->prepare("SELECT * FROM lignes_reception_bc WHERE id_reception = ?");
        $stmt->execute([$id]);
        $anciennes_lignes = $stmt->fetchAll();

        foreach ($anciennes_lignes as $al) {
            // Retrancher des lignes BC
            $stmt = $db->prepare("
                UPDATE lignes_bon_commande
                SET quantite_recue = quantite_recue - ?,
                    montant_facture = montant_facture - ?
                WHERE id = ?
            ");
            $stmt->execute([$al['quantite_recue'], $al['montant_facture'], $al['id_ligne_bc']]);
        }

        // Supprimer les anciennes lignes
        $stmt = $db->prepare("DELETE FROM lignes_reception_bc WHERE id_reception = ?");
        $stmt->execute([$id]);
    }

    // Insérer ou mettre à jour la réception
    if ($id > 0) {
        $stmt = $db->prepare("
            UPDATE receptions_bc SET
                type_reception = ?,
                numero_reception = ?,
                date_reception = ?,
                numero_document = ?,
                date_document = ?,
                observations = ?,
                montant_total_ht = ?,
                montant_tva = ?,
                montant_retenue = ?,
                net_a_payer = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $type_reception, $numero_reception, $date_reception,
            $numero_document, $date_document, $observations,
            $montant_total_ht, $montant_tva, $montant_retenue, $net_a_payer,
            $id
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO receptions_bc
            (id_bon_commande, type_reception, numero_reception, date_reception,
             numero_document, date_document, observations,
             montant_total_ht, montant_tva, montant_retenue, net_a_payer)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id_bon_commande, $type_reception, $numero_reception, $date_reception,
            $numero_document, $date_document, $observations,
            $montant_total_ht, $montant_tva, $montant_retenue, $net_a_payer
        ]);
        $id = $db->lastInsertId();
    }

    // Insérer les nouvelles lignes
    $stmt_ligne = $db->prepare("
        INSERT INTO lignes_reception_bc
        (id_reception, id_ligne_bc, quantite_recue, montant_facture)
        VALUES (?, ?, ?, ?)
    ");

    $stmt_update_bc = $db->prepare("
        UPDATE lignes_bon_commande
        SET quantite_recue = quantite_recue + ?,
            montant_facture = montant_facture + ?
        WHERE id = ?
    ");

    foreach ($lignes as $ligne) {
        $qte = (float)($ligne['quantite_recue'] ?? 0);
        $montant = (float)($ligne['montant_facture'] ?? 0);

        // Ne pas insérer les lignes à zéro
        if ($type_reception == 'Livraison' && $qte <= 0) continue;
        if ($type_reception == 'Facture' && $montant <= 0) continue;

        $stmt_ligne->execute([
            $id,
            $ligne['id_ligne_bc'],
            $qte,
            $montant
        ]);

        // Mettre à jour les compteurs du BC
        $stmt_update_bc->execute([$qte, $montant, $ligne['id_ligne_bc']]);
    }

    // Mettre à jour le statut du BC
    updateStatutBC($db, $id_bon_commande);

    $db->commit();

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Réception enregistrée avec succès'];
    header('Location: receptions.php');
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    header('Location: reception_form.php' . ($id > 0 ? '?id=' . $id : ''));
    exit;
}

/**
 * Met à jour le statut du BC en fonction des réceptions
 */
function updateStatutBC($db, $bc_id) {
    // Récupérer le BC
    $stmt = $db->prepare("SELECT type_suivi FROM bons_commande WHERE id = ?");
    $stmt->execute([$bc_id]);
    $bc = $stmt->fetch();

    // Vérifier l'état des lignes
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as nb_lignes,
            SUM(CASE WHEN type_suivi = 'Quantité' AND quantite_recue >= quantite_commandee THEN 1
                     WHEN type_suivi = 'Montant' AND montant_facture >= montant_commandee THEN 1
                     ELSE 0 END) as lignes_completes,
            SUM(CASE WHEN quantite_recue > 0 OR montant_facture > 0 THEN 1 ELSE 0 END) as lignes_partielles
        FROM lignes_bon_commande
        WHERE id_bon_commande = ?
    ");
    $stmt->execute([$bc_id]);
    $stats = $stmt->fetch();

    $nouveau_statut = 'Validé';

    if ($stats['lignes_completes'] == $stats['nb_lignes']) {
        $nouveau_statut = 'Soldé';
    } elseif ($stats['lignes_partielles'] > 0) {
        $nouveau_statut = 'Partiellement reçu';
    }

    $stmt = $db->prepare("UPDATE bons_commande SET statut = ? WHERE id = ?");
    $stmt->execute([$nouveau_statut, $bc_id]);
}
