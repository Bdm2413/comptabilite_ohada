<?php
require_once '../../config/config.php';

$db = Database::getInstance()->getConnection();

try {
    // Vérifier si les colonnes existent déjà
    $checkSecret = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'totp_secret'");
    $checkEnabled = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'totp_enabled'");

    if ($checkSecret->rowCount() === 0) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN totp_secret VARCHAR(64) NULL DEFAULT NULL");
        echo "✓ Colonne totp_secret ajoutée<br>";
    } else {
        echo "— Colonne totp_secret déjà présente<br>";
    }

    if ($checkEnabled->rowCount() === 0) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
        echo "✓ Colonne totp_enabled ajoutée<br>";
    } else {
        echo "— Colonne totp_enabled déjà présente<br>";
    }

    echo "<br><strong style='color:green'>Migration TOTP terminée avec succès.</strong>";
} catch (Exception $e) {
    echo "<strong style='color:red'>Erreur : " . $e->getMessage() . "</strong>";
}
?>
