<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

// ── Création des tables ───────────────────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS note_3e_lignes (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        societe_id          INT NOT NULL,
        exercice            YEAR NOT NULL,
        poste_bilan         VARCHAR(200) NOT NULL,
        cout_historique     DECIMAL(15,2) DEFAULT 0,
        amort_supplementaire DECIMAL(15,2) DEFAULT 0,
        cessions_rembours   DECIMAL(15,2) DEFAULT 0,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_soc_ex (societe_id, exercice)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$db->exec("
    CREATE TABLE IF NOT EXISTS note_3e_textes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        societe_id  INT NOT NULL,
        exercice    YEAR NOT NULL,
        cle         VARCHAR(30) NOT NULL,
        contenu     TEXT,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_soc_ex_cle (societe_id, exercice, cle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin   = $_GET['date_fin']   ?? date('Y-12-31');
$annee_n    = (int)date('Y', strtotime($date_fin));
$qs         = http_build_query(['date_debut' => $date_debut, 'date_fin' => $date_fin]);
$editRow    = null;

// ── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Sauvegarde des textes narratifs
    if ($action === 'save_textes') {
        $stmt = $db->prepare("
            INSERT INTO note_3e_textes (societe_id, exercice, cle, contenu)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE contenu=VALUES(contenu), updated_at=NOW()
        ");
        foreach (['contexte', 'methode', 'traitement_fiscal'] as $cle) {
            $stmt->execute([$societe_id, $annee_n, $cle, trim($_POST[$cle] ?? '') ?: null]);
        }
        $_SESSION['note3e_msg'] = 'Textes sauvegardés.';
        header("Location: note_3e_reevaluations.php?$qs"); exit;
    }

    // Ajout / modification d'une ligne du tableau
    if ($action === 'add' || $action === 'edit') {
        $poste = trim($_POST['poste_bilan'] ?? '');
        $ch    = (float)str_replace([' ',','],['','.'], $_POST['cout_historique'] ?? 0);
        $as    = (float)str_replace([' ',','],['','.'], $_POST['amort_supplementaire'] ?? 0);
        $cr    = (float)str_replace([' ',','],['','.'], $_POST['cessions_rembours'] ?? 0);
        if ($poste) {
            if ($action === 'add') {
                $db->prepare("INSERT INTO note_3e_lignes (societe_id,exercice,poste_bilan,cout_historique,amort_supplementaire,cessions_rembours) VALUES (?,?,?,?,?,?)")
                   ->execute([$societe_id,$annee_n,$poste,$ch,$as,$cr]);
            } else {
                $db->prepare("UPDATE note_3e_lignes SET poste_bilan=?,cout_historique=?,amort_supplementaire=?,cessions_rembours=? WHERE id=? AND societe_id=?")
                   ->execute([$poste,$ch,$as,$cr,(int)$_POST['id'],$societe_id]);
            }
        }
        $_SESSION['note3e_msg'] = $action === 'add' ? 'Ligne ajoutée.' : 'Ligne modifiée.';
        header("Location: note_3e_reevaluations.php?$qs"); exit;
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM note_3e_lignes WHERE id=? AND societe_id=?")->execute([(int)$_POST['id'],$societe_id]);
        $_SESSION['note3e_msg'] = 'Ligne supprimée.';
        header("Location: note_3e_reevaluations.php?$qs"); exit;
    }
}

$message = $_SESSION['note3e_msg'] ?? ''; unset($_SESSION['note3e_msg']);

if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM note_3e_lignes WHERE id=? AND societe_id=?");
    $s->execute([(int)$_GET['edit'], $societe_id]);
    $editRow = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Données ───────────────────────────────────────────────────────────────────
$lignes = $db->prepare("SELECT * FROM note_3e_lignes WHERE societe_id=? AND exercice=? ORDER BY id");
$lignes->execute([$societe_id, $annee_n]);
$lignes = $lignes->fetchAll(PDO::FETCH_ASSOC);

$totaux = ['cout_historique'=>0.0,'amort_supplementaire'=>0.0,'cessions_rembours'=>0.0];
foreach ($lignes as $l) {
    $totaux['cout_historique']      += (float)$l['cout_historique'];
    $totaux['amort_supplementaire'] += (float)$l['amort_supplementaire'];
    $totaux['cessions_rembours']    += (float)$l['cessions_rembours'];
}

$textes = $db->prepare("SELECT cle,contenu FROM note_3e_textes WHERE societe_id=? AND exercice=?");
$textes->execute([$societe_id, $annee_n]);
$txt = [];
foreach ($textes->fetchAll(PDO::FETCH_ASSOC) as $r) $txt[$r['cle']] = $r['contenu'];

function nfN($n) { return abs((float)$n)<0.5 ? '—' : number_format((float)$n,0,',',' '); }
function hsc($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Note 3E — Réévaluations <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .note-table th, .note-table td { border: 1px solid #d1d5db !important; color: black !important; background: white !important; }
            .note-table tfoot td { background: #f3f4f6 !important; font-weight: bold; }
            .print-section { border: 1px solid #d1d5db; padding: 12px; margin-top: 16px; }
            .print-section h3 { font-weight: bold; margin-bottom: 8px; }
        }
        .note-table th { font-size: 11px; white-space: nowrap; }
        .note-table td { font-size: 12px; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-teal-400 to-cyan-400 mb-1">
                <i class="fas fa-balance-scale mr-3"></i>Note 3E — Informations sur les réévaluations
            </h1>
            <p class="text-slate-400 text-sm">Exercice <?= $annee_n ?> — Réévaluations effectuées par l'entité</p>
        </div>
        <div class="flex gap-2 no-print">
            <a href="notes_annexes.php?<?= $qs ?>"
               class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Toutes les notes
            </a>
            <button onclick="window.print()"
                    class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-print"></i> Imprimer
            </button>
        </div>
    </div>

    <!-- Filtre période -->
    <form method="GET" class="no-print mb-6 bg-slate-800 border border-slate-700 rounded-xl p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Début N</label>
                <input type="date" name="date_debut" value="<?= hsc($date_debut) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Fin N</label>
                <input type="date" name="date_fin" value="<?= hsc($date_fin) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
            </div>
            <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Afficher
            </button>
        </div>
    </form>

    <?php if ($message): ?>
    <div class="no-print mb-4 px-4 py-3 bg-emerald-900/30 border border-emerald-700 text-emerald-300 rounded-lg text-sm">
        <i class="fas fa-check-circle mr-2"></i><?= hsc($message) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">

        <!-- ── Formulaire ligne ─────────────────────────────────── -->
        <div class="no-print xl:col-span-1">
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 sticky top-4">
                <h2 class="text-sm font-bold text-teal-400 mb-4 flex items-center gap-2">
                    <i class="fas fa-<?= $editRow ? 'edit' : 'plus-circle' ?>"></i>
                    <?= $editRow ? 'Modifier la ligne' : 'Ajouter un élément réévalué' ?>
                </h2>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                    <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
                    <?php endif; ?>

                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Poste du bilan réévalué *</label>
                        <input type="text" name="poste_bilan"
                               value="<?= hsc($editRow['poste_bilan'] ?? '') ?>"
                               placeholder="Ex. Terrains, Bâtiments, Matériel..."
                               class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Montants en coûts historiques (FCFA)</label>
                        <input type="number" name="cout_historique" step="1"
                               value="<?= $editRow ? (float)$editRow['cout_historique'] : '' ?>"
                               placeholder="0"
                               class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Amortissements supplémentaires (FCFA)</label>
                        <input type="number" name="amort_supplementaire" step="1"
                               value="<?= $editRow ? (float)$editRow['amort_supplementaire'] : '' ?>"
                               placeholder="0"
                               class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Cessions / Remboursements en cours d'exercice (FCFA)</label>
                        <input type="number" name="cessions_rembours" step="1"
                               value="<?= $editRow ? (float)$editRow['cessions_rembours'] : '' ?>"
                               placeholder="0"
                               class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-gradient-to-r from-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800 text-white rounded-lg text-sm font-medium inline-flex items-center justify-center gap-2">
                            <i class="fas fa-<?= $editRow ? 'save' : 'plus' ?>"></i>
                            <?= $editRow ? 'Enregistrer' : 'Ajouter' ?>
                        </button>
                        <?php if ($editRow): ?>
                        <a href="note_3e_reevaluations.php?<?= $qs ?>"
                           class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm inline-flex items-center gap-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Tableau ──────────────────────────────────────────── -->
        <div class="xl:col-span-2">
            <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700">
                    <h2 class="text-sm font-bold text-slate-100">
                        <i class="fas fa-table mr-2 text-teal-400"></i>
                        Éléments réévalués par postes du bilan — Exercice <?= $annee_n ?> (en FCFA)
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="note-table w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-900 border-b border-slate-700">
                                <th class="text-left px-4 py-2.5 text-slate-300">Éléments réévalués</th>
                                <th class="cell-num px-4 py-2.5 text-sky-300">Montants en<br>coûts historiques</th>
                                <th class="cell-num px-4 py-2.5 text-amber-300">Amortissements<br>supplémentaires</th>
                                <th class="cell-num px-4 py-2.5 text-rose-300">Cessions /<br>Remboursements</th>
                                <th class="text-center px-3 py-2.5 text-slate-400 text-xs no-print w-20">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($lignes)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-slate-500">
                                    <i class="fas fa-balance-scale text-3xl mb-3 block text-slate-700"></i>
                                    Aucun élément réévalué pour l'exercice <?= $annee_n ?><br>
                                    <span class="text-xs">Si l'entité n'a effectué aucune réévaluation, cette note peut rester vide.</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lignes as $l): ?>
                            <tr class="border-b border-slate-700/40 hover:bg-slate-700/20 transition-colors">
                                <td class="px-4 py-2 text-slate-200 font-medium"><?= hsc($l['poste_bilan']) ?></td>
                                <td class="cell-num px-4 py-2 text-sky-300"><?= nfN($l['cout_historique']) ?></td>
                                <td class="cell-num px-4 py-2 text-amber-300"><?= nfN($l['amort_supplementaire']) ?></td>
                                <td class="cell-num px-4 py-2 text-rose-300"><?= nfN($l['cessions_rembours']) ?></td>
                                <td class="text-center px-3 py-2 no-print">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="?<?= $qs ?>&edit=<?= $l['id'] ?>"
                                           class="p-1.5 bg-teal-900/40 hover:bg-teal-700/60 text-teal-300 rounded transition-colors" title="Modifier">
                                            <i class="fas fa-edit text-xs"></i>
                                        </a>
                                        <form method="POST" onsubmit="return confirm('Supprimer cette ligne ?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <button type="submit"
                                                    class="p-1.5 bg-red-900/40 hover:bg-red-700/60 text-red-300 rounded transition-colors" title="Supprimer">
                                                <i class="fas fa-trash text-xs"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($lignes)): ?>
                        <tfoot>
                            <tr class="bg-slate-900 border-t-2 border-teal-700/50">
                                <td class="px-4 py-2.5 text-teal-300 font-bold text-sm">TOTAL GÉNÉRAL</td>
                                <td class="cell-num px-4 py-2.5 text-sky-300 font-bold"><?= number_format($totaux['cout_historique'],0,',',' ') ?></td>
                                <td class="cell-num px-4 py-2.5 text-amber-300 font-bold"><?= number_format($totaux['amort_supplementaire'],0,',',' ') ?></td>
                                <td class="cell-num px-4 py-2.5 text-rose-300 font-bold"><?= number_format($totaux['cessions_rembours'],0,',',' ') ?></td>
                                <td class="no-print"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Sections narratives ────────────────────────────────────────────── -->
    <form method="POST">
        <input type="hidden" name="action" value="save_textes">

        <div class="space-y-4 mb-6">
            <!-- Contexte -->
            <div class="print-section bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700">
                    <h3 class="text-sm font-bold text-teal-300">
                        <i class="fas fa-info-circle mr-2"></i>Contexte des réévaluations
                    </h3>
                </div>
                <div class="p-5">
                    <textarea name="contexte" rows="4"
                              placeholder="Décrire le contexte ayant conduit aux réévaluations (réévaluation légale, libre, date de la décision, autorité ayant autorisé la réévaluation...)&#10;Laisser vide si l'entité n'a effectué aucune réévaluation."
                              class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-teal-500"><?= hsc($txt['contexte'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Méthode -->
            <div class="print-section bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700">
                    <h3 class="text-sm font-bold text-teal-300">
                        <i class="fas fa-cogs mr-2"></i>Méthode de réévaluation utilisée
                    </h3>
                </div>
                <div class="p-5">
                    <textarea name="methode" rows="4"
                              placeholder="Décrire la méthode de réévaluation appliquée (valeur vénale, valeur d'utilité, indice de prix, expertise indépendante...). Préciser les hypothèses retenues et les dates d'évaluation."
                              class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-teal-500"><?= hsc($txt['methode'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Traitement fiscal -->
            <div class="print-section bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700">
                    <h3 class="text-sm font-bold text-teal-300">
                        <i class="fas fa-landmark mr-2"></i>Traitement fiscal de l'écart de réévaluation et des amortissements supplémentaires
                    </h3>
                </div>
                <div class="p-5">
                    <textarea name="traitement_fiscal" rows="4"
                              placeholder="Préciser le traitement fiscal appliqué à l'écart de réévaluation (imposable, non imposable, incorporation au capital...) et aux amortissements supplémentaires calculés sur la valeur réévaluée."
                              class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-teal-500"><?= hsc($txt['traitement_fiscal'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="no-print flex justify-end">
            <button type="submit"
                    class="px-6 py-2.5 bg-gradient-to-r from-teal-600 to-cyan-600 hover:from-teal-700 hover:to-cyan-700 text-white rounded-lg text-sm font-semibold shadow-lg inline-flex items-center gap-2">
                <i class="fas fa-save"></i> Enregistrer les textes
            </button>
        </div>
    </form>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
