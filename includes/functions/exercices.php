<?php
/**
 * Fonctions de gestion des exercices comptables
 */

/**
 * Vérifie si une date se trouve dans un exercice clôturé
 *
 * @param string $date Date à vérifier (format Y-m-d)
 * @param PDO $db Connexion à la base de données
 * @param int|null $societe_id Identifiant de la société (optionnel)
 * @return array|false Retourne les infos de l'exercice clôturé ou false si non clôturé
 */
function isDateInClosedExercice($date, $db, $societe_id = null) {
    if (empty($date)) {
        return false;
    }

    if ($societe_id !== null) {
        $stmt = $db->prepare("
            SELECT id, code, libelle, statut, date_debut, date_fin
            FROM exercices_comptables
            WHERE ? BETWEEN date_debut AND date_fin
            AND societe_id = ?
        ");
        $stmt->execute([$date, $societe_id]);
    } else {
        $stmt = $db->prepare("
            SELECT id, code, libelle, statut, date_debut, date_fin
            FROM exercices_comptables
            WHERE ? BETWEEN date_debut AND date_fin
        ");
        $stmt->execute([$date]);
    }
    $exercice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exercice && $exercice['statut'] === 'Clôturé') {
        return $exercice;
    }

    return false;
}

/**
 * Vérifie si une écriture est dans un exercice clôturé
 *
 * @param int $ecriture_id ID de l'écriture
 * @param PDO $db Connexion à la base de données
 * @return array|false Retourne les infos de l'exercice clôturé ou false si non clôturé
 */
function isEcritureInClosedExercice($ecriture_id, $db) {
    $stmt = $db->prepare("
        SELECT e.date_ecriture
        FROM ecritures e
        WHERE e.id = ?
    ");
    $stmt->execute([$ecriture_id]);
    $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ecriture) {
        return false;
    }

    return isDateInClosedExercice($ecriture['date_ecriture'], $db);
}

/**
 * Bloque l'action si la date est dans un exercice clôturé et redirige avec message d'erreur
 *
 * @param string $date Date à vérifier
 * @param PDO $db Connexion à la base de données
 * @param string $redirect_url URL de redirection en cas de blocage
 * @return void
 */
function blockIfExerciceClosed($date, $db, $redirect_url = null) {
    $exercice = isDateInClosedExercice($date, $db);

    if ($exercice) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => "Impossible d'effectuer cette opération : l'exercice {$exercice['libelle']} ({$exercice['code']}) est clôturé depuis le " . date('d/m/Y', strtotime($exercice['date_fin']))
        ];

        if ($redirect_url) {
            header('Location: ' . $redirect_url);
        } else {
            header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'index.php');
        }
        exit;
    }
}

/**
 * Bloque l'action si l'écriture est dans un exercice clôturé
 *
 * @param int $ecriture_id ID de l'écriture
 * @param PDO $db Connexion à la base de données
 * @param string $redirect_url URL de redirection en cas de blocage
 * @return void
 */
function blockIfEcritureInClosedExercice($ecriture_id, $db, $redirect_url = null) {
    $exercice = isEcritureInClosedExercice($ecriture_id, $db);

    if ($exercice) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => "Impossible de modifier cette écriture : elle appartient à l'exercice clôturé {$exercice['libelle']} ({$exercice['code']})"
        ];

        if ($redirect_url) {
            header('Location: ' . $redirect_url);
        } else {
            header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'liste.php');
        }
        exit;
    }
}
