<?php
/**
 * Configuration initiale du système
 * Application: Comptabilité SYSCOHADA Révisé
 * Date: 30 Décembre 2024
 */

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions_multi_societes.php';
require_once __DIR__ . '/../config/functions_multi_devises.php';

// Vérifier si l'installation est déjà complète
if (!needsInitialSetup()) {
    header('Location: ../index.php');
    exit();
}

$erreurs = [];
$succes = false;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        // Validation des données
        $raison_sociale = trim($_POST['raison_sociale'] ?? '');
        $code_societe = trim($_POST['code_societe'] ?? '');
        $type_entite = $_POST['type_entite'] ?? 'entreprise_individuelle';
        $devise_principale = $_POST['devise_principale'] ?? 'XOF';

        if (empty($raison_sociale)) {
            $erreurs[] = "La raison sociale est obligatoire";
        }
        if (empty($code_societe)) {
            $erreurs[] = "Le code société est obligatoire";
        }

        // Données utilisateur admin
        $nom_utilisateur = trim($_POST['nom_utilisateur'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($nom_utilisateur)) {
            $erreurs[] = "Le nom d'utilisateur est obligatoire";
        }
        if (empty($email)) {
            $erreurs[] = "L'email est obligatoire";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "L'email n'est pas valide";
        }
        if (empty($password)) {
            $erreurs[] = "Le mot de passe est obligatoire";
        } elseif (strlen($password) < 8) {
            $erreurs[] = "Le mot de passe doit contenir au moins 8 caractères";
        } elseif ($password !== $password_confirm) {
            $erreurs[] = "Les mots de passe ne correspondent pas";
        }

        if (empty($erreurs)) {
            // Créer la première société
            $societe_data = [
                'code_societe' => $code_societe,
                'raison_sociale' => $raison_sociale,
                'type_entite' => $type_entite,
                'devise_principale' => $devise_principale,
                'adresse' => $_POST['adresse'] ?? null,
                'ville' => $_POST['ville'] ?? null,
                'pays' => $_POST['pays'] ?? 'Côte d\'Ivoire',
                'telephone' => $_POST['telephone'] ?? null,
                'email' => $_POST['email_societe'] ?? null,
                'numero_rccm' => $_POST['numero_rccm'] ?? null,
                'numero_contribuable' => $_POST['numero_contribuable'] ?? null,
                'forme_juridique' => $_POST['forme_juridique'] ?? null,
                'regime_fiscal' => $_POST['regime_fiscal'] ?? 'reel_normal',
                'actif' => 1
            ];

            $societe_id = createSociete($societe_data);

            if (!$societe_id) {
                throw new Exception("Erreur lors de la création de la société");
            }

            // Créer l'utilisateur administrateur
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("
                INSERT INTO utilisateurs (
                    nom_utilisateur, email, mot_de_passe,
                    role_global, dernier_societe_id, actif
                ) VALUES (?, ?, ?, 'super_admin', ?, 1)
            ");

            $stmt->execute([
                $nom_utilisateur,
                $email,
                $password_hash,
                $societe_id
            ]);

            $user_id = (int)$db->lastInsertId();

            // Donner accès admin à la société
            if (!grantUserAccessToSociete($user_id, $societe_id, 'admin', true)) {
                throw new Exception("Erreur lors de l'association utilisateur-société");
            }

            // Mettre à jour les taux de change fixes
            mettreAJourTauxFixes();

            // Marquer l'installation comme terminée
            if (!markInstallationComplete()) {
                throw new Exception("Erreur lors de la finalisation de l'installation");
            }

            $db->commit();

            // Authentifier l'utilisateur automatiquement
            $_SESSION['user_id'] = $user_id;
            $_SESSION['user_name'] = $nom_utilisateur;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = 'super_admin';
            $_SESSION['societe_id'] = $societe_id;

            $succes = true;

            // Redirection après 2 secondes
            header("Refresh: 2; url=../index.php");
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $erreurs[] = "Erreur: " . $e->getMessage();
        error_log("Initial setup error: " . $e->getMessage());
    }
}

// Obtenir les devises pour le select
$devises = getDevisesActives();

// Si aucune devise n'est disponible, créer les devises de base
if (empty($devises)) {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier si la table devises existe
        $tables = $db->query("SHOW TABLES LIKE 'devises'")->fetchAll();

        if (!empty($tables)) {
            // Insérer les devises de base si la table existe mais est vide
            $db->exec("
                INSERT IGNORE INTO devises (code_devise, libelle, symbole, decimales, actif, priorite_affichage, position_symbole) VALUES
                ('XOF', 'Franc CFA (UEMOA)', 'FCFA', 0, 1, 100, 'apres'),
                ('XAF', 'Franc CFA (CEMAC)', 'FCFA', 0, 1, 99, 'apres'),
                ('EUR', 'Euro', '€', 2, 1, 98, 'avant'),
                ('USD', 'Dollar américain', '$', 2, 1, 97, 'avant'),
                ('GBP', 'Livre sterling', '£', 2, 1, 96, 'avant')
            ");

            // Recharger les devises
            $devises = getDevisesActives();
        }
    } catch (Exception $e) {
        error_log("Error loading currencies: " . $e->getMessage());
    }
}

// Liste de devises par défaut si la table n'existe pas encore
if (empty($devises)) {
    $devises = [
        ['code_devise' => 'XOF', 'libelle' => 'Franc CFA (UEMOA)', 'symbole' => 'FCFA'],
        ['code_devise' => 'XAF', 'libelle' => 'Franc CFA (CEMAC)', 'symbole' => 'FCFA'],
        ['code_devise' => 'EUR', 'libelle' => 'Euro', 'symbole' => '€'],
        ['code_devise' => 'USD', 'libelle' => 'Dollar américain', 'symbole' => '$'],
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Initiale - Comptabilité SYSCOHADA</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .setup-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .setup-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .setup-header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .setup-header p {
            color: #7f8c8d;
            font-size: 14px;
        }
        .form-section {
            margin-bottom: 30px;
        }
        .form-section h2 {
            color: #34495e;
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-row.full {
            grid-template-columns: 1fr;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        .form-group label.required::after {
            content: " *";
            color: #e74c3c;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
        }
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #7f8c8d;
            font-size: 12px;
        }
        .error-messages {
            background: #fee;
            border: 1px solid #fcc;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .error-messages ul {
            margin: 0;
            padding-left: 20px;
        }
        .error-messages li {
            color: #c00;
            margin-bottom: 5px;
        }
        .success-message {
            background: #efe;
            border: 1px solid #cfc;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            color: #060;
            text-align: center;
        }
        .submit-button {
            background: #27ae60;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            font-weight: 500;
        }
        .submit-button:hover {
            background: #229954;
        }
        .submit-button:active {
            transform: translateY(1px);
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1>🎉 Bienvenue dans Comptabilité SYSCOHADA</h1>
            <p>Configuration initiale de votre système de comptabilité</p>
        </div>

        <?php if (!empty($erreurs)): ?>
        <div class="error-messages">
            <ul>
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?= htmlspecialchars($erreur) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($succes): ?>
        <div class="success-message">
            <strong>✅ Configuration terminée avec succès !</strong><br>
            Redirection vers l'application...
        </div>
        <?php else: ?>

        <form method="POST" action="">
            <!-- Informations de la société -->
            <div class="form-section">
                <h2>1. Informations de votre société</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="raison_sociale" class="required">Raison sociale</label>
                        <input type="text" id="raison_sociale" name="raison_sociale"
                               value="<?= htmlspecialchars($_POST['raison_sociale'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="code_societe" class="required">Code société</label>
                        <input type="text" id="code_societe" name="code_societe"
                               value="<?= htmlspecialchars($_POST['code_societe'] ?? '') ?>"
                               placeholder="Ex: SOC001" required>
                        <small>Code unique pour identifier votre société</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="type_entite" class="required">Type d'entité</label>
                        <select id="type_entite" name="type_entite" required>
                            <option value="entreprise_individuelle" <?= ($_POST['type_entite'] ?? '') === 'entreprise_individuelle' ? 'selected' : '' ?>>
                                Entreprise individuelle
                            </option>
                            <option value="groupe" <?= ($_POST['type_entite'] ?? '') === 'groupe' ? 'selected' : '' ?>>
                                Groupe de sociétés
                            </option>
                            <option value="cabinet" <?= ($_POST['type_entite'] ?? '') === 'cabinet' ? 'selected' : '' ?>>
                                Cabinet d'expertise comptable
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="devise_principale" class="required">Devise principale</label>
                        <select id="devise_principale" name="devise_principale" required>
                            <?php foreach ($devises as $devise): ?>
                                <option value="<?= htmlspecialchars($devise['code_devise']) ?>"
                                        <?= ($devise['code_devise'] === 'XOF' || ($devise['code_devise'] === ($_POST['devise_principale'] ?? ''))) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($devise['code_devise']) ?> - <?= htmlspecialchars($devise['libelle']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="numero_rccm">Numéro RCCM</label>
                        <input type="text" id="numero_rccm" name="numero_rccm"
                               value="<?= htmlspecialchars($_POST['numero_rccm'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="numero_contribuable">Numéro contribuable</label>
                        <input type="text" id="numero_contribuable" name="numero_contribuable"
                               value="<?= htmlspecialchars($_POST['numero_contribuable'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row full">
                    <div class="form-group">
                        <label for="adresse">Adresse</label>
                        <input type="text" id="adresse" name="adresse"
                               value="<?= htmlspecialchars($_POST['adresse'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ville">Ville</label>
                        <input type="text" id="ville" name="ville"
                               value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="pays">Pays</label>
                        <input type="text" id="pays" name="pays"
                               value="<?= htmlspecialchars($_POST['pays'] ?? 'Côte d\'Ivoire') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="telephone">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone"
                               value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="email_societe">Email société</label>
                        <input type="email" id="email_societe" name="email_societe"
                               value="<?= htmlspecialchars($_POST['email_societe'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Informations administrateur -->
            <div class="form-section">
                <h2>2. Compte administrateur</h2>

                <div class="form-row full">
                    <div class="form-group">
                        <label for="nom_utilisateur" class="required">Nom d'utilisateur</label>
                        <input type="text" id="nom_utilisateur" name="nom_utilisateur"
                               value="<?= htmlspecialchars($_POST['nom_utilisateur'] ?? '') ?>" required>
                        <small>Utilisé pour l'identification dans le système</small>
                    </div>
                </div>

                <div class="form-row full">
                    <div class="form-group">
                        <label for="email" class="required">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        <small>Utilisé pour la connexion au système</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="required">Mot de passe</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <small>Minimum 8 caractères</small>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm" class="required">Confirmer le mot de passe</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-button">
                Finaliser la configuration
            </button>
        </form>

        <?php endif; ?>
    </div>
</body>
</html>
