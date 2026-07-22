<?php
/**
 * API Balance Générale et Auxiliaire (AVEC CACHE)
 * GET /api/v1/balance/generale - Balance générale
 * GET /api/v1/balance/auxiliaire - Balance auxiliaire
 *
 * Version optimisée avec cache intelligent
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/Auth.php';
require_once __DIR__ . '/../middleware/RateLimit.php';
require_once __DIR__ . '/../../classes/CacheManager.php';

// Appliquer le rate limiting
RateLimit::check();

// Authentification requise
$currentUser = AuthMiddleware::authenticate();

// Initialiser le cache
$cache = new CacheManager();

// Récupérer la méthode HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Seul GET est autorisé
if ($method !== 'GET') {
    sendError(405, 'Method not allowed');
}

// Récupérer le type de balance
$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';

if (strpos($path, '/generale') !== false) {
    getBalanceGenerale($currentUser, $cache);
} elseif (strpos($path, '/auxiliaire') !== false) {
    getBalanceAuxiliaire($currentUser, $cache);
} else {
    sendError(404, ERROR_NOT_FOUND, 'Endpoint not found. Use /generale or /auxiliaire');
}

/**
 * GET - Balance Générale (avec cache)
 */
function getBalanceGenerale($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        // Paramètres optionnels
        $params = [
            'date_debut' => $_GET['date_debut'] ?? null,
            'date_fin' => $_GET['date_fin'] ?? null,
            'exercice_id' => $_GET['exercice_id'] ?? null,
            'classe' => $_GET['classe'] ?? null
        ];

        // Utiliser le cache
        $balance = $cache->getBalanceGenerale($db, $params);

        // Calculer les totaux
        $totaux = [
            'total_debit' => 0,
            'total_credit' => 0,
            'solde_debiteur' => 0,
            'solde_crediteur' => 0
        ];

        foreach ($balance as $ligne) {
            $totaux['total_debit'] += $ligne['total_debit'];
            $totaux['total_credit'] += $ligne['total_credit'];

            if ($ligne['solde'] > 0) {
                $totaux['solde_debiteur'] += $ligne['solde'];
            } else {
                $totaux['solde_crediteur'] += abs($ligne['solde']);
            }
        }

        // Log API
        logApiRequest('GET', '/balance/generale', $currentUser['user_id'], 200);

        // Réponse
        sendResponse(200, [
            'balance' => $balance,
            'totaux' => $totaux,
            'parametres' => array_filter($params),
            'count' => count($balance),
            'cached' => true
        ]);

    } catch (PDOException $e) {
        logApiRequest('GET', '/balance/generale', $currentUser['user_id'], 500, $e->getMessage());
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Balance Auxiliaire (avec cache)
 */
function getBalanceAuxiliaire($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        // Paramètres optionnels
        $params = [
            'date_debut' => $_GET['date_debut'] ?? null,
            'date_fin' => $_GET['date_fin'] ?? null,
            'exercice_id' => $_GET['exercice_id'] ?? null,
            'tiers_type' => $_GET['tiers_type'] ?? null
        ];

        // Utiliser le cache
        $balance = $cache->getBalanceAuxiliaire($db, $params);

        // Calculer les totaux
        $totaux = [
            'total_debit' => 0,
            'total_credit' => 0,
            'solde_debiteur' => 0,
            'solde_crediteur' => 0
        ];

        foreach ($balance as $ligne) {
            $totaux['total_debit'] += $ligne['total_debit'];
            $totaux['total_credit'] += $ligne['total_credit'];

            if ($ligne['solde'] > 0) {
                $totaux['solde_debiteur'] += $ligne['solde'];
            } else {
                $totaux['solde_crediteur'] += abs($ligne['solde']);
            }
        }

        // Log API
        logApiRequest('GET', '/balance/auxiliaire', $currentUser['user_id'], 200);

        // Réponse
        sendResponse(200, [
            'balance' => $balance,
            'totaux' => $totaux,
            'parametres' => array_filter($params),
            'count' => count($balance),
            'cached' => true
        ]);

    } catch (PDOException $e) {
        logApiRequest('GET', '/balance/auxiliaire', $currentUser['user_id'], 500, $e->getMessage());
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}
?>
