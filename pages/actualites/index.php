<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer les flux RSS configurés
function fetchRSSFeed($url, $limit = 5) {
    $items = [];

    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,  // Réduit de 5 à 2 secondes pour un chargement plus rapide
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            return $items;
        }

        // Supprimer les caractères invalides
        $content = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $content);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            return $items;
        }

        // Détection automatique du format (RSS ou Atom)
        if (isset($xml->channel->item)) {
            // Format RSS 2.0
            $count = 0;
            foreach ($xml->channel->item as $item) {
                if ($count >= $limit) break;

                $items[] = [
                    'title' => (string)$item->title,
                    'link' => (string)$item->link,
                    'description' => strip_tags((string)$item->description),
                    'pubDate' => strtotime((string)$item->pubDate),
                    'source' => (string)$xml->channel->title
                ];
                $count++;
            }
        } elseif (isset($xml->entry)) {
            // Format Atom
            $count = 0;
            foreach ($xml->entry as $entry) {
                if ($count >= $limit) break;

                $link = '';
                if (isset($entry->link['href'])) {
                    $link = (string)$entry->link['href'];
                }

                $items[] = [
                    'title' => (string)$entry->title,
                    'link' => $link,
                    'description' => strip_tags((string)$entry->summary),
                    'pubDate' => strtotime((string)$entry->updated),
                    'source' => (string)$xml->title
                ];
                $count++;
            }
        }

    } catch (Exception $e) {
        // Erreur silencieuse
    }

    return $items;
}

// Configuration des flux RSS - Uniquement comptabilité OHADA et fiscal
$rss_feeds = [
    [
        'name' => 'OHADA',
        'url' => 'https://www.ohada.org/feed/',
        'category' => 'Normes'
    ],
    [
        'name' => 'DGI Côte d\'Ivoire',
        'url' => 'https://www.dgi.gouv.ci/feed/',
        'category' => 'Fiscal'
    ],
    [
        'name' => 'Ordre des Experts Comptables CI',
        'url' => 'https://www.oecca.ci/feed/',
        'category' => 'Professionnel'
    ]
];

// Récupérer tous les articles
$all_articles = [];
foreach ($rss_feeds as $feed) {
    $articles = fetchRSSFeed($feed['url'], 5); // 5 articles par flux (3 flux = max 15 articles)
    foreach ($articles as &$article) {
        $article['category'] = $feed['category'];
        $article['feed_name'] = $feed['name'];
    }
    $all_articles = array_merge($all_articles, $articles);
}

// Trier par date (plus récent en premier)
usort($all_articles, function($a, $b) {
    return ($b['pubDate'] ?? 0) - ($a['pubDate'] ?? 0);
});

// Limiter à 20 articles
$all_articles = array_slice($all_articles, 0, 20);

// Calendrier fiscal - Échéances importantes
$current_month = date('m');
$current_year = date('Y');

$echeances_fiscales = [
    [
        'date' => $current_year . '-' . $current_month . '-15',
        'title' => 'Déclaration TVA',
        'description' => 'Date limite de déclaration et paiement de la TVA',
        'type' => 'TVA',
        'importance' => 'high'
    ],
    [
        'date' => $current_year . '-' . $current_month . '-20',
        'title' => 'Paiement CNPS',
        'description' => 'Cotisations sociales du mois précédent',
        'type' => 'Social',
        'importance' => 'high'
    ],
    [
        'date' => $current_year . '-' . $current_month . '-25',
        'title' => 'Déclaration mensuelle IS',
        'description' => 'Acompte provisionnel impôt sur les sociétés',
        'type' => 'IS',
        'importance' => 'medium'
    ],
    [
        'date' => date('Y-m-t', strtotime($current_year . '-' . $current_month . '-01')),
        'title' => 'États financiers mensuels',
        'description' => 'Clôture et révision des comptes du mois',
        'type' => 'Comptabilité',
        'importance' => 'medium'
    ]
];

// Trier par date
usort($echeances_fiscales, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Documentation OHADA
$documents_ohada = [
    [
        'title' => 'Acte Uniforme relatif au Droit Comptable',
        'description' => 'AUDCIF - Référentiel comptable SYSCOHADA révisé',
        'category' => 'Législation',
        'icon' => 'fa-balance-scale',
        'url' => 'https://www.ohada.org/actes-uniformes/acte-uniforme-portant-organisation-et-harmonisation-des-comptabilites-des-entreprises/'
    ],
    [
        'title' => 'Plan Comptable SYSCOHADA',
        'description' => 'Liste des comptes et principes comptables',
        'category' => 'Référence',
        'icon' => 'fa-book',
        'url' => 'https://www.ohada.org/index.php/fr/droit-comptable-ohada'
    ],
    [
        'title' => 'États Financiers OHADA',
        'description' => 'Modèles et instructions pour les états financiers',
        'category' => 'Modèles',
        'icon' => 'fa-file-invoice',
        'url' => 'https://www.ohada.org/index.php/fr/etats-financiers-syscohada'
    ],
    [
        'title' => 'Guide Fiscal Côte d\'Ivoire',
        'description' => 'Code Général des Impôts et procédures fiscales',
        'category' => 'Fiscal',
        'icon' => 'fa-landmark',
        'url' => 'https://www.dgi.gouv.ci/'
    ]
];

$pageTitle = "Actualités & Veille";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        body {
            font-size: 12px;
        }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 flex flex-col overflow-hidden p-8">
            <!-- En-tête de page -->
            <div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-100 mb-2">
                <i class="fas fa-newspaper mr-3 text-blue-400"></i>Actualités & Veille
            </h1>
            <p class="text-slate-400">Restez informé des dernières actualités comptables, fiscales et réglementaires</p>
        </div>
        <div class="text-right">
            <div class="text-sm text-slate-400">Dernière mise à jour</div>
            <div class="text-lg font-semibold text-slate-200"><?= date('d/m/Y H:i') ?></div>
        </div>
    </div>
</div>

<!-- Contenu scrollable -->
<div class="flex-1 overflow-y-auto min-h-0">
<!-- Grille principale -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Colonne principale - Actualités RSS -->
    <div class="lg:col-span-2 space-y-6">

        <!-- Module Actualités RSS -->
        <div class="bg-slate-800/50 backdrop-blur border border-slate-700/50 rounded-xl shadow-xl">
            <div class="border-b border-slate-700/50 px-6 py-4">
                <h2 class="text-xl font-bold text-slate-100 flex items-center">
                    <i class="fas fa-rss text-orange-400 mr-3"></i>
                    Fil d'actualités
                </h2>
            </div>

            <div class="p-6">
                <?php if (empty($all_articles)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-exclamation-circle text-6xl text-slate-600 mb-4"></i>
                        <p class="text-slate-400">Aucune actualité disponible pour le moment</p>
                        <p class="text-sm text-slate-500 mt-2">Les flux RSS ne sont pas accessibles ou sont vides</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($all_articles as $article): ?>
                            <article class="border border-slate-700/50 rounded-lg p-4 hover:bg-slate-700/30 transition-all duration-200">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <?php
                                        $category_colors = [
                                            'Normes' => 'bg-purple-900/30 text-purple-400',
                                            'Fiscal' => 'bg-red-900/30 text-red-400',
                                            'Professionnel' => 'bg-green-900/30 text-green-400',
                                            'Économie' => 'bg-blue-900/30 text-blue-400',
                                            'International' => 'bg-orange-900/30 text-orange-400'
                                        ];
                                        $category_class = $category_colors[$article['category']] ?? 'bg-slate-900/30 text-slate-400';
                                        ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded <?= $category_class ?>">
                                            <?= htmlspecialchars($article['category']) ?>
                                        </span>
                                        <span class="text-xs text-slate-500">
                                            <?= htmlspecialchars($article['feed_name']) ?>
                                        </span>
                                    </div>
                                    <?php if (isset($article['pubDate']) && $article['pubDate']): ?>
                                        <time class="text-xs text-slate-500">
                                            <?= date('d/m/Y', $article['pubDate']) ?>
                                        </time>
                                    <?php endif; ?>
                                </div>

                                <h3 class="text-lg font-semibold text-slate-200 mb-2">
                                    <?php if (!empty($article['link'])): ?>
                                        <a href="<?= htmlspecialchars($article['link']) ?>" target="_blank" class="hover:text-blue-400 transition-colors">
                                            <?= htmlspecialchars($article['title']) ?>
                                            <i class="fas fa-external-link-alt text-xs ml-1"></i>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($article['title']) ?>
                                    <?php endif; ?>
                                </h3>

                                <?php if (!empty($article['description'])): ?>
                                    <p class="text-sm text-slate-400 line-clamp-2">
                                        <?= htmlspecialchars(substr($article['description'], 0, 200)) ?>
                                        <?= strlen($article['description']) > 200 ? '...' : '' ?>
                                    </p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Module Documentation OHADA -->
        <div class="bg-slate-800/50 backdrop-blur border border-slate-700/50 rounded-xl shadow-xl">
            <div class="border-b border-slate-700/50 px-6 py-4">
                <h2 class="text-xl font-bold text-slate-100 flex items-center">
                    <i class="fas fa-book-open text-green-400 mr-3"></i>
                    Documentation & Ressources
                </h2>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($documents_ohada as $doc): ?>
                        <div class="border border-slate-700/50 rounded-lg p-4 hover:bg-slate-700/30 transition-all duration-200 hover:border-blue-500/50">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0">
                                    <div class="w-10 h-10 bg-blue-900/30 rounded-lg flex items-center justify-center">
                                        <i class="fas <?= $doc['icon'] ?> text-blue-400"></i>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xs font-semibold px-2 py-0.5 bg-slate-700/50 text-slate-300 rounded">
                                            <?= htmlspecialchars($doc['category']) ?>
                                        </span>
                                    </div>
                                    <h3 class="font-semibold text-slate-200 mb-1 text-sm">
                                        <?php if (!empty($doc['url']) && $doc['url'] !== '#'): ?>
                                            <a href="<?= htmlspecialchars($doc['url']) ?>" target="_blank" class="hover:text-blue-400 transition-colors">
                                                <?= htmlspecialchars($doc['title']) ?>
                                                <i class="fas fa-external-link-alt text-xs ml-1"></i>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($doc['title']) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <p class="text-xs text-slate-400">
                                        <?= htmlspecialchars($doc['description']) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Colonne latérale - Calendrier Fiscal -->
    <div class="lg:col-span-1">
        <div class="bg-slate-800/50 backdrop-blur border border-slate-700/50 rounded-xl shadow-xl sticky top-6">
            <div class="border-b border-slate-700/50 px-6 py-4">
                <h2 class="text-xl font-bold text-slate-100 flex items-center">
                    <i class="fas fa-calendar-alt text-red-400 mr-3"></i>
                    Calendrier Fiscal
                </h2>
                <?php
                $mois_fr = [
                    '01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril',
                    '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août',
                    '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre'
                ];
                ?>
                <p class="text-xs text-slate-400 mt-1"><?= $mois_fr[$current_month] . ' ' . $current_year ?></p>
            </div>

            <div class="p-6">
                <div class="space-y-3">
                    <?php foreach ($echeances_fiscales as $echeance): ?>
                        <?php
                        $days_until = floor((strtotime($echeance['date']) - time()) / (60 * 60 * 24));
                        $is_urgent = $days_until <= 3;
                        $is_passed = $days_until < 0;
                        ?>
                        <div class="border <?= $is_urgent && !$is_passed ? 'border-red-500/50 bg-red-900/10' : 'border-slate-700/50' ?> rounded-lg p-3 <?= $is_passed ? 'opacity-50' : '' ?>">
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full <?= $echeance['importance'] === 'high' ? 'bg-red-500' : 'bg-yellow-500' ?>"></div>
                                    <span class="text-xs font-semibold px-2 py-0.5 bg-slate-700/50 text-slate-300 rounded">
                                        <?= htmlspecialchars($echeance['type']) ?>
                                    </span>
                                </div>
                                <time class="text-xs font-mono <?= $is_urgent && !$is_passed ? 'text-red-400 font-bold' : 'text-slate-500' ?>">
                                    <?= date('d/m', strtotime($echeance['date'])) ?>
                                </time>
                            </div>

                            <h3 class="font-semibold text-slate-200 mb-1 text-sm">
                                <?= htmlspecialchars($echeance['title']) ?>
                            </h3>

                            <p class="text-xs text-slate-400 mb-2">
                                <?= htmlspecialchars($echeance['description']) ?>
                            </p>

                            <?php if (!$is_passed): ?>
                                <div class="flex items-center gap-1 text-xs">
                                    <i class="fas fa-clock text-slate-500"></i>
                                    <span class="<?= $is_urgent ? 'text-red-400 font-semibold' : 'text-slate-500' ?>">
                                        <?php if ($days_until === 0): ?>
                                            Aujourd'hui
                                        <?php elseif ($days_until === 1): ?>
                                            Demain
                                        <?php else: ?>
                                            Dans <?= $days_until ?> jours
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="flex items-center gap-1 text-xs text-slate-500">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Échéance passée</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Bouton voir plus -->
                <div class="mt-4 text-center">
                    <button class="text-sm text-blue-400 hover:text-blue-300 transition-colors">
                        <i class="fas fa-calendar-week mr-2"></i>
                        Voir le calendrier complet
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
</div>
        </main>
    </div>

    <script>
        // Animation de la page - cibler uniquement les cartes de contenu, pas la sidebar
        anime({
            targets: 'main .bg-slate-800\\/50',
            opacity: [0, 1],
            translateY: [20, 0],
            duration: 600,
            delay: anime.stagger(100),
            easing: 'easeOutQuad'
        });
    </script>
</body>
</html>
