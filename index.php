<?php
require_once 'config/config.php';

// Si l'utilisateur est déjà connecté, rediriger vers le dashboard
if (isLoggedIn()) {
    header('Location: pages/dashboard/index.php');
} else {
    header('Location: pages/auth/login.php');
}
exit();
