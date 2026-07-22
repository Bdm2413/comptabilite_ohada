<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Migrations
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN periodicite ENUM('annuelle','mensuelle') DEFAULT 'annuelle'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement ADD COLUMN mois TINYINT DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement DROP INDEX unique_immo_exercice"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE dotations_amortissement ADD UNIQUE KEY unique_immo_exercice_mois (immobilisation_id, exercice, mois)"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN id_ecriture_cession INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE immobilisations ADD COLUMN parent_id INT NULL"); } catch (Exception $e) {}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: liste.php'); exit; }

$stmt = $db->prepare("SELECT * FROM immobilisations WHERE id=? AND societe_id=?");
$stmt->execute([$id, $societe_id]);
$immo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$immo) { header('Location: liste.php'); exit; }

$message = '';
$messageType = '';

// Générer une dotation (annuelle ou mensuelle)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generer_dotation') {

    $periodicite = $immo['periodicite'] ?? 'annuelle';

    if (!$immo['amortissable'] || !$immo['duree_amortissement']) {
        $message = "Cette immobilisation n'est pas amortissable ou sa durée n'est pas définie.";
        $messageType = 'error';
    } elseif (!$immo['compte_amortissement'] || !$immo['compte_dotation']) {
        $message = "Veuillez définir les comptes d'amortissement et de dotation avant de comptabiliser.";
        $messageType = 'error';
    } else {
        if ($periodicite === 'mensuelle') {
            // --- Mode mensuel ---
            $periode_key = $_POST['periode_key'] ?? ''; // format "AAAA-MM"
            [$exercice, $mois] = array_map('intval', explode('-', $periode_key));

            $check = $db->prepare("SELECT id FROM dotations_amortissement WHERE immobilisation_id=? AND exercice=? AND mois=?");
            $check->execute([$id, $exercice, $mois]);
            if ($check->fetchColumn()) {
                $message = "La dotation de " . sprintf('%02d/%d', $mois, $exercice) . " est déjà comptabilisée.";
                $messageType = 'error';
            } else {
                $tableMensuel = calculerTableauMensuel($immo);
                $dotationLine = null;
                foreach ($tableMensuel as $line) {
                    if ($line['exercice'] == $exercice && $line['mois'] == $mois) { $dotationLine = $line; break; }
                }
                if (!$dotationLine || $dotationLine['montant'] <= 0) {
                    $message = "Aucune dotation calculée pour cette période.";
                    $messageType = 'error';
                } else {
                    try {
                        $db->beginTransaction();
                        $journal = obtenirJournalOD($db, $societe_id);

                        $lastDay = date('Y-m-t', mktime(0, 0, 0, $mois, 1, $exercice));
                        $nomMois = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'][$mois];
                        $libelle_ecriture = "DAM " . $nomMois . " $exercice - " . mb_substr($immo['designation'], 0, 40);
                        $montant = round($dotationLine['montant'], 2);
                        $numero_ecriture = $journal . sprintf('%02d', $mois) . str_pad($exercice % 100, 2, '0', STR_PAD_LEFT) . str_pad($id, 4, '0', STR_PAD_LEFT);

                        $stmt_e = $db->prepare("INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, journal, libelle, mois, annee, statut, montant_total, date_creation) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                        $stmt_e->execute([$societe_id, $numero_ecriture, $lastDay, $journal, $libelle_ecriture, $mois, $exercice, 'Validé', $montant]);
                        $id_ecriture = $db->lastInsertId();

                        $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,?,0)");
                        $stmt_l->execute([$id_ecriture, $societe_id, $immo['compte_dotation'], $libelle_ecriture, $montant]);
                        $stmt_l2 = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,0,?)");
                        $stmt_l2->execute([$id_ecriture, $societe_id, $immo['compte_amortissement'], $libelle_ecriture, $montant]);

                        $stmt_d = $db->prepare("INSERT INTO dotations_amortissement (societe_id, immobilisation_id, exercice, mois, date_dotation, montant, id_ecriture, statut) VALUES (?,?,?,?,?,?,?,'comptabilise')");
                        $stmt_d->execute([$societe_id, $id, $exercice, $mois, $lastDay, $montant, $id_ecriture]);

                        $db->commit();
                        $message = "Dotation de " . number_format($montant, 0, ',', ' ') . " FCFA comptabilisée pour $nomMois $exercice (écriture $numero_ecriture).";
                        $messageType = 'success';
                        logActivity('Génération dotation mensuelle', 'dotations_amortissement', $id_ecriture, $immo['designation']);
                    } catch (Exception $ex) {
                        $db->rollBack();
                        $message = 'Erreur: ' . $ex->getMessage();
                        $messageType = 'error';
                    }
                }
            }
        } else {
            // --- Mode annuel ---
            $exercice = (int)$_POST['exercice'];

            $check = $db->prepare("SELECT id FROM dotations_amortissement WHERE immobilisation_id=? AND exercice=? AND mois=0");
            $check->execute([$id, $exercice]);
            if ($check->fetchColumn()) {
                $message = "La dotation de l'exercice $exercice est déjà comptabilisée.";
                $messageType = 'error';
            } else {
                $schedule = calculerTableauAmortissement($immo);
                $dotationLine = null;
                foreach ($schedule as $line) {
                    if ($line['exercice'] == $exercice) { $dotationLine = $line; break; }
                }
                if (!$dotationLine || $dotationLine['dotation'] <= 0) {
                    $message = "Aucune dotation calculée pour l'exercice $exercice.";
                    $messageType = 'error';
                } else {
                    try {
                        $db->beginTransaction();
                        $journal = obtenirJournalOD($db, $societe_id);

                        $date_dotation = $exercice . '-12-31';
                        $libelle_ecriture = "Dotation amortissement - " . mb_substr($immo['designation'], 0, 50) . " - " . $exercice;
                        $montant = round($dotationLine['dotation'], 2);
                        $numero_ecriture = $journal . '1231' . str_pad($id, 4, '0', STR_PAD_LEFT) . substr($exercice, -2);

                        $stmt_e = $db->prepare("INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, journal, libelle, mois, annee, statut, montant_total, date_creation) VALUES (?,?,?,?,?,12,?,?,?,NOW())");
                        $stmt_e->execute([$societe_id, $numero_ecriture, $date_dotation, $journal, $libelle_ecriture, $exercice, 'Validé', $montant]);
                        $id_ecriture = $db->lastInsertId();

                        $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,?,0)");
                        $stmt_l->execute([$id_ecriture, $societe_id, $immo['compte_dotation'], $libelle_ecriture, $montant]);
                        $stmt_l2 = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,0,?)");
                        $stmt_l2->execute([$id_ecriture, $societe_id, $immo['compte_amortissement'], $libelle_ecriture, $montant]);

                        $stmt_d = $db->prepare("INSERT INTO dotations_amortissement (societe_id, immobilisation_id, exercice, mois, date_dotation, montant, id_ecriture, statut) VALUES (?,?,?,0,?,?,?,'comptabilise')");
                        $stmt_d->execute([$societe_id, $id, $exercice, $date_dotation, $montant, $id_ecriture]);

                        $db->commit();
                        $message = "Dotation de " . number_format($montant, 0, ',', ' ') . " FCFA comptabilisée pour l'exercice $exercice (écriture $numero_ecriture).";
                        $messageType = 'success';
                        logActivity('Génération dotation annuelle', 'dotations_amortissement', $id_ecriture, $immo['designation']);
                    } catch (Exception $ex) {
                        $db->rollBack();
                        $message = 'Erreur: ' . $ex->getMessage();
                        $messageType = 'error';
                    }
                }
            }
        }
    }
    // Recharger les données
    $stmt = $db->prepare("SELECT * FROM immobilisations WHERE id=? AND societe_id=?");
    $stmt->execute([$id, $societe_id]);
    $immo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Comptabiliser la cession
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comptabiliser_cession') {
    if ($immo['statut'] !== 'cede') {
        $message = "L'immobilisation n'est pas marquée comme cédée.";
        $messageType = 'error';
    } elseif ($immo['id_ecriture_cession']) {
        $message = "La cession a déjà été comptabilisée.";
        $messageType = 'error';
    } elseif (!$immo['valeur_cession'] || !$immo['date_cession']) {
        $message = "Veuillez renseigner la date et la valeur de cession dans la fiche immobilisation.";
        $messageType = 'error';
    } else {
        $compte_vnc         = trim($_POST['compte_vnc'] ?? '');
        $compte_produit     = trim($_POST['compte_produit'] ?? '');
        $compte_contrepartie = trim($_POST['compte_contrepartie'] ?? '');

        if (!$compte_vnc || !$compte_produit || !$compte_contrepartie) {
            $message = "Veuillez renseigner tous les comptes de cession.";
            $messageType = 'error';
        } else {
            $amort_cumul_cession = (float)(function() use ($db, $id) {
                $s = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM dotations_amortissement WHERE immobilisation_id=?");
                $s->execute([$id]);
                return $s->fetchColumn();
            })();
            $vnc_cession  = max(0, $immo['valeur_brute'] - $amort_cumul_cession);
            $val_cession  = (float)$immo['valeur_cession'];
            $date_cess    = $immo['date_cession'];
            $mois_cess    = (int)date('m', strtotime($date_cess));
            $annee_cess   = (int)date('Y', strtotime($date_cess));

            try {
                $db->beginTransaction();
                $journal = obtenirJournalOD($db, $societe_id);
                $designShort = mb_substr($immo['designation'], 0, 35);

                // ── Écriture 1 : sortie du bilan ──
                $libelle1    = "Cession immo - $designShort";
                $num_cess1   = $journal . 'CESS' . $annee_cess . str_pad($id, 4, '0', STR_PAD_LEFT);
                $stmt_e1 = $db->prepare("INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, journal, libelle, mois, annee, statut, montant_total, date_creation) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                $stmt_e1->execute([$societe_id, $num_cess1, $date_cess, $journal, $libelle1, $mois_cess, $annee_cess, 'Validé', $immo['valeur_brute']]);
                $id_e1 = $db->lastInsertId();

                // Débit 28x (amortissements cumulés)
                if ($amort_cumul_cession > 0) {
                    $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,?,0)");
                    $stmt_l->execute([$id_e1, $societe_id, $immo['compte_amortissement'], $libelle1, round($amort_cumul_cession, 2)]);
                }
                // Débit 81x (VNC)
                if ($vnc_cession > 0) {
                    $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,?,0)");
                    $stmt_l->execute([$id_e1, $societe_id, $compte_vnc, $libelle1, round($vnc_cession, 2)]);
                }
                // Crédit 2xx (valeur brute)
                $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,0,?)");
                $stmt_l->execute([$id_e1, $societe_id, $immo['compte_immobilisation'], $libelle1, round($immo['valeur_brute'], 2)]);

                // ── Écriture 2 : produit de cession ──
                $libelle2  = "Produit cession - $designShort";
                $num_cess2 = $journal . 'PROD' . $annee_cess . str_pad($id, 4, '0', STR_PAD_LEFT);
                $stmt_e2 = $db->prepare("INSERT INTO ecritures (societe_id, numero_ecriture, date_ecriture, journal, libelle, mois, annee, statut, montant_total, date_creation) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                $stmt_e2->execute([$societe_id, $num_cess2, $date_cess, $journal, $libelle2, $mois_cess, $annee_cess, 'Validé', $val_cession]);
                $id_e2 = $db->lastInsertId();

                // Débit 5xx/4xx (trésorerie / client)
                $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,?,0)");
                $stmt_l->execute([$id_e2, $societe_id, $compte_contrepartie, $libelle2, round($val_cession, 2)]);
                // Crédit 82x (produit)
                $stmt_l = $db->prepare("INSERT INTO lignes_ecriture (id_ecriture, societe_id, compte, libelle, debit, credit) VALUES (?,?,?,?,0,?)");
                $stmt_l->execute([$id_e2, $societe_id, $compte_produit, $libelle2, round($val_cession, 2)]);

                // Lier la cession à l'immobilisation
                $db->prepare("UPDATE immobilisations SET id_ecriture_cession=? WHERE id=?")->execute([$id_e1, $id]);

                $db->commit();
                $plus_moins = $val_cession - $vnc_cession;
                $label_pm   = $plus_moins >= 0 ? 'plus-value' : 'moins-value';
                $message    = "Cession comptabilisée (écritures $num_cess1 et $num_cess2). " . ucfirst($label_pm) . " : " . number_format(abs($plus_moins), 0, ',', ' ') . " FCFA.";
                $messageType = 'success';
                logActivity('Cession immobilisation', 'immobilisations', $id, $immo['designation']);
            } catch (Exception $ex) {
                $db->rollBack();
                $message = 'Erreur: ' . $ex->getMessage();
                $messageType = 'error';
            }
            // Recharger
            $stmt = $db->prepare("SELECT * FROM immobilisations WHERE id=? AND societe_id=?");
            $stmt->execute([$id, $societe_id]);
            $immo = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// Helper : journal OD
function obtenirJournalOD($db, $societe_id) {
    $stmt = $db->prepare("SELECT code_journal FROM journaux WHERE societe_id=? AND actif=1 AND (LOWER(type_journal) LIKE '%od%' OR LOWER(code_journal)='od') LIMIT 1");
    $stmt->execute([$societe_id]);
    $journal = $stmt->fetchColumn();
    if (!$journal) {
        $stmt = $db->prepare("SELECT code_journal FROM journaux WHERE societe_id=? AND actif=1 LIMIT 1");
        $stmt->execute([$societe_id]);
        $journal = $stmt->fetchColumn();
    }
    if (!$journal) throw new Exception("Aucun journal actif trouvé.");
    return $journal;
}

// Calculer le tableau d'amortissement
function calculerTableauAmortissement($immo) {
    $schedule = [];
    if (!$immo['amortissable'] || !$immo['duree_amortissement']) return $schedule;

    $valeurBase = $immo['valeur_brute'] - ($immo['valeur_residuelle'] ?? 0);
    if ($valeurBase <= 0) return $schedule;

    $annuiteBase = $valeurBase / $immo['duree_amortissement'];
    $dateAcq     = strtotime($immo['date_acquisition']);
    $anneeAcq    = (int)date('Y', $dateAcq);
    $moisAcq     = (int)date('m', $dateAcq);
    $jourAcq     = (int)date('d', $dateAcq);

    // Règle du 15 : si acquis après le 15, le mois d'acquisition ne compte pas
    $moisDebut = ($jourAcq > 15) ? $moisAcq + 1 : $moisAcq;
    if ($moisDebut > 12) { $moisDebut = 1; $anneeAcq++; }

    $prorata1 = (13 - $moisDebut) / 12;

    $amortCumul = 0;
    // S'il y a un prorata < 1, une année supplémentaire est nécessaire
    $nbAnnees = ($prorata1 < 1) ? $immo['duree_amortissement'] + 1 : $immo['duree_amortissement'];

    for ($i = 0; $i < $nbAnnees; $i++) {
        $annee = $anneeAcq + $i;
        if ($i === 0) {
            $dotation = $annuiteBase * $prorata1;
        } elseif ($prorata1 < 1 && $i === $nbAnnees - 1) {
            $dotation = $valeurBase - $amortCumul; // solde restant
        } else {
            $dotation = $annuiteBase;
        }
        $dotation = round(min($dotation, $valeurBase - $amortCumul), 2);
        if ($dotation <= 0.001) break;

        $amortCumul += $dotation;
        $schedule[] = [
            'exercice'    => $annee,
            'taux'        => round(100 / $immo['duree_amortissement'], 2),
            'base'        => $valeurBase,
            'dotation'    => $dotation,
            'amort_cumul' => round($amortCumul, 2),
            'vnc'         => round(max(0, $immo['valeur_brute'] - $amortCumul), 2),
        ];
    }
    return $schedule;
}

// Tableau mensuel : une ligne par mois actif
function calculerTableauMensuel($immo) {
    $entries = [];
    if (!$immo['amortissable'] || !$immo['duree_amortissement']) return $entries;

    $valeurBase = $immo['valeur_brute'] - ($immo['valeur_residuelle'] ?? 0);
    if ($valeurBase <= 0) return $entries;

    $montantMensuel = round($valeurBase / $immo['duree_amortissement'] / 12, 2);
    $totalMois = $immo['duree_amortissement'] * 12;

    $dateAcq = strtotime($immo['date_acquisition']);
    $annee   = (int)date('Y', $dateAcq);
    $moisCur = (int)date('m', $dateAcq);
    $jourAcq = (int)date('d', $dateAcq);

    // Règle du 15 : si acquis après le 15, le mois d'acquisition ne compte pas
    if ($jourAcq > 15) {
        $moisCur++;
        if ($moisCur > 12) { $moisCur = 1; $annee++; }
    }

    $amortCumul = 0;

    for ($i = 0; $i < $totalMois; $i++) {
        $montant = ($i === $totalMois - 1) ? round($valeurBase - $amortCumul, 2) : $montantMensuel;
        $amortCumul += $montant;
        $entries[] = [
            'exercice'    => $annee,
            'mois'        => $moisCur,
            'montant'     => $montant,
            'amort_cumul' => round($amortCumul, 2),
            'vnc'         => round(max(0, $immo['valeur_brute'] - $amortCumul), 2),
        ];
        $moisCur++;
        if ($moisCur > 12) { $moisCur = 1; $annee++; }
    }
    return $entries;
}

$schedule = calculerTableauAmortissement($immo);
$anneeActuelle = (int)date('Y');

$periodicite = $immo['periodicite'] ?? 'annuelle';

// Dotations déjà comptabilisées
$stmt_d = $db->prepare("SELECT d.*, e.numero_ecriture FROM dotations_amortissement d LEFT JOIN ecritures e ON d.id_ecriture = e.id WHERE d.immobilisation_id=? ORDER BY d.exercice, d.mois");
$stmt_d->execute([$id]);
$dotations_faites = $stmt_d->fetchAll(PDO::FETCH_ASSOC);

// Index des périodes déjà faites
$dotations_faites_index = [];
foreach ($dotations_faites as $df) {
    $key = $periodicite === 'mensuelle' ? $df['exercice'] . '-' . sprintf('%02d', $df['mois']) : (string)$df['exercice'];
    $dotations_faites_index[$key] = true;
}
// Pour le plan théorique annuel (indicateur ✓)
$dotations_faites_exercices = [];
foreach ($dotations_faites as $df) {
    $dotations_faites_exercices[$df['exercice']] = true;
}

// Périodes disponibles selon la périodicité
$moisActuel   = (int)date('m');
$periodes_disponibles = [];

if ($periodicite === 'mensuelle') {
    $tableMensuel = calculerTableauMensuel($immo);
    $nomsMois = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    foreach ($tableMensuel as $line) {
        $isFutur = ($line['exercice'] > $anneeActuelle) || ($line['exercice'] == $anneeActuelle && $line['mois'] > $moisActuel);
        if ($isFutur) continue;
        $key = $line['exercice'] . '-' . sprintf('%02d', $line['mois']);
        if (!isset($dotations_faites_index[$key])) {
            $periodes_disponibles[] = [
                'key'     => $key,
                'label'   => $nomsMois[$line['mois']] . ' ' . $line['exercice'],
                'montant' => $line['montant'],
            ];
        }
    }
} else {
    foreach ($schedule as $line) {
        $key = (string)$line['exercice'];
        if ($line['exercice'] <= $anneeActuelle && !isset($dotations_faites_index[$key])) {
            $periodes_disponibles[] = [
                'key'     => $key,
                'label'   => (string)$line['exercice'],
                'montant' => $line['dotation'],
            ];
        }
    }
}

$categories = [
    'incorporelle' => 'Immobilisation incorporelle', 'terrain' => 'Terrain',
    'batiment' => 'Bâtiment', 'amenagement' => 'Aménagement & installation',
    'materiel_mobilier' => 'Matériel & mobilier', 'materiel_transport' => 'Matériel de transport',
    'materiel_info' => 'Matériel informatique', 'financiere' => 'Immobilisation financière', 'autre' => 'Autre',
];
$amort_cumul_total = array_sum(array_column($dotations_faites, 'montant'));
$vnc_actuelle = max(0, $immo['valeur_brute'] - $amort_cumul_total);

// Comptes suggérés pour la cession selon le compte d'immobilisation
$cpte_immo = $immo['compte_immobilisation'] ?? '';
$cpte_prefix = substr($cpte_immo, 0, 2);
$suggest_vnc     = ($cpte_prefix === '20') ? '811' : '812';
$suggest_produit = ($cpte_prefix === '20') ? '821' : '822';
$suggest_contrep = '521'; // Banque par défaut

// Infos cession
$vnc_cession = max(0, $immo['valeur_brute'] - $amort_cumul_total);
$plus_moins_value = ($immo['valeur_cession'] ?? 0) - $vnc_cession;

// Composants de cette immobilisation
$stmt_comp = $db->prepare("
    SELECT i.*, COALESCE(SUM(d.montant),0) as amort_cumul
    FROM immobilisations i
    LEFT JOIN dotations_amortissement d ON i.id = d.immobilisation_id
    WHERE i.parent_id = ? AND i.societe_id = ?
    GROUP BY i.id
    ORDER BY i.date_acquisition, i.designation
");
$stmt_comp->execute([$id, $societe_id]);
$composants = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);

// Immobilisation parente (si ce bien est lui-même un composant)
$parent = null;
if ($immo['parent_id']) {
    $sp = $db->prepare("SELECT id, designation, reference FROM immobilisations WHERE id=? AND societe_id=?");
    $sp->execute([$immo['parent_id'], $societe_id]);
    $parent = $sp->fetch(PDO::FETCH_ASSOC);
}

// Si ce bien a des composants, agréger leurs valeurs (pas les siennes propres)
$has_composants = !empty($composants);
if ($has_composants) {
    $amort_cumul_agg  = array_sum(array_column($composants, 'amort_cumul'));
    $valeur_brute_agg = array_sum(array_column($composants, 'valeur_brute'));
    $vnc_agg          = max(0, $valeur_brute_agg - $amort_cumul_agg);
}

// Écriture cession déjà générée ?
$ecriture_cession = null;
if ($immo['id_ecriture_cession']) {
    $st = $db->prepare("SELECT id, numero_ecriture FROM ecritures WHERE id=?");
    $st->execute([$immo['id_ecriture_cession']]);
    $ecriture_cession = $st->fetch(PDO::FETCH_ASSOC);
    // Also get the produit ecriture (second one)
    $st2 = $db->prepare("SELECT id, numero_ecriture FROM ecritures WHERE societe_id=? AND libelle LIKE ? ORDER BY id LIMIT 1");
    $st2->execute([$societe_id, 'Produit cession - ' . mb_substr($immo['designation'], 0, 35) . '%']);
    $ecriture_cession_produit = $st2->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($immo['designation']) ?> - Immobilisations</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
    <?php include '../../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="p-6 space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <a href="liste.php" class="hover:text-amber-400 transition">Immobilisations</a>
                <?php if ($parent): ?>
                <i class="fas fa-chevron-right text-xs"></i>
                <a href="voir.php?id=<?= $parent['id'] ?>" class="hover:text-amber-400 transition"><?= htmlspecialchars($parent['designation']) ?></a>
                <?php endif; ?>
                <i class="fas fa-chevron-right text-xs"></i>
                <span class="text-white"><?= htmlspecialchars($immo['designation']) ?></span>
                <?php if ($immo['parent_id']): ?>
                <span class="px-1.5 py-0.5 rounded text-[10px] bg-blue-500/10 border border-blue-500/20 text-blue-400">composant</span>
                <?php endif; ?>
            </div>

            <?php if ($message): ?>
            <div class="p-3 rounded-lg <?= $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/40 text-emerald-400' : 'bg-red-500/10 border border-red-500/40 text-red-400' ?>">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <!-- Fiche immobilisation -->
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-5">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h1 class="text-xl font-bold text-white"><?= htmlspecialchars($immo['designation']) ?></h1>
                        <?php if ($immo['reference']): ?>
                        <span class="text-xs text-slate-400 font-mono"><?= htmlspecialchars($immo['reference']) ?></span>
                        <?php endif; ?>
                        <span class="ml-2 px-2 py-0.5 rounded text-xs bg-slate-700 text-slate-300"><?= $categories[$immo['categorie']] ?? $immo['categorie'] ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php
                        $statusColors = ['en_service' => 'text-emerald-400 bg-emerald-500/20 border-emerald-500/40', 'cede' => 'text-amber-400 bg-amber-500/20 border-amber-500/40', 'rebute' => 'text-red-400 bg-red-500/20 border-red-500/40'];
                        $statusLabels = ['en_service' => 'En service', 'cede' => 'Cédé', 'rebute' => 'Mis au rebut'];
                        ?>
                        <span class="px-3 py-1 rounded-lg text-sm border <?= $statusColors[$immo['statut']] ?>">
                            <?= $statusLabels[$immo['statut']] ?>
                        </span>
                        <a href="liste.php" class="p-2 text-slate-400 hover:text-white hover:bg-slate-700 rounded-lg transition">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    </div>
                </div>

                <?php if ($has_composants): ?>
                <div class="mb-3 px-3 py-2 rounded-lg bg-purple-500/5 border border-purple-500/20 text-purple-300 text-xs flex items-center gap-2">
                    <i class="fas fa-puzzle-piece text-purple-400"></i>
                    <span>Ce bien est géré <strong>par composants</strong>. Les valeurs ci-dessous sont la somme de ses <?= count($composants) ?> composant<?= count($composants) > 1 ? 's' : '' ?>. L'amortissement se fait exclusivement au niveau de chaque composant.</span>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <p class="text-xs text-slate-500">Date d'acquisition</p>
                        <p class="text-sm text-white font-mono"><?= date('d/m/Y', strtotime($immo['date_acquisition'])) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Valeur brute <?= $has_composants ? '<span class="text-purple-400">(Σ composants)</span>' : '' ?></p>
                        <p class="text-sm text-blue-400 font-mono font-semibold"><?= number_format($has_composants ? $valeur_brute_agg : $immo['valeur_brute'], 0, ',', ' ') ?> FCFA</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Amort. cumulé <?= $has_composants ? '<span class="text-purple-400">(Σ composants)</span>' : '(comptabilisé)' ?></p>
                        <p class="text-sm text-red-400 font-mono font-semibold"><?= number_format($has_composants ? $amort_cumul_agg : $amort_cumul_total, 0, ',', ' ') ?> FCFA</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">VNC actuelle <?= $has_composants ? '<span class="text-purple-400">(Σ composants)</span>' : '' ?></p>
                        <p class="text-sm text-emerald-400 font-mono font-semibold"><?= number_format($has_composants ? $vnc_agg : $vnc_actuelle, 0, ',', ' ') ?> FCFA</p>
                    </div>
                    <?php if ($immo['amortissable'] && $immo['duree_amortissement']): ?>
                    <div>
                        <p class="text-xs text-slate-500">Durée amortissement</p>
                        <p class="text-sm text-white"><?= $immo['duree_amortissement'] ?> ans (<?= round(100 / $immo['duree_amortissement'], 2) ?>%)</p>
                    </div>
                    <?php endif; ?>
                    <?php if ($immo['compte_immobilisation']): ?>
                    <div>
                        <p class="text-xs text-slate-500">Compte immobilisation</p>
                        <p class="text-sm text-white font-mono"><?= $immo['compte_immobilisation'] ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($immo['compte_amortissement']): ?>
                    <div>
                        <p class="text-xs text-slate-500">Compte amortissement</p>
                        <p class="text-sm text-white font-mono"><?= $immo['compte_amortissement'] ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($immo['compte_dotation']): ?>
                    <div>
                        <p class="text-xs text-slate-500">Compte dotation</p>
                        <p class="text-sm text-white font-mono"><?= $immo['compte_dotation'] ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($immo['fournisseur']): ?>
                    <div>
                        <p class="text-xs text-slate-500">Fournisseur</p>
                        <p class="text-sm text-white"><?= htmlspecialchars($immo['fournisseur']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($has_composants): ?>
            <!-- Message : amortissement délégué aux composants -->
            <div class="bg-slate-800 border border-purple-500/20 rounded-xl p-5 flex items-start gap-4">
                <div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-puzzle-piece text-purple-400"></i>
                </div>
                <div>
                    <p class="text-sm font-semibold text-white mb-1">Amortissement géré par composants</p>
                    <p class="text-xs text-slate-400">
                        Conformément au SYSCOHADA révisé, ce bien est décomposé en <?= count($composants) ?> composant<?= count($composants) > 1 ? 's' : '' ?>.
                        Chaque composant possède son propre plan d'amortissement et ses propres dotations.
                        Le bien principal n'est pas lui-même amortissable.
                        Consultez chaque composant ci-dessous pour comptabiliser les dotations.
                    </p>
                </div>
            </div>
            <?php else: ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Tableau d'amortissement théorique -->
                <?php
                $planVide     = ($periodicite === 'mensuelle') ? empty($tableMensuel) : empty($schedule);
                $nomsMoisPlan = ['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
                ?>
                <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-700 flex items-center justify-between">
                        <h2 class="font-semibold text-white text-sm flex items-center gap-2">
                            <i class="fas fa-table text-amber-400"></i>
                            Plan d'amortissement théorique
                        </h2>
                        <span class="text-xs px-2 py-0.5 rounded border <?= $periodicite === 'mensuelle' ? 'bg-blue-500/10 border-blue-500/30 text-blue-400' : 'bg-slate-700 border-slate-600 text-slate-400' ?>">
                            <?= $periodicite === 'mensuelle' ? 'Mensuel' : 'Annuel' ?>
                        </span>
                    </div>
                    <?php if ($planVide): ?>
                    <p class="p-4 text-slate-400 text-sm text-center">Non amortissable ou durée non définie.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto overflow-y-auto max-h-80">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-900/50 sticky top-0">
                                <tr class="border-b border-slate-700 text-slate-400">
                                    <th class="px-3 py-2 text-left"><?= $periodicite === 'mensuelle' ? 'Période' : 'Exercice' ?></th>
                                    <?php if ($periodicite === 'annuelle'): ?>
                                    <th class="px-3 py-2 text-right">Taux</th>
                                    <?php endif; ?>
                                    <th class="px-3 py-2 text-right">Dotation</th>
                                    <th class="px-3 py-2 text-right">Amort. cumulé</th>
                                    <th class="px-3 py-2 text-right">VNC</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/50">
                            <?php if ($periodicite === 'mensuelle'): ?>
                                <?php foreach ($tableMensuel as $line):
                                    $isCurrent   = ($line['exercice'] == $anneeActuelle && $line['mois'] == $moisActuel);
                                    $cleIdx      = $line['exercice'] . '-' . sprintf('%02d', $line['mois']);
                                    $isComptabilise = isset($dotations_faites_index[$cleIdx]);
                                ?>
                                <tr class="<?= $isCurrent ? 'bg-amber-500/5' : 'hover:bg-slate-700/20' ?> transition-colors">
                                    <td class="px-3 py-2 font-mono <?= $isCurrent ? 'text-amber-400 font-bold' : 'text-slate-300' ?>">
                                        <?= $nomsMoisPlan[$line['mois']] ?> <?= $line['exercice'] ?>
                                        <?php if ($isComptabilise): ?>
                                        <i class="fas fa-check-circle text-emerald-400 ml-1" title="Comptabilisé"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-blue-400"><?= number_format($line['montant'], 0, ',', ' ') ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-red-400"><?= number_format($line['amort_cumul'], 0, ',', ' ') ?></td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold text-emerald-400"><?= number_format($line['vnc'], 0, ',', ' ') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($schedule as $line):
                                    $isCurrent      = ($line['exercice'] == $anneeActuelle);
                                    $isComptabilise = isset($dotations_faites_exercices[$line['exercice']]);
                                ?>
                                <tr class="<?= $isCurrent ? 'bg-amber-500/5' : 'hover:bg-slate-700/20' ?> transition-colors">
                                    <td class="px-3 py-2 font-mono <?= $isCurrent ? 'text-amber-400 font-bold' : 'text-slate-300' ?>">
                                        <?= $line['exercice'] ?>
                                        <?php if ($isComptabilise): ?>
                                        <i class="fas fa-check-circle text-emerald-400 ml-1" title="Comptabilisé"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-right text-slate-400"><?= $line['taux'] ?>%</td>
                                    <td class="px-3 py-2 text-right font-mono text-blue-400"><?= number_format($line['dotation'], 0, ',', ' ') ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-red-400"><?= number_format($line['amort_cumul'], 0, ',', ' ') ?></td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold text-emerald-400"><?= number_format($line['vnc'], 0, ',', ' ') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Dotations comptabilisées + Génération -->
                <div class="space-y-4">

                    <!-- Formulaire de génération -->
                    <?php if ($immo['amortissable'] && !empty($periodes_disponibles)): ?>
                    <div class="bg-slate-800 border border-amber-500/30 rounded-xl p-4">
                        <h2 class="font-semibold text-white text-sm mb-3 flex items-center gap-2">
                            <i class="fas fa-magic text-amber-400"></i>
                            Comptabiliser une dotation
                            <span class="ml-2 px-2 py-0.5 rounded text-xs border <?= $periodicite === 'mensuelle' ? 'bg-blue-500/10 border-blue-500/30 text-blue-400' : 'bg-slate-700 border-slate-600 text-slate-400' ?>">
                                <?= $periodicite === 'mensuelle' ? 'Mensuelle' : 'Annuelle' ?>
                            </span>
                        </h2>
                        <form method="POST" class="flex items-end gap-3">
                            <input type="hidden" name="action" value="generer_dotation">
                            <div class="flex-1">
                                <label class="block text-xs text-slate-400 mb-1">
                                    <?= $periodicite === 'mensuelle' ? 'Mois à comptabiliser' : 'Exercice' ?>
                                </label>
                                <?php if ($periodicite === 'mensuelle'): ?>
                                <select name="periode_key" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                                    <?php foreach ($periodes_disponibles as $p): ?>
                                    <option value="<?= $p['key'] ?>"><?= $p['label'] ?> — <?= number_format($p['montant'], 0, ',', ' ') ?> FCFA</option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <select name="exercice" class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white focus:ring-2 focus:ring-amber-500">
                                    <?php foreach ($periodes_disponibles as $p): ?>
                                    <option value="<?= $p['key'] ?>"><?= $p['label'] ?> — <?= number_format($p['montant'], 0, ',', ' ') ?> FCFA</option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                            <button type="submit" onclick="return confirm('Comptabiliser cette dotation ?')" class="px-4 py-2 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-lg text-sm transition whitespace-nowrap">
                                <i class="fas fa-check mr-1"></i> Comptabiliser
                            </button>
                        </form>
                    </div>
                    <?php elseif ($immo['amortissable'] && empty($periodes_disponibles) && !empty($schedule)): ?>
                    <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-4 text-emerald-400 text-sm text-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        Toutes les dotations disponibles ont été comptabilisées.
                    </div>
                    <?php endif; ?>

                    <!-- Historique des dotations -->
                    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-slate-700">
                            <h2 class="font-semibold text-white text-sm flex items-center gap-2">
                                <i class="fas fa-history text-emerald-400"></i>
                                Dotations comptabilisées
                            </h2>
                        </div>
                        <?php if (empty($dotations_faites)): ?>
                        <p class="p-4 text-slate-400 text-sm text-center">Aucune dotation comptabilisée.</p>
                        <?php else: ?>
                        <?php
                        $nomsMoisHist = ['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
                        ?>
                        <table class="w-full text-xs">
                            <thead class="bg-slate-900/50">
                                <tr class="border-b border-slate-700 text-slate-400">
                                    <th class="px-3 py-2 text-left">Période</th>
                                    <th class="px-3 py-2 text-left">Date</th>
                                    <th class="px-3 py-2 text-right">Montant</th>
                                    <th class="px-3 py-2 text-left">N° écriture</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/50">
                            <?php foreach ($dotations_faites as $dot): ?>
                                <tr class="hover:bg-slate-700/20">
                                    <td class="px-3 py-2 font-mono text-amber-400">
                                        <?php if ($dot['mois'] > 0): ?>
                                            <?= $nomsMoisHist[$dot['mois']] ?> <?= $dot['exercice'] ?>
                                        <?php else: ?>
                                            <?= $dot['exercice'] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-400"><?= date('d/m/Y', strtotime($dot['date_dotation'])) ?></td>
                                    <td class="px-3 py-2 text-right font-mono text-emerald-400"><?= number_format($dot['montant'], 0, ',', ' ') ?></td>
                                    <td class="px-3 py-2 text-xs">
                                        <?php if ($dot['id_ecriture'] && $dot['numero_ecriture']): ?>
                                        <a href="../ecritures/voir.php?id=<?= $dot['id_ecriture'] ?>" class="font-mono text-blue-400 hover:text-blue-300 hover:underline transition" title="Voir l'écriture">
                                            <?= htmlspecialchars($dot['numero_ecriture']) ?>
                                            <i class="fas fa-external-link-alt text-[9px] ml-0.5 opacity-60"></i>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-slate-500">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-900/30 border-t border-slate-600">
                                <tr>
                                    <td colspan="2" class="px-3 py-2 text-slate-400 text-xs font-semibold uppercase">Total</td>
                                    <td class="px-3 py-2 text-right font-mono font-bold text-white"><?= number_format($amort_cumul_total, 0, ',', ' ') ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; // end else (has_composants) ?>

            <?php if (!empty($composants) || !$immo['parent_id']): ?>
            <!-- ── Composants ── -->
            <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700 flex items-center justify-between">
                    <h2 class="font-semibold text-white text-sm flex items-center gap-2">
                        <i class="fas fa-puzzle-piece text-purple-400"></i>
                        Composants
                        <?php if (!empty($composants)): ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-purple-500/10 border border-purple-500/20 text-purple-400"><?= count($composants) ?></span>
                        <?php endif; ?>
                    </h2>
                    <a href="liste.php?parent_id=<?= $id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-500/10 hover:bg-purple-500/20 border border-purple-500/30 text-purple-400 rounded-lg text-xs transition">
                        <i class="fas fa-plus text-[10px]"></i> Ajouter un composant
                    </a>
                </div>
                <?php if (empty($composants)): ?>
                <p class="p-5 text-slate-500 text-sm text-center">
                    Aucun composant. Cliquez sur "Ajouter un composant" pour décomposer ce bien.
                </p>
                <?php else: ?>
                <?php
                $total_comp_brut  = array_sum(array_column($composants, 'valeur_brute'));
                $total_comp_amort = array_sum(array_column($composants, 'amort_cumul'));
                $total_comp_vnc   = $total_comp_brut - $total_comp_amort;
                ?>
                <table class="w-full text-xs">
                    <thead class="bg-slate-900/50">
                        <tr class="border-b border-slate-700 text-slate-400">
                            <th class="px-4 py-2 text-left">Composant</th>
                            <th class="px-4 py-2 text-left">Durée</th>
                            <th class="px-4 py-2 text-right">Valeur brute</th>
                            <th class="px-4 py-2 text-right">Amort. cumulé</th>
                            <th class="px-4 py-2 text-right">VNC</th>
                            <th class="px-4 py-2 text-center">Statut</th>
                            <th class="px-4 py-2 text-center"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                    <?php foreach ($composants as $c):
                        $c_vnc = max(0, $c['valeur_brute'] - $c['amort_cumul']);
                        $cStatusColors = ['en_service' => 'text-emerald-400 bg-emerald-500/10', 'cede' => 'text-amber-400 bg-amber-500/10', 'rebute' => 'text-red-400 bg-red-500/10'];
                        $cStatusLabels = ['en_service' => 'En service', 'cede' => 'Cédé', 'rebute' => 'Mis au rebut'];
                    ?>
                    <tr class="hover:bg-slate-700/20 transition-colors">
                        <td class="px-4 py-2">
                            <a href="voir.php?id=<?= $c['id'] ?>" class="text-slate-200 hover:text-amber-400 transition font-medium">
                                <?= htmlspecialchars($c['designation']) ?>
                            </a>
                            <?php if ($c['reference']): ?>
                            <span class="block text-slate-500 font-mono"><?= htmlspecialchars($c['reference']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-slate-400">
                            <?= $c['amortissable'] && $c['duree_amortissement'] ? $c['duree_amortissement'] . ' ans' : '<span class="text-slate-600">—</span>' ?>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-blue-400"><?= number_format($c['valeur_brute'], 0, ',', ' ') ?></td>
                        <td class="px-4 py-2 text-right font-mono text-red-400"><?= $c['amort_cumul'] > 0 ? number_format($c['amort_cumul'], 0, ',', ' ') : '—' ?></td>
                        <td class="px-4 py-2 text-right font-mono font-semibold text-emerald-400"><?= number_format($c_vnc, 0, ',', ' ') ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="px-2 py-0.5 rounded text-xs <?= $cStatusColors[$c['statut']] ?>">
                                <?= $cStatusLabels[$c['statut']] ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-center">
                            <a href="voir.php?id=<?= $c['id'] ?>" class="p-1 text-slate-400 hover:text-amber-400 hover:bg-amber-500/10 rounded transition" title="Voir">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-slate-900/30 border-t border-slate-600">
                        <tr class="text-xs font-semibold">
                            <td colspan="2" class="px-4 py-2 text-slate-400 uppercase">Total composants</td>
                            <td class="px-4 py-2 text-right font-mono text-blue-400"><?= number_format($total_comp_brut, 0, ',', ' ') ?></td>
                            <td class="px-4 py-2 text-right font-mono text-red-400"><?= number_format($total_comp_amort, 0, ',', ' ') ?></td>
                            <td class="px-4 py-2 text-right font-mono text-emerald-400 font-bold"><?= number_format(max(0, $total_comp_vnc), 0, ',', ' ') ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($immo['statut'] === 'cede'): ?>
            <!-- ── Cession de l'immobilisation ── -->
            <div class="bg-slate-800 border border-amber-500/40 rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-amber-500/30 bg-amber-500/5 flex items-center justify-between">
                    <h2 class="font-semibold text-white text-sm flex items-center gap-2">
                        <i class="fas fa-exchange-alt text-amber-400"></i>
                        Cession de l'immobilisation
                    </h2>
                    <?php if ($immo['id_ecriture_cession']): ?>
                    <span class="px-2 py-0.5 rounded text-xs bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
                        <i class="fas fa-check-circle mr-1"></i>Comptabilisée
                    </span>
                    <?php else: ?>
                    <span class="px-2 py-0.5 rounded text-xs bg-amber-500/10 border border-amber-500/30 text-amber-400">
                        <i class="fas fa-clock mr-1"></i>Non comptabilisée
                    </span>
                    <?php endif; ?>
                </div>

                <div class="p-5 space-y-4">
                    <!-- Récapitulatif financier -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-slate-900/50 rounded-lg p-3">
                            <p class="text-xs text-slate-500 mb-1">Date de cession</p>
                            <p class="text-sm text-white font-mono font-semibold">
                                <?= $immo['date_cession'] ? date('d/m/Y', strtotime($immo['date_cession'])) : '<span class="text-red-400">Non renseignée</span>' ?>
                            </p>
                        </div>
                        <div class="bg-slate-900/50 rounded-lg p-3">
                            <p class="text-xs text-slate-500 mb-1">Prix de cession</p>
                            <p class="text-sm text-blue-400 font-mono font-semibold">
                                <?= $immo['valeur_cession'] ? number_format($immo['valeur_cession'], 0, ',', ' ') . ' FCFA' : '<span class="text-red-400">Non renseigné</span>' ?>
                            </p>
                        </div>
                        <div class="bg-slate-900/50 rounded-lg p-3">
                            <p class="text-xs text-slate-500 mb-1">VNC à la cession</p>
                            <p class="text-sm text-amber-400 font-mono font-semibold"><?= number_format($vnc_cession, 0, ',', ' ') ?> FCFA</p>
                        </div>
                        <div class="bg-slate-900/50 rounded-lg p-3">
                            <p class="text-xs text-slate-500 mb-1">
                                <?= $plus_moins_value >= 0 ? 'Plus-value' : 'Moins-value' ?>
                            </p>
                            <p class="text-sm font-mono font-bold <?= $plus_moins_value >= 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $plus_moins_value >= 0 ? '+' : '' ?><?= number_format($plus_moins_value, 0, ',', ' ') ?> FCFA
                            </p>
                        </div>
                    </div>

                    <?php if ($immo['id_ecriture_cession'] && $ecriture_cession): ?>
                    <!-- Écritures générées -->
                    <div class="bg-slate-900/40 border border-slate-600 rounded-lg p-4">
                        <p class="text-xs text-slate-400 mb-3 uppercase font-semibold tracking-wide">Écritures comptables générées</p>
                        <div class="flex flex-wrap gap-3">
                            <a href="../ecritures/voir.php?id=<?= $ecriture_cession['id'] ?>" class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-lg text-sm text-blue-400 hover:text-blue-300 transition">
                                <i class="fas fa-file-alt text-xs"></i>
                                <span class="font-mono"><?= htmlspecialchars($ecriture_cession['numero_ecriture']) ?></span>
                                <span class="text-slate-400 text-xs">Sortie du bilan</span>
                                <i class="fas fa-external-link-alt text-[9px] opacity-60"></i>
                            </a>
                            <?php if (!empty($ecriture_cession_produit)): ?>
                            <a href="../ecritures/voir.php?id=<?= $ecriture_cession_produit['id'] ?>" class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-lg text-sm text-blue-400 hover:text-blue-300 transition">
                                <i class="fas fa-file-alt text-xs"></i>
                                <span class="font-mono"><?= htmlspecialchars($ecriture_cession_produit['numero_ecriture']) ?></span>
                                <span class="text-slate-400 text-xs">Produit de cession</span>
                                <i class="fas fa-external-link-alt text-[9px] opacity-60"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php elseif (!$immo['id_ecriture_cession']): ?>
                    <!-- Schéma des écritures -->
                    <div class="bg-slate-900/40 border border-slate-700 rounded-lg p-4">
                        <p class="text-xs text-slate-400 mb-3 uppercase font-semibold tracking-wide">Schéma des écritures à générer</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                            <div class="space-y-1.5">
                                <p class="text-slate-500 font-semibold mb-2">Écriture 1 — Sortie du bilan</p>
                                <?php if ($amort_cumul_total > 0): ?>
                                <div class="flex justify-between">
                                    <span class="text-amber-400 font-mono">Débit <?= $immo['compte_amortissement'] ?></span>
                                    <span class="text-slate-300"><?= number_format($amort_cumul_total, 0, ',', ' ') ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($vnc_cession > 0): ?>
                                <div class="flex justify-between">
                                    <span class="text-amber-400 font-mono">Débit <?= $suggest_vnc ?> <span class="text-slate-500">(VNC)</span></span>
                                    <span class="text-slate-300"><?= number_format($vnc_cession, 0, ',', ' ') ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between border-t border-slate-700 pt-1.5 mt-1.5">
                                    <span class="text-blue-400 font-mono">Crédit <?= $immo['compte_immobilisation'] ?></span>
                                    <span class="text-slate-300"><?= number_format($immo['valeur_brute'], 0, ',', ' ') ?></span>
                                </div>
                            </div>
                            <?php if ($immo['valeur_cession']): ?>
                            <div class="space-y-1.5">
                                <p class="text-slate-500 font-semibold mb-2">Écriture 2 — Produit de cession</p>
                                <div class="flex justify-between">
                                    <span class="text-amber-400 font-mono">Débit <?= $suggest_contrep ?> <span class="text-slate-500">(Banque)</span></span>
                                    <span class="text-slate-300"><?= number_format($immo['valeur_cession'], 0, ',', ' ') ?></span>
                                </div>
                                <div class="flex justify-between border-t border-slate-700 pt-1.5 mt-1.5">
                                    <span class="text-blue-400 font-mono">Crédit <?= $suggest_produit ?> <span class="text-slate-500">(Produit)</span></span>
                                    <span class="text-slate-300"><?= number_format($immo['valeur_cession'], 0, ',', ' ') ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Formulaire de comptabilisation -->
                    <?php if ($immo['date_cession'] && $immo['valeur_cession'] && $immo['compte_immobilisation'] && $immo['compte_amortissement']): ?>
                    <form method="POST" class="space-y-3" onsubmit="return confirm('Générer les écritures de cession ?')">
                        <input type="hidden" name="action" value="comptabiliser_cession">
                        <p class="text-xs text-slate-400 uppercase font-semibold tracking-wide">Comptes à utiliser</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Compte VNC <span class="text-slate-500">(81x)</span></label>
                                <input type="text" name="compte_vnc" value="<?= htmlspecialchars($suggest_vnc) ?>"
                                    class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white font-mono focus:ring-2 focus:ring-amber-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Compte produit de cession <span class="text-slate-500">(82x)</span></label>
                                <input type="text" name="compte_produit" value="<?= htmlspecialchars($suggest_produit) ?>"
                                    class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white font-mono focus:ring-2 focus:ring-amber-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Contrepartie <span class="text-slate-500">(trésorerie / client)</span></label>
                                <input type="text" name="compte_contrepartie" value="<?= htmlspecialchars($suggest_contrep) ?>"
                                    class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-white font-mono focus:ring-2 focus:ring-amber-500 focus:border-transparent">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-5 py-2 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-lg text-sm font-semibold transition flex items-center gap-2">
                                <i class="fas fa-calculator"></i>
                                Comptabiliser la cession
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 text-sm">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Veuillez d'abord renseigner la date de cession, le prix de cession, le compte d'immobilisation et le compte d'amortissement dans la liste des immobilisations.
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>
</body>
</html>
