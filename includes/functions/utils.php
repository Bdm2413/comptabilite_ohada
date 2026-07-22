<?php
/**
 * Fonctions utilitaires
 */

/**
 * Formate un montant en format monétaire
 */
function formatMontant($montant, $devise = 'XOF') {
    return number_format($montant, 2, ',', ' ') . ' ' . $devise;
}

/**
 * Formate une date au format français
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

/**
 * Convertit une date du format français au format MySQL
 */
function dateToMySQL($date) {
    if (empty($date)) return null;
    $parts = explode('/', $date);
    if (count($parts) === 3) {
        return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
    }
    return $date;
}

/**
 * Génère un numéro de pièce unique
 */
function generateNumeroPiece($codeJournal, $exercice) {
    try {
        $db = Database::getInstance()->getConnection();

        // Récupérer le dernier numéro pour ce journal et cet exercice
        $stmt = $db->prepare("
            SELECT numero_piece
            FROM pieces_comptables
            WHERE id_journal = (SELECT id_journal FROM journaux WHERE code_journal = :code)
            AND id_exercice = :exercice
            ORDER BY id_piece DESC
            LIMIT 1
        ");

        $stmt->execute(['code' => $codeJournal, 'exercice' => $exercice]);
        $lastPiece = $stmt->fetch();

        if ($lastPiece) {
            // Extraire le numéro et l'incrémenter
            preg_match('/(\d+)$/', $lastPiece['numero_piece'], $matches);
            $numero = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        } else {
            $numero = 1;
        }

        return $codeJournal . '-' . str_pad($numero, 6, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        error_log("Erreur lors de la génération du numéro de pièce: " . $e->getMessage());
        return $codeJournal . '-000001';
    }
}

/**
 * Vérifie si un exercice est ouvert
 */
function isExerciceOuvert($idExercice) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT cloture FROM exercices WHERE id_exercice = :id");
        $stmt->execute(['id' => $idExercice]);
        $exercice = $stmt->fetch();

        return $exercice && $exercice['cloture'] == 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Récupère l'exercice actif
 */
function getExerciceActif() {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT * FROM exercices
            WHERE cloture = 0
            AND CURDATE() BETWEEN date_debut AND date_fin
            ORDER BY date_debut DESC
            LIMIT 1
        ");

        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Vérifie si une écriture est équilibrée
 */
function isEcritureEquilibree($lignes) {
    $totalDebit = 0;
    $totalCredit = 0;

    foreach ($lignes as $ligne) {
        $totalDebit += floatval($ligne['debit'] ?? 0);
        $totalCredit += floatval($ligne['credit'] ?? 0);
    }

    return abs($totalDebit - $totalCredit) < 0.01; // Tolérance de 0.01 pour les erreurs d'arrondi
}

/**
 * Récupère les périodes d'un exercice
 */
function getPeriodes($idExercice, $typePeriode) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM periodes
            WHERE id_exercice = :exercice
            AND type_periode = :type
            ORDER BY periode_numero
        ");

        $stmt->execute([
            'exercice' => $idExercice,
            'type' => $typePeriode
        ]);

        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Calcule les soldes pour un compte
 */
function getSoldeCompte($numeroCompte, $dateDebut = null, $dateFin = null) {
    try {
        $db = Database::getInstance()->getConnection();

        $sql = "
            SELECT
                SUM(le.debit) as total_debit,
                SUM(le.credit) as total_credit
            FROM lignes_ecriture le
            JOIN pieces_comptables pc ON le.id_piece = pc.id_piece
            WHERE le.numero_compte = :compte
            AND pc.valide = 1
        ";

        $params = ['compte' => $numeroCompte];

        if ($dateDebut) {
            $sql .= " AND pc.date_piece >= :date_debut";
            $params['date_debut'] = $dateDebut;
        }

        if ($dateFin) {
            $sql .= " AND pc.date_piece <= :date_fin";
            $params['date_fin'] = $dateFin;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        $debit = floatval($result['total_debit'] ?? 0);
        $credit = floatval($result['total_credit'] ?? 0);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'solde' => $debit - $credit
        ];
    } catch (Exception $e) {
        return ['debit' => 0, 'credit' => 0, 'solde' => 0];
    }
}

/**
 * Détermine le type de solde (débiteur ou créditeur)
 */
function getTypeSolde($solde) {
    if ($solde > 0) return 'débiteur';
    if ($solde < 0) return 'créditeur';
    return 'nul';
}

/**
 * Récupère la liste des comptes d'une classe
 */
function getComptesParClasse($classe) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM plan_comptable
            WHERE classe = :classe
            AND actif = 1
            ORDER BY numero_compte
        ");

        $stmt->execute(['classe' => $classe]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Formate une date en français long (ex: Mardi 29 octobre 2025)
 */
function formatDateLong($date = null) {
    $timestamp = $date ? strtotime($date) : time();

    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    $jour_semaine = $jours[date('w', $timestamp)];
    $jour = date('d', $timestamp);
    $mois_nom = $mois[intval(date('m', $timestamp))];
    $annee = date('Y', $timestamp);

    return "$jour_semaine $jour $mois_nom $annee";
}
