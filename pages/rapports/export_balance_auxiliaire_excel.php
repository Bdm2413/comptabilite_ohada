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

// Créer un nouveau document Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);

// Titre
$sheet->setCellValue('A1', 'BALANCE AUXILIAIRE');
$sheet->mergeCells('A1:I1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Période
$row = 2;
$sheet->setCellValue('A' . $row, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)));
$sheet->mergeCells('A' . $row . ':I' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

if (!empty($type_tiers)) {
    $row++;
    $sheet->setCellValue('A' . $row, 'Type: ' . $type_tiers);
    $sheet->mergeCells('A' . $row . ':I' . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$row += 2;

// En-tête du tableau - Première ligne
$sheet->setCellValue('A' . $row, 'Compte');
$sheet->setCellValue('B' . $row, 'Nom');
$sheet->setCellValue('C' . $row, 'Type');
$sheet->setCellValue('D' . $row, 'Soldes Antérieurs');
$sheet->mergeCells('D' . $row . ':E' . $row);
$sheet->setCellValue('F' . $row, 'Mouvements Période');
$sheet->mergeCells('F' . $row . ':G' . $row);
$sheet->setCellValue('H' . $row, 'Soldes Finaux');
$sheet->mergeCells('H' . $row . ':I' . $row);

// Style en-tête première ligne
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2980B9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray($headerStyle);

$row++;

// En-tête du tableau - Deuxième ligne
$sheet->setCellValue('A' . $row, '');
$sheet->setCellValue('B' . $row, '');
$sheet->setCellValue('C' . $row, '');
$sheet->setCellValue('D' . $row, 'Débit');
$sheet->setCellValue('E' . $row, 'Crédit');
$sheet->setCellValue('F' . $row, 'Débit');
$sheet->setCellValue('G' . $row, 'Crédit');
$sheet->setCellValue('H' . $row, 'Débit');
$sheet->setCellValue('I' . $row, 'Crédit');
$sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray($headerStyle);

$row++;

// Données
$current_type = '';
foreach ($balance as $ligne) {
    // Ajouter une ligne de séparation pour chaque type
    if ($current_type !== $ligne['type'] && empty($type_tiers)) {
        $current_type = $ligne['type'];
        $sheet->setCellValue('A' . $row, $current_type . 's');
        $sheet->mergeCells('A' . $row . ':I' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6E6E6');
        $sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        $row++;
    }

    $sheet->setCellValue('A' . $row, $ligne['compte_tiers']);
    $sheet->setCellValue('B' . $row, $ligne['nom']);
    $sheet->setCellValue('C' . $row, $ligne['type']);
    $sheet->setCellValue('D' . $row, $ligne['debit_anterieur'] > 0 ? $ligne['debit_anterieur'] : 0);
    $sheet->setCellValue('E' . $row, $ligne['credit_anterieur'] > 0 ? $ligne['credit_anterieur'] : 0);
    $sheet->setCellValue('F' . $row, $ligne['debit_periode'] > 0 ? $ligne['debit_periode'] : 0);
    $sheet->setCellValue('G' . $row, $ligne['credit_periode'] > 0 ? $ligne['credit_periode'] : 0);
    $sheet->setCellValue('H' . $row, $ligne['debit_final'] > 0 ? $ligne['debit_final'] : 0);
    $sheet->setCellValue('I' . $row, $ligne['credit_final'] > 0 ? $ligne['credit_final'] : 0);

    // Format nombres
    $sheet->getStyle('D' . $row . ':I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('D' . $row . ':I' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Bordures
    $sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);

    $row++;
}

// Ligne totaux
$sheet->setCellValue('A' . $row, 'TOTAUX');
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->setCellValue('D' . $row, $totaux['debit_anterieur']);
$sheet->setCellValue('E' . $row, $totaux['credit_anterieur']);
$sheet->setCellValue('F' . $row, $totaux['debit_periode']);
$sheet->setCellValue('G' . $row, $totaux['credit_periode']);
$sheet->setCellValue('H' . $row, $totaux['debit_final']);
$sheet->setCellValue('I' . $row, $totaux['credit_final']);

// Style ligne totaux
$totalStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2980B9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray($totalStyle);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('D' . $row . ':I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

// Ajuster la largeur des colonnes
$sheet->getColumnDimension('A')->setWidth(15);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(15);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);
$sheet->getColumnDimension('I')->setWidth(15);

// Générer le fichier Excel
$filename = 'Balance_Auxiliaire_' . date('Y-m-d', strtotime($date_fin)) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
