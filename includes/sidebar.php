<?php
/**
 * Menu sidebar réutilisable avec menus accordéon
 */

// Déterminer la page active
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

function isActive($page, $dir = null) {
    global $currentPage, $currentDir;
    if ($dir) {
        return ($currentDir === $dir && $currentPage === $page) ? 'bg-emerald-500/10 text-emerald-400' : 'text-slate-300 hover:bg-slate-700/50';
    }
    return $currentPage === $page ? 'bg-emerald-500/10 text-emerald-400' : 'text-slate-300 hover:bg-slate-700/50';
}

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
    'budget' => '..',
    'actualites' => '..',
    'achats' => '..',
    'suivi' => '..',
    'immobilisations' => '..',
    'paie' => '..',
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

    <!-- Sélecteur de société -->
    <?php
    $societe_id = getCurrentSocieteId();
    $user_id = $_SESSION['user_id'] ?? null;
    $societes = $user_id ? getUserSocietes($user_id) : [];
    $societe_actuelle = null;

    if ($societe_id) {
        foreach ($societes as $soc) {
            if ($soc['id'] == $societe_id) {
                $societe_actuelle = $soc;
                break;
            }
        }
    }
    ?>
    <?php if (!empty($societes)): ?>
    <div class="p-2 border-b border-slate-700/50">
        <button onclick="toggleSocieteSwitcher()" class="w-full px-2 py-2 bg-slate-700/30 hover:bg-slate-700/50 rounded-lg transition group">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 flex-1 min-w-0">
                    <svg class="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    <div class="flex-1 text-left min-w-0">
                        <p class="text-[9px] text-slate-400">Société</p>
                        <p class="text-[10px] font-medium text-white truncate">
                            <?php echo $societe_actuelle ? htmlspecialchars($societe_actuelle['code_societe']) : 'Aucune'; ?>
                        </p>
                    </div>
                </div>
                <svg id="icon-societes" class="w-3 h-3 text-slate-400 group-hover:text-slate-300 transition-transform duration-200 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
        </button>

        <!-- Dropdown des sociétés -->
        <div id="societe-switcher" class="hidden mt-1 bg-slate-700/50 rounded-lg overflow-hidden max-h-60 overflow-y-auto">
            <?php foreach ($societes as $soc): ?>
            <a href="<?php echo APP_URL; ?>/includes/switch_societe.php?societe_id=<?php echo $soc['id']; ?>"
               class="block px-3 py-2 hover:bg-slate-600/50 transition <?php echo $soc['id'] == $societe_id ? 'bg-emerald-500/10' : ''; ?>">
                <div class="flex items-center justify-between">
                    <div class="flex-1 min-w-0">
                        <p class="text-[10px] font-medium text-white truncate"><?php echo htmlspecialchars($soc['code_societe']); ?></p>
                        <p class="text-[9px] text-slate-400 truncate"><?php echo htmlspecialchars($soc['raison_sociale']); ?></p>
                    </div>
                    <?php if ($soc['id'] == $societe_id): ?>
                    <svg class="w-3 h-3 text-emerald-400 flex-shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>

            <?php if (isAdmin()): ?>
            <div class="border-t border-slate-600/50 mt-1 pt-1">
                <a href="<?php echo $basePath; ?>/settings/societes.php" class="block px-3 py-2 hover:bg-slate-600/50 transition">
                    <div class="flex items-center gap-2">
                        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        <span class="text-[10px] text-slate-300">Gérer les sociétés</span>
                    </div>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-2 space-y-0.5">
        <!-- Tableau de bord -->
        <a href="<?php echo $basePath; ?>/dashboard/index.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('index.php', 'dashboard'); ?> rounded-lg text-xs font-medium transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Tableau de bord
        </a>

        <!-- Vue d'ensemble -->
        <a href="<?php echo $basePath; ?>/dashboard/vue_ensemble.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('vue_ensemble.php', 'dashboard'); ?> rounded-lg text-xs font-medium transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
            </svg>
            Vue d'ensemble
        </a>

        <!-- Section Paramètres (Accordéon) -->
        <div class="pt-1.5">
            <button onclick="toggleAccordion('parametres')" class="w-full flex items-center justify-between px-2 py-1.5 text-xs font-semibold text-slate-400 hover:text-slate-300 transition rounded-lg hover:bg-slate-700/30">
                <div class="flex items-center gap-2">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span class="uppercase tracking-wider">Paramètres</span>
                </div>
                <svg id="icon-parametres" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div id="accordion-parametres" class="hidden mt-0.5 ml-2 space-y-0.5">
                <a href="<?php echo $basePath; ?>/settings/correspondance.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('correspondance.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Tableau correspondance
                </a>
                <a href="<?php echo $basePath; ?>/settings/plan_comptable.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('plan_comptable.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    Plan comptable
                </a>
                <a href="<?php echo $basePath; ?>/settings/referentiel_ohada.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('referentiel_ohada.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                    Référentiel OHADA
                </a>
                <a href="<?php echo $basePath; ?>/settings/code_journaux.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('code_journaux.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                    Code journaux
                </a>
                <a href="<?php echo $basePath; ?>/settings/tiers.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('tiers.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    Tiers
                </a>
                <?php if (hasRole('admin')): ?>
                <a href="<?php echo $basePath; ?>/settings/utilisateurs.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('utilisateurs.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    Utilisateurs
                </a>
                <?php endif; ?>
                <a href="<?php echo $basePath; ?>/settings/exercices.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('exercices.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    Exercices
                </a>
                <a href="<?php echo $basePath; ?>/settings/reprise_soldes.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('reprise_soldes.php'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    Reprise des soldes
                </a>
            </div>
        </div>

        <!-- Section Opérations (Toujours visible) -->
        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Opérations</p>
        </div>
        <a href="<?php echo $basePath; ?>/ecritures/liste.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActiveMultiple(['liste.php', 'saisie.php', 'voir.php'], 'ecritures'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Écritures comptables
        </a>
        <a href="<?php echo $basePath; ?>/ecritures/lettrage.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('lettrage.php', 'ecritures'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
            </svg>
            Lettrage
        </a>

        <!-- Section Suivi (Toujours visible) -->
        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Suivi</p>
        </div>
        <a href="<?php echo $basePath; ?>/suivi/dashboard_pca_cca.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('dashboard_pca_cca.php', 'suivi'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            Tableau de Bord PCA/CCA
        </a>
        <a href="<?php echo $basePath; ?>/suivi/charges_avance.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('charges_avance.php', 'suivi'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z"></path>
            </svg>
            Charges Constatées d'Avance
        </a>
        <a href="<?php echo $basePath; ?>/suivi/produits_avance.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('produits_avance.php', 'suivi'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Produits Constatés d'Avance
        </a>

        <!-- Section Achats (Accordéon) -->
        <div class="pt-1.5">
            <button onclick="toggleAccordion('achats')" class="w-full flex items-center justify-between px-2 py-1.5 text-xs font-semibold text-slate-400 hover:text-slate-300 transition rounded-lg hover:bg-slate-700/30">
                <div class="flex items-center gap-2">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <span class="uppercase tracking-wider">Achats</span>
                </div>
                <svg id="icon-achats" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div id="accordion-achats" class="hidden mt-0.5 ml-2 space-y-0.5">
                <a href="<?php echo $basePath; ?>/achats/catalogue.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('catalogue.php', 'achats'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    Catalogue fournisseurs
                </a>
                <a href="<?php echo $basePath; ?>/achats/devis.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActiveMultiple(['devis.php', 'devis_form.php'], 'achats'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Devis fournisseurs
                </a>
                <a href="<?php echo $basePath; ?>/achats/bons_commande.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActiveMultiple(['bons_commande.php', 'bc_form.php'], 'achats'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                    Bons de commande
                </a>
            </div>
        </div>

        <!-- Section États & Rapports (Accordéon) -->
        <div class="pt-1.5">
            <button onclick="toggleAccordion('rapports')" class="w-full flex items-center justify-between px-2 py-1.5 text-xs font-semibold text-slate-400 hover:text-slate-300 transition rounded-lg hover:bg-slate-700/30">
                <div class="flex items-center gap-2">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span class="uppercase tracking-wider">États & Rapports</span>
                </div>
                <svg id="icon-rapports" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div id="accordion-rapports" class="hidden mt-0.5 ml-2 space-y-0.5">
                <!-- Analyses -->
                <div class="pt-1 pb-0.5">
                    <p class="px-2 text-[9px] font-semibold text-slate-500 uppercase tracking-wider">Analyses</p>
                </div>
                <a href="<?php echo $basePath; ?>/analyses/heatmap.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('heatmap.php', 'analyses'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                    </svg>
                    Heatmap
                </a>
                <a href="<?php echo $basePath; ?>/analyses/comparaison.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('comparaison.php', 'analyses'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    Comparaison
                </a>

                <!-- Livres Comptables -->
                <div class="pt-1 pb-0.5">
                    <p class="px-2 text-[9px] font-semibold text-slate-500 uppercase tracking-wider">Livres</p>
                </div>
                <a href="<?php echo $basePath; ?>/rapports/grand_livre.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('grand_livre.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                    Grand Livre
                </a>
                <a href="<?php echo $basePath; ?>/rapports/journal_general.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('journal_general.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Journal Général
                </a>
                <a href="<?php echo $basePath; ?>/rapports/balance_generale.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_generale.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Balance Générale
                </a>
                <a href="<?php echo $basePath; ?>/rapports/balance_auxiliaire.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_auxiliaire.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    Balance Auxiliaire
                </a>

                <!-- Tiers -->
                <div class="pt-1 pb-0.5">
                    <p class="px-2 text-[9px] font-semibold text-slate-500 uppercase tracking-wider">Tiers</p>
                </div>
                <a href="<?php echo $basePath; ?>/rapports/balance_agee_clients.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_agee_clients.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    Balance Âgée Clients
                </a>
                <a href="<?php echo $basePath; ?>/rapports/balance_agee_fournisseurs.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('balance_agee_fournisseurs.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    Balance Âgée Fournisseurs
                </a>

                <!-- Trésorerie -->
                <div class="pt-1 pb-0.5">
                    <p class="px-2 text-[9px] font-semibold text-slate-500 uppercase tracking-wider">Trésorerie</p>
                </div>
                <a href="<?php echo $basePath; ?>/rapports/rapport_caisse.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('rapport_caisse.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    Rapport de Caisse
                </a>
                <a href="<?php echo $basePath; ?>/rapports/rapprochement_bancaire.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('rapprochement_bancaire.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                    Rapprochement Bancaire
                </a>

                <!-- Autres -->
                <div class="pt-1 pb-0.5">
                    <p class="px-2 text-[9px] font-semibold text-slate-500 uppercase tracking-wider">Autres</p>
                </div>
                <a href="<?php echo $basePath; ?>/rapports/evolution_report_nouveau.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('evolution_report_nouveau.php', 'rapports'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                    Évolution Report à Nouveau
                </a>
            </div>
        </div>

        <!-- Section Budget (Accordéon) -->
        <div class="pt-1.5">
            <button onclick="toggleAccordion('budget')" class="w-full flex items-center justify-between px-2 py-1.5 text-xs font-semibold text-slate-400 hover:text-slate-300 transition rounded-lg hover:bg-slate-700/30">
                <div class="flex items-center gap-2">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="uppercase tracking-wider">Budget</span>
                </div>
                <svg id="icon-budget" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div id="accordion-budget" class="hidden mt-0.5 ml-2 space-y-0.5">
                <a href="<?php echo $basePath; ?>/budget/dashboard.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('dashboard.php', 'budget'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    Tableau de bord
                </a>
                <a href="<?php echo $basePath; ?>/budget/import_budget.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('import_budget.php', 'budget'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Import Budget
                </a>
                <a href="<?php echo $basePath; ?>/budget/suivi_detaille.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('suivi_detaille.php', 'budget'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    Suivi détaillé
                </a>
                <a href="<?php echo $basePath; ?>/budget/analyse_comparative.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('analyse_comparative.php', 'budget'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    Analyse Comparative
                </a>
                <a href="<?php echo $basePath; ?>/budget/gestion_versions.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('gestion_versions.php', 'budget'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    Gestion versions
                </a>
                <a href="<?php echo $basePath; ?>/budget/rubriques.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('rubriques.php', 'budget'); ?> rounded-lg text-xs transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                    </svg>
                    Rubriques budgétaires
                </a>
            </div>
        </div>

        <!-- Section Immobilisations -->
        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Actifs</p>
        </div>
        <button onclick="toggleAccordion('immobilisations')" class="w-full flex items-center justify-between px-2 py-1.5 text-xs font-semibold text-slate-400 hover:text-slate-300 transition rounded-lg hover:bg-slate-700/30">
            <span class="flex items-center gap-2">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                Immobilisations
            </span>
            <svg id="icon-immobilisations" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
        <div id="accordion-immobilisations" class="hidden mt-0.5 ml-2 space-y-0.5">
            <a href="<?php echo $basePath; ?>/immobilisations/liste.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('liste.php', 'immobilisations'); ?> rounded-lg text-xs transition">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                </svg>
                Registre des immobilisations
            </a>
            <a href="<?php echo $basePath; ?>/immobilisations/synthese.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('synthese.php', 'immobilisations'); ?> rounded-lg text-xs transition">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                État des immobilisations
            </a>
        </div>

        <!-- Section Paie (Accordéon) -->
        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Ressources Humaines</p>
        </div>
        <button onclick="toggleAccordion('paie')" class="w-full flex items-center justify-between px-2 py-1.5 text-xs font-semibold text-slate-400 hover:text-slate-300 transition rounded-lg hover:bg-slate-700/30">
            <span class="flex items-center gap-2">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Paie
            </span>
            <svg id="icon-paie" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
        <div id="accordion-paie" class="hidden mt-0.5 ml-2 space-y-0.5">
            <a href="<?php echo $basePath; ?>/paie/employes.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('employes.php', 'paie'); ?> rounded-lg text-xs transition">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Employés
            </a>
            <a href="<?php echo $basePath; ?>/paie/parametres.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('parametres.php', 'paie'); ?> rounded-lg text-xs transition">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Paramètres paie
            </a>
            <a href="<?php echo $basePath; ?>/paie/index.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActiveMultiple(['index.php', 'bulletin.php', 'voir_bulletin.php'], 'paie'); ?> rounded-lg text-xs transition">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Bulletins de paie
            </a>
            <a href="<?php echo $basePath; ?>/paie/livre_paie.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('livre_paie.php', 'paie'); ?> rounded-lg text-xs transition">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                </svg>
                Livre de paie
            </a>
        </div>

        <!-- Section États Financiers (Toujours visible) -->
        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">États Financiers</p>
        </div>
        <a href="<?php echo $basePath; ?>/etats_financiers/bilan.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('bilan.php', 'etats_financiers'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
            </svg>
            Bilan
        </a>
        <a href="<?php echo $basePath; ?>/etats_financiers/compte_resultat.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('compte_resultat.php', 'etats_financiers'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
            </svg>
            Compte de Résultat
        </a>
        <a href="<?php echo $basePath; ?>/etats_financiers/tft.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('tft.php', 'etats_financiers'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v8m0 0l4-4m-4 4l-4-4"></path>
            </svg>
            Flux de Trésorerie
        </a>
        <?php $notesClass = ($currentDir==='etats_financiers' && (str_starts_with($currentPage,'note'))) ? 'bg-emerald-500/10 text-emerald-400' : 'text-slate-300 hover:bg-slate-700/50'; ?>
        <a href="<?php echo $basePath; ?>/etats_financiers/notes_annexes.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo $notesClass; ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Notes Annexes
        </a>

        <!-- Section Veille & Actualités (Toujours visible) -->
        <div class="pt-1.5 pb-0.5">
            <p class="px-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Veille</p>
        </div>
        <a href="<?php echo $basePath; ?>/actualites/index.php" class="flex items-center gap-2 px-2 py-1.5 <?php echo isActive('index.php', 'actualites'); ?> rounded-lg text-xs transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>
            </svg>
            Actualités & Veille
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
    // État des accordéons (sauvegardé dans localStorage)
    const accordionState = JSON.parse(localStorage.getItem('accordionState') || '{}');

    // Fonction pour basculer le sélecteur de société
    function toggleSocieteSwitcher() {
        const switcher = document.getElementById('societe-switcher');
        const icon = document.getElementById('icon-societes');
        const isHidden = switcher.classList.contains('hidden');

        if (isHidden) {
            switcher.classList.remove('hidden');
            icon.style.transform = 'rotate(180deg)';
            anime({
                targets: '#societe-switcher',
                opacity: [0, 1],
                maxHeight: ['0px', switcher.scrollHeight + 'px'],
                duration: 200,
                easing: 'easeOutQuad',
                complete: () => {
                    switcher.style.maxHeight = 'none';
                }
            });
        } else {
            icon.style.transform = 'rotate(0deg)';
            anime({
                targets: '#societe-switcher',
                opacity: [1, 0],
                maxHeight: [switcher.scrollHeight + 'px', '0px'],
                duration: 200,
                easing: 'easeInQuad',
                complete: () => {
                    switcher.classList.add('hidden');
                }
            });
        }
    }

    // Fermer le switcher en cliquant ailleurs
    document.addEventListener('click', function(e) {
        const switcher = document.getElementById('societe-switcher');
        const button = e.target.closest('button[onclick="toggleSocieteSwitcher()"]');

        if (switcher && !switcher.classList.contains('hidden') && !switcher.contains(e.target) && !button) {
            toggleSocieteSwitcher();
        }
    });

    // Fonction pour basculer un accordéon
    function toggleAccordion(name) {
        const content = document.getElementById('accordion-' + name);
        const icon = document.getElementById('icon-' + name);
        const isHidden = content.classList.contains('hidden');

        if (isHidden) {
            // Ouvrir l'accordéon
            content.classList.remove('hidden');
            content.style.opacity = '0';
            content.style.maxHeight = '0px';
            content.style.overflow = 'hidden';

            // Obtenir la hauteur réelle après avoir rendu visible
            const targetHeight = content.scrollHeight + 'px';

            icon.style.transform = 'rotate(180deg)';
            accordionState[name] = true;

            // Animation d'ouverture
            anime({
                targets: content,
                maxHeight: ['0px', targetHeight],
                opacity: [0, 1],
                duration: 300,
                easing: 'easeOutQuad',
                complete: () => {
                    content.style.maxHeight = 'none';
                    content.style.overflow = 'visible';
                }
            });
        } else {
            // Fermer l'accordéon
            const currentHeight = content.scrollHeight + 'px';
            content.style.maxHeight = currentHeight;
            content.style.overflow = 'hidden';

            anime({
                targets: content,
                maxHeight: [currentHeight, '0px'],
                opacity: [1, 0],
                duration: 300,
                easing: 'easeInQuad',
                complete: () => {
                    content.classList.add('hidden');
                    content.style.maxHeight = '';
                    content.style.overflow = '';
                }
            });
            icon.style.transform = 'rotate(0deg)';
            accordionState[name] = false;
        }

        // Sauvegarder l'état
        localStorage.setItem('accordionState', JSON.stringify(accordionState));
    }

    // Restaurer l'état des accordéons au chargement
    document.addEventListener('DOMContentLoaded', function() {
        Object.keys(accordionState).forEach(name => {
            if (accordionState[name]) {
                const content = document.getElementById('accordion-' + name);
                const icon = document.getElementById('icon-' + name);
                if (content && icon) {
                    content.classList.remove('hidden');
                    icon.style.transform = 'rotate(180deg)';
                }
            }
        });
    });

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
