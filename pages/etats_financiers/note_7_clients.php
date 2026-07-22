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
function sdNet(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r["cum_debit_$p"] - (float)$r["cum_credit_$p"];
    }
    return max(0.0, $t);
}
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

// ── Rubriques CRÉANCES CLIENTS (actif — solde débiteur) ───────────────────────
$creances = [
    ['label' => 'Clients (hors réserves de propriété et Groupe)',              'pfx' => ['4111','4114','4115','4117','4118']],
    ['label' => 'Clients effets à recevoir (hors réserves et Groupe)',         'pfx' => ['4121','4124','4125']],
    ['label' => 'Clients avec réserves de propriété',                          'pfx' => ['4116']],
    ['label' => 'Clients et effets à recevoir — Groupe',                       'pfx' => ['4112','4122']],
    ['label' => 'Créances sur cessions courantes d\'immobilisations',          'pfx' => ['414']],
    ['label' => 'Clients effets escomptés non échus',                          'pfx' => ['415']],
    ['label' => 'Créances litigieuses ou douteuses',                           'pfx' => ['416']],
    ['label' => 'Clients, produits à recevoir',                                'pfx' => ['418']],
];

foreach ($creances as &$cr) {
    $cr['brut_N']  = sdNet($comptes, $cr['pfx'], 'N');
    $cr['brut_N1'] = sdNet($comptes, $cr['pfx'], 'N1');
}
unset($cr);

// Préfixes globaux créances et dépréciations
$pfx_all_creances = ['4111','4114','4115','4117','4118','4121','4124','4125',
                     '4116','4112','4122','414','415','416','418'];
$tot_brut_N  = sdNet($comptes, $pfx_all_creances, 'N');
$tot_brut_N1 = sdNet($comptes, $pfx_all_creances, 'N1');
$dep_N       = scNet($comptes, ['491'], 'N');
$dep_N1      = scNet($comptes, ['491'], 'N1');
$net_N       = max(0.0, $tot_brut_N  - $dep_N);
$net_N1      = max(0.0, $tot_brut_N1 - $dep_N1);

// ── Rubriques CLIENTS CRÉDITEURS (passif — solde créditeur) ──────────────────
$crediteurs = [
    ['label' => 'Clients, avances et acomptes reçus (hors Groupe)',   'pfx' => ['4191']],
    ['label' => 'Clients — Groupe, avances et acomptes reçus',        'pfx' => ['4192']],
    ['label' => 'Autres clients créditeurs',                          'pfx' => ['4194','4198']],
];

foreach ($crediteurs as &$cr) {
    $cr['val_N']  = scNet($comptes, $cr['pfx'], 'N');
    $cr['val_N1'] = scNet($comptes, $cr['pfx'], 'N1');
}
unset($cr);

$pfx_all_cred     = ['4191','4192','4194','4198'];
$tot_cred_N  = scNet($comptes, $pfx_all_cred, 'N');
$tot_cred_N1 = scNet($comptes, $pfx_all_cred, 'N1');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Note 7 — Clients <?= $annee_n ?></title>
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
        .row-subtotal td { background: #1e293b; color: #7dd3fc; font-weight: 600; border-top: 1px solid #334155; }
        .row-dep td      { background: #1c1917; color: #fca5a5; font-style: italic; }
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
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-400 mb-1">
                <i class="fas fa-users mr-3"></i>Note 7 — Clients
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
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Fin N</label>
                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin_n) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit"
                    class="px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Afficher
            </button>
            <a href="note_7_clients.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-redo"></i> Réinit.
            </a>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TABLEAU I — CRÉANCES CLIENTS
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-8 h-8 rounded-full bg-blue-500/20 flex items-center justify-center text-blue-400 font-bold text-sm">I</span>
            <div>
                <h2 class="text-base font-bold text-slate-100">Créances clients (en FCFA)</h2>
                <p class="text-xs text-slate-400 mt-0.5">Soldes débiteurs au <?= date('d/m/Y', strtotime($date_fin_n)) ?> — Dépréciations compte 491</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300" style="min-width:360px">Intitulé</th>
                        <th class="cell-num px-5 py-3 text-sky-300">
                            Brut N<br><span class="font-normal text-slate-500"><?= $annee_n ?></span>
                        </th>
                        <th class="cell-num px-5 py-3 text-slate-400">
                            Brut N-1<br><span class="font-normal text-slate-500"><?= $annee_n1 ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-section">
                        <td colspan="3" class="px-5 py-2">CRÉANCES CLIENTS</td>
                    </tr>

                    <?php foreach ($creances as $cr): ?>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8"><?= htmlspecialchars($cr['label']) ?></td>
                        <td class="cell-num px-5 py-2 text-sky-300"><?= nfN($cr['brut_N']) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-400"><?= nfN($cr['brut_N1']) ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Sous-total brut -->
                    <tr class="row-subtotal">
                        <td class="px-5 py-2">TOTAL BRUT CLIENTS</td>
                        <td class="cell-num px-5 py-2 text-sky-300"><?= nfN($tot_brut_N) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-400"><?= nfN($tot_brut_N1) ?></td>
                    </tr>

                    <!-- Dépréciations -->
                    <tr class="row-dep border-b border-slate-700/50">
                        <td class="px-5 py-2 pl-8">(−) Dépréciations des comptes clients (491)</td>
                        <td class="cell-num px-5 py-2"><?= nfN($dep_N) ?></td>
                        <td class="cell-num px-5 py-2 opacity-60"><?= nfN($dep_N1) ?></td>
                    </tr>

                    <!-- Net -->
                    <tr class="row-grand">
                        <td class="px-5 py-3">TOTAL NET CLIENTS</td>
                        <td class="cell-num px-5 py-3 text-emerald-400"><?= nfN($net_N) ?></td>
                        <td class="cell-num px-5 py-3 text-emerald-400/70"><?= nfN($net_N1) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TABLEAU II — CLIENTS CRÉDITEURS
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden mb-4">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-8 h-8 rounded-full bg-amber-500/20 flex items-center justify-center text-amber-400 font-bold text-sm">II</span>
            <div>
                <h2 class="text-base font-bold text-slate-100">Clients créditeurs (en FCFA)</h2>
                <p class="text-xs text-slate-400 mt-0.5">Avances et acomptes reçus — soldes créditeurs au <?= date('d/m/Y', strtotime($date_fin_n)) ?></p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300" style="min-width:360px">Intitulé</th>
                        <th class="cell-num px-5 py-3 text-amber-300">
                            Exercice N<br><span class="font-normal text-slate-500"><?= $annee_n ?></span>
                        </th>
                        <th class="cell-num px-5 py-3 text-slate-400">
                            Exercice N-1<br><span class="font-normal text-slate-500"><?= $annee_n1 ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-section">
                        <td colspan="3" class="px-5 py-2">CLIENTS CRÉDITEURS</td>
                    </tr>

                    <?php foreach ($crediteurs as $cr): ?>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8"><?= htmlspecialchars($cr['label']) ?></td>
                        <td class="cell-num px-5 py-2 text-amber-300"><?= nfN($cr['val_N']) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-400"><?= nfN($cr['val_N1']) ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <tr class="row-grand">
                        <td class="px-5 py-3">TOTAL CLIENTS CRÉDITEURS</td>
                        <td class="cell-num px-5 py-3 text-amber-300"><?= nfN($tot_cred_N) ?></td>
                        <td class="cell-num px-5 py-3 text-slate-400"><?= nfN($tot_cred_N1) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-slate-600 mt-3 no-print">
        Source : Plan Comptable SYSCOHADA — Liasse DGI Système Normal, Note 7 — Données issues de la balance comptable (comptes 411x-418, 491, 4191-4198)
    </p>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
