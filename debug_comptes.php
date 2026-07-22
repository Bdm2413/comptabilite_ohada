<?php
/**
 * Fichier de diagnostic pour comprendre pourquoi les listes de comptes sont vides
 * Accédez à ce fichier via: http://localhost/comptabilite_ohada/debug_comptes.php
 */

require_once 'config/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Diagnostic - Listes de Comptes</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        .success { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4CAF50; color: white; }
        pre { background: #f5f5f5; padding: 10px; border-left: 3px solid #4CAF50; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Diagnostic - Comparaison de Comptes</h1>

    <?php
    $db = Database::getInstance()->getConnection();

    // Test 1: Vérifier la table lignes_ecriture
    echo '<div class="section">';
    echo '<h2>Test 1: Table lignes_ecriture</h2>';
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM lignes_ecriture");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_lignes = $result['total'];

        if ($total_lignes > 0) {
            echo "<p class='success'>✅ Table lignes_ecriture contient {$total_lignes} lignes</p>";

            // Afficher un échantillon
            $stmt = $db->query("SELECT * FROM lignes_ecriture LIMIT 5");
            $echantillon = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<p><strong>Échantillon de 5 lignes:</strong></p>";
            echo "<pre>" . print_r($echantillon, true) . "</pre>";
        } else {
            echo "<p class='error'>❌ Table lignes_ecriture est VIDE</p>";
            echo "<p>C'est probablement pour cela que vos listes déroulantes sont vides.</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo '</div>';

    // Test 2: Vérifier la table plan_comptable
    echo '<div class="section">';
    echo '<h2>Test 2: Table plan_comptable</h2>';
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM plan_comptable");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_comptes = $result['total'];

        if ($total_comptes > 0) {
            echo "<p class='success'>✅ Table plan_comptable contient {$total_comptes} comptes</p>";

            // Afficher un échantillon
            $stmt = $db->query("SELECT * FROM plan_comptable LIMIT 10");
            $echantillon = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<p><strong>Échantillon de 10 comptes:</strong></p>";
            echo "<table>";
            echo "<tr><th>Numéro</th><th>Libellé</th><th>Type</th></tr>";
            foreach ($echantillon as $compte) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($compte['numero']) . "</td>";
                echo "<td>" . htmlspecialchars($compte['libelle']) . "</td>";
                echo "<td>" . htmlspecialchars($compte['type'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ Table plan_comptable est VIDE</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo '</div>';

    // Test 3: Tester la requête EXACTE utilisée dans comparaison.php
    echo '<div class="section">';
    echo '<h2>Test 3: Requête de comparaison.php</h2>';
    echo '<p>Voici la requête exacte utilisée dans la page comparaison.php:</p>';
    $sql = "
        SELECT DISTINCT le.compte as numero, pc.libelle
        FROM lignes_ecriture le
        LEFT JOIN plan_comptable pc ON le.compte = pc.numero
        WHERE le.compte IS NOT NULL AND le.compte != ''
        ORDER BY le.compte ASC
    ";
    echo "<pre>" . htmlspecialchars($sql) . "</pre>";

    try {
        $stmt = $db->query($sql);
        $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($comptes) > 0) {
            echo "<p class='success'>✅ La requête retourne " . count($comptes) . " comptes</p>";
            echo "<table>";
            echo "<tr><th>Numéro de compte</th><th>Libellé</th></tr>";
            foreach ($comptes as $compte) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($compte['numero']) . "</td>";
                echo "<td>" . htmlspecialchars($compte['libelle'] ?? 'Non trouvé dans plan_comptable') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ La requête retourne 0 résultat</p>";
            echo "<p><strong>Causes possibles:</strong></p>";
            echo "<ul>";
            echo "<li>La table lignes_ecriture est vide (aucune écriture comptable saisie)</li>";
            echo "<li>Tous les champs 'compte' sont NULL ou vides</li>";
            echo "<li>Problème de jointure avec plan_comptable</li>";
            echo "</ul>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erreur SQL: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo '</div>';

    // Test 4: Vérifier les comptes sans jointure (juste lignes_ecriture)
    echo '<div class="section">';
    echo '<h2>Test 4: Comptes dans lignes_ecriture (sans jointure)</h2>';
    try {
        $stmt = $db->query("
            SELECT DISTINCT compte, COUNT(*) as nb_lignes
            FROM lignes_ecriture
            WHERE compte IS NOT NULL AND compte != ''
            GROUP BY compte
            ORDER BY compte
            LIMIT 20
        ");
        $comptes_brut = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($comptes_brut) > 0) {
            echo "<p class='success'>✅ Comptes trouvés dans lignes_ecriture: " . count($comptes_brut) . "</p>";
            echo "<table>";
            echo "<tr><th>Numéro de compte</th><th>Nombre de lignes</th></tr>";
            foreach ($comptes_brut as $compte) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($compte['compte']) . "</td>";
                echo "<td>" . htmlspecialchars($compte['nb_lignes']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ Aucun compte trouvé dans lignes_ecriture</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo '</div>';

    // Test 5: Alternative - Proposer d'utiliser plan_comptable directement
    echo '<div class="section">';
    echo '<h2>Test 5: Solution alternative - Utiliser plan_comptable</h2>';
    echo '<p>Si lignes_ecriture est vide, on peut afficher TOUS les comptes du plan comptable:</p>';
    try {
        $stmt = $db->query("
            SELECT numero, libelle, type
            FROM plan_comptable
            ORDER BY numero ASC
            LIMIT 20
        ");
        $tous_comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($tous_comptes) > 0) {
            echo "<p class='success'>✅ " . count($tous_comptes) . " comptes disponibles dans plan_comptable</p>";
            echo "<table>";
            echo "<tr><th>Numéro</th><th>Libellé</th><th>Type</th></tr>";
            foreach ($tous_comptes as $compte) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($compte['numero']) . "</td>";
                echo "<td>" . htmlspecialchars($compte['libelle']) . "</td>";
                echo "<td>" . htmlspecialchars($compte['type'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";

            echo "<p class='warning'>💡 <strong>Suggestion:</strong> Modifier la requête pour utiliser plan_comptable au lieu de lignes_ecriture</p>";
        } else {
            echo "<p class='error'>❌ Plan comptable également vide</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo '</div>';

    // Recommandations
    echo '<div class="section">';
    echo '<h2>📋 Recommandations</h2>';
    echo '<ol>';
    echo '<li><strong>Si lignes_ecriture est vide:</strong> La requête actuelle ne peut pas fonctionner. Il faut changer pour utiliser plan_comptable directement.</li>';
    echo '<li><strong>Si plan_comptable est vide:</strong> Il faut d\'abord importer le plan comptable OHADA.</li>';
    echo '<li><strong>Si les deux tables ont des données mais le JOIN échoue:</strong> Vérifier que les numéros de compte correspondent exactement (pas d\'espaces, même format).</li>';
    echo '</ol>';
    echo '</div>';
    ?>

    <div class="section">
        <h2>🔧 Action à faire</h2>
        <p>Une fois que vous aurez identifié le problème grâce à ce diagnostic, je pourrai corriger le code de comparaison.php</p>
        <p><strong>Copiez les résultats ci-dessus et partagez-les moi pour que je puisse vous proposer la solution appropriée.</strong></p>
    </div>
</body>
</html>
