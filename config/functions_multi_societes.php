<?php
/**
 * Fonctions de gestion multi-sociétés
 * Application: Comptabilité SYSCOHADA Révisé
 * Date: 30 Décembre 2024
 */

/**
 * Vérifie si le système nécessite une configuration initiale
 *
 * @return bool True si setup nécessaire, False sinon
 */
function needsInitialSetup(): bool {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier le flag d'installation
        $stmt = $db->query("
            SELECT valeur
            FROM parametres_systeme
            WHERE cle = 'installation_complete'
        ");
        $param = $stmt->fetch();

        // Si le flag existe et vaut '1', l'installation est complète
        if ($param && $param['valeur'] === '1') {
            return false;
        }

        // Sinon, setup nécessaire
        return true;

    } catch (PDOException $e) {
        // Si erreur (tables n'existent pas), c'est une première installation
        error_log("needsInitialSetup error: " . $e->getMessage());
        return true;
    }
}

/**
 * Marque l'installation comme terminée
 *
 * @return bool Success
 */
function markInstallationComplete(): bool {
    try {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            UPDATE parametres_systeme
            SET valeur = '1',
                date_modification = NOW()
            WHERE cle = 'installation_complete'
        ");
        $stmt->execute();

        // Enregistrer la date d'installation si pas déjà fait
        $stmt = $db->prepare("
            UPDATE parametres_systeme
            SET valeur = NOW()
            WHERE cle = 'date_installation'
              AND (valeur IS NULL OR valeur = '')
        ");
        $stmt->execute();

        return true;

    } catch (PDOException $e) {
        error_log("markInstallationComplete error: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtient un paramètre système
 *
 * @param string $key Clé du paramètre
 * @param mixed $default Valeur par défaut si non trouvé
 * @return mixed Valeur du paramètre
 */
function getSystemParameter(string $key, $default = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT valeur, type_valeur
            FROM parametres_systeme
            WHERE cle = ?
        ");
        $stmt->execute([$key]);
        $param = $stmt->fetch();

        if (!$param) {
            return $default;
        }

        // Conversion selon le type
        switch ($param['type_valeur']) {
            case 'int':
                return (int)$param['valeur'];
            case 'bool':
                return ($param['valeur'] === '1' || $param['valeur'] === 'true');
            case 'json':
                return json_decode($param['valeur'], true);
            default:
                return $param['valeur'];
        }

    } catch (PDOException $e) {
        error_log("getSystemParameter error: " . $e->getMessage());
        return $default;
    }
}

/**
 * Définit un paramètre système
 *
 * @param string $key Clé du paramètre
 * @param mixed $value Valeur à définir
 * @return bool Success
 */
function setSystemParameter(string $key, $value): bool {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier si le paramètre est modifiable
        $stmt = $db->prepare("
            SELECT modifiable
            FROM parametres_systeme
            WHERE cle = ?
        ");
        $stmt->execute([$key]);
        $param = $stmt->fetch();

        if (!$param || !$param['modifiable']) {
            return false;
        }

        // Conversion de la valeur
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        }

        $stmt = $db->prepare("
            UPDATE parametres_systeme
            SET valeur = ?,
                date_modification = NOW()
            WHERE cle = ?
        ");

        return $stmt->execute([$value, $key]);

    } catch (PDOException $e) {
        error_log("setSystemParameter error: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtient la société courante de l'utilisateur
 *
 * @return int|null ID de la société courante
 */
function getCurrentSocieteId(): ?int {
    if (isset($_SESSION['societe_id'])) {
        return (int)$_SESSION['societe_id'];
    }
    return null;
}

/**
 * Définit la société courante
 *
 * @param int $societe_id ID de la société
 * @return void
 */
function setCurrentSocieteId(int $societe_id): void {
    $_SESSION['societe_id'] = $societe_id;

    // Sauvegarder en BDD pour la prochaine connexion
    if (isset($_SESSION['user_id'])) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                UPDATE utilisateurs
                SET dernier_societe_id = ?
                WHERE id_utilisateur = ?
            ");
            $stmt->execute([$societe_id, $_SESSION['user_id']]);
        } catch (PDOException $e) {
            error_log("setCurrentSocieteId error: " . $e->getMessage());
        }
    }
}

/**
 * Obtient la liste des sociétés accessibles par l'utilisateur
 *
 * @param int $user_id ID de l'utilisateur
 * @return array Liste des sociétés
 */
function getUserSocietes(int $user_id): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT
                s.*,
                us.role,
                us.par_defaut,
                us.peut_modifier_plan,
                us.peut_cloturer_exercice,
                us.peut_valider_ecritures,
                us.peut_exporter
            FROM societes s
            INNER JOIN utilisateurs_societes us ON s.id = us.societe_id
            WHERE us.id_utilisateur = ?
              AND s.actif = 1
              AND us.actif = 1
              AND (us.date_acces_fin IS NULL OR us.date_acces_fin >= CURDATE())
            ORDER BY us.par_defaut DESC, s.raison_sociale
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log("getUserSocietes error: " . $e->getMessage());
        return [];
    }
}

/**
 * Vérifie si l'utilisateur a accès à une société
 *
 * @param int $user_id ID de l'utilisateur
 * @param int $societe_id ID de la société
 * @return bool True si accès autorisé
 */
function userHasAccessToSociete(int $user_id, int $societe_id): bool {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM utilisateurs_societes
            WHERE id_utilisateur = ?
              AND societe_id = ?
              AND actif = 1
              AND (date_acces_fin IS NULL OR date_acces_fin >= CURDATE())
        ");
        $stmt->execute([$user_id, $societe_id]);
        $result = $stmt->fetch();
        return ($result['count'] > 0);

    } catch (PDOException $e) {
        error_log("userHasAccessToSociete error: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtient le rôle de l'utilisateur dans une société
 *
 * @param int $user_id ID de l'utilisateur
 * @param int $societe_id ID de la société
 * @return string|null Rôle de l'utilisateur
 */
function getUserRoleInSociete(int $user_id, int $societe_id): ?string {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT role
            FROM utilisateurs_societes
            WHERE id_utilisateur = ?
              AND societe_id = ?
              AND actif = 1
        ");
        $stmt->execute([$user_id, $societe_id]);
        $result = $stmt->fetch();
        return $result ? $result['role'] : null;

    } catch (PDOException $e) {
        error_log("getUserRoleInSociete error: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtient le nombre de sociétés actives pour un utilisateur
 *
 * @param int $user_id ID de l'utilisateur
 * @return int Nombre de sociétés
 */
function getNombreSocietesActives(int $user_id): int {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM societes s
            INNER JOIN utilisateurs_societes us ON s.id = us.societe_id
            WHERE us.id_utilisateur = ?
              AND s.actif = 1
              AND us.actif = 1
              AND (us.date_acces_fin IS NULL OR us.date_acces_fin >= CURDATE())
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return (int)$result['count'];

    } catch (PDOException $e) {
        error_log("getNombreSocietesActives error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Obtient les informations de la société courante
 *
 * @return array|null Données de la société
 */
function getCurrentSociete(): ?array {
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) {
        return null;
    }

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM societes WHERE id = ? AND actif = 1");
        $stmt->execute([$societe_id]);
        return $stmt->fetch() ?: null;

    } catch (PDOException $e) {
        error_log("getCurrentSociete error: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtient une société par son ID
 *
 * @param int $societe_id ID de la société
 * @return array|null Données de la société
 */
function getSocieteById(int $societe_id): ?array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM societes WHERE id = ?");
        $stmt->execute([$societe_id]);
        return $stmt->fetch() ?: null;

    } catch (PDOException $e) {
        error_log("getSocieteById error: " . $e->getMessage());
        return null;
    }
}

/**
 * Vérifie l'accès à une société et lance une exception si refusé
 *
 * @param int $societe_id ID de la société
 * @throws Exception Si accès refusé
 */
function requireSocieteAccess(int $societe_id): void {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception('Non authentifié');
    }

    $user_id = $_SESSION['user_id'];
    if (!userHasAccessToSociete($user_id, $societe_id)) {
        http_response_code(403);
        throw new Exception('Accès refusé à cette société');
    }
}

/**
 * Vérifie un droit spécifique de l'utilisateur dans la société courante
 *
 * @param string $droit Nom du droit (peut_modifier_plan, peut_cloturer_exercice, etc.)
 * @return bool True si l'utilisateur a le droit
 */
function userHasRight(string $droit): bool {
    $user_id = $_SESSION['user_id'] ?? null;
    $societe_id = getCurrentSocieteId();

    if (!$user_id || !$societe_id) {
        return false;
    }

    try {
        $db = Database::getInstance()->getConnection();

        // Les admins ont tous les droits
        $stmt = $db->prepare("
            SELECT role, $droit
            FROM utilisateurs_societes
            WHERE id_utilisateur = ?
              AND societe_id = ?
              AND actif = 1
        ");
        $stmt->execute([$user_id, $societe_id]);
        $result = $stmt->fetch();

        if (!$result) {
            return false;
        }

        // Admin a tous les droits
        if ($result['role'] === 'admin') {
            return true;
        }

        // Vérifier le droit spécifique
        return (bool)$result[$droit];

    } catch (PDOException $e) {
        error_log("userHasRight error: " . $e->getMessage());
        return false;
    }
}

/**
 * Crée une nouvelle société
 *
 * @param array $data Données de la société
 * @return int|false ID de la société créée ou false
 */
function createSociete(array $data) {
    try {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            INSERT INTO societes (
                code_societe, raison_sociale, type_entite,
                id_cabinet, id_societe_mere,
                numero_rccm, numero_contribuable, forme_juridique, capital,
                adresse, ville, pays, telephone, email, site_web,
                devise_principale, regime_fiscal, date_debut_activite,
                logo, actif
            ) VALUES (
                :code, :raison_sociale, :type_entite,
                :id_cabinet, :id_societe_mere,
                :numero_rccm, :numero_contribuable, :forme_juridique, :capital,
                :adresse, :ville, :pays, :telephone, :email, :site_web,
                :devise, :regime_fiscal, :date_debut_activite,
                :logo, :actif
            )
        ");

        $stmt->execute([
            'code' => $data['code_societe'],
            'raison_sociale' => $data['raison_sociale'],
            'type_entite' => $data['type_entite'] ?? 'entreprise_individuelle',
            'id_cabinet' => $data['id_cabinet'] ?? null,
            'id_societe_mere' => $data['id_societe_mere'] ?? null,
            'numero_rccm' => $data['numero_rccm'] ?? null,
            'numero_contribuable' => $data['numero_contribuable'] ?? null,
            'forme_juridique' => $data['forme_juridique'] ?? null,
            'capital' => $data['capital'] ?? null,
            'adresse' => $data['adresse'] ?? null,
            'ville' => $data['ville'] ?? null,
            'pays' => $data['pays'] ?? 'Côte d\'Ivoire',
            'telephone' => $data['telephone'] ?? null,
            'email' => $data['email'] ?? null,
            'site_web' => $data['site_web'] ?? null,
            'devise' => $data['devise_principale'] ?? 'XOF',
            'regime_fiscal' => $data['regime_fiscal'] ?? 'reel_normal',
            'date_debut_activite' => $data['date_debut_activite'] ?? null,
            'logo' => $data['logo'] ?? null,
            'actif' => $data['actif'] ?? 1
        ]);

        return (int)$db->lastInsertId();

    } catch (PDOException $e) {
        error_log("createSociete error: " . $e->getMessage());
        return false;
    }
}

/**
 * Donne accès à un utilisateur à une société
 *
 * @param int $user_id ID de l'utilisateur
 * @param int $societe_id ID de la société
 * @param string $role Rôle dans la société
 * @param bool $par_defaut Société par défaut
 * @return bool Success
 */
function grantUserAccessToSociete(int $user_id, int $societe_id, string $role = 'lecteur', bool $par_defaut = false): bool {
    try {
        $db = Database::getInstance()->getConnection();

        // Si par_defaut = true, mettre les autres à false
        if ($par_defaut) {
            $stmt = $db->prepare("
                UPDATE utilisateurs_societes
                SET par_defaut = 0
                WHERE id_utilisateur = ?
            ");
            $stmt->execute([$user_id]);
        }

        $stmt = $db->prepare("
            INSERT INTO utilisateurs_societes (
                id_utilisateur, societe_id, role, par_defaut, date_acces_debut, actif
            ) VALUES (
                ?, ?, ?, ?, CURDATE(), 1
            )
            ON DUPLICATE KEY UPDATE
                role = VALUES(role),
                par_defaut = VALUES(par_defaut),
                actif = 1,
                date_acces_fin = NULL
        ");

        return $stmt->execute([$user_id, $societe_id, $role, $par_defaut ? 1 : 0]);

    } catch (PDOException $e) {
        error_log("grantUserAccessToSociete error: " . $e->getMessage());
        return false;
    }
}
