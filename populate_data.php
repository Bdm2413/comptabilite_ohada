<?php
/**
 * Script pour peupler les données initiales
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peupler les données - ComptaSYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-6">
            <h1 class="text-2xl font-bold text-white mb-4">Insertion des données initiales</h1>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['populate'])) {
                try {
                    $pdo = new PDO("mysql:host=localhost;dbname=comptabilite_syscohada;charset=utf8mb4", 'root', '', [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);

                    echo '<div class="space-y-4">';

                    // 1. Supprimer les contraintes de clés étrangères sur pieces_comptables
                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Étape 1 : Modification de la table pieces_comptables...</p>';

                    try {
                        // Vérifier les contraintes existantes
                        $constraints = $pdo->query("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = 'comptabilite_syscohada'
                            AND TABLE_NAME = 'pieces_comptables'
                            AND REFERENCED_TABLE_NAME IS NOT NULL
                        ")->fetchAll(PDO::FETCH_COLUMN);

                        foreach ($constraints as $constraint) {
                            try {
                                $pdo->exec("ALTER TABLE pieces_comptables DROP FOREIGN KEY `$constraint`");
                                echo '<p class="text-green-400 text-sm mt-1">✅ Contrainte supprimée : ' . htmlspecialchars($constraint) . '</p>';
                            } catch (Exception $e) {
                                echo '<p class="text-yellow-400 text-xs mt-1">⚠️ ' . htmlspecialchars($e->getMessage()) . '</p>';
                            }
                        }

                        // Modifier la colonne id_journal
                        $pdo->exec("ALTER TABLE pieces_comptables MODIFY COLUMN id_journal VARCHAR(10) NOT NULL COMMENT 'Code du journal'");
                        echo '<p class="text-green-400 text-sm mt-1">✅ Colonne id_journal modifiée en VARCHAR(10)</p>';

                    } catch (Exception $e) {
                        echo '<p class="text-yellow-400 text-sm mt-1">⚠️ ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    echo '</div>';

                    // 2. Ajouter la colonne compte_auxiliaire
                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Étape 2 : Modification de lignes_ecriture...</p>';
                    try {
                        $pdo->exec("ALTER TABLE lignes_ecriture ADD COLUMN compte_auxiliaire VARCHAR(50) NULL COMMENT 'Compte tiers/auxiliaire' AFTER numero_compte");
                        echo '<p class="text-green-400 text-sm mt-1">✅ Colonne compte_auxiliaire ajoutée</p>';
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                            echo '<p class="text-blue-400 text-sm mt-1">ℹ️ Colonne déjà existante</p>';
                        } else {
                            echo '<p class="text-yellow-400 text-sm mt-1">⚠️ ' . htmlspecialchars($e->getMessage()) . '</p>';
                        }
                    }
                    echo '</div>';

                    // 3. Peupler code_journal
                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Étape 3 : Insertion des codes journaux...</p>';

                    $journaux = [
                        ['AC', 'Journal des Achats', 'Achat', NULL],
                        ['VE', 'Journal des Ventes', 'Vente', NULL],
                        ['BQ', 'Journal de Banque', 'Trésorerie', '521'],
                        ['CA', 'Journal de Caisse', 'Trésorerie', '57'],
                        ['OD', 'Journal des Opérations Diverses', 'Général', NULL],
                        ['SAL', 'Journal des Salaires', 'Salaire', NULL],
                        ['IMMO', 'Journal des Immobilisations', 'Immobilisation', NULL]
                    ];

                    $stmt = $pdo->prepare("INSERT IGNORE INTO code_journal (code, journal, type, compte_tresorerie) VALUES (?, ?, ?, ?)");
                    $count = 0;
                    foreach ($journaux as $j) {
                        try {
                            $stmt->execute($j);
                            if ($stmt->rowCount() > 0) $count++;
                        } catch (Exception $e) {}
                    }
                    echo '<p class="text-green-400 text-sm mt-1">✅ ' . $count . ' journaux insérés</p>';
                    echo '</div>';

                    // 4. Peupler table_correspondance
                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Étape 4 : Insertion de la table de correspondance...</p>';

                    $correspondances = [
                        // Classe 1
                        [1000, 1, 'CAPITAL ET RESERVES', 'Bilan', NULL, 'PC', NULL, NULL],
                        [1100, 1, 'REPORT A NOUVEAU', 'Bilan', NULL, 'PC', NULL, NULL],
                        [1200, 1, 'RESULTAT NET DE L\'EXERCICE', 'Bilan', NULL, 'PC', NULL, NULL],
                        [1300, 1, 'SUBVENTIONS D\'INVESTISSEMENT', 'Bilan', NULL, 'PC', NULL, NULL],
                        [1400, 1, 'EMPRUNTS ET DETTES FINANCIERES', 'Bilan', NULL, 'DF', NULL, NULL],
                        // Classe 2
                        [2000, 2, 'CHARGES IMMOBILISEES', 'Bilan', 'AI', NULL, NULL, NULL],
                        [2100, 2, 'IMMOBILISATIONS INCORPORELLES', 'Bilan', 'AI', NULL, NULL, NULL],
                        [2200, 2, 'TERRAINS', 'Bilan', 'AI', NULL, NULL, NULL],
                        [2300, 2, 'BATIMENTS ET INSTALLATIONS', 'Bilan', 'AI', NULL, NULL, NULL],
                        [2400, 2, 'MATERIEL', 'Bilan', 'AI', NULL, NULL, NULL],
                        [2800, 2, 'AMORTISSEMENTS', 'Bilan', NULL, 'AI', NULL, NULL],
                        // Classe 3
                        [3100, 3, 'MARCHANDISES', 'Bilan', 'AC', NULL, NULL, NULL],
                        [3200, 3, 'MATIERES PREMIERES', 'Bilan', 'AC', NULL, NULL, NULL],
                        [3300, 3, 'AUTRES APPROVISIONNEMENTS', 'Bilan', 'AC', NULL, NULL, NULL],
                        // Classe 4
                        [4000, 4, 'FOURNISSEURS', 'Bilan', NULL, 'PC', NULL, NULL],
                        [4100, 4, 'CLIENTS', 'Bilan', 'AC', NULL, NULL, NULL],
                        [4200, 4, 'PERSONNEL', 'Bilan', 'AC', NULL, NULL, NULL],
                        [4300, 4, 'ORGANISMES SOCIAUX', 'Bilan', NULL, 'PC', NULL, NULL],
                        [4400, 4, 'ETAT', 'Bilan', 'AC', NULL, NULL, NULL],
                        // Classe 5
                        [5200, 5, 'BANQUES', 'Bilan', 'TA', NULL, NULL, NULL],
                        [5700, 5, 'CAISSE', 'Bilan', 'TA', NULL, NULL, NULL],
                        // Classe 6
                        [6000, 6, 'ACHATS', 'Résultat', NULL, NULL, 'RD', NULL],
                        [6100, 6, 'TRANSPORTS', 'Résultat', NULL, NULL, 'RD', NULL],
                        [6200, 6, 'SERVICES EXTERIEURS A', 'Résultat', NULL, NULL, 'RD', NULL],
                        [6300, 6, 'SERVICES EXTERIEURS B', 'Résultat', NULL, NULL, 'RD', NULL],
                        [6400, 6, 'IMPOTS ET TAXES', 'Résultat', NULL, NULL, 'RD', NULL],
                        [6600, 6, 'CHARGES DE PERSONNEL', 'Résultat', NULL, NULL, 'RD', NULL],
                        // Classe 7
                        [7000, 7, 'VENTES', 'Résultat', NULL, NULL, NULL, 'RC'],
                        [7100, 7, 'SUBVENTIONS D\'EXPLOITATION', 'Résultat', NULL, NULL, NULL, 'RC'],
                        [7700, 7, 'REVENUS FINANCIERS', 'Résultat', NULL, NULL, NULL, 'RC'],
                        // Classe 8
                        [8100, 8, 'VALEURS COMPTABLES DES CESSIONS', 'Résultat', NULL, NULL, 'RD', NULL],
                        [8200, 8, 'PRODUITS DES CESSIONS', 'Résultat', NULL, NULL, NULL, 'RC']
                    ];

                    $stmt = $pdo->prepare("INSERT IGNORE INTO table_correspondance (compte, classe, libelle, tableau, bd, bc, rd, rc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $count = 0;
                    foreach ($correspondances as $c) {
                        try {
                            $stmt->execute($c);
                            if ($stmt->rowCount() > 0) $count++;
                        } catch (Exception $e) {}
                    }
                    echo '<p class="text-green-400 text-sm mt-1">✅ ' . $count . ' comptes de correspondance insérés</p>';
                    echo '</div>';

                    // 5. Initialiser le compteur
                    echo '<div class="bg-slate-700/50 p-4 rounded">';
                    echo '<p class="font-semibold text-emerald-400">Étape 5 : Initialisation du compteur...</p>';
                    try {
                        $pdo->exec("INSERT IGNORE INTO sage_piece_counter (counter_name, current_value, description) VALUES ('piece_generale', 1, 'Compteur général pour les pièces comptables')");
                        echo '<p class="text-green-400 text-sm mt-1">✅ Compteur initialisé</p>';
                    } catch (Exception $e) {
                        echo '<p class="text-yellow-400 text-sm mt-1">⚠️ ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    echo '</div>';

                    // Résumé final
                    echo '<div class="bg-emerald-500/10 border border-emerald-500/50 rounded p-4 mt-4">';
                    echo '<p class="text-emerald-400 font-semibold mb-3">✅ Données insérées avec succès !</p>';

                    // Compter les enregistrements
                    $stats = [
                        'code_journal' => $pdo->query("SELECT COUNT(*) FROM code_journal")->fetchColumn(),
                        'table_correspondance' => $pdo->query("SELECT COUNT(*) FROM table_correspondance")->fetchColumn(),
                        'sage_piece_counter' => $pdo->query("SELECT COUNT(*) FROM sage_piece_counter")->fetchColumn()
                    ];

                    echo '<div class="bg-slate-900 p-3 rounded text-sm">';
                    echo '<p class="text-white">📊 <strong>Statistiques :</strong></p>';
                    echo '<p class="text-slate-300 mt-1">• Code journaux : ' . $stats['code_journal'] . '</p>';
                    echo '<p class="text-slate-300">• Table correspondance : ' . $stats['table_correspondance'] . '</p>';
                    echo '<p class="text-slate-300">• Compteurs : ' . $stats['sage_piece_counter'] . '</p>';
                    echo '</div>';

                    echo '<div class="mt-4 space-x-2">';
                    echo '<a href="pages/dashboard/index.php" class="inline-block bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded text-sm transition">Aller au Dashboard</a>';
                    echo '<a href="diagnostic.php" class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm transition">Diagnostic</a>';
                    echo '</div>';
                    echo '</div>';

                    echo '</div>';

                } catch (Exception $e) {
                    echo '<div class="bg-red-500/10 border border-red-500/50 rounded p-4">';
                    echo '<p class="text-red-400 font-semibold">❌ Erreur</p>';
                    echo '<p class="text-red-300 text-sm mt-2">' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div>';
                }

            } else {
                ?>
                <p class="text-slate-300 mb-4">
                    Ce script va insérer les données initiales dans les tables créées par la migration.
                </p>

                <div class="bg-blue-500/10 border border-blue-500/50 rounded p-4 mb-6">
                    <p class="text-blue-400 text-sm mb-2">
                        ℹ️ <strong>Données à insérer :</strong>
                    </p>
                    <ul class="list-disc list-inside text-blue-300 text-sm space-y-1">
                        <li>7 codes journaux (AC, VE, BQ, CA, OD, SAL, IMMO)</li>
                        <li>~32 comptes de correspondance (comptes racines à 4 chiffres)</li>
                        <li>1 compteur de pièces</li>
                    </ul>
                </div>

                <form method="POST">
                    <button
                        type="submit"
                        name="populate"
                        class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-3 px-4 rounded transition"
                    >
                        📥 Insérer les données
                    </button>
                </form>

                <div class="mt-6 text-center space-x-4">
                    <a href="diagnostic.php" class="text-blue-400 hover:underline text-sm">Diagnostic</a>
                    <a href="install_migration.php" class="text-blue-400 hover:underline text-sm">Migration</a>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</body>
</html>
