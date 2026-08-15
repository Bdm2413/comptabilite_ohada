<?php
/**
 * API pour récupérer les articles du catalogue d'un fournisseur
 */

require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { echo json_encode(['success' => false, 'message' => 'Aucune société sélectionnée']); exit; }

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$fournisseur_id = isset($_GET['fournisseur_id']) ? (int)$_GET['fournisseur_id'] : 0;
$article_id = isset($_GET['article_id']) ? (int)$_GET['article_id'] : 0;

try {
    switch ($action) {
        case 'liste':
            // Accepte fournisseur_id optionnel : 0 = articles généraux + fournisseur, >0 = filtre fournisseur
            $sql = "
                SELECT id, reference, designation, description, unite,
                       prix_unitaire_ht, prix_vente_ht, type_article,
                       usage_article, compte_achat, compte_vente, type_taxe_defaut
                FROM catalogues_fournisseurs
                WHERE actif = 'Oui' AND societe_id = ?
            ";
            $params = [$societe_id];

            if ($fournisseur_id > 0) {
                $sql .= " AND (id_fournisseur = ? OR id_fournisseur IS NULL)";
                $params[] = $fournisseur_id;
            }

            $sql .= " ORDER BY designation";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $articles = $stmt->fetchAll();

            echo json_encode(['success' => true, 'articles' => $articles]);
            break;

        case 'liste_vente':
            // Articles disponibles pour les factures clients
            $stmt = $db->prepare("
                SELECT id, reference, designation, description, unite,
                       prix_vente_ht, type_article, compte_vente, type_taxe_defaut
                FROM catalogues_fournisseurs
                WHERE actif = 'Oui' AND societe_id = ?
                  AND usage_article IN ('vente','les_deux')
                ORDER BY designation
            ");
            $stmt->execute([$societe_id]);
            echo json_encode(['success' => true, 'articles' => $stmt->fetchAll()]);
            break;

        case 'detail':
            if ($article_id <= 0) {
                throw new Exception('Article non spécifié');
            }

            $stmt = $db->prepare("
                SELECT c.*, pt.nom as nom_fournisseur
                FROM catalogues_fournisseurs c
                LEFT JOIN plan_tiers pt ON c.id_fournisseur = pt.id
                WHERE c.id = ? AND c.societe_id = ?
            ");
            $stmt->execute([$article_id, $societe_id]);
            $article = $stmt->fetch();

            if (!$article) {
                throw new Exception('Article introuvable');
            }

            echo json_encode(['success' => true, 'article' => $article]);
            break;

        case 'recherche':
            $terme = $_GET['terme'] ?? '';
            $usage  = $_GET['usage'] ?? '';  // 'achat', 'vente', ou vide = tous
            if (empty($terme)) {
                echo json_encode(['success' => true, 'articles' => []]);
                break;
            }

            $sql = "
                SELECT c.id, c.reference, c.designation,
                       c.prix_unitaire_ht, c.prix_vente_ht, c.unite,
                       c.type_article, c.usage_article,
                       c.compte_achat, c.compte_vente, c.type_taxe_defaut,
                       pt.nom as nom_fournisseur
                FROM catalogues_fournisseurs c
                LEFT JOIN plan_tiers pt ON c.id_fournisseur = pt.id
                WHERE c.actif = 'Oui'
                  AND c.societe_id = ?
                  AND (c.designation LIKE ? OR c.reference LIKE ?)
            ";
            $params = [$societe_id, "%$terme%", "%$terme%"];

            if ($fournisseur_id > 0) {
                $sql .= " AND (c.id_fournisseur = ? OR c.id_fournisseur IS NULL)";
                $params[] = $fournisseur_id;
            }

            if ($usage === 'vente') {
                $sql .= " AND c.usage_article IN ('vente','les_deux')";
            } elseif ($usage === 'achat') {
                $sql .= " AND c.usage_article IN ('achat','les_deux')";
            }

            $sql .= " ORDER BY c.designation LIMIT 20";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $articles = $stmt->fetchAll();

            echo json_encode(['success' => true, 'articles' => $articles]);
            break;

        default:
            throw new Exception('Action non reconnue');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
