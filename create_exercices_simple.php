<?php
/**
 * Script ultra-simple de création de la table exercices
 */

require_once 'config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Création simple de la table exercices</h2>";
    echo "<hr>";

    // Désactiver les contraintes FK
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✓ Contraintes FK désactivées<br>";

    // Supprimer la table
    $db->exec("DROP TABLE IF EXISTS exercices");
    echo "✓ Table exercices supprimée<br><br>";

    // Créer la table - VERSION ULTRA SIMPLE
    echo "Création de la table (version simple)...<br>";

    $db->exec("
        CREATE TABLE exercices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            annee INT NOT NULL UNIQUE,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            statut VARCHAR(50) NOT NULL DEFAULT 'Ouvert',
            resultat_calcule DECIMAL(15, 2) DEFAULT NULL,
            date_cloture DATETIME DEFAULT NULL,
            cloture_par INT DEFAULT NULL,
            ecriture_cloture_id INT DEFAULT NULL,
            ecriture_ouverture_id INT DEFAULT NULL,
            ecriture_affectation_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    echo "✓ Table créée<br><br>";

    // Réactiver les contraintes FK
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✓ Contraintes FK réactivées<br><br>";

    // Insérer les exercices
    echo "Insertion des exercices...<br>";
    $db->exec("
        INSERT INTO exercices (annee, date_debut, date_fin, statut)
        VALUES
            (2025, '2025-01-01', '2025-12-31', 'Ouvert'),
            (2026, '2026-01-01', '2026-12-31', 'Ouvert')
    ");
    echo "✓ Exercices 2025 et 2026 insérés<br><br>";

    // Vérification
    echo "<h3>Vérification de la structure:</h3>";
    $stmt = $db->query("DESCRIBE exercices");
    $columns = $stmt->fetchAll();

    echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";

    // Afficher les données
    echo "<h3>Données insérées:</h3>";
    $stmt = $db->query("SELECT * FROM exercices");
    $exercices = $stmt->fetchAll();

    echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>";
    echo "<tr><th>ID</th><th>Année</th><th>Date début</th><th>Date fin</th><th>Statut</th></tr>";
    foreach ($exercices as $ex) {
        echo "<tr>";
        echo "<td>{$ex['id']}</td>";
        echo "<td>{$ex['annee']}</td>";
        echo "<td>{$ex['date_debut']}</td>";
        echo "<td>{$ex['date_fin']}</td>";
        echo "<td>{$ex['statut']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";

    echo "<hr>";
    echo "<h2 style='color: green;'>✓ SUCCESS !</h2>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ ERREUR</h2>";
    echo "<p>Message: " . $e->getMessage() . "</p>";
    echo "<p>Code: " . $e->getCode() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
