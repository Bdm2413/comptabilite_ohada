<?php
require_once '../../config/config.php';

// Si déjà connecté, rediriger vers le dashboard
if (isLoggedIn()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE email = :email AND actif = 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['mot_de_passe'])) {
                // Connexion réussie
                $_SESSION['user_id'] = $user['id_utilisateur'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['nom_utilisateur'];
                $_SESSION['user_role'] = $user['role'];

                // Charger la société par défaut de l'utilisateur
                $societes = getUserSocietes($user['id_utilisateur']);
                if (!empty($societes)) {
                    // Prendre la société par défaut ou la première disponible
                    $societe_defaut = null;
                    foreach ($societes as $soc) {
                        if ($soc['par_defaut']) {
                            $societe_defaut = $soc;
                            break;
                        }
                    }
                    if (!$societe_defaut) {
                        $societe_defaut = $societes[0];
                    }
                    $_SESSION['societe_id'] = $societe_defaut['id'];
                }

                // Mettre à jour la dernière connexion
                $updateStmt = $db->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id_utilisateur = :id");
                $updateStmt->execute(['id' => $user['id_utilisateur']]);

                // Enregistrer l'activité
                logActivity('Connexion', 'utilisateurs', $user['id_utilisateur']);

                header('Location: ../dashboard/index.php');
                exit();
            } else {
                $error = 'Email ou mot de passe incorrect';
                logActivity('Tentative de connexion échouée', null, null, $email);
            }
        } catch (Exception $e) {
            $error = 'Erreur de connexion. Veuillez réessayer.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 min-h-screen flex items-center justify-center p-4">

    <div id="login-container" class="w-full max-w-md opacity-0">
        <!-- Logo et Titre -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl mb-3 shadow-lg">
                <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-1"><?php echo APP_NAME; ?></h1>
            <p class="text-sm text-slate-400">Système de comptabilité SYSCOHADA Révisé</p>
        </div>

        <!-- Carte de connexion -->
        <div class="bg-slate-800/50 backdrop-blur-sm rounded-2xl shadow-2xl border border-slate-700/50 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Connexion</h2>

            <?php if ($error): ?>
                <div class="bg-red-500/10 border border-red-500/50 text-red-400 px-3 py-2 rounded-lg mb-4 text-sm">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="login-form">
                <!-- Email -->
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent text-sm transition"
                        placeholder="votre@email.com"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    >
                </div>

                <!-- Mot de passe -->
                <div class="mb-5">
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-1.5">Mot de passe</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent text-sm transition"
                        placeholder="••••••••"
                    >
                </div>

                <!-- Bouton de connexion -->
                <button
                    type="submit"
                    class="w-full bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-medium py-2.5 px-4 rounded-lg transition duration-200 shadow-lg hover:shadow-emerald-500/50 text-sm"
                >
                    Se connecter
                </button>
            </form>
        </div>

        <!-- Informations de test -->
        <div class="mt-4 text-center">
            <p class="text-xs text-slate-500">
                Version <?php echo APP_VERSION; ?> - Test: admin@comptabilite.local / admin123
            </p>
        </div>
    </div>

    <script>
        // Animation d'entrée
        anime({
            targets: '#login-container',
            opacity: [0, 1],
            translateY: [30, 0],
            duration: 800,
            easing: 'easeOutQuad'
        });

        // Animation au focus des inputs
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('focus', function() {
                anime({
                    targets: this,
                    scale: [1, 1.02],
                    duration: 200,
                    easing: 'easeOutQuad'
                });
            });

            input.addEventListener('blur', function() {
                anime({
                    targets: this,
                    scale: [1.02, 1],
                    duration: 200,
                    easing: 'easeOutQuad'
                });
            });
        });

        // Animation du bouton au survol
        const submitBtn = document.querySelector('button[type="submit"]');
        submitBtn.addEventListener('mouseenter', function() {
            anime({
                targets: this,
                scale: 1.02,
                duration: 200,
                easing: 'easeOutQuad'
            });
        });

        submitBtn.addEventListener('mouseleave', function() {
            anime({
                targets: this,
                scale: 1,
                duration: 200,
                easing: 'easeOutQuad'
            });
        });
    </script>
</body>
</html>
