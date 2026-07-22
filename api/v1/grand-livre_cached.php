<?php
/**
 * API Grand-Livre (AVEC CACHE)
 * GET /api/v1/grand-livre - Grand-livre général (tous comptes)
 * GET /api/v1/grand-livre/{compte} - Grand-livre d'un compte spécifique
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

// Récupérer le compte depuis l'URL
$compte = $_GET['compte'] ?? null;

if ($compte !== null) {
    getGrandLivreCompte($currentUser, $cache, $compte);
} else {
    getGrandLivreGeneral($currentUser, $cache);
}

/**
 * GET - Grand-Livre Général (tous comptes résumés) avec cache
 */
function getGrandLivreGeneral($currentUser, $cache) {
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
        $comptes = $cache->getGrandLivre($db, null, $params);

        // Filtrer par classe si demandé
        if (!empty($params['classe'])) {
            $comptes = array_filter($comptes, function($c) use ($params) {
                return $c['classe'] == $params['classe'];
            });
            $comptes = array_values($comptes); // Réindexer
        }

        // Log API
        logApiRequest('GET', '/grand-livre', $currentUser['user_id'], 200);

        // Réponse
        sendResponse(200, [
            'comptes' => $comptes,
            'parametres' => array_filter($params),
            'count' => count($comptes),
            'cached' => true
        ]);

    } catch (PDOException $e) {
        logApiRequest('GET', '/grand-livre', $currentUser['user_id'], 500, $e->getMessage());
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Grand-Livre d'un Compte Spécifique (avec cache)
 */
function getGrandLivreCompte($currentUser, $cache, $compte) {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier que le compte existe
        $stmt = $db->prepare("SELECT compte, intitule_compte, classe FROM plan_comptable WHERE compte = ? AND actif = 'Oui'");
        $stmt->execute([$compte]);
        $compteInfo = $stmt->fetch();

        if (!$compteInfo) {
            sendError(404, ERROR_NOT_FOUND, "Compte $compte not found or inactive");
        }

        // Paramètres optionnels
        $params = [
            'date_debut' => $_GET['date_debut'] ?? null,
            'date_fin' => $_GET['date_fin'] ?? null,
            'exercice_id' => $_GET['exercice_id'] ?? null
        ];

        // Utiliser le cache
        $mouvements = $cache->getGrandLivre($db, $compte, $params);

        // Calculer le solde progressif
        $soldeProgressif = 0;
        foreach ($mouvements as &$mvt) {
            $soldeProgressif += ($mvt['debit'] - $mvt['credit']);
            $mvt['solde_progressif'] = $soldeProgressif;
        }

        // Calculer les totaux
        $totaux = [
            'total_debit' => array_sum(array_column($mouvements, 'debit')),
            'total_credit' => array_sum(array_column($mouvements, 'credit')),
            'solde_final' => $soldeProgressif
        ];

        // Log API
        logApiRequest('GET', "/grand-livre/$compte", $currentUser['user_id'], 200);

        // Réponse
        sendResponse(200, [
            'compte' => [
                'numero' => $compteInfo['compte'],
                'intitule' => $compteInfo['intitule_compte'],
                'classe' => $compteInfo['classe']
            ],
            'mouvements' => $mouvements,
            'totaux' => $totaux,
            'parametres' => array_filter($params),
            'count' => count($mouvements),
            'cached' => true
        ]);

    } catch (PDOException $e) {
        logApiRequest('GET', "/grand-livre/$compte", $currentUser['user_id'], 500, $e->getMessage());
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}
?>
