<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();

$exerciceActif = getExerciceActif();
if (!$exerciceActif || !is_array($exerciceActif)) {
    $exerciceActif = ['annee' => date('Y'), 'statut' => 'Non défini'];
}
$annee = intval($exerciceActif['annee']);

// ── 1. Trésorerie ─────────────────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN pc.type = 'Caisse' THEN le.debit - le.credit ELSE 0 END) as caisse,
            SUM(CASE WHEN pc.type = 'Banque' THEN le.debit - le.credit ELSE 0 END) as banque
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.type IN ('Caisse', 'Banque')
    ");
    $stmt->execute([$societe_id]);
    $tresoData = $stmt->fetch();
    $tresorerieCaisse = floatval($tresoData['caisse'] ?? 0);
    $tresorerieBanque = floatval($tresoData['banque'] ?? 0);
    $tresorerieTotal  = $tresorerieCaisse + $tresorerieBanque;
} catch (Exception $e) {
    $tresorerieCaisse = $tresorerieBanque = $tresorerieTotal = 0;
}

// ── 2. Résultat net de l'exercice ─────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT
            SUM(CASE
                WHEN pc.type = 'Produit' THEN le.credit - le.debit
                WHEN pc.type = 'Résultat-Gestion'
                     AND CAST(LEFT(pc.compte, 2) AS UNSIGNED) % 2 = 0
                     THEN le.credit - le.debit
                ELSE 0
            END) as produits,
            SUM(CASE
                WHEN pc.type = 'Charge' THEN le.debit - le.credit
                WHEN pc.type = 'Résultat-Gestion'
                     AND CAST(LEFT(pc.compte, 2) AS UNSIGNED) % 2 = 1
                     THEN le.debit - le.credit
                ELSE 0
            END) as charges
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE e.statut = 'Validé'
        AND e.annee = ?
        AND e.societe_id = ?
        AND pc.type IN ('Produit', 'Charge', 'Résultat-Gestion')
    ");
    $stmt->execute([$annee, $societe_id]);
    $resultatData = $stmt->fetch();
    $totalProduits = floatval($resultatData['produits'] ?? 0);
    $totalCharges  = floatval($resultatData['charges'] ?? 0);
    $resultatNet   = $totalProduits - $totalCharges;
} catch (Exception $e) {
    $totalProduits = $totalCharges = $resultatNet = 0;
}

// ── 3. Créances clients (comptes de type Client) ──────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(le.debit) - SUM(le.credit), 0) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.type = 'Client'
    ");
    $stmt->execute([$societe_id]);
    $creancesClients = floatval($stmt->fetchColumn() ?? 0);
} catch (Exception $e) { $creancesClients = 0; }

// ── 4. Dettes fournisseurs (comptes de type Fournisseur) ──────────────────────
try {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(le.credit) - SUM(le.debit), 0) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        WHERE e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.type = 'Fournisseur'
    ");
    $stmt->execute([$societe_id]);
    $dettesFournisseurs = floatval($stmt->fetchColumn() ?? 0);
} catch (Exception $e) { $dettesFournisseurs = 0; }

// ── 5. Activité du mois courant ────────────────────────────────────────────────
$moisActuel = date('Y-m');
try {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'Validé'    THEN 1 ELSE 0 END) as validees,
            SUM(CASE WHEN statut = 'Brouillon' THEN 1 ELSE 0 END) as brouillons
        FROM ecritures
        WHERE DATE_FORMAT(date_ecriture, '%Y-%m') = ? AND societe_id = ?
    ");
    $stmt->execute([$moisActuel, $societe_id]);
    $activiteMois = $stmt->fetch();
} catch (Exception $e) {
    $activiteMois = ['total' => 0, 'validees' => 0, 'brouillons' => 0];
}

// ── 6. Brouillons en attente (tous exercices) ──────────────────────────────────
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM ecritures WHERE statut = 'Brouillon' AND societe_id = ?");
    $stmt->execute([$societe_id]);
    $totalBrouillons = intval($stmt->fetchColumn());
} catch (Exception $e) { $totalBrouillons = 0; }

// ── 7. Top 5 clients par solde (tiers individuels depuis plan_tiers) ─────────
try {
    $stmt = $db->prepare("
        SELECT
            le.compte_tiers,
            COALESCE(MAX(pt.abreviation), MAX(pt.nom), le.compte_tiers) as nom_client,
            SUM(le.debit) - SUM(le.credit) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        INNER JOIN plan_tiers pt ON le.compte_tiers = pt.compte_tiers
        WHERE e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.type = 'Client'
        AND le.compte_tiers IS NOT NULL AND le.compte_tiers != ''
        GROUP BY le.compte_tiers
        HAVING solde > 0.01
        ORDER BY solde DESC
        LIMIT 5
    ");
    $stmt->execute([$societe_id]);
    $topClients = $stmt->fetchAll();
} catch (Exception $e) { $topClients = []; }

// ── 8. Top 5 fournisseurs par solde (tiers individuels depuis plan_tiers) ────
try {
    $stmt = $db->prepare("
        SELECT
            le.compte_tiers,
            COALESCE(MAX(pt.abreviation), MAX(pt.nom), le.compte_tiers) as nom_fournisseur,
            SUM(le.credit) - SUM(le.debit) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        INNER JOIN plan_comptable pc ON le.compte = pc.compte AND pc.societe_id = e.societe_id
        INNER JOIN plan_tiers pt ON le.compte_tiers = pt.compte_tiers
        WHERE e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.type = 'Fournisseur'
        AND le.compte_tiers IS NOT NULL AND le.compte_tiers != ''
        GROUP BY le.compte_tiers
        HAVING solde > 0.01
        ORDER BY solde DESC
        LIMIT 5
    ");
    $stmt->execute([$societe_id]);
    $topFournisseurs = $stmt->fetchAll();
} catch (Exception $e) { $topFournisseurs = []; }

// ── 9. Dernières écritures ─────────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT e.id, e.numero_ecriture, e.date_ecriture, e.journal, e.libelle, e.statut,
               SUM(le.debit) as total_debit
        FROM ecritures e
        LEFT JOIN lignes_ecriture le ON le.id_ecriture = e.id
        WHERE e.societe_id = ?
        GROUP BY e.id, e.numero_ecriture, e.date_ecriture, e.journal, e.libelle, e.statut
        ORDER BY e.date_creation DESC
        LIMIT 8
    ");
    $stmt->execute([$societe_id]);
    $dernieresEcritures = $stmt->fetchAll();
} catch (Exception $e) { $dernieresEcritures = []; }

// ── Helpers ────────────────────────────────────────────────────────────────────
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vue d'ensemble - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
    <?php include '../../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <!-- Header -->
        <header class="bg-slate-800/30 border-b border-slate-700/50 p-4 sticky top-0 z-10 backdrop-blur">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-teal-400 to-cyan-500">
                        <i class="fas fa-chart-pie mr-2"></i>Vue d'ensemble
                    </h1>
                    <p class="text-slate-400 text-sm mt-0.5">
                        Exercice <?php echo $annee; ?> &mdash; <?php echo htmlspecialchars($exerciceActif['statut']); ?>
                        &nbsp;&bull;&nbsp; Mise à jour : <?php echo date('d/m/Y à H:i'); ?>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($totalBrouillons > 0): ?>
                    <a href="../ecritures/liste.php?statut=Brouillon" class="flex items-center gap-2 px-3 py-1.5 bg-yellow-500/10 border border-yellow-500/40 text-yellow-400 rounded-lg text-xs hover:bg-yellow-500/20 transition">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $totalBrouillons; ?> brouillon<?php echo $totalBrouillons > 1 ? 's' : ''; ?> en attente
                    </a>
                    <?php endif; ?>
                    <a href="../ecritures/saisie.php" class="flex items-center gap-2 px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-xs font-medium transition">
                        <i class="fas fa-plus"></i> Nouvelle écriture
                    </a>
                </div>
            </div>
        </header>

        <div class="p-6 space-y-6">

            <!-- ── Ligne 1 : 4 KPI principaux ─────────────────────────────── -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">

                <!-- Trésorerie -->
                <div class="kpi-card bg-gradient-to-br from-teal-500/10 to-cyan-600/10 border border-teal-500/30 rounded-xl p-5 hover:border-teal-400/50 transition">
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-teal-500/20 rounded-lg">
                            <i class="fas fa-wallet text-teal-400 text-xl w-6 text-center"></i>
                        </div>
                        <span class="text-xs px-2 py-1 bg-teal-500/20 text-teal-300 rounded-full">Trésorerie</span>
                    </div>
                    <p class="text-2xl font-bold text-white mb-1"><?php echo fmt($tresorerieTotal); ?> <span class="text-sm font-normal text-slate-400">FCFA</span></p>
                    <p class="text-xs text-slate-400 mb-3">Solde total disponible</p>
                    <div class="pt-3 border-t border-teal-500/20 flex gap-4 text-xs">
                        <div>
                            <p class="text-slate-500">Caisse</p>
                            <p class="text-teal-400 font-medium"><?php echo fmt($tresorerieCaisse); ?></p>
                        </div>
                        <div>
                            <p class="text-slate-500">Banque</p>
                            <p class="text-cyan-400 font-medium"><?php echo fmt($tresorerieBanque); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Résultat net -->
                <?php $resultatPositif = $resultatNet >= 0; ?>
                <div class="kpi-card bg-gradient-to-br <?php echo $resultatPositif ? 'from-emerald-500/10 to-green-600/10 border-emerald-500/30' : 'from-red-500/10 to-rose-600/10 border-red-500/30'; ?> border rounded-xl p-5 hover:border-opacity-70 transition">
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 <?php echo $resultatPositif ? 'bg-emerald-500/20' : 'bg-red-500/20'; ?> rounded-lg">
                            <i class="fas <?php echo $resultatPositif ? 'fa-arrow-trend-up text-emerald-400' : 'fa-arrow-trend-down text-red-400'; ?> text-xl w-6 text-center"></i>
                        </div>
                        <span class="text-xs px-2 py-1 <?php echo $resultatPositif ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'; ?> rounded-full">Résultat <?php echo $annee; ?></span>
                    </div>
                    <p class="text-2xl font-bold <?php echo $resultatPositif ? 'text-emerald-400' : 'text-red-400'; ?> mb-1"><?php echo ($resultatNet < 0 ? '-' : '') . fmt(abs($resultatNet)); ?> <span class="text-sm font-normal text-slate-400">FCFA</span></p>
                    <p class="text-xs text-slate-400 mb-3">Résultat net provisoire</p>
                    <div class="pt-3 border-t <?php echo $resultatPositif ? 'border-emerald-500/20' : 'border-red-500/20'; ?> flex gap-4 text-xs">
                        <div>
                            <p class="text-slate-500">Produits</p>
                            <p class="text-green-400 font-medium"><?php echo fmt($totalProduits); ?></p>
                        </div>
                        <div>
                            <p class="text-slate-500">Charges</p>
                            <p class="text-red-400 font-medium"><?php echo fmt($totalCharges); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Créances clients -->
                <div class="kpi-card bg-gradient-to-br from-amber-500/10 to-orange-600/10 border border-amber-500/30 rounded-xl p-5 hover:border-amber-400/50 transition">
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-amber-500/20 rounded-lg">
                            <i class="fas fa-file-invoice-dollar text-amber-400 text-xl w-6 text-center"></i>
                        </div>
                        <span class="text-xs px-2 py-1 bg-amber-500/20 text-amber-300 rounded-full">Clients</span>
                    </div>
                    <p class="text-2xl font-bold text-white mb-1"><?php echo fmt($creancesClients); ?> <span class="text-sm font-normal text-slate-400">FCFA</span></p>
                    <p class="text-xs text-slate-400 mb-3">Créances à encaisser</p>
                    <div class="pt-3 border-t border-amber-500/20">
                        <a href="../rapports/balance_agee_clients.php" class="text-xs text-amber-400 hover:text-amber-300 transition">
                            <i class="fas fa-arrow-right mr-1"></i>Voir la balance âgée
                        </a>
                    </div>
                </div>

                <!-- Dettes fournisseurs -->
                <div class="kpi-card bg-gradient-to-br from-rose-500/10 to-pink-600/10 border border-rose-500/30 rounded-xl p-5 hover:border-rose-400/50 transition">
                    <div class="flex items-center justify-between mb-3">
                        <div class="p-2.5 bg-rose-500/20 rounded-lg">
                            <i class="fas fa-receipt text-rose-400 text-xl w-6 text-center"></i>
                        </div>
                        <span class="text-xs px-2 py-1 bg-rose-500/20 text-rose-300 rounded-full">Fournisseurs</span>
                    </div>
                    <p class="text-2xl font-bold text-white mb-1"><?php echo fmt($dettesFournisseurs); ?> <span class="text-sm font-normal text-slate-400">FCFA</span></p>
                    <p class="text-xs text-slate-400 mb-3">Dettes à régler</p>
                    <div class="pt-3 border-t border-rose-500/20">
                        <a href="../rapports/balance_agee_fournisseurs.php" class="text-xs text-rose-400 hover:text-rose-300 transition">
                            <i class="fas fa-arrow-right mr-1"></i>Voir la balance âgée
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── Ligne 2 : Activité mois + Santé financière + Alertes ───── -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                <!-- Activité du mois -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <i class="fas fa-calendar-alt text-blue-400"></i>
                        <h3 class="font-semibold text-white text-sm">Activité — <?php
                            $moisNoms = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
                            echo $moisNoms[(int)date('n') - 1] . ' ' . date('Y');
                        ?></h3>
                    </div>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-slate-400">Total écritures ce mois</span>
                            <span class="text-sm font-bold text-white"><?php echo intval($activiteMois['total']); ?></span>
                        </div>
                        <!-- Barre validées -->
                        <?php
                        $total = max(intval($activiteMois['total']), 1);
                        $pctValid = round(intval($activiteMois['validees']) / $total * 100);
                        $pctBroui = round(intval($activiteMois['brouillons']) / $total * 100);
                        ?>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-emerald-400"><i class="fas fa-check-circle mr-1"></i>Validées</span>
                                <span class="text-emerald-400"><?php echo intval($activiteMois['validees']); ?></span>
                            </div>
                            <div class="w-full bg-slate-700 rounded-full h-1.5">
                                <div class="bg-emerald-500 h-1.5 rounded-full transition-all" style="width: <?php echo $pctValid; ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-yellow-400"><i class="fas fa-clock mr-1"></i>Brouillons</span>
                                <span class="text-yellow-400"><?php echo intval($activiteMois['brouillons']); ?></span>
                            </div>
                            <div class="w-full bg-slate-700 rounded-full h-1.5">
                                <div class="bg-yellow-500 h-1.5 rounded-full transition-all" style="width: <?php echo $pctBroui; ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-700/50">
                        <a href="../ecritures/liste.php" class="text-xs text-blue-400 hover:text-blue-300 transition">
                            <i class="fas fa-arrow-right mr-1"></i>Toutes les écritures
                        </a>
                    </div>
                </div>

                <!-- Santé financière -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <i class="fas fa-heartbeat text-pink-400"></i>
                        <h3 class="font-semibold text-white text-sm">Santé financière</h3>
                    </div>
                    <?php
                    $tauxCharges = $totalProduits > 0 ? round($totalCharges / $totalProduits * 100, 1) : 0;
                    $margeNette  = $totalProduits > 0 ? round($resultatNet / $totalProduits * 100, 1) : 0;
                    $couverture  = $dettesFournisseurs > 0 ? round($tresorerieTotal / $dettesFournisseurs * 100, 0) : 100;
                    ?>
                    <div class="space-y-4">
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-slate-400">Taux de charges</span>
                                <span class="<?php echo $tauxCharges > 90 ? 'text-red-400' : ($tauxCharges > 75 ? 'text-yellow-400' : 'text-emerald-400'); ?> font-medium"><?php echo $tauxCharges; ?>%</span>
                            </div>
                            <div class="w-full bg-slate-700 rounded-full h-2">
                                <div class="h-2 rounded-full <?php echo $tauxCharges > 90 ? 'bg-red-500' : ($tauxCharges > 75 ? 'bg-yellow-500' : 'bg-emerald-500'); ?>" style="width: <?php echo min($tauxCharges, 100); ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-slate-400">Marge nette</span>
                                <span class="<?php echo $margeNette < 0 ? 'text-red-400' : ($margeNette < 5 ? 'text-yellow-400' : 'text-emerald-400'); ?> font-medium"><?php echo $margeNette; ?>%</span>
                            </div>
                            <div class="w-full bg-slate-700 rounded-full h-2">
                                <div class="h-2 rounded-full <?php echo $margeNette < 0 ? 'bg-red-500' : ($margeNette < 5 ? 'bg-yellow-500' : 'bg-emerald-500'); ?>" style="width: <?php echo min(max($margeNette, 0), 100); ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-slate-400">Couverture dettes / tréso.</span>
                                <span class="<?php echo $couverture < 50 ? 'text-red-400' : ($couverture < 100 ? 'text-yellow-400' : 'text-emerald-400'); ?> font-medium"><?php echo min($couverture, 999); ?>%</span>
                            </div>
                            <div class="w-full bg-slate-700 rounded-full h-2">
                                <div class="h-2 rounded-full <?php echo $couverture < 50 ? 'bg-red-500' : ($couverture < 100 ? 'bg-yellow-500' : 'bg-emerald-500'); ?>" style="width: <?php echo min($couverture, 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alertes & Raccourcis -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <i class="fas fa-bell text-yellow-400"></i>
                        <h3 class="font-semibold text-white text-sm">Alertes & Actions rapides</h3>
                    </div>
                    <div class="space-y-2 mb-4">
                        <?php if ($totalBrouillons > 0): ?>
                        <a href="../ecritures/liste.php?statut=Brouillon" class="flex items-center gap-2 p-2 bg-yellow-500/10 border border-yellow-500/30 rounded-lg text-xs text-yellow-300 hover:bg-yellow-500/20 transition">
                            <i class="fas fa-exclamation-triangle text-yellow-400 w-3"></i>
                            <span><?php echo $totalBrouillons; ?> écriture<?php echo $totalBrouillons > 1 ? 's' : ''; ?> en brouillon</span>
                            <i class="fas fa-arrow-right ml-auto"></i>
                        </a>
                        <?php else: ?>
                        <div class="flex items-center gap-2 p-2 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-300">
                            <i class="fas fa-check-circle text-emerald-400 w-3"></i>
                            <span>Aucun brouillon en attente</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($tresorerieTotal < 0): ?>
                        <div class="flex items-center gap-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg text-xs text-red-300">
                            <i class="fas fa-times-circle text-red-400 w-3"></i>
                            <span>Trésorerie négative !</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($resultatNet < 0): ?>
                        <div class="flex items-center gap-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg text-xs text-red-300">
                            <i class="fas fa-chart-line text-red-400 w-3"></i>
                            <span>Résultat déficitaire</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($couverture < 50 && $dettesFournisseurs > 0): ?>
                        <div class="flex items-center gap-2 p-2 bg-orange-500/10 border border-orange-500/30 rounded-lg text-xs text-orange-300">
                            <i class="fas fa-exclamation-circle text-orange-400 w-3"></i>
                            <span>Trésorerie insuffisante vs dettes</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="pt-3 border-t border-slate-700/50 space-y-1.5">
                        <a href="../rapports/grand_livre.php" class="flex items-center gap-2 text-xs text-slate-400 hover:text-teal-400 transition">
                            <i class="fas fa-book w-3 text-center"></i> Grand livre
                        </a>
                        <a href="../rapports/balance_generale.php" class="flex items-center gap-2 text-xs text-slate-400 hover:text-teal-400 transition">
                            <i class="fas fa-balance-scale w-3 text-center"></i> Balance générale
                        </a>
                        <a href="../ecritures/lettrage.php" class="flex items-center gap-2 text-xs text-slate-400 hover:text-teal-400 transition">
                            <i class="fas fa-link w-3 text-center"></i> Lettrage
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── Ligne 3 : Top clients + Top fournisseurs ────────────────── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                <!-- Top clients -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-users text-amber-400"></i>
                            <h3 class="font-semibold text-white text-sm">Top créances clients</h3>
                        </div>
                        <a href="../rapports/balance_agee_clients.php" class="text-xs text-amber-400 hover:text-amber-300 transition">Voir tout →</a>
                    </div>
                    <?php if (empty($topClients)): ?>
                    <p class="text-xs text-slate-500 text-center py-4">Aucune créance en cours</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php
                        $maxClient = floatval($topClients[0]['solde'] ?? 1);
                        foreach ($topClients as $i => $client):
                            $pct = round(floatval($client['solde']) / $maxClient * 100);
                        ?>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-500 w-4 text-right"><?php echo $i+1; ?></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-center mb-0.5">
                                    <span class="text-xs text-slate-300 truncate"><?php echo htmlspecialchars($client['nom_client'] ?? 'N/A'); ?></span>
                                    <span class="text-xs font-medium text-amber-400 ml-2 flex-shrink-0"><?php echo fmt($client['solde']); ?></span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-1">
                                    <div class="bg-amber-500/70 h-1 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Top fournisseurs -->
                <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-building text-rose-400"></i>
                            <h3 class="font-semibold text-white text-sm">Top dettes fournisseurs</h3>
                        </div>
                        <a href="../rapports/balance_agee_fournisseurs.php" class="text-xs text-rose-400 hover:text-rose-300 transition">Voir tout →</a>
                    </div>
                    <?php if (empty($topFournisseurs)): ?>
                    <p class="text-xs text-slate-500 text-center py-4">Aucune dette en cours</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php
                        $maxFourn = floatval($topFournisseurs[0]['solde'] ?? 1);
                        foreach ($topFournisseurs as $i => $fourn):
                            $pct = round(floatval($fourn['solde']) / $maxFourn * 100);
                        ?>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-500 w-4 text-right"><?php echo $i+1; ?></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-center mb-0.5">
                                    <span class="text-xs text-slate-300 truncate"><?php echo htmlspecialchars($fourn['nom_fournisseur'] ?? 'N/A'); ?></span>
                                    <span class="text-xs font-medium text-rose-400 ml-2 flex-shrink-0"><?php echo fmt($fourn['solde']); ?></span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-1">
                                    <div class="bg-rose-500/70 h-1 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Ligne 4 : Dernières écritures ──────────────────────────── -->
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-history text-blue-400"></i>
                        <h3 class="font-semibold text-white text-sm">Dernières écritures</h3>
                    </div>
                    <a href="../ecritures/liste.php" class="text-xs text-blue-400 hover:text-blue-300 transition">Toutes les écritures →</a>
                </div>
                <?php if (empty($dernieresEcritures)): ?>
                <p class="text-xs text-slate-500 text-center py-6">Aucune écriture enregistrée</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-slate-700/50">
                                <th class="text-left py-2 px-3 text-slate-400 font-medium">Date</th>
                                <th class="text-left py-2 px-3 text-slate-400 font-medium">N° Écriture</th>
                                <th class="text-left py-2 px-3 text-slate-400 font-medium">Journal</th>
                                <th class="text-left py-2 px-3 text-slate-400 font-medium">Libellé</th>
                                <th class="text-right py-2 px-3 text-slate-400 font-medium">Montant</th>
                                <th class="text-center py-2 px-3 text-slate-400 font-medium">Statut</th>
                                <th class="text-center py-2 px-3 text-slate-400 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dernieresEcritures as $ec): ?>
                            <tr class="border-b border-slate-700/30 hover:bg-slate-700/20 transition">
                                <td class="py-2 px-3 text-slate-300"><?php echo date('d/m/Y', strtotime($ec['date_ecriture'])); ?></td>
                                <td class="py-2 px-3 text-slate-300 font-mono"><?php echo htmlspecialchars($ec['numero_ecriture'] ?? '-'); ?></td>
                                <td class="py-2 px-3">
                                    <span class="px-2 py-0.5 bg-blue-500/20 text-blue-300 rounded text-xs"><?php echo htmlspecialchars($ec['journal'] ?? '-'); ?></span>
                                </td>
                                <td class="py-2 px-3 text-slate-300 max-w-xs truncate"><?php echo htmlspecialchars($ec['libelle'] ?? '-'); ?></td>
                                <td class="py-2 px-3 text-right text-slate-300"><?php echo fmt($ec['total_debit'] ?? 0); ?></td>
                                <td class="py-2 px-3 text-center">
                                    <?php if ($ec['statut'] === 'Validé'): ?>
                                    <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-full text-xs">Validé</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 bg-yellow-500/20 text-yellow-400 rounded-full text-xs">Brouillon</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <a href="../ecritures/voir.php?id=<?php echo $ec['id'] ?? ''; ?>" class="text-slate-500 hover:text-blue-400 transition">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /p-6 -->
    </main>
</div>

<script>
    // Animation d'entrée des cartes KPI
    anime({
        targets: '.kpi-card',
        opacity: [0, 1],
        translateY: [20, 0],
        delay: anime.stagger(80),
        duration: 500,
        easing: 'easeOutCubic'
    });

    // Animation sidebar (reprise du comportement existant)
    anime({ targets: '#sidebar', opacity: [0, 1], translateX: [-20, 0], duration: 400, easing: 'easeOutCubic' });
</script>
</body>
</html>
