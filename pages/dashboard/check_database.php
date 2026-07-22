<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérification Base de Données</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-white p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">🗄️ Vérification des données</h1>

        <div class="space-y-4">
            <?php
            $tables = [
                'ecritures' => ['numero_piece', 'libelle', 'statut'],
                'plan_comptable' => ['compte', 'intitule_compte', 'classe'],
                'tiers' => ['nom', 'type', 'email'],
                'pieces_comptables' => ['reference', 'type_piece'],
                'journaux' => ['code', 'libelle']
            ];

            foreach ($tables as $table => $columns) {
                echo "<div class='bg-slate-800 rounded-lg p-6'>";
                echo "<h2 class='text-xl font-semibold mb-4'>📊 Table: $table</h2>";

                try {
                    // Compter les lignes
                    $count = $db->query("SELECT COUNT(*) as total FROM $table")->fetch()['total'];
                    echo "<p class='text-lg mb-3'><strong>Total:</strong> <span class='text-green-400'>$count</span> enregistrement(s)</p>";

                    if ($count > 0) {
                        // Afficher les 3 premiers
                        $stmt = $db->query("SELECT * FROM $table LIMIT 3");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        echo "<div class='bg-slate-700/50 rounded p-4'>";
                        echo "<h3 class='font-semibold mb-2'>Aperçu (3 premiers):</h3>";
                        echo "<div class='text-sm space-y-2'>";

                        foreach ($rows as $i => $row) {
                            echo "<div class='border-t border-slate-600 pt-2 mt-2'>";
                            echo "<strong>Ligne " . ($i + 1) . ":</strong><br>";

                            foreach ($columns as $col) {
                                if (isset($row[$col])) {
                                    $value = htmlspecialchars(substr($row[$col], 0, 50));
                                    echo "<span class='text-slate-400'>$col:</span> <span class='text-blue-300'>$value</span><br>";
                                }
                            }
                            echo "</div>";
                        }

                        echo "</div></div>";
                    } else {
                        echo "<p class='text-yellow-400'>⚠️ Aucune donnée dans cette table</p>";
                    }
                } catch (Exception $e) {
                    echo "<p class='text-red-400'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
                }

                echo "</div>";
            }
            ?>
        </div>

        <div class="mt-6 bg-blue-900/50 border border-blue-500 rounded-lg p-4">
            <p class="text-sm">
                💡 <strong>Astuce:</strong> Si certaines tables sont vides, vous devez d'abord ajouter des données
                (écritures, comptes, tiers, etc.) pour que la recherche fonctionne.
            </p>
        </div>

        <div class="mt-4 text-center">
            <a href="test_search.php" class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold">
                → Tester la recherche API
            </a>
        </div>
    </div>
</body>
</html>
