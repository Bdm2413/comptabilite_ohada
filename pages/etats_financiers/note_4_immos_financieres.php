<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

$date_debut_n = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin_n   = $_GET['date_fin']   ?? date('Y-12-31');
$annee_n      = (int)date('Y', strtotime($date_fin_n));
$annee_n1     = $annee_n - 1;
$qs           = http_build_query(['date_debut' => $date_debut_n, 'date_fin' => $date_fin_n]);

// ── Création table si besoin ───────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS note_4_participations (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    societe_id               INT NOT NULL,
    exercice                 INT NOT NULL,
    denomination             VARCHAR(255) NOT NULL,
    localisation             VARCHAR(255) DEFAULT '',
    valeur_acquisition       DECIMAL(15,2) DEFAULT 0,
    pourcentage              DECIMAL(6,3)  DEFAULT 0,
    capitaux_propres         DECIMAL(15,2) DEFAULT 0,
    resultat_dernier_exercice DECIMAL(15,2) DEFAULT 0,
    annee_dernier_exercice   INT DEFAULT NULL,
    part_benefice_recue      DECIMAL(15,2) DEFAULT 0,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Actions CRUD ─────────────────────────────────────────────────────────────
$action   = $_POST['action'] ?? '';
$edit_id  = (int)($_GET['edit'] ?? 0);
$errors   = [];

if ($action === 'add' || $action === 'edit') {
    $denomination  = trim($_POST['denomination'] ?? '');
    $localisation  = trim($_POST['localisation'] ?? '');
    $val_acq       = (float)str_replace([' ', ','], ['', '.'], $_POST['valeur_acquisition'] ?? '0');
    $pct           = (float)str_replace(',', '.', $_POST['pourcentage'] ?? '0');
    $cap_propres   = (float)str_replace([' ', ','], ['', '.'], $_POST['capitaux_propres'] ?? '0');
    $resultat      = (float)str_replace([' ', ','], ['', '.'], $_POST['resultat_dernier_exercice'] ?? '0');
    $annee_fil     = $_POST['annee_dernier_exercice'] !== '' ? (int)$_POST['annee_dernier_exercice'] : null;
    $part_benef    = (float)str_replace([' ', ','], ['', '.'], $_POST['part_benefice_recue'] ?? '0');

    if ($denomination === '') $errors[] = 'La dénomination sociale est obligatoire.';

    if (empty($errors)) {
        if ($action === 'add') {
            $st = $db->prepare("INSERT INTO note_4_participations
                (societe_id, exercice, denomination, localisation, valeur_acquisition,
                 pourcentage, capitaux_propres, resultat_dernier_exercice,
                 annee_dernier_exercice, part_benefice_recue)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$societe_id, $annee_n, $denomination, $localisation, $val_acq,
                          $pct, $cap_propres, $resultat, $annee_fil, $part_benef]);
        } else {
            $id = (int)$_POST['id'];
            $st = $db->prepare("UPDATE note_4_participations SET
                denomination=?, localisation=?, valeur_acquisition=?,
                pourcentage=?, capitaux_propres=?, resultat_dernier_exercice=?,
                annee_dernier_exercice=?, part_benefice_recue=?
                WHERE id=? AND societe_id=?");
            $st->execute([$denomination, $localisation, $val_acq,
                          $pct, $cap_propres, $resultat, $annee_fil, $part_benef,
                          $id, $societe_id]);
        }
        header("Location: note_4_immos_financieres.php?$qs");
        exit;
    }
}

if ($action === 'delete') {
    $id = (int)$_POST['id'];
    $db->prepare("DELETE FROM note_4_participations WHERE id=? AND societe_id=?")->execute([$id, $societe_id]);
    header("Location: note_4_immos_financieres.php?$qs");
    exit;
}

// ── Lecture participations ────────────────────────────────────────────────────
$participations = $db->prepare("SELECT * FROM note_4_participations
    WHERE societe_id=? AND exercice=? ORDER BY denomination");
$participations->execute([$societe_id, $annee_n]);
$participations = $participations->fetchAll(PDO::FETCH_ASSOC);

// Ligne en cours d'édition
$edit_row = null;
if ($edit_id) {
    foreach ($participations as $p) {
        if ($p['id'] == $edit_id) { $edit_row = $p; break; }
    }
}

// ── Requête balance (comptes 26, 27x, 296, 297) ───────────────────────────────
$sql = "
    SELECT pc.compte,
        COALESCE(SUM(CASE WHEN e.date_ecriture < ?  AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS debit_avant,
        COALESCE(SUM(CASE WHEN e.date_ecriture < ?  AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS credit_avant,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS debit_n,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS credit_n
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON le.compte = pc.compte AND le.societe_id = pc.societe_id
    LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut='Validé' AND e.societe_id = pc.societe_id
    WHERE pc.actif='Oui' AND pc.societe_id = ?
    GROUP BY pc.compte
    ORDER BY pc.compte
";
$stmt = $db->prepare($sql);
$stmt->execute([$date_debut_n, $date_debut_n,
                $date_debut_n, $date_fin_n,
                $date_debut_n, $date_fin_n,
                $societe_id]);
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers balance ───────────────────────────────────────────────────────────
if (!function_exists('matchPx')) {
    function matchPx(string $s, array $pfx): bool {
        foreach ($pfx as $p) { if (str_starts_with($s, $p)) return true; }
        return false;
    }
}
function brutOuvF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r['debit_avant'] - (float)$r['credit_avant'];
    }
    return max(0.0, $t);
}
function acqF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r['debit_n'];
    }
    return $t;
}
function sorF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r['credit_n'];
    }
    return $t;
}
function brutCloF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += ((float)$r['debit_avant'] + (float)$r['debit_n'])
            - ((float)$r['credit_avant'] + (float)$r['credit_n']);
    }
    return max(0.0, $t);
}
function depOuvF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r['credit_avant'] - (float)$r['debit_avant'];
    }
    return max(0.0, $t);
}
function dotF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r['credit_n'];
    }
    return $t;
}
function reprF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += (float)$r['debit_n'];
    }
    return $t;
}
function depCloF(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r) {
        if (!matchPx((string)$r['compte'], $pfx)) continue;
        $t += ((float)$r['credit_avant'] + (float)$r['credit_n'])
            - ((float)$r['debit_avant'] + (float)$r['debit_n']);
    }
    return max(0.0, $t);
}
function nfN($n) {
    if (abs((float)$n) < 0.5) return '';
    return number_format((float)$n, 0, ',', ' ');
}
function nfP($n) { // pourcentage
    if (abs((float)$n) < 0.001) return '';
    return number_format((float)$n, 3, ',', ' ') . ' %';
}

// ── Rubriques ─────────────────────────────────────────────────────────────────
$rubriques = [
    ['label' => 'Titres de participation',                                       'pfx' => ['26'],  'dep' => ['296']],
    ['label' => 'Prêts au personnel',                                            'pfx' => ['272'], 'dep' => ['297']],
    ['label' => 'Créances sur l\'État',                                          'pfx' => ['273'], 'dep' => ['297']],
    ['label' => 'Titres immobilisés',                                            'pfx' => ['274'], 'dep' => ['297']],
    ['label' => 'Dépôts et cautionnements versés',                               'pfx' => ['275'], 'dep' => ['297']],
    ['label' => 'Prêts et créances',                                             'pfx' => ['276'], 'dep' => ['297']],
    ['label' => 'Créances rattachées à des participations et avances à des GIE', 'pfx' => ['277'], 'dep' => ['297']],
    ['label' => 'Immobilisations financières diverses',                          'pfx' => ['278'], 'dep' => ['297']],
];
$pfx_brut = ['26','272','273','274','275','276','277','278'];
$pfx_dep  = ['296','297'];

$tot_brut_ouv = brutOuvF($comptes, $pfx_brut);
$tot_acq      = acqF($comptes, $pfx_brut);
$tot_sor      = sorF($comptes, $pfx_brut);
$tot_brut_clo = brutCloF($comptes, $pfx_brut);

$dep_titres_ouv = depOuvF($comptes, ['296']); $dep_titres_dot = dotF($comptes, ['296']);
$dep_titres_rep = reprF($comptes, ['296']);   $dep_titres_clo = depCloF($comptes, ['296']);
$dep_autres_ouv = depOuvF($comptes, ['297']); $dep_autres_dot = dotF($comptes, ['297']);
$dep_autres_rep = reprF($comptes, ['297']);   $dep_autres_clo = depCloF($comptes, ['297']);

$tot_dep_ouv = $dep_titres_ouv + $dep_autres_ouv;
$tot_dep_dot = $dep_titres_dot + $dep_autres_dot;
$tot_dep_rep = $dep_titres_rep + $dep_autres_rep;
$tot_dep_clo = $dep_titres_clo + $dep_autres_clo;

$vn_n  = $tot_brut_clo - $tot_dep_clo;
$vn_n1 = $tot_brut_ouv - $tot_dep_ouv;

// Totaux participations manuelles
$tot_val_acq    = array_sum(array_column($participations, 'valeur_acquisition'));
$tot_cap_prop   = array_sum(array_column($participations, 'capitaux_propres'));
$tot_part_benef = array_sum(array_column($participations, 'part_benefice_recue'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Note 4 — Immobilisations financières <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .note-table th, .note-table td { border: 1px solid #d1d5db !important; color: black !important; background: white !important; font-size: 9px !important; }
            .row-section td  { background: #e5e7eb !important; color: #111 !important; }
            .row-subtotal td { background: #f3f4f6 !important; }
        }
        .note-table th { font-size: 10px; white-space: nowrap; }
        .note-table td { font-size: 11px; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .row-section td  { background: #1e3a5f; color: #93c5fd; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .row-subtotal td { background: #1e293b; color: #7dd3fc; font-weight: 600; border-top: 1px solid #334155; }
        .row-grand td    { background: #0f172a; color: #34d399; font-weight: 700; border-top: 2px solid #10b981; }
        .row-data:hover td { background: rgba(255,255,255,0.03); }
        .section-card { border-left: 3px solid; }
        input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-400 mb-1">
                <i class="fas fa-chart-pie mr-3"></i>Note 4 — Immobilisations financières
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
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Fin N</label>
                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin_n) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500">
            </div>
            <button type="submit"
                    class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Afficher
            </button>
            <a href="note_4_immos_financieres.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-redo"></i> Réinit.
            </a>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════════════════════════════════
         SECTION I — TABLEAU MANUEL DES PARTICIPATIONS
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="mb-8">
        <h2 class="text-base font-bold text-slate-200 mb-4 flex items-center gap-2">
            <span class="w-7 h-7 rounded-full bg-violet-500/20 flex items-center justify-center text-violet-400 font-bold text-xs">I</span>
            État des filiales et participations — Exercice <?= $annee_n ?>
        </h2>

        <div class="xl:grid xl:grid-cols-3 xl:gap-6 space-y-6 xl:space-y-0">

            <!-- Formulaire CRUD -->
            <div class="xl:col-span-1 no-print">
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 sticky top-6">
                    <h3 class="text-sm font-bold text-slate-200 mb-4 flex items-center gap-2">
                        <i class="fas fa-<?= $edit_row ? 'edit text-amber-400' : 'plus-circle text-cyan-400' ?>"></i>
                        <?= $edit_row ? 'Modifier la participation' : 'Ajouter une participation' ?>
                    </h3>

                    <?php if (!empty($errors)): ?>
                    <div class="mb-4 p-3 bg-rose-900/40 border border-rose-700 rounded-lg text-rose-300 text-xs">
                        <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="note_4_immos_financieres.php?<?= $qs ?><?= $edit_row ? '&edit='.$edit_row['id'] : '' ?>" class="space-y-3">
                        <input type="hidden" name="action" value="<?= $edit_row ? 'edit' : 'add' ?>">
                        <?php if ($edit_row): ?>
                        <input type="hidden" name="id" value="<?= $edit_row['id'] ?>">
                        <?php endif; ?>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Dénomination sociale *</label>
                            <input type="text" name="denomination" required
                                   value="<?= htmlspecialchars($edit_row['denomination'] ?? '') ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="Nom de la société">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Localisation (ville / pays)</label>
                            <input type="text" name="localisation"
                                   value="<?= htmlspecialchars($edit_row['localisation'] ?? '') ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="Ex : Abidjan / Côte d'Ivoire">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Valeur d'acquisition (FCFA)</label>
                            <input type="number" name="valeur_acquisition" step="1" min="0"
                                   value="<?= $edit_row ? (float)$edit_row['valeur_acquisition'] : '' ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="0">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">% Détenu</label>
                            <input type="number" name="pourcentage" step="0.001" min="0" max="100"
                                   value="<?= $edit_row ? (float)$edit_row['pourcentage'] : '' ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="0.000">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Capitaux propres filiale (FCFA)</label>
                            <input type="number" name="capitaux_propres" step="1"
                                   value="<?= $edit_row ? (float)$edit_row['capitaux_propres'] : '' ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="0">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Résultat dernier exercice (1) (FCFA)</label>
                            <input type="number" name="resultat_dernier_exercice" step="1"
                                   value="<?= $edit_row ? (float)$edit_row['resultat_dernier_exercice'] : '' ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="0 (négatif si perte)">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Année du dernier exercice filiale</label>
                            <input type="number" name="annee_dernier_exercice" min="2000" max="2099"
                                   value="<?= $edit_row ? ($edit_row['annee_dernier_exercice'] ?? '') : '' ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="<?= $annee_n ?>">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Part de bénéfice reçue (2) (FCFA)</label>
                            <input type="number" name="part_benefice_recue" step="1" min="0"
                                   value="<?= $edit_row ? (float)$edit_row['part_benefice_recue'] : '' ?>"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-cyan-500"
                                   placeholder="0">
                        </div>

                        <div class="flex gap-2 pt-1">
                            <button type="submit"
                                    class="flex-1 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg text-sm font-medium inline-flex items-center justify-center gap-2">
                                <i class="fas fa-<?= $edit_row ? 'save' : 'plus' ?>"></i>
                                <?= $edit_row ? 'Enregistrer' : 'Ajouter' ?>
                            </button>
                            <?php if ($edit_row): ?>
                            <a href="note_4_immos_financieres.php?<?= $qs ?>"
                               class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm inline-flex items-center gap-2">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <p class="text-xs text-slate-600 mt-4">
                        (1) Résultat net du dernier exercice connu de la filiale<br>
                        (2) Dividendes et parts de bénéfice effectivement reçus au cours de l'exercice N
                    </p>
                </div>
            </div>

            <!-- Tableau des participations -->
            <div class="xl:col-span-2">
                <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-700 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-slate-200">Liste des participations — <?= $annee_n ?></p>
                            <p class="text-xs text-slate-500 mt-0.5"><?= count($participations) ?> entrée(s) enregistrée(s)</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="note-table w-full border-collapse">
                            <thead>
                                <tr class="bg-slate-900 border-b border-slate-700 text-slate-400">
                                    <th class="text-left px-4 py-2" style="min-width:160px">Dénomination</th>
                                    <th class="text-left px-3 py-2">Localisation</th>
                                    <th class="cell-num px-3 py-2 text-sky-300">Val. acquisition</th>
                                    <th class="cell-num px-3 py-2">% Détenu</th>
                                    <th class="cell-num px-3 py-2">Cap. propres</th>
                                    <th class="cell-num px-3 py-2">Résultat (1)</th>
                                    <th class="cell-num px-3 py-2">Année</th>
                                    <th class="cell-num px-3 py-2 text-emerald-400">Bénéfice (2)</th>
                                    <th class="px-3 py-2 no-print"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($participations)): ?>
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-600 text-sm">
                                        <i class="fas fa-building mr-2"></i>Aucune participation enregistrée pour l'exercice <?= $annee_n ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($participations as $p):
                                    $res = (float)$p['resultat_dernier_exercice'];
                                    $resColor = $res < 0 ? 'text-rose-400' : 'text-slate-300';
                                ?>
                                <tr class="row-data border-b border-slate-700/50 <?= $p['id']==$edit_id ? 'bg-cyan-900/20' : '' ?>">
                                    <td class="px-4 py-2 text-slate-200 font-medium"><?= htmlspecialchars($p['denomination']) ?></td>
                                    <td class="px-3 py-2 text-slate-400 text-xs"><?= htmlspecialchars($p['localisation']) ?></td>
                                    <td class="cell-num px-3 py-2 text-sky-300"><?= nfN($p['valeur_acquisition']) ?></td>
                                    <td class="cell-num px-3 py-2 text-slate-300"><?= nfP($p['pourcentage']) ?></td>
                                    <td class="cell-num px-3 py-2 text-slate-300"><?= nfN($p['capitaux_propres']) ?></td>
                                    <td class="cell-num px-3 py-2 <?= $resColor ?>"><?= nfN($res) ?></td>
                                    <td class="cell-num px-3 py-2 text-slate-400"><?= $p['annee_dernier_exercice'] ?? '—' ?></td>
                                    <td class="cell-num px-3 py-2 text-emerald-400"><?= nfN($p['part_benefice_recue']) ?></td>
                                    <td class="px-3 py-2 no-print">
                                        <div class="flex gap-1">
                                            <a href="note_4_immos_financieres.php?<?= $qs ?>&edit=<?= $p['id'] ?>"
                                               class="w-7 h-7 flex items-center justify-center rounded bg-amber-500/20 hover:bg-amber-500/40 text-amber-400 transition-colors">
                                                <i class="fas fa-edit text-xs"></i>
                                            </a>
                                            <form method="POST" action="note_4_immos_financieres.php?<?= $qs ?>"
                                                  onsubmit="return confirm('Supprimer cette participation ?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                <button type="submit"
                                                        class="w-7 h-7 flex items-center justify-center rounded bg-rose-500/20 hover:bg-rose-500/40 text-rose-400 transition-colors">
                                                    <i class="fas fa-trash text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <!-- Totaux -->
                                <tr class="row-subtotal">
                                    <td class="px-4 py-2" colspan="2">TOTAL</td>
                                    <td class="cell-num px-3 py-2 text-sky-300"><?= nfN($tot_val_acq) ?></td>
                                    <td></td>
                                    <td class="cell-num px-3 py-2 text-slate-300"><?= nfN($tot_cap_prop) ?></td>
                                    <td></td>
                                    <td></td>
                                    <td class="cell-num px-3 py-2 text-emerald-400"><?= nfN($tot_part_benef) ?></td>
                                    <td class="no-print"></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         SECTION II — MOUVEMENTS (BALANCE AUTOMATIQUE)
    ═══════════════════════════════════════════════════════════════════════ -->
    <h2 class="text-base font-bold text-slate-200 mb-4 flex items-center gap-2">
        <span class="w-7 h-7 rounded-full bg-sky-500/20 flex items-center justify-center text-sky-400 font-bold text-xs">II</span>
        Mouvements comptables — Balance automatique
    </h2>

    <!-- TABLE A — VALEURS BRUTES -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden mb-6 section-card border-l-cyan-500">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-7 h-7 rounded-full bg-cyan-500/20 flex items-center justify-center text-cyan-400 font-bold text-xs">A</span>
            <div>
                <h3 class="text-sm font-bold text-slate-100">Valeurs brutes (en FCFA)</h3>
                <p class="text-xs text-slate-400 mt-0.5">Mouvements de l'exercice <?= $annee_n ?></p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300" style="min-width:260px">Intitulé</th>
                        <th class="cell-num px-4 py-3 text-slate-400">Début exercice<br><span class="font-normal text-slate-500">01/01/<?= $annee_n ?></span></th>
                        <th class="cell-num px-4 py-3 text-emerald-400">+ Acquisitions<br><span class="font-normal text-slate-500">exercice N</span></th>
                        <th class="cell-num px-4 py-3 text-rose-400">− Sorties<br><span class="font-normal text-slate-500">exercice N</span></th>
                        <th class="cell-num px-4 py-3 text-sky-300">Fin exercice<br><span class="font-normal text-slate-500">31/12/<?= $annee_n ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-section"><td colspan="5" class="px-5 py-2">TITRES DE PARTICIPATION</td></tr>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8">Titres de participation</td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN(brutOuvF($comptes, ['26'])) ?></td>
                        <td class="cell-num px-4 py-2 text-emerald-400"><?= nfN(acqF($comptes, ['26'])) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN(sorF($comptes, ['26'])) ?></td>
                        <td class="cell-num px-4 py-2 text-sky-300"><?= nfN(brutCloF($comptes, ['26'])) ?></td>
                    </tr>
                    <tr class="row-section"><td colspan="5" class="px-5 py-2">AUTRES IMMOBILISATIONS FINANCIÈRES</td></tr>
                    <?php foreach ($rubriques as $rub):
                        if ($rub['pfx'] === ['26']) continue;
                    ?>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8"><?= htmlspecialchars($rub['label']) ?></td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN(brutOuvF($comptes, $rub['pfx'])) ?></td>
                        <td class="cell-num px-4 py-2 text-emerald-400"><?= nfN(acqF($comptes, $rub['pfx'])) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN(sorF($comptes, $rub['pfx'])) ?></td>
                        <td class="cell-num px-4 py-2 text-sky-300"><?= nfN(brutCloF($comptes, $rub['pfx'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="row-subtotal">
                        <td class="px-5 py-2">TOTAL BRUT IMMOBILISATIONS FINANCIÈRES</td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN($tot_brut_ouv) ?></td>
                        <td class="cell-num px-4 py-2 text-emerald-400"><?= nfN($tot_acq) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN($tot_sor) ?></td>
                        <td class="cell-num px-4 py-2 text-sky-300"><?= nfN($tot_brut_clo) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABLE B — DÉPRÉCIATIONS -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden mb-6 section-card border-l-amber-500">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-7 h-7 rounded-full bg-amber-500/20 flex items-center justify-center text-amber-400 font-bold text-xs">B</span>
            <div>
                <h3 class="text-sm font-bold text-slate-100">Dépréciations (en FCFA)</h3>
                <p class="text-xs text-slate-400 mt-0.5">Comptes 296 et 297 — exercice <?= $annee_n ?></p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300" style="min-width:260px">Intitulé</th>
                        <th class="cell-num px-4 py-3 text-slate-400">Début exercice</th>
                        <th class="cell-num px-4 py-3 text-rose-400">+ Dotations</th>
                        <th class="cell-num px-4 py-3 text-emerald-400">− Reprises</th>
                        <th class="cell-num px-4 py-3 text-amber-300">Fin exercice</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-section"><td colspan="5" class="px-5 py-2">DÉPRÉCIATIONS DES IMMOBILISATIONS FINANCIÈRES</td></tr>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8">Dépréciations des titres de participation (296)</td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN($dep_titres_ouv) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN($dep_titres_dot) ?></td>
                        <td class="cell-num px-4 py-2 text-emerald-400"><?= nfN($dep_titres_rep) ?></td>
                        <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($dep_titres_clo) ?></td>
                    </tr>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8">Dépréciations des autres immobilisations financières (297)</td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN($dep_autres_ouv) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN($dep_autres_dot) ?></td>
                        <td class="cell-num px-4 py-2 text-emerald-400"><?= nfN($dep_autres_rep) ?></td>
                        <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($dep_autres_clo) ?></td>
                    </tr>
                    <tr class="row-subtotal">
                        <td class="px-5 py-2">TOTAL DÉPRÉCIATIONS</td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN($tot_dep_ouv) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN($tot_dep_dot) ?></td>
                        <td class="cell-num px-4 py-2 text-emerald-400"><?= nfN($tot_dep_rep) ?></td>
                        <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($tot_dep_clo) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABLE C — VALEURS NETTES -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden mb-4 section-card border-l-emerald-500">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-7 h-7 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 font-bold text-xs">C</span>
            <div>
                <h3 class="text-sm font-bold text-slate-100">Valeurs nettes comparées (en FCFA)</h3>
                <p class="text-xs text-slate-400 mt-0.5">N / N-1</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="note-table w-full border-collapse">
                <thead>
                    <tr class="bg-slate-900 border-b border-slate-700">
                        <th class="text-left px-5 py-3 text-slate-300" style="min-width:260px">Intitulé</th>
                        <th class="cell-num px-4 py-3 text-sky-300">Valeur nette N<br><span class="font-normal text-slate-500">31/12/<?= $annee_n ?></span></th>
                        <th class="cell-num px-4 py-3 text-slate-400">Valeur nette N-1<br><span class="font-normal text-slate-500">31/12/<?= $annee_n1 ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rubriques as $rub): ?>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8"><?= htmlspecialchars($rub['label']) ?></td>
                        <td class="cell-num px-4 py-2 text-slate-200"><?= nfN(brutCloF($comptes, $rub['pfx'])) ?></td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN(brutOuvF($comptes, $rub['pfx'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="row-subtotal">
                        <td class="px-5 py-2">TOTAL BRUT</td>
                        <td class="cell-num px-4 py-2 text-sky-300"><?= nfN($tot_brut_clo) ?></td>
                        <td class="cell-num px-4 py-2 text-slate-400"><?= nfN($tot_brut_ouv) ?></td>
                    </tr>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8">(−) Dépréciations des titres de participation (296)</td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN($dep_titres_clo) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400/60"><?= nfN($dep_titres_ouv) ?></td>
                    </tr>
                    <tr class="row-data border-b border-slate-700/50">
                        <td class="px-5 py-2 text-slate-300 pl-8">(−) Dépréciations des autres immobilisations financières (297)</td>
                        <td class="cell-num px-4 py-2 text-rose-400"><?= nfN($dep_autres_clo) ?></td>
                        <td class="cell-num px-4 py-2 text-rose-400/60"><?= nfN($dep_autres_ouv) ?></td>
                    </tr>
                    <tr class="row-grand">
                        <td class="px-5 py-3">VALEUR NETTE TOTALE DES IMMOBILISATIONS FINANCIÈRES</td>
                        <td class="cell-num px-4 py-3 text-emerald-400"><?= nfN($vn_n) ?></td>
                        <td class="cell-num px-4 py-3 text-emerald-400/70"><?= nfN($vn_n1) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-slate-600 mt-3 no-print">
        Source : Plan Comptable SYSCOHADA — Liasse DGI Système Normal, Note 4 — Balance comptable (comptes 26, 272-278, 296-297)
    </p>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
