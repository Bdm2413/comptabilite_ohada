<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$date_debut_n = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin_n   = $_GET['date_fin']   ?? date('Y-12-31');
$date_fin_n1  = date('Y-m-d', strtotime($date_fin_n . ' -1 year'));
$annee_n      = date('Y', strtotime($date_fin_n));
$annee_n1     = date('Y', strtotime($date_fin_n1));
$qs           = http_build_query(['date_debut' => $date_debut_n, 'date_fin' => $date_fin_n]);

// ── Balance cumulée fin N et fin N-1 ─────────────────────────────────────────
$sql = "
    SELECT pc.compte,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS cum_debit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS cum_credit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS cum_debit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS cum_credit_N1
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON le.compte = pc.compte AND le.societe_id = pc.societe_id
    LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut='Validé' AND e.societe_id = pc.societe_id
    WHERE pc.actif='Oui' AND pc.societe_id = ?
    GROUP BY pc.compte
    ORDER BY pc.compte
";
$stmt = $db->prepare($sql);
$stmt->execute([$date_fin_n, $date_fin_n, $date_fin_n1, $date_fin_n1, $societe_id]);
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ───────────────────────────────────────────────────────────────────
if (!function_exists('matchPx')) {
    function matchPx(string $s, array $pfx): bool {
        foreach ($pfx as $p) { if (str_starts_with($s, $p)) return true; }
        return false;
    }
}
// Solde débiteur net (stocks — actif)
function sdNet(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r["cum_debit_$p"] - (float)$r["cum_credit_$p"];
    }
    return max(0.0, $t);
}
// Solde créditeur net (dépréciations — passif)
function scNet(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r["cum_credit_$p"] - (float)$r["cum_debit_$p"];
    }
    return max(0.0, $t);
}
function nfN($n) {
    if (abs((float)$n) < 0.5) return '';
    return number_format((float)$n, 0, ',', ' ');
}

// ── Rubriques stocks avec leurs dépréciations associées ──────────────────────
// Chaque rubrique : pfx = compte stock, dep = compte dépréciation correspondant
$rubriques = [
    ['label' => 'Marchandises',                                              'pfx' => ['31'],  'dep' => ['391']],
    ['label' => 'Matières premières et fournitures liées',                   'pfx' => ['32'],  'dep' => ['392']],
    ['label' => 'Autres approvisionnements',                                 'pfx' => ['33'],  'dep' => ['393']],
    ['label' => 'Produits en cours',                                         'pfx' => ['34'],  'dep' => ['394']],
    ['label' => 'Services en cours',                                         'pfx' => ['35'],  'dep' => ['395']],
    ['label' => 'Produits finis',                                            'pfx' => ['36'],  'dep' => ['396']],
    ['label' => 'Produits intermédiaires',                                   'pfx' => ['371'], 'dep' => ['397']],
    ['label' => 'Stocks en cours de route, en consignation ou en dépôt',    'pfx' => ['38'],  'dep' => ['398']],
];

// Calcul par rubrique
$rows = [];
foreach ($rubriques as $rub) {
    $brut_N  = sdNet($comptes, $rub['pfx'], 'N');
    $brut_N1 = sdNet($comptes, $rub['pfx'], 'N1');
    $dep_N   = scNet($comptes, $rub['dep'], 'N');
    $dep_N1  = scNet($comptes, $rub['dep'], 'N1');
    $rows[] = [
        'label'   => $rub['label'],
        'brut_N'  => $brut_N,
        'dep_N'   => $dep_N,
        'net_N'   => max(0.0, $brut_N  - $dep_N),
        'net_N1'  => max(0.0, $brut_N1 - $dep_N1),
        'brut_N1' => $brut_N1,
        'dep_N1'  => $dep_N1,
    ];
}

// Totaux
$pfx_all_stocks = ['31','32','33','34','35','36','371','38'];
$pfx_all_dep    = ['391','392','393','394','395','396','397','398'];

$tot_brut_N  = sdNet($comptes, $pfx_all_stocks, 'N');
$tot_brut_N1 = sdNet($comptes, $pfx_all_stocks, 'N1');
$tot_dep_N   = scNet($comptes, $pfx_all_dep, 'N');
$tot_dep_N1  = scNet($comptes, $pfx_all_dep, 'N1');
$tot_net_N   = max(0.0, $tot_brut_N  - $tot_dep_N);
$tot_net_N1  = max(0.0, $tot_brut_N1 - $tot_dep_N1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Note 6 — Stocks et en-cours <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .note-table th, .note-table td { border: 1px solid #d1d5db !important; color: black !important; background: white !important; }
            .row-section td  { background: #e5e7eb !important; color: #111 !important; }
            .row-subtotal td { background: #f3f4f6 !important; }
        }
        .note-table th { font-size: 11px; white-space: nowrap; }
        .note-table td { font-size: 12px; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .row-section td  { background: #1e3a5f; color: #93c5fd; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .row-grand td    { background: #0f172a; color: #34d399; font-weight: 700; border-top: 2px solid #10b981; }
        .row-data:hover td { background: rgba(255,255,255,0.03); }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-teal-400 to-emerald-400 mb-1">
                <i class="fas fa-boxes mr-3"></i>Note 6 — Stocks et en-cours
            </h1>
            <p class="text-slate-400 text-sm">
                Exercice du <?= date('d/m/Y', strtotime($date_debut_n)) ?> au <?= date('d/m/Y', strtotime($date_fin_n)) ?>
                &nbsp;|&nbsp; N-1 : <?= $annee_n1 ?>
            </p>
        </div>
        <div class="flex gap-2 no-print">
            <a href="notes_annexes.php?<?= $qs ?>"
               class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2 text-sm">
                <i class="fas fa-arrow-left"></i> Toutes les notes
            </a>
            <button onclick="window.print()"
                    class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg transition-colors inline-flex items-center gap-2 text-sm">
                <i class="fas fa-print"></i> Imprimer
            </button>
        </div>
    </div>

    <!-- Filtre période -->
    <form method="GET" class="no-print mb-6 bg-slate-800 border border-slate-700 rounded-xl p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Début N</label>
                <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut_n) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Fin N</label>
                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin_n) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
            </div>
            <button type="submit"
                    class="px-4 py-2 bg-gradient-to-r from-teal-600 to-emerald-600 hover:from-teal-700 hover:to-emerald-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Afficher
            </button>
            <a href="note_6_stocks.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-redo"></i> Réinit.
            </a>
        </div>
    </form>

    <!-- Tableau stocks -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden mb-4">
        <div class="px-6 py-4 border-b border-slate-700">
            <h2 class="text-base font-bold text-slate-100">Note 6 — Stocks et en-cours (en FCFA)</h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Soldes au <?= date('d/m/Y', strtotime($date_fin_n)) ?> — Dépréciations comptes 391 à 398
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300" style="min-width:320px">Intitulé</th>
                        <th class="cell-num px-5 py-3 text-sky-300">
                            Valeur brute N<br><span class="font-normal text-slate-500"><?= $annee_n ?></span>
                        </th>
                        <th class="cell-num px-5 py-3 text-rose-400">
                            Dépréciations N<br><span class="font-normal text-slate-500"><?= $annee_n ?></span>
                        </th>
                        <th class="cell-num px-5 py-3 text-emerald-400">
                            Valeur nette N<br><span class="font-normal text-slate-500"><?= $annee_n ?></span>
                        </th>
                        <th class="cell-num px-5 py-3 text-slate-400">
                            Valeur nette N-1<br><span class="font-normal text-slate-500"><?= $annee_n1 ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-section">
                        <td colspan="5" class="px-5 py-2">STOCKS ET EN-COURS</td>
                    </tr>

                    <?php foreach ($rows as $r): ?>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8"><?= htmlspecialchars($r['label']) ?></td>
                        <td class="cell-num px-5 py-2 text-sky-300"><?= nfN($r['brut_N']) ?></td>
                        <td class="cell-num px-5 py-2 text-rose-400"><?= nfN($r['dep_N']) ?></td>
                        <td class="cell-num px-5 py-2 text-emerald-400"><?= nfN($r['net_N']) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-400"><?= nfN($r['net_N1']) ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Total -->
                    <tr class="row-grand">
                        <td class="px-5 py-3">TOTAL STOCKS ET EN-COURS</td>
                        <td class="cell-num px-5 py-3 text-sky-300"><?= nfN($tot_brut_N) ?></td>
                        <td class="cell-num px-5 py-3 text-rose-400"><?= nfN($tot_dep_N) ?></td>
                        <td class="cell-num px-5 py-3 text-emerald-400"><?= nfN($tot_net_N) ?></td>
                        <td class="cell-num px-5 py-3 text-slate-400"><?= nfN($tot_net_N1) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-slate-600 mt-3 no-print">
        Source : Plan Comptable SYSCOHADA — Liasse DGI Système Normal, Note 6 — Données issues de la balance comptable (comptes 31-38, 391-398)
    </p>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
