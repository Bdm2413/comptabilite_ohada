<?php
require_once '../../config/config.php';

// Enregistrer la déconnexion
if (isLoggedIn()) {
    logActivity('Déconnexion', 'utilisateurs', $_SESSION['user_id']);
}

// Détruire la session
session_unset();
session_destroy();

// Rediriger vers la page de connexion
header('Location: login.php');
exit();
