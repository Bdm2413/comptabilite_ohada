<?php
try {
    // Connexion à la base de données accounting_workflow_approval
    $db = new PDO('mysql:host=localhost;dbname=accounting_workflow_approval', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== Structure de plan_comptable (accounting_workflow_approval) ===\n\n";
    $result = $db->query('DESCRIBE plan_comptable');
    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['Field'], 20) . " | " . str_pad($row['Type'], 30) . " | " .
             str_pad($row['Null'], 5) . " | " . str_pad($row['Key'], 5) . " | " .
             str_pad($row['Default'] ?? 'NULL', 10) . " | " . ($row['Extra'] ?? '') . "\n";
    }

    echo "\n=== Exemples de données plan_comptable ===\n\n";
    $result = $db->query('SELECT * FROM plan_comptable LIMIT 5');
    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }

    echo "\n=== Structure de table_correspondance ===\n\n";
    $result = $db->query('DESCRIBE table_correspondance');
    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['Field'], 20) . " | " . str_pad($row['Type'], 30) . " | " .
             str_pad($row['Null'], 5) . " | " . str_pad($row['Key'], 5) . " | " .
             str_pad($row['Default'] ?? 'NULL', 10) . " | " . ($row['Extra'] ?? '') . "\n";
    }

    echo "\n=== Exemples de données table_correspondance ===\n\n";
    $result = $db->query('SELECT * FROM table_correspondance LIMIT 5');
    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }

} catch(Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
