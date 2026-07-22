<?php
/**
 * Assistant IA - Version 2 Améliorée
 * Corrections majeures :
 * 1. Requêtes SQL correctes selon plan comptable OHADA
 * 2. Meilleure détection d'intentions
 * 3. Validation des données
 */

// Pour utiliser cette version, renommez AIAssistant.php en AIAssistant_old.php
// et renommez ce fichier en AIAssistant.php

// Ajoutez ces patterns dans ai_intent_patterns :
/*
INSERT INTO ai_intent_patterns (intent, pattern, description, priority) VALUES
('KPI_TRESORERIE', 'caisse|banque.*solde|solde.*caisse|solde.*banque', 'Solde caisse/banque', 11),
('KPI_TRESORERIE', 'combien.*argent|disponible.*jour', 'Argent disponible', 11);
*/

echo "Fichier de référence pour corrections à appliquer.\n";
echo "Corrections nécessaires :\n\n";

echo "1. TRÉSORERIE - Requête SQL correcte :\n";
echo "
SELECT
    SUM(CASE WHEN le.sens = 'D' THEN le.montant ELSE -le.montant END) as tresorerie
FROM lignes_ecriture le
JOIN ecritures e ON le.ecriture_id = e.id
WHERE e.statut = 'Validé'
  AND (le.compte LIKE '57%' OR le.compte LIKE '521%')
";

echo "\n\n2. CHIFFRE D'AFFAIRES - Requête SQL correcte :\n";
echo "
SELECT
    SUM(le.credit) - SUM(le.debit) as ca
FROM lignes_ecriture le
JOIN ecritures e ON le.ecriture_id = e.id
WHERE e.statut = 'Validé'
  AND le.compte LIKE '7%'
  AND MONTH(e.date_ecriture) = :month
  AND YEAR(e.date_ecriture) = :year
";

echo "\n\n3. CHARGES - Requête SQL correcte :\n";
echo "
SELECT
    SUM(le.debit) - SUM(le.credit) as charges
FROM lignes_ecriture le
JOIN ecritures e ON le.ecriture_id = e.id
WHERE e.statut = 'Validé'
  AND le.compte LIKE '6%'
  AND MONTH(e.date_ecriture) = :month
  AND YEAR(e.date_ecriture) = :year
";

echo "\n\n4. Pattern à ajouter pour 'solde caisse' :\n";
echo "
UPDATE ai_intent_patterns
SET pattern = 'trésorerie|cash|liquidités|solde.*banque|solde.*caisse|caisse.*jour|banque.*jour'
WHERE intent = 'KPI_TRESORERIE';
";
?>
