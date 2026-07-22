<?php
/**
 * Test de l'intégration Claude API
 */

require_once '../../../includes/load_env.php';
require_once '../../../includes/ClaudeAPI.php';

echo "<h2>Test de l'API Claude</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { color: green; background: #e8f5e9; padding: 10px; margin: 10px 0; }
    .error { color: red; background: #ffebee; padding: 10px; margin: 10px 0; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 10px 0; }
    pre { background: #f5f5f5; padding: 15px; overflow-x: auto; }
</style>";

// Vérifier si la clé API est configurée
$apiKey = $_ENV['CLAUDE_API_KEY'] ?? null;

echo "<h3>1. Vérification de la configuration</h3>";

if (!$apiKey || $apiKey === 'sk-ant-votre-cle-ici') {
    echo "<div class='error'>
        <strong>❌ Clé API non configurée</strong><br><br>
        <strong>Comment configurer :</strong><br>
        1. Crée un fichier <code>.env</code> à la racine du projet<br>
        2. Copie le contenu de <code>.env.example</code><br>
        3. Remplace <code>sk-ant-votre-cle-ici</code> par ta vraie clé API<br>
        4. Recharge cette page
    </div>";
    exit;
}

echo "<div class='success'>✓ Clé API configurée : " . substr($apiKey, 0, 12) . "...</div>";

// Test de connexion à l'API
echo "<h3>2. Test de connexion à Claude API</h3>";

try {
    $claude = new ClaudeAPI($apiKey);

    $testMessage = "Dis bonjour en une phrase courte.";
    echo "<div class='info'><strong>Question test :</strong> $testMessage</div>";

    $response = $claude->sendMessage($testMessage);

    if ($response['success']) {
        echo "<div class='success'>
            <strong>✓ Réponse reçue de Claude :</strong><br>
            <em>{$response['content']}</em>
        </div>";

        if (isset($response['usage'])) {
            echo "<div class='info'>
                <strong>Tokens utilisés :</strong><br>
                - Input: {$response['usage']['input_tokens']}<br>
                - Output: {$response['usage']['output_tokens']}
            </div>";
        }

        echo "<h3>✅ L'intégration Claude API fonctionne !</h3>";
        echo "<p>Tu peux maintenant utiliser l'assistant IA avec Claude.</p>";
        echo "<p><a href='../../../pages/dashboard/'>→ Tester l'assistant IA</a></p>";

    } else {
        echo "<div class='error'>
            <strong>❌ Erreur :</strong> {$response['error']}
        </div>";

        if (strpos($response['error'], 'authentication') !== false) {
            echo "<div class='info'>
                <strong>Problème d'authentification détecté.</strong><br>
                Vérifie que ta clé API est correcte sur https://console.anthropic.com/
            </div>";
        }
    }

} catch (Exception $e) {
    echo "<div class='error'><strong>Exception :</strong> {$e->getMessage()}</div>";
}

echo "<h3>3. Informations de débogage</h3>";
echo "<pre>";
echo "Fichier .env: " . (file_exists(__DIR__ . '/../../../.env') ? "✓ Existe" : "✗ N'existe pas") . "\n";
echo "cURL activé: " . (function_exists('curl_init') ? "✓ Oui" : "✗ Non") . "\n";
echo "</pre>";
?>
