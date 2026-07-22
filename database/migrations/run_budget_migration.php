<?php
require_once '../../config/config.php';
requireLogin();

// Vérifier que l'utilisateur est admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Accès refusé. Vous devez être administrateur pour exécuter cette migration.");
}

$db = Database::getInstance()->getConnection();

try {
    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/create_budget.sql');

    // Séparer les requêtes
    $queries = array_filter(array_map('trim', explode(';', $sql)));

    $db->beginTransaction();

    $results = [];
    foreach ($queries as $query) {
        if (empty($query) || substr($query, 0, 2) === '--') {
            continue;
        }

        try {
            $db->exec($query);
            $results[] = "✓ Requête exécutée avec succès";
        } catch (PDOException $e) {
            $results[] = "✗ Erreur : " . $e->getMessage();
        }
    }

    $db->commit();

    echo "<!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <title>Migration Module Budgétaire</title>
        <script src='https://cdn.tailwindcss.com'></script>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    </head>
    <body class='bg-slate-900 text-slate-100 p-8'>
        <div class='max-w-4xl mx-auto'>
            <div class='bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700'>
                <h1 class='text-2xl font-bold text-green-400 mb-4'>
                    <i class='fas fa-check-circle'></i> Migration exécutée avec succès !
                </h1>
                <div class='bg-slate-800 rounded-lg p-4 mb-4'>
                    <h2 class='font-bold text-white mb-2'>Résultats :</h2>
                    <ul class='space-y-1 text-sm'>";

    foreach ($results as $result) {
        echo "<li>" . htmlspecialchars($result) . "</li>";
    }

    echo "          </ul>
                </div>
                <div class='bg-green-900/20 border border-green-800/30 rounded-lg p-4 mb-4'>
                    <p class='text-green-300'>
                        <strong>Tables créées :</strong><br>
                        • budget_versions (versions de budgets annuels)<br>
                        • budget_lignes (lignes budgétaires par compte et par mois)<br>
                        • budget_historique (historique des modifications)<br>
                        • budget_alertes (alertes et seuils configurables)
                    </p>
                </div>
                <a href='../../pages/budget/index.php' class='inline-block px-6 py-3 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white rounded-lg transition-all'>
                    Accéder au Module Budgétaire
                </a>
                <a href='../../pages/rapports/index.php' class='inline-block ml-3 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors'>
                    Retour aux Rapports
                </a>
            </div>
        </div>
    </body>
    </html>";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "<!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <title>Erreur Migration</title>
        <script src='https://cdn.tailwindcss.com'></script>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    </head>
    <body class='bg-slate-900 text-slate-100 p-8'>
        <div class='max-w-4xl mx-auto'>
            <div class='bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-red-700'>
                <h1 class='text-2xl font-bold text-red-400 mb-4'>
                    <i class='fas fa-times-circle'></i> Erreur lors de la migration
                </h1>
                <div class='bg-red-900/20 border border-red-800/30 rounded-lg p-4 mb-4'>
                    <p class='text-red-300'>" . htmlspecialchars($e->getMessage()) . "</p>
                </div>
                <a href='../../pages/rapports/index.php' class='inline-block px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors'>
                    Retour aux Rapports
                </a>
            </div>
        </div>
    </body>
    </html>";
}
