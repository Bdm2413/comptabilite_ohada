<?php
/**
 * Script de vérification de l'équilibre du bilan après corrections
 */
require_once 'config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Tester avec les mêmes dates que le PDF
$date_debut = '2025-01-01';
$date_fin = '2025-12-17';

echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<h1>🔍 Vérification de l'équilibre du bilan</h1>";
echo "<p>Période: du $date_debut au $date_fin</p>";
echo "<hr>";

// Créer une requête simplifiée qui simule le calcul du bilan
$sql = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        pc.bd,
        pc.bc,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui' AND pc.tableau = 'Bilan'
    AND LEFT(pc.compte, 1) NOT IN ('6', '7', '8')
    AND LEFT(pc.compte, 2) != '13'
    GROUP BY pc.compte, pc.intitule_compte, pc.bd, pc.bc
    ORDER BY pc.compte
";

$stmt = $db->prepare($sql);
$stmt->execute([$date_fin, $date_fin]);
$comptes = $stmt->fetchAll();

$rubriques_actif = ['AF', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AS', 'AZ', 'BA', 'BB', 'BG', 'BH', 'BI', 'BJ', 'BK', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BZ'];
$rubriques_passif = ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM', 'CP', 'DA', 'DB', 'DC', 'DD', 'DF', 'DH', 'DI', 'DJ', 'DK', 'DM', 'DN', 'DP', 'DQ', 'DR', 'DT', 'DV', 'DZ'];

$total_actif_brut = 0;
$total_actif_amort = 0;
$total_actif_net = 0;
$total_passif = 0;

$details_actif = [];
$details_passif = [];

foreach ($comptes as $compte) {
    $solde = $compte['total_debit'] - $compte['total_credit'];

    if ($solde == 0) continue;

    $prefix2 = substr($compte['compte'], 0, 2);
    $prefix3 = substr($compte['compte'], 0, 3);

    // Exclure reclassements
    if ($prefix3 == '416' || $prefix3 == '426' || $prefix3 == '491' || $prefix3 == '496') continue;

    // Amortissements
    if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
        if (!empty($compte['bd'])) {
            $total_actif_amort += abs($solde);
            $total_actif_net -= abs($solde);
            $details_actif[] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'rubrique' => $compte['bd'],
                'type' => 'AMORT',
                'solde' => $solde,
                'contribution_net' => -abs($solde)
            ];
        }
    }
    // BD ≠ BC
    elseif ($compte['bd'] != $compte['bc']) {
        if ($solde > 0 && !empty($compte['bd'])) {
            $total_actif_brut += $solde;
            $total_actif_net += $solde;
            $details_actif[] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'rubrique' => $compte['bd'],
                'type' => 'BD≠BC (Débiteur)',
                'solde' => $solde,
                'contribution_net' => $solde
            ];
        } elseif ($solde < 0 && !empty($compte['bc'])) {
            $total_passif += abs($solde);
            $details_passif[] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'rubrique' => $compte['bc'],
                'type' => 'BD≠BC (Créditeur)',
                'solde' => $solde,
                'contribution' => abs($solde)
            ];
        }
    }
    // BD = BC
    elseif ($compte['bd'] == $compte['bc'] && !empty($compte['bd'])) {
        $ref = $compte['bd'];

        if (in_array($ref, $rubriques_actif)) {
            $total_actif_brut += $solde;
            $total_actif_net += $solde;
            $details_actif[] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'rubrique' => $ref,
                'type' => 'BD=BC (Actif)',
                'solde' => $solde,
                'contribution_net' => $solde
            ];
        } elseif (in_array($ref, $rubriques_passif)) {
            // NOUVELLE FORMULE: -= $solde
            $contribution = -$solde; // Solde créditeur (négatif) devient positif, débiteur (positif) devient négatif
            $total_passif += $contribution;
            $details_passif[] = [
                'compte' => $compte['compte'],
                'intitule' => $compte['intitule_compte'],
                'rubrique' => $ref,
                'type' => 'BD=BC (Passif)',
                'solde' => $solde,
                'contribution' => $contribution
            ];
        }
    }
}

// Calculer CJ (Résultat net)
$sql_resultat = "
    SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_net
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 1) IN ('6', '7', '8')
    AND e.date_ecriture BETWEEN ? AND ?
    AND e.statut = 'Validé'
";
$stmt_resultat = $db->prepare($sql_resultat);
$stmt_resultat->execute([$date_debut, $date_fin]);
$resultat_net = $stmt_resultat->fetchColumn();

// CJ est traité comme un compte avec BD=BC dans le passif
$contribution_cj = -$resultat_net; // Si perte (positif), devient négatif; si bénéfice (négatif), devient positif
$total_passif += $contribution_cj;

$details_passif[] = [
    'compte' => 'CJ',
    'intitule' => 'Résultat net de l\'exercice',
    'rubrique' => 'CJ',
    'type' => 'Résultat',
    'solde' => $resultat_net,
    'contribution' => $contribution_cj
];

// Afficher les résultats
echo "<h2>📊 Totaux Généraux</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background-color: #e8f4f8;'>";
echo "<th>Élément</th><th>Montant (FCFA)</th><th>Référence PDF</th><th>Statut</th>";
echo "</tr>";

$actif_attendu = 23256870728.08;
$passif_attendu = 23256870728.08;

echo "<tr>";
echo "<td><strong>ACTIF NET (calculé)</strong></td>";
echo "<td style='text-align: right; font-family: monospace;'>" . number_format($total_actif_net, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right; font-family: monospace;'>" . number_format($actif_attendu, 2, ',', ' ') . "</td>";
$diff_actif = $total_actif_net - $actif_attendu;
echo "<td style='color: " . (abs($diff_actif) < 1 ? 'green' : 'red') . "'>" . (abs($diff_actif) < 1 ? '✓ CORRECT' : '✗ ÉCART: ' . number_format($diff_actif, 2, ',', ' ')) . "</td>";
echo "</tr>";

echo "<tr>";
echo "<td><strong>PASSIF NET (calculé)</strong></td>";
echo "<td style='text-align: right; font-family: monospace;'>" . number_format($total_passif, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right; font-family: monospace;'>" . number_format($passif_attendu, 2, ',', ' ') . "</td>";
$diff_passif = $total_passif - $passif_attendu;
echo "<td style='color: " . (abs($diff_passif) < 1 ? 'green' : 'red') . "'>" . (abs($diff_passif) < 1 ? '✓ CORRECT' : '✗ ÉCART: ' . number_format($diff_passif, 2, ',', ' ')) . "</td>";
echo "</tr>";

echo "<tr style='background-color: " . (abs($total_actif_net - $total_passif) < 1 ? '#90EE90' : '#FFB6C1') . "; font-weight: bold;'>";
echo "<td>ÉQUILIBRE (Actif - Passif)</td>";
$equilibre = $total_actif_net - $total_passif;
echo "<td style='text-align: right; font-family: monospace;'>" . number_format($equilibre, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right;'>0,00</td>";
echo "<td style='color: " . (abs($equilibre) < 1 ? 'green' : 'red') . "'>" . (abs($equilibre) < 1 ? '✓ BILAN ÉQUILIBRÉ' : '✗ BILAN NON ÉQUILIBRÉ') . "</td>";
echo "</tr>";

echo "</table>";

echo "<h2>💡 Résultat Net (CJ)</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Description</th><th>Valeur</th></tr>";
echo "<tr><td>Résultat net (Crédit - Débit classes 6,7,8)</td><td style='text-align: right; font-family: monospace;'>" . number_format($resultat_net, 2, ',', ' ') . "</td></tr>";
echo "<tr><td>Nature</td><td>" . ($resultat_net > 0 ? "Bénéfice (créditeur)" : "Perte (débiteur)") . "</td></tr>";
echo "<tr><td>Contribution au Passif (NOUVELLE FORMULE: -\$solde)</td><td style='text-align: right; font-family: monospace; font-weight: bold;'>" . number_format($contribution_cj, 2, ',', ' ') . "</td></tr>";
echo "<tr><td>Explication</td><td>" . ($resultat_net > 0 ? "Bénéfice: augmente le passif" : "Perte: <span style='color: red; font-weight: bold;'>DIMINUE</span> le passif") . "</td></tr>";
echo "</table>";

echo "</body></html>";
