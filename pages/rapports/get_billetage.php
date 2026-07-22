<?php
require_once '../../config/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) throw new Exception('Aucune société sélectionnée');
    $id = $_GET['id'] ?? 0;

    if (empty($id)) {
        throw new Exception("ID manquant");
    }

    $stmt = $db->prepare("SELECT * FROM billetages WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $billetage = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$billetage) {
        throw new Exception("Billetage introuvable");
    }

    echo json_encode([
        'success' => true,
        'billetage' => $billetage
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
