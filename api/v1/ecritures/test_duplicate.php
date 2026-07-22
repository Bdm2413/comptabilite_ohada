<?php
/**
 * Test de la duplication d'écriture - diagnostic
 */
require_once '../../../config/config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Test de diagnostic - Duplication d'écriture</h2>";
echo "<style>body{font-family:Arial;padding:20px;} .ok{color:green;} .error{color:red;} pre{background:#f5f5f5;padding:10px;}</style>";

try {
    $db = Database::getInstance()->getConnection();

    // 1. Vérifier la structure de la table ecritures
    echo "<h3>1. Structure de la table 'ecritures'</h3>";
    $stmt = $db->query("DESCRIBE ecritures");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<pre>" . implode("\n", $columns) . "</pre>";

    // 2. Colonnes requises pour la duplication
    $required = ['numero_ecriture', 'date_ecriture', 'mois', 'annee', 'journal', 'libelle',
                 'id_tiers', 'compte_tiers', 'num_piece', 'reference_piece', 'num_facture',
                 'type_document', 'montant_total', 'statut', 'createur', 'date_creation'];

    echo "<h3>2. Vérification des colonnes requises</h3>";
    $missing = [];
    foreach ($required as $col) {
        if (in_array($col, $columns)) {
            echo "<div class='ok'>✓ {$col}</div>";
        } else {
            echo "<div class='error'>✗ {$col} - MANQUANT</div>";
            $missing[] = $col;
        }
    }

    if (!empty($missing)) {
        echo "<h3>⚠️ Colonnes manquantes dans la table ecritures :</h3>";
        echo "<pre>" . implode(", ", $missing) . "</pre>";
        echo "<p><strong>Solution :</strong> Ces colonnes n'existent pas dans votre table. Je vais adapter l'API.</p>";
    }

    // 3. Tester une récupération d'écriture
    echo "<h3>3. Test de récupération d'une écriture</h3>";
    $stmt = $db->query("SELECT * FROM ecritures ORDER BY id DESC LIMIT 1");
    $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ecriture) {
        echo "<div class='ok'>✓ Écriture trouvée (ID: {$ecriture['id']})</div>";
        echo "<pre>";
        foreach ($ecriture as $key => $value) {
            echo "$key: " . ($value ?? 'NULL') . "\n";
        }
        echo "</pre>";
    } else {
        echo "<div class='error'>✗ Aucune écriture trouvée</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>Erreur : " . $e->getMessage() . "</div>";
}
?>
