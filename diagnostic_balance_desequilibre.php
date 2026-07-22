<?php
require_once 'config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<h1>🔍 Diagnostic du déséquilibre de la balance</h1>";
echo "<hr>";

// Balance actuelle (toutes écritures jusqu'à date_fin)
$date_fin = $_GET['date_fin'] ?? '2025-12-17';

echo "<h2>1. Balance Générale au $date_fin</h2>";

$sql_balance = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit,
        (COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) -
         COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0)) as solde
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    GROUP BY pc.compte, pc.intitule_compte
    HAVING ABS(total_debit) > 0.01 OR ABS(total_credit) > 0.01
    ORDER BY pc.compte
";

$stmt = $db->prepare($sql_balance);
$stmt->execute([$date_fin, $date_fin, $date_fin, $date_fin]);
$comptes = $stmt->fetchAll();

$total_debit = 0;
$total_credit = 0;
$compte_13_trouve = false;
$details_13 = [];

foreach ($comptes as $compte) {
    $total_debit += $compte['total_debit'];
    $total_credit += $compte['total_credit'];

    if (substr($compte['compte'], 0, 2) == '13') {
        $compte_13_trouve = true;
        $details_13[] = $compte;
    }
}

$difference = $total_debit - $total_credit;

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background-color: #e8f4f8;'>";
echo "<th>Élément</th><th>Montant (FCFA)</th>";
echo "</tr>";
echo "<tr><td>Total Débit</td><td style='text-align: right; font-family: monospace;'>" . number_format($total_debit, 2, ',', ' ') . "</td></tr>";
echo "<tr><td>Total Crédit</td><td style='text-align: right; font-family: monospace;'>" . number_format($total_credit, 2, ',', ' ') . "</td></tr>";
echo "<tr style='background-color: " . (abs($difference) < 1 ? '#90EE90' : '#FFB6C1') . "; font-weight: bold;'>";
echo "<td>Différence (D - C)</td><td style='text-align: right; font-family: monospace;'>" . number_format($difference, 2, ',', ' ') . "</td></tr>";
echo "</table>";

echo "<h2>2. Comptes de Résultat (13x) dans la balance</h2>";

if ($compte_13_trouve) {
    echo "<p style='color: red; font-weight: bold;'>⚠ ATTENTION: Des comptes 13x (Résultat net) sont présents dans la balance!</p>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Compte</th><th>Intitulé</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr>";

    $total_13_debit = 0;
    $total_13_credit = 0;

    foreach ($details_13 as $c) {
        echo "<tr>";
        echo "<td>" . $c['compte'] . "</td>";
        echo "<td>" . htmlspecialchars($c['intitule_compte']) . "</td>";
        echo "<td style='text-align: right; font-family: monospace;'>" . number_format($c['total_debit'], 2, ',', ' ') . "</td>";
        echo "<td style='text-align: right; font-family: monospace;'>" . number_format($c['total_credit'], 2, ',', ' ') . "</td>";
        echo "<td style='text-align: right; font-family: monospace;'>" . number_format($c['solde'], 2, ',', ' ') . "</td>";
        echo "</tr>";

        $total_13_debit += $c['total_debit'];
        $total_13_credit += $c['total_credit'];
    }

    echo "<tr style='font-weight: bold; background-color: #f0f0f0;'>";
    echo "<td colspan='2'>TOTAL Comptes 13x</td>";
    echo "<td style='text-align: right; font-family: monospace;'>" . number_format($total_13_debit, 2, ',', ' ') . "</td>";
    echo "<td style='text-align: right; font-family: monospace;'>" . number_format($total_13_credit, 2, ',', ' ') . "</td>";
    echo "<td style='text-align: right; font-family: monospace;'>" . number_format($total_13_debit - $total_13_credit, 2, ',', ' ') . "</td>";
    echo "</tr>";
    echo "</table>";

    echo "<p><strong>Impact sur la balance:</strong></p>";
    echo "<p>Si on EXCLUAIT les comptes 13x de la balance:</p>";
    $debit_sans_13 = $total_debit - $total_13_debit;
    $credit_sans_13 = $total_credit - $total_13_credit;
    $diff_sans_13 = $debit_sans_13 - $credit_sans_13;

    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><td>Total Débit sans 13x</td><td style='text-align: right; font-family: monospace;'>" . number_format($debit_sans_13, 2, ',', ' ') . "</td></tr>";
    echo "<tr><td>Total Crédit sans 13x</td><td style='text-align: right; font-family: monospace;'>" . number_format($credit_sans_13, 2, ',', ' ') . "</td></tr>";
    echo "<tr style='background-color: " . (abs($diff_sans_13) < 1 ? '#90EE90' : '#FFB6C1') . "; font-weight: bold;'>";
    echo "<td>Différence</td><td style='text-align: right; font-family: monospace;'>" . number_format($diff_sans_13, 2, ',', ' ') . "</td></tr>";
    echo "</table>";
} else {
    echo "<p style='color: green;'>✓ Aucun compte 13x trouvé dans la balance.</p>";
}

echo "<h2>3. Comparaison avec les valeurs attendues</h2>";

$resultat_n1_attendu = -1586846772.89;
$resultat_n1_affiche = -585112802.56;

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Source</th><th>Résultat N-1</th></tr>";
echo "<tr><td>Différence balance (D - C)</td><td style='text-align: right; font-family: monospace;'>" . number_format($difference, 2, ',', ' ') . "</td></tr>";
echo "<tr><td>PDF - Résultat N-1 attendu</td><td style='text-align: right; font-family: monospace;'>" . number_format($resultat_n1_attendu, 2, ',', ' ') . "</td></tr>";
echo "<tr><td>Bilan - Résultat N-1 affiché</td><td style='text-align: right; font-family: monospace;'>" . number_format($resultat_n1_affiche, 2, ',', ' ') . "</td></tr>";
echo "<tr style='background-color: " . (abs($difference - $resultat_n1_attendu) < 1 ? '#90EE90' : '#FFB6C1') . ";'>";
echo "<td>Différence balance = PDF?</td><td style='font-weight: bold;'>" . (abs($difference - $resultat_n1_attendu) < 1 ? "✓ OUI - IDENTIQUES!" : "✗ Non") . "</td></tr>";
echo "</table>";

echo "<h2>4. Diagnostic</h2>";

if (abs($difference - $resultat_n1_attendu) < 1) {
    echo "<div style='background-color: #fff3cd; padding: 15px; border-left: 5px solid #ffc107;'>";
    echo "<h3>⚠ PROBLÈME IDENTIFIÉ</h3>";
    echo "<p><strong>La différence dans la balance (-1,586,846,772.89) correspond EXACTEMENT au résultat N-1 du PDF!</strong></p>";
    echo "<p>Cela signifie que:</p>";
    echo "<ul>";
    echo "<li>La balance INCLUT probablement le compte 13 (Résultat net de N-1)</li>";
    echo "<li>MAIS le bilan recalcule le CJ de N-1 à partir des classes 6,7,8 de la période N-1</li>";
    echo "<li>Cela crée un DOUBLE COMPTAGE ou une INCOHÉRENCE</li>";
    echo "</ul>";
    echo "<p><strong>Solution:</strong></p>";
    echo "<ul>";
    echo "<li>Pour N-1, ne PAS recalculer CJ à partir des classes 6,7,8</li>";
    echo "<li>Utiliser directement le solde du compte 13x présent dans la balance de clôture N-1</li>";
    echo "<li>Le résultat N-1 doit provenir de la clôture, pas d'un recalcul</li>";
    echo "</ul>";
    echo "</div>";
} else {
    echo "<p>La différence ne correspond pas exactement. Vérification manuelle nécessaire.</p>";
}

echo "<h2>5. Résultat N (exercice en cours)</h2>";

$date_debut = '2025-01-01';
$sql_resultat_n = "
    SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_net
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 1) IN ('6', '7', '8')
    AND e.date_ecriture BETWEEN ? AND ?
    AND e.statut = 'Validé'
";
$stmt_n = $db->prepare($sql_resultat_n);
$stmt_n->execute([$date_debut, $date_fin]);
$resultat_n = $stmt_n->fetchColumn();

echo "<p>Résultat N calculé (classes 6,7,8 de $date_debut à $date_fin): <strong>" . number_format($resultat_n, 2, ',', ' ') . " FCFA</strong></p>";
echo "<p>Nature: " . ($resultat_n > 0 ? "Bénéfice" : "Perte") . "</p>";

echo "</body></html>";
