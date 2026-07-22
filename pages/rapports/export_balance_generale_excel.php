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

// Générer l'Excel avec PhpSpreadsheet
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);
$sheet->setTitle('Balance');

// Titre
if ($tableau_filter === 'Bilan') {
    $titre_balance = 'BALANCE DES COMPTES DE BILAN';
} elseif ($tableau_filter === 'Resultat') {
    $titre_balance = 'BALANCE DES COMPTES DE RÉSULTAT';
} else {
    $titre_balance = 'BALANCE GÉNÉRALE';
}
$sheet->setCellValue('A1', $titre_balance);
$sheet->mergeCells('A1:J1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Informations
$sheet->setCellValue('A2', 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)));
$sheet->mergeCells('A2:J2');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

if (!empty($classe_filter)) {
    $sheet->setCellValue('A3', 'Classe: ' . $classe_filter);
    $sheet->mergeCells('A3:J3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row = 5;
} else {
    $row = 4;
}

// En-tête du tableau - Première ligne
$sheet->setCellValue('A' . $row, 'Compte');
$sheet->setCellValue('B' . $row, 'Intitulé');
$sheet->setCellValue('C' . $row, 'Tableau');
$sheet->setCellValue('D' . $row, 'Rubrique');
$sheet->setCellValue('E' . $row, 'Soldes Antérieurs');
$sheet->mergeCells('E' . $row . ':F' . $row);
$sheet->setCellValue('G' . $row, 'Mouvements Période');
$sheet->mergeCells('G' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, 'Soldes Finaux');
$sheet->mergeCells('I' . $row . ':J' . $row);

$sheet->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':J' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFE0E0E0');
$sheet->getStyle('A' . $row . ':J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row++;

// En-tête du tableau - Deuxième ligne
$sheet->setCellValue('A' . $row, '');
$sheet->mergeCells('A' . ($row-1) . ':A' . $row);
$sheet->setCellValue('B' . $row, '');
$sheet->mergeCells('B' . ($row-1) . ':B' . $row);
$sheet->setCellValue('C' . $row, '');
$sheet->mergeCells('C' . ($row-1) . ':C' . $row);
$sheet->setCellValue('D' . $row, '');
$sheet->mergeCells('D' . ($row-1) . ':D' . $row);
$sheet->setCellValue('E' . $row, 'Débit');
$sheet->setCellValue('F' . $row, 'Crédit');
$sheet->setCellValue('G' . $row, 'Débit');
$sheet->setCellValue('H' . $row, 'Crédit');
$sheet->setCellValue('I' . $row, 'Débit');
$sheet->setCellValue('J' . $row, 'Crédit');

$sheet->getStyle('E' . $row . ':J' . $row)->getFont()->setBold(true);
$sheet->getStyle('E' . $row . ':J' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFE0E0E0');
$sheet->getStyle('E' . $row . ':J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row++;

// Données
foreach ($balance as $ligne) {
    $sheet->setCellValue('A' . $row, $ligne['compte']);
    $sheet->setCellValue('B' . $row, $ligne['intitule']);
    $sheet->setCellValue('C' . $row, $ligne['tableau']);
    $sheet->setCellValue('D' . $row, $ligne['rubrique']);
    $sheet->setCellValue('E' . $row, $ligne['debit_anterieur'] > 0 ? $ligne['debit_anterieur'] : '');
    $sheet->setCellValue('F' . $row, $ligne['credit_anterieur'] > 0 ? $ligne['credit_anterieur'] : '');
    $sheet->setCellValue('G' . $row, $ligne['debit_periode'] > 0 ? $ligne['debit_periode'] : '');
    $sheet->setCellValue('H' . $row, $ligne['credit_periode'] > 0 ? $ligne['credit_periode'] : '');
    $sheet->setCellValue('I' . $row, $ligne['debit_final'] > 0 ? $ligne['debit_final'] : '');
    $sheet->setCellValue('J' . $row, $ligne['credit_final'] > 0 ? $ligne['credit_final'] : '');

    // Format monétaire
    if ($ligne['debit_anterieur'] > 0) {
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    if ($ligne['credit_anterieur'] > 0) {
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    if ($ligne['debit_periode'] > 0) {
        $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    if ($ligne['credit_periode'] > 0) {
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    if ($ligne['debit_final'] > 0) {
        $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    if ($ligne['credit_final'] > 0) {
        $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    $sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
}

// Ligne totaux
$sheet->setCellValue('A' . $row, 'TOTAUX');
$sheet->mergeCells('A' . $row . ':D' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->setCellValue('E' . $row, $totaux['debit_anterieur']);
$sheet->setCellValue('F' . $row, $totaux['credit_anterieur']);
$sheet->setCellValue('G' . $row, $totaux['debit_periode']);
$sheet->setCellValue('H' . $row, $totaux['credit_periode']);
$sheet->setCellValue('I' . $row, $totaux['debit_final']);
$sheet->setCellValue('J' . $row, $totaux['credit_final']);

$sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

$sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A' . $row . ':J' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFE0E0E0');
$row++;

// Ligne équilibre
$ecart_anterieur = $totaux['debit_anterieur'] - $totaux['credit_anterieur'];
$ecart_periode = $totaux['debit_periode'] - $totaux['credit_periode'];
$ecart_final = $totaux['debit_final'] - $totaux['credit_final'];
$equilibre_anterieur = abs($ecart_anterieur) < 0.01;
$equilibre_periode = abs($ecart_periode) < 0.01;
$equilibre_final = abs($ecart_final) < 0.01;

$sheet->setCellValue('A' . $row, 'Équilibre');
$sheet->mergeCells('A' . $row . ':D' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);

// Afficher le statut avec le montant de l'écart si déséquilibré
$statut_anterieur = $equilibre_anterieur ? 'Équilibré' : 'Écart: ' . number_format(abs($ecart_anterieur), 2, ',', ' ') . ' (' . ($ecart_anterieur > 0 ? 'D' : 'C') . ')';
$statut_periode = $equilibre_periode ? 'Équilibré' : 'Écart: ' . number_format(abs($ecart_periode), 2, ',', ' ') . ' (' . ($ecart_periode > 0 ? 'D' : 'C') . ')';
$statut_final = $equilibre_final ? 'Équilibré' : 'Écart: ' . number_format(abs($ecart_final), 2, ',', ' ') . ' (' . ($ecart_final > 0 ? 'D' : 'C') . ')';

$sheet->setCellValue('E' . $row, $statut_anterieur);
$sheet->mergeCells('E' . $row . ':F' . $row);
$sheet->setCellValue('G' . $row, $statut_periode);
$sheet->mergeCells('G' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, $statut_final);
$sheet->mergeCells('I' . $row . ':J' . $row);

// Couleur rouge pour les cellules déséquilibrées
if (!$equilibre_anterieur) {
    $sheet->getStyle('E' . $row . ':F' . $row)->getFont()->getColor()->setARGB('FFDC2626');
}
if (!$equilibre_periode) {
    $sheet->getStyle('G' . $row . ':H' . $row)->getFont()->getColor()->setARGB('FFDC2626');
}
if (!$equilibre_final) {
    $sheet->getStyle('I' . $row . ':J' . $row)->getFont()->getColor()->setARGB('FFDC2626');
}

$sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A' . $row . ':J' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFE0E0E0');
$sheet->getStyle('E' . $row . ':J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Ajuster la largeur des colonnes
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(50);
$sheet->getColumnDimension('C')->setWidth(12);
$sheet->getColumnDimension('D')->setWidth(25);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);
$sheet->getColumnDimension('I')->setWidth(15);
$sheet->getColumnDimension('J')->setWidth(15);

// ── Feuille 2 : Synthèse par rubrique ───────────────────────────────────────
$sheet2 = $spreadsheet->createSheet();
$sheet2->setShowGridlines(false);
$sheet2->setTitle('Synthèse rubriques');

// Agréger les soldes par rubrique à partir de $balance
$synthese = [];
foreach ($balance as $ligne) {
    if (empty($ligne['rubrique'])) continue; // ignorer les comptes sans rubrique
    $solde_net = $ligne['debit_final'] - $ligne['credit_final'];
    $rubrique_key = $ligne['rubrique'];
    $tableau_val  = $ligne['tableau'];
    $agg_key = $rubrique_key . '||' . $tableau_val;

    if (!isset($synthese[$agg_key])) {
        $synthese[$agg_key] = [
            'rubrique' => $rubrique_key,
            'tableau'  => $tableau_val,
            'solde'    => 0,
        ];
    }
    $synthese[$agg_key]['solde'] += $solde_net;
}

// Trier : Bilan avant Résultat, puis par rubrique alphabétique
usort($synthese, function($a, $b) {
    $ordre = ['Bilan' => 0, 'Résultat' => 1];
    $oa = $ordre[$a['tableau']] ?? 2;
    $ob = $ordre[$b['tableau']] ?? 2;
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($a['rubrique'], $b['rubrique']);
});

// Titre feuille 2
$sheet2->setCellValue('A1', 'SYNTHÈSE PAR RUBRIQUE SYSCOHADA');
$sheet2->mergeCells('A1:D1');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet2->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet2->setCellValue('A2', 'Période du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)));
$sheet2->mergeCells('A2:D2');
$sheet2->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$r2 = 4; // ligne de départ des données

// En-tête feuille 2
$entetes2 = ['Rubrique', 'Tableau', 'Solde', 'Nature'];
$cols2 = ['A', 'B', 'C', 'D'];
foreach ($entetes2 as $i => $label) {
    $sheet2->setCellValue($cols2[$i] . $r2, $label);
}
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getFont()->setBold(true);
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFE0E0E0');
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$r2++;

// Lignes de données
$total_synthese = 0;
foreach ($synthese as $item) {
    $solde   = $item['solde'];
    $nature  = $solde > 0.005 ? 'Débiteur' : ($solde < -0.005 ? 'Créditeur' : 'Nul');
    $is_bilan = ($item['tableau'] === 'Bilan');

    $sheet2->setCellValue('A' . $r2, $item['rubrique']);
    $sheet2->setCellValue('B' . $r2, $item['tableau']);
    $sheet2->setCellValue('C' . $r2, $solde);
    $sheet2->setCellValue('D' . $r2, $nature);

    $sheet2->getStyle('C' . $r2)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle('C' . $r2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Couleur du texte Nature
    if ($nature === 'Débiteur') {
        $sheet2->getStyle('D' . $r2)->getFont()->getColor()->setARGB('FF1E3A8A');
    } elseif ($nature === 'Créditeur') {
        $sheet2->getStyle('D' . $r2)->getFont()->getColor()->setARGB('FFDC2626');
    } else {
        $sheet2->getStyle('D' . $r2)->getFont()->getColor()->setARGB('FF6B7280');
    }

    $sheet2->getStyle('A' . $r2 . ':D' . $r2)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet2->getStyle('D' . $r2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $total_synthese += $solde;
    $r2++;
}

// Ligne de total feuille 2
$sheet2->setCellValue('A' . $r2, 'TOTAL');
$sheet2->mergeCells('A' . $r2 . ':B' . $r2);
$sheet2->setCellValue('C' . $r2, $total_synthese);
$ecart_label = abs($total_synthese) < 0.01 ? 'Équilibré' : 'Écart: ' . number_format(abs($total_synthese), 2, ',', ' ');
$sheet2->setCellValue('D' . $r2, $ecart_label);
$sheet2->getStyle('C' . $r2)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('C' . $r2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getFont()->setBold(true);
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFE0E0E0');
$sheet2->getStyle('A' . $r2 . ':D' . $r2)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet2->getStyle('D' . $r2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
if (abs($total_synthese) >= 0.01) {
    $sheet2->getStyle('D' . $r2)->getFont()->getColor()->setARGB('FFDC2626'); // rouge si écart
}

// Largeurs colonnes feuille 2
$sheet2->getColumnDimension('A')->setWidth(15);
$sheet2->getColumnDimension('B')->setWidth(15);
$sheet2->getColumnDimension('C')->setWidth(25);
$sheet2->getColumnDimension('D')->setWidth(15);

// Revenir à la première feuille par défaut
$spreadsheet->setActiveSheetIndex(0);

// Générer le fichier
$writer = new Xlsx($spreadsheet);
if ($tableau_filter === 'Bilan') {
    $filename = 'balance_bilan_' . date('Ymd') . '.xlsx';
} elseif ($tableau_filter === 'Resultat') {
    $filename = 'balance_resultat_' . date('Ymd') . '.xlsx';
} else {
    $filename = 'Balance_Generale_' . date('YmdHis') . '.xlsx';
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
