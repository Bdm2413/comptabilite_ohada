<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$db = Database::getInstance()->getConnection();

// Récupérer les paramètres
$compte_filter = $_GET['compte'] ?? '';
$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$journal_filter = $_GET['journal'] ?? '';

if (empty($compte_filter)) {
    die("Veuillez sélectionner un compte");
}

// Récupérer les informations du compte
$stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE compte = ?");
$stmt->execute([$compte_filter]);
$compte_info = $stmt->fetch();

if (!$compte_info) {
    die("Compte introuvable");
}

// Calculer le solde initial
$premiere_classe = substr($compte_filter, 0, 1);
$est_compte_resultat = in_array($premiere_classe, ['6', '7', '8']);
$annee_debut = date('Y', strtotime($date_debut));
$date_debut_calcul = $est_compte_resultat ? $annee_debut . '-01-01' : '1900-01-01';

$stmt = $db->prepare("
    SELECT COALESCE(SUM(le.debit), 0) as total_debit, COALESCE(SUM(le.credit), 0) as total_credit
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE le.compte = ? AND e.statut = 'Validé' AND e.date_ecriture >= ? AND e.date_ecriture < ?
");
$stmt->execute([$compte_filter, $date_debut_calcul, $date_debut]);
$solde_data = $stmt->fetch();
$solde_initial = ($solde_data['total_debit'] ?? 0) - ($solde_data['total_credit'] ?? 0);

// Récupérer les lignes du grand livre
$sql = "
    SELECT
        e.date_ecriture, e.numero_ecriture, e.journal,
        cj.journal as journal_libelle, e.num_piece,
        e.libelle, le.libelle as libelle_ligne, le.numero_facture,
        le.debit, le.credit, pt.nom as tiers_nom
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    LEFT JOIN code_journal cj ON e.journal = cj.code
    LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
    WHERE le.compte = ? AND e.statut = 'Validé' AND e.date_ecriture BETWEEN ? AND ?
";

$params = [$compte_filter, $date_debut, $date_fin];

if (!empty($journal_filter)) {
    $sql .= " AND e.journal = ?";
    $params[] = $journal_filter;
}

$sql .= " ORDER BY e.date_ecriture, e.numero_ecriture";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

// Créer le fichier Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);
$sheet->setTitle('Grand Livre');

// En-tête
$row = 1;
$sheet->setCellValue('A' . $row, 'GRAND LIVRE');
$sheet->mergeCells('A' . $row . ':K' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Compte : ' . $compte_filter . ' - ' . $compte_info['intitule_compte']);
$sheet->mergeCells('A' . $row . ':K' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)));
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

// Ligne solde initial
$sheet->setCellValue('A' . $row, 'Solde au ' . date('d/m/Y', strtotime($date_debut)));
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, '-');
$sheet->setCellValue('J' . $row, '-');
$sheet->setCellValue('K' . $row, $solde_initial);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':K' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$row++;

// Données
$solde_courant = $solde_initial;
$total_debit = 0;
$total_credit = 0;

foreach ($lignes as $ligne) {
    $solde_courant += ($ligne['debit'] - $ligne['credit']);
    $total_debit += $ligne['debit'];
    $total_credit += $ligne['credit'];

    $sheet->setCellValue('A' . $row, date('d/m/Y', strtotime($ligne['date_ecriture'])));
    $sheet->setCellValue('B' . $row, $ligne['numero_ecriture']);
    $sheet->setCellValue('C' . $row, $ligne['journal']);
    $sheet->setCellValue('D' . $row, $compte_filter);
    $sheet->setCellValue('E' . $row, $ligne['num_piece'] ?? '-');
    $sheet->setCellValue('F' . $row, $ligne['numero_facture'] ?? '-');
    $sheet->setCellValue('G' . $row, $ligne['libelle_ligne'] ?? '-');
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
$sheet->setCellValue('A' . $row, 'Total des mouvements');
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

// Ligne solde final
$sheet->setCellValue('A' . $row, 'Solde au ' . date('d/m/Y', strtotime($date_fin)));
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, '-');
$sheet->setCellValue('J' . $row, '-');
$sheet->setCellValue('K' . $row, $solde_courant);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':K' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
$sheet->getStyle('A' . $row . ':K' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');

// Bordures
$lastRow = $row;
$sheet->getStyle('A5:K' . $lastRow)->applyFromArray([
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
$sheet->getStyle('I5:K' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Générer le fichier
$filename = 'Grand_Livre_' . $compte_filter . '_' . date('Ymd', strtotime($date_debut)) . '_' . date('Ymd', strtotime($date_fin)) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
