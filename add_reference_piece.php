<?php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Ajout du champ reference_piece</h2>";
echo "<style>body { font-family: Arial; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";

// Vérifier si la colonne existe déjà
$cols = $db->query("DESCRIBE ecritures")->fetchAll(PDO::FETCH_COLUMN, 0);

if (in_array('reference_piece', $cols)) {
    echo "<p class='info'>✓ La colonne 'reference_piece' existe déjà dans la table 'ecritures'.</p>";
} else {
    echo "<p class='info'>⚠ La colonne 'reference_piece' n'existe pas. Ajout en cours...</p>";

    try {
        $db->exec("ALTER TABLE ecritures ADD COLUMN reference_piece VARCHAR(100) NULL AFTER num_piece");
        echo "<p class='success'>✓ Colonne 'reference_piece' ajoutée avec succès !</p>";
    } catch (Exception $e) {
        echo "<p class='error'>✗ Erreur lors de l'ajout : " . $e->getMessage() . "</p>";
    }
}

// Afficher la structure actuelle
echo "<h3>Structure actuelle de la table ecritures :</h3>";
$cols = $db->query("DESCRIBE ecritures")->fetchAll();
echo "<table border='1' cellpadding='5'><tr><th>Champ</th><th>Type</th><th>Null</th><th>Default</th></tr>";
foreach ($cols as $col) {
    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Default']}</td></tr>";
}
echo "</table>";
?>
