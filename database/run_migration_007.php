<?php
require_once '../config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "=== Exécution de la migration 007: AI Conversations ===\n\n";

    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/migrations/007_create_ai_conversations.sql');

    // Séparer les requêtes
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($query) {
            return !empty($query) && !preg_match('/^--/', $query);
        }
    );

    $successCount = 0;
    $errorCount = 0;

    foreach ($queries as $query) {
        if (empty(trim($query))) continue;

        try {
            $db->exec($query);
            $successCount++;

            // Afficher le type de requête
            if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $query, $matches)) {
                echo "✓ Table '{$matches[1]}' créée avec succès\n";
            } elseif (preg_match('/INSERT INTO.*?`?(\w+)`?/i', $query, $matches)) {
                echo "✓ Données insérées dans '{$matches[1]}'\n";
            } else {
                echo "✓ Requête exécutée avec succès\n";
            }

        } catch (PDOException $e) {
            $errorCount++;
            echo "✗ Erreur: " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== Résumé ===\n";
    echo "Requêtes réussies: $successCount\n";
    echo "Requêtes échouées: $errorCount\n";

    if ($errorCount === 0) {
        echo "\n✓ Migration terminée avec succès!\n";
    } else {
        echo "\n⚠ Migration terminée avec des erreurs\n";
    }

} catch (Exception $e) {
    echo "Erreur fatale: " . $e->getMessage() . "\n";
    exit(1);
}
?>
