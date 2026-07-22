<?php
/**
 * Interface d'affectation du résultat d'un exercice clôturé
 * Permet de ventiler le résultat net vers :
 * - Réserves légales (1111000)
 * - Réserves statutaires (1121000)
 * - Réserves facultatives (1181000)
 * - Dividendes à payer (4651000)
 * - Report à nouveau créditeur/débiteur (1210000/1291000)
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: Aucune société sélectionnée'];
    header('Location: exercices.php');
    exit;
}

$exercice_id = intval($_GET['id'] ?? 0);

// Récupérer les informations de l'exercice
$stmt = $db->prepare("SELECT * FROM exercices WHERE id = ? AND societe_id = ?");
$stmt->execute([$exercice_id, $societe_id]);
$exercice = $stmt->fetch();

if (!$exercice) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Exercice introuvable'];
    header('Location: exercices.php');
    exit;
}

if ($exercice['statut'] !== 'Clôturé') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Seuls les exercices clôturés peuvent être affectés'];
    header('Location: exercices.php');
    exit;
}

// Vérifier si une affectation existe déjà
$affectation_existante = $exercice['ecriture_affectation_id'] !== null;

// Traiter le formulaire d'affectation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $reserve_legale = floatval($_POST['reserve_legale'] ?? 0);
        $reserve_statutaire = floatval($_POST['reserve_statutaire'] ?? 0);
        $reserve_facultative = floatval($_POST['reserve_facultative'] ?? 0);
        $dividendes = floatval($_POST['dividendes'] ?? 0);
        $report_a_nouveau = floatval($_POST['report_a_nouveau'] ?? 0);

        $resultat = $exercice['resultat_calcule'];
        $total_affectation = $reserve_legale + $reserve_statutaire + $reserve_facultative + $dividendes + $report_a_nouveau;

        // Vérifier que le total correspond au résultat
        if (abs($total_affectation - abs($resultat)) > 0.01) {
            throw new Exception("Le total de l'affectation (" . number_format($total_affectation, 0, ',', ' ') . ") ne correspond pas au résultat (" . number_format(abs($resultat), 0, ',', ' ') . ")");
        }

        // Créer l'écriture d'affectation
        $annee = $exercice['annee'];
        $date_affectation = date('Y-m-d');

        $stmt = $db->prepare("
            INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle, statut, createur)
            VALUES (?, ?, ?, ?, ?, 'OD', ?, 'Validé', ?)
        ");

        $numero = 'AFF-' . $annee;
        $libelle = "Affectation du résultat de l'exercice $annee";
        $mois = date('F', strtotime($date_affectation));
        $stmt->execute([$societe_id, $numero, $date_affectation, $mois, $annee + 1, $libelle, 'admin']);
        $ecriture_affectation_id = $db->lastInsertId();

        $stmt_ligne = $db->prepare("
            INSERT INTO lignes_ecriture (id_ecriture, compte, debit, credit)
            VALUES (?, ?, ?, ?)
        ");

        // Débiter le compte de résultat (1310000 pour bénéfice, 1390000 pour perte)
        if ($resultat >= 0) {
            // Bénéfice : débiter 1310000 (ou 1301000 si c'est le résultat en instance d'affectation)
            $stmt_ligne->execute([$ecriture_affectation_id, '1310000', $resultat, 0]);
        } else {
            // Perte : créditer 1390000
            $stmt_ligne->execute([$ecriture_affectation_id, '1390000', 0, abs($resultat)]);
        }

        // Créditer les différentes affectations
        if ($reserve_legale > 0) {
            $stmt_ligne->execute([$ecriture_affectation_id, '1111000', 0, $reserve_legale]);
        }

        if ($reserve_statutaire > 0) {
            $stmt_ligne->execute([$ecriture_affectation_id, '1121000', 0, $reserve_statutaire]);
        }

        if ($reserve_facultative > 0) {
            $stmt_ligne->execute([$ecriture_affectation_id, '1181000', 0, $reserve_facultative]);
        }

        if ($dividendes > 0) {
            $stmt_ligne->execute([$ecriture_affectation_id, '4651000', 0, $dividendes]);
        }

        if ($report_a_nouveau > 0) {
            if ($resultat >= 0) {
                // Report à nouveau créditeur (bénéfice)
                $stmt_ligne->execute([$ecriture_affectation_id, '1210000', 0, $report_a_nouveau]);
            } else {
                // Report à nouveau débiteur (perte)
                $stmt_ligne->execute([$ecriture_affectation_id, '1291000', $report_a_nouveau, 0]);
            }
        }

        // Lier l'écriture d'affectation à l'exercice
        $stmt = $db->prepare("UPDATE exercices SET ecriture_affectation_id = ? WHERE id = ? AND societe_id = ?");
        $stmt->execute([$ecriture_affectation_id, $exercice_id, $societe_id]);

        $db->commit();

        $_SESSION['flash'] = ['type' => 'success', 'message' => "Affectation du résultat enregistrée avec succès (écriture $numero)"];
        header('Location: exercices.php');
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    }
}

$pageTitle = "Affectation du Résultat - Exercice " . $exercice['annee'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center gap-3 mb-2">
                    <a href="exercices.php" class="text-slate-400 hover:text-white transition-colors">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600">
                        <i class="fas fa-balance-scale mr-3"></i>Affectation du Résultat
                    </h1>
                </div>
                <p class="text-slate-400">Exercice <?= $exercice['annee'] ?> - Ventilation du résultat net vers les capitaux propres</p>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mb-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-900/30 border border-green-700 text-green-400' : 'bg-red-900/30 border border-red-700 text-red-400' ?>">
                    <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <?php if ($affectation_existante): ?>
                <!-- Affectation déjà effectuée -->
                <div class="bg-gradient-to-br from-green-900/20 to-blue-900/20 border border-green-700/30 rounded-xl p-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-green-500/20 rounded-full mb-4">
                        <i class="fas fa-check-circle text-green-400 text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-2">Affectation déjà effectuée</h3>
                    <p class="text-slate-400">Le résultat de l'exercice <?= $exercice['annee'] ?> a déjà été affecté.</p>
                    <a href="exercices.php" class="inline-block mt-6 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Retour aux exercices
                    </a>
                </div>
            <?php else: ?>
                <!-- Formulaire d'affectation -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Colonne gauche : Résumé -->
                    <div class="lg:col-span-1">
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6 sticky top-6">
                            <h3 class="text-lg font-semibold text-white mb-4">
                                <i class="fas fa-info-circle mr-2 text-blue-400"></i>Résultat à Affecter
                            </h3>

                            <div class="space-y-4">
                                <!-- Résultat -->
                                <div class="bg-slate-700/30 rounded-lg p-4">
                                    <div class="text-sm text-slate-400 mb-1">Résultat de l'exercice</div>
                                    <div class="text-2xl font-bold <?= $exercice['resultat_calcule'] >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                        <?= number_format(abs($exercice['resultat_calcule']), 0, ',', ' ') ?> F CFA
                                    </div>
                                    <div class="text-xs <?= $exercice['resultat_calcule'] >= 0 ? 'text-green-500' : 'text-red-500' ?> mt-1">
                                        <?= $exercice['resultat_calcule'] >= 0 ? 'Bénéfice' : 'Perte' ?>
                                    </div>
                                </div>

                                <!-- Montant restant à affecter (calculé dynamiquement) -->
                                <div class="bg-purple-900/20 border border-purple-700/30 rounded-lg p-4">
                                    <div class="text-sm text-slate-400 mb-1">Restant à affecter</div>
                                    <div id="montant-restant" class="text-2xl font-bold text-white">
                                        <?= number_format(abs($exercice['resultat_calcule']), 0, ',', ' ') ?> F CFA
                                    </div>
                                </div>

                                <!-- Règles SYSCOHADA -->
                                <div class="border-t border-slate-700 pt-4 mt-4">
                                    <h4 class="text-sm font-semibold text-white mb-2">
                                        <i class="fas fa-book mr-2 text-purple-400"></i>Règles SYSCOHADA
                                    </h4>
                                    <ul class="text-xs text-slate-400 space-y-2">
                                        <li><i class="fas fa-check text-green-400 mr-2"></i>Réserve légale : minimum 10% du bénéfice jusqu'à 20% du capital</li>
                                        <li><i class="fas fa-check text-green-400 mr-2"></i>Réserves statutaires : selon les statuts</li>
                                        <li><i class="fas fa-check text-green-400 mr-2"></i>Le solde peut être affecté en dividendes ou report à nouveau</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Colonne droite : Formulaire -->
                    <div class="lg:col-span-2">
                        <form method="POST" id="form-affectation" class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                            <h3 class="text-lg font-semibold text-white mb-6">
                                <i class="fas fa-sliders-h mr-2 text-purple-400"></i>Ventilation du Résultat
                            </h3>

                            <div class="space-y-4">
                                <?php if ($exercice['resultat_calcule'] >= 0): ?>
                                    <!-- Affectation d'un bénéfice -->

                                    <!-- Réserve légale -->
                                    <div class="bg-slate-700/30 rounded-lg p-4">
                                        <label class="flex items-center justify-between mb-2">
                                            <span class="text-white font-medium">
                                                <i class="fas fa-shield-alt mr-2 text-blue-400"></i>Réserve légale (1111000)
                                            </span>
                                            <span class="text-xs text-slate-400">Obligatoire : min 10%</span>
                                        </label>
                                        <div class="flex items-center gap-3">
                                            <input type="number" name="reserve_legale" step="0.01" min="0"
                                                   class="flex-1 px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500"
                                                   placeholder="0.00"
                                                   oninput="calculerRestant()">
                                            <button type="button" onclick="appliquer10Pourcent()"
                                                    class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm transition-colors whitespace-nowrap">
                                                10%
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Réserves statutaires -->
                                    <div class="bg-slate-700/30 rounded-lg p-4">
                                        <label class="flex items-center justify-between mb-2">
                                            <span class="text-white font-medium">
                                                <i class="fas fa-file-contract mr-2 text-green-400"></i>Réserves statutaires (1121000)
                                            </span>
                                            <span class="text-xs text-slate-400">Selon les statuts</span>
                                        </label>
                                        <input type="number" name="reserve_statutaire" step="0.01" min="0"
                                               class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500"
                                               placeholder="0.00"
                                               oninput="calculerRestant()">
                                    </div>

                                    <!-- Réserves facultatives -->
                                    <div class="bg-slate-700/30 rounded-lg p-4">
                                        <label class="flex items-center justify-between mb-2">
                                            <span class="text-white font-medium">
                                                <i class="fas fa-piggy-bank mr-2 text-purple-400"></i>Réserves facultatives (1181000)
                                            </span>
                                            <span class="text-xs text-slate-400">Facultatif</span>
                                        </label>
                                        <input type="number" name="reserve_facultative" step="0.01" min="0"
                                               class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500"
                                               placeholder="0.00"
                                               oninput="calculerRestant()">
                                    </div>

                                    <!-- Dividendes -->
                                    <div class="bg-slate-700/30 rounded-lg p-4">
                                        <label class="flex items-center justify-between mb-2">
                                            <span class="text-white font-medium">
                                                <i class="fas fa-coins mr-2 text-yellow-400"></i>Dividendes à payer (4651000)
                                            </span>
                                            <span class="text-xs text-slate-400">Distribution aux associés</span>
                                        </label>
                                        <input type="number" name="dividendes" step="0.01" min="0"
                                               class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500"
                                               placeholder="0.00"
                                               oninput="calculerRestant()">
                                    </div>

                                    <!-- Report à nouveau créditeur -->
                                    <div class="bg-slate-700/30 rounded-lg p-4">
                                        <label class="flex items-center justify-between mb-2">
                                            <span class="text-white font-medium">
                                                <i class="fas fa-arrow-circle-right mr-2 text-cyan-400"></i>Report à nouveau créditeur (1210000)
                                            </span>
                                            <span class="text-xs text-slate-400">Bénéfice reporté</span>
                                        </label>
                                        <div class="flex items-center gap-3">
                                            <input type="number" name="report_a_nouveau" step="0.01" min="0"
                                                   class="flex-1 px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white focus:ring-2 focus:ring-purple-500"
                                                   placeholder="0.00"
                                                   oninput="calculerRestant()">
                                            <button type="button" onclick="affecterSolde()"
                                                    class="px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded text-sm transition-colors whitespace-nowrap">
                                                Solde
                                            </button>
                                        </div>
                                    </div>

                                <?php else: ?>
                                    <!-- Affectation d'une perte -->
                                    <div class="bg-red-900/20 border border-red-700/30 rounded-lg p-4 mb-4">
                                        <p class="text-red-400 text-sm">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            L'exercice a dégagé une perte. Elle doit être affectée en report à nouveau débiteur.
                                        </p>
                                    </div>

                                    <!-- Report à nouveau débiteur -->
                                    <div class="bg-slate-700/30 rounded-lg p-4">
                                        <label class="flex items-center justify-between mb-2">
                                            <span class="text-white font-medium">
                                                <i class="fas fa-arrow-circle-right mr-2 text-red-400"></i>Report à nouveau débiteur (1291000)
                                            </span>
                                            <span class="text-xs text-slate-400">Perte à reporter</span>
                                        </label>
                                        <input type="number" name="report_a_nouveau" step="0.01" min="0"
                                               value="<?= abs($exercice['resultat_calcule']) ?>"
                                               readonly
                                               class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white"
                                               oninput="calculerRestant()">
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Boutons -->
                            <div class="flex gap-4 mt-8 pt-6 border-t border-slate-700">
                                <a href="exercices.php"
                                   class="flex-1 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-center transition-colors">
                                    <i class="fas fa-times mr-2"></i>Annuler
                                </a>
                                <button type="submit"
                                        class="flex-1 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all">
                                    <i class="fas fa-check mr-2"></i>Valider l'Affectation
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const resultatTotal = Math.abs(<?= $exercice['resultat_calcule'] ?>);

        function calculerRestant() {
            const reserveLegale = parseFloat(document.querySelector('input[name="reserve_legale"]')?.value || 0);
            const reserveStatutaire = parseFloat(document.querySelector('input[name="reserve_statutaire"]')?.value || 0);
            const reserveFacultative = parseFloat(document.querySelector('input[name="reserve_facultative"]')?.value || 0);
            const dividendes = parseFloat(document.querySelector('input[name="dividendes"]')?.value || 0);
            const reportNouveau = parseFloat(document.querySelector('input[name="report_a_nouveau"]')?.value || 0);

            const totalAffecte = reserveLegale + reserveStatutaire + reserveFacultative + dividendes + reportNouveau;
            const restant = resultatTotal - totalAffecte;

            const montantRestantEl = document.getElementById('montant-restant');
            montantRestantEl.textContent = restant.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' F CFA';

            // Changer la couleur selon le montant restant
            if (Math.abs(restant) < 0.01) {
                montantRestantEl.className = 'text-2xl font-bold text-green-400';
            } else if (restant < 0) {
                montantRestantEl.className = 'text-2xl font-bold text-red-400';
            } else {
                montantRestantEl.className = 'text-2xl font-bold text-orange-400';
            }
        }

        function appliquer10Pourcent() {
            const montant10Pourcent = resultatTotal * 0.10;
            document.querySelector('input[name="reserve_legale"]').value = montant10Pourcent.toFixed(2);
            calculerRestant();
        }

        function affecterSolde() {
            const reserveLegale = parseFloat(document.querySelector('input[name="reserve_legale"]')?.value || 0);
            const reserveStatutaire = parseFloat(document.querySelector('input[name="reserve_statutaire"]')?.value || 0);
            const reserveFacultative = parseFloat(document.querySelector('input[name="reserve_facultative"]')?.value || 0);
            const dividendes = parseFloat(document.querySelector('input[name="dividendes"]')?.value || 0);

            const totalAffecte = reserveLegale + reserveStatutaire + reserveFacultative + dividendes;
            const solde = resultatTotal - totalAffecte;

            if (solde >= 0) {
                document.querySelector('input[name="report_a_nouveau"]').value = solde.toFixed(2);
                calculerRestant();
            }
        }

        // Validation du formulaire
        document.getElementById('form-affectation').addEventListener('submit', function(e) {
            const reserveLegale = parseFloat(document.querySelector('input[name="reserve_legale"]')?.value || 0);
            const reserveStatutaire = parseFloat(document.querySelector('input[name="reserve_statutaire"]')?.value || 0);
            const reserveFacultative = parseFloat(document.querySelector('input[name="reserve_facultative"]')?.value || 0);
            const dividendes = parseFloat(document.querySelector('input[name="dividendes"]')?.value || 0);
            const reportNouveau = parseFloat(document.querySelector('input[name="report_a_nouveau"]')?.value || 0);

            const totalAffecte = reserveLegale + reserveStatutaire + reserveFacultative + dividendes + reportNouveau;
            const difference = Math.abs(resultatTotal - totalAffecte);

            if (difference > 0.01) {
                e.preventDefault();
                alert('⚠️ Erreur : Le total de l\'affectation (' + totalAffecte.toLocaleString('fr-FR') + ' F CFA) ne correspond pas au résultat à affecter (' + resultatTotal.toLocaleString('fr-FR') + ' F CFA).\n\nVeuillez ajuster les montants.');
                return false;
            }

            return confirm(
                '✓ Confirmation de l\'affectation du résultat\n\n' +
                'Résultat à affecter : ' + resultatTotal.toLocaleString('fr-FR') + ' F CFA\n\n' +
                'Répartition :\n' +
                '- Réserve légale : ' + reserveLegale.toLocaleString('fr-FR') + ' F CFA\n' +
                '- Réserves statutaires : ' + reserveStatutaire.toLocaleString('fr-FR') + ' F CFA\n' +
                '- Réserves facultatives : ' + reserveFacultative.toLocaleString('fr-FR') + ' F CFA\n' +
                '- Dividendes : ' + dividendes.toLocaleString('fr-FR') + ' F CFA\n' +
                '- Report à nouveau : ' + reportNouveau.toLocaleString('fr-FR') + ' F CFA\n\n' +
                'Confirmer l\'affectation ?'
            );
        });
    </script>
</body>
</html>
