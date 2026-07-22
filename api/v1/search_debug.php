<?php
// Version de debug pour voir les erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!-- DEBUG: Script started -->\n";

require_once '../../config/config.php';

echo "<!-- DEBUG: Config loaded -->\n";

// Vérifier la session
session_start();
echo "<!-- DEBUG: Session ID = " . session_id() . " -->\n";
echo "<!-- DEBUG: User = " . ($_SESSION['nom_utilisateur'] ?? 'NOT SET') . " -->\n";

// Tester la connexion DB
try {
    $db = Database::getInstance()->getConnection();
    echo "<!-- DEBUG: DB connected -->\n";
} catch (Exception $e) {
    echo "<!-- DEBUG: DB ERROR = " . $e->getMessage() . " -->\n";
    die(json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]));
}

// Tester une requête simple
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures");
    $count = $stmt->fetch()['total'];
    echo "<!-- DEBUG: Ecritures count = $count -->\n";
} catch (Exception $e) {
    echo "<!-- DEBUG: Query ERROR = " . $e->getMessage() . " -->\n";
}

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($query)) {
    echo json_encode([
        'success' => false,
        'error' => 'Paramètre q manquant',
        'debug' => [
            'get_params' => $_GET,
            'session_user' => $_SESSION['nom_utilisateur'] ?? 'not set'
        ]
    ]);
    exit();
}

// Test simple de recherche
try {
    $searchTerm = '%' . $query . '%';

    $stmt = $db->prepare("
        SELECT
            'ecriture' as type,
            e.id,
            e.numero_piece,
            e.libelle,
            CONCAT(e.numero_piece, ' - ', e.libelle) as display_text
        FROM ecritures e
        WHERE e.numero_piece LIKE :search OR e.libelle LIKE :search
        LIMIT 5
    ");

    $stmt->execute([':search' => $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'query' => $query,
        'total' => count($results),
        'results' => $results,
        'debug' => [
            'search_term' => $searchTerm,
            'session_ok' => isset($_SESSION['nom_utilisateur'])
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
