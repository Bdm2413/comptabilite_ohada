<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

function isActive($page, $dir = null) {
    global $currentPage, $currentDir;
    if ($dir) return ($currentDir === $dir && $currentPage === $page) ? 'bg-blue-500/15 text-blue-300' : 'text-slate-400 hover:bg-slate-700/40 hover:text-slate-200';
    return $currentPage === $page ? 'bg-blue-500/15 text-blue-300' : 'text-slate-400 hover:bg-slate-700/40 hover:text-slate-200';
}
function isActiveMultiple($pages, $dir) {
    global $currentPage, $currentDir;
    return ($currentDir === $dir && in_array($currentPage, $pages))
        ? 'bg-blue-500/15 text-blue-300'
        : 'text-slate-400 hover:bg-slate-700/40 hover:text-slate-200';
}
function isActiveDir($dir) {
    global $currentDir;
    return $currentDir === $dir ? 'bg-blue-500/15 text-blue-300' : 'text-slate-400 hover:bg-slate-700/40 hover:text-slate-200';
}
function isNoteActive() {
    global $currentPage, $currentDir;
    return ($currentDir === 'etats_financiers' && (str_starts_with($currentPage, 'note') || $currentPage === 'notes_annexes.php'))
        ? 'bg-blue-500/15 text-blue-300' : 'text-slate-400 hover:bg-slate-700/40 hover:text-slate-200';
}

$basePath = match($currentDir) {
    'dashboard','auth','ecritures','settings','comptabilisation','reports',
    'rapports','etats_financiers','analyses','budget','actualites',
    'achats','suivi','immobilisations','paie' => '..',
    default => '..'
};

$societe_id      = getCurrentSocieteId();
$user_id         = $_SESSION['user_id'] ?? null;
$societes        = $user_id ? getUserSocietes($user_id) : [];
$societe_actuelle = null;
foreach ($societes as $soc) {
    if ($soc['id'] == $societe_id) { $societe_actuelle = $soc; break; }
}

// Helper pour les liens de nav
function navLink($href, $icon_path, $label, $active_class, $badge = '') {
    $badge_html = $badge ? "<span class='ml-auto text-[9px] bg-slate-600 text-slate-300 px-1.5 py-0.5 rounded-full'>$badge</span>" : '';
    echo "<a href='$href' class='flex items-center gap-2 px-2 py-1.5 $active_class rounded-lg text-xs transition group'>
        <svg class='w-3.5 h-3.5 flex-shrink-0' fill='none' stroke='currentColor' viewBox='0 0 24 24'>$icon_path</svg>
        <span class='truncate'>$label</span>$badge_html
    </a>";
}
function sectionLabel($label) {
    echo "<p class='px-2 pt-3 pb-1 text-[9px] font-bold text-slate-500 uppercase tracking-widest'>$label</p>";
}
function accordionBtn($id, $icon_path, $label, $badge = '') {
    $badge_html = $badge ? "<span class='ml-auto mr-1 text-[9px] bg-blue-900/60 text-blue-300 px-1.5 py-0.5 rounded-full'>$badge</span>" : '';
    echo "<button onclick='toggleAccordion(\"$id\")' class='w-full flex items-center gap-2 px-2 py-1.5 text-xs font-medium text-slate-400 hover:text-slate-200 hover:bg-slate-700/40 transition rounded-lg'>
        <svg class='w-3.5 h-3.5 flex-shrink-0' fill='none' stroke='currentColor' viewBox='0 0 24 24'>$icon_path</svg>
        <span class='flex-1 text-left truncate'>$label</span>
        $badge_html
        <svg id='icon-$id' class='w-3 h-3 flex-shrink-0 transition-transform duration-200' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
            <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/>
        </svg>
    </button>
    <div id='accordion-$id' class='hidden mt-0.5 ml-3 pl-2 border-l border-slate-700/50 space-y-0.5'>";
}
function endAccordion() { echo "</div>"; }
?>

<aside id="sidebar" class="w-56 bg-slate-800/60 border-r border-slate-700/50 flex flex-col opacity-0 flex-shrink-0">

    <!-- Logo -->
    <div class="px-3 py-3 border-b border-slate-700/50">
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 bg-gradient-to-br from-blue-500 to-blue-700 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-bold text-white leading-tight">ComptaOHADA</p>
                <p class="text-[9px] text-slate-400">ERP SYSCOHADA Révisé</p>
            </div>
        </div>
    </div>

    <!-- Sélecteur de société -->
    <?php if (!empty($societes)): ?>
    <div class="px-2 py-2 border-b border-slate-700/50">
        <button onclick="toggleSocieteSwitcher()" class="w-full px-2 py-1.5 bg-slate-700/30 hover:bg-slate-700/50 rounded-lg transition">
            <div class="flex items-center gap-2">
                <div class="w-5 h-5 bg-blue-600/30 rounded flex items-center justify-center flex-shrink-0">
                    <svg class="w-3 h-3 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
                <div class="flex-1 text-left min-w-0">
                    <p class="text-[9px] text-slate-500 leading-tight">Société active</p>
                    <p class="text-[10px] font-semibold text-white truncate">
                        <?= $societe_actuelle ? htmlspecialchars($societe_actuelle['raison_sociale']) : 'Aucune sélectionnée' ?>
                    </p>
                </div>
                <svg id="icon-societes" class="w-3 h-3 text-slate-500 transition-transform duration-200 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </button>
        <div id="societe-switcher" class="hidden mt-1 bg-slate-900/50 rounded-lg overflow-hidden">
            <?php foreach ($societes as $soc): ?>
            <a href="<?= APP_URL ?>/includes/switch_societe.php?societe_id=<?= $soc['id'] ?>"
               class="flex items-center gap-2 px-3 py-2 hover:bg-slate-700/50 transition <?= $soc['id'] == $societe_id ? 'bg-blue-500/10' : '' ?>">
                <div class="w-1.5 h-1.5 rounded-full <?= $soc['id'] == $societe_id ? 'bg-blue-400' : 'bg-slate-600' ?> flex-shrink-0"></div>
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-medium text-white truncate"><?= htmlspecialchars($soc['raison_sociale']) ?></p>
                    <p class="text-[9px] text-slate-500"><?= htmlspecialchars($soc['code_societe']) ?></p>
                </div>
                <?php if ($soc['id'] == $societe_id): ?>
                <svg class="w-3 h-3 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
            <?php if (isAdmin()): ?>
            <div class="border-t border-slate-700/50 px-3 py-2">
                <a href="<?= $basePath ?>/settings/societes.php" class="flex items-center gap-1.5 text-[10px] text-blue-400 hover:text-blue-300 transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Gérer les sociétés
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation principale -->
    <nav class="flex-1 overflow-y-auto px-2 py-2 space-y-0.5 text-xs">

        <!-- TABLEAU DE BORD -->
        <?php navLink(
            "$basePath/dashboard/index.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
            'Tableau de bord',
            isActive('index.php', 'dashboard')
        ); ?>

        <!-- COMPTABILITE -->
        <?php sectionLabel('Comptabilité GL'); ?>
        <?php navLink(
            "$basePath/ecritures/liste.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
            'Saisie des écritures',
            isActiveMultiple(['liste.php','saisie.php','voir.php'], 'ecritures')
        ); ?>
        <?php navLink(
            "$basePath/ecritures/lettrage.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>',
            'Lettrage',
            isActive('lettrage.php', 'ecritures')
        ); ?>
        <?php navLink(
            "$basePath/rapports/grand_livre.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
            'Grand Livre',
            isActive('grand_livre.php', 'rapports')
        ); ?>
        <?php navLink(
            "$basePath/rapports/journal_general.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
            'Journal Général',
            isActive('journal_general.php', 'rapports')
        ); ?>
        <?php navLink(
            "$basePath/rapports/balance_generale.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>',
            'Balance Générale',
            isActive('balance_generale.php', 'rapports')
        ); ?>

        <!-- ETATS FINANCIERS -->
        <?php sectionLabel('États Financiers'); ?>
        <?php navLink("$basePath/etats_financiers/bilan.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>',
            'Bilan', isActive('bilan.php', 'etats_financiers')
        ); ?>
        <?php navLink("$basePath/etats_financiers/compte_resultat.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>',
            'Compte de Résultat', isActive('compte_resultat.php', 'etats_financiers')
        ); ?>
        <?php navLink("$basePath/etats_financiers/tft.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v8m0 0l4-4m-4 4l-4-4"/>',
            'Flux de Trésorerie', isActive('tft.php', 'etats_financiers')
        ); ?>
        <?php navLink("$basePath/etats_financiers/notes_annexes.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
            'Notes Annexes', isNoteActive()
        ); ?>

        <!-- CLIENTS AR -->
        <?php sectionLabel('Clients (AR)'); ?>
        <?php accordionBtn('clients',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
            'Clients'
        ); ?>
            <?php navLink("$basePath/rapports/balance_agee_clients.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
                'Balance Âgée Clients', isActive('balance_agee_clients.php', 'rapports')
            ); ?>
            <?php navLink("$basePath/rapports/balance_auxiliaire.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
                'Balance Auxiliaire', isActive('balance_auxiliaire.php', 'rapports')
            ); ?>
            <?php navLink("$basePath/achats/devis.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
                'Devis', isActive('devis.php', 'achats')
            ); ?>
        <?php endAccordion(); ?>

        <!-- FOURNISSEURS AP -->
        <?php sectionLabel('Fournisseurs (AP)'); ?>
        <?php accordionBtn('fournisseurs',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
            'Fournisseurs'
        ); ?>
            <?php navLink("$basePath/rapports/balance_agee_fournisseurs.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
                'Balance Âgée Fourn.', isActive('balance_agee_fournisseurs.php', 'rapports')
            ); ?>
            <?php navLink("$basePath/achats/bons_commande.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
                'Bons de commande', isActiveMultiple(['bons_commande.php','bc_form.php'], 'achats')
            ); ?>
            <?php navLink("$basePath/achats/catalogue.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>',
                'Catalogue', isActive('catalogue.php', 'achats')
            ); ?>
        <?php endAccordion(); ?>

        <!-- TRESORERIE CM -->
        <?php sectionLabel('Trésorerie (CM)'); ?>
        <?php navLink("$basePath/rapports/rapprochement_bancaire.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
            'Rapprochement bancaire', isActive('rapprochement_bancaire.php', 'rapports')
        ); ?>
        <?php navLink("$basePath/rapports/rapport_caisse.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
            'Rapport de caisse', isActive('rapport_caisse.php', 'rapports')
        ); ?>
        <?php navLink("$basePath/suivi/dashboard_pca_cca.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
            'Suivi PCA / CCA', isActive('dashboard_pca_cca.php', 'suivi')
        ); ?>
        <?php navLink("$basePath/rapports/evolution_report_nouveau.php",
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>',
            'Report à nouveau', isActive('evolution_report_nouveau.php', 'rapports')
        ); ?>

        <!-- IMMOBILISATIONS FA -->
        <?php sectionLabel('Immobilisations (FA)'); ?>
        <?php accordionBtn('immobilisations',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
            'Immobilisations'
        ); ?>
            <?php navLink("$basePath/immobilisations/liste.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
                'Registre', isActive('liste.php', 'immobilisations')
            ); ?>
            <?php navLink("$basePath/immobilisations/synthese.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
                'Synthèse', isActive('synthese.php', 'immobilisations')
            ); ?>
        <?php endAccordion(); ?>

        <!-- PAIE & RH -->
        <?php sectionLabel('Paie & RH'); ?>
        <?php accordionBtn('paie',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
            'Paie'
        ); ?>
            <?php navLink("$basePath/paie/employes.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
                'Employés', isActive('employes.php', 'paie')
            ); ?>
            <?php navLink("$basePath/paie/index.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
                'Bulletins de paie', isActiveMultiple(['index.php','bulletin.php','voir_bulletin.php'], 'paie')
            ); ?>
            <?php navLink("$basePath/paie/livre_paie.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
                'Livre de paie', isActive('livre_paie.php', 'paie')
            ); ?>
            <?php navLink("$basePath/paie/parametres.php",
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
                'Paramètres paie', isActive('parametres.php', 'paie')
            ); ?>
        <?php endAccordion(); ?>

        <!-- BUDGET -->
        <?php sectionLabel('Budget & Analyses'); ?>
        <?php accordionBtn('budget',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'Budget'
        ); ?>
            <?php navLink("$basePath/budget/dashboard.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>', 'Tableau de bord', isActive('dashboard.php', 'budget')); ?>
            <?php navLink("$basePath/budget/import_budget.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>', 'Import budget', isActive('import_budget.php', 'budget')); ?>
            <?php navLink("$basePath/budget/suivi_detaille.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>', 'Suivi détaillé', isActive('suivi_detaille.php', 'budget')); ?>
            <?php navLink("$basePath/budget/analyse_comparative.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>', 'Analyse comparative', isActive('analyse_comparative.php', 'budget')); ?>
        <?php endAccordion(); ?>

        <?php accordionBtn('analyses',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>',
            'Analyses'
        ); ?>
            <?php navLink("$basePath/analyses/heatmap.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>', 'Heatmap', isActive('heatmap.php', 'analyses')); ?>
            <?php navLink("$basePath/analyses/comparaison.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>', 'Comparaison', isActive('comparaison.php', 'analyses')); ?>
        <?php endAccordion(); ?>

        <!-- PARAMETRES -->
        <?php sectionLabel('Paramètres'); ?>
        <?php accordionBtn('parametres',
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
            'Paramètres'
        ); ?>
            <?php if (isAdmin()): ?>
            <?php navLink("$basePath/settings/societes.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>', 'Sociétés', isActive('societes.php', 'settings')); ?>
            <?php navLink("$basePath/settings/utilisateurs.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', 'Utilisateurs', isActive('utilisateurs.php', 'settings')); ?>
            <?php endif; ?>
            <?php navLink("$basePath/settings/plan_comptable.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>', 'Plan comptable', isActive('plan_comptable.php', 'settings')); ?>
            <?php navLink("$basePath/settings/referentiel_ohada.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>', 'Référentiel OHADA', isActive('referentiel_ohada.php', 'settings')); ?>
            <?php navLink("$basePath/settings/code_journaux.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>', 'Journaux', isActive('code_journaux.php', 'settings')); ?>
            <?php navLink("$basePath/settings/exercices.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>', 'Exercices', isActive('exercices.php', 'settings')); ?>
            <?php navLink("$basePath/settings/tiers.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>', 'Tiers', isActive('tiers.php', 'settings')); ?>

            <?php navLink("$basePath/settings/reprise_soldes.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>', 'Reprise des soldes', isActive('reprise_soldes.php', 'settings')); ?>
            <?php navLink("$basePath/settings/totp_setup.php", '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>', 'Sécurité (2FA)', isActive('totp_setup.php', 'settings')); ?>
        <?php endAccordion(); ?>

    </nav>

    <!-- Utilisateur connecté -->
    <div class="px-2 py-2 border-t border-slate-700/50">
        <div class="flex items-center gap-2 px-2 py-1.5 bg-slate-700/30 rounded-lg">
            <div class="w-6 h-6 bg-gradient-to-br from-blue-500 to-blue-700 rounded-full flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0">
                <?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 2)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-semibold text-white truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></p>
                <p class="text-[9px] text-slate-400 truncate"><?= htmlspecialchars($_SESSION['user_role'] ?? '') ?></p>
            </div>
            <a href="<?= $basePath ?>/auth/logout.php" class="text-slate-500 hover:text-red-400 transition flex-shrink-0" title="Déconnexion">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </a>
        </div>
    </div>
</aside>

<script>
const accordionState = JSON.parse(localStorage.getItem('sidebarAccordion') || '{}');

function toggleSocieteSwitcher() {
    const el   = document.getElementById('societe-switcher');
    const icon = document.getElementById('icon-societes');
    const open = el.classList.contains('hidden');
    el.classList.toggle('hidden', !open);
    icon.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
}
document.addEventListener('click', function(e) {
    const sw  = document.getElementById('societe-switcher');
    const btn = e.target.closest('[onclick="toggleSocieteSwitcher()"]');
    if (sw && !sw.classList.contains('hidden') && !sw.contains(e.target) && !btn) toggleSocieteSwitcher();
});

function toggleAccordion(name) {
    const el   = document.getElementById('accordion-' + name);
    const icon = document.getElementById('icon-' + name);
    const open = el.classList.contains('hidden');
    el.classList.toggle('hidden', !open);
    icon.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
    accordionState[name] = open;
    localStorage.setItem('sidebarAccordion', JSON.stringify(accordionState));
}

document.addEventListener('DOMContentLoaded', function() {
    // Restaurer accordéons ouverts
    Object.entries(accordionState).forEach(([name, open]) => {
        if (open) {
            const el   = document.getElementById('accordion-' + name);
            const icon = document.getElementById('icon-' + name);
            if (el) { el.classList.remove('hidden'); }
            if (icon) { icon.style.transform = 'rotate(180deg)'; }
        }
    });

    // Auto-ouvrir l'accordéon de la page active
    document.querySelectorAll('[id^="accordion-"]').forEach(accordion => {
        if (accordion.querySelector('.bg-blue-500\\/15')) {
            accordion.classList.remove('hidden');
            const name = accordion.id.replace('accordion-', '');
            const icon = document.getElementById('icon-' + name);
            if (icon) icon.style.transform = 'rotate(180deg)';
        }
    });

    // Animation entrée sidebar
    if (typeof anime !== 'undefined') {
        anime({ targets: '#sidebar', opacity: [0, 1], translateX: [-20, 0], duration: 400, easing: 'easeOutQuad' });
    } else {
        document.getElementById('sidebar').style.opacity = '1';
    }
});
</script>
