<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$journal_filter = isset($_GET['journal']) ? $_GET['journal'] : '';
$statut_filter = isset($_GET['statut']) ? $_GET['statut'] : 'Validé';

// Récupérer toutes les lignes d'écritures avec leurs informations
$sql = "
    SELECT
        e.date_ecriture, e.numero_ecriture, e.journal,
        cj.libelle as journal_libelle, e.num_piece,
        e.libelle as libelle_ecriture, le.libelle as libelle_ligne,
        le.numero_facture, le.compte, pc.intitule_compte,
        le.debit, le.credit,
        COALESCE(pt_ligne.nom, pt_ecriture.nom) as tiers_nom
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
    LEFT JOIN plan_comptable pc ON le.compte = pc.compte
    LEFT JOIN plan_tiers pt_ligne ON le.compte_tiers = pt_ligne.compte_tiers
    LEFT JOIN plan_tiers pt_ecriture ON e.id_tiers = pt_ecriture.id
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

$sql .= " ORDER BY e.date_ecriture, e.numero_ecriture, le.id";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

// Créer le fichier Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Journal Général');

// En-tête
$row = 1;
$sheet->setCellValue('A' . $row, 'JOURNAL GÉNÉRAL');
$sheet->mergeCells('A' . $row . ':K' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Période et filtres
$periode_text = 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin));
if (!empty($journal_filter)) {
    $periode_text .= ' - Journal: ' . $journal_filter;
}
if (!empty($statut_filter)) {
    $periode_text .= ' - Statut: ' . $statut_filter;
}
$sheet->setCellValue('A' . $row, $periode_text);
$sheet->mergeCells('A' . $row . ':K' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 2;

// En-têtes de colonnes
$headers = ['Date', 'N° Écriture', 'Journal', 'Compte', 'N° Pièce', 'N° Facture', 'Libellé', 'Tiers', 'Débit', 'Crédit', 'Solde'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $sheet->getStyle($col . $row)->getFont()->setBold(true);
    $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF334155');
    $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $col++;
}
$row++;

// Données
$solde_courant = 0;
$total_debit = 0;
$total_credit = 0;

foreach ($lignes as $ligne) {
    $solde_courant += ($ligne['debit'] - $ligne['credit']);
    $total_debit += $ligne['debit'];
    $total_credit += $ligne['credit'];

    // Convertir la date en format Excel
    $sheet->setCellValue('A' . $row, Date::PHPToExcel(strtotime($ligne['date_ecriture'])));
    $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

    $sheet->setCellValue('B' . $row, $ligne['numero_ecriture']);
    $sheet->setCellValue('C' . $row, $ligne['journal']);
    $sheet->setCellValue('D' . $row, $ligne['compte']);
    $sheet->setCellValue('E' . $row, $ligne['num_piece'] ?? '-');
    $sheet->setCellValue('F' . $row, $ligne['numero_facture'] ?? '-');

    // Utiliser le libellé de la ligne, ou à défaut le libellé de l'écriture
    $libelle_display = !empty($ligne['libelle_ligne']) ? $ligne['libelle_ligne'] : $ligne['libelle_ecriture'];
    $sheet->setCellValue('G' . $row, $libelle_display);

    $sheet->setCellValue('H' . $row, $ligne['tiers_nom'] ?? '-');
    $sheet->setCellValue('I' . $row, $ligne['debit'] > 0 ? $ligne['debit'] : '');
    $sheet->setCellValue('J' . $row, $ligne['credit'] > 0 ? $ligne['credit'] : '');
    $sheet->setCellValue('K' . $row, $solde_courant);

    $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

    if ($ligne['debit'] > 0) {
        $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('FF00AA00');
    }
    if ($ligne['credit'] > 0) {
        $sheet->getStyle('J' . $row)->getFont()->getColor()->setARGB('FFFF0000');
    }

    $row++;
}

// Ligne totaux
$sheet->setCellValue('A' . $row, 'TOTAUX GÉNÉRAUX');
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, $total_debit);
$sheet->setCellValue('J' . $row, $total_credit);
$sheet->setCellValue('K' . $row, '');
$sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':K' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF475569');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$row++;

// Ligne équilibre
$equilibre = abs($total_debit - $total_credit) < 0.01;
$sheet->setCellValue('A' . $row, 'Équilibre');
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, $equilibre ? 'Équilibré' : 'Déséquilibré');
$sheet->mergeCells('I' . $row . ':K' . $row);
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':K' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Bordures
$lastRow = $row;
$sheet->getStyle('A4:K' . $lastRow)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF64748B']
        ]
    ]
]);

// Largeurs de colonnes
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(10);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(40);
$sheet->getColumnDimension('H')->setWidth(20);
$sheet->getColumnDimension('I')->setWidth(15);
$sheet->getColumnDimension('J')->setWidth(15);
$sheet->getColumnDimension('K')->setWidth(15);

// Alignement
$sheet->getStyle('I4:K' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Générer le fichier
$filename = 'Journal_General_Format_Grand_Livre_' . date('Ymd', strtotime($date_debut)) . '_' . date('Ymd', strtotime($date_fin)) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
