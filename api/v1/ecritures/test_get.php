<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test API GET</h2>";
echo "<p>ID à tester : " . ($_GET['id'] ?? 'non fourni') . "</p>";

require_once '../../../config/config.php';
require_once '../../../includes/auth_check.php';

if (!isLoggedIn()) {
    echo "<p style='color: red;'>Non connecté</p>";
    exit;
}

$id = $_GET['id'] ?? null;
echo "<p>ID reçu : $id</p>";

if (!$id) {
    echo "<p style='color: red;'>ID manquant</p>";
    exit;
}

$db = Database::getInstance()->getConnection();
echo "<p style='color: green;'>✓ Connexion DB OK</p>";

// Récupérer l'écriture
$stmt = $db->prepare("SELECT * FROM ecritures WHERE id = ?");
$stmt->execute([$id]);
$ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ecriture) {
    echo "<p style='color: red;'>Écriture introuvable</p>";
} else {
    echo "<p style='color: green;'>✓ Écriture trouvée</p>";
    echo "<pre>" . print_r($ecriture, true) . "</pre>";
}

// Récupérer les lignes
$stmt = $db->prepare("SELECT * FROM lignes_ecriture WHERE id_ecriture = ? ORDER BY id");
$stmt->execute([$id]);
$lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p style='color: green;'>✓ " . count($lignes) . " ligne(s) trouvée(s)</p>";
echo "<pre>" . print_r($lignes, true) . "</pre>";

echo "<hr>";
echo "<h3>Test JSON :</h3>";
$data = [
    'success' => true,
    'ecriture' => $ecriture,
    'lignes' => $lignes
];
echo "<pre>" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</pre>";
?>
