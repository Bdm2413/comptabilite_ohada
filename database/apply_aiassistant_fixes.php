<?php
require_once '../config/config.php';

echo "<h2>Application des corrections à l'Assistant IA</h2>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { color: green; background: #e8f5e9; padding: 10px; margin: 10px 0; border-radius: 5px; }
    .error { color: red; background: #ffebee; padding: 10px; margin: 10px 0; border-radius: 5px; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 10px 0; border-radius: 5px; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
</style>";

try {
    $db = Database::getInstance()->getConnection();

    // 1. Mettre à jour les patterns
    echo "<h3>1. Mise à jour des patterns d'intentions</h3>";

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
        echo "<div class='success'>✓ Pattern mis à jour : {$update['intent']}</div>";
    }

    // 2. Afficher les requêtes SQL corrigées
    echo "<h3>2. Requêtes SQL corrigées (à appliquer manuellement)</h3>";

    echo "<div class='info'><strong>TRÉSORERIE (Caisse + Banques)</strong><br>";
    echo "Comptes : 57 (Caisse) + 521 (Banques)</div>";
    echo "<pre>
SELECT
    COALESCE(SUM(CASE
        WHEN le.sens = 'D' THEN le.montant
        ELSE -le.montant
    END), 0) as tresorerie
FROM lignes_ecriture le
JOIN ecritures e ON le.ecriture_id = e.id
WHERE e.statut = 'Validé'
  AND (le.compte LIKE '57%' OR le.compte LIKE '521%')
</pre>";

    echo "<div class='info'><strong>CHIFFRE D'AFFAIRES</strong><br>";
    echo "Comptes : Classe 7 (Produits)</div>";
    echo "<pre>
SELECT
    COALESCE(SUM(le.credit) - SUM(le.debit), 0) as ca
FROM lignes_ecriture le
JOIN ecritures e ON le.ecriture_id = e.id
WHERE e.statut = 'Validé'
  AND le.compte LIKE '7%'
  AND MONTH(e.date_ecriture) = :month
  AND YEAR(e.date_ecriture) = :year
</pre>";

    echo "<div class='info'><strong>CHARGES</strong><br>";
    echo "Comptes : Classe 6 (Charges)</div>";
    echo "<pre>
SELECT
    COALESCE(SUM(le.debit) - SUM(le.credit), 0) as charges
FROM lignes_ecriture le
JOIN ecritures e ON le.ecriture_id = e.id
WHERE e.statut = 'Validé'
  AND le.compte LIKE '6%'
  AND MONTH(e.date_ecriture) = :month
  AND YEAR(e.date_ecriture) = :year
</pre>";

    echo "<div class='info'><strong>RÉSULTAT NET</strong><br>";
    echo "Formule : Produits (Classe 7) - Charges (Classe 6)</div>";
    echo "<pre>
SELECT
    COALESCE(
        (SELECT SUM(le.credit) - SUM(le.debit)
         FROM lignes_ecriture le
         JOIN ecritures e ON le.ecriture_id = e.id
         WHERE e.statut = 'Validé'
           AND le.compte LIKE '7%'
           AND MONTH(e.date_ecriture) = :month
           AND YEAR(e.date_ecriture) = :year)
        -
        (SELECT SUM(le.debit) - SUM(le.credit)
         FROM lignes_ecriture le
         JOIN ecritures e ON le.ecriture_id = e.id
         WHERE e.statut = 'Validé'
           AND le.compte LIKE '6%'
           AND MONTH(e.date_ecriture) = :month
           AND YEAR(e.date_ecriture) = :year)
    , 0) as resultat
</pre>";

    // 3. Créer le fichier de remplacement
    echo "<h3>3. Fichier de correction créé</h3>";
    echo "<div class='success'>
        ✓ Les patterns ont été mis à jour dans la base de données.<br><br>
        <strong>Prochaine étape :</strong><br>
        Je vais maintenant corriger le fichier AIAssistant.php avec les bonnes requêtes SQL.
    </div>";

    echo "<p><a href='test_fixed_queries.php'>→ Tester les nouvelles requêtes</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>Erreur : {$e->getMessage()}</div>";
}
?>
