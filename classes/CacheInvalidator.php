<?php
/**
 * CacheInvalidator - Système d'Invalidation Automatique du Cache
 * ComptaSYSCOHADA
 *
 * Invalide automatiquement le cache lors de modifications des données
 * comptables (écritures, lignes, tiers, etc.)
 *
 * @version 1.0
 * @date 2025-12-01
 */

require_once __DIR__ . '/CacheManager.php';

class CacheInvalidator {

    private $cache;
    private $db;
    private $enabled;

    /**
     * Constructeur
     *
     * @param CacheManager $cache Instance du gestionnaire de cache
     * @param PDO $db Connexion à la base de données
     * @param bool $enabled Activer/désactiver l'invalidation
     */
    public function __construct(CacheManager $cache, PDO $db, $enabled = true) {
        $this->cache = $cache;
        $this->db = $db;
        $this->enabled = $enabled;
    }

    /**
     * Hook appelé après création d'une écriture
     *
     * @param array $ecritureData Données de l'écriture créée
     * @return int Nombre de clés invalidées
     */
    public function onEcritureCreated($ecritureData) {
        if (!$this->enabled) {
            return 0;
        }

        $count = $this->cache->invalidateOnEcritureChange('create', $ecritureData);

        $this->log("Écriture créée (ID: {$ecritureData['id']}) - Cache invalidé ($count clés)");

        return $count;
    }

    /**
     * Hook appelé après modification d'une écriture
     *
     * @param int $ecritureId ID de l'écriture modifiée
     * @param array $ecritureData Nouvelles données
     * @return int Nombre de clés invalidées
     */
    public function onEcritureUpdated($ecritureId, $ecritureData) {
        if (!$this->enabled) {
            return 0;
        }

        $count = $this->cache->invalidateOnEcritureChange('update', $ecritureData);

        $this->log("Écriture modifiée (ID: $ecritureId) - Cache invalidé ($count clés)");

        return $count;
    }

    /**
     * Hook appelé après suppression d'une écriture
     *
     * @param int $ecritureId ID de l'écriture supprimée
     * @return int Nombre de clés invalidées
     */
    public function onEcritureDeleted($ecritureId) {
        if (!$this->enabled) {
            return 0;
        }

        $count = $this->cache->invalidateOnEcritureChange('delete', ['id' => $ecritureId]);

        $this->log("Écriture supprimée (ID: $ecritureId) - Cache invalidé ($count clés)");

        return $count;
    }

    /**
     * Hook appelé après validation d'une écriture (brouillon -> validé)
     *
     * @param int $ecritureId ID de l'écriture validée
     * @return int Nombre de clés invalidées
     */
    public function onEcritureValidated($ecritureId) {
        if (!$this->enabled) {
            return 0;
        }

        // Une validation change le statut, donc affecte tous les rapports
        $count = $this->cache->invalidateOnEcritureChange('validate', ['id' => $ecritureId]);

        $this->log("Écriture validée (ID: $ecritureId) - Cache invalidé ($count clés)");

        return $count;
    }

    /**
     * Hook appelé après création/modification d'un tiers
     *
     * @param int $tiersId ID du tiers
     * @return int Nombre de clés invalidées
     */
    public function onTiersChanged($tiersId) {
        if (!$this->enabled) {
            return 0;
        }

        // Invalider les balances auxiliaires
        $count = $this->cache->deleteByPrefix(CacheManager::PREFIX_BALANCE_AUXILIAIRE);

        $this->log("Tiers modifié (ID: $tiersId) - Cache invalidé ($count clés)");

        return $count;
    }

    /**
     * Hook appelé après modification du plan comptable
     *
     * @param string $compte Numéro de compte
     * @return int Nombre de clés invalidées
     */
    public function onCompteChanged($compte) {
        if (!$this->enabled) {
            return 0;
        }

        // Invalider le grand-livre de ce compte
        $count = 0;
        $count += $this->cache->delete(CacheManager::PREFIX_GRAND_LIVRE_COMPTE . $compte);
        $count += $this->cache->deleteByPrefix(CacheManager::PREFIX_BALANCE_GENERALE);
        $count += $this->cache->deleteByPrefix(CacheManager::PREFIX_GRAND_LIVRE);

        $this->log("Compte modifié ($compte) - Cache invalidé ($count clés)");

        return $count;
    }

    /**
     * Hook appelé en fin de journée pour nettoyer le cache expiré
     * À appeler via CRON (quotidien recommandé)
     *
     * @return int Nombre de fichiers supprimés
     */
    public function dailyCleanup() {
        if (!$this->enabled) {
            return 0;
        }

        $count = $this->cache->cleanup();

        $this->log("Nettoyage quotidien - $count fichiers expirés supprimés");

        return $count;
    }

    /**
     * Invalider tout le cache manuellement
     * Utile après import en masse ou corrections majeures
     *
     * @return int Nombre de fichiers supprimés
     */
    public function invalidateAll() {
        if (!$this->enabled) {
            return 0;
        }

        $count = $this->cache->clear();

        $this->log("Invalidation complète - $count fichiers supprimés");

        return $count;
    }

    /**
     * Activer/désactiver l'invalidation automatique
     *
     * @param bool $enabled État
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
        $this->log("Invalidation automatique " . ($enabled ? "activée" : "désactivée"));
    }

    /**
     * Obtenir les statistiques du cache
     *
     * @return array Statistiques
     */
    public function getStats() {
        return $this->cache->getStats();
    }

    // ========================================================================
    // MÉTHODES PRIVÉES
    // ========================================================================

    /**
     * Logger les événements d'invalidation
     */
    private function log($message) {
        $logFile = __DIR__ . '/../logs/cache_invalidation.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";

        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}

/**
 * Hook global pour invalider le cache après modifications
 * À utiliser dans les scripts de modification d'écritures
 */
class CacheHooks {

    private static $invalidator = null;

    /**
     * Initialiser les hooks
     *
     * @param CacheInvalidator $invalidator Instance du système d'invalidation
     */
    public static function init(CacheInvalidator $invalidator) {
        self::$invalidator = $invalidator;
    }

    /**
     * Déclencher un hook
     *
     * @param string $event Nom de l'événement
     * @param mixed ...$args Arguments de l'événement
     */
    public static function trigger($event, ...$args) {
        if (self::$invalidator === null) {
            return;
        }

        switch ($event) {
            case 'ecriture.created':
                self::$invalidator->onEcritureCreated($args[0]);
                break;

            case 'ecriture.updated':
                self::$invalidator->onEcritureUpdated($args[0], $args[1] ?? []);
                break;

            case 'ecriture.deleted':
                self::$invalidator->onEcritureDeleted($args[0]);
                break;

            case 'ecriture.validated':
                self::$invalidator->onEcritureValidated($args[0]);
                break;

            case 'tiers.changed':
                self::$invalidator->onTiersChanged($args[0]);
                break;

            case 'compte.changed':
                self::$invalidator->onCompteChanged($args[0]);
                break;
        }
    }
}
?>
