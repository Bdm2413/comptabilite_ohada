<?php
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== DIAGNOSTIC BILAN (NOUVELLE LOGIQUE) ===\n\n";

// Définir les rubriques d'actif et de passif
$rubriques_actif = ['AF', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AS', 'AZ', 'BA', 'BB', 'BG', 'BH', 'BI', 'BJ', 'BK', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BZ'];
$rubriques_passif = ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM', 'CP', 'DA', 'DB', 'DC', 'DD', 'DF', 'DH', 'DI', 'DJ', 'DK', 'DM', 'DN', 'DP', 'DQ', 'DR', 'DT', 'DV', 'DZ'];

// Récupérer tous les comptes avec mouvements
$sql = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        pc.bd,
        pc.bc,
        SUM(le.debit) as total_debit,
        SUM(le.credit) as total_credit,
        (SUM(le.debit) - SUM(le.credit)) as solde
    FROM plan_comptable pc
    INNER JOIN lignes_ecriture le ON pc.compte = le.compte
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui' AND pc.tableau = 'Bilan' AND e.statut = 'Validé'
    GROUP BY pc.compte
    HAVING ABS(solde) > 0.01
    ORDER BY pc.compte
";

$stmt = $db->query($sql);
$comptes = $stmt->fetchAll();

$total_actif_brut = 0;
$total_actif_amort = 0;
$total_actif_net = 0;
$total_passif = 0;

echo "COMPTES ACTIF:\n";
echo str_repeat("-", 120) . "\n";
printf("%-15s %-50s %-8s %-8s %20s %20s\n", "Compte", "Intitulé", "BD", "BC", "Solde", "Classement");
echo str_repeat("-", 120) . "\n";

foreach ($comptes as $c) {
    $prefix1 = substr($c['compte'], 0, 1);
    $prefix2 = substr($c['compte'], 0, 2);
    $prefix3 = substr($c['compte'], 0, 3);
    $solde = $c['solde'];

    // Exclure les comptes de reclassement ET leurs dépréciations
    if ($prefix3 == '416' || $prefix3 == '426' || $prefix3 == '491' || $prefix3 == '496') continue;

    // Déterminer où va ce compte avec la NOUVELLE LOGIQUE
    $classement = "";

    // Exception: amortissements/dépréciations
    if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
        if (!empty($c['bd'])) {
            $classement = "ACTIF-AMORT";
            $total_actif_amort += abs($solde);
            $total_actif_net -= abs($solde);
            printf("%-15s %-50s %-8s %-8s %20.2f %20s\n",
                $c['compte'], substr($c['intitule_compte'], 0, 50),
                $c['bd'], $c['bc'], $solde, $classement
            );
        }
    }
    // Cas 1: BD ≠ BC
    elseif ($c['bd'] != $c['bc']) {
        if ($solde > 0 && !empty($c['bd'])) {
            $classement = "ACTIF-BRUT (BD≠BC)";
            $total_actif_brut += $solde;
            $total_actif_net += $solde;
            printf("%-15s %-50s %-8s %-8s %20.2f %20s\n",
                $c['compte'], substr($c['intitule_compte'], 0, 50),
                $c['bd'], $c['bc'], $solde, $classement
            );
        } elseif ($solde < 0 && !empty($c['bc'])) {
            $classement = "PASSIF (BD≠BC)";
            $total_passif += abs($solde);
        }
    }
    // Cas 2: BD = BC
    elseif ($c['bd'] == $c['bc'] && !empty($c['bd'])) {
        $ref = $c['bd'];
        if (in_array($ref, $rubriques_actif)) {
            $classement = "ACTIF-BRUT (BD=BC)";
            $total_actif_brut += $solde; // Peut être positif ou négatif!
            $total_actif_net += $solde;
            printf("%-15s %-50s %-8s %-8s %20.2f %20s\n",
                $c['compte'], substr($c['intitule_compte'], 0, 50),
                $c['bd'], $c['bc'], $solde, $classement
            );
        } elseif (in_array($ref, $rubriques_passif)) {
            $classement = "PASSIF (BD=BC)";
            $total_passif -= $solde; // Inverse le signe
        }
    }
}

echo "\n\nCOMPTES PASSIF:\n";
echo str_repeat("-", 120) . "\n";
printf("%-15s %-50s %-8s %-8s %20s %20s\n", "Compte", "Intitulé", "BD", "BC", "Solde", "Classement");
echo str_repeat("-", 120) . "\n";

foreach ($comptes as $c) {
    $prefix1 = substr($c['compte'], 0, 1);
    $prefix2 = substr($c['compte'], 0, 2);
    $solde = $c['solde'];

    // Même logique pour affichage PASSIF
    if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
        // Déjà traité en AMORT, skip
    }
    elseif ($c['bd'] != $c['bc']) {
        if ($solde < 0 && !empty($c['bc'])) {
            printf("%-15s %-50s %-8s %-8s %20.2f %20s\n",
                $c['compte'], substr($c['intitule_compte'], 0, 50),
                $c['bd'], $c['bc'], $solde, "PASSIF (BD≠BC)"
            );
        }
    }
    elseif ($c['bd'] == $c['bc'] && !empty($c['bd'])) {
        $ref = $c['bd'];
        if (in_array($ref, $rubriques_passif)) {
            printf("%-15s %-50s %-8s %-8s %20.2f %20s\n",
                $c['compte'], substr($c['intitule_compte'], 0, 50),
                $c['bd'], $c['bc'], $solde, "PASSIF (BD=BC)"
            );
        }
    }
}

echo "\n\n=== TOTAUX ===\n";
echo "ACTIF BRUT:  " . number_format($total_actif_brut, 2, ',', ' ') . "\n";
echo "ACTIF AMORT: " . number_format($total_actif_amort, 2, ',', ' ') . "\n";
echo "ACTIF NET:   " . number_format($total_actif_net, 2, ',', ' ') . "\n";
echo "PASSIF:      " . number_format($total_passif, 2, ',', ' ') . "\n";
echo "\nDIFFÉRENCE (Actif NET - Passif): " . number_format($total_actif_net - $total_passif, 2, ',', ' ') . "\n";

echo "\n\n=== TOTAUX ATTENDUS (selon utilisateur) ===\n";
echo "ACTIF:  23 156 658 366\n";
echo "PASSIF: 23 156 658 366\n";

echo "\n\n=== ÉCARTS ===\n";
echo "Écart Actif:  " . number_format($total_actif_net - 23156658366, 2, ',', ' ') . "\n";
echo "Écart Passif: " . number_format($total_passif - 23156658366, 2, ',', ' ') . "\n";
?>
