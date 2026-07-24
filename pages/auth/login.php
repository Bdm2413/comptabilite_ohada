<?php
require_once '../../config/config.php';

if (isLoggedIn()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';
$active_tab = $_GET['tab'] ?? 'password';

// Connexion par mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'password') {
    $email    = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
        $active_tab = 'password';
    } else {
        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE email = :email AND actif = 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['mot_de_passe'])) {
                if ($user['totp_enabled']) {
                    // TOTP activé → étape 2
                    $_SESSION['totp_pending_user_id'] = $user['id_utilisateur'];
                    header('Location: totp_verify.php');
                    exit();
                }
                // Connexion directe
                $_SESSION['user_id']    = $user['id_utilisateur'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name']  = $user['nom_utilisateur'];
                $_SESSION['user_role']  = $user['role'];

                $societes = getUserSocietes($user['id_utilisateur']);
                if (!empty($societes)) {
                    $societe_defaut = null;
                    foreach ($societes as $soc) {
                        if ($soc['par_defaut']) { $societe_defaut = $soc; break; }
                    }
                    $_SESSION['societe_id'] = ($societe_defaut ?? $societes[0])['id'];
                }

                $db->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id_utilisateur = :id")
                   ->execute(['id' => $user['id_utilisateur']]);
                logActivity('Connexion', 'utilisateurs', $user['id_utilisateur']);

                header('Location: ../dashboard/index.php');
                exit();
            } else {
                $error = 'Email ou mot de passe incorrect.';
                logActivity('Tentative de connexion échouée', null, null, $email);
                $active_tab = 'password';
            }
        } catch (Exception $e) {
            $error = 'Erreur de connexion. Veuillez réessayer.';
            $active_tab = 'password';
        }
    }
}

// Connexion directe par TOTP (sans mot de passe)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'totp_direct') {
    $email = cleanInput($_POST['email'] ?? '');
    $code  = preg_replace('/\s+/', '', $_POST['totp_code'] ?? '');

    if (empty($email) || empty($code)) {
        $error = 'Veuillez remplir tous les champs.';
        $active_tab = 'totp';
    } else {
        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE email = :email AND actif = 1 AND totp_enabled = 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                require_once '../../vendor/autoload.php';
                $tfa    = new \RobThree\Auth\TwoFactorAuth(new \RobThree\Auth\Providers\Qr\QRServerProvider());
                $valid  = $tfa->verifyCode($user['totp_secret'], $code);

                if ($valid) {
                    $_SESSION['user_id']    = $user['id_utilisateur'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name']  = $user['nom_utilisateur'];
                    $_SESSION['user_role']  = $user['role'];

                    $societes = getUserSocietes($user['id_utilisateur']);
                    if (!empty($societes)) {
                        $societe_defaut = null;
                        foreach ($societes as $soc) {
                            if ($soc['par_defaut']) { $societe_defaut = $soc; break; }
                        }
                        $_SESSION['societe_id'] = ($societe_defaut ?? $societes[0])['id'];
                    }

                    $db->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id_utilisateur = :id")
                       ->execute(['id' => $user['id_utilisateur']]);
                    logActivity('Connexion TOTP', 'utilisateurs', $user['id_utilisateur']);

                    header('Location: ../dashboard/index.php');
                    exit();
                } else {
                    $error = 'Code invalide ou expiré. Vérifiez votre application d\'authentification.';
                }
            } else {
                $error = 'Aucun compte avec cet email ou l\'authentificateur n\'est pas activé.';
            }
            $active_tab = 'totp';
        } catch (Exception $e) {
            $error = 'Erreur de connexion. Veuillez réessayer.';
            $active_tab = 'totp';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f2557 0%, #1a3a8f 40%, #1565c0 70%, #0d47a1 100%);
            min-height: 100vh;
        }
        .card { background: rgba(255,255,255,0.97); }
        .tab-active {
            color: #1565c0;
            border-bottom: 2px solid #1565c0;
        }
        .tab-inactive {
            color: #6b7280;
            border-bottom: 2px solid transparent;
        }
        .tab-inactive:hover { color: #374151; }
        .input-field {
            width: 100%;
            padding: 0.6rem 0.9rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            color: #111827;
        }
        .input-field:focus {
            border-color: #1565c0;
            box-shadow: 0 0 0 3px rgba(21,101,192,.15);
        }
        .btn-primary {
            width: 100%;
            background: #1565c0;
            color: #fff;
            padding: 0.65rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background .2s, transform .1s;
            cursor: pointer;
            border: none;
        }
        .btn-primary:hover  { background: #0d47a1; }
        .btn-primary:active { transform: scale(.98); }
        .code-input {
            letter-spacing: 0.4em;
            font-size: 1.5rem;
            text-align: center;
            font-family: monospace;
        }
        @keyframes fadeUp {
            from { opacity:0; transform:translateY(24px); }
            to   { opacity:1; transform:translateY(0);    }
        }
        .fade-up { animation: fadeUp .45s ease both; }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <!-- Header -->
    <header class="flex items-center justify-between px-8 py-4">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white rounded-lg flex items-center justify-center shadow">
                <i class="fas fa-calculator text-blue-700 text-lg"></i>
            </div>
            <span class="text-white font-bold text-lg tracking-wide"><?php echo APP_NAME; ?></span>
        </div>
        <span class="text-blue-200 text-xs">SYSCOHADA Révisé <?php echo APP_VERSION; ?></span>
    </header>

    <!-- Main -->
    <main class="flex-1 flex items-center justify-center px-4 py-8">
        <div class="fade-up w-full max-w-sm">

            <h1 class="text-white text-2xl font-bold text-center mb-6">Bienvenue sur <?php echo APP_NAME; ?></h1>

            <!-- Carte -->
            <div class="card rounded-xl shadow-2xl overflow-hidden">

                <!-- Onglets -->
                <div class="flex border-b border-gray-200">
                    <button onclick="switchTab('password')"
                            id="tab-password"
                            class="flex-1 flex items-center justify-center gap-1.5 py-3.5 text-xs font-semibold transition <?php echo $active_tab === 'password' ? 'tab-active' : 'tab-inactive'; ?>">
                        <i class="fas fa-lock text-xs"></i>
                        Mot de passe
                    </button>
                    <button onclick="switchTab('totp')"
                            id="tab-totp"
                            class="flex-1 flex items-center justify-center gap-1.5 py-3.5 text-xs font-semibold transition <?php echo $active_tab === 'totp' ? 'tab-active' : 'tab-inactive'; ?>">
                        <i class="fas fa-mobile-alt text-xs"></i>
                        Authentificateur
                    </button>
                </div>

                <!-- Message d'erreur -->
                <?php if ($error): ?>
                <div class="mx-5 mt-4 bg-red-50 border border-red-300 text-red-700 px-3 py-2.5 rounded-lg text-xs flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Panneau Mot de passe -->
                <div id="panel-password" class="p-6 <?php echo $active_tab !== 'password' ? 'hidden' : ''; ?>">
                    <p class="text-gray-500 text-xs mb-5">Connectez-vous avec votre adresse email et mot de passe.</p>
                    <form method="POST">
                        <input type="hidden" name="mode" value="password">
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Adresse email</label>
                            <input type="email" name="email" required placeholder="votre@email.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   class="input-field">
                        </div>
                        <div class="mb-5">
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Mot de passe</label>
                            <div class="relative">
                                <input type="password" name="password" id="pwd-input" required placeholder="••••••••"
                                       class="input-field pr-10">
                                <button type="button" onclick="togglePwd()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye text-xs" id="pwd-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-sign-in-alt mr-2"></i>Se connecter
                        </button>
                    </form>
                </div>

                <!-- Panneau Authentificateur TOTP -->
                <div id="panel-totp" class="p-6 <?php echo $active_tab !== 'totp' ? 'hidden' : ''; ?>">
                    <p class="text-gray-500 text-xs mb-5">
                        Saisissez votre email et le code à 6 chiffres affiché dans votre application
                        <span class="font-semibold text-gray-700">Google Authenticator</span> ou <span class="font-semibold text-gray-700">Authy</span>.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="mode" value="totp_direct">
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Adresse email</label>
                            <input type="email" name="email" required placeholder="votre@email.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   class="input-field">
                        </div>
                        <div class="mb-5">
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Code à 6 chiffres</label>
                            <input type="text" name="totp_code" required placeholder="000 000"
                                   maxlength="7" inputmode="numeric" autocomplete="one-time-code"
                                   class="input-field code-input">
                            <p class="text-gray-400 text-xs mt-1.5 text-center">Le code se renouvelle toutes les 30 secondes</p>
                        </div>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-shield-alt mr-2"></i>Vérifier le code
                        </button>
                    </form>

                    <div class="mt-4 pt-4 border-t border-gray-100 text-center">
                        <a href="?tab=password" class="text-xs text-blue-600 hover:text-blue-800 transition">
                            <i class="fas fa-arrow-left mr-1"></i>Revenir à la connexion par mot de passe
                        </a>
                    </div>
                </div>

            </div><!-- /card -->

            <!-- Lien activation TOTP -->
            <div class="mt-4 text-center">
                <p class="text-blue-200 text-xs">
                    <i class="fas fa-info-circle mr-1"></i>
                    Pour activer l'authentificateur, connectez-vous d'abord puis allez dans
                    <strong>Paramètres → Sécurité</strong>.
                </p>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="text-center pb-5">
        <p class="text-blue-300 text-xs">
            &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> &mdash; Système de comptabilité OHADA
        </p>
    </footer>

    <script>
        function switchTab(tab) {
            const tabs   = ['password', 'totp'];
            tabs.forEach(t => {
                document.getElementById('tab-' + t).className =
                    'flex-1 flex items-center justify-center gap-1.5 py-3.5 text-xs font-semibold transition ' +
                    (t === tab ? 'tab-active' : 'tab-inactive');
                const panel = document.getElementById('panel-' + t);
                panel.classList.toggle('hidden', t !== tab);
            });
        }

        function togglePwd() {
            const input = document.getElementById('pwd-input');
            const eye   = document.getElementById('pwd-eye');
            if (input.type === 'password') {
                input.type = 'text';
                eye.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                eye.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // Auto-format code TOTP (espace après 3 chiffres)
        const codeInput = document.querySelector('input[name="totp_code"]');
        if (codeInput) {
            codeInput.addEventListener('input', function() {
                let v = this.value.replace(/\D/g, '').substring(0, 6);
                this.value = v.length > 3 ? v.slice(0,3) + ' ' + v.slice(3) : v;
            });
        }
    </script>
</body>
</html>
