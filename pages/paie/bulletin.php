<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

try { $db->query("SELECT 1 FROM paie_employes LIMIT 1"); }
catch (Exception $e) { header('Location: employes.php'); exit; }

$MOIS_NOMS = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// Charger paramètres
$params = $db->prepare("SELECT * FROM paie_parametres WHERE societe_id=?");
$params->execute([$societe_id]);
$p = $params->fetch(PDO::FETCH_ASSOC);
if (!$p) { header('Location: employes.php'); exit; }

$bareme = $db->prepare("SELECT * FROM paie_its_bareme WHERE societe_id=? ORDER BY ordre");
$bareme->execute([$societe_id]);
$tranches = $bareme->fetchAll(PDO::FETCH_ASSOC);

// ── Mode édition ou nouveau ──────────────────────────────────────────────────
$bulletin = null;
$employe  = null;
$id_bulletin = (int)($_GET['id'] ?? 0);
$employe_id  = (int)($_GET['employe_id'] ?? 0);
$mois_def = (int)($_GET['mois'] ?? date('m'));
$annee_def = (int)($_GET['annee'] ?? date('Y'));

if ($id_bulletin) {
    $stmt = $db->prepare("SELECT b.*, e.nom, e.prenom, e.matricule, e.poste, e.nationalite, e.situation_famille, e.nb_enfants, e.num_cnps
        FROM paie_bulletins b JOIN paie_employes e ON e.id=b.employe_id
        WHERE b.id=? AND b.societe_id=?");
    $stmt->execute([$id_bulletin, $societe_id]);
    $bulletin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bulletin) { header('Location: index.php'); exit; }
    $employe_id = $bulletin['employe_id'];
}

if ($employe_id && !$employe) {
    $stmt = $db->prepare("SELECT * FROM paie_employes WHERE id=? AND societe_id=?");
    $stmt->execute([$employe_id, $societe_id]);
    $employe = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Liste employés pour select
$stmt_emp = $db->prepare("SELECT id, nom, prenom, matricule FROM paie_employes WHERE societe_id=? AND actif=1 ORDER BY nom");
$stmt_emp->execute([$societe_id]);
$employes_list = $stmt_emp->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$messageType = '';

// ── Sauvegarde ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $eid    = (int)($_POST['employe_id'] ?? 0);
    $mois   = (int)($_POST['mois'] ?? 0);
    $annee  = (int)($_POST['annee'] ?? 0);
    $bid    = (int)($_POST['bulletin_id'] ?? 0);

    $emp_stmt = $db->prepare("SELECT * FROM paie_employes WHERE id=? AND societe_id=?");
    $emp_stmt->execute([$eid, $societe_id]);
    $emp_data = $emp_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$emp_data || !$mois || !$annee) {
        $message = 'Données invalides.';
        $messageType = 'error';
    } else {
        // Récupérer les valeurs du formulaire (permettre override manuel)
        $salaire_base = (float)$_POST['salaire_base'];
        $transport    = (float)$_POST['indemnite_transport'];

        // Override situation/enfants si modifié dans le bulletin
        $emp_data['salaire_base'] = $salaire_base;
        $emp_data['indemnite_transport'] = $transport;
        $emp_data['situation_famille'] = $_POST['situation_famille'] ?? $emp_data['situation_famille'];
        $emp_data['nb_enfants'] = (int)($_POST['nb_enfants'] ?? $emp_data['nb_enfants']);
        $emp_data['nationalite'] = $_POST['nationalite'] ?? $emp_data['nationalite'];

        // Calcul
        $calc = calculerBulletin($emp_data, $p, $tranches);

        $data = [
            $societe_id, $eid, $mois, $annee,
            $calc['salaire_base'], $calc['transport'], $calc['brut'],
            $calc['cnps_sal'], $calc['cmu_sal'],
            $calc['its_avant'], $calc['ricf'], $calc['its_net'], $calc['net'],
            $calc['cnps_pat'], $calc['pf'], $calc['am'], $calc['at'], $calc['cmu_pat'],
            $calc['ce'], $calc['cn'], $calc['ta'], $calc['fdfp'], $calc['cout_total'],
            $action === 'valider' ? 'valide' : 'brouillon'
        ];

        if ($bid) {
            // Mise à jour
            $upd = $db->prepare("UPDATE paie_bulletins SET employe_id=?, mois=?, annee=?,
                salaire_base=?, indemnite_transport=?, salaire_brut=?,
                cnps_salarie=?, cmu_salarie=?, its_avant_ricf=?, ricf=?, its_net=?, net_a_payer=?,
                cnps_patronal=?, pf_patronal=?, am_patronal=?, at_patronal=?, cmu_patronal=?,
                ce_patronal=?, cn_patronal=?, ta_patronal=?, fdfp_patronal=?, cout_total_employeur=?, statut=?
                WHERE id=? AND societe_id=?");
            $upd->execute(array_slice($data, 1) + [count($data) => $bid, count($data)+1 => $societe_id]);
            // PDO indexed fix
            $upd_vals = [$eid, $mois, $annee,
                $calc['salaire_base'], $calc['transport'], $calc['brut'],
                $calc['cnps_sal'], $calc['cmu_sal'],
                $calc['its_avant'], $calc['ricf'], $calc['its_net'], $calc['net'],
                $calc['cnps_pat'], $calc['pf'], $calc['am'], $calc['at'], $calc['cmu_pat'],
                $calc['ce'], $calc['cn'], $calc['ta'], $calc['fdfp'], $calc['cout_total'],
                $action === 'valider' ? 'valide' : 'brouillon',
                $bid, $societe_id];
            $db->prepare("UPDATE paie_bulletins SET employe_id=?, mois=?, annee=?,
                salaire_base=?, indemnite_transport=?, salaire_brut=?,
                cnps_salarie=?, cmu_salarie=?, its_avant_ricf=?, ricf=?, its_net=?, net_a_payer=?,
                cnps_patronal=?, pf_patronal=?, am_patronal=?, at_patronal=?, cmu_patronal=?,
                ce_patronal=?, cn_patronal=?, ta_patronal=?, fdfp_patronal=?, cout_total_employeur=?, statut=?
                WHERE id=? AND societe_id=?")->execute($upd_vals);
            $message = $action === 'valider' ? 'Bulletin validé.' : 'Bulletin mis à jour.';
            $redirect_id = $bid;
        } else {
            // Insertion (upsert)
            $check = $db->prepare("SELECT id FROM paie_bulletins WHERE societe_id=? AND employe_id=? AND mois=? AND annee=?");
            $check->execute([$societe_id, $eid, $mois, $annee]);
            if ($check->fetchColumn()) {
                $message = 'Un bulletin existe déjà pour cet employé ce mois. Veuillez le modifier.';
                $messageType = 'error';
            } else {
                $ins = $db->prepare("INSERT INTO paie_bulletins (societe_id, employe_id, mois, annee,
                    salaire_base, indemnite_transport, salaire_brut,
                    cnps_salarie, cmu_salarie, its_avant_ricf, ricf, its_net, net_a_payer,
                    cnps_patronal, pf_patronal, am_patronal, at_patronal, cmu_patronal,
                    ce_patronal, cn_patronal, ta_patronal, fdfp_patronal, cout_total_employeur, statut)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute($data);
                $redirect_id = $db->lastInsertId();
                $message = $action === 'valider' ? 'Bulletin créé et validé.' : 'Bulletin enregistré.';
            }
        }
        $messageType = $messageType ?: 'success';
        if ($messageType === 'success') {
            header('Location: voir_bulletin.php?id=' . ($redirect_id ?? $bid) . '&msg=' . urlencode($message));
            exit;
        }
    }
}

// ── Fonctions calcul (réutilisées de index.php) ─────────────────────────────
function calculerBulletin(array $emp, array $p, array $tranches): array {
    $brut      = (float)$emp['salaire_base'];
    $transport = (float)($emp['indemnite_transport'] ?? $p['indemnite_transport_defaut']);
    $assiette_cnps = min($brut, (float)$p['cnps_plafond']);
    $cnps_sal  = round($assiette_cnps * (float)$p['cnps_salarie_taux']);
    $cmu_sal   = $emp['situation_famille'] === 'M' ? (float)$p['cmu_salarie_m'] : (float)$p['cmu_salarie_c'];
    $cmu_sal  += (int)$emp['nb_enfants'] * (float)$p['cmu_salarie_enfant'];
    $its_avant = calculerITS($brut, $tranches);
    $parts     = calculerParts($emp['situation_famille'], (int)$emp['nb_enfants']);
    $ricf      = round($parts * (float)$p['ricf_valeur_part']);
    $its_net   = max(0, $its_avant - $ricf);
    $net       = $brut + $transport - $cnps_sal - $cmu_sal - $its_net;
    $cnps_pat  = round(min($brut, (float)$p['cnps_plafond']) * (float)$p['cnps_patronal_taux']);
    $pf  = round(min($brut, (float)$p['pf_plafond'])  * (float)$p['pf_taux']);
    $am  = round(min($brut, (float)$p['am_plafond'])  * (float)$p['am_taux']);
    $at  = round(min($brut, (float)$p['at_plafond'])  * (float)$p['at_taux']);
    $cmu_pat   = $emp['situation_famille'] === 'M' ? (float)$p['cmu_patronal_m'] : (float)$p['cmu_patronal_c'];
    $cmu_pat  += (int)$emp['nb_enfants'] * (float)$p['cmu_patronal_enfant'];
    $ce   = $emp['nationalite'] === 'expatrie' ? round($brut * (float)$p['ce_taux']) : 0;
    $cn   = round($brut * (float)$p['cn_taux']);
    $ta   = round($brut * (float)$p['ta_taux']);
    $fdfp = round($brut * (float)$p['fdfp_taux']);
    $cout_total = $net + $cnps_pat + $pf + $am + $at + $cmu_pat + $ce + $cn + $ta + $fdfp;
    return compact('brut','transport','cnps_sal','cmu_sal','its_avant','ricf','its_net','net',
        'cnps_pat','pf','am','at','cmu_pat','ce','cn','ta','fdfp','cout_total','parts')
        + ['salaire_base' => $brut];
}
function calculerITS(float $base, array $tranches): float {
    $its = 0;
    foreach ($tranches as $t) {
        $min = (float)$t['tranche_min'];
        $max = $t['tranche_max'] !== null ? (float)$t['tranche_max'] : PHP_FLOAT_MAX;
        if ($base <= $min) break;
        $its += (min($base, $max) - $min) * (float)$t['taux'];
    }
    return round($its);
}
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

// Calculer pour affichage en temps réel (si employé déjà sélectionné)
$preview = null;
if ($employe) {
    $preview = calculerBulletin($employe, $p, $tranches);
}
if ($bulletin) {
    $employe = $employe ?: [];
    $employe = array_merge($employe, [
        'salaire_base' => $bulletin['salaire_base'],
        'indemnite_transport' => $bulletin['indemnite_transport'],
    ]);
    $preview = calculerBulletin(array_merge(
        $db->prepare("SELECT * FROM paie_employes WHERE id=?")->execute([$bulletin['employe_id']]) ? [] : [],
        $employe,
        ['situation_famille' => $employe['situation_famille'] ?? 'C', 'nb_enfants' => $employe['nb_enfants'] ?? 0, 'nationalite' => $employe['nationalite'] ?? 'locale']
    ), $p, $tranches);
    // reload proper
    $emp_full = $db->prepare("SELECT * FROM paie_employes WHERE id=? AND societe_id=?");
    $emp_full->execute([$bulletin['employe_id'], $societe_id]);
    $emp_data_full = $emp_full->fetch(PDO::FETCH_ASSOC);
    if ($emp_data_full) {
        $emp_data_full['salaire_base'] = $bulletin['salaire_base'];
        $emp_data_full['indemnite_transport'] = $bulletin['indemnite_transport'];
        $preview = calculerBulletin($emp_data_full, $p, $tranches);
    }
}

$json_params = json_encode($p);
$json_tranches = json_encode($tranches);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulletin de paie | <?php echo APP_NAME; ?></title>
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
                <i class="fa-solid fa-file-invoice-dollar text-violet-400 text-xs"></i>
            </div>
            <h1 class="text-sm font-semibold text-white"><?php echo $bulletin ? 'Modifier bulletin' : 'Nouveau bulletin'; ?></h1>
        </div>
        <a href="index.php?mois=<?php echo $bulletin['mois'] ?? $mois_def; ?>&annee=<?php echo $bulletin['annee'] ?? $annee_def; ?>" class="flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            Retour
        </a>
    </header>

    <div class="flex-1 p-4 overflow-auto">
        <?php if ($message && $messageType === 'error'): ?>
        <div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-500/10 border border-red-500/20 text-red-400">
            <i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <form method="post" id="formBulletin">
            <input type="hidden" name="bulletin_id" value="<?php echo $bulletin['id'] ?? ''; ?>">

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <!-- Colonne gauche : paramètres -->
                <div class="space-y-4">
                    <!-- Employé & période -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                        <h2 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-user text-violet-400 text-xs"></i>
                            Employé & Période
                        </h2>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Employé <span class="text-red-400">*</span></label>
                                <select name="employe_id" id="selectEmploye" onchange="chargerEmploye(this.value)"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2.5 py-2 text-xs text-white focus:outline-none focus:border-violet-500"
                                    <?php echo $bulletin ? 'disabled' : ''; ?>>
                                    <option value="">-- Sélectionner un employé --</option>
                                    <?php foreach ($employes_list as $e): ?>
                                    <option value="<?php echo $e['id']; ?>"
                                        <?php echo ($bulletin['employe_id'] ?? $employe_id) == $e['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($e['nom'] . ' ' . $e['prenom'] . ($e['matricule'] ? ' (' . $e['matricule'] . ')' : '')); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($bulletin): ?>
                                <input type="hidden" name="employe_id" value="<?php echo $bulletin['employe_id']; ?>">
                                <?php endif; ?>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[10px] text-slate-400 mb-1">Mois <span class="text-red-400">*</span></label>
                                    <select name="mois" class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-2 text-xs text-white focus:outline-none focus:border-violet-500">
                                        <?php for ($m=1; $m<=12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo ($bulletin['mois'] ?? $mois_def) == $m ? 'selected' : ''; ?>><?php echo $MOIS_NOMS[$m]; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] text-slate-400 mb-1">Année <span class="text-red-400">*</span></label>
                                    <select name="annee" class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-2 text-xs text-white focus:outline-none focus:border-violet-500">
                                        <?php for ($a=2020; $a<=2030; $a++): ?>
                                        <option value="<?php echo $a; ?>" <?php echo ($bulletin['annee'] ?? $annee_def) == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Éléments de rémunération -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                        <h2 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-coins text-amber-400 text-xs"></i>
                            Éléments de rémunération
                        </h2>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Salaire de base (FCFA)</label>
                                <input type="number" name="salaire_base" id="salaire_base" min="0" step="1"
                                    value="<?php echo $bulletin['salaire_base'] ?? $employe['salaire_base'] ?? ''; ?>"
                                    oninput="recalculer()"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2.5 py-2 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Indemnité de transport (FCFA)</label>
                                <input type="number" name="indemnite_transport" id="indemnite_transport" min="0" step="1"
                                    value="<?php echo $bulletin['indemnite_transport'] ?? $employe['indemnite_transport'] ?? $p['indemnite_transport_defaut']; ?>"
                                    oninput="recalculer()"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2.5 py-2 text-xs text-white focus:outline-none focus:border-violet-500 font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- Situation familiale -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                        <h2 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-family text-emerald-400 text-xs"></i>
                            Situation (pour RICF)
                        </h2>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Nationalité</label>
                                <select name="nationalite" id="nationalite" onchange="recalculer()"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-2 text-xs text-white focus:outline-none focus:border-violet-500">
                                    <option value="locale" <?php echo ($employe['nationalite'] ?? 'locale') === 'locale' ? 'selected' : ''; ?>>Locale</option>
                                    <option value="expatrie" <?php echo ($employe['nationalite'] ?? '') === 'expatrie' ? 'selected' : ''; ?>>Expatrié</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Situation</label>
                                <select name="situation_famille" id="situation_famille" onchange="recalculer()"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2 py-2 text-xs text-white focus:outline-none focus:border-violet-500">
                                    <option value="C" <?php echo ($employe['situation_famille'] ?? 'C') === 'C' ? 'selected' : ''; ?>>Célibataire</option>
                                    <option value="M" <?php echo ($employe['situation_famille'] ?? '') === 'M' ? 'selected' : ''; ?>>Marié(e)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 mb-1">Nb enfants</label>
                                <input type="number" name="nb_enfants" id="nb_enfants" min="0" max="20"
                                    value="<?php echo $employe['nb_enfants'] ?? 0; ?>"
                                    oninput="recalculer()"
                                    class="w-full bg-slate-700/50 border border-slate-600/50 rounded-lg px-2.5 py-2 text-xs text-white focus:outline-none focus:border-violet-500">
                            </div>
                        </div>
                        <p id="parts_display" class="text-[10px] text-slate-400 mt-2">Parts fiscales : <span id="parts_val" class="text-violet-300 font-semibold">—</span></p>
                    </div>
                </div>

                <!-- Colonne droite : résultat calcul -->
                <div class="space-y-4">
                    <!-- Aperçu bulletin -->
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-slate-700/50 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                                <i class="fa-solid fa-calculator text-violet-400 text-xs"></i>
                                Calcul automatique
                            </h2>
                            <span class="text-[10px] text-slate-500">Mis à jour en temps réel</span>
                        </div>

                        <!-- Gains -->
                        <div class="p-4 border-b border-slate-700/30">
                            <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-2">Gains</p>
                            <div class="space-y-1.5">
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">Salaire de base</span>
                                    <span id="r_brut" class="font-mono text-slate-300">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">Indemnité transport</span>
                                    <span id="r_transport" class="font-mono text-slate-300">—</span>
                                </div>
                                <div class="flex justify-between text-xs font-semibold border-t border-slate-700/50 pt-1.5 mt-1.5">
                                    <span class="text-white">Brut + Transport</span>
                                    <span id="r_brut_transport" class="font-mono text-white">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Retenues salariales -->
                        <div class="p-4 border-b border-slate-700/30">
                            <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-2">Retenues salariales</p>
                            <div class="space-y-1.5">
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">CNPS retraite (<span id="r_cnps_pct"><?php echo number_format($p['cnps_salarie_taux']*100, 1); ?>%</span>)</span>
                                    <span id="r_cnps_sal" class="font-mono text-red-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">CMU salarié</span>
                                    <span id="r_cmu_sal" class="font-mono text-red-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs text-slate-500">
                                    <span>ITS avant RICF</span>
                                    <span id="r_its_avant" class="font-mono">—</span>
                                </div>
                                <div class="flex justify-between text-xs text-slate-500">
                                    <span>RICF (<span id="r_parts">—</span> parts × <?php echo number_format($p['ricf_valeur_part'], 0, ',', ' '); ?> FCFA)</span>
                                    <span id="r_ricf" class="font-mono text-emerald-500">— </span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">ITS net (après RICF)</span>
                                    <span id="r_its_net" class="font-mono text-red-400">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Net à payer -->
                        <div class="p-4 bg-emerald-500/5 border-b border-slate-700/30">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-bold text-white">NET À PAYER</span>
                                <span id="r_net" class="text-xl font-bold font-mono text-emerald-300">—</span>
                            </div>
                        </div>

                        <!-- Charges patronales -->
                        <div class="p-4 border-b border-slate-700/30">
                            <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-2">Charges patronales</p>
                            <div class="space-y-1.5">
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">CNPS retraite (<?php echo number_format($p['cnps_patronal_taux']*100, 1); ?>%)</span>
                                    <span id="r_cnps_pat" class="font-mono text-orange-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">PF (<?php echo number_format($p['pf_taux']*100, 1); ?>%)</span>
                                    <span id="r_pf" class="font-mono text-orange-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">AM (<?php echo number_format($p['am_taux']*100, 2); ?>%)</span>
                                    <span id="r_am" class="font-mono text-orange-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">AT (<?php echo number_format($p['at_taux']*100, 1); ?>%)</span>
                                    <span id="r_at" class="font-mono text-orange-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">CMU patronal</span>
                                    <span id="r_cmu_pat" class="font-mono text-orange-400">—</span>
                                </div>
                                <div id="row_ce" class="flex justify-between text-xs hidden">
                                    <span class="text-slate-400">CE (<?php echo number_format($p['ce_taux']*100, 1); ?>% — expatrié)</span>
                                    <span id="r_ce" class="font-mono text-orange-400">—</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-slate-400">CN+TA+FDFP (<?php echo number_format(($p['cn_taux']+$p['ta_taux']+$p['fdfp_taux'])*100, 1); ?>%)</span>
                                    <span id="r_cntafdfp" class="font-mono text-orange-400">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Coût total -->
                        <div class="p-4 bg-rose-500/5">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-bold text-white">COÛT TOTAL EMPLOYEUR</span>
                                <span id="r_cout" class="text-lg font-bold font-mono text-rose-300">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons -->
                    <div class="flex gap-2">
                        <button type="submit" name="action" value="sauvegarder" class="flex-1 px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-xs font-medium transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Enregistrer brouillon
                        </button>
                        <button type="submit" name="action" value="valider" class="flex-1 px-4 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-check"></i>
                            Valider le bulletin
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const PARAMS = <?php echo $json_params; ?>;
const TRANCHES = <?php echo $json_tranches; ?>;

// Données des employés pour auto-remplissage
const EMPLOYES = {
    <?php foreach ($employes_list as $e):
        $emp_full = $db->prepare("SELECT * FROM paie_employes WHERE id=? AND societe_id=?");
        $emp_full->execute([$e['id'], $societe_id]);
        $ef = $emp_full->fetch(PDO::FETCH_ASSOC);
        if ($ef): ?>
    <?php echo $ef['id']; ?>: {
        salaire_base: <?php echo (float)$ef['salaire_base']; ?>,
        transport: <?php echo (float)$ef['indemnite_transport']; ?>,
        situation: '<?php echo $ef['situation_famille']; ?>',
        enfants: <?php echo (int)$ef['nb_enfants']; ?>,
        nationalite: '<?php echo $ef['nationalite']; ?>'
    },
    <?php endif; endforeach; ?>
};

function fmt(n) {
    return Math.round(n).toLocaleString('fr-FR') + ' FCFA';
}

function calculerITS(base) {
    let its = 0;
    for (const t of TRANCHES) {
        const min = parseFloat(t.tranche_min);
        const max = t.tranche_max !== null ? parseFloat(t.tranche_max) : Infinity;
        if (base <= min) break;
        its += (Math.min(base, max) - min) * parseFloat(t.taux);
    }
    return Math.round(its);
}

function calculerParts(situation, enfants) {
    let parts = situation === 'M' ? 2.0 : 1.0;
    if (situation === 'C') {
        if (enfants >= 1) parts += 1.0;
        if (enfants >= 2) parts += 0.5 * (enfants - 1);
    } else {
        parts += 0.5 * enfants;
    }
    return parts;
}

function recalculer() {
    const brut = parseFloat(document.getElementById('salaire_base').value) || 0;
    const transport = parseFloat(document.getElementById('indemnite_transport').value) || 0;
    const situation = document.getElementById('situation_famille').value;
    const enfants = parseInt(document.getElementById('nb_enfants').value) || 0;
    const nationalite = document.getElementById('nationalite').value;

    if (!brut) return;

    // CNPS salarié
    const assietteCnps = Math.min(brut, parseFloat(PARAMS.cnps_plafond));
    const cnpsSal = Math.round(assietteCnps * parseFloat(PARAMS.cnps_salarie_taux));

    // CMU salarié
    let cmuSal = situation === 'M' ? parseFloat(PARAMS.cmu_salarie_m) : parseFloat(PARAMS.cmu_salarie_c);
    cmuSal += enfants * parseFloat(PARAMS.cmu_salarie_enfant);

    // ITS
    const itsAvant = calculerITS(brut);
    const parts = calculerParts(situation, enfants);
    const ricf = Math.round(parts * parseFloat(PARAMS.ricf_valeur_part));
    const itsNet = Math.max(0, itsAvant - ricf);

    // Net
    const net = brut + transport - cnpsSal - cmuSal - itsNet;

    // Charges patronales
    const cnpsPat = Math.round(Math.min(brut, parseFloat(PARAMS.cnps_plafond)) * parseFloat(PARAMS.cnps_patronal_taux));
    const pf = Math.round(Math.min(brut, parseFloat(PARAMS.pf_plafond)) * parseFloat(PARAMS.pf_taux));
    const am = Math.round(Math.min(brut, parseFloat(PARAMS.am_plafond)) * parseFloat(PARAMS.am_taux));
    const at = Math.round(Math.min(brut, parseFloat(PARAMS.at_plafond)) * parseFloat(PARAMS.at_taux));
    let cmuPat = situation === 'M' ? parseFloat(PARAMS.cmu_patronal_m) : parseFloat(PARAMS.cmu_patronal_c);
    cmuPat += enfants * parseFloat(PARAMS.cmu_patronal_enfant);
    const ce = nationalite === 'expatrie' ? Math.round(brut * parseFloat(PARAMS.ce_taux)) : 0;
    const cn = Math.round(brut * parseFloat(PARAMS.cn_taux));
    const ta = Math.round(brut * parseFloat(PARAMS.ta_taux));
    const fdfp = Math.round(brut * parseFloat(PARAMS.fdfp_taux));
    const coutTotal = net + cnpsPat + pf + am + at + cmuPat + ce + cn + ta + fdfp;

    // Affichage
    document.getElementById('r_brut').textContent = fmt(brut);
    document.getElementById('r_transport').textContent = fmt(transport);
    document.getElementById('r_brut_transport').textContent = fmt(brut + transport);
    document.getElementById('r_cnps_sal').textContent = '- ' + fmt(cnpsSal);
    document.getElementById('r_cmu_sal').textContent = '- ' + fmt(cmuSal);
    document.getElementById('r_its_avant').textContent = fmt(itsAvant);
    document.getElementById('r_ricf').textContent = '- ' + fmt(ricf);
    document.getElementById('r_its_net').textContent = '- ' + fmt(itsNet);
    document.getElementById('r_parts').textContent = parts.toFixed(1);
    document.getElementById('parts_val').textContent = parts.toFixed(1) + ' parts';
    document.getElementById('r_net').textContent = fmt(net);
    document.getElementById('r_cnps_pat').textContent = fmt(cnpsPat);
    document.getElementById('r_pf').textContent = fmt(pf);
    document.getElementById('r_am').textContent = fmt(am);
    document.getElementById('r_at').textContent = fmt(at);
    document.getElementById('r_cmu_pat').textContent = fmt(cmuPat);
    document.getElementById('r_cntafdfp').textContent = fmt(cn + ta + fdfp);
    document.getElementById('r_cout').textContent = fmt(coutTotal);

    // CE (expatriés)
    document.getElementById('row_ce').classList.toggle('hidden', nationalite !== 'expatrie');
    document.getElementById('r_ce').textContent = fmt(ce);
}

function chargerEmploye(id) {
    if (!id || !EMPLOYES[id]) return;
    const e = EMPLOYES[id];
    document.getElementById('salaire_base').value = e.salaire_base;
    document.getElementById('indemnite_transport').value = e.transport;
    document.getElementById('situation_famille').value = e.situation;
    document.getElementById('nb_enfants').value = e.enfants;
    document.getElementById('nationalite').value = e.nationalite;
    recalculer();
}

// Calcul initial
document.addEventListener('DOMContentLoaded', recalculer);
</script>
</div>
</body>
</html>
