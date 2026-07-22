<?php
/**
 * Script de correction du calcul du résultat N-1
 *
 * Problème identifié:
 * - Le bilan recalcule CJ pour N-1 à partir des classes 6,7,8 de la période N-1
 * - Mais cela ignore le compte 13 (Résultat net) qui existe déjà dans la balance
 * - Cela crée un double comptage ou une incohérence
 *
 * Solution:
 * - Pour N-1, utiliser directement le solde du compte 13x de la balance
 * - Ne PAS recalculer à partir des classes 6,7,8 pour N-1
 */

echo "🔧 Script de correction du résultat N-1\n";
echo "========================================\n\n";

$bilan_file = 'pages/etats_financiers/bilan.php';

if (!file_exists($bilan_file)) {
    die("❌ Fichier $bilan_file introuvable\n");
}

$content = file_get_contents($bilan_file);
$original_content = $content;
$modifications = 0;

echo "Problème:\n";
echo "---------\n";
echo "- Résultat N-1 affiché dans le bilan: -585,112,802.56 FCFA\n";
echo "- Résultat N-1 attendu (d'après PDF): -1,586,846,772.89 FCFA\n";
echo "- Différence balance (Débit - Crédit): -1,586,846,772.89 FCFA\n";
echo "- Conclusion: La différence de balance = résultat N-1!\n\n";

echo "Solution:\n";
echo "---------\n";
echo "Pour N-1, ne PAS recalculer le résultat à partir des classes 6,7,8.\n";
echo "Utiliser directement le compte 13 (Résultat net) de la balance.\n\n";

echo "1️⃣ Recherche du code actuel pour CJ N-1...\n";

// Chercher la section qui calcule CJ pour N-1
$pattern_cj_n1 = '/\/\/ CJ \(Résultat net de l\'exercice N-1\) = Produits - Charges de la période N-1.*?\$passif_n1\[\'CJ\'\]\[\'net\'\] = \$stmt_resultat_n1->fetchColumn\(\);/s';

if (preg_match($pattern_cj_n1, $content, $matches)) {
    echo "   ✓ Trouvé le code de calcul de CJ N-1\n";
    echo "   Code actuel:\n";
    echo "   " . str_replace("\n", "\n   ", trim($matches[0])) . "\n\n";

    // Nouveau code: utiliser le compte 13 de la balance
    $nouveau_code = "// CJ (Résultat net N-1) = Solde du compte 13x dans la balance de clôture N-1
    // On ne recalcule PAS à partir des classes 6,7,8 car le compte 13 existe déjà
    \$sql_compte_13_n1 = \"
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_n1
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '13'
        AND e.date_ecriture <= ?
        AND e.statut = 'Validé'
    \";
    \$stmt_resultat_n1 = \$db->prepare(\$sql_compte_13_n1);
    \$stmt_resultat_n1->execute([\$date_fin_n1]);
    \$passif_n1['CJ']['net'] = \$stmt_resultat_n1->fetchColumn();";

    $content = preg_replace($pattern_cj_n1, $nouveau_code, $content);
    $modifications++;
    echo "   ✓ Code modifié pour utiliser le compte 13 au lieu de recalculer\n\n";
} else {
    echo "   ⚠ Pattern non trouvé, recherche manuelle...\n\n";

    // Alternative: chercher ligne par ligne
    if (strpos($content, '$stmt_resultat_n1->execute([$date_debut_n1, $date_fin_n1]);') !== false) {
        echo "   Trouvé la ligne d'exécution du statement N-1\n";
        echo "   Modification manuelle nécessaire\n";
    }
}

// Sauvegarder les modifications
if ($modifications > 0) {
    // Créer une sauvegarde
    $backup_file = $bilan_file . '.backup_resultat_n1.' . date('Y-m-d_H-i-s');
    file_put_contents($backup_file, $original_content);
    echo "📦 Sauvegarde créée: $backup_file\n";

    // Sauvegarder le fichier modifié
    file_put_contents($bilan_file, $content);
    echo "✅ Fichier $bilan_file mis à jour avec $modifications modification(s)\n";
} else {
    echo "⚠ Aucune modification automatique possible\n";
    echo "Modification manuelle nécessaire dans bilan.php\n";
}

echo "\n========================================\n";
echo "✨ Script terminé!\n\n";
echo "Prochaines étapes:\n";
echo "1. Vérifier le diagnostic: http://localhost/comptabilite_ohada/diagnostic_balance_desequilibre.php\n";
echo "2. Actualiser le bilan\n";
echo "3. Vérifier que CJ N-1 = -1,586,846,772.89 FCFA\n";
echo "4. Vérifier que Total Actif N-1 = Total Passif N-1\n";
