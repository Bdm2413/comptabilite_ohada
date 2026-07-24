<?php
require_once '../../config/config.php';

// Doit avoir passé l'étape 1 (mot de passe)
if (empty($_SESSION['totp_pending_user_id'])) {
    header('Location: login.php');
    exit();
}

if (isLoggedIn()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\s+/', '', $_POST['totp_code'] ?? '');

    if (empty($code)) {
        $error = 'Veuillez saisir le code.';
    } else {
        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id_utilisateur = :id AND actif = 1");
            $stmt->execute(['id' => $_SESSION['totp_pending_user_id']]);
            $user = $stmt->fetch();

            if ($user && $user['totp_enabled'] && $user['totp_secret']) {
                require_once '../../vendor/autoload.php';
                $tfa   = new \RobThree\Auth\TwoFactorAuth(new \RobThree\Auth\Providers\Qr\EndroidQrCodeProvider());
                $valid = $tfa->verifyCode($user['totp_secret'], $code);

                if ($valid) {
                    unset($_SESSION['totp_pending_user_id']);

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
                    logActivity('Connexion 2FA', 'utilisateurs', $user['id_utilisateur']);

                    header('Location: ../dashboard/index.php');
                    exit();
                } else {
                    $error = 'Code invalide ou expiré. Vérifiez votre application.';
                }
            } else {
                $error = 'Erreur de session. Veuillez recommencer.';
            }
        } catch (Exception $e) {
            $error = 'Erreur technique. Veuillez réessayer.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification — <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #0f2557 0%, #1a3a8f 40%, #1565c0 70%, #0d47a1 100%); min-height: 100vh; }
        .card { background: rgba(255,255,255,0.97); }
        .input-field { width:100%; padding:.6rem .9rem; border:1px solid #d1d5db; border-radius:6px; font-size:.875rem; outline:none; transition:border-color .2s,box-shadow .2s; color:#111827; }
        .input-field:focus { border-color:#1565c0; box-shadow:0 0 0 3px rgba(21,101,192,.15); }
        .btn-primary { width:100%; background:#1565c0; color:#fff; padding:.65rem 1rem; border-radius:6px; font-size:.875rem; font-weight:600; transition:background .2s; cursor:pointer; border:none; }
        .btn-primary:hover { background:#0d47a1; }
        .code-input { letter-spacing:.4em; font-size:1.75rem; text-align:center; font-family:monospace; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
        .fade-up { animation: fadeUp .45s ease both; }

        /* Timer circulaire */
        .timer-ring { transform: rotate(-90deg); }
        .timer-circle { transition: stroke-dashoffset 1s linear; }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <header class="flex items-center justify-between px-8 py-4">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white rounded-lg flex items-center justify-center shadow">
                <i class="fas fa-calculator text-blue-700 text-lg"></i>
            </div>
            <span class="text-white font-bold text-lg tracking-wide"><?php echo APP_NAME; ?></span>
        </div>
    </header>

    <main class="flex-1 flex items-center justify-center px-4 py-8">
        <div class="fade-up w-full max-w-sm">

            <h1 class="text-white text-2xl font-bold text-center mb-2">Vérification en 2 étapes</h1>
            <p class="text-blue-200 text-sm text-center mb-6">Étape 2 sur 2 — Code d'authentification</p>

            <div class="card rounded-xl shadow-2xl p-6">

                <!-- Icône + timer -->
                <div class="flex flex-col items-center mb-6">
                    <div class="relative w-20 h-20">
                        <svg class="w-20 h-20 timer-ring" viewBox="0 0 80 80">
                            <circle cx="40" cy="40" r="34" fill="none" stroke="#e5e7eb" stroke-width="5"/>
                            <circle id="timerCircle" cx="40" cy="40" r="34" fill="none" stroke="#1565c0"
                                    stroke-width="5" stroke-linecap="round"
                                    stroke-dasharray="213.6" stroke-dashoffset="0" class="timer-circle"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <i class="fas fa-shield-alt text-blue-700 text-xl mb-0.5"></i>
                            <span id="timerCount" class="text-blue-800 text-xs font-bold">30</span>
                        </div>
                    </div>
                    <p class="text-gray-500 text-xs mt-2">Le code change dans <span id="timerLabel" class="font-semibold text-blue-700">30</span>s</p>
                </div>

                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-300 text-red-700 px-3 py-2.5 rounded-lg text-xs flex items-start gap-2 mb-4">
                    <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" id="totpForm">
                    <div class="mb-5">
                        <label class="block text-xs font-semibold text-gray-600 mb-2 text-center">
                            Code à 6 chiffres
                        </label>
                        <input type="text" name="totp_code" id="totpCode" required
                               placeholder="000 000" maxlength="7"
                               inputmode="numeric" autocomplete="one-time-code"
                               autofocus
                               class="input-field code-input">
                        <p class="text-gray-400 text-xs mt-2 text-center">
                            Ouvrez <strong>Google Authenticator</strong> ou <strong>Authy</strong> sur votre téléphone
                        </p>
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-check-circle mr-2"></i>Confirmer
                    </button>
                </form>

                <div class="mt-5 pt-4 border-t border-gray-100 text-center space-y-2">
                    <a href="login.php" class="text-xs text-gray-500 hover:text-blue-700 transition block">
                        <i class="fas fa-arrow-left mr-1"></i>Recommencer la connexion
                    </a>
                </div>
            </div>
        </div>
    </main>

    <footer class="text-center pb-5">
        <p class="text-blue-300 text-xs">&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?></p>
    </footer>

    <script>
        // Timer TOTP (30 secondes)
        const circumference = 213.6;
        function updateTimer() {
            const now       = Math.floor(Date.now() / 1000);
            const remaining = 30 - (now % 30);
            const offset    = circumference * (1 - remaining / 30);

            document.getElementById('timerCircle').style.strokeDashoffset = offset;
            document.getElementById('timerCount').textContent  = remaining;
            document.getElementById('timerLabel').textContent  = remaining;

            // Passer en rouge les 5 dernières secondes
            const color = remaining <= 5 ? '#dc2626' : '#1565c0';
            document.getElementById('timerCircle').style.stroke = color;
            document.getElementById('timerCount').style.color   = remaining <= 5 ? '#dc2626' : '#1e40af';
        }
        updateTimer();
        setInterval(updateTimer, 1000);

        // Auto-format code TOTP
        const codeInput = document.getElementById('totpCode');
        codeInput.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '').substring(0, 6);
            this.value = v.length > 3 ? v.slice(0,3) + ' ' + v.slice(3) : v;
            // Soumettre automatiquement quand 6 chiffres saisis
            if (v.length === 6) {
                setTimeout(() => document.getElementById('totpForm').submit(), 300);
            }
        });
    </script>
</body>
</html>
