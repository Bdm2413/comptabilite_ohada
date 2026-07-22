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
$id_rapprochement = $_GET['id'] ?? 0;

if (empty($id_rapprochement)) {
    die("ID de rapprochement manquant");
}

// Récupérer le rapprochement
$stmt = $db->prepare("
    SELECT r.*, pc.intitule_compte
    FROM rapprochements_bancaires r
    LEFT JOIN plan_comptable pc ON r.compte_banque = pc.compte
    WHERE r.id = ? AND r.societe_id = ?
");
$stmt->execute([$id_rapprochement, $societe_id]);
$rapprochement = $stmt->fetch();

if (!$rapprochement) {
    die("Rapprochement introuvable");
}

// Récupérer les lignes
$stmt = $db->prepare("
    SELECT * FROM rapprochements_lignes
    WHERE id_rapprochement = ?
    ORDER BY date_operation, id
");
$stmt->execute([$id_rapprochement]);
$lignes = $stmt->fetchAll();

$mois_names = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

// Créer le fichier Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);
$sheet->setTitle('Rapprochement Bancaire');

// En-tête principal
$row = 1;
$sheet->setCellValue('A' . $row, 'RAPPORT DE RAPPROCHEMENT BANCAIRE');
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF0891B2');
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Informations du compte
$sheet->setCellValue('A' . $row, 'Compte : ' . $rapprochement['compte_banque'] . ' - ' . $rapprochement['intitule_compte']);
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Période : ' . $mois_names[$rapprochement['mois']] . ' ' . $rapprochement['annee']);
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Du ' . date('d/m/Y', strtotime($rapprochement['date_debut'])) . ' au ' . date('d/m/Y', strtotime($rapprochement['date_fin'])));
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 2;

// Section Tableau de Rapprochement
$sheet->setCellValue('A' . $row, 'TABLEAU DE RAPPROCHEMENT');
$sheet->mergeCells('A' . $row . ':B' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF0891B2');
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0F2FE');
$row++;

// Solde comptable
$sheet->setCellValue('A' . $row, 'Solde comptable au ' . date('d/m/Y', strtotime($rapprochement['date_fin'])));
$sheet->setCellValue('B' . $row, $rapprochement['solde_comptable']);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->setBold(true)->getColor()->setARGB('FF0891B2');
$row++;

// Solde bancaire
$sheet->setCellValue('A' . $row, 'Solde bancaire (relevé)');
$sheet->setCellValue('B' . $row, $rapprochement['solde_bancaire']);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->setBold(true)->getColor()->setARGB('FF1E40AF');
$row++;

// Écart
$sheet->setCellValue('A' . $row, 'Écart à justifier');
$sheet->setCellValue('B' . $row, $rapprochement['ecart_calcule']);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEF3C7');
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF92400E');
$sheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEF3C7');

// Bordures pour le tableau récap
$sheet->getStyle('A' . ($row - 3) . ':B' . $row)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF94A3B8']
        ]
    ]
]);
$row += 2;

// Calculer les totaux des justifications
$total_debit_justif = 0;
$total_credit_justif = 0;
foreach ($lignes as $ligne) {
    if ($ligne['type_operation'] === 'Débit') {
        $total_debit_justif += $ligne['montant'];
    } else {
        $total_credit_justif += $ligne['montant'];
    }
}
$solde_ajuste = $rapprochement['solde_comptable'] + $total_debit_justif - $total_credit_justif;
$diff_finale = $solde_ajuste - $rapprochement['solde_bancaire'];

// Section Calcul du Solde Ajusté
$sheet->setCellValue('A' . $row, 'CALCUL DU SOLDE AJUSTÉ');
$sheet->mergeCells('A' . $row . ':B' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF7C3AED');
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3E8FF');
$row++;

// Total justifications au débit
$sheet->setCellValue('A' . $row, 'Total justifications au débit');
$sheet->setCellValue('B' . $row, $total_debit_justif);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FF059669');
$row++;

// Total justifications au crédit
$sheet->setCellValue('A' . $row, 'Total justifications au crédit');
$sheet->setCellValue('B' . $row, $total_credit_justif);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FFDC2626');
$row++;

// Nouveau solde comptable ajusté
$sheet->setCellValue('A' . $row, 'Nouveau solde comptable ajusté');
$sheet->setCellValue('B' . $row, $solde_ajuste);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3E8FF');
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF7C3AED');
$sheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3E8FF');
$row++;

// Différence finale avec le solde bancaire
$bgColor = abs($diff_finale) < 0.01 ? 'FFD1FAE5' : 'FFFEF3C7';
$textColor = abs($diff_finale) < 0.01 ? 'FF065F46' : 'FF92400E';
$sheet->setCellValue('A' . $row, 'Différence finale avec le solde bancaire');
$sheet->setCellValue('B' . $row, $diff_finale);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgColor);
$sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB($textColor);
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('B' . $row)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB($textColor);
$sheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgColor);

// Bordures pour le tableau solde ajusté
$sheet->getStyle('A' . ($row - 4) . ':B' . $row)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF94A3B8']
        ]
    ]
]);
$row += 2;

// Section Lignes de Justification
if (!empty($lignes)) {
    $sheet->setCellValue('A' . $row, 'LIGNES DE JUSTIFICATION (' . count($lignes) . ' ligne' . (count($lignes) > 1 ? 's' : '') . ')');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF0891B2');
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0F2FE');
    $row++;

    // En-têtes de colonnes
    $headers = ['Date', 'Libellé', 'Catégorie', 'Type', 'Montant'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0891B2');
        $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $col++;
    }
    $row++;

    // Données
    foreach ($lignes as $ligne) {
        $sheet->setCellValue('A' . $row, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($ligne['date_operation'])));
        $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('B' . $row, $ligne['libelle']);
        $sheet->setCellValue('C' . $row, $ligne['categorie']);
        $sheet->setCellValue('D' . $row, $ligne['type_operation']);
        $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if ($ligne['type_operation'] === 'Débit') {
            $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FF059669');
        } else {
            $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FFDC2626');
        }

        $sheet->setCellValue('E' . $row, $ligne['montant']);
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Alternance de couleurs
        if ($row % 2 == 0) {
            $sheet->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
        }

        $row++;
    }

    // Totaux
    $sheet->setCellValue('A' . $row, 'TOTAUX');
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('D' . $row, 'Débit');
    $sheet->setCellValue('E' . $row, $total_debit_justif);
    $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FF059669');
    $sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB('FF059669');
    $row++;

    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('D' . $row, 'Crédit');
    $sheet->setCellValue('E' . $row, $total_credit_justif);
    $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FFDC2626');
    $sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB('FFDC2626');

    // Bordures pour le tableau des lignes
    $lignesStartRow = $row - count($lignes) - 2;
    $sheet->getStyle('A' . $lignesStartRow . ':E' . $row)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF94A3B8']
            ]
        ]
    ]);

    $row += 2;
}

// Notes
if (!empty($rapprochement['notes'])) {
    $sheet->setCellValue('A' . $row, 'NOTES ET COMMENTAIRES');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEF3C7');
    $row++;

    $sheet->setCellValue('A' . $row, $rapprochement['notes']);
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setWrapText(true);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFBEB');
    $sheet->getRowDimension($row)->setRowHeight(-1); // Auto-height
}

// Largeurs de colonnes
$sheet->getColumnDimension('A')->setWidth(15);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(25);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(18);

// Générer le fichier
$filename = 'Rapprochement_Bancaire_' . $rapprochement['compte_banque'] . '_' . $rapprochement['mois'] . '_' . $rapprochement['annee'] . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
