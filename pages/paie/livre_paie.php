<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

try { $db->query("SELECT 1 FROM paie_bulletins LIMIT 1"); }
catch (Exception $e) { header('Location: index.php'); exit; }

$MOIS_NOMS = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

$mois_courant = (int)date('m');
$annee_courante = (int)date('Y');
$mois_sel  = (int)($_GET['mois']  ?? $mois_courant);
$annee_sel = (int)($_GET['annee'] ?? $annee_courante);

// Navigation
$prev_m = $mois_sel == 1 ? 12 : $mois_sel - 1;
$prev_a = $mois_sel == 1 ? $annee_sel - 1 : $annee_sel;
$next_m = $mois_sel == 12 ? 1 : $mois_sel + 1;
$next_a = $mois_sel == 12 ? $annee_sel + 1 : $annee_sel;

// Données bulletins
$stmt = $db->prepare("
    SELECT b.*, e.nom, e.prenom, e.matricule, e.poste, e.nationalite, e.num_cnps,
           e.situation_famille, e.nb_enfants
    FROM paie_bulletins b
    JOIN paie_employes e ON e.id = b.employe_id
    WHERE b.societe_id=? AND b.mois=? AND b.annee=?
    ORDER BY e.nom, e.prenom");
$stmt->execute([$societe_id, $mois_sel, $annee_sel]);
$bulletins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Params
$params = $db->prepare("SELECT * FROM paie_parametres WHERE societe_id=?");
$params->execute([$societe_id]);
$p = $params->fetch(PDO::FETCH_ASSOC);

// Societe
$soc = $db->prepare("SELECT * FROM societes WHERE id=?");
$soc->execute([$societe_id]);
$societe = $soc->fetch(PDO::FETCH_ASSOC);

// Totaux
$totaux = [
    'brut' => 0, 'transport' => 0,
    'cnps_sal' => 0, 'cmu_sal' => 0, 'its' => 0, 'net' => 0,
    'cnps_pat' => 0, 'pf' => 0, 'am' => 0, 'at' => 0, 'cmu_pat' => 0,
    'ce' => 0, 'cn' => 0, 'ta' => 0, 'fdfp' => 0, 'cout' => 0,
];
foreach ($bulletins as $b) {
    $totaux['brut']      += $b['salaire_brut'];
    $totaux['transport'] += $b['indemnite_transport'];
    $totaux['cnps_sal']  += $b['cnps_salarie'];
    $totaux['cmu_sal']   += $b['cmu_salarie'];
    $totaux['its']       += $b['its_net'];
    $totaux['net']       += $b['net_a_payer'];
    $totaux['cnps_pat']  += $b['cnps_patronal'];
    $totaux['pf']        += $b['pf_patronal'];
    $totaux['am']        += $b['am_patronal'];
    $totaux['at']        += $b['at_patronal'];
    $totaux['cmu_pat']   += $b['cmu_patronal'];
    $totaux['ce']        += $b['ce_patronal'];
    $totaux['cn']        += $b['cn_patronal'];
    $totaux['ta']        += $b['ta_patronal'];
    $totaux['fdfp']      += $b['fdfp_patronal'];
    $totaux['cout']      += $b['cout_total_employeur'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Livre de Paie - <?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; font-size: 10px; }
            table { font-size: 9px; }
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
<?php include '../../includes/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0">
    <header class="h-12 bg-slate-800/50 border-b border-slate-700/50 flex items-center px-4 gap-3 flex-shrink-0 no-print">
        <div class="flex items-center gap-2 flex-1">
            <div class="w-6 h-6 bg-violet-500/20 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-book text-violet-400 text-xs"></i>
            </div>
            <h1 class="text-sm font-semibold text-white">Livre de Paie</h1>
            <span class="text-slate-500 text-xs">/</span>
            <span class="text-slate-400 text-xs"><?php echo $MOIS_NOMS[$mois_sel]; ?> <?php echo $annee_sel; ?></span>
        </div>
        <div class="flex items-center gap-2">
            <a href="index.php?mois=<?php echo $mois_sel; ?>&annee=<?php echo $annee_sel; ?>" class="px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-arrow-left text-xs"></i> Bulletins
            </a>
            <button onclick="window.print()" class="px-2.5 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs transition">
                <i class="fa-solid fa-print text-xs"></i> Imprimer
            </button>
        </div>
    </header>

    <div class="flex-1 p-4 overflow-auto">
        <!-- Navigateur de mois -->
        <div class="flex items-center justify-between mb-4 no-print">
            <a href="?mois=<?php echo $prev_m; ?>&annee=<?php echo $prev_a; ?>" class="p-1.5 hover:bg-slate-700/50 rounded-lg transition text-slate-400">
                <i class="fa-solid fa-chevron-left text-xs"></i>
            </a>
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
            <a href="?mois=<?php echo $next_m; ?>&annee=<?php echo $next_a; ?>" class="p-1.5 hover:bg-slate-700/50 rounded-lg transition text-slate-400">
                <i class="fa-solid fa-chevron-right text-xs"></i>
            </a>
        </div>

        <!-- Document -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <!-- En-tête -->
            <div class="p-5 border-b border-slate-700/30 text-center">
                <h2 class="text-lg font-bold text-white"><?php echo htmlspecialchars($societe['raison_sociale'] ?? 'Société'); ?></h2>
                <h1 class="text-base font-semibold text-violet-300 mt-1">LIVRE DE PAIE — <?php echo strtoupper($MOIS_NOMS[$mois_sel]); ?> <?php echo $annee_sel; ?></h1>
                <p class="text-xs text-slate-500 mt-0.5"><?php echo count($bulletins); ?> employé(s)</p>
            </div>

            <?php if (empty($bulletins)): ?>
            <div class="p-12 text-center text-slate-500 text-sm">
                <i class="fa-solid fa-book-open text-3xl mb-3 block opacity-30"></i>
                Aucun bulletin pour ce mois.
            </div>
            <?php else: ?>

            <!-- Table principale : charges salariales -->
            <div class="p-4">
                <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-2">I — CHARGES SALARIALES</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead>
                            <tr class="bg-slate-700/30 text-slate-400">
                                <th class="text-left px-3 py-2 border border-slate-700/50">Matricule</th>
                                <th class="text-left px-3 py-2 border border-slate-700/50">Nom & Prénom</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">Brut</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">Transport</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">CNPS sal.</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">CMU sal.</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">ITS net</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50 font-semibold text-emerald-400">NET À PAYER</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bulletins as $b): ?>
                            <tr class="border-b border-slate-700/30 hover:bg-slate-700/10">
                                <td class="px-3 py-2 border border-slate-700/30 text-slate-500"><?php echo htmlspecialchars($b['matricule'] ?: '—'); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-white font-medium">
                                    <?php echo htmlspecialchars($b['nom'] . ' ' . $b['prenom']); ?>
                                    <span class="text-[10px] text-slate-500 ml-1">(<?php echo $b['situation_famille']; ?>/<?php echo $b['nb_enfants']; ?>)</span>
                                </td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono"><?php echo number_format($b['salaire_brut'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-slate-400"><?php echo number_format($b['indemnite_transport'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-red-400"><?php echo number_format($b['cnps_salarie'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-red-400"><?php echo number_format($b['cmu_salarie'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-amber-400"><?php echo number_format($b['its_net'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono font-semibold text-emerald-300"><?php echo number_format($b['net_a_payer'], 0, ',', ' '); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-700/30 font-semibold border-t-2 border-slate-600">
                                <td class="px-3 py-2 border border-slate-700/50" colspan="2">TOTAUX</td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono"><?php echo number_format($totaux['brut'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-slate-400"><?php echo number_format($totaux['transport'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-red-400"><?php echo number_format($totaux['cnps_sal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-red-400"><?php echo number_format($totaux['cmu_sal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-amber-400"><?php echo number_format($totaux['its'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-emerald-300"><?php echo number_format($totaux['net'], 0, ',', ' '); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Table : charges patronales -->
            <div class="p-4 border-t border-slate-700/30">
                <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-2">II — CHARGES PATRONALES</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead>
                            <tr class="bg-slate-700/30 text-slate-400">
                                <th class="text-left px-3 py-2 border border-slate-700/50">Employé</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">CNPS pat.</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">PF</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">AM+AT</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">CMU pat.</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">CE</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50">CN+TA+FDFP</th>
                                <th class="text-right px-3 py-2 border border-slate-700/50 font-semibold text-rose-400">COÛT TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bulletins as $b): ?>
                            <tr class="border-b border-slate-700/30 hover:bg-slate-700/10">
                                <td class="px-3 py-2 border border-slate-700/30 text-white"><?php echo htmlspecialchars($b['nom'] . ' ' . $b['prenom']); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-orange-400"><?php echo number_format($b['cnps_patronal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-orange-400"><?php echo number_format($b['pf_patronal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-orange-400"><?php echo number_format($b['am_patronal'] + $b['at_patronal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-orange-400"><?php echo number_format($b['cmu_patronal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono <?php echo $b['ce_patronal'] > 0 ? 'text-orange-400' : 'text-slate-600'; ?>"><?php echo number_format($b['ce_patronal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono text-orange-400"><?php echo number_format($b['cn_patronal'] + $b['ta_patronal'] + $b['fdfp_patronal'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/30 text-right font-mono font-semibold text-rose-300"><?php echo number_format($b['cout_total_employeur'], 0, ',', ' '); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-700/30 font-semibold border-t-2 border-slate-600">
                                <td class="px-3 py-2 border border-slate-700/50">TOTAUX</td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-orange-400"><?php echo number_format($totaux['cnps_pat'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-orange-400"><?php echo number_format($totaux['pf'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-orange-400"><?php echo number_format($totaux['am'] + $totaux['at'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-orange-400"><?php echo number_format($totaux['cmu_pat'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-orange-400"><?php echo number_format($totaux['ce'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-orange-400"><?php echo number_format($totaux['cn'] + $totaux['ta'] + $totaux['fdfp'], 0, ',', ' '); ?></td>
                                <td class="px-3 py-2 border border-slate-700/50 text-right font-mono text-rose-300"><?php echo number_format($totaux['cout'], 0, ',', ' '); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Récapitulatif déclarations -->
            <div class="p-4 border-t border-slate-700/30">
                <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-3">III — RÉCAPITULATIF DÉCLARATIONS</p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <!-- CNPS -->
                    <div class="bg-slate-700/30 rounded-xl p-3">
                        <p class="text-[10px] font-semibold text-slate-400 mb-2 flex items-center gap-1.5">
                            <i class="fa-solid fa-building-columns text-violet-400 text-[9px]"></i>
                            CNPS (Retraite + PF + AM + AT)
                        </p>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-slate-400">Part salariale</span>
                                <span class="font-mono text-red-300"><?php echo number_format($totaux['cnps_sal'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Part patronale</span>
                                <span class="font-mono text-orange-300"><?php echo number_format($totaux['cnps_pat'] + $totaux['pf'] + $totaux['am'] + $totaux['at'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between font-semibold border-t border-slate-600/50 pt-1 mt-1">
                                <span class="text-white">Total à verser</span>
                                <span class="font-mono text-violet-300"><?php echo number_format($totaux['cnps_sal'] + $totaux['cnps_pat'] + $totaux['pf'] + $totaux['am'] + $totaux['at'], 0, ',', ' '); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- ITS / DGI -->
                    <div class="bg-slate-700/30 rounded-xl p-3">
                        <p class="text-[10px] font-semibold text-slate-400 mb-2 flex items-center gap-1.5">
                            <i class="fa-solid fa-receipt text-amber-400 text-[9px]"></i>
                            DGI — ITS & Taxes
                        </p>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-slate-400">ITS (part salariale)</span>
                                <span class="font-mono text-amber-300"><?php echo number_format($totaux['its'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">CE patronal</span>
                                <span class="font-mono text-orange-300"><?php echo number_format($totaux['ce'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">CN + TA + FDFP</span>
                                <span class="font-mono text-orange-300"><?php echo number_format($totaux['cn'] + $totaux['ta'] + $totaux['fdfp'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between font-semibold border-t border-slate-600/50 pt-1 mt-1">
                                <span class="text-white">Total à verser</span>
                                <span class="font-mono text-amber-300"><?php echo number_format($totaux['its'] + $totaux['ce'] + $totaux['cn'] + $totaux['ta'] + $totaux['fdfp'], 0, ',', ' '); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- CMU -->
                    <div class="bg-slate-700/30 rounded-xl p-3">
                        <p class="text-[10px] font-semibold text-slate-400 mb-2 flex items-center gap-1.5">
                            <i class="fa-solid fa-heart-pulse text-emerald-400 text-[9px]"></i>
                            CMU
                        </p>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-slate-400">Part salariale</span>
                                <span class="font-mono text-red-300"><?php echo number_format($totaux['cmu_sal'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Part patronale</span>
                                <span class="font-mono text-orange-300"><?php echo number_format($totaux['cmu_pat'], 0, ',', ' '); ?></span>
                            </div>
                            <div class="flex justify-between font-semibold border-t border-slate-600/50 pt-1 mt-1">
                                <span class="text-white">Total CMU</span>
                                <span class="font-mono text-emerald-300"><?php echo number_format($totaux['cmu_sal'] + $totaux['cmu_pat'], 0, ',', ' '); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Total général -->
                <div class="mt-4 bg-violet-500/10 border border-violet-500/20 rounded-xl p-4">
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-[10px] text-slate-400">Masse salariale brute</p>
                            <p class="text-base font-bold font-mono text-white mt-1"><?php echo number_format($totaux['brut'], 0, ',', ' '); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] text-slate-400">Total net à payer</p>
                            <p class="text-base font-bold font-mono text-emerald-300 mt-1"><?php echo number_format($totaux['net'], 0, ',', ' '); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] text-slate-400">Coût total employeur</p>
                            <p class="text-base font-bold font-mono text-rose-300 mt-1"><?php echo number_format($totaux['cout'], 0, ',', ' '); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signatures -->
            <div class="p-5 border-t border-slate-700/30 grid grid-cols-3 gap-6">
                <div>
                    <p class="text-[10px] text-slate-500 mb-1">Établi par</p>
                    <div class="h-10 border-b border-slate-600/50"></div>
                </div>
                <div>
                    <p class="text-[10px] text-slate-500 mb-1">Vérifié par</p>
                    <div class="h-10 border-b border-slate-600/50"></div>
                </div>
                <div>
                    <p class="text-[10px] text-slate-500 mb-1">Approuvé par</p>
                    <div class="h-10 border-b border-slate-600/50"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</body>
</html>
