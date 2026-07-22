<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration - Tables Écritures Comptables</title>
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
        h2 { color: #34495e; margin-top: 30px; border-left: 4px solid #3498db; padding-left: 10px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; border-left: 4px solid #28a745; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; border-left: 4px solid #dc3545; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; border-left: 4px solid #17a2b8; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 4px; border-left: 4px solid #ffc107; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
        .btn-danger { background: #e74c3c; }
        .btn:hover { opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #3498db; color: white; }
        .step { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #6c757d; }
        .step-title { font-weight: bold; color: #495057; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗂️ Migration des Tables Écritures Comptables</h1>

        <?php
        $sqlFile = __DIR__ . '/create_ecritures_tables.sql';

        if (!file_exists($sqlFile)) {
            echo '<div class="error">✗ Fichier SQL non trouvé : create_ecritures_tables.sql</div>';
            echo '<div class="info">Emplacement attendu : ' . $sqlFile . '</div>';
            exit;
        }

        echo '<div class="success">✓ Fichier SQL trouvé : create_ecritures_tables.sql</div>';

        if (!isset($_GET['confirm'])) {
            echo '<div class="warning">';
            echo '<h2>⚠️ Avertissement</h2>';
            echo '<p><strong>Cette migration va créer les tables et objets suivants :</strong></p>';
            echo '<ul>';
            echo '<li><strong>Table :</strong> <code>ecritures</code> - En-têtes des écritures comptables</li>';
            echo '<li><strong>Table :</strong> <code>lignes_ecriture</code> - Lignes de détail (débit/crédit)</li>';
            echo '<li><strong>Vue :</strong> <code>v_ecritures_detail</code> - Vue complète avec jointures</li>';
            echo '<li><strong>Vue :</strong> <code>v_ecritures_totaux</code> - Totaux et équilibre par écriture</li>';
            echo '<li><strong>Trigger :</strong> <code>generate_numero_ecriture</code> - Auto-génération des numéros</li>';
            echo '<li><strong>Fonction :</strong> <code>fn_ecriture_equilibree</code> - Vérification équilibre</li>';
            echo '</ul>';
            echo '<p><strong>Caractéristiques :</strong></p>';
            echo '<ul>';
            echo '<li>✅ Partie double automatique (débit = crédit)</li>';
            echo '<li>✅ Numérotation automatique des écritures</li>';
            echo '<li>✅ Audit trail complet (créateur, modificateur, dates)</li>';
            echo '<li>✅ Contraintes d\'intégrité référentielle</li>';
            echo '<li>✅ Pas de workflow de validation (système simplifié)</li>';
            echo '</ul>';
            echo '<p><strong>⚠️ Si les tables existent déjà, elles seront conservées (CREATE IF NOT EXISTS).</strong></p>';
            echo '<a href="?confirm=1" class="btn btn-danger">Confirmer la migration</a>';
            echo '<a href="../" class="btn">Annuler</a>';
            echo '</div>';
        } else {
            // Migration confirmée
            echo '<h2>🔄 Exécution de la migration...</h2>';

            try {
                $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada;charset=utf8mb4', 'root', '');
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

                echo '<div class="success">✓ Connexion à la base de données réussie</div>';

                // Lire le fichier SQL
                $sql = file_get_contents($sqlFile);

                echo '<div class="info">📄 Fichier SQL chargé (' . number_format(strlen($sql)) . ' caractères)</div>';

                // Séparer les commandes SQL
                // On doit gérer les délimiteurs pour les triggers et fonctions
                $commands = [];
                $currentCommand = '';
                $inDelimiter = false;

                $lines = explode("\n", $sql);

                foreach ($lines as $line) {
                    $trimmedLine = trim($line);

                    // Ignorer les commentaires et lignes vides
                    if (empty($trimmedLine) || substr($trimmedLine, 0, 2) === '--') {
                        continue;
                    }

                    // Détecter le changement de délimiteur
                    if (stripos($trimmedLine, 'DELIMITER') === 0) {
                        if (!$inDelimiter) {
                            $inDelimiter = true;
                        } else {
                            $inDelimiter = false;
                            if (!empty($currentCommand)) {
                                $commands[] = trim($currentCommand);
                                $currentCommand = '';
                            }
                        }
                        continue;
                    }

                    $currentCommand .= $line . "\n";

                    // Si on n'est pas dans un bloc DELIMITER, on cherche les point-virgules
                    if (!$inDelimiter && substr($trimmedLine, -1) === ';') {
                        $commands[] = trim($currentCommand);
                        $currentCommand = '';
                    }
                }

                // Ajouter la dernière commande si elle existe
                if (!empty($currentCommand)) {
                    $commands[] = trim($currentCommand);
                }

                echo '<div class="info">📊 ' . count($commands) . ' commandes SQL à exécuter</div>';

                // Exécuter chaque commande
                $executed = 0;
                $errors = 0;
                $results = [];

                foreach ($commands as $index => $command) {
                    if (empty($command)) continue;

                    try {
                        $db->exec($command);
                        $executed++;

                        // Extraire le type de commande pour l'affichage
                        $commandType = 'SQL';
                        if (stripos($command, 'CREATE TABLE') !== false) {
                            preg_match('/CREATE TABLE[^`]*`?([a-z_]+)`?/i', $command, $matches);
                            $commandType = 'Table créée : ' . ($matches[1] ?? 'inconnue');
                        } elseif (stripos($command, 'CREATE VIEW') !== false || stripos($command, 'CREATE OR REPLACE VIEW') !== false) {
                            preg_match('/VIEW[^`]*`?([a-z_]+)`?/i', $command, $matches);
                            $commandType = 'Vue créée : ' . ($matches[1] ?? 'inconnue');
                        } elseif (stripos($command, 'CREATE TRIGGER') !== false) {
                            preg_match('/TRIGGER[^`]*`?([a-z_]+)`?/i', $command, $matches);
                            $commandType = 'Trigger créé : ' . ($matches[1] ?? 'inconnu');
                        } elseif (stripos($command, 'CREATE FUNCTION') !== false) {
                            preg_match('/FUNCTION[^`]*`?([a-z_]+)`?/i', $command, $matches);
                            $commandType = 'Fonction créée : ' . ($matches[1] ?? 'inconnue');
                        }

                        $results[] = ['type' => 'success', 'message' => $commandType];

                    } catch (PDOException $e) {
                        $errors++;
                        $errorMsg = $e->getMessage();

                        // Ignorer certaines erreurs non bloquantes
                        if (stripos($errorMsg, 'already exists') !== false ||
                            stripos($errorMsg, 'Duplicate') !== false) {
                            $results[] = ['type' => 'warning', 'message' => 'Objet déjà existant (ignoré)'];
                        } else {
                            $results[] = ['type' => 'error', 'message' => $errorMsg];
                        }
                    }
                }

                // Afficher les résultats
                echo '<h2>✅ Résultats de la migration</h2>';
                echo '<div class="success">';
                echo '<p>✓ Commandes exécutées avec succès : <strong>' . $executed . '</strong></p>';
                if ($errors > 0) {
                    echo '<p>⚠ Erreurs ou avertissements : <strong>' . $errors . '</strong></p>';
                }
                echo '</div>';

                // Détails des opérations
                echo '<h3>📋 Détails des opérations</h3>';
                echo '<table>';
                echo '<tr><th>N°</th><th>Statut</th><th>Opération</th></tr>';
                foreach ($results as $index => $result) {
                    $icon = $result['type'] === 'success' ? '✓' : ($result['type'] === 'warning' ? '⚠' : '✗');
                    $class = $result['type'] === 'success' ? 'success' : ($result['type'] === 'warning' ? 'warning' : 'error');
                    echo '<tr>';
                    echo '<td>' . ($index + 1) . '</td>';
                    echo '<td><span class="' . $class . '">' . $icon . '</span></td>';
                    echo '<td>' . htmlspecialchars($result['message']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';

                // Vérifier les tables créées
                echo '<h2>📊 Vérification des tables</h2>';

                $tables = ['ecritures', 'lignes_ecriture'];
                foreach ($tables as $table) {
                    $stmt = $db->query("SHOW TABLES LIKE '$table'");
                    if ($stmt->rowCount() > 0) {
                        // Compter les enregistrements
                        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                        echo '<div class="success">✓ Table <code>' . $table . '</code> existe (' . $count . ' enregistrements)</div>';

                        // Afficher la structure
                        echo '<details style="margin: 10px 0;">';
                        echo '<summary style="cursor: pointer; color: #3498db; font-weight: bold;">Voir la structure de ' . $table . '</summary>';
                        echo '<table style="margin-top: 10px;">';
                        echo '<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th></tr>';
                        $fields = $db->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
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
                        echo '</details>';
                    } else {
                        echo '<div class="error">✗ Table <code>' . $table . '</code> n\'existe pas</div>';
                    }
                }

                // Vérifier les vues
                echo '<h3>🔍 Vues créées</h3>';
                $views = ['v_ecritures_detail', 'v_ecritures_totaux'];
                foreach ($views as $view) {
                    $stmt = $db->query("SHOW TABLES LIKE '$view'");
                    if ($stmt->rowCount() > 0) {
                        echo '<div class="success">✓ Vue <code>' . $view . '</code> créée</div>';
                    } else {
                        echo '<div class="warning">⚠ Vue <code>' . $view . '</code> non trouvée</div>';
                    }
                }

                // Vérifier les triggers
                echo '<h3>⚡ Triggers créés</h3>';
                $triggers = $db->query("SHOW TRIGGERS WHERE `Trigger` = 'generate_numero_ecriture'")->fetchAll();
                if (count($triggers) > 0) {
                    echo '<div class="success">✓ Trigger <code>generate_numero_ecriture</code> créé</div>';
                } else {
                    echo '<div class="warning">⚠ Trigger <code>generate_numero_ecriture</code> non trouvé</div>';
                }

                // Vérifier les fonctions
                echo '<h3>🔧 Fonctions créées</h3>';
                $functions = $db->query("SHOW FUNCTION STATUS WHERE Db = 'comptabilite_syscohada' AND Name = 'fn_ecriture_equilibree'")->fetchAll();
                if (count($functions) > 0) {
                    echo '<div class="success">✓ Fonction <code>fn_ecriture_equilibree</code> créée</div>';
                } else {
                    echo '<div class="warning">⚠ Fonction <code>fn_ecriture_equilibree</code> non trouvée</div>';
                }

                echo '<h2>🎉 Migration terminée !</h2>';
                echo '<div class="success">';
                echo '<p><strong>Le module des écritures comptables est prêt à être utilisé.</strong></p>';
                echo '<p>Prochaines étapes :</p>';
                echo '<ul>';
                echo '<li>Créer les pages de saisie des écritures</li>';
                echo '<li>Créer la page de consultation/liste des écritures</li>';
                echo '<li>Ajouter les liens dans le menu de navigation</li>';
                echo '</ul>';
                echo '</div>';

                echo '<a href="../pages/settings/plan_comptable.php" class="btn">Aller au Plan Comptable</a>';
                echo '<a href="../" class="btn">Retour à l\'accueil</a>';

            } catch (PDOException $e) {
                echo '<div class="error">✗ Erreur de connexion : ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
        ?>
    </div>
</body>
</html>
