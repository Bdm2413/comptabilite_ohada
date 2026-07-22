<?php
require_once 'config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

$date_debut = $_GET['date_debut'] ?? '2025-01-01';
$date_fin = $_GET['date_fin'] ?? '2025-12-17';

echo "<h1>Diagnostic Simple CJ (Résultat Net)</h1>\n";
echo "<p>Période: du $date_debut au $date_fin</p>\n";
echo "<hr>\n\n";

// 1. Calculer le résultat net selon la formule actuelle du bilan
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
$stmt = $db->prepare($sql_resultat);
$stmt->execute([$date_debut, $date_fin]);
$resultat_net = $stmt->fetchColumn();

echo "<h2>Résultat Net (Crédit - Débit des classes 6,7,8)</h2>\n";
echo "<p><strong>Valeur: " . number_format($resultat_net, 2, ',', ' ') . " FCFA</strong></p>\n";
echo "<p>Interprétation: " . ($resultat_net > 0 ? "BÉNÉFICE (produits > charges)" : "PERTE (charges > produits)") . "</p>\n";
echo "<hr>\n\n";

// 2. Détail des classes
$sql_detail = "
    SELECT
        LEFT(pc.compte, 1) as classe,
        SUM(le.debit) as total_debit,
        SUM(le.credit) as total_credit,
        (SUM(le.credit) - SUM(le.debit)) as solde
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 1) IN ('6', '7', '8')
    AND e.date_ecriture BETWEEN ? AND ?
    AND e.statut = 'Validé'
    GROUP BY LEFT(pc.compte, 1)
    ORDER BY LEFT(pc.compte, 1)
";
$stmt = $db->prepare($sql_detail);
$stmt->execute([$date_debut, $date_fin]);
$details = $stmt->fetchAll();

echo "<h2>Détail par Classe</h2>\n";
echo "<table border='1' cellpadding='5'>\n";
echo "<tr><th>Classe</th><th>Débit</th><th>Crédit</th><th>Solde (C-D)</th><th>Nature</th></tr>\n";

foreach ($details as $detail) {
    $classe_name = $detail['classe'] == '6' ? 'Charges' : ($detail['classe'] == '7' ? 'Produits' : 'Autres');
    echo "<tr>\n";
    echo "<td><strong>Classe " . $detail['classe'] . "</strong> ($classe_name)</td>\n";
    echo "<td style='text-align: right'>" . number_format($detail['total_debit'], 2, ',', ' ') . "</td>\n";
    echo "<td style='text-align: right'>" . number_format($detail['total_credit'], 2, ',', ' ') . "</td>\n";
    echo "<td style='text-align: right; font-weight: bold'>" . number_format($detail['solde'], 2, ',', ' ') . "</td>\n";
    echo "<td>" . ($detail['solde'] > 0 ? "Créditeur" : "Débiteur") . "</td>\n";
    echo "</tr>\n";
}
echo "</table>\n";
echo "<hr>\n\n";

// 3. Comparaison avec la valeur attendue du PDF
$resultat_attendu = -585112802.56; // D'après le PDF (perte)

echo "<h2>Comparaison avec PDF</h2>\n";
echo "<table border='1' cellpadding='5'>\n";
echo "<tr><th>Source</th><th>Résultat</th><th>Nature</th></tr>\n";
echo "<tr>\n";
echo "<td>Calculé (actuel)</td>\n";
echo "<td style='text-align: right'>" . number_format($resultat_net, 2, ',', ' ') . "</td>\n";
echo "<td>" . ($resultat_net > 0 ? "Bénéfice" : "Perte") . "</td>\n";
echo "</tr>\n";
echo "<tr>\n";
echo "<td>PDF (attendu)</td>\n";
echo "<td style='text-align: right'>" . number_format($resultat_attendu, 2, ',', ' ') . "</td>\n";
echo "<td>Perte</td>\n";
echo "</tr>\n";
echo "<tr style='background-color: " . (abs($resultat_net - $resultat_attendu) < 1 ? '#90EE90' : '#FFB6C1') . "'>\n";
echo "<td>Différence</td>\n";
echo "<td style='text-align: right'>" . number_format($resultat_net - $resultat_attendu, 2, ',', ' ') . "</td>\n";
echo "<td>" . (abs($resultat_net - $resultat_attendu) < 1 ? "OK ✓" : "ERREUR ✗") . "</td>\n";
echo "</tr>\n";
echo "</table>\n";
echo "<hr>\n\n";

// 4. Comment CJ devrait affecter le passif
echo "<h2>Impact sur le Passif</h2>\n";
echo "<p>Selon SYSCOHADA, le résultat CJ doit être ajouté aux Capitaux Propres (CP):</p>\n";
echo "<ul>\n";
echo "<li>Si <strong>BÉNÉFICE</strong> (résultat > 0): CP augmente → PASSIF augmente</li>\n";
echo "<li>Si <strong>PERTE</strong> (résultat < 0): CP diminue → PASSIF diminue</li>\n";
echo "</ul>\n";
echo "<p><strong>Formule actuelle du code:</strong> CP = CA + CB + CD + CE + CF + CG + CH + <span style='color: red'>CJ</span> + CL + CM</p>\n";
echo "<p>Avec CJ = " . number_format($resultat_net, 2, ',', ' ') . " FCFA</p>\n";
echo "<p>Impact: " . ($resultat_net > 0 ? "Augmente le CP de " . number_format($resultat_net, 2, ',', ' ') : "Diminue le CP de " . number_format(abs($resultat_net), 2, ',', ' ')) . " FCFA</p>\n";
