<?php
/**
 * Configuration générale de l'application
 * Application de Comptabilité SYSCOHADA Révisé
 */

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuration des chemins
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('CLASSES_PATH', INCLUDES_PATH . '/classes');
define('FUNCTIONS_PATH', INCLUDES_PATH . '/functions');
define('PAGES_PATH', ROOT_PATH . '/pages');
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('CONFIG_PATH', ROOT_PATH . '/config');

// Configuration de l'application
define('APP_NAME', 'ComptaSYSCOHADA');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/comptabilite_ohada');

// Configuration de sécurité
define('SESSION_LIFETIME', 3600); // 1 heure
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Configuration des erreurs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Timezone
date_default_timezone_set('Africa/Abidjan');

// Autoloader simple
spl_autoload_register(function ($class_name) {
    $file = CLASSES_PATH . '/' . $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Inclure la connexion à la base de données
require_once CONFIG_PATH . '/database.php';

// Inclure les fonctions utilitaires
if (file_exists(FUNCTIONS_PATH . '/utils.php')) {
    require_once FUNCTIONS_PATH . '/utils.php';
}

if (file_exists(FUNCTIONS_PATH . '/security.php')) {
    require_once FUNCTIONS_PATH . '/security.php';
}

if (file_exists(FUNCTIONS_PATH . '/exercices.php')) {
    require_once FUNCTIONS_PATH . '/exercices.php';
}

// Inclure les fonctions multi-sociétés et multi-devises
if (file_exists(CONFIG_PATH . '/functions_multi_societes.php')) {
    require_once CONFIG_PATH . '/functions_multi_societes.php';
}

if (file_exists(CONFIG_PATH . '/functions_multi_devises.php')) {
    require_once CONFIG_PATH . '/functions_multi_devises.php';
}

// Vérifier si le système nécessite une configuration initiale
// Ne pas rediriger si on est déjà sur la page de setup ou sur la page de login
$current_script = basename($_SERVER['PHP_SELF']);
$is_setup_page = (strpos($_SERVER['REQUEST_URI'], '/setup/') !== false);
$is_login_page = ($current_script === 'login.php' || $current_script === 'connexion.php');
$is_logout_page = ($current_script === 'logout.php' || $current_script === 'deconnexion.php');
$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Rediriger vers le setup si l'installation n'est pas complète
if (!$is_setup_page && !$is_login_page && !$is_logout_page && !$is_ajax && needsInitialSetup()) {
    // Si un utilisateur est déjà connecté, le déconnecter d'abord
    if (isset($_SESSION['user_id'])) {
        session_destroy();
        session_start();
    }
    header('Location: ' . APP_URL . '/setup/initial_setup.php');
    exit();
}
