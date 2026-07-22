<?php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Structure des tables</h2>";
echo "<style>body { font-family: Arial; padding: 20px; } table { border-collapse: collapse; margin: 20px 0; } th, td { border: 1px solid #ddd; padding: 8px; } th { background-color: #4CAF50; color: white; }</style>";

// Structure code_journal
echo "<h3>Structure de code_journal</h3>";
$cols = $db->query("DESCRIBE code_journal")->fetchAll();
echo "<table><tr><th>Champ</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
foreach ($cols as $col) {
    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>{$col['Default']}</td></tr>";
}
echo "</table>";

// Aperçu des données
$data = $db->query("SELECT * FROM code_journal LIMIT 5")->fetchAll();
echo "<p>Aperçu des données :</p>";
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

// Structure plan_comptable
echo "<h3>Structure de plan_comptable</h3>";
$cols2 = $db->query("DESCRIBE plan_comptable")->fetchAll();
echo "<table><tr><th>Champ</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
foreach ($cols2 as $col) {
    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>{$col['Default']}</td></tr>";
}
echo "</table>";

// Aperçu des données
$data2 = $db->query("SELECT * FROM plan_comptable LIMIT 5")->fetchAll();
echo "<p>Aperçu des données :</p>";
echo "<table><tr>";
foreach ($cols2 as $col) {
    echo "<th>{$col['Field']}</th>";
}
echo "</tr>";
foreach ($data2 as $row) {
    echo "<tr>";
    foreach ($cols2 as $col) {
        echo "<td>" . htmlspecialchars($row[$col['Field']] ?? '') . "</td>";
    }
    echo "</tr>";
}
echo "</table>";
?>
