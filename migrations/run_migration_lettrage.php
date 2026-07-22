<?php
/**
 * Script d'exécution de la migration : Ajout des champs lettrage
 * Exécute les modifications de structure de la base de données
 */

require_once '../config/config.php';

// Vérifier que l'utilisateur est connecté et est admin
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$db = Database::getInstance()->getConnection();

echo "<h2>Migration : Ajout des champs lettrage et date_reception_transmission</h2>";
echo "<pre>";

try {
    // Vérifier si les colonnes existent déjà
    $stmt = $db->query("SHOW COLUMNS FROM ecritures LIKE 'lettrage'");
    if ($stmt->rowCount() > 0) {
        echo "⚠️  La colonne 'lettrage' existe déjà.\n";
    } else {
        echo "➡️  Ajout de la colonne 'lettrage'...\n";
        $db->exec("ALTER TABLE ecritures ADD COLUMN lettrage VARCHAR(10) NULL COMMENT 'Code de lettrage pour rapprocher les écritures'");
        echo "✅ Colonne 'lettrage' ajoutée avec succès.\n";
    }

    $stmt = $db->query("SHOW COLUMNS FROM ecritures LIKE 'date_reception_transmission'");
    if ($stmt->rowCount() > 0) {
        echo "⚠️  La colonne 'date_reception_transmission' existe déjà.\n";
    } else {
        echo "➡️  Ajout de la colonne 'date_reception_transmission'...\n";
        $db->exec("ALTER TABLE ecritures ADD COLUMN date_reception_transmission DATE NULL COMMENT 'Date transmission facture (client) ou réception facture (fournisseur)'");
        echo "✅ Colonne 'date_reception_transmission' ajoutée avec succès.\n";
    }

    $stmt = $db->query("SHOW COLUMNS FROM ecritures LIKE 'statut_lettrage'");
    if ($stmt->rowCount() > 0) {
        echo "⚠️  La colonne 'statut_lettrage' existe déjà.\n";
    } else {
        echo "➡️  Ajout de la colonne 'statut_lettrage'...\n";
        $db->exec("ALTER TABLE ecritures ADD COLUMN statut_lettrage ENUM('Non lettré', 'Partiellement lettré', 'Lettré') DEFAULT 'Non lettré' COMMENT 'Statut du lettrage'");
        echo "✅ Colonne 'statut_lettrage' ajoutée avec succès.\n";
    }

    // Créer les index s'ils n'existent pas
    echo "\n➡️  Création des index...\n";

    try {
        $db->exec("CREATE INDEX idx_lettrage ON ecritures(lettrage)");
        echo "✅ Index 'idx_lettrage' créé.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⚠️  Index 'idx_lettrage' existe déjà.\n";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("CREATE INDEX idx_date_reception_transmission ON ecritures(date_reception_transmission)");
        echo "✅ Index 'idx_date_reception_transmission' créé.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⚠️  Index 'idx_date_reception_transmission' existe déjà.\n";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("CREATE INDEX idx_statut_lettrage ON ecritures(statut_lettrage)");
        echo "✅ Index 'idx_statut_lettrage' créé.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⚠️  Index 'idx_statut_lettrage' existe déjà.\n";
        } else {
            throw $e;
        }
    }

    echo "\n✅ Migration terminée avec succès !\n";
    echo "\n📋 Structure de la table 'ecritures' :\n";

    $stmt = $db->query("DESCRIBE ecritures");
    $columns = $stmt->fetchAll();

    foreach ($columns as $column) {
        echo "  - {$column['Field']} ({$column['Type']})\n";
    }

} catch (PDOException $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

echo "</pre>";
echo "<br><a href='../pages/dashboard/index.php'>← Retour au tableau de bord</a>";
?>
