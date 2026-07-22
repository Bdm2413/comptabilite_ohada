<?php
/**
 * Script de correction du format et de la logique du passif dans bilan.php
 *
 * Problèmes identifiés:
 * 1. Format des nombres: pas de virgule décimale
 * 2. Passif négatif au lieu de positif
 * 3. Bilan non équilibré
 */

echo "🔧 Script de correction du bilan\n";
echo "=================================\n\n";

$bilan_file = 'pages/etats_financiers/bilan.php';

if (!file_exists($bilan_file)) {
    die("❌ Fichier $bilan_file introuvable\n");
}

$content = file_get_contents($bilan_file);
$original_content = $content;
$modifications = 0;

// 1. Corriger le format des nombres (virgule au lieu d'espace pour les décimales)
echo "1️⃣ Correction du format des nombres...\n";
$old_format = "function safe_number_format(\$number, \$decimals = 0, \$decimal_separator = ' ', \$thousands_separator = ' ') {";
$new_format = "function safe_number_format(\$number, \$decimals = 2, \$decimal_separator = ',', \$thousands_separator = ' ') {";

if (strpos($content, $old_format) !== false) {
    $content = str_replace($old_format, $new_format, $content);
    $modifications++;
    echo "   ✓ Format des nombres corrigé (virgule pour les décimales)\n";
} else {
    echo "   ℹ Format déjà correct ou différent\n";
}

// 2. Corriger la logique du passif
echo "\n2️⃣ Analyse de la logique du passif...\n";
echo "   Le problème principal est que les soldes créditeurs du passif sont négatifs.\n";
echo "   Cela vient probablement de la ligne:\n";
echo "   \$passif[\$ref]['net'] -= \$solde;\n";
echo "   qui devrait être:\n";
echo "   \$passif[\$ref]['net'] += abs(\$solde);\n\n";

// Chercher et corriger la ligne problématique dans le cas BD = BC
$pattern1 = "/(\\\$passif\[\\\$ref\]\['net'\] -= \\\$solde;)/";
$replacement1 = "\$passif[\$ref]['net'] += abs(\$solde);";

if (preg_match($pattern1, $content)) {
    $content = preg_replace($pattern1, $replacement1, $content);
    $modifications++;
    echo "   ✓ Logique du passif corrigée (ligne avec -= \$solde)\n";
}

// Sauvegarder les modifications
if ($modifications > 0) {
    // Créer une sauvegarde
    $backup_file = $bilan_file . '.backup.' . date('Y-m-d_H-i-s');
    file_put_contents($backup_file, $original_content);
    echo "\n📦 Sauvegarde créée: $backup_file\n";

    // Sauvegarder le fichier modifié
    file_put_contents($bilan_file, $content);
    echo "✅ Fichier $bilan_file mis à jour avec $modifications modification(s)\n";
} else {
    echo "\n⚠ Aucune modification nécessaire\n";
}

echo "\n=================================\n";
echo "✨ Script terminé!\n\n";
echo "Prochaines étapes:\n";
echo "1. Actualisez la page du bilan\n";
echo "2. Vérifiez que les montants ont des virgules\n";
echo "3. Vérifiez que le passif est positif\n";
echo "4. Vérifiez que Total Actif = Total Passif\n";
