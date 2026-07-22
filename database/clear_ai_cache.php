<?php
/**
 * Vider le cache de l'assistant IA
 */
require_once '../config/config.php';

echo "<h2>Vidage du cache de l'Assistant IA</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { color: green; background: #e8f5e9; padding: 10px; margin: 10px 0; border-radius: 5px; }
    .error { color: red; background: #ffebee; padding: 10px; margin: 10px 0; border-radius: 5px; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 10px 0; border-radius: 5px; }
</style>";

try {
    $db = Database::getInstance()->getConnection();

    // 1. Vider tout le cache
    echo "<h3>🗑️ Suppression du cache...</h3>";

    $stmt = $db->query("DELETE FROM ai_response_cache");
    $deletedCache = $stmt->rowCount();

    echo "<div class='success'>✓ {$deletedCache} réponse(s) en cache supprimée(s)</div>";

    // 2. Optionnel : Vider aussi l'historique des conversations pour repartir à zéro
    echo "<h3>📋 Historique des conversations</h3>";

    $stmt = $db->query("SELECT COUNT(*) as total FROM ai_conversations");
    $totalConversations = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo "<div class='info'>Tu as {$totalConversations} conversation(s) dans l'historique.</div>";

    echo "<p>Si tu veux aussi vider l'historique, clique sur ce lien :</p>";
    echo "<p><a href='clear_ai_history.php' style='color: red;'>⚠️ Supprimer tout l'historique</a></p>";

    // 3. Vérifier les patterns actuels
    echo "<h3>📊 Patterns d'intentions actuels</h3>";

    $stmt = $db->query("
        SELECT intent, priority, pattern
        FROM ai_intent_patterns
        WHERE intent IN ('KPI_TRESORERIE', 'KPI_CA', 'KPI_CHARGES')
        ORDER BY priority DESC
    ");

    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th>Intention</th><th>Priorité</th><th>Pattern</th></tr>";

    foreach ($patterns as $p) {
        $bgColor = $p['intent'] === 'KPI_TRESORERIE' ? '#e8f5e9' : '#fff';
        echo "<tr style='background: {$bgColor};'>";
        echo "<td><strong>{$p['intent']}</strong></td>";
        echo "<td style='text-align: center;'><strong>{$p['priority']}</strong></td>";
        echo "<td><small>{$p['pattern']}</small></td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<h3>✅ Cache vidé avec succès !</h3>";
    echo "<div class='info'>";
    echo "<strong>Prochaine étape :</strong><br><br>";
    echo "1. Retourne sur le dashboard<br>";
    echo "2. Pose à nouveau la question : <strong>\"Quel est le solde de la caisse à ce jour ?\"</strong><br>";
    echo "3. Cette fois, l'assistant devrait détecter <strong>KPI_TRESORERIE</strong> et afficher le bon résultat<br>";
    echo "</div>";

    echo "<p><a href='../pages/dashboard/' style='display: inline-block; background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>→ Retourner au dashboard</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>Erreur : {$e->getMessage()}</div>";
}
?>
