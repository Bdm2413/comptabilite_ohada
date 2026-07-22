<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Correction Vue Totaux V2</title>
    <style>
        body { font-family: Arial; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🔧 Correction de la vue v_ecritures_totaux (Version 2)</h1>
    <?php
    try {
        $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Supprimer l'ancienne vue
        $db->exec("DROP VIEW IF EXISTS v_ecritures_totaux");
        echo '<div class="success">✓ Ancienne vue supprimée</div>';

        // Créer la nouvelle vue avec sous-requêtes (évite les problèmes de GROUP BY)
        $sql = "CREATE VIEW v_ecritures_totaux AS
        SELECT
            e.id,
            e.numero_ecriture,
            e.date_ecriture,
            e.journal,
            e.libelle,
            e.statut,
            (SELECT COUNT(*) FROM lignes_ecriture WHERE id_ecriture = e.id) as nb_lignes,
            (SELECT COALESCE(SUM(debit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) as total_debit,
            (SELECT COALESCE(SUM(credit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) as total_credit,
            ABS((SELECT COALESCE(SUM(debit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) -
                (SELECT COALESCE(SUM(credit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id)) as ecart,
            CASE
                WHEN ABS((SELECT COALESCE(SUM(debit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id) -
                         (SELECT COALESCE(SUM(credit), 0) FROM lignes_ecriture WHERE id_ecriture = e.id)) < 0.01
                THEN 'Équilibrée'
                ELSE 'Déséquilibrée'
            END as equilibre
        FROM ecritures e";

        $db->exec($sql);
        echo '<div class="success">✓ Vue v_ecritures_totaux recréée avec succès !</div>';

        echo '<h2>✅ Migration 100% complète !</h2>';
        echo '<p>Les tables d\'écritures sont maintenant prêtes :</p>';
        echo '<ul>';
        echo '<li>✓ Table <strong>ecritures</strong></li>';
        echo '<li>✓ Table <strong>lignes_ecriture</strong></li>';
        echo '<li>✓ Vue <strong>v_ecritures_detail</strong></li>';
        echo '<li>✓ Vue <strong>v_ecritures_totaux</strong> (corrigée)</li>';
        echo '</ul>';
        echo '<p>Prochaine étape : Créer les pages de saisie et consultation.</p>';
        echo '<a href="../" style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px;">Retour</a>';

    } catch (PDOException $e) {
        echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>
</body>
</html>
