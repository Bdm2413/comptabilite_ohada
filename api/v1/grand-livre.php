<?php
/**
 * API Grand-Livre
 * GET /api/v1/grand-livre - Grand-livre général (par compte)
 * GET /api/v1/grand-livre/{compte} - Mouvements d'un compte spécifique
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/Auth.php';
require_once __DIR__ . '/../middleware/RateLimit.php';

// Appliquer le rate limiting
RateLimit::check();

// Authentification requise
$currentUser = AuthMiddleware::authenticate();

// Récupérer la méthode HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Seul GET est autorisé
if ($method !== 'GET') {
    sendError(405, 'Method not allowed');
}

// Récupérer le compte depuis l'URL
$compte = $_GET['compte'] ?? null;

if ($compte) {
    getGrandLivreByCompte($compte, $currentUser);
} else {
    getGrandLivreGeneral($currentUser);
}

/**
 * GET - Grand-Livre Général (tous les comptes)
 */
function getGrandLivreGeneral($currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Paramètres optionnels
        $dateDebut = $_GET['date_debut'] ?? null;
        $dateFin = $_GET['date_fin'] ?? null;
        $exerciceId = $_GET['exercice_id'] ?? null;
        $classeCompte = $_GET['classe'] ?? null;

        // Construire les filtres
        $filters = ["e.statut = 'valide'"];
        $params = [];

        if ($exerciceId) {
            $filters[] = "e.id_exercice = ?";
            $params[] = $exerciceId;
        }

        if ($dateDebut) {
            $filters[] = "e.date_piece >= ?";
            $params[] = $dateDebut;
        }

        if ($dateFin) {
            $filters[] = "e.date_piece <= ?";
            $params[] = $dateFin;
        }

        if ($classeCompte) {
            $filters[] = "LEFT(el.compte, 1) = ?";
            $params[] = $classeCompte;
        }

        $where = 'WHERE ' . implode(' AND ', $filters);

        // Requête pour obtenir le résumé par compte
        $sql = "SELECT
                    pc.compte,
                    pc.intitule_compte,
                    pc.classe,
                    COUNT(DISTINCT el.id_ecriture) as nb_mouvements,
                    COALESCE(SUM(el.debit), 0) as total_debit,
                    COALESCE(SUM(el.credit), 0) as total_credit,
                    COALESCE(SUM(el.debit) - SUM(el.credit), 0) as solde
                FROM plan_comptable pc
                LEFT JOIN ecritures_lignes el ON pc.compte = el.compte
                LEFT JOIN ecritures e ON el.id_ecriture = e.id
                $where
                GROUP BY pc.compte, pc.intitule_compte, pc.classe
                HAVING total_debit > 0 OR total_credit > 0
                ORDER BY pc.compte";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $grandLivre = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formater les données
        foreach ($grandLivre as &$ligne) {
            $ligne['nb_mouvements'] = intval($ligne['nb_mouvements']);
            $ligne['total_debit'] = floatval($ligne['total_debit']);
            $ligne['total_credit'] = floatval($ligne['total_credit']);
            $ligne['solde'] = floatval($ligne['solde']);
        }

        $response = [
            'comptes' => $grandLivre,
            'parametres' => [
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'exercice_id' => $exerciceId,
                'classe' => $classeCompte
            ],
            'count' => count($grandLivre)
        ];

        logApiRequest('/grand-livre', 'GET', $currentUser['user_id'], 200);
        sendResponse(200, $response);

    } catch (Exception $e) {
        logApiRequest('/grand-livre', 'GET', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * GET - Grand-Livre d'un compte spécifique avec tous les mouvements
 */
function getGrandLivreByCompte($compte, $currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier que le compte existe
        $stmtCompte = $db->prepare("SELECT * FROM plan_comptable WHERE compte = ?");
        $stmtCompte->execute([$compte]);
        $compteInfo = $stmtCompte->fetch(PDO::FETCH_ASSOC);

        if (!$compteInfo) {
            sendError(404, ERROR_NOT_FOUND, "Compte $compte not found");
        }

        // Paramètres optionnels
        $dateDebut = $_GET['date_debut'] ?? null;
        $dateFin = $_GET['date_fin'] ?? null;
        $exerciceId = $_GET['exercice_id'] ?? null;

        // Construire les filtres
        $filters = [
            "e.statut = 'valide'",
            "el.compte = ?"
        ];
        $params = [$compte];

        if ($exerciceId) {
            $filters[] = "e.id_exercice = ?";
            $params[] = $exerciceId;
        }

        if ($dateDebut) {
            $filters[] = "e.date_piece >= ?";
            $params[] = $dateDebut;
        }

        if ($dateFin) {
            $filters[] = "e.date_piece <= ?";
            $params[] = $dateFin;
        }

        $where = 'WHERE ' . implode(' AND ', $filters);

        // Récupérer les mouvements
        $sql = "SELECT
                    e.id as ecriture_id,
                    e.numero_piece,
                    e.date_piece,
                    e.journal,
                    e.libelle_ecriture,
                    el.id as ligne_id,
                    el.libelle_ligne,
                    el.debit,
                    el.credit,
                    el.tiers_type,
                    el.tiers_id,
                    t.nom as tiers_nom
                FROM ecritures_lignes el
                INNER JOIN ecritures e ON el.id_ecriture = e.id
                LEFT JOIN tiers t ON el.tiers_id = t.id AND el.tiers_type = t.type
                $where
                ORDER BY e.date_piece ASC, e.id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculer le solde progressif
        $solde = 0;
        foreach ($mouvements as &$mouvement) {
            $mouvement['debit'] = floatval($mouvement['debit']);
            $mouvement['credit'] = floatval($mouvement['credit']);
            $solde += $mouvement['debit'] - $mouvement['credit'];
            $mouvement['solde_progressif'] = $solde;
        }

        // Calculer les totaux
        $totaux = [
            'total_debit' => array_sum(array_column($mouvements, 'debit')),
            'total_credit' => array_sum(array_column($mouvements, 'credit')),
            'solde_final' => $solde
        ];

        $response = [
            'compte' => [
                'numero' => $compteInfo['compte'],
                'intitule' => $compteInfo['intitule_compte'],
                'classe' => $compteInfo['classe']
            ],
            'mouvements' => $mouvements,
            'totaux' => $totaux,
            'parametres' => [
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'exercice_id' => $exerciceId
            ],
            'count' => count($mouvements)
        ];

        logApiRequest("/grand-livre/$compte", 'GET', $currentUser['user_id'], 200);
        sendResponse(200, $response);

    } catch (Exception $e) {
        logApiRequest("/grand-livre/$compte", 'GET', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}
