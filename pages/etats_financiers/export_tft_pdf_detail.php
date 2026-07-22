<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

$date_debut_n = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin_n   = $_GET['date_fin']   ?? date('Y-12-31');
$date_debut_n1 = date('Y-m-d', strtotime($date_debut_n . ' -1 year'));
$date_fin_n1   = date('Y-m-d', strtotime($date_fin_n   . ' -1 year'));
$annee_n  = date('Y', strtotime($date_fin_n));
$annee_n1 = date('Y', strtotime($date_fin_n1));

$stmt_s = $db->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
$stmt_s->execute([$societe_id]);
$societe = $stmt_s->fetchColumn() ?: 'Société';

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

$nf = function($v) { return (abs($v) < 0.5) ? '' : number_format($v, 0, ',', ' '); };

// ─── Données de détail (même structure que tft.php) ──────────────────
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

// ─── GÉNÉRATION PDF ───────────────────────────────────────────────────
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor($societe);
$pdf->SetTitle("TFT Détaillé {$annee_n}");
$pdf->SetMargins(10, 25, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(8);
$pdf->setHeaderFont(['helvetica', 'B', 9]);
$pdf->setFooterFont(['helvetica', '', 7]);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetFont('helvetica', '', 7.5);
$pdf->SetHeaderData('', 0, $societe, "Tableau de Flux de Trésorerie DÉTAILLÉ — Exercice {$annee_n}");
$pdf->AddPage();

// ─── Helpers HTML ────────────────────────────────────────────────────
$C_TOTAL   = '#0D5C3A';
$C_SUBTOT  = '#1A3A2E';
$C_SECTION = '#374151';
$C_DETAIL  = '#1E3A50';
$C_DETAIL2 = '#F0F7FF';

$trMain = function($ref, $label, $note, $vN, $vN1, $type = 'normal') use ($nf, $C_TOTAL, $C_SUBTOT, $C_SECTION) {
    if ($type === 'section') {
        return "<tr style=\"background-color:{$C_SECTION};color:#FFFFFF;\">
            <td colspan=\"5\" style=\"padding-left:2mm;font-weight:bold;font-size:6.5pt;\">" . strtoupper($label) . "</td></tr>";
    }
    $bg = $type === 'total' ? $C_TOTAL : ($type === 'subtotal' ? $C_SUBTOT : '#FFFFFF');
    $fc = ($type === 'total' || $type === 'subtotal') ? '#FFFFFF' : '#1F2937';
    $fw = ($type === 'total' || $type === 'subtotal') ? 'bold' : 'normal';
    return "<tr style=\"background-color:{$bg};\">
        <td width=\"6%\" style=\"text-align:center;font-family:courier;font-weight:{$fw};color:{$fc};\">{$ref}</td>
        <td width=\"55%\" style=\"padding-left:2mm;font-weight:{$fw};color:{$fc};\">{$label}</td>
        <td width=\"5%\" style=\"text-align:center;color:{$fc};\">{$note}</td>
        <td width=\"17%\" style=\"text-align:right;padding-right:2mm;font-weight:{$fw};color:{$fc};\">" . $nf($vN) . "</td>
        <td width=\"17%\" style=\"text-align:right;padding-right:2mm;font-weight:{$fw};color:{$fc};\">" . $nf($vN1) . "</td>
    </tr>";
};

$trDetail = function($label, $vN, $vN1) use ($nf) {
    $cN  = ($vN  !== null && $vN  < -0.5) ? '#DC2626' : '#1E40AF';
    $cN1 = ($vN1 !== null && $vN1 < -0.5) ? '#DC2626' : '#374151';
    $fN  = ($vN  !== null && abs($vN)  >= 0.5) ? number_format($vN,  0, ',', ' ') : '';
    $fN1 = ($vN1 !== null && abs($vN1) >= 0.5) ? number_format($vN1, 0, ',', ' ') : '';
    return "<tr style=\"background-color:#EFF6FF;\">
        <td width=\"6%\"></td>
        <td width=\"55%\" style=\"padding-left:8mm;font-size:6pt;color:#374151;font-style:italic;\">{$label}</td>
        <td width=\"5%\"></td>
        <td width=\"17%\" style=\"text-align:right;padding-right:2mm;font-size:6pt;color:{$cN};\">{$fN}</td>
        <td width=\"17%\" style=\"text-align:right;padding-right:2mm;font-size:6pt;color:{$cN1};\">{$fN1}</td>
    </tr>";
};

$trFormula = function($formula) {
    return "<tr style=\"background-color:#DBEAFE;\">
        <td width=\"6%\"></td>
        <td colspan=\"4\" style=\"padding-left:8mm;font-size:5.5pt;color:#1E40AF;font-style:italic;\">
            Formule : {$formula}
        </td>
    </tr>";
};

// ─── Construction du contenu ─────────────────────────────────────────
$rows = '';

// En-tête
$rows .= "<tr style=\"background-color:#374151;color:#FFFFFF;\">
    <th width=\"6%\" style=\"text-align:center;\">REF</th>
    <th width=\"55%\">LIBELLÉS</th>
    <th width=\"5%\" style=\"text-align:center;\">NOTE</th>
    <th width=\"17%\" style=\"text-align:center;\">EXERCICE<br/>{$annee_n}</th>
    <th width=\"17%\" style=\"text-align:center;\">EXERCICE<br/>{$annee_n1}</th>
</tr>";

// Fonction locale pour émettre une ligne + son détail
$emitRow = function($ref, $label, $note, $vN, $vN1, $type = 'normal') use ($trMain, $trFormula, $trDetail, &$rows, $detail_rows) {
    $rows .= $trMain($ref, $label, $note, $vN, $vN1, $type);
    if (isset($detail_rows[$ref])) {
        $d = $detail_rows[$ref];
        $rows .= $trFormula($d['formula']);
        foreach ($d['items'] as $item) {
            $rows .= $trDetail($item[0], $item[1], $item[2]);
        }
    }
};

$BF_N  = -$FB_N - $FC_N - $FD_N + $FE_N;
$BF_N1 = -$FB_N1 - $FC_N1 - $FD_N1 + $FE_N1;

$emitRow('ZA', 'Trésorerie nette au 1er janvier', 'A', $ZA_N, $ZA_N1);
$rows .= $trMain('', 'Flux de trésorerie provenant des activités opérationnelles', '', null, null, 'section');
$emitRow('FA', 'Capacité d\'Autofinancement Globale (CAFG)', '', $FA_N, $FA_N1);
$emitRow('FB', '- Variation d\'actif circulant HAO', '', -$FB_N, -$FB_N1);
$emitRow('FC', '- Variation des stocks', '', -$FC_N, -$FC_N1);
$emitRow('FD', '- Variation des créances', '', -$FD_N, -$FD_N1);
$emitRow('FE', '+ Variation du passif circulant', '', $FE_N, $FE_N1);
$rows .= $trMain('', 'Variation du BF lié aux activités opérationnelles (FB+FC+FD+FE)', '', $BF_N, $BF_N1);
$emitRow('ZB', 'Flux de trésorerie provenant des activités opérationnelles', 'B', $ZB_N, $ZB_N1, 'total');

$rows .= $trMain('', 'Flux de trésorerie provenant des activités d\'investissements', '', null, null, 'section');
$emitRow('FF', '- Décaissements acquisitions immos incorporelles', '', -$FF_N, -$FF_N1);
$emitRow('FG', '- Décaissements acquisitions immos corporelles', '', -$FG_N, -$FG_N1);
$emitRow('FH', '- Décaissements acquisitions immos financières', '', -$FH_N, -$FH_N1);
$emitRow('FI', '+ Encaissements cessions immos incorp. et corp.', '', $FI_N, $FI_N1);
$emitRow('FJ', '+ Encaissements cessions immos financières', '', $FJ_N, $FJ_N1);
$emitRow('ZC', 'Flux de trésorerie provenant des activités d\'investissement', 'C', $ZC_N, $ZC_N1, 'total');

$rows .= $trMain('', 'Flux de trésorerie — financement capitaux propres', '', null, null, 'section');
$emitRow('FK', '+ Augmentations de capital par apports nouveaux', '', $FK_N, $FK_N1);
$emitRow('FL', '+ Subventions d\'investissement reçues', '', $FL_N, $FL_N1);
$emitRow('FM', '- Prélèvements sur le capital', '', -$FM_N, -$FM_N1);
$emitRow('FN', '- Dividendes versés', '', -$FN_N, -$FN_N1);
$emitRow('ZD', 'Flux de trésorerie provenant des capitaux propres', 'D', $ZD_N, $ZD_N1, 'subtotal');

$rows .= $trMain('', 'Trésorerie provenant du financement par les capitaux étrangers', '', null, null, 'section');
$emitRow('FO', '+ Emprunts', '', $FO_N, $FO_N1);
$emitRow('FP', '+ Autres dettes financières diverses', '', $FP_N, $FP_N1);
$emitRow('FQ', '- Remboursements des emprunts et autres dettes financières', '', -$FQ_N, -$FQ_N1);
$emitRow('ZE', 'Flux de trésorerie provenant des capitaux étrangers', 'E', $ZE_N, $ZE_N1, 'subtotal');

$emitRow('ZF', 'Flux de trésorerie provenant des activités de financement (D+E)', 'F', $ZF_N, $ZF_N1, 'total');
$emitRow('ZG', 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (B+C+F)', 'G', $ZG_N, $ZG_N1, 'total');
$emitRow('ZH', 'Trésorerie nette au 31 Décembre (G+A)', 'H', $ZH_check_N, $ZH_check_N1, 'total');

$html = "
<style>
    table { border-collapse: collapse; width: 100%; font-size: 7.5pt; }
    th { background-color: #374151; color: white; text-align: center; padding: 1.5mm; font-size: 7.5pt; }
    td { padding: 0.8mm 1mm; border-bottom: 0.1mm solid #E5E7EB; }
</style>
<table><tbody>{$rows}</tbody></table>
<br/>
<p style=\"font-size:5.5pt;color:#6B7280;\">
(1) À l'exclusion des variations des créances et dettes liées aux activités d'investissement et de financement.<br/>
(2) Comptes 161, 162, 1661, 1662 &nbsp;&nbsp; (3) Comptes 16 sauf (161, 162, 1661, 1662) et comptes 18
</p>
";

$pdf->writeHTML($html, true, false, true, false, '');
$filename = 'TFT_Detail_' . $annee_n . '_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
exit;
