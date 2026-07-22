<?php
/**
 * Script d'installation automatique de la base de données
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - ComptaSYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-8">
    <div class="max-w-3xl mx-auto">
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6">
            <h1 class="text-2xl font-bold text-white mb-6">Installation de ComptaSYSCOHADA</h1>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
                echo '<div class="space-y-4">';

                // Étape 1 : Connexion au serveur MySQL
                echo '<div class="bg-slate-700/50 p-4 rounded">';
                echo '<p class="font-semibold text-emerald-400">Étape 1 : Connexion au serveur MySQL...</p>';

                try {
                    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", 'root', '', [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    echo '<p class="text-green-400 text-sm mt-2">✅ Connexion réussie au serveur MySQL</p>';
                } catch (PDOException $e) {
                    echo '<p class="text-red-400 text-sm mt-2">❌ Erreur : ' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div></div></div></body></html>';
                    exit;
                }
                echo '</div>';

                // Étape 2 : Création de la base de données
                echo '<div class="bg-slate-700/50 p-4 rounded">';
                echo '<p class="font-semibold text-emerald-400">Étape 2 : Création de la base de données...</p>';

                try {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS comptabilite_syscohada CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    echo '<p class="text-green-400 text-sm mt-2">✅ Base de données "comptabilite_syscohada" créée</p>';
                } catch (PDOException $e) {
                    echo '<p class="text-red-400 text-sm mt-2">❌ Erreur : ' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div></div></div></body></html>';
                    exit;
                }
                echo '</div>';

                // Étape 3 : Sélection de la base de données
                echo '<div class="bg-slate-700/50 p-4 rounded">';
                echo '<p class="font-semibold text-emerald-400">Étape 3 : Connexion à la base de données...</p>';

                try {
                    $pdo->exec("USE comptabilite_syscohada");
                    echo '<p class="text-green-400 text-sm mt-2">✅ Connecté à la base de données</p>';
                } catch (PDOException $e) {
                    echo '<p class="text-red-400 text-sm mt-2">❌ Erreur : ' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div></div></div></body></html>';
                    exit;
                }
                echo '</div>';

                // Étape 4 : Lecture du fichier SQL
                echo '<div class="bg-slate-700/50 p-4 rounded">';
                echo '<p class="font-semibold text-emerald-400">Étape 4 : Lecture du fichier schema.sql...</p>';

                $sqlFile = __DIR__ . '/database/schema.sql';
                if (!file_exists($sqlFile)) {
                    echo '<p class="text-red-400 text-sm mt-2">❌ Fichier schema.sql introuvable</p>';
                    echo '</div></div></div></body></html>';
                    exit;
                }

                $sql = file_get_contents($sqlFile);
                echo '<p class="text-green-400 text-sm mt-2">✅ Fichier lu (' . number_format(strlen($sql)) . ' caractères)</p>';
                echo '</div>';

                // Étape 5 : Exécution du script SQL
                echo '<div class="bg-slate-700/50 p-4 rounded">';
                echo '<p class="font-semibold text-emerald-400">Étape 5 : Exécution du script SQL...</p>';

                try {
                    // Supprimer les commentaires et lignes vides
                    $sql = preg_replace('/^--.*$/m', '', $sql);
                    $sql = preg_replace('/^\s*$/m', '', $sql);

                    // Séparer les requêtes
                    $queries = array_filter(array_map('trim', explode(';', $sql)));

                    $successCount = 0;
                    $errorCount = 0;

                    foreach ($queries as $query) {
                        if (empty($query)) continue;

                        try {
                            $pdo->exec($query);
                            $successCount++;
                        } catch (PDOException $e) {
                            $errorCount++;
                            // Ignorer les erreurs "table already exists"
                            if (strpos($e->getMessage(), 'already exists') === false) {
                                echo '<p class="text-yellow-400 text-xs mt-1">⚠️ ' . htmlspecialchars($e->getMessage()) . '</p>';
                            }
                        }
                    }

                    echo '<p class="text-green-400 text-sm mt-2">✅ ' . $successCount . ' requêtes exécutées avec succès</p>';
                    if ($errorCount > 0) {
                        echo '<p class="text-yellow-400 text-sm mt-1">⚠️ ' . $errorCount . ' requêtes ont échoué (peut-être déjà existantes)</p>';
                    }
                } catch (Exception $e) {
                    echo '<p class="text-red-400 text-sm mt-2">❌ Erreur : ' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div></div></div></body></html>';
                    exit;
                }
                echo '</div>';

                // Étape 6 : Vérification
                echo '<div class="bg-slate-700/50 p-4 rounded">';
                echo '<p class="font-semibold text-emerald-400">Étape 6 : Vérification de l\'installation...</p>';

                try {
                    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    echo '<p class="text-green-400 text-sm mt-2">✅ ' . count($tables) . ' tables créées</p>';

                    // Vérifier l'utilisateur admin
                    $user = $pdo->query("SELECT * FROM utilisateurs WHERE email = 'admin@comptabilite.local'")->fetch();
                    if ($user) {
                        echo '<p class="text-green-400 text-sm mt-1">✅ Utilisateur admin créé</p>';
                        echo '<div class="bg-slate-900 p-3 rounded mt-2 text-xs">';
                        echo '<p class="text-white"><strong>Identifiants de connexion :</strong></p>';
                        echo '<p class="text-slate-300 mt-1">Email : <code class="text-emerald-400">admin@comptabilite.local</code></p>';
                        echo '<p class="text-slate-300">Mot de passe : <code class="text-emerald-400">admin123</code></p>';
                        echo '</div>';
                    }
                } catch (PDOException $e) {
                    echo '<p class="text-red-400 text-sm mt-2">❌ Erreur : ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
                echo '</div>';

                // Message de succès
                echo '<div class="bg-emerald-500/10 border border-emerald-500/50 rounded p-4 mt-4">';
                echo '<p class="text-emerald-400 font-semibold">🎉 Installation terminée avec succès !</p>';
                echo '<div class="mt-4 space-x-2">';
                echo '<a href="clear_session.php" class="inline-block bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded text-sm transition">Nettoyer la session</a>';
                echo '<a href="pages/auth/login.php" class="inline-block bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded text-sm transition">Aller à la connexion</a>';
                echo '</div>';
                echo '</div>';

                echo '</div>'; // fin space-y-4

            } else {
                // Formulaire d'installation
                ?>
                <p class="text-slate-300 mb-4">
                    Ce script va créer automatiquement la base de données <code class="bg-slate-700 px-2 py-1 rounded text-emerald-400">comptabilite_syscohada</code>
                    et importer toutes les tables nécessaires.
                </p>

                <div class="bg-blue-500/10 border border-blue-500/50 rounded p-4 mb-6">
                    <p class="text-blue-400 text-sm">
                        ℹ️ <strong>Informations :</strong> Cette opération va créer environ 15 tables avec le plan comptable SYSCOHADA pré-chargé.
                    </p>
                </div>

                <form method="POST" class="space-y-4">
                    <div class="bg-slate-700/30 p-4 rounded">
                        <h3 class="font-semibold text-white mb-2">Paramètres MySQL</h3>
                        <div class="space-y-2 text-sm">
                            <p><span class="text-slate-400">Hôte :</span> <code class="text-white">localhost</code></p>
                            <p><span class="text-slate-400">Utilisateur :</span> <code class="text-white">root</code></p>
                            <p><span class="text-slate-400">Mot de passe :</span> <code class="text-white">(vide)</code></p>
                            <p><span class="text-slate-400">Base de données :</span> <code class="text-emerald-400">comptabilite_syscohada</code></p>
                        </div>
                    </div>

                    <button
                        type="submit"
                        name="install"
                        class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-3 px-4 rounded transition"
                    >
                        🚀 Lancer l'installation
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <a href="diagnostic.php" class="text-blue-400 hover:underline text-sm">
                        Diagnostic du système
                    </a>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</body>
</html>
