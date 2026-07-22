<?php
require_once 'config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

echo "<h1>Diagnostic Équilibre Bilan</h1>";
echo "<p>Période: du $date_debut au $date_fin</p>";
echo "<hr>";

// Calculer le résultat net de l'exercice (classes 6, 7, 8)
$sql_resultat = "
    SELECT
        COALESCE(SUM(le.credit - le.debit), 0) as resultat_net
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND (LEFT(pc.compte, 1) IN ('6', '7', '8'))
    AND e.date_ecriture BETWEEN ? AND ?
    AND e.statut = 'Validé'
";
$stmt = $db->prepare($sql_resultat);
$stmt->execute([$date_debut, $date_fin]);
$resultat_net_exercice = $stmt->fetchColumn();

echo "<h2>1. Résultat Net de l'Exercice</h2>";
echo "<p>Résultat calculé (Classes 6, 7, 8): " . number_format($resultat_net_exercice, 2, ',', ' ') . " FCFA</p>";
echo "<hr>";

// Vérifier le compte 13 (Résultat net de l'exercice)
$sql_compte_13 = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        COALESCE(SUM(le.debit), 0) as total_debit,
        COALESCE(SUM(le.credit), 0) as total_credit,
        COALESCE(SUM(le.credit - le.debit), 0) as solde
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 2) = '13'
    AND e.date_ecriture <= ?
    AND e.statut = 'Validé'
    GROUP BY pc.compte, pc.intitule_compte
";
$stmt = $db->prepare($sql_compte_13);
$stmt->execute([$date_fin]);
$comptes_13 = $stmt->fetchAll();

echo "<h2>2. Comptes 13 (Résultat net)</h2>";
$total_compte_13 = 0;
foreach ($comptes_13 as $c) {
    echo "<p>{$c['compte']} - {$c['intitule_compte']}: " . number_format($c['solde'], 2, ',', ' ') . " FCFA</p>";
    $total_compte_13 += $c['solde'];
}
echo "<p><strong>Total compte 13: " . number_format($total_compte_13, 2, ',', ' ') . " FCFA</strong></p>";
echo "<hr>";

// Calculer Total Actif (BZ)
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
    GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc
    ORDER BY pc.compte
";

$stmt = $db->prepare($sql);
$stmt->execute([$date_fin, $date_fin]);
$comptes = $stmt->fetchAll();

$rubriques_actif = ['AF', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AS', 'AZ', 'BA', 'BB', 'BG', 'BH', 'BI', 'BJ', 'BK', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BZ'];
$rubriques_passif = ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM', 'CP', 'DA', 'DB', 'DC', 'DD', 'DF', 'DH', 'DI', 'DJ', 'DK', 'DM', 'DN', 'DP', 'DQ', 'DR', 'DT', 'DV', 'DZ'];

$actif = [];
$passif = [];

foreach ($comptes as $compte) {
    $solde = $compte['total_debit'] - $compte['total_credit'];
    $prefix2 = substr($compte['compte'], 0, 2);
    $prefix3 = substr($compte['compte'], 0, 3);

    if ($solde == 0) continue;
    if ($prefix3 == '416' || $prefix3 == '426' || $prefix3 == '491' || $prefix3 == '496') continue;

    if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
        if (!empty($compte['bd'])) {
            $ref = $compte['bd'];
            if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
            $actif[$ref]['amort_deprec'] += abs($solde);
            $actif[$ref]['net'] -= abs($solde);
        }
    } elseif ($compte['bd'] != $compte['bc']) {
        if ($solde > 0 && !empty($compte['bd'])) {
            $ref = $compte['bd'];
            if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
            $actif[$ref]['brut'] += $solde;
            $actif[$ref]['net'] += $solde;
        } elseif ($solde < 0 && !empty($compte['bc'])) {
            $ref = $compte['bc'];
            if (!isset($passif[$ref])) $passif[$ref] = ['net' => 0];
            $passif[$ref]['net'] += abs($solde);
        }
    } elseif ($compte['bd'] == $compte['bc'] && !empty($compte['bd'])) {
        $ref = $compte['bd'];
        if (in_array($ref, $rubriques_actif)) {
            if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
            $actif[$ref]['brut'] += $solde;
            $actif[$ref]['net'] += $solde;
        } elseif (in_array($ref, $rubriques_passif)) {
            if (!isset($passif[$ref])) $passif[$ref] = ['net' => 0];
            $passif[$ref]['net'] -= $solde;
        }
    }
}

// Calcul des totaux
$actif['BZ']['net'] =
    ($actif['AZ']['net'] ?? 0) +
    ($actif['BK']['net'] ?? 0) +
    ($actif['BT']['net'] ?? 0) +
    ($actif['BU']['net'] ?? 0);

// Report à nouveau
$sql_report = "
    SELECT COALESCE(SUM(le.credit - le.debit), 0) as report
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 2) = '12'
    AND e.date_ecriture < ?
    AND e.statut = 'Validé'
";
$stmt = $db->prepare($sql_report);
$stmt->execute([$date_debut]);
$passif['CF']['net'] = $stmt->fetchColumn();

$passif['CP']['net'] =
    ($passif['CA']['net'] ?? 0) +
    ($passif['CB']['net'] ?? 0) +
    ($passif['CD']['net'] ?? 0) +
    ($passif['CE']['net'] ?? 0) +
    ($passif['CF']['net'] ?? 0) +
    ($passif['CG']['net'] ?? 0) +
    ($passif['CH']['net'] ?? 0) +
    ($passif['CJ']['net'] ?? 0) +
    ($passif['CL']['net'] ?? 0) +
    ($passif['CM']['net'] ?? 0);

$passif['DD']['net'] = ($passif['DA']['net'] ?? 0) + ($passif['DB']['net'] ?? 0) + ($passif['DC']['net'] ?? 0);
$passif['DF']['net'] = ($passif['CP']['net'] ?? 0) + ($passif['DD']['net'] ?? 0);
$passif['DP']['net'] = ($passif['DH']['net'] ?? 0) + ($passif['DI']['net'] ?? 0) + ($passif['DJ']['net'] ?? 0) +
                       ($passif['DK']['net'] ?? 0) + ($passif['DM']['net'] ?? 0) + ($passif['DN']['net'] ?? 0);
$passif['DT']['net'] = ($passif['DQ']['net'] ?? 0) + ($passif['DR']['net'] ?? 0);
$passif['DZ']['net'] = ($passif['DF']['net'] ?? 0) + ($passif['DP']['net'] ?? 0) + ($passif['DT']['net'] ?? 0) + ($passif['DV']['net'] ?? 0);

echo "<h2>3. Totaux Bilan</h2>";
echo "<p><strong>Total Actif (BZ): " . number_format($actif['BZ']['net'], 2, ',', ' ') . " FCFA</strong></p>";
echo "<p><strong>Total Passif (DZ): " . number_format($passif['DZ']['net'], 2, ',', ' ') . " FCFA</strong></p>";
echo "<p><strong>Différence (Actif - Passif): " . number_format($actif['BZ']['net'] - $passif['DZ']['net'], 2, ',', ' ') . " FCFA</strong></p>";
echo "<hr>";

echo "<h2>4. Détail Capitaux Propres (CP)</h2>";
echo "<p>CA (Capital): " . number_format($passif['CA']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CB (Apports): " . number_format($passif['CB']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CD (Écarts réévaluation): " . number_format($passif['CD']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CE (Écarts équivalence): " . number_format($passif['CE']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CF (Report à nouveau): " . number_format($passif['CF']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CG (Primes liées capital): " . number_format($passif['CG']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CH (Réserves): " . number_format($passif['CH']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CJ (Résultat net): " . number_format($passif['CJ']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CL (Subventions): " . number_format($passif['CL']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p>CM (Provisions réglementées): " . number_format($passif['CM']['net'] ?? 0, 2, ',', ' ') . " FCFA</p>";
echo "<p><strong>Total CP: " . number_format($passif['CP']['net'] ?? 0, 2, ',', ' ') . " FCFA</strong></p>";
echo "<hr>";

echo "<h2>5. Analyse</h2>";
$difference = $actif['BZ']['net'] - $passif['DZ']['net'];
echo "<p>La différence entre Actif et Passif est de: <strong>" . number_format(abs($difference), 2, ',', ' ') . " FCFA</strong></p>";
echo "<p>Cette différence correspond ";
if (abs($difference - $resultat_net_exercice) < 1) {
    echo "EXACTEMENT au résultat net de l'exercice.</p>";
    echo "<p style='color: red;'><strong>PROBLÈME IDENTIFIÉ:</strong> Le résultat net de l'exercice n'est pas inclus dans le passif (rubrique CJ).</p>";
} else {
    echo "à " . number_format(abs($difference - $resultat_net_exercice), 2, ',', ' ') . " FCFA du résultat net.</p>";
}

echo "<hr>";
echo "<h2>6. Solution</h2>";
echo "<p>Le résultat net de l'exercice doit être ajouté au passif dans la rubrique CJ pour équilibrer le bilan.</p>";
echo "<p>Formule: CJ = Résultat net de l'exercice (Classes 6, 7, 8)</p>";
