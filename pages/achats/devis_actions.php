<?php
/**
 * Actions sur les devis fournisseurs (approuver, rejeter, supprimer, créer BC)
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { echo json_encode(['success' => false, 'message' => 'Aucune société sélectionnée']); exit; }

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$id = (int)($data['id'] ?? 0);

try {
    switch ($action) {
        case 'approuver':
            approuverDevis($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Devis approuvé']);
            break;

        case 'rejeter':
            rejeterDevis($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Devis rejeté']);
            break;

        case 'supprimer':
            supprimerDevis($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Devis supprimé']);
            break;

        case 'creer_bc':
            $bc_id = creerBCDepuisDevis($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Bon de commande créé', 'bc_id' => $bc_id]);
            break;

        default:
            throw new Exception('Action non reconnue');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function approuverDevis($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM devis_fournisseurs WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $devis = $stmt->fetch();

    if (!$devis) {
        throw new Exception('Devis introuvable');
    }

    if ($devis['statut'] != 'Brouillon') {
        throw new Exception('Seuls les devis en brouillon peuvent être approuvés');
    }

    $stmt = $db->prepare("UPDATE devis_fournisseurs SET statut = 'Approuvé', date_approbation = CURDATE() WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
}

function rejeterDevis($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM devis_fournisseurs WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $devis = $stmt->fetch();

    if (!$devis) {
        throw new Exception('Devis introuvable');
    }

    if ($devis['statut'] != 'Brouillon') {
        throw new Exception('Seuls les devis en brouillon peuvent être rejetés');
    }

    $stmt = $db->prepare("UPDATE devis_fournisseurs SET statut = 'Rejeté' WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
}

function supprimerDevis($db, $id, $societe_id) {
    $stmt = $db->prepare("SELECT statut FROM devis_fournisseurs WHERE id = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    $devis = $stmt->fetch();

    if (!$devis) {
        throw new Exception('Devis introuvable');
    }

    if ($devis['statut'] != 'Brouillon' && $devis['statut'] != 'Rejeté') {
        throw new Exception('Seuls les devis en brouillon ou rejetés peuvent être supprimés');
    }

    // Vérifier si un BC a été créé à partir de ce devis
    $stmt = $db->prepare("SELECT id FROM bons_commande WHERE id_devis = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    if ($stmt->fetch()) {
        throw new Exception('Un bon de commande a été créé à partir de ce devis');
    }

    $db->beginTransaction();
    try {
        // Supprimer les lignes
        $stmt = $db->prepare("DELETE FROM lignes_devis WHERE id_devis = ?");
        $stmt->execute([$id]);

        // Supprimer le devis
        $stmt = $db->prepare("DELETE FROM devis_fournisseurs WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id, $societe_id]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function creerBCDepuisDevis($db, $id, $societe_id) {
    $stmt = $db->prepare("
        SELECT d.*, f.nom_fournisseur
        FROM devis_fournisseurs d
        JOIN fournisseurs f ON d.id_fournisseur = f.id
        WHERE d.id = ? AND d.societe_id = ?
    ");
    $stmt->execute([$id, $societe_id]);
    $devis = $stmt->fetch();

    if (!$devis) {
        throw new Exception('Devis introuvable');
    }

    if ($devis['statut'] != 'Approuvé') {
        throw new Exception('Seuls les devis approuvés peuvent être convertis en bon de commande');
    }

    // Vérifier si un BC existe déjà pour ce devis
    $stmt = $db->prepare("SELECT id FROM bons_commande WHERE id_devis = ? AND societe_id = ?");
    $stmt->execute([$id, $societe_id]);
    if ($stmt->fetch()) {
        throw new Exception('Un bon de commande existe déjà pour ce devis');
    }

    $db->beginTransaction();
    try {
        // Générer un numéro de BC
        $annee = date('Y');
        $stmt_num = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(numero_bc, '-', -1) AS UNSIGNED)) as max_num FROM bons_commande WHERE numero_bc LIKE 'BC-$annee-%'");
        $max = $stmt_num->fetch()['max_num'] ?? 0;
        $numero_bc = sprintf('BC-%s-%04d', $annee, $max + 1);

        $stmt = $db->prepare("
            INSERT INTO bons_commande
            (id_fournisseur, id_devis, numero_bc, date_bc, objet, observations,
             statut, type_suivi, seuil_alerte, montant_total_ht, montant_tva,
             montant_retenue, net_a_payer, societe_id)
            VALUES (?, ?, ?, CURDATE(), ?, ?, 'Brouillon', 'Quantité', 80, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $devis['id_fournisseur'],
            $id,
            $numero_bc,
            $devis['objet'],
            'Créé à partir du devis ' . $devis['numero_devis'],
            $devis['montant_total_ht'],
            $devis['montant_tva'],
            $devis['montant_retenue'],
            $devis['net_a_payer'],
            $societe_id
        ]);
        $bc_id = $db->lastInsertId();

        // Copier les lignes du devis vers le BC
        $stmt = $db->prepare("SELECT * FROM lignes_devis WHERE id_devis = ?");
        $stmt->execute([$id]);
        $lignes = $stmt->fetchAll();

        $stmt_ligne = $db->prepare("
            INSERT INTO lignes_bon_commande
            (id_bon_commande, id_article_catalogue, designation, description, unite,
             quantite_commandee, montant_commandee, prix_unitaire, type_remise, valeur_remise,
             type_taxe, taux_taxe, type_suivi)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Quantité')
        ");

        foreach ($lignes as $ligne) {
            // Calculer le montant HT de la ligne
            $montant_brut = $ligne['quantite'] * $ligne['prix_unitaire'];
            $montant_remise = 0;
            if ($ligne['type_remise'] == 'Pourcentage') {
                $montant_remise = $montant_brut * ($ligne['valeur_remise'] / 100);
            } elseif ($ligne['type_remise'] == 'Montant') {
                $montant_remise = $ligne['valeur_remise'];
            }
            $montant_ht = $montant_brut - $montant_remise;

            $stmt_ligne->execute([
                $bc_id,
                $ligne['id_article_catalogue'],
                $ligne['designation'],
                $ligne['description'],
                $ligne['unite'],
                $ligne['quantite'],
                $montant_ht,
                $ligne['prix_unitaire'],
                $ligne['type_remise'],
                $ligne['valeur_remise'],
                $ligne['type_taxe'],
                $ligne['taux_taxe']
            ]);
        }

        // Mettre à jour le statut du devis
        $stmt = $db->prepare("UPDATE devis_fournisseurs SET statut = 'Converti' WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id, $societe_id]);

        $db->commit();
        return $bc_id;

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
