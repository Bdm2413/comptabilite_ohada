<?php
/**
 * Migration: Ajout du champ régime_fiscal à la table plan_tiers
 * Date: 2025-12-14
 */

try {
    $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Début de la migration...\n";

    // Vérifier si la colonne existe déjà
    $stmt = $db->query("SHOW COLUMNS FROM plan_tiers LIKE 'regime_fiscal'");
    if ($stmt->rowCount() > 0) {
        echo "La colonne 'regime_fiscal' existe déjà.\n";
        exit(0);
    }

    // Ajouter la colonne regime_fiscal
    $db->exec("ALTER TABLE plan_tiers ADD COLUMN regime_fiscal VARCHAR(100) NULL AFTER type");

    echo "✓ Colonne 'regime_fiscal' ajoutée avec succès à la table plan_tiers\n";
    echo "Migration terminée avec succès!\n";

} catch (PDOException $e) {
    echo "Erreur lors de la migration: " . $e->getMessage() . "\n";
    exit(1);
}
