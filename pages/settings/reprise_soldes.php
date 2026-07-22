<?php
/**
 * Interface de Reprise des Soldes d'Ouverture
 * Permet de démarrer un exercice avec les soldes de clôture de l'exercice précédent
 * sans avoir à saisir tout l'historique des écritures
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    die('Erreur: Aucune société sélectionnée');
}

$exercice_id = isset($_GET['exercice_id']) ? intval($_GET['exercice_id']) : null;

// Récupérer les informations de l'exercice
$exercice = null;
if ($exercice_id) {
    $stmt = $db->prepare("SELECT * FROM exercices WHERE id = ? AND societe_id = ?");
    $stmt->execute([$exercice_id, $societe_id]);
    $exercice = $stmt->fetch();
}

// Récupérer la liste des exercices (tous, même ceux avec écriture d'ouverture)
// On affichera un avertissement si l'écriture existe déjà
$stmt = $db->prepare("
    SELECT e.*, ecr.numero_ecriture as ecriture_ouverture_numero
    FROM exercices e
    LEFT JOIN ecritures ecr ON e.ecriture_ouverture_id = ecr.id AND ecr.societe_id = ?
    WHERE e.societe_id = ?
    ORDER BY e.annee ASC
");
$stmt->execute([$societe_id, $societe_id]);
$exercices_disponibles = $stmt->fetchAll();

// Récupérer tous les comptes actifs
$stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE actif = 'Oui' AND societe_id = ? ORDER BY compte");
$stmt->execute([$societe_id]);
$comptes = $stmt->fetchAll();

$pageTitle = "Reprise des Soldes d'Ouverture";
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
                    <h1 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-600">
                        <i class="fas fa-file-import mr-3"></i>Reprise des Soldes d'Ouverture
                    </h1>
                </div>
                <p class="text-slate-400">Initialisez un exercice avec les soldes de clôture de l'exercice précédent</p>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mb-6 p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-900/30 border border-green-700 text-green-400' : 'bg-red-900/30 border border-red-700 text-red-400' ?>">
                    <i class="fas <?= $_SESSION['flash']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Info Section -->
            <div class="bg-gradient-to-r from-cyan-900/20 to-blue-900/20 border border-cyan-800/30 rounded-lg p-6 mb-6">
                <div class="flex items-start gap-4">
                    <div class="p-3 bg-cyan-500/20 rounded-lg">
                        <i class="fas fa-lightbulb text-cyan-400 text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-white font-semibold mb-2">À quoi sert cette fonctionnalité ?</h3>
                        <div class="text-slate-300 text-sm space-y-2">
                            <p>La reprise des soldes vous permet de <strong>démarrer l'utilisation de l'application</strong> sans avoir à ressaisir des années d'écritures comptables.</p>

                            <div class="mt-4">
                                <p class="font-semibold text-cyan-400 mb-2">Exemple d'utilisation :</p>
                                <ul class="list-disc list-inside space-y-1 ml-4">
                                    <li>Votre entreprise a déjà réalisé les exercices 2020, 2021, 2022, 2023 et 2024</li>
                                    <li>Vous voulez commencer à utiliser cette application pour l'exercice 2025</li>
                                    <li>Au lieu de ressaisir 5 ans d'écritures, vous créez l'exercice 2025 et vous saisissez uniquement les <strong>soldes de clôture au 31/12/2024</strong></li>
                                    <li>L'application génère automatiquement l'écriture d'ouverture pour 2025</li>
                                </ul>
                            </div>

                            <div class="mt-4 p-3 bg-green-900/20 border border-green-700/30 rounded">
                                <p class="text-green-400"><i class="fas fa-check-circle mr-2"></i><strong>Avantages :</strong> Gain de temps considérable, pas besoin de ressaisir l'historique, démarrage immédiat</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$exercice_id): ?>
                <!-- Sélection de l'exercice -->
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-8">
                    <h2 class="text-xl font-bold text-white mb-4">
                        <i class="fas fa-calendar-check mr-2 text-cyan-400"></i>Étape 1 : Sélectionner l'exercice
                    </h2>
                    <p class="text-slate-400 mb-6">Choisissez l'exercice pour lequel vous souhaitez saisir les soldes d'ouverture</p>

                    <?php if (empty($exercices_disponibles)): ?>
                        <div class="bg-orange-900/20 border border-orange-700/30 rounded-lg p-6 text-center">
                            <i class="fas fa-exclamation-triangle text-orange-400 text-3xl mb-3"></i>
                            <p class="text-orange-300 mb-4">Aucun exercice disponible pour la reprise des soldes.</p>
                            <p class="text-slate-400 text-sm">Tous vos exercices ont déjà une écriture d'ouverture ou vous devez créer un nouvel exercice.</p>
                            <a href="exercices.php" class="inline-block mt-4 px-6 py-3 bg-orange-600 hover:bg-orange-700 text-white rounded-lg transition-colors">
                                <i class="fas fa-arrow-left mr-2"></i>Retour aux exercices
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($exercices_disponibles as $ex): ?>
                                <a href="?exercice_id=<?= $ex['id'] ?>"
                                   class="block bg-slate-700/30 hover:bg-slate-700/50 border border-slate-600 hover:border-cyan-500 rounded-lg p-6 transition-all group">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-2xl font-bold text-white">Exercice <?= $ex['annee'] ?></span>
                                        <i class="fas fa-arrow-right text-slate-500 group-hover:text-cyan-400 transition-colors"></i>
                                    </div>
                                    <div class="text-sm text-slate-400">
                                        Du <?= date('d/m/Y', strtotime($ex['date_debut'])) ?><br>
                                        au <?= date('d/m/Y', strtotime($ex['date_fin'])) ?>
                                    </div>
                                    <div class="mt-3 flex gap-2 flex-wrap">
                                        <span class="inline-block px-3 py-1 bg-cyan-900/30 text-cyan-400 border border-cyan-700 rounded-full text-xs">
                                            <?= $ex['statut'] ?>
                                        </span>
                                        <?php if ($ex['ecriture_ouverture_id']): ?>
                                            <span class="inline-block px-3 py-1 bg-orange-900/30 text-orange-400 border border-orange-700 rounded-full text-xs"
                                                  title="Écriture existante : <?= htmlspecialchars($ex['ecriture_ouverture_numero'] ?? '') ?>">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>Écriture existante
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Exercice sélectionné -->
                <?php if (!$exercice): ?>
                    <div class="bg-red-900/30 border border-red-700 rounded-lg p-6 text-center">
                        <i class="fas fa-exclamation-circle text-red-400 text-3xl mb-3"></i>
                        <p class="text-red-300">Exercice introuvable</p>
                        <a href="reprise_soldes.php" class="inline-block mt-4 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                            Retour
                        </a>
                    </div>
                <?php else: ?>
                    <!-- En-tête exercice sélectionné -->
                    <div class="bg-gradient-to-r from-cyan-900/30 to-blue-900/30 border border-cyan-800/30 rounded-lg p-6 mb-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-2xl font-bold text-white mb-1">
                                    <i class="fas fa-calendar-check mr-2 text-cyan-400"></i>Exercice <?= $exercice['annee'] ?>
                                </h2>
                                <p class="text-slate-300">
                                    Du <?= date('d/m/Y', strtotime($exercice['date_debut'])) ?> au <?= date('d/m/Y', strtotime($exercice['date_fin'])) ?>
                                </p>
                            </div>
                            <a href="reprise_soldes.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                                <i class="fas fa-exchange-alt mr-2"></i>Changer d'exercice
                            </a>
                        </div>
                    </div>

                    <?php if ($exercice['ecriture_ouverture_id']): ?>
                        <!-- Avertissement : écriture existante -->
                        <?php
                        $stmt = $db->prepare("SELECT numero_ecriture, date_ecriture FROM ecritures WHERE id = ? AND societe_id = ?");
                        $stmt->execute([$exercice['ecriture_ouverture_id'], $societe_id]);
                        $ecriture_existante = $stmt->fetch();
                        ?>
                        <?php if ($ecriture_existante): ?>
                        <div class="bg-orange-900/20 border border-orange-700/30 rounded-lg p-6 mb-6">
                            <div class="flex items-start gap-4">
                                <div class="p-2 bg-orange-500/20 rounded-lg">
                                    <i class="fas fa-exclamation-triangle text-orange-400 text-xl"></i>
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-white font-semibold mb-2">Écriture d'ouverture existante</h4>
                                    <p class="text-slate-300 text-sm mb-3">
                                        Cet exercice possède déjà une écriture d'ouverture :
                                        <strong><?= htmlspecialchars($ecriture_existante['numero_ecriture']) ?></strong>
                                        du <?= date('d/m/Y', strtotime($ecriture_existante['date_ecriture'])) ?>
                                    </p>
                                    <p class="text-orange-300 text-sm">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        Si vous continuez, l'ancienne écriture sera <strong>supprimée</strong> et remplacée par la nouvelle reprise des soldes.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Choix du mode de saisie -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Mode Saisie Manuelle -->
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                            <div class="flex items-start gap-4 mb-4">
                                <div class="p-3 bg-blue-500/20 rounded-lg">
                                    <i class="fas fa-keyboard text-blue-400 text-2xl"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-xl font-bold text-white mb-2">Saisie Manuelle</h3>
                                    <p class="text-slate-400 text-sm">Saisissez les soldes compte par compte dans un formulaire</p>
                                </div>
                            </div>
                            <ul class="text-slate-300 text-sm space-y-2 mb-4">
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Interface guidée</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Saisie progressive</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Validation en temps réel</li>
                            </ul>
                            <button onclick="activerModeSaisie()"
                                    class="w-full px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg font-medium transition-all">
                                <i class="fas fa-keyboard mr-2"></i>Saisie Manuelle
                            </button>
                        </div>

                        <!-- Mode Import Excel -->
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                            <div class="flex items-start gap-4 mb-4">
                                <div class="p-3 bg-green-500/20 rounded-lg">
                                    <i class="fas fa-file-excel text-green-400 text-2xl"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-xl font-bold text-white mb-2">Import Excel</h3>
                                    <p class="text-slate-400 text-sm">Importez un fichier Excel contenant votre balance</p>
                                </div>
                            </div>
                            <ul class="text-slate-300 text-sm space-y-2 mb-4">
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Import rapide</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Format flexible</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Modèle téléchargeable</li>
                            </ul>
                            <button onclick="activerModeImport()"
                                    class="w-full px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg font-medium transition-all">
                                <i class="fas fa-file-excel mr-2"></i>Import Excel
                            </button>
                        </div>
                    </div>

                    <!-- Section Saisie Manuelle (cachée par défaut) -->
                    <div id="section-saisie-manuelle" class="hidden">
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-xl font-bold text-white">
                                    <i class="fas fa-keyboard mr-2 text-blue-400"></i>Saisie Manuelle des Soldes
                                </h3>
                                <button onclick="cacherModules()" class="text-slate-400 hover:text-white transition-colors">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>

                            <form id="form-saisie-manuelle" action="traiter_reprise_soldes.php" method="POST">
                                <input type="hidden" name="mode" value="saisie_manuelle">
                                <input type="hidden" name="exercice_id" value="<?= $exercice['id'] ?>">

                                <!-- Barre de recherche de compte -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-slate-300 mb-2">
                                        <i class="fas fa-search mr-2"></i>Rechercher un compte
                                    </label>
                                    <input type="text" id="search-compte" placeholder="Tapez un numéro ou intitulé de compte..."
                                           class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white focus:ring-2 focus:ring-blue-500"
                                           oninput="filtrerComptes()">
                                </div>

                                <!-- Tableau des soldes -->
                                <div class="bg-slate-800/50 rounded-lg overflow-hidden mb-6">
                                    <div class="overflow-y-auto max-h-[500px]">
                                        <table class="w-full text-sm">
                                            <thead class="bg-slate-700 sticky top-0 z-10">
                                                <tr>
                                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Compte</th>
                                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Intitulé</th>
                                                    <th class="px-4 py-3 text-right text-xs font-semibold text-green-300 uppercase">Solde Débiteur</th>
                                                    <th class="px-4 py-3 text-right text-xs font-semibold text-red-300 uppercase">Solde Créditeur</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbody-comptes" class="divide-y divide-slate-700">
                                                <?php foreach ($comptes as $compte): ?>
                                                    <tr class="hover:bg-slate-700/30 transition-colors compte-row"
                                                        data-compte="<?= strtolower($compte['compte']) ?>"
                                                        data-intitule="<?= strtolower($compte['intitule_compte']) ?>">
                                                        <td class="px-4 py-3 font-mono text-slate-300"><?= htmlspecialchars($compte['compte']) ?></td>
                                                        <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($compte['intitule_compte']) ?></td>
                                                        <td class="px-4 py-3 text-right">
                                                            <input type="number"
                                                                   name="debit[<?= htmlspecialchars($compte['compte']) ?>]"
                                                                   step="0.01"
                                                                   min="0"
                                                                   placeholder="0.00"
                                                                   class="w-32 px-2 py-1 bg-slate-700 border border-slate-600 rounded text-green-400 text-right focus:ring-2 focus:ring-green-500"
                                                                   oninput="calculerTotaux(); verifierExclusivite(this)">
                                                        </td>
                                                        <td class="px-4 py-3 text-right">
                                                            <input type="number"
                                                                   name="credit[<?= htmlspecialchars($compte['compte']) ?>]"
                                                                   step="0.01"
                                                                   min="0"
                                                                   placeholder="0.00"
                                                                   class="w-32 px-2 py-1 bg-slate-700 border border-slate-600 rounded text-red-400 text-right focus:ring-2 focus:ring-red-500"
                                                                   oninput="calculerTotaux(); verifierExclusivite(this)">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="bg-slate-700 sticky bottom-0">
                                                <tr class="font-bold">
                                                    <td colspan="2" class="px-4 py-3 text-white">
                                                        <i class="fas fa-calculator mr-2"></i>TOTAUX
                                                    </td>
                                                    <td class="px-4 py-3 text-right text-green-300" id="total-debit">0.00</td>
                                                    <td class="px-4 py-3 text-right text-red-300" id="total-credit">0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="px-4 py-3 text-white font-semibold">
                                                        <i class="fas fa-check-circle mr-2"></i>Équilibre
                                                    </td>
                                                    <td colspan="2" class="px-4 py-3 text-center">
                                                        <span id="statut-equilibre" class="inline-block px-3 py-1 rounded-full text-sm">
                                                            En attente de saisie
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>

                                <!-- Boutons -->
                                <div class="flex gap-4">
                                    <button type="button" onclick="cacherModules()"
                                            class="flex-1 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                                        <i class="fas fa-times mr-2"></i>Annuler
                                    </button>
                                    <button type="submit" id="btn-valider-saisie"
                                            class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg font-medium transition-all"
                                            disabled>
                                        <i class="fas fa-check mr-2"></i>Valider la Reprise
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Section Import Excel (cachée par défaut) -->
                    <div id="section-import-excel" class="hidden">
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 p-6">
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-xl font-bold text-white">
                                    <i class="fas fa-file-excel mr-2 text-green-400"></i>Import Excel
                                </h3>
                                <button onclick="cacherModules()" class="text-slate-400 hover:text-white transition-colors">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>

                            <!-- Instructions -->
                            <div class="bg-blue-900/20 border border-blue-700/30 rounded-lg p-4 mb-6">
                                <h4 class="text-white font-semibold mb-2">
                                    <i class="fas fa-info-circle mr-2"></i>Format du fichier Excel
                                </h4>
                                <p class="text-slate-300 text-sm mb-3">Votre fichier Excel doit contenir les colonnes suivantes :</p>
                                <ul class="text-slate-300 text-sm space-y-1 ml-4">
                                    <li><strong>Compte</strong> : Numéro de compte (ex: 4011000)</li>
                                    <li><strong>Débit</strong> : Montant débiteur (laisser vide ou 0 si créditeur)</li>
                                    <li><strong>Crédit</strong> : Montant créditeur (laisser vide ou 0 si débiteur)</li>
                                </ul>
                            </div>

                            <!-- Télécharger modèle -->
                            <div class="mb-6">
                                <button onclick="telechargerModele()"
                                        class="w-full px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                                    <i class="fas fa-download mr-2"></i>Télécharger le Modèle Excel
                                </button>
                            </div>

                            <!-- Upload fichier -->
                            <form id="form-import-excel" action="traiter_reprise_soldes.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="mode" value="import_excel">
                                <input type="hidden" name="exercice_id" value="<?= $exercice['id'] ?>">
                                <input type="hidden" name="data_excel" id="data-excel">

                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-slate-300 mb-2">
                                        <i class="fas fa-upload mr-2"></i>Sélectionner un fichier Excel
                                    </label>
                                    <input type="file"
                                           id="fichier-excel"
                                           accept=".xlsx,.xls"
                                           onchange="traiterFichierExcel(this)"
                                           class="block w-full text-slate-300 bg-slate-800 border border-slate-700 rounded-lg cursor-pointer focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-l-lg file:border-0 file:bg-green-600 file:text-white hover:file:bg-green-700">
                                </div>

                                <!-- Aperçu des données -->
                                <div id="apercu-donnees" class="hidden mb-6">
                                    <h4 class="text-white font-semibold mb-3">
                                        <i class="fas fa-eye mr-2"></i>Aperçu des données importées
                                    </h4>
                                    <div class="bg-slate-800/50 rounded-lg overflow-hidden mb-4">
                                        <div class="overflow-x-auto max-h-[400px]">
                                            <table class="w-full text-sm">
                                                <thead class="bg-slate-700 sticky top-0">
                                                    <tr>
                                                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Compte</th>
                                                        <th class="px-4 py-3 text-right text-xs font-semibold text-green-300 uppercase">Débit</th>
                                                        <th class="px-4 py-3 text-right text-xs font-semibold text-red-300 uppercase">Crédit</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="tbody-apercu" class="divide-y divide-slate-700">
                                                </tbody>
                                                <tfoot class="bg-slate-700 sticky bottom-0">
                                                    <tr class="font-bold">
                                                        <td class="px-4 py-3 text-white">TOTAUX</td>
                                                        <td class="px-4 py-3 text-right text-green-300" id="total-debit-import">0.00</td>
                                                        <td class="px-4 py-3 text-right text-red-300" id="total-credit-import">0.00</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="px-4 py-3 text-white font-semibold">Équilibre</td>
                                                        <td colspan="2" class="px-4 py-3 text-center">
                                                            <span id="statut-equilibre-import" class="inline-block px-3 py-1 rounded-full text-sm"></span>
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Boutons -->
                                <div class="flex gap-4">
                                    <button type="button" onclick="cacherModules()"
                                            class="flex-1 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                                        <i class="fas fa-times mr-2"></i>Annuler
                                    </button>
                                    <button type="submit" id="btn-valider-import"
                                            class="flex-1 px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg font-medium transition-all"
                                            disabled>
                                        <i class="fas fa-check mr-2"></i>Valider la Reprise
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php endif; ?>
            <?php endif; ?>

        </main>
    </div>

    <script>
        function activerModeSaisie() {
            document.getElementById('section-saisie-manuelle').classList.remove('hidden');
            document.getElementById('section-import-excel').classList.add('hidden');
        }

        function activerModeImport() {
            document.getElementById('section-import-excel').classList.remove('hidden');
            document.getElementById('section-saisie-manuelle').classList.add('hidden');
        }

        function cacherModules() {
            document.getElementById('section-saisie-manuelle').classList.add('hidden');
            document.getElementById('section-import-excel').classList.add('hidden');
        }

        function filtrerComptes() {
            const recherche = document.getElementById('search-compte').value.toLowerCase();
            const lignes = document.querySelectorAll('.compte-row');

            lignes.forEach(ligne => {
                const compte = ligne.dataset.compte;
                const intitule = ligne.dataset.intitule;

                if (compte.includes(recherche) || intitule.includes(recherche)) {
                    ligne.style.display = '';
                } else {
                    ligne.style.display = 'none';
                }
            });
        }

        function verifierExclusivite(input) {
            // Un compte ne peut avoir qu'un solde débiteur OU créditeur, pas les deux
            const row = input.closest('tr');
            const debitInput = row.querySelector('input[name^="debit"]');
            const creditInput = row.querySelector('input[name^="credit"]');

            if (input === debitInput && parseFloat(debitInput.value || 0) > 0) {
                creditInput.value = '';
            } else if (input === creditInput && parseFloat(creditInput.value || 0) > 0) {
                debitInput.value = '';
            }
        }

        function calculerTotaux() {
            let totalDebit = 0;
            let totalCredit = 0;

            document.querySelectorAll('input[name^="debit"]').forEach(input => {
                totalDebit += parseFloat(input.value || 0);
            });

            document.querySelectorAll('input[name^="credit"]').forEach(input => {
                totalCredit += parseFloat(input.value || 0);
            });

            document.getElementById('total-debit').textContent = totalDebit.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('total-credit').textContent = totalCredit.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Vérifier l'équilibre
            const difference = Math.abs(totalDebit - totalCredit);
            const statutEl = document.getElementById('statut-equilibre');
            const btnValider = document.getElementById('btn-valider-saisie');

            if (totalDebit === 0 && totalCredit === 0) {
                statutEl.textContent = 'En attente de saisie';
                statutEl.className = 'inline-block px-3 py-1 rounded-full text-sm bg-slate-700 text-slate-400';
                btnValider.disabled = true;
            } else if (difference < 0.01) {
                statutEl.textContent = 'Équilibré';
                statutEl.className = 'inline-block px-3 py-1 rounded-full text-sm bg-green-900/30 text-green-400 border border-green-700';
                btnValider.disabled = false;
            } else {
                statutEl.textContent = 'Déséquilibré (' + difference.toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ')';
                statutEl.className = 'inline-block px-3 py-1 rounded-full text-sm bg-red-900/30 text-red-400 border border-red-700';
                btnValider.disabled = true;
            }
        }

        function telechargerModele() {
            // Créer un fichier Excel modèle
            const wb = XLSX.utils.book_new();
            const data = [
                ['Compte', 'Débit', 'Crédit'],
                ['4011000', '1000000', ''],
                ['5211000', '500000', ''],
                ['4011000', '', '1500000']
            ];
            const ws = XLSX.utils.aoa_to_sheet(data);
            XLSX.utils.book_append_sheet(wb, ws, 'Soldes');
            XLSX.writeFile(wb, 'modele_reprise_soldes.xlsx');
        }

        function traiterFichierExcel(input) {
            const file = input.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });

                // Parser les données
                const soldes = [];
                let totalDebit = 0;
                let totalCredit = 0;

                for (let i = 1; i < jsonData.length; i++) {
                    const row = jsonData[i];
                    if (row.length >= 3 && row[0]) {
                        const compte = String(row[0]).trim();
                        const debit = parseFloat(row[1] || 0);
                        const credit = parseFloat(row[2] || 0);

                        if (debit > 0 || credit > 0) {
                            soldes.push({ compte, debit, credit });
                            totalDebit += debit;
                            totalCredit += credit;
                        }
                    }
                }

                // Afficher l'aperçu
                afficherApercuImport(soldes, totalDebit, totalCredit);

                // Stocker les données pour l'envoi
                document.getElementById('data-excel').value = JSON.stringify(soldes);
            };
            reader.readAsArrayBuffer(file);
        }

        function afficherApercuImport(soldes, totalDebit, totalCredit) {
            const tbody = document.getElementById('tbody-apercu');
            tbody.innerHTML = '';

            soldes.forEach(s => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-700/30';
                tr.innerHTML = `
                    <td class="px-4 py-3 font-mono text-slate-300">${s.compte}</td>
                    <td class="px-4 py-3 text-right text-green-400">${s.debit > 0 ? s.debit.toLocaleString('fr-FR', {minimumFractionDigits: 2}) : '-'}</td>
                    <td class="px-4 py-3 text-right text-red-400">${s.credit > 0 ? s.credit.toLocaleString('fr-FR', {minimumFractionDigits: 2}) : '-'}</td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('total-debit-import').textContent = totalDebit.toLocaleString('fr-FR', { minimumFractionDigits: 2 });
            document.getElementById('total-credit-import').textContent = totalCredit.toLocaleString('fr-FR', { minimumFractionDigits: 2 });

            const difference = Math.abs(totalDebit - totalCredit);
            const statutEl = document.getElementById('statut-equilibre-import');
            const btnValider = document.getElementById('btn-valider-import');

            if (difference < 0.01) {
                statutEl.textContent = 'Équilibré';
                statutEl.className = 'inline-block px-3 py-1 rounded-full text-sm bg-green-900/30 text-green-400 border border-green-700';
                btnValider.disabled = false;
            } else {
                statutEl.textContent = 'Déséquilibré (' + difference.toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ')';
                statutEl.className = 'inline-block px-3 py-1 rounded-full text-sm bg-red-900/30 text-red-400 border border-red-700';
                btnValider.disabled = true;
            }

            document.getElementById('apercu-donnees').classList.remove('hidden');
        }
    </script>
</body>
</html>
