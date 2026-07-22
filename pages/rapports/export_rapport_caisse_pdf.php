<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

use TCPDF;

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Récupérer les paramètres
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$compte_caisse = $_GET['compte_caisse'] ?? '5711000';

// Fonction de formatage
function format_number($number) {
    return number_format((float)$number, 2, ',', ' ');
}

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

// Créer le PDF
class MYPDF extends TCPDF {
    public function Header() {
        // Vide - pas de header automatique
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

$pdf = new MYPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Comptabilité SYSCOHADA');
$pdf->SetAuthor('Comptabilité SYSCOHADA');
$pdf->SetTitle('Rapport de Caisse');
$pdf->SetSubject('Rapport de Caisse');

$pdf->setPrintHeader(false);
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'RAPPORT DE CAISSE', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, $compte_caisse . ' - ' . $intitule_caisse, 0, 1, 'C');
$pdf->Cell(0, 8, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
$pdf->Ln(5);

// Statistiques
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(59, 130, 246);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(60, 7, 'Solde Initial', 1, 0, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(60, 7, format_number($solde_initial), 1, 1, 'R');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(34, 197, 94);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(60, 7, 'Total Entrées', 1, 0, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(60, 7, format_number($total_entrees), 1, 1, 'R');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(239, 68, 68);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(60, 7, 'Total Sorties', 1, 0, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(60, 7, format_number($total_sorties), 1, 1, 'R');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(168, 85, 247);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(60, 7, 'Solde Final', 1, 0, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(60, 7, format_number($solde_courant), 1, 1, 'R');
$pdf->Ln(5);

// Tableau des transactions
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(51, 65, 85);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(20, 7, 'DATE', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'NUM', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'PAID TO/FROM', 1, 0, 'C', true);
$pdf->Cell(70, 7, 'PURPOSE', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'GL ACCOUNT', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'CASH IN', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'CASH OUT', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'BALANCE', 1, 1, 'C', true);

// Ligne OPENING BALANCE
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(30, 41, 59);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(185, 6, 'OPENING BALANCE', 1, 0, 'L', true);
$pdf->Cell(25, 6, '-', 1, 0, 'R', true);
$pdf->Cell(25, 6, '-', 1, 0, 'R', true);
$pdf->Cell(25, 6, format_number($solde_initial), 1, 1, 'R', true);

// Données
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);

foreach ($transactions as $trans) {
    $pdf->Cell(20, 6, date('d/m/y', strtotime($trans['date_ecriture'])), 1, 0, 'L');
    $pdf->Cell(25, 6, $trans['numero_ecriture'], 1, 0, 'L');
    $pdf->Cell(45, 6, substr($trans['tiers_nom'] ?? '-', 0, 20), 1, 0, 'L');
    $pdf->Cell(70, 6, substr($trans['libelle_ligne'] ?: $trans['libelle_ecriture'], 0, 35), 1, 0, 'L');
    $pdf->Cell(25, 6, $compte_caisse, 1, 0, 'C');
    $pdf->Cell(25, 6, $trans['debit'] > 0 ? format_number($trans['debit']) : '', 1, 0, 'R');
    $pdf->Cell(25, 6, $trans['credit'] > 0 ? format_number($trans['credit']) : '', 1, 0, 'R');
    $pdf->Cell(25, 6, format_number($trans['solde_apres']), 1, 1, 'R');
}

// Ligne TOTAL
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(71, 85, 105);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(185, 7, 'TOTAL', 1, 0, 'L', true);
$pdf->Cell(25, 7, format_number($total_entrees), 1, 0, 'R', true);
$pdf->Cell(25, 7, format_number($total_sorties), 1, 0, 'R', true);
$pdf->Cell(25, 7, format_number($solde_courant), 1, 1, 'R', true);

// Générer le PDF
$filename = 'Rapport_Caisse_' . $compte_caisse . '_' . date('Y-m-d', strtotime($date_debut)) . '_' . date('Y-m-d', strtotime($date_fin)) . '.pdf';
$pdf->Output($filename, 'D');
exit;
