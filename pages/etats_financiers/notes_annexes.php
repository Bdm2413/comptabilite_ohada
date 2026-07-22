<?php
require_once '../../config/config.php';
requireLogin();
$societe_id = getCurrentSocieteId();

$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin   = $_GET['date_fin']   ?? date('Y-12-31');
$annee      = date('Y', strtotime($date_fin));

// Définition de toutes les notes avec leur statut
$notes = [
    [
        'groupe' => 'Immobilisations',
        'couleur' => 'emerald',
        'icone' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        'notes' => [
            ['ref'=>'3A', 'titre'=>'Immobilisations (brutes)', 'page'=>'note_3_immos.php', 'statut'=>'ok'],
            ['ref'=>'3B', 'titre'=>'Biens pris en location-acquisition', 'page'=>'note_3_immos.php', 'statut'=>'ok'],
            ['ref'=>'3C', 'titre'=>'Immobilisations (amortissements)', 'page'=>'note_3_immos.php', 'statut'=>'ok'],
            ['ref'=>'3C BIS', 'titre'=>'Immobilisations (dépréciations)', 'page'=>'note_3_immos.php', 'statut'=>'ok'],
            ['ref'=>'3D', 'titre'=>'Plus-values et moins-values de cession', 'page'=>'note_3_immos.php', 'statut'=>'ok'],
            ['ref'=>'3E', 'titre'=>'Informations sur les réévaluations', 'page'=>'note_3e_reevaluations.php', 'statut'=>'ok'],
            ['ref'=>'4',  'titre'=>'Immobilisations financières', 'page'=>'note_4_immos_financieres.php', 'statut'=>'ok'],
        ],
    ],
    [
        'groupe' => 'Actif circulant',
        'couleur' => 'sky',
        'icone' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'notes' => [
            ['ref'=>'5',  'titre'=>'Actif circulant et dettes circulantes HAO', 'page'=>'note_5_hao.php', 'statut'=>'ok'],
            ['ref'=>'6',  'titre'=>'Stocks et en cours', 'page'=>'note_6_stocks.php', 'statut'=>'ok'],
            ['ref'=>'7',  'titre'=>'Clients', 'page'=>'note_7_clients.php', 'statut'=>'ok'],
            ['ref'=>'8',  'titre'=>'Autres créances', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'8A', 'titre'=>'Étalement des charges immobilisées', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'8B', 'titre'=>'Étalement provisions charges à répartir', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'8C', 'titre'=>'Étalement provisions engagements retraite', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'9',  'titre'=>'Titres de placement', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'10', 'titre'=>'Valeurs à encaisser', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'11', 'titre'=>'Disponibilités', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'12', 'titre'=>'Écarts de conversion et transferts de charges', 'page'=>'', 'statut'=>'todo'],
        ],
    ],
    [
        'groupe' => 'Capitaux propres',
        'couleur' => 'violet',
        'icone' => 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z',
        'notes' => [
            ['ref'=>'13',  'titre'=>'Capital', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'14',  'titre'=>'Primes et réserves', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'15A', 'titre'=>'Subventions d\'investissement et provisions réglementées', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'15B', 'titre'=>'Autres fonds propres', 'page'=>'', 'statut'=>'todo'],
        ],
    ],
    [
        'groupe' => 'Dettes et passif',
        'couleur' => 'amber',
        'icone' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3',
        'notes' => [
            ['ref'=>'16A', 'titre'=>'Dettes financières et ressources assimilées', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'16B', 'titre'=>'Engagements de retraite (méthode actuarielle)', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'16C', 'titre'=>'Actifs et passifs éventuels', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'1',   'titre'=>'Dettes garanties par des sûretés réelles', 'page'=>'note_1_garanties.php', 'statut'=>'ok'],
            ['ref'=>'17',  'titre'=>'Fournisseurs d\'exploitation', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'18',  'titre'=>'Dettes fiscales et sociales', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'19',  'titre'=>'Autres dettes et provisions pour risques à court terme', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'20',  'titre'=>'Banques, crédits d\'escompte et de trésorerie', 'page'=>'', 'statut'=>'todo'],
        ],
    ],
    [
        'groupe' => 'Charges et produits',
        'couleur' => 'rose',
        'icone' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
        'notes' => [
            ['ref'=>'21',  'titre'=>'Chiffre d\'affaires et autres produits', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'22',  'titre'=>'Achats', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'23',  'titre'=>'Transports', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'24',  'titre'=>'Services extérieurs', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'25',  'titre'=>'Impôts et taxes', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'26',  'titre'=>'Autres charges', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'27A', 'titre'=>'Charges de personnel', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'27B', 'titre'=>'Effectifs, masse salariale et personnel extérieur', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'28',  'titre'=>'Dotations et charges pour provisions et dépréciations', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'29',  'titre'=>'Charges et revenus financiers', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'30',  'titre'=>'Autres charges et produits HAO', 'page'=>'', 'statut'=>'todo'],
        ],
    ],
    [
        'groupe' => 'Synthèse & Informations',
        'couleur' => 'teal',
        'icone' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'notes' => [
            ['ref'=>'31',  'titre'=>'Répartition du résultat et éléments des 5 derniers exercices', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'32',  'titre'=>'Production de l\'exercice', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'33',  'titre'=>'Achats destinés à la production', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'34',  'titre'=>'Fiche de synthèse des principaux indicateurs financiers', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'35',  'titre'=>'Informations sociales, environnementales et sociétales', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'37',  'titre'=>'Détermination des impôts sur le résultat', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'38',  'titre'=>'Événements postérieurs à la clôture', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'39',  'titre'=>'Changements de méthodes et corrections d\'erreurs', 'page'=>'', 'statut'=>'todo'],
            ['ref'=>'2',   'titre'=>'Informations obligatoires', 'page'=>'note_2_informations.php', 'statut'=>'ok'],
        ],
    ],
];

$total_notes   = array_sum(array_map(fn($g) => count($g['notes']), $notes));
$total_ok      = array_sum(array_map(fn($g) => count(array_filter($g['notes'], fn($n) => $n['statut']==='ok')), $notes));
$pct           = $total_notes > 0 ? round($total_ok / $total_notes * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes Annexes — <?= htmlspecialchars($annee) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex">
<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-auto p-6">

    <!-- En-tête -->
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-1">
            <a href="../dashboard/index.php" class="text-slate-400 hover:text-emerald-400 transition text-xs">← Retour</a>
        </div>
        <h1 class="text-2xl font-bold bg-gradient-to-r from-emerald-400 to-teal-400 bg-clip-text text-transparent">
            Notes Annexes — SYSCOHADA Révisé
        </h1>
        <p class="text-slate-400 text-sm mt-1">Exercice <?= $annee ?> — Liasse DGI Côte d'Ivoire (Système Normal)</p>
    </div>

    <!-- Barre de progression globale -->
    <div class="bg-slate-800 rounded-xl p-5 mb-6 border border-slate-700">
        <div class="flex items-center justify-between mb-3">
            <div>
                <p class="text-sm font-semibold text-slate-200">Progression de l'implémentation</p>
                <p class="text-xs text-slate-400 mt-0.5"><?= $total_ok ?> / <?= $total_notes ?> notes implémentées</p>
            </div>
            <span class="text-2xl font-bold <?= $pct >= 80 ? 'text-emerald-400' : ($pct >= 40 ? 'text-amber-400' : 'text-rose-400') ?>">
                <?= $pct ?>%
            </span>
        </div>
        <div class="w-full bg-slate-700 rounded-full h-2.5">
            <div class="<?= $pct >= 80 ? 'bg-emerald-500' : ($pct >= 40 ? 'bg-amber-500' : 'bg-rose-500') ?> h-2.5 rounded-full transition-all"
                 style="width: <?= $pct ?>%"></div>
        </div>
    </div>

    <!-- Filtre période -->
    <form method="GET" class="bg-slate-800 rounded-xl p-4 mb-6 border border-slate-700 flex flex-wrap gap-4 items-end">
        <div>
            <label class="text-xs text-slate-400 block mb-1">Début exercice</label>
            <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                   class="bg-slate-700 text-slate-200 border border-slate-600 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-emerald-500">
        </div>
        <div>
            <label class="text-xs text-slate-400 block mb-1">Fin exercice</label>
            <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                   class="bg-slate-700 text-slate-200 border border-slate-600 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-emerald-500">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium transition">
            Appliquer
        </button>
    </form>

    <!-- Grille des groupes de notes -->
    <div class="space-y-5">
    <?php foreach ($notes as $groupe): ?>
        <?php
        $ok_g = count(array_filter($groupe['notes'], fn($n) => $n['statut']==='ok'));
        $tot_g = count($groupe['notes']);
        $pct_g = $tot_g > 0 ? round($ok_g / $tot_g * 100) : 0;
        $col = $groupe['couleur'];
        $border_col = "border-{$col}-500/30";
        ?>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl overflow-hidden">
            <!-- En-tête groupe -->
            <div class="flex items-center gap-3 px-5 py-3 bg-slate-800 border-b border-slate-700">
                <div class="w-7 h-7 rounded-lg bg-<?= $col ?>-500/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-<?= $col ?>-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $groupe['icone'] ?>"></path>
                    </svg>
                </div>
                <h2 class="font-semibold text-slate-200 text-sm flex-1"><?= htmlspecialchars($groupe['groupe']) ?></h2>
                <span class="text-xs text-slate-400"><?= $ok_g ?>/<?= $tot_g ?></span>
                <div class="w-20 bg-slate-700 rounded-full h-1.5">
                    <div class="<?= $pct_g === 100 ? 'bg-emerald-500' : 'bg-amber-500' ?> h-1.5 rounded-full" style="width:<?= $pct_g ?>%"></div>
                </div>
            </div>

            <!-- Liste des notes -->
            <div class="divide-y divide-slate-700/50">
            <?php foreach ($groupe['notes'] as $note): ?>
                <?php $is_ok = $note['statut'] === 'ok'; ?>
                <div class="flex items-center gap-3 px-5 py-3 <?= $is_ok ? 'hover:bg-slate-700/30' : 'opacity-60' ?> transition group">
                    <!-- Badge REF -->
                    <span class="inline-flex items-center justify-center min-w-[3.5rem] px-2 py-0.5 rounded-md text-xs font-mono font-bold
                        <?= $is_ok ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-400' ?>">
                        Note <?= htmlspecialchars($note['ref']) ?>
                    </span>

                    <!-- Titre -->
                    <span class="flex-1 text-sm <?= $is_ok ? 'text-slate-200' : 'text-slate-500' ?>">
                        <?= htmlspecialchars($note['titre']) ?>
                    </span>

                    <!-- Statut -->
                    <?php if ($is_ok): ?>
                        <a href="<?= htmlspecialchars($note['page']) ?>?date_debut=<?= urlencode($date_debut) ?>&date_fin=<?= urlencode($date_fin) ?>#note<?= urlencode($note['ref']) ?>"
                           class="flex items-center gap-1.5 text-xs text-emerald-400 hover:text-emerald-300 transition font-medium">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Disponible
                            <svg class="w-3.5 h-3.5 opacity-0 group-hover:opacity-100 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    <?php else: ?>
                        <span class="flex items-center gap-1.5 text-xs text-slate-500">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            À venir
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <p class="text-xs text-slate-600 text-center mt-8">
        Source : Liasse DGI Côte d'Ivoire — Système Normal — Plan Comptable OHADA 2017
    </p>
</main>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
