<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction pour lire un fichier Excel avec PhpSpreadsheet
function lireFichierExcel($filePath) {
    // Vérifier si PhpSpreadsheet est disponible
    $composerAutoload = '../../vendor/autoload.php';
    if (!file_exists($composerAutoload)) {
        throw new Exception("PhpSpreadsheet n'est pas installé. Veuillez installer les dépendances avec 'composer install'.");
    }

    require_once $composerAutoload;

    $lignesData = [];

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();

        // Ignorer la première ligne (en-têtes)
        for ($row = 2; $row <= $highestRow; $row++) {
            $compte = $worksheet->getCell('A' . $row)->getValue();

            // Ignorer les lignes sans compte
            if (empty($compte)) continue;

            $ligne = [
                trim($compte), // Compte
                $worksheet->getCell('B' . $row)->getValue() ?? '', // Intitulé (optionnel)
                $worksheet->getCell('C' . $row)->getValue() ?? '', // Rubrique
                $worksheet->getCell('D' . $row)->getValue() ?? '', // Compte Oracle
                $worksheet->getCell('E' . $row)->getValue() ?? 0, // Janvier
                $worksheet->getCell('F' . $row)->getValue() ?? 0, // Février
                $worksheet->getCell('G' . $row)->getValue() ?? 0, // Mars
                $worksheet->getCell('H' . $row)->getValue() ?? 0, // Avril
                $worksheet->getCell('I' . $row)->getValue() ?? 0, // Mai
                $worksheet->getCell('J' . $row)->getValue() ?? 0, // Juin
                $worksheet->getCell('K' . $row)->getValue() ?? 0, // Juillet
                $worksheet->getCell('L' . $row)->getValue() ?? 0, // Août
                $worksheet->getCell('M' . $row)->getValue() ?? 0, // Septembre
                $worksheet->getCell('N' . $row)->getValue() ?? 0, // Octobre
                $worksheet->getCell('O' . $row)->getValue() ?? 0, // Novembre
                $worksheet->getCell('P' . $row)->getValue() ?? 0, // Décembre
            ];

            $lignesData[] = $ligne;
        }
    } catch (Exception $e) {
        throw new Exception("Erreur lors de la lecture du fichier Excel : " . $e->getMessage());
    }

    return $lignesData;
}

try {
    // Vérifier que le fichier a été uploadé
    if (!isset($_FILES['fichier_excel']) || $_FILES['fichier_excel']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Erreur lors de l'upload du fichier.");
    }

    $annee = $_POST['annee'];
    $version = $_POST['version'];
    $statut = $_POST['statut'];
    $description = $_POST['description'] ?? null;
    $created_by = $_SESSION['nom'] ?? 'Utilisateur';

    $file = $_FILES['fichier_excel'];
    $filePath = $file['tmp_name'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Vérifier l'extension
    if (!in_array($extension, ['xlsx', 'xls'])) {
        throw new Exception("Format de fichier non supporté. Utilisez .xlsx ou .xls");
    }

    // Lire le fichier Excel
    $lignesData = lireFichierExcel($filePath);

    if (empty($lignesData)) {
        throw new Exception("Le fichier est vide ou mal formaté.");
    }

    // Commencer la transaction
    $db->beginTransaction();

    // Créer la version de budget
    $stmt = $db->prepare("INSERT INTO budget_versions (annee, version, statut, description, created_by, societe_id)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$annee, $version, $statut, $description, $created_by, $societe_id]);
    $id_version = $db->lastInsertId();

    // Enregistrer l'historique
    $stmt = $db->prepare("INSERT INTO budget_historique (id_budget_version, action, user_name, details)
                          VALUES (?, 'Import Excel', ?, ?)");
    $stmt->execute([$id_version, $created_by, "Import de " . count($lignesData) . " lignes depuis Excel"]);

    // Insérer les lignes
    $stmt = $db->prepare("INSERT INTO budget_lignes (id_budget_version, compte, id_rubrique, compte_oracle, janvier, fevrier, mars, avril, mai, juin, juillet, aout, septembre, octobre, novembre, decembre)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Préparer la récupération des rubriques
    $stmtRubrique = $db->prepare("SELECT id FROM budget_rubriques WHERE code = ?");

    $compteurLignes = 0;
    foreach ($lignesData as $ligne) {
        // Colonne 0 = Compte (obligatoire)
        // Colonne 1 = Intitulé (optionnel, ignoré)
        // Colonne 2 = Rubrique (code)
        // Colonne 3 = Compte Oracle
        // Colonnes 4-15 = Mois (Janvier à Décembre)

        $compte = trim($ligne[0]);
        if (empty($compte)) continue;

        // Récupérer l'ID de la rubrique à partir du code
        $codeRubrique = trim($ligne[2] ?? '');
        $idRubrique = null;
        if (!empty($codeRubrique)) {
            $stmtRubrique->execute([$codeRubrique]);
            $resultRubrique = $stmtRubrique->fetch(PDO::FETCH_ASSOC);
            if ($resultRubrique) {
                $idRubrique = $resultRubrique['id'];
            }
        }

        // Compte Oracle
        $compteOracle = trim($ligne[3] ?? '');
        $compteOracle = !empty($compteOracle) ? $compteOracle : null;

        $mois = [];
        for ($i = 4; $i <= 15; $i++) {
            $valeur = isset($ligne[$i]) ? floatval($ligne[$i]) : 0;
            $mois[] = $valeur;
        }

        // S'assurer qu'on a 12 mois
        while (count($mois) < 12) {
            $mois[] = 0;
        }

        $stmt->execute([
            $id_version,
            $compte,
            $idRubrique,
            $compteOracle,
            $mois[0],  // Janvier
            $mois[1],  // Février
            $mois[2],  // Mars
            $mois[3],  // Avril
            $mois[4],  // Mai
            $mois[5],  // Juin
            $mois[6],  // Juillet
            $mois[7],  // Août
            $mois[8],  // Septembre
            $mois[9],  // Octobre
            $mois[10], // Novembre
            $mois[11]  // Décembre
        ]);

        $compteurLignes++;
    }

    $db->commit();

    // Redirection avec succès
    header("Location: dashboard.php?annee=$annee&version=$id_version&success=1&imported=$compteurLignes");
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $errorMsg = urlencode($e->getMessage());
    header("Location: import_budget.php?error=$errorMsg");
    exit;
}
