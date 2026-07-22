<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: rapprochement_bancaire.php');
    exit;
}

try {
    $compte_banque = $_POST['compte_banque'] ?? '';
    $mois = $_POST['mois'] ?? 0;
    $annee = $_POST['annee'] ?? 0;
    $date_debut = $_POST['date_debut'] ?? '';
    $date_fin = $_POST['date_fin'] ?? '';
    $solde_comptable = $_POST['solde_comptable'] ?? 0;
    $solde_bancaire = $_POST['solde_bancaire'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    $action = $_POST['action'] ?? 'enregistrer';
    $id_rapprochement = $_POST['id_rapprochement'] ?? null;
    $lignes = $_POST['lignes'] ?? [];

    // Calculer l'écart
    $ecart_calcule = $solde_comptable - $solde_bancaire;

    // Définir le statut
    $statut = ($action === 'valider') ? 'Validé' : 'En cours';

    $db->beginTransaction();

    if ($id_rapprochement) {
        // Mettre à jour le rapprochement existant
        $stmt = $db->prepare("
            UPDATE rapprochements_bancaires
            SET solde_comptable = ?,
                solde_bancaire = ?,
                ecart_calcule = ?,
                notes = ?,
                statut = ?,
                updated_at = NOW()
            WHERE id = ? AND societe_id = ?
        ");
        $stmt->execute([
            $solde_comptable,
            $solde_bancaire,
            $ecart_calcule,
            $notes,
            $statut,
            $id_rapprochement,
            $societe_id
        ]);

        // Supprimer les anciennes lignes
        $stmt = $db->prepare("DELETE FROM rapprochements_lignes WHERE id_rapprochement = ?");
        $stmt->execute([$id_rapprochement]);

        $message = "Rapprochement mis à jour avec succès";
    } else {
        // Créer un nouveau rapprochement
        $stmt = $db->prepare("
            INSERT INTO rapprochements_bancaires (
                compte_banque, mois, annee, date_debut, date_fin,
                solde_comptable, solde_bancaire, ecart_calcule,
                notes, statut, created_by, societe_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $compte_banque,
            $mois,
            $annee,
            $date_debut,
            $date_fin,
            $solde_comptable,
            $solde_bancaire,
            $ecart_calcule,
            $notes,
            $statut,
            $_SESSION['user_id'],
            $societe_id
        ]);

        $id_rapprochement = $db->lastInsertId();
        $message = "Rapprochement créé avec succès";
    }

    // Insérer les nouvelles lignes
    if (!empty($lignes)) {
        $stmt = $db->prepare("
            INSERT INTO rapprochements_lignes (
                id_rapprochement, date_operation, libelle,
                type_operation, montant, categorie
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($lignes as $ligne) {
            if (empty($ligne['libelle']) || empty($ligne['montant'])) {
                continue;
            }

            $stmt->execute([
                $id_rapprochement,
                $ligne['date_operation'],
                $ligne['libelle'],
                $ligne['type_operation'],
                $ligne['montant'],
                $ligne['categorie'] ?? 'Autre'
            ]);
        }
    }

    // Enregistrer dans l'historique
    $stmt = $db->prepare("
        INSERT INTO rapprochements_historique (id_rapprochement, action, user_id, details)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $id_rapprochement,
        $action === 'valider' ? 'Validation' : 'Enregistrement',
        $_SESSION['user_id'],
        "Solde comptable: $solde_comptable, Solde bancaire: $solde_bancaire, Écart: $ecart_calcule"
    ]);

    $db->commit();

    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => $message . ($action === 'valider' ? ' et validé' : '')
    ];

    header("Location: rapprochement_bancaire.php?compte=$compte_banque&mois=$mois&annee=$annee");
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Erreur lors de l\'enregistrement: ' . $e->getMessage()
    ];
    header('Location: rapprochement_bancaire.php');
    exit;
}
