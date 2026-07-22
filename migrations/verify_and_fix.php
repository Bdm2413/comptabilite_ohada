<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérification et Correction</title>
    <style>
        body { font-family: Arial; max-width: 1000px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #3498db; color: white; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>🔍 Vérification des tables écritures</h1>
    <?php
    try {
        $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        echo '<div class="success">✓ Connexion réussie</div>';

        // Vérifier la structure de lignes_ecriture
        echo '<h2>📋 Structure de la table lignes_ecriture</h2>';
        $stmt = $db->query("DESCRIBE lignes_ecriture");
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<table>';
        echo '<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th></tr>';
        foreach ($fields as $field) {
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($field['Field']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($field['Type']) . '</td>';
            echo '<td>' . htmlspecialchars($field['Null']) . '</td>';
            echo '<td>' . htmlspecialchars($field['Key']) . '</td>';
            echo '<td>' . htmlspecialchars($field['Default'] ?? 'NULL') . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        // Vérifier si id_ecriture existe
        $hasIdEcriture = false;
        foreach ($fields as $field) {
            if ($field['Field'] === 'id_ecriture') {
                $hasIdEcriture = true;
                break;
            }
        }

        if ($hasIdEcriture) {
            echo '<div class="success">✓ Le champ id_ecriture existe</div>';

            // Essayer de créer la vue
            echo '<h2>🔄 Création de la vue v_ecritures_totaux</h2>';

            $db->exec("DROP VIEW IF EXISTS v_ecritures_totaux");
            echo '<div class="info">Vue existante supprimée</div>';

            $sql = "CREATE VIEW v_ecritures_totaux AS
            SELECT
                e.id,
                e.numero_ecriture,
                e.date_ecriture,
                e.journal,
                e.libelle,
                e.statut,
                (SELECT COUNT(*) FROM lignes_ecriture WHERE id_ecriture = e.id) as nb_lignes,
                (SELECT COALESCE(SUM(debit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) as total_debit,
                (SELECT COALESCE(SUM(credit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) as total_credit,
                ABS((SELECT COALESCE(SUM(debit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) -
                    (SELECT COALESCE(SUM(credit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id)) as ecart,
                CASE
                    WHEN ABS((SELECT COALESCE(SUM(debit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) -
                             (SELECT COALESCE(SUM(credit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id)) < 0.01
                    THEN 'Équilibrée'
                    ELSE 'Déséquilibrée'
                END as equilibre
            FROM ecritures e";

            $db->exec($sql);
            echo '<div class="success">✓ Vue v_ecritures_totaux créée avec succès !</div>';

        } else {
            echo '<div class="error">✗ Le champ id_ecriture n\'existe PAS dans lignes_ecriture</div>';
            echo '<div class="info">La table lignes_ecriture semble avoir une structure différente ou être issue de l\'ancienne base.</div>';

            // Proposer de recréer la table
            if (isset($_GET['recreate'])) {
                echo '<h2>🔄 Recréation de la table lignes_ecriture</h2>';

                // Sauvegarder les données existantes
                $backup = $db->query("SELECT * FROM lignes_ecriture")->fetchAll(PDO::FETCH_ASSOC);
                echo '<div class="info">Sauvegarde de ' . count($backup) . ' lignes</div>';

                // Supprimer la table
                $db->exec("DROP TABLE IF EXISTS lignes_ecriture");
                echo '<div class="info">Ancienne table supprimée</div>';

                // Recréer avec la bonne structure
                $db->exec("CREATE TABLE lignes_ecriture (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_ecriture INT NOT NULL,
                    compte INT NOT NULL,
                    compte_tiers VARCHAR(20) NULL,
                    libelle TEXT NOT NULL,
                    debit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    date_ligne DATE NULL,
                    createur VARCHAR(255) NOT NULL,
                    modificateur VARCHAR(255) NULL,
                    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    date_modification DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_id_ecriture (id_ecriture),
                    INDEX idx_compte (compte),
                    INDEX idx_date_ligne (date_ligne),
                    CONSTRAINT fk_lignes_ecriture_ecriture FOREIGN KEY (id_ecriture)
                        REFERENCES ecritures(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_lignes_ecriture_compte FOREIGN KEY (compte)
                        REFERENCES plan_comptable(compte) ON DELETE RESTRICT ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                echo '<div class="success">✓ Table lignes_ecriture recréée</div>';
                echo '<div class="info">Rechargez la page sans le paramètre ?recreate pour vérifier</div>';
            } else {
                echo '<div class="info">';
                echo '<p><strong>Solution :</strong> Recréer la table lignes_ecriture avec la bonne structure.</p>';
                echo '<a href="?recreate=1" style="display: inline-block; padding: 10px 20px; background: #e74c3c; color: white; text-decoration: none; border-radius: 4px;">Recréer la table lignes_ecriture</a>';
                echo '</div>';
            }
        }

        echo '<h2>✅ Résumé</h2>';
        echo '<ul>';
        echo '<li>Tables créées : ecritures, lignes_ecriture</li>';
        echo '<li>Vue v_ecritures_detail : ' . ($db->query("SHOW TABLES LIKE 'v_ecritures_detail'")->rowCount() > 0 ? '✓ OK' : '✗ Manquante') . '</li>';
        echo '<li>Vue v_ecritures_totaux : ' . ($db->query("SHOW TABLES LIKE 'v_ecritures_totaux'")->rowCount() > 0 ? '✓ OK' : '✗ Manquante') . '</li>';
        echo '</ul>';

        echo '<a href="../" style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px;">Continuer</a>';

    } catch (PDOException $e) {
        echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
    ?>
</body>
</html>
