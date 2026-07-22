<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer les paramètres
$compte = $_GET['compte'] ?? '';
$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$journal = $_GET['journal'] ?? '';

if (empty($compte)) {
    die('Compte non spécifié');
}

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les informations du compte
$stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE compte = ?");
$stmt->execute([$compte]);
$compte_info = $stmt->fetch();

if (!$compte_info) {
    die('Compte introuvable');
}

// Calculer le solde initial
$stmt = $db->prepare("
    SELECT
        COALESCE(SUM(le.debit), 0) as total_debit,
        COALESCE(SUM(le.credit), 0) as total_credit
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE le.compte = ?
    AND e.statut = 'Validé'
    AND e.date_ecriture < ?
");
$stmt->execute([$compte, $date_debut]);
$solde_data = $stmt->fetch();
$solde_initial = ($solde_data['total_debit'] ?? 0) - ($solde_data['total_credit'] ?? 0);

// Récupérer les lignes du grand livre
$sql = "
    SELECT
        e.date_ecriture,
        e.numero_ecriture,
        e.journal,
        cj.libelle as journal_libelle,
        e.num_piece,
        e.libelle,
        le.libelle as libelle_ligne,
        le.numero_facture,
        le.debit,
        le.credit,
        NULL as lettrage,
        NULL as statut_lettrage,
        pt.nom as tiers_nom
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
    LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
    WHERE le.compte = ?
    AND e.societe_id = ?
    AND e.statut = 'Validé'
    AND e.date_ecriture BETWEEN ? AND ?
";

$params = [$compte, $societe_id, $date_debut, $date_fin];

if (!empty($journal)) {
    $sql .= " AND e.journal = ?";
    $params[] = $journal;
}

$sql .= " ORDER BY e.date_ecriture, e.numero_ecriture";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

// Générer le PDF avec TCPDF
require_once '../../vendor/autoload.php';


$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Informations du document
$pdf->SetCreator('ComptaSYSCOHADA');
$pdf->SetAuthor($_SESSION['user_name']);
$pdf->SetTitle('Grand Livre - Compte ' . $compte_info['compte']);
$pdf->SetSubject('Grand Livre');

// Marges
$pdf->SetMargins(10, 10, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Police
$pdf->SetFont('helvetica', '', 10);

// Ajouter une page
$pdf->AddPage();

// En-tête
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'GRAND LIVRE', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Compte: ' . $compte_info['compte'] . ' - ' . $compte_info['intitule_compte'], 0, 1, 'C');
$pdf->Cell(0, 6, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
$pdf->Ln(5);

// En-tête du tableau
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(20, 7, 'Date', 1, 0, 'C', true);
$pdf->Cell(20, 7, 'N° Écriture', 1, 0, 'C', true);
$pdf->Cell(12, 7, 'Journal', 1, 0, 'C', true);
$pdf->Cell(16, 7, 'Compte', 1, 0, 'C', true);
$pdf->Cell(15, 7, 'N° Pièce', 1, 0, 'C', true);
$pdf->Cell(14, 7, 'N° Fact.', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Libellé', 1, 0, 'C', true);
$pdf->Cell(28, 7, 'Débit', 1, 0, 'C', true);
$pdf->Cell(28, 7, 'Crédit', 1, 0, 'C', true);
$pdf->Cell(28, 7, 'Solde', 1, 0, 'C', true);
$pdf->Cell(18, 7, 'Lettrage', 1, 1, 'C', true);

// Ligne solde initial
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(142, 6, 'Solde au ' . date('d/m/Y', strtotime($date_debut)), 1, 0, 'L');
$pdf->Cell(28, 6, '-', 1, 0, 'R');
$pdf->Cell(28, 6, '-', 1, 0, 'R');
$solde_text = number_format(abs($solde_initial), 2, ',', ' ') . ' ' . ($solde_initial >= 0 ? 'D' : 'C');
$pdf->Cell(28, 6, $solde_text, 1, 0, 'R');
$pdf->Cell(18, 6, '-', 1, 1, 'C');

// Données
$pdf->SetFont('helvetica', '', 7);
$solde_courant = $solde_initial;
$total_debit = 0;
$total_credit = 0;

foreach ($lignes as $ligne) {
    $solde_courant += ($ligne['debit'] - $ligne['credit']);
    $total_debit += $ligne['debit'];
    $total_credit += $ligne['credit'];

    $pdf->Cell(20, 6, date('d/m/Y', strtotime($ligne['date_ecriture'])), 1, 0, 'C');
    $pdf->Cell(20, 6, $ligne['numero_ecriture'], 1, 0, 'C');
    $pdf->Cell(12, 6, $ligne['journal'], 1, 0, 'C');
    $pdf->Cell(16, 6, $compte, 1, 0, 'C');
    $pdf->Cell(15, 6, $ligne['num_piece'] ?? '-', 1, 0, 'C');
    $pdf->Cell(14, 6, $ligne['numero_facture'] ?? '-', 1, 0, 'C');
    $pdf->Cell(45, 6, substr($ligne['libelle_ligne'] ?? $ligne['libelle'], 0, 30), 1, 0, 'L');
    $pdf->Cell(28, 6, $ligne['debit'] > 0 ? number_format($ligne['debit'], 2, ',', ' ') : '-', 1, 0, 'R');
    $pdf->Cell(28, 6, $ligne['credit'] > 0 ? number_format($ligne['credit'], 2, ',', ' ') : '-', 1, 0, 'R');
    $solde_text = number_format(abs($solde_courant), 2, ',', ' ') . ' ' . ($solde_courant >= 0 ? 'D' : 'C');
    $pdf->Cell(28, 6, $solde_text, 1, 0, 'R');
    $pdf->Cell(18, 6, $ligne['lettrage'] ?? '-', 1, 1, 'C');
}

// Ligne totaux
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(142, 6, 'Total des mouvements', 1, 0, 'L');
$pdf->Cell(28, 6, number_format($total_debit, 2, ',', ' '), 1, 0, 'R');
$pdf->Cell(28, 6, number_format($total_credit, 2, ',', ' '), 1, 0, 'R');
$pdf->Cell(28, 6, '', 1, 0, 'R');
$pdf->Cell(18, 6, '', 1, 1, 'C');

// Ligne solde final
$pdf->Cell(142, 6, 'Solde au ' . date('d/m/Y', strtotime($date_fin)), 1, 0, 'L');
$pdf->Cell(28, 6, '-', 1, 0, 'R');
$pdf->Cell(28, 6, '-', 1, 0, 'R');
$solde_text = number_format(abs($solde_courant), 2, ',', ' ') . ' ' . ($solde_courant >= 0 ? 'D' : 'C');
$pdf->Cell(28, 6, $solde_text, 1, 0, 'R');
$pdf->Cell(18, 6, '', 1, 1, 'C');

// Sortie du PDF
$filename = 'GrandLivre_' . $compte_info['compte'] . '_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
