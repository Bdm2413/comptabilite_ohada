<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Correction Vue Totaux</title>
    <style>
        body { font-family: Arial; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🔧 Correction de la vue v_ecritures_totaux</h1>
    <?php
    try {
        $db = new PDO('mysql:host=localhost;dbname=comptabilite_syscohada', 'root', '');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Supprimer l'ancienne vue
        $db->exec("DROP VIEW IF EXISTS v_ecritures_totaux");
        echo '<div class="success">✓ Ancienne vue supprimée</div>';

        // Créer la nouvelle vue corrigée
        $sql = "CREATE VIEW v_ecritures_totaux AS
        SELECT
            e.id,
            e.numero_ecriture,
            e.date_ecriture,
            e.journal,
            e.libelle,
            e.statut,
            COUNT(DISTINCT le.id) as nb_lignes,
            COALESCE(SUM(le.debit), 0) as total_debit,
            COALESCE(SUM(le.credit), 0) as total_credit,
            ABS(COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0)) as ecart,
            CASE
                WHEN ABS(COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0)) < 0.01 THEN 'Équilibrée'
                ELSE 'Déséquilibrée'
            END as equilibre
        FROM ecritures e
        LEFT JOIN lignes_ecriture le ON e.id = le.id_ecriture
        GROUP BY e.id, e.numero_ecriture, e.date_ecriture, e.journal, e.libelle, e.statut";

        $db->exec($sql);
        echo '<div class="success">✓ Vue v_ecritures_totaux recréée avec succès !</div>';

        echo '<h2>✅ Migration complète !</h2>';
        echo '<p>Les tables d\'écritures sont maintenant prêtes à être utilisées.</p>';
        echo '<a href="../" style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px;">Retour</a>';

    } catch (PDOException $e) {
        echo '<div class="error">✗ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>
</body>
</html>
