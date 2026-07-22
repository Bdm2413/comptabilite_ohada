<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

echo "<h2>Diagnostic des données</h2>";
echo "<style>body { font-family: Arial; padding: 20px; } table { border-collapse: collapse; width: 100%; margin: 20px 0; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #4CAF50; color: white; }</style>";

// Code journaux
echo "<h3>1. Code Journaux</h3>";
try {
    $journaux = $db->query("SELECT * FROM code_journal WHERE actif = 'Oui' ORDER BY code")->fetchAll();
    echo "<p>Nombre de journaux actifs : <strong>" . count($journaux) . "</strong></p>";

    if (count($journaux) > 0) {
        echo "<table>";
        echo "<tr><th>Code</th><th>Journal</th><th>Actif</th></tr>";
        foreach ($journaux as $j) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($j['code']) . "</td>";
            echo "<td>" . htmlspecialchars($j['journal']) . "</td>";
            echo "<td>" . htmlspecialchars($j['actif']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>⚠ Aucun journal actif trouvé !</p>";

        // Vérifier s'il y a des journaux inactifs
        $inactifs = $db->query("SELECT COUNT(*) FROM code_journal WHERE actif = 'Non'")->fetchColumn();
        if ($inactifs > 0) {
            echo "<p style='color: orange;'>Il y a $inactifs journaux inactifs. Vous devez les activer.</p>";
        }

        $total = $db->query("SELECT COUNT(*) FROM code_journal")->fetchColumn();
        echo "<p>Total de journaux dans la table : $total</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur : " . $e->getMessage() . "</p>";
}

// Plan comptable
echo "<h3>2. Plan Comptable</h3>";
try {
    $comptes = $db->query("SELECT compte, intitule FROM plan_comptable WHERE actif = 'Oui' ORDER BY compte LIMIT 10")->fetchAll();
    $totalComptes = $db->query("SELECT COUNT(*) FROM plan_comptable WHERE actif = 'Oui'")->fetchColumn();

    echo "<p>Nombre de comptes actifs : <strong>$totalComptes</strong></p>";

    if (count($comptes) > 0) {
        echo "<p>Aperçu des 10 premiers comptes :</p>";
        echo "<table>";
        echo "<tr><th>Compte</th><th>Intitulé</th></tr>";
        foreach ($comptes as $c) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($c['compte']) . "</td>";
            echo "<td>" . htmlspecialchars($c['intitule']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>⚠ Aucun compte actif trouvé !</p>";

        $total = $db->query("SELECT COUNT(*) FROM plan_comptable")->fetchColumn();
        echo "<p>Total de comptes dans la table : $total</p>";

        if ($total > 0) {
            // Afficher les valeurs distinctes dans la colonne actif
            $valeurs = $db->query("SELECT DISTINCT actif FROM plan_comptable")->fetchAll();
            echo "<p>Valeurs dans la colonne 'actif' :</p><ul>";
            foreach ($valeurs as $v) {
                $count = $db->query("SELECT COUNT(*) FROM plan_comptable WHERE actif = '" . $v['actif'] . "'")->fetchColumn();
                echo "<li>" . htmlspecialchars($v['actif']) . " : $count comptes</li>";
            }
            echo "</ul>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur : " . $e->getMessage() . "</p>";
}

// Plan tiers
echo "<h3>3. Plan Tiers</h3>";
try {
    $tiers = $db->query("SELECT id, nom, type FROM plan_tiers WHERE actif = 1 ORDER BY nom")->fetchAll();
    echo "<p>Nombre de tiers actifs : <strong>" . count($tiers) . "</strong></p>";

    if (count($tiers) > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Nom</th><th>Type</th></tr>";
        foreach ($tiers as $t) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($t['id']) . "</td>";
            echo "<td>" . htmlspecialchars($t['nom']) . "</td>";
            echo "<td>" . htmlspecialchars($t['type']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>⚠ Aucun tiers actif trouvé !</p>";

        $total = $db->query("SELECT COUNT(*) FROM plan_tiers")->fetchColumn();
        echo "<p>Total de tiers dans la table : $total</p>";

        if ($total > 0) {
            // Afficher les valeurs distinctes dans la colonne actif
            $valeurs = $db->query("SELECT DISTINCT actif FROM plan_tiers")->fetchAll();
            echo "<p>Valeurs dans la colonne 'actif' :</p><ul>";
            foreach ($valeurs as $v) {
                $count = $db->query("SELECT COUNT(*) FROM plan_tiers WHERE actif = " . $v['actif'])->fetchColumn();
                echo "<li>" . htmlspecialchars($v['actif']) . " : $count tiers</li>";
            }
            echo "</ul>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur : " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h3>Recommandations</h3>";
echo "<ul>";
echo "<li>Si des données existent mais ne sont pas actives, allez dans les pages de gestion pour les activer</li>";
echo "<li>Si aucune donnée n'existe, vous devez d'abord créer des journaux, comptes et tiers</li>";
echo "<li><a href='../settings/code_journaux.php'>Gérer les Code Journaux</a></li>";
echo "<li><a href='../settings/plan_comptable.php'>Gérer le Plan Comptable</a></li>";
echo "<li><a href='../settings/tiers.php'>Gérer les Tiers</a></li>";
echo "</ul>";
?>
