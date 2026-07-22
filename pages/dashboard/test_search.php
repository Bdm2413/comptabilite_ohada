<?php
// Test simple de l'API de recherche
require_once '../../config/config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Test Recherche API</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-white p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">🔍 Test API Recherche</h1>

        <div class="bg-slate-800 rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Tester la recherche</h2>

            <div class="flex gap-4 mb-4">
                <input
                    type="text"
                    id="searchQuery"
                    placeholder="Entrez un terme de recherche..."
                    class="flex-1 px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                    value="client"
                >
                <select id="searchModule" class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                    <option value="all">Tous</option>
                    <option value="ecritures">Écritures</option>
                    <option value="comptes">Comptes</option>
                    <option value="tiers">Tiers</option>
                    <option value="pieces">Pièces</option>
                    <option value="journaux">Journaux</option>
                </select>
                <button
                    onclick="testSearch()"
                    class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold"
                >
                    Rechercher
                </button>
            </div>

            <div class="text-sm text-slate-400 mb-2">
                <strong>URL testée:</strong> <code id="apiUrl" class="text-blue-400"></code>
            </div>
        </div>

        <div id="loading" class="hidden text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-white"></div>
            <p class="mt-2">Recherche en cours...</p>
        </div>

        <div id="error" class="hidden bg-red-900/50 border border-red-500 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-red-400 mb-2">❌ Erreur</h3>
            <pre id="errorContent" class="text-sm text-red-300 whitespace-pre-wrap"></pre>
        </div>

        <div id="results" class="hidden">
            <div class="bg-slate-800 rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-4">📊 Résultats</h2>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div class="bg-slate-700 rounded p-3">
                            <div class="text-slate-400">Requête</div>
                            <div class="font-semibold" id="resultQuery"></div>
                        </div>
                        <div class="bg-slate-700 rounded p-3">
                            <div class="text-slate-400">Résultats trouvés</div>
                            <div class="font-semibold text-2xl text-green-400" id="resultTotal"></div>
                        </div>
                    </div>

                    <div class="bg-slate-700/50 rounded p-4 max-h-96 overflow-auto">
                        <h3 class="font-semibold mb-3">Données JSON</h3>
                        <pre id="jsonContent" class="text-xs text-slate-300 whitespace-pre-wrap"></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 bg-slate-800 rounded-lg p-6">
            <h3 class="font-semibold mb-3">ℹ️ Informations de débogage</h3>
            <div class="text-sm space-y-2 text-slate-300">
                <p><strong>Chemin actuel:</strong> <code class="text-blue-400"><?php echo __FILE__; ?></code></p>
                <p><strong>Chemin API:</strong> <code class="text-blue-400"><?php echo realpath('../../api/v1/search.php'); ?></code></p>
                <p><strong>Session utilisateur:</strong> <code class="text-green-400"><?php echo $_SESSION['nom_utilisateur'] ?? 'Non connecté'; ?></code></p>
            </div>
        </div>
    </div>

    <script>
        function testSearch() {
            const query = document.getElementById('searchQuery').value;
            const module = document.getElementById('searchModule').value;

            if (!query || query.trim().length === 0) {
                alert('Veuillez entrer un terme de recherche');
                return;
            }

            // Tester directement le fichier PHP au lieu du URL rewriting
            const apiUrl = `../../api/v1/search.php?q=${encodeURIComponent(query)}&module=${module}&limit=20`;
            document.getElementById('apiUrl').textContent = apiUrl;

            // Afficher le chargement
            document.getElementById('loading').classList.remove('hidden');
            document.getElementById('error').classList.add('hidden');
            document.getElementById('results').classList.add('hidden');

            // Effectuer la requête
            fetch(apiUrl)
                .then(response => {
                    console.log('Status:', response.status);
                    console.log('Headers:', response.headers);
                    return response.json();
                })
                .then(data => {
                    console.log('Data:', data);
                    document.getElementById('loading').classList.add('hidden');

                    if (data.success) {
                        // Afficher les résultats
                        document.getElementById('results').classList.remove('hidden');
                        document.getElementById('resultQuery').textContent = data.query;
                        document.getElementById('resultTotal').textContent = data.total;
                        document.getElementById('jsonContent').textContent = JSON.stringify(data, null, 2);
                    } else {
                        // Afficher l'erreur
                        document.getElementById('error').classList.remove('hidden');
                        document.getElementById('errorContent').textContent = JSON.stringify(data, null, 2);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('loading').classList.add('hidden');
                    document.getElementById('error').classList.remove('hidden');
                    document.getElementById('errorContent').textContent = 'Erreur réseau: ' + error.message + '\n\nVérifiez que l\'API est accessible et que le serveur est démarré.';
                });
        }

        // Lancer la recherche au chargement
        window.addEventListener('load', function() {
            testSearch();
        });
    </script>
</body>
</html>
