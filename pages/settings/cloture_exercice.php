<?php
/**
 * Script de clôture d'un exercice comptable
 * Génère automatiquement :
 * 1. Écriture de clôture (solde des comptes 6, 7, 8 → 131/139)
 * 2. Écriture d'ouverture du prochain exercice (report à nouveau des comptes 1-5)
 */

require_once '../../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: exercices.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: Aucune société sélectionnée'];
    header('Location: exercices.php');
    exit;
}

$exercice_id = intval($_POST['exercice_id']);
$user_id = $_SESSION['user_id'];

try {
    $db->beginTransaction();

    // 1. Récupérer les informations de l'exercice
    $stmt = $db->prepare("SELECT * FROM exercices WHERE id = ? AND societe_id = ?");
    $stmt->execute([$exercice_id, $societe_id]);
    $exercice = $stmt->fetch();

    if (!$exercice) {
        throw new Exception("Exercice introuvable");
    }

    if ($exercice['statut'] !== 'Ouvert') {
        throw new Exception("Cet exercice est déjà clôturé");
    }

    $annee = $exercice['annee'];
    $date_cloture = $exercice['date_fin']; // Dernier jour de l'exercice

    // 2. Calculer le résultat si pas encore fait
    if ($exercice['resultat_calcule'] === null) {
        // Calculer charges
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(le.debit - le.credit), 0) as total
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.societe_id = ?
                AND e.statut = 'Validé'
                AND YEAR(e.date_ecriture) = ?
                AND (LEFT(le.compte, 1) = '6' OR LEFT(le.compte, 1) = '8')
        ");
        $stmt->execute([$societe_id, $annee]);
        $total_charges = $stmt->fetch()['total'];

        // Calculer produits
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(le.credit - le.debit), 0) as total
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.societe_id = ?
                AND e.statut = 'Validé'
                AND YEAR(e.date_ecriture) = ?
                AND LEFT(le.compte, 1) = '7'
        ");
        $stmt->execute([$societe_id, $annee]);
        $total_produits = $stmt->fetch()['total'];

        $resultat = $total_produits - $total_charges;

        // Mettre à jour le résultat
        $stmt = $db->prepare("UPDATE exercices SET resultat_calcule = ? WHERE id = ? AND societe_id = ?");
        $stmt->execute([$resultat, $exercice_id, $societe_id]);
    } else {
        $resultat = $exercice['resultat_calcule'];
    }

    // 3. CRÉER L'ÉCRITURE DE CLÔTURE
    // Cette écriture solde tous les comptes de résultat (6, 7, 8) vers 131 ou 139

    // Récupérer tous les soldes des comptes de résultat
    $stmt = $db->prepare("
        SELECT
            le.compte,
            SUM(le.debit) as total_debit,
            SUM(le.credit) as total_credit,
            (SUM(le.debit) - SUM(le.credit)) as solde
        FROM lignes_ecriture le
        INNER JOIN ecritures e ON le.id_ecriture = e.id
        WHERE e.societe_id = ?
            AND e.statut = 'Validé'
            AND YEAR(e.date_ecriture) = ?
            AND (LEFT(le.compte, 1) IN ('6', '7', '8'))
        GROUP BY le.compte
        HAVING ABS(solde) > 0.01
    ");
    $stmt->execute([$societe_id, $annee]);
    $comptes_resultat = $stmt->fetchAll();

    if (count($comptes_resultat) > 0) {
        // Créer l'écriture de clôture
        $stmt = $db->prepare("
            INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle, statut, createur)
            VALUES (?, ?, ?, ?, ?, 'OD', ?, 'Validé', ?)
        ");

        $numero = 'CLOT-' . $annee;
        $libelle = "Écriture de clôture exercice $annee - Solde des comptes de résultat";
        $mois = date('F', strtotime($date_cloture)); // Nom du mois
        $stmt->execute([$societe_id, $numero, $date_cloture, $mois, $annee, $libelle, 'admin']);
        $ecriture_cloture_id = $db->lastInsertId();

        // Ajouter les lignes pour solder chaque compte de résultat
        $stmt_ligne = $db->prepare("
            INSERT INTO lignes_ecriture (id_ecriture, compte, debit, credit)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($comptes_resultat as $compte) {
            $solde = $compte['solde'];

            if ($solde > 0) {
                // Compte débiteur → créditer pour solder
                $stmt_ligne->execute([$ecriture_cloture_id, $compte['compte'], 0, $solde]);
            } else {
                // Compte créditeur → débiter pour solder
                $stmt_ligne->execute([$ecriture_cloture_id, $compte['compte'], abs($solde), 0]);
            }
        }

        // Ajouter la contrepartie au compte 1310000 (bénéfice) ou 1390000 (perte)
        if ($resultat >= 0) {
            // Bénéfice → Compte 1310000 créditeur (Résultat net : Bénéfice)
            $stmt_ligne->execute([$ecriture_cloture_id, '1310000', 0, $resultat]);
        } else {
            // Perte → Compte 1390000 débiteur (Résultat net : Perte)
            $stmt_ligne->execute([$ecriture_cloture_id, '1390000', abs($resultat), 0]);
        }

        // Lier l'écriture de clôture à l'exercice
        $stmt = $db->prepare("UPDATE exercices SET ecriture_cloture_id = ? WHERE id = ? AND societe_id = ?");
        $stmt->execute([$ecriture_cloture_id, $exercice_id, $societe_id]);
    }

    // 4. CRÉER L'ÉCRITURE D'OUVERTURE DU PROCHAIN EXERCICE
    // Vérifier si le prochain exercice existe
    $stmt = $db->prepare("SELECT * FROM exercices WHERE annee = ? AND societe_id = ?");
    $stmt->execute([$annee + 1, $societe_id]);
    $exercice_suivant = $stmt->fetch();

    if ($exercice_suivant) {
        $date_ouverture = $exercice_suivant['date_debut'];

        // Récupérer les soldes de tous les comptes de bilan à la fin de l'exercice
        $stmt = $db->prepare("
            SELECT
                le.compte,
                SUM(le.debit) as total_debit,
                SUM(le.credit) as total_credit,
                (SUM(le.debit) - SUM(le.credit)) as solde
            FROM lignes_ecriture le
            INNER JOIN ecritures e ON le.id_ecriture = e.id
            WHERE e.societe_id = ?
                AND e.statut = 'Validé'
                AND e.date_ecriture <= ?
                AND (LEFT(le.compte, 1) IN ('1', '2', '3', '4', '5'))
            GROUP BY le.compte
            HAVING ABS(solde) > 0.01
        ");
        $stmt->execute([$societe_id, $date_cloture]);
        $comptes_bilan = $stmt->fetchAll();

        if (count($comptes_bilan) > 0) {
            // Créer l'écriture d'ouverture
            $stmt = $db->prepare("
                INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle, statut, createur)
                VALUES (?, ?, ?, ?, ?, 'OD', ?, 'Validé', ?)
            ");

            $numero = 'AN-' . ($annee + 1);
            $libelle = "À-nouveau exercice " . ($annee + 1) . " - Report des soldes de bilan";
            $mois_ouverture = date('F', strtotime($date_ouverture));
            $stmt->execute([$societe_id, $numero, $date_ouverture, $mois_ouverture, ($annee + 1), $libelle, 'admin']);
            $ecriture_ouverture_id = $db->lastInsertId();

            // Ajouter les lignes de l'écriture d'ouverture
            $stmt_ligne = $db->prepare("
                INSERT INTO lignes_ecriture (id_ecriture, compte, debit, credit)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($comptes_bilan as $compte) {
                $solde = $compte['solde'];

                if ($solde > 0) {
                    // Solde débiteur → débiter le compte
                    $stmt_ligne->execute([$ecriture_ouverture_id, $compte['compte'], $solde, 0]);
                } else {
                    // Solde créditeur → créditer le compte
                    $stmt_ligne->execute([$ecriture_ouverture_id, $compte['compte'], 0, abs($solde)]);
                }
            }

            // Note : L'écriture d'ouverture (À-nouveau) s'équilibre naturellement
            // car elle reprend les soldes réels du bilan de clôture.
            // Aucun compte de contrepartie n'est nécessaire selon le SYSCOHADA.

            // Lier l'écriture d'ouverture à l'exercice suivant
            $stmt = $db->prepare("UPDATE exercices SET ecriture_ouverture_id = ? WHERE id = ? AND societe_id = ?");
            $stmt->execute([$ecriture_ouverture_id, $exercice_suivant['id'], $societe_id]);
        }
    }

    // 5. Marquer l'exercice comme clôturé
    $stmt = $db->prepare("
        UPDATE exercices
        SET statut = 'Clôturé',
            date_cloture = NOW(),
            cloture_par = ?
        WHERE id = ? AND societe_id = ?
    ");
    $stmt->execute([$user_id, $exercice_id, $societe_id]);

    $db->commit();

    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => "Exercice $annee clôturé avec succès ! Écritures de clôture et d'ouverture générées automatiquement."
    ];

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => "Erreur lors de la clôture : " . $e->getMessage()
    ];
}

header('Location: exercices.php');
exit;
?>
