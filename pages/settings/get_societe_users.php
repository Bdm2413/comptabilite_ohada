<?php
require_once '../../config/config.php';
requireLogin();
if (!isAdmin()) {
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

header('Content-Type: application/json');

$societe_id = $_GET['societe_id'] ?? null;

if (!$societe_id) {
    echo json_encode(['error' => 'ID société manquant']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Récupérer les utilisateurs actuels de la société
$stmt = $db->prepare("
    SELECT u.id_utilisateur, u.nom_utilisateur, u.email, us.role, us.par_defaut
    FROM utilisateurs u
    INNER JOIN utilisateurs_societes us ON u.id_utilisateur = us.id_utilisateur
    WHERE us.societe_id = :societe_id
    ORDER BY u.nom_utilisateur
");
$stmt->execute(['societe_id' => $societe_id]);
$current_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les utilisateurs disponibles (qui ne sont pas encore dans cette société)
$stmt = $db->prepare("
    SELECT u.id_utilisateur, u.nom_utilisateur, u.email
    FROM utilisateurs u
    WHERE u.id_utilisateur NOT IN (
        SELECT id_utilisateur FROM utilisateurs_societes WHERE societe_id = :societe_id
    )
    ORDER BY u.nom_utilisateur
");
$stmt->execute(['societe_id' => $societe_id]);
$available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'current_users' => $current_users,
    'available_users' => $available_users
]);
