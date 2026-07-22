<?php
/**
 * Test direct de l'API chat pour déboguer
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test de l'API Chat - Diagnostic</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { color: green; background: #e8f5e9; padding: 10px; margin: 10px 0; }
    .error { color: red; background: #ffebee; padding: 10px; margin: 10px 0; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 10px 0; }
    pre { background: #f5f5f5; padding: 15px; overflow-x: auto; }
</style>";

// Test 1: Vérifier les fichiers requis
echo "<h3>1. Vérification des fichiers</h3>";

$files = [
    'config' => '../../../config/config.php',
    'AIAssistant' => '../../../includes/AIAssistant.php',
    'chat.php' => 'chat.php'
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "<div class='success'>✓ {$name}: {$path}</div>";
    } else {
        echo "<div class='error'>✗ {$name}: {$path} - INTROUVABLE</div>";
    }
}

// Test 2: Charger les fichiers
echo "<h3>2. Chargement des dépendances</h3>";

try {
    require_once '../../../config/config.php';
    echo "<div class='success'>✓ config.php chargé</div>";
} catch (Exception $e) {
    echo "<div class='error'>✗ Erreur config.php: {$e->getMessage()}</div>";
    exit;
}

try {
    require_once '../../../includes/AIAssistant.php';
    echo "<div class='success'>✓ AIAssistant.php chargé</div>";
} catch (Exception $e) {
    echo "<div class='error'>✗ Erreur AIAssistant.php: {$e->getMessage()}</div>";
    exit;
}

// Test 3: Vérifier la session
echo "<h3>3. Vérification de la session</h3>";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    echo "<div class='success'>✓ Utilisateur connecté: ID = {$_SESSION['user_id']}</div>";
    $userId = $_SESSION['user_id'];
} else {
    echo "<div class='error'>✗ Aucun utilisateur connecté - Simulation avec user_id = 1</div>";
    $userId = 1; // Pour le test
}

// Test 4: Créer une instance AIAssistant
echo "<h3>4. Test de l'AIAssistant</h3>";

try {
    $assistant = new AIAssistant($userId);
    echo "<div class='success'>✓ Instance AIAssistant créée</div>";
} catch (Exception $e) {
    echo "<div class='error'>✗ Erreur création AIAssistant: {$e->getMessage()}</div>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    exit;
}

// Test 5: Poser une question simple
echo "<h3>5. Test d'une question: 'aide'</h3>";

try {
    $result = $assistant->processQuestion('aide');

    if ($result['success']) {
        echo "<div class='success'>✓ Réponse reçue</div>";
        echo "<pre>" . print_r($result, true) . "</pre>";
    } else {
        echo "<div class='error'>✗ Erreur dans la réponse</div>";
        echo "<pre>" . print_r($result, true) . "</pre>";
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Exception: {$e->getMessage()}</div>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h3>✅ Test terminé</h3>";
echo "<p><a href='../../../pages/dashboard/'>→ Retour au dashboard</a></p>";
?>
