<?php
// API de recherche globale - Version finale fonctionnelle
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../config/config.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $db = Database::getInstance()->getConnection();

    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $module = isset($_GET['module']) ? $_GET['module'] : 'all';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    if (empty($query)) {
        echo json_encode(['success' => false, 'error' => 'Paramètre q manquant']);
        exit();
    }

    $searchTerm = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

    $results = [
        'success' => true,
        'query' => $query,
        'total' => 0,
        'results' => []
    ];

    // 1. RECHERCHE DANS LES ÉCRITURES
    if ($module === 'all' || $module === 'ecritures') {
        $stmt = $db->prepare("
            SELECT
                'ecriture' as type,
                id,
                numero_ecriture,
                num_piece,
                libelle,
                date_ecriture,
                statut,
                montant_total,
                CONCAT(COALESCE(num_piece, numero_ecriture), ' - ', libelle) as display_text,
                'pages/ecritures/modifier_ecriture.php?id=' as url
            FROM ecritures
            WHERE num_piece LIKE :search1
               OR libelle LIKE :search2
               OR reference_piece LIKE :search3
               OR numero_ecriture LIKE :search4
            ORDER BY date_ecriture DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search3', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search4', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $ecritures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['results'] = array_merge($results['results'], $ecritures);
    }

    // 2. RECHERCHE DANS LE PLAN COMPTABLE
    if ($module === 'all' || $module === 'comptes') {
        $stmt = $db->prepare("
            SELECT
                'compte' as type,
                id,
                compte,
                intitule_compte,
                classe,
                type as compte_type,
                CONCAT(compte, ' - ', intitule_compte) as display_text,
                'pages/plan_comptable/modifier_compte.php?id=' as url
            FROM plan_comptable
            WHERE compte LIKE :search1 OR intitule_compte LIKE :search2
            ORDER BY compte ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['results'] = array_merge($results['results'], $comptes);
    }

    // 3. RECHERCHE DANS LES TIERS (plan_tiers)
    if ($module === 'all' || $module === 'tiers') {
        $stmt = $db->prepare("
            SELECT
                'tiers' as type,
                id,
                nom,
                type as tiers_type,
                email,
                telephone,
                ville,
                nom as display_text,
                CASE
                    WHEN type = 'Client' THEN 'pages/tiers/modifier_client.php?id='
                    WHEN type = 'Fournisseur' THEN 'pages/tiers/modifier_fournisseur.php?id='
                    ELSE 'pages/tiers/liste_tiers.php?id='
                END as url
            FROM plan_tiers
            WHERE actif = 1
              AND (nom LIKE :search1 OR email LIKE :search2 OR telephone LIKE :search3)
            ORDER BY nom ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search3', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['results'] = array_merge($results['results'], $tiers);
    }

    // 4. RECHERCHE DANS LES JOURNAUX
    if ($module === 'all' || $module === 'journaux') {
        $stmt = $db->prepare("
            SELECT
                'journal' as type,
                id,
                code,
                libelle,
                type as journal_type,
                CONCAT(code, ' - ', libelle) as display_text,
                'pages/journaux/modifier_journal.php?id=' as url
            FROM code_journal
            WHERE code LIKE :search1 OR libelle LIKE :search2
            ORDER BY code ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':search2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $journaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['results'] = array_merge($results['results'], $journaux);
    }

    // Calculer le score de pertinence
    foreach ($results['results'] as &$result) {
        $score = 0;
        $queryLower = strtolower($query);
        $textLower = strtolower($result['display_text']);

        if ($textLower === $queryLower) {
            $score = 100;
        } elseif (strpos($textLower, $queryLower) === 0) {
            $score = 80;
        } elseif (strpos($textLower, $queryLower) !== false) {
            $score = 60;
        } else {
            $words = explode(' ', $queryLower);
            $matchCount = 0;
            foreach ($words as $word) {
                if (strpos($textLower, $word) !== false) {
                    $matchCount++;
                }
            }
            $score = ($matchCount / count($words)) * 40;
        }

        $result['relevance_score'] = $score;
    }

    // Trier par pertinence
    usort($results['results'], function($a, $b) {
        return $b['relevance_score'] <=> $a['relevance_score'];
    });

    // Limiter et grouper
    $results['results'] = array_slice($results['results'], 0, $limit);
    $results['total'] = count($results['results']);

    $grouped = [];
    foreach ($results['results'] as $result) {
        $type = $result['type'];
        if (!isset($grouped[$type])) {
            $grouped[$type] = [];
        }
        $grouped[$type][] = $result;
    }
    $results['grouped'] = $grouped;

    echo json_encode($results, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
