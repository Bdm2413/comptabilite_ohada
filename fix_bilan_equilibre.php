<?php
/**
 * Script de correction de l'équilibre du bilan
 *
 * Problème identifié d'après le PDF:
 * - CJ (Résultat net) avec solde DÉBITEUR (perte) = 585,112,802.56
 * - Ce montant doit DIMINUER le passif, pas l'augmenter
 * - Actuellement: Passif = 24,843,717,500.97 (INCORRECT)
 * - Attendu: Passif = 23,256,870,728.08 (correct, égal à l'actif)
 * - Différence: 1,586,846,772.89 (exactement le double de CJ!)
 *
 * La cause: À la ligne 165 de bilan.php, on utilise abs($solde) pour les comptes passif
 * avec BD=BC, ce qui inverse le signe et fait que les pertes augmentent le passif au lieu
 * de le diminuer.
 */

echo "🔧 Script de correction de l'équilibre du bilan\n";
echo "================================================\n\n";

$bilan_file = 'pages/etats_financiers/bilan.php';

if (!file_exists($bilan_file)) {
    die("❌ Fichier $bilan_file introuvable\n");
}

$content = file_get_contents($bilan_file);
$original_content = $content;
$modifications = 0;

echo "Analyse du problème:\n";
echo "-------------------\n";
echo "D'après le PDF 'Montage bilan 2024 et 2025.pdf':\n";
echo "- CJ (ligne N/2025): Solde = 585,112,802.56 DÉBITEUR\n";
echo "- Ce solde débiteur signifie une PERTE\n";
echo "- Une perte doit RÉDUIRE les Capitaux Propres (CP)\n";
echo "- Donc CJ avec solde débiteur doit être SOUSTRAIT du passif\n\n";

echo "Valeurs attendues:\n";
echo "- Actif N: 23,256,870,728.08 FCFA\n";
echo "- Passif N: 23,256,870,728.08 FCFA (doit être égal)\n";
echo "- Différence actuelle: 1,586,846,772.89 FCFA\n\n";

// Correction 1: Ligne 165 - Pour les rubriques de passif avec BD=BC, respecter le signe du solde
echo "1️⃣ Correction de la ligne 165 (passif avec BD=BC)...\n";
echo "   Contexte: solde = debit - credit\n";
echo "   - Solde créditeur (négatif): doit AUGMENTER le passif → -(-1000) = +1000 ✓\n";
echo "   - Solde débiteur (positif): doit DIMINUER le passif → -(+1000) = -1000 ✓\n\n";

// Alternative: chercher juste la ligne exacte
$old_line = '                $passif[$ref][\'net\'] += abs($solde); // Inverse le signe: créditeur (négatif) devient positif au passif';
if (strpos($content, $old_line) !== false) {
    $new_line = '                $passif[$ref][\'net\'] -= $solde; // Solde créditeur (négatif) augmente, solde débiteur (positif) diminue';
    $content = str_replace($old_line, $new_line, $content);
    $modifications++;
    echo "   ✓ Ligne 165 corrigée: += abs(\$solde) → -= \$solde\n";
    echo "   Explication:\n";
    echo "     - Avant: abs() forçait toujours positif → perte augmentait le passif ✗\n";
    echo "     - Après: -\$solde respecte le signe → perte diminue le passif ✓\n";
} else {
    echo "   ⚠ Ligne exacte non trouvée, tentative pattern...\n";

    $pattern1 = '/\$passif\[\$ref\]\[\'net\'\] \+= abs\(\$solde\);/';
    if (preg_match($pattern1, $content)) {
        $content = preg_replace($pattern1, '$passif[$ref][\'net\'] -= $solde;', $content);
        $modifications++;
        echo "   ✓ Ligne corrigée via pattern\n";
    }
}

// Vérifier si d'autres utilisations de abs() pour le passif existent
echo "\n2️⃣ Vérification des autres utilisations de abs() pour le passif...\n";
$pattern_check = '/\$passif.*?abs\(\$solde\)/';
preg_match_all($pattern_check, $content, $matches);
if (count($matches[0]) > 0) {
    echo "   ⚠ Trouvé " . count($matches[0]) . " occurrence(s) de abs(\$solde) dans le passif\n";
    foreach ($matches[0] as $match) {
        echo "   - $match\n";
    }
} else {
    echo "   ✓ Aucune autre utilisation problématique de abs() détectée\n";
}

// Sauvegarder les modifications
if ($modifications > 0) {
    // Créer une sauvegarde
    $backup_file = $bilan_file . '.backup_equilibre.' . date('Y-m-d_H-i-s');
    file_put_contents($backup_file, $original_content);
    echo "\n📦 Sauvegarde créée: $backup_file\n";

    // Sauvegarder le fichier modifié
    file_put_contents($bilan_file, $content);
    echo "✅ Fichier $bilan_file mis à jour avec $modifications modification(s)\n";
} else {
    echo "\n⚠ Aucune modification nécessaire ou pattern non trouvé\n";
    echo "Vérification manuelle recommandée à la ligne 165\n";
}

echo "\n================================================\n";
echo "✨ Script terminé!\n\n";
echo "Prochaines étapes:\n";
echo "1. Actualisez la page du bilan\n";
echo "2. Vérifiez que Total Actif = Total Passif\n";
echo "3. Vérifiez que Passif N = 23,256,870,728.08 FCFA\n";
echo "4. Vérifiez que CJ apparaît avec le bon signe (négatif pour perte)\n";
