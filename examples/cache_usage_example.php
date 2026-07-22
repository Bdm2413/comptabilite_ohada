<?php
/**
 * Exemple d'utilisation du système de cache
 * ComptaSYSCOHADA
 *
 * Ce fichier montre comment utiliser le CacheManager et CacheInvalidator
 * dans votre code
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CacheManager.php';
require_once __DIR__ . '/../classes/CacheInvalidator.php';

// ============================================================================
// EXEMPLE 1 : Utilisation basique du cache
// ============================================================================

echo "=== EXEMPLE 1 : Utilisation basique du cache ===\n\n";

// Initialiser le cache
$cache = new CacheManager();

// Stocker une valeur
$cache->set('user_123', ['nom' => 'Dupont', 'prenom' => 'Jean'], 3600);
echo "✅ Valeur stockée dans le cache\n";

// Récupérer une valeur
$user = $cache->get('user_123');
if ($user !== null) {
    echo "✅ Valeur récupérée : " . $user['nom'] . " " . $user['prenom'] . "\n";
} else {
    echo "❌ Valeur non trouvée dans le cache\n";
}

// Vérifier l'existence
if ($cache->has('user_123')) {
    echo "✅ La clé existe dans le cache\n";
}

// Supprimer
$cache->delete('user_123');
echo "✅ Valeur supprimée du cache\n\n";

// ============================================================================
// EXEMPLE 2 : Cache de la balance générale
// ============================================================================

echo "=== EXEMPLE 2 : Cache de la balance générale ===\n\n";

$db = Database::getInstance()->getConnection();

// Sans paramètres
$balance = $cache->getBalanceGenerale($db);
echo "✅ Balance générale récupérée (" . count($balance) . " comptes)\n";

// Avec filtres
$params = [
    'classe' => '6',
    'date_debut' => '2025-01-01',
    'date_fin' => '2025-01-31'
];
$balance = $cache->getBalanceGenerale($db, $params);
echo "✅ Balance classe 6 récupérée (" . count($balance) . " comptes)\n\n";

// ============================================================================
// EXEMPLE 3 : Cache du grand-livre
// ============================================================================

echo "=== EXEMPLE 3 : Cache du grand-livre ===\n\n";

// Grand-livre général (tous comptes)
$grandLivre = $cache->getGrandLivre($db);
echo "✅ Grand-livre général récupéré (" . count($grandLivre) . " comptes)\n";

// Grand-livre d'un compte spécifique
$grandLivreCompte = $cache->getGrandLivre($db, '601100');
echo "✅ Grand-livre compte 601100 récupéré (" . count($grandLivreCompte) . " mouvements)\n\n";

// ============================================================================
// EXEMPLE 4 : Invalidation automatique
// ============================================================================

echo "=== EXEMPLE 4 : Invalidation automatique ===\n\n";

// Initialiser le système d'invalidation
$invalidator = new CacheInvalidator($cache, $db);

// Initialiser les hooks
CacheHooks::init($invalidator);

// Simuler la création d'une écriture
$ecritureData = [
    'id' => 999,
    'numero_piece' => 'TEST001',
    'journal' => 'AC',
    'montant_total' => 50000
];

// Déclencher le hook
CacheHooks::trigger('ecriture.created', $ecritureData);
echo "✅ Cache invalidé après création d'écriture\n";

// Simuler la validation d'une écriture
CacheHooks::trigger('ecriture.validated', 999);
echo "✅ Cache invalidé après validation d'écriture\n";

// Simuler la modification d'un tiers
CacheHooks::trigger('tiers.changed', 10);
echo "✅ Cache invalidé après modification d'un tiers\n\n";

// ============================================================================
// EXEMPLE 5 : Statistiques du cache
// ============================================================================

echo "=== EXEMPLE 5 : Statistiques du cache ===\n\n";

$stats = $cache->getStats();
echo "📊 Statistiques du cache :\n";
echo "   - Activé : " . ($stats['enabled'] ? 'Oui' : 'Non') . "\n";
echo "   - Fichiers totaux : " . $stats['total_files'] . "\n";
echo "   - Fichiers valides : " . $stats['valid_files'] . "\n";
echo "   - Fichiers expirés : " . $stats['expired_files'] . "\n";
echo "   - Taille totale : " . $stats['total_size_mb'] . " MB\n";
echo "   - Répertoire : " . $stats['cache_dir'] . "\n\n";

// ============================================================================
// EXEMPLE 6 : Nettoyage du cache
// ============================================================================

echo "=== EXEMPLE 6 : Nettoyage du cache ===\n\n";

// Nettoyer les fichiers expirés
$count = $cache->cleanup();
echo "✅ $count fichiers expirés supprimés\n";

// Supprimer par préfixe
$count = $cache->deleteByPrefix(CacheManager::PREFIX_BALANCE_GENERALE);
echo "✅ $count fichiers de balance supprimés\n";

// Vider tout le cache
// $count = $cache->clear();
// echo "✅ $count fichiers au total supprimés\n";

echo "\n";

// ============================================================================
// EXEMPLE 7 : Utilisation dans une API
// ============================================================================

echo "=== EXEMPLE 7 : Utilisation dans une API ===\n\n";

echo "Code PHP pour un endpoint API :\n\n";
echo <<<'PHP'
// api/v1/balance_cached.php
require_once '../../classes/CacheManager.php';

$cache = new CacheManager();
$db = Database::getInstance()->getConnection();

// Paramètres de la requête
$params = [
    'classe' => $_GET['classe'] ?? null,
    'date_debut' => $_GET['date_debut'] ?? null,
    'date_fin' => $_GET['date_fin'] ?? null
];

// Utiliser le cache (mise en cache automatique)
$balance = $cache->getBalanceGenerale($db, $params);

// Retourner la réponse
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => $balance,
    'cached' => true,
    'count' => count($balance)
]);
PHP;

echo "\n\n";

// ============================================================================
// EXEMPLE 8 : CRON pour nettoyage quotidien
// ============================================================================

echo "=== EXEMPLE 8 : CRON pour nettoyage quotidien ===\n\n";

echo "Script CRON (cache_cleanup_cron.php) :\n\n";
echo <<<'PHP'
#!/usr/bin/env php
<?php
require_once __DIR__ . '/../classes/CacheManager.php';
require_once __DIR__ . '/../classes/CacheInvalidator.php';
require_once __DIR__ . '/../config/database.php';

$cache = new CacheManager();
$db = Database::getInstance()->getConnection();
$invalidator = new CacheInvalidator($cache, $db);

// Nettoyer les fichiers expirés
$count = $invalidator->dailyCleanup();
echo date('Y-m-d H:i:s') . " - $count fichiers expirés supprimés\n";
?>
PHP;

echo "\n";
echo "Configurer dans crontab (exécuter tous les jours à 3h du matin) :\n";
echo "0 3 * * * php /path/to/comptabilite_ohada/examples/cache_cleanup_cron.php\n\n";

// ============================================================================
// RÉSUMÉ
// ============================================================================

echo "=== RÉSUMÉ ===\n\n";
echo "✅ Le système de cache est opérationnel\n";
echo "✅ Utilisez CacheManager pour stocker/récupérer des données\n";
echo "✅ Utilisez CacheInvalidator pour invalider automatiquement\n";
echo "✅ Les endpoints API avec cache sont disponibles\n";
echo "✅ Configurez un CRON pour le nettoyage quotidien\n\n";

echo "📖 Consultez CACHE_DOCUMENTATION.md pour plus de détails\n\n";
?>
