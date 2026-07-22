<?php
// Augmenter les limites de temps et mémoire de manière plus agressive
@ini_set('max_execution_time', '600'); // 10 minutes
@set_time_limit(600);
@ini_set('memory_limit', '1024M'); // 1GB

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
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$journal_filter = isset($_GET['journal']) ? $_GET['journal'] : '';
$statut_filter = isset($_GET['statut']) ? $_GET['statut'] : 'Validé';

// Récupérer les écritures et leurs lignes en UNE SEULE requête (optimisation majeure)
$ecritures = [];
$total_debit = 0;
$total_credit = 0;

try {
    // Requête optimisée avec JOIN pour récupérer tout en une fois
    $sql = "
        SELECT
            e.id,
            e.numero_ecriture,
            e.date_ecriture,
            e.journal,
            cj.libelle as journal_libelle,
            e.libelle,
            e.num_piece,
            e.reference_piece,
            e.statut,
            pt.nom as tiers_nom,
            le.id as ligne_id,
            le.compte,
            le.debit,
            le.credit,
            le.libelle as ligne_libelle,
            pc.intitule_compte
        FROM ecritures e
        LEFT JOIN journaux cj ON e.journal = cj.code_journal AND cj.societe_id = e.societe_id
        LEFT JOIN plan_tiers pt ON e.id_tiers = pt.id
        LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture
        LEFT JOIN plan_comptable pc ON le.compte = pc.compte
        WHERE e.societe_id = ? AND e.date_ecriture BETWEEN ? AND ?
    ";

    $params = [$societe_id, $date_debut, $date_fin];

    if (!empty($journal_filter)) {
        $sql .= " AND e.journal = ?";
        $params[] = $journal_filter;
    }

    if (!empty($statut_filter)) {
        $sql .= " AND e.statut = ?";
        $params[] = $statut_filter;
    }

    $sql .= " ORDER BY e.date_ecriture, e.numero_ecriture, le.id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Regrouper les résultats par écriture
    $current_ecriture_id = null;
    $current_ecriture = null;
    $current_lignes = [];
    $current_debit = 0;
    $current_credit = 0;

    foreach ($results as $row) {
        if ($current_ecriture_id !== $row['id']) {
            // Sauvegarder l'écriture précédente si elle existe
            if ($current_ecriture_id !== null) {
                $ecritures[] = [
                    'ecriture' => $current_ecriture,
                    'lignes' => $current_lignes,
                    'total_debit' => $current_debit,
                    'total_credit' => $current_credit
                ];
                $total_debit += $current_debit;
                $total_credit += $current_credit;
            }

            // Nouvelle écriture
            $current_ecriture_id = $row['id'];
            $current_ecriture = [
                'id' => $row['id'],
                'numero_ecriture' => $row['numero_ecriture'],
                'date_ecriture' => $row['date_ecriture'],
                'journal' => $row['journal'],
                'journal_libelle' => $row['journal_libelle'],
                'libelle' => $row['libelle'],
                'num_piece' => $row['num_piece'],
                'reference_piece' => $row['reference_piece'],
                'statut' => $row['statut'],
                'tiers_nom' => $row['tiers_nom']
            ];
            $current_lignes = [];
            $current_debit = 0;
            $current_credit = 0;
        }

        // Ajouter la ligne
        if ($row['ligne_id']) {
            $current_lignes[] = [
                'id' => $row['ligne_id'],
                'compte' => $row['compte'],
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'libelle' => $row['ligne_libelle'],
                'intitule_compte' => $row['intitule_compte']
            ];
            $current_debit += $row['debit'];
            $current_credit += $row['credit'];
        }
    }

    // Sauvegarder la dernière écriture
    if ($current_ecriture_id !== null) {
        $ecritures[] = [
            'ecriture' => $current_ecriture,
            'lignes' => $current_lignes,
            'total_debit' => $current_debit,
            'total_credit' => $current_credit
        ];
        $total_debit += $current_debit;
        $total_credit += $current_credit;
    }

} catch (Exception $e) {
    die('Erreur lors du chargement: ' . $e->getMessage());
}

// Créer un nouveau document Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);

// Titre
$sheet->setCellValue('A1', 'JOURNAL GÉNÉRAL');
$sheet->mergeCells('A1:E1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Période
$row = 2;
$periode_text = 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin));
if (!empty($journal_filter)) {
    $periode_text .= ' - Journal: ' . $journal_filter;
}
if (!empty($statut_filter)) {
    $periode_text .= ' - Statut: ' . $statut_filter;
}
$sheet->setCellValue('A' . $row, $periode_text);
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row += 2;

// Pré-définir les styles (appliqués une seule fois à la fin pour optimisation)
$stylesRanges = [
    'headers' => [],
    'numbers' => []
];

// Pour chaque écriture - VERSION SIMPLIFIÉE SANS STYLES
foreach ($ecritures as $item) {
    $e = $item['ecriture'];
    $lignes = $item['lignes'];

    // En-tête de l'écriture
    $header_text = 'Écriture #' . $e['numero_ecriture'] . ' - ' . date('d/m/Y', strtotime($e['date_ecriture'])) . ' - ' . $e['journal'] . ' - ' . $e['statut'];
    $sheet->setCellValue('A' . $row, $header_text);
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $stylesRanges['headers'][] = 'A' . $row . ':E' . $row;
    $row++;

    // Libellé
    $sheet->setCellValue('A' . $row, 'Libellé: ' . $e['libelle']);
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $row++;

    // Informations supplémentaires
    if ($e['num_piece'] || $e['reference_piece'] || $e['tiers_nom']) {
        $info_text = '';
        if ($e['num_piece']) $info_text .= 'N° Pièce: ' . $e['num_piece'] . '  ';
        if ($e['reference_piece']) $info_text .= 'Réf: ' . $e['reference_piece'] . '  ';
        if ($e['tiers_nom']) $info_text .= 'Tiers: ' . $e['tiers_nom'];
        $sheet->setCellValue('A' . $row, $info_text);
        $sheet->mergeCells('A' . $row . ':E' . $row);
        $row++;
    }

    // En-tête du tableau des lignes
    $sheet->setCellValue('A' . $row, 'Compte');
    $sheet->setCellValue('B' . $row, 'Intitulé');
    $sheet->setCellValue('C' . $row, 'Libellé ligne');
    $sheet->setCellValue('D' . $row, 'Débit');
    $sheet->setCellValue('E' . $row, 'Crédit');
    $row++;

    // Lignes de l'écriture - SANS STYLES INDIVIDUELS
    foreach ($lignes as $ligne) {
        $sheet->setCellValue('A' . $row, $ligne['compte']);
        $sheet->setCellValue('B' . $row, $ligne['intitule_compte']);
        $sheet->setCellValue('C' . $row, $ligne['libelle'] ?? '-');
        $sheet->setCellValue('D' . $row, $ligne['debit'] > 0 ? $ligne['debit'] : '');
        $sheet->setCellValue('E' . $row, $ligne['credit'] > 0 ? $ligne['credit'] : '');

        // Collecter les plages pour formatage des nombres
        if ($ligne['debit'] > 0 || $ligne['credit'] > 0) {
            $stylesRanges['numbers'][] = 'D' . $row . ':E' . $row;
        }

        $row++;
    }

    // Total de l'écriture
    $sheet->setCellValue('A' . $row, 'Total écriture');
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('D' . $row, $item['total_debit']);
    $sheet->setCellValue('E' . $row, $item['total_credit']);
    $stylesRanges['numbers'][] = 'D' . $row . ':E' . $row;

    $row += 2;
}

// Appliquer les styles de formatage des nombres en batch (beaucoup plus rapide)
foreach ($stylesRanges['numbers'] as $range) {
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

// Totaux généraux - VERSION SIMPLIFIÉE
$sheet->setCellValue('A' . $row, 'TOTAUX GÉNÉRAUX');
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->setCellValue('D' . $row, $total_debit);
$sheet->setCellValue('E' . $row, $total_credit);
$sheet->getStyle('D' . $row . ':E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);

$row++;

// Équilibre
$equilibre = abs($total_debit - $total_credit) < 0.01;
$sheet->setCellValue('A' . $row, 'Équilibre');
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->setCellValue('D' . $row, $equilibre ? 'Équilibré' : 'Déséquilibré');
$sheet->mergeCells('D' . $row . ':E' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);

// Ajuster la largeur des colonnes
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(50);
$sheet->getColumnDimension('D')->setWidth(18);
$sheet->getColumnDimension('E')->setWidth(18);

// Générer le fichier Excel
$filename = 'Journal_General_' . date('YmdHis') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Désactiver le calcul automatique pour accélérer la génération
$spreadsheet->getActiveSheet()->setSelectedCell('A1');

$writer = new Xlsx($spreadsheet);
// Optimisations du writer
$writer->setPreCalculateFormulas(false);
$writer->save('php://output');
exit;
