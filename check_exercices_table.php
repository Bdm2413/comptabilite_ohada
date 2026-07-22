<?php
/**
 * Script de vérification de la structure de la table exercices
 */

require_once 'config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Vérification de la table exercices</h2>";
    echo "<hr>";

    // Vérifier si la table existe
    $stmt = $db->query("SHOW TABLES LIKE 'exercices'");
    $table_exists = $stmt->rowCount() > 0;

    if ($table_exists) {
        echo "✓ La table 'exercices' existe<br><br>";

        // Afficher la structure
        echo "<h3>Structure de la table:</h3>";
        $stmt = $db->query("DESCRIBE exercices");
        $columns = $stmt->fetchAll();

        echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";

        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
            echo "<td>{$col['Extra']}</td>";
            echo "</tr>";
        }

        echo "</table><br>";

        // Afficher le CREATE TABLE
        echo "<h3>CREATE TABLE statement:</h3>";
        $stmt = $db->query("SHOW CREATE TABLE exercices");
        $create = $stmt->fetch();
        echo "<pre>" . htmlspecialchars($create['Create Table']) . "</pre>";

    } else {
        echo "✗ La table 'exercices' n'existe pas<br>";
    }

} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ Erreur</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
