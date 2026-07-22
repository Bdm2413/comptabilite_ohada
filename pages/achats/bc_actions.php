<?php
/**
 * Actions sur les bons de commande (valider, annuler, supprimer)
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { echo json_encode(['success' => false, 'message' => 'Aucune société sélectionnée']); exit; }

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$id = (int)($data['id'] ?? 0);

try {
    switch ($action) {
        case 'valider':
            validerBC($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Bon de commande validé']);
            break;

        case 'annuler':
            annulerBC($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Bon de commande annulé']);
            break;

        case 'supprimer':
            supprimerBC($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Bon de commande supprimé']);
            break;

        case 'cloturer':
            cloturerBC($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Bon de commande clôturé']);
            break;

        default:
            throw new Exception('Action non reconnue');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function validerBC($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM bons_commande WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $bc = $stmt->fetch();

    if (!$bc) {
        throw new Exception('Bon de commande introuvable');
    }

    if ($bc['statut'] != 'Brouillon') {
        throw new Exception('Seuls les BC en brouillon peuvent être validés');
    }

    $stmt = $db->prepare("UPDATE bons_commande SET statut = 'Validé', date_validation = CURDATE() WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
}

function annulerBC($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM bons_commande WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $bc = $stmt->fetch();

    if (!$bc) {
        throw new Exception('Bon de commande introuvable');
    }

    if (!in_array($bc['statut'], ['Brouillon', 'Validé'])) {
        throw new Exception('Ce BC ne peut plus être annulé');
    }

    // Vérifier s'il y a des réceptions
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM receptions_bc WHERE id_bon_commande = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()['count'] > 0) {
        throw new Exception('Ce BC a des réceptions et ne peut pas être annulé');
    }

    $stmt = $db->prepare("UPDATE bons_commande SET statut = 'Annulé' WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
}

function supprimerBC($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM bons_commande WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $bc = $stmt->fetch();

    if (!$bc) {
        throw new Exception('Bon de commande introuvable');
    }

    if (!in_array($bc['statut'], ['Brouillon', 'Annulé'])) {
        throw new Exception('Seuls les BC en brouillon ou annulés peuvent être supprimés');
    }

    // Vérifier s'il y a des réceptions
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM receptions_bc WHERE id_bon_commande = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()['count'] > 0) {
        throw new Exception('Ce BC a des réceptions et ne peut pas être supprimé');
    }

    $db->beginTransaction();
    try {
        // Supprimer les lignes
        $stmt = $db->prepare("DELETE FROM lignes_bon_commande WHERE id_bon_commande = ?");
        $stmt->execute([$id]);

        // Supprimer le BC
        $stmt = $db->prepare("DELETE FROM bons_commande WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id, $societe_id]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function cloturerBC($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM bons_commande WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $bc = $stmt->fetch();

    if (!$bc) {
        throw new Exception('Bon de commande introuvable');
    }

    if (!in_array($bc['statut'], ['Validé', 'En cours', 'Partiellement reçu'])) {
        throw new Exception('Ce BC ne peut pas être clôturé');
    }

    $stmt = $db->prepare("UPDATE bons_commande SET statut = 'Soldé' WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
}
