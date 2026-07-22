<?php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();

echo "Structure de la table lignes_ecriture:\n\n";

$cols = $db->query('DESCRIBE lignes_ecriture')->fetchAll();

foreach ($cols as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}
