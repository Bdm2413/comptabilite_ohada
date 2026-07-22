<?php
/**
 * API Dashboard - Données pour le tableau de bord interactif
 * GET /api/v1/dashboard/kpis - KPIs principaux
 * GET /api/v1/dashboard/ca-mensuel - Chiffre d'affaires mensuel
 * GET /api/v1/dashboard/charges - Charges par catégorie
 * GET /api/v1/dashboard/tresorerie - Évolution trésorerie
 * GET /api/v1/dashboard/top-clients - Top 10 clients
 * GET /api/v1/dashboard/top-fournisseurs - Top 10 fournisseurs
 * GET /api/v1/dashboard/resultat - Compte de résultat simplifié
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

// Récupérer l'endpoint demandé
$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';

// Router
if (strpos($path, '/kpis') !== false) {
    getKPIs($currentUser, $cache);
} elseif (strpos($path, '/ca-mensuel') !== false) {
    getCAMensuel($currentUser, $cache);
} elseif (strpos($path, '/charges') !== false) {
    getCharges($currentUser, $cache);
} elseif (strpos($path, '/tresorerie') !== false) {
    getTresorerie($currentUser, $cache);
} elseif (strpos($path, '/top-clients') !== false) {
    getTopClients($currentUser, $cache);
} elseif (strpos($path, '/top-fournisseurs') !== false) {
    getTopFournisseurs($currentUser, $cache);
} elseif (strpos($path, '/resultat') !== false) {
    getResultat($currentUser, $cache);
} else {
    sendError(404, ERROR_NOT_FOUND, 'Endpoint not found');
}

/**
 * GET - KPIs principaux du dashboard
 */
function getKPIs($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier le cache
        $cacheKey = 'dashboard_kpis_' . date('Y-m-d');
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, [
                'kpis' => $cached,
                'cached' => true
            ]);
            return;
        }

        // Exercice actif
        $exerciceActif = getExerciceActif($db);
        $anneeActuelle = $exerciceActif['annee'] ?? date('Y');

        // 1. Total écritures
        $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures");
        $totalEcritures = $stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Brouillon'");
        $ecrituresBrouillon = $stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM ecritures WHERE statut = 'Validé'");
        $ecrituresValidees = $stmt->fetch()['total'];

        // 2. Trésorerie (Comptes 57 - Caisse et 521 - Banques)
        $stmt = $db->query("
            SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as solde
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE (LEFT(le.compte, 2) = '57' OR LEFT(le.compte, 3) = '521')
              AND e.statut = 'Validé'
        ");
        $tresorerie = $stmt->fetch()['solde'];

        // 3. CA du mois (Compte classe 7 - Produits)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 1) = '7'
              AND e.statut = 'Validé'
              AND e.annee = ?
              AND e.mois = ?
        ");
        $stmt->execute([$anneeActuelle, getMoisActuel()]);
        $caMois = $stmt->fetch()['ca'];

        // 4. Charges du mois (Compte classe 6)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as charges
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 1) = '6'
              AND e.statut = 'Validé'
              AND e.annee = ?
              AND e.mois = ?
        ");
        $stmt->execute([$anneeActuelle, getMoisActuel()]);
        $chargesMois = $stmt->fetch()['charges'];

        // 5. Résultat du mois
        $resultatMois = $caMois - $chargesMois;

        // 6. Créances clients (Compte 411)
        $stmt = $db->query("
            SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as creances
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 3) = '411'
              AND e.statut = 'Validé'
        ");
        $creancesClients = $stmt->fetch()['creances'];

        // 7. Dettes fournisseurs (Compte 401)
        $stmt = $db->query("
            SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as dettes
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 3) = '401'
              AND e.statut = 'Validé'
        ");
        $dettesFournisseurs = $stmt->fetch()['dettes'];

        // 8. Comptes et Tiers
        $stmt = $db->query("SELECT COUNT(*) as total FROM plan_comptable WHERE actif = 'Oui'");
        $totalComptes = $stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM tiers WHERE actif = 'Oui'");
        $totalTiers = $stmt->fetch()['total'];

        $kpis = [
            'ecritures' => [
                'total' => (int)$totalEcritures,
                'brouillon' => (int)$ecrituresBrouillon,
                'validees' => (int)$ecrituresValidees
            ],
            'tresorerie' => [
                'montant' => (float)$tresorerie,
                'devise' => 'FCFA'
            ],
            'ca_mois' => [
                'montant' => (float)$caMois,
                'devise' => 'FCFA',
                'mois' => getMoisActuel(),
                'annee' => $anneeActuelle
            ],
            'charges_mois' => [
                'montant' => (float)$chargesMois,
                'devise' => 'FCFA',
                'mois' => getMoisActuel(),
                'annee' => $anneeActuelle
            ],
            'resultat_mois' => [
                'montant' => (float)$resultatMois,
                'devise' => 'FCFA',
                'mois' => getMoisActuel(),
                'annee' => $anneeActuelle
            ],
            'creances_clients' => [
                'montant' => (float)$creancesClients,
                'devise' => 'FCFA'
            ],
            'dettes_fournisseurs' => [
                'montant' => (float)$dettesFournisseurs,
                'devise' => 'FCFA'
            ],
            'comptes' => (int)$totalComptes,
            'tiers' => (int)$totalTiers
        ];

        // Mettre en cache (1 heure)
        $cache->set($cacheKey, $kpis, 3600);

        sendResponse(200, [
            'kpis' => $kpis,
            'cached' => false
        ]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Chiffre d'affaires mensuel (12 derniers mois)
 */
function getCAMensuel($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        $cacheKey = 'dashboard_ca_mensuel_' . date('Y-m');
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, ['data' => $cached, 'cached' => true]);
            return;
        }

        // CA par mois sur les 12 derniers mois
        $stmt = $db->query("
            SELECT
                e.annee,
                e.mois,
                COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
            FROM ecritures e
            INNER JOIN lignes_ecriture le ON e.id = le.id_ecriture
            WHERE LEFT(le.compte, 1) = '7'
              AND e.statut = 'Validé'
              AND e.date_ecriture >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY e.annee, e.mois
            ORDER BY e.annee DESC,
                     FIELD(e.mois, 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                                   'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre') DESC
            LIMIT 12
        ");

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Inverser l'ordre pour avoir du plus ancien au plus récent
        $data = array_reverse($data);

        // Formater pour Chart.js
        $result = [
            'labels' => array_map(function($row) {
                return substr($row['mois'], 0, 3) . ' ' . $row['annee'];
            }, $data),
            'values' => array_map(function($row) {
                return (float)$row['ca'];
            }, $data)
        ];

        $cache->set($cacheKey, $result, 3600);

        sendResponse(200, ['data' => $result, 'cached' => false]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Charges par catégorie (classe 6)
 */
function getCharges($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        $annee = $_GET['annee'] ?? date('Y');
        $mois = $_GET['mois'] ?? getMoisActuel();

        $cacheKey = "dashboard_charges_{$annee}_{$mois}";
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, ['data' => $cached, 'cached' => true]);
            return;
        }

        // Charges regroupées par racine de compte (2 premiers chiffres)
        $stmt = $db->prepare("
            SELECT
                LEFT(le.compte, 2) as classe_compte,
                CASE LEFT(le.compte, 2)
                    WHEN '60' THEN 'Achats'
                    WHEN '61' THEN 'Transports'
                    WHEN '62' THEN 'Services extérieurs'
                    WHEN '63' THEN 'Autres services'
                    WHEN '64' THEN 'Impôts et taxes'
                    WHEN '65' THEN 'Autres charges'
                    WHEN '66' THEN 'Charges financières'
                    WHEN '67' THEN 'Éléments extraordinaires'
                    WHEN '68' THEN 'Dotations amortissements'
                    ELSE 'Autres charges'
                END as categorie,
                COALESCE(SUM(le.debit) - SUM(le.credit), 0) as montant
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 1) = '6'
              AND e.statut = 'Validé'
              AND e.annee = ?
              AND e.mois = ?
            GROUP BY LEFT(le.compte, 2)
            HAVING montant > 0
            ORDER BY montant DESC
        ");

        $stmt->execute([$annee, $mois]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formater pour Chart.js
        $result = [
            'labels' => array_column($data, 'categorie'),
            'values' => array_map(function($row) {
                return (float)$row['montant'];
            }, $data)
        ];

        $cache->set($cacheKey, $result, 3600);

        sendResponse(200, ['data' => $result, 'cached' => false]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Évolution trésorerie (30 derniers jours)
 */
function getTresorerie($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        $cacheKey = 'dashboard_tresorerie_' . date('Y-m-d');
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, ['data' => $cached, 'cached' => true]);
            return;
        }

        // Trésorerie jour par jour (30 derniers jours)
        $stmt = $db->query("
            SELECT
                DATE(e.date_ecriture) as date_op,
                SUM(CASE
                    WHEN (LEFT(le.compte, 2) = '57' OR LEFT(le.compte, 3) = '521')
                    THEN le.debit - le.credit
                    ELSE 0
                END) as variation
            FROM ecritures e
            INNER JOIN lignes_ecriture le ON e.id = le.id_ecriture
            WHERE e.statut = 'Validé'
              AND e.date_ecriture >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(e.date_ecriture)
            ORDER BY date_op ASC
        ");

        $mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculer le solde progressif
        $soldeActuel = 0;
        $dates = [];
        $soldes = [];

        foreach ($mouvements as $mouvement) {
            $soldeActuel += (float)$mouvement['variation'];
            $dates[] = date('d/m', strtotime($mouvement['date_op']));
            $soldes[] = $soldeActuel;
        }

        $result = [
            'labels' => $dates,
            'values' => $soldes
        ];

        $cache->set($cacheKey, $result, 3600);

        sendResponse(200, ['data' => $result, 'cached' => false]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Top 10 clients (par CA)
 */
function getTopClients($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        $annee = $_GET['annee'] ?? date('Y');

        $cacheKey = "dashboard_top_clients_{$annee}";
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, ['data' => $cached, 'cached' => true]);
            return;
        }

        $stmt = $db->prepare("
            SELECT
                t.nom,
                COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            LEFT JOIN tiers t ON le.id_tiers = t.id
            WHERE LEFT(le.compte, 3) = '701'
              AND e.statut = 'Validé'
              AND e.annee = ?
              AND t.id IS NOT NULL
            GROUP BY t.id, t.nom
            HAVING ca > 0
            ORDER BY ca DESC
            LIMIT 10
        ");

        $stmt->execute([$annee]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'labels' => array_column($data, 'nom'),
            'values' => array_map(function($row) {
                return (float)$row['ca'];
            }, $data)
        ];

        $cache->set($cacheKey, $result, 7200);

        sendResponse(200, ['data' => $result, 'cached' => false]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Top 10 fournisseurs (par montant achats)
 */
function getTopFournisseurs($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        $annee = $_GET['annee'] ?? date('Y');

        $cacheKey = "dashboard_top_fournisseurs_{$annee}";
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, ['data' => $cached, 'cached' => true]);
            return;
        }

        $stmt = $db->prepare("
            SELECT
                t.nom,
                COALESCE(SUM(le.debit) - SUM(le.credit), 0) as achats
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            LEFT JOIN tiers t ON le.id_tiers = t.id
            WHERE LEFT(le.compte, 2) = '60'
              AND e.statut = 'Validé'
              AND e.annee = ?
              AND t.id IS NOT NULL
            GROUP BY t.id, t.nom
            HAVING achats > 0
            ORDER BY achats DESC
            LIMIT 10
        ");

        $stmt->execute([$annee]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'labels' => array_column($data, 'nom'),
            'values' => array_map(function($row) {
                return (float)$row['achats'];
            }, $data)
        ];

        $cache->set($cacheKey, $result, 7200);

        sendResponse(200, ['data' => $result, 'cached' => false]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

/**
 * GET - Compte de résultat simplifié
 */
function getResultat($currentUser, $cache) {
    try {
        $db = Database::getInstance()->getConnection();

        $annee = $_GET['annee'] ?? date('Y');

        $cacheKey = "dashboard_resultat_{$annee}";
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            sendResponse(200, ['data' => $cached, 'cached' => true]);
            return;
        }

        // Produits (classe 7)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as produits
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 1) = '7'
              AND e.statut = 'Validé'
              AND e.annee = ?
        ");
        $stmt->execute([$annee]);
        $produits = (float)$stmt->fetch()['produits'];

        // Charges (classe 6)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as charges
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE LEFT(le.compte, 1) = '6'
              AND e.statut = 'Validé'
              AND e.annee = ?
        ");
        $stmt->execute([$annee]);
        $charges = (float)$stmt->fetch()['charges'];

        $resultat = $produits - $charges;

        $data = [
            'produits' => $produits,
            'charges' => $charges,
            'resultat' => $resultat,
            'annee' => $annee,
            'marge' => $produits > 0 ? round(($resultat / $produits) * 100, 2) : 0
        ];

        $cache->set($cacheKey, $data, 3600);

        sendResponse(200, ['data' => $data, 'cached' => false]);

    } catch (PDOException $e) {
        sendError(500, ERROR_SERVER, 'Database error: ' . $e->getMessage());
    }
}

// ============================================================================
// Fonctions utilitaires
// ============================================================================

function getExerciceActif($db) {
    try {
        $stmt = $db->query("SELECT * FROM exercices WHERE statut = 'Ouvert' ORDER BY annee DESC LIMIT 1");
        return $stmt->fetch();
    } catch (Exception $e) {
        return ['annee' => date('Y'), 'statut' => 'Non défini'];
    }
}

function getMoisActuel() {
    $mois = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    return $mois[(int)date('n')];
}
?>
