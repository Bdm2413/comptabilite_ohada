<?php
require_once '../../config/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) throw new Exception('Aucune société sélectionnée');

    // Récupérer les données du POST
    $date_billetage = $_POST['date_billetage'] ?? date('Y-m-d');
    $compte_caisse = $_POST['compte_caisse'] ?? '';

    // Billets
    $bill_10000 = (int)($_POST['bill_10000'] ?? 0);
    $bill_5000 = (int)($_POST['bill_5000'] ?? 0);
    $bill_2000 = (int)($_POST['bill_2000'] ?? 0);
    $bill_1000 = (int)($_POST['bill_1000'] ?? 0);
    $bill_500 = (int)($_POST['bill_500'] ?? 0);

    // Pièces
    $coin_500 = (int)($_POST['coin_500'] ?? 0);
    $coin_250 = (int)($_POST['coin_250'] ?? 0);
    $coin_200 = (int)($_POST['coin_200'] ?? 0);
    $coin_100 = (int)($_POST['coin_100'] ?? 0);
    $coin_50 = (int)($_POST['coin_50'] ?? 0);
    $coin_25 = (int)($_POST['coin_25'] ?? 0);
    $coin_10 = (int)($_POST['coin_10'] ?? 0);
    $coin_5 = (int)($_POST['coin_5'] ?? 0);

    // Totaux
    $total_billets = (float)($_POST['total_billets'] ?? 0);
    $total_pieces = (float)($_POST['total_pieces'] ?? 0);
    $solde_physique = (float)($_POST['solde_physique'] ?? 0);
    $solde_comptable = (float)($_POST['solde_comptable'] ?? 0);
    $ecart = (float)($_POST['ecart'] ?? 0);

    if (empty($compte_caisse)) {
        throw new Exception("Compte caisse manquant");
    }

    // Vérifier si un billetage existe déjà pour cette date et ce compte
    $stmt = $db->prepare("
        SELECT id, createur, date_creation
        FROM billetages
        WHERE date_billetage = ? AND compte_caisse = ? AND societe_id = ?
    ");
    $stmt->execute([$date_billetage, $compte_caisse, $societe_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        throw new Exception(
            "Un billetage existe déjà pour le " . date('d/m/Y', strtotime($date_billetage)) .
            " (enregistré par " . $existing['createur'] .
            " le " . date('d/m/Y à H:i', strtotime($existing['date_creation'])) . ")"
        );
    }

    // Enregistrer le billetage
    $stmt = $db->prepare("
        INSERT INTO billetages (
            date_billetage, compte_caisse,
            bill_10000, bill_5000, bill_2000, bill_1000, bill_500,
            coin_500, coin_250, coin_200, coin_100, coin_50, coin_25, coin_10, coin_5,
            total_billets, total_pieces, solde_physique, solde_comptable, ecart,
            createur, societe_id
        ) VALUES (
            ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?
        )
    ");

    $stmt->execute([
        $date_billetage, $compte_caisse,
        $bill_10000, $bill_5000, $bill_2000, $bill_1000, $bill_500,
        $coin_500, $coin_250, $coin_200, $coin_100, $coin_50, $coin_25, $coin_10, $coin_5,
        $total_billets, $total_pieces, $solde_physique, $solde_comptable, $ecart,
        $_SESSION['user_name'], $societe_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Billetage enregistré avec succès',
        'id' => $db->lastInsertId()
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
