<?php
/**
 * Fonctions de sécurité
 */

/**
 * Vérifie si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

/**
 * Vérifie si l'utilisateur a un rôle spécifique
 */
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Vérifie si l'utilisateur est administrateur (admin ou super_admin)
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin', 'Admin', 'Super_admin']);
}

/**
 * Redirige vers la page de connexion si l'utilisateur n'est pas connecté
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/pages/auth/login.php');
        exit();
    }
}

/**
 * Redirige vers une page avec un message
 */
function redirectWithMessage($url, $message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url);
    exit();
}

/**
 * Récupère et supprime le message flash
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Nettoie une chaîne pour éviter les injections
 * Note: N'encode PAS en HTML - l'encodage se fait à l'affichage avec htmlspecialchars()
 */
function cleanInput($data) {
    if ($data === null || $data === '') {
        return $data;
    }
    $data = trim($data);
    $data = stripslashes($data);
    // Ne PAS utiliser htmlspecialchars ici - cela cause un double encodage
    // L'encodage HTML doit se faire uniquement lors de l'affichage
    return $data;
}

/**
 * Génère un token CSRF
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le token CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Hache un mot de passe
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Vérifie un mot de passe
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Enregistre une activité dans les logs
 */
function logActivity($action, $table = null, $id = null, $details = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO logs_activite (id_utilisateur, action, table_affectee, id_enregistrement, details, ip_address)
            VALUES (:user_id, :action, :table, :id, :details, :ip)
        ");

        $stmt->execute([
            'user_id' => $_SESSION['user_id'] ?? null,
            'action' => $action,
            'table' => $table,
            'id' => $id,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Erreur lors de l'enregistrement de l'activité: " . $e->getMessage());
    }
}
