<?php
/**
 * API Écritures Comptables
 * GET    /api/v1/ecritures - Liste des écritures (avec pagination)
 * GET    /api/v1/ecritures/{id} - Détail d'une écriture
 * POST   /api/v1/ecritures - Créer une écriture
 * PUT    /api/v1/ecritures/{id} - Modifier une écriture
 * DELETE /api/v1/ecritures/{id} - Supprimer une écriture
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

// Récupérer l'ID depuis l'URL si présent
$id = $_GET['id'] ?? null;

// Router
switch ($method) {
    case 'GET':
        if ($id) {
            getEcritureById($id, $currentUser);
        } else {
            getEcritures($currentUser);
        }
        break;

    case 'POST':
        createEcriture($currentUser);
        break;

    case 'PUT':
        if (!$id) {
            sendError(400, ERROR_BAD_REQUEST, 'ID required for update');
        }
        updateEcriture($id, $currentUser);
        break;

    case 'DELETE':
        if (!$id) {
            sendError(400, ERROR_BAD_REQUEST, 'ID required for delete');
        }
        deleteEcriture($id, $currentUser);
        break;

    default:
        sendError(405, 'Method not allowed');
}

/**
 * GET - Liste des écritures avec pagination et filtres
 */
function getEcritures($currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Récupérer les paramètres de pagination
        $pagination = getPaginationParams();

        // Filtres optionnels
        $filters = [];
        $params = [];

        // Filtre par journal
        if (!empty($_GET['journal'])) {
            $filters[] = "e.journal = ?";
            $params[] = $_GET['journal'];
        }

        // Filtre par statut
        if (!empty($_GET['statut'])) {
            $filters[] = "e.statut = ?";
            $params[] = $_GET['statut'];
        }

        // Filtre par période
        if (!empty($_GET['date_debut'])) {
            $filters[] = "e.date_piece >= ?";
            $params[] = $_GET['date_debut'];
        }
        if (!empty($_GET['date_fin'])) {
            $filters[] = "e.date_piece <= ?";
            $params[] = $_GET['date_fin'];
        }

        // Filtre par exercice
        if (!empty($_GET['exercice_id'])) {
            $filters[] = "e.id_exercice = ?";
            $params[] = $_GET['exercice_id'];
        }

        // Construire la clause WHERE
        $where = !empty($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

        // Compter le total
        $countSql = "SELECT COUNT(*) as total FROM ecritures e $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Récupérer les écritures
        $sql = "SELECT
                    e.id,
                    e.numero_piece,
                    e.date_piece,
                    e.journal,
                    e.libelle_ecriture,
                    e.statut,
                    e.montant_total,
                    e.created_at,
                    e.updated_at,
                    ex.annee as exercice_annee
                FROM ecritures e
                LEFT JOIN exercices ex ON e.id_exercice = ex.id
                $where
                ORDER BY e.date_piece DESC, e.id DESC
                LIMIT {$pagination['limit']} OFFSET {$pagination['offset']}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ecritures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formater la réponse
        $response = formatPaginatedResponse($ecritures, $total, $pagination['page'], $pagination['limit']);

        logApiRequest('/ecritures', 'GET', $currentUser['user_id'], 200);
        sendResponse(200, $response);

    } catch (Exception $e) {
        logApiRequest('/ecritures', 'GET', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * GET - Détail d'une écriture par ID
 */
function getEcritureById($id, $currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Récupérer l'écriture
        $sql = "SELECT
                    e.*,
                    ex.annee as exercice_annee,
                    u.nom_utilisateur as createur_nom
                FROM ecritures e
                LEFT JOIN exercices ex ON e.id_exercice = ex.id
                LEFT JOIN utilisateurs u ON e.created_by = u.id
                WHERE e.id = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ecriture) {
            sendError(404, ERROR_NOT_FOUND, "Écriture #$id not found");
        }

        // Récupérer les lignes de l'écriture
        $sqlLignes = "SELECT
                        el.*,
                        pc.intitule_compte
                    FROM ecritures_lignes el
                    LEFT JOIN plan_comptable pc ON el.compte = pc.compte
                    WHERE el.id_ecriture = ?
                    ORDER BY el.id";

        $stmt = $db->prepare($sqlLignes);
        $stmt->execute([$id]);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ecriture['lignes'] = $lignes;

        logApiRequest("/ecritures/$id", 'GET', $currentUser['user_id'], 200);
        sendResponse(200, $ecriture);

    } catch (Exception $e) {
        logApiRequest("/ecritures/$id", 'GET', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * POST - Créer une nouvelle écriture
 */
function createEcriture($currentUser) {
    $data = getRequestBody();

    // Validation
    if (empty($data['date_piece']) || empty($data['journal']) || empty($data['lignes'])) {
        sendError(400, ERROR_BAD_REQUEST, 'Required fields: date_piece, journal, lignes');
    }

    if (!is_array($data['lignes']) || count($data['lignes']) < 2) {
        sendError(400, ERROR_BAD_REQUEST, 'At least 2 lines required');
    }

    try {
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        // Insérer l'écriture
        $sql = "INSERT INTO ecritures (
                    numero_piece, date_piece, journal, libelle_ecriture,
                    statut, id_exercice, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['numero_piece'] ?? null,
            $data['date_piece'],
            $data['journal'],
            $data['libelle_ecriture'] ?? '',
            $data['statut'] ?? 'brouillon',
            $data['exercice_id'] ?? null,
            $currentUser['user_id']
        ]);

        $ecritureId = $db->lastInsertId();

        // Insérer les lignes
        $sqlLigne = "INSERT INTO ecritures_lignes (
                        id_ecriture, compte, libelle_ligne, debit, credit,
                        tiers_type, tiers_id, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmtLigne = $db->prepare($sqlLigne);
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($data['lignes'] as $ligne) {
            if (empty($ligne['compte'])) {
                $db->rollBack();
                sendError(400, ERROR_BAD_REQUEST, 'Compte required for each line');
            }

            $debit = floatval($ligne['debit'] ?? 0);
            $credit = floatval($ligne['credit'] ?? 0);

            $totalDebit += $debit;
            $totalCredit += $credit;

            $stmtLigne->execute([
                $ecritureId,
                $ligne['compte'],
                $ligne['libelle'] ?? '',
                $debit,
                $credit,
                $ligne['tiers_type'] ?? null,
                $ligne['tiers_id'] ?? null
            ]);
        }

        // Vérifier l'équilibre
        if (abs($totalDebit - $totalCredit) > 0.01) {
            $db->rollBack();
            sendError(400, ERROR_BAD_REQUEST, "Unbalanced entry: Debit=$totalDebit, Credit=$totalCredit");
        }

        // Mettre à jour le montant total
        $updateSql = "UPDATE ecritures SET montant_total = ? WHERE id = ?";
        $db->prepare($updateSql)->execute([$totalDebit, $ecritureId]);

        $db->commit();

        logApiRequest('/ecritures', 'POST', $currentUser['user_id'], 201);
        sendResponse(201, ['id' => $ecritureId], 'Écriture created successfully');

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        logApiRequest('/ecritures', 'POST', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * PUT - Modifier une écriture
 */
function updateEcriture($id, $currentUser) {
    $data = getRequestBody();

    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier que l'écriture existe
        $stmt = $db->prepare("SELECT statut FROM ecritures WHERE id = ?");
        $stmt->execute([$id]);
        $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ecriture) {
            sendError(404, ERROR_NOT_FOUND, "Écriture #$id not found");
        }

        // Ne pas permettre la modification des écritures validées
        if ($ecriture['statut'] === 'valide') {
            sendError(403, ERROR_FORBIDDEN, 'Cannot modify validated entries');
        }

        // Mettre à jour
        $updates = [];
        $params = [];

        if (isset($data['date_piece'])) {
            $updates[] = "date_piece = ?";
            $params[] = $data['date_piece'];
        }
        if (isset($data['journal'])) {
            $updates[] = "journal = ?";
            $params[] = $data['journal'];
        }
        if (isset($data['libelle_ecriture'])) {
            $updates[] = "libelle_ecriture = ?";
            $params[] = $data['libelle_ecriture'];
        }
        if (isset($data['statut'])) {
            $updates[] = "statut = ?";
            $params[] = $data['statut'];
        }

        if (empty($updates)) {
            sendError(400, ERROR_BAD_REQUEST, 'No fields to update');
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE ecritures SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        logApiRequest("/ecritures/$id", 'PUT', $currentUser['user_id'], 200);
        sendResponse(200, null, 'Écriture updated successfully');

    } catch (Exception $e) {
        logApiRequest("/ecritures/$id", 'PUT', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}

/**
 * DELETE - Supprimer une écriture
 */
function deleteEcriture($id, $currentUser) {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier que l'écriture existe
        $stmt = $db->prepare("SELECT statut FROM ecritures WHERE id = ?");
        $stmt->execute([$id]);
        $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ecriture) {
            sendError(404, ERROR_NOT_FOUND, "Écriture #$id not found");
        }

        // Ne pas permettre la suppression des écritures validées
        if ($ecriture['statut'] === 'valide') {
            sendError(403, ERROR_FORBIDDEN, 'Cannot delete validated entries');
        }

        $db->beginTransaction();

        // Supprimer les lignes
        $db->prepare("DELETE FROM ecritures_lignes WHERE id_ecriture = ?")->execute([$id]);

        // Supprimer l'écriture
        $db->prepare("DELETE FROM ecritures WHERE id = ?")->execute([$id]);

        $db->commit();

        logApiRequest("/ecritures/$id", 'DELETE', $currentUser['user_id'], 200);
        sendResponse(200, null, 'Écriture deleted successfully');

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        logApiRequest("/ecritures/$id", 'DELETE', $currentUser['user_id'], 500);
        sendError(500, ERROR_SERVER, $e->getMessage());
    }
}
