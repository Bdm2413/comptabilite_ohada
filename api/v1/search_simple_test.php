<?php
// Test ultra-simple pour identifier l'erreur
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test de l'API de recherche</h1>";
echo "<h2>1. Test du require config.php</h2>";

try {
    require_once '../../config/config.php';
    echo "✅ Config chargé<br>";
} catch (Exception $e) {
    echo "❌ Erreur config: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>2. Test de la connexion DB</h2>";
try {
    $db = Database::getInstance()->getConnection();
    echo "✅ DB connectée<br>";
} catch (Exception $e) {
    echo "❌ Erreur DB: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>3. Test d'une requête simple</h2>";
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures");
    $count = $stmt->fetch()['total'];
    echo "✅ Requête OK - $count écritures trouvées<br>";
} catch (Exception $e) {
    echo "❌ Erreur requête: " . $e->getMessage() . "<br>";
}

echo "<h2>4. Test de recherche dans plan_tiers</h2>";
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM plan_tiers WHERE actif = 1");
    $count = $stmt->fetch()['total'];
    echo "✅ Table plan_tiers OK - $count tiers actifs<br>";
} catch (Exception $e) {
    echo "❌ Erreur plan_tiers: " . $e->getMessage() . "<br>";
}

echo "<h2>5. Test de recherche LIKE</h2>";
try {
    $searchTerm = '%client%';
    $stmt = $db->prepare("SELECT nom FROM plan_tiers WHERE nom LIKE :search LIMIT 3");
    $stmt->execute([':search' => $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Recherche OK - " . count($results) . " résultats<br>";
    foreach ($results as $row) {
        echo "  - " . htmlspecialchars($row['nom']) . "<br>";
    }
} catch (Exception $e) {
    echo "❌ Erreur recherche: " . $e->getMessage() . "<br>";
}

echo "<h2>✅ Tous les tests réussis !</h2>";
echo "<p>Si vous voyez ce message, l'API devrait fonctionner.</p>";
echo "<p><a href='search.php?q=client&module=all'>Tester l'API complète</a></p>";
?>
