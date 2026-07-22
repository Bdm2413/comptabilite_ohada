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
$societe_id = getCurrentSocieteId();
$annee = $_GET['annee'] ?? date('Y');

// Récupérer toutes les CCA actives
$stmt = $db->prepare("
    SELECT *
    FROM charges_constatees_avance
    WHERE societe_id = ?
    AND statut = 'Actif'
    AND (
        (YEAR(date_debut) <= ? AND YEAR(date_fin) >= ?)
        OR (YEAR(date_debut) = ? OR YEAR(date_fin) = ?)
    )
    ORDER BY date_debut
");
$stmt->execute([$societe_id, $annee, $annee, $annee, $annee]);
$charges = $stmt->fetchAll();

// Construire le récapitulatif mensuel
$recap_mensuel = [];
for ($mois = 1; $mois <= 12; $mois++) {
    $recap_mensuel[$mois] = ['charges' => [], 'total' => 0];
}

foreach ($charges as $charge) {
    $date_debut = new DateTime($charge['date_debut']);
    $date_fin = new DateTime($charge['date_fin']);
    $date_courante = clone $date_debut;

    while ($date_courante <= $date_fin) {
        if ((int)$date_courante->format('Y') == $annee) {
            $mois = (int)$date_courante->format('n');
            $recap_mensuel[$mois]['charges'][] = [
                'numero_facture' => $charge['numero_facture'],
                'description' => $charge['description'],
                'compte_charge' => $charge['compte_charge'],
                'montant' => $charge['montant_mensuel'],
                'nb_mois' => $charge['nb_mois']
            ];
            $recap_mensuel[$mois]['total'] += $charge['montant_mensuel'];
        }
        $date_courante->modify('+1 month');
    }
}

// Créer le fichier Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);
$sheet->setTitle('Récap Mensuel CCA');

// En-tête
$row = 1;
$sheet->setCellValue('A' . $row, 'RÉCAPITULATIF MENSUEL - CHARGES CONSTATÉES D\'AVANCE');
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Année : ' . $annee);
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 2;

$mois_fr = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

$total_annuel = 0;

// Pour chaque mois
foreach ($recap_mensuel as $mois => $data) {
    if (count($data['charges']) > 0) {
        // En-tête du mois
        $sheet->setCellValue('A' . $row, $mois_fr[$mois]);
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->setCellValue('E' . $row, 'Total:');
        $sheet->setCellValue('F' . $row, $data['total']);
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':F' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF334155');
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;

        // En-têtes colonnes
        $sheet->setCellValue('A' . $row, 'N° Facture');
        $sheet->setCellValue('B' . $row, 'Description');
        $sheet->setCellValue('C' . $row, 'Compte');
        $sheet->setCellValue('D' . $row, 'Nb Mois');
        $sheet->setCellValue('E' . $row, 'Montant');
        $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':E' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF475569');
        $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
        $row++;

        // Détails
        foreach ($data['charges'] as $charge) {
            $sheet->setCellValue('A' . $row, $charge['numero_facture']);
            $sheet->setCellValue('B' . $row, $charge['description']);
            $sheet->setCellValue('C' . $row, $charge['compte_charge']);
            $sheet->setCellValue('D' . $row, $charge['nb_mois']);
            $sheet->setCellValue('E' . $row, $charge['montant']);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        $total_annuel += $data['total'];
        $row++;
    }
}

// Total annuel
$sheet->setCellValue('A' . $row, 'TOTAL ANNUEL');
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->setCellValue('F' . $row, $total_annuel);
$sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A' . $row . ':F' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF1E293B');
$sheet->getStyle('A' . $row . ':F' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

// Largeurs de colonnes
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(50);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(18);

// Bordures
$sheet->getStyle('A1:F' . $row)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF64748B']
        ]
    ]
]);

// Générer le fichier
$filename = 'Recap_CCA_' . $annee . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
