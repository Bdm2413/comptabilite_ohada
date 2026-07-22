<?php
/**
 * CacheManager - Gestionnaire de Cache Intelligent
 * ComptaSYSCOHADA
 *
 * Gère le cache des requêtes fréquentes (balance, grand-livre, rapports)
 * avec invalidation automatique lors de modifications
 *
 * @version 1.0
 * @date 2025-12-01
 */

class CacheManager {

    private $cacheDir;
    private $enabled;
    private $defaultTTL;

    // Durées de vie par défaut (en secondes)
    const TTL_BALANCE = 3600;        // 1 heure
    const TTL_GRAND_LIVRE = 3600;    // 1 heure
    const TTL_JOURNAL = 1800;        // 30 minutes
    const TTL_STATS = 7200;          // 2 heures
    const TTL_RAPPORTS = 3600;       // 1 heure

    // Préfixes de clés
    const PREFIX_BALANCE_GENERALE = 'balance_generale_';
    const PREFIX_BALANCE_AUXILIAIRE = 'balance_auxiliaire_';
    const PREFIX_GRAND_LIVRE = 'grand_livre_';
    const PREFIX_GRAND_LIVRE_COMPTE = 'grand_livre_compte_';
    const PREFIX_JOURNAL = 'journal_';
    const PREFIX_STATS = 'stats_';

    /**
     * Constructeur
     *
     * @param string $cacheDir Répertoire de stockage du cache
     * @param bool $enabled Activer/désactiver le cache
     * @param int $defaultTTL Durée de vie par défaut
     */
    public function __construct($cacheDir = null, $enabled = true, $defaultTTL = 3600) {
        $this->cacheDir = $cacheDir ?? __DIR__ . '/../cache';
        $this->enabled = $enabled;
        $this->defaultTTL = $defaultTTL;

        // Créer le répertoire de cache s'il n'existe pas
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Créer le fichier .htaccess pour sécurité
        $htaccessFile = $this->cacheDir . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Order deny,allow\nDeny from all");
        }
    }

    /**
     * Récupérer une valeur du cache
     *
     * @param string $key Clé de cache
     * @return mixed|null Valeur en cache ou null si inexistant/expiré
     */
    public function get($key) {
        if (!$this->enabled) {
            return null;
        }

        $filename = $this->getCacheFilename($key);

        if (!file_exists($filename)) {
            return null;
        }

        $data = unserialize(file_get_contents($filename));

        // Vérifier l'expiration
        if ($data['expires_at'] < time()) {
            $this->delete($key);
            return null;
        }

        return $data['value'];
    }

    /**
     * Stocker une valeur dans le cache
     *
     * @param string $key Clé de cache
     * @param mixed $value Valeur à mettre en cache
     * @param int|null $ttl Durée de vie en secondes (null = défaut)
     * @return bool Succès ou échec
     */
    public function set($key, $value, $ttl = null) {
        if (!$this->enabled) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTTL;
        $filename = $this->getCacheFilename($key);

        $data = [
            'key' => $key,
            'value' => $value,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'ttl' => $ttl
        ];

        return file_put_contents($filename, serialize($data)) !== false;
    }

    /**
     * Vérifier si une clé existe dans le cache
     *
     * @param string $key Clé de cache
     * @return bool Existe et valide
     */
    public function has($key) {
        return $this->get($key) !== null;
    }

    /**
     * Supprimer une clé du cache
     *
     * @param string $key Clé de cache
     * @return bool Succès ou échec
     */
    public function delete($key) {
        $filename = $this->getCacheFilename($key);

        if (file_exists($filename)) {
            return unlink($filename);
        }

        return true;
    }

    /**
     * Supprimer toutes les clés correspondant à un préfixe
     *
     * @param string $prefix Préfixe de clé
     * @return int Nombre de fichiers supprimés
     */
    public function deleteByPrefix($prefix) {
        $count = 0;
        $files = glob($this->cacheDir . '/' . $this->sanitizeKey($prefix) . '*.cache');

        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Vider tout le cache
     *
     * @return int Nombre de fichiers supprimés
     */
    public function clear() {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');

        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Nettoyer les fichiers expirés
     *
     * @return int Nombre de fichiers supprimés
     */
    public function cleanup() {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');

        foreach ($files as $file) {
            $data = unserialize(file_get_contents($file));

            if ($data['expires_at'] < time()) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Obtenir les statistiques du cache
     *
     * @return array Statistiques
     */
    public function getStats() {
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        $validCount = 0;
        $expiredCount = 0;

        foreach ($files as $file) {
            $totalSize += filesize($file);
            $data = unserialize(file_get_contents($file));

            if ($data['expires_at'] >= time()) {
                $validCount++;
            } else {
                $expiredCount++;
            }
        }

        return [
            'enabled' => $this->enabled,
            'total_files' => count($files),
            'valid_files' => $validCount,
            'expired_files' => $expiredCount,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'cache_dir' => $this->cacheDir
        ];
    }

    /**
     * Invalider le cache lors de modifications d'écritures
     *
     * @param string $action Type d'action (create, update, delete)
     * @param array $data Données de l'écriture modifiée
     * @return int Nombre de clés invalidées
     */
    public function invalidateOnEcritureChange($action, $data = []) {
        $count = 0;

        // Invalider tous les caches liés aux écritures
        $count += $this->deleteByPrefix(self::PREFIX_BALANCE_GENERALE);
        $count += $this->deleteByPrefix(self::PREFIX_BALANCE_AUXILIAIRE);
        $count += $this->deleteByPrefix(self::PREFIX_GRAND_LIVRE);
        $count += $this->deleteByPrefix(self::PREFIX_JOURNAL);
        $count += $this->deleteByPrefix(self::PREFIX_STATS);

        // Si on connaît le compte, invalider spécifiquement
        if (isset($data['compte'])) {
            $count += $this->delete(self::PREFIX_GRAND_LIVRE_COMPTE . $data['compte']);
        }

        $this->log("Invalidation cache - Action: $action, Clés supprimées: $count");

        return $count;
    }

    /**
     * Récupérer ou calculer la balance générale (avec mise en cache)
     *
     * @param PDO $db Connexion base de données
     * @param array $params Paramètres (date_debut, date_fin, exercice_id, classe)
     * @return array Balance générale
     */
    public function getBalanceGenerale($db, $params = []) {
        $cacheKey = $this->buildBalanceGeneraleKey($params);

        // Essayer de récupérer depuis le cache
        $cached = $this->get($cacheKey);
        if ($cached !== null) {
            $this->log("Cache HIT - Balance générale");
            return $cached;
        }

        // Sinon, calculer
        $this->log("Cache MISS - Balance générale");
        $balance = $this->calculateBalanceGenerale($db, $params);

        // Mettre en cache
        $this->set($cacheKey, $balance, self::TTL_BALANCE);

        return $balance;
    }

    /**
     * Récupérer ou calculer la balance auxiliaire (avec mise en cache)
     *
     * @param PDO $db Connexion base de données
     * @param array $params Paramètres (date_debut, date_fin, exercice_id, tiers_type)
     * @return array Balance auxiliaire
     */
    public function getBalanceAuxiliaire($db, $params = []) {
        $cacheKey = $this->buildBalanceAuxiliaireKey($params);

        // Essayer de récupérer depuis le cache
        $cached = $this->get($cacheKey);
        if ($cached !== null) {
            $this->log("Cache HIT - Balance auxiliaire");
            return $cached;
        }

        // Sinon, calculer
        $this->log("Cache MISS - Balance auxiliaire");
        $balance = $this->calculateBalanceAuxiliaire($db, $params);

        // Mettre en cache
        $this->set($cacheKey, $balance, self::TTL_BALANCE);

        return $balance;
    }

    /**
     * Récupérer ou calculer le grand-livre (avec mise en cache)
     *
     * @param PDO $db Connexion base de données
     * @param string|null $compte Compte spécifique (null = tous)
     * @param array $params Paramètres (date_debut, date_fin, exercice_id)
     * @return array Grand-livre
     */
    public function getGrandLivre($db, $compte = null, $params = []) {
        $cacheKey = $this->buildGrandLivreKey($compte, $params);

        // Essayer de récupérer depuis le cache
        $cached = $this->get($cacheKey);
        if ($cached !== null) {
            $this->log("Cache HIT - Grand-livre" . ($compte ? " compte $compte" : ""));
            return $cached;
        }

        // Sinon, calculer
        $this->log("Cache MISS - Grand-livre" . ($compte ? " compte $compte" : ""));
        $grandLivre = $this->calculateGrandLivre($db, $compte, $params);

        // Mettre en cache
        $this->set($cacheKey, $grandLivre, self::TTL_GRAND_LIVRE);

        return $grandLivre;
    }

    // ========================================================================
    // MÉTHODES PRIVÉES
    // ========================================================================

    /**
     * Obtenir le nom de fichier pour une clé
     */
    private function getCacheFilename($key) {
        return $this->cacheDir . '/' . $this->sanitizeKey($key) . '.cache';
    }

    /**
     * Nettoyer une clé pour en faire un nom de fichier valide
     */
    private function sanitizeKey($key) {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    }

    /**
     * Construire la clé de cache pour la balance générale
     */
    private function buildBalanceGeneraleKey($params) {
        $parts = [self::PREFIX_BALANCE_GENERALE];

        if (!empty($params['date_debut'])) {
            $parts[] = 'debut_' . $params['date_debut'];
        }
        if (!empty($params['date_fin'])) {
            $parts[] = 'fin_' . $params['date_fin'];
        }
        if (!empty($params['exercice_id'])) {
            $parts[] = 'ex_' . $params['exercice_id'];
        }
        if (!empty($params['classe'])) {
            $parts[] = 'classe_' . $params['classe'];
        }

        return implode('_', $parts);
    }

    /**
     * Construire la clé de cache pour la balance auxiliaire
     */
    private function buildBalanceAuxiliaireKey($params) {
        $parts = [self::PREFIX_BALANCE_AUXILIAIRE];

        if (!empty($params['date_debut'])) {
            $parts[] = 'debut_' . $params['date_debut'];
        }
        if (!empty($params['date_fin'])) {
            $parts[] = 'fin_' . $params['date_fin'];
        }
        if (!empty($params['exercice_id'])) {
            $parts[] = 'ex_' . $params['exercice_id'];
        }
        if (!empty($params['tiers_type'])) {
            $parts[] = 'type_' . $params['tiers_type'];
        }

        return implode('_', $parts);
    }

    /**
     * Construire la clé de cache pour le grand-livre
     */
    private function buildGrandLivreKey($compte, $params) {
        if ($compte !== null) {
            $parts = [self::PREFIX_GRAND_LIVRE_COMPTE . $compte];
        } else {
            $parts = [self::PREFIX_GRAND_LIVRE];
        }

        if (!empty($params['date_debut'])) {
            $parts[] = 'debut_' . $params['date_debut'];
        }
        if (!empty($params['date_fin'])) {
            $parts[] = 'fin_' . $params['date_fin'];
        }
        if (!empty($params['exercice_id'])) {
            $parts[] = 'ex_' . $params['exercice_id'];
        }

        return implode('_', $parts);
    }

    /**
     * Calculer la balance générale depuis la base de données
     */
    private function calculateBalanceGenerale($db, $params) {
        $sql = "SELECT * FROM v_balance_generale WHERE 1=1";
        $sqlParams = [];

        if (!empty($params['classe'])) {
            $sql .= " AND classe = ?";
            $sqlParams[] = $params['classe'];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($sqlParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calculer la balance auxiliaire depuis la base de données
     */
    private function calculateBalanceAuxiliaire($db, $params) {
        $sql = "SELECT * FROM v_balance_auxiliaire WHERE 1=1";
        $sqlParams = [];

        if (!empty($params['tiers_type'])) {
            $sql .= " AND type_tiers = ?";
            $sqlParams[] = $params['tiers_type'];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($sqlParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calculer le grand-livre depuis la base de données
     */
    private function calculateGrandLivre($db, $compte, $params) {
        if ($compte !== null) {
            // Grand-livre d'un compte spécifique
            $sql = "
                SELECT le.*, e.date_ecriture, e.journal, e.libelle AS libelle_ecriture,
                       pc.intitule_compte, t.nom AS nom_tiers
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                LEFT JOIN plan_comptable pc ON le.compte = pc.compte
                LEFT JOIN tiers t ON le.id_tiers = t.id
                WHERE le.compte = ? AND e.statut = 'Validé'
                ORDER BY e.date_ecriture, e.id
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$compte]);
        } else {
            // Grand-livre résumé (tous comptes)
            $sql = "SELECT * FROM v_grand_livre_resume";
            $stmt = $db->query($sql);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Logger les événements de cache
     */
    private function log($message) {
        $logFile = __DIR__ . '/../logs/cache.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";

        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
?>
