<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Tableau de Correspondance</title>
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
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 12px;
            border-radius: 4px;
            border-left: 4px solid #17a2b8;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #3498db;
            color: white;
            font-size: 12px;
        }
        td {
            font-size: 12px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Import du Tableau de Correspondance depuis Excel</h1>

        <?php
        // Chemin du fichier Excel
        $excelFile = __DIR__ . '/tableau_correspondance.xlsx';

        if (!file_exists($excelFile)) {
            echo '<div class="error">✗ Fichier Excel non trouvé : ' . $excelFile . '</div>';
            exit;
        }

        echo '<div class="success">✓ Fichier Excel trouvé : tableau_correspondance.xlsx</div>';

        // Vérifier si SimpleXLSX est disponible, sinon utiliser une lecture manuelle
        // Télécharger SimpleXLSX depuis https://github.com/shuchkin/simplexlsx

        // Alternative: Convertir le fichier .xlsx en CSV ou utiliser une bibliothèque
        echo '<div class="info">📦 Utilisation de la bibliothèque SimpleXLSX pour lire le fichier...</div>';

        // Télécharger SimpleXLSX
        $simpleXLSXFile = __DIR__ . '/SimpleXLSX.php';

        if (!file_exists($simpleXLSXFile)) {
            echo '<div class="info">📥 Téléchargement de SimpleXLSX...</div>';
            $simpleXLSXContent = file_get_contents('https://raw.githubusercontent.com/shuchkin/simplexlsx/master/src/SimpleXLSX.php');
            if ($simpleXLSXContent) {
                file_put_contents($simpleXLSXFile, $simpleXLSXContent);
                echo '<div class="success">✓ SimpleXLSX téléchargé</div>';
            } else {
                echo '<div class="error">✗ Impossible de télécharger SimpleXLSX. Veuillez le télécharger manuellement.</div>';
                echo '<div class="info">Alternative: Veuillez exporter le fichier Excel en CSV et utiliser un autre script.</div>';
                exit;
            }
        }

        require_once $simpleXLSXFile;

        try {
            $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            echo '<div class="success">✓ Connexion à la base de données réussie</div>';

            // Lire le fichier Excel
            if ($xlsx = SimpleXLSX::parse($excelFile)) {
                echo '<h2>📖 Lecture du fichier Excel</h2>';

                $rows = $xlsx->rows();

                echo '<div class="info">Nombre de lignes : ' . count($rows) . '</div>';

                // Afficher les premières lignes pour vérification
                echo '<h3>📋 Aperçu des données (10 premières lignes)</h3>';
                echo '<table>';
                echo '<tr><th>Ligne</th><th>Colonnes</th></tr>';

                foreach (array_slice($rows, 0, 10) as $index => $row) {
                    echo '<tr>';
                    echo '<td>' . ($index + 1) . '</td>';
                    echo '<td>' . implode(' | ', array_map('htmlspecialchars', $row)) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';

                // Demander confirmation avant d'importer
                if (!isset($_GET['confirm'])) {
                    echo '<div class="info">';
                    echo '<p><strong>⚠️ Attention :</strong> L\'import va :</p>';
                    echo '<ul>';
                    echo '<li>Supprimer toutes les données actuelles de la table <code>table_correspondance</code></li>';
                    echo '<li>Importer ' . (count($rows) - 1) . ' lignes depuis le fichier Excel</li>';
                    echo '</ul>';
                    echo '<p><a href="?confirm=1" class="btn" style="background: #e74c3c;">Confirmer l\'import</a></p>';
                    echo '</div>';
                } else {
                    // Import confirmé
                    echo '<h2>🔄 Import en cours...</h2>';

                    // Vider la table
                    $db->exec('DELETE FROM table_correspondance');
                    echo '<div class="success">✓ Table vidée</div>';

                    // Déterminer les colonnes (première ligne = en-têtes)
                    $headers = array_shift($rows);

                    echo '<div class="info">Colonnes détectées : ' . implode(', ', $headers) . '</div>';

                    // Préparer l'insertion
                    $inserted = 0;
                    $errors = 0;

                    foreach ($rows as $index => $row) {
                        // Ignorer les lignes vides
                        if (empty(array_filter($row))) {
                            continue;
                        }

                        try {
                            // Mapper les colonnes selon votre structure
                            // Adaptez les indices selon la structure de votre Excel
                            $compte = isset($row[0]) ? (int)$row[0] : 0;
                            $classe = isset($row[1]) ? (int)$row[1] : 0;
                            $libelle = isset($row[2]) ? trim($row[2]) : '';
                            $tableau = isset($row[3]) ? trim($row[3]) : '';
                            $bd = isset($row[4]) ? trim($row[4]) : null;
                            $bc = isset($row[5]) ? trim($row[5]) : null;
                            $rd = isset($row[6]) ? trim($row[6]) : null;
                            $rc = isset($row[7]) ? trim($row[7]) : null;

                            // Ignorer si compte ou libellé est vide
                            if ($compte == 0 || empty($libelle)) {
                                continue;
                            }

                            $stmt = $db->prepare("
                                INSERT INTO table_correspondance
                                (compte, classe, libelle, tableau, bd, bc, rd, rc)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");

                            $stmt->execute([
                                $compte,
                                $classe,
                                $libelle,
                                $tableau,
                                $bd ?: null,
                                $bc ?: null,
                                $rd ?: null,
                                $rc ?: null
                            ]);

                            $inserted++;
                        } catch (PDOException $e) {
                            echo '<div class="error">✗ Erreur ligne ' . ($index + 2) . ': ' . $e->getMessage() . '</div>';
                            $errors++;
                        }
                    }

                    echo '<h2>✅ Résultat de l\'import</h2>';
                    echo '<div class="success">';
                    echo '<p>✓ Enregistrements insérés : <strong>' . $inserted . '</strong></p>';
                    if ($errors > 0) {
                        echo '<p>⚠ Erreurs : <strong>' . $errors . '</strong></p>';
                    }
                    echo '</div>';

                    // Afficher quelques enregistrements importés
                    echo '<h3>📋 Données importées (10 premiers)</h3>';
                    $stmt = $db->query('SELECT * FROM table_correspondance ORDER BY compte LIMIT 10');
                    $imported = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    echo '<table>';
                    echo '<tr><th>Compte</th><th>Classe</th><th>Libellé</th><th>Tableau</th><th>BD</th><th>BC</th><th>RD</th><th>RC</th></tr>';
                    foreach ($imported as $row) {
                        echo '<tr>';
                        echo '<td><strong>' . $row['compte'] . '</strong></td>';
                        echo '<td>' . $row['classe'] . '</td>';
                        echo '<td>' . htmlspecialchars($row['libelle']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['tableau']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['bd'] ?? '-') . '</td>';
                        echo '<td>' . htmlspecialchars($row['bc'] ?? '-') . '</td>';
                        echo '<td>' . htmlspecialchars($row['rd'] ?? '-') . '</td>';
                        echo '<td>' . htmlspecialchars($row['rc'] ?? '-') . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';

                    echo '<a href="migrate_plan_comptable_force.php" class="btn">Continuer avec la migration du plan comptable</a>';
                }

            } else {
                echo '<div class="error">✗ Erreur lors de la lecture du fichier Excel: ' . SimpleXLSX::parseError() . '</div>';
            }

        } catch (Exception $e) {
            echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        ?>
    </div>
</body>
</html>
