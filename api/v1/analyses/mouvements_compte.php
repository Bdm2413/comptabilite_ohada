<?php
/**
 * API pour récupérer les mouvements d'un compte sur une période
 * Utilisé pour la heatmap
 */

require_once '../../../config/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Non authentifié'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $compte = $_GET['compte'] ?? null;
    $date_debut = $_GET['date_debut'] ?? null;
    $date_fin = $_GET['date_fin'] ?? null;

    if (!$compte || !$date_debut || !$date_fin) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Paramètres manquants (compte, date_debut, date_fin requis)'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Aucune société sélectionnée'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Récupérer tous les mouvements du compte sur la période
    $sql = "
        SELECT
            le.id,
            le.id_ecriture,
            le.compte,
            le.date_ligne,
            le.libelle,
            le.debit,
            le.credit,
            (le.debit - le.credit) as mouvement,
            le.numero_facture,
            e.id as ecriture_id,
            e.numero_ecriture,
            e.journal,
            e.libelle as libelle_ecriture,
            e.num_piece,
            e.reference_piece
        FROM lignes_ecriture le
        JOIN ecritures e ON le.id_ecriture = e.id
        WHERE e.statut = 'Validé'
          AND e.societe_id = ?
          AND le.compte LIKE ?
          AND le.date_ligne BETWEEN ? AND ?
        ORDER BY le.date_ligne ASC, le.id ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$societe_id, $compte . '%', $date_debut, $date_fin]);
    $mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grouper les mouvements par date
    $mouvements_par_date = [];
    foreach ($mouvements as $mvt) {
        $date = $mvt['date_ligne'];

        if (!isset($mouvements_par_date[$date])) {
            $mouvements_par_date[$date] = [
                'date' => $date,
                'total_debit' => 0,
                'total_credit' => 0,
                'mouvement_net' => 0,
                'nb_operations' => 0,
                'operations' => []
            ];
        }

        $mouvements_par_date[$date]['total_debit'] += floatval($mvt['debit']);
        $mouvements_par_date[$date]['total_credit'] += floatval($mvt['credit']);
        $mouvements_par_date[$date]['mouvement_net'] += floatval($mvt['mouvement']);
        $mouvements_par_date[$date]['nb_operations']++;

        $mouvements_par_date[$date]['operations'][] = [
            'id' => $mvt['id'],
            'id_ecriture' => $mvt['ecriture_id'],
            'compte' => $mvt['compte'],
            'libelle' => $mvt['libelle'] ?: $mvt['libelle_ecriture'],
            'debit' => floatval($mvt['debit']),
            'credit' => floatval($mvt['credit']),
            'numero_ecriture' => $mvt['numero_ecriture'],
            'journal' => $mvt['journal'],
            'num_piece' => $mvt['num_piece'],
            'reference_piece' => $mvt['reference_piece'],
            'numero_facture' => $mvt['numero_facture']
        ];
    }

    // Convertir en tableau indexé
    $resultats = array_values($mouvements_par_date);

    echo json_encode([
        'success' => true,
        'compte' => $compte,
        'periode' => [
            'debut' => $date_debut,
            'fin' => $date_fin
        ],
        'mouvements' => $resultats,
        'total_jours_activite' => count($resultats)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
