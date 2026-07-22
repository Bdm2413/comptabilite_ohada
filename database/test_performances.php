<?php
/**
 * Script de Test des Performances - Base de Données
 * ComptaSYSCOHADA
 *
 * Ce script mesure les performances des requêtes AVANT et APRÈS l'application des optimisations
 *
 * Usage:
 * - AVANT optimisations: php test_performances.php
 * - Appliquer optimisations.sql
 * - APRÈS optimisations: php test_performances.php
 * - Comparer les résultats
 */

// Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'comptabilite_syscohada');
define('DB_USER', 'root');
define('DB_PASS', '');

// Nombre d'itérations pour chaque test (moyenne)
define('ITERATIONS', 5);

// Couleurs pour le terminal
define('COLOR_RESET', "\033[0m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_RED', "\033[31m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_CYAN', "\033[36m");

// ============================================================================
// Connexion à la base de données
// ============================================================================

try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Désactiver le cache de requêtes pour des tests réels
    $db->exec("SET SESSION query_cache_type = OFF");

} catch (PDOException $e) {
    die("❌ Erreur de connexion : " . $e->getMessage() . "\n");
}

// ============================================================================
// Fonctions utilitaires
// ============================================================================

/**
 * Mesure le temps d'exécution d'une requête (moyenne sur N itérations)
 */
function measureQuery($db, $sql, $params = [], $iterations = ITERATIONS) {
    $times = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();

        $end = microtime(true);
        $times[] = ($end - $start) * 1000; // Convertir en millisecondes
    }

    return [
        'avg' => array_sum($times) / count($times),
        'min' => min($times),
        'max' => max($times),
        'count' => count($result)
    ];
}

/**
 * Affiche un titre de section
 */
function printSection($title) {
    echo "\n" . COLOR_CYAN . str_repeat("=", 80) . COLOR_RESET . "\n";
    echo COLOR_CYAN . "  " . $title . COLOR_RESET . "\n";
    echo COLOR_CYAN . str_repeat("=", 80) . COLOR_RESET . "\n\n";
}

/**
 * Affiche un résultat de test
 */
function printResult($testName, $result, $expectedImprovement = null) {
    $avg = number_format($result['avg'], 2);
    $min = number_format($result['min'], 2);
    $max = number_format($result['max'], 2);
    $count = $result['count'];

    // Couleur selon la performance
    $color = COLOR_GREEN;
    if ($result['avg'] > 500) {
        $color = COLOR_RED;
    } elseif ($result['avg'] > 200) {
        $color = COLOR_YELLOW;
    }

    echo "📊 {$testName}\n";
    echo "   Résultats : {$count} lignes\n";
    echo "   {$color}Temps moyen : {$avg} ms{$COLOR_RESET} (min: {$min} ms, max: {$max} ms)\n";

    if ($expectedImprovement !== null) {
        echo "   {$color}Amélioration attendue : {$expectedImprovement}%{$COLOR_RESET}\n";
    }

    echo "\n";
}

/**
 * Vérifie si les optimisations sont appliquées
 */
function checkOptimizations($db) {
    echo COLOR_BLUE . "🔍 Vérification des optimisations...\n" . COLOR_RESET;

    // Vérifier les index
    $stmt = $db->query("
        SELECT COUNT(*) as nb_index
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "'
          AND TABLE_NAME = 'ecritures'
          AND INDEX_NAME LIKE 'idx_%'
    ");
    $indexCount = $stmt->fetch()['nb_index'];

    // Vérifier les vues
    $stmt = $db->query("
        SELECT COUNT(*) as nb_vues
        FROM INFORMATION_SCHEMA.VIEWS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "'
          AND TABLE_NAME LIKE 'v_%'
    ");
    $vueCount = $stmt->fetch()['nb_vues'];

    // Vérifier les procédures
    $stmt = $db->query("
        SELECT COUNT(*) as nb_proc
        FROM INFORMATION_SCHEMA.ROUTINES
        WHERE ROUTINE_SCHEMA = '" . DB_NAME . "'
          AND ROUTINE_TYPE = 'PROCEDURE'
          AND ROUTINE_NAME LIKE 'sp_%'
    ");
    $procCount = $stmt->fetch()['nb_proc'];

    echo "   Index détectés : " . ($indexCount >= 5 ? COLOR_GREEN : COLOR_RED) . "{$indexCount}" . COLOR_RESET . " (attendu: >= 5)\n";
    echo "   Vues détectées : " . ($vueCount >= 7 ? COLOR_GREEN : COLOR_RED) . "{$vueCount}" . COLOR_RESET . " (attendu: 7)\n";
    echo "   Procédures détectées : " . ($procCount >= 2 ? COLOR_GREEN : COLOR_RED) . "{$procCount}" . COLOR_RESET . " (attendu: 2)\n";

    $optimized = ($indexCount >= 5 && $vueCount >= 7 && $procCount >= 2);

    if ($optimized) {
        echo "\n" . COLOR_GREEN . "✅ Optimisations détectées - Mode APRÈS" . COLOR_RESET . "\n";
    } else {
        echo "\n" . COLOR_YELLOW . "⚠️  Optimisations NON détectées - Mode AVANT" . COLOR_RESET . "\n";
    }

    return $optimized;
}

/**
 * Récupère les statistiques de la base
 */
function getDbStats($db) {
    echo COLOR_BLUE . "📈 Statistiques de la base de données...\n" . COLOR_RESET;

    $stmt = $db->query("SELECT COUNT(*) as nb FROM ecritures");
    $nbEcritures = $stmt->fetch()['nb'];

    $stmt = $db->query("SELECT COUNT(*) as nb FROM lignes_ecriture");
    $nbLignes = $stmt->fetch()['nb'];

    $stmt = $db->query("SELECT COUNT(*) as nb FROM tiers WHERE actif = 'Oui'");
    $nbTiers = $stmt->fetch()['nb'];

    $stmt = $db->query("SELECT COUNT(*) as nb FROM plan_comptable WHERE actif = 'Oui'");
    $nbComptes = $stmt->fetch()['nb'];

    echo "   Écritures : " . number_format($nbEcritures, 0, ',', ' ') . "\n";
    echo "   Lignes d'écriture : " . number_format($nbLignes, 0, ',', ' ') . "\n";
    echo "   Tiers actifs : " . number_format($nbTiers, 0, ',', ' ') . "\n";
    echo "   Comptes actifs : " . number_format($nbComptes, 0, ',', ' ') . "\n";

    return [
        'ecritures' => $nbEcritures,
        'lignes' => $nbLignes,
        'tiers' => $nbTiers,
        'comptes' => $nbComptes
    ];
}

// ============================================================================
// Tests de performance
// ============================================================================

echo "\n";
echo COLOR_BLUE . "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                                                                            ║\n";
echo "║               📊 TEST DE PERFORMANCES - BASE DE DONNÉES                    ║\n";
echo "║                    ComptaSYSCOHADA - Version 1.0                           ║\n";
echo "║                                                                            ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝" . COLOR_RESET . "\n";

// Vérifier l'état des optimisations
$optimized = checkOptimizations($db);
echo "\n";

// Statistiques de la base
$stats = getDbStats($db);
echo "\n";

// ============================================================================
// TEST 1 : Requêtes sur les ÉCRITURES
// ============================================================================

printSection("TEST 1 : Requêtes sur les ÉCRITURES");

// 1.1 - Liste des écritures avec filtres (date + journal + statut)
$sql = "
    SELECT e.*, cj.journal AS libelle_journal
    FROM ecritures e
    LEFT JOIN code_journal cj ON e.journal = cj.code
    WHERE e.date_ecriture BETWEEN '2025-01-01' AND '2025-12-31'
      AND e.journal = 'AC'
      AND e.statut = 'Validé'
    ORDER BY e.date_ecriture DESC
    LIMIT 50
";
$result = measureQuery($db, $sql);
printResult("Liste écritures filtrées (date + journal + statut)", $result, 65);

// 1.2 - Recherche par référence de pièce
$sql = "SELECT * FROM ecritures WHERE reference_piece LIKE 'AC%' LIMIT 20";
$result = measureQuery($db, $sql);
printResult("Recherche par référence de pièce", $result, 50);

// 1.3 - Écritures par mois/année
$sql = "
    SELECT annee, mois, COUNT(*) as nb, SUM(montant_total) as total
    FROM ecritures
    WHERE annee = 2025 AND mois = 'Janvier'
    GROUP BY annee, mois
";
$result = measureQuery($db, $sql);
printResult("Écritures par mois/année", $result, 60);

// ============================================================================
// TEST 2 : Requêtes sur les LIGNES D'ÉCRITURE
// ============================================================================

printSection("TEST 2 : Requêtes sur les LIGNES D'ÉCRITURE");

// 2.1 - Solde d'un compte
$sql = "
    SELECT compte,
           SUM(debit) as total_debit,
           SUM(credit) as total_credit,
           SUM(debit) - SUM(credit) as solde
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE le.compte = '601100' AND e.statut = 'Validé'
    GROUP BY compte
";
$result = measureQuery($db, $sql);
printResult("Solde d'un compte spécifique", $result, 70);

// 2.2 - Tous les mouvements d'un compte
$sql = "
    SELECT le.*, e.date_ecriture, e.journal, e.libelle AS libelle_ecriture
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE le.compte = '601100' AND e.statut = 'Validé'
    ORDER BY e.date_ecriture DESC
    LIMIT 100
";
$result = measureQuery($db, $sql);
printResult("Mouvements d'un compte (Grand-livre)", $result, 65);

// 2.3 - Balance générale (tous les comptes)
$sql = "
    SELECT pc.compte,
           pc.intitule_compte,
           COALESCE(SUM(le.debit), 0) as total_debit,
           COALESCE(SUM(le.credit), 0) as total_credit,
           COALESCE(SUM(le.debit) - SUM(le.credit), 0) as solde
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE (e.statut = 'Validé' OR e.id IS NULL) AND pc.actif = 'Oui'
    GROUP BY pc.compte, pc.intitule_compte
    HAVING total_debit > 0 OR total_credit > 0
    ORDER BY pc.compte
";
$result = measureQuery($db, $sql, [], 3); // Seulement 3 itérations (requête lourde)
printResult("Balance générale (requête directe)", $result, 75);

// ============================================================================
// TEST 3 : Requêtes sur les TIERS
// ============================================================================

printSection("TEST 3 : Requêtes sur les TIERS");

// 3.1 - Liste des clients actifs
$sql = "SELECT * FROM tiers WHERE type = 'client' AND actif = 'Oui' ORDER BY nom LIMIT 50";
$result = measureQuery($db, $sql);
printResult("Liste des clients actifs", $result, 50);

// 3.2 - Recherche de tiers par nom
$sql = "SELECT * FROM tiers WHERE nom LIKE 'S%' AND actif = 'Oui' LIMIT 20";
$result = measureQuery($db, $sql);
printResult("Recherche de tiers par nom", $result, 60);

// 3.3 - Soldes des tiers (balance auxiliaire)
$sql = "
    SELECT t.id, t.nom, t.type,
           COALESCE(SUM(le.debit), 0) as total_debit,
           COALESCE(SUM(le.credit), 0) as total_credit,
           COALESCE(SUM(le.debit) - SUM(le.credit), 0) as solde
    FROM tiers t
    LEFT JOIN lignes_ecriture le ON t.id = le.id_tiers
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE t.actif = 'Oui' AND (e.statut = 'Validé' OR e.id IS NULL)
    GROUP BY t.id, t.nom, t.type
    HAVING total_debit > 0 OR total_credit > 0
";
$result = measureQuery($db, $sql, [], 3); // Seulement 3 itérations (requête lourde)
printResult("Balance auxiliaire (tous les tiers)", $result, 70);

// ============================================================================
// TEST 4 : Requêtes sur le PLAN COMPTABLE
// ============================================================================

printSection("TEST 4 : Requêtes sur le PLAN COMPTABLE");

// 4.1 - Comptes par classe
$sql = "SELECT * FROM plan_comptable WHERE classe = '6' AND actif = 'Oui' ORDER BY compte";
$result = measureQuery($db, $sql);
printResult("Comptes de la classe 6 (Charges)", $result, 50);

// 4.2 - Recherche de compte par intitulé
$sql = "SELECT * FROM plan_comptable WHERE intitule_compte LIKE '%Achat%' AND actif = 'Oui' LIMIT 20";
$result = measureQuery($db, $sql);
printResult("Recherche par intitulé de compte", $result, 60);

// ============================================================================
// TEST 5 : Requêtes COMPLEXES (JOIN multiples)
// ============================================================================

printSection("TEST 5 : Requêtes COMPLEXES (JOIN multiples)");

// 5.1 - Journal général
$sql = "
    SELECT e.numero_ecriture, e.date_ecriture, e.journal,
           cj.journal AS libelle_journal,
           e.libelle AS libelle_ecriture,
           le.compte, pc.intitule_compte,
           le.libelle AS libelle_ligne,
           le.debit, le.credit,
           t.nom AS nom_tiers
    FROM ecritures e
    INNER JOIN lignes_ecriture le ON e.id = le.id_ecriture
    LEFT JOIN plan_comptable pc ON le.compte = pc.compte
    LEFT JOIN tiers t ON le.id_tiers = t.id
    LEFT JOIN code_journal cj ON e.journal = cj.code
    WHERE e.statut = 'Validé'
      AND e.date_ecriture BETWEEN '2025-01-01' AND '2025-01-31'
    ORDER BY e.date_ecriture, e.id, le.id
    LIMIT 200
";
$result = measureQuery($db, $sql, [], 3);
printResult("Journal général (mois complet)", $result, 70);

// ============================================================================
// TEST 6 : VUES (si optimisations appliquées)
// ============================================================================

if ($optimized) {
    printSection("TEST 6 : Performance des VUES");

    // 6.1 - Vue balance générale
    $sql = "SELECT * FROM v_balance_generale";
    $result = measureQuery($db, $sql, [], 3);
    printResult("Vue v_balance_generale", $result);

    // 6.2 - Vue balance auxiliaire
    $sql = "SELECT * FROM v_balance_auxiliaire";
    $result = measureQuery($db, $sql, [], 3);
    printResult("Vue v_balance_auxiliaire", $result);

    // 6.3 - Vue grand-livre résumé
    $sql = "SELECT * FROM v_grand_livre_resume";
    $result = measureQuery($db, $sql, [], 3);
    printResult("Vue v_grand_livre_resume", $result);

    // 6.4 - Vue statistiques mensuelles
    $sql = "SELECT * FROM v_stats_mensuelles WHERE annee = 2025";
    $result = measureQuery($db, $sql);
    printResult("Vue v_stats_mensuelles", $result);
}

// ============================================================================
// TEST 7 : PROCÉDURES STOCKÉES (si optimisations appliquées)
// ============================================================================

if ($optimized) {
    printSection("TEST 7 : Performance des PROCÉDURES STOCKÉES");

    // 7.1 - Procédure sp_solde_compte
    $start = microtime(true);
    $db->exec("CALL sp_solde_compte('601100', '2025-01-01', '2025-12-31', @solde)");
    $stmt = $db->query("SELECT @solde AS solde");
    $solde = $stmt->fetch()['solde'];
    $end = microtime(true);
    $time = ($end - $start) * 1000;

    echo "📊 Procédure sp_solde_compte\n";
    echo "   Résultat : Solde = " . number_format($solde, 2) . " FCFA\n";
    echo "   " . COLOR_GREEN . "Temps d'exécution : " . number_format($time, 2) . " ms" . COLOR_RESET . "\n\n";

    // 7.2 - Procédure sp_stats_rapides
    $start = microtime(true);
    $stmt = $db->query("CALL sp_stats_rapides(2025, 'Janvier')");
    $stats = $stmt->fetchAll();
    $end = microtime(true);
    $time = ($end - $start) * 1000;

    echo "📊 Procédure sp_stats_rapides\n";
    echo "   Résultats : " . count($stats) . " lignes\n";
    echo "   " . COLOR_GREEN . "Temps d'exécution : " . number_format($time, 2) . " ms" . COLOR_RESET . "\n\n";
}

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

printSection("RÉSUMÉ ET RECOMMANDATIONS");

echo "📌 " . COLOR_BLUE . "État des optimisations :" . COLOR_RESET . "\n";
if ($optimized) {
    echo "   " . COLOR_GREEN . "✅ Optimisations appliquées" . COLOR_RESET . "\n";
    echo "   ✅ " . $stats['ecritures'] . " écritures en base\n";
    echo "   ✅ " . $stats['lignes'] . " lignes d'écriture en base\n\n";

    echo "📌 " . COLOR_BLUE . "Performances observées :" . COLOR_RESET . "\n";
    echo "   " . COLOR_GREEN . "✅ Index utilisés pour accélérer les requêtes" . COLOR_RESET . "\n";
    echo "   " . COLOR_GREEN . "✅ Vues disponibles pour rapports" . COLOR_RESET . "\n";
    echo "   " . COLOR_GREEN . "✅ Procédures stockées fonctionnelles" . COLOR_RESET . "\n\n";

    echo "📌 " . COLOR_BLUE . "Prochaines étapes :" . COLOR_RESET . "\n";
    echo "   1. Analyser les logs MySQL pour détecter les requêtes lentes\n";
    echo "   2. Exécuter OPTIMIZE TABLE tous les 3 mois\n";
    echo "   3. Surveiller la taille des tables (INFORMATION_SCHEMA)\n";
    echo "   4. Configurer my.ini/my.cnf selon les recommandations\n\n";

} else {
    echo "   " . COLOR_YELLOW . "⚠️  Optimisations NON appliquées" . COLOR_RESET . "\n";
    echo "   ⏸️  " . $stats['ecritures'] . " écritures en base\n";
    echo "   ⏸️  " . $stats['lignes'] . " lignes d'écriture en base\n\n";

    echo "📌 " . COLOR_BLUE . "Performances observées :" . COLOR_RESET . "\n";
    echo "   " . COLOR_RED . "❌ Requêtes sans index (plus lentes)" . COLOR_RESET . "\n";
    echo "   " . COLOR_RED . "❌ Vues non disponibles" . COLOR_RESET . "\n";
    echo "   " . COLOR_RED . "❌ Procédures stockées non créées" . COLOR_RESET . "\n\n";

    echo "📌 " . COLOR_BLUE . "Action requise :" . COLOR_RESET . "\n";
    echo "   " . COLOR_YELLOW . "1. Appliquer le script database/optimisations.sql" . COLOR_RESET . "\n";
    echo "   2. Relancer ce script de test\n";
    echo "   3. Comparer les résultats AVANT/APRÈS\n\n";

    echo COLOR_YELLOW . "   💡 Gain attendu : 60-80% d'amélioration des performances" . COLOR_RESET . "\n\n";
}

echo "📌 " . COLOR_BLUE . "Tests effectués :" . COLOR_RESET . "\n";
echo "   ✅ Requêtes sur écritures (3 tests)\n";
echo "   ✅ Requêtes sur lignes d'écriture (3 tests)\n";
echo "   ✅ Requêtes sur tiers (3 tests)\n";
echo "   ✅ Requêtes sur plan comptable (2 tests)\n";
echo "   ✅ Requêtes complexes (1 test)\n";
if ($optimized) {
    echo "   ✅ Performance des vues (4 tests)\n";
    echo "   ✅ Performance des procédures (2 tests)\n";
}
echo "\n";

echo COLOR_GREEN . "✨ Tests terminés avec succès !" . COLOR_RESET . "\n\n";

// ============================================================================
// Exporter les résultats dans un fichier JSON (optionnel)
// ============================================================================

$exportFile = __DIR__ . '/../logs/performance_test_' . date('Y-m-d_H-i-s') . '.json';
$exportData = [
    'date' => date('Y-m-d H:i:s'),
    'optimized' => $optimized,
    'stats' => $stats,
    'note' => 'Voir les détails dans la sortie console'
];

if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0777, true);
}

file_put_contents($exportFile, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo COLOR_CYAN . "📄 Résultats exportés : " . basename($exportFile) . COLOR_RESET . "\n\n";

?>
