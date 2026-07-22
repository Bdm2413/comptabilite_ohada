<?php
require_once '../config/config.php';
requireLogin();

// Vérifier que l'utilisateur est admin
if (!hasRole('admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    $userId = $_GET['id_utilisateur'] ?? null;

    if (!$userId) {
        throw new Exception('ID utilisateur manquant');
    }

    // Récupérer les 50 derniers logs de cet utilisateur
    $stmt = $db->prepare("
        SELECT
            action,
            table_affectee,
            details,
            ip_address,
            DATE_FORMAT(date_action, '%d/%m/%Y à %H:%i:%s') as date_action
        FROM logs_activite
        WHERE id_utilisateur = ?
        ORDER BY date_action DESC
        LIMIT 50
    ");

    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
