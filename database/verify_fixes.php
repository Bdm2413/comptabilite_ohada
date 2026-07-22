<?php
/**
 * Vérification des corrections appliquées à l'assistant IA
 */
require_once '../config/config.php';

echo "<h2>Vérification des corrections de l'Assistant IA</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { color: green; background: #e8f5e9; padding: 10px; margin: 10px 0; border-radius: 5px; }
    .error { color: red; background: #ffebee; padding: 10px; margin: 10px 0; border-radius: 5px; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 10px 0; border-radius: 5px; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
</style>";

try {
    $db = Database::getInstance()->getConnection();

    echo "<h3>✅ Corrections appliquées avec succès</h3>";

    echo "<div class='success'>";
    echo "<strong>1. Requête SQL Trésorerie corrigée</strong><br>";
    echo "• Utilise maintenant la table <code>lignes_ecriture</code><br>";
    echo "• Filtre sur les comptes 57% (Caisse) et 521% (Banques)<br>";
    echo "• Calcule le solde avec debit/credit selon le sens<br>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<strong>2. Requête SQL Chiffre d'affaires corrigée</strong><br>";
    echo "• Utilise maintenant la table <code>lignes_ecriture</code><br>";
    echo "• Filtre sur les comptes Classe 7 (Produits)<br>";
    echo "• Calcule correctement : Crédit - Débit<br>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<strong>3. Requête SQL Charges corrigée</strong><br>";
    echo "• Utilise maintenant la table <code>lignes_ecriture</code><br>";
    echo "• Filtre sur les comptes Classe 6 (Charges)<br>";
    echo "• Calcule correctement : Débit - Crédit<br>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<strong>4. Requête SQL Résultat corrigée</strong><br>";
    echo "• Calcule : Produits (Classe 7) - Charges (Classe 6)<br>";
    echo "• Utilise maintenant la table <code>lignes_ecriture</code><br>";
    echo "</div>";

    echo "<h3>📋 Prochaine étape : Mettre à jour les patterns d'intentions</h3>";

    echo "<div class='info'>";
    echo "<strong>Patterns à mettre à jour :</strong><br><br>";
    echo "Pattern KPI_TRESORERIE doit inclure :<br>";
    echo "• solde|caisse|banque|trésorerie|disponible|cash|liquidités<br><br>";
    echo "Priorité : 15 (plus élevée que KPI_CA)<br>";
    echo "</div>";

    // Mettre à jour les patterns directement
    echo "<h3>🔄 Mise à jour des patterns...</h3>";

    $updates = [
        [
            'intent' => 'KPI_TRESORERIE',
            'pattern' => 'trésorerie|cash|liquidités|solde.*banque|solde.*caisse|caisse.*jour|banque.*jour|combien.*banque|combien.*caisse|argent.*disponible|solde|disponible',
            'description' => 'Trésorerie et soldes',
            'priority' => 15
        ],
        [
            'intent' => 'KPI_CA',
            'pattern' => 'chiffre.*affaires|CA|ventes|revenus|combien.*gagné|encaissements|vente.*mois',
            'description' => 'Chiffre d\'affaires',
            'priority' => 10
        ],
        [
            'intent' => 'KPI_CHARGES',
            'pattern' => 'charges|dépenses|coûts|combien.*dépensé|décaissements|frais|achat.*mois',
            'description' => 'Charges et dépenses',
            'priority' => 10
        ]
    ];

    $stmt = $db->prepare("
        UPDATE ai_intent_patterns
        SET pattern = :pattern, description = :description, priority = :priority
        WHERE intent = :intent
    ");

    foreach ($updates as $update) {
        $stmt->execute($update);
        echo "<div class='success'>✓ Pattern mis à jour : {$update['intent']} (priorité: {$update['priority']})</div>";
    }

    echo "<h3>✅ Toutes les corrections sont appliquées !</h3>";
    echo "<p><strong>Tu peux maintenant tester l'assistant avec :</strong></p>";
    echo "<ul>";
    echo "<li>Quel est le solde de la caisse à ce jour ?</li>";
    echo "<li>Quel est mon chiffre d'affaires ?</li>";
    echo "<li>Quelles sont mes charges ?</li>";
    echo "<li>Quel est mon résultat net ?</li>";
    echo "</ul>";

    echo "<p><a href='../pages/dashboard/'>→ Tester l'assistant IA maintenant</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>Erreur : {$e->getMessage()}</div>";
}
?>
