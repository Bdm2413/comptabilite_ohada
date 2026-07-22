<?php
/**
 * Middleware d'authentification pour l'API
 */

require_once __DIR__ . '/JWT.php';

class AuthMiddleware {

    /**
     * Vérifier l'authentification
     * Retourne les données de l'utilisateur si authentifié
     */
    public static function authenticate() {
        // Récupérer le token
        $token = JWT::getBearerToken();

        if (!$token) {
            sendError(401, ERROR_UNAUTHORIZED, 'Token missing');
        }

        try {
            // Décoder et valider le token
            $payload = JWT::decode($token);

            // Vérifier que les champs requis sont présents
            if (!isset($payload['user_id']) || !isset($payload['email'])) {
                sendError(401, ERROR_UNAUTHORIZED, 'Invalid token payload');
            }

            // Retourner les données utilisateur
            return [
                'user_id' => $payload['user_id'],
                'email' => $payload['email'],
                'nom' => $payload['nom'] ?? null,
                'role' => $payload['role'] ?? 'user'
            ];

        } catch (Exception $e) {
            sendError(401, ERROR_UNAUTHORIZED, $e->getMessage());
        }
    }

    /**
     * Vérifier le rôle de l'utilisateur
     */
    public static function requireRole($user, $requiredRole) {
        $roles = ['user', 'comptable', 'admin'];
        $userRoleIndex = array_search($user['role'], $roles);
        $requiredRoleIndex = array_search($requiredRole, $roles);

        if ($userRoleIndex === false || $userRoleIndex < $requiredRoleIndex) {
            sendError(403, ERROR_FORBIDDEN, "Role '$requiredRole' required");
        }
    }

    /**
     * Générer un token pour un utilisateur
     */
    public static function generateToken($user) {
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'nom' => $user['nom'] ?? $user['nom_utilisateur'],
            'role' => $user['role'] ?? 'user'
        ];

        return JWT::encode($payload);
    }
}
