<?php
/**
 * Sauvegarde d'un devis fournisseur
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

// Fonction pour parser les nombres au format français
function parseNumber($value) {
    if (empty($value)) return 0;
    // Supprimer les espaces (séparateurs de milliers)
    $value = str_replace([' ', ' ', "\xc2\xa0"], '', $value);
    // Remplacer la virgule par un point (séparateur décimal)
    $value = str_replace(',', '.', $value);
    return floatval($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: devis.php');
    exit;
}

try {
    $db->beginTransaction();

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $id_fournisseur = (int)$_POST['id_fournisseur'];
    $numero_devis = trim($_POST['numero_devis']);
    $date_devis = $_POST['date_devis'];
    $date_validite = !empty($_POST['date_validite']) ? $_POST['date_validite'] : null;
    $objet = trim($_POST['objet'] ?? '');
    $observations = trim($_POST['observations'] ?? '');
    $lignes = $_POST['lignes'] ?? [];

    // Validation
    if (empty($id_fournisseur)) {
        throw new Exception('Veuillez sélectionner un fournisseur');
    }

    if (empty($numero_devis)) {
        throw new Exception('Le numéro de devis est obligatoire');
    }

    if (empty($lignes)) {
        throw new Exception('Veuillez ajouter au moins une ligne');
    }

    // Vérifier l'unicité du numéro
    $stmt = $db->prepare("SELECT id FROM devis_fournisseurs WHERE numero_devis = ? AND id != ? AND societe_id = ?");
    $stmt->execute([$numero_devis, $id, $societe_id]);
    if ($stmt->fetch()) {
        throw new Exception('Ce numéro de devis existe déjà');
    }

    // Calculer les totaux
    $montant_total_ht = (float)$_POST['montant_total_ht'];
    $montant_tva = (float)$_POST['montant_tva'];
    $montant_retenue = (float)$_POST['montant_retenue'];
    $net_a_payer = (float)$_POST['net_a_payer'];

    if ($id > 0) {
        // Vérifier le statut (on ne peut modifier qu'un brouillon)
        $stmt = $db->prepare("SELECT statut FROM devis_fournisseurs WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id, $societe_id]);
        $devis = $stmt->fetch();

        if ($devis && $devis['statut'] != 'Brouillon') {
            throw new Exception('Seuls les devis en brouillon peuvent être modifiés');
        }

        // Mise à jour
        $stmt = $db->prepare("
            UPDATE devis_fournisseurs SET
                id_fournisseur = ?,
                numero_devis = ?,
                date_devis = ?,
                date_validite = ?,
                objet = ?,
                observations = ?,
                montant_total_ht = ?,
                montant_tva = ?,
                montant_retenue = ?,
                net_a_payer = ?
            WHERE id = ? AND societe_id = ?
        ");
        $stmt->execute([
            $id_fournisseur, $numero_devis, $date_devis, $date_validite,
            $objet, $observations, $montant_total_ht, $montant_tva,
            $montant_retenue, $net_a_payer, $id, $societe_id
        ]);

        // Supprimer les anciennes lignes
        $stmt = $db->prepare("DELETE FROM lignes_devis WHERE id_devis = ?");
        $stmt->execute([$id]);
    } else {
        // Création
        $stmt = $db->prepare("
            INSERT INTO devis_fournisseurs
            (id_fournisseur, numero_devis, date_devis, date_validite,
             objet, observations, statut, montant_total_ht, montant_tva,
             montant_retenue, net_a_payer, societe_id)
            VALUES (?, ?, ?, ?, ?, ?, 'Brouillon', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id_fournisseur, $numero_devis, $date_devis, $date_validite,
            $objet, $observations, $montant_total_ht, $montant_tva,
            $montant_retenue, $net_a_payer, $societe_id
        ]);
        $id = $db->lastInsertId();
    }

    // Insérer les lignes
    $stmt_ligne = $db->prepare("
        INSERT INTO lignes_devis
        (id_devis, id_article_catalogue, designation, description, unite,
         quantite, prix_unitaire, type_remise, valeur_remise, type_taxe, taux_taxe)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($lignes as $ligne) {
        $stmt_ligne->execute([
            $id,
            !empty($ligne['id_article']) ? $ligne['id_article'] : null,
            $ligne['designation'],
            $ligne['description'] ?? '',
            $ligne['unite'] ?? 'Unité',
            parseNumber($ligne['quantite'] ?? 1),
            parseNumber($ligne['prix_unitaire_ht'] ?? 0),
            $ligne['type_remise'] ?? 'Aucune',
            parseNumber($ligne['valeur_remise'] ?? 0),
            $ligne['type_taxe'] ?? 'Aucune',
            parseNumber($ligne['taux_taxe'] ?? 0)
        ]);
    }

    $db->commit();

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Devis enregistré avec succès'];
    header('Location: devis.php');
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    header('Location: devis_form.php' . ($id > 0 ? '?id=' . $id : ''));
    exit;
}
