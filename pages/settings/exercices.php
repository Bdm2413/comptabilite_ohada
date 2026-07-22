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
            // Créer un nouvel exercice
            $annee = intval($_POST['annee']);
            $date_debut = $_POST['date_debut'];
            $date_fin = $_POST['date_fin'];

            $stmt = $db->prepare("
                INSERT INTO exercices (societe_id, annee, date_debut, date_fin, statut)
                VALUES (?, ?, ?, ?, 'Ouvert')
            ");
            $stmt->execute([$societe_id, $annee, $date_debut, $date_fin]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => "Exercice $annee créé avec succès"];
            header('Location: exercices.php');
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

$pageTitle = "Gestion des Exercices Comptables";
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

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-2">
                    <i class="fas fa-calendar-check mr-3"></i>Gestion des Exercices Comptables
                </h1>
                <p class="text-slate-400">Créez, clôturez et gérez vos exercices comptables avec report à nouveau automatique</p>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mb-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-900/30 border border-green-700 text-green-400' : 'bg-red-900/30 border border-red-700 text-red-400' ?>">
                    <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Boutons Actions -->
            <div class="mb-6 flex gap-3">
                <button onclick="document.getElementById('modal-create').classList.remove('hidden')"
                        class="px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all duration-300 shadow-lg hover:shadow-xl">
                    <i class="fas fa-plus mr-2"></i>Créer un Nouvel Exercice
                </button>
                <a href="reprise_soldes.php"
                   class="px-6 py-3 bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700 text-white rounded-lg font-medium transition-all duration-300 shadow-lg hover:shadow-xl">
                    <i class="fas fa-file-import mr-2"></i>Reprise des Soldes
                </a>
            </div>

            <!-- Liste des Exercices -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
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
