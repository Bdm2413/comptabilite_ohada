<?php
/**
 * Menu sidebar réutilisable
 */

// Déterminer la page active
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

function isActive($page, $dir = null) {
    global $currentPage, $currentDir;
    if ($dir) {
        // Vérifier à la fois le dossier ET la page
        return ($currentDir === $dir && $currentPage === $page) ? 'bg-emerald-500/10 text-emerald-400' : 'text-slate-300 hover:bg-slate-700/50';
    }
    return $currentPage === $page ? 'bg-emerald-500/10 text-emerald-400' : 'text-slate-300 hover:bg-slate-700/50';
}

// Fonction pour vérifier si une des pages est active
function isActiveMultiple($pages, $dir) {
    global $currentPage, $currentDir;
    if ($currentDir === $dir && in_array($currentPage, $pages)) {
        return 'bg-emerald-500/10 text-emerald-400';
    }
    return 'text-slate-300 hover:bg-slate-700/50';
}

// Déterminer le chemin de base selon le dossier actuel
$basePath = match($currentDir) {
    'dashboard' => '..',
    'auth' => '..',
    'ecritures' => '..',
    'settings' => '..',
    'comptabilisation' => '..',
    'reports' => '..',
    'rapports' => '..',
    'etats_financiers' => '..',
    'analyses' => '..',
    default => '..'
};
?>

<!-- Sidebar -->
<aside id="sidebar" class="w-56 bg-slate-800/50 border-r border-slate-700/50 flex flex-col opacity-0">
    <!-- Logo -->
    <div class="p-3 border-b border-slate-700/50">
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-lg flex items-center justify-center">
                <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            </div>
            <span class="font-semibold text-xs text-white">ComptaSYSCOHADA</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-2 space-y-0.5">
        <!-- Tableau de bord -->
        <a href="<?php echo $basePath; ?>/dashboard/index.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('index.php', 'dashboard'); ?> rounded-lg text-xs font-medium transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Tableau de bord
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Paramètres</p>
        </div>

        <!-- Tableau de correspondance -->
        <a href="<?php echo $basePath; ?>/settings/correspondance.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('correspondance.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Tableau correspondance
        </a>

        <!-- Plan comptable -->
        <a href="<?php echo $basePath; ?>/settings/plan_comptable.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('plan_comptable.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            Plan comptable
        </a>

        <!-- Code journaux -->
        <a href="<?php echo $basePath; ?>/settings/code_journaux.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('code_journaux.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
            </svg>
            Code journaux
        </a>

        <!-- Tiers -->
        <a href="<?php echo $basePath; ?>/settings/tiers.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('tiers.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Tiers
        </a>

        <!-- Utilisateurs -->
        <?php if (hasRole('admin')): ?>
        <a href="<?php echo $basePath; ?>/settings/utilisateurs.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('utilisateurs.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            Utilisateurs
        </a>
        <?php endif; ?>

        <!-- Exercices -->
        <a href="<?php echo $basePath; ?>/settings/exercices.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('exercices.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            Exercices
        </a>

        <!-- Reprise des soldes -->
        <a href="<?php echo $basePath; ?>/settings/reprise_soldes.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('reprise_soldes.php'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
            </svg>
            Reprise des soldes
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Opérations</p>
        </div>

        <!-- Écritures comptables -->
        <a href="<?php echo $basePath; ?>/ecritures/liste.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActiveMultiple(['liste.php', 'saisie.php', 'voir.php'], 'ecritures'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Écritures comptables
        </a>

        <!-- Lettrage -->
        <a href="<?php echo $basePath; ?>/ecritures/lettrage.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('lettrage.php', 'ecritures'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
            </svg>
            Lettrage
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Rapports</p>
        </div>

        <!-- Accueil Rapports -->
        <a href="<?php echo $basePath; ?>/rapports/index.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('index.php', 'rapports'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Accueil
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Analyses</p>
        </div>

        <!-- Heatmap -->
        <a href="<?php echo $basePath; ?>/analyses/heatmap.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('heatmap.php', 'analyses'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path>
            </svg>
            Heatmap Mouvements
        </a>

        <!-- Comparaison de Comptes -->
        <a href="<?php echo $basePath; ?>/analyses/comparaison.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('comparaison.php', 'analyses'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            Comparaison de Comptes
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Livres & États</p>
        </div>

        <!-- Grand Livre -->
        <a href="<?php echo $basePath; ?>/rapports/grand_livre.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('grand_livre.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
            </svg>
            Grand Livre
        </a>

        <!-- Balance Générale -->
        <a href="<?php echo $basePath; ?>/rapports/balance_generale.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_generale.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
            </svg>
            Balance Générale
        </a>

        <!-- Balance Auxiliaire -->
        <a href="<?php echo $basePath; ?>/rapports/balance_auxiliaire.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_auxiliaire.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            Balance Auxiliaire
        </a>

        <!-- Journal Général -->
        <a href="<?php echo $basePath; ?>/rapports/journal_general.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('journal_general.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Journal Général
        </a>

        <!-- Balance Âgée Clients -->
        <a href="<?php echo $basePath; ?>/rapports/balance_agee_clients.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_agee_clients.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Balance Âgée Clients
        </a>

        <!-- Balance Âgée Fournisseurs -->
        <a href="<?php echo $basePath; ?>/rapports/balance_agee_fournisseurs.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_agee_fournisseurs.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            Balance Âgée Fournisseurs
        </a>

        <!-- Rapport de Caisse -->
        <a href="<?php echo $basePath; ?>/rapports/rapport_caisse.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('rapport_caisse.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Rapport de Caisse
        </a>

        <!-- Rapprochement Bancaire -->
        <a href="<?php echo $basePath; ?>/rapports/rapprochement_bancaire.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('rapprochement_bancaire.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
            </svg>
            Rapprochement Bancaire
        </a>

        <!-- Évolution Report à Nouveau -->
        <a href="<?php echo $basePath; ?>/rapports/evolution_report_nouveau.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('evolution_report_nouveau.php', 'rapports'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
            </svg>
            Évolution Report à Nouveau
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Gestion Budgétaire</p>
        </div>

        <!-- Accueil Budget -->
        <a href="<?php echo $basePath; ?>/budget/index.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('index.php', 'budget'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Accueil
        </a>

        <!-- Tableau de bord budgétaire -->
        <a href="<?php echo $basePath; ?>/budget/dashboard.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('dashboard.php', 'budget'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            Tableau de bord
        </a>

        <!-- Import Budget -->
        <a href="<?php echo $basePath; ?>/budget/import_budget.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('import_budget.php', 'budget'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
            </svg>
            Import Budget
        </a>

        <!-- Suivi détaillé -->
        <a href="<?php echo $basePath; ?>/budget/suivi_detaille.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('suivi_detaille.php', 'budget'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            Suivi détaillé
        </a>

        <!-- Analyse Comparative -->
        <a href="<?php echo $basePath; ?>/budget/analyse_comparative.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('analyse_comparative.php', 'budget'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            Analyse Comparative
        </a>

        <!-- Gestion versions -->
        <a href="<?php echo $basePath; ?>/budget/gestion_versions.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('gestion_versions.php', 'budget'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
            </svg>
            Gestion versions
        </a>

        <!-- Rubriques budgétaires -->
        <a href="<?php echo $basePath; ?>/budget/rubriques.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('rubriques.php', 'budget'); ?> rounded-lg text-xs transition ml-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
            </svg>
            Rubriques budgétaires
        </a>

        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">États Financiers</p>
        </div>

        <!-- Bilan OHADA -->
        <a href="<?php echo $basePath; ?>/etats_financiers/bilan.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('bilan.php', 'etats_financiers'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
            </svg>
            Bilan
        </a>

        <!-- Compte de Résultat -->
        <a href="<?php echo $basePath; ?>/etats_financiers/compte_resultat.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('compte_resultat.php', 'etats_financiers'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
            </svg>
            Compte de Résultat
        </a>
    </nav>

    <!-- User menu -->
    <div class="p-2 border-t border-slate-700/50">
        <div class="flex items-center gap-2 px-2 py-1 bg-slate-700/30 rounded-lg">
            <div class="w-6 h-6 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-full flex items-center justify-center text-[10px] font-semibold text-white">
                <?php echo strtoupper(substr($_SESSION['user_name'], 0, 2)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-medium text-white truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <p class="text-[9px] text-slate-400"><?php echo htmlspecialchars($_SESSION['user_role']); ?></p>
            </div>
            <a href="<?php echo $basePath; ?>/auth/logout.php" class="text-slate-400 hover:text-red-400 transition" title="Déconnexion">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
            </a>
        </div>
    </div>
</aside>

<script>
    // Animation du sidebar
    anime({
        targets: '#sidebar',
        opacity: [0, 1],
        translateX: [-50, 0],
        duration: 600,
        easing: 'easeOutQuad'
    });

    // Animation au survol des liens
    document.querySelectorAll('nav a').forEach(link => {
        link.addEventListener('mouseenter', function() {
            if (!this.classList.contains('bg-emerald-500/10')) {
                anime({
                    targets: this,
                    translateX: [0, 4],
                    duration: 200,
                    easing: 'easeOutQuad'
                });
            }
        });

        link.addEventListener('mouseleave', function() {
            if (!this.classList.contains('bg-emerald-500/10')) {
                anime({
                    targets: this,
                    translateX: [4, 0],
                    duration: 200,
                    easing: 'easeOutQuad'
                });
            }
        });
    });
</script>
