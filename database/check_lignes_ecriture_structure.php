<?php
/**
 * Vérifier la structure de la table lignes_ecriture
 */
require_once '../config/config.php';

echo "<h2>Structure de la table lignes_ecriture</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #4CAF50; color: white; }
    tr:nth-child(even) { background: #f2f2f2; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 10px 0; border-radius: 5px; }
</style>";

try {
    $db = Database::getInstance()->getConnection();

    // Obtenir la structure de la table
    $stmt = $db->query("DESCRIBE lignes_ecriture");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Colonnes de la table lignes_ecriture :</h3>";
    echo "<table>";
    echo "<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th><th>Extra</th></tr>";

    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>{$col['Field']}</strong></td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }

    echo "</table>";

    // Afficher quelques lignes d'exemple
    echo "<h3>Exemple de données :</h3>";
    $stmt = $db->query("SELECT * FROM lignes_ecriture LIMIT 5");
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
                echo "<td>" . ($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "<div class='info'>";
    echo "<strong>Information :</strong><br>";
    echo "La colonne 'sens' n'existe pas dans ta table lignes_ecriture.<br><br>";
    echo "Pour calculer la trésorerie (comptes 57 et 521), on doit utiliser :<br>";
    echo "• <strong>Débit</strong> : augmente le solde (entrée d'argent)<br>";
    echo "• <strong>Crédit</strong> : diminue le solde (sortie d'argent)<br><br>";
    echo "Formule correcte : <code>SUM(debit) - SUM(credit)</code>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='color: red; background: #ffebee; padding: 10px;'>Erreur : {$e->getMessage()}</div>";
}
?>
