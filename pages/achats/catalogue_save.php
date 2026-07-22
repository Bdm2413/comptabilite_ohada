<?php
/**
 * Sauvegarde d'un article du catalogue fournisseurs
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { echo json_encode(['success' => false, 'message' => 'Aucune société sélectionnée']); exit; }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $id_fournisseur = (int)($data['id_fournisseur'] ?? 0);
    $reference = trim($data['reference'] ?? '');
    $designation = trim($data['designation'] ?? '');
    $description = trim($data['description'] ?? '');
    $unite = trim($data['unite'] ?? 'Unité');
    $prix_unitaire = (float)($data['prix_unitaire'] ?? 0);
    $type_article = $data['type_article'] ?? 'Bien';
    $type_taxe = $data['type_taxe'] ?? 'Aucune';
    $taux_taxe = (float)($data['taux_taxe'] ?? 0);
    $actif = isset($data['actif']) ? (int)$data['actif'] : 1;

    // Validation
    if (empty($id_fournisseur)) {
        throw new Exception('Veuillez sélectionner un fournisseur');
    }

    if (empty($reference)) {
        throw new Exception('La référence est obligatoire');
    }

    if (empty($designation)) {
        throw new Exception('La désignation est obligatoire');
    }

    if ($prix_unitaire < 0) {
        throw new Exception('Le prix unitaire ne peut pas être négatif');
    }

    // Vérifier l'unicité de la référence pour ce fournisseur
    $stmt = $db->prepare("
        SELECT id FROM catalogues_fournisseurs
        WHERE id_fournisseur = ? AND reference = ? AND id != ? AND societe_id = ?
    ");
    $stmt->execute([$id_fournisseur, $reference, $id, $societe_id]);
    if ($stmt->fetch()) {
        throw new Exception('Cette référence existe déjà pour ce fournisseur');
    }

    // Ajuster le taux de taxe selon le type
    if ($type_taxe == 'TVA') {
        $taux_taxe = 18;
    } elseif ($type_taxe == 'PPSSI') {
        $taux_taxe = 2;
    } elseif ($type_taxe == 'BNC') {
        $taux_taxe = 7.5;
    } else {
        $taux_taxe = 0;
    }

    if ($id > 0) {
        // Mise à jour
        $stmt = $db->prepare("
            UPDATE catalogues_fournisseurs SET
                id_fournisseur = ?,
                reference = ?,
                designation = ?,
                description = ?,
                unite = ?,
                prix_unitaire = ?,
                type_article = ?,
                type_taxe = ?,
                taux_taxe = ?,
                actif = ?
            WHERE id = ? AND societe_id = ?
        ");
        $stmt->execute([
            $id_fournisseur, $reference, $designation, $description,
            $unite, $prix_unitaire, $type_article, $type_taxe, $taux_taxe,
            $actif, $id, $societe_id
        ]);
        $message = 'Article mis à jour avec succès';
    } else {
        // Création
        $stmt = $db->prepare("
            INSERT INTO catalogues_fournisseurs
            (id_fournisseur, reference, designation, description, unite,
             prix_unitaire, type_article, type_taxe, taux_taxe, actif, societe_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id_fournisseur, $reference, $designation, $description,
            $unite, $prix_unitaire, $type_article, $type_taxe, $taux_taxe,
            $actif, $societe_id
        ]);
        $id = $db->lastInsertId();
        $message = 'Article créé avec succès';
    }

    echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
