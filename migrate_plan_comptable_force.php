<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Plan Comptable - Force</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 900px;
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
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            border-left: 4px solid #28a745;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            border-left: 4px solid #dc3545;
            margin: 10px 0;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 12px;
            border-radius: 4px;
            border-left: 4px solid #ffc107;
            margin: 10px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 12px;
            border-radius: 4px;
            border-left: 4px solid #17a2b8;
            margin: 10px 0;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            max-height: 300px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .btn:hover {
            background: #2980b9;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Migration Plan Comptable (Force)</h1>
        <p>Cette migration va <strong>forcer</strong> la transformation de la structure de <code>plan_comptable</code>.</p>

        <?php
        try {
            $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            echo '<div class="success">✓ Connexion à la base de données réussie</div>';

            // Étape 1: Sauvegarder l'ancienne table si elle existe
            echo '<h2>📦 Étape 1: Sauvegarde</h2>';

            // Vérifier si la table plan_comptable existe
            $stmt = $db->query("SHOW TABLES LIKE 'plan_comptable'");
            $tableExists = $stmt->fetch();
            $stmt->closeCursor();

            if ($tableExists) {
                $db->exec('DROP TABLE IF EXISTS plan_comptable_backup_old');
                $db->exec('CREATE TABLE plan_comptable_backup_old AS SELECT * FROM plan_comptable');

                $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable_backup_old');
                $backupCount = $stmt->fetchColumn();
                $stmt->closeCursor();

                echo '<div class="success">✓ Sauvegarde créée : ' . $backupCount . ' enregistrements</div>';
            } else {
                echo '<div class="info">ℹ Table plan_comptable n\'existe pas (première installation)</div>';
            }

            // Étape 2: Supprimer les contraintes de clé étrangère qui pointent vers plan_comptable
            echo '<h2>🔗 Étape 2: Suppression des contraintes de clé étrangère</h2>';

            if ($tableExists) {
                // Récupérer toutes les contraintes qui référencent plan_comptable
                $stmt = $db->query("
                    SELECT
                        TABLE_NAME,
                        CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE REFERENCED_TABLE_SCHEMA = 'comptabilite_syscohada'
                    AND REFERENCED_TABLE_NAME = 'plan_comptable'
                ");
                $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                if (!empty($constraints)) {
                    foreach ($constraints as $constraint) {
                        try {
                            $sql = "ALTER TABLE `{$constraint['TABLE_NAME']}` DROP FOREIGN KEY `{$constraint['CONSTRAINT_NAME']}`";
                            $db->exec($sql);
                            echo '<div class="info">✓ Contrainte supprimée : ' . $constraint['CONSTRAINT_NAME'] . ' de ' . $constraint['TABLE_NAME'] . '</div>';
                        } catch (PDOException $e) {
                            echo '<div class="warning">⚠ Impossible de supprimer ' . $constraint['CONSTRAINT_NAME'] . ': ' . $e->getMessage() . '</div>';
                        }
                    }
                } else {
                    echo '<div class="info">ℹ Aucune contrainte à supprimer</div>';
                }
            } else {
                echo '<div class="info">ℹ Aucune contrainte à supprimer (table n\'existe pas)</div>';
            }

            // Supprimer TOUTES les contraintes de clé étrangère de TOUTES les tables
            // qui pourraient référencer plan_comptable (même via des colonnes qui n'existent plus)
            echo '<div class="info">🔍 Recherche de toutes les contraintes potentielles...</div>';

            $tablesToCheck = ['lignes_ecriture', 'plan_tiers', 'tiers'];

            foreach ($tablesToCheck as $tableName) {
                try {
                    $stmt = $db->query("
                        SELECT CONSTRAINT_NAME
                        FROM information_schema.TABLE_CONSTRAINTS
                        WHERE TABLE_SCHEMA = 'comptabilite_syscohada'
                        AND TABLE_NAME = '$tableName'
                        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                    ");
                    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    foreach ($constraints as $constraint) {
                        try {
                            $db->exec("ALTER TABLE `$tableName` DROP FOREIGN KEY `{$constraint['CONSTRAINT_NAME']}`");
                            echo '<div class="info">✓ Contrainte supprimée : ' . $constraint['CONSTRAINT_NAME'] . ' de ' . $tableName . '</div>';
                        } catch (PDOException $e) {
                            // Ignorer si déjà supprimée
                        }
                    }
                } catch (PDOException $e) {
                    // Table n'existe pas, ignorer
                }
            }

            // Étape 3: Supprimer complètement l'ancienne table
            echo '<h2>🗑️ Étape 3: Suppression de l\'ancienne structure</h2>';
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');
            $db->exec('DROP TABLE IF EXISTS plan_comptable');
            echo '<div class="success">✓ Ancienne table supprimée</div>';

            // Étape 4: Créer la nouvelle structure
            echo '<h2>🏗️ Étape 4: Création de la nouvelle structure</h2>';

            $createTableSQL = "
            CREATE TABLE plan_comptable (
                id INT AUTO_INCREMENT PRIMARY KEY,
                compte INT NOT NULL UNIQUE COMMENT 'Numéro de compte complet (ex: 4111000)',
                intitule_compte VARCHAR(255) NULL COMMENT 'Intitulé du compte',
                classe INT NOT NULL COMMENT 'Classe du compte (1-8) - 1er chiffre',
                quatre_chiffres INT NOT NULL COMMENT 'Lien vers table_correspondance (4 premiers chiffres)',
                tableau VARCHAR(50) NOT NULL COMMENT 'Bilan ou Résultat (hérité de table_correspondance)',
                type ENUM('Client', 'Fournisseur', 'Salarié', 'Banque', 'Caisse',
                          'Amortis/Provision', 'Résultat-Bilan', 'Charge', 'Produit',
                          'Résultat-Gestion', 'Immobilisation', 'Capitaux', 'Stock',
                          'Titre', 'Etat', 'CNPS', 'Autres') NOT NULL DEFAULT 'Autres',
                bd TEXT NULL COMMENT 'Position Bilan Débit (hérité)',
                bc TEXT NULL COMMENT 'Position Bilan Crédit (hérité)',
                rd TEXT NULL COMMENT 'Position Résultat Débit (hérité)',
                rc TEXT NULL COMMENT 'Position Résultat Crédit (hérité)',
                actif ENUM('Oui', 'Non') DEFAULT 'Oui',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_compte (compte),
                INDEX idx_classe (classe),
                INDEX idx_quatre_chiffres (quatre_chiffres),
                INDEX idx_type (type),
                INDEX idx_actif (actif),

                CONSTRAINT plan_comptable_ibfk_1
                    FOREIGN KEY (quatre_chiffres)
                    REFERENCES table_correspondance(compte)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";

            $db->exec($createTableSQL);
            echo '<div class="success">✓ Nouvelle structure créée avec succès</div>';

            $db->exec('SET FOREIGN_KEY_CHECKS = 1');

            // Étape 5: Recréer les contraintes pour lignes_ecriture si nécessaire
            echo '<h2>🔗 Étape 5: Recréation des contraintes</h2>';

            // Vérifier si la table lignes_ecriture existe et a une colonne numero_compte ou compte
            try {
                $stmt = $db->query("SHOW COLUMNS FROM lignes_ecriture LIKE 'numero_compte'");
                $hasNumeroCompte = $stmt->fetch();
                $stmt->closeCursor();

                if ($hasNumeroCompte) {
                    // Renommer la colonne numero_compte en compte et changer le type
                    echo '<div class="info">ℹ Mise à jour de la colonne lignes_ecriture.numero_compte...</div>';
                    $db->exec("ALTER TABLE lignes_ecriture CHANGE numero_compte compte INT NULL");
                    echo '<div class="success">✓ Colonne lignes_ecriture.compte mise à jour (INT)</div>';
                }

                // Ajouter la contrainte de clé étrangère
                $db->exec("
                    ALTER TABLE lignes_ecriture
                    ADD CONSTRAINT lignes_ecriture_ibfk_2
                    FOREIGN KEY (compte)
                    REFERENCES plan_comptable(compte)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
                ");
                echo '<div class="success">✓ Contrainte de clé étrangère ajoutée pour lignes_ecriture</div>';

            } catch (PDOException $e) {
                echo '<div class="warning">⚠ Table lignes_ecriture : ' . $e->getMessage() . '</div>';
            }

            // Étape 6: Insérer des comptes d'exemple
            echo '<h2>💾 Étape 6: Insertion de comptes d\'exemple</h2>';

            // Vérifier quels codes existent dans table_correspondance
            $stmt = $db->query('SELECT COUNT(*) FROM table_correspondance');
            $corrCount = $stmt->fetchColumn();
            $stmt->closeCursor();

            echo '<div class="info">Codes disponibles dans table_correspondance : ' . $corrCount . '</div>';

            if ($corrCount == 0) {
                echo '<div class="warning">⚠ La table de correspondance est vide. Veuillez d\'abord la peupler.</div>';
                echo '<a href="populate_data.php" class="btn">Peupler la table de correspondance</a>';
            } else {
                // Insérer compte 4111000 - CLIENTS si le code 4111 existe
                $stmt = $db->query('SELECT * FROM table_correspondance WHERE compte = 4111');
                $code4111 = $stmt->fetch();
                $stmt->closeCursor();

                if ($code4111) {
                    $stmt = $db->prepare("
                        INSERT INTO plan_comptable
                        (compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        4111000,
                        'CLIENTS',
                        4,
                        4111,
                        $code4111['tableau'],
                        'Client',
                        $code4111['bd'],
                        $code4111['bc'],
                        $code4111['rd'],
                        $code4111['rc'],
                        'Oui'
                    ]);

                    echo '<div class="success">✓ Compte créé : 4111000 - CLIENTS</div>';
                } else {
                    echo '<div class="warning">⚠ Code 4111 non trouvé dans table_correspondance</div>';
                }

                // Insérer compte 4011000 - FOURNISSEURS si le code 4011 existe
                $stmt = $db->query('SELECT * FROM table_correspondance WHERE compte = 4011');
                $code4011 = $stmt->fetch();
                $stmt->closeCursor();

                if ($code4011) {
                    $stmt = $db->prepare("
                        INSERT INTO plan_comptable
                        (compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        4011000,
                        'FOURNISSEURS',
                        4,
                        4011,
                        $code4011['tableau'],
                        'Fournisseur',
                        $code4011['bd'],
                        $code4011['bc'],
                        $code4011['rd'],
                        $code4011['rc'],
                        'Oui'
                    ]);

                    echo '<div class="success">✓ Compte créé : 4011000 - FOURNISSEURS</div>';
                } else {
                    echo '<div class="warning">⚠ Code 4011 non trouvé dans table_correspondance</div>';
                }

                // Insérer compte 5711000 - CAISSE si le code 5711 existe
                $stmt = $db->query('SELECT * FROM table_correspondance WHERE compte = 5711');
                $code5711 = $stmt->fetch();
                $stmt->closeCursor();

                if ($code5711) {
                    $stmt = $db->prepare("
                        INSERT INTO plan_comptable
                        (compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        5711000,
                        'CAISSE EN MONNAIE NATIONALE',
                        5,
                        5711,
                        $code5711['tableau'],
                        'Caisse',
                        $code5711['bd'],
                        $code5711['bc'],
                        $code5711['rd'],
                        $code5711['rc'],
                        'Oui'
                    ]);

                    echo '<div class="success">✓ Compte créé : 5711000 - CAISSE EN MONNAIE NATIONALE</div>';
                }
            }

            // Étape 7: Vérification finale
            echo '<h2>✅ Étape 7: Vérification finale</h2>';

            $stmt = $db->query('DESCRIBE plan_comptable');
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            echo '<div class="success">✓ Colonnes créées :</div>';
            echo '<pre>';
            foreach ($columns as $col) {
                echo str_pad($col['Field'], 20) . ' | ' . $col['Type'] . "\n";
            }
            echo '</pre>';

            $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable');
            $totalComptes = $stmt->fetchColumn();
            $stmt->closeCursor();

            echo '<div class="success">✓ Migration terminée avec succès !<br>Total des comptes : ' . $totalComptes . '</div>';

            echo '<a href="verify_migration.php" class="btn">Vérifier la migration</a>';
            echo '<a href="pages/settings/plan_comptable_new.php" class="btn" style="background: #28a745; margin-left: 10px;">Voir le Plan Comptable</a>';

        } catch (Exception $e) {
            echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        ?>
    </div>
</body>
</html>
