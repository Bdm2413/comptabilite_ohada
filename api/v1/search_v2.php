<?php
// Version ultra-simple sans gestionnaire d'erreur
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../config/config.php';

    header('Content-Type: application/json');

    $db = Database::getInstance()->getConnection();

    $query = isset($_GET['q']) ? trim($_GET['q']) : '';

    if (empty($query)) {
        echo json_encode(['success' => false, 'error' => 'Paramètre q manquant']);
        exit();
    }

    // Test simple de recherche
    $searchTerm = '%' . $query . '%';

    $stmt = $db->prepare("
        SELECT
            'ecriture' as type,
            id,
            numero_ecriture,
            num_piece,
            libelle,
            date_ecriture,
            statut,
            montant_total,
            CONCAT(COALESCE(num_piece, numero_ecriture), ' - ', libelle) as display_text,
            'pages/ecritures/modifier_ecriture.php?id=' as url
        FROM ecritures
        WHERE num_piece LIKE :search1
           OR libelle LIKE :search2
           OR reference_piece LIKE :search3
           OR numero_ecriture LIKE :search4
        LIMIT 10
    ");

    $stmt->execute([
        ':search1' => $searchTerm,
        ':search2' => $searchTerm,
        ':search3' => $searchTerm,
        ':search4' => $searchTerm
    ]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'query' => $query,
        'total' => count($results),
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
