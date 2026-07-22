<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("
    SELECT b.*, e.nom, e.prenom, e.matricule, e.poste, e.categorie, e.num_cnps,
           e.nationalite, e.situation_famille, e.nb_enfants, e.date_embauche,
           s.raison_sociale, s.adresse as soc_adresse, s.ville as soc_ville
    FROM paie_bulletins b
    JOIN paie_employes e ON e.id = b.employe_id
    LEFT JOIN societes s ON s.id = b.societe_id
    WHERE b.id=? AND b.societe_id=?");
$stmt->execute([$id, $societe_id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$b) { header('Location: index.php'); exit; }

// Charger barème pour détail ITS
$bareme = $db->prepare("SELECT * FROM paie_its_bareme WHERE societe_id=? ORDER BY ordre");
$bareme->execute([$societe_id]);
$tranches = $bareme->fetchAll(PDO::FETCH_ASSOC);

$params = $db->prepare("SELECT * FROM paie_parametres WHERE societe_id=?");
$params->execute([$societe_id]);
$p = $params->fetch(PDO::FETCH_ASSOC);

function calculerParts(string $situation, int $nb_enfants): float {
    $parts = $situation === 'M' ? 2.0 : 1.0;
    if ($situation === 'C') {
        if ($nb_enfants >= 1) $parts += 1.0;
        if ($nb_enfants >= 2) $parts += 0.5 * ($nb_enfants - 1);
    } else {
        $parts += 0.5 * $nb_enfants;
    }
    return $parts;
}

$parts = calculerParts($b['situation_famille'], (int)$b['nb_enfants']);

// ── Actions ──────────────────────────────────────────────────────────────────
$message = urldecode($_GET['msg'] ?? '');
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'valider') {
        $db->prepare("UPDATE paie_bulletins SET statut='valide' WHERE id=? AND societe_id=? AND statut='brouillon'")->execute([$id, $societe_id]);
        $b['statut'] = 'valide';
        $message = 'Bulletin validé.';
    }

    if ($action === 'comptabiliser' && $b['statut'] === 'valide') {
        // Créer l'écriture comptable de paie
        try {
            $db->beginTransaction();

            $libelle = "Salaire {$b['nom']} {$b['prenom']} - " . sprintf('%02d/%d', $b['mois'], $b['annee']);
            $code_journal = 'PAI';
            $date_op = date($b['annee'] . '-' . sprintf('%02d', $b['mois']) . '-28'); // dernier jour du mois

            // Vérifier / créer journal PAI
            $jrnl = $db->prepare("SELECT id FROM journaux WHERE societe_id=? AND code=?");
            $jrnl->execute([$societe_id, $code_journal]);
            if (!$jrnl->fetchColumn()) {
                $db->prepare("INSERT INTO journaux (societe_id, code, libelle, type) VALUES (?,?,?,?)")
                   ->execute([$societe_id, $code_journal, 'Journal de Paie', 'OD']);
            }

            $ordre = $db->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM ecritures WHERE societe_id=? AND journal=? AND DATE_FORMAT(date_operation,'%Y-%m')=?");
            $ordre->execute([$societe_id, $code_journal, date('Y-m', strtotime($date_op))]);
            $num_ordre = $ordre->fetchColumn();

            $insLine = $db->prepare("INSERT INTO ecritures (societe_id, journal, date_operation, numero_piece, libelle, compte, debit, credit, lettre, exercice, ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

            $piece = 'BUL-' . sprintf('%05d', $id);
            $exercice = $b['annee'];

            // ── Charges salariales ──
            // Débit 6611 - Salaires et traitements
            $insLine->execute([$societe_id, $code_journal, $date_op, $piece, $libelle, '6611', $b['salaire_brut'], 0, null, $exercice, $num_ordre]);

            // Crédit 4220 - Personnel, rémunérations dues (net à payer)
            $insLine->execute([$societe_id, $code_journal, $date_op, $piece, $libelle . ' (net)', '4220', 0, $b['net_a_payer'], null, $exercice, $num_ordre]);

            // Crédit 4313 - CNPS salarié
            if ($b['cnps_salarie'] > 0)
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CNPS retenu ' . $b['nom'], '4313', 0, $b['cnps_salarie'], null, $exercice, $num_ordre]);

            // Crédit 4472 - ITS retenu
            if ($b['its_net'] > 0)
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'ITS retenu ' . $b['nom'], '4472', 0, $b['its_net'], null, $exercice, $num_ordre]);

            // Crédit 4318 - CMU salarié
            if ($b['cmu_salarie'] > 0)
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CMU retenu ' . $b['nom'], '4318', 0, $b['cmu_salarie'], null, $exercice, $num_ordre]);

            // ── Charges patronales ──
            $total_pat = $b['cnps_patronal'] + $b['pf_patronal'] + $b['am_patronal'] + $b['at_patronal'] + $b['cmu_patronal'] + $b['ce_patronal'];
            $total_taxes = $b['cn_patronal'] + $b['ta_patronal'] + $b['fdfp_patronal'];

            if ($total_pat > 0) {
                // Débit 6413/6414/6415 - Charges sociales patronales
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'Charges CNPS pat. ' . $b['nom'], '6413', $b['cnps_patronal'], 0, null, $exercice, $num_ordre]);
                if ($b['pf_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'PF pat. '.$b['nom'], '6414', $b['pf_patronal'], 0, null, $exercice, $num_ordre]);
                if ($b['am_patronal'] + $b['at_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'AM+AT pat. '.$b['nom'], '6415', $b['am_patronal'] + $b['at_patronal'], 0, null, $exercice, $num_ordre]);
                if ($b['cmu_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CMU pat. '.$b['nom'], '6415', $b['cmu_patronal'], 0, null, $exercice, $num_ordre]);
                if ($b['ce_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CE pat. '.$b['nom'], '6641', $b['ce_patronal'], 0, null, $exercice, $num_ordre]);

                // Crédit 4311/4312 - CNPS patronal dû
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CNPS pat. dû '.$b['nom'], '4311', 0, $b['cnps_patronal'], null, $exercice, $num_ordre]);
                if ($b['pf_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'PF dû '.$b['nom'], '4312', 0, $b['pf_patronal'] + $b['am_patronal'] + $b['at_patronal'], null, $exercice, $num_ordre]);
                if ($b['cmu_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CMU pat. dû '.$b['nom'], '4318', 0, $b['cmu_patronal'], null, $exercice, $num_ordre]);
                if ($b['ce_patronal'] > 0) $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CE dû '.$b['nom'], '4472', 0, $b['ce_patronal'], null, $exercice, $num_ordre]);
            }

            if ($total_taxes > 0) {
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CN+TA+FDFP ' . $b['nom'], '6634', $total_taxes, 0, null, $exercice, $num_ordre]);
                $insLine->execute([$societe_id, $code_journal, $date_op, $piece, 'CN+TA+FDFP dû '.$b['nom'], '4472', 0, $total_taxes, null, $exercice, $num_ordre]);
            }

            $ecriture_id = $db->lastInsertId();
            $db->prepare("UPDATE paie_bulletins SET statut='comptabilise', id_ecriture=? WHERE id=?")->execute([$ecriture_id, $id]);
            $b['statut'] = 'comptabilise';

            $db->commit();
            $message = 'Bulletin comptabilisé avec succès (Journal PAI).';
        } catch (Exception $e2) {
            $db->rollBack();
            $message = 'Erreur lors de la comptabilisation : ' . $e2->getMessage();
            $messageType = 'error';
        }
    }
}

$MOIS_NOMS = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$statut_class = match($b['statut']) {
    'valide' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
    'comptabilise' => 'bg-blue-500/15 text-blue-400 border-blue-500/20',
    default => 'bg-slate-600/30 text-slate-400 border-slate-600/20',
};
$statut_label = match($b['statut']) {
    'valide' => 'Validé',
    'comptabilise' => 'Comptabilisé',
    default => 'Brouillon',
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulletin <?php echo $b['nom']; ?> - <?php echo $MOIS_NOMS[$b['mois']]; ?> <?php echo $b['annee']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-bg { background: white !important; color: black !important; border: 1px solid #ccc !important; }
            .print-text { color: black !important; }
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
                <i class="fa-solid fa-file-invoice-dollar text-violet-400 text-xs"></i>
            </div>
            <h1 class="text-sm font-semibold text-white">Bulletin de paie</h1>
            <span class="text-slate-500 text-xs">/</span>
            <span class="text-slate-400 text-xs"><?php echo htmlspecialchars($b['nom'] . ' ' . $b['prenom']); ?> — <?php echo $MOIS_NOMS[$b['mois']]; ?> <?php echo $b['annee']; ?></span>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-[10px] border px-2 py-1 rounded-full <?php echo $statut_class; ?>"><?php echo $statut_label; ?></span>
            <a href="index.php?mois=<?php echo $b['mois']; ?>&annee=<?php echo $b['annee']; ?>" class="px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-arrow-left text-xs"></i> Retour
            </a>
            <?php if ($b['statut'] === 'brouillon'): ?>
            <a href="bulletin.php?id=<?php echo $id; ?>" class="px-2.5 py-1.5 bg-amber-600/50 hover:bg-amber-600 text-amber-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-pen-to-square text-xs"></i> Modifier
            </a>
            <form method="post" class="inline">
                <input type="hidden" name="action" value="valider">
                <button class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs transition">
                    <i class="fa-solid fa-check text-xs"></i> Valider
                </button>
            </form>
            <?php endif; ?>
            <?php if ($b['statut'] === 'valide'): ?>
            <form method="post" class="inline" onsubmit="return confirm('Comptabiliser ce bulletin ? Cette action créera une écriture dans le journal PAI.')">
                <input type="hidden" name="action" value="comptabiliser">
                <button class="px-2.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs transition">
                    <i class="fa-solid fa-journal-whills text-xs"></i> Comptabiliser
                </button>
            </form>
            <?php endif; ?>
            <button onclick="window.print()" class="px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-print text-xs"></i> Imprimer
            </button>
        </div>
    </header>

    <div class="flex-1 p-4 overflow-auto">
        <?php if ($message): ?>
        <div class="mb-4 px-4 py-3 rounded-lg text-sm no-print <?php echo $messageType === 'error' ? 'bg-red-500/10 border border-red-500/20 text-red-400' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400'; ?>">
            <i class="fa-solid fa-<?php echo $messageType === 'error' ? 'circle-exclamation' : 'check-circle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Bulletin imprimable -->
        <div class="max-w-3xl mx-auto bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden print-bg">
            <!-- En-tête -->
            <div class="p-6 border-b border-slate-700/30">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-lg font-bold text-white"><?php echo htmlspecialchars($b['raison_sociale'] ?? 'Société'); ?></h2>
                        <?php if ($b['soc_adresse']): ?>
                        <p class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($b['soc_adresse']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <h1 class="text-base font-bold text-violet-300 uppercase tracking-wide">Bulletin de Paie</h1>
                        <p class="text-xs text-slate-400 mt-0.5"><?php echo $MOIS_NOMS[$b['mois']]; ?> <?php echo $b['annee']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Infos employé -->
            <div class="p-5 border-b border-slate-700/30 grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-24 flex-shrink-0">Matricule</span>
                        <span class="text-xs text-slate-300"><?php echo htmlspecialchars($b['matricule'] ?: '—'); ?></span>
                    </div>
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-24 flex-shrink-0">Nom et prénom</span>
                        <span class="text-xs font-semibold text-white"><?php echo htmlspecialchars($b['nom'] . ' ' . $b['prenom']); ?></span>
                    </div>
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-24 flex-shrink-0">Poste</span>
                        <span class="text-xs text-slate-300"><?php echo htmlspecialchars($b['poste'] ?: '—'); ?></span>
                    </div>
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-24 flex-shrink-0">Catégorie</span>
                        <span class="text-xs text-slate-300"><?php echo htmlspecialchars($b['categorie'] ?: '—'); ?></span>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-28 flex-shrink-0">N° CNPS</span>
                        <span class="text-xs text-slate-300"><?php echo htmlspecialchars($b['num_cnps'] ?: '—'); ?></span>
                    </div>
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-28 flex-shrink-0">Situation</span>
                        <span class="text-xs text-slate-300"><?php echo $b['situation_famille'] === 'M' ? 'Marié(e)' : 'Célibataire'; ?> — <?php echo $b['nb_enfants']; ?> enfant(s)</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-28 flex-shrink-0">Parts fiscales</span>
                        <span class="text-xs text-violet-300 font-semibold"><?php echo number_format($parts, 1); ?></span>
                    </div>
                    <div class="flex gap-3">
                        <span class="text-[10px] text-slate-500 w-28 flex-shrink-0">Date embauche</span>
                        <span class="text-xs text-slate-300"><?php echo $b['date_embauche'] ? date('d/m/Y', strtotime($b['date_embauche'])) : '—'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Tableau des éléments -->
            <table class="w-full text-xs">
                <!-- GAINS -->
                <tbody>
                <tr class="bg-slate-700/20">
                    <td colspan="3" class="px-5 py-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Éléments de rémunération</td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2.5 text-slate-300">Salaire de base</td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-slate-200"><?php echo number_format($b['salaire_base'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2.5 text-slate-300">Indemnité de transport</td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-slate-200"><?php echo number_format($b['indemnite_transport'], 0, ',', ' '); ?></td>
                </tr>

                <!-- RETENUES -->
                <tr class="bg-slate-700/20">
                    <td colspan="3" class="px-5 py-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Retenues salariales</td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2.5 text-slate-300">CNPS - Cotisation retraite (<?php echo number_format($p['cnps_salarie_taux']*100, 1); ?>% plafonné)</td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-red-400">- <?php echo number_format($b['cnps_salarie'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2.5 text-slate-300">CMU - Couverture maladie universelle</td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-red-400">- <?php echo number_format($b['cmu_salarie'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2.5 text-slate-300 pl-7 text-slate-500 italic">
                        ITS avant RICF (brut)
                    </td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-slate-500"><?php echo number_format($b['its_avant_ricf'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2.5 text-slate-300 pl-7 text-slate-500 italic">
                        RICF (<?php echo number_format($parts, 1); ?> parts × <?php echo number_format($p['ricf_valeur_part'], 0, ',', ' '); ?> FCFA)
                    </td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-emerald-500">- <?php echo number_format($b['ricf'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/30">
                    <td class="px-5 py-2.5 text-slate-300">ITS - Impôt sur les Traitements et Salaires (net après RICF)</td>
                    <td class="px-5 py-2.5 text-center text-slate-500">—</td>
                    <td class="px-5 py-2.5 text-right font-mono text-red-400">- <?php echo number_format($b['its_net'], 0, ',', ' '); ?></td>
                </tr>

                <!-- NET À PAYER -->
                <tr class="bg-emerald-500/10 border-t-2 border-emerald-500/30">
                    <td class="px-5 py-3.5 font-bold text-white text-sm">NET À PAYER</td>
                    <td class="px-5 py-3.5 text-center text-slate-400 font-mono text-xs"><?php echo $MOIS_NOMS[$b['mois']]; ?> <?php echo $b['annee']; ?></td>
                    <td class="px-5 py-3.5 text-right font-bold font-mono text-emerald-300 text-base"><?php echo number_format($b['net_a_payer'], 0, ',', ' '); ?> FCFA</td>
                </tr>

                <!-- CHARGES PATRONALES -->
                <tr class="bg-slate-700/20">
                    <td colspan="3" class="px-5 py-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Charges patronales (information)</td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2 text-slate-400">CNPS retraite patronal (<?php echo number_format($p['cnps_patronal_taux']*100, 1); ?>%)</td>
                    <td></td>
                    <td class="px-5 py-2 text-right font-mono text-orange-400"><?php echo number_format($b['cnps_patronal'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2 text-slate-400">Prestations Familiales - PF (<?php echo number_format($p['pf_taux']*100, 1); ?>%)</td>
                    <td></td>
                    <td class="px-5 py-2 text-right font-mono text-orange-400"><?php echo number_format($b['pf_patronal'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2 text-slate-400">Accident Maladie + Travail (<?php echo number_format(($p['am_taux']+$p['at_taux'])*100, 2); ?>%)</td>
                    <td></td>
                    <td class="px-5 py-2 text-right font-mono text-orange-400"><?php echo number_format($b['am_patronal'] + $b['at_patronal'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2 text-slate-400">CMU patronal</td>
                    <td></td>
                    <td class="px-5 py-2 text-right font-mono text-orange-400"><?php echo number_format($b['cmu_patronal'], 0, ',', ' '); ?></td>
                </tr>
                <?php if ($b['ce_patronal'] > 0): ?>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2 text-slate-400">Contribution Employeur - CE (<?php echo number_format($p['ce_taux']*100, 1); ?>% — expatrié)</td>
                    <td></td>
                    <td class="px-5 py-2 text-right font-mono text-orange-400"><?php echo number_format($b['ce_patronal'], 0, ',', ' '); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="border-b border-slate-700/20">
                    <td class="px-5 py-2 text-slate-400">CN + TA + FDFP (<?php echo number_format(($p['cn_taux']+$p['ta_taux']+$p['fdfp_taux'])*100, 1); ?>%)</td>
                    <td></td>
                    <td class="px-5 py-2 text-right font-mono text-orange-400"><?php echo number_format($b['cn_patronal'] + $b['ta_patronal'] + $b['fdfp_patronal'], 0, ',', ' '); ?></td>
                </tr>
                <tr class="bg-rose-500/5 border-t border-slate-700/30">
                    <td class="px-5 py-3 font-semibold text-white">COÛT TOTAL EMPLOYEUR</td>
                    <td></td>
                    <td class="px-5 py-3 text-right font-bold font-mono text-rose-300"><?php echo number_format($b['cout_total_employeur'], 0, ',', ' '); ?> FCFA</td>
                </tr>
                </tbody>
            </table>

            <!-- Pied de bulletin -->
            <div class="p-5 border-t border-slate-700/30 grid grid-cols-2 gap-6">
                <div>
                    <p class="text-[10px] text-slate-500 mb-1">Signature employé</p>
                    <div class="h-12 border-b border-slate-600/50"></div>
                    <p class="text-[10px] text-slate-600 mt-1">Lu et approuvé</p>
                </div>
                <div>
                    <p class="text-[10px] text-slate-500 mb-1">Cachet et signature employeur</p>
                    <div class="h-12 border-b border-slate-600/50"></div>
                </div>
            </div>
        </div>

        <?php if ($b['id_ecriture']): ?>
        <div class="max-w-3xl mx-auto mt-3 px-4 py-2.5 bg-blue-500/10 border border-blue-500/20 rounded-lg text-xs text-blue-400 no-print">
            <i class="fa-solid fa-check-circle mr-1.5"></i>
            Écriture comptable générée.
            <a href="../ecritures/liste.php" class="underline hover:text-blue-300">Voir les écritures</a>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>
