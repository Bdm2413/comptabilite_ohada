<?php
require_once '../../config/config.php';
requireLogin();

// Vérifier si PhpSpreadsheet est disponible
$composerAutoload = '../../vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    die("PhpSpreadsheet n'est pas installé. Veuillez installer les dépendances avec 'composer install'.");
}

require_once $composerAutoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Créer un nouveau spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Budget ' . date('Y'));

// En-têtes
$headers = [
    'A1' => 'Compte',
    'B1' => 'Intitulé',
    'C1' => 'Rubrique',
    'D1' => 'Compte Oracle',
    'E1' => 'Janvier',
    'F1' => 'Février',
    'G1' => 'Mars',
    'H1' => 'Avril',
    'I1' => 'Mai',
    'J1' => 'Juin',
    'K1' => 'Juillet',
    'L1' => 'Août',
    'M1' => 'Septembre',
    'N1' => 'Octobre',
    'O1' => 'Novembre',
    'P1' => 'Décembre'
];

// Appliquer les en-têtes
foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

// Style des en-têtes
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '4F46E5']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$sheet->getStyle('A1:P1')->applyFromArray($headerStyle);

// Ajuster la hauteur de la ligne d'en-tête
$sheet->getRowDimension(1)->setRowHeight(25);

// Exemples de données
$exemples = [
    ['601100', 'Achats de marchandises', 'ACHATS', '6011000', 50000, 52000, 48000, 51000, 53000, 49000, 50000, 51000, 52000, 54000, 55000, 60000],
    ['701100', 'Ventes de marchandises', 'CA', '7011000', 120000, 125000, 115000, 122000, 128000, 118000, 120000, 123000, 126000, 130000, 135000, 145000],
    ['661100', 'Salaires', 'PERSONNEL', '6611000', 80000, 80000, 80000, 80000, 80000, 80000, 80000, 80000, 80000, 80000, 80000, 85000],
    ['605300', 'Fournitures de bureau', 'FOURNITURES', '6053000', 2000, 2000, 2000, 2000, 2500, 2000, 2000, 2000, 2000, 2500, 2000, 3000],
];

$row = 2;
foreach ($exemples as $exemple) {
    $col = 'A';
    foreach ($exemple as $value) {
        $sheet->setCellValue($col . $row, $value);
        $col++;
    }
    $row++;
}

// Style des données exemples
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
];
$sheet->getStyle('A2:P5')->applyFromArray($dataStyle);

// Ajouter des lignes vides formatées
for ($i = 0; $i < 10; $i++) {
    $sheet->setCellValue('A' . ($row + $i), '');
    $sheet->setCellValue('B' . ($row + $i), '');
    $sheet->setCellValue('C' . ($row + $i), '');
    $sheet->setCellValue('D' . ($row + $i), '');
    for ($col = 'E'; $col <= 'P'; $col++) {
        $sheet->setCellValue($col . ($row + $i), 0);
    }
}

// Style des lignes vides
$sheet->getStyle('A' . $row . ':P' . ($row + 9))->applyFromArray($dataStyle);

// Ajuster la largeur des colonnes
$sheet->getColumnDimension('A')->setWidth(15);  // Compte
$sheet->getColumnDimension('B')->setWidth(30);  // Intitulé
$sheet->getColumnDimension('C')->setWidth(20);  // Rubrique
$sheet->getColumnDimension('D')->setWidth(15);  // Compte Oracle
for ($col = 'E'; $col <= 'P'; $col++) {
    $sheet->getColumnDimension($col)->setWidth(12);  // Mois
}

// Formater les cellules de montants
$sheet->getStyle('E2:P' . ($row + 9))->getNumberFormat()->setFormatCode('#,##0');

// Générer le fichier
$filename = 'modele_budget_' . date('Y') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
