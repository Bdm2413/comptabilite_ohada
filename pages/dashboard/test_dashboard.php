<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Test de récupération de données basiques
header('Content-Type: application/json');

try {
    // Test 1: Compter les écritures
    $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures");
    $totalEcritures = $stmt->fetch()['total'];

    // Test 2: CA du mois
    $stmt = $db->query("
        SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE LEFT(le.compte, 1) = '7'
          AND e.statut = 'Validé'
    ");
    $ca = $stmt->fetch()['ca'];

    echo json_encode([
        'success' => true,
        'data' => [
            'total_ecritures' => $totalEcritures,
            'ca_total' => $ca,
            'database_ok' => true
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
