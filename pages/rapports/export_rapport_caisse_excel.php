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

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$compte_caisse = $_GET['compte_caisse'] ?? '5711000';

// Récupérer l'intitulé du compte
$stmt = $db->prepare("SELECT intitule_compte FROM plan_comptable WHERE compte = ?");
$stmt->execute([$compte_caisse]);
$compte_info = $stmt->fetch();
$intitule_caisse = $compte_info['intitule_compte'] ?? 'Caisse';

// Calculer le solde initial
$stmt = $db->prepare("
    SELECT COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0) as solde_initial
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    WHERE le.compte = ? AND e.date_ecriture < ? AND e.statut = 'Validé' AND e.societe_id = ?
");
$stmt->execute([$compte_caisse, $date_debut, $societe_id]);
$result = $stmt->fetch();
$solde_initial = $result['solde_initial'] ?? 0;

// Récupérer les transactions
$stmt = $db->prepare("
    SELECT
        e.date_ecriture,
        e.numero_ecriture,
        e.libelle as libelle_ecriture,
        le.libelle as libelle_ligne,
        le.debit,
        le.credit,
        t.nom as tiers_nom,
        le.id
    FROM lignes_ecriture le
    INNER JOIN ecritures e ON le.id_ecriture = e.id
    LEFT JOIN plan_tiers t ON e.id_tiers = t.id
    WHERE le.compte = ? AND e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' AND e.societe_id = ?
    GROUP BY le.id
    ORDER BY e.date_ecriture ASC, e.numero_ecriture ASC, le.id ASC
");
$stmt->execute([$compte_caisse, $date_debut, $date_fin, $societe_id]);
$transactions = $stmt->fetchAll();

// Calculer les totaux
$solde_courant = $solde_initial;
$total_entrees = 0;
$total_sorties = 0;

foreach ($transactions as &$trans) {
    if ($trans['debit'] > 0) {
        $total_entrees += $trans['debit'];
        $solde_courant += $trans['debit'];
    }
    if ($trans['credit'] > 0) {
        $total_sorties += $trans['credit'];
        $solde_courant -= $trans['credit'];
    }
    $trans['solde_apres'] = $solde_courant;
}

// Créer le fichier Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);
$sheet->setTitle('Rapport Caisse');

// En-tête du rapport
$row = 1;
$sheet->setCellValue('A' . $row, 'RAPPORT DE CAISSE');
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, $compte_caisse . ' - ' . $intitule_caisse);
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)));
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 2;

// Statistiques
$sheet->setCellValue('A' . $row, 'Solde Initial:');
$sheet->setCellValue('B' . $row, $solde_initial);
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$row++;

$sheet->setCellValue('A' . $row, 'Total Entrées:');
$sheet->setCellValue('B' . $row, $total_entrees);
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FF00AA00');
$row++;

$sheet->setCellValue('A' . $row, 'Total Sorties:');
$sheet->setCellValue('B' . $row, $total_sorties);
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FFFF0000');
$row++;

$sheet->setCellValue('A' . $row, 'Solde Final:');
$sheet->setCellValue('B' . $row, $solde_courant);
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getFont()->setBold(true);
$row += 2;

// En-têtes du tableau
$headers = ['DATE', 'NUM', 'PAID TO / RECEIVED FROM', 'PURPOSE', 'GL ACCOUNT CODES', 'CASH IN', 'CASH OUT', 'BALANCE'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $sheet->getStyle($col . $row)->getFont()->setBold(true);
    $sheet->getStyle($col . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF334155');
    $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $col++;
}
$row++;

// Ligne OPENING BALANCE
$sheet->setCellValue('A' . $row, 'OPENING BALANCE');
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->setCellValue('F' . $row, '-');
$sheet->setCellValue('G' . $row, '-');
$sheet->setCellValue('H' . $row, $solde_initial);
$sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':H' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF1E293B');
$sheet->getStyle('A' . $row . ':H' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$row++;

// Données des transactions
foreach ($transactions as $trans) {
    $sheet->setCellValue('A' . $row, date('d/m/y', strtotime($trans['date_ecriture'])));
    $sheet->setCellValue('B' . $row, $trans['numero_ecriture']);
    $sheet->setCellValue('C' . $row, $trans['tiers_nom'] ?? '-');
    $sheet->setCellValue('D' . $row, $trans['libelle_ligne'] ?: $trans['libelle_ecriture']);
    $sheet->setCellValue('E' . $row, $compte_caisse);
    $sheet->setCellValue('F' . $row, $trans['debit'] > 0 ? $trans['debit'] : '');
    $sheet->setCellValue('G' . $row, $trans['credit'] > 0 ? $trans['credit'] : '');
    $sheet->setCellValue('H' . $row, $trans['solde_apres']);

    // Format des montants
    $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

    // Couleurs
    if ($trans['debit'] > 0) {
        $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF00AA00');
    }
    if ($trans['credit'] > 0) {
        $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FFFF0000');
    }

    $row++;
}

// Ligne TOTAL
$sheet->setCellValue('A' . $row, 'TOTAL');
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->setCellValue('F' . $row, $total_entrees);
$sheet->setCellValue('G' . $row, $total_sorties);
$sheet->setCellValue('H' . $row, $solde_courant);
$sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':H' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF475569');
$sheet->getStyle('A' . $row . ':H' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');

// Bordures pour tout le tableau
$lastRow = $row;
$sheet->getStyle('A10:H' . $lastRow)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF64748B']
        ]
    ]
]);

// Ajuster la largeur des colonnes
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(25);
$sheet->getColumnDimension('D')->setWidth(40);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);

// Alignement
$sheet->getStyle('F10:H' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Générer le fichier
$filename = 'Rapport_Caisse_' . $compte_caisse . '_' . date('Y-m-d', strtotime($date_debut)) . '_' . date('Y-m-d', strtotime($date_fin)) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
