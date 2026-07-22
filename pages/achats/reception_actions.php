<?php
/**
 * Actions sur les réceptions BC (supprimer, comptabiliser)
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
        case 'supprimer':
            supprimerReception($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Réception supprimée']);
            break;

        case 'comptabiliser':
            comptabiliserReception($db, $id, $societe_id);
            echo json_encode(['success' => true, 'message' => 'Réception comptabilisée']);
            break;

        default:
            throw new Exception('Action non reconnue');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Supprime une réception et ajuste les compteurs du BC
 */
function supprimerReception($db, $id, $societe_id) {
    $db->beginTransaction();

    try {
        // Vérifier que la réception existe et n'est pas comptabilisée
        // Filtre via le BC associé
        $stmt = $db->prepare("
            SELECT r.* FROM receptions_bc r
            JOIN bons_commande bc ON r.id_bon_commande = bc.id AND bc.societe_id = ?
            WHERE r.id = ?
        ");
        $stmt->execute([$societe_id, $id]);
        $reception = $stmt->fetch();

        if (!$reception) {
            throw new Exception('Réception introuvable');
        }

        if ($reception['id_ecriture']) {
            throw new Exception('Cette réception est déjà comptabilisée. Supprimez d\'abord l\'écriture associée.');
        }

        // Récupérer les lignes pour ajuster les compteurs BC
        $stmt = $db->prepare("SELECT * FROM lignes_reception_bc WHERE id_reception = ?");
        $stmt->execute([$id]);
        $lignes = $stmt->fetchAll();

        foreach ($lignes as $ligne) {
            $stmt = $db->prepare("
                UPDATE lignes_bon_commande
                SET quantite_recue = quantite_recue - ?,
                    montant_facture = montant_facture - ?
                WHERE id = ?
            ");
            $stmt->execute([$ligne['quantite_recue'], $ligne['montant_facture'], $ligne['id_ligne_bc']]);
        }

        // Supprimer les lignes de réception
        $stmt = $db->prepare("DELETE FROM lignes_reception_bc WHERE id_reception = ?");
        $stmt->execute([$id]);

        // Supprimer la réception
        $stmt = $db->prepare("DELETE FROM receptions_bc WHERE id = ?");
        $stmt->execute([$id]);

        // Mettre à jour le statut du BC
        updateStatutBC($db, $reception['id_bon_commande']);

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Comptabilise une réception (crée l'écriture comptable)
 */
function comptabiliserReception($db, $id, $societe_id) {
    $db->beginTransaction();

    try {
        // Récupérer la réception avec les infos du BC et fournisseur
        $stmt = $db->prepare("
            SELECT r.*, bc.numero_bc, bc.objet as bc_objet,
                   f.nom_fournisseur, f.compte_fournisseur, f.compte_charge
            FROM receptions_bc r
            JOIN bons_commande bc ON r.id_bon_commande = bc.id AND bc.societe_id = ?
            JOIN fournisseurs f ON bc.id_fournisseur = f.id
            WHERE r.id = ?
        ");
        $stmt->execute([$societe_id, $id]);
        $reception = $stmt->fetch();

        if (!$reception) {
            throw new Exception('Réception introuvable');
        }

        if ($reception['id_ecriture']) {
            throw new Exception('Cette réception est déjà comptabilisée');
        }

        // Vérifier qu'il s'agit bien d'une facture pour comptabiliser
        if ($reception['type_reception'] !== 'Facture') {
            throw new Exception('Seules les factures peuvent être comptabilisées (pas les livraisons)');
        }

        // Récupérer les lignes de réception avec les infos de taxe
        $stmt = $db->prepare("
            SELECT lr.*, lbc.designation, lbc.type_taxe, lbc.taux_taxe,
                   lbc.prix_unitaire
            FROM lignes_reception_bc lr
            JOIN lignes_bon_commande lbc ON lr.id_ligne_bc = lbc.id
            WHERE lr.id_reception = ?
        ");
        $stmt->execute([$id]);
        $lignes = $stmt->fetchAll();

        // Comptes par défaut si non définis sur le fournisseur
        $compte_fournisseur = $reception['compte_fournisseur'] ?: '401100';
        $compte_charge = $reception['compte_charge'] ?: '601100';
        $compte_tva = '445620'; // TVA déductible sur achats
        $compte_retenue_ppssi = '447100'; // Retenue PPSSI
        $compte_retenue_bnc = '447200'; // Retenue BNC

        // Créer l'écriture
        $libelle = sprintf('Facture %s - %s - BC %s',
            $reception['numero_document'] ?: $reception['numero_reception'],
            $reception['nom_fournisseur'],
            $reception['numero_bc']
        );

        $stmt = $db->prepare("
            INSERT INTO ecritures (date_ecriture, libelle, reference, statut)
            VALUES (?, ?, ?, 'Brouillon')
        ");
        $stmt->execute([
            $reception['date_reception'],
            $libelle,
            $reception['numero_document'] ?: $reception['numero_reception']
        ]);
        $id_ecriture = $db->lastInsertId();

        // Préparer les lignes d'écriture
        $stmt_ligne = $db->prepare("
            INSERT INTO lignes_ecriture (id_ecriture, compte, libelle, debit, credit, numero_facture)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $total_ht = 0;
        $total_tva = 0;
        $total_ppssi = 0;
        $total_bnc = 0;

        // Calculer les totaux par type de taxe
        foreach ($lignes as $ligne) {
            $montant_ligne = $ligne['montant_facture'];
            $total_ht += $montant_ligne;

            if ($ligne['type_taxe'] == 'TVA') {
                $total_tva += $montant_ligne * ($ligne['taux_taxe'] / 100);
            } elseif ($ligne['type_taxe'] == 'PPSSI') {
                $total_ppssi += $montant_ligne * ($ligne['taux_taxe'] / 100);
            } elseif ($ligne['type_taxe'] == 'BNC') {
                $total_bnc += $montant_ligne * ($ligne['taux_taxe'] / 100);
            }
        }

        // 1. Compte de charge (Débit)
        if ($total_ht > 0) {
            $stmt_ligne->execute([
                $id_ecriture,
                $compte_charge,
                $libelle,
                $total_ht,
                0,
                $reception['numero_document']
            ]);
        }

        // 2. TVA déductible (Débit)
        if ($total_tva > 0) {
            $stmt_ligne->execute([
                $id_ecriture,
                $compte_tva,
                'TVA déductible - ' . $reception['numero_document'],
                $total_tva,
                0,
                $reception['numero_document']
            ]);
        }

        // 3. Retenue PPSSI (Crédit)
        if ($total_ppssi > 0) {
            $stmt_ligne->execute([
                $id_ecriture,
                $compte_retenue_ppssi,
                'Retenue PPSSI - ' . $reception['numero_document'],
                0,
                $total_ppssi,
                $reception['numero_document']
            ]);
        }

        // 4. Retenue BNC (Crédit)
        if ($total_bnc > 0) {
            $stmt_ligne->execute([
                $id_ecriture,
                $compte_retenue_bnc,
                'Retenue BNC - ' . $reception['numero_document'],
                0,
                $total_bnc,
                $reception['numero_document']
            ]);
        }

        // 5. Compte fournisseur (Crédit pour le net à payer)
        $net_a_payer = $total_ht + $total_tva - $total_ppssi - $total_bnc;
        $stmt_ligne->execute([
            $id_ecriture,
            $compte_fournisseur,
            $reception['nom_fournisseur'],
            0,
            $net_a_payer,
            $reception['numero_document']
        ]);

        // Lier l'écriture à la réception
        $stmt = $db->prepare("UPDATE receptions_bc SET id_ecriture = ? WHERE id = ?");
        $stmt->execute([$id_ecriture, $id]);

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Met à jour le statut du BC en fonction des réceptions
 */
function updateStatutBC($db, $bc_id) {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as nb_lignes,
            SUM(CASE WHEN type_suivi = 'Quantité' AND quantite_recue >= quantite_commandee THEN 1
                     WHEN type_suivi = 'Montant' AND montant_facture >= montant_commandee THEN 1
                     ELSE 0 END) as lignes_completes,
            SUM(CASE WHEN quantite_recue > 0 OR montant_facture > 0 THEN 1 ELSE 0 END) as lignes_partielles
        FROM lignes_bon_commande
        WHERE id_bon_commande = ?
    ");
    $stmt->execute([$bc_id]);
    $stats = $stmt->fetch();

    $nouveau_statut = 'Validé';

    if ($stats['lignes_completes'] == $stats['nb_lignes']) {
        $nouveau_statut = 'Soldé';
    } elseif ($stats['lignes_partielles'] > 0) {
        $nouveau_statut = 'Partiellement reçu';
    }

    $stmt = $db->prepare("UPDATE bons_commande SET statut = ? WHERE id = ?");
    $stmt->execute([$nouveau_statut, $bc_id]);
}
