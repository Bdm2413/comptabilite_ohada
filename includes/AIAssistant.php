<?php
/**
 * Assistant IA Conversationnel pour ComptaSYSCOHADA
 *
 * Cette classe gère:
 * - Détection d'intentions (NLU)
 * - Génération de requêtes SQL
 * - Intégration avec Claude API
 * - Cache des réponses
 * - Historique des conversations
 */

class AIAssistant
{
    private $db;
    private $userId;
    private $sessionId;
    private $claudeApiKey;
    private $useCache = true;
    private $cacheExpiration = 3600; // 1 heure

    // Intentions supportées
    const INTENT_KPI_CA = 'KPI_CA';
    const INTENT_KPI_CHARGES = 'KPI_CHARGES';
    const INTENT_KPI_TRESORERIE = 'KPI_TRESORERIE';
    const INTENT_KPI_RESULTAT = 'KPI_RESULTAT';
    const INTENT_SEARCH_CLIENT = 'SEARCH_CLIENT';
    const INTENT_SEARCH_FOURNISSEUR = 'SEARCH_FOURNISSEUR';
    const INTENT_SEARCH_ECRITURE = 'SEARCH_ECRITURE';
    const INTENT_SEARCH_IMPAYES = 'SEARCH_IMPAYES';
    const INTENT_ANALYZE_EVOLUTION = 'ANALYZE_EVOLUTION';
    const INTENT_ANALYZE_POURQUOI = 'ANALYZE_POURQUOI';
    const INTENT_CREATE_ECRITURE = 'CREATE_ECRITURE';
    const INTENT_HELP = 'HELP';
    const INTENT_UNKNOWN = 'UNKNOWN';

    public function __construct($userId)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->userId = $userId;
        $this->sessionId = $this->generateSessionId();
        $this->claudeApiKey = $_ENV['CLAUDE_API_KEY'] ?? null;
    }

    /**
     * Point d'entrée principal: traiter une question utilisateur
     */
    public function processQuestion($question)
    {
        $startTime = microtime(true);

        try {
            // 1. Vérifier le cache
            if ($this->useCache) {
                $cachedResponse = $this->getCachedResponse($question);
                if ($cachedResponse) {
                    $this->saveConversation($question, $cachedResponse, null, null, 0);
                    return [
                        'success' => true,
                        'response' => $cachedResponse,
                        'cached' => true,
                        'execution_time_ms' => 0
                    ];
                }
            }

            // 2. Détecter l'intention
            $intent = $this->detectIntent($question);

            // 3. Traiter selon l'intention
            $result = $this->handleIntent($intent, $question);

            // 4. Générer la réponse
            $response = $this->generateResponse($question, $intent, $result);

            // 5. Sauvegarder dans l'historique
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->saveConversation(
                $question,
                $response,
                $intent,
                $result['sql'] ?? null,
                (int)$executionTime
            );

            // 6. Mettre en cache
            if ($this->useCache) {
                $this->cacheResponse($question, $response);
            }

            return [
                'success' => true,
                'response' => $response,
                'intent' => $intent,
                'data' => $result['data'] ?? null,
                'cached' => false,
                'execution_time_ms' => (int)$executionTime
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => (int)((microtime(true) - $startTime) * 1000)
            ];
        }
    }

    /**
     * Détection d'intention via patterns
     */
    private function detectIntent($question)
    {
        $questionLower = mb_strtolower($question, 'UTF-8');

        // Charger les patterns depuis la base
        $stmt = $this->db->prepare("
            SELECT intent, pattern, priority
            FROM ai_intent_patterns
            WHERE active = 1
            ORDER BY priority DESC
        ");
        $stmt->execute();
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tester chaque pattern
        foreach ($patterns as $p) {
            if (preg_match('/' . $p['pattern'] . '/iu', $questionLower)) {
                return $p['intent'];
            }
        }

        return self::INTENT_UNKNOWN;
    }

    /**
     * Traiter l'intention détectée
     */
    private function handleIntent($intent, $question)
    {
        switch ($intent) {
            case self::INTENT_KPI_CA:
                return $this->getChiffreAffaires($question);

            case self::INTENT_KPI_CHARGES:
                return $this->getCharges($question);

            case self::INTENT_KPI_TRESORERIE:
                return $this->getTresorerie($question);

            case self::INTENT_KPI_RESULTAT:
                return $this->getResultat($question);

            case self::INTENT_SEARCH_IMPAYES:
                return $this->getImpayes($question);

            case self::INTENT_SEARCH_CLIENT:
                return $this->searchClients($question);

            case self::INTENT_SEARCH_FOURNISSEUR:
                return $this->searchFournisseurs($question);

            case self::INTENT_HELP:
                return $this->getHelp();

            default:
                return ['data' => null, 'sql' => null];
        }
    }

    /**
     * Chiffre d'affaires (OHADA: Classe 7 - Produits)
     */
    private function getChiffreAffaires($question)
    {
        // Détecter la période (mois en cours par défaut)
        $period = $this->extractPeriod($question);

        $sql = "
            SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
            FROM lignes_ecriture le
            JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.statut = 'Validé'
              AND le.compte LIKE '7%'
              AND MONTH(e.date_ecriture) = :month
              AND YEAR(e.date_ecriture) = :year
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':month' => $period['month'],
            ':year' => $period['year']
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql,
            'period' => $period
        ];
    }

    /**
     * Charges (OHADA: Classe 6)
     */
    private function getCharges($question)
    {
        $period = $this->extractPeriod($question);

        $sql = "
            SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as charges
            FROM lignes_ecriture le
            JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.statut = 'Validé'
              AND le.compte LIKE '6%'
              AND MONTH(e.date_ecriture) = :month
              AND YEAR(e.date_ecriture) = :year
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':month' => $period['month'],
            ':year' => $period['year']
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql,
            'period' => $period
        ];
    }

    /**
     * Trésorerie (OHADA: 57 = Caisse + 521 = Banques)
     * Comptes d'actif : Débit augmente, Crédit diminue
     */
    private function getTresorerie($question)
    {
        $sql = "
            SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as tresorerie
            FROM lignes_ecriture le
            JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.statut = 'Validé'
              AND (le.compte LIKE '57%' OR le.compte LIKE '521%')
        ";

        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql
        ];
    }

    /**
     * Résultat net (OHADA: Produits - Charges)
     */
    private function getResultat($question)
    {
        $period = $this->extractPeriod($question);

        $sql = "
            SELECT COALESCE(
                (SELECT SUM(le.credit) - SUM(le.debit)
                 FROM lignes_ecriture le
                 JOIN ecritures e ON le.id_ecriture = e.id
                 WHERE e.statut = 'Validé'
                   AND le.compte LIKE '7%'
                   AND MONTH(e.date_ecriture) = :month
                   AND YEAR(e.date_ecriture) = :year)
                -
                (SELECT SUM(le.debit) - SUM(le.credit)
                 FROM lignes_ecriture le
                 JOIN ecritures e ON le.id_ecriture = e.id
                 WHERE e.statut = 'Validé'
                   AND le.compte LIKE '6%'
                   AND MONTH(e.date_ecriture) = :month2
                   AND YEAR(e.date_ecriture) = :year2)
            , 0) as resultat
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':month' => $period['month'],
            ':year' => $period['year'],
            ':month2' => $period['month'],
            ':year2' => $period['year']
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql,
            'period' => $period
        ];
    }

    /**
     * Factures impayées
     */
    private function getImpayes($question)
    {
        $sql = "
            SELECT COUNT(*) as nb_impayes, SUM(montant_total) as total_impayes
            FROM ecritures
            WHERE statut = 'Brouillon'
        ";

        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql
        ];
    }

    /**
     * Recherche clients
     */
    private function searchClients($question)
    {
        $sql = "
            SELECT nom, email, telephone
            FROM plan_tiers
            WHERE type = 'Client' AND actif = 1
            LIMIT 10
        ";

        $stmt = $this->db->query($sql);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql
        ];
    }

    /**
     * Recherche fournisseurs
     */
    private function searchFournisseurs($question)
    {
        $sql = "
            SELECT nom, email, telephone
            FROM plan_tiers
            WHERE type = 'Fournisseur' AND actif = 1
            LIMIT 10
        ";

        $stmt = $this->db->query($sql);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $result,
            'sql' => $sql
        ];
    }

    /**
     * Aide
     */
    private function getHelp()
    {
        return [
            'data' => [
                'commands' => [
                    'Chiffre d\'affaires du mois',
                    'Charges du mois',
                    'Trésorerie actuelle',
                    'Factures impayées',
                    'Liste des clients',
                    'Liste des fournisseurs'
                ]
            ],
            'sql' => null
        ];
    }

    /**
     * Générer la réponse finale (avec ou sans Claude API)
     */
    private function generateResponse($question, $intent, $result)
    {
        // Si Claude API est disponible, utiliser l'IA
        if ($this->claudeApiKey && $intent !== self::INTENT_UNKNOWN) {
            return $this->generateAIResponse($question, $intent, $result);
        }

        // Sinon, réponse basique
        return $this->generateBasicResponse($intent, $result);
    }

    /**
     * Réponse professionnelle et dynamique
     */
    private function generateBasicResponse($intent, $result)
    {
        $data = $result['data'] ?? [];
        $period = $result['period'] ?? null;

        switch ($intent) {
            case self::INTENT_KPI_CA:
                return $this->formatCAResponse($data, $period);

            case self::INTENT_KPI_CHARGES:
                return $this->formatChargesResponse($data, $period);

            case self::INTENT_KPI_TRESORERIE:
                return $this->formatTresorerieResponse($data);

            case self::INTENT_KPI_RESULTAT:
                return $this->formatResultatResponse($data, $period);

            case self::INTENT_SEARCH_IMPAYES:
                return $this->formatImpayesResponse($data);

            case self::INTENT_SEARCH_CLIENT:
                return $this->formatClientsResponse($data);

            case self::INTENT_SEARCH_FOURNISSEUR:
                return $this->formatFournisseursResponse($data);

            case self::INTENT_HELP:
                return $this->formatHelpResponse();

            default:
                return $this->formatUnknownResponse();
        }
    }

    /**
     * Formatter les réponses CA
     */
    private function formatCAResponse($data, $period)
    {
        $ca = $data['ca'] ?? 0;
        $caFormatted = number_format($ca, 0, ',', ' ');
        $monthName = $this->getMonthName($period['month']);

        if ($ca == 0) {
            return "⚠️ Aucun chiffre d'affaires enregistré pour {$monthName} {$period['year']}.\n\n" .
                   "💡 Conseil : Vérifiez que vos écritures de ventes sont bien validées.";
        }

        // Calculer le CA du mois précédent pour comparaison
        $prevMonth = $period['month'] - 1;
        $prevYear = $period['year'];
        if ($prevMonth == 0) {
            $prevMonth = 12;
            $prevYear--;
        }

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca_prev
            FROM lignes_ecriture le
            JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.statut = 'Validé'
              AND le.compte LIKE '7%'
              AND MONTH(e.date_ecriture) = :month
              AND YEAR(e.date_ecriture) = :year
        ");
        $stmt->execute([':month' => $prevMonth, ':year' => $prevYear]);
        $caPrev = $stmt->fetch(PDO::FETCH_ASSOC)['ca_prev'] ?? 0;

        $evolution = "";
        if ($caPrev > 0) {
            $diff = (($ca - $caPrev) / $caPrev) * 100;
            $diffFormatted = number_format(abs($diff), 1);
            $prevMonthName = $this->getMonthName($prevMonth);

            if ($diff > 0) {
                $evolution = "\n\n📈 +{$diffFormatted}% par rapport à {$prevMonthName} {$prevYear}";
            } elseif ($diff < 0) {
                $evolution = "\n\n📉 -{$diffFormatted}% par rapport à {$prevMonthName} {$prevYear}";
            }
        }

        return "💰 Chiffre d'affaires - {$monthName} {$period['year']}\n\n" .
               "Montant : {$caFormatted} FCFA{$evolution}";
    }

    /**
     * Formatter les réponses Charges
     */
    private function formatChargesResponse($data, $period)
    {
        $charges = $data['charges'] ?? 0;
        $chargesFormatted = number_format($charges, 0, ',', ' ');
        $monthName = $this->getMonthName($period['month']);

        if ($charges == 0) {
            return "⚠️ Aucune charge enregistrée pour {$monthName} {$period['year']}.";
        }

        return "💸 Charges - {$monthName} {$period['year']}\n\n" .
               "Montant : {$chargesFormatted} FCFA\n\n" .
               "💡 Astuce : Tapez 'détail charges' pour voir la répartition par catégorie.";
    }

    /**
     * Formatter les réponses Trésorerie
     */
    private function formatTresorerieResponse($data)
    {
        $tresorerie = $data['tresorerie'] ?? 0;
        $tresorerieFormatted = number_format($tresorerie, 0, ',', ' ');

        $icon = $tresorerie > 0 ? '✅' : '⚠️';
        $status = $tresorerie > 0 ? 'positive' : 'attention requise';

        return "{$icon} Trésorerie actuelle\n\n" .
               "Solde : {$tresorerieFormatted} FCFA\n" .
               "Statut : {$status}\n\n" .
               ($tresorerie < 0 ? "⚠️ Votre trésorerie est négative. Vérifiez vos encaissements." : "");
    }

    /**
     * Formatter les réponses Résultat
     */
    private function formatResultatResponse($data, $period)
    {
        $resultat = $data['resultat'] ?? 0;
        $resultatFormatted = number_format($resultat, 0, ',', ' ');
        $monthName = $this->getMonthName($period['month']);

        $icon = $resultat > 0 ? '📊' : '📉';
        $type = $resultat > 0 ? 'Bénéfice' : 'Perte';

        return "{$icon} Résultat net - {$monthName} {$period['year']}\n\n" .
               "{$type} : {$resultatFormatted} FCFA";
    }

    /**
     * Formatter les réponses Impayés
     */
    private function formatImpayesResponse($data)
    {
        $nb = $data['nb_impayes'] ?? 0;
        $total = $data['total_impayes'] ?? 0;
        $totalFormatted = number_format($total, 0, ',', ' ');

        if ($nb == 0) {
            return "✅ Excellent ! Aucune facture impayée.\n\n" .
                   "Toutes vos créances sont à jour.";
        }

        return "⚠️ Factures impayées\n\n" .
               "Nombre : {$nb} facture(s)\n" .
               "Montant total : {$totalFormatted} FCFA\n\n" .
               "💡 Conseil : Effectuez des relances régulières pour réduire les délais de paiement.";
    }

    /**
     * Formatter les réponses Clients
     */
    private function formatClientsResponse($data)
    {
        $count = count($data);

        if ($count == 0) {
            return "📋 Aucun client enregistré dans votre base.";
        }

        $response = "👥 Liste de vos clients ({$count})\n\n";

        foreach (array_slice($data, 0, 5) as $client) {
            $response .= "• {$client['nom']}\n";
            if (!empty($client['email'])) {
                $response .= "  ✉️ {$client['email']}\n";
            }
            if (!empty($client['telephone'])) {
                $response .= "  📞 {$client['telephone']}\n";
            }
            $response .= "\n";
        }

        if ($count > 5) {
            $response .= "... et " . ($count - 5) . " autre(s) client(s)";
        }

        return $response;
    }

    /**
     * Formatter les réponses Fournisseurs
     */
    private function formatFournisseursResponse($data)
    {
        $count = count($data);

        if ($count == 0) {
            return "📋 Aucun fournisseur enregistré dans votre base.";
        }

        $response = "🏢 Liste de vos fournisseurs ({$count})\n\n";

        foreach (array_slice($data, 0, 5) as $fournisseur) {
            $response .= "• {$fournisseur['nom']}\n";
            if (!empty($fournisseur['email'])) {
                $response .= "  ✉️ {$fournisseur['email']}\n";
            }
            if (!empty($fournisseur['telephone'])) {
                $response .= "  📞 {$fournisseur['telephone']}\n";
            }
            $response .= "\n";
        }

        if ($count > 5) {
            $response .= "... et " . ($count - 5) . " autre(s) fournisseur(s)";
        }

        return $response;
    }

    /**
     * Formatter l'aide
     */
    private function formatHelpResponse()
    {
        return "👋 Je suis votre assistant comptable IA.\n\n" .
               "📊 Questions que vous pouvez me poser :\n\n" .
               "💰 Finances\n" .
               "• Quel est mon CA du mois ?\n" .
               "• Quelles sont mes charges ?\n" .
               "• Quelle est ma trésorerie ?\n" .
               "• Quel est mon résultat net ?\n\n" .
               "📋 Données\n" .
               "• Factures impayées\n" .
               "• Liste des clients\n" .
               "• Liste des fournisseurs\n\n" .
               "💡 Astuce : Je comprends aussi les variations comme 'CA janvier', 'charges février', etc.";
    }

    /**
     * Réponse pour question non comprise
     */
    private function formatUnknownResponse()
    {
        return "🤔 Désolé, je n'ai pas bien compris votre question.\n\n" .
               "💡 Essayez de reformuler ou tapez 'aide' pour voir ce que je peux faire.\n\n" .
               "Exemples :\n" .
               "• Quel est mon CA ?\n" .
               "• Montre-moi les impayés\n" .
               "• Liste des clients";
    }

    /**
     * Obtenir le nom du mois en français
     */
    private function getMonthName($monthNumber)
    {
        $months = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        return $months[$monthNumber] ?? 'Mois inconnu';
    }

    /**
     * Réponse avec Claude API (à implémenter)
     */
    private function generateAIResponse($question, $intent, $result)
    {
        // TODO: Intégrer avec Claude API
        // Pour l'instant, retourner la réponse basique
        return $this->generateBasicResponse($intent, $result);
    }

    /**
     * Extraire la période de la question (mois en cours par défaut)
     */
    private function extractPeriod($question)
    {
        $questionLower = mb_strtolower($question, 'UTF-8');

        // Mois spécifiques
        $months = [
            'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4,
            'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8,
            'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12
        ];

        foreach ($months as $monthName => $monthNum) {
            if (strpos($questionLower, $monthName) !== false) {
                return ['month' => $monthNum, 'year' => date('Y')];
            }
        }

        // Par défaut: mois en cours
        return ['month' => (int)date('m'), 'year' => (int)date('Y')];
    }

    /**
     * Générer un ID de session unique
     */
    private function generateSessionId()
    {
        return md5($this->userId . time() . rand());
    }

    /**
     * Sauvegarder la conversation
     */
    private function saveConversation($question, $response, $intent, $sql, $executionTime)
    {
        $stmt = $this->db->prepare("
            INSERT INTO ai_conversations
            (user_id, session_id, question, response, intent, sql_query, execution_time_ms)
            VALUES (:user_id, :session_id, :question, :response, :intent, :sql, :exec_time)
        ");

        $stmt->execute([
            ':user_id' => $this->userId,
            ':session_id' => $this->sessionId,
            ':question' => $question,
            ':response' => $response,
            ':intent' => $intent,
            ':sql' => $sql,
            ':exec_time' => $executionTime
        ]);
    }

    /**
     * Récupérer réponse du cache
     */
    private function getCachedResponse($question)
    {
        $hash = $this->normalizeQuestion($question);

        $stmt = $this->db->prepare("
            SELECT response
            FROM ai_response_cache
            WHERE question_hash = :hash
              AND (expires_at IS NULL OR expires_at > NOW())
        ");

        $stmt->execute([':hash' => $hash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // Incrémenter le compteur de hits
            $this->db->prepare("UPDATE ai_response_cache SET hit_count = hit_count + 1 WHERE question_hash = :hash")
                     ->execute([':hash' => $hash]);

            return $result['response'];
        }

        return null;
    }

    /**
     * Mettre en cache une réponse
     */
    private function cacheResponse($question, $response)
    {
        $hash = $this->normalizeQuestion($question);

        $stmt = $this->db->prepare("
            INSERT INTO ai_response_cache (question_hash, question, response, expires_at)
            VALUES (:hash, :question, :response, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
            ON DUPLICATE KEY UPDATE
                response = VALUES(response),
                hit_count = 1,
                expires_at = VALUES(expires_at)
        ");

        $stmt->execute([
            ':hash' => $hash,
            ':question' => $question,
            ':response' => $response,
            ':ttl' => $this->cacheExpiration
        ]);
    }

    /**
     * Normaliser une question pour le cache
     */
    private function normalizeQuestion($question)
    {
        $normalized = mb_strtolower(trim($question), 'UTF-8');
        $normalized = preg_replace('/[^\w\s]/u', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return md5($normalized);
    }

    /**
     * Récupérer l'historique des conversations
     */
    public function getHistory($limit = 10)
    {
        $stmt = $this->db->prepare("
            SELECT question, response, intent, created_at
            FROM ai_conversations
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
