<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification Migration</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #3498db;
            color: white;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; margin: 10px 0; }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Vérification de la migration</h1>

        <?php
        try {
            $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            echo '<div class="success">✓ Connexion à la base de données réussie</div>';

            // 1. Vérifier la structure de plan_comptable
            echo '<h2>📋 Structure de la table plan_comptable</h2>';
            $stmt = $db->query('DESCRIBE plan_comptable');
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            echo '<table>';
            echo '<tr><th>Colonne</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';
            foreach ($columns as $col) {
                echo '<tr>';
                echo '<td><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($col['Type']) . '</td>';
                echo '<td>' . htmlspecialchars($col['Null']) . '</td>';
                echo '<td>' . htmlspecialchars($col['Key']) . '</td>';
                echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
                echo '<td>' . htmlspecialchars($col['Extra']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';

            // Vérifier si la structure est correcte
            $columnNames = array_column($columns, 'Field');
            $expectedColumns = ['id', 'compte', 'intitule_compte', 'classe', 'quatre_chiffres', 'tableau', 'type', 'bd', 'bc', 'rd', 'rc', 'actif', 'created_at', 'updated_at'];

            $missingColumns = array_diff($expectedColumns, $columnNames);
            $extraColumns = array_diff($columnNames, $expectedColumns);

            if (empty($missingColumns) && empty($extraColumns)) {
                echo '<div class="success">✓ Structure correcte : toutes les colonnes attendues sont présentes</div>';
            } else {
                if (!empty($missingColumns)) {
                    echo '<div class="error">✗ Colonnes manquantes : ' . implode(', ', $missingColumns) . '</div>';
                }
                if (!empty($extraColumns)) {
                    echo '<div class="info">ℹ Colonnes supplémentaires : ' . implode(', ', $extraColumns) . '</div>';
                }
            }

            // 2. Vérifier le type de la colonne compte
            foreach ($columns as $col) {
                if ($col['Field'] === 'compte') {
                    if (stripos($col['Type'], 'int') !== false) {
                        echo '<div class="success">✓ Colonne "compte" est de type INT</div>';
                    } else {
                        echo '<div class="error">✗ Colonne "compte" est de type ' . $col['Type'] . ' (devrait être INT)</div>';
                    }
                }
            }

            // 3. Vérifier les contraintes de clé étrangère
            echo '<h2>🔗 Contraintes de clé étrangère</h2>';
            $stmt = $db->query("
                SELECT
                    CONSTRAINT_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = 'comptabilite_syscohada'
                AND TABLE_NAME = 'plan_comptable'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!empty($foreignKeys)) {
                echo '<table>';
                echo '<tr><th>Contrainte</th><th>Colonne</th><th>Table référencée</th><th>Colonne référencée</th></tr>';
                foreach ($foreignKeys as $fk) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($fk['CONSTRAINT_NAME']) . '</td>';
                    echo '<td>' . htmlspecialchars($fk['COLUMN_NAME']) . '</td>';
                    echo '<td>' . htmlspecialchars($fk['REFERENCED_TABLE_NAME']) . '</td>';
                    echo '<td>' . htmlspecialchars($fk['REFERENCED_COLUMN_NAME']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<div class="error">✗ Aucune contrainte de clé étrangère trouvée</div>';
            }

            // 4. Vérifier les données
            echo '<h2>📊 Données dans plan_comptable</h2>';
            $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable');
            $count = $stmt->fetchColumn();
            $stmt->closeCursor();

            echo '<div class="info">Nombre total de comptes : ' . $count . '</div>';

            if ($count > 0) {
                $stmt = $db->query('SELECT * FROM plan_comptable LIMIT 10');
                $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                echo '<table>';
                echo '<tr><th>ID</th><th>Compte</th><th>Intitulé</th><th>Classe</th><th>4 chiffres</th><th>Tableau</th><th>Type</th><th>Actif</th></tr>';
                foreach ($comptes as $compte) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($compte['id']) . '</td>';
                    echo '<td><strong>' . htmlspecialchars($compte['compte']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($compte['intitule_compte']) . '</td>';
                    echo '<td>' . htmlspecialchars($compte['classe']) . '</td>';
                    echo '<td>' . htmlspecialchars($compte['quatre_chiffres']) . '</td>';
                    echo '<td>' . htmlspecialchars($compte['tableau']) . '</td>';
                    echo '<td>' . htmlspecialchars($compte['type']) . '</td>';
                    echo '<td>' . htmlspecialchars($compte['actif']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }

            // 5. Vérifier table_correspondance
            echo '<h2>📋 Table de correspondance</h2>';
            $stmt = $db->query('SELECT COUNT(*) FROM table_correspondance');
            $countCorr = $stmt->fetchColumn();
            $stmt->closeCursor();

            echo '<div class="info">Nombre de codes dans table_correspondance : ' . $countCorr . '</div>';

            if ($countCorr > 0) {
                $stmt = $db->query('SELECT * FROM table_correspondance LIMIT 10');
                $correspondances = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                echo '<table>';
                echo '<tr><th>Compte</th><th>Classe</th><th>Libellé</th><th>Tableau</th><th>BD</th><th>BC</th><th>RD</th><th>RC</th></tr>';
                foreach ($correspondances as $corr) {
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($corr['compte']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($corr['classe']) . '</td>';
                    echo '<td>' . htmlspecialchars($corr['libelle']) . '</td>';
                    echo '<td>' . htmlspecialchars($corr['tableau']) . '</td>';
                    echo '<td>' . htmlspecialchars($corr['bd'] ?? '-') . '</td>';
                    echo '<td>' . htmlspecialchars($corr['bc'] ?? '-') . '</td>';
                    echo '<td>' . htmlspecialchars($corr['rd'] ?? '-') . '</td>';
                    echo '<td>' . htmlspecialchars($corr['rc'] ?? '-') . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }

            // 6. Vérifier si plan_comptable_backup existe
            echo '<h2>💾 Backup</h2>';
            try {
                $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable_backup');
                $backupCount = $stmt->fetchColumn();
                $stmt->closeCursor();
                echo '<div class="success">✓ Backup créé avec ' . $backupCount . ' enregistrements</div>';
            } catch (PDOException $e) {
                echo '<div class="info">ℹ Pas de table de backup (première migration)</div>';
            }

        } catch (Exception $e) {
            echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>

        <p style="margin-top: 30px;">
            <a href="migrate_plan_comptable.php" style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px;">Relancer la migration</a>
            <a href="pages/settings/plan_comptable_new.php" style="display: inline-block; padding: 10px 20px; background: #27ae60; color: white; text-decoration: none; border-radius: 4px; margin-left: 10px;">Voir plan comptable (nouvelle version)</a>
        </p>
    </div>
</body>
</html>
