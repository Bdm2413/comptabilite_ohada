<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

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

// Fonction helper pour formater les montants
function format_montant($number) {
    return number_format($number ?: 0, 2, ',', ' ');
}

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

$pdf = new MYPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Comptabilité SYSCOHADA');
$pdf->SetAuthor('Comptabilité SYSCOHADA');
$pdf->SetTitle('Rapport de Rapprochement Bancaire');
$pdf->SetSubject('Rapprochement Bancaire');

$pdf->setPrintHeader(false);
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

$pdf->AddPage();

// Titre principal
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetTextColor(8, 145, 178);
$pdf->Cell(0, 10, 'RAPPORT DE RAPPROCHEMENT BANCAIRE', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 6, 'Comptabilité OHADA', 0, 1, 'C');
$pdf->Ln(5);

// Informations du compte
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(224, 242, 254);
$pdf->Cell(0, 8, 'Informations du Compte', 0, 1, 'L', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 6, 'Compte bancaire :', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, $rapprochement['compte_banque'] . ' - ' . $rapprochement['intitule_compte'], 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 6, 'Période :', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, $mois_names[$rapprochement['mois']] . ' ' . $rapprochement['annee'], 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 6, 'Dates :', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, 'Du ' . date('d/m/Y', strtotime($rapprochement['date_debut'])) . ' au ' . date('d/m/Y', strtotime($rapprochement['date_fin'])), 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 6, 'Statut :', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
if ($rapprochement['statut'] === 'Validé') {
    $pdf->SetTextColor(6, 95, 70);
} else {
    $pdf->SetTextColor(146, 64, 14);
}
$pdf->Cell(0, 6, $rapprochement['statut'], 0, 1, 'L');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 6, 'Date d\'édition :', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, date('d/m/Y à H:i'), 0, 1, 'L');
$pdf->Ln(5);

// Tableau de Rapprochement
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(224, 242, 254);
$pdf->Cell(0, 8, 'Tableau de Rapprochement', 0, 1, 'L', true);
$pdf->Ln(2);

// Solde comptable
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(248, 250, 252);
$pdf->Cell(110, 7, 'Solde comptable au ' . date('d/m/Y', strtotime($rapprochement['date_fin'])), 1, 0, 'L', true);
$pdf->SetFont('courier', 'B', 9);
$pdf->SetTextColor(8, 145, 178);
$pdf->Cell(70, 7, format_montant($rapprochement['solde_comptable']) . ' F CFA', 1, 1, 'R');

// Solde bancaire
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(110, 7, 'Solde bancaire (relevé)', 1, 0, 'L', true);
$pdf->SetFont('courier', 'B', 9);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(70, 7, format_montant($rapprochement['solde_bancaire']) . ' F CFA', 1, 1, 'R');

// Écart
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(254, 243, 199);
$pdf->Cell(110, 7, 'Écart à justifier', 1, 0, 'L', true);
$pdf->SetFont('courier', 'B', 10);
$pdf->SetTextColor(146, 64, 14);
$ecart = $rapprochement['ecart_calcule'];
if (abs($ecart) < 0.01) {
    $ecart_text = '0,00 F CFA (Équilibré)';
} else {
    $ecart_text = ($ecart >= 0 ? '+' : '') . format_montant($ecart) . ' F CFA';
}
$pdf->Cell(70, 7, $ecart_text, 1, 1, 'R', true);
$pdf->Ln(5);

// Calcul du Solde Ajusté
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(124, 58, 237);
$pdf->SetFillColor(243, 232, 255);
$pdf->Cell(0, 8, 'Calcul du Solde Ajusté', 0, 1, 'L', true);
$pdf->Ln(2);

// Total justifications au débit
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(248, 250, 252);
$pdf->Cell(110, 7, 'Total justifications au débit', 1, 0, 'L', true);
$pdf->SetFont('courier', '', 9);
$pdf->SetTextColor(5, 150, 105);
$pdf->Cell(70, 7, format_montant($total_debit_justif) . ' F CFA', 1, 1, 'R');

// Total justifications au crédit
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(110, 7, 'Total justifications au crédit', 1, 0, 'L', true);
$pdf->SetFont('courier', '', 9);
$pdf->SetTextColor(220, 38, 38);
$pdf->Cell(70, 7, format_montant($total_credit_justif) . ' F CFA', 1, 1, 'R');

// Nouveau solde comptable ajusté
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(124, 58, 237);
$pdf->SetFillColor(243, 232, 255);
$pdf->Cell(110, 7, 'Nouveau solde comptable ajusté', 1, 0, 'L', true);
$pdf->SetFont('courier', 'B', 10);
$pdf->Cell(70, 7, format_montant($solde_ajuste) . ' F CFA', 1, 1, 'R', true);

// Différence finale avec le solde bancaire
$pdf->SetFont('helvetica', 'B', 9);
if (abs($diff_finale) < 0.01) {
    $pdf->SetTextColor(6, 95, 70);
    $pdf->SetFillColor(209, 250, 229);
    $diff_text = '0,00 F CFA ✓ RAPPROCHEMENT OK';
} else {
    $pdf->SetTextColor(146, 64, 14);
    $pdf->SetFillColor(254, 243, 199);
    $diff_text = ($diff_finale >= 0 ? '+' : '') . format_montant($diff_finale) . ' F CFA';
}
$pdf->Cell(110, 7, 'Différence finale avec le solde bancaire', 1, 0, 'L', true);
$pdf->SetFont('courier', 'B', 9);
$pdf->Cell(70, 7, $diff_text, 1, 1, 'R', true);
$pdf->Ln(5);

// Lignes de Justification
if (!empty($lignes)) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(8, 145, 178);
    $pdf->SetFillColor(224, 242, 254);
    $pdf->Cell(0, 8, 'Lignes de Justification (' . count($lignes) . ' ligne' . (count($lignes) > 1 ? 's' : '') . ')', 0, 1, 'L', true);
    $pdf->Ln(2);

    // En-têtes du tableau
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor(8, 145, 178);
    $pdf->Cell(25, 7, 'Date', 1, 0, 'C', true);
    $pdf->Cell(60, 7, 'Libellé', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Catégorie', 1, 0, 'C', true);
    $pdf->Cell(20, 7, 'Type', 1, 0, 'C', true);
    $pdf->Cell(40, 7, 'Montant', 1, 1, 'C', true);

    // Données
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    foreach ($lignes as $ligne) {
        if ($fill) {
            $pdf->SetFillColor(248, 250, 252);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        $pdf->Cell(25, 6, date('d/m/Y', strtotime($ligne['date_operation'])), 1, 0, 'C', $fill);
        $pdf->Cell(60, 6, substr($ligne['libelle'], 0, 35), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, substr($ligne['categorie'], 0, 20), 1, 0, 'L', $fill);

        if ($ligne['type_operation'] === 'Débit') {
            $pdf->SetTextColor(5, 150, 105);
        } else {
            $pdf->SetTextColor(220, 38, 38);
        }
        $pdf->Cell(20, 6, $ligne['type_operation'], 1, 0, 'C', $fill);

        $pdf->SetFont('courier', '', 8);
        $pdf->Cell(40, 6, format_montant($ligne['montant']), 1, 1, 'R', $fill);

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $fill = !$fill;
    }

    // Totaux
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(120, 6, 'TOTAUX', 1, 0, 'R', true);
    $pdf->SetTextColor(5, 150, 105);
    $pdf->Cell(20, 6, 'Débit', 1, 0, 'C', true);
    $pdf->SetFont('courier', 'B', 8);
    $pdf->Cell(40, 6, format_montant($total_debit_justif), 1, 1, 'R', true);

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(120, 6, '', 1, 0, 'R', true);
    $pdf->SetTextColor(220, 38, 38);
    $pdf->Cell(20, 6, 'Crédit', 1, 0, 'C', true);
    $pdf->SetFont('courier', 'B', 8);
    $pdf->Cell(40, 6, format_montant($total_credit_justif), 1, 1, 'R', true);
} else {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 10, 'Aucune ligne de justification renseignée', 0, 1, 'C');
}

// Notes
if (!empty($rapprochement['notes'])) {
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(146, 64, 14);
    $pdf->SetFillColor(254, 243, 199);
    $pdf->Cell(0, 7, 'Notes et Commentaires', 0, 1, 'L', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(120, 53, 15);
    $pdf->SetFillColor(255, 251, 235);
    $pdf->MultiCell(0, 6, $rapprochement['notes'], 1, 'L', true);
}

$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 5, 'Document généré automatiquement - Comptabilité OHADA', 0, 1, 'C');
$pdf->Cell(0, 5, 'Rapport extra-comptable - Aucune écriture comptable n\'est générée par ce document', 0, 1, 'C');

// Générer le PDF
$filename = 'Rapprochement_Bancaire_' . $rapprochement['compte_banque'] . '_' . $rapprochement['mois'] . '_' . $rapprochement['annee'] . '.pdf';
$pdf->Output($filename, 'D');
