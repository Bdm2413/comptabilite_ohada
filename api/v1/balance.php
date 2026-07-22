<?php
/**
 * API Balance Générale et Auxiliaire
 * GET /api/v1/balance/generale - Balance générale
 * GET /api/v1/balance/auxiliaire - Balance auxiliaire
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

// Récupérer le type de balance
$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';

if (strpos($path, '/generale') !== false) {
    getBalanceGenerale($currentUser);
} elseif (strpos($path, '/auxiliaire') !== false) {
    getBalanceAuxiliaire($currentUser);
} else {
    sendError(404, ERROR_NOT_FOUND, 'Endpoint not found. Use /generale or /auxiliaire');
}

/**
 * GET - Balance Générale
 */
function getBalanceGenerale($currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Paramètres optionnels
        $dateDebut = $_GET['date_debut'] ?? null;
        $dateFin = $_GET['date_fin'] ?? null;
        $exerciceId = $_GET['exercice_id'] ?? null;
        $classeCompte = $_GET['classe'] ?? null;

        // Construire les filtres
        $filters = [];
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

        // Ajouter le filtre pour les écritures validées uniquement
        $filters[] = "e.statut = 'valide'";

        $where = !empty($filters) ? 'WHERE ' . implode(' AND ', $filters) : 'WHERE e.statut = \'valide\'';

        // Requête pour la balance
        $sql = "SELECT
                    pc.compte,
                    pc.intitule_compte,
                    pc.classe,
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
        $balance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculer les totaux
        $totaux = [
            'total_debit' => 0,
            'total_credit' => 0,
            'solde_debiteur' => 0,
            'solde_crediteur' => 0
        ];

        foreach ($balance as &$ligne) {
            $ligne['total_debit'] = floatval($ligne['total_debit']);
            $ligne['total_credit'] = floatval($ligne['total_credit']);
            $ligne['solde'] = floatval($ligne['solde']);

            $totaux['total_debit'] += $ligne['total_debit'];
            $totaux['total_credit'] += $ligne['total_credit'];

            if ($ligne['solde'] > 0) {
                $totaux['solde_debiteur'] += $ligne['solde'];
            } else {
                $totaux['solde_crediteur'] += abs($ligne['solde']);
            }
        }

        // Préparer la réponse
        $response = [
            'balance' => $balance,
            'totaux' => $totaux,
            'parametres' => [
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'exercice_id' => $exerciceId,
                'classe' => $classeCompte
            ],
            'count' => count($balance)
        ];

        logApiRequest('/balance/generale', 'GET', $currentUser['user_id'], 200);
        sendResponse(200, $response);

    } catch (Exception $e) {
        logApiRequest('/balance/generale', 'GET', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * GET - Balance Auxiliaire
 */
function getBalanceAuxiliaire($currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Paramètres optionnels
        $dateDebut = $_GET['date_debut'] ?? null;
        $dateFin = $_GET['date_fin'] ?? null;
        $exerciceId = $_GET['exercice_id'] ?? null;
        $tiersType = $_GET['tiers_type'] ?? null; // 'client' ou 'fournisseur'

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

        if ($tiersType) {
            $filters[] = "el.tiers_type = ?";
            $params[] = $tiersType;
        }

        // Filtrer uniquement les comptes de tiers (classe 4)
        $filters[] = "LEFT(el.compte, 1) = '4'";

        $where = 'WHERE ' . implode(' AND ', $filters);

        // Requête pour la balance auxiliaire
        $sql = "SELECT
                    el.compte,
                    pc.intitule_compte,
                    el.tiers_type,
                    el.tiers_id,
                    t.nom as tiers_nom,
                    t.type as tiers_categorie,
                    COALESCE(SUM(el.debit), 0) as total_debit,
                    COALESCE(SUM(el.credit), 0) as total_credit,
                    COALESCE(SUM(el.debit) - SUM(el.credit), 0) as solde
                FROM ecritures_lignes el
                INNER JOIN ecritures e ON el.id_ecriture = e.id
                LEFT JOIN plan_comptable pc ON el.compte = pc.compte
                LEFT JOIN tiers t ON el.tiers_id = t.id AND el.tiers_type = t.type
                $where
                GROUP BY el.compte, pc.intitule_compte, el.tiers_type, el.tiers_id, t.nom, t.type
                HAVING total_debit > 0 OR total_credit > 0
                ORDER BY el.compte, t.nom";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $balance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculer les totaux
        $totaux = [
            'total_debit' => 0,
            'total_credit' => 0,
            'solde_debiteur' => 0,
            'solde_crediteur' => 0
        ];

        foreach ($balance as &$ligne) {
            $ligne['total_debit'] = floatval($ligne['total_debit']);
            $ligne['total_credit'] = floatval($ligne['total_credit']);
            $ligne['solde'] = floatval($ligne['solde']);

            $totaux['total_debit'] += $ligne['total_debit'];
            $totaux['total_credit'] += $ligne['total_credit'];

            if ($ligne['solde'] > 0) {
                $totaux['solde_debiteur'] += $ligne['solde'];
            } else {
                $totaux['solde_crediteur'] += abs($ligne['solde']);
            }
        }

        // Préparer la réponse
        $response = [
            'balance' => $balance,
            'totaux' => $totaux,
            'parametres' => [
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'exercice_id' => $exerciceId,
                'tiers_type' => $tiersType
            ],
            'count' => count($balance)
        ];

        logApiRequest('/balance/auxiliaire', 'GET', $currentUser['user_id'], 200);
        sendResponse(200, $response);

    } catch (Exception $e) {
        logApiRequest('/balance/auxiliaire', 'GET', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}
