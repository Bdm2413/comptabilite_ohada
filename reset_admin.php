<?php
/**
 * Script de réinitialisation du mot de passe admin
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation Admin - ComptaSYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-8">
    <div class="max-w-2xl mx-auto">
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6">
            <h1 class="text-2xl font-bold text-white mb-6">Réinitialisation du mot de passe Admin</h1>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
                try {
                    // Connexion à la base de données
                    $pdo = new PDO("mysql:host=localhost;dbname=comptabilite_syscohada;charset=utf8mb4", 'root', '', [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);

                    // Nouveau mot de passe
                    $newPassword = 'admin123';
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                    // Mise à jour du mot de passe
                    $stmt = $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = :password WHERE email = 'admin@comptabilite.local'");
                    $stmt->execute(['password' => $hashedPassword]);

                    if ($stmt->rowCount() > 0) {
                        echo '<div class="bg-emerald-500/10 border border-emerald-500/50 rounded p-4 mb-4">';
                        echo '<p class="text-emerald-400 font-semibold mb-2">✅ Mot de passe réinitialisé avec succès !</p>';
                        echo '<div class="bg-slate-900 p-3 rounded mt-3 text-sm">';
                        echo '<p class="text-white"><strong>Identifiants de connexion :</strong></p>';
                        echo '<p class="text-slate-300 mt-2">Email : <code class="text-emerald-400 bg-slate-700 px-2 py-1 rounded">admin@comptabilite.local</code></p>';
                        echo '<p class="text-slate-300 mt-1">Mot de passe : <code class="text-emerald-400 bg-slate-700 px-2 py-1 rounded">admin123</code></p>';
                        echo '</div>';
                        echo '<div class="mt-4 space-x-2">';
                        echo '<a href="clear_session.php" class="inline-block bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded text-sm transition">Nettoyer la session</a>';
                        echo '<a href="pages/auth/login.php" class="inline-block bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded text-sm transition">Aller à la connexion</a>';
                        echo '</div>';
                        echo '</div>';
                    } else {
                        echo '<div class="bg-red-500/10 border border-red-500/50 rounded p-4 mb-4">';
                        echo '<p class="text-red-400">❌ Aucun utilisateur trouvé avec cet email</p>';
                        echo '</div>';
                    }

                    // Afficher tous les utilisateurs
                    echo '<div class="bg-slate-700/30 p-4 rounded mt-4">';
                    echo '<h3 class="font-semibold text-white mb-3">Utilisateurs dans la base :</h3>';
                    $users = $pdo->query("SELECT id_utilisateur, nom_utilisateur, email, role, actif FROM utilisateurs")->fetchAll();

                    if (count($users) > 0) {
                        echo '<div class="overflow-x-auto">';
                        echo '<table class="w-full text-sm">';
                        echo '<thead class="bg-slate-700/50">';
                        echo '<tr><th class="p-2 text-left">ID</th><th class="p-2 text-left">Nom</th><th class="p-2 text-left">Email</th><th class="p-2 text-left">Rôle</th><th class="p-2 text-left">Actif</th></tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        foreach ($users as $user) {
                            echo '<tr class="border-t border-slate-700">';
                            echo '<td class="p-2">' . $user['id_utilisateur'] . '</td>';
                            echo '<td class="p-2">' . htmlspecialchars($user['nom_utilisateur']) . '</td>';
                            echo '<td class="p-2">' . htmlspecialchars($user['email']) . '</td>';
                            echo '<td class="p-2">' . htmlspecialchars($user['role']) . '</td>';
                            echo '<td class="p-2">' . ($user['actif'] ? '✅' : '❌') . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody>';
                        echo '</table>';
                        echo '</div>';
                    } else {
                        echo '<p class="text-slate-400">Aucun utilisateur trouvé</p>';
                    }
                    echo '</div>';

                } catch (PDOException $e) {
                    echo '<div class="bg-red-500/10 border border-red-500/50 rounded p-4 mb-4">';
                    echo '<p class="text-red-400 font-semibold">❌ Erreur de connexion</p>';
                    echo '<p class="text-red-300 text-sm mt-2">' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div>';
                }

            } else {
                // Formulaire de réinitialisation
                ?>
                <p class="text-slate-300 mb-4">
                    Ce script va réinitialiser le mot de passe de l'utilisateur admin à <code class="bg-slate-700 px-2 py-1 rounded text-emerald-400">admin123</code>
                </p>

                <div class="bg-amber-500/10 border border-amber-500/50 rounded p-4 mb-6">
                    <p class="text-amber-400 text-sm">
                        ⚠️ <strong>Attention :</strong> Cette opération va modifier le mot de passe de l'utilisateur admin.
                    </p>
                </div>

                <form method="POST" class="space-y-4">
                    <button
                        type="submit"
                        name="reset"
                        class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-3 px-4 rounded transition"
                    >
                        🔄 Réinitialiser le mot de passe admin
                    </button>
                </form>

                <div class="mt-6 text-center space-x-4">
                    <a href="diagnostic.php" class="text-blue-400 hover:underline text-sm">Diagnostic</a>
                    <a href="install.php" class="text-blue-400 hover:underline text-sm">Installation</a>
                    <a href="index.php" class="text-blue-400 hover:underline text-sm">Accueil</a>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</body>
</html>
