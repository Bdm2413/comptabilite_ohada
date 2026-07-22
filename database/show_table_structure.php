<?php
/**
 * Afficher la structure complète des tables
 */
require_once '../config/config.php';

echo "<h2>Structure des tables</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #4CAF50; color: white; }
    tr:nth-child(even) { background: #f2f2f2; }
    .section { margin: 30px 0; }
</style>";

try {
    $db = Database::getInstance()->getConnection();

    // 1. Structure de lignes_ecriture
    echo "<div class='section'>";
    echo "<h3>📋 Table: lignes_ecriture</h3>";
    $stmt = $db->query("DESCRIBE lignes_ecriture");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>";
    echo "<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th></tr>";
    foreach ($columns as $col) {
        $highlight = (stripos($col['Field'], 'ecriture') !== false || stripos($col['Field'], 'id') !== false) ?
                     "style='background: #fff3cd; font-weight: bold;'" : "";
        echo "<tr {$highlight}>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    // 2. Structure de ecritures
    echo "<div class='section'>";
    echo "<h3>📋 Table: ecritures</h3>";
    $stmt = $db->query("DESCRIBE ecritures");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>";
    echo "<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th></tr>";
    foreach ($columns as $col) {
        $highlight = (stripos($col['Field'], 'id') !== false) ?
                     "style='background: #fff3cd; font-weight: bold;'" : "";
        echo "<tr {$highlight}>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    // 3. Exemple de données de lignes_ecriture
    echo "<div class='section'>";
    echo "<h3>📊 Exemple de données - lignes_ecriture (3 premières lignes)</h3>";
    $stmt = $db->query("SELECT * FROM lignes_ecriture LIMIT 3");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        echo "<table>";
        echo "<tr>";
        foreach (array_keys($rows[0]) as $header) {
            echo "<th>{$header}</th>";
        }
        echo "</tr>";

        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Aucune donnée trouvée.</p>";
    }
    echo "</div>";

    echo "<div style='background: #e3f2fd; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<strong>🔍 Information importante :</strong><br>";
    echo "Je vais identifier le nom exact de la colonne de liaison entre les deux tables.<br>";
    echo "Cherche dans les colonnes surlignées en jaune ci-dessus.";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='color: red; background: #ffebee; padding: 10px;'>Erreur : {$e->getMessage()}</div>";
}
?>
