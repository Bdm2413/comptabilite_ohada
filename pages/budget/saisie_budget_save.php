<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

try {
    $db->beginTransaction();

    $id_version = $_POST['id_version'] ?? null;
    $annee = $_POST['annee'];
    $version = $_POST['version'];
    $statut = $_POST['statut'];
    $description = $_POST['description'] ?? null;
    $lignes = $_POST['lignes'] ?? [];

    $created_by = $_SESSION['nom'] ?? 'Utilisateur';

    if ($id_version) {
        // Modification
        $stmt = $db->prepare("UPDATE budget_versions
                              SET version = ?, statut = ?, description = ?, updated_at = NOW()
                              WHERE id = ? AND societe_id = ?");
        $stmt->execute([$version, $statut, $description, $id_version, $societe_id]);

        // Supprimer les anciennes lignes
        $stmt = $db->prepare("DELETE FROM budget_lignes WHERE id_budget_version = ?");
        $stmt->execute([$id_version]);

        // Enregistrer l'historique
        $stmt = $db->prepare("INSERT INTO budget_historique (id_budget_version, action, user_name, details)
                              VALUES (?, 'Modification', ?, ?)");
        $stmt->execute([$id_version, $created_by, "Modification du budget version: $version"]);

    } else {
        // Création
        $stmt = $db->prepare("INSERT INTO budget_versions (annee, version, statut, description, created_by, societe_id)
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$annee, $version, $statut, $description, $created_by, $societe_id]);
        $id_version = $db->lastInsertId();

        // Enregistrer l'historique
        $stmt = $db->prepare("INSERT INTO budget_historique (id_budget_version, action, user_name, details)
                              VALUES (?, 'Création', ?, ?)");
        $stmt->execute([$id_version, $created_by, "Création du budget $annee version: $version"]);
    }

    // Insérer les nouvelles lignes
    $stmt = $db->prepare("INSERT INTO budget_lignes (id_budget_version, compte, id_rubrique, compte_oracle, janvier, fevrier, mars, avril, mai, juin, juillet, aout, septembre, octobre, novembre, decembre)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($lignes as $ligne) {
        if (empty($ligne['compte'])) continue;

        $stmt->execute([
            $id_version,
            $ligne['compte'],
            !empty($ligne['id_rubrique']) ? $ligne['id_rubrique'] : null,
            !empty($ligne['compte_oracle']) ? $ligne['compte_oracle'] : null,
            $ligne['janvier'] ?? 0,
            $ligne['fevrier'] ?? 0,
            $ligne['mars'] ?? 0,
            $ligne['avril'] ?? 0,
            $ligne['mai'] ?? 0,
            $ligne['juin'] ?? 0,
            $ligne['juillet'] ?? 0,
            $ligne['aout'] ?? 0,
            $ligne['septembre'] ?? 0,
            $ligne['octobre'] ?? 0,
            $ligne['novembre'] ?? 0,
            $ligne['decembre'] ?? 0
        ]);
    }

    $db->commit();

    // Redirection avec message de succès
    header("Location: dashboard.php?annee=$annee&version=$id_version&success=1");
    exit;

} catch (Exception $e) {
    $db->rollBack();
    die("Erreur lors de l'enregistrement du budget: " . $e->getMessage());
}
