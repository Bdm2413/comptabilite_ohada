<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$date_debut_n  = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin_n    = $_GET['date_fin']   ?? date('Y-12-31');
$date_debut_n1 = date('Y-m-d', strtotime($date_debut_n . ' -1 year'));
$date_fin_n1   = date('Y-m-d', strtotime($date_fin_n   . ' -1 year'));
$annee_n  = date('Y', strtotime($date_fin_n));
$annee_n1 = date('Y', strtotime($date_fin_n1));
$qs = http_build_query(['date_debut' => $date_debut_n, 'date_fin' => $date_fin_n]);

// ── Requête balance (même pattern que TFT) ────────────────────────────────────
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
// Solde créditeur net (dettes, passif)
function scNet(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r["cum_credit_$p"] - (float)$r["cum_debit_$p"];
    }
    return max(0.0, $t); // une dette ne peut pas être négative
}

function nfN($n) {
    if (abs((float)$n) < 0.5) return '';
    return number_format((float)$n, 0, ',', ' ');
}

// ── Rubriques de la Note 1 ────────────────────────────────────────────────────
// Source : DGI Liasse Système Normal — Note 1 (comptes SYSCOHADA)
$rubriques = [
    ['type' => 'section', 'label' => 'EMPRUNTS ET DETTES FINANCIÈRES'],
    ['label' => 'Emprunts obligataires convertibles',              'pfx' => ['1612']],
    ['label' => 'Autres emprunts obligataires',                    'pfx' => ['1618']],
    ['label' => 'Emprunts et dettes des établissements de crédit', 'pfx' => ['162']],
    ['label' => 'Autres dettes financières',                       'pfx' => ['168']],
    ['type' => 'subtotal', 'label' => 'SOUS-TOTAL DETTES FINANCIÈRES', 'pfx' => ['1612','1618','162','168']],

    ['type' => 'section', 'label' => 'DETTES DE LOCATION-ACQUISITION'],
    ['label' => 'Dettes de crédit-bail immobilier',                'pfx' => ['172']],
    ['label' => 'Dettes de crédit-bail mobilier',                  'pfx' => ['173']],
    ['label' => 'Dettes sur contrats de location-vente',           'pfx' => ['174']],
    ['label' => 'Autres dettes de location-acquisition',           'pfx' => ['178']],
    ['type' => 'subtotal', 'label' => 'SOUS-TOTAL LOCATION-ACQUISITION', 'pfx' => ['172','173','174','178']],

    ['type' => 'section', 'label' => 'DETTES D\'EXPLOITATION ET DIVERSES'],
    ['label' => 'Fournisseurs et comptes rattachés',               'pfx' => ['40']],
    ['label' => 'Clients créditeurs',                              'pfx' => ['411']],
    ['label' => 'Personnel',                                       'pfx' => ['42']],
    ['label' => 'Sécurité sociale et organismes sociaux',          'pfx' => ['43']],
    ['label' => 'État et collectivités publiques',                 'pfx' => ['44']],
    ['label' => 'Organismes internationaux',                       'pfx' => ['45']],
    ['label' => 'Associés et groupe',                              'pfx' => ['46']],
    ['label' => 'Créditeurs divers',                               'pfx' => ['4712']],
    ['type' => 'subtotal', 'label' => 'SOUS-TOTAL DETTES D\'EXPLOITATION', 'pfx' => ['40','411','42','43','44','45','46','4712']],

    ['type' => 'grand_total', 'label' => 'TOTAL GÉNÉRAL',
     'pfx' => ['1612','1618','162','168','172','173','174','178','40','411','42','43','44','45','46','4712']],
];

// ── Calcul ────────────────────────────────────────────────────────────────────
$rows = [];
foreach ($rubriques as $rub) {
    if (($rub['type'] ?? '') === 'section') { $rows[] = $rub; continue; }
    $pfx = $rub['pfx'];
    $rows[] = array_merge($rub, [
        'n'  => scNet($comptes, $pfx, 'N'),
        'n1' => scNet($comptes, $pfx, 'N1'),
    ]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Note 1 — État des dettes <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .note-table th, .note-table td { border: 1px solid #d1d5db !important; color: black !important; background: white !important; }
            .row-section td { background: #e5e7eb !important; color: #111 !important; }
            .row-subtotal td { background: #f3f4f6 !important; }
        }
        .note-table th { font-size: 11px; white-space: nowrap; }
        .note-table td { font-size: 12px; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .row-section td  { background: #1e3a5f; color: #93c5fd; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .row-subtotal td { background: #1e293b; color: #7dd3fc; font-weight: 600; border-top: 1px solid #334155; }
        .row-grand-total td { background: #0f172a; color: #34d399; font-weight: 700; border-top: 2px solid #10b981; }
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
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-amber-400 to-orange-400 mb-1">
                <i class="fas fa-file-invoice-dollar mr-3"></i>Note 1 — État des dettes
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
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Fin N</label>
                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin_n) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-amber-500">
            </div>
            <button type="submit"
                    class="px-4 py-2 bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Afficher
            </button>
            <a href="note_1_garanties.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-redo"></i> Réinit.
            </a>
        </div>
    </form>

    <!-- Tableau -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700">
            <h2 class="text-base font-bold text-slate-100">Note 1 — État des dettes (en FCFA)</h2>
            <p class="text-xs text-slate-400 mt-0.5">Soldes créditeurs nets au <?= date('d/m/Y', strtotime($date_fin_n)) ?> — Source : balance comptable</p>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300">Intitulé</th>
                        <th class="cell-num px-5 py-3 text-sky-300">
                            Exercice N<br><span class="font-normal text-slate-500"><?= $annee_n ?></span>
                        </th>
                        <th class="cell-num px-5 py-3 text-slate-400">
                            Exercice N-1<br><span class="font-normal text-slate-500"><?= $annee_n1 ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $type = $r['type'] ?? 'data';
                ?>
                <?php if ($type === 'section'): ?>
                    <tr class="row-section">
                        <td colspan="3" class="px-5 py-2"><?= htmlspecialchars($r['label']) ?></td>
                    </tr>
                <?php elseif ($type === 'subtotal'): ?>
                    <tr class="row-subtotal">
                        <td class="px-5 py-2 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                        <td class="cell-num px-5 py-2 text-sky-300"><?= nfN($r['n']) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-400"><?= nfN($r['n1']) ?></td>
                    </tr>
                <?php elseif ($type === 'grand_total'): ?>
                    <tr class="row-grand-total">
                        <td class="px-5 py-3 text-sm"><?= htmlspecialchars($r['label']) ?></td>
                        <td class="cell-num px-5 py-3"><?= nfN($r['n']) ?></td>
                        <td class="cell-num px-5 py-3"><?= nfN($r['n1']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8"><?= htmlspecialchars($r['label']) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-200"><?= nfN($r['n']) ?></td>
                        <td class="cell-num px-5 py-2 text-slate-400"><?= nfN($r['n1']) ?></td>
                    </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-slate-600 mt-3 no-print">
        Source : Plan Comptable SYSCOHADA — Liasse DGI Système Normal, Note 1
    </p>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
