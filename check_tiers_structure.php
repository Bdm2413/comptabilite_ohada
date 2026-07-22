<?php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Structure de plan_tiers</h2>";
echo "<style>body { font-family: Arial; padding: 20px; } table { border-collapse: collapse; margin: 20px 0; } th, td { border: 1px solid #ddd; padding: 8px; } th { background-color: #4CAF50; color: white; }</style>";

$cols = $db->query("DESCRIBE plan_tiers")->fetchAll();
echo "<table><tr><th>Champ</th><th>Type</th></tr>";
foreach ($cols as $col) {
    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td></tr>";
}
echo "</table>";

echo "<h3>Aperçu des données</h3>";
$data = $db->query("SELECT * FROM plan_tiers LIMIT 5")->fetchAll();
echo "<table><tr>";
foreach ($cols as $col) {
    echo "<th>{$col['Field']}</th>";
}
echo "</tr>";
foreach ($data as $row) {
    echo "<tr>";
    foreach ($cols as $col) {
        echo "<td>" . htmlspecialchars($row[$col['Field']] ?? '') . "</td>";
    }
    echo "</tr>";
}
echo "</table>";
?>
