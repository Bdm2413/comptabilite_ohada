<?php
require_once '../config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Création des tables de l'Assistant IA</h2>";
    echo "<style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { color: green; padding: 10px; background: #e8f5e9; margin: 10px 0; border-radius: 5px; }
        .error { color: red; padding: 10px; background: #ffebee; margin: 10px 0; border-radius: 5px; }
        .info { color: blue; padding: 10px; background: #e3f2fd; margin: 10px 0; border-radius: 5px; }
    </style>";

    // Table 1: ai_conversations
    echo "<div class='info'><strong>Création de la table ai_conversations...</strong></div>";
    try {
        $sql1 = "CREATE TABLE IF NOT EXISTS ai_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            question TEXT NOT NULL,
            response TEXT NOT NULL,
            intent VARCHAR(50) DEFAULT NULL,
            sql_query TEXT DEFAULT NULL,
            execution_time_ms INT DEFAULT NULL,
            context JSON DEFAULT NULL,
            rating TINYINT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_session (user_id, session_id),
            INDEX idx_user_date (user_id, created_at),
            INDEX idx_intent (intent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->exec($sql1);
        echo "<div class='success'>✓ Table ai_conversations créée avec succès</div>";
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Erreur: " . $e->getMessage() . "</div>";
    }

    // Table 2: ai_intent_patterns
    echo "<div class='info'><strong>Création de la table ai_intent_patterns...</strong></div>";
    try {
        $sql2 = "CREATE TABLE IF NOT EXISTS ai_intent_patterns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            intent VARCHAR(50) NOT NULL,
            pattern VARCHAR(255) NOT NULL,
            description TEXT,
            sql_template TEXT,
            priority INT DEFAULT 0,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intent (intent),
            INDEX idx_active_priority (active, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->exec($sql2);
        echo "<div class='success'>✓ Table ai_intent_patterns créée avec succès</div>";
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Erreur: " . $e->getMessage() . "</div>";
    }

    // Table 3: ai_response_cache
    echo "<div class='info'><strong>Création de la table ai_response_cache...</strong></div>";
    try {
        $sql3 = "CREATE TABLE IF NOT EXISTS ai_response_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question_hash VARCHAR(64) NOT NULL UNIQUE,
            question TEXT NOT NULL,
            response TEXT NOT NULL,
            hit_count INT DEFAULT 1,
            last_hit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            INDEX idx_hash (question_hash),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->exec($sql3);
        echo "<div class='success'>✓ Table ai_response_cache créée avec succès</div>";
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Erreur: " . $e->getMessage() . "</div>";
    }

    // Insertion des patterns
    echo "<div class='info'><strong>Insertion des patterns d'intentions...</strong></div>";
    try {
        $patterns = [
            ['KPI_CA', 'chiffre.*affaires|CA|ventes|revenus', 'Demande de chiffre d\'affaires', 10],
            ['KPI_CHARGES', 'charges|dépenses|coûts', 'Demande sur les charges', 10],
            ['KPI_TRESORERIE', 'trésorerie|cash|liquidités|solde banque', 'Demande de trésorerie', 10],
            ['KPI_RESULTAT', 'résultat|bénéfice|profit|perte', 'Demande de résultat net', 10],
            ['SEARCH_CLIENT', 'client|clients', 'Recherche de clients', 8],
            ['SEARCH_FOURNISSEUR', 'fournisseur|fournisseurs', 'Recherche de fournisseurs', 8],
            ['SEARCH_ECRITURE', 'écriture|écritures|transaction', 'Recherche d\'écritures', 7],
            ['SEARCH_IMPAYES', 'impayé|impayés|facture.*payé', 'Recherche de factures impayées', 9],
            ['ANALYZE_EVOLUTION', 'évolution|tendance|comparaison', 'Analyse d\'évolution', 6],
            ['ANALYZE_POURQUOI', 'pourquoi|expliquer|raison', 'Demande d\'explication', 6],
            ['CREATE_ECRITURE', 'créer.*écriture|nouvelle.*écriture|ajouter.*écriture', 'Création d\'écriture', 5],
            ['HELP', 'aide|help|commande|que.*faire', 'Demande d\'aide', 3]
        ];

        $stmt = $db->prepare("INSERT INTO ai_intent_patterns (intent, pattern, description, priority) VALUES (?, ?, ?, ?)");

        $count = 0;
        foreach ($patterns as $p) {
            try {
                $stmt->execute($p);
                $count++;
            } catch (PDOException $e) {
                // Ignorer les doublons
            }
        }

        echo "<div class='success'>✓ {$count} pattern(s) inséré(s) avec succès</div>";
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Erreur: " . $e->getMessage() . "</div>";
    }

    echo "<h3>✅ Migration terminée !</h3>";
    echo "<p><a href='check_ai_tables.php'>→ Vérifier les tables</a></p>";
    echo "<p><a href='../pages/dashboard/'>→ Tester l'assistant IA</a></p>";

} catch (Exception $e) {
    echo "<div class='error'><strong>Erreur fatale:</strong> " . $e->getMessage() . "</div>";
}
?>
