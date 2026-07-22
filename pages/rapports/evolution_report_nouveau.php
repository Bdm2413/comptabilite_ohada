<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Déterminer la plage d'années à afficher
$annee_actuelle = date('Y');
$annee_debut = $_GET['annee_debut'] ?? ($annee_actuelle - 5); // 5 ans en arrière par défaut
$annee_fin = $_GET['annee_fin'] ?? $annee_actuelle;

// Récupérer l'évolution année par année
$evolution = [];
$total_cumule = 0;

for ($annee = $annee_debut; $annee <= $annee_fin; $annee++) {
    $date_debut_exercice = "$annee-01-01";
    $date_fin_exercice = "$annee-12-31";

    // Pour l'année en cours, utiliser la date actuelle au lieu du 31 décembre
    $is_current_year = ($annee == date('Y'));
    $date_fin_calcul = $is_current_year ? date('Y-m-d') : $date_fin_exercice;

    // Compte 12: Report à nouveau comptable
    $sql_compte_12 = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as solde_12
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '12'
        AND e.date_ecriture <= ?
        AND e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.societe_id = ?
    ";
    $stmt_12 = $db->prepare($sql_compte_12);
    $stmt_12->execute([$date_fin_calcul, $societe_id, $societe_id]);
    $compte_12 = $stmt_12->fetchColumn();

    // Pour l'année en cours: calculer à partir des classes 6,7,8
    // Pour les années antérieures: utiliser le compte 13
    if ($is_current_year) {
        $sql_resultat = "
            SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat
            FROM plan_comptable pc
            LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
            LEFT JOIN ecritures e ON le.id_ecriture = e.id
            WHERE pc.actif = 'Oui'
            AND LEFT(pc.compte, 1) IN ('6', '7', '8')
            AND e.date_ecriture BETWEEN ? AND ?
            AND e.statut = 'Validé'
            AND e.societe_id = ?
            AND pc.societe_id = ?
        ";
        $stmt_resultat = $db->prepare($sql_resultat);
        $stmt_resultat->execute([$date_debut_exercice, $date_fin_calcul, $societe_id, $societe_id]);
        $resultat_exercice = $stmt_resultat->fetchColumn();
    } else {
        // Années antérieures: utiliser le compte 13
        $sql_resultat = "
            SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat
            FROM plan_comptable pc
            LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
            LEFT JOIN ecritures e ON le.id_ecriture = e.id
            WHERE pc.actif = 'Oui'
            AND LEFT(pc.compte, 2) = '13'
            AND e.date_ecriture BETWEEN ? AND ?
            AND e.statut = 'Validé'
            AND e.societe_id = ?
            AND pc.societe_id = ?
        ";
        $stmt_resultat = $db->prepare($sql_resultat);
        $stmt_resultat->execute([$date_debut_exercice, $date_fin_exercice, $societe_id, $societe_id]);
        $resultat_exercice = $stmt_resultat->fetchColumn();
    }

    // Résultats cumulés jusqu'à l'année précédente
    $sql_cumul_anterieur = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as cumul
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '13'
        AND e.date_ecriture < ?
        AND e.statut = 'Validé'
        AND e.societe_id = ?
        AND pc.societe_id = ?
    ";
    $stmt_cumul = $db->prepare($sql_cumul_anterieur);
    $stmt_cumul->execute([$date_debut_exercice, $societe_id, $societe_id]);
    $cumul_anterieur = $stmt_cumul->fetchColumn();

    // CH = Compte 12 + Cumul des résultats antérieurs
    $report_nouveau = $compte_12 + $cumul_anterieur;

    // Total cumulé (pour affichage dans l'année suivante)
    $total_cumule = $compte_12 + $cumul_anterieur + $resultat_exercice;

    $evolution[] = [
        'annee' => $annee,
        'compte_12' => $compte_12,
        'cumul_anterieur' => $cumul_anterieur,
        'report_nouveau' => $report_nouveau,
        'resultat_exercice' => $resultat_exercice,
        'total_cumule' => $total_cumule
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Évolution du Report à Nouveau - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-6">
    <div class="bg-gradient-to-r from-slate-800 to-slate-900 rounded-xl shadow-2xl border border-slate-700 overflow-hidden">
        <!-- Header -->
        <div class="p-8 border-b border-slate-700">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-emerald-600 mb-2">
                        <i class="fas fa-chart-line mr-3"></i>Évolution Report à Nouveau
                    </h1>
                    <p class="text-slate-400 mt-2">
                        Analyse cumulative des résultats non affectés de <?= $annee_debut ?> à <?= $annee_fin ?>
                    </p>
                </div>
                <a href="../etats_financiers/bilan.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                    <i class="fas fa-arrow-left mr-2"></i>Retour au Bilan
                </a>
            </div>
        </div>

        <!-- Filtres -->
        <div class="p-6 bg-slate-800/50 border-b border-slate-700">
            <form method="GET" class="flex gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Année de début</label>
                    <input type="number" name="annee_debut" value="<?= $annee_debut ?>"
                           class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-cyan-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Année de fin</label>
                    <input type="number" name="annee_fin" value="<?= $annee_fin ?>"
                           class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-cyan-500">
                </div>
                <button type="submit" class="px-6 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg transition">
                    <i class="fas fa-search mr-2"></i>Actualiser
                </button>
            </form>
        </div>

        <!-- Légende explicative -->
        <div class="p-6 bg-blue-900/20 border-b border-slate-700">
            <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                <i class="fas fa-info-circle text-blue-400"></i>
                Comment lire ce rapport
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-cyan-400 font-semibold mb-1">Compte 12 (Report comptable)</p>
                    <p class="text-slate-300">Montant des écritures d'affectation du résultat passées manuellement</p>
                </div>
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-purple-400 font-semibold mb-1">Cumul Antérieur</p>
                    <p class="text-slate-300">Somme de TOUS les résultats des années précédentes (compte 13)</p>
                </div>
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-yellow-400 font-semibold mb-1">CH (Report à Nouveau)</p>
                    <p class="text-slate-300">Compte 12 + Cumul Antérieur = Report à nouveau au début de l'exercice</p>
                </div>
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-green-400 font-semibold mb-1">Résultat Exercice</p>
                    <p class="text-slate-300">Résultat de l'année (Produits - Charges)</p>
                </div>
            </div>
        </div>

        <!-- Tableau d'évolution -->
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-900/50 sticky top-0">
                    <tr class="border-b border-slate-700">
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-300 uppercase">Année</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-300 uppercase">Compte 12</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-300 uppercase">Cumul Antérieur</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-300 uppercase">CH (Report à Nouveau)</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-300 uppercase">Résultat Exercice</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-300 uppercase">Total Cumulé</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-slate-300 uppercase">Variation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php
                    $precedent_total = null;
                    $annee_actuelle_int = date('Y');
                    foreach ($evolution as $index => $data):
                        $variation = $precedent_total !== null ? ($data['total_cumule'] - $precedent_total) : 0;
                        $precedent_total = $data['total_cumule'];

                        $is_current = ($data['annee'] == $annee_actuelle_int);
                        $row_class = $data['total_cumule'] < 0 ? 'bg-red-900/10' : ($data['total_cumule'] > 0 ? 'bg-green-900/10' : '');
                        if ($is_current) {
                            $row_class .= ' border-l-4 border-cyan-500';
                        }
                    ?>
                    <tr class="hover:bg-slate-700/30 transition-colors <?= $row_class ?>">
                        <td class="px-6 py-4 font-bold text-white">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-calendar-alt text-cyan-400"></i>
                                <?= $data['annee'] ?>
                                <?php if ($is_current): ?>
                                    <span class="ml-2 px-2 py-0.5 bg-cyan-500/20 text-cyan-400 text-xs font-semibold rounded-full">
                                        En cours
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-right font-mono text-cyan-400">
                            <?= number_format($data['compte_12'], 2, ',', ' ') ?>
                        </td>
                        <td class="px-6 py-4 text-right font-mono text-purple-400">
                            <?= number_format($data['cumul_anterieur'], 2, ',', ' ') ?>
                        </td>
                        <td class="px-6 py-4 text-right font-mono font-bold <?= $data['report_nouveau'] < 0 ? 'text-yellow-400' : 'text-yellow-300' ?>">
                            <?= number_format($data['report_nouveau'], 2, ',', ' ') ?>
                        </td>
                        <td class="px-6 py-4 text-right font-mono <?= $data['resultat_exercice'] < 0 ? 'text-red-400' : 'text-green-400' ?>">
                            <?= number_format($data['resultat_exercice'], 2, ',', ' ') ?>
                        </td>
                        <td class="px-6 py-4 text-right font-mono font-bold text-lg <?= $data['total_cumule'] < 0 ? 'text-red-400' : 'text-green-400' ?>">
                            <?= number_format($data['total_cumule'], 2, ',', ' ') ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($index > 0): ?>
                                <?php if ($variation < 0): ?>
                                    <span class="inline-flex items-center gap-1 text-red-400">
                                        <i class="fas fa-arrow-down"></i>
                                        <?= number_format(abs($variation), 2, ',', ' ') ?>
                                    </span>
                                <?php elseif ($variation > 0): ?>
                                    <span class="inline-flex items-center gap-1 text-green-400">
                                        <i class="fas fa-arrow-up"></i>
                                        <?= number_format($variation, 2, ',', ' ') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-500">-</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-slate-500">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Résumé et Analyse -->
        <div class="p-6 bg-slate-800/50 border-t border-slate-700">
            <?php
            $premier = $evolution[0] ?? null;
            $dernier = $evolution[count($evolution) - 1] ?? null;
            if ($premier && $dernier):
                $evolution_totale = $dernier['total_cumule'] - $premier['cumul_anterieur'];
                $annees_deficit = array_filter($evolution, fn($d) => $d['resultat_exercice'] < 0);
                $annees_benefice = array_filter($evolution, fn($d) => $d['resultat_exercice'] > 0);
            ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-slate-900/50 p-6 rounded-lg border border-slate-700">
                    <div class="text-sm text-slate-400 mb-2">Situation Initiale (<?= $premier['annee'] ?>)</div>
                    <div class="text-2xl font-bold <?= $premier['cumul_anterieur'] < 0 ? 'text-red-400' : 'text-green-400' ?>">
                        <?= number_format($premier['cumul_anterieur'], 2, ',', ' ') ?> FCFA
                    </div>
                </div>
                <div class="bg-slate-900/50 p-6 rounded-lg border border-slate-700">
                    <div class="text-sm text-slate-400 mb-2">Évolution Totale</div>
                    <div class="text-2xl font-bold <?= $evolution_totale < 0 ? 'text-red-400' : 'text-green-400' ?>">
                        <?= $evolution_totale >= 0 ? '+' : '' ?><?= number_format($evolution_totale, 2, ',', ' ') ?> FCFA
                    </div>
                </div>
                <div class="bg-slate-900/50 p-6 rounded-lg border border-slate-700">
                    <div class="text-sm text-slate-400 mb-2">Situation Actuelle (<?= $dernier['annee'] ?>)</div>
                    <div class="text-2xl font-bold <?= $dernier['total_cumule'] < 0 ? 'text-red-400' : 'text-green-400' ?>">
                        <?= number_format($dernier['total_cumule'], 2, ',', ' ') ?> FCFA
                    </div>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-red-900/20 p-4 rounded-lg border border-red-700/50">
                    <div class="text-sm text-red-300 mb-1">Années en Déficit</div>
                    <div class="text-xl font-bold text-red-400"><?= count($annees_deficit) ?> année(s)</div>
                </div>
                <div class="bg-green-900/20 p-4 rounded-lg border border-green-700/50">
                    <div class="text-sm text-green-300 mb-1">Années en Bénéfice</div>
                    <div class="text-xl font-bold text-green-400"><?= count($annees_benefice) ?> année(s)</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Note importante -->
        <div class="p-6 bg-yellow-900/20 border-t border-yellow-700/50">
            <div class="flex items-start gap-3">
                <i class="fas fa-exclamation-triangle text-yellow-400 text-xl mt-1"></i>
                <div>
                    <h4 class="font-semibold text-yellow-300 mb-2">Note Importante</h4>
                    <p class="text-slate-300 text-sm leading-relaxed">
                        Ce rapport montre l'évolution du report à nouveau <strong>en l'absence de décision d'affectation du résultat</strong>
                        par l'Assemblée Générale. Selon le SYSCOHADA, le résultat de chaque exercice devrait normalement être affecté
                        en réserves, dividendes ou report à nouveau par décision de l'AG. Une fois cette affectation décidée et comptabilisée,
                        le rapport reflétera les montants effectifs du compte 12 (Report à nouveau).
                    </p>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>
</body>
</html>
