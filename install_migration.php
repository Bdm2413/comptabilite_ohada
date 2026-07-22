<?php
/**
 * Script d'exécution de la migration v2
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration v2 - ComptaSYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6">
            <h1 class="text-2xl font-bold text-white mb-4">Migration v2 - Adaptation de la structure</h1>

            <div class="bg-blue-500/10 border border-blue-500/50 rounded p-4 mb-6">
                <p class="text-blue-400 text-sm">
                    ℹ️ <strong>Information :</strong> Cette migration va adapter la structure de la base de données pour inclure :
                </p>
                <ul class="list-disc list-inside text-blue-300 text-sm mt-2 space-y-1">
                    <li>Table de correspondance (comptes à 4 chiffres)</li>
                    <li>Nouveau plan comptable détaillé</li>
                    <li>Code journaux (remplace journaux)</li>
                    <li>Plan tiers (avec comptes auxiliaires)</li>
                    <li>Schémas d'écritures prédéfinis</li>
                </ul>
            </div>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
                echo '<div class="space-y-4">';

                try {
                    // Connexion à la base
                    $pdo = new PDO("mysql:host=localhost;dbname=comptabilite_syscohada;charset=utf8mb4", 'root', '', [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);

                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">✅ Connexion à la base de données réussie</p>';
                    echo '</div>';

                    // Lire le fichier SQL
                    $sqlFile = __DIR__ . '/database/migration_v2_fixed.sql';
                    if (!file_exists($sqlFile)) {
                        throw new Exception("Fichier migration_v2_fixed.sql introuvable");
                    }

                    $sql = file_get_contents($sqlFile);

                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Exécution de la migration...</p>';

                    // Séparer et exécuter les requêtes
                    $queries = array_filter(array_map('trim', explode(';', $sql)));
                    $successCount = 0;
                    $errorCount = 0;
                    $errors = [];

                    foreach ($queries as $query) {
                        // Ignorer les commentaires et lignes vides
                        if (empty($query) ||
                            strpos($query, '--') === 0 ||
                            strpos($query, '/*') === 0 ||
                            strpos(trim($query), 'SET') === 0 ||
                            strpos(trim($query), 'USE') === 0) {
                            continue;
                        }

                        try {
                            $pdo->exec($query);
                            $successCount++;
                        } catch (PDOException $e) {
                            $errorCount++;
                            // Ignorer certaines erreurs attendues
                            if (strpos($e->getMessage(), 'already exists') === false &&
                                strpos($e->getMessage(), 'Duplicate entry') === false &&
                                strpos($e->getMessage(), 'doesn\'t exist') === false) {
                                $errors[] = $e->getMessage();
                            }
                        }
                    }

                    echo '<p class="text-green-400 text-sm mt-2">✅ ' . $successCount . ' requêtes exécutées</p>';

                    if ($errorCount > 0) {
                        echo '<p class="text-yellow-400 text-sm mt-1">⚠️ ' . $errorCount . ' requêtes ont échoué (peut-être déjà existantes)</p>';
                    }

                    if (!empty($errors)) {
                        echo '<div class="bg-red-500/10 border border-red-500/50 rounded p-3 mt-3">';
                        echo '<p class="text-red-400 text-sm font-semibold">Erreurs détaillées :</p>';
                        echo '<div class="max-h-40 overflow-y-auto mt-2">';
                        foreach (array_slice($errors, 0, 10) as $error) {
                            echo '<p class="text-red-300 text-xs mt-1">• ' . htmlspecialchars($error) . '</p>';
                        }
                        if (count($errors) > 10) {
                            echo '<p class="text-red-400 text-xs mt-2">... et ' . (count($errors) - 10) . ' autres erreurs</p>';
                        }
                        echo '</div>';
                        echo '</div>';
                    }

                    echo '</div>';

                    // Vérification des tables créées
                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Vérification des tables...</p>';

                    $tables = [
                        'table_correspondance' => 'Table de correspondance',
                        'code_journal' => 'Code journaux',
                        'plan_tiers' => 'Plan tiers',
                        'plan_comptable' => 'Plan comptable',
                        'correspondance_moderne' => 'Correspondances modernes',
                        'sage_piece_counter' => 'Compteur de pièces'
                    ];

                    foreach ($tables as $table => $label) {
                        try {
                            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                            echo '<p class="text-green-400 text-sm mt-1">✅ ' . $label . ' : ' . $count . ' enregistrement(s)</p>';
                        } catch (PDOException $e) {
                            echo '<p class="text-red-400 text-sm mt-1">❌ ' . $label . ' : Table introuvable</p>';
                        }
                    }

                    echo '</div>';

                    // Message de succès
                    echo '<div class="bg-emerald-500/10 border border-emerald-500/50 rounded p-4 mt-4">';
                    echo '<p class="text-emerald-400 font-semibold">🎉 Migration terminée !</p>';
                    echo '<p class="text-emerald-300 text-sm mt-2">La base de données a été mise à jour avec la nouvelle structure.</p>';
                    echo '<div class="mt-4 space-x-2">';
                    echo '<a href="diagnostic.php" class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm transition">Diagnostic</a>';
                    echo '<a href="pages/dashboard/index.php" class="inline-block bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded text-sm transition">Dashboard</a>';
                    echo '</div>';
                    echo '</div>';

                } catch (Exception $e) {
                    echo '<div class="bg-red-500/10 border border-red-500/50 rounded p-4">';
                    echo '<p class="text-red-400 font-semibold">❌ Erreur lors de la migration</p>';
                    echo '<p class="text-red-300 text-sm mt-2">' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div>';
                }

                echo '</div>';

            } else {
                // Formulaire de migration
                ?>
                <div class="bg-amber-500/10 border border-amber-500/50 rounded p-4 mb-6">
                    <p class="text-amber-400 text-sm">
                        ⚠️ <strong>Attention :</strong> Cette opération va modifier la structure de la base de données.
                        Des sauvegardes automatiques seront créées.
                    </p>
                </div>

                <div class="bg-slate-700/30 p-4 rounded mb-6">
                    <h3 class="font-semibold text-white mb-3">Nouveaux menus après migration :</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <p class="text-emerald-400 font-semibold mb-2">Paramètres</p>
                            <ul class="text-slate-300 space-y-1">
                                <li>• Tableau correspondance</li>
                                <li>• Plan comptable</li>
                                <li>• Code journaux</li>
                                <li>• Tiers</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-blue-400 font-semibold mb-2">Opérations</p>
                            <ul class="text-slate-300 space-y-1">
                                <li>• Comptabilisation</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-purple-400 font-semibold mb-2">États</p>
                            <ul class="text-slate-300 space-y-1">
                                <li>• Grand livre</li>
                                <li>• Balance</li>
                                <li>• Bilan</li>
                                <li>• Compte de résultat</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <button
                        type="submit"
                        name="migrate"
                        class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-3 px-4 rounded transition"
                    >
                        🚀 Lancer la migration v2
                    </button>
                </form>

                <div class="mt-6 text-center space-x-4">
                    <a href="diagnostic.php" class="text-blue-400 hover:underline text-sm">Diagnostic</a>
                    <a href="database/README_MIGRATION.md" target="_blank" class="text-blue-400 hover:underline text-sm">Documentation</a>
                    <a href="index.php" class="text-blue-400 hover:underline text-sm">Accueil</a>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</body>
</html>
