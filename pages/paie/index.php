<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Vérifier que les tables existent (inclure employes.php en tant que loader de migrations si besoin)
try { $db->query("SELECT 1 FROM paie_employes LIMIT 1"); }
catch (Exception $e) { header('Location: employes.php'); exit; }

$mois_courant = (int)date('m');
$annee_courante = (int)date('Y');
$mois_sel  = (int)($_GET['mois']  ?? $mois_courant);
$annee_sel = (int)($_GET['annee'] ?? $annee_courante);

if ($mois_sel < 1 || $mois_sel > 12) $mois_sel = $mois_courant;
if ($annee_sel < 2000 || $annee_sel > 2099) $annee_sel = $annee_courante;

$MOIS_NOMS = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// ── Actions ─────────────────────────────────────────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generer_tous') {
        // Générer bulletins brouillon pour tous les employés actifs sans bulletin ce mois
        $employes_actifs = $db->prepare("SELECT * FROM paie_employes WHERE societe_id=? AND actif=1");
        $employes_actifs->execute([$societe_id]);
        $employes_list = $employes_actifs->fetchAll(PDO::FETCH_ASSOC);

        $params = $db->prepare("SELECT * FROM paie_parametres WHERE societe_id=?");
        $params->execute([$societe_id]);
        $p = $params->fetch(PDO::FETCH_ASSOC);

        $bareme = $db->prepare("SELECT * FROM paie_its_bareme WHERE societe_id=? ORDER BY ordre");
        $bareme->execute([$societe_id]);
        $tranches = $bareme->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        foreach ($employes_list as $emp) {
            // Vérifier si bulletin existe déjà
            $check = $db->prepare("SELECT id FROM paie_bulletins WHERE societe_id=? AND employe_id=? AND mois=? AND annee=?");
            $check->execute([$societe_id, $emp['id'], $mois_sel, $annee_sel]);
            if ($check->fetchColumn()) continue;

            $calc = calculerBulletin($emp, $p, $tranches);

            $ins = $db->prepare("INSERT INTO paie_bulletins (societe_id, employe_id, mois, annee,
                salaire_base, indemnite_transport, salaire_brut,
                cnps_salarie, cmu_salarie, its_avant_ricf, ricf, its_net, net_a_payer,
                cnps_patronal, pf_patronal, am_patronal, at_patronal, cmu_patronal,
                ce_patronal, cn_patronal, ta_patronal, fdfp_patronal, cout_total_employeur, statut)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $societe_id, $emp['id'], $mois_sel, $annee_sel,
                $calc['salaire_base'], $calc['transport'], $calc['brut'],
                $calc['cnps_sal'], $calc['cmu_sal'], $calc['its_avant'], $calc['ricf'], $calc['its_net'], $calc['net'],
                $calc['cnps_pat'], $calc['pf'], $calc['am'], $calc['at'], $calc['cmu_pat'],
                $calc['ce'], $calc['cn'], $calc['ta'], $calc['fdfp'], $calc['cout_total'], 'brouillon'
            ]);
            $created++;
        }
        $message = $created > 0 ? "$created bulletin(s) généré(s) pour {$MOIS_NOMS[$mois_sel]} $annee_sel." : "Tous les bulletins existent déjà pour ce mois.";
        $messageType = 'success';
    }

    if ($action === 'delete_bulletin') {
        $bid = (int)($_POST['bulletin_id'] ?? 0);
        $check = $db->prepare("SELECT id, statut FROM paie_bulletins WHERE id=? AND societe_id=?");
        $check->execute([$bid, $societe_id]);
        $bull = $check->fetch();
        if ($bull && $bull['statut'] !== 'comptabilise') {
            $db->prepare("DELETE FROM paie_bulletins WHERE id=? AND societe_id=?")->execute([$bid, $societe_id]);
            $message = 'Bulletin supprimé.';
            $messageType = 'success';
        } else {
            $message = 'Impossible de supprimer un bulletin déjà comptabilisé.';
            $messageType = 'error';
        }
    }
}

// ── Calcul bulletin ──────────────────────────────────────────────────────────
function calculerBulletin(array $emp, array $p, array $tranches): array {
    $brut = (float)$emp['salaire_base'];
    $transport = (float)$emp['indemnite_transport'];

    // CNPS salarié (plafonné)
    $assiette_cnps = min($brut, (float)$p['cnps_plafond']);
    $cnps_sal = round($assiette_cnps * (float)$p['cnps_salarie_taux']);

    // CMU salarié
    $cmu_sal = $emp['situation_famille'] === 'M' ? (float)$p['cmu_salarie_m'] : (float)$p['cmu_salarie_c'];
    $cmu_sal += (int)$emp['nb_enfants'] * (float)$p['cmu_salarie_enfant'];

    // ITS - base imposable = salaire brut (pas de transport)
    $its_avant = calculerITS($brut, $tranches);

    // RICF (Réduction d'impôt pour charge de famille)
    $parts = calculerParts($emp['situation_famille'], (int)$emp['nb_enfants']);
    $ricf  = round($parts * (float)$p['ricf_valeur_part']);

    // ITS net (ne peut pas être négatif)
    $its_net = max(0, $its_avant - $ricf);

    // Net à payer
    $net = $brut + $transport - $cnps_sal - $cmu_sal - $its_net;

    // ── Charges patronales ───────────────────────────────────────────────────
    $cnps_pat = round(min($brut, (float)$p['cnps_plafond']) * (float)$p['cnps_patronal_taux']);
    $pf  = round(min($brut, (float)$p['pf_plafond'])  * (float)$p['pf_taux']);
    $am  = round(min($brut, (float)$p['am_plafond'])  * (float)$p['am_taux']);
    $at  = round(min($brut, (float)$p['at_plafond'])  * (float)$p['at_taux']);

    $cmu_pat = $emp['situation_famille'] === 'M' ? (float)$p['cmu_patronal_m'] : (float)$p['cmu_patronal_c'];
    $cmu_pat += (int)$emp['nb_enfants'] * (float)$p['cmu_patronal_enfant'];

    $ce   = $emp['nationalite'] === 'expatrie' ? round($brut * (float)$p['ce_taux']) : 0;
    $cn   = round($brut * (float)$p['cn_taux']);
    $ta   = round($brut * (float)$p['ta_taux']);
    $fdfp = round($brut * (float)$p['fdfp_taux']);

    $cout_total = $net + $cnps_pat + $pf + $am + $at + $cmu_pat + $ce + $cn + $ta + $fdfp;

    return compact('brut', 'transport', 'cnps_sal', 'cmu_sal', 'its_avant', 'ricf', 'its_net', 'net',
        'cnps_pat', 'pf', 'am', 'at', 'cmu_pat', 'ce', 'cn', 'ta', 'fdfp', 'cout_total',
        'salaire_base', 'parts') + ['salaire_base' => $brut];
}

function calculerITS(float $base, array $tranches): float {
    $its = 0;
    foreach ($tranches as $t) {
        $min = (float)$t['tranche_min'];
        $max = $t['tranche_max'] !== null ? (float)$t['tranche_max'] : PHP_FLOAT_MAX;
        if ($base <= $min) break;
        $imposable = min($base, $max) - $min;
        $its += $imposable * (float)$t['taux'];
    }
    return round($its);
}

function calculerParts(string $situation, int $nb_enfants): float {
    // C = 1 base, M = 2 base
    // C : +1 pour 1er enfant, +0.5 pour les suivants
    // M : +0.5 par enfant
    $parts = $situation === 'M' ? 2.0 : 1.0;
    if ($situation === 'C') {
        if ($nb_enfants >= 1) $parts += 1.0;
        if ($nb_enfants >= 2) $parts += 0.5 * ($nb_enfants - 1);
    } else {
        $parts += 0.5 * $nb_enfants;
    }
    return $parts;
}

// ── Données affichage ────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT b.*, e.nom, e.prenom, e.matricule, e.poste, e.nationalite, e.situation_famille, e.nb_enfants
    FROM paie_bulletins b
    JOIN paie_employes e ON e.id = b.employe_id
    WHERE b.societe_id=? AND b.mois=? AND b.annee=?
    ORDER BY e.nom, e.prenom");
$stmt->execute([$societe_id, $mois_sel, $annee_sel]);
$bulletins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats = [
    'total_brut'  => array_sum(array_column($bulletins, 'salaire_brut')),
    'total_net'   => array_sum(array_column($bulletins, 'net_a_payer')),
    'total_cnps'  => array_sum(array_column($bulletins, 'cnps_salarie')) + array_sum(array_column($bulletins, 'cnps_patronal')),
    'total_its'   => array_sum(array_column($bulletins, 'its_net')),
    'total_cout'  => array_sum(array_column($bulletins, 'cout_total_employeur')),
    'nb_val'      => count(array_filter($bulletins, fn($b) => $b['statut'] !== 'brouillon')),
    'nb_compt'    => count(array_filter($bulletins, fn($b) => $b['statut'] === 'comptabilise')),
];

// Mois précédent / suivant
$prev_m = $mois_sel == 1 ? 12 : $mois_sel - 1;
$prev_a = $mois_sel == 1 ? $annee_sel - 1 : $annee_sel;
$next_m = $mois_sel == 12 ? 1 : $mois_sel + 1;
$next_a = $mois_sel == 12 ? $annee_sel + 1 : $annee_sel;

// Nb employés actifs
$nbActifs = $db->prepare("SELECT COUNT(*) FROM paie_employes WHERE societe_id=? AND actif=1");
$nbActifs->execute([$societe_id]);
$nbActifs = (int)$nbActifs->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paie <?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?> | <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
<?php include '../../includes/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0">
    <!-- Header -->
    <header class="h-12 bg-slate-800/50 border-b border-slate-700/50 flex items-center px-4 gap-3 flex-shrink-0">
        <div class="flex items-center gap-2 flex-1">
            <div class="w-6 h-6 bg-violet-500/20 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-file-invoice-dollar text-violet-400 text-xs"></i>
            </div>
            <h1 class="text-sm font-semibold text-white">Paie</h1>
            <span class="text-slate-500 text-xs">/</span>
            <span class="text-slate-400 text-xs"><?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?></span>
        </div>
        <div class="flex items-center gap-2">
            <a href="employes.php" class="flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-users text-xs"></i>
                Employés
            </a>
            <a href="livre_paie.php?mois=<?php echo $mois_sel; ?>&annee=<?php echo $annee_sel; ?>" class="flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-book text-xs"></i>
                Livre de paie
            </a>
            <a href="parametres.php" class="flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-sliders text-xs"></i>
                Paramètres
            </a>
        </div>
    </header>

    <div class="flex-1 p-4 overflow-auto space-y-4">
        <?php if ($message): ?>
        <div class="px-4 py-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border border-red-500/20 text-red-400'; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?> mr-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Navigateur de mois -->
        <div class="flex items-center justify-between bg-slate-800/50 border border-slate-700/50 rounded-xl px-4 py-3">
            <a href="?mois=<?php echo $prev_m; ?>&annee=<?php echo $prev_a; ?>" class="p-1.5 hover:bg-slate-700/50 rounded-lg transition text-slate-400 hover:text-white">
                <i class="fa-solid fa-chevron-left text-xs"></i>
            </a>
            <div class="flex items-center gap-4">
                <form method="get" class="flex items-center gap-2">
                    <select name="mois" onchange="this.form.submit()" class="bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1 text-xs text-white focus:outline-none">
                        <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m==$mois_sel ? 'selected':''; ?>><?php echo $MOIS_NOMS[$m]; ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="annee" onchange="this.form.submit()" class="bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-1 text-xs text-white focus:outline-none">
                        <?php for ($a=2020; $a<=2030; $a++): ?>
                        <option value="<?php echo $a; ?>" <?php echo $a==$annee_sel ? 'selected':''; ?>><?php echo $a; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
            <a href="?mois=<?php echo $next_m; ?>&annee=<?php echo $next_a; ?>" class="p-1.5 hover:bg-slate-700/50 rounded-lg transition text-slate-400 hover:text-white">
                <i class="fa-solid fa-chevron-right text-xs"></i>
            </a>
        </div>

        <!-- Cartes statistiques -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Bulletins</p>
                <p class="text-xl font-bold text-white"><?php echo count($bulletins); ?><span class="text-sm text-slate-500 font-normal"> / <?php echo $nbActifs; ?></span></p>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Masse salariale brute</p>
                <p class="text-base font-bold text-white font-mono"><?php echo number_format($stats['total_brut'], 0, ',', ' '); ?> <span class="text-xs text-slate-400">FCFA</span></p>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Total net à payer</p>
                <p class="text-base font-bold text-emerald-300 font-mono"><?php echo number_format($stats['total_net'], 0, ',', ' '); ?> <span class="text-xs text-slate-400">FCFA</span></p>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">ITS total</p>
                <p class="text-base font-bold text-amber-300 font-mono"><?php echo number_format($stats['total_its'], 0, ',', ' '); ?> <span class="text-xs text-slate-400">FCFA</span></p>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Coût total employeur</p>
                <p class="text-base font-bold text-rose-300 font-mono"><?php echo number_format($stats['total_cout'], 0, ',', ' '); ?> <span class="text-xs text-slate-400">FCFA</span></p>
            </div>
        </div>

        <!-- Actions globales -->
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-white">
                Bulletins de paie
                <span class="text-xs text-slate-400 font-normal ml-1">— <?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?></span>
            </h2>
            <div class="flex gap-2">
                <a href="bulletin.php?mois=<?php echo $mois_sel; ?>&annee=<?php echo $annee_sel; ?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                    <i class="fa-solid fa-plus text-xs"></i>
                    Bulletin individuel
                </a>
                <?php if (count($bulletins) < $nbActifs): ?>
                <form method="post">
                    <input type="hidden" name="action" value="generer_tous">
                    <input type="hidden" name="mois" value="<?php echo $mois_sel; ?>">
                    <input type="hidden" name="annee" value="<?php echo $annee_sel; ?>">
                    <button type="submit" class="flex items-center gap-1.5 px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs transition" onclick="return confirm('Générer les bulletins manquants pour <?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?> ?')">
                        <i class="fa-solid fa-wand-magic-sparkles text-xs"></i>
                        Générer tous
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table des bulletins -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <?php if (empty($bulletins)): ?>
            <div class="p-12 text-center text-slate-500 text-sm">
                <i class="fa-solid fa-file-circle-question text-3xl mb-3 block opacity-30"></i>
                Aucun bulletin pour <?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?>.<br>
                <span class="text-xs">Cliquez sur "Générer tous" pour créer automatiquement les bulletins.</span>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-700/50 text-slate-400">
                            <th class="text-left px-4 py-2.5 font-medium">Employé</th>
                            <th class="text-right px-3 py-2.5 font-medium">Brut</th>
                            <th class="text-right px-3 py-2.5 font-medium hidden md:table-cell">CNPS sal.</th>
                            <th class="text-right px-3 py-2.5 font-medium hidden md:table-cell">ITS net</th>
                            <th class="text-right px-3 py-2.5 font-medium">Net à payer</th>
                            <th class="text-right px-3 py-2.5 font-medium hidden lg:table-cell">Coût total</th>
                            <th class="text-center px-3 py-2.5 font-medium">Statut</th>
                            <th class="text-right px-4 py-2.5 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bulletins as $b): ?>
                        <tr class="border-b border-slate-700/30 hover:bg-slate-700/20 transition-colors">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-violet-500/20 flex items-center justify-center flex-shrink-0">
                                        <span class="text-[9px] font-semibold text-violet-300"><?php echo strtoupper(substr($b['nom'], 0, 1) . substr($b['prenom'] ?? '', 0, 1)); ?></span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white"><?php echo htmlspecialchars($b['nom'] . ' ' . $b['prenom']); ?></p>
                                        <p class="text-[10px] text-slate-500"><?php echo htmlspecialchars($b['poste'] ?: '—'); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right font-mono text-slate-300"><?php echo number_format($b['salaire_brut'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-3 text-right font-mono text-slate-400 hidden md:table-cell"><?php echo number_format($b['cnps_salarie'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-3 text-right font-mono text-amber-400 hidden md:table-cell"><?php echo number_format($b['its_net'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-3 text-right font-mono font-semibold text-emerald-300"><?php echo number_format($b['net_a_payer'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-3 text-right font-mono text-rose-400 hidden lg:table-cell"><?php echo number_format($b['cout_total_employeur'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-3 text-center">
                                <?php
                                $statut_class = match($b['statut']) {
                                    'valide' => 'bg-emerald-500/15 text-emerald-400',
                                    'comptabilise' => 'bg-blue-500/15 text-blue-400',
                                    default => 'bg-slate-600/30 text-slate-400',
                                };
                                $statut_label = match($b['statut']) {
                                    'valide' => 'Validé',
                                    'comptabilise' => 'Comptabilisé',
                                    default => 'Brouillon',
                                };
                                ?>
                                <span class="text-[10px] px-1.5 py-0.5 rounded-full <?php echo $statut_class; ?>">
                                    <?php echo $statut_label; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="voir_bulletin.php?id=<?php echo $b['id']; ?>" class="p-1.5 hover:bg-slate-700/50 rounded text-slate-400 hover:text-violet-400 transition" title="Voir le bulletin">
                                        <i class="fa-solid fa-eye text-[10px]"></i>
                                    </a>
                                    <?php if ($b['statut'] === 'brouillon'): ?>
                                    <a href="bulletin.php?id=<?php echo $b['id']; ?>" class="p-1.5 hover:bg-slate-700/50 rounded text-slate-400 hover:text-amber-400 transition" title="Modifier">
                                        <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                                    </a>
                                    <form method="post" class="inline" onsubmit="return confirm('Supprimer ce bulletin ?')">
                                        <input type="hidden" name="action" value="delete_bulletin">
                                        <input type="hidden" name="bulletin_id" value="<?php echo $b['id']; ?>">
                                        <button type="submit" class="p-1.5 hover:bg-slate-700/50 rounded text-slate-400 hover:text-red-400 transition">
                                            <i class="fa-solid fa-trash text-[10px]"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-700/50 bg-slate-800/30">
                            <td class="px-4 py-2.5 text-xs font-semibold text-slate-300">TOTAL (<?php echo count($bulletins); ?> employés)</td>
                            <td class="px-3 py-2.5 text-right text-xs font-mono font-semibold text-slate-300"><?php echo number_format($stats['total_brut'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-2.5 text-right text-xs font-mono text-slate-400 hidden md:table-cell"><?php echo number_format(array_sum(array_column($bulletins,'cnps_salarie')), 0, ',', ' '); ?></td>
                            <td class="px-3 py-2.5 text-right text-xs font-mono text-amber-400 hidden md:table-cell"><?php echo number_format($stats['total_its'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-2.5 text-right text-xs font-mono font-semibold text-emerald-300"><?php echo number_format($stats['total_net'], 0, ',', ' '); ?></td>
                            <td class="px-3 py-2.5 text-right text-xs font-mono text-rose-400 hidden lg:table-cell"><?php echo number_format($stats['total_cout'], 0, ',', ' '); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</body>
</html>
