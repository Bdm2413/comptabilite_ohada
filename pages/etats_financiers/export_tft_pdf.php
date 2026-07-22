<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

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
    $date_debut_n, $date_fin_n,
    $date_debut_n, $date_fin_n,
    $date_fin_n1, $date_fin_n1,
    $date_debut_n1, $date_fin_n1,
    $date_debut_n1, $date_fin_n1,
    $societe_id
]);
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/tft_calculs.php';

$nf = function($v) { return (abs($v) < 0.5) ? '' : number_format($v, 0, ',', ' '); };

// =====================================================================
// GÉNÉRATION PDF TCPDF
// =====================================================================
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor($societe);
$pdf->SetTitle("Tableau de Flux de Trésorerie {$annee_n}");
$pdf->SetMargins(10, 25, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(8);
$pdf->setHeaderFont(['helvetica', 'B', 9]);
$pdf->setFooterFont(['helvetica', '', 7]);
$pdf->SetDefaultMonospacedFont('courier');
$pdf->SetAutoPageBreak(true, 15);
$pdf->setImageScale(1.25);
$pdf->SetFont('helvetica', '', 8);

// Header/Footer personnalisés
$pdf->SetHeaderData('', 0, $societe, "Tableau de Flux de Trésorerie — Exercice {$annee_n}");

$pdf->AddPage();

// Construction HTML
$rows = '';

$trNormal = fn($ref, $label, $note, $vN, $vN1, $bg='#FFFFFF') =>
    "<tr style=\"background-color:{$bg};\">
        <td width=\"6%\" style=\"text-align:center;font-family:courier;\">{$ref}</td>
        <td width=\"56%\" style=\"padding-left:2mm;\">{$label}</td>
        <td width=\"5%\" style=\"text-align:center;color:#6B7280;\">{$note}</td>
        <td width=\"16.5%\" style=\"text-align:right;padding-right:2mm;\">{$vN}</td>
        <td width=\"16.5%\" style=\"text-align:right;padding-right:2mm;\">{$vN1}</td>
    </tr>";

$trTotal = fn($ref, $label, $note, $vN, $vN1) =>
    "<tr style=\"background-color:#0D5C3A;color:#FFFFFF;\">
        <td width=\"6%\" style=\"text-align:center;font-family:courier;font-weight:bold;\">{$ref}</td>
        <td width=\"56%\" style=\"padding-left:2mm;font-weight:bold;\">{$label}</td>
        <td width=\"5%\" style=\"text-align:center;font-weight:bold;\">{$note}</td>
        <td width=\"16.5%\" style=\"text-align:right;padding-right:2mm;font-weight:bold;\">{$vN}</td>
        <td width=\"16.5%\" style=\"text-align:right;padding-right:2mm;font-weight:bold;\">{$vN1}</td>
    </tr>";

$trSubtot = fn($ref, $label, $note, $vN, $vN1) =>
    "<tr style=\"background-color:#1A3A2E;color:#FFFFFF;\">
        <td width=\"6%\" style=\"text-align:center;font-family:courier;\">{$ref}</td>
        <td width=\"56%\" style=\"padding-left:2mm;font-weight:bold;\">{$label}</td>
        <td width=\"5%\" style=\"text-align:center;\">{$note}</td>
        <td width=\"16.5%\" style=\"text-align:right;padding-right:2mm;\">{$vN}</td>
        <td width=\"16.5%\" style=\"text-align:right;padding-right:2mm;\">{$vN1}</td>
    </tr>";

$trSection = fn($label) =>
    "<tr style=\"background-color:#374151;color:#FFFFFF;\">
        <td colspan=\"5\" style=\"padding-left:2mm;font-weight:bold;font-size:7pt;\">" . strtoupper($label) . "</td>
    </tr>";

$rows .= $trNormal('ZA', 'Trésorerie nette au 1er janvier<br/><span style="font-size:6pt;color:#9CA3AF;">(Trésorerie actif N-1 − Trésorerie passif N-1)</span>', 'A', $nf($ZA_N), $nf($ZA_N1));
$rows .= $trSection('Flux de trésorerie provenant des activités opérationnelles');
$rows .= $trNormal('FA', 'Capacité d\'Autofinancement Globale (CAFG)', '', $nf($FA_N), $nf($FA_N1));
$rows .= $trNormal('FB', '- Variation d\'actif circulant HAO <sup>(1)</sup>', '', $nf(-$FB_N), $nf(-$FB_N1), '#F9FAFB');
$rows .= $trNormal('FC', '- Variation des stocks', '', $nf(-$FC_N), $nf(-$FC_N1));
$rows .= $trNormal('FD', '- Variation des créances', '', $nf(-$FD_N), $nf(-$FD_N1), '#F9FAFB');
$rows .= $trNormal('FE', '+ Variation du passif circulant <sup>(1)</sup>', '', $nf($FE_N), $nf($FE_N1));
$rows .= $trNormal('', '<em>Variation du BF lié aux activités opérationnelles (FB+FC+FD+FE)</em>', '', $nf($BF_N), $nf($BF_N1), '#F0F4F8');
$rows .= $trTotal('ZB', 'Flux de trésorerie provenant des activités opérationnelles (Somme FA à FE)', 'B', $nf($ZB_N), $nf($ZB_N1));

$rows .= $trSection('Flux de trésorerie provenant des activités d\'investissements');
$rows .= $trNormal('FF', '- Décaissements liés aux acquisitions d\'immobilisations incorporelles', '', $nf(-$FF_N), $nf(-$FF_N1));
$rows .= $trNormal('FG', '- Décaissements liés aux acquisitions d\'immobilisations corporelles', '', $nf(-$FG_N), $nf(-$FG_N1), '#F9FAFB');
$rows .= $trNormal('FH', '- Décaissements liés aux acquisitions d\'immobilisations financières', '', $nf(-$FH_N), $nf(-$FH_N1));
$rows .= $trNormal('FI', '+ Encaissements liés aux cessions d\'immobilisations incorp. et corp.', '', $nf($FI_N), $nf($FI_N1), '#F9FAFB');
$rows .= $trNormal('FJ', '+ Encaissements liés aux cessions d\'immobilisations financières', '', $nf($FJ_N), $nf($FJ_N1));
$rows .= $trTotal('ZC', 'Flux de trésorerie provenant des activités d\'investissement (somme FF à FJ)', 'C', $nf($ZC_N), $nf($ZC_N1));

$rows .= $trSection('Flux de trésorerie provenant du financement par les capitaux propres');
$rows .= $trNormal('FK', '+ Augmentations de capital par apports nouveaux', '', $nf($FK_N), $nf($FK_N1));
$rows .= $trNormal('FL', '+ Subventions d\'investissement reçues', '', $nf($FL_N), $nf($FL_N1), '#F9FAFB');
$rows .= $trNormal('FM', '- Prélèvements sur le capital', '', $nf(-$FM_N), $nf(-$FM_N1));
$rows .= $trNormal('FN', '- Dividendes versés', '', $nf(-$FN_N), $nf(-$FN_N1), '#F9FAFB');
$rows .= $trSubtot('ZD', 'Flux de trésorerie provenant des capitaux propres (somme FK à FN)', 'D', $nf($ZD_N), $nf($ZD_N1));

$rows .= $trSection('Trésorerie provenant du financement par les capitaux étrangers');
$rows .= $trNormal('FO', '+ Emprunts <sup>(2)</sup>', '', $nf($FO_N), $nf($FO_N1));
$rows .= $trNormal('FP', '+ Autres dettes financières diverses <sup>(3)</sup>', '', $nf($FP_N), $nf($FP_N1), '#F9FAFB');
$rows .= $trNormal('FQ', '- Remboursements des emprunts et autres dettes financières', '', $nf(-$FQ_N), $nf(-$FQ_N1));
$rows .= $trSubtot('ZE', 'Flux de trésorerie provenant des capitaux étrangers (somme FO à FQ)', 'E', $nf($ZE_N), $nf($ZE_N1));

$rows .= $trTotal('ZF', 'Flux de trésorerie provenant des activités de financement (D+E)', 'F', $nf($ZF_N), $nf($ZF_N1));
$rows .= $trTotal('ZG', 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (B+C+F)', 'G', $nf($ZG_N), $nf($ZG_N1));
$rows .= $trTotal('ZH', 'Trésorerie nette au 31 Décembre (G+A)', 'H', $nf($ZH_check_N), $nf($ZH_check_N1));

$html = "
<style>
    table { border-collapse: collapse; width: 100%; font-size: 8pt; }
    th { background-color: #374151; color: white; text-align: center; padding: 2mm; font-size: 8pt; }
    td { padding: 1mm 1mm; border-bottom: 0.2mm solid #E5E7EB; }
</style>
<table>
    <thead>
        <tr>
            <th width=\"6%\">REF</th>
            <th width=\"56%\">LIBELLÉS</th>
            <th width=\"5%\">NOTE</th>
            <th width=\"16.5%\">EXERCICE<br/>{$annee_n}</th>
            <th width=\"16.5%\">EXERCICE<br/>{$annee_n1}</th>
        </tr>
    </thead>
    <tbody>
        {$rows}
    </tbody>
</table>
<br/>
<p style=\"font-size:6pt;color:#6B7280;\">
(1) À l\'exclusion des variations des créances et dettes liées aux activités d\'investissement et de financement.<br/>
(2) Comptes 161, 162, 1661, 1662<br/>
(3) Comptes 16 sauf (161, 162, 1661, 1662) et comptes 18
</p>
";

$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'TFT_' . $annee_n . '_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
exit;
