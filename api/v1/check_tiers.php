<?php
require_once '../../config/config.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Structure de la table 'plan_tiers'</h1>";

try {
    $stmt = $db->query("DESCRIBE plan_tiers");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Colonne</th><th>Type</th></tr>";

    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<h2>Exemple de données</h2>";
    $stmt = $db->query("SELECT * FROM plan_tiers LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}
?>
