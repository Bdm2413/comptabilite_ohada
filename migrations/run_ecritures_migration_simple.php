<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration - Tables Écritures</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; border-left: 4px solid #28a745; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; border-left: 4px solid #dc3545; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; border-left: 4px solid #17a2b8; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 4px; border-left: 4px solid #ffc107; margin: 10px 0; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
        .btn-danger { background: #e74c3c; }
        .btn:hover { opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #3498db; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗂️ Migration Tables Écritures Comptables</h1>

        <?php
        if (!isset($_GET['confirm'])) {
            echo '<div class="warning">';
            echo '<h2>⚠️ Cette migration va créer :</h2>';
            echo '<ul>';
            echo '<li>Table <code>ecritures</code> - En-têtes des écritures</li>';
            echo '<li>Table <code>lignes_ecriture</code> - Lignes détaillées (débit/crédit)</li>';
            echo '<li>Vue <code>v_ecritures_detail</code> - Consultation complète</li>';
            echo '<li>Vue <code>v_ecritures_totaux</code> - Totaux et équilibre</li>';
            echo '</ul>';
            echo '<a href="?confirm=1" class="btn btn-danger">Confirmer la migration</a>';
            echo '<a href="../" class="btn">Annuler</a>';
            echo '</div>';
        } else {
            try {
                $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada;charset=utf8mb4', 'root', '');
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                echo '<div class="success">✓ Connexion réussie</div>';

                // Lire et exécuter le SQL
                $sql = file_get_contents(__DIR__ . '/create_ecritures_tables_simple.sql');

                // Séparer par point-virgule et exécuter
                $statements = array_filter(array_map('trim', explode(';', $sql)));

                $executed = 0;
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $db->exec($statement);
                            $executed++;
                        } catch (PDOException $e) {
                            if (strpos($e->getMessage(), 'already exists') === false) {
                                echo '<div class="error">Erreur: ' . htmlspecialchars($e->getMessage()) . '</div>';
                            }
                        }
                    }
                }

                echo '<div class="success">✓ ' . $executed . ' commandes exécutées</div>';

                // Vérification
                $tables = ['ecritures', 'lignes_ecriture'];
                foreach ($tables as $table) {
                    $stmt = $db->query("SHOW TABLES LIKE '$table'");
                    if ($stmt->rowCount() > 0) {
                        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                        echo '<div class="success">✓ Table <code>' . $table . '</code> créée (' . $count . ' lignes)</div>';
                    }
                }

                echo '<h2>🎉 Migration réussie !</h2>';
                echo '<a href="../pages/settings/plan_comptable.php" class="btn">Continuer</a>';

            } catch (PDOException $e) {
                echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
        ?>
    </div>
</body>
</html>
