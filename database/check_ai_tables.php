<?php
require_once '../config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Vérification des tables de l'Assistant IA</h2>";
    echo "<style>
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        .exists { color: green; font-weight: bold; }
        .missing { color: red; font-weight: bold; }
    </style>";

    $tables = ['ai_conversations', 'ai_intent_patterns', 'ai_response_cache'];

    echo "<table>";
    echo "<tr><th>Table</th><th>Statut</th><th>Nombre de lignes</th></tr>";

    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "<tr>";
            echo "<td>$table</td>";
            echo "<td class='exists'>✓ Existe</td>";
            echo "<td>$count ligne(s)</td>";
            echo "</tr>";
        } catch (PDOException $e) {
            echo "<tr>";
            echo "<td>$table</td>";
            echo "<td class='missing'>✗ N'existe pas</td>";
            echo "<td>-</td>";
            echo "</tr>";
        }
    }

    echo "</table>";

    // Si ai_intent_patterns existe, afficher les patterns
    try {
        $stmt = $db->query("SELECT intent, pattern, priority FROM ai_intent_patterns ORDER BY priority DESC LIMIT 5");
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($patterns) > 0) {
            echo "<h3>Patterns d'intentions (5 premiers)</h3>";
            echo "<table>";
            echo "<tr><th>Intention</th><th>Pattern</th><th>Priorité</th></tr>";
            foreach ($patterns as $p) {
                echo "<tr>";
                echo "<td>{$p['intent']}</td>";
                echo "<td>{$p['pattern']}</td>";
                echo "<td>{$p['priority']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        // Table n'existe pas
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}
?>
