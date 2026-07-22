<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer les paramètres
$annee = $_GET['annee'] ?? date('Y');
$classe_filter = $_GET['classe'] ?? '';
$show_zero = isset($_GET['show_zero']) && $_GET['show_zero'] == '1';

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction helper
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Générer la liste des mois
$mois_labels = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Utiliser le fichier optimisé pour calculer le rapport mensuel
require_once 'calcul_rapport_mensuel_optimise.php';
// Maintenant $rapport_mensuel et $totaux_par_mois sont disponibles

// Générer l'Excel
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);

// Fonction helper pour convertir un index en lettre de colonne
function getColumnLetter($index) {
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
}

// Titre
$totalCols = 3 + (12 * 3); // Compte + Intitulé + Tableau + (12 mois × 3 colonnes) = 39
$lastCol = getColumnLetter($totalCols); // AM (colonne 39)

$sheet->setCellValue('A1', 'RAPPORT MENSUEL');
$sheet->mergeCells('A1:' . $lastCol . '1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Informations
$sheet->setCellValue('A2', 'Année: ' . $annee);
$sheet->mergeCells('A2:' . $lastCol . '2');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

if (!empty($classe_filter)) {
    $sheet->setCellValue('A3', 'Classe: ' . $classe_filter);
    $sheet->mergeCells('A3:' . $lastCol . '3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row = 5;
} else {
    $row = 4;
}

// En-tête - Première ligne
$colIndex = 1;
$sheet->setCellValue(getColumnLetter($colIndex) . $row, 'Compte');
$sheet->mergeCells(getColumnLetter($colIndex) . $row . ':' . getColumnLetter($colIndex) . ($row + 1));
$colIndex++;

$sheet->setCellValue(getColumnLetter($colIndex) . $row, 'Intitulé');
$sheet->mergeCells(getColumnLetter($colIndex) . $row . ':' . getColumnLetter($colIndex) . ($row + 1));
$colIndex++;

$sheet->setCellValue(getColumnLetter($colIndex) . $row, 'Tableau');
$sheet->mergeCells(getColumnLetter($colIndex) . $row . ':' . getColumnLetter($colIndex) . ($row + 1));
$colIndex++;

// En-têtes des mois
foreach ($mois_labels as $num_mois => $label_mois) {
    $startCol = getColumnLetter($colIndex);
    $sheet->setCellValue($startCol . $row, $label_mois . ' ' . $annee);
    $endCol = getColumnLetter($colIndex + 2);
    $sheet->mergeCells($startCol . $row . ':' . $endCol . $row);
    $colIndex += 3;
}

// Style de la première ligne d'en-tête
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF4682B4');
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row++;

// En-tête - Deuxième ligne (Débit, Crédit, Rubrique pour chaque mois)
$colIndex = 4; // Commencer après Compte, Intitulé, Tableau
for ($m = 1; $m <= 12; $m++) {
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, 'Débit');
    $colIndex++;
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, 'Crédit');
    $colIndex++;
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, 'Rubrique');
    $colIndex++;
}

$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF4682B4');
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row++;

// Données
foreach ($rapport_mensuel as $ligne) {
    $colIndex = 1;
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, $ligne['compte']);
    $colIndex++;
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, $ligne['intitule']);
    $colIndex++;
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, $ligne['tableau']);
    $colIndex++;

    for ($mois = 1; $mois <= 12; $mois++) {
        $solde_mois = $ligne['soldes_mois'][$mois];

        // Débit
        $sheet->setCellValue(getColumnLetter($colIndex) . $row, $solde_mois['debit'] > 0 ? $solde_mois['debit'] : '');
        if ($solde_mois['debit'] > 0) {
            $sheet->getStyle(getColumnLetter($colIndex) . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $colIndex++;

        // Crédit
        $sheet->setCellValue(getColumnLetter($colIndex) . $row, $solde_mois['credit'] > 0 ? $solde_mois['credit'] : '');
        if ($solde_mois['credit'] > 0) {
            $sheet->getStyle(getColumnLetter($colIndex) . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $colIndex++;

        // Rubrique
        $sheet->setCellValue(getColumnLetter($colIndex) . $row, $solde_mois['rubrique']);
        $colIndex++;
    }

    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
}

// Ligne de totaux
$sheet->setCellValue('A' . $row, 'TOTAUX');
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row . ':C' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('4682B4');
$sheet->getStyle('A' . $row . ':C' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$colIndex = 4;
for ($mois = 1; $mois <= 12; $mois++) {
    // Total Débit
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, $totaux_par_mois[$mois]['total_debit']);
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getFont()->setBold(true);
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('90EE90');
    $colIndex++;

    // Total Crédit
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, $totaux_par_mois[$mois]['total_credit']);
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getFont()->setBold(true);
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('FFB6C1');
    $colIndex++;

    // Rubrique (vide pour totaux)
    $sheet->setCellValue(getColumnLetter($colIndex) . $row, '');
    $sheet->getStyle(getColumnLetter($colIndex) . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('D3D3D3');
    $colIndex++;
}

$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);
$row++;

// Figer les volets (Compte, Intitulé, Tableau)
$sheet->freezePane('D' . ($row - count($rapport_mensuel)));

// Ajuster la largeur des colonnes
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(50);
$sheet->getColumnDimension('C')->setWidth(12);

// Largeur des colonnes de mois
$colIndex = 4;
for ($m = 1; $m <= 12; $m++) {
    $sheet->getColumnDimension(getColumnLetter($colIndex))->setWidth(15); // Débit
    $colIndex++;
    $sheet->getColumnDimension(getColumnLetter($colIndex))->setWidth(15); // Crédit
    $colIndex++;
    $sheet->getColumnDimension(getColumnLetter($colIndex))->setWidth(25); // Rubrique
    $colIndex++;
}

// Générer le fichier
$writer = new Xlsx($spreadsheet);
$filename = 'Rapport_Mensuel_' . $annee . '_' . date('YmdHis') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
