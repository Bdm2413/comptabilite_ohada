<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

// Traiter les actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $annee      = intval($_POST['annee']);
            $date_debut = $_POST['date_debut'];
            $date_fin   = $_POST['date_fin'];

            $stmt = $db->prepare("
                INSERT INTO exercices (societe_id, annee, date_debut, date_fin, statut)
                VALUES (?, ?, ?, ?, 'Ouvert')
            ");
            $stmt->execute([$societe_id, $annee, $date_debut, $date_fin]);
            $exercice_id = (int)$db->lastInsertId();

            // Auto-générer les 12 périodes mensuelles
            $nb = generatePeriodesExercice($exercice_id, $societe_id, $date_debut, $date_fin, $_SESSION['user_id'] ?? 1);

            $_SESSION['flash'] = ['type' => 'success', 'message' => "Exercice $annee créé avec $nb périodes mensuelles générées automatiquement."];
            header('Location: exercices.php?tab=periodes&ex=' . $exercice_id);
            exit;

        } elseif ($action === 'open_periode') {
            $periode_id = (int)($_POST['periode_id'] ?? 0);
            ouvrirPeriode($periode_id, $societe_id, $_SESSION['user_id'] ?? 1);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Période ouverte avec succès.'];
            header('Location: exercices.php?tab=periodes&ex=' . (int)($_POST['exercice_id'] ?? 0));
            exit;

        } elseif ($action === 'close_periode') {
            $periode_id = (int)($_POST['periode_id'] ?? 0);
            $definitif  = !empty($_POST['definitif']);
            fermerPeriode($periode_id, $societe_id, $_SESSION['user_id'] ?? 1, $definitif);
            $msg = $definitif ? 'Période définitivement clôturée.' : 'Période fermée avec succès.';
            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
            header('Location: exercices.php?tab=periodes&ex=' . (int)($_POST['exercice_id'] ?? 0));
            exit;

        } elseif ($action === 'open_all_periodes') {
            $ex_id   = (int)($_POST['exercice_id'] ?? 0);
            $periodes = getPeriodesExercice($ex_id, $societe_id);
            $count = 0;
            foreach ($periodes as $p) {
                if (in_array($p['statut'], ['Jamais_ouvert', 'Fermé'])) {
                    ouvrirPeriode((int)$p['id_periode'], $societe_id, $_SESSION['user_id'] ?? 1);
                    $count++;
                }
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => "$count période(s) ouverte(s)."];
            header('Location: exercices.php?tab=periodes&ex=' . $ex_id);
            exit;

        } elseif ($action === 'calculate_result') {
            // Calculer le résultat d'un exercice
            $exercice_id = intval($_POST['exercice_id']);

            // Récupérer l'année de l'exercice
            $stmt = $db->prepare("SELECT annee FROM exercices WHERE id = ? AND societe_id = ?");
            $stmt->execute([$exercice_id, $societe_id]);
            $exercice = $stmt->fetch();
            $annee = $exercice['annee'];

            // Calculer le total des charges (classes 6 et 8)
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(le.debit - le.credit), 0) as total_charges
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE e.societe_id = ?
                    AND e.statut = 'Validé'
                    AND YEAR(e.date_ecriture) = ?
                    AND (LEFT(le.compte, 1) = '6' OR LEFT(le.compte, 1) = '8')
            ");
            $stmt->execute([$societe_id, $annee]);
            $total_charges = $stmt->fetch()['total_charges'];

            // Calculer le total des produits (classe 7)
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(le.credit - le.debit), 0) as total_produits
                FROM lignes_ecriture le
                INNER JOIN ecritures e ON le.id_ecriture = e.id
                WHERE e.societe_id = ?
                    AND e.statut = 'Validé'
                    AND YEAR(e.date_ecriture) = ?
                    AND LEFT(le.compte, 1) = '7'
            ");
            $stmt->execute([$societe_id, $annee]);
            $total_produits = $stmt->fetch()['total_produits'];

            // Résultat = Produits - Charges
            $resultat = $total_produits - $total_charges;

            // Mettre à jour l'exercice
            $stmt = $db->prepare("
                UPDATE exercices
                SET resultat_calcule = ?
                WHERE id = ? AND societe_id = ?
            ");
            $stmt->execute([$resultat, $exercice_id, $societe_id]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => "Résultat calculé : " . number_format($resultat, 0, ',', ' ') . " F CFA"];
            header('Location: exercices.php');
            exit;
        }

    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
        header('Location: exercices.php');
        exit;
    }
}

// Récupérer tous les exercices
$stmt = $db->prepare("SELECT * FROM exercices WHERE societe_id = ? ORDER BY annee DESC");
$stmt->execute([$societe_id]);
$exercices = $stmt->fetchAll();

// Onglet actif et exercice sélectionné pour la vue périodes
$tab    = $_GET['tab'] ?? 'exercices';
$ex_sel = (int)($_GET['ex'] ?? ($exercices[0]['id'] ?? 0));

// Données de périodes pour l'onglet Périodes
$periodes       = [];
$exercice_actif = null;
if ($tab === 'periodes' && $ex_sel) {
    $periodes       = getPeriodesExercice($ex_sel, $societe_id);
    foreach ($exercices as $e) {
        if ($e['id'] === $ex_sel) { $exercice_actif = $e; break; }
    }
    // Si l'exercice existe mais n'a pas de périodes, les générer maintenant
    if ($exercice_actif && empty($periodes)) {
        generatePeriodesExercice($ex_sel, $societe_id, $exercice_actif['date_debut'], $exercice_actif['date_fin'], $_SESSION['user_id'] ?? 1);
        $periodes = getPeriodesExercice($ex_sel, $societe_id);
    }
}

$pageTitle = "Gestion des périodes";
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
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-6">
            <!-- Header -->
            <div class="mb-5">
                <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-500 mb-1">
                    <i class="fas fa-calendar-check mr-2"></i>Gestion des périodes
                </h1>
                <p class="text-slate-400 text-sm">Gérez vos exercices et contrôlez l'ouverture/fermeture des périodes mensuelles</p>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mb-5 px-4 py-3 rounded-lg text-sm font-medium
                    <?= $_SESSION['flash']['type'] === 'success' ? 'bg-emerald-900/40 border border-emerald-700/50 text-emerald-300' : 'bg-rose-900/40 border border-rose-700/50 text-rose-300' ?>">
                    <i class="fas fa-<?= $_SESSION['flash']['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> mr-2"></i>
                    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Onglets -->
            <div class="flex gap-1 border-b border-slate-700 mb-6">
                <a href="?tab=exercices"
                   class="px-5 py-2.5 text-sm font-medium transition <?= $tab==='exercices' ? 'border-b-2 border-purple-500 text-purple-300' : 'border-b-2 border-transparent text-slate-400 hover:text-slate-300' ?>">
                    <i class="fas fa-calendar-alt mr-2"></i>Exercices
                </a>
                <a href="?tab=periodes<?= $ex_sel ? '&ex='.$ex_sel : '' ?>"
                   class="px-5 py-2.5 text-sm font-medium transition <?= $tab==='periodes' ? 'border-b-2 border-purple-500 text-purple-300' : 'border-b-2 border-transparent text-slate-400 hover:text-slate-300' ?>">
                    <i class="fas fa-calendar-week mr-2"></i>Périodes mensuelles
                </a>
            </div>

            <?php if ($tab === 'periodes'): ?>
            <!-- ================================================================ -->
            <!-- ONGLET 2 : GESTION DES PÉRIODES -->
            <!-- ================================================================ -->

            <?php if (empty($exercices)): ?>
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-8 text-center">
                <i class="fas fa-calendar-xmark text-4xl text-slate-600 mb-3 block"></i>
                <p class="text-slate-400">Aucun exercice trouvé. Créez d'abord un exercice dans l'onglet "Exercices".</p>
            </div>
            <?php else: ?>

            <!-- Sélecteur d'exercice -->
            <div class="flex items-center gap-4 mb-6">
                <label class="text-sm text-slate-400 font-medium">Exercice :</label>
                <div class="flex gap-2">
                    <?php foreach ($exercices as $e): ?>
                    <a href="?tab=periodes&ex=<?= $e['id'] ?>"
                       class="px-4 py-1.5 rounded-lg text-sm font-medium transition
                              <?= $e['id']==$ex_sel ? 'bg-purple-700 text-white' : 'bg-slate-700 hover:bg-slate-600 text-slate-300' ?>">
                        <?= $e['annee'] ?>
                        <span class="ml-1 text-xs opacity-70"><?= $e['statut'] === 'Ouvert' ? '●' : '○' ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($exercice_actif): ?>
                <span class="text-xs text-slate-500">
                    <?= date('d/m/Y', strtotime($exercice_actif['date_debut'])) ?> -
                    <?= date('d/m/Y', strtotime($exercice_actif['date_fin'])) ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($exercice_actif && !empty($periodes)): ?>

            <!-- Légende statuts -->
            <div class="flex flex-wrap gap-2 mb-4 text-xs">
                <?php foreach (PERIODE_STATUT_COLORS as $statut => $cls): ?>
                <span class="px-2 py-1 rounded <?= $cls['bg'] ?> <?= $cls['text'] ?>">
                    <i class="fas <?= $cls['icon'] ?> mr-1"></i><?= str_replace('_', ' ', $statut) ?>
                </span>
                <?php endforeach; ?>
                <span class="text-slate-600 ml-2">Seul l'administrateur peut ouvrir ou fermer une période.</span>
            </div>

            <!-- Grille des périodes (style Oracle GL) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 mb-6">
                <?php foreach ($periodes as $p):
                    $isAjust = $p['type_periode_detail'] === 'ajustement';
                    $cls     = PERIODE_STATUT_COLORS[$p['statut']] ?? PERIODE_STATUT_COLORS['Jamais_ouvert'];
                    $canOpen = in_array($p['statut'], ['Jamais_ouvert', 'Fermé']);
                    $canClose= $p['statut'] === 'Ouvert';
                ?>
                <div class="bg-slate-800 border <?= $p['statut']==='Ouvert' ? 'border-emerald-700/60' : 'border-slate-700' ?> rounded-xl p-4
                            <?= $isAjust ? 'opacity-70' : '' ?>">
                    <div class="flex items-start justify-between mb-2">
                        <div>
                            <p class="font-semibold text-slate-100 text-sm"><?= htmlspecialchars($p['libelle']) ?></p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                <?= date('d/m', strtotime($p['date_debut'])) ?> -
                                <?= date('d/m/Y', strtotime($p['date_fin'])) ?>
                            </p>
                        </div>
                        <span class="text-xs font-bold text-slate-600">#<?= $p['periode_numero'] ?></span>
                    </div>

                    <!-- Statut badge -->
                    <div class="mb-3">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium <?= $cls['bg'] ?> <?= $cls['text'] ?>">
                            <i class="fas <?= $cls['icon'] ?> text-xs"></i>
                            <?= str_replace('_', ' ', $p['statut']) ?>
                        </span>
                        <?php if ($isAjust): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-indigo-900/40 text-indigo-300 text-xs rounded">Ajustement</span>
                        <?php endif; ?>
                    </div>

                    <!-- Infos audit -->
                    <?php if ($p['date_ouverture']): ?>
                    <p class="text-xs text-slate-600 mb-1">
                        <i class="fas fa-unlock text-xs mr-1"></i>
                        Ouvert <?= date('d/m/Y H:i', strtotime($p['date_ouverture'])) ?>
                        <?= $p['ouvert_par_nom'] ? 'par ' . htmlspecialchars($p['ouvert_par_nom']) : '' ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($p['date_cloture_periode']): ?>
                    <p class="text-xs text-slate-600 mb-1">
                        <i class="fas fa-lock text-xs mr-1"></i>
                        Fermé <?= date('d/m/Y H:i', strtotime($p['date_cloture_periode'])) ?>
                        <?= $p['ferme_par_nom'] ? 'par ' . htmlspecialchars($p['ferme_par_nom']) : '' ?>
                    </p>
                    <?php endif; ?>

                    <!-- Boutons action (admin seulement) -->
                    <?php $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin'; ?>
                    <?php if ($isAdmin): ?>
                    <div class="flex gap-2 mt-3">
                        <?php if ($canOpen): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="open_periode">
                            <input type="hidden" name="periode_id" value="<?= $p['id_periode'] ?>">
                            <input type="hidden" name="exercice_id" value="<?= $ex_sel ?>">
                            <button type="submit"
                                    class="px-3 py-1 bg-emerald-700 hover:bg-emerald-600 text-white text-xs rounded transition">
                                <i class="fas fa-unlock mr-1"></i>Ouvrir
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canClose): ?>
                        <form method="POST" onsubmit="return confirm('Fermer la période <?= htmlspecialchars($p['libelle']) ?> ?\n\nLes écritures sur cette période seront bloquées.\nL\'administrateur pourra la rouvrir si nécessaire.')">
                            <input type="hidden" name="action" value="close_periode">
                            <input type="hidden" name="periode_id" value="<?= $p['id_periode'] ?>">
                            <input type="hidden" name="exercice_id" value="<?= $ex_sel ?>">
                            <button type="submit"
                                    class="px-3 py-1 bg-amber-700 hover:bg-amber-600 text-white text-xs rounded transition">
                                <i class="fas fa-lock mr-1"></i>Fermer
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($p['statut'] === 'Fermé'): ?>
                        <form method="POST" onsubmit="return confirm('CLÔTURE DÉFINITIVE de <?= htmlspecialchars($p['libelle']) ?> ?\n\nCette action est IRRÉVERSIBLE.\nLa période ne pourra plus jamais être rouverte.')">
                            <input type="hidden" name="action" value="close_periode">
                            <input type="hidden" name="periode_id" value="<?= $p['id_periode'] ?>">
                            <input type="hidden" name="exercice_id" value="<?= $ex_sel ?>">
                            <input type="hidden" name="definitif" value="1">
                            <button type="submit"
                                    class="px-3 py-1 bg-red-800 hover:bg-red-700 text-white text-xs rounded transition">
                                <i class="fas fa-ban mr-1"></i>Définitif
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($p['statut'] === 'Ouvert'): ?>
                    <p class="text-xs text-emerald-600 mt-2"><i class="fas fa-info-circle mr-1"></i>Saisie active</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Actions globales (admin) -->
            <?php if ($isAdmin): ?>
            <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl p-4 flex flex-wrap items-center gap-4">
                <span class="text-sm text-slate-400"><i class="fas fa-shield-alt mr-1 text-amber-400"></i>Actions globales :</span>
                <form method="POST" onsubmit="return confirm('Ouvrir TOUTES les périodes de l\'exercice <?= $exercice_actif['annee'] ?> ?')">
                    <input type="hidden" name="action" value="open_all_periodes">
                    <input type="hidden" name="exercice_id" value="<?= $ex_sel ?>">
                    <button type="submit" class="px-4 py-1.5 bg-emerald-800 hover:bg-emerald-700 text-white text-sm rounded-lg transition">
                        <i class="fas fa-unlock mr-1"></i>Ouvrir toutes les périodes
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <?php endif; // exercice_actif && periodes ?>
            <?php endif; // exercices non vides ?>

            <?php else: ?>
            <!-- ================================================================ -->
            <!-- ONGLET 1 : LISTE DES EXERCICES -->
            <!-- ================================================================ -->

            <!-- Boutons Actions -->
            <div class="mb-6 flex gap-3">
                <button onclick="document.getElementById('modal-create').classList.remove('hidden')"
                        class="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all shadow-lg text-sm">
                    <i class="fas fa-plus mr-2"></i>Créer un Nouvel Exercice
                </button>
                <a href="reprise_soldes.php"
                   class="px-5 py-2.5 bg-gradient-to-r from-cyan-700 to-blue-700 hover:from-cyan-800 hover:to-blue-800 text-white rounded-lg font-medium transition-all shadow-lg text-sm">
                    <i class="fas fa-file-import mr-2"></i>Reprise des Soldes
                </a>
            </div>

            <!-- Liste des Exercices -->
            <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-slate-700/50">
                                <th class="px-6 py-4 text-left text-xs font-semibold text-slate-300 uppercase tracking-wider">Année</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-slate-300 uppercase tracking-wider">Période</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-slate-300 uppercase tracking-wider">Statut</th>
                                <th class="px-6 py-4 text-right text-xs font-semibold text-slate-300 uppercase tracking-wider">Résultat</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-slate-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <?php foreach ($exercices as $ex): ?>
                                <?php
                                $statusColors = [
                                    'Ouvert' => 'bg-green-900/30 text-green-400 border-green-700',
                                    'En cours de clôture' => 'bg-orange-900/30 text-orange-400 border-orange-700',
                                    'Clôturé' => 'bg-slate-700/30 text-slate-400 border-slate-600'
                                ];
                                $statusColor = $statusColors[$ex['statut']] ?? 'bg-slate-700/30 text-slate-400';
                                ?>
                                <tr class="hover:bg-slate-700/20 transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="text-xl font-bold text-white"><?= $ex['annee'] ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-slate-300">
                                        <?= date('d/m/Y', strtotime($ex['date_debut'])) ?>
                                        <i class="fas fa-arrow-right mx-2 text-slate-500"></i>
                                        <?= date('d/m/Y', strtotime($ex['date_fin'])) ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium border <?= $statusColor ?>">
                                            <?= $ex['statut'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($ex['resultat_calcule'] !== null): ?>
                                            <?php if ($ex['resultat_calcule'] >= 0): ?>
                                                <span class="text-green-400 font-semibold">
                                                    <i class="fas fa-arrow-up mr-1"></i>
                                                    <?= number_format($ex['resultat_calcule'], 0, ',', ' ') ?> F CFA
                                                </span>
                                                <div class="text-xs text-slate-500 mt-1">Bénéfice</div>
                                            <?php else: ?>
                                                <span class="text-red-400 font-semibold">
                                                    <i class="fas fa-arrow-down mr-1"></i>
                                                    <?= number_format(abs($ex['resultat_calcule']), 0, ',', ' ') ?> F CFA
                                                </span>
                                                <div class="text-xs text-slate-500 mt-1">Perte</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-slate-500">Non calculé</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-center gap-2">
                                            <?php if ($ex['statut'] === 'Ouvert'): ?>
                                                <!-- Calculer le résultat -->
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="calculate_result">
                                                    <input type="hidden" name="exercice_id" value="<?= $ex['id'] ?>">
                                                    <button type="submit"
                                                            class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm transition-colors"
                                                            title="Calculer le résultat">
                                                        <i class="fas fa-calculator mr-1"></i>Calculer
                                                    </button>
                                                </form>

                                                <!-- Clôturer -->
                                                <button onclick="confirmerCloture(<?= $ex['id'] ?>, <?= $ex['annee'] ?>)"
                                                        class="px-3 py-1 bg-orange-600 hover:bg-orange-700 text-white rounded text-sm transition-colors"
                                                        title="Clôturer l'exercice">
                                                    <i class="fas fa-lock mr-1"></i>Clôturer
                                                </button>
                                            <?php else: ?>
                                                <!-- Exercice clôturé -->
                                                <?php if ($ex['ecriture_affectation_id'] === null): ?>
                                                    <!-- Affectation non encore effectuée -->
                                                    <a href="affectation_resultat.php?id=<?= $ex['id'] ?>"
                                                       class="px-3 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded text-sm transition-colors"
                                                       title="Affecter le résultat">
                                                        <i class="fas fa-balance-scale mr-1"></i>Affecter le résultat
                                                    </a>
                                                <?php else: ?>
                                                    <!-- Affectation déjà effectuée -->
                                                    <span class="text-green-400 text-sm">
                                                        <i class="fas fa-check-circle mr-1"></i>Résultat affecté
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Info Section -->
            <div class="mt-6 bg-gradient-to-r from-blue-900/20 to-purple-900/20 border border-blue-800/30 rounded-lg p-6">
                <div class="flex items-start gap-4">
                    <div class="p-2 bg-blue-500/20 rounded-lg">
                        <i class="fas fa-info-circle text-blue-400 text-xl"></i>
                    </div>
                    <div>
                        <h4 class="text-white font-semibold mb-2">À propos des exercices comptables</h4>
                        <ul class="text-slate-400 text-sm space-y-1">
                            <li><i class="fas fa-check text-green-400 mr-2"></i>Un exercice comptable correspond généralement à une année civile</li>
                            <li><i class="fas fa-check text-green-400 mr-2"></i>La clôture d'un exercice génère automatiquement les écritures de report à nouveau</li>
                            <li><i class="fas fa-check text-green-400 mr-2"></i>Les comptes de résultat (classes 6, 7, 8) sont soldés à la clôture</li>
                            <li><i class="fas fa-check text-green-400 mr-2"></i>Les comptes de bilan (classes 1-5) sont reportés sur le nouvel exercice</li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php endif; // tab exercices vs periodes ?>

        </main>
    </div>

    <!-- Modal Création Exercice -->
    <div id="modal-create" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-slate-800 rounded-xl p-6 w-full max-w-md border border-slate-700">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white">
                    <i class="fas fa-plus-circle mr-2 text-purple-400"></i>Créer un Nouvel Exercice
                </h3>
                <button onclick="document.getElementById('modal-create').classList.add('hidden')"
                        class="text-slate-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create">

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Année</label>
                    <input type="number" name="annee" required min="2020" max="2100"
                           class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                           placeholder="Ex: 2027">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Date de début</label>
                    <input type="date" name="date_debut" required
                           class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Date de fin</label>
                    <input type="date" name="date_fin" required
                           class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button"
                            onclick="document.getElementById('modal-create').classList.add('hidden')"
                            class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                        Annuler
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all">
                        Créer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Formulaire caché pour la clôture -->
    <form id="form-cloture" method="POST" action="cloture_exercice.php" style="display: none;">
        <input type="hidden" name="exercice_id" id="cloture_exercice_id">
    </form>

    <script>
        // Animation de la page
        anime({
            targets: 'table tbody tr',
            opacity: [0, 1],
            translateY: [20, 0],
            delay: anime.stagger(50),
            easing: 'easeOutQuad'
        });

        // Fonction de confirmation de clôture
        function confirmerCloture(exerciceId, annee) {
            const confirmation = confirm(
                `⚠️ ATTENTION - Clôture de l'exercice ${annee}\n\n` +
                `Cette action va :\n` +
                `1. Solder tous les comptes de résultat (classes 6, 7, 8)\n` +
                `2. Créer une écriture de clôture automatique\n` +
                `3. Générer l'écriture d'ouverture du prochain exercice (report à nouveau)\n` +
                `4. Verrouiller l'exercice (plus de modifications possibles)\n\n` +
                `Voulez-vous vraiment clôturer l'exercice ${annee} ?`
            );

            if (confirmation) {
                document.getElementById('cloture_exercice_id').value = exerciceId;
                document.getElementById('form-cloture').submit();
            }
        }
    </script>
</body>
</html>
