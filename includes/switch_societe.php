<?php
/**
 * Script pour changer de société active
 */
require_once '../config/config.php';
requireLogin();

$societe_id = $_GET['societe_id'] ?? null;

if (!$societe_id) {
    $_SESSION['error'] = 'Identifiant de société manquant';
    header('Location: ' . APP_URL . '/pages/dashboard/index.php');
    exit();
}

// Vérifier que l'utilisateur a accès à cette société
$user_id = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("
    SELECT s.*, us.role, us.par_defaut
    FROM societes s
    INNER JOIN utilisateurs_societes us ON s.id = us.societe_id
    WHERE s.id = :societe_id AND us.id_utilisateur = :user_id AND s.actif = 1
");
$stmt->execute([
    'societe_id' => $societe_id,
    'user_id' => $user_id
]);

$societe = $stmt->fetch();

if (!$societe) {
    $_SESSION['error'] = 'Vous n\'avez pas accès à cette société';
    header('Location: ' . APP_URL . '/pages/dashboard/index.php');
    exit();
}

// Changer la société active dans la session
$_SESSION['societe_id'] = $societe_id;

// Log de l'activité
logActivity('Changement de société', 'societes', $societe_id, $societe['code_societe']);

// Redirection
$redirect = $_GET['redirect'] ?? APP_URL . '/pages/dashboard/index.php';
header('Location: ' . $redirect);
exit();
