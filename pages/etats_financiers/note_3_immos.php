<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function nfN($n) {
    if ($n === null || abs((float)$n) < 0.5) return '';
    return number_format((float)$n, 0, ',', ' ');
}
function nfS($n) { // signed (shows negative)
    if ($n === null || abs((float)$n) < 0.5) return '';
    return number_format((float)$n, 0, ',', ' ');
}

$date_debut_n  = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin_n    = $_GET['date_fin']   ?? date('Y-12-31');
$date_debut_n1 = date('Y-m-d', strtotime($date_debut_n . ' -1 year'));
$date_fin_n1   = date('Y-m-d', strtotime($date_fin_n   . ' -1 year'));
$annee_n  = date('Y', strtotime($date_fin_n));
$annee_n1 = date('Y', strtotime($date_fin_n1));
$activeTab = $_GET['tab'] ?? '3a';

// ── Données depuis le module immobilisations ──────────────────────────────────
$stmt_i = $db->prepare("
    SELECT id, compte_immobilisation, designation, valeur_brute,
           date_acquisition, date_cession, valeur_cession
    FROM immobilisations
    WHERE societe_id = ?
    ORDER BY compte_immobilisation
");
$stmt_i->execute([$societe_id]);
$immos = $stmt_i->fetchAll(PDO::FETCH_ASSOC);

$stmt_d = $db->prepare("
    SELECT d.immobilisation_id AS immo_id,
           i.compte_immobilisation,
           d.montant,
           d.date_dotation
    FROM dotations_amortissement d
    INNER JOIN immobilisations i ON d.immobilisation_id = i.id
    WHERE d.societe_id = ?
    ORDER BY d.date_dotation
");
$stmt_d->execute([$societe_id]);
$dotations = $stmt_d->fetchAll(PDO::FETCH_ASSOC);

// =====================================================================
// Helper functions
// =====================================================================
if (!function_exists('matchPx')) {
    function matchPx(string $s, array $pfx): bool {
        foreach ($pfx as $p) { if (str_starts_with($s, $p)) return true; }
        return false;
    }
}

// Brut ouverture : actifs acquis avant $d0, non cédés avant $d0
function brutOuv(array $im, array $pfx, array $excl, string $d0): float {
    $t = 0.0;
    foreach ($im as $i) {
        $c = (string)$i['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if ($i['date_acquisition'] >= $d0) continue;
        if (!empty($i['date_cession']) && $i['date_cession'] < $d0) continue;
        $t += (float)$i['valeur_brute'];
    }
    return $t;
}
// Acquisitions durant [d0,d1]
function brutAcq(array $im, array $pfx, array $excl, string $d0, string $d1): float {
    $t = 0.0;
    foreach ($im as $i) {
        $c = (string)$i['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if ($i['date_acquisition'] < $d0 || $i['date_acquisition'] > $d1) continue;
        $t += (float)$i['valeur_brute'];
    }
    return $t;
}
// Cessions (valeur brute) durant [d0,d1]
function brutCes(array $im, array $pfx, array $excl, string $d0, string $d1): float {
    $t = 0.0;
    foreach ($im as $i) {
        $c = (string)$i['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if (empty($i['date_cession'])) continue;
        if ($i['date_cession'] < $d0 || $i['date_cession'] > $d1) continue;
        $t += (float)$i['valeur_brute'];
    }
    return $t;
}
// Brut clôture : acquis <= d1, non cédés <= d1
function brutClo(array $im, array $pfx, array $excl, string $d1): float {
    $t = 0.0;
    foreach ($im as $i) {
        $c = (string)$i['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if ($i['date_acquisition'] > $d1) continue;
        if (!empty($i['date_cession']) && $i['date_cession'] <= $d1) continue;
        $t += (float)$i['valeur_brute'];
    }
    return $t;
}
// Amort ouverture : dotations avant $d0
function amortOuv(array $dots, array $pfx, array $excl, string $d0): float {
    $t = 0.0;
    foreach ($dots as $d) {
        $c = (string)$d['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if ($d['date_dotation'] >= $d0) continue;
        $t += (float)$d['montant'];
    }
    return $t;
}
// Dotations de l'exercice
function amortDot(array $dots, array $pfx, array $excl, string $d0, string $d1): float {
    $t = 0.0;
    foreach ($dots as $d) {
        $c = (string)$d['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if ($d['date_dotation'] < $d0 || $d['date_dotation'] > $d1) continue;
        $t += (float)$d['montant'];
    }
    return $t;
}
// Amort clôture : toutes dotations <= d1
function amortClo(array $dots, array $pfx, array $excl, string $d1): float {
    $t = 0.0;
    foreach ($dots as $d) {
        $c = (string)$d['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if ($d['date_dotation'] > $d1) continue;
        $t += (float)$d['montant'];
    }
    return $t;
}
// Amort des actifs cédés durant [d0,d1] — colonne "Réductions" Note 3C
function amortCessions(array $im, array $dots, array $pfx, array $excl, string $d0, string $d1): float {
    $ids = [];
    foreach ($im as $i) {
        $c = (string)$i['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if (empty($i['date_cession'])) continue;
        if ($i['date_cession'] < $d0 || $i['date_cession'] > $d1) continue;
        $ids[(int)$i['id']] = true;
    }
    if (empty($ids)) return 0.0;
    $t = 0.0;
    foreach ($dots as $d) {
        if (!isset($ids[(int)$d['immo_id']])) continue;
        $t += (float)$d['montant'];
    }
    return $t;
}
// Prix de cession durant [d0,d1]
function cessionsPrix(array $im, array $pfx, array $excl, string $d0, string $d1): float {
    $t = 0.0;
    foreach ($im as $i) {
        $c = (string)$i['compte_immobilisation'];
        if (!matchPx($c,$pfx) || (!empty($excl) && matchPx($c,$excl))) continue;
        if (empty($i['date_cession'])) continue;
        if ($i['date_cession'] < $d0 || $i['date_cession'] > $d1) continue;
        $t += (float)($i['valeur_cession'] ?? 0);
    }
    return $t;
}

// =====================================================================
// RUBRIQUES — Notes 3A / 3C / 3C BIS
// pfx : préfixes sur compte_immobilisation (ex. '244' capte 24423000)
// =====================================================================
$rubriques = [
    ['type'=>'section', 'label'=>'IMMOBILISATIONS INCORPORELLES'],
    ['label'=>'Frais de développement et de prospection',        'pfx'=>['211'],'excl'=>[]],
    ['label'=>'Brevets, licences, marques et droits similaires', 'pfx'=>['212'],'excl'=>[]],
    ['label'=>'Logiciels',                                       'pfx'=>['213'],'excl'=>[]],
    ['label'=>'Fonds commercial et droit au bail',               'pfx'=>['214'],'excl'=>[]],
    ['label'=>'Autres immobilisations incorporelles',            'pfx'=>['215','216','217','218'],'excl'=>[]],
    ['label'=>'Avances et acomptes sur imm. incorporelles',      'pfx'=>['219'],'excl'=>[]],
    ['type'=>'subtotal','label'=>'SOUS-TOTAL INCORPORELLES',     'pfx'=>['21'],'excl'=>[]],

    ['type'=>'section','label'=>'IMMOBILISATIONS CORPORELLES'],
    ['label'=>'Terrains (hors immeubles de placement)',          'pfx'=>['22'],'excl'=>['228']],
    ['label'=>'Immeubles de placement',                          'pfx'=>['228'],'excl'=>[]],
    ['label'=>'Bâtiments et constructions',                      'pfx'=>['231','232','233'],'excl'=>[]],
    ['label'=>'Installations, agencements et aménagements',      'pfx'=>['234','235','236','237'],'excl'=>[]],
    ['label'=>'Matériel et mobilier',                            'pfx'=>['241','242','243','244','246','247','248'],'excl'=>[]],
    ['label'=>'Matériel de transport',                           'pfx'=>['245'],'excl'=>[]],
    ['label'=>'Avances et acomptes sur imm. corporelles',        'pfx'=>['239','249'],'excl'=>[]],
    ['type'=>'subtotal','label'=>'SOUS-TOTAL CORPORELLES',       'pfx'=>['22','23','24'],'excl'=>[]],

    ['type'=>'section','label'=>'IMMOBILISATIONS FINANCIÈRES'],
    ['label'=>'Titres de participation',                         'pfx'=>['261','262','263'],'excl'=>[]],
    ['label'=>'Autres immobilisations financières',              'pfx'=>['264','265','266','267','268','271','272','274','275','276','277','278'],'excl'=>[]],
    ['label'=>'Avances et acomptes sur imm. financières',        'pfx'=>['269','279'],'excl'=>[]],
    ['type'=>'subtotal','label'=>'SOUS-TOTAL FINANCIÈRES',       'pfx'=>['26','27'],'excl'=>[]],

    ['type'=>'grand_total','label'=>'TOTAL GÉNÉRAL',             'pfx'=>['21','22','23','24','25','26','27'],'excl'=>[]],
];

// =====================================================================
// Calcul des lignes
// =====================================================================
$rows = [];
foreach ($rubriques as $rub) {
    if (($rub['type'] ?? '') === 'section') { $rows[] = $rub; continue; }
    $pfx  = $rub['pfx']  ?? [];
    $excl = $rub['excl'] ?? [];

    $b_ouv = brutOuv($immos, $pfx, $excl, $date_debut_n);
    $b_acq = brutAcq($immos, $pfx, $excl, $date_debut_n, $date_fin_n);
    $b_ces = brutCes($immos, $pfx, $excl, $date_debut_n, $date_fin_n);
    $b_clo = brutClo($immos, $pfx, $excl, $date_fin_n);

    $a_ouv = amortOuv($dotations, $pfx, $excl, $date_debut_n);
    $a_dot = amortDot($dotations, $pfx, $excl, $date_debut_n, $date_fin_n);
    $a_red = amortCessions($immos, $dotations, $pfx, $excl, $date_debut_n, $date_fin_n);
    $a_clo = amortClo($dotations, $pfx, $excl, $date_fin_n);

    $d_ouv = 0.0; $d_dot = 0.0; $d_rep = 0.0; $d_clo = 0.0; // dépréciations (module non implémenté)

    $rows[] = array_merge($rub, [
        'b_ouv'=>$b_ouv, 'b_acq'=>$b_acq, 'b_ces'=>$b_ces, 'b_clo'=>$b_clo,
        'a_ouv'=>$a_ouv, 'a_dot'=>$a_dot, 'a_red'=>$a_red, 'a_clo'=>$a_clo,
        'd_ouv'=>$d_ouv, 'd_dot'=>$d_dot, 'd_rep'=>$d_rep, 'd_clo'=>$d_clo,
        'vnc_ouv'=>$b_ouv - $a_ouv - $d_ouv,
        'vnc_clo'=>$b_clo - $a_clo - $d_clo,
    ]);
}

// =====================================================================
// Note 3D — Plus/moins-values de cession
// =====================================================================
$d3D_defs = [
    ['label'=>"Cessions d'immo. incorporelles",  'pfx'=>['21'],           'excl'=>[]],
    ['label'=>"Cessions d'immo. corporelles",    'pfx'=>['22','23','24'], 'excl'=>[]],
    ['label'=>"Cessions d'immo. financières",    'pfx'=>['26','27'],      'excl'=>[]],
    ['label'=>"Total toutes cessions",           'pfx'=>['21','22','23','24','26','27'], 'excl'=>[]],
];

$rows3D = [];
$tot3D  = ['brut'=>0.0,'amort'=>0.0,'vnc'=>0.0,'prix'=>0.0,'pmv'=>0.0];
foreach ($d3D_defs as $def) {
    $pfx  = $def['pfx'];
    $excl = $def['excl'];
    $brut  = brutCes($immos, $pfx, $excl, $date_debut_n, $date_fin_n);
    $amort = amortCessions($immos, $dotations, $pfx, $excl, $date_debut_n, $date_fin_n);
    $vnc   = $brut - $amort;
    $prix  = cessionsPrix($immos, $pfx, $excl, $date_debut_n, $date_fin_n);
    $pmv   = $prix - $vnc;
    $rows3D[] = array_merge($def, compact('brut','amort','vnc','prix','pmv'));
    foreach (['brut','amort','vnc','prix','pmv'] as $k) $tot3D[$k] += $$k;
}

$qs = http_build_query(['date_debut'=>$date_debut_n,'date_fin'=>$date_fin_n]);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes Annexes — Immobilisations <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            aside, .no-print, form { display: none !important; }
            body { background: white !important; color: black !important; }
            .tab-content { display: block !important; }
            .tab-header-row { background: #e5e7eb !important; color: #111827 !important; }
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .note-table th { font-size: 11px; white-space: nowrap; }
        .note-table td { font-size: 12px; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .row-section td { background: #1e3a5f; color: #93c5fd; font-weight: 700; letter-spacing: 0.04em; font-size: 11px; text-transform: uppercase; }
        .row-subtotal td { background: #1e293b; color: #7dd3fc; font-weight: 600; border-top: 1px solid #334155; }
        .row-grand-total td { background: #0f172a; color: #34d399; font-weight: 700; border-top: 2px solid #10b981; }
        .row-data:hover td { background: rgba(255,255,255,0.03); }
        .pmv-pos { color: #34d399; }
        .pmv-neg { color: #f87171; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-teal-400 mb-1">
                    <i class="fas fa-clipboard-list mr-3"></i>Notes Annexes — Immobilisations (Notes 3A, 3B, 3C, 3C BIS, 3D)
                </h1>
                <p class="text-slate-400 text-sm">
                    Exercice du <?= date('d/m/Y', strtotime($date_debut_n)) ?> au <?= date('d/m/Y', strtotime($date_fin_n)) ?>
                    &nbsp;|&nbsp; N-1 : <?= date('d/m/Y', strtotime($date_debut_n1)) ?> au <?= date('d/m/Y', strtotime($date_fin_n1)) ?>
                </p>
            </div>
            <a href="notes_annexes.php?<?= $qs ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2 text-sm no-print">
                <i class="fas fa-arrow-left"></i> Toutes les notes
            </a>
        </div>
    </div>

    <!-- Filtre période -->
    <form method="GET" class="no-print mb-6 bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl p-5">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1"><i class="fas fa-calendar-alt mr-1"></i>Début N</label>
                <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut_n) ?>"
                       class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 text-slate-100 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1"><i class="fas fa-calendar-alt mr-1"></i>Fin N</label>
                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin_n) ?>"
                       class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 text-slate-100 text-sm">
            </div>
            <input type="hidden" name="tab" id="formTab" value="<?= htmlspecialchars($activeTab) ?>">
            <button type="submit" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2 text-sm">
                <i class="fas fa-search"></i>Afficher
            </button>
            <a href="note_3_immos.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2 text-sm">
                <i class="fas fa-redo"></i>Réinit.
            </a>
            <div class="h-9 w-px bg-slate-600"></div>
            <button type="button" onclick="exportPDF()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2 text-sm">
                <i class="fas fa-file-pdf"></i>PDF
            </button>
            <button type="button" onclick="exportExcel()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2 text-sm">
                <i class="fas fa-file-excel"></i>Excel
            </button>
        </div>
    </form>

    <!-- Onglets -->
    <div class="no-print flex gap-1 mb-4 bg-slate-800 border border-slate-700 rounded-xl p-1 w-fit">
        <?php
        $tabs = [
            '3a'     => ['Note 3A',     'fas fa-table', 'Immobilisations brutes'],
            '3b'     => ['Note 3B',     'fas fa-file-contract', 'Location-acquisition'],
            '3c'     => ['Note 3C',     'fas fa-chart-bar', 'Amortissements'],
            '3cbis'  => ['Note 3C BIS', 'fas fa-chart-line', 'Dépréciations'],
            '3d'     => ['Note 3D',     'fas fa-exchange-alt', 'Plus/moins-values'],
        ];
        foreach ($tabs as $key => [$label, $icon, $title]):
            $isActive = ($activeTab === $key);
        ?>
        <button onclick="switchTab('<?= $key ?>')"
                id="tab-btn-<?= $key ?>"
                class="tab-btn px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 <?= $isActive ? 'bg-emerald-600 text-white shadow-lg' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-700' ?>"
                title="<?= $title ?>">
            <i class="<?= $icon ?> text-xs"></i><?= $label ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ================================================================
         TAB 3A — Immobilisations brutes
    ================================================================ -->
    <div id="tab-3a" class="tab-content <?= $activeTab==='3a' ? 'active' : '' ?>">
        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
                <div class="w-2 h-6 bg-emerald-500 rounded-full"></div>
                <div>
                    <h2 class="text-base font-bold text-slate-100">Note 3A — Immobilisations brutes</h2>
                    <p class="text-xs text-slate-400">Tableau de variation des valeurs brutes — Exercice <?= $annee_n ?> (en FCFA)</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="note-table w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-700">
                            <th class="text-left px-4 py-3 text-slate-300 w-80">Intitulé</th>
                            <th class="cell-num px-4 py-3 text-sky-300">Brut ouverture<br><span class="text-slate-500 font-normal text-xs">(A) — <?= $annee_n1 ?></span></th>
                            <th class="cell-num px-4 py-3 text-emerald-300">Acquisitions<br><span class="text-slate-500 font-normal text-xs">(B) — exercice N</span></th>
                            <th class="cell-num px-4 py-3 text-rose-300">Cessions / Sorties<br><span class="text-slate-500 font-normal text-xs">(C) — exercice N</span></th>
                            <th class="cell-num px-4 py-3 text-amber-300">Brut clôture<br><span class="text-slate-500 font-normal text-xs">(D=A+B-C) — <?= $annee_n ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $type = $r['type'] ?? 'data';
                    ?>
                    <?php if ($type === 'section'): ?>
                        <tr class="row-section">
                            <td colspan="5" class="px-4 py-2"><?= htmlspecialchars($r['label']) ?></td>
                        </tr>
                    <?php elseif ($type === 'subtotal'): ?>
                        <tr class="row-subtotal">
                            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-sky-300"><?= nfN($r['b_ouv']) ?></td>
                            <td class="cell-num px-4 py-2 text-emerald-300"><?= nfN($r['b_acq']) ?></td>
                            <td class="cell-num px-4 py-2 text-rose-300"><?= nfN($r['b_ces']) ?></td>
                            <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($r['b_clo']) ?></td>
                        </tr>
                    <?php elseif ($type === 'grand_total'): ?>
                        <tr class="row-grand-total">
                            <td class="px-4 py-3 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['b_ouv']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['b_acq']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['b_ces']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['b_clo']) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr class="row-data border-b border-slate-700/50">
                            <td class="px-4 py-2 text-slate-300 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['b_ouv']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['b_acq']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['b_ces']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['b_clo']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-2 no-print">
            <i class="fas fa-info-circle mr-1"></i>
            Brut ouverture = solde net débiteur 21x–27x au <?= date('d/m/Y', strtotime($date_fin_n1)) ?>.
            Acquisitions = mouvements débiteurs de l'exercice N. Cessions = mouvements créditeurs de l'exercice N.
        </p>
    </div>

    <!-- ================================================================
         TAB 3B — Biens pris en location-acquisition
    ================================================================ -->
    <div id="tab-3b" class="tab-content <?= $activeTab==='3b' ? 'active' : '' ?>">
        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
                <div class="w-2 h-6 bg-sky-500 rounded-full"></div>
                <div>
                    <h2 class="text-base font-bold text-slate-100">Note 3B — Biens pris en location-acquisition (crédit-bail)</h2>
                    <p class="text-xs text-slate-400">Immobilisations détenues sous contrat de location-financement — Exercice <?= $annee_n ?></p>
                </div>
            </div>
            <div class="p-6">
                <div class="bg-sky-900/20 border border-sky-700/40 rounded-xl p-6 text-center">
                    <i class="fas fa-file-contract text-4xl text-sky-400 mb-4 block"></i>
                    <h3 class="text-sky-300 font-semibold text-base mb-2">Suivi des biens en location-acquisition</h3>
                    <p class="text-slate-400 text-sm mb-4 max-w-lg mx-auto">
                        La Note 3B recense les immobilisations détenues dans le cadre de contrats de crédit-bail ou location-financement.
                        Ces biens sont comptabilisés dans les comptes 21x–24x avec une subdivision spécifique selon le plan comptable de l'entité.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6 text-left">
                        <div class="bg-slate-800 rounded-lg p-4 border border-slate-700">
                            <p class="text-xs font-semibold text-sky-300 uppercase mb-2">Colonnes SYSCOHADA</p>
                            <ul class="text-xs text-slate-400 space-y-1">
                                <li>• Valeur brute d'origine</li>
                                <li>• Amortissements cumulés</li>
                                <li>• Valeur nette comptable</li>
                                <li>• Redevances de l'exercice</li>
                                <li>• Redevances restant à payer</li>
                            </ul>
                        </div>
                        <div class="bg-slate-800 rounded-lg p-4 border border-slate-700">
                            <p class="text-xs font-semibold text-sky-300 uppercase mb-2">Comptes concernés</p>
                            <ul class="text-xs text-slate-400 space-y-1">
                                <li>• 21x–24x (actif loué)</li>
                                <li>• 281x–284x (amort.)</li>
                                <li>• 17x (dettes de leasing)</li>
                                <li>• 6125 (redevances)</li>
                            </ul>
                        </div>
                        <div class="bg-slate-800 rounded-lg p-4 border border-slate-700">
                            <p class="text-xs font-semibold text-amber-300 uppercase mb-2">Configuration requise</p>
                            <p class="text-xs text-slate-400">
                                Pour activer cette note, créez des sous-comptes dédiés au crédit-bail dans votre plan comptable
                                (ex. 2151 — Matériel en crédit-bail) et signalez-les dans la configuration de l'application.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         TAB 3C — Amortissements
    ================================================================ -->
    <div id="tab-3c" class="tab-content <?= $activeTab==='3c' ? 'active' : '' ?>">
        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
                <div class="w-2 h-6 bg-violet-500 rounded-full"></div>
                <div>
                    <h2 class="text-base font-bold text-slate-100">Note 3C — Amortissements des immobilisations</h2>
                    <p class="text-xs text-slate-400">Tableau de variation des amortissements cumulés — Exercice <?= $annee_n ?> (en FCFA)</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="note-table w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-700">
                            <th class="text-left px-4 py-3 text-slate-300 w-80">Intitulé</th>
                            <th class="cell-num px-4 py-3 text-sky-300">Cumul ouverture<br><span class="text-slate-500 font-normal text-xs">(A) — <?= $annee_n1 ?></span></th>
                            <th class="cell-num px-4 py-3 text-violet-300">Dotations N<br><span class="text-slate-500 font-normal text-xs">(B) — exercice N</span></th>
                            <th class="cell-num px-4 py-3 text-rose-300">Réductions N<br><span class="text-slate-500 font-normal text-xs">(C) — sorties N</span></th>
                            <th class="cell-num px-4 py-3 text-amber-300">Cumul clôture<br><span class="text-slate-500 font-normal text-xs">(D=A+B-C) — <?= $annee_n ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $type = $r['type'] ?? 'data';
                    ?>
                    <?php if ($type === 'section'): ?>
                        <tr class="row-section">
                            <td colspan="5" class="px-4 py-2"><?= htmlspecialchars($r['label']) ?></td>
                        </tr>
                    <?php elseif ($type === 'subtotal'): ?>
                        <tr class="row-subtotal">
                            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-sky-300"><?= nfN($r['a_ouv']) ?></td>
                            <td class="cell-num px-4 py-2 text-violet-300"><?= nfN($r['a_dot']) ?></td>
                            <td class="cell-num px-4 py-2 text-rose-300"><?= nfN($r['a_red']) ?></td>
                            <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($r['a_clo']) ?></td>
                        </tr>
                    <?php elseif ($type === 'grand_total'): ?>
                        <tr class="row-grand-total">
                            <td class="px-4 py-3 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['a_ouv']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['a_dot']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['a_red']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['a_clo']) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr class="row-data border-b border-slate-700/50">
                            <td class="px-4 py-2 text-slate-300 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['a_ouv']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['a_dot']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['a_red']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['a_clo']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-2 no-print">
            <i class="fas fa-info-circle mr-1"></i>
            Cumul ouverture = solde net créditeur des comptes 281x–284x au <?= date('d/m/Y', strtotime($date_fin_n1)) ?>.
            Dotations = mouvements créditeurs N. Réductions = mouvements débiteurs N (reprises + sorties).
        </p>
    </div>

    <!-- ================================================================
         TAB 3C BIS — Dépréciations
    ================================================================ -->
    <div id="tab-3cbis" class="tab-content <?= $activeTab==='3cbis' ? 'active' : '' ?>">
        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
                <div class="w-2 h-6 bg-amber-500 rounded-full"></div>
                <div>
                    <h2 class="text-base font-bold text-slate-100">Note 3C BIS — Dépréciations des immobilisations</h2>
                    <p class="text-xs text-slate-400">Tableau de variation des dépréciations — Exercice <?= $annee_n ?> (en FCFA)</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="note-table w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-700">
                            <th class="text-left px-4 py-3 text-slate-300 w-80">Intitulé</th>
                            <th class="cell-num px-4 py-3 text-sky-300">Cumul ouverture<br><span class="text-slate-500 font-normal text-xs">(A) — <?= $annee_n1 ?></span></th>
                            <th class="cell-num px-4 py-3 text-amber-300">Dotations N<br><span class="text-slate-500 font-normal text-xs">(B) — exercice N</span></th>
                            <th class="cell-num px-4 py-3 text-rose-300">Reprises N<br><span class="text-slate-500 font-normal text-xs">(C) — exercice N</span></th>
                            <th class="cell-num px-4 py-3 text-emerald-300">Cumul clôture<br><span class="text-slate-500 font-normal text-xs">(D=A+B-C) — <?= $annee_n ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $type = $r['type'] ?? 'data';
                    ?>
                    <?php if ($type === 'section'): ?>
                        <tr class="row-section">
                            <td colspan="5" class="px-4 py-2"><?= htmlspecialchars($r['label']) ?></td>
                        </tr>
                    <?php elseif ($type === 'subtotal'): ?>
                        <tr class="row-subtotal">
                            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-sky-300"><?= nfN($r['d_ouv']) ?></td>
                            <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($r['d_dot']) ?></td>
                            <td class="cell-num px-4 py-2 text-rose-300"><?= nfN($r['d_rep']) ?></td>
                            <td class="cell-num px-4 py-2 text-emerald-300"><?= nfN($r['d_clo']) ?></td>
                        </tr>
                    <?php elseif ($type === 'grand_total'): ?>
                        <tr class="row-grand-total">
                            <td class="px-4 py-3 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['d_ouv']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['d_dot']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['d_rep']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($r['d_clo']) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr class="row-data border-b border-slate-700/50">
                            <td class="px-4 py-2 text-slate-300 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['d_ouv']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['d_dot']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['d_rep']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['d_clo']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-2 no-print">
            <i class="fas fa-info-circle mr-1"></i>
            Cumul ouverture = solde net créditeur des comptes 291x–297x au <?= date('d/m/Y', strtotime($date_fin_n1)) ?>.
            Dotations = mouvements créditeurs N. Reprises = mouvements débiteurs N.
        </p>
    </div>

    <!-- ================================================================
         TAB 3D — Plus/moins-values de cession
    ================================================================ -->
    <div id="tab-3d" class="tab-content <?= $activeTab==='3d' ? 'active' : '' ?>">
        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
                <div class="w-2 h-6 bg-rose-500 rounded-full"></div>
                <div>
                    <h2 class="text-base font-bold text-slate-100">Note 3D — Plus-values et moins-values de cession</h2>
                    <p class="text-xs text-slate-400">Analyse des cessions d'immobilisations — Exercice <?= $annee_n ?> (en FCFA)</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="note-table w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-700">
                            <th class="text-left px-4 py-3 text-slate-300 w-72">Catégorie de cession</th>
                            <th class="cell-num px-4 py-3 text-sky-300">Brut cédé<br><span class="text-slate-500 font-normal text-xs">Valeur brute sortie</span></th>
                            <th class="cell-num px-4 py-3 text-violet-300">Amort. cumulé<br><span class="text-slate-500 font-normal text-xs">Repris à la sortie</span></th>
                            <th class="cell-num px-4 py-3 text-amber-300">VNC<br><span class="text-slate-500 font-normal text-xs">Valeur nette</span></th>
                            <th class="cell-num px-4 py-3 text-emerald-300">Prix de cession<br><span class="text-slate-500 font-normal text-xs">Encaissement</span></th>
                            <th class="cell-num px-4 py-3 text-rose-300">+/- value<br><span class="text-slate-500 font-normal text-xs">Prix – VNC</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows3D as $r): ?>
                        <tr class="row-data border-b border-slate-700/50">
                            <td class="px-4 py-2 text-slate-300 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['brut']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['amort']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['vnc']) ?></td>
                            <td class="cell-num px-4 py-2 text-slate-300"><?= nfN($r['prix']) ?></td>
                            <td class="cell-num px-4 py-2 font-semibold <?= $r['pmv'] >= 0 ? 'pmv-pos' : 'pmv-neg' ?>">
                                <?= $r['pmv'] >= 0 ? '+' : '' ?><?= nfS($r['pmv']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <!-- Total -->
                        <tr class="row-grand-total">
                            <td class="px-4 py-3 text-sm">TOTAL CESSIONS</td>
                            <td class="cell-num px-4 py-3"><?= nfN($tot3D['brut']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($tot3D['amort']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($tot3D['vnc']) ?></td>
                            <td class="cell-num px-4 py-3"><?= nfN($tot3D['prix']) ?></td>
                            <td class="cell-num px-4 py-3 font-bold <?= $tot3D['pmv'] >= 0 ? 'pmv-pos' : 'pmv-neg' ?>">
                                <?= $tot3D['pmv'] >= 0 ? '+' : '' ?><?= nfS($tot3D['pmv']) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Légende -->
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 no-print">
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                <p class="text-xs font-semibold text-rose-300 uppercase mb-2"><i class="fas fa-info-circle mr-1"></i>Cessions HAO (Hors Activités Ordinaires)</p>
                <ul class="text-xs text-slate-400 space-y-1">
                    <li>• <span class="text-slate-300">Incorporelles</span> : VNC → 811x | Prix → 821x</li>
                    <li>• <span class="text-slate-300">Corporelles</span> : VNC → 812x–814x | Prix → 822x–824x</li>
                    <li>• <span class="text-slate-300">Financières</span> : VNC → 816x–817x | Prix → 826x–827x</li>
                </ul>
            </div>
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                <p class="text-xs font-semibold text-emerald-300 uppercase mb-2"><i class="fas fa-info-circle mr-1"></i>Cessions courantes (Activités Ordinaires)</p>
                <ul class="text-xs text-slate-400 space-y-1">
                    <li>• <span class="text-slate-300">VNC cédée</span> : compte 654 (charges HAO — VNC des immob. cédées)</li>
                    <li>• <span class="text-slate-300">Prix de cession</span> : compte 754 (produits de cessions courantes)</li>
                    <li>• <span class="text-slate-300">Note</span> : les cessions courantes concernent les immobilisations ayant un caractère courant dans l'activité.</li>
                </ul>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-2 no-print">
            <i class="fas fa-info-circle mr-1"></i>
            Brut cédé = mouvements créditeurs N sur les comptes bruts (sorties). Amort. = mouvements débiteurs N sur les comptes d'amortissement (reprises à la sortie).
            VNC = Brut cédé − Amort. Plus-value (+) si Prix > VNC, moins-value (−) si Prix &lt; VNC.
        </p>
    </div>

    <!-- Synthèse VNC (toutes notes) -->
    <div class="mt-6 bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl p-6 no-print">
        <h3 class="text-sm font-semibold text-slate-300 mb-4 flex items-center gap-2">
            <i class="fas fa-balance-scale text-emerald-400"></i>
            Synthèse VNC — Valeurs nettes comptables
        </h3>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-4 py-2 text-slate-400 text-xs">Intitulé</th>
                        <th class="cell-num px-4 py-2 text-sky-300 text-xs">VNC ouverture<br><?= $annee_n1 ?></th>
                        <th class="cell-num px-4 py-2 text-emerald-300 text-xs">VNC clôture<br><?= $annee_n ?></th>
                        <th class="cell-num px-4 py-2 text-amber-300 text-xs">Variation</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $type = $r['type'] ?? 'data';
                    if ($type === 'section') continue;
                    $variation = ($r['vnc_clo'] ?? 0) - ($r['vnc_ouv'] ?? 0);
                    $rowClass = match($type) {
                        'grand_total' => 'row-grand-total',
                        'subtotal'    => 'row-subtotal',
                        default       => 'row-data border-b border-slate-700/30',
                    };
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="px-4 py-1.5 text-sm text-slate-300"><?= htmlspecialchars($r['label']) ?></td>
                    <td class="cell-num px-4 py-1.5 text-slate-300"><?= nfN($r['vnc_ouv']) ?></td>
                    <td class="cell-num px-4 py-1.5 text-slate-300"><?= nfN($r['vnc_clo']) ?></td>
                    <td class="cell-num px-4 py-1.5 <?= $variation >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                        <?= $variation >= 0 ? '+' : '' ?><?= nfS($variation) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500 mt-2">VNC = Brut − Amortissements − Dépréciations</p>
    </div>

</main>
</div>

<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });

function switchTab(tab) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    // Deactivate all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('bg-emerald-600', 'text-white', 'shadow-lg');
        btn.classList.add('text-slate-400', 'hover:text-slate-200', 'hover:bg-slate-700');
    });
    // Activate selected
    const content = document.getElementById('tab-' + tab);
    if (content) content.classList.add('active');
    const btn = document.getElementById('tab-btn-' + tab);
    if (btn) {
        btn.classList.add('bg-emerald-600', 'text-white', 'shadow-lg');
        btn.classList.remove('text-slate-400', 'hover:text-slate-200', 'hover:bg-slate-700');
    }
    // Update hidden form field
    document.getElementById('formTab').value = tab;
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

function exportPDF() {
    const tab = document.getElementById('formTab').value;
    const params = new URLSearchParams(window.location.search);
    params.set('tab', tab);
    window.open('export_note3_pdf.php?' + params.toString(), '_blank');
}
function exportExcel() {
    const tab = document.getElementById('formTab').value;
    const params = new URLSearchParams(window.location.search);
    params.set('tab', tab);
    window.open('export_note3_excel.php?' + params.toString(), '_blank');
}
</script>
</body>
</html>
