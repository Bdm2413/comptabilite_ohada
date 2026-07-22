<?php
/**
 * Configuration de l'API REST
 * ComptaSYSCOHADA API v1.0
 */

// Headers CORS pour permettre les requêtes cross-origin
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=UTF-8');

// Gérer les requêtes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Charger la configuration principale
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Configuration JWT
define('JWT_SECRET_KEY', 'votre_cle_secrete_super_securisee_2025'); // À CHANGER en production
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRATION', 3600 * 24); // 24 heures

// Configuration Rate Limiting
define('RATE_LIMIT_REQUESTS', 100); // Nombre de requêtes
define('RATE_LIMIT_WINDOW', 3600);  // Par heure (en secondes)

// Version de l'API
define('API_VERSION', 'v1');
define('API_BASE_PATH', '/api/v1');

// Messages d'erreur standardisés
define('ERROR_UNAUTHORIZED', 'Unauthorized - Token manquant ou invalide');
define('ERROR_FORBIDDEN', 'Forbidden - Accès non autorisé');
define('ERROR_NOT_FOUND', 'Resource not found');
define('ERROR_BAD_REQUEST', 'Bad request - Données invalides');
define('ERROR_RATE_LIMIT', 'Rate limit exceeded - Trop de requêtes');
define('ERROR_SERVER', 'Internal server error');

/**
 * Fonction pour renvoyer une réponse JSON standardisée
 */
function sendResponse($statusCode, $data = null, $message = null) {
    http_response_code($statusCode);

    $response = [
        'success' => $statusCode >= 200 && $statusCode < 300,
        'status' => $statusCode,
        'timestamp' => date('c'),
        'api_version' => API_VERSION
    ];

    if ($message !== null) {
        $response['message'] = $message;
    }

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Fonction pour renvoyer une erreur JSON standardisée
 */
function sendError($statusCode, $message, $details = null) {
    http_response_code($statusCode);

    $response = [
        'success' => false,
        'status' => $statusCode,
        'error' => [
            'message' => $message,
            'code' => $statusCode
        ],
        'timestamp' => date('c'),
        'api_version' => API_VERSION
    ];

    if ($details !== null) {
        $response['error']['details'] = $details;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Récupérer le corps de la requête JSON
 */
function getRequestBody() {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE && !empty($body)) {
        sendError(400, ERROR_BAD_REQUEST, 'Invalid JSON format');
    }

    return $data ?? [];
}

/**
 * Récupérer les paramètres de pagination
 */
function getPaginationParams() {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Formater une réponse paginée
 */
function formatPaginatedResponse($data, $total, $page, $limit) {
    $totalPages = ceil($total / $limit);

    return [
        'items' => $data,
        'pagination' => [
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total_pages' => (int)$totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ]
    ];
}

/**
 * Logger les requêtes API (optionnel)
 */
function logApiRequest($endpoint, $method, $userId = null, $statusCode = null) {
    $logFile = __DIR__ . '/../logs/api_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logEntry = sprintf(
        "[%s] %s %s - User: %s - Status: %s - IP: %s\n",
        date('Y-m-d H:i:s'),
        $method,
        $endpoint,
        $userId ?? 'guest',
        $statusCode ?? '-',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );

    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
