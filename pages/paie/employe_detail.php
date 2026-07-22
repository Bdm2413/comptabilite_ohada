<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: employes.php'); exit; }

$stmt = $db->prepare("SELECT * FROM paie_employes WHERE id=? AND societe_id=?");
$stmt->execute([$id, $societe_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { header('Location: employes.php'); exit; }

// Supérieur hiérarchique
$superieur = null;
if ($emp['superieur_id']) {
    $s = $db->prepare("SELECT id, nom, prenom, poste, photo FROM paie_employes WHERE id=? AND societe_id=?");
    $s->execute([$emp['superieur_id'], $societe_id]);
    $superieur = $s->fetch(PDO::FETCH_ASSOC);
}

// Subordonnés directs
$subStmt = $db->prepare("SELECT id, nom, prenom, poste, photo, actif FROM paie_employes WHERE superieur_id=? AND societe_id=? ORDER BY nom, prenom");
$subStmt->execute([$id, $societe_id]);
$subordonnes = $subStmt->fetchAll(PDO::FETCH_ASSOC);

// Derniers bulletins (12 derniers)
$bStmt = $db->prepare("SELECT * FROM paie_bulletins WHERE employe_id=? AND societe_id=? ORDER BY annee DESC, mois DESC LIMIT 12");
$bStmt->execute([$id, $societe_id]);
$bulletins = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques bulletins
$statsStmt = $db->prepare("SELECT
    COUNT(*) as nb_total,
    SUM(CASE WHEN statut='valide' OR statut='comptabilise' THEN 1 ELSE 0 END) as nb_valides,
    AVG(net_a_payer) as moy_net,
    MAX(net_a_payer) as max_net,
    MIN(CASE WHEN net_a_payer > 0 THEN net_a_payer END) as min_net
    FROM paie_bulletins WHERE employe_id=? AND societe_id=?");
$statsStmt->execute([$id, $societe_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Ancienneté
$anciennete = '';
if ($emp['date_embauche']) {
    $debut = new DateTime($emp['date_embauche']);
    $fin   = $emp['date_depart'] ? new DateTime($emp['date_depart']) : new DateTime();
    $diff  = $debut->diff($fin);
    $parts = [];
    if ($diff->y > 0) $parts[] = $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) $parts[] = $diff->m . ' mois';
    if (empty($parts))  $parts[] = $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
    $anciennete = implode(' ', $parts);
}

$MOIS = ['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
$tcColors = [
    'CDI'    => 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20',
    'CDD'    => 'text-blue-400 bg-blue-500/10 border-blue-500/20',
    'Stage'  => 'text-amber-400 bg-amber-500/10 border-amber-500/20',
    'Interim'=> 'text-orange-400 bg-orange-500/10 border-orange-500/20',
];
$statutColors = [
    'brouillon'    => 'text-slate-400 bg-slate-700/50',
    'valide'       => 'text-blue-400 bg-blue-500/10',
    'comptabilise' => 'text-emerald-400 bg-emerald-500/10',
];

function fmtDate($d, $fmt = 'd/m/Y') {
    return $d ? date($fmt, strtotime($d)) : '—';
}
function fmtMontant($n) {
    return $n !== null ? number_format((float)$n, 0, ',', ' ') . ' FCFA' : '—';
}
function initiales($nom, $prenom = '') {
    return strtoupper(substr($nom,0,1) . substr($prenom,0,1));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($emp['nom'].' '.$emp['prenom']); ?> | <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        .info-row { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; border-bottom: 1px solid rgb(51 65 85 / 0.4); }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-label { font-size: 10px; color: rgb(100 116 139); min-width: 130px; flex-shrink: 0; padding-top: 1px; }
        .info-value { font-size: 12px; color: rgb(226 232 240); word-break: break-word; }
        .section-title { font-size: 11px; font-weight: 600; letter-spacing: .04em; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .card { background: rgb(30 41 59 / 0.5); border: 1px solid rgb(51 65 85 / 0.5); border-radius: 12px; padding: 16px; }
        @media print {
            aside, header, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-break { break-before: page; }
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
<?php include '../../includes/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0">
    <!-- Header -->
    <header class="h-12 bg-slate-800/50 border-b border-slate-700/50 flex items-center px-4 gap-3 flex-shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <a href="employes.php" class="p-1 hover:bg-slate-700/50 rounded-lg text-slate-400 hover:text-slate-200 transition flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-xs"></i>
            </a>
            <div class="w-6 h-6 bg-violet-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-user text-violet-400 text-xs"></i>
            </div>
            <nav class="flex items-center gap-1.5 text-xs min-w-0">
                <a href="employes.php" class="text-slate-400 hover:text-slate-200 transition">Employés</a>
                <i class="fa-solid fa-chevron-right text-slate-600 text-[9px]"></i>
                <span class="text-white font-medium truncate"><?php echo htmlspecialchars($emp['nom'].' '.$emp['prenom']); ?></span>
            </nav>
        </div>
        <div class="flex items-center gap-2 no-print">
            <button onclick="window.print()" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/60 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-print text-xs"></i> Imprimer
            </button>
            <a href="bulletin.php?employe_id=<?php echo $emp['id']; ?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/60 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-file-invoice-dollar text-xs"></i> Bulletin
            </a>
            <a href="employe_form.php?edit=<?php echo $emp['id']; ?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition">
                <i class="fa-solid fa-pen-to-square text-xs"></i> Modifier
            </a>
        </div>
    </header>

    <div class="flex-1 overflow-auto p-5">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            <!-- ══ Colonne gauche ══ -->
            <div class="lg:col-span-1 space-y-4">

                <!-- Carte profil -->
                <div class="card text-center">
                    <!-- Photo -->
                    <div class="flex justify-center mb-4">
                        <div class="w-24 h-24 rounded-2xl overflow-hidden flex-shrink-0 <?php echo !empty($emp['photo']) ? '' : 'bg-gradient-to-br from-violet-500/30 to-violet-700/20 flex items-center justify-center'; ?>">
                            <?php if (!empty($emp['photo'])): ?>
                            <img src="../../<?php echo htmlspecialchars($emp['photo']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                            <span class="text-2xl font-bold text-violet-300"><?php echo initiales($emp['nom'], $emp['prenom'] ?? ''); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Nom & poste -->
                    <h2 class="text-lg font-bold text-white"><?php echo htmlspecialchars($emp['nom'].' '.($emp['prenom'] ?? '')); ?></h2>
                    <?php if ($emp['poste']): ?>
                    <p class="text-sm text-slate-400 mt-0.5"><?php echo htmlspecialchars($emp['poste']); ?></p>
                    <?php endif; ?>
                    <?php if ($emp['departement']): ?>
                    <p class="text-xs text-slate-500 mt-1">
                        <i class="fa-solid fa-building text-[10px] mr-1"></i><?php echo htmlspecialchars($emp['departement']); ?>
                    </p>
                    <?php endif; ?>

                    <!-- Badges -->
                    <div class="flex items-center justify-center gap-2 flex-wrap mt-3">
                        <?php
                        $tc = $emp['type_contrat'] ?? 'CDI';
                        $tcC = $tcColors[$tc] ?? 'text-slate-400 bg-slate-700/50 border-slate-600/30';
                        ?>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-semibold <?php echo $tcC; ?>"><?php echo $tc; ?></span>
                        <?php if ($emp['date_depart']): ?>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-semibold text-red-400 bg-red-500/10 border-red-500/20">Parti</span>
                        <?php elseif ($emp['actif']): ?>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-semibold text-emerald-400 bg-emerald-500/10 border-emerald-500/20">Actif</span>
                        <?php else: ?>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-semibold text-slate-400 bg-slate-700/50 border-slate-600/30">Inactif</span>
                        <?php endif; ?>
                        <?php if ($emp['nationalite'] === 'expatrie'): ?>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-semibold text-orange-400 bg-orange-500/10 border-orange-500/20">Expatrié</span>
                        <?php endif; ?>
                    </div>

                    <!-- Ancienneté -->
                    <?php if ($anciennete): ?>
                    <div class="mt-4 p-3 bg-slate-700/30 rounded-xl">
                        <p class="text-[10px] text-slate-500 mb-0.5">Ancienneté</p>
                        <p class="text-sm font-semibold text-white"><?php echo $anciennete; ?></p>
                        <p class="text-[10px] text-slate-500">depuis le <?php echo fmtDate($emp['date_embauche']); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Matricule -->
                    <?php if ($emp['matricule']): ?>
                    <div class="mt-3">
                        <span class="text-[10px] text-slate-500">Matricule · </span>
                        <span class="text-xs font-mono text-slate-300"><?php echo htmlspecialchars($emp['matricule']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Statistiques paie -->
                <?php if ($stats['nb_total'] > 0): ?>
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-chart-bar text-violet-400"></i> Statistiques paie</p>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div class="bg-slate-700/30 rounded-xl p-3 text-center">
                            <p class="text-xl font-bold text-white"><?php echo $stats['nb_total']; ?></p>
                            <p class="text-[10px] text-slate-500">bulletins</p>
                        </div>
                        <div class="bg-slate-700/30 rounded-xl p-3 text-center">
                            <p class="text-xl font-bold text-emerald-300"><?php echo $stats['nb_valides']; ?></p>
                            <p class="text-[10px] text-slate-500">validés</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] text-slate-500">Net moyen</span>
                            <span class="text-xs font-semibold text-white font-mono"><?php echo fmtMontant($stats['moy_net']); ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] text-slate-500">Net maximum</span>
                            <span class="text-xs text-emerald-400 font-mono"><?php echo fmtMontant($stats['max_net']); ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] text-slate-500">Net minimum</span>
                            <span class="text-xs text-slate-400 font-mono"><?php echo fmtMontant($stats['min_net']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact -->
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-address-book text-sky-400"></i> Contact</p>
                    <?php if ($emp['telephone'] || $emp['email']): ?>
                    <div class="space-y-2">
                        <?php if ($emp['telephone']): ?>
                        <a href="tel:<?php echo htmlspecialchars($emp['telephone']); ?>" class="flex items-center gap-3 p-2 bg-slate-700/30 rounded-lg hover:bg-slate-700/50 transition group">
                            <div class="w-7 h-7 rounded-lg bg-sky-500/15 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-phone text-sky-400 text-[10px]"></i>
                            </div>
                            <span class="text-xs text-slate-300 group-hover:text-white transition"><?php echo htmlspecialchars($emp['telephone']); ?></span>
                        </a>
                        <?php endif; ?>
                        <?php if ($emp['email']): ?>
                        <a href="mailto:<?php echo htmlspecialchars($emp['email']); ?>" class="flex items-center gap-3 p-2 bg-slate-700/30 rounded-lg hover:bg-slate-700/50 transition group">
                            <div class="w-7 h-7 rounded-lg bg-sky-500/15 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-envelope text-sky-400 text-[10px]"></i>
                            </div>
                            <span class="text-xs text-slate-300 group-hover:text-white transition truncate"><?php echo htmlspecialchars($emp['email']); ?></span>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-slate-600">Aucun contact renseigné.</p>
                    <?php endif; ?>
                </div>

                <!-- Supérieur & Subordonnés -->
                <?php if ($superieur || !empty($subordonnes)): ?>
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-sitemap text-indigo-400"></i> Hiérarchie</p>
                    <?php if ($superieur): ?>
                    <p class="text-[10px] text-slate-500 mb-1.5 uppercase tracking-wider">Supérieur</p>
                    <a href="employe_detail.php?id=<?php echo $superieur['id']; ?>" class="flex items-center gap-2.5 p-2 bg-slate-700/30 rounded-xl hover:bg-slate-700/50 transition mb-3">
                        <div class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0 bg-slate-600/50 flex items-center justify-center">
                            <?php if (!empty($superieur['photo'])): ?>
                            <img src="../../<?php echo htmlspecialchars($superieur['photo']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                            <span class="text-[10px] font-bold text-slate-300"><?php echo initiales($superieur['nom'], $superieur['prenom'] ?? ''); ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-white"><?php echo htmlspecialchars($superieur['nom'].' '.($superieur['prenom'] ?? '')); ?></p>
                            <?php if ($superieur['poste']): ?><p class="text-[10px] text-slate-500"><?php echo htmlspecialchars($superieur['poste']); ?></p><?php endif; ?>
                        </div>
                        <i class="fa-solid fa-chevron-right text-slate-600 text-[9px] ml-auto"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($subordonnes)): ?>
                    <p class="text-[10px] text-slate-500 mb-1.5 uppercase tracking-wider">Subordonnés (<?php echo count($subordonnes); ?>)</p>
                    <div class="space-y-1.5">
                        <?php foreach ($subordonnes as $sub): ?>
                        <a href="employe_detail.php?id=<?php echo $sub['id']; ?>" class="flex items-center gap-2 p-2 bg-slate-700/30 rounded-lg hover:bg-slate-700/50 transition <?php echo !$sub['actif'] ? 'opacity-50' : ''; ?>">
                            <div class="w-6 h-6 rounded-lg overflow-hidden flex-shrink-0 bg-violet-500/20 flex items-center justify-center">
                                <?php if (!empty($sub['photo'])): ?>
                                <img src="../../<?php echo htmlspecialchars($sub['photo']); ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                <span class="text-[9px] font-bold text-violet-300"><?php echo initiales($sub['nom'], $sub['prenom'] ?? ''); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs text-slate-300 truncate"><?php echo htmlspecialchars($sub['nom'].' '.($sub['prenom'] ?? '')); ?></p>
                                <?php if ($sub['poste']): ?><p class="text-[9px] text-slate-500 truncate"><?php echo htmlspecialchars($sub['poste']); ?></p><?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══ Colonne droite ══ -->
            <div class="lg:col-span-2 space-y-4">

                <!-- Informations personnelles -->
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-id-card text-violet-400"></i> Informations personnelles</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-venus-mars text-[9px] mr-1 text-slate-600"></i>Sexe</span>
                                <span class="info-value"><?php echo $emp['sexe'] === 'M' ? 'Masculin' : ($emp['sexe'] === 'F' ? 'Féminin' : '—'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-cake-candles text-[9px] mr-1 text-slate-600"></i>Date de naissance</span>
                                <span class="info-value"><?php echo fmtDate($emp['date_naissance']); ?>
                                    <?php if ($emp['date_naissance']): ?>
                                    <span class="text-slate-500 text-[10px] ml-1">(<?php echo (int)((new DateTime())->diff(new DateTime($emp['date_naissance']))->y); ?> ans)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-location-dot text-[9px] mr-1 text-slate-600"></i>Lieu de naissance</span>
                                <span class="info-value"><?php echo $emp['lieu_naissance'] ? htmlspecialchars($emp['lieu_naissance']) : '—'; ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-flag text-[9px] mr-1 text-slate-600"></i>Nationalité</span>
                                <span class="info-value"><?php echo $emp['nationalite_civile'] ? htmlspecialchars($emp['nationalite_civile']) : '—'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-id-badge text-[9px] mr-1 text-slate-600"></i>Pièce d'identité</span>
                                <span class="info-value font-mono"><?php echo $emp['num_piece_identite'] ? htmlspecialchars($emp['num_piece_identite']) : '—'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-heart text-[9px] mr-1 text-slate-600"></i>Situation de famille</span>
                                <span class="info-value">
                                    <?php echo $emp['situation_famille'] === 'M' ? 'Marié(e)' : 'Célibataire'; ?>
                                    <?php if ($emp['nb_enfants'] > 0): ?>
                                    · <span class="text-slate-400"><?php echo $emp['nb_enfants']; ?> enfant<?php echo $emp['nb_enfants'] > 1 ? 's' : ''; ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contrat & Emploi -->
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-briefcase text-blue-400"></i> Contrat &amp; Emploi</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-file-contract text-[9px] mr-1 text-slate-600"></i>Type de contrat</span>
                                <span class="info-value">
                                    <?php $tc = $emp['type_contrat'] ?? 'CDI'; $tcC2 = $tcColors[$tc] ?? ''; ?>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold border <?php echo $tcC2; ?>"><?php echo $tc; ?></span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-calendar-plus text-[9px] mr-1 text-slate-600"></i>Date d'embauche</span>
                                <span class="info-value"><?php echo fmtDate($emp['date_embauche']); ?></span>
                            </div>
                            <?php if ($emp['date_fin_contrat']): ?>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-calendar-xmark text-[9px] mr-1 text-slate-600"></i>Fin de contrat</span>
                                <span class="info-value text-amber-400"><?php echo fmtDate($emp['date_fin_contrat']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-hourglass text-[9px] mr-1 text-slate-600"></i>Ancienneté</span>
                                <span class="info-value text-emerald-300"><?php echo $anciennete ?: '—'; ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-user-tie text-[9px] mr-1 text-slate-600"></i>Poste</span>
                                <span class="info-value"><?php echo $emp['poste'] ? htmlspecialchars($emp['poste']) : '—'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-layer-group text-[9px] mr-1 text-slate-600"></i>Catégorie</span>
                                <span class="info-value"><?php echo $emp['categorie'] ? htmlspecialchars($emp['categorie']) : '—'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-building text-[9px] mr-1 text-slate-600"></i>Département</span>
                                <span class="info-value"><?php echo $emp['departement'] ? htmlspecialchars($emp['departement']) : '—'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paie & Cotisations -->
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-coins text-emerald-400"></i> Paie &amp; Cotisations</p>

                    <!-- Montants clés -->
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div class="bg-slate-700/30 rounded-xl p-3 text-center">
                            <p class="text-[10px] text-slate-500 mb-1">Salaire de base</p>
                            <p class="text-base font-bold text-emerald-300 font-mono"><?php echo number_format($emp['salaire_base'], 0, ',', ' '); ?></p>
                            <p class="text-[9px] text-slate-600">FCFA</p>
                        </div>
                        <div class="bg-slate-700/30 rounded-xl p-3 text-center">
                            <p class="text-[10px] text-slate-500 mb-1">Transport</p>
                            <p class="text-base font-bold text-sky-300 font-mono"><?php echo number_format($emp['indemnite_transport'] ?? 0, 0, ',', ' '); ?></p>
                            <p class="text-[9px] text-slate-600">FCFA</p>
                        </div>
                        <div class="bg-slate-700/30 rounded-xl p-3 text-center">
                            <p class="text-[10px] text-slate-500 mb-1">Brut mensuel</p>
                            <p class="text-base font-bold text-white font-mono"><?php echo number_format($emp['salaire_base'] + ($emp['indemnite_transport'] ?? 0), 0, ',', ' '); ?></p>
                            <p class="text-[9px] text-slate-600">FCFA</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-percent text-[9px] mr-1 text-slate-600"></i>Statut fiscal</span>
                                <span class="info-value"><?php echo $emp['nationalite'] === 'expatrie' ? '<span class="text-orange-400">Expatrié(e)</span>' : 'Locale'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-shield text-[9px] mr-1 text-slate-600"></i>N° CNPS</span>
                                <span class="info-value font-mono"><?php echo $emp['num_cnps'] ? htmlspecialchars($emp['num_cnps']) : '—'; ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-book text-[9px] mr-1 text-slate-600"></i>Compte de charge</span>
                                <span class="info-value font-mono text-violet-300"><?php echo htmlspecialchars($emp['compte_charge'] ?? '6611'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fa-solid fa-children text-[9px] mr-1 text-slate-600"></i>Parts RICF</span>
                                <span class="info-value">
                                    <?php
                                    $parts = ($emp['situation_famille'] === 'M') ? 2 : 1;
                                    if ($emp['nb_enfants'] >= 1) $parts += 1;
                                    if ($emp['nb_enfants'] >= 2) $parts += 0.5 * ($emp['nb_enfants'] - 1);
                                    echo number_format($parts, 1);
                                    ?> parts
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coordonnées bancaires -->
                <?php if ($emp['banque'] || $emp['num_compte_bancaire']): ?>
                <div class="card">
                    <p class="section-title text-slate-300"><i class="fa-solid fa-building-columns text-amber-400"></i> Coordonnées bancaires</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div class="info-row">
                            <span class="info-label"><i class="fa-solid fa-university text-[9px] mr-1 text-slate-600"></i>Banque</span>
                            <span class="info-value"><?php echo $emp['banque'] ? htmlspecialchars($emp['banque']) : '—'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fa-solid fa-credit-card text-[9px] mr-1 text-slate-600"></i>N° de compte</span>
                            <span class="info-value font-mono"><?php echo $emp['num_compte_bancaire'] ? htmlspecialchars($emp['num_compte_bancaire']) : '—'; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Départ -->
                <?php if ($emp['date_depart']): ?>
                <div class="card border-red-500/20 bg-red-500/5">
                    <p class="section-title text-red-300/80"><i class="fa-solid fa-door-open text-red-400/80"></i> Départ de l'entreprise</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div class="info-row">
                            <span class="info-label">Date de départ</span>
                            <span class="info-value text-red-400"><?php echo fmtDate($emp['date_depart']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Motif</span>
                            <span class="info-value"><?php echo $emp['motif_depart'] ? htmlspecialchars($emp['motif_depart']) : '—'; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Historique des bulletins -->
                <div class="card">
                    <div class="flex items-center justify-between mb-4">
                        <p class="section-title text-slate-300 mb-0"><i class="fa-solid fa-file-invoice-dollar text-violet-400"></i> Historique des bulletins</p>
                        <a href="bulletin.php?employe_id=<?php echo $emp['id']; ?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-violet-600/20 hover:bg-violet-600/30 text-violet-300 rounded-lg text-xs transition no-print">
                            <i class="fa-solid fa-plus text-[10px]"></i> Nouveau bulletin
                        </a>
                    </div>

                    <?php if (empty($bulletins)): ?>
                    <div class="py-8 text-center text-slate-600">
                        <i class="fa-solid fa-file-circle-xmark text-2xl mb-2 block opacity-30"></i>
                        <p class="text-xs">Aucun bulletin généré pour cet employé.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="border-b border-slate-700/50">
                                    <th class="text-left py-2 pr-4 text-slate-500 font-medium">Période</th>
                                    <th class="text-right py-2 px-3 text-slate-500 font-medium">Brut</th>
                                    <th class="text-right py-2 px-3 text-slate-500 font-medium">Net à payer</th>
                                    <th class="text-right py-2 px-3 text-slate-500 font-medium">ITS</th>
                                    <th class="text-right py-2 px-3 text-slate-500 font-medium">Coût employeur</th>
                                    <th class="text-center py-2 px-3 text-slate-500 font-medium">Statut</th>
                                    <th class="text-right py-2 text-slate-500 font-medium no-print"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/30">
                                <?php foreach ($bulletins as $b): ?>
                                <tr class="hover:bg-slate-700/20 transition-colors">
                                    <td class="py-2.5 pr-4">
                                        <span class="font-semibold text-white"><?php echo $MOIS[$b['mois']]; ?> <?php echo $b['annee']; ?></span>
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-mono text-slate-300"><?php echo number_format($b['salaire_brut'], 0, ',', ' '); ?></td>
                                    <td class="py-2.5 px-3 text-right font-mono font-semibold text-emerald-300"><?php echo number_format($b['net_a_payer'], 0, ',', ' '); ?></td>
                                    <td class="py-2.5 px-3 text-right font-mono text-red-400/80"><?php echo number_format($b['its_net'], 0, ',', ' '); ?></td>
                                    <td class="py-2.5 px-3 text-right font-mono text-slate-400"><?php echo number_format($b['cout_total_employeur'], 0, ',', ' '); ?></td>
                                    <td class="py-2.5 px-3 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $statutColors[$b['statut']] ?? ''; ?>">
                                            <?php echo ucfirst($b['statut']); ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 text-right no-print">
                                        <a href="voir_bulletin.php?id=<?php echo $b['id']; ?>" class="p-1.5 hover:bg-slate-700/60 rounded-lg text-slate-500 hover:text-violet-400 transition inline-flex items-center" title="Voir le bulletin">
                                            <i class="fa-solid fa-eye text-[10px]"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($bulletins) === 12): ?>
                    <p class="text-[10px] text-slate-600 text-center mt-3">12 derniers bulletins affichés · <a href="livre_paie.php" class="text-violet-400/70 hover:text-violet-400">Voir le livre de paie</a></p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof toggleAccordion === 'function') toggleAccordion('paie');
});
</script>
</div>
</body>
</html>
