<?php
require_once '../../config/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    // Récupérer la société courante
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) {
        throw new Exception("Aucune société sélectionnée");
    }

    // Récupérer l'ID de l'écriture à extourner
    $id_ecriture = isset($_POST['id_ecriture']) ? (int)$_POST['id_ecriture'] : 0;

    if ($id_ecriture <= 0) {
        throw new Exception("ID d'écriture invalide");
    }

    // Récupérer l'écriture à extourner
    $stmt = $db->prepare("
        SELECT e.*, ex.date_debut, ex.date_fin, ex.statut as statut_exercice
        FROM ecritures e
        LEFT JOIN exercices ex ON e.annee = ex.annee AND ex.societe_id = e.societe_id
        WHERE e.id = ? AND e.societe_id = ?
    ");
    $stmt->execute([$id_ecriture, $societe_id]);
    $ecriture = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ecriture) {
        throw new Exception("Écriture introuvable");
    }

    // Vérifier si l'écriture a déjà été extournée
    if ($ecriture['extournee'] === 'Oui') {
        throw new Exception("Cette écriture a déjà été extournée");
    }

    // Vérifier que l'exercice n'est pas clôturé
    if ($ecriture['statut_exercice'] === 'Clôturé') {
        throw new Exception("Impossible d'extourner : l'exercice comptable est clôturé");
    }

    // Vérifier que l'exercice existe
    if (!$ecriture['date_debut']) {
        throw new Exception("Aucun exercice comptable trouvé pour cette écriture");
    }

    // Récupérer les lignes de l'écriture
    $stmt = $db->prepare("
        SELECT * FROM lignes_ecriture
        WHERE id_ecriture = ?
        ORDER BY id
    ");
    $stmt->execute([$id_ecriture]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($lignes)) {
        throw new Exception("Aucune ligne d'écriture trouvée");
    }

    // Générer le nouveau numéro d'écriture
    $journal = $ecriture['journal'];
    $date_extourne = date('Y-m-d'); // Date du jour pour l'extourne
    $annee = date('Y', strtotime($date_extourne));
    $mois_numero = date('m', strtotime($date_extourne));
    $mois_texte = strtoupper(date('M', strtotime($date_extourne)));

    // Correspondance des mois français
    $mois_fr = [
        'JAN' => 'JAN', 'FEB' => 'FEV', 'MAR' => 'MAR', 'APR' => 'AVR',
        'MAY' => 'MAI', 'JUN' => 'JUN', 'JUL' => 'JUL', 'AUG' => 'AOU',
        'SEP' => 'SEP', 'OCT' => 'OCT', 'NOV' => 'NOV', 'DEC' => 'DEC'
    ];
    $mois = $mois_fr[$mois_texte] ?? $mois_texte;

    // Générer le préfixe du numéro
    $annee_court = substr($annee, 2, 2);
    $prefix = $journal . $mois_numero . $annee_court;

    // Obtenir le dernier numéro séquentiel pour ce préfixe
    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING(numero_ecriture, -4) AS UNSIGNED)) as dernier_num
        FROM ecritures
        WHERE numero_ecriture LIKE ? AND societe_id = ?
    ");
    $stmt->execute([$prefix . "%", $societe_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $dernier_num = $result["dernier_num"] ?? 0;
    $prochain_num = $dernier_num + 1;

    $numero_ecriture = $prefix . str_pad($prochain_num, 4, "0", STR_PAD_LEFT);

    // Commencer la transaction
    $db->beginTransaction();

    try {
        // Créer la nouvelle écriture (extourne)
        $stmt = $db->prepare("
            INSERT INTO ecritures (
                societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle,
                id_tiers, compte_tiers, num_piece, reference_piece, num_facture,
                type_document, montant_total, statut, createur, date_creation,
                id_ecriture_extourne
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, NOW(),
                ?
            )
        ");

        $libelle_extourne = "EXTOURNE - " . $ecriture['libelle'];
        $ref_extourne = "EXTOURNE/" . $ecriture['numero_ecriture'];

        $stmt->execute([
            $societe_id,
            $numero_ecriture,
            $date_extourne,
            $mois,
            $annee,
            $ecriture['journal'],
            $libelle_extourne,
            $ecriture['id_tiers'],
            $ecriture['compte_tiers'],
            $ecriture['num_piece'],
            $ref_extourne,
            $ecriture['num_facture'],
            $ecriture['type_document'],
            $ecriture['montant_total'],
            $ecriture['statut'], // Même statut que l'original
            $_SESSION['user_name'],
            $id_ecriture // Référence à l'écriture d'origine
        ]);

        $nouvelle_ecriture_id = $db->lastInsertId();

        // Créer les lignes inversées
        $stmt = $db->prepare("
            INSERT INTO lignes_ecriture (
                id_ecriture, compte, libelle, debit, credit,
                compte_tiers, numero_facture, date_ligne, createur
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($lignes as $ligne) {
            // Inverser débit et crédit
            $nouveau_debit = $ligne['credit'];
            $nouveau_credit = $ligne['debit'];

            $libelle_ligne_extourne = "EXTOURNE - " . $ligne['libelle'];

            $stmt->execute([
                $nouvelle_ecriture_id,
                $ligne['compte'],
                $libelle_ligne_extourne,
                $nouveau_debit,
                $nouveau_credit,
                $ligne['compte_tiers'],
                $ligne['numero_facture'],
                $ligne['date_ligne'],
                $_SESSION['user_name']
            ]);
        }

        // Marquer l'écriture d'origine comme extournée
        $stmt = $db->prepare("UPDATE ecritures SET extournee = 'Oui' WHERE id = ? AND societe_id = ?");
        $stmt->execute([$id_ecriture, $societe_id]);

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => "Écriture extournée avec succès",
            'numero_extourne' => $numero_ecriture,
            'id_extourne' => $nouvelle_ecriture_id
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
