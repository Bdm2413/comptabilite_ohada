<?php
/**
 * Sauvegarde d'un bon de commande
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { echo json_encode(['success' => false, 'message' => 'Aucune société sélectionnée']); exit; }

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
    header('Location: bons_commande.php');
    exit;
}

try {
    $db->beginTransaction();

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $id_fournisseur = (int)$_POST['id_fournisseur'];
    $numero_bc = trim($_POST['numero_bc']);
    $date_bc = $_POST['date_bc'];
    $objet = trim($_POST['objet'] ?? '');
    $observations = trim($_POST['observations'] ?? '');
    $type_suivi = $_POST['type_suivi'] ?? 'Quantité';
    $seuil_alerte = (int)($_POST['seuil_alerte'] ?? 80);
    $lignes = $_POST['lignes'] ?? [];

    // Validation
    if (empty($id_fournisseur)) {
        throw new Exception('Veuillez sélectionner un fournisseur');
    }

    if (empty($numero_bc)) {
        throw new Exception('Le numéro de BC est obligatoire');
    }

    if (empty($lignes)) {
        throw new Exception('Veuillez ajouter au moins une ligne');
    }

    // Vérifier l'unicité du numéro
    $stmt = $db->prepare("SELECT id FROM bons_commande WHERE numero_bc = ? AND id != ? AND societe_id = ?");
    $stmt->execute([$numero_bc, $id, $societe_id]);
    if ($stmt->fetch()) {
        throw new Exception('Ce numéro de BC existe déjà');
    }

    // Calculer les totaux
    $montant_total_ht = (float)$_POST['montant_total_ht'];
    $montant_tva = (float)$_POST['montant_tva'];
    $montant_retenue = (float)$_POST['montant_retenue'];
    $net_a_payer = (float)$_POST['net_a_payer'];

    if ($id > 0) {
        // Vérifier le statut
        $stmt = $db->prepare("SELECT statut FROM bons_commande WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id, $societe_id]);
        $bc = $stmt->fetch();

        if ($bc && !in_array($bc['statut'], ['Brouillon', 'Validé'])) {
            throw new Exception('Ce bon de commande ne peut plus être modifié');
        }

        // Mise à jour
        $stmt = $db->prepare("
            UPDATE bons_commande SET
                id_fournisseur = ?,
                numero_bc = ?,
                date_bc = ?,
                objet = ?,
                observations = ?,
                type_suivi = ?,
                seuil_alerte = ?,
                montant_total_ht = ?,
                montant_tva = ?,
                montant_retenue = ?,
                net_a_payer = ?
            WHERE id = ? AND societe_id = ?
        ");
        $stmt->execute([
            $id_fournisseur, $numero_bc, $date_bc, $objet, $observations,
            $type_suivi, $seuil_alerte, $montant_total_ht, $montant_tva,
            $montant_retenue, $net_a_payer, $id, $societe_id
        ]);

        // Supprimer les anciennes lignes (seulement si pas de réceptions)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM lignes_reception_bc lr
            JOIN lignes_bon_commande lbc ON lr.id_ligne_bc = lbc.id
            WHERE lbc.id_bon_commande = ?
        ");
        $stmt->execute([$id]);
        if ($stmt->fetch()['count'] > 0) {
            throw new Exception('Ce BC a des réceptions, les lignes ne peuvent pas être modifiées');
        }

        $stmt = $db->prepare("DELETE FROM lignes_bon_commande WHERE id_bon_commande = ?");
        $stmt->execute([$id]);
    } else {
        // Création
        $stmt = $db->prepare("
            INSERT INTO bons_commande
            (societe_id, id_fournisseur, numero_bc, date_bc, objet, observations,
             statut, type_suivi, seuil_alerte, montant_total_ht, montant_tva,
             montant_retenue, net_a_payer)
            VALUES (?, ?, ?, ?, ?, ?, 'Brouillon', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $societe_id, $id_fournisseur, $numero_bc, $date_bc, $objet, $observations,
            $type_suivi, $seuil_alerte, $montant_total_ht, $montant_tva,
            $montant_retenue, $net_a_payer
        ]);
        $id = $db->lastInsertId();
    }

    // Insérer les lignes
    $stmt_ligne = $db->prepare("
        INSERT INTO lignes_bon_commande
        (id_bon_commande, id_article_catalogue, designation, description, unite,
         quantite_commandee, montant_commandee, prix_unitaire, type_remise, valeur_remise,
         type_taxe, taux_taxe, type_suivi)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($lignes as $ligne) {
        // Calculer le montant HT de la ligne
        $quantite = parseNumber($ligne['quantite'] ?? 1);
        $prix_unitaire = parseNumber($ligne['prix_unitaire_ht'] ?? 0);
        $montant_brut = $quantite * $prix_unitaire;
        $montant_remise = 0;
        $type_remise = $ligne['type_remise'] ?? 'Aucune';
        $valeur_remise = parseNumber($ligne['valeur_remise'] ?? 0);

        if ($type_remise == 'Pourcentage') {
            $montant_remise = $montant_brut * ($valeur_remise / 100);
        } elseif ($type_remise == 'Montant') {
            $montant_remise = $valeur_remise;
        }
        $montant_ht = $montant_brut - $montant_remise;

        $stmt_ligne->execute([
            $id,
            !empty($ligne['id_article']) ? $ligne['id_article'] : null,
            $ligne['designation'],
            $ligne['description'] ?? '',
            $ligne['unite'] ?? 'Unité',
            $quantite,
            $montant_ht,
            $prix_unitaire,
            $type_remise,
            $valeur_remise,
            $ligne['type_taxe'] ?? 'Aucune',
            parseNumber($ligne['taux_taxe'] ?? 0),
            $ligne['type_suivi'] ?? $type_suivi
        ]);
    }

    $db->commit();

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bon de commande enregistré avec succès'];
    header('Location: bons_commande.php');
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    header('Location: bc_form.php' . ($id > 0 ? '?id=' . $id : ''));
    exit;
}
