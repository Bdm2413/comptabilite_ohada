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
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; border-left: 4px solid #28a745; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; border-left: 4px solid #dc3545; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; border-left: 4px solid #17a2b8; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #3498db; color: white; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
        .btn-danger { background: #e74c3c; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Import du Tableau de Correspondance</h1>

        <?php
        $excelFile = __DIR__ . '/tableau_correspondance.xlsx';

        if (!file_exists($excelFile)) {
            echo '<div class="error">✗ Fichier non trouvé : tableau_correspondance.xlsx</div>';
            echo '<div class="info">Veuillez placer le fichier tableau_correspondance.xlsx dans ' . __DIR__ . '</div>';
            exit;
        }

        echo '<div class="success">✓ Fichier trouvé : tableau_correspondance.xlsx</div>';

        // Méthode : Lire le fichier .xlsx en utilisant ZipArchive (format xlsx = zip + XML)
        if (!class_exists('ZipArchive')) {
            echo '<div class="error">✗ Extension ZipArchive non disponible. Veuillez activer l\'extension zip dans php.ini</div>';
            exit;
        }

        $zip = new ZipArchive;
        if ($zip->open($excelFile) === TRUE) {
            // Lire le fichier sharedStrings.xml (contient les chaînes de caractères)
            $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
            $sharedStrings = [];

            if ($sharedStringsXML) {
                $xml = simplexml_load_string($sharedStringsXML);
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)$si->t;
                }
            }

            // Lire le premier onglet (sheet1.xml)
            $sheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($sheetXML) {
                $xml = simplexml_load_string($sheetXML);

                $rows = [];
                foreach ($xml->sheetData->row as $row) {
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        $value = '';
                        if (isset($cell->v)) {
                            // Type 's' = shared string
                            if (isset($cell['t']) && (string)$cell['t'] === 's') {
                                $value = $sharedStrings[(int)$cell->v];
                            } else {
                                $value = (string)$cell->v;
                            }
                        }
                        $rowData[] = $value;
                    }
                    $rows[] = $rowData;
                }

                $zip->close();

                echo '<div class="success">✓ Fichier Excel lu avec succès (' . count($rows) . ' lignes)</div>';

                // Afficher aperçu
                echo '<h2>📋 Aperçu des données (10 premières lignes)</h2>';
                echo '<table>';
                foreach (array_slice($rows, 0, 10) as $index => $row) {
                    if ($index === 0) {
                        echo '<tr>';
                        foreach ($row as $cell) {
                            echo '<th>' . htmlspecialchars($cell) . '</th>';
                        }
                        echo '</tr>';
                    } else {
                        echo '<tr>';
                        foreach ($row as $cell) {
                            echo '<td>' . htmlspecialchars($cell) . '</td>';
                        }
                        echo '</tr>';
                    }
                }
                echo '</table>';

                if (!isset($_GET['confirm'])) {
                    echo '<div class="info">';
                    echo '<p><strong>⚠️ Attention :</strong> L\'import va :</p>';
                    echo '<ul>';
                    echo '<li>Supprimer toutes les données actuelles de <code>table_correspondance</code></li>';
                    echo '<li>Importer ' . (count($rows) - 1) . ' lignes depuis le fichier Excel</li>';
                    echo '</ul>';
                    echo '<a href="?confirm=1" class="btn btn-danger">Confirmer et importer</a>';
                    echo '</div>';
                } else {
                    // Import confirmé
                    echo '<h2>🔄 Import en cours...</h2>';

                    try {
                        $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
                        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                        // Vider la table
                        $db->exec('DELETE FROM table_correspondance');
                        echo '<div class="success">✓ Table vidée</div>';

                        // Ignorer la première ligne (en-têtes)
                        $headers = array_shift($rows);
                        echo '<div class="info">Colonnes : ' . implode(' | ', $headers) . '</div>';

                        $inserted = 0;
                        $errors = 0;

                        foreach ($rows as $index => $row) {
                            // Ignorer les lignes vides
                            if (empty(array_filter($row))) {
                                continue;
                            }

                            try {
                                // Mapper selon votre structure Excel
                                // Structure: id, compte, classe, libelle, tableau, bd, bc, rd, rc
                                // Index:     0   1       2       3        4        5   6   7   8
                                $id = isset($row[0]) && $row[0] !== '' ? (int)$row[0] : 0; // Ignoré (auto-increment)
                                $compte = isset($row[1]) && $row[1] !== '' ? (int)$row[1] : 0;
                                $classe = isset($row[2]) && $row[2] !== '' ? (int)$row[2] : 0;
                                $libelle = isset($row[3]) ? trim($row[3]) : '';
                                $tableau = isset($row[4]) ? trim($row[4]) : '';
                                $bd = isset($row[5]) && $row[5] !== '' ? trim($row[5]) : null;
                                $bc = isset($row[6]) && $row[6] !== '' ? trim($row[6]) : null;
                                $rd = isset($row[7]) && $row[7] !== '' ? trim($row[7]) : null;
                                $rc = isset($row[8]) && $row[8] !== '' ? trim($row[8]) : null;

                                // Ignorer si compte invalide
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
                                    $bd,
                                    $bc,
                                    $rd,
                                    $rc
                                ]);

                                $inserted++;
                            } catch (PDOException $e) {
                                echo '<div class="error" style="font-size: 11px;">✗ Ligne ' . ($index + 2) . ': ' . $e->getMessage() . '</div>';
                                $errors++;
                            }
                        }

                        echo '<h2>✅ Résultat</h2>';
                        echo '<div class="success">';
                        echo '<p>✓ Enregistrements insérés : <strong>' . $inserted . '</strong></p>';
                        if ($errors > 0) {
                            echo '<p>⚠ Erreurs : <strong>' . $errors . '</strong></p>';
                        }
                        echo '</div>';

                        // Afficher les données importées
                        echo '<h3>📋 Données importées (10 premiers)</h3>';
                        $stmt = $db->query('SELECT * FROM table_correspondance ORDER BY compte LIMIT 10');
                        $imported = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $stmt->closeCursor();

                        echo '<table>';
                        echo '<tr><th>Compte</th><th>Classe</th><th>Libellé</th><th>Tableau</th><th>BD</th><th>BC</th><th>RD</th><th>RC</th></tr>';
                        foreach ($imported as $data) {
                            echo '<tr>';
                            echo '<td><strong>' . $data['compte'] . '</strong></td>';
                            echo '<td>' . $data['classe'] . '</td>';
                            echo '<td>' . htmlspecialchars($data['libelle']) . '</td>';
                            echo '<td>' . htmlspecialchars($data['tableau']) . '</td>';
                            echo '<td>' . htmlspecialchars($data['bd'] ?? '-') . '</td>';
                            echo '<td>' . htmlspecialchars($data['bc'] ?? '-') . '</td>';
                            echo '<td>' . htmlspecialchars($data['rd'] ?? '-') . '</td>';
                            echo '<td>' . htmlspecialchars($data['rc'] ?? '-') . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';

                        echo '<a href="migrate_plan_comptable_force.php" class="btn">Continuer avec la migration du plan comptable</a>';

                    } catch (Exception $e) {
                        echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                }

            } else {
                echo '<div class="error">✗ Impossible de lire sheet1.xml</div>';
            }
        } else {
            echo '<div class="error">✗ Impossible d\'ouvrir le fichier Excel</div>';
        }
        ?>
    </div>
</body>
</html>
