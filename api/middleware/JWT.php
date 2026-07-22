<?php
/**
 * Classe JWT pour l'authentification de l'API
 * Gestion des tokens JWT (JSON Web Tokens)
 */

class JWT {

    /**
     * Générer un token JWT
     */
    public static function encode($payload, $secret = null) {
        $secret = $secret ?? JWT_SECRET_KEY;

        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => JWT_ALGORITHM
        ];

        // Ajouter timestamp et expiration
        $payload['iat'] = time(); // Issued at
        $payload['exp'] = time() + JWT_EXPIRATION; // Expiration

        // Encoder
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Signature
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Décoder et valider un token JWT
     */
    public static function decode($token, $secret = null) {
        $secret = $secret ?? JWT_SECRET_KEY;

        // Séparer les parties du token
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Vérifier la signature
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Invalid signature');
        }

        // Décoder le payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in payload');
        }

        // Vérifier l'expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Token expired');
        }

        return $payload;
    }

    /**
     * Extraire le token du header Authorization
     */
    public static function getBearerToken() {
        $headers = self::getAuthorizationHeader();

        if (!empty($headers)) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Récupérer le header Authorization
     */
    public static function getAuthorizationHeader() {
        $headers = null;

        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(
                array_map('ucwords', array_keys($requestHeaders)),
                array_values($requestHeaders)
            );

            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }

        return $headers;
    }

    /**
     * Encoder en Base64 URL-safe
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Décoder depuis Base64 URL-safe
     */
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Vérifier si un token est valide (sans exception)
     */
    public static function isValid($token, $secret = null) {
        try {
            self::decode($token, $secret);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Obtenir les informations d'un token sans validation stricte (pour debug)
     */
    public static function getPayloadWithoutValidation($token) {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);

        return $payload;
    }
}
