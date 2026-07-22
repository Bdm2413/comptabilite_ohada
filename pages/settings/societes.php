<?php
require_once '../../config/config.php';
requireLogin();
if (!isAdmin()) {
    header('Location: ../../pages/dashboard/index.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add':
                    // Vérifier que le code société n'existe pas déjà
                    $stmt = $db->prepare("SELECT COUNT(*) FROM societes WHERE code_societe = ?");
                    $stmt->execute([strtoupper(cleanInput($_POST['code_societe']))]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Ce code société existe déjà');
                    }

                    $stmt = $db->prepare("
                        INSERT INTO societes (
                            code_societe, raison_sociale, forme_juridique,
                            adresse, ville, pays, telephone, email,
                            numero_rccm, numero_contribuable, devise_principale, actif
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        strtoupper(cleanInput($_POST['code_societe'])),
                        cleanInput($_POST['raison_sociale']),
                        cleanInput($_POST['forme_juridique'] ?? ''),
                        cleanInput($_POST['adresse'] ?? ''),
                        cleanInput($_POST['ville'] ?? ''),
                        cleanInput($_POST['pays'] ?? ''),
                        cleanInput($_POST['telephone'] ?? ''),
                        cleanInput($_POST['email'] ?? ''),
                        cleanInput($_POST['numero_rccm'] ?? ''),
                        cleanInput($_POST['numero_cc'] ?? ''),
                        cleanInput($_POST['code_devise_defaut'] ?? 'XOF')
                    ]);
                    $societe_id = $db->lastInsertId();

                    // Ajouter automatiquement le créateur à cette société
                    $stmt = $db->prepare("
                        INSERT INTO utilisateurs_societes (id_utilisateur, societe_id, role, par_defaut, date_acces_debut, actif)
                        VALUES (?, ?, 'admin', 0, CURDATE(), 1)
                    ");
                    $stmt->execute([$_SESSION['user_id'], $societe_id]);

                    $message = 'Société créée avec succès';
                    $messageType = 'success';
                    logActivity('Création société', 'societes', $societe_id, $_POST['code_societe']);
                    break;

                case 'edit':
                    $stmt = $db->prepare("
                        UPDATE societes SET
                            raison_sociale = ?, forme_juridique = ?,
                            adresse = ?, ville = ?, pays = ?,
                            telephone = ?, email = ?,
                            numero_rccm = ?, numero_contribuable = ?,
                            devise_principale = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        cleanInput($_POST['raison_sociale']),
                        cleanInput($_POST['forme_juridique'] ?? ''),
                        cleanInput($_POST['adresse'] ?? ''),
                        cleanInput($_POST['ville'] ?? ''),
                        cleanInput($_POST['pays'] ?? ''),
                        cleanInput($_POST['telephone'] ?? ''),
                        cleanInput($_POST['email'] ?? ''),
                        cleanInput($_POST['numero_rccm'] ?? ''),
                        cleanInput($_POST['numero_cc'] ?? ''),
                        cleanInput($_POST['code_devise_defaut'] ?? 'XOF'),
                        $_POST['id']
                    ]);
                    $message = 'Société modifiée avec succès';
                    $messageType = 'success';
                    logActivity('Modification société', 'societes', $_POST['id']);
                    break;

                case 'toggle':
                    $stmt = $db->prepare("UPDATE societes SET actif = NOT actif WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $message = 'Statut modifié avec succès';
                    $messageType = 'success';
                    logActivity('Toggle actif société', 'societes', $_POST['id']);
                    break;

                case 'delete':
                    // Vérifier qu'il y a d'autres sociétés actives
                    $stmt = $db->prepare("SELECT COUNT(*) FROM societes WHERE actif = 1 AND id != ?");
                    $stmt->execute([$_POST['id']]);
                    if ($stmt->fetchColumn() == 0) {
                        throw new Exception('Impossible de supprimer la dernière société active');
                    }

                    // Vérifier qu'il n'y a pas d'écritures
                    $stmt = $db->prepare("SELECT COUNT(*) FROM ecritures WHERE societe_id = ?");
                    $stmt->execute([$_POST['id']]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Impossible de supprimer une société avec des écritures');
                    }

                    $stmt = $db->prepare("DELETE FROM societes WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $message = 'Société supprimée avec succès';
                    $messageType = 'success';
                    logActivity('Suppression société', 'societes', $_POST['id']);
                    break;

                case 'add_user':
                    // Ajouter un utilisateur à une société
                    $stmt = $db->prepare("
                        INSERT INTO utilisateurs_societes (id_utilisateur, societe_id, role, par_defaut, date_acces_debut, actif)
                        VALUES (?, ?, ?, ?, CURDATE(), 1)
                        ON DUPLICATE KEY UPDATE role = VALUES(role), actif = 1
                    ");
                    $stmt->execute([
                        $_POST['user_id'],
                        $_POST['societe_id'],
                        $_POST['role_societe'],
                        isset($_POST['par_defaut']) ? 1 : 0
                    ]);
                    $message = 'Utilisateur ajouté à la société';
                    $messageType = 'success';
                    logActivity('Ajout utilisateur à société', 'utilisateurs_societes', $_POST['societe_id']);
                    break;

                case 'remove_user':
                    $stmt = $db->prepare("DELETE FROM utilisateurs_societes WHERE id_utilisateur = ? AND societe_id = ?");
                    $stmt->execute([$_POST['user_id'], $_POST['societe_id']]);
                    $message = 'Utilisateur retiré de la société';
                    $messageType = 'success';
                    logActivity('Retrait utilisateur de société', 'utilisateurs_societes', $_POST['societe_id']);
                    break;
            }
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Récupérer toutes les sociétés
$stmt = $db->query("SELECT * FROM societes ORDER BY code_societe");
$societes = $stmt->fetchAll();

// Récupérer tous les utilisateurs
$stmt = $db->query("SELECT id_utilisateur, nom_utilisateur, email, role_global FROM utilisateurs ORDER BY nom_utilisateur");
$utilisateurs = $stmt->fetchAll();

// Récupérer toutes les devises disponibles
$stmt = $db->query("SELECT code_devise, libelle FROM devises ORDER BY code_devise");
$devises = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Sociétés - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Header -->
            <header class="bg-slate-800/30 border-b border-slate-700/50 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-orange-400 to-red-600 mb-2">
                            <i class="fas fa-building mr-3"></i>Gestion des Sociétés
                        </h1>
                        <p class="text-sm text-slate-400 mt-0.5">Gestion multi-sociétés et affectation des utilisateurs</p>
                    </div>
                    <button onclick="openModal('add')" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded-lg transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Nouvelle société
                    </button>
                </div>
            </header>

            <!-- Content -->
            <div class="p-4">
                <?php if ($message): ?>
                    <div class="mb-4 p-3 rounded-lg <?php echo $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/50 text-emerald-400' : 'bg-red-500/10 border border-red-500/50 text-red-400'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Grille des sociétés -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($societes as $societe): ?>
                        <div class="bg-slate-800/30 border border-slate-700/50 rounded-xl p-4 hover:border-slate-600/50 transition">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded text-xs font-mono font-semibold">
                                            <?php echo htmlspecialchars($societe['code_societe']); ?>
                                        </span>
                                        <?php if ($societe['actif']): ?>
                                            <span class="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 rounded text-[10px]">Actif</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-red-500/10 text-red-400 rounded text-[10px]">Inactif</span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="text-sm font-semibold text-white"><?php echo htmlspecialchars($societe['raison_sociale']); ?></h3>
                                    <?php if ($societe['forme_juridique']): ?>
                                        <p class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($societe['forme_juridique']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="space-y-1.5 mb-3 text-xs">
                                <?php if ($societe['ville'] || $societe['pays']): ?>
                                    <div class="flex items-center gap-2 text-slate-400">
                                        <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <span><?php echo htmlspecialchars($societe['ville'] . ($societe['pays'] ? ', ' . $societe['pays'] : '')); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($societe['email']): ?>
                                    <div class="flex items-center gap-2 text-slate-400">
                                        <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                        </svg>
                                        <span><?php echo htmlspecialchars($societe['email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="flex items-center gap-2 text-slate-400">
                                    <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span>Devise: <?php echo htmlspecialchars($societe['devise_principale']); ?></span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 pt-3 border-t border-slate-700/30">
                                <button onclick='editSociete(<?php echo json_encode($societe, JSON_HEX_APOS); ?>)'
                                        class="flex-1 px-3 py-1.5 text-xs bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 rounded-lg transition">
                                    Modifier
                                </button>
                                <button onclick="manageSocieteUsers(<?php echo $societe['id']; ?>, '<?php echo htmlspecialchars($societe['raison_sociale']); ?>')"
                                        class="flex-1 px-3 py-1.5 text-xs bg-purple-500/10 text-purple-400 hover:bg-purple-500/20 rounded-lg transition">
                                    Utilisateurs
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Changer le statut ?')">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $societe['id']; ?>">
                                    <button type="submit" class="px-2 py-1.5 text-xs <?php echo $societe['actif'] ? 'bg-red-500/10 text-red-400 hover:bg-red-500/20' : 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20'; ?> rounded-lg transition">
                                        <?php echo $societe['actif'] ? 'Désactiver' : 'Activer'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Statistiques -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4">
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Total sociétés</p>
                        <p class="text-2xl font-bold text-white mt-1"><?php echo count($societes); ?></p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Actives</p>
                        <p class="text-2xl font-bold text-emerald-400 mt-1">
                            <?php echo count(array_filter($societes, fn($s) => $s['actif'])); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Inactives</p>
                        <p class="text-2xl font-bold text-red-400 mt-1">
                            <?php echo count(array_filter($societes, fn($s) => !$s['actif'])); ?>
                        </p>
                    </div>
                    <div class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-4">
                        <p class="text-xs text-slate-400">Utilisateurs</p>
                        <p class="text-2xl font-bold text-blue-400 mt-1"><?php echo count($utilisateurs); ?></p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Société -->
    <div id="modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl max-w-2xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <h3 id="modalTitle" class="text-lg font-semibold text-white mb-4">Nouvelle société</h3>
            <form id="societeForm" method="POST" class="space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Code Société *</label>
                        <input type="text" name="code_societe" id="formCodeSociete" required maxlength="20"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm uppercase"
                               placeholder="Ex: ABP">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Devise par défaut</label>
                        <select name="code_devise_defaut" id="formDevise"
                                class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm">
                            <?php foreach ($devises as $devise): ?>
                                <option value="<?php echo $devise['code_devise']; ?>" <?php echo $devise['code_devise'] === 'XOF' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($devise['code_devise'] . ' - ' . $devise['libelle']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Raison Sociale *</label>
                    <input type="text" name="raison_sociale" id="formRaisonSociale" required maxlength="255"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                           placeholder="Ex: AGILITY BUSINESS PARKS CI">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Forme Juridique</label>
                    <input type="text" name="forme_juridique" id="formFormeJuridique" maxlength="50"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                           placeholder="Ex: SARL, SA, SAS...">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Adresse</label>
                    <input type="text" name="adresse" id="formAdresse" maxlength="255"
                           class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                           placeholder="Adresse complète">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Ville</label>
                        <input type="text" name="ville" id="formVille" maxlength="100"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Ville">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Pays</label>
                        <input type="text" name="pays" id="formPays" maxlength="100"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Pays">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Téléphone</label>
                        <input type="text" name="telephone" id="formTelephone" maxlength="20"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Téléphone">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
                        <input type="email" name="email" id="formEmail" maxlength="100"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Email">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">N° RCCM</label>
                        <input type="text" name="numero_rccm" id="formRCCM" maxlength="50"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Numéro RCCM">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">N° Compte Contribuable</label>
                        <input type="text" name="numero_cc" id="formCC" maxlength="50"
                               class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                               placeholder="Numéro CC">
                    </div>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">
                        Annuler
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-sm transition">
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Gestion des utilisateurs -->
    <div id="modalUsers" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl max-w-2xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <h3 id="modalUsersTitle" class="text-lg font-semibold text-white mb-4">Utilisateurs de la société</h3>

            <div id="usersContent" class="space-y-3">
                <!-- Le contenu sera chargé dynamiquement -->
            </div>

            <div class="flex gap-2 pt-4 border-t border-slate-700/50 mt-4">
                <button type="button" onclick="closeModalUsers()" class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition">
                    Fermer
                </button>
            </div>
        </div>
    </div>

    <script>
        function openModal(action = 'add') {
            document.getElementById('modal').classList.remove('hidden');
            document.getElementById('formAction').value = action;
            document.getElementById('modalTitle').textContent = action === 'add' ? 'Nouvelle société' : 'Modifier la société';

            if (action === 'add') {
                document.getElementById('societeForm').reset();
                document.getElementById('formCodeSociete').disabled = false;
            }
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        function editSociete(societe) {
            openModal('edit');
            document.getElementById('formId').value = societe.id;
            document.getElementById('formCodeSociete').value = societe.code_societe;
            document.getElementById('formCodeSociete').disabled = true;
            document.getElementById('formRaisonSociale').value = societe.raison_sociale;
            document.getElementById('formFormeJuridique').value = societe.forme_juridique || '';
            document.getElementById('formAdresse').value = societe.adresse || '';
            document.getElementById('formVille').value = societe.ville || '';
            document.getElementById('formPays').value = societe.pays || '';
            document.getElementById('formTelephone').value = societe.telephone || '';
            document.getElementById('formEmail').value = societe.email || '';
            document.getElementById('formRCCM').value = societe.numero_rccm || '';
            document.getElementById('formCC').value = societe.numero_cc || '';
            document.getElementById('formDevise').value = societe.devise_principale || 'XOF';
        }

        async function manageSocieteUsers(societeId, raisonSociale) {
            document.getElementById('modalUsersTitle').textContent = 'Utilisateurs - ' + raisonSociale;
            document.getElementById('modalUsers').classList.remove('hidden');

            // Charger les utilisateurs de la société
            const response = await fetch('get_societe_users.php?societe_id=' + societeId);
            const data = await response.json();

            let html = '';

            // Utilisateurs actuels
            if (data.current_users.length > 0) {
                html += '<div class="mb-4"><h4 class="text-sm font-semibold text-slate-300 mb-2">Utilisateurs actifs</h4>';
                data.current_users.forEach(user => {
                    html += `
                        <div class="flex items-center justify-between p-3 bg-slate-700/30 rounded-lg mb-2">
                            <div>
                                <p class="text-sm font-medium text-white">${user.nom_utilisateur}</p>
                                <p class="text-xs text-slate-400">${user.email} - ${user.role}</p>
                            </div>
                            <form method="POST" class="inline" onsubmit="return confirm('Retirer cet utilisateur ?')">
                                <input type="hidden" name="action" value="remove_user">
                                <input type="hidden" name="user_id" value="${user.id_utilisateur}">
                                <input type="hidden" name="societe_id" value="${societeId}">
                                <button type="submit" class="px-3 py-1 text-xs bg-red-500/10 text-red-400 hover:bg-red-500/20 rounded transition">
                                    Retirer
                                </button>
                            </form>
                        </div>
                    `;
                });
                html += '</div>';
            }

            // Ajouter un utilisateur
            html += `
                <div class="border-t border-slate-700/50 pt-4">
                    <h4 class="text-sm font-semibold text-slate-300 mb-3">Ajouter un utilisateur</h4>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="add_user">
                        <input type="hidden" name="societe_id" value="${societeId}">

                        <div>
                            <select name="user_id" required class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option value="">Sélectionner un utilisateur...</option>
                                ${data.available_users.map(u => `<option value="${u.id_utilisateur}">${u.nom_utilisateur} (${u.email})</option>`).join('')}
                            </select>
                        </div>

                        <div>
                            <select name="role_societe" required class="w-full px-3 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option value="utilisateur">Utilisateur</option>
                                <option value="comptable">Comptable</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="par_defaut" id="parDefaut" class="w-4 h-4 bg-slate-900/50 border-slate-600 rounded">
                            <label for="parDefaut" class="text-sm text-slate-300">Société par défaut</label>
                        </div>

                        <button type="submit" class="w-full px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-sm transition">
                            Ajouter
                        </button>
                    </form>
                </div>
            `;

            document.getElementById('usersContent').innerHTML = html;
        }

        function closeModalUsers() {
            document.getElementById('modalUsers').classList.add('hidden');
        }

        // Fermer les modals en cliquant à l'extérieur
        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('modalUsers').addEventListener('click', function(e) {
            if (e.target === this) closeModalUsers();
        });
    </script>
</body>
</html>
