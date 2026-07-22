<?php
/**
 * Fonctions de gestion multi-devises
 * Application: Comptabilité SYSCOHADA Révisé
 * Date: 30 Décembre 2024
 */

/**
 * Obtient la devise principale de la société courante
 *
 * @return string|null Code devise (ex: 'XOF', 'EUR')
 */
function getCurrentDevise(): ?string {
    $societe = getCurrentSociete();
    return $societe ? $societe['devise_principale'] : null;
}

/**
 * Obtient les informations d'une devise
 *
 * @param string $code_devise Code ISO 4217 de la devise
 * @return array|null Informations de la devise
 */
function getDevise(string $code_devise): ?array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM devises
            WHERE code_devise = ?
        ");
        $stmt->execute([$code_devise]);
        return $stmt->fetch() ?: null;

    } catch (PDOException $e) {
        error_log("getDevise error: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtient toutes les devises actives
 *
 * @return array Liste des devises
 */
function getDevisesActives(): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT *
            FROM devises
            WHERE actif = 1
            ORDER BY priorite_affichage DESC, libelle
        ");
        return $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log("getDevisesActives error: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtient le taux de change entre deux devises
 *
 * @param string $devise_origine Code devise source
 * @param string $devise_cible Code devise destination
 * @param string|null $date Date du taux (NULL = aujourd'hui)
 * @return float|null Taux de change ou null si non trouvé
 */
function getTauxChange(string $devise_origine, string $devise_cible, ?string $date = null): ?float {
    // Si même devise, taux = 1
    if ($devise_origine === $devise_cible) {
        return 1.0;
    }

    try {
        $db = Database::getInstance()->getConnection();

        // Utiliser la fonction SQL get_taux_change
        $stmt = $db->prepare("SELECT get_taux_change(?, ?, ?) as taux");
        $stmt->execute([$devise_origine, $devise_cible, $date ?? date('Y-m-d')]);
        $result = $stmt->fetch();

        return $result && $result['taux'] ? (float)$result['taux'] : null;

    } catch (PDOException $e) {
        error_log("getTauxChange error: " . $e->getMessage());
        return null;
    }
}

/**
 * Convertit un montant d'une devise à une autre
 *
 * @param float $montant Montant à convertir
 * @param string $devise_origine Code devise source
 * @param string $devise_cible Code devise destination
 * @param string|null $date Date du taux (NULL = aujourd'hui)
 * @return float|null Montant converti ou null si conversion impossible
 */
function convertirMontant(float $montant, string $devise_origine, string $devise_cible, ?string $date = null): ?float {
    $taux = getTauxChange($devise_origine, $devise_cible, $date);

    if ($taux === null) {
        return null;
    }

    return $montant * $taux;
}

/**
 * Formate un montant avec le symbole de la devise
 *
 * @param float $montant Montant à formater
 * @param string $code_devise Code de la devise
 * @param bool $afficher_symbole Afficher le symbole de devise
 * @return string Montant formaté
 */
function formaterMontantDevise(float $montant, string $code_devise, bool $afficher_symbole = true): string {
    $devise = getDevise($code_devise);

    if (!$devise) {
        return number_format($montant, 2, ',', ' ');
    }

    $montant_formate = number_format(
        $montant,
        $devise['decimales'],
        ',',
        ' '
    );

    if ($afficher_symbole) {
        // Position du symbole selon la devise
        if ($devise['position_symbole'] === 'avant') {
            return $devise['symbole'] . ' ' . $montant_formate;
        } else {
            return $montant_formate . ' ' . $devise['symbole'];
        }
    }

    return $montant_formate;
}

/**
 * Enregistre un nouveau taux de change
 *
 * @param string $devise_origine Code devise source
 * @param string $devise_cible Code devise destination
 * @param float $taux Taux de change
 * @param string $date_debut Date de début de validité
 * @param string|null $date_fin Date de fin de validité (NULL = indéfinie)
 * @param string $source Source du taux (ex: 'BCEAO', 'manuel')
 * @return bool Success
 */
function enregistrerTauxChange(
    string $devise_origine,
    string $devise_cible,
    float $taux,
    string $date_debut,
    ?string $date_fin = null,
    string $source = 'manuel'
): bool {
    try {
        $db = Database::getInstance()->getConnection();

        // Invalider les anciens taux qui se chevauchent
        $stmt = $db->prepare("
            UPDATE taux_change
            SET date_fin = DATE_SUB(?, INTERVAL 1 DAY)
            WHERE devise_origine = ?
              AND devise_cible = ?
              AND date_debut <= ?
              AND (date_fin IS NULL OR date_fin >= ?)
        ");
        $stmt->execute([$date_debut, $devise_origine, $devise_cible, $date_debut, $date_debut]);

        // Insérer le nouveau taux
        $stmt = $db->prepare("
            INSERT INTO taux_change (
                devise_origine, devise_cible, taux, date_debut, date_fin, source
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            $devise_origine,
            $devise_cible,
            $taux,
            $date_debut,
            $date_fin,
            $source
        ]);

    } catch (PDOException $e) {
        error_log("enregistrerTauxChange error: " . $e->getMessage());
        return false;
    }
}

/**
 * Met à jour les taux de change fixes (FCFA/EUR)
 * Taux fixe: 1 EUR = 655.957 XOF/XAF
 *
 * @return bool Success
 */
function mettreAJourTauxFixes(): bool {
    $taux_eur_fcfa = 655.957;
    $date_debut = date('Y-m-d');

    $resultats = [];

    // EUR -> XOF
    $resultats[] = enregistrerTauxChange('EUR', 'XOF', $taux_eur_fcfa, $date_debut, null, 'BCEAO');

    // XOF -> EUR
    $resultats[] = enregistrerTauxChange('XOF', 'EUR', 1 / $taux_eur_fcfa, $date_debut, null, 'BCEAO');

    // EUR -> XAF
    $resultats[] = enregistrerTauxChange('EUR', 'XAF', $taux_eur_fcfa, $date_debut, null, 'BEAC');

    // XAF -> EUR
    $resultats[] = enregistrerTauxChange('XAF', 'EUR', 1 / $taux_eur_fcfa, $date_debut, null, 'BEAC');

    // XOF <-> XAF (parité 1:1)
    $resultats[] = enregistrerTauxChange('XOF', 'XAF', 1.0, $date_debut, null, 'Parité');
    $resultats[] = enregistrerTauxChange('XAF', 'XOF', 1.0, $date_debut, null, 'Parité');

    return !in_array(false, $resultats, true);
}

/**
 * Obtient l'historique des taux de change entre deux devises
 *
 * @param string $devise_origine Code devise source
 * @param string $devise_cible Code devise destination
 * @param int $limite Nombre maximum de résultats
 * @return array Historique des taux
 */
function getHistoriqueTaux(string $devise_origine, string $devise_cible, int $limite = 30): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM taux_change
            WHERE devise_origine = ?
              AND devise_cible = ?
            ORDER BY date_debut DESC
            LIMIT ?
        ");
        $stmt->execute([$devise_origine, $devise_cible, $limite]);
        return $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log("getHistoriqueTaux error: " . $e->getMessage());
        return [];
    }
}

/**
 * Vérifie si une écriture nécessite une conversion de devise
 *
 * @param array $ligne_ecriture Ligne d'écriture
 * @return bool True si conversion nécessaire
 */
function necessiteConversionDevise(array $ligne_ecriture): bool {
    if (empty($ligne_ecriture['devise_origine'])) {
        return false;
    }

    $devise_societe = getCurrentDevise();
    return $ligne_ecriture['devise_origine'] !== $devise_societe;
}

/**
 * Calcule les montants en devise pour une ligne d'écriture
 *
 * @param float $montant_devise Montant en devise d'origine
 * @param string $devise_origine Code devise d'origine
 * @param string|null $date Date de l'écriture
 * @return array|null ['montant_local' => float, 'taux_change' => float, 'ecart_change' => float] ou null
 */
function calculerMontantsDevise(float $montant_devise, string $devise_origine, ?string $date = null): ?array {
    $devise_societe = getCurrentDevise();

    if (!$devise_societe || $devise_origine === $devise_societe) {
        return null;
    }

    $taux = getTauxChange($devise_origine, $devise_societe, $date);

    if ($taux === null) {
        return null;
    }

    $montant_local = $montant_devise * $taux;

    return [
        'montant_local' => $montant_local,
        'taux_change' => $taux,
        'ecart_change' => 0 // Calculé ultérieurement lors des règlements
    ];
}

/**
 * Calcule l'écart de change lors d'un règlement
 *
 * @param float $montant_devise Montant en devise d'origine
 * @param string $devise_origine Code devise d'origine
 * @param float $taux_initial Taux lors de la facturation
 * @param float $taux_reglement Taux lors du règlement
 * @return float Écart de change (positif = gain, négatif = perte)
 */
function calculerEcartChange(
    float $montant_devise,
    string $devise_origine,
    float $taux_initial,
    float $taux_reglement
): float {
    $montant_initial = $montant_devise * $taux_initial;
    $montant_reglement = $montant_devise * $taux_reglement;

    return $montant_reglement - $montant_initial;
}

/**
 * Génère le libellé d'une opération en devise
 *
 * @param string $libelle_base Libellé de base
 * @param float $montant_devise Montant en devise
 * @param string $code_devise Code de la devise
 * @param float $taux_change Taux de change appliqué
 * @return string Libellé enrichi
 */
function genererLibelleDevise(
    string $libelle_base,
    float $montant_devise,
    string $code_devise,
    float $taux_change
): string {
    $devise = getDevise($code_devise);
    $symbole = $devise ? $devise['symbole'] : $code_devise;

    return sprintf(
        "%s (%s %s au taux de %s)",
        $libelle_base,
        number_format($montant_devise, 2, ',', ' '),
        $symbole,
        number_format($taux_change, 6, ',', ' ')
    );
}

/**
 * Obtient les comptes d'écart de change pour la société courante
 *
 * @return array ['gain' => string, 'perte' => string] Numéros de comptes
 */
function getComptesEcartChange(): array {
    // Comptes SYSCOHADA pour les gains et pertes de change
    return [
        'gain' => '776',  // Gains de change
        'perte' => '676'  // Pertes de change
    ];
}

/**
 * Vérifie si une devise est utilisée dans la comptabilité
 *
 * @param string $code_devise Code de la devise
 * @return bool True si utilisée
 */
function deviseEstUtilisee(string $code_devise): bool {
    try {
        $db = Database::getInstance()->getConnection();
        $societe_id = getCurrentSocieteId();

        if (!$societe_id) {
            return false;
        }

        // Vérifier si la devise est la devise principale d'une société
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM societes
            WHERE devise_principale = ?
        ");
        $stmt->execute([$code_devise]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            return true;
        }

        // Vérifier si utilisée dans des écritures
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM lignes_ecriture
            WHERE societe_id = ?
              AND devise_origine = ?
            LIMIT 1
        ");
        $stmt->execute([$societe_id, $code_devise]);
        $result = $stmt->fetch();

        return ($result['count'] > 0);

    } catch (PDOException $e) {
        error_log("deviseEstUtilisee error: " . $e->getMessage());
        return false;
    }
}
