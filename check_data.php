<?php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();

echo "<h3>Vérification des données</h3>";

// Code journaux
$count = $db->query('SELECT COUNT(*) FROM code_journal')->fetchColumn();
echo "<p>Code journaux: $count</p>";

if ($count > 0) {
    $journaux = $db->query('SELECT * FROM code_journal LIMIT 5')->fetchAll();
    echo "<pre>";
    print_r($journaux);
    echo "</pre>";
}

// Tiers
$count2 = $db->query('SELECT COUNT(*) FROM plan_tiers')->fetchColumn();
echo "<p>Tiers: $count2</p>";

if ($count2 > 0) {
    $tiers = $db->query('SELECT * FROM plan_tiers LIMIT 5')->fetchAll();
    echo "<pre>";
    print_r($tiers);
    echo "</pre>";
}

// Comptes
$count3 = $db->query('SELECT COUNT(*) FROM plan_comptable')->fetchColumn();
echo "<p>Comptes: $count3</p>";

if ($count3 > 0) {
    $comptes = $db->query('SELECT * FROM plan_comptable LIMIT 5')->fetchAll();
    echo "<pre>";
    print_r($comptes);
    echo "</pre>";
}
?>
