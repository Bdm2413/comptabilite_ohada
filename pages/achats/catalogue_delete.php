<?php
/**
 * Suppression d'un article du catalogue fournisseurs
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { echo json_encode(['success' => false, 'message' => 'Aucune société sélectionnée']); exit; }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);

try {
    if ($id <= 0) {
        throw new Exception('ID invalide');
    }

    // Vérifier si l'article est utilisé dans des devis ou BC
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM lignes_devis WHERE id_article_catalogue = ?
        UNION ALL
        SELECT COUNT(*) FROM lignes_bon_commande WHERE id_article_catalogue = ?
    ");
    $stmt->execute([$id, $id]);
    $counts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (array_sum($counts) > 0) {
        throw new Exception('Cet article est utilisé dans des devis ou bons de commande et ne peut pas être supprimé');
    }

    $stmt = $db->prepare("DELETE FROM catalogues_fournisseurs WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);

    echo json_encode(['success' => true, 'message' => 'Article supprimé avec succès']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
