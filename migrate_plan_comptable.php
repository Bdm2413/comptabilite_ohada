<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Plan Comptable v3</title>
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Migration Plan Comptable v3</h1>
        <p>Cette migration va transformer la structure de <code>plan_comptable</code> pour correspondre au modèle d'accounting_workflow_approval.</p>

        <?php
        try {
            $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

            echo '<div class="success">✓ Connexion à la base de données réussie</div>';

            // Lire et exécuter le fichier SQL
            $sqlFile = __DIR__ . '/database/migration_plan_comptable_v3.sql';
            if (!file_exists($sqlFile)) {
                throw new Exception("Fichier SQL non trouvé: $sqlFile");
            }

            $sql = file_get_contents($sqlFile);

            // Séparer les commandes SQL
            $commands = array_filter(
                array_map('trim', explode(';', $sql)),
                function($cmd) {
                    return !empty($cmd) &&
                           !preg_match('/^--/', $cmd) &&
                           !preg_match('/^\/\*/', $cmd);
                }
            );

            echo '<div class="info">📄 Exécution du script de migration...</div>';

            foreach ($commands as $command) {
                if (trim($command)) {
                    try {
                        // Ignorer les commandes SELECT dans le fichier SQL
                        if (stripos(trim($command), 'SELECT') === 0) {
                            continue;
                        }
                        $db->exec($command);
                    } catch (PDOException $e) {
                        // Ignorer certaines erreurs non critiques
                        if (strpos($e->getMessage(), 'already exists') === false &&
                            strpos($e->getMessage(), "doesn't exist") === false) {
                            echo '<div class="warning">⚠ Warning: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                    }
                }
            }

            echo '<div class="success">✓ Structure de plan_comptable migrée avec succès</div>';

            // Fermer toute transaction en cours et créer une nouvelle connexion propre
            $db = null;
            $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Vérifier si table_correspondance a des données
            $stmt = $db->query('SELECT COUNT(*) FROM table_correspondance');
            $count = $stmt->fetchColumn();
            $stmt->closeCursor();
            echo '<div class="info">📊 Table de correspondance : ' . $count . ' enregistrements</div>';

            if ($count == 0) {
                echo '<div class="error">✗ La table_correspondance est vide. Vous devez d\'abord la peupler avant de créer des comptes.</div>';
                echo '<a href="populate_data.php" class="btn">Peupler la table de correspondance</a>';
            } else {
                // Insérer quelques exemples de comptes si souhaité
                echo '<h2>💾 Insertion de comptes d\'exemple</h2>';

                // Vérifier quels codes à 4 chiffres existent
                $stmt = $db->query('SELECT compte, libelle FROM table_correspondance ORDER BY compte');
                $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                echo '<div class="info">Codes disponibles dans table_correspondance :</div>';
                echo '<pre>';
                foreach (array_slice($codes, 0, 10) as $code) {
                    echo $code['compte'] . ' - ' . $code['libelle'] . "\n";
                }
                if (count($codes) > 10) {
                    echo '... et ' . (count($codes) - 10) . ' autres\n';
                }
                echo '</pre>';

                // Créer un compte d'exemple si un code 4111 existe
                $stmt = $db->query('SELECT * FROM table_correspondance WHERE compte = 4111');
                $code4111 = $stmt->fetch();
                $stmt->closeCursor();

                if ($code4111) {
                    // Vérifier si le compte existe déjà
                    $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable WHERE compte = 4111000');
                    $existingAccount = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if ($existingAccount == 0) {
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

                        echo '<div class="success">✓ Compte exemple créé : 4111000 - CLIENTS</div>';
                    } else {
                        echo '<div class="warning">⚠ Compte 4111000 existe déjà</div>';
                    }
                }

                // Créer un compte fournisseur si code 4011 existe
                $stmt = $db->query('SELECT * FROM table_correspondance WHERE compte = 4011');
                $code4011 = $stmt->fetch();
                $stmt->closeCursor();

                if ($code4011) {
                    $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable WHERE compte = 4011000');
                    $existingAccount = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if ($existingAccount == 0) {
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

                        echo '<div class="success">✓ Compte exemple créé : 4011000 - FOURNISSEURS</div>';
                    }
                }
            }

            // Statistiques finales
            $stmt = $db->query('SELECT COUNT(*) FROM plan_comptable');
            $totalComptes = $stmt->fetchColumn();
            $stmt->closeCursor();
            echo '<h2>📈 Statistiques finales</h2>';
            echo '<div class="success">✓ Total des comptes dans plan_comptable : ' . $totalComptes . '</div>';

            echo '<a href="pages/settings/plan_comptable.php" class="btn">Voir le plan comptable</a>';

        } catch (Exception $e) {
            echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        ?>
    </div>
</body>
</html>
