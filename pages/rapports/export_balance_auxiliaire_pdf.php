<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$type_tiers = isset($_GET['type_tiers']) ? $_GET['type_tiers'] : '';
$show_zero = isset($_GET['show_zero']) ? true : false;

// Récupérer la balance auxiliaire
$balance = [];
$totaux = [
    'debit_anterieur' => 0,
    'credit_anterieur' => 0,
    'debit_periode' => 0,
    'credit_periode' => 0,
    'debit_final' => 0,
    'credit_final' => 0
];

try {
    // Requête pour récupérer tous les tiers avec leurs mouvements
    $sql = "
        SELECT
            pt.id,
            pt.nom,
            pt.type,
            pt.compte_tiers,
            -- Mouvements antérieurs (avant date_debut)
            COALESCE(SUM(CASE WHEN e.date_ecriture < ? THEN le.debit ELSE 0 END), 0) as debit_anterieur,
            COALESCE(SUM(CASE WHEN e.date_ecriture < ? THEN le.credit ELSE 0 END), 0) as credit_anterieur,
            -- Mouvements période
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? THEN le.debit ELSE 0 END), 0) as debit_periode,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? THEN le.credit ELSE 0 END), 0) as credit_periode
        FROM plan_tiers pt
        LEFT JOIN lignes_ecriture le ON le.compte_tiers = pt.compte_tiers AND le.societe_id = pt.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut = 'Validé' AND e.societe_id = pt.societe_id
        WHERE pt.actif = 1
        AND pt.societe_id = ?
    ";

    $params = [$date_debut, $date_debut, $date_debut, $date_fin, $date_debut, $date_fin, $societe_id];

    if (!empty($type_tiers)) {
        $sql .= " AND pt.type = ?";
        $params[] = $type_tiers;
    }

    $sql .= " GROUP BY pt.id, pt.nom, pt.type, pt.compte_tiers ORDER BY pt.type, pt.nom";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $resultats = $stmt->fetchAll();

    foreach ($resultats as $row) {
        // Calculer les soldes
        $solde_anterieur = $row['debit_anterieur'] - $row['credit_anterieur'];
        $solde_final = ($row['debit_anterieur'] + $row['debit_periode']) - ($row['credit_anterieur'] + $row['credit_periode']);

        // Filtrer les tiers à solde nul si demandé
        if (!$show_zero && abs($solde_final) < 0.01 && abs($row['debit_periode']) < 0.01 && abs($row['credit_periode']) < 0.01) {
            continue;
        }

        $ligne = [
            'id' => $row['id'],
            'nom' => $row['nom'],
            'type' => $row['type'],
            'compte_tiers' => $row['compte_tiers'],
            'debit_anterieur' => $solde_anterieur > 0 ? $solde_anterieur : 0,
            'credit_anterieur' => $solde_anterieur < 0 ? abs($solde_anterieur) : 0,
            'debit_periode' => $row['debit_periode'],
            'credit_periode' => $row['credit_periode'],
            'debit_final' => $solde_final > 0 ? $solde_final : 0,
            'credit_final' => $solde_final < 0 ? abs($solde_final) : 0,
        ];

        $balance[] = $ligne;

        // Cumuler les totaux
        $totaux['debit_anterieur'] += $ligne['debit_anterieur'];
        $totaux['credit_anterieur'] += $ligne['credit_anterieur'];
        $totaux['debit_periode'] += $ligne['debit_periode'];
        $totaux['credit_periode'] += $ligne['credit_periode'];
        $totaux['debit_final'] += $ligne['debit_final'];
        $totaux['credit_final'] += $ligne['credit_final'];
    }

} catch (Exception $e) {
    die('Erreur lors du calcul de la balance: ' . $e->getMessage());
}

// Créer le PDF
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Informations du document
$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor('Comptabilité OHADA');
$pdf->SetTitle('Balance Auxiliaire');
$pdf->SetSubject('Balance Auxiliaire');

// Supprimer header/footer par défaut
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Ajouter une page
$pdf->AddPage();

// Marges
$pdf->SetMargins(10, 10, 10);

// Police
$pdf->SetFont('helvetica', 'B', 16);

// Titre
$pdf->Cell(0, 5, 'BALANCE AUXILIAIRE', 0, 1, 'C');
$pdf->Ln(2);

// Période
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
if (!empty($type_tiers)) {
    $pdf->Cell(0, 5, 'Type: ' . $type_tiers, 0, 1, 'C');
}
$pdf->Ln(3);

// En-tête du tableau
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(41, 128, 185);
$pdf->SetTextColor(255, 255, 255);

// Première ligne d'en-tête
$pdf->Cell(20, 6, 'Compte', 1, 0, 'C', true);
$pdf->Cell(50, 6, 'Nom', 1, 0, 'C', true);
$pdf->Cell(20, 6, 'Type', 1, 0, 'C', true);
$pdf->Cell(40, 6, 'Soldes Antérieurs', 1, 0, 'C', true);
$pdf->Cell(40, 6, 'Mouvements Période', 1, 0, 'C', true);
$pdf->Cell(40, 6, 'Soldes Finaux', 1, 1, 'C', true);

// Deuxième ligne d'en-tête
$pdf->Cell(20, 5, '', 0, 0, 'C');
$pdf->Cell(50, 5, '', 0, 0, 'C');
$pdf->Cell(20, 5, '', 0, 0, 'C');
$pdf->Cell(20, 5, 'Débit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Crédit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Débit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Crédit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Débit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Crédit', 1, 1, 'C', true);

// Données
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);

$fill = false;
$current_type = '';

foreach ($balance as $ligne) {
    // Ajouter une ligne de séparation pour chaque type
    if ($current_type !== $ligne['type'] && empty($type_tiers)) {
        $current_type = $ligne['type'];
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(270, 5, $current_type . 's', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 7);
    }

    // Alterner les couleurs de fond
    if ($fill) {
        $pdf->SetFillColor(245, 245, 245);
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }

    $pdf->Cell(20, 5, $ligne['compte_tiers'], 1, 0, 'L', true);
    $pdf->Cell(50, 5, mb_substr($ligne['nom'], 0, 30), 1, 0, 'L', true);
    $pdf->Cell(20, 5, $ligne['type'], 1, 0, 'C', true);
    $pdf->Cell(20, 5, $ligne['debit_anterieur'] > 0 ? safe_number_format($ligne['debit_anterieur']) : '-', 1, 0, 'R', true);
    $pdf->Cell(20, 5, $ligne['credit_anterieur'] > 0 ? safe_number_format($ligne['credit_anterieur']) : '-', 1, 0, 'R', true);
    $pdf->Cell(20, 5, $ligne['debit_periode'] > 0 ? safe_number_format($ligne['debit_periode']) : '-', 1, 0, 'R', true);
    $pdf->Cell(20, 5, $ligne['credit_periode'] > 0 ? safe_number_format($ligne['credit_periode']) : '-', 1, 0, 'R', true);
    $pdf->Cell(20, 5, $ligne['debit_final'] > 0 ? safe_number_format($ligne['debit_final']) : '-', 1, 0, 'R', true);
    $pdf->Cell(20, 5, $ligne['credit_final'] > 0 ? safe_number_format($ligne['credit_final']) : '-', 1, 1, 'R', true);

    $fill = !$fill;
}

// Ligne de totaux
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(41, 128, 185);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(90, 6, 'TOTAUX', 1, 0, 'C', true);
$pdf->Cell(20, 6, safe_number_format($totaux['debit_anterieur']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['credit_anterieur']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['debit_periode']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['credit_periode']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['debit_final']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['credit_final']), 1, 1, 'R', true);

// Génération du fichier
$filename = 'Balance_Auxiliaire_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
exit;
