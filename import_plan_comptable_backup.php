<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Plan Comptable</title>
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
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 4px; border-left: 4px solid #ffc107; margin: 10px 0; }
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
        <h1>📊 Import du Plan Comptable depuis Excel</h1>

        <?php
        $excelFile = __DIR__ . '/plan_comptable_30102025.xlsx';

        if (!file_exists($excelFile)) {
            echo '<div class="error">✗ Fichier non trouvé : plan_comptable_30102025.xlsx</div>';
            echo '<div class="info">Veuillez placer le fichier dans ' . __DIR__ . '</div>';
            exit;
        }

        echo '<div class="success">✓ Fichier trouvé : plan_comptable_30102025.xlsx</div>';

        // Vérifier ZipArchive
        if (!class_exists('ZipArchive')) {
            echo '<div class="error">✗ Extension ZipArchive non disponible</div>';
            exit;
        }

        $zip = new ZipArchive;
        if ($zip->open($excelFile) === TRUE) {
            // Lire sharedStrings.xml
            $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
            $sharedStrings = [];

            if ($sharedStringsXML) {
                $xml = simplexml_load_string($sharedStringsXML);
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)$si->t;
                }
            }

            // Lire sheet1.xml
            $sheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($sheetXML) {
                $xml = simplexml_load_string($sheetXML);

                $rows = [];
                foreach ($xml->sheetData->row as $row) {
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        $value = '';
                        if (isset($cell->v)) {
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
                    echo '<div class="warning">';
                    echo '<p><strong>⚠️ Attention :</strong> L\'import va :</p>';
                    echo '<ul>';
                    echo '<li>Supprimer TOUS les comptes actuels de <code>plan_comptable</code></li>';
                    echo '<li>Importer ' . (count($rows) - 1) . ' lignes depuis le fichier Excel</li>';
                    echo '</ul>';
                    echo '<p><strong>Structure attendue du fichier Excel :</strong></p>';
                    echo '<p>Colonnes : compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif</p>';
                    echo '<a href="?confirm=1" class="btn btn-danger">Confirmer l\'import</a>';
                    echo '</div>';
                } else {
                    // Import confirmé
                    echo '<h2>🔄 Import en cours...</h2>';

                    try {
                        $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
                        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                        echo '<div class="success">✓ Connexion à la base de données réussie</div>';

                        // Vider la table
                        $db->exec('DELETE FROM plan_comptable');
                        echo '<div class="success">✓ Table plan_comptable vidée</div>';

                        // Ignorer les en-têtes
                        $headers = array_shift($rows);
                        echo '<div class="info">Colonnes : ' . implode(' | ', $headers) . '</div>';

                        $inserted = 0;
                        $errors = 0;
                        $errorDetails = [];

                        foreach ($rows as $index => $row) {
                            // Ignorer les lignes vides
                            if (empty(array_filter($row))) {
                                continue;
                            }

                            try {
                                // Structure Excel: compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif
                                // Index:           0       1                2       3               4        5     6   7   8   9   10
                                $compte = isset($row[0]) && $row[0] !== '' ? (int)$row[0] : 0;
                                $intitule_compte = isset($row[1]) ? trim($row[1]) : '';
                                $classe = isset($row[2]) && $row[2] !== '' ? (int)$row[2] : 0;
                                $quatre_chiffres = isset($row[3]) && $row[3] !== '' ? (int)$row[3] : 0;
                                $tableau = isset($row[4]) ? trim($row[4]) : '';
                                $type = isset($row[5]) ? trim($row[5]) : 'Autres';
                                $bd = isset($row[6]) && $row[6] !== '' ? trim($row[6]) : null;
                                $bc = isset($row[7]) && $row[7] !== '' ? trim($row[7]) : null;
                                $rd = isset($row[8]) && $row[8] !== '' ? trim($row[8]) : null;
                                $rc = isset($row[9]) && $row[9] !== '' ? trim($row[9]) : null;
                                $actif = isset($row[10]) ? trim($row[10]) : 'Oui';

                                // Ignorer si compte invalide
                                if ($compte == 0 || empty($intitule_compte)) {
                                    continue;
                                }

                                // Normaliser le type
                                $typeNormalise = $type;
                                $validTypes = ['Client', 'Fournisseur', 'Salarié', 'Banque', 'Caisse',
                                             'Amortis/Provision', 'Résultat-Bilan', 'Charge', 'Produit',
                                             'Résultat-Gestion', 'Immobilisation', 'Capitaux', 'Stock',
                                             'Titre', 'Etat', 'CNPS', 'Autres'];

                                if (!in_array($typeNormalise, $validTypes)) {
                                    $typeNormalise = 'Autres';
                                }

                                // Normaliser actif
                                $actifNormalise = (strtolower($actif) === 'oui' || $actif === '1') ? 'Oui' : 'Non';

                                // Insérer directement avec les données de l'Excel
                                $stmt = $db->prepare("
                                    INSERT INTO plan_comptable
                                    (compte, intitule_compte, classe, quatre_chiffres, tableau, type, bd, bc, rd, rc, actif)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");

                                $stmt->execute([
                                    $compte,
                                    $intitule_compte,
                                    $classe,
                                    $quatre_chiffres,
                                    $tableau,
                                    $typeNormalise,
                                    $bd,
                                    $bc,
                                    $rd,
                                    $rc,
                                    $actifNormalise
                                ]);

                                $inserted++;
                            } catch (PDOException $e) {
                                $errorDetails[] = [
                                    'ligne' => $index + 2,
                                    'compte' => $compte ?? 'N/A',
                                    'erreur' => $e->getMessage()
                                ];
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

                        // Afficher les erreurs détaillées si présentes
                        if (!empty($errorDetails)) {
                            echo '<h3>⚠ Détails des erreurs</h3>';
                            echo '<table>';
                            echo '<tr><th>Ligne</th><th>Compte</th><th>Erreur</th></tr>';
                            foreach (array_slice($errorDetails, 0, 20) as $error) {
                                echo '<tr>';
                                echo '<td>' . $error['ligne'] . '</td>';
                                echo '<td>' . htmlspecialchars($error['compte']) . '</td>';
                                echo '<td style="font-size: 10px;">' . htmlspecialchars($error['erreur']) . '</td>';
                                echo '</tr>';
                            }
                            echo '</table>';
                            if (count($errorDetails) > 20) {
                                echo '<p>... et ' . (count($errorDetails) - 20) . ' autres erreurs</p>';
                            }
                        }

                        // Afficher quelques comptes importés
                        echo '<h3>📋 Comptes importés (10 premiers)</h3>';
                        $stmt = $db->query('SELECT * FROM plan_comptable ORDER BY compte LIMIT 10');
                        $imported = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $stmt->closeCursor();

                        echo '<table>';
                        echo '<tr><th>Compte</th><th>Intitulé</th><th>Classe</th><th>4 chiffres</th><th>Tableau</th><th>Type</th><th>Actif</th></tr>';
                        foreach ($imported as $data) {
                            echo '<tr>';
                            echo '<td><strong>' . $data['compte'] . '</strong></td>';
                            echo '<td>' . htmlspecialchars($data['intitule_compte']) . '</td>';
                            echo '<td>' . $data['classe'] . '</td>';
                            echo '<td>' . $data['quatre_chiffres'] . '</td>';
                            echo '<td>' . htmlspecialchars($data['tableau']) . '</td>';
                            echo '<td>' . htmlspecialchars($data['type']) . '</td>';
                            echo '<td>' . htmlspecialchars($data['actif']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';

                        echo '<a href="pages/settings/plan_comptable.php" class="btn">Voir le Plan Comptable</a>';

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
