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

// Migrations si nécessaire
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN periodicite ENUM('annuelle','mensuelle') DEFAULT 'annuelle'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement ADD COLUMN mois TINYINT DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN parent_id INT NULL"); } catch (Exception $e) {}

// Filtres
$exercice      = (int)($_GET['exercice'] ?? date('Y'));
$filtre_cat    = $_GET['categorie'] ?? 'toutes';
$filtre_statut = $_GET['statut'] ?? 'tous';
$vue           = in_array($_GET['vue'] ?? '', ['detail','condense']) ? $_GET['vue'] : 'detail';

$annee_debut = $exercice . '-01-01';
$annee_fin   = $exercice . '-12-31';

// Années disponibles
$stmt_years = $db->prepare("SELECT DISTINCT YEAR(date_acquisition) as annee FROM immobilisations WHERE societe_id=? ORDER BY annee DESC");
$stmt_years->execute([$societe_id]);
$annees_dispo = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
$annees_dispo = array_map('intval', $annees_dispo);
// S'assurer que l'année courante est toujours disponible
if (!in_array((int)date('Y'), $annees_dispo)) {
    array_unshift($annees_dispo, (int)date('Y'));
}
rsort($annees_dispo);

// Catégories
$categories = [
    'incorporelle'       => 'Immob. incorporelle',
    'terrain'            => 'Terrain',
    'batiment'           => 'Bâtiment',
    'amenagement'        => 'Aménagement & install.',
    'materiel_mobilier'  => 'Matériel & mobilier',
    'materiel_transport' => 'Matériel de transport',
    'materiel_info'      => 'Matériel informatique',
    'financiere'         => 'Immob. financière',
    'autre'              => 'Autre',
];

// Requête principale
$where  = ["i.societe_id = ?"];
$params = [$societe_id];

if ($filtre_cat !== 'toutes') {
    $where[] = "i.categorie = ?";
    $params[] = $filtre_cat;
}
if ($filtre_statut !== 'tous') {
    $where[] = "i.statut = ?";
    $params[] = $filtre_statut;
}
// Exclure les composants (parent_id IS NOT NULL) du listing principal
// Pour les parents qui ont des composants, agréger les valeurs des composants
$where[] = "i.parent_id IS NULL";
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT i.*,
        COALESCE(SUM(CASE WHEN d.exercice <= ? THEN d.montant ELSE 0 END), 0) as amort_cumul_total,
        COALESCE(SUM(CASE WHEN d.exercice = ?  THEN d.montant ELSE 0 END), 0) as dotation_exercice,
        (SELECT COUNT(*) FROM immobilisations c WHERE c.parent_id = i.id) as nb_composants,
        (SELECT COALESCE(SUM(c.valeur_brute), 0) FROM immobilisations c WHERE c.parent_id = i.id) as composants_valeur_brute,
        (SELECT COALESCE(SUM(cd.montant), 0)
            FROM immobilisations c
            JOIN dotations_amortissement cd ON c.id = cd.immobilisation_id
            WHERE c.parent_id = i.id AND cd.exercice <= ?) as composants_amort_cumul,
        (SELECT COALESCE(SUM(cd.montant), 0)
            FROM immobilisations c
            JOIN dotations_amortissement cd ON c.id = cd.immobilisation_id
            WHERE c.parent_id = i.id AND cd.exercice = ?) as composants_dotation_exercice
    FROM immobilisations i
    LEFT JOIN dotations_amortissement d ON i.id = d.immobilisation_id
    WHERE $whereStr
    GROUP BY i.id
    ORDER BY i.categorie, i.compte_immobilisation, i.designation
");
$stmt->execute(array_merge([$exercice, $exercice, $exercice, $exercice], $params));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement ligne par ligne
$lignes = [];
foreach ($rows as $immo) {
    $acq  = $immo['date_acquisition'];
    $cess = $immo['date_cession'];
    $nb_composants = (int)$immo['nb_composants'];

    // Pour les immobilisations par composants, utiliser les valeurs agrégées des composants
    if ($nb_composants > 0) {
        $valeur_brute = (float)$immo['composants_valeur_brute'];
        $amort        = (float)$immo['composants_amort_cumul'];
        $dotex        = (float)$immo['composants_dotation_exercice'];
        $taux         = 0; // taux mixte (chaque composant a son propre taux)
    } else {
        $valeur_brute = (float)$immo['valeur_brute'];
        $amort        = (float)$immo['amort_cumul_total'];
        $dotex        = (float)$immo['dotation_exercice'];
        $taux = ($immo['amortissable'] && $immo['duree_amortissement'])
            ? round(100 / $immo['duree_amortissement'], 2) : 0;
    }

    $vnc = max(0, $valeur_brute - $amort);

    // Solde initial / acquisitions / sorties basés sur valeur_brute effective
    $solde_initial = ($acq < $annee_debut) ? $valeur_brute : 0;
    $acquisitions  = ($acq >= $annee_debut && $acq <= $annee_fin) ? $valeur_brute : 0;
    $sorties       = 0;
    if ($cess && $cess >= $annee_debut && $cess <= $annee_fin) {
        $sorties = (float)($immo['valeur_cession'] ?? $valeur_brute);
    }

    // Durée restante en années (arrondie)
    $duree_restante = ($dotex > 0) ? round($vnc / $dotex, 1) : null;

    $lignes[] = [
        'id'               => $immo['id'],
        'compte'           => $immo['compte_immobilisation'] ?? '—',
        'designation'      => $immo['designation'],
        'reference'        => $immo['reference'],
        'categorie'        => $immo['categorie'],
        'cat_label'        => $categories[$immo['categorie']] ?? $immo['categorie'],
        'statut'           => $immo['statut'],
        'periodicite'      => $immo['periodicite'] ?? 'annuelle',
        'nb_composants'    => $nb_composants,
        'solde_initial'    => $solde_initial,
        'acquisitions'     => $acquisitions,
        'sorties'          => $sorties,
        'valeur_brute'     => $valeur_brute,
        'taux'             => $taux,
        'dotation_exercice'=> $dotex,
        'amort_cumul'      => $amort,
        'vnc'              => $vnc,
        'duree_restante'   => $duree_restante,
    ];
}

// Charger les composants pour la vue détaillée (sous-lignes)
$composants_par_parent = [];
$ids_parents = array_filter(array_column($lignes, 'id'), fn($id) => in_array($id,
    array_column(array_filter($lignes, fn($l) => $l['nb_composants'] > 0), 'id')
));
if (!empty($ids_parents)) {
    $in_placeholders = implode(',', array_fill(0, count($ids_parents), '?'));
    $stmt_comp = $db->prepare("
        SELECT c.*,
            COALESCE(SUM(CASE WHEN d.exercice <= ? THEN d.montant ELSE 0 END), 0) as amort_cumul_total,
            COALESCE(SUM(CASE WHEN d.exercice = ?  THEN d.montant ELSE 0 END), 0) as dotation_exercice
        FROM immobilisations c
        LEFT JOIN dotations_amortissement d ON c.id = d.immobilisation_id
        WHERE c.parent_id IN ($in_placeholders)
        GROUP BY c.id
        ORDER BY c.designation
    ");
    $stmt_comp->execute(array_merge([$exercice, $exercice], $ids_parents));
    foreach ($stmt_comp->fetchAll(PDO::FETCH_ASSOC) as $comp) {
        $comp_amort = (float)$comp['amort_cumul_total'];
        $comp_brute = (float)$comp['valeur_brute'];
        $comp_taux  = ($comp['amortissable'] && $comp['duree_amortissement'])
            ? round(100 / $comp['duree_amortissement'], 2) : 0;
        $composants_par_parent[$comp['parent_id']][] = [
            'id'                => $comp['id'],
            'compte'            => $comp['compte_immobilisation'] ?? '—',
            'designation'       => $comp['designation'],
            'valeur_brute'      => $comp_brute,
            'taux'              => $comp_taux,
            'dotation_exercice' => (float)$comp['dotation_exercice'],
            'amort_cumul'       => $comp_amort,
            'vnc'               => max(0, $comp_brute - $comp_amort),
            'statut'            => $comp['statut'],
        ];
    }
}

// Totaux généraux
$total_brut   = array_sum(array_column($lignes, 'valeur_brute'));
$total_amort  = array_sum(array_column($lignes, 'amort_cumul'));
$total_vnc    = $total_brut - $total_amort;
$total_dotex  = array_sum(array_column($lignes, 'dotation_exercice'));
$total_acq    = array_sum(array_column($lignes, 'acquisitions'));
$total_sort   = array_sum(array_column($lignes, 'sorties'));
$nb_actifs    = count(array_filter($lignes, fn($l) => $l['statut'] === 'en_service'));
$taux_global  = $total_brut > 0 ? round($total_amort / $total_brut * 100, 1) : 0;

// Grouper par catégorie
$par_categorie = [];
foreach ($lignes as $l) {
    $cat = $l['categorie'];
    if (!isset($par_categorie[$cat])) {
        $par_categorie[$cat] = [
            'cat_label'        => $l['cat_label'],
            'solde_initial'    => 0, 'acquisitions' => 0, 'sorties' => 0,
            'valeur_brute'     => 0, 'dotation_exercice' => 0,
            'amort_cumul'      => 0, 'vnc' => 0, 'count' => 0,
        ];
    }
    $par_categorie[$cat]['solde_initial']     += $l['solde_initial'];
    $par_categorie[$cat]['acquisitions']      += $l['acquisitions'];
    $par_categorie[$cat]['sorties']           += $l['sorties'];
    $par_categorie[$cat]['valeur_brute']      += $l['valeur_brute'];
    $par_categorie[$cat]['dotation_exercice'] += $l['dotation_exercice'];
    $par_categorie[$cat]['amort_cumul']       += $l['amort_cumul'];
    $par_categorie[$cat]['vnc']               += $l['vnc'];
    $par_categorie[$cat]['count']++;
}

function fmt($n) { return number_format($n, 0, ',', ' '); }
function vnc_class($vnc, $brut) {
    if ($brut <= 0) return 'text-slate-400';
    $pct = $vnc / $brut * 100;
    if ($pct > 50) return 'text-emerald-400';
    if ($pct > 20) return 'text-amber-400';
    return 'text-red-400';
}

// ── Export Excel ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('État des immobilisations');

    // Récupérer la société
    $stmt_soc = $db->prepare("SELECT raison_sociale FROM societes WHERE id=?");
    $stmt_soc->execute([$societe_id]);
    $societe_nom = $stmt_soc->fetchColumn() ?: 'Société';
    $stmt_soc->closeCursor();

    // ── En-tête document ──
    $sheet->mergeCells('A1:K1');
    $sheet->setCellValue('A1', strtoupper($societe_nom));
    $sheet->mergeCells('A2:K2');
    $sheet->setCellValue('A2', 'ÉTAT DES IMMOBILISATIONS — EXERCICE ' . $exercice);
    $sheet->mergeCells('A3:K3');
    $sheet->setCellValue('A3', 'Édité le ' . date('d/m/Y à H:i'));

    $styleTitle = [
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    $sheet->getStyle('A1:K3')->applyFromArray($styleTitle);
    $sheet->getStyle('A2')->getFont()->setSize(14);
    $sheet->getStyle('A3')->getFont()->setSize(9)->setBold(false);
    $sheet->getStyle('A3')->getFont()->getColor()->setRGB('94A3B8');

    // ── En-têtes colonnes ── (ligne 5)
    $headers = [
        'A' => 'Compte',
        'B' => 'Désignation',
        'C' => 'Catégorie',
        'D' => 'Solde initial',
        'E' => 'Acquisitions',
        'F' => 'Sorties',
        'G' => 'Valeur brute',
        'H' => 'Taux',
        'I' => 'Dotation ' . $exercice,
        'J' => '∑ Amortissements',
        'K' => 'Valeur nette comptable',
    ];
    foreach ($headers as $col => $label) {
        $sheet->setCellValue($col . '5', $label);
    }
    $styleHeader = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D97706']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B45309']]],
    ];
    $sheet->getStyle('A5:K5')->applyFromArray($styleHeader);
    $sheet->getRowDimension(5)->setRowHeight(28);

    // ── Données ──
    $row = 6;
    $cat_courante_xls = null;
    $num_cols = ['D','E','F','G','I','J','K'];

    foreach ($lignes as $l) {
        // En-tête catégorie
        if ($l['categorie'] !== $cat_courante_xls) {
            $cat_courante_xls = $l['categorie'];
            $sheet->mergeCells('A' . $row . ':K' . $row);
            $sheet->setCellValue('A' . $row, strtoupper($l['cat_label']));
            $sheet->getStyle('A' . $row)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'D97706'], 'size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(16);
            $row++;
        }

        $sheet->setCellValue('A' . $row, $l['compte']);
        $designation_xls = $l['designation'];
        if ($l['nb_composants'] > 0) {
            $designation_xls .= ' [' . $l['nb_composants'] . ' composant' . ($l['nb_composants'] > 1 ? 's' : '') . ']';
        }
        $sheet->setCellValue('B' . $row, $designation_xls);
        $sheet->setCellValue('C' . $row, $l['cat_label']);
        $sheet->setCellValue('D' . $row, $l['solde_initial'] ?: 0);
        $sheet->setCellValue('E' . $row, $l['acquisitions'] ?: 0);
        $sheet->setCellValue('F' . $row, $l['sorties'] ?: 0);
        $sheet->setCellValue('G' . $row, $l['valeur_brute']);
        $sheet->setCellValue('H' . $row, $l['taux'] > 0 ? ($l['taux'] / 100) : 0);
        $sheet->setCellValue('I' . $row, $l['dotation_exercice'] ?: 0);
        $sheet->setCellValue('J' . $row, $l['amort_cumul']);
        $sheet->setCellValue('K' . $row, $l['vnc']);

        // Format nombre
        $fmtNum = '#,##0';
        foreach ($num_cols as $c) {
            $sheet->getStyle($c . $row)->getNumberFormat()->setFormatCode($fmtNum);
        }
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('0.00"%"');

        // Couleur alternée
        $bgColor = ($row % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
        $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
            'font'      => ['size' => 9],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('D' . $row . ':K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;

        // Sous-lignes composants dans l'export
        if ($l['nb_composants'] > 0 && !empty($composants_par_parent[$l['id']])) {
            foreach ($composants_par_parent[$l['id']] as $comp) {
                $sheet->setCellValue('A' . $row, $comp['compte']);
                $sheet->setCellValue('B' . $row, '  ↳ ' . $comp['designation']);
                $sheet->setCellValue('C' . $row, '');
                $sheet->setCellValue('D' . $row, '');
                $sheet->setCellValue('E' . $row, '');
                $sheet->setCellValue('F' . $row, '');
                $sheet->setCellValue('G' . $row, $comp['valeur_brute']);
                $sheet->setCellValue('H' . $row, $comp['taux'] > 0 ? ($comp['taux'] / 100) : 0);
                $sheet->setCellValue('I' . $row, $comp['dotation_exercice'] ?: 0);
                $sheet->setCellValue('J' . $row, $comp['amort_cumul']);
                $sheet->setCellValue('K' . $row, $comp['vnc']);
                foreach ($num_cols as $c) {
                    $sheet->getStyle($c . $row)->getNumberFormat()->setFormatCode($fmtNum);
                }
                $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('0.00"%"');
                $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F0FF']],
                    'font'      => ['size' => 8, 'italic' => true, 'color' => ['rgb' => '7C3AED']],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDD6FE']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle('G' . $row . ':K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $row++;
            }
        }
    }

    // ── Total général ──
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('A' . $row, 'TOTAL GÉNÉRAL');
    $sheet->setCellValue('D' . $row, array_sum(array_column($lignes, 'solde_initial')));
    $sheet->setCellValue('E' . $row, $total_acq);
    $sheet->setCellValue('F' . $row, $total_sort);
    $sheet->setCellValue('G' . $row, $total_brut);
    $sheet->setCellValue('H' . $row, '');
    $sheet->setCellValue('I' . $row, $total_dotex);
    $sheet->setCellValue('J' . $row, $total_amort);
    $sheet->setCellValue('K' . $row, $total_vnc);
    $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
        'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '064E3B']],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '047857']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    foreach ($num_cols as $c) {
        $sheet->getStyle($c . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle($c . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    $sheet->getRowDimension($row)->setRowHeight(20);

    // ── Largeurs colonnes ──
    $sheet->getColumnDimension('A')->setWidth(14);
    $sheet->getColumnDimension('B')->setWidth(32);
    $sheet->getColumnDimension('C')->setWidth(22);
    foreach (['D','E','F','G','I','J','K'] as $c) $sheet->getColumnDimension($c)->setWidth(16);
    $sheet->getColumnDimension('H')->setWidth(8);

    // ── Figer la ligne d'en-têtes ──
    $sheet->freezePane('A6');

    // ── Envoi du fichier ──
    $filename = 'etat_immobilisations_' . $exercice . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
// ── Fin export ──────────────────────────────────────────────────────────────

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>État des immobilisations - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        :root { --font-size-xs: 10px; --font-size-sm: 11px; --font-size-base: 12px; }
        body { font-size: var(--font-size-base); }
        .tbl td, .tbl th { font-size: var(--font-size-sm); }
        .cat-row td { background: rgba(245,158,11,0.06); }
        .total-row td { background: rgba(16,185,129,0.06); }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
    <?php include '../../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <!-- Header -->
        <header class="bg-slate-800/30 border-b border-slate-700/50 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-amber-400 to-orange-500 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-amber-400"></i>
                        État des Immobilisations
                    </h1>
                    <p class="text-slate-400 text-sm mt-1">Synthèse du patrimoine immobilisé — Exercice <?= $exercice ?></p>
                </div>
                <div class="flex gap-2">
                    <a href="liste.php" class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-xs transition flex items-center gap-2">
                        <i class="fas fa-list"></i> Registre
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs transition flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                </div>
            </div>
        </header>

        <div class="p-6 space-y-6">

            <!-- Cartes statistiques -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 hover:border-amber-500/40 transition-all">
                    <p class="text-xs text-slate-400 mb-1">Valeur brute totale</p>
                    <p class="text-lg font-bold text-blue-400 font-mono"><?= fmt($total_brut) ?></p>
                    <p class="text-[10px] text-slate-500 mt-1">FCFA</p>
                </div>
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 hover:border-red-500/40 transition-all">
                    <p class="text-xs text-slate-400 mb-1">Amort. cumulés</p>
                    <p class="text-lg font-bold text-red-400 font-mono"><?= fmt($total_amort) ?></p>
                    <p class="text-[10px] text-slate-500 mt-1">FCFA</p>
                </div>
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 hover:border-emerald-500/40 transition-all">
                    <p class="text-xs text-slate-400 mb-1">Valeur nette comptable</p>
                    <p class="text-lg font-bold text-emerald-400 font-mono"><?= fmt($total_vnc) ?></p>
                    <p class="text-[10px] text-slate-500 mt-1">FCFA</p>
                </div>
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 hover:border-purple-500/40 transition-all">
                    <p class="text-xs text-slate-400 mb-1">Taux amorti global</p>
                    <p class="text-lg font-bold text-purple-400 font-mono"><?= $taux_global ?>%</p>
                    <div class="mt-2 h-1 bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-1 rounded-full <?= $taux_global > 75 ? 'bg-red-400' : ($taux_global > 50 ? 'bg-amber-400' : 'bg-emerald-400') ?>" style="width: <?= min(100, $taux_global) ?>%"></div>
                    </div>
                </div>
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 hover:border-cyan-500/40 transition-all">
                    <p class="text-xs text-slate-400 mb-1">Acquisitions <?= $exercice ?></p>
                    <p class="text-lg font-bold text-cyan-400 font-mono"><?= fmt($total_acq) ?></p>
                    <p class="text-[10px] text-slate-500 mt-1">FCFA</p>
                </div>
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 hover:border-slate-500/40 transition-all">
                    <p class="text-xs text-slate-400 mb-1">Dotation <?= $exercice ?></p>
                    <p class="text-lg font-bold text-amber-400 font-mono"><?= fmt($total_dotex) ?></p>
                    <p class="text-[10px] text-slate-500 mt-1">FCFA</p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                <form method="GET" class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Exercice</label>
                        <select name="exercice" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-xs text-white focus:ring-2 focus:ring-amber-500">
                            <?php foreach ($annees_dispo as $y): ?>
                            <option value="<?= $y ?>" <?= $y == $exercice ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Catégorie</label>
                        <select name="categorie" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-xs text-white focus:ring-2 focus:ring-amber-500">
                            <option value="toutes" <?= $filtre_cat === 'toutes' ? 'selected' : '' ?>>Toutes catégories</option>
                            <?php foreach ($categories as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $filtre_cat === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Statut</label>
                        <select name="statut" class="px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-xs text-white focus:ring-2 focus:ring-amber-500">
                            <option value="tous"       <?= $filtre_statut === 'tous'       ? 'selected' : '' ?>>Tous</option>
                            <option value="en_service" <?= $filtre_statut === 'en_service' ? 'selected' : '' ?>>En service</option>
                            <option value="cede"       <?= $filtre_statut === 'cede'       ? 'selected' : '' ?>>Cédés</option>
                            <option value="rebute"     <?= $filtre_statut === 'rebute'     ? 'selected' : '' ?>>Mis au rebut</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Vue</label>
                        <div class="flex rounded-lg overflow-hidden border border-slate-600">
                            <button type="submit" name="vue" value="detail"
                                class="px-3 py-2 text-xs transition <?= $vue === 'detail' ? 'bg-amber-500 text-white' : 'bg-slate-900 text-slate-400 hover:bg-slate-700' ?>">
                                <i class="fas fa-list-ul mr-1"></i>Détaillée
                            </button>
                            <button type="submit" name="vue" value="condense"
                                class="px-3 py-2 text-xs transition <?= $vue === 'condense' ? 'bg-amber-500 text-white' : 'bg-slate-900 text-slate-400 hover:bg-slate-700' ?>">
                                <i class="fas fa-layer-group mr-1"></i>Condensée
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs transition flex items-center gap-2">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                </form>
            </div>

            <!-- Tableau -->
            <?php if (empty($lignes)): ?>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-12 text-center">
                <i class="fas fa-inbox text-3xl text-slate-600 mb-3"></i>
                <p class="text-slate-400">Aucune immobilisation trouvée pour ces critères.</p>
            </div>
            <?php else: ?>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-700 flex items-center justify-between">
                    <h2 class="font-semibold text-white text-sm flex items-center gap-2">
                        <i class="fas fa-table text-amber-400"></i>
                        <?= $vue === 'condense' ? 'Vue condensée par catégorie' : 'Vue détaillée' ?>
                        <span class="text-slate-400 text-xs font-normal">(<?= count($lignes) ?> immobilisation<?= count($lignes) > 1 ? 's' : '' ?>)</span>
                    </h2>
                    <span class="text-xs text-slate-400">Exercice <?= $exercice ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full tbl">
                        <thead class="bg-slate-900/60 sticky top-0">
                            <tr class="border-b border-slate-700 text-slate-400 text-[10px] uppercase tracking-wider">
                                <?php if ($vue === 'detail'): ?>
                                <th class="px-3 py-2.5 text-left">Compte</th>
                                <th class="px-3 py-2.5 text-left">Désignation</th>
                                <?php else: ?>
                                <th class="px-3 py-2.5 text-left">Catégorie</th>
                                <th class="px-3 py-2.5 text-center">Nb</th>
                                <?php endif; ?>
                                <th class="px-3 py-2.5 text-right">Solde initial</th>
                                <th class="px-3 py-2.5 text-right">Acquisitions</th>
                                <th class="px-3 py-2.5 text-right">Sorties</th>
                                <th class="px-3 py-2.5 text-right">Valeur brute</th>
                                <?php if ($vue === 'detail'): ?>
                                <th class="px-3 py-2.5 text-center">Taux</th>
                                <?php endif; ?>
                                <th class="px-3 py-2.5 text-right">Dotation <?= $exercice ?></th>
                                <th class="px-3 py-2.5 text-right">∑ Amort.</th>
                                <th class="px-3 py-2.5 text-right">VNC</th>
                                <?php if ($vue === 'detail'): ?>
                                <th class="px-3 py-2.5 text-center">% Amorti</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700/30">

                        <?php if ($vue === 'condense'): ?>
                            <?php foreach ($par_categorie as $cat => $g): ?>
                            <tr class="hover:bg-slate-700/20 transition-colors">
                                <td class="px-3 py-2.5 font-semibold text-amber-300">
                                    <i class="fas fa-folder text-amber-500/60 mr-1.5"></i>
                                    <?= $g['cat_label'] ?>
                                </td>
                                <td class="px-3 py-2.5 text-center text-slate-400"><?= $g['count'] ?></td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-300"><?= fmt($g['solde_initial']) ?></td>
                                <td class="px-3 py-2.5 text-right font-mono text-cyan-400"><?= $g['acquisitions'] > 0 ? fmt($g['acquisitions']) : '—' ?></td>
                                <td class="px-3 py-2.5 text-right font-mono text-red-400"><?= $g['sorties'] > 0 ? fmt($g['sorties']) : '—' ?></td>
                                <td class="px-3 py-2.5 text-right font-mono font-semibold text-blue-400"><?= fmt($g['valeur_brute']) ?></td>
                                <td class="px-3 py-2.5 text-right font-mono text-amber-400"><?= $g['dotation_exercice'] > 0 ? fmt($g['dotation_exercice']) : '—' ?></td>
                                <td class="px-3 py-2.5 text-right font-mono text-red-400"><?= fmt($g['amort_cumul']) ?></td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold <?= vnc_class($g['vnc'], $g['valeur_brute']) ?>"><?= fmt($g['vnc']) ?></td>
                            </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <?php
                            $cat_courante = null;
                            $cat_totaux   = [];
                            foreach ($lignes as $i => $l) {
                                // Ligne en-tête de catégorie
                                if ($l['categorie'] !== $cat_courante) {
                                    // Sous-total de la catégorie précédente
                                    if ($cat_courante !== null && isset($cat_totaux[$cat_courante]) && $cat_totaux[$cat_courante]['count'] > 1) {
                                        $ct = $cat_totaux[$cat_courante];
                                        echo '<tr class="cat-row border-t border-amber-500/20">';
                                        echo '<td class="px-3 py-1.5 text-amber-400/70 text-[10px] italic" colspan="2">Sous-total ' . htmlspecialchars($ct['cat_label']) . ' (' . $ct['count'] . ')</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono text-slate-400 text-[10px]">' . fmt($ct['solde_initial']) . '</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono text-cyan-400/70 text-[10px]">' . ($ct['acquisitions'] > 0 ? fmt($ct['acquisitions']) : '—') . '</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono text-red-400/70 text-[10px]">' . ($ct['sorties'] > 0 ? fmt($ct['sorties']) : '—') . '</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono font-semibold text-blue-400/70 text-[10px]">' . fmt($ct['valeur_brute']) . '</td>';
                                        echo '<td class="px-3 py-1.5 text-center text-slate-600 text-[10px]">—</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono text-amber-400/70 text-[10px]">' . ($ct['dotation_exercice'] > 0 ? fmt($ct['dotation_exercice']) : '—') . '</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono text-red-400/70 text-[10px]">' . fmt($ct['amort_cumul']) . '</td>';
                                        echo '<td class="px-3 py-1.5 text-right font-mono font-bold text-[10px] ' . vnc_class($ct['vnc'], $ct['valeur_brute']) . '">' . fmt($ct['vnc']) . '</td>';
                                        echo '<td></td>';
                                        echo '</tr>';
                                    }
                                    $cat_courante = $l['categorie'];
                                    // En-tête catégorie
                                    echo '<tr class="bg-slate-900/40 border-t-2 border-amber-500/20">';
                                    echo '<td colspan="11" class="px-3 py-1.5 text-[10px] font-bold text-amber-400 uppercase tracking-wider">';
                                    echo '<i class="fas fa-tag mr-1.5 text-amber-500/60"></i>' . htmlspecialchars($l['cat_label']);
                                    echo '</td></tr>';
                                }

                                // Accumuler sous-totaux catégorie
                                if (!isset($cat_totaux[$cat_courante])) {
                                    $cat_totaux[$cat_courante] = ['cat_label' => $l['cat_label'], 'count' => 0,
                                        'solde_initial' => 0, 'acquisitions' => 0, 'sorties' => 0,
                                        'valeur_brute' => 0, 'dotation_exercice' => 0, 'amort_cumul' => 0, 'vnc' => 0];
                                }
                                $cat_totaux[$cat_courante]['count']++;
                                foreach (['solde_initial','acquisitions','sorties','valeur_brute','dotation_exercice','amort_cumul','vnc'] as $f) {
                                    $cat_totaux[$cat_courante][$f] += $l[$f];
                                }

                                // Statuts
                                $statut_badge = [
                                    'en_service' => '<span class="px-1.5 py-0.5 rounded text-[9px] bg-emerald-500/15 text-emerald-400">En service</span>',
                                    'cede'       => '<span class="px-1.5 py-0.5 rounded text-[9px] bg-amber-500/15 text-amber-400">Cédé</span>',
                                    'rebute'     => '<span class="px-1.5 py-0.5 rounded text-[9px] bg-red-500/15 text-red-400">Rebut</span>',
                                ][$l['statut']] ?? '';

                                $pct_amorti = $l['valeur_brute'] > 0 ? round($l['amort_cumul'] / $l['valeur_brute'] * 100) : 0;
                                $bar_color  = $pct_amorti > 75 ? 'bg-red-400' : ($pct_amorti > 50 ? 'bg-amber-400' : 'bg-emerald-400');
                            ?>
                                <tr class="hover:bg-slate-700/20 transition-colors">
                                    <td class="px-3 py-2">
                                        <span class="font-mono text-slate-400"><?= htmlspecialchars($l['compte']) ?></span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <a href="voir.php?id=<?= $l['id'] ?>" class="text-white hover:text-amber-400 transition font-medium">
                                            <?= htmlspecialchars($l['designation']) ?>
                                        </a>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <?= $statut_badge ?>
                                            <?php if ($l['periodicite'] === 'mensuelle'): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] bg-blue-500/15 text-blue-400">Mensuel</span>
                                            <?php endif; ?>
                                            <?php if ($l['nb_composants'] > 0): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] bg-purple-500/15 text-purple-400"><?= $l['nb_composants'] ?> composant<?= $l['nb_composants'] > 1 ? 's' : '' ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-slate-400"><?= $l['solde_initial'] > 0 ? fmt($l['solde_initial']) : '—' ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-cyan-400"><?= $l['acquisitions'] > 0 ? fmt($l['acquisitions']) : '—' ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-red-400"><?= $l['sorties'] > 0 ? fmt($l['sorties']) : '—' ?></td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold text-blue-400"><?= fmt($l['valeur_brute']) ?></td>
                                    <td class="px-3 py-2 text-center text-slate-400"><?= $l['taux'] > 0 ? $l['taux'] . '%' : '—' ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-amber-400"><?= $l['dotation_exercice'] > 0 ? fmt($l['dotation_exercice']) : '—' ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-red-400"><?= fmt($l['amort_cumul']) ?></td>
                                    <td class="px-3 py-2 text-right font-mono font-bold <?= vnc_class($l['vnc'], $l['valeur_brute']) ?>">
                                        <?= fmt($l['vnc']) ?>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-1.5 min-w-[60px]">
                                            <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                                <div class="h-1.5 rounded-full <?= $bar_color ?>" style="width: <?= $pct_amorti ?>%"></div>
                                            </div>
                                            <span class="text-[9px] text-slate-400 w-7 text-right"><?= $pct_amorti ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php // Sous-lignes composants (vue détaillée uniquement)
                            if ($l['nb_composants'] > 0 && !empty($composants_par_parent[$l['id']])): ?>
                            <?php foreach ($composants_par_parent[$l['id']] as $comp):
                                $comp_pct = $comp['valeur_brute'] > 0 ? round($comp['amort_cumul'] / $comp['valeur_brute'] * 100) : 0;
                                $comp_bar = $comp_pct > 75 ? 'bg-red-400' : ($comp_pct > 50 ? 'bg-amber-400' : 'bg-emerald-400');
                            ?>
                                <tr class="bg-purple-900/10 border-t border-purple-500/10 hover:bg-purple-900/20 transition-colors">
                                    <td class="px-3 py-1.5 pl-6">
                                        <span class="font-mono text-purple-400/70 text-[10px]"><?= htmlspecialchars($comp['compte']) ?></span>
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-purple-400/60 text-xs">↳</span>
                                            <a href="voir.php?id=<?= $comp['id'] ?>" class="text-purple-300 hover:text-purple-200 transition text-[11px]">
                                                <?= htmlspecialchars($comp['designation']) ?>
                                            </a>
                                            <span class="px-1 py-0 rounded text-[8px] bg-purple-500/20 text-purple-400">composant</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-slate-600 text-[10px]">—</td>
                                    <td class="px-3 py-1.5 text-right text-slate-600 text-[10px]">—</td>
                                    <td class="px-3 py-1.5 text-right text-slate-600 text-[10px]">—</td>
                                    <td class="px-3 py-1.5 text-right font-mono text-blue-400/80 text-[10px]"><?= fmt($comp['valeur_brute']) ?></td>
                                    <td class="px-3 py-1.5 text-center text-purple-400/70 text-[10px]"><?= $comp['taux'] > 0 ? $comp['taux'] . '%' : '—' ?></td>
                                    <td class="px-3 py-1.5 text-right font-mono text-amber-400/80 text-[10px]"><?= $comp['dotation_exercice'] > 0 ? fmt($comp['dotation_exercice']) : '—' ?></td>
                                    <td class="px-3 py-1.5 text-right font-mono text-red-400/80 text-[10px]"><?= fmt($comp['amort_cumul']) ?></td>
                                    <td class="px-3 py-1.5 text-right font-mono font-semibold text-[10px] <?= vnc_class($comp['vnc'], $comp['valeur_brute']) ?>"><?= fmt($comp['vnc']) ?></td>
                                    <td class="px-3 py-1.5">
                                        <div class="flex items-center gap-1 min-w-[60px]">
                                            <div class="flex-1 h-1 bg-slate-700 rounded-full overflow-hidden">
                                                <div class="h-1 rounded-full <?= $comp_bar ?>" style="width: <?= $comp_pct ?>%"></div>
                                            </div>
                                            <span class="text-[8px] text-slate-500 w-7 text-right"><?= $comp_pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <?php } ?>

                            <?php // Sous-total dernière catégorie
                            if ($cat_courante !== null && isset($cat_totaux[$cat_courante]) && $cat_totaux[$cat_courante]['count'] > 1):
                                $ct = $cat_totaux[$cat_courante];
                            ?>
                            <tr class="cat-row border-t border-amber-500/20">
                                <td class="px-3 py-1.5 text-amber-400/70 text-[10px] italic" colspan="2">Sous-total <?= htmlspecialchars($ct['cat_label']) ?> (<?= $ct['count'] ?>)</td>
                                <td class="px-3 py-1.5 text-right font-mono text-slate-400 text-[10px]"><?= fmt($ct['solde_initial']) ?></td>
                                <td class="px-3 py-1.5 text-right font-mono text-cyan-400/70 text-[10px]"><?= $ct['acquisitions'] > 0 ? fmt($ct['acquisitions']) : '—' ?></td>
                                <td class="px-3 py-1.5 text-right font-mono text-red-400/70 text-[10px]"><?= $ct['sorties'] > 0 ? fmt($ct['sorties']) : '—' ?></td>
                                <td class="px-3 py-1.5 text-right font-mono font-semibold text-blue-400/70 text-[10px]"><?= fmt($ct['valeur_brute']) ?></td>
                                <td class="px-3 py-1.5 text-center text-slate-600 text-[10px]">—</td>
                                <td class="px-3 py-1.5 text-right font-mono text-amber-400/70 text-[10px]"><?= $ct['dotation_exercice'] > 0 ? fmt($ct['dotation_exercice']) : '—' ?></td>
                                <td class="px-3 py-1.5 text-right font-mono text-red-400/70 text-[10px]"><?= fmt($ct['amort_cumul']) ?></td>
                                <td class="px-3 py-1.5 text-right font-mono font-bold text-[10px] <?= vnc_class($ct['vnc'], $ct['valeur_brute']) ?>"><?= fmt($ct['vnc']) ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>

                        </tbody>
                        <!-- Total général -->
                        <tfoot>
                            <tr class="total-row border-t-2 border-emerald-500/30 font-bold text-xs">
                                <td class="px-3 py-3 text-emerald-400 uppercase tracking-wide" colspan="<?= $vue === 'condense' ? 2 : 2 ?>">
                                    <i class="fas fa-sigma mr-1.5"></i>TOTAL GÉNÉRAL
                                </td>
                                <td class="px-3 py-3 text-right font-mono text-slate-300"><?= fmt(array_sum(array_column($lignes, 'solde_initial'))) ?></td>
                                <td class="px-3 py-3 text-right font-mono text-cyan-400"><?= $total_acq > 0 ? fmt($total_acq) : '—' ?></td>
                                <td class="px-3 py-3 text-right font-mono text-red-400"><?= $total_sort > 0 ? fmt($total_sort) : '—' ?></td>
                                <td class="px-3 py-3 text-right font-mono text-blue-400"><?= fmt($total_brut) ?></td>
                                <?php if ($vue === 'detail'): ?>
                                <td class="px-3 py-3 text-center text-slate-500">—</td>
                                <?php endif; ?>
                                <td class="px-3 py-3 text-right font-mono text-amber-400"><?= $total_dotex > 0 ? fmt($total_dotex) : '—' ?></td>
                                <td class="px-3 py-3 text-right font-mono text-red-400"><?= fmt($total_amort) ?></td>
                                <td class="px-3 py-3 text-right font-mono text-emerald-400"><?= fmt($total_vnc) ?></td>
                                <?php if ($vue === 'detail'): ?>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                            <div class="h-1.5 rounded-full <?= $taux_global > 75 ? 'bg-red-400' : ($taux_global > 50 ? 'bg-amber-400' : 'bg-emerald-400') ?>" style="width: <?= min(100, $taux_global) ?>%"></div>
                                        </div>
                                        <span class="text-[9px] text-slate-300 w-7 text-right"><?= $taux_global ?>%</span>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>
</body>
</html>
