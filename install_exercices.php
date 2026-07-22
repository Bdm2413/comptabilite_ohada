<?php
/**
 * Script d'installation de la table exercices
 * À exécuter une seule fois pour créer la table
 */

require_once 'config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Installation de la table exercices</h2>";
    echo "<hr>";

    // Créer la table
    $sql_create = "
        CREATE TABLE IF NOT EXISTS exercices (
            id INT AUTO_INCREMENT PRIMARY KEY,

            -- Informations de l'exercice
            annee INT NOT NULL UNIQUE COMMENT 'Année de l\'exercice (ex: 2025)',
            date_debut DATE NOT NULL COMMENT 'Date de début de l\'exercice',
            date_fin DATE NOT NULL COMMENT 'Date de fin de l\'exercice',

            -- Statut de l'exercice
            statut ENUM('Ouvert', 'Clôturé', 'En cours de clôture') NOT NULL DEFAULT 'Ouvert'
                COMMENT 'Statut de l\'exercice : Ouvert (saisie autorisée), En cours de clôture, Clôturé (lecture seule)',

            -- Résultat de l'exercice
            resultat_calcule DECIMAL(15, 2) DEFAULT NULL
                COMMENT 'Résultat calculé (produits - charges). Positif = bénéfice, Négatif = perte',

            -- Informations de clôture
            date_cloture DATETIME DEFAULT NULL COMMENT 'Date et heure de clôture de l\'exercice',
            cloture_par INT DEFAULT NULL COMMENT 'ID de l\'utilisateur ayant clôturé l\'exercice',

            -- Écritures de clôture/ouverture
            ecriture_cloture_id INT DEFAULT NULL
                COMMENT 'ID de l\'écriture de clôture (solde des comptes 6, 7, 8 → 131/139)',
            ecriture_ouverture_id INT DEFAULT NULL
                COMMENT 'ID de l\'écriture d\'ouverture du prochain exercice (report à nouveau)',
            ecriture_affectation_id INT DEFAULT NULL
                COMMENT 'ID de l\'écriture d\'affectation du résultat (131/139 → 110/119/121/465)',

            -- Métadonnées
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            -- Contraintes
            CONSTRAINT fk_exercice_cloture_user FOREIGN KEY (cloture_par)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_exercice_ecriture_cloture FOREIGN KEY (ecriture_cloture_id)
                REFERENCES ecritures(id) ON DELETE SET NULL,
            CONSTRAINT fk_exercice_ecriture_ouverture FOREIGN KEY (ecriture_ouverture_id)
                REFERENCES ecritures(id) ON DELETE SET NULL,
            CONSTRAINT fk_exercice_ecriture_affectation FOREIGN KEY (ecriture_affectation_id)
                REFERENCES ecritures(id) ON DELETE SET NULL,

            -- Index
            INDEX idx_annee (annee),
            INDEX idx_statut (statut),
            INDEX idx_date_debut (date_debut),
            INDEX idx_date_fin (date_fin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Gestion des exercices comptables avec clôture et report à nouveau'
    ";

    $db->exec($sql_create);
    echo "✓ Table 'exercices' créée avec succès<br><br>";

    // Insérer les exercices 2025 et 2026
    $sql_insert = "
        INSERT IGNORE INTO exercices (annee, date_debut, date_fin, statut, resultat_calcule)
        VALUES
            (2025, '2025-01-01', '2025-12-31', 'Ouvert', NULL),
            (2026, '2026-01-01', '2026-12-31', 'Ouvert', NULL)
    ";

    $db->exec($sql_insert);
    echo "✓ Exercices 2025 et 2026 créés (ou déjà existants)<br><br>";

    // Afficher les exercices créés
    $stmt = $db->query("SELECT * FROM exercices ORDER BY annee");
    $exercices = $stmt->fetchAll();

    echo "<h3>Exercices créés:</h3>";
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
    echo "<h2 style='color: green;'>✓ Installation terminée avec succès !</h2>";
    echo "<p><a href='pages/settings/exercices.php'>Accéder à la gestion des exercices</a></p>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ Erreur lors de l'installation</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
