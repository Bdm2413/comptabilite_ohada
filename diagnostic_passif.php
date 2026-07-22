<?php
require_once 'config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

echo "<h1>Diagnostic du Passif</h1>";
echo "<p>Période: du $date_debut au $date_fin</p>";
echo "<hr>";

// 1. Calculer le résultat net
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

echo "<h2>1. Résultat Net (Classes 6, 7, 8)</h2>";
echo "<p><strong>Résultat calculé: " . number_format($resultat_net, 2, ',', ' ') . " FCFA</strong></p>";
echo "<hr>";

// 2. Récupérer tous les comptes de passif (classes 1-5) avec solde créditeur
$sql_passif = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        pc.bc,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit,
        (COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) -
         COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0)) as solde
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 1) IN ('1', '2', '3', '4', '5')
    GROUP BY pc.compte, pc.intitule_compte, pc.bc
    HAVING solde > 0.01
    ORDER BY pc.compte
";

$stmt = $db->prepare($sql_passif);
$stmt->execute([
    $date_debut, $date_fin,
    $date_debut, $date_fin,
    $date_debut, $date_fin,
    $date_debut, $date_fin
]);
$comptes_passif = $stmt->fetchAll();

echo "<h2>2. Comptes du Passif (Soldes Créditeurs - Classes 1-5)</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Compte</th><th>Intitulé</th><th>BC</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr>";

$total_passif_sans_resultat = 0;
foreach ($comptes_passif as $compte) {
    echo "<tr>";
    echo "<td>" . $compte['compte'] . "</td>";
    echo "<td>" . htmlspecialchars($compte['intitule_compte']) . "</td>";
    echo "<td>" . ($compte['bc'] ?: '-') . "</td>";
    echo "<td style='text-align: right'>" . number_format($compte['total_debit'], 2, ',', ' ') . "</td>";
    echo "<td style='text-align: right'>" . number_format($compte['total_credit'], 2, ',', ' ') . "</td>";
    echo "<td style='text-align: right'><strong>" . number_format($compte['solde'], 2, ',', ' ') . "</strong></td>";
    echo "</tr>";

    $total_passif_sans_resultat += $compte['solde'];
}

echo "<tr style='background-color: #f0f0f0; font-weight: bold;'>";
echo "<td colspan='5'>TOTAL PASSIF (SANS RÉSULTAT)</td>";
echo "<td style='text-align: right'>" . number_format($total_passif_sans_resultat, 2, ',', ' ') . "</td>";
echo "</tr>";
echo "</table>";
echo "<hr>";

// 3. Calcul avec résultat
$total_passif_avec_resultat = $total_passif_sans_resultat + $resultat_net;

echo "<h2>3. Totaux</h2>";
echo "<p>Total Passif SANS résultat: <strong>" . number_format($total_passif_sans_resultat, 2, ',', ' ') . " FCFA</strong></p>";
echo "<p>Résultat Net: <strong>" . number_format($resultat_net, 2, ',', ' ') . " FCFA</strong></p>";
echo "<p>Total Passif AVEC résultat: <strong>" . number_format($total_passif_avec_resultat, 2, ',', ' ') . " FCFA</strong></p>";
echo "<hr>";

// 4. Comparer avec les valeurs attendues
echo "<h2>4. Comparaison avec les valeurs attendues</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Élément</th><th>Valeur Actuelle</th><th>Valeur Attendue</th><th>Différence</th></tr>";

$passif_attendu_sans_resultat = 23841983530.64;
$passif_attendu_avec_resultat = 23256870728.08;

$diff_sans_resultat = $total_passif_sans_resultat - $passif_attendu_sans_resultat;
$diff_avec_resultat = $total_passif_avec_resultat - $passif_attendu_avec_resultat;

echo "<tr>";
echo "<td>Passif SANS résultat</td>";
echo "<td style='text-align: right'>" . number_format($total_passif_sans_resultat, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right'>" . number_format($passif_attendu_sans_resultat, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right; color: " . ($diff_sans_resultat > 0 ? 'red' : 'green') . "'>" . number_format($diff_sans_resultat, 2, ',', ' ') . "</td>";
echo "</tr>";

echo "<tr>";
echo "<td>Passif AVEC résultat</td>";
echo "<td style='text-align: right'>" . number_format($total_passif_avec_resultat, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right'>" . number_format($passif_attendu_avec_resultat, 2, ',', ' ') . "</td>";
echo "<td style='text-align: right; color: " . ($diff_avec_resultat > 0 ? 'red' : 'green') . "'>" . number_format($diff_avec_resultat, 2, ',', ' ') . "</td>";
echo "</tr>";
echo "</table>";
echo "<hr>";

// 5. Rechercher les comptes suspects
echo "<h2>5. Comptes Suspects</h2>";
echo "<p>Recherche de comptes qui pourraient être comptés en double ou incorrectement affectés...</p>";

// Chercher les comptes de résultat qui seraient encore dans le passif
$sql_suspects = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        pc.bc,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit - le.debit ELSE 0 END), 0) as solde
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND (LEFT(pc.compte, 1) IN ('6', '7', '8') OR LEFT(pc.compte, 2) = '13')
    GROUP BY pc.compte, pc.intitule_compte, pc.bc
    HAVING ABS(solde) > 0.01
    ORDER BY ABS(solde) DESC
";

$stmt = $db->prepare($sql_suspects);
$stmt->execute([$date_debut, $date_fin]);
$suspects = $stmt->fetchAll();

if (count($suspects) > 0) {
    echo "<p style='color: red;'><strong>⚠ ATTENTION: Des comptes de résultat ou compte 13 ont des soldes!</strong></p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Compte</th><th>Intitulé</th><th>BC</th><th>Solde</th></tr>";

    foreach ($suspects as $s) {
        echo "<tr>";
        echo "<td>" . $s['compte'] . "</td>";
        echo "<td>" . htmlspecialchars($s['intitule_compte']) . "</td>";
        echo "<td>" . ($s['bc'] ?: '-') . "</td>";
        echo "<td style='text-align: right'>" . number_format($s['solde'], 2, ',', ' ') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: green;'>✓ Aucun compte de résultat ou compte 13 suspect détecté.</p>";
}
