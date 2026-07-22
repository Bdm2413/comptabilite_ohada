<?php
/**
 * Script pour nettoyer la session et redémarrer l'application
 */

// Démarrer la session
session_start();

// Afficher les variables de session actuelles
echo "<h2>Variables de session avant nettoyage :</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Détruire toutes les variables de session
session_unset();

// Détruire la session
session_destroy();

echo "<h2>Session nettoyée avec succès!</h2>";
echo "<p><a href='index.php'>Retourner à l'application</a></p>";
?>
