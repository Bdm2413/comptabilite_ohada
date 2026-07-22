<?php
/**
 * API Authentication Endpoint
 * POST /api/v1/auth/login - Obtenir un token JWT
 * POST /api/v1/auth/refresh - Rafraîchir un token
 * POST /api/v1/auth/logout - Invalider un token (optionnel)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/Auth.php';
require_once __DIR__ . '/../middleware/RateLimit.php';

// Appliquer le rate limiting
RateLimit::check();

// Récupérer la méthode HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Router
switch ($method) {
    case 'POST':
        handlePost();
        break;

    default:
        sendError(405, 'Method not allowed');
}

/**
 * Gérer les requêtes POST
 */
function handlePost() {
    // Récupérer l'action depuis l'URL
    $path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';

    if (strpos($path, '/login') !== false) {
        login();
    } elseif (strpos($path, '/refresh') !== false) {
        refresh();
    } elseif (strpos($path, '/logout') !== false) {
        logout();
    } else {
        sendError(404, ERROR_NOT_FOUND);
    }
}

/**
 * Login - Générer un token JWT
 */
function login() {
    $data = getRequestBody();

    // Validation
    if (empty($data['email']) || empty($data['password'])) {
        sendError(400, ERROR_BAD_REQUEST, 'Email and password required');
    }

    try {
        $db = Database::getInstance()->getConnection();

        // Rechercher l'utilisateur
        $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE email = ? LIMIT 1");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Vérifier l'utilisateur et le mot de passe
        if (!$user || !password_verify($data['password'], $user['password'])) {
            // Logger la tentative échouée
            logApiRequest('/auth/login', 'POST', null, 401);
            sendError(401, 'Invalid credentials', 'Email or password incorrect');
        }

        // Générer le token
        $token = AuthMiddleware::generateToken($user);

        // Logger la connexion réussie
        logApiRequest('/auth/login', 'POST', $user['id'], 200);

        // Réponse
        sendResponse(200, [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => JWT_EXPIRATION,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'nom' => $user['nom'] ?? $user['nom_utilisateur'],
                'role' => $user['role'] ?? 'user'
            ]
        ], 'Login successful');

    } catch (Exception $e) {
        logApiRequest('/auth/login', 'POST', null, 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * Refresh - Rafraîchir un token
 */
function refresh() {
    // Authentifier avec le token actuel
    $user = AuthMiddleware::authenticate();

    try {
        $db = Database::getInstance()->getConnection();

        // Récupérer les infos utilisateur actualisées
        $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ? LIMIT 1");
        $stmt->execute([$user['user_id']]);
        $userDB = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userDB) {
            sendError(401, ERROR_UNAUTHORIZED, 'User not found');
        }

        // Générer un nouveau token
        $token = AuthMiddleware::generateToken($userDB);

        // Logger
        logApiRequest('/auth/refresh', 'POST', $user['user_id'], 200);

        sendResponse(200, [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => JWT_EXPIRATION
        ], 'Token refreshed successfully');

    } catch (Exception $e) {
        logApiRequest('/auth/refresh', 'POST', $user['user_id'] ?? null, 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * Logout - Invalider un token (optionnel - côté client)
 */
function logout() {
    // Authentifier
    $user = AuthMiddleware::authenticate();

    // Logger
    logApiRequest('/auth/logout', 'POST', $user['user_id'], 200);

    // Note: Avec JWT, le logout est géré côté client en supprimant le token
    // Pour une vraie invalidation, il faudrait une blacklist de tokens

    sendResponse(200, null, 'Logged out successfully. Please delete the token client-side.');
}
