<?php
/**
 * Script de diagnostic de l'application
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic - ComptaSYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold text-white mb-6">Diagnostic de l'application</h1>

        <?php
        // 1. Vérification de la session
        session_start();
        ?>
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 mb-4">
            <h2 class="text-xl font-semibold text-emerald-400 mb-3">1. État de la session</h2>
            <div class="text-sm">
                <p class="mb-2"><strong>Session active :</strong> <?php echo session_status() === PHP_SESSION_ACTIVE ? '✅ Oui' : '❌ Non'; ?></p>
                <p class="mb-2"><strong>Session ID :</strong> <?php echo session_id(); ?></p>
                <p class="mb-2"><strong>Variables de session :</strong></p>
                <pre class="bg-slate-900 p-3 rounded text-xs overflow-auto"><?php print_r($_SESSION); ?></pre>
            </div>
        </div>

        <?php
        // 2. Vérification de la connexion à la base de données
        $dbConnected = false;
        $dbError = '';
        $dbName = 'comptabilite_syscohada';

        try {
            $dsn = "mysql:host=localhost;dbname=$dbName;charset=utf8mb4";
            $pdo = new PDO($dsn, 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $dbConnected = true;
        } catch (PDOException $e) {
            $dbError = $e->getMessage();
        }
        ?>
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 mb-4">
            <h2 class="text-xl font-semibold text-emerald-400 mb-3">2. Connexion à la base de données</h2>
            <div class="text-sm">
                <p class="mb-2"><strong>Base de données :</strong> <?php echo $dbName; ?></p>
                <p class="mb-2"><strong>Connexion :</strong>
                    <?php if ($dbConnected): ?>
                        <span class="text-emerald-400">✅ Réussie</span>
                    <?php else: ?>
                        <span class="text-red-400">❌ Échec</span>
                    <?php endif; ?>
                </p>
                <?php if (!$dbConnected): ?>
                    <div class="bg-red-500/10 border border-red-500/50 rounded p-3 mt-2">
                        <p class="text-red-400"><strong>Erreur :</strong> <?php echo htmlspecialchars($dbError); ?></p>
                        <p class="mt-2 text-yellow-400">⚠️ Vous devez créer la base de données et importer le fichier schema.sql</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($dbConnected): ?>
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 mb-4">
            <h2 class="text-xl font-semibold text-emerald-400 mb-3">3. Tables de la base de données</h2>
            <div class="text-sm">
                <?php
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                ?>
                <p class="mb-2"><strong>Nombre de tables :</strong> <?php echo count($tables); ?></p>
                <div class="bg-slate-900 p-3 rounded overflow-auto">
                    <ul class="text-xs space-y-1">
                        <?php foreach ($tables as $table): ?>
                            <li>✓ <?php echo htmlspecialchars($table); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 mb-4">
            <h2 class="text-xl font-semibold text-emerald-400 mb-3">4. Utilisateurs dans la base</h2>
            <div class="text-sm">
                <?php
                $users = $pdo->query("SELECT id_utilisateur, nom_utilisateur, email, role, actif FROM utilisateurs")->fetchAll();
                ?>
                <p class="mb-2"><strong>Nombre d'utilisateurs :</strong> <?php echo count($users); ?></p>
                <?php if (count($users) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-700/50">
                                <tr>
                                    <th class="p-2 text-left">ID</th>
                                    <th class="p-2 text-left">Nom</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Rôle</th>
                                    <th class="p-2 text-left">Actif</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr class="border-t border-slate-700">
                                        <td class="p-2"><?php echo $user['id_utilisateur']; ?></td>
                                        <td class="p-2"><?php echo htmlspecialchars($user['nom_utilisateur']); ?></td>
                                        <td class="p-2"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="p-2"><?php echo htmlspecialchars($user['role']); ?></td>
                                        <td class="p-2"><?php echo $user['actif'] ? '✅' : '❌'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 mb-4">
            <h2 class="text-xl font-semibold text-emerald-400 mb-3">5. Configuration PHP</h2>
            <div class="text-sm space-y-2">
                <p><strong>Version PHP :</strong> <?php echo phpversion(); ?></p>
                <p><strong>Display Errors :</strong> <?php echo ini_get('display_errors') ? '✅ Activé' : '❌ Désactivé'; ?></p>
                <p><strong>Max Execution Time :</strong> <?php echo ini_get('max_execution_time'); ?>s</p>
                <p><strong>Memory Limit :</strong> <?php echo ini_get('memory_limit'); ?></p>
                <p><strong>Upload Max Filesize :</strong> <?php echo ini_get('upload_max_filesize'); ?></p>
            </div>
        </div>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 mb-4">
            <h2 class="text-xl font-semibold text-emerald-400 mb-3">6. Actions</h2>
            <div class="space-y-2">
                <a href="clear_session.php" class="inline-block bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded text-sm transition">
                    Nettoyer la session
                </a>
                <a href="pages/auth/login.php" class="inline-block bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded text-sm transition">
                    Aller à la page de connexion
                </a>
                <a href="index.php" class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm transition">
                    Retour à l'accueil
                </a>
            </div>
        </div>

        <?php if (!$dbConnected): ?>
        <div class="bg-red-500/10 border border-red-500/50 rounded-lg p-6">
            <h2 class="text-xl font-semibold text-red-400 mb-3">⚠️ Instructions d'installation</h2>
            <div class="text-sm space-y-2">
                <p>1. Ouvrir phpMyAdmin : <a href="http://localhost/phpmyadmin" target="_blank" class="text-blue-400 underline">http://localhost/phpmyadmin</a></p>
                <p>2. Cliquer sur "Nouvelle base de données"</p>
                <p>3. Nom : <code class="bg-slate-900 px-2 py-1 rounded">comptabilite_syscohada</code></p>
                <p>4. Interclassement : <code class="bg-slate-900 px-2 py-1 rounded">utf8mb4_unicode_ci</code></p>
                <p>5. Cliquer sur "Créer"</p>
                <p>6. Cliquer sur "Importer"</p>
                <p>7. Sélectionner le fichier : <code class="bg-slate-900 px-2 py-1 rounded">database/schema.sql</code></p>
                <p>8. Cliquer sur "Exécuter"</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
