<?php
require_once '../../config/config.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    // Vérifier la structure de la table code_journal
    $stmt = $db->query("DESCRIBE code_journal");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'columns' => $columns
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
