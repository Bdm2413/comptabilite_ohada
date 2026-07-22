<?php
/**
 * API pour comparer deux comptes comptables
 * Permet l'analyse par année, trimestre ou mois
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

    $compte1 = $_GET['compte1'] ?? null;
    $compte2 = $_GET['compte2'] ?? null;
    $date_debut = $_GET['date_debut'] ?? null;
    $date_fin = $_GET['date_fin'] ?? null;
    $periode = $_GET['periode'] ?? 'mois'; // 'annee', 'trimestre', 'mois'

    if (!$compte1 || !$compte2 || !$date_debut || !$date_fin) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Paramètres manquants (compte1, compte2, date_debut, date_fin requis)'
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

    // Fonction pour formater la période selon le type
    function formatPeriode($date, $type) {
        $timestamp = strtotime($date);
        switch ($type) {
            case 'annee':
                return date('Y', $timestamp);
            case 'trimestre':
                $year = date('Y', $timestamp);
                $month = (int)date('m', $timestamp);
                $trimestre = ceil($month / 3);
                return "{$year}-T{$trimestre}";
            case 'mois':
            default:
                return date('Y-m', $timestamp);
        }
    }

    // Récupérer les mouvements pour le compte 1
    $sql = "
        SELECT
            le.date_ligne,
            SUM(le.debit) as total_debit,
            SUM(le.credit) as total_credit,
            SUM(le.debit - le.credit) as solde
        FROM lignes_ecriture le
        JOIN ecritures e ON le.id_ecriture = e.id
        WHERE e.statut = 'Validé'
          AND e.societe_id = ?
          AND le.compte LIKE ?
          AND le.date_ligne BETWEEN ? AND ?
        GROUP BY le.date_ligne
        ORDER BY le.date_ligne ASC
    ";

    $stmt1 = $db->prepare($sql);
    $stmt1->execute([$societe_id, $compte1 . '%', $date_debut, $date_fin]);
    $mouvements1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer les mouvements pour le compte 2
    $stmt2 = $db->prepare($sql);
    $stmt2->execute([$societe_id, $compte2 . '%', $date_debut, $date_fin]);
    $mouvements2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Grouper par période
    $donnees_compte1 = [];
    $donnees_compte2 = [];

    foreach ($mouvements1 as $mvt) {
        $periode_key = formatPeriode($mvt['date_ligne'], $periode);
        if (!isset($donnees_compte1[$periode_key])) {
            $donnees_compte1[$periode_key] = [
                'periode' => $periode_key,
                'debit' => 0,
                'credit' => 0,
                'solde' => 0
            ];
        }
        $donnees_compte1[$periode_key]['debit'] += floatval($mvt['total_debit']);
        $donnees_compte1[$periode_key]['credit'] += floatval($mvt['total_credit']);
        $donnees_compte1[$periode_key]['solde'] += floatval($mvt['solde']);
    }

    foreach ($mouvements2 as $mvt) {
        $periode_key = formatPeriode($mvt['date_ligne'], $periode);
        if (!isset($donnees_compte2[$periode_key])) {
            $donnees_compte2[$periode_key] = [
                'periode' => $periode_key,
                'debit' => 0,
                'credit' => 0,
                'solde' => 0
            ];
        }
        $donnees_compte2[$periode_key]['debit'] += floatval($mvt['total_debit']);
        $donnees_compte2[$periode_key]['credit'] += floatval($mvt['total_credit']);
        $donnees_compte2[$periode_key]['solde'] += floatval($mvt['solde']);
    }

    // Récupérer les libellés des comptes
    $stmtLibelle = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE compte = ? AND societe_id = ? LIMIT 1");

    $stmtLibelle->execute([$compte1, $societe_id]);
    $info_compte1 = $stmtLibelle->fetch(PDO::FETCH_ASSOC);

    $stmtLibelle->execute([$compte2, $societe_id]);
    $info_compte2 = $stmtLibelle->fetch(PDO::FETCH_ASSOC);

    // Calculer les totaux
    $total_compte1 = array_sum(array_column($donnees_compte1, 'solde'));
    $total_compte2 = array_sum(array_column($donnees_compte2, 'solde'));
    $difference = $total_compte1 - $total_compte2;
    $pourcentage = $total_compte2 != 0 ? (($total_compte1 - $total_compte2) / abs($total_compte2)) * 100 : 0;

    // Fusionner les périodes pour avoir toutes les clés
    $toutes_periodes = array_unique(array_merge(
        array_keys($donnees_compte1),
        array_keys($donnees_compte2)
    ));
    sort($toutes_periodes);

    // Préparer les données pour le graphique
    $series_data = [];
    foreach ($toutes_periodes as $p) {
        $series_data[] = [
            'periode' => $p,
            'compte1' => isset($donnees_compte1[$p]) ? $donnees_compte1[$p]['solde'] : 0,
            'compte2' => isset($donnees_compte2[$p]) ? $donnees_compte2[$p]['solde'] : 0,
        ];
    }

    echo json_encode([
        'success' => true,
        'compte1' => [
            'numero' => $compte1,
            'libelle' => $info_compte1['intitule_compte'] ?? 'Compte inconnu',
            'total' => $total_compte1,
            'donnees' => array_values($donnees_compte1)
        ],
        'compte2' => [
            'numero' => $compte2,
            'libelle' => $info_compte2['intitule_compte'] ?? 'Compte inconnu',
            'total' => $total_compte2,
            'donnees' => array_values($donnees_compte2)
        ],
        'comparaison' => [
            'difference' => $difference,
            'pourcentage' => round($pourcentage, 2),
            'periodes' => $toutes_periodes,
            'series' => $series_data
        ],
        'parametres' => [
            'date_debut' => $date_debut,
            'date_fin' => $date_fin,
            'type_periode' => $periode
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
