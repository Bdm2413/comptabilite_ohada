<?php
require_once '../../config/config.php';
requireLogin();

// Récupérer les paramètres
$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$classe_filter = $_GET['classe'] ?? '';
$show_zero = isset($_GET['show_zero']) && $_GET['show_zero'] == '1';
$tableau_filter = in_array($_GET['tableau'] ?? '', ['Bilan', 'Resultat']) ? $_GET['tableau'] : null;

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction helper pour number_format
function safe_number_format($number, $decimals = 2, $decimal_separator = ',', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer la balance
$balance = [];
$totaux = [
    'debit_anterieur' => 0,
    'credit_anterieur' => 0,
    'debit_periode' => 0,
    'credit_periode' => 0,
    'debit_final' => 0,
    'credit_final' => 0
];

// Extraire l'année de début pour la logique des comptes de résultat (classes 6, 7, 8)
$annee_debut = date('Y', strtotime($date_debut));
$date_debut_annee = $annee_debut . '-01-01';

// Requête pour récupérer tous les comptes avec leurs mouvements
// IMPORTANT: Pour les comptes de gestion (classes 6, 7, 8), les soldes antérieurs
// ne sont calculés qu'à partir du 1er janvier de l'année en cours (pas de report)
$sql = "
    SELECT
        pc.compte,
        pc.intitule_compte,
        pc.tableau,
        pc.bd,
        pc.bc,
        pc.rd,
        pc.rc,
        -- Mouvements antérieurs (pour comptes de bilan uniquement, sinon depuis début d'année)
        COALESCE(SUM(CASE
            WHEN e.statut = 'Validé'
                AND e.societe_id = ?
                AND e.date_ecriture < ?
                AND (LEFT(pc.compte, 1) NOT IN ('6', '7', '8') OR e.date_ecriture >= ?)
            THEN le.debit
            ELSE 0
        END), 0) as debit_anterieur,
        COALESCE(SUM(CASE
            WHEN e.statut = 'Validé'
                AND e.societe_id = ?
                AND e.date_ecriture < ?
                AND (LEFT(pc.compte, 1) NOT IN ('6', '7', '8') OR e.date_ecriture >= ?)
            THEN le.credit
            ELSE 0
        END), 0) as credit_anterieur,
        -- Mouvements période
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' AND e.societe_id = ? THEN le.debit ELSE 0 END), 0) as debit_periode,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' AND e.societe_id = ? THEN le.credit ELSE 0 END), 0) as credit_periode
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND pc.societe_id = ?
";

$params = [$societe_id, $date_debut, $date_debut_annee, $societe_id, $date_debut, $date_debut_annee, $date_debut, $date_fin, $societe_id, $date_debut, $date_fin, $societe_id, $societe_id];

if ($tableau_filter) {
    $sql .= " AND pc.tableau = ?";
    $params[] = $tableau_filter;
}

if (!empty($classe_filter)) {
    $sql .= " AND LEFT(pc.compte, 1) = ?";
    $params[] = $classe_filter;
}

$sql .= " GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc ORDER BY pc.compte";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$resultats = $stmt->fetchAll();

foreach ($resultats as $row) {
    $solde_anterieur = $row['debit_anterieur'] - $row['credit_anterieur'];
    $solde_final = ($row['debit_anterieur'] + $row['debit_periode']) - ($row['credit_anterieur'] + $row['credit_periode']);

    if (!$show_zero && abs($solde_final) < 0.01 && abs($row['debit_periode']) < 0.01 && abs($row['credit_periode']) < 0.01) {
        continue;
    }

    // Déterminer le tableau et la rubrique
    $tableau = $row['tableau'] ?? '';
    $rubrique = '';

    if ($tableau === 'Bilan') {
        if ($solde_final > 0) {
            $rubrique = $row['bd'] ?? '';
        } else if ($solde_final < 0) {
            $rubrique = $row['bc'] ?? '';
        }
    } else if ($tableau === 'Résultat') {
        if ($solde_final > 0) {
            $rubrique = $row['rd'] ?? '';
        } else if ($solde_final < 0) {
            $rubrique = $row['rc'] ?? '';
        }
    }

    $ligne = [
        'compte' => $row['compte'],
        'intitule' => $row['intitule_compte'],
        'tableau' => $tableau,
        'rubrique' => $rubrique,
        'debit_anterieur' => $solde_anterieur > 0 ? $solde_anterieur : 0,
        'credit_anterieur' => $solde_anterieur < 0 ? abs($solde_anterieur) : 0,
        'debit_periode' => $row['debit_periode'],
        'credit_periode' => $row['credit_periode'],
        'debit_final' => $solde_final > 0 ? $solde_final : 0,
        'credit_final' => $solde_final < 0 ? abs($solde_final) : 0,
    ];

    $balance[] = $ligne;

    $totaux['debit_anterieur'] += $ligne['debit_anterieur'];
    $totaux['credit_anterieur'] += $ligne['credit_anterieur'];
    $totaux['debit_periode'] += $ligne['debit_periode'];
    $totaux['credit_periode'] += $ligne['credit_periode'];
    $totaux['debit_final'] += $ligne['debit_final'];
    $totaux['credit_final'] += $ligne['credit_final'];
}

// Générer le PDF avec TCPDF
require_once '../../vendor/autoload.php';

// Déterminer le titre selon le filtre tableau
if ($tableau_filter === 'Bilan') {
    $titre_balance = 'BALANCE DES COMPTES DE BILAN';
} elseif ($tableau_filter === 'Resultat') {
    $titre_balance = 'BALANCE DES COMPTES DE RÉSULTAT';
} else {
    $titre_balance = 'BALANCE GÉNÉRALE';
}

class BalancePDF extends TCPDF {
    public $titreBalance = 'BALANCE GÉNÉRALE';

    public function Header() {
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 10, $this->titreBalance, 0, 1, 'C');
        $this->Ln(2);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new BalancePDF('L', 'mm', 'A4', true, 'UTF-8');
$pdf->titreBalance = $titre_balance;
$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor('Système Comptable');
$pdf->SetTitle($titre_balance);

$pdf->SetMargins(15, 30, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// Informations avec style amélioré
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(0, 102, 204);
$pdf->Cell(0, 5, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
if (!empty($classe_filter)) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Classe: ' . $classe_filter, 0, 1, 'C');
}
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Calculer la largeur totale et centrer le tableau
$tableWidth = 253; // Largeur totale du tableau
$pageWidth = $pdf->getPageWidth();
$leftMargin = ($pageWidth - $tableWidth) / 2;
$pdf->SetLeftMargin($leftMargin);
$pdf->SetX($leftMargin);

// En-tête du tableau
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(70, 130, 180);
$pdf->SetTextColor(255, 255, 255);

// Première ligne d'en-tête (avec colspan simulé)
$pdf->Cell(15, 10, 'Compte', 1, 0, 'C', true);
$pdf->Cell(65, 10, 'Intitulé', 1, 0, 'C', true);
$pdf->Cell(18, 10, 'Tableau', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Rubrique', 1, 0, 'C', true);
$pdf->Cell(40, 5, 'Soldes Antérieurs', 1, 0, 'C', true);
$pdf->Cell(40, 5, 'Mouvements Période', 1, 0, 'C', true);
$pdf->Cell(40, 5, 'Soldes Finaux', 1, 1, 'C', true);

// Position pour la deuxième ligne
$pdf->SetX($leftMargin + 133); // Position après les 4 premières colonnes (15+65+18+35)
$pdf->Cell(20, 5, 'Débit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Crédit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Débit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Crédit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Débit', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Crédit', 1, 1, 'C', true);

// Données avec alternance de couleurs
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($balance as $ligne) {
    $pdf->SetX($leftMargin);
    if ($fill) {
        $pdf->SetFillColor(245, 245, 245);
    }
    $pdf->Cell(15, 5, $ligne['compte'], 1, 0, 'L', $fill);
    $pdf->Cell(65, 5, mb_substr($ligne['intitule'], 0, 75), 1, 0, 'L', $fill);
    $pdf->Cell(18, 5, $ligne['tableau'], 1, 0, 'C', $fill);
    $pdf->Cell(35, 5, mb_substr($ligne['rubrique'], 0, 40), 1, 0, 'L', $fill);
    $pdf->Cell(20, 5, $ligne['debit_anterieur'] > 0 ? safe_number_format($ligne['debit_anterieur']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell(20, 5, $ligne['credit_anterieur'] > 0 ? safe_number_format($ligne['credit_anterieur']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell(20, 5, $ligne['debit_periode'] > 0 ? safe_number_format($ligne['debit_periode']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell(20, 5, $ligne['credit_periode'] > 0 ? safe_number_format($ligne['credit_periode']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell(20, 5, $ligne['debit_final'] > 0 ? safe_number_format($ligne['debit_final']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell(20, 5, $ligne['credit_final'] > 0 ? safe_number_format($ligne['credit_final']) : '-', 1, 1, 'R', $fill);
    $fill = !$fill;
}

// Totaux
$pdf->SetX($leftMargin);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(70, 130, 180);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(133, 6, 'TOTAUX', 1, 0, 'L', true);
$pdf->Cell(20, 6, safe_number_format($totaux['debit_anterieur']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['credit_anterieur']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['debit_periode']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['credit_periode']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['debit_final']), 1, 0, 'R', true);
$pdf->Cell(20, 6, safe_number_format($totaux['credit_final']), 1, 1, 'R', true);

// Vérification équilibre
$ecart_anterieur = $totaux['debit_anterieur'] - $totaux['credit_anterieur'];
$ecart_periode = $totaux['debit_periode'] - $totaux['credit_periode'];
$ecart_final = $totaux['debit_final'] - $totaux['credit_final'];
$equilibre_anterieur = abs($ecart_anterieur) < 0.01;
$equilibre_periode = abs($ecart_periode) < 0.01;
$equilibre_final = abs($ecart_final) < 0.01;

// Préparer les textes avec montant d'écart si déséquilibré
$statut_anterieur = $equilibre_anterieur ? 'Équilibré' : 'Écart: ' . safe_number_format(abs($ecart_anterieur)) . ' (' . ($ecart_anterieur > 0 ? 'D' : 'C') . ')';
$statut_periode = $equilibre_periode ? 'Équilibré' : 'Écart: ' . safe_number_format(abs($ecart_periode)) . ' (' . ($ecart_periode > 0 ? 'D' : 'C') . ')';
$statut_final = $equilibre_final ? 'Équilibré' : 'Écart: ' . safe_number_format(abs($ecart_final)) . ' (' . ($ecart_final > 0 ? 'D' : 'C') . ')';

$pdf->SetX($leftMargin);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(133, 6, 'Équilibre', 1, 0, 'L', true);

// Couleur rouge pour les écarts
if (!$equilibre_anterieur) $pdf->SetTextColor(220, 38, 38);
$pdf->Cell(40, 6, $statut_anterieur, 1, 0, 'C', true);

$pdf->SetTextColor(0, 0, 0);
if (!$equilibre_periode) $pdf->SetTextColor(220, 38, 38);
$pdf->Cell(40, 6, $statut_periode, 1, 0, 'C', true);

$pdf->SetTextColor(0, 0, 0);
if (!$equilibre_final) $pdf->SetTextColor(220, 38, 38);
$pdf->Cell(40, 6, $statut_final, 1, 1, 'C', true);

// ── Page supplémentaire : Synthèse par rubrique SYSCOHADA ──────────────────
$pdf->AddPage();

// Titre de la section
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(70, 130, 180);
$pdf->Cell(0, 8, 'SYNTHÈSE PAR RUBRIQUE SYSCOHADA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 5, 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
$pdf->Ln(3);

// Agréger les soldes par rubrique à partir de $balance
$synthese_pdf = [];
foreach ($balance as $ligne) {
    if (empty($ligne['rubrique'])) continue; // ignorer les comptes sans rubrique
    $solde_net    = $ligne['debit_final'] - $ligne['credit_final'];
    $rubrique_key = $ligne['rubrique'];
    $tableau_val  = $ligne['tableau'];
    $agg_key = $rubrique_key . '||' . $tableau_val;

    if (!isset($synthese_pdf[$agg_key])) {
        $synthese_pdf[$agg_key] = [
            'rubrique' => $rubrique_key,
            'tableau'  => $tableau_val,
            'solde'    => 0,
        ];
    }
    $synthese_pdf[$agg_key]['solde'] += $solde_net;
}

// Trier : Bilan avant Résultat, puis par rubrique alphabétique
usort($synthese_pdf, function($a, $b) {
    $ordre = ['Bilan' => 0, 'Résultat' => 1];
    $oa = $ordre[$a['tableau']] ?? 2;
    $ob = $ordre[$b['tableau']] ?? 2;
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($a['rubrique'], $b['rubrique']);
});

// Centrage du tableau synthèse (4 colonnes : 25+30+45+25 = 125 mm)
$synthTableWidth = 125;
$synthLeftMargin = ($pdf->getPageWidth() - $synthTableWidth) / 2;
$pdf->SetLeftMargin($synthLeftMargin);
$pdf->SetX($synthLeftMargin);

// En-tête du tableau synthèse
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(70, 130, 180);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(25, 8, 'Rubrique',  1, 0, 'C', true);
$pdf->Cell(30, 8, 'Tableau',   1, 0, 'C', true);
$pdf->Cell(45, 8, 'Solde',     1, 0, 'C', true);
$pdf->Cell(25, 8, 'Nature',    1, 1, 'C', true);

// Lignes de données
$pdf->SetFont('helvetica', '', 8);
$total_synthese_pdf = 0;
foreach ($synthese_pdf as $item) {
    $solde  = $item['solde'];
    $nature = $solde > 0.005 ? 'Débiteur' : ($solde < -0.005 ? 'Créditeur' : 'Nul');
    $is_bilan = ($item['tableau'] === 'Bilan');

    $pdf->SetX($synthLeftMargin);
    $pdf->SetFillColor(245, 245, 245); // gris très clair, alternance avec blanc
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Cell(25, 6, mb_substr($item['rubrique'], 0, 12), 1, 0, 'L', false);
    $pdf->Cell(30, 6, $item['tableau'], 1, 0, 'C', false);
    $pdf->Cell(45, 6, safe_number_format($solde), 1, 0, 'R', false);

    // Couleur Nature
    if ($nature === 'Débiteur') {
        $pdf->SetTextColor(30, 58, 138);   // bleu foncé
    } elseif ($nature === 'Créditeur') {
        $pdf->SetTextColor(220, 38, 38);   // rouge
    } else {
        $pdf->SetTextColor(107, 114, 128); // gris
    }
    $pdf->Cell(25, 6, $nature, 1, 1, 'C', false);

    $total_synthese_pdf += $solde;
}

// Ligne de total synthèse
$pdf->SetX($synthLeftMargin);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(70, 130, 180);
$pdf->SetTextColor(255, 255, 255);
$ecart_label_pdf = abs($total_synthese_pdf) < 0.01
    ? 'Équilibré'
    : 'Écart: ' . safe_number_format(abs($total_synthese_pdf));
$pdf->Cell(55, 7, 'TOTAL', 1, 0, 'L', true);
$pdf->Cell(45, 7, safe_number_format($total_synthese_pdf), 1, 0, 'R', true);
if (abs($total_synthese_pdf) >= 0.01) {
    $pdf->SetTextColor(220, 38, 38); // rouge si écart
}
$pdf->Cell(25, 7, $ecart_label_pdf, 1, 1, 'C', true);

// Sortie du PDF
if ($tableau_filter === 'Bilan') {
    $filename = 'balance_bilan_' . date('Ymd') . '.pdf';
} elseif ($tableau_filter === 'Resultat') {
    $filename = 'balance_resultat_' . date('Ymd') . '.pdf';
} else {
    $filename = 'Balance_Generale_' . date('YmdHis') . '.pdf';
}
$pdf->Output($filename, 'D');
exit;
