<?php
require_once '../config/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    // Récupérer les paramètres
    $date_debut = $_GET['date_debut'] ?? date('Y-01-01');
    $date_fin = $_GET['date_fin'] ?? date('Y-m-d');

    // Filtre optionnel sur le tableau (Bilan ou Resultat)
    $tableau = $_GET['tableau'] ?? null;
    $tableaux_autorises = ['Bilan', 'Resultat'];
    if (!in_array($tableau, $tableaux_autorises, true)) {
        $tableau = null; // Valeur invalide : pas de filtre
    }

    // Requête pour calculer la balance générale
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) {
        echo json_encode(['success' => false, 'error' => 'Aucune société sélectionnée']);
        exit;
    }

    // Clause WHERE de base
    $where_extra = '';
    $params_extra = [];
    if ($tableau !== null) {
        $where_extra = ' AND pc.tableau = ?';
        $params_extra[] = $tableau;
    }

    $sql = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.classe,
            pc.tableau,
            pc.bd,
            pc.bc,
            pc.rd,
            pc.rc,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit,
            (COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0)) as solde
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut = 'Validé' AND e.societe_id = pc.societe_id
        WHERE pc.actif = 'Oui' AND pc.societe_id = ?{$where_extra}
        GROUP BY pc.compte, pc.intitule_compte, pc.classe, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc
        HAVING ABS(total_debit) > 0.01 OR ABS(total_credit) > 0.01
        ORDER BY pc.compte
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge(
        [
            $date_debut, $date_fin,
            $date_debut, $date_fin,
            $date_debut, $date_fin,
            $date_debut, $date_fin,
            $societe_id,
        ],
        $params_extra
    ));

    $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculer les totaux
    $total_debit = 0;
    $total_credit = 0;

    foreach ($comptes as $compte) {
        $total_debit += $compte['total_debit'];
        $total_credit += $compte['total_credit'];
    }

    echo json_encode([
        'success' => true,
        'comptes' => $comptes,
        'totaux' => [
            'debit' => $total_debit,
            'credit' => $total_credit
        ],
        'periode' => [
            'debut' => $date_debut,
            'fin' => $date_fin
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
