<?php
$file = 'pages/rapports/grand_livre.php';
$content = file_get_contents($file);

// Remplacer la ligne problématique
$content = str_replace(
    "<?= htmlspecialchars(['libelle_ligne'] ?? '-') ?>",
    "<?= htmlspecialchars(\$ligne['libelle_ligne'] ?? '-') ?>",
    $content
);

file_put_contents($file, $content);

echo "Fichier corrigé avec succès!\n";
