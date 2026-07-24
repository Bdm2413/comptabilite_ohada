<?php
require_once '../../config/config.php';
requireLogin();

require_once '../../vendor/autoload.php';

$db      = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id_utilisateur = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch();

$tfa     = new \RobThree\Auth\TwoFactorAuth(new \RobThree\Auth\Providers\Qr\QRServerProvider(false));
$success = '';
$error   = '';

// Désactiver le TOTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable') {
    $code = preg_replace('/\s+/', '', $_POST['confirm_code'] ?? '');
    if ($tfa->verifyCode($user['totp_secret'], $code)) {
        $db->prepare("UPDATE utilisateurs SET totp_enabled = 0, totp_secret = NULL WHERE id_utilisateur = :id")
           ->execute(['id' => $user_id]);
        $success = 'L\'authentificateur a été désactivé.';
        $stmt->execute(['id' => $user_id]);
        $user = $stmt->fetch();
    } else {
        $error = 'Code incorrect. Désactivation annulée.';
    }
}

// Activer le TOTP (confirmer avec le code)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable') {
    $secret = $_POST['secret'] ?? '';
    $code   = preg_replace('/\s+/', '', $_POST['totp_code'] ?? '');

    if (empty($secret) || empty($code)) {
        $error = 'Données manquantes.';
    } elseif ($tfa->verifyCode($secret, $code)) {
        $db->prepare("UPDATE utilisateurs SET totp_secret = :secret, totp_enabled = 1 WHERE id_utilisateur = :id")
           ->execute(['secret' => $secret, 'id' => $user_id]);
        $success = 'Authentificateur activé avec succès ! Votre compte est maintenant protégé en 2 étapes.';
        $stmt->execute(['id' => $user_id]);
        $user = $stmt->fetch();
    } else {
        $error = 'Code invalide. Vérifiez que votre application est bien synchronisée et réessayez.';
    }
}

// Générer un nouveau secret si l'utilisateur n'en a pas encore
$new_secret = '';
$qr_url     = '';
if (!$user['totp_enabled']) {
    // Garder le même secret pendant la session de configuration
    if (empty($_SESSION['totp_setup_secret'])) {
        $_SESSION['totp_setup_secret'] = $tfa->createSecret();
    }
    $new_secret = $_SESSION['totp_setup_secret'];
    $label      = APP_NAME . ' (' . $user['email'] . ')';
    $qr_url     = $tfa->getQRCodeImageAsDataUri($label, $new_secret, 200);
}

if ($user['totp_enabled']) {
    unset($_SESSION['totp_setup_secret']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sécurité — <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
    <?php include '../../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <header class="bg-slate-800/30 border-b border-slate-700/50 p-4 sticky top-0 z-10 backdrop-blur">
            <div class="flex items-center gap-3">
                <a href="index.php" class="text-slate-400 hover:text-white transition">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-lg font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-500">
                        <i class="fas fa-shield-alt mr-2"></i>Sécurité — Authentification 2 facteurs
                    </h1>
                    <p class="text-slate-400 text-xs mt-0.5">Protégez votre compte avec Google Authenticator ou Authy</p>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-2xl mx-auto space-y-6">

            <?php if ($success): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/50 text-emerald-400 px-4 py-3 rounded-xl flex items-center gap-3">
                <i class="fas fa-check-circle text-lg"></i>
                <span class="text-sm"><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/50 text-red-400 px-4 py-3 rounded-xl flex items-center gap-3">
                <i class="fas fa-exclamation-circle text-lg"></i>
                <span class="text-sm"><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <!-- Statut actuel -->
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center <?php echo $user['totp_enabled'] ? 'bg-emerald-500/20' : 'bg-slate-700/50'; ?>">
                        <i class="fas fa-<?php echo $user['totp_enabled'] ? 'shield-alt text-emerald-400' : 'shield text-slate-400'; ?> text-xl"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-white text-sm">Authentification à deux facteurs</p>
                        <p class="text-xs mt-0.5 <?php echo $user['totp_enabled'] ? 'text-emerald-400' : 'text-slate-400'; ?>">
                            <?php echo $user['totp_enabled'] ? '✓ Activée — votre compte est protégé' : 'Non activée'; ?>
                        </p>
                    </div>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $user['totp_enabled'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400'; ?>">
                    <?php echo $user['totp_enabled'] ? 'ACTIF' : 'INACTIF'; ?>
                </span>
            </div>

            <?php if (!$user['totp_enabled']): ?>
            <!-- ACTIVATION -->
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6">
                <h2 class="font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-mobile-alt text-blue-400"></i>
                    Activer l'authentificateur
                </h2>

                <!-- Étapes -->
                <div class="space-y-5">
                    <!-- Étape 1 -->
                    <div class="flex gap-4">
                        <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0 text-xs font-bold">1</div>
                        <div>
                            <p class="text-sm font-medium text-white mb-1">Téléchargez une application d'authentification</p>
                            <div class="flex gap-3 flex-wrap">
                                <span class="px-3 py-1.5 bg-slate-700 rounded-lg text-xs flex items-center gap-1.5 text-slate-300">
                                    <i class="fab fa-google text-red-400"></i> Google Authenticator
                                </span>
                                <span class="px-3 py-1.5 bg-slate-700 rounded-lg text-xs flex items-center gap-1.5 text-slate-300">
                                    <i class="fas fa-key text-blue-400"></i> Authy
                                </span>
                                <span class="px-3 py-1.5 bg-slate-700 rounded-lg text-xs flex items-center gap-1.5 text-slate-300">
                                    <i class="fab fa-microsoft text-blue-300"></i> Microsoft Authenticator
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Étape 2 -->
                    <div class="flex gap-4">
                        <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0 text-xs font-bold">2</div>
                        <div>
                            <p class="text-sm font-medium text-white mb-3">Scannez ce QR code avec votre application</p>
                            <div class="inline-block bg-white p-3 rounded-xl shadow-lg">
                                <img src="<?php echo $qr_url; ?>" alt="QR Code TOTP" class="w-44 h-44">
                            </div>
                            <p class="text-slate-400 text-xs mt-2">Vous ne pouvez pas scanner ? Entrez manuellement ce code :</p>
                            <div class="mt-1.5 flex items-center gap-2">
                                <code class="bg-slate-900 text-cyan-400 px-3 py-1.5 rounded-lg text-xs font-mono tracking-widest select-all" id="secretCode">
                                    <?php echo wordwrap($new_secret, 4, ' ', true); ?>
                                </code>
                                <button onclick="copySecret()" class="text-slate-400 hover:text-white transition p-1.5" title="Copier">
                                    <i class="fas fa-copy text-xs"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Étape 3 -->
                    <div class="flex gap-4">
                        <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0 text-xs font-bold">3</div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-white mb-3">Confirmez avec le code affiché dans l'application</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="secret" value="<?php echo htmlspecialchars($new_secret); ?>">
                                <div class="flex gap-3">
                                    <input type="text" name="totp_code" required
                                           placeholder="000 000" maxlength="7"
                                           inputmode="numeric" autocomplete="one-time-code"
                                           class="flex-1 px-4 py-2.5 bg-slate-900 border border-slate-600 rounded-lg text-white text-center text-xl font-mono tracking-widest focus:outline-none focus:border-blue-500"
                                           id="confirmCode">
                                    <button type="submit"
                                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                                        <i class="fas fa-check"></i> Activer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- DÉSACTIVATION -->
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6">
                <h2 class="font-semibold text-white mb-2 flex items-center gap-2">
                    <i class="fas fa-toggle-off text-red-400"></i>
                    Désactiver l'authentificateur
                </h2>
                <p class="text-slate-400 text-sm mb-5">
                    Pour désactiver la double authentification, saisissez un code valide de votre application pour confirmer.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="disable">
                    <div class="flex gap-3">
                        <input type="text" name="confirm_code" required
                               placeholder="000 000" maxlength="7"
                               inputmode="numeric" autocomplete="one-time-code"
                               class="flex-1 px-4 py-2.5 bg-slate-900 border border-slate-600 rounded-lg text-white text-center text-xl font-mono tracking-widest focus:outline-none focus:border-red-500">
                        <button type="submit"
                                onclick="return confirm('Êtes-vous sûr de vouloir désactiver la 2FA ?')"
                                class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                            <i class="fas fa-times"></i> Désactiver
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Info sécurité -->
            <div class="bg-blue-500/5 border border-blue-500/20 rounded-xl p-4">
                <div class="flex gap-3">
                    <i class="fas fa-info-circle text-blue-400 mt-0.5 flex-shrink-0"></i>
                    <div class="text-xs text-slate-400 space-y-1">
                        <p>L'authentification à deux facteurs ajoute une couche de sécurité supplémentaire. Même si votre mot de passe est compromis, personne ne pourra se connecter sans votre téléphone.</p>
                        <p class="text-slate-500">Conservez un accès à votre application d'authentification. En cas de perte, contactez votre administrateur.</p>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
    function copySecret() {
        const code = document.getElementById('secretCode').textContent.replace(/\s/g, '');
        navigator.clipboard.writeText(code).then(() => {
            const btn = event.currentTarget;
            btn.innerHTML = '<i class="fas fa-check text-xs text-emerald-400"></i>';
            setTimeout(() => btn.innerHTML = '<i class="fas fa-copy text-xs"></i>', 2000);
        });
    }

    // Auto-format codes TOTP
    document.querySelectorAll('input[name="totp_code"], input[name="confirm_code"]').forEach(input => {
        input.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '').substring(0, 6);
            this.value = v.length > 3 ? v.slice(0,3) + ' ' + v.slice(3) : v;
        });
    });
</script>
</body>
</html>
