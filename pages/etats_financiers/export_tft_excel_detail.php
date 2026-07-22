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

// Nom de la société
$stmt_s = $db->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
$stmt_s->execute([$societe_id]);
$societe = $stmt_s->fetchColumn() ?: 'Société';

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
    $date_debut_n, $date_fin_n, $date_debut_n, $date_fin_n,
    $date_fin_n1, $date_fin_n1,
    $date_debut_n1, $date_fin_n1, $date_debut_n1, $date_fin_n1,
    $societe_id
]);
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/tft_calculs.php';

$nf = fn($v) => (abs($v) < 0.5) ? '' : number_format($v, 0, ',', ' ');

// ─── Données de détail ────────────────────────────────────────────────
$detail_rows = [
    'ZA' => ['formula' => 'Tréso. actif N-1 + D(4726) − C(4726) − Tréso. passif N-1',
        'items' => [['Trésorerie actif (50→58)', $tresoAct_N1, null], ['+ D(4726)', $t4726d_N1, null], ['− C(4726)', -$t4726c_N1, null], ['− Trésorerie passif', -$tresoPass_N1, null]]],
    'FA' => ['formula' => 'XD + D(654) − C(754) + XF + TO − RP − RQ − RS',
        'items' => [['XD — Excédent Brut d\'Exploitation', $XD_N, $XD_N1], ['+ D(654) VNC cessions', $vnc_N, $vnc_N1], ['− C(754) Produits cessions', -$prodCess_N, -$prodCess_N1], ['+ XF Résultat financier', $XF_N, $XF_N1], ['+ TO Produits HAO', $TO_N, $TO_N1], ['− RP Charges HAO', -$RP_N, -$RP_N1], ['− RQ Participation', -$RQ_N, -$RQ_N1], ['− RS Impôt', -$RS_N, -$RS_N1]]],
    'FB' => ['formula' => 'BA(N) − BA(N-1) − 485(N) + 485(N-1) + D(4781) − C(4791)',
        'items' => [['Rubrique BA (fin N)', $BA_N, $BA_N1], ['− Rubrique BA (fin N-1)', -$BA_N1, -$BA_N2], ['− sdD 485 (N)', -$s485_N, -$s485_N1], ['+ sdD 485 (N-1)', $s485_N1, $s485_N2], ['+ sdD 4781', $d4781_N, $d4781_N1], ['− sdC 4791', -$c4791_N, -$c4791_N1]]],
    'FC' => ['formula' => 'BB(N) − BB(N-1)',
        'items' => [['Rubrique BB stocks (fin N)', $BB_N, $BB_N1], ['− Rubrique BB (fin N-1)', -$BB_N1, -$BB_N2]]],
    'FD' => ['formula' => '(BH+BI+BJ)(N−N1) + net(4781+4782+4791+4792)(N−N1) − excl. + D(2714)',
        'items' => [['BH+BI+BJ (fin N)', $BH_BI_BJ_N, $BH_BI_BJ_N1], ['− BH+BI+BJ (fin N-1)', -$BH_BI_BJ_N1, -$BH_BI_BJ_N2], ['+ net 47xx (N)', $c47xx_N, $c47xx_N1], ['− net 47xx (N-1)', -$c47xx_N1, -$c47xx_N2], ['− Excl. invest. (N)', -$excl_N, -$excl_N1], ['+ Excl. invest. (N-1)', $excl_N1, $excl_N2], ['+ mvt D(2714)', $mvt2714_N, $mvt2714_N1]]],
    'FE' => ['formula' => 'DP(N) − DP(N-1) − excl. + C(4793) − D(4783) + mvt(4752)',
        'items' => [['DP (fin N)', $DP_N, $DP_N1], ['− DP (fin N-1)', -$DP_N1, -$DP_N2], ['− Excl. (N)', -$pexcl_N, -$pexcl_N1], ['+ Excl. (N-1)', $pexcl_N1, $pexcl_N2], ['+ sdC 4793', $c4793_N, $c4793_N1], ['− sdD 4783', -$d4783_N, -$d4783_N1], ['+ mvt 4752', $mvt4752_N, $mvt4752_N1]]],
    'FF' => ['formula' => 'VNC AD(N−N1) + net mvt(251) + mvt_D(PFX_D) − mvt_C(PFX_C) + sdD(6541,811)',
        'items' => [['VNC AD (N)', $vncAD_N, $vncAD_N1], ['− VNC AD (N-1)', -$vncAD_N1, -$vncAD_N2], ['+ mvt D(251)−C(251)', sumPx($comptes,['251'],'mvt_debit_N')-sumPx($comptes,['251'],'mvt_credit_N'), sumPx($comptes,['251'],'mvt_debit_N1')-sumPx($comptes,['251'],'mvt_credit_N1')], ['+ mvt D(4041..811)', sumPx($comptes,$FF_PFX_D,'mvt_debit_N'), sumPx($comptes,$FF_PFX_D,'mvt_debit_N1')], ['− mvt C(4041..1541)', -sumPx($comptes,$FF_PFX_C,'mvt_credit_N'), -sumPx($comptes,$FF_PFX_C,'mvt_credit_N1')], ['+ sdD 6541', sdPx($comptes,['6541'],'N'), sdPx($comptes,['6541'],'N1')], ['+ sdD 811', sdPx($comptes,['811'],'N'), sdPx($comptes,['811'],'N1')]]],
    'FG' => ['formula' => 'VNC AI(N−N1) + net mvt(252) + mvt_D(PFX_D) − mvt_C(PFX_C) + sdD(6542,812)',
        'items' => [['VNC AI (N)', $vncAI_N, $vncAI_N1], ['− VNC AI (N-1)', -$vncAI_N1, -$vncAI_N2], ['+ mvt D(252)−C(252)', sumPx($comptes,['252'],'mvt_debit_N')-sumPx($comptes,['252'],'mvt_credit_N'), sumPx($comptes,['252'],'mvt_debit_N1')-sumPx($comptes,['252'],'mvt_credit_N1')], ['+ mvt D(4042..284)', sumPx($comptes,$FG_PFX_D,'mvt_debit_N'), sumPx($comptes,$FG_PFX_D,'mvt_debit_N1')], ['− mvt C(17,4042..1542)', -sumPx($comptes,$FG_PFX_C,'mvt_credit_N'), -sumPx($comptes,$FG_PFX_C,'mvt_credit_N1')], ['+ sdD 6542', sdPx($comptes,['6542'],'N'), sdPx($comptes,['6542'],'N1')], ['+ sdD 812', sdPx($comptes,['812'],'N'), sdPx($comptes,['812'],'N1')]]],
    'FH' => ['formula' => 'mvt_D(26+27 sf 276,2714) + net mvt(4813) − mvt_C(1061,1062,1543) + sdD(4782) + sdC(4792)',
        'items' => [['mvt D(26)', sumPx($comptes,['26'],'mvt_debit_N'), sumPx($comptes,['26'],'mvt_debit_N1')], ['mvt D(27 sf 276,2714)', sumPx($comptes,['27'],'mvt_debit_N')-sumPx($comptes,['276','2714'],'mvt_debit_N'), sumPx($comptes,['27'],'mvt_debit_N1')-sumPx($comptes,['276','2714'],'mvt_debit_N1')], ['mvt D(4813)−C(4813)', sumPx($comptes,['4813'],'mvt_debit_N')-sumPx($comptes,['4813'],'mvt_credit_N'), sumPx($comptes,['4813'],'mvt_debit_N1')-sumPx($comptes,['4813'],'mvt_credit_N1')], ['− mvt C(1061,1062,1543)', -sumPx($comptes,['1061','1062','1543'],'mvt_credit_N'), -sumPx($comptes,['1061','1062','1543'],'mvt_credit_N1')], ['+ sdD 4782', sdPx($comptes,['4782'],'N'), sdPx($comptes,['4782'],'N1')], ['+ sdC 4792', scPx($comptes,['4792'],'N'), scPx($comptes,['4792'],'N1')]]],
    'FI' => ['formula' => 'sdC(821+822+7541+7542) + mvt_C(4851+4852+4141+4142) − mvt_D(idem)',
        'items' => [['sdC 821+822', scPx($comptes,['821','822'],'N'), scPx($comptes,['821','822'],'N1')], ['sdC 7541+7542', scPx($comptes,['7541','7542'],'N'), scPx($comptes,['7541','7542'],'N1')], ['mvt C(4851,4852,4141,4142)', sumPx($comptes,$FI_PFX_ADJ,'mvt_credit_N'), sumPx($comptes,$FI_PFX_ADJ,'mvt_credit_N1')], ['− mvt D(idem)', -sumPx($comptes,$FI_PFX_ADJ,'mvt_debit_N'), -sumPx($comptes,$FI_PFX_ADJ,'mvt_debit_N1')]]],
    'FJ' => ['formula' => 'mvt_C(26+27 sf 2714,2766) + sdC(826) + net mvt(4143,4856)',
        'items' => [['mvt C(26+27 sf 2714,2766)', sumPx($comptes,['26','27'],'mvt_credit_N')-sumPx($comptes,['2714','2766'],'mvt_credit_N'), sumPx($comptes,['26','27'],'mvt_credit_N1')-sumPx($comptes,['2714','2766'],'mvt_credit_N1')], ['sdC 826', scPx($comptes,['826'],'N'), scPx($comptes,['826'],'N1')], ['mvt C(4143,4856)−D(idem)', sumPx($comptes,$FJ_PFX_ADJ,'mvt_credit_N')-sumPx($comptes,$FJ_PFX_ADJ,'mvt_debit_N'), sumPx($comptes,$FJ_PFX_ADJ,'mvt_credit_N1')-sumPx($comptes,$FJ_PFX_ADJ,'mvt_debit_N1')]]],
    'FK' => ['formula' => 'sdC(101+102+1051, N−N1) − sdD(109,4581,4612,4613,467) − mvt_D(11,1211,131) + mvt_C(103..465)',
        'items' => [['sdC 101+102+1051 (N)', scPx($comptes,$FK_CAP,'N'), scPx($comptes,$FK_CAP,'N1')], ['− sdC (N-1)', -scPx($comptes,$FK_CAP,'N1'), -scPx_N2($comptes,$FK_CAP)], ['− sdD (109,4581..467)', -sdPx($comptes,$FK_DEB,'N'), -sdPx($comptes,$FK_DEB,'N1')], ['− mvt D(11,1211,131)', -sumPx($comptes,$FK_MVD,'mvt_debit_N'), -sumPx($comptes,$FK_MVD,'mvt_debit_N1')], ['+ mvt C(103..465)', sumPx($comptes,$FK_MVC,'mvt_credit_N'), sumPx($comptes,$FK_MVC,'mvt_credit_N1')]]],
    'FL' => ['formula' => 'sdC(14, N−N1) + sdC(799) − sdC(4494+4581+4582)',
        'items' => [['sdC 14 (N)', scPx($comptes,['14'],'N'), scPx($comptes,['14'],'N1')], ['− sdC 14 (N-1)', -scPx($comptes,['14'],'N1'), -scPx_N2($comptes,['14'])], ['+ sdC 799 − sdC(4494,4581,4582)', scPx($comptes,['799'],'N')-scPx($comptes,['4494','4581','4582'],'N'), scPx($comptes,['799'],'N1')-scPx($comptes,['4494','4581','4582'],'N1')]]],
    'FM' => ['formula' => 'mvt_D(4619+103+104)',
        'items' => [['mvt D(4619)', sumPx($comptes,['4619'],'mvt_debit_N'), sumPx($comptes,['4619'],'mvt_debit_N1')], ['mvt D(103+104)', sumPx($comptes,['103','104'],'mvt_debit_N'), sumPx($comptes,['103','104'],'mvt_debit_N1')]]],
    'FN' => ['formula' => 'mvt_D(465)',
        'items' => [['mvt D(465)', sumPx($comptes,['465'],'mvt_debit_N'), sumPx($comptes,['465'],'mvt_debit_N1')]]],
    'FO' => ['formula' => 'mvt_C(161+162+1661+1662) − mvt_D(4713) − sdD(4784)',
        'items' => [['mvt C(161,162,1661,1662)', sumPx($comptes,['161','162','1661','1662'],'mvt_credit_N'), sumPx($comptes,['161','162','1661','1662'],'mvt_credit_N1')], ['− mvt D(4713)', -sumPx($comptes,['4713'],'mvt_debit_N'), -sumPx($comptes,['4713'],'mvt_debit_N1')], ['− sdD 4784', -sdPx($comptes,['4784'],'N'), -sdPx($comptes,['4784'],'N1')]]],
    'FP' => ['formula' => 'mvt_C(163+164+165+167+168+181→184) − sdD(4784)',
        'items' => [['mvt C(163,164,165,167,168)', sumPx($comptes,['163','164','165','167','168'],'mvt_credit_N'), sumPx($comptes,['163','164','165','167','168'],'mvt_credit_N1')], ['mvt C(181,182,183,184)', sumPx($comptes,['181','182','183','184'],'mvt_credit_N'), sumPx($comptes,['181','182','183','184'],'mvt_credit_N1')], ['− sdD 4784', -sdPx($comptes,['4784'],'N'), -sdPx($comptes,['4784'],'N1')]]],
    'FQ' => ['formula' => 'mvt_D(16+17+181→184) − sdC(4794)',
        'items' => [['mvt D(16)', sumPx($comptes,['16'],'mvt_debit_N'), sumPx($comptes,['16'],'mvt_debit_N1')], ['mvt D(17+181→184)', sumPx($comptes,['17','181','182','183','184'],'mvt_debit_N'), sumPx($comptes,['17','181','182','183','184'],'mvt_debit_N1')], ['− sdC 4794', -scPx($comptes,['4794'],'N'), -scPx($comptes,['4794'],'N1')]]],
    'ZB' => ['formula' => 'FA − FB − FC − FD + FE',
        'items' => [['+ FA CAFG', $FA_N, $FA_N1], ['− FB Actif HAO', -$FB_N, -$FB_N1], ['− FC Stocks', -$FC_N, -$FC_N1], ['− FD Créances', -$FD_N, -$FD_N1], ['+ FE Passif circ.', $FE_N, $FE_N1]]],
    'ZC' => ['formula' => '−FF − FG − FH + FI + FJ',
        'items' => [['− FF', -$FF_N, -$FF_N1], ['− FG', -$FG_N, -$FG_N1], ['− FH', -$FH_N, -$FH_N1], ['+ FI', $FI_N, $FI_N1], ['+ FJ', $FJ_N, $FJ_N1]]],
    'ZD' => ['formula' => 'FK + FL − FM − FN',
        'items' => [['+ FK', $FK_N, $FK_N1], ['+ FL', $FL_N, $FL_N1], ['− FM', -$FM_N, -$FM_N1], ['− FN', -$FN_N, -$FN_N1]]],
    'ZE' => ['formula' => 'FO + FP − FQ',
        'items' => [['+ FO', $FO_N, $FO_N1], ['+ FP', $FP_N, $FP_N1], ['− FQ', -$FQ_N, -$FQ_N1]]],
    'ZF' => ['formula' => 'ZD + ZE', 'items' => [['ZD', $ZD_N, $ZD_N1], ['ZE', $ZE_N, $ZE_N1]]],
    'ZG' => ['formula' => 'ZB + ZC + ZF', 'items' => [['ZB', $ZB_N, $ZB_N1], ['ZC', $ZC_N, $ZC_N1], ['ZF', $ZF_N, $ZF_N1]]],
    'ZH' => ['formula' => 'ZA + ZG', 'items' => [['ZA', $ZA_N, $ZA_N1], ['ZG', $ZG_N, $ZG_N1]]],
];

// =====================================================================
// GÉNÉRATION EXCEL
// =====================================================================
$ss = new Spreadsheet();
$ws = $ss->getActiveSheet();
$ws->setTitle('TFT Détaillé');

// Couleurs
$C_HEADER  = 'FF1E3A5F';
$C_SECTION = 'FF374151';
$C_TOTAL   = 'FF0D5C3A';
$C_SUBTOT  = 'FF1A3A2E';
$C_WHITE   = 'FFFFFFFF';
$C_FORMULA = 'FFDBEAFE'; // bleu clair — ligne formule
$C_DETAIL  = 'FFEFF6FF'; // bleu très clair — lignes composantes
$C_RED     = 'FFDC2626';
$C_BLUE    = 'FF1E40AF';
$C_GRAY    = 'FF374151';

$row = 1;

// Titre principal
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", 'TABLEAU DE FLUX DE TRÉSORERIE DÉTAILLÉ — SYSCOHADA RÉVISÉ');
$ws->getStyle("A{$row}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => $C_WHITE]],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_HEADER]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$ws->getRowDimension($row)->setRowHeight(22);
$row++;

// Sous-titre
$ws->mergeCells("A{$row}:E{$row}");
$ws->setCellValue("A{$row}", $societe . '  —  Période : ' . date('d/m/Y', strtotime($date_debut_n)) . ' au ' . date('d/m/Y', strtotime($date_fin_n)));
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
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_SECTION]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF4B5563']]],
    ]);
}
$ws->getRowDimension($row)->setRowHeight(28);
$row++;

// ─── Helpers ─────────────────────────────────────────────────────────

// Émet une ligne principale (section / normal / total / subtotal)
$writeMain = function(string $ref, string $label, string $note, $vN, $vN1, string $type = 'normal')
    use ($ws, &$row, $nf, $C_TOTAL, $C_SUBTOT, $C_SECTION, $C_WHITE) {

    if ($type === 'section') {
        $ws->mergeCells("A{$row}:E{$row}");
        $ws->setCellValue("A{$row}", strtoupper($label));
        $ws->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 8, 'color' => ['argb' => $C_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_SECTION]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $ws->getRowDimension($row)->setRowHeight(14);
        $row++;
        return;
    }

    $isTotal  = $type === 'total';
    $isSubtot = $type === 'subtotal';
    $fillColor = $isTotal ? $C_TOTAL : ($isSubtot ? $C_SUBTOT : 'FFFFFFFF');
    $fontColor = ($isTotal || $isSubtot) ? $C_WHITE : 'FF1F2937';

    $ws->setCellValue("A{$row}", $ref);
    $ws->setCellValue("B{$row}", $label);
    $ws->setCellValue("C{$row}", $note);
    if ($vN !== null && abs((float)$vN) >= 0.5) { $ws->setCellValue("D{$row}", (float)$vN); }
    if ($vN1 !== null && abs((float)$vN1) >= 0.5) { $ws->setCellValue("E{$row}", (float)$vN1); }

    $ws->getStyle("A{$row}:E{$row}")->applyFromArray([
        'font'    => ['bold' => $isTotal || $isSubtot, 'color' => ['argb' => $fontColor]],
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $fillColor]],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
    ]);
    $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->getRowDimension($row)->setRowHeight(14);
    $row++;
};

// Émet la ligne de formule (fond bleu clair)
$writeFormula = function(string $formula) use ($ws, &$row, $C_FORMULA, $C_BLUE) {
    $ws->mergeCells("A{$row}:E{$row}");
    $ws->setCellValue("A{$row}", 'Formule : ' . $formula);
    $ws->getStyle("A{$row}")->applyFromArray([
        'font'      => ['italic' => true, 'size' => 7, 'color' => ['argb' => $C_BLUE]],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_FORMULA]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 2],
    ]);
    $ws->getRowDimension($row)->setRowHeight(12);
    $row++;
};

// Émet une ligne composante (fond bleu très clair, indent, valeurs colorées)
$writeDetail = function(string $label, $vN, $vN1) use ($ws, &$row, $C_DETAIL, $C_RED, $C_BLUE, $C_GRAY) {
    $ws->setCellValue("B{$row}", $label);

    if ($vN  !== null && abs((float)$vN)  >= 0.5) { $ws->setCellValue("D{$row}", (float)$vN); }
    if ($vN1 !== null && abs((float)$vN1) >= 0.5) { $ws->setCellValue("E{$row}", (float)$vN1); }

    $colorN  = ($vN  !== null && $vN  < -0.5) ? $C_RED : $C_BLUE;
    $colorN1 = ($vN1 !== null && $vN1 < -0.5) ? $C_RED : $C_GRAY;

    $ws->getStyle("A{$row}:E{$row}")->applyFromArray([
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $C_DETAIL]],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD1D5DB']]],
    ]);
    $ws->getStyle("B{$row}")->applyFromArray([
        'font'      => ['italic' => true, 'size' => 7.5, 'color' => ['argb' => 'FF374151']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 4],
    ]);
    $ws->getStyle("D{$row}")->applyFromArray([
        'font'      => ['size' => 7.5, 'color' => ['argb' => $colorN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    ]);
    $ws->getStyle("E{$row}")->applyFromArray([
        'font'      => ['size' => 7.5, 'color' => ['argb' => $colorN1]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    ]);
    $ws->getRowDimension($row)->setRowHeight(11);
    $row++;
};

// Émet une ligne principale + son bloc de détail si disponible
$emitRow = function(string $ref, string $label, string $note, $vN, $vN1, string $type = 'normal')
    use ($writeMain, $writeFormula, $writeDetail, $detail_rows) {

    $writeMain($ref, $label, $note, $vN, $vN1, $type);
    if (isset($detail_rows[$ref])) {
        $d = $detail_rows[$ref];
        $writeFormula($d['formula']);
        foreach ($d['items'] as $item) {
            $writeDetail($item[0], $item[1], $item[2]);
        }
    }
};

// ─── Contenu ─────────────────────────────────────────────────────────
$BF_N  = -$FB_N - $FC_N - $FD_N + $FE_N;
$BF_N1 = -$FB_N1 - $FC_N1 - $FD_N1 + $FE_N1;

$emitRow('ZA', 'Trésorerie nette au 1er janvier', 'A', $ZA_N, $ZA_N1);

$writeMain('', 'Flux de trésorerie provenant des activités opérationnelles', '', null, null, 'section');
$emitRow('FA', 'Capacité d\'Autofinancement Globale (CAFG)', '', $FA_N, $FA_N1);
$emitRow('FB', '- Variation d\'actif circulant HAO', '', -$FB_N, -$FB_N1);
$emitRow('FC', '- Variation des stocks', '', -$FC_N, -$FC_N1);
$emitRow('FD', '- Variation des créances', '', -$FD_N, -$FD_N1);
$emitRow('FE', '+ Variation du passif circulant', '', $FE_N, $FE_N1);
$writeMain('', 'Variation du BF lié aux activités opérationnelles (FB+FC+FD+FE)', '', $BF_N, $BF_N1);
$emitRow('ZB', 'Flux de trésorerie provenant des activités opérationnelles (Somme FA à FE)', 'B', $ZB_N, $ZB_N1, 'total');

$writeMain('', 'Flux de trésorerie provenant des activités d\'investissements', '', null, null, 'section');
$emitRow('FF', '- Décaissements liés aux acquisitions d\'immos incorporelles', '', -$FF_N, -$FF_N1);
$emitRow('FG', '- Décaissements liés aux acquisitions d\'immos corporelles', '', -$FG_N, -$FG_N1);
$emitRow('FH', '- Décaissements liés aux acquisitions d\'immos financières', '', -$FH_N, -$FH_N1);
$emitRow('FI', '+ Encaissements liés aux cessions d\'immos incorp. et corp.', '', $FI_N, $FI_N1);
$emitRow('FJ', '+ Encaissements liés aux cessions d\'immos financières', '', $FJ_N, $FJ_N1);
$emitRow('ZC', 'Flux de trésorerie provenant des activités d\'investissement (somme FF à FJ)', 'C', $ZC_N, $ZC_N1, 'total');

$writeMain('', 'Flux de trésorerie provenant du financement par les capitaux propres', '', null, null, 'section');
$emitRow('FK', '+ Augmentations de capital par apports nouveaux', '', $FK_N, $FK_N1);
$emitRow('FL', '+ Subventions d\'investissement reçues', '', $FL_N, $FL_N1);
$emitRow('FM', '- Prélèvements sur le capital', '', -$FM_N, -$FM_N1);
$emitRow('FN', '- Dividendes versés', '', -$FN_N, -$FN_N1);
$emitRow('ZD', 'Flux de trésorerie provenant des capitaux propres (somme FK à FN)', 'D', $ZD_N, $ZD_N1, 'subtotal');

$writeMain('', 'Trésorerie provenant du financement par les capitaux étrangers', '', null, null, 'section');
$emitRow('FO', '+ Emprunts', '', $FO_N, $FO_N1);
$emitRow('FP', '+ Autres dettes financières diverses', '', $FP_N, $FP_N1);
$emitRow('FQ', '- Remboursements des emprunts et autres dettes financières', '', -$FQ_N, -$FQ_N1);
$emitRow('ZE', 'Flux de trésorerie provenant des capitaux étrangers (somme FO à FQ)', 'E', $ZE_N, $ZE_N1, 'subtotal');

$emitRow('ZF', 'Flux de trésorerie provenant des activités de financement (D+E)', 'F', $ZF_N, $ZF_N1, 'total');
$emitRow('ZG', 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (B+C+F)', 'G', $ZG_N, $ZG_N1, 'total');
$emitRow('ZH', 'Trésorerie nette au 31 Décembre (G+A)', 'H', $ZH_check_N, $ZH_check_N1, 'total');

// Notes de bas
$row++;
foreach ([
    '(1) À l\'exclusion des variations des créances et dettes liées aux activités d\'investissement et de financement.',
    '(2) Comptes 161, 162, 1661, 1662',
    '(3) Comptes 16 sauf (161, 162, 1661, 1662) et comptes 18',
] as $note) {
    $ws->mergeCells("A{$row}:E{$row}");
    $ws->setCellValue("A{$row}", $note);
    $ws->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(7.5);
    $ws->getStyle("A{$row}")->getFont()->getColor()->setARGB('FF6B7280');
    $row++;
}

// Largeurs colonnes
$ws->getColumnDimension('A')->setWidth(7);
$ws->getColumnDimension('B')->setWidth(62);
$ws->getColumnDimension('C')->setWidth(7);
$ws->getColumnDimension('D')->setWidth(20);
$ws->getColumnDimension('E')->setWidth(20);

// Format numérique colonnes valeurs
$ws->getStyle('D4:E2000')->getNumberFormat()->setFormatCode('#,##0.00');

// Désactiver le quadrillage
$ws->setShowGridlines(false);

// Figer l'en-tête
$ws->freezePane('A4');

// Export
$filename = 'TFT_Detail_' . $annee_n . '_' . date('YmdHis') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
