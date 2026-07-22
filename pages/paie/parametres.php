<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

try { $db->query("SELECT 1 FROM paie_parametres LIMIT 1"); }
catch (Exception $e) { header('Location: employes.php'); exit; }

$message = '';
$messageType = '';

// ── Sauvegarde paramètres ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_params') {
        $fields = [
            'cnps_salarie_taux', 'cnps_patronal_taux', 'cnps_plafond',
            'pf_taux', 'pf_plafond', 'am_taux', 'am_plafond',
            'at_taux', 'at_plafond',
            'cmu_salarie_c', 'cmu_salarie_m', 'cmu_salarie_enfant',
            'cmu_patronal_c', 'cmu_patronal_m', 'cmu_patronal_enfant',
            'ce_taux', 'cn_taux', 'ta_taux', 'fdfp_taux',
            'indemnite_transport_defaut', 'ricf_valeur_part',
        ];
        $sets = implode(', ', array_map(fn($f) => "$f=?", $fields));
        $vals = array_map(fn($f) => (float)str_replace(',', '.', $_POST[$f] ?? 0), $fields);
        $vals[] = $societe_id;
        $db->prepare("UPDATE paie_parametres SET $sets WHERE societe_id=?")->execute($vals);
        $message = 'Paramètres mis à jour.';
        $messageType = 'success';
    }

    if ($action === 'save_bareme') {
        // Supprimer et réinsérer les tranches
        $db->prepare("DELETE FROM paie_its_bareme WHERE societe_id=?")->execute([$societe_id]);
        $mins  = $_POST['tranche_min']  ?? [];
        $maxes = $_POST['tranche_max']  ?? [];
        $taux  = $_POST['taux']         ?? [];
        $ins = $db->prepare("INSERT INTO paie_its_bareme (societe_id, tranche_min, tranche_max, taux, ordre) VALUES (?,?,?,?,?)");
        foreach ($mins as $i => $min) {
            $max = ($maxes[$i] === '' || $maxes[$i] === null) ? null : (float)str_replace(' ', '', $maxes[$i]);
            $ins->execute([$societe_id, (float)str_replace(' ', '', $min), $max, (float)str_replace(',', '.', $taux[$i] ?? 0), $i+1]);
        }
        $message = 'Barème ITS mis à jour.';
        $messageType = 'success';
    }

    if ($action === 'add_tranche') {
        $ordre_max = $db->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM paie_its_bareme WHERE societe_id=?");
        $ordre_max->execute([$societe_id]);
        $next_ordre = $ordre_max->fetchColumn();
        $db->prepare("INSERT INTO paie_its_bareme (societe_id, tranche_min, tranche_max, taux, ordre) VALUES (?,?,?,?,?)")
           ->execute([$societe_id, 0, null, 0, $next_ordre]);
        $message = 'Tranche ajoutée.';
        $messageType = 'success';
    }

    if ($action === 'delete_tranche') {
        $tid = (int)($_POST['tranche_id'] ?? 0);
        $db->prepare("DELETE FROM paie_its_bareme WHERE id=? AND societe_id=?")->execute([$tid, $societe_id]);
        // Réordonner
        $reorder = $db->prepare("SELECT id FROM paie_its_bareme WHERE societe_id=? ORDER BY ordre");
        $reorder->execute([$societe_id]);
        $ids = $reorder->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $i => $rid) {
            $db->prepare("UPDATE paie_its_bareme SET ordre=? WHERE id=?")->execute([$i+1, $rid]);
        }
        $message = 'Tranche supprimée.';
        $messageType = 'success';
    }
}

// Charger
$params = $db->prepare("SELECT * FROM paie_parametres WHERE societe_id=?");
$params->execute([$societe_id]);
$p = $params->fetch(PDO::FETCH_ASSOC);

$bareme = $db->prepare("SELECT * FROM paie_its_bareme WHERE societe_id=? ORDER BY ordre");
$bareme->execute([$societe_id]);
$tranches = $bareme->fetchAll(PDO::FETCH_ASSOC);

function pct(float $v): string {
    return number_format($v * 100, 4, '.', '');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres Paie | <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
<?php include '../../includes/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0">
    <header class="h-12 bg-slate-800/50 border-b border-slate-700/50 flex items-center px-4 gap-3 flex-shrink-0">
        <div class="flex items-center gap-2 flex-1">
            <div class="w-6 h-6 bg-violet-500/20 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-sliders text-violet-400 text-xs"></i>
            </div>
            <h1 class="text-sm font-semibold text-white">Paramètres Paie</h1>
            <span class="text-slate-500 text-xs">/</span>
            <span class="text-slate-400 text-xs">Côte d'Ivoire</span>
        </div>
        <a href="index.php" class="px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
            <i class="fa-solid fa-arrow-left text-xs"></i> Retour
        </a>
    </header>

    <div class="flex-1 p-4 overflow-auto space-y-4">
        <?php if ($message): ?>
        <div class="px-4 py-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border border-red-500/20 text-red-400'; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?> mr-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <!-- Barème ITS -->
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-700/50 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <i class="fa-solid fa-receipt text-amber-400 text-xs"></i>
                        Barème ITS (Impôt sur Traitements et Salaires)
                    </h2>
                    <form method="post" class="inline">
                        <input type="hidden" name="action" value="add_tranche">
                        <button class="text-xs text-violet-400 hover:text-violet-300 transition flex items-center gap-1">
                            <i class="fa-solid fa-plus text-[10px]"></i> Ajouter tranche
                        </button>
                    </form>
                </div>
                <div class="p-4">
                    <p class="text-[10px] text-slate-500 mb-3">
                        Application : ITS brut calculé sur la base imposable, puis <strong class="text-slate-400">RICF soustrait</strong> pour obtenir l'ITS net.<br>
                        Les taux sont en <strong class="text-slate-400">pourcentage</strong> (ex: 16 pour 16%).
                    </p>
                    <form method="post">
                        <input type="hidden" name="action" value="save_bareme">
                        <div class="space-y-2">
                            <div class="grid grid-cols-12 gap-1.5 text-[10px] text-slate-500 px-1">
                                <div class="col-span-4">De (FCFA)</div>
                                <div class="col-span-4">À (FCFA — vide = illimité)</div>
                                <div class="col-span-3">Taux (%)</div>
                                <div class="col-span-1"></div>
                            </div>
                            <?php foreach ($tranches as $i => $t): ?>
                            <div class="grid grid-cols-12 gap-1.5 items-center">
                                <div class="col-span-4">
                                    <input type="number" name="tranche_min[]" value="<?php echo (int)$t['tranche_min']; ?>" min="0" step="1"
                                        class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                </div>
                                <div class="col-span-4">
                                    <input type="text" name="tranche_max[]" value="<?php echo $t['tranche_max'] ?? ''; ?>" placeholder="Sans limite"
                                        class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                </div>
                                <div class="col-span-3">
                                    <input type="number" name="taux[]" value="<?php echo number_format((float)$t['taux'] * 100, 2, '.', ''); ?>" min="0" max="100" step="0.01"
                                        class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                </div>
                                <div class="col-span-1 text-center">
                                    <form method="post" class="inline" onsubmit="return confirm('Supprimer cette tranche ?')">
                                        <input type="hidden" name="action" value="delete_tranche">
                                        <input type="hidden" name="tranche_id" value="<?php echo $t['id']; ?>">
                                        <button class="p-1 text-slate-500 hover:text-red-400 transition">
                                            <i class="fa-solid fa-xmark text-[10px]"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="mt-4 w-full px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition">
                            <i class="fa-solid fa-floppy-disk mr-1.5"></i>
                            Enregistrer le barème
                        </button>
                    </form>

                    <!-- Aperçu barème -->
                    <?php if (!empty($tranches)): ?>
                    <div class="mt-4 p-3 bg-slate-700/30 rounded-lg">
                        <p class="text-[10px] font-semibold text-slate-400 mb-2 uppercase tracking-wider">Aperçu barème actuel</p>
                        <div class="space-y-1">
                            <?php foreach ($tranches as $t):
                                $min = number_format($t['tranche_min'], 0, ',', ' ');
                                $max = $t['tranche_max'] ? number_format($t['tranche_max'], 0, ',', ' ') : '∞';
                                $taux_pct = number_format($t['taux'] * 100, 1);
                            ?>
                            <div class="flex items-center gap-2 text-[10px]">
                                <span class="text-slate-400 w-40"><?php echo $min; ?> → <?php echo $max; ?></span>
                                <span class="px-1.5 py-0.5 rounded <?php echo $t['taux'] == 0 ? 'bg-emerald-500/10 text-emerald-400' : ($t['taux'] > 0.2 ? 'bg-red-500/10 text-red-400' : 'bg-amber-500/10 text-amber-400'); ?>"><?php echo $taux_pct; ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Taux et plafonds CNPS/autres -->
            <div class="space-y-4">
                <form method="post">
                    <input type="hidden" name="action" value="save_params">

                    <!-- CNPS -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 mb-4">
                        <h3 class="text-xs font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-building-columns text-violet-400 text-[10px]"></i>
                            CNPS — Retraite
                        </h3>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Taux salarié (%)</label>
                                <input type="number" name="cnps_salarie_taux" value="<?php echo pct($p['cnps_salarie_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Taux patronal (%)</label>
                                <input type="number" name="cnps_patronal_taux" value="<?php echo pct($p['cnps_patronal_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Plafond mensuel (FCFA)</label>
                                <input type="number" name="cnps_plafond" value="<?php echo (int)$p['cnps_plafond']; ?>" min="0" step="1"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                        </div>

                        <h3 class="text-xs font-semibold text-white mt-3 mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-baby text-pink-400 text-[10px]"></i>
                            Prestations Familiales (PF)
                        </h3>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Taux (%)</label>
                                <input type="number" name="pf_taux" value="<?php echo pct($p['pf_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Plafond (FCFA)</label>
                                <input type="number" name="pf_plafond" value="<?php echo (int)$p['pf_plafond']; ?>" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                        </div>

                        <h3 class="text-xs font-semibold text-white mt-3 mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-blue-400 text-[10px]"></i>
                            AM (Accident Maladie) & AT (Accident Travail)
                        </h3>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">AM taux (%) / AT taux (%)</label>
                                <div class="flex gap-1">
                                    <input type="number" name="am_taux" value="<?php echo pct($p['am_taux']); ?>" step="0.0001" min="0"
                                        class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono" placeholder="AM">
                                    <input type="number" name="at_taux" value="<?php echo pct($p['at_taux']); ?>" step="0.0001" min="0"
                                        class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono" placeholder="AT">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Plafond commun (FCFA)</label>
                                <input type="number" name="am_plafond" value="<?php echo (int)$p['am_plafond']; ?>" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                <input type="hidden" name="at_plafond" value="<?php echo (int)$p['at_plafond']; ?>">
                            </div>
                        </div>
                    </div>

                    <!-- CMU -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 mb-4">
                        <h3 class="text-xs font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-heart-pulse text-emerald-400 text-[10px]"></i>
                            CMU — Couverture Maladie Universelle
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-[10px] text-slate-500 mb-2">Part salariale</p>
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2">
                                        <label class="text-[10px] text-slate-400 w-16 flex-shrink-0">Célibataire</label>
                                        <input type="number" name="cmu_salarie_c" value="<?php echo (int)$p['cmu_salarie_c']; ?>" min="0"
                                            class="flex-1 bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-[10px] text-slate-400 w-16 flex-shrink-0">Marié(e)</label>
                                        <input type="number" name="cmu_salarie_m" value="<?php echo (int)$p['cmu_salarie_m']; ?>" min="0"
                                            class="flex-1 bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-[10px] text-slate-400 w-16 flex-shrink-0">/ enfant</label>
                                        <input type="number" name="cmu_salarie_enfant" value="<?php echo (int)$p['cmu_salarie_enfant']; ?>" min="0"
                                            class="flex-1 bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                    </div>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-500 mb-2">Part patronale</p>
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2">
                                        <label class="text-[10px] text-slate-400 w-16 flex-shrink-0">Célibataire</label>
                                        <input type="number" name="cmu_patronal_c" value="<?php echo (int)$p['cmu_patronal_c']; ?>" min="0"
                                            class="flex-1 bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-[10px] text-slate-400 w-16 flex-shrink-0">Marié(e)</label>
                                        <input type="number" name="cmu_patronal_m" value="<?php echo (int)$p['cmu_patronal_m']; ?>" min="0"
                                            class="flex-1 bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-[10px] text-slate-400 w-16 flex-shrink-0">/ enfant</label>
                                        <input type="number" name="cmu_patronal_enfant" value="<?php echo (int)$p['cmu_patronal_enfant']; ?>" min="0"
                                            class="flex-1 bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Autres taxes -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 mb-4">
                        <h3 class="text-xs font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-percent text-orange-400 text-[10px]"></i>
                            Autres charges patronales
                        </h3>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">CE — Contribution Employeur (expatriés, %)</label>
                                <input type="number" name="ce_taux" value="<?php echo pct($p['ce_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">CN — Contribution Nationale (%)</label>
                                <input type="number" name="cn_taux" value="<?php echo pct($p['cn_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">TA — Taxe d'Apprentissage (%)</label>
                                <input type="number" name="ta_taux" value="<?php echo pct($p['ta_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">FDFP — Formation professionnelle (%)</label>
                                <input type="number" name="fdfp_taux" value="<?php echo pct($p['fdfp_taux']); ?>" step="0.0001" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- Divers -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4 mb-4">
                        <h3 class="text-xs font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-gear text-slate-400 text-[10px]"></i>
                            Valeurs par défaut
                        </h3>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Transport par défaut (FCFA)</label>
                                <input type="number" name="indemnite_transport_defaut" value="<?php echo (int)$p['indemnite_transport_defaut']; ?>" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">RICF — Valeur par part (FCFA)</label>
                                <input type="number" name="ricf_valeur_part" value="<?php echo (int)$p['ricf_valeur_part']; ?>" min="0"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                                <p class="text-[10px] text-slate-500 mt-1">RICF = parts × valeur. Déduit de l'ITS brut pour obtenir l'ITS net.</p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full px-4 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Enregistrer tous les paramètres
                    </button>
                </form>
            </div>
        </div>

        <!-- Info sur les parts RICF -->
        <div class="bg-violet-500/5 border border-violet-500/20 rounded-xl p-4">
            <p class="text-xs font-semibold text-violet-300 mb-2">
                <i class="fa-solid fa-circle-info mr-1.5"></i>
                Calcul des parts RICF
            </p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-[10px] text-slate-400">
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Célibataire sans enfant (C/0)</p>
                    <p>1 part</p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Célibataire avec 1 enfant</p>
                    <p>1 + 1 = <strong class="text-violet-300">2 parts</strong></p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Célibataire avec 2 enfants</p>
                    <p>1 + 1 + 0.5 = <strong class="text-violet-300">2.5 parts</strong></p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Marié(e) sans enfant (M/0)</p>
                    <p>2 parts</p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Marié(e) + 1 enfant</p>
                    <p>2 + 0.5 = <strong class="text-violet-300">2.5 parts</strong></p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Marié(e) + 2 enfants</p>
                    <p>2 + 1 = <strong class="text-violet-300">3 parts</strong></p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">Marié(e) + 3 enfants</p>
                    <p>2 + 1.5 = <strong class="text-violet-300">3.5 parts</strong></p>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-2.5">
                    <p class="font-semibold text-white mb-1">ITS net</p>
                    <p>= ITS brut − (parts × <?php echo number_format($p['ricf_valeur_part'], 0, ',', ' '); ?> FCFA)</p>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</body>
</html>
