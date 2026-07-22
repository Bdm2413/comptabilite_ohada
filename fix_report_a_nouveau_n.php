<?php
/**
 * Script de correction du Report à nouveau (CH) pour l'exercice N
 *
 * Problème:
 * - Le résultat de N-1 devrait être dans CH (Report à nouveau) en N
 * - Actuellement, CF utilise le compte 12, mais c'est CH qui devrait l'utiliser
 * - Le bilan N n'est pas équilibré car le résultat N-1 n'est pas reporté
 *
 * Solution SYSCOHADA:
 * - CF (Réserves indisponibles) = Compte 11
 * - CH (Report à nouveau) = Compte 12 (résultat N-1 reporté)
 * - CJ (Résultat exercice) = Classes 6,7,8 de N
 */

echo "🔧 Script de correction du Report à nouveau (CH) pour N\n";
echo "========================================================\n\n";

$bilan_file = 'pages/etats_financiers/bilan.php';

if (!file_exists($bilan_file)) {
    die("❌ Fichier $bilan_file introuvable\n");
}

$content = file_get_contents($bilan_file);
$original_content = $content;
$modifications = 0;

echo "Problème identifié:\n";
echo "-------------------\n";
echo "- Bilan N déséquilibré\n";
echo "- Le résultat de N-1 (-1,586,846,772.89) devrait être dans CH en N\n";
echo "- Actuellement, CF utilise le compte 12 au lieu de CH\n\n";

echo "Solution SYSCOHADA:\n";
echo "-------------------\n";
echo "- CF (Réserves indisponibles) → Compte 11\n";
echo "- CH (Report à nouveau) → Compte 12 + Résultat N-1\n";
echo "- CJ (Résultat exercice N) → Classes 6,7,8 de N\n\n";

// Correction 1: Modifier CF pour utiliser le compte 11
echo "1️⃣ Correction de CF (compte 11 au lieu de 12)...\n";

$old_cf = "    // CF (Report à nouveau) en N = Cumul de tous les résultats jusqu'à fin N-1
    \$sql_report_nouveau_n = \"
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as report_nouveau
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '12'
        AND e.date_ecriture < ?
        AND e.statut = 'Validé'
    \";
    \$stmt_report_n = \$db->prepare(\$sql_report_nouveau_n);
    \$stmt_report_n->execute([\$date_debut]);
    \$passif['CF']['net'] = \$stmt_report_n->fetchColumn();";

$new_cf = "    // CF (Réserves indisponibles) en N = Compte 11
    \$sql_reserves_n = \"
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as reserves
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '11'
        AND e.date_ecriture <= ?
        AND e.statut = 'Validé'
    \";
    \$stmt_reserves_n = \$db->prepare(\$sql_reserves_n);
    \$stmt_reserves_n->execute([\$date_fin]);
    \$passif['CF']['net'] = \$stmt_reserves_n->fetchColumn();

    // CH (Report à nouveau) en N = Compte 12 + Résultat N-1 (compte 13)
    // Compte 12: Report à nouveau proprement dit
    \$sql_report_12 = \"
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as report_12
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '12'
        AND e.date_ecriture <= ?
        AND e.statut = 'Validé'
    \";
    \$stmt_report_12 = \$db->prepare(\$sql_report_12);
    \$stmt_report_12->execute([\$date_fin]);
    \$report_12 = \$stmt_report_12->fetchColumn();

    // Compte 13: Résultat N-1 (jusqu'à fin N-1)
    \$sql_resultat_n1 = \"
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_n1
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '13'
        AND e.date_ecriture < ?
        AND e.statut = 'Validé'
    \";
    \$stmt_resultat_n1 = \$db->prepare(\$sql_resultat_n1);
    \$stmt_resultat_n1->execute([\$date_debut]);
    \$resultat_n1 = \$stmt_resultat_n1->fetchColumn();

    // CH = Compte 12 + Résultat N-1
    \$passif['CH']['net'] = \$report_12 + \$resultat_n1;";

if (strpos($content, $old_cf) !== false) {
    $content = str_replace($old_cf, $new_cf, $content);
    $modifications++;
    echo "   ✓ CF corrigé (compte 11) et CH ajouté (compte 12 + résultat N-1)\n";
} else {
    echo "   ⚠ Code exact non trouvé\n";
    echo "   Tentative de modification partielle...\n";

    // Essayer juste de changer le compte 12 en 11 pour CF
    if (strpos($content, "AND LEFT(pc.compte, 2) = '12'") !== false) {
        // On va devoir faire une modification manuelle
        echo "   ⚠ Modification manuelle nécessaire\n";
    }
}

// Sauvegarder les modifications
if ($modifications > 0) {
    // Créer une sauvegarde
    $backup_file = $bilan_file . '.backup_ch_n.' . date('Y-m-d_H-i-s');
    file_put_contents($backup_file, $original_content);
    echo "\n📦 Sauvegarde créée: $backup_file\n";

    // Sauvegarder le fichier modifié
    file_put_contents($bilan_file, $content);
    echo "✅ Fichier $bilan_file mis à jour avec $modifications modification(s)\n";
} else {
    echo "\n⚠ Aucune modification automatique effectuée\n";
    echo "Modification manuelle nécessaire\n";
}

echo "\n========================================================\n";
echo "✨ Script terminé!\n\n";
echo "Logique attendue après correction:\n";
echo "- CF = Compte 11 (Réserves indisponibles)\n";
echo "- CH = Compte 12 + Résultat N-1 (Report à nouveau)\n";
echo "- CJ = Classes 6,7,8 de N (Résultat exercice en cours)\n";
echo "- CP = CA + CB + CD + CE + CF + CG + CH + CJ + CL + CM\n\n";
echo "Vérification:\n";
echo "1. Actualiser le bilan\n";
echo "2. Vérifier Total Actif N = Total Passif N\n";
