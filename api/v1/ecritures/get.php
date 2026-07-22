<?php
/**
 * API pour récupérer une écriture et ses lignes
 */

require_once '../../../config/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Non authentifié'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = $_GET['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID manquant'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = Database::getInstance()->getConnection();

    // Récupérer l'écriture
    $stmt = $db->prepare("SELECT * FROM ecritures WHERE id = ?");
    $stmt->execute([$id]);
    $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ecriture) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Écriture introuvable'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Récupérer les lignes
    $stmt = $db->prepare("SELECT * FROM lignes_ecriture WHERE id_ecriture = ? ORDER BY id");
    $stmt->execute([$id]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ecriture' => $ecriture,
        'lignes' => $lignes
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
