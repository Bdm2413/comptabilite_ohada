<?php
/**
 * Script de traitement de la reprise des soldes
 * Génère l'écriture d'ouverture à partir des soldes saisis ou importés
 */

require_once '../../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reprise_soldes.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: Aucune société sélectionnée'];
    header('Location: reprise_soldes.php');
    exit;
}

$mode = $_POST['mode'] ?? '';
$exercice_id = intval($_POST['exercice_id'] ?? 0);
$user_id = $_SESSION['user_id'];

try {
    $db->beginTransaction();

    // Récupérer les informations de l'exercice
    $stmt = $db->prepare("SELECT * FROM exercices WHERE id = ? AND societe_id = ?");
    $stmt->execute([$exercice_id, $societe_id]);
    $exercice = $stmt->fetch();

    if (!$exercice) {
        throw new Exception("Exercice introuvable");
    }

    // Si l'exercice a déjà une écriture d'ouverture, la supprimer d'abord
    if ($exercice['ecriture_ouverture_id'] !== null) {
        $ancienne_ecriture_id = $exercice['ecriture_ouverture_id'];

        // Supprimer les lignes de l'ancienne écriture
        $stmt = $db->prepare("DELETE FROM lignes_ecriture WHERE id_ecriture = ?");
        $stmt->execute([$ancienne_ecriture_id]);

        // Supprimer l'ancienne écriture
        $stmt = $db->prepare("DELETE FROM ecritures WHERE id = ? AND societe_id = ?");
        $stmt->execute([$ancienne_ecriture_id, $societe_id]);

        // Détacher l'écriture de l'exercice
        $stmt = $db->prepare("UPDATE exercices SET ecriture_ouverture_id = NULL WHERE id = ?");
        $stmt->execute([$exercice_id]);
    }

    // Récupérer les soldes selon le mode
    $soldes = [];

    if ($mode === 'saisie_manuelle') {
        // Mode saisie manuelle
        $debits = $_POST['debit'] ?? [];
        $credits = $_POST['credit'] ?? [];

        foreach ($debits as $compte => $montant_debit) {
            $montant_debit = floatval($montant_debit);
            $montant_credit = floatval($credits[$compte] ?? 0);

            if ($montant_debit > 0 || $montant_credit > 0) {
                // Vérifier que le compte existe
                $stmt = $db->prepare("SELECT compte FROM plan_comptable WHERE compte = ? AND societe_id = ?");
                $stmt->execute([$compte, $societe_id]);
                if (!$stmt->fetch()) {
                    throw new Exception("Le compte $compte n'existe pas dans le plan comptable");
                }

                $soldes[] = [
                    'compte' => $compte,
                    'debit' => $montant_debit,
                    'credit' => $montant_credit
                ];
            }
        }

    } elseif ($mode === 'import_excel') {
        // Mode import Excel
        $data_excel = $_POST['data_excel'] ?? '';
        if (empty($data_excel)) {
            throw new Exception("Aucune donnée Excel fournie");
        }

        $soldes = json_decode($data_excel, true);
        if (!is_array($soldes)) {
            throw new Exception("Format de données Excel invalide");
        }

        // Vérifier que tous les comptes existent
        foreach ($soldes as $s) {
            $stmt = $db->prepare("SELECT compte FROM plan_comptable WHERE compte = ? AND societe_id = ?");
            $stmt->execute([$s['compte'], $societe_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Le compte " . $s['compte'] . " n'existe pas dans le plan comptable");
            }
        }

    } else {
        throw new Exception("Mode de saisie invalide");
    }

    // Vérifier qu'il y a au moins un solde
    if (empty($soldes)) {
        throw new Exception("Aucun solde à reprendre. Veuillez saisir au moins un solde.");
    }

    // Calculer les totaux et vérifier l'équilibre
    $total_debit = 0;
    $total_credit = 0;

    foreach ($soldes as $s) {
        $total_debit += floatval($s['debit']);
        $total_credit += floatval($s['credit']);
    }

    $difference = abs($total_debit - $total_credit);
    if ($difference > 0.01) {
        throw new Exception("L'écriture n'est pas équilibrée. Débit: " . number_format($total_debit, 2, ',', ' ') . " - Crédit: " . number_format($total_credit, 2, ',', ' ') . " - Différence: " . number_format($difference, 2, ',', ' '));
    }

    // Créer l'écriture de reprise
    $annee = $exercice['annee'];
    $date_ouverture = $exercice['date_debut'];
    $numero = 'REP-' . $annee;
    $libelle = "Reprise des soldes d'ouverture - Exercice $annee";
    $mois = date('F', strtotime($date_ouverture));

    $stmt = $db->prepare("
        INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle, statut, createur)
        VALUES (?, ?, ?, ?, ?, 'OD', ?, 'Validé', ?)
    ");
    $stmt->execute([$societe_id, $numero, $date_ouverture, $mois, $annee, $libelle, 'admin']);
    $ecriture_id = $db->lastInsertId();

    // Ajouter les lignes de l'écriture
    $stmt_ligne = $db->prepare("
        INSERT INTO lignes_ecriture (id_ecriture, compte, debit, credit)
        VALUES (?, ?, ?, ?)
    ");

    $nb_lignes = 0;
    foreach ($soldes as $s) {
        $debit = floatval($s['debit']);
        $credit = floatval($s['credit']);

        if ($debit > 0 || $credit > 0) {
            $stmt_ligne->execute([$ecriture_id, $s['compte'], $debit, $credit]);
            $nb_lignes++;
        }
    }

    if ($nb_lignes === 0) {
        throw new Exception("Aucune ligne d'écriture créée");
    }

    // Lier l'écriture à l'exercice
    $stmt = $db->prepare("UPDATE exercices SET ecriture_ouverture_id = ? WHERE id = ? AND societe_id = ?");
    $stmt->execute([$ecriture_id, $exercice_id, $societe_id]);

    $db->commit();

    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => "Reprise des soldes effectuée avec succès ! Écriture $numero créée avec $nb_lignes lignes. Total: " . number_format($total_debit, 0, ',', ' ') . " F CFA"
    ];

    header('Location: exercices.php');
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Erreur lors de la reprise des soldes : ' . $e->getMessage()
    ];
    header('Location: reprise_soldes.php?exercice_id=' . $exercice_id);
    exit;
}
?>
