<?php
/**
 * Middleware de Rate Limiting pour l'API
 * Limite le nombre de requêtes par IP/utilisateur
 */

class RateLimit {

    private static $storageFile = __DIR__ . '/../../logs/rate_limit.json';

    /**
     * Vérifier et appliquer le rate limiting
     */
    public static function check($identifier = null) {
        // Utiliser l'IP comme identifiant par défaut
        $identifier = $identifier ?? self::getClientIP();

        // Charger les données de rate limiting
        $data = self::loadData();

        // Nettoyer les anciennes entrées
        $data = self::cleanOldEntries($data);

        // Vérifier le nombre de requêtes
        if (!isset($data[$identifier])) {
            $data[$identifier] = [
                'count' => 0,
                'reset_at' => time() + RATE_LIMIT_WINDOW
            ];
        }

        $entry = $data[$identifier];

        // Si la fenêtre est expirée, réinitialiser
        if ($entry['reset_at'] < time()) {
            $data[$identifier] = [
                'count' => 0,
                'reset_at' => time() + RATE_LIMIT_WINDOW
            ];
            $entry = $data[$identifier];
        }

        // Incrémenter le compteur
        $data[$identifier]['count']++;

        // Vérifier la limite
        if ($data[$identifier]['count'] > RATE_LIMIT_REQUESTS) {
            self::saveData($data);

            // Ajouter les headers de rate limit
            header('X-RateLimit-Limit: ' . RATE_LIMIT_REQUESTS);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $entry['reset_at']);
            header('Retry-After: ' . ($entry['reset_at'] - time()));

            sendError(429, ERROR_RATE_LIMIT, [
                'limit' => RATE_LIMIT_REQUESTS,
                'window' => RATE_LIMIT_WINDOW,
                'reset_at' => date('c', $entry['reset_at'])
            ]);
        }

        // Sauvegarder les données
        self::saveData($data);

        // Ajouter les headers informatifs
        $remaining = RATE_LIMIT_REQUESTS - $data[$identifier]['count'];
        header('X-RateLimit-Limit: ' . RATE_LIMIT_REQUESTS);
        header('X-RateLimit-Remaining: ' . max(0, $remaining));
        header('X-RateLimit-Reset: ' . $entry['reset_at']);
    }

    /**
     * Charger les données de rate limiting
     */
    private static function loadData() {
        $dir = dirname(self::$storageFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!file_exists(self::$storageFile)) {
            return [];
        }

        $content = file_get_contents(self::$storageFile);
        $data = json_decode($content, true);

        return $data ?? [];
    }

    /**
     * Sauvegarder les données de rate limiting
     */
    private static function saveData($data) {
        $dir = dirname(self::$storageFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::$storageFile, json_encode($data));
    }

    /**
     * Nettoyer les anciennes entrées expirées
     */
    private static function cleanOldEntries($data) {
        $now = time();

        foreach ($data as $identifier => $entry) {
            if ($entry['reset_at'] < $now - RATE_LIMIT_WINDOW) {
                unset($data[$identifier]);
            }
        }

        return $data;
    }

    /**
     * Obtenir l'IP du client
     */
    private static function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }

    /**
     * Réinitialiser le rate limit pour un identifiant
     */
    public static function reset($identifier) {
        $data = self::loadData();
        unset($data[$identifier]);
        self::saveData($data);
    }
}
