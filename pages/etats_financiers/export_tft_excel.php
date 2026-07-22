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

// Paramètres
$date_debut_n = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin_n   = $_GET['date_fin']   ?? date('Y-12-31');
$date_debut_n1 = date('Y-m-d', strtotime($date_debut_n . ' -1 year'));
$date_fin_n1   = date('Y-m-d', strtotime($date_fin_n   . ' -1 year'));
$annee_n  = date('Y', strtotime($date_fin_n));
$annee_n1 = date('Y', strtotime($date_fin_n1));

// Requête unique
$sql = "
    SELECT pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS cum_debit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS cum_credit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS mvt_debit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS mvt_credit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS cum_debit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS cum_credit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS mvt_debit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS mvt_credit_N1
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON le.compte = pc.compte AND le.societe_id = pc.societe_id
    LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut='Validé' AND e.societe_id = pc.societe_id
    WHERE pc.actif='Oui' AND pc.societe_id = ?
    GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc
    ORDER BY pc.compte
";
$stmt = $db->prepare($sql);
$stmt->execute([
    $date_fin_n, $date_fin_n,
    $date_debut_n, $date_fin_n,
    $date_debut_n, $date_fin_n,
    $date_fin_n1, $date_fin_n1,
    $date_debut_n1, $date_fin_n1,
    $date_debut_n1, $date_fin_n1,
    $societe_id
]);
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/tft_calculs.php';

// =====================================================================
// GÉNÉRATION EXCEL
// =====================================================================
$ss = new Spreadsheet();
$ws = $ss->getActiveSheet();
$ws->setTitle('TFT');

// Helpers de style
$nf = fn($v) => (abs($v) < 0.5) ? '' : number_format($v, 0, ',', ' ');

// Couleurs
$C_HEADER  = 'FF1E3A5F'; // bleu foncé
$C_SECTION = 'FF2D4A6B'; // bleu section
$C_TOTAL   = 'FF0D5C3A'; // vert total
$C_SUBTOT  = 'FF1A3A2E'; // vert sous-total
$C_WHITE   = 'FFFFFFFF';
$C_YELLOW  = 'FFFFF3CD';
$C_GRAY    = 'FFF0F4F8';

$row = 1;

// Titre
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", 'TABLEAU DE FLUX DE TRÉSORERIE — SYSCOHADA RÉVISÉ');
$ws->getStyle("A{$row}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => $C_WHITE]],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_HEADER]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$ws->getRowDimension($row)->setRowHeight(22);
$row++;

// Sous-titre période
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", "Période : " . date('d/m/Y', strtotime($date_debut_n)) . " au " . date('d/m/Y', strtotime($date_fin_n)));
$ws->getStyle("A{$row}")->applyFromArray([
    'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => $C_WHITE]],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_HEADER]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$row++;

// En-têtes colonnes
$headers = ['REF', 'LIBELLÉS', 'NOTE', "EXERCICE\n{$annee_n}", "EXERCICE\n{$annee_n1}"];
foreach (['A','B','C','D','E'] as $i => $col) {
    $ws->setCellValue("{$col}{$row}", $headers[$i]);
    $ws->getStyle("{$col}{$row}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['argb' => $C_WHITE]],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF374151']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF4B5563']]],
    ]);
}
$ws->getRowDimension($row)->setRowHeight(28);
$row++;

// Fonctions helpers pour lignes
$styleRow = function(string $ref, string $label, string $note, $vN, $vN1, string $type = 'normal') use ($ws, &$row, $nf, $C_TOTAL, $C_SUBTOT, $C_SECTION, $C_GRAY, $C_WHITE) {
    $isTotal   = $type === 'total';
    $isSubtot  = $type === 'subtotal';
    $isSection = $type === 'section';

    if ($isSection) {
        $ws->mergeCells("A{$row}:E{$row}");
        $ws->setCellValue("A{$row}", strtoupper($label));
        $ws->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 8, 'color' => ['argb' => $C_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF374151']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $ws->getRowDimension($row)->setRowHeight(14);
        $row++;
        return;
    }

    $fillColor = $isTotal ? $C_TOTAL : ($isSubtot ? $C_SUBTOT : 'FFFFFFFF');
    $fontColor = ($isTotal || $isSubtot) ? $C_WHITE : 'FF1F2937';

    $ws->setCellValue("A{$row}", $ref);
    $ws->setCellValue("B{$row}", $label);
    $ws->setCellValue("C{$row}", $note);
    if (abs((float)$vN) >= 0.5) { $ws->setCellValue("D{$row}", (float)$vN); }
    if (abs((float)$vN1) >= 0.5) { $ws->setCellValue("E{$row}", (float)$vN1); }

    $styleBase = [
        'font'    => ['bold' => $isTotal || $isSubtot, 'color' => ['argb' => $fontColor]],
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $fillColor]],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
    ];
    $ws->getStyle("A{$row}:E{$row}")->applyFromArray($styleBase);
    $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->getRowDimension($row)->setRowHeight(14);
    $row++;
};

// Données du tableau
$styleRow('ZA', 'Trésorerie nette au 1er janvier', 'A', $ZA_N, $ZA_N1);
$styleRow('', 'Flux de trésorerie provenant des activités opérationnelles', '', '', '', 'section');
$styleRow('FA', 'Capacité d\'Autofinancement Globale (CAFG)', '', $FA_N, $FA_N1);
$styleRow('FB', '- Variation d\'actif circulant HAO', '', -$FB_N, -$FB_N1);
$styleRow('FC', '- Variation des stocks', '', -$FC_N, -$FC_N1);
$styleRow('FD', '- Variation des créances', '', -$FD_N, -$FD_N1);
$styleRow('FE', '+ Variation du passif circulant', '', $FE_N, $FE_N1);
$styleRow('', 'Variation du BF lié aux activités opérationnelles (FB+FC+FD+FE)', '', $BF_N, $BF_N1);
$styleRow('ZB', 'Flux de trésorerie provenant des activités opérationnelles (Somme FA à FE)', 'B', $ZB_N, $ZB_N1, 'total');

$styleRow('', 'Flux de trésorerie provenant des activités d\'investissements', '', '', '', 'section');
$styleRow('FF', '- Décaissements liés aux acquisitions d\'immos incorporelles', '', -$FF_N, -$FF_N1);
$styleRow('FG', '- Décaissements liés aux acquisitions d\'immos corporelles', '', -$FG_N, -$FG_N1);
$styleRow('FH', '- Décaissements liés aux acquisitions d\'immos financières', '', -$FH_N, -$FH_N1);
$styleRow('FI', '+ Encaissements liés aux cessions d\'immos incorp. et corp.', '', $FI_N, $FI_N1);
$styleRow('FJ', '+ Encaissements liés aux cessions d\'immos financières', '', $FJ_N, $FJ_N1);
$styleRow('ZC', 'Flux de trésorerie provenant des activités d\'investissement (somme FF à FJ)', 'C', $ZC_N, $ZC_N1, 'total');

$styleRow('', 'Flux de trésorerie provenant du financement par les capitaux propres', '', '', '', 'section');
$styleRow('FK', '+ Augmentations de capital par apports nouveaux', '', $FK_N, $FK_N1);
$styleRow('FL', '+ Subventions d\'investissement reçues', '', $FL_N, $FL_N1);
$styleRow('FM', '- Prélèvements sur le capital', '', -$FM_N, -$FM_N1);
$styleRow('FN', '- Dividendes versés', '', -$FN_N, -$FN_N1);
$styleRow('ZD', 'Flux de trésorerie provenant des capitaux propres (somme FK à FN)', 'D', $ZD_N, $ZD_N1, 'subtotal');

$styleRow('', 'Trésorerie provenant du financement par les capitaux étrangers', '', '', '', 'section');
$styleRow('FO', '+ Emprunts', '', $FO_N, $FO_N1);
$styleRow('FP', '+ Autres dettes financières diverses', '', $FP_N, $FP_N1);
$styleRow('FQ', '- Remboursements des emprunts et autres dettes financières', '', -$FQ_N, -$FQ_N1);
$styleRow('ZE', 'Flux de trésorerie provenant des capitaux étrangers (somme FO à FQ)', 'E', $ZE_N, $ZE_N1, 'subtotal');

$styleRow('ZF', 'Flux de trésorerie provenant des activités de financement (D+E)', 'F', $ZF_N, $ZF_N1, 'total');
$styleRow('ZG', 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (B+C+F)', 'G', $ZG_N, $ZG_N1, 'total');
$styleRow('ZH', 'Trésorerie nette au 31 Décembre (G+A)', 'H', $ZH_check_N, $ZH_check_N1, 'total');

// Notes de bas
$row++;
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", '(1) À l\'exclusion des variations des créances et dettes liées aux activités d\'investissement et de financement.');
$ws->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8);
$row++;
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", '(2) Comptes 161, 162, 1661, 1662');
$ws->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8);
$row++;
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", '(3) Comptes 16 sauf (161, 162, 1661, 1662) et comptes 18');
$ws->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8);

// Largeurs colonnes
$ws->getColumnDimension('A')->setWidth(7);
$ws->getColumnDimension('B')->setWidth(62);
$ws->getColumnDimension('C')->setWidth(7);
$ws->getColumnDimension('D')->setWidth(20);
$ws->getColumnDimension('E')->setWidth(20);

// Format numérique colonnes valeurs
$ws->getStyle('D4:E500')->getNumberFormat()->setFormatCode('#,##0.00');

// Désactiver le quadrillage
$ws->setShowGridlines(false);

// Figer l'en-tête
$ws->freezePane('A4');

// Export
$filename = 'TFT_' . $annee_n . '_' . date('YmdHis') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
