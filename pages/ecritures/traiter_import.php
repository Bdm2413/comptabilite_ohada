<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

use PhpOffice\PhpSpreadsheet\IOFactory;

// Vérifier la confirmation
if (!isset($_POST['confirmer'])) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Vous devez confirmer l\'import pour continuer.'
    ];
    header('Location: import.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Récupérer la société courante
$societe_id = getCurrentSocieteId();
if (!$societe_id) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Erreur: Aucune société sélectionnée'
    ];
    header('Location: import.php');
    exit;
}

// Options d'import
$creer_comptes = isset($_POST['creer_comptes']);
$creer_tiers = isset($_POST['creer_tiers']);
$mode_simulation = isset($_POST['mode_simulation']);

// Chemins des fichiers
$fichier_ecritures = __DIR__ . '/../../ecritures.xlsx';
$fichier_lignes = __DIR__ . '/../../lignes_ecriture.xlsx';

// Vérifier l'existence des fichiers
if (!file_exists($fichier_ecritures)) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Le fichier ecritures.xlsx est introuvable à la racine de l\'application.'
    ];
    header('Location: import.php');
    exit;
}

if (!file_exists($fichier_lignes)) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Le fichier lignes_ecriture.xlsx est introuvable à la racine de l\'application.'
    ];
    header('Location: import.php');
    exit;
}

// Initialiser les compteurs
$stats = [
    'ecritures_importees' => 0,
    'lignes_importees' => 0,
    'comptes_crees' => 0,
    'tiers_crees' => 0,
    'erreurs' => []
];

try {
    // Démarrer une transaction
    if (!$mode_simulation) {
        $db->beginTransaction();
    }

    // === ÉTAPE 1 : Lire le fichier des écritures ===
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Import en cours</title>";
    echo "<script src='https://cdn.tailwindcss.com'></script>";
    echo "</head><body class='bg-slate-900 text-slate-100 p-8'>";
    echo "<div class='max-w-4xl mx-auto'>";
    echo "<h1 class='text-2xl font-bold text-green-400 mb-6'>Import en cours...</h1>";
    echo "<div class='bg-slate-800 rounded-lg p-6 space-y-4'>";

    echo "<p class='text-slate-300'><i class='fas fa-spinner fa-spin mr-2'></i>Lecture du fichier ecritures.xlsx...</p>";
    flush();

    $spreadsheet_ecritures = IOFactory::load($fichier_ecritures);
    $sheet_ecritures = $spreadsheet_ecritures->getActiveSheet();
    $ecritures_data = [];

    // Lire les en-têtes
    $headers_ecritures = [];
    $headerRow = $sheet_ecritures->getRowIterator(1, 1)->current();
    $cellIterator = $headerRow->getCellIterator();
    $cellIterator->setIterateOnlyExistingCells(false);

    $col = 0;
    foreach ($cellIterator as $cell) {
        $headers_ecritures[$col] = $cell->getValue();
        $col++;
    }

    // Trouver les indices des colonnes
    $col_indices_ecr = [];
    foreach ($headers_ecritures as $idx => $header) {
        $col_indices_ecr[$header] = $idx;
    }

    // Lire les données
    $row_num = 2;
    foreach ($sheet_ecritures->getRowIterator(2) as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);

        $rowData = [];
        $col = 0;
        foreach ($cellIterator as $cell) {
            $rowData[$col] = $cell->getValue();
            $col++;
        }

        // Créer un tableau associatif
        $ecriture = [];
        foreach ($col_indices_ecr as $header => $idx) {
            $ecriture[$header] = $rowData[$idx] ?? null;
        }

        // Filtrer les lignes vides
        if (!empty($ecriture['numero_ecriture'])) {
            $ecritures_data[] = $ecriture;
        }

        $row_num++;
    }

    echo "<p class='text-green-400'><i class='fas fa-check mr-2'></i>" . count($ecritures_data) . " écritures trouvées</p>";
    flush();

    // === ÉTAPE 2 : Lire le fichier des lignes d'écritures ===
    echo "<p class='text-slate-300'><i class='fas fa-spinner fa-spin mr-2'></i>Lecture du fichier lignes_ecriture.xlsx...</p>";
    flush();

    $spreadsheet_lignes = IOFactory::load($fichier_lignes);
    $sheet_lignes = $spreadsheet_lignes->getActiveSheet();
    $lignes_data = [];

    // Lire les en-têtes
    $headers_lignes = [];
    $headerRow = $sheet_lignes->getRowIterator(1, 1)->current();
    $cellIterator = $headerRow->getCellIterator();
    $cellIterator->setIterateOnlyExistingCells(false);

    $col = 0;
    foreach ($cellIterator as $cell) {
        $headers_lignes[$col] = $cell->getValue();
        $col++;
    }

    // Trouver les indices des colonnes
    $col_indices_lig = [];
    foreach ($headers_lignes as $idx => $header) {
        $col_indices_lig[$header] = $idx;
    }

    // Lire les données
    foreach ($sheet_lignes->getRowIterator(2) as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);

        $rowData = [];
        $col = 0;
        foreach ($cellIterator as $cell) {
            $rowData[$col] = $cell->getValue();
            $col++;
        }

        // Créer un tableau associatif
        $ligne = [];
        foreach ($col_indices_lig as $header => $idx) {
            $ligne[$header] = $rowData[$idx] ?? null;
        }

        // Filtrer les lignes vides
        if (!empty($ligne['compte']) && (!empty($ligne['debit']) || !empty($ligne['credit']))) {
            $lignes_data[] = $ligne;
        }
    }

    echo "<p class='text-green-400'><i class='fas fa-check mr-2'></i>" . count($lignes_data) . " lignes d'écritures trouvées</p>";
    flush();

    // Regrouper les lignes par numéro d'écriture
    $lignes_par_ecriture = [];
    foreach ($lignes_data as $ligne) {
        $num_ecr = $ligne['numero_ecriture'] ?? $ligne['id_ecriture'] ?? null;
        if ($num_ecr) {
            if (!isset($lignes_par_ecriture[$num_ecr])) {
                $lignes_par_ecriture[$num_ecr] = [];
            }
            $lignes_par_ecriture[$num_ecr][] = $ligne;
        }
    }

    echo "<p class='text-blue-400'><i class='fas fa-info-circle mr-2'></i>Lignes regroupées par écriture</p>";
    flush();

    // === ÉTAPE 3 : Supprimer les écritures existantes de 2025 ===
    if (!$mode_simulation) {
        echo "<p class='text-yellow-300'><i class='fas fa-trash mr-2'></i>Suppression des écritures existantes de l'exercice 2025...</p>";
        flush();

        // Supprimer les lignes d'abord
        $stmtDel = $db->prepare("DELETE le FROM lignes_ecriture le
                   INNER JOIN ecritures e ON le.id_ecriture = e.id
                   WHERE e.societe_id = ? AND YEAR(e.date_ecriture) = 2025");
        $stmtDel->execute([$societe_id]);

        // Puis les écritures
        $stmtDel2 = $db->prepare("DELETE FROM ecritures WHERE societe_id = ? AND YEAR(date_ecriture) = 2025");
        $stmtDel2->execute([$societe_id]);

        echo "<p class='text-green-400'><i class='fas fa-check mr-2'></i>Écritures existantes supprimées</p>";
        flush();
    }

    // === ÉTAPE 4 : Importer les écritures ===
    echo "<p class='text-slate-300 mt-4'><i class='fas fa-spinner fa-spin mr-2'></i>Import des écritures...</p>";
    flush();

    $user_id = $_SESSION['user_id'] ?? 1;

    foreach ($ecritures_data as $idx => $ecriture) {
        $numero_ecriture = $ecriture['numero_ecriture'];

        // Convertir la date
        $date_ecriture = $ecriture['date_ecriture'];
        if (is_numeric($date_ecriture)) {
            // Date Excel (nombre de jours depuis 1900-01-01)
            $date_obj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date_ecriture);
            $date_ecriture = $date_obj->format('Y-m-d');
        } elseif (!empty($date_ecriture)) {
            $date_ecriture = date('Y-m-d', strtotime($date_ecriture));
        }

        // Préparer les données - TOUS LES CHAMPS
        $journal = $ecriture['journal'] ?? 'OD';
        $libelle = $ecriture['libelle'] ?? '';
        $statut = $ecriture['statut'] ?? 'Validé';
        $id_tiers = $ecriture['id_tiers'] ?? null;
        $compte_tiers = $ecriture['compte_tiers'] ?? null;
        $num_piece = $ecriture['num_piece'] ?? null;
        $reference_piece = $ecriture['reference_piece'] ?? null;
        $num_facture = $ecriture['num_facture'] ?? null;
        $type_document = $ecriture['type_document'] ?? null;
        $type_facture = $ecriture['type_facture'] ?? null;
        $facture_initiale = $ecriture['facture_initiale'] ?? null;
        $montant_total = $ecriture['montant_total'] ?? null;
        $lettrage = $ecriture['lettrage'] ?? null;
        $statut_lettrage = $ecriture['statut_lettrage'] ?? 'Non lettré';
        $extournee = $ecriture['extournee'] ?? 'Non';
        $id_ecriture_extourne = $ecriture['id_ecriture_extourne'] ?? null;

        // Calculer mois et année automatiquement à partir de date_ecriture
        $mois = date('F', strtotime($date_ecriture));
        $annee = date('Y', strtotime($date_ecriture));

        if (!$mode_simulation) {
            // Insérer l'écriture avec TOUS LES CHAMPS
            $stmt = $db->prepare("
                INSERT INTO ecritures (
                    societe_id, numero_ecriture, date_ecriture, mois, annee, journal, libelle, statut,
                    id_tiers, compte_tiers, num_piece, reference_piece, num_facture,
                    type_document, type_facture, facture_initiale, montant_total,
                    lettrage, statut_lettrage, extournee, id_ecriture_extourne,
                    createur, date_creation, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");

            $stmt->execute([
                $societe_id,
                $numero_ecriture,
                $date_ecriture,
                $mois,
                $annee,
                $journal,
                $libelle,
                $statut,
                $id_tiers,
                $compte_tiers,
                $num_piece,
                $reference_piece,
                $num_facture,
                $type_document,
                $type_facture,
                $facture_initiale,
                $montant_total,
                $lettrage,
                $statut_lettrage,
                $extournee,
                $id_ecriture_extourne,
                $user_id
            ]);

            $id_ecriture = $db->lastInsertId();

            // Insérer les lignes
            if (isset($lignes_par_ecriture[$numero_ecriture])) {
                $stmt_ligne = $db->prepare("
                    INSERT INTO lignes_ecriture (
                        id_ecriture, compte, compte_tiers, numero_facture, libelle,
                        debit, credit, date_ligne, createur, date_creation, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                foreach ($lignes_par_ecriture[$numero_ecriture] as $ligne) {
                    // Convertir date_ligne si présente
                    $date_ligne = $ligne['date_ligne'] ?? null;
                    if ($date_ligne && is_numeric($date_ligne)) {
                        $date_obj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date_ligne);
                        $date_ligne = $date_obj->format('Y-m-d');
                    } elseif ($date_ligne) {
                        $date_ligne = date('Y-m-d', strtotime($date_ligne));
                    }

                    $stmt_ligne->execute([
                        $id_ecriture,
                        $ligne['compte'],
                        $ligne['compte_tiers'] ?? null,
                        $ligne['numero_facture'] ?? null,
                        $ligne['libelle'] ?? null,
                        $ligne['debit'] ?? 0,
                        $ligne['credit'] ?? 0,
                        $date_ligne,
                        $user_id
                    ]);

                    $stats['lignes_importees']++;
                }
            }
        }

        $stats['ecritures_importees']++;

        // Afficher la progression tous les 100 écritures
        if (($idx + 1) % 100 == 0) {
            echo "<p class='text-blue-400'><i class='fas fa-clock mr-2'></i>" . ($idx + 1) . " écritures traitées...</p>";
            flush();
        }
    }

    echo "<p class='text-green-400 font-bold mt-4'><i class='fas fa-check-circle mr-2'></i>Import terminé avec succès !</p>";
    echo "</div>";

    // Statistiques
    echo "<div class='bg-slate-800 rounded-lg p-6 mt-6'>";
    echo "<h2 class='text-xl font-bold text-white mb-4'>Statistiques d'import</h2>";
    echo "<div class='grid grid-cols-2 gap-4'>";
    echo "<div class='bg-green-900/20 border border-green-800/30 rounded-lg p-4'>";
    echo "<p class='text-green-300 text-sm'>Écritures importées</p>";
    echo "<p class='text-3xl font-bold text-green-400'>" . $stats['ecritures_importees'] . "</p>";
    echo "</div>";
    echo "<div class='bg-blue-900/20 border border-blue-800/30 rounded-lg p-4'>";
    echo "<p class='text-blue-300 text-sm'>Lignes d'écritures importées</p>";
    echo "<p class='text-3xl font-bold text-blue-400'>" . $stats['lignes_importees'] . "</p>";
    echo "</div>";
    echo "</div>";

    if ($mode_simulation) {
        echo "<p class='text-yellow-300 mt-4'><i class='fas fa-info-circle mr-2'></i>Mode simulation : aucune donnée n'a été réellement importée.</p>";
    }

    echo "</div>";

    // Boutons
    echo "<div class='mt-6 flex gap-3'>";
    echo "<a href='liste.php' class='px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors inline-flex items-center gap-2'>";
    echo "<i class='fas fa-list'></i> Voir les écritures";
    echo "</a>";
    echo "<a href='import.php' class='px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2'>";
    echo "<i class='fas fa-redo'></i> Nouvel import";
    echo "</a>";
    echo "</div>";

    echo "</div></body></html>";

    // Valider la transaction
    if (!$mode_simulation) {
        $db->commit();
    }

} catch (Exception $e) {
    // Annuler la transaction en cas d'erreur
    if (!$mode_simulation && $db->inTransaction()) {
        $db->rollBack();
    }

    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Erreur lors de l\'import : ' . $e->getMessage(),
        'details' => $stats['erreurs']
    ];

    header('Location: import.php');
    exit;
}
