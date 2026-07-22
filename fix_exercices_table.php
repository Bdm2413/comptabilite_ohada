<?php
/**
 * Script de correction de la table exercices
 * Supprime et recrée la table correctement
 */

require_once 'config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Correction de la table exercices</h2>";
    echo "<hr>";

    // Désactiver temporairement les vérifications de clés étrangères
    echo "Désactivation des contraintes de clés étrangères...<br>";
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✓ Contraintes désactivées<br><br>";

    // Supprimer la table existante
    echo "Suppression de la table incomplète...<br>";
    $db->exec("DROP TABLE IF EXISTS exercices");
    echo "✓ Table supprimée<br><br>";

    // Réactiver les vérifications de clés étrangères
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✓ Contraintes réactivées<br><br>";

    // Recréer la table correctement - Version simplifiée
    echo "Création de la nouvelle table...<br>";

    $sql_create = "
        CREATE TABLE exercices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            annee INT NOT NULL UNIQUE,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            statut ENUM('Ouvert', 'Clôturé', 'En cours de clôture') NOT NULL DEFAULT 'Ouvert',
            resultat_calcule DECIMAL(15, 2) DEFAULT NULL,
            date_cloture DATETIME DEFAULT NULL,
            cloture_par INT DEFAULT NULL,
            ecriture_cloture_id INT DEFAULT NULL,
            ecriture_ouverture_id INT DEFAULT NULL,
            ecriture_affectation_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $db->exec($sql_create);
    echo "✓ Table créée<br><br>";

    // Ajouter les index
    echo "Ajout des index...<br>";
    $db->exec("CREATE INDEX idx_annee ON exercices(annee)");
    $db->exec("CREATE INDEX idx_statut ON exercices(statut)");
    $db->exec("CREATE INDEX idx_date_debut ON exercices(date_debut)");
    $db->exec("CREATE INDEX idx_date_fin ON exercices(date_fin)");
    echo "✓ Index ajoutés<br><br>";

    // Ajouter les contraintes de clés étrangères APRÈS création
    echo "Ajout des contraintes de clés étrangères...<br>";

    try {
        $db->exec("
            ALTER TABLE exercices
            ADD CONSTRAINT fk_exercice_cloture_user
            FOREIGN KEY (cloture_par) REFERENCES users(id) ON DELETE SET NULL
        ");
        echo "✓ Contrainte fk_exercice_cloture_user ajoutée<br>";
    } catch (Exception $e) {
        echo "⚠ Contrainte fk_exercice_cloture_user ignorée: " . $e->getMessage() . "<br>";
    }

    try {
        $db->exec("
            ALTER TABLE exercices
            ADD CONSTRAINT fk_exercice_ecriture_cloture
            FOREIGN KEY (ecriture_cloture_id) REFERENCES ecritures(id) ON DELETE SET NULL
        ");
        echo "✓ Contrainte fk_exercice_ecriture_cloture ajoutée<br>";
    } catch (Exception $e) {
        echo "⚠ Contrainte fk_exercice_ecriture_cloture ignorée: " . $e->getMessage() . "<br>";
    }

    try {
        $db->exec("
            ALTER TABLE exercices
            ADD CONSTRAINT fk_exercice_ecriture_ouverture
            FOREIGN KEY (ecriture_ouverture_id) REFERENCES ecritures(id) ON DELETE SET NULL
        ");
        echo "✓ Contrainte fk_exercice_ecriture_ouverture ajoutée<br>";
    } catch (Exception $e) {
        echo "⚠ Contrainte fk_exercice_ecriture_ouverture ignorée: " . $e->getMessage() . "<br>";
    }

    try {
        $db->exec("
            ALTER TABLE exercices
            ADD CONSTRAINT fk_exercice_ecriture_affectation
            FOREIGN KEY (ecriture_affectation_id) REFERENCES ecritures(id) ON DELETE SET NULL
        ");
        echo "✓ Contrainte fk_exercice_ecriture_affectation ajoutée<br><br>";
    } catch (Exception $e) {
        echo "⚠ Contrainte fk_exercice_ecriture_affectation ignorée: " . $e->getMessage() . "<br><br>";
    }

    // Insérer les exercices 2025 et 2026
    echo "Insertion des exercices 2025 et 2026...<br>";
    $sql_insert = "
        INSERT INTO exercices (annee, date_debut, date_fin, statut)
        VALUES
            (2025, '2025-01-01', '2025-12-31', 'Ouvert'),
            (2026, '2026-01-01', '2026-12-31', 'Ouvert')
    ";

    $db->exec($sql_insert);
    echo "✓ Exercices créés<br><br>";

    // Afficher la structure finale
    echo "<h3>Structure de la table (vérification):</h3>";
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

    // Afficher les exercices
    echo "<h3>Exercices créés:</h3>";
    $stmt = $db->query("SELECT * FROM exercices ORDER BY annee");
    $exercices = $stmt->fetchAll();

    echo "<table border='1' style='border-collapse: collapse; padding: 5px;'>";
    echo "<tr><th>ID</th><th>Année</th><th>Date début</th><th>Date fin</th><th>Statut</th><th>Résultat</th></tr>";

    foreach ($exercices as $ex) {
        echo "<tr>";
        echo "<td>{$ex['id']}</td>";
        echo "<td>{$ex['annee']}</td>";
        echo "<td>{$ex['date_debut']}</td>";
        echo "<td>{$ex['date_fin']}</td>";
        echo "<td>{$ex['statut']}</td>";
        echo "<td>" . ($ex['resultat_calcule'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }

    echo "</table><br>";

    echo "<hr>";
    echo "<h2 style='color: green;'>✓ Table exercices créée avec succès !</h2>";
    echo "<p><a href='check_exercices_table.php'>Vérifier la structure</a></p>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ Erreur</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
