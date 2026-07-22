<?php
require_once '../../config/config.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Structure de la table 'ecritures'</h1>";

try {
    $stmt = $db->query("DESCRIBE ecritures");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Colonne</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";

    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<h2>Exemple de données (1ère ligne)</h2>";
    $stmt = $db->query("SELECT * FROM ecritures LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    } else {
        echo "<p>Aucune donnée dans la table</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}
?>
