<?php
/**
 * API Endpoint: Assistant IA Conversationnel
 *
 * POST /api/v1/ai/chat.php
 * Body: { "question": "Quel est mon CA du mois ?" }
 *
 * GET /api/v1/ai/chat.php?action=history&limit=10
 * Récupère l'historique des conversations
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../config/config.php';
require_once '../../../includes/AIAssistant.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Vérifier l'authentification
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Non authentifié. Veuillez vous connecter.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $userId = $_SESSION['user_id'];
    $assistant = new AIAssistant($userId);

    // GET: Récupérer l'historique
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'history') {
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
            $history = $assistant->getHistory($limit);

            echo json_encode([
                'success' => true,
                'history' => $history,
                'total' => count($history)
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // Action non reconnue
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Action non reconnue. Utilisez ?action=history'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // POST: Poser une question
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Récupérer le body JSON
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['question'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Paramètre "question" manquant'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $question = trim($data['question']);

        if (empty($question)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'La question ne peut pas être vide'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // Limiter la longueur
        if (mb_strlen($question) > 500) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Question trop longue (max 500 caractères)'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // Traiter la question
        $result = $assistant->processQuestion($question);

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // Méthode non autorisée
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Méthode non autorisée. Utilisez GET ou POST'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur serveur: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
?>
