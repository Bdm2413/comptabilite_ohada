<?php
require_once '../config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>Ajout de nouveaux patterns d'intentions</h2>";
    echo "<style>
        body { font-family: Arial; padding: 20px; }
        .success { color: green; background: #e8f5e9; padding: 10px; margin: 10px 0; }
        .error { color: red; background: #ffebee; padding: 10px; margin: 10px 0; }
    </style>";

    // Nouveaux patterns plus variés et naturels
    $newPatterns = [
        // CA - Plus de variantes
        ['KPI_CA', 'combien.*gagné|revenus|encaissements', 'Variantes CA', 10],
        ['KPI_CA', 'vente.*mois|vente.*janvier|vente.*février', 'CA par mois spécifique', 10],

        // Charges - Plus de variantes
        ['KPI_CHARGES', 'combien.*dépensé|sorties|décaissements', 'Variantes charges', 10],
        ['KPI_CHARGES', 'achat.*mois|frais.*mois', 'Charges par mois', 10],

        // Trésorerie - Plus naturel
        ['KPI_TRESORERIE', 'combien.*banque|solde.*compte|argent.*disponible', 'Variantes trésorerie', 10],
        ['KPI_TRESORERIE', 'puis-je.*payer|ai-je.*argent', 'Questions sur disponibilité', 9],

        // Résultat
        ['KPI_RESULTAT', 'gagné.*mois|perdu.*mois', 'Résultat naturel', 10],
        ['KPI_RESULTAT', 'profitable|rentable|bénéficiaire', 'Rentabilité', 9],

        // Impayés - Plus de variantes
        ['SEARCH_IMPAYES', 'créances|clients.*payé|retard.*paiement', 'Variantes impayés', 9],
        ['SEARCH_IMPAYES', 'recouvrement|relance.*faire', 'Gestion impayés', 9],

        // Questions générales
        ['HELP', 'que.*peux.*faire|que.*sais.*faire|commencer', 'Aide générale', 3],
        ['HELP', 'bonjour|salut|hello|hey', 'Salutations', 2],

        // Questions sur état général
        ['ANALYZE_EVOLUTION', 'comment.*va|santé.*entreprise|situation.*financière', 'État général', 7],

        // Reconnaissance de périodes spécifiques
        ['KPI_CA', 'janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre', 'Mois spécifiques', 5],
    ];

    $stmt = $db->prepare("
        INSERT INTO ai_intent_patterns (intent, pattern, description, priority)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            description = VALUES(description),
            priority = VALUES(priority)
    ");

    $added = 0;
    foreach ($newPatterns as $pattern) {
        try {
            $stmt->execute($pattern);
            $added++;
            echo "<div class='success'>✓ Pattern ajouté : {$pattern[2]}</div>";
        } catch (PDOException $e) {
            echo "<div class='error'>✗ Erreur: {$e->getMessage()}</div>";
        }
    }

    echo "<h3>✅ {$added} nouveau(x) pattern(s) ajouté(s) !</h3>";
    echo "<p><a href='../pages/dashboard/'>→ Tester l'assistant amélioré</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>Erreur: {$e->getMessage()}</div>";
}
?>
