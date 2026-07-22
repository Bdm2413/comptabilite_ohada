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
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$journal_filter = isset($_GET['journal']) ? $_GET['journal'] : '';
$statut_filter = isset($_GET['statut']) ? $_GET['statut'] : 'Validé';

// Récupérer les écritures
$ecritures = [];
$total_debit = 0;
$total_credit = 0;

try {
    $sql = "
        SELECT
            e.id,
            e.numero_ecriture,
            e.date_ecriture,
            e.journal,
            cj.libelle as journal_libelle,
            e.libelle,
            e.num_piece,
            e.reference_piece,
            e.statut,
            pt.nom as tiers_nom
        FROM ecritures e
        LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
        LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
        WHERE e.societe_id = ? AND e.date_ecriture BETWEEN ? AND ?
    ";

    $params = [$societe_id, $date_debut, $date_fin];

    if (!empty($journal_filter)) {
        $sql .= " AND e.journal = ?";
        $params[] = $journal_filter;
    }

    if (!empty($statut_filter)) {
        $sql .= " AND e.statut = ?";
        $params[] = $statut_filter;
    }

    $sql .= " ORDER BY e.date_ecriture, e.numero_ecriture";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $ecritures_data = $stmt->fetchAll();

    // Pour chaque écriture, récupérer ses lignes
    foreach ($ecritures_data as $ecriture) {
        $stmt = $db->prepare("
            SELECT
                le.*,
                pc.intitule_compte
            FROM lignes_ecriture le
            LEFT JOIN plan_comptable pc ON le.compte = pc.compte
            WHERE le.id_ecriture = ?
            ORDER BY le.id
        ");
        $stmt->execute([$ecriture['id']]);
        $lignes = $stmt->fetchAll();

        // Calculer les totaux de l'écriture
        $ecriture_debit = 0;
        $ecriture_credit = 0;
        foreach ($lignes as $ligne) {
            $ecriture_debit += $ligne['debit'];
            $ecriture_credit += $ligne['credit'];
        }

        $ecritures[] = [
            'ecriture' => $ecriture,
            'lignes' => $lignes,
            'total_debit' => $ecriture_debit,
            'total_credit' => $ecriture_credit
        ];

        $total_debit += $ecriture_debit;
        $total_credit += $ecriture_credit;
    }

} catch (Exception $e) {
    die('Erreur lors du chargement: ' . $e->getMessage());
}

// Créer le PDF
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Informations du document
$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor('Comptabilité OHADA');
$pdf->SetTitle('Journal Général');
$pdf->SetSubject('Journal Général');

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
$pdf->Cell(0, 5, 'JOURNAL GÉNÉRAL', 0, 1, 'C');
$pdf->Ln(2);

// Période
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
if (!empty($journal_filter)) {
    $pdf->Cell(0, 5, 'Journal: ' . $journal_filter, 0, 1, 'C');
}
if (!empty($statut_filter)) {
    $pdf->Cell(0, 5, 'Statut: ' . $statut_filter, 0, 1, 'C');
}
$pdf->Ln(3);

// Pour chaque écriture
foreach ($ecritures as $item) {
    $e = $item['ecriture'];
    $lignes = $item['lignes'];

    // En-tête de l'écriture
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(70, 130, 180);
    $pdf->SetTextColor(255, 255, 255);

    $header_text = 'Écriture #' . $e['numero_ecriture'] . ' - ' . date('d/m/Y', strtotime($e['date_ecriture'])) . ' - ' . $e['journal'];
    $pdf->Cell(0, 6, $header_text, 1, 1, 'L', true);

    // Libellé et informations
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(0, 5, 'Libellé: ' . mb_substr($e['libelle'], 0, 100), 1, 1, 'L', true);

    if ($e['num_piece'] || $e['reference_piece'] || $e['tiers_nom']) {
        $info_text = '';
        if ($e['num_piece']) $info_text .= 'N° Pièce: ' . $e['num_piece'] . '  ';
        if ($e['reference_piece']) $info_text .= 'Réf: ' . $e['reference_piece'] . '  ';
        if ($e['tiers_nom']) $info_text .= 'Tiers: ' . $e['tiers_nom'];
        $pdf->Cell(0, 5, $info_text, 1, 1, 'L', true);
    }

    // En-tête tableau lignes
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->Cell(25, 5, 'Compte', 1, 0, 'L', true);
    $pdf->Cell(85, 5, 'Intitulé', 1, 0, 'L', true);
    $pdf->Cell(85, 5, 'Libellé ligne', 1, 0, 'L', true);
    $pdf->Cell(30, 5, 'Débit', 1, 0, 'R', true);
    $pdf->Cell(30, 5, 'Crédit', 1, 1, 'R', true);

    // Lignes de l'écriture
    $pdf->SetFont('helvetica', '', 7);
    foreach ($lignes as $ligne) {
        $pdf->Cell(25, 5, $ligne['compte'], 1, 0, 'L');
        $pdf->Cell(85, 5, mb_substr($ligne['intitule_compte'], 0, 45), 1, 0, 'L');
        $pdf->Cell(85, 5, mb_substr($ligne['libelle'] ?? '-', 0, 50), 1, 0, 'L');
        $pdf->Cell(30, 5, $ligne['debit'] > 0 ? safe_number_format($ligne['debit']) : '-', 1, 0, 'R');
        $pdf->Cell(30, 5, $ligne['credit'] > 0 ? safe_number_format($ligne['credit']) : '-', 1, 1, 'R');
    }

    // Total de l'écriture
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(195, 5, 'Total écriture', 1, 0, 'R', true);
    $pdf->Cell(30, 5, safe_number_format($item['total_debit']), 1, 0, 'R', true);
    $pdf->Cell(30, 5, safe_number_format($item['total_credit']), 1, 1, 'R', true);

    $pdf->Ln(3);
}

// Totaux généraux
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(41, 128, 185);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(195, 7, 'TOTAUX GÉNÉRAUX', 1, 0, 'C', true);
$pdf->Cell(30, 7, safe_number_format($total_debit), 1, 0, 'R', true);
$pdf->Cell(30, 7, safe_number_format($total_credit), 1, 1, 'R', true);

// Équilibre
$equilibre = abs($total_debit - $total_credit) < 0.01;
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(195, 6, 'Équilibre', 1, 0, 'C', true);
$pdf->Cell(60, 6, $equilibre ? 'Équilibré' : 'Déséquilibré', 1, 1, 'C', true);

// Génération du fichier
$filename = 'Journal_General_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
exit;
