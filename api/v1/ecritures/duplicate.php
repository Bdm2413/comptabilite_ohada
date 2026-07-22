<?php
/**
 * API pour dupliquer une écriture comptable
 */

// Désactiver l'affichage des erreurs en HTML
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../../config/config.php';
require_once '../../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Vérifier que l'utilisateur est connecté
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Non authentifié'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Vérifier la méthode
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Méthode non autorisée'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Récupérer l'ID de l'écriture à dupliquer
    $data = json_decode(file_get_contents('php://input'), true);
    $idEcriture = $data['id'] ?? null;

    if (!$idEcriture) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'ID manquant'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    // 1. Récupérer l'écriture originale
    $stmt = $db->prepare("SELECT * FROM ecritures WHERE id = ?");
    $stmt->execute([$idEcriture]);
    $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ecriture) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Écriture introuvable'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Générer un nouveau numéro d'écriture
    $year = date('Y');
    $month = date('m');
    $pattern = "ECR-$year-$month-%";

    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(numero_ecriture, '-', -1) AS UNSIGNED)) as max_num
        FROM ecritures
        WHERE YEAR(date_ecriture) = ?
          AND MONTH(date_ecriture) = ?
          AND numero_ecriture LIKE ?
    ");
    $stmt->execute([$year, $month, $pattern]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextNum = ($result['max_num'] ?? 0) + 1;
    $nouveauNumero = sprintf("ECR-%s-%s-%04d", $year, $month, $nextNum);

    // 3. Créer la nouvelle écriture - seulement avec les colonnes qui existent
    $colonnes = ['numero_ecriture', 'date_ecriture', 'mois', 'annee', 'journal', 'libelle', 'montant_total', 'statut'];
    $valeurs = [
        $nouveauNumero,
        date('Y-m-d'),
        date('m'),
        date('Y'),
        $ecriture['journal'],
        'Copie - ' . $ecriture['libelle'],
        $ecriture['montant_total'] ?? 0,
        'Brouillon'
    ];

    // Ajouter les colonnes optionnelles si elles existent dans l'écriture originale
    $optionnelles = [
        'id_tiers' => $ecriture['id_tiers'] ?? null,
        'compte_tiers' => $ecriture['compte_tiers'] ?? null,
        'num_piece' => null,
        'reference_piece' => null,
        'num_facture' => null,
        'type_document' => $ecriture['type_document'] ?? null,
        'createur' => $_SESSION['user_name'] ?? 'admin'
    ];

    foreach ($optionnelles as $col => $val) {
        if (array_key_exists($col, $ecriture)) {
            $colonnes[] = $col;
            $valeurs[] = $val;
        }
    }

    // Construction de la requête dynamique
    $placeholders = implode(', ', array_fill(0, count($colonnes), '?'));
    $colonnesStr = implode(', ', $colonnes);

    $sql = "INSERT INTO ecritures ($colonnesStr) VALUES ($placeholders)";
    $stmt = $db->prepare($sql);
    $stmt->execute($valeurs);

    $newEcritureId = $db->lastInsertId();

    // 4. Récupérer toutes les lignes de l'écriture originale
    $stmt = $db->prepare("SELECT * FROM lignes_ecriture WHERE id_ecriture = ? ORDER BY id");
    $stmt->execute([$idEcriture]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Dupliquer toutes les lignes
    $colonnesLigne = ['id_ecriture', 'compte', 'libelle', 'debit', 'credit', 'date_ligne'];
    $valeursLigne = [];

    foreach ($lignes as $ligne) {
        $valeursLigne = [
            $newEcritureId,
            $ligne['compte'],
            $ligne['libelle'],
            $ligne['debit'] ?? 0,
            $ligne['credit'] ?? 0,
            date('Y-m-d')
        ];

        // Colonnes optionnelles pour les lignes
        $optionnellesLigne = [
            'compte_tiers' => $ligne['compte_tiers'] ?? null,
            'numero_facture' => $ligne['numero_facture'] ?? null,
            'createur' => $_SESSION['user_name'] ?? 'admin'
        ];

        $colonnesLigneActuelles = $colonnesLigne;
        $valeursLigneActuelles = $valeursLigne;

        foreach ($optionnellesLigne as $col => $val) {
            if (array_key_exists($col, $ligne)) {
                $colonnesLigneActuelles[] = $col;
                $valeursLigneActuelles[] = $val;
            }
        }

        $placeholdersLigne = implode(', ', array_fill(0, count($colonnesLigneActuelles), '?'));
        $colonnesLigneStr = implode(', ', $colonnesLigneActuelles);

        $sqlLigne = "INSERT INTO lignes_ecriture ($colonnesLigneStr) VALUES ($placeholdersLigne)";
        $stmtLigne = $db->prepare($sqlLigne);
        $stmtLigne->execute($valeursLigneActuelles);
    }

    $db->commit();

    // Succès
    echo json_encode([
        'success' => true,
        'message' => 'Écriture dupliquée avec succès',
        'data' => [
            'id' => $newEcritureId,
            'numero_ecriture' => $nouveauNumero,
            'original_id' => $idEcriture,
            'nb_lignes' => count($lignes)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
