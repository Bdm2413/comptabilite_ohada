<?php
/**
 * Chargeur simple de fichier .env
 * Charge les variables d'environnement depuis le fichier .env
 */

function loadEnv($filePath = null)
{
    if ($filePath === null) {
        $filePath = __DIR__ . '/../.env';
    }

    if (!file_exists($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Ignorer les commentaires
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parser la ligne KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Supprimer les guillemets si présents
            $value = trim($value, '"\'');

            // Définir dans $_ENV et putenv()
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    return true;
}

// Auto-charger le .env si ce fichier est inclus
loadEnv();
