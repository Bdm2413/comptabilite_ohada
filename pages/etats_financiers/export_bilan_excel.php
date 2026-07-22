<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Fonction helper pour number_format
function safe_number_format($number, $decimals = 0, $decimal_separator = ' ', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres
$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$mode = $_GET['mode'] ?? 'detail';
$is_condense = ($mode === 'condense');
$is_with_comptes = ($mode === 'comptes');

// Calculer automatiquement les dates N-1
$date_debut_n1 = date('Y-m-d', strtotime($date_debut . ' -1 year'));
$date_fin_n1 = date('Y-m-d', strtotime($date_fin . ' -1 year'));

// Initialiser les tableaux
$actif = [];
$passif = [];
$actif_n1 = [];
$passif_n1 = [];
$actif_details = [];
$passif_details = [];

// Rubriques pour le cas BD = BC
$rubriques_actif  = ['AE','AF','AG','AH','AJ','AK','AL','AM','AN','AP','AR','AS','AZ','BA','BB','BG','BH','BI','BJ','BK','BQ','BR','BS','BT','BU','BZ'];
$rubriques_passif = ['CA','CB','CD','CE','CF','CG','CH','CJ','CL','CM','CP','DA','DB','DC','DD','DF','DH','DI','DJ','DK','DM','DN','DP','DQ','DR','DT','DV','DZ'];

// Fonction générique de classification d'une liste de comptes dans actif/passif
function classifierComptes(array $comptes, array &$actif, array &$passif,
                           array &$actif_details, array &$passif_details,
                           array $rubriques_actif, array $rubriques_passif,
                           bool $is_with_comptes): void
{
    foreach ($comptes as $compte) {
        $solde   = $compte['total_debit'] - $compte['total_credit'];
        $prefix1 = substr($compte['compte'], 0, 1);
        $prefix2 = substr($compte['compte'], 0, 2);
        $prefix3 = substr($compte['compte'], 0, 3);

        if ($solde == 0) continue;

        // Exclure classes 6, 7, 8 (résultat calculé séparément dans CJ)
        if ($prefix1 == '6' || $prefix1 == '7' || $prefix1 == '8') continue;

        // Exclure compte 13 (remplacé par le calcul CJ)
        if ($prefix2 == '13') continue;

        // Exclure comptes de reclassement et leurs dépréciations
        if ($prefix3 == '416' || $prefix3 == '426' || $prefix3 == '491' || $prefix3 == '496') continue;

        // Cas spécial: comptes d'amortissements/dépréciations (28, 29, 39, 49, 59)
        // → toujours en amort_deprec de l'actif, via leur rubrique BD
        if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
            if (!empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $actif[$ref]['amort_deprec'] += abs($solde);
                $actif[$ref]['net']          -= abs($solde);
                if ($is_with_comptes) {
                    $actif_details[$ref][] = ['compte' => $compte['compte'], 'intitule' => $compte['intitule_compte'], 'solde' => -abs($solde)];
                }
            }
        }
        // Cas 1: BD ≠ BC → actif si solde débiteur, passif si créditeur
        elseif ($compte['bd'] != $compte['bc']) {
            if ($solde > 0 && !empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $actif[$ref]['brut'] += $solde;
                $actif[$ref]['net']  += $solde;
                if ($is_with_comptes) {
                    $actif_details[$ref][] = ['compte' => $compte['compte'], 'intitule' => $compte['intitule_compte'], 'solde' => $solde];
                }
            } elseif ($solde < 0 && !empty($compte['bc'])) {
                $ref = $compte['bc'];
                if (!isset($passif[$ref])) $passif[$ref] = ['net' => 0];
                $passif[$ref]['net'] += abs($solde);
                if ($is_with_comptes) {
                    $passif_details[$ref][] = ['compte' => $compte['compte'], 'intitule' => $compte['intitule_compte'], 'solde' => abs($solde)];
                }
            }
        }
        // Cas 2: BD = BC → la rubrique est fixe, le sens (+ ou −) dépend du type de rubrique
        elseif ($compte['bd'] == $compte['bc'] && !empty($compte['bd'])) {
            $ref = $compte['bd'];
            if (in_array($ref, $rubriques_actif)) {
                if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $actif[$ref]['brut'] += $solde;
                $actif[$ref]['net']  += $solde;
                if ($is_with_comptes) {
                    $actif_details[$ref][] = ['compte' => $compte['compte'], 'intitule' => $compte['intitule_compte'], 'solde' => $solde];
                }
            } elseif (in_array($ref, $rubriques_passif)) {
                if (!isset($passif[$ref])) $passif[$ref] = ['net' => 0];
                $passif[$ref]['net'] -= $solde; // solde créditeur (négatif) augmente le passif
                if ($is_with_comptes) {
                    $passif_details[$ref][] = ['compte' => $compte['compte'], 'intitule' => $compte['intitule_compte'], 'solde' => -$solde];
                }
            }
        }
    }
}

// Fonction pour calculer les sous-totaux ACTIF selon SYSCOHADA
function calculerTotauxActif(array &$actif): void {
    $actif['AD']['brut']       = ($actif['AE']['brut'] ?? 0) + ($actif['AF']['brut'] ?? 0) + ($actif['AG']['brut'] ?? 0) + ($actif['AH']['brut'] ?? 0);
    $actif['AD']['amort_deprec'] = ($actif['AE']['amort_deprec'] ?? 0) + ($actif['AF']['amort_deprec'] ?? 0) + ($actif['AG']['amort_deprec'] ?? 0) + ($actif['AH']['amort_deprec'] ?? 0);
    $actif['AD']['net']        = ($actif['AE']['net'] ?? 0) + ($actif['AF']['net'] ?? 0) + ($actif['AG']['net'] ?? 0) + ($actif['AH']['net'] ?? 0);

    $actif['AI']['brut']       = ($actif['AJ']['brut'] ?? 0) + ($actif['AK']['brut'] ?? 0) + ($actif['AL']['brut'] ?? 0) + ($actif['AM']['brut'] ?? 0) + ($actif['AN']['brut'] ?? 0);
    $actif['AI']['amort_deprec'] = ($actif['AJ']['amort_deprec'] ?? 0) + ($actif['AK']['amort_deprec'] ?? 0) + ($actif['AL']['amort_deprec'] ?? 0) + ($actif['AM']['amort_deprec'] ?? 0) + ($actif['AN']['amort_deprec'] ?? 0);
    $actif['AI']['net']        = ($actif['AJ']['net'] ?? 0) + ($actif['AK']['net'] ?? 0) + ($actif['AL']['net'] ?? 0) + ($actif['AM']['net'] ?? 0) + ($actif['AN']['net'] ?? 0);

    $actif['AQ']['brut']       = ($actif['AR']['brut'] ?? 0) + ($actif['AS']['brut'] ?? 0);
    $actif['AQ']['amort_deprec'] = ($actif['AR']['amort_deprec'] ?? 0) + ($actif['AS']['amort_deprec'] ?? 0);
    $actif['AQ']['net']        = ($actif['AR']['net'] ?? 0) + ($actif['AS']['net'] ?? 0);

    $actif['AZ']['brut']       = ($actif['AD']['brut'] ?? 0) + ($actif['AI']['brut'] ?? 0) + ($actif['AQ']['brut'] ?? 0) + ($actif['AP']['brut'] ?? 0);
    $actif['AZ']['amort_deprec'] = ($actif['AD']['amort_deprec'] ?? 0) + ($actif['AI']['amort_deprec'] ?? 0) + ($actif['AQ']['amort_deprec'] ?? 0) + ($actif['AP']['amort_deprec'] ?? 0);
    $actif['AZ']['net']        = ($actif['AD']['net'] ?? 0) + ($actif['AI']['net'] ?? 0) + ($actif['AQ']['net'] ?? 0) + ($actif['AP']['net'] ?? 0);

    $actif['BG']['brut']       = ($actif['BH']['brut'] ?? 0) + ($actif['BI']['brut'] ?? 0) + ($actif['BJ']['brut'] ?? 0);
    $actif['BG']['amort_deprec'] = ($actif['BH']['amort_deprec'] ?? 0) + ($actif['BI']['amort_deprec'] ?? 0) + ($actif['BJ']['amort_deprec'] ?? 0);
    $actif['BG']['net']        = ($actif['BH']['net'] ?? 0) + ($actif['BI']['net'] ?? 0) + ($actif['BJ']['net'] ?? 0);

    $actif['BK']['brut']       = ($actif['BA']['brut'] ?? 0) + ($actif['BB']['brut'] ?? 0) + ($actif['BG']['brut'] ?? 0);
    $actif['BK']['amort_deprec'] = ($actif['BA']['amort_deprec'] ?? 0) + ($actif['BB']['amort_deprec'] ?? 0) + ($actif['BG']['amort_deprec'] ?? 0);
    $actif['BK']['net']        = ($actif['BA']['net'] ?? 0) + ($actif['BB']['net'] ?? 0) + ($actif['BG']['net'] ?? 0);

    $actif['BT']['brut']       = ($actif['BQ']['brut'] ?? 0) + ($actif['BR']['brut'] ?? 0) + ($actif['BS']['brut'] ?? 0);
    $actif['BT']['amort_deprec'] = ($actif['BQ']['amort_deprec'] ?? 0) + ($actif['BR']['amort_deprec'] ?? 0) + ($actif['BS']['amort_deprec'] ?? 0);
    $actif['BT']['net']        = ($actif['BQ']['net'] ?? 0) + ($actif['BR']['net'] ?? 0) + ($actif['BS']['net'] ?? 0);

    $actif['BZ']['brut']       = ($actif['AZ']['brut'] ?? 0) + ($actif['BK']['brut'] ?? 0) + ($actif['BT']['brut'] ?? 0) + ($actif['BU']['brut'] ?? 0);
    $actif['BZ']['amort_deprec'] = ($actif['AZ']['amort_deprec'] ?? 0) + ($actif['BK']['amort_deprec'] ?? 0) + ($actif['BT']['amort_deprec'] ?? 0) + ($actif['BU']['amort_deprec'] ?? 0);
    $actif['BZ']['net']        = ($actif['AZ']['net'] ?? 0) + ($actif['BK']['net'] ?? 0) + ($actif['BT']['net'] ?? 0) + ($actif['BU']['net'] ?? 0);
}

// Fonction pour calculer les sous-totaux PASSIF selon SYSCOHADA
function calculerTotauxPassif(array &$passif): void {
    $passif['CP']['net'] = ($passif['CA']['net'] ?? 0) + ($passif['CB']['net'] ?? 0) + ($passif['CD']['net'] ?? 0) +
                           ($passif['CE']['net'] ?? 0) + ($passif['CF']['net'] ?? 0) + ($passif['CG']['net'] ?? 0) +
                           ($passif['CH']['net'] ?? 0) + ($passif['CJ']['net'] ?? 0) + ($passif['CL']['net'] ?? 0) +
                           ($passif['CM']['net'] ?? 0);
    $passif['DD']['net'] = ($passif['DA']['net'] ?? 0) + ($passif['DB']['net'] ?? 0) + ($passif['DC']['net'] ?? 0);
    $passif['DF']['net'] = ($passif['CP']['net'] ?? 0) + ($passif['DD']['net'] ?? 0);
    $passif['DP']['net'] = ($passif['DH']['net'] ?? 0) + ($passif['DI']['net'] ?? 0) + ($passif['DJ']['net'] ?? 0) +
                           ($passif['DK']['net'] ?? 0) + ($passif['DM']['net'] ?? 0) + ($passif['DN']['net'] ?? 0);
    $passif['DT']['net'] = ($passif['DQ']['net'] ?? 0) + ($passif['DR']['net'] ?? 0);
    $passif['DZ']['net'] = ($passif['DF']['net'] ?? 0) + ($passif['DP']['net'] ?? 0) + ($passif['DT']['net'] ?? 0) + ($passif['DV']['net'] ?? 0);
}

try {
    // ── Requête commune (réutilisée pour N et N-1) ────────────────────────────
    $sql = "
        SELECT
            pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc,
            COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.debit  ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut = 'Validé' AND e.societe_id = pc.societe_id
        WHERE pc.actif = 'Oui' AND pc.tableau = 'Bilan' AND pc.societe_id = ?
        GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc
        ORDER BY pc.compte
    ";

    // ── Période N ─────────────────────────────────────────────────────────────
    $stmt = $db->prepare($sql);
    $stmt->execute([$date_fin, $date_fin, $societe_id]);
    $comptes = $stmt->fetchAll();

    classifierComptes($comptes, $actif, $passif, $actif_details, $passif_details,
                      $rubriques_actif, $rubriques_passif, $is_with_comptes);
    calculerTotauxActif($actif);

    // CF (Réserves indisponibles) — compte 11 cumulatif
    $stmt_cf = $db->prepare("
        SELECT COALESCE(SUM(le.credit - le.debit), 0)
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif='Oui' AND LEFT(pc.compte,2)='11'
        AND e.date_ecriture <= ? AND e.statut='Validé'
        AND e.societe_id = ? AND pc.societe_id = ?
    ");
    $stmt_cf->execute([$date_fin, $societe_id, $societe_id]);
    $passif['CF']['net'] = (float)$stmt_cf->fetchColumn();

    // CH (Report à nouveau) — compte 12 (affectations) + compte 13 (résultats antérieurs avant N)
    $stmt_ch12 = $db->prepare("
        SELECT COALESCE(SUM(le.credit - le.debit), 0)
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif='Oui' AND LEFT(pc.compte,2)='12'
        AND e.date_ecriture <= ? AND e.statut='Validé'
        AND e.societe_id = ? AND pc.societe_id = ?
    ");
    $stmt_ch12->execute([$date_fin, $societe_id, $societe_id]);
    $report_12 = (float)$stmt_ch12->fetchColumn();

    $stmt_ch13 = $db->prepare("
        SELECT COALESCE(SUM(le.credit - le.debit), 0)
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif='Oui' AND LEFT(pc.compte,2)='13'
        AND e.date_ecriture < ? AND e.statut='Validé'
        AND e.societe_id = ? AND pc.societe_id = ?
    ");
    $stmt_ch13->execute([$date_debut, $societe_id, $societe_id]);
    $resultat_n1_pour_ch = (float)$stmt_ch13->fetchColumn();
    $passif['CH']['net'] = $report_12 + $resultat_n1_pour_ch;

    // CJ (Résultat net exercice N) — classes 6, 7, 8 cumulatif jusqu'à date_fin
    $stmt_cj = $db->prepare("
        SELECT COALESCE(SUM(le.credit - le.debit), 0)
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif='Oui' AND LEFT(pc.compte,1) IN ('6','7','8')
        AND e.date_ecriture <= ? AND e.statut='Validé'
        AND e.societe_id = ? AND pc.societe_id = ?
    ");
    $stmt_cj->execute([$date_fin, $societe_id, $societe_id]);
    $passif['CJ']['net'] = (float)$stmt_cj->fetchColumn();

    calculerTotauxPassif($passif);

    // ── Période N-1 ───────────────────────────────────────────────────────────
    $stmt_n1 = $db->prepare($sql);
    $stmt_n1->execute([$date_fin_n1, $date_fin_n1, $societe_id]);
    $comptes_n1 = $stmt_n1->fetchAll();

    $actif_details_n1  = [];
    $passif_details_n1 = [];
    classifierComptes($comptes_n1, $actif_n1, $passif_n1, $actif_details_n1, $passif_details_n1,
                      $rubriques_actif, $rubriques_passif, false);
    calculerTotauxActif($actif_n1);

    // CF N-1
    $stmt_cf->execute([$date_fin_n1, $societe_id, $societe_id]);
    $passif_n1['CF']['net'] = (float)$stmt_cf->fetchColumn();

    // CH N-1 = compte 12 (<= fin N-1) + compte 13 (< début N-1)
    $stmt_ch12->execute([$date_fin_n1, $societe_id, $societe_id]);
    $report_12_n1 = (float)$stmt_ch12->fetchColumn();
    $stmt_ch13->execute([$date_debut_n1, $societe_id, $societe_id]);
    $passif_n1['CH']['net'] = $report_12_n1 + (float)$stmt_ch13->fetchColumn();

    // CJ N-1 = compte 13 (<= fin N-1) — le résultat N-1 est déjà clôturé dans le compte 13
    // (on n'utilise PAS les classes 6/7/8 pour N-1, contrairement à N)
    $stmt_cj_n1 = $db->prepare("
        SELECT COALESCE(SUM(le.credit - le.debit), 0)
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif='Oui' AND LEFT(pc.compte,2)='13'
        AND e.date_ecriture <= ? AND e.statut='Validé'
        AND e.societe_id = ? AND pc.societe_id = ?
    ");
    $stmt_cj_n1->execute([$date_fin_n1, $societe_id, $societe_id]);
    $passif_n1['CJ']['net'] = (float)$stmt_cj_n1->fetchColumn();

    calculerTotauxPassif($passif_n1);

} catch (Exception $e) {
    die('Erreur: ' . $e->getMessage());
}

// Libellés des rubriques
$libelles_actif = [
    'AD' => 'IMMOBILISATIONS INCORPORELLES',
    'AE' => 'Frais de développement et de prospection',
    'AF' => 'Brevets, licences, logiciels, et droits similaires',
    'AG' => 'Fonds commercial et droit au bail',
    'AH' => 'Autres immobilisations incorporelles',
    'AI' => 'IMMOBILISATIONS CORPORELLES',
    'AJ' => 'Terrains',
    'AK' => 'Bâtiments',
    'AL' => 'Aménagements, agencements et installations',
    'AM' => 'Matériel, mobilier et actifs biologiques',
    'AN' => 'Matériel de transport',
    'AP' => 'AVANCES ET ACOMPTES VERSES SUR IMMOBILISATIONS',
    'AQ' => 'IMMOBILISATIONS FINANCIERES',
    'AR' => 'Titres de participation',
    'AS' => 'Autres immobilisations financières',
    'AZ' => 'TOTAL ACTIF IMMOBILISE',
    'BA' => 'ACTIF CIRCULANT HAO',
    'BB' => 'STOCKS ET ENCOURS',
    'BC' => 'CREANCES ET EMPLOIS ASSIMILES',
    'BH' => 'Fournisseurs avances versées',
    'BI' => 'Clients',
    'BJ' => 'Autres créances',
    'BK' => 'TOTAL ACTIF CIRCULANT',
    'BQ' => 'Titres de placement',
    'BR' => 'Valeurs à encaisser',
    'BS' => 'Banques, chèques postaux, caisse et assimilés',
    'BT' => 'TOTAL TRESORERIE-ACTIF',
    'BU' => 'Ecart de conversion-Actif',
    'BZ' => 'TOTAL GENERAL'
];

$libelles_passif = [
    'CA' => 'Capital',
    'CB' => 'Apporteurs capital non appelé (-)',
    'CD' => 'Primes liées au capital social',
    'CE' => 'Ecarts de réévaluation',
    'CF' => 'Réserves indisponibles',
    'CG' => 'Réserves libres',
    'CH' => 'Report à nouveau (+ ou -)',
    'CJ' => 'Résultat net de l\'exercice (bénéfice + ou perte -)',
    'CL' => 'Subventions d\'investissement',
    'CM' => 'Provisions réglementées',
    'CP' => 'TOTAL CAPITAUX PROPRES ET RESSOURCES ASSIMILEES',
    'DA' => 'Emprunts et dettes financières diverses',
    'DB' => 'Dettes de location-acquisition',
    'DC' => 'Provisions pour risques et charges',
    'DD' => 'TOTAL DETTES FINANCIERES ET RESSOURCES ASSIMILEES',
    'DF' => 'TOTAL RESSOURCES STABLES',
    'DH' => 'Dettes circulantes HAO',
    'DI' => 'Clients, avances reçues',
    'DJ' => 'Fournisseurs d\'exploitation',
    'DK' => 'Dettes fiscales et sociales',
    'DM' => 'Autres dettes',
    'DN' => 'Provisions pour risques à court terme',
    'DP' => 'TOTAL PASSIF CIRCULANT',
    'DQ' => 'Banques, crédits d\'escompte',
    'DR' => 'Banques, établissements financiers et crédits de trésorerie',
    'DT' => 'TOTAL TRESORERIE-PASSIF',
    'DV' => 'Ecart de conversion-Passif',
    'DZ' => 'TOTAL GENERAL'
];

// Créer un nouveau document Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setShowGridlines(false);

// Titre principal centré sur toute la largeur
$sheet->setCellValue('A1', 'BILAN' . ($is_condense ? ' - CONDENSÉ' : ($is_with_comptes ? ' - DÉTAILLÉ AVEC COMPTES' : ' - DÉTAILLÉ')));
$sheet->mergeCells('A1:R1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Dates
$sheet->setCellValue('A2', 'EXERCICE au ' . date('d/m/Y', strtotime($date_fin)));
$sheet->mergeCells('A2:I2');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('J2', 'EXERCICE AU ' . date('d/m/Y', strtotime($date_fin_n1)));
$sheet->mergeCells('J2:M2');
$sheet->getStyle('J2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('N2', 'EXERCICE AU ' . date('d/m/Y', strtotime($date_fin)));
$sheet->mergeCells('N2:Q2');
$sheet->getStyle('N2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('R2', 'EXERCICE AU ' . date('d/m/Y', strtotime($date_fin_n1)));
$sheet->setCellValue('R2', '');

$row = 7;

// En-tête de tableau ACTIF
$sheet->setCellValue('A' . $row, 'ACTIF');
$sheet->mergeCells('A' . $row . ':G' . $row);
$actifTableHeaderStyle = [
    'font' => ['bold' => true, 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4682B4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray($actifTableHeaderStyle);

// En-tête de tableau PASSIF
$sheet->setCellValue('I' . $row, 'PASSIF');
$sheet->mergeCells('I' . $row . ':M' . $row);
$passifTableHeaderStyle = [
    'font' => ['bold' => true, 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '20B2AA']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('I' . $row . ':M' . $row)->applyFromArray($passifTableHeaderStyle);

$row++;

// En-têtes colonnes
// ACTIF - gauche
$sheet->setCellValue('A' . $row, 'REF');
$sheet->setCellValue('B' . $row, 'ACTIF (1)');
$sheet->setCellValue('C' . $row, 'NOTE');
$sheet->setCellValue('D' . $row, 'BRUT');
$sheet->setCellValue('E' . $row, 'AMORT et DEPREC.');
$sheet->setCellValue('F' . $row, 'NET');
$sheet->setCellValue('G' . $row, 'NET');

// Colonne vide de séparation
$sheet->setCellValue('H' . $row, '');

// PASSIF - droite
$sheet->setCellValue('I' . $row, 'REF');
$sheet->setCellValue('J' . $row, 'PASSIF');
$sheet->setCellValue('K' . $row, 'NOTE');
$sheet->setCellValue('L' . $row, 'NET');
$sheet->setCellValue('M' . $row, 'NET');

$headerStyle = [
    'font' => ['bold' => true, 'size' => 9],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

$sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray($headerStyle);
$sheet->getStyle('I' . $row . ':M' . $row)->applyFromArray($headerStyle);

$row++;

// Convertir les tableaux en listes indexées pour itérer en parallèle
$actif_items = array_keys($libelles_actif);
$passif_items = array_keys($libelles_passif);

// En mode condensé, ne garder que les lignes "TOTAL" / lignes agrégées
if ($is_condense) {
    $children_actif = ['AE','AF','AG','AH','AJ','AK','AL','AM','AN','AP','AR','AS','BH','BI','BJ','BQ','BR','BS'];
    $children_passif = ['CA','CB','CD','CE','CF','CG','CH','CJ','CL','CM','DA','DB','DC','DH','DI','DJ','DK','DM','DN','DQ','DR'];
    $actif_items = array_values(array_filter($actif_items, fn($r) => !in_array($r, $children_actif)));
    $passif_items = array_values(array_filter($passif_items, fn($r) => !in_array($r, $children_passif)));
}

$start_row = $row;
$actif_index = 0;
$passif_index = 0;

// Ligne où doit commencer DQ dans le PASSIF (ligne 32 Excel = ligne 23 après le header à ligne 9)
$passif_skip_at_row = 31; // Ligne Excel 31 doit être vide

while ($actif_index < count($actif_items) || $passif_index < count($passif_items)) {
    // ACTIF (colonnes A-G)
    if ($actif_index < count($actif_items)) {
        $ref_actif = $actif_items[$actif_index];
        $libelle_actif = $libelles_actif[$ref_actif];
        $is_total_actif = strpos($libelle_actif, 'TOTAL') !== false;

        $brut = $actif[$ref_actif]['brut'] ?? 0;
        $amort = $actif[$ref_actif]['amort_deprec'] ?? 0;
        $net_n = $actif[$ref_actif]['net'] ?? 0;
        $net_n1 = $actif_n1[$ref_actif]['net'] ?? 0;

        $note = ''; // Peut être rempli avec les numéros de note si nécessaire

        $sheet->setCellValue('A' . $row, $ref_actif);
        $sheet->setCellValue('B' . $row, $libelle_actif);
        $sheet->setCellValue('C' . $row, $note);
        $sheet->setCellValue('D' . $row, $brut != 0 ? $brut : '');
        $sheet->setCellValue('E' . $row, $amort != 0 ? $amort : '');
        $sheet->setCellValue('F' . $row, $net_n != 0 ? $net_n : '');
        $sheet->setCellValue('G' . $row, $net_n1 != 0 ? $net_n1 : '');

        // Style pour ACTIF
        if ($is_total_actif) {
            $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6E6E6']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        } else {
            $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        }

        // Format nombres ACTIF
        $sheet->getStyle('D' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('D' . $row . ':G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $actif_index++;
    }

    // Colonne vide H
    $sheet->setCellValue('H' . $row, '');

    // PASSIF (colonnes I-M) - avec gestion de la ligne vide à 31
    if ($row == $passif_skip_at_row) {
        // Ligne 31: laisser vide avec fond gris pour le PASSIF
        $sheet->getStyle('I' . $row . ':M' . $row)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D3D3D3']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        // Ne pas incrémenter passif_index
    } elseif ($passif_index < count($passif_items)) {
        $ref_passif = $passif_items[$passif_index];
        $libelle_passif = $libelles_passif[$ref_passif];
        $is_total_passif = strpos($libelle_passif, 'TOTAL') !== false;

        $net_n = $passif[$ref_passif]['net'] ?? 0;
        $net_n1 = $passif_n1[$ref_passif]['net'] ?? 0;

        $note = ''; // Peut être rempli avec les numéros de note

        $sheet->setCellValue('I' . $row, $ref_passif);
        $sheet->setCellValue('J' . $row, $libelle_passif);
        $sheet->setCellValue('K' . $row, $note);
        $sheet->setCellValue('L' . $row, $net_n != 0 ? $net_n : '');
        $sheet->setCellValue('M' . $row, $net_n1 != 0 ? $net_n1 : '');

        // Style pour PASSIF
        if ($is_total_passif) {
            $sheet->getStyle('I' . $row . ':M' . $row)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6E6E6']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        } else {
            $sheet->getStyle('I' . $row . ':M' . $row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        }

        // Format nombres PASSIF
        $sheet->getStyle('L' . $row . ':M' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('L' . $row . ':M' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $passif_index++;
    }

    $row++;
}

// Feuille de détail par compte (mode 'comptes' uniquement)
if ($is_with_comptes) {
    $sheet->setTitle('Bilan');

    $detail_sheet = $spreadsheet->createSheet();
    $detail_sheet->setShowGridlines(false);
    $detail_sheet->setTitle('Détail par compte');
    $drow = 1;

    // Titre
    $detail_sheet->setCellValue('A' . $drow, 'DÉTAIL PAR COMPTE - BILAN au ' . date('d/m/Y', strtotime($date_fin)));
    $detail_sheet->mergeCells('A' . $drow . ':E' . $drow);
    $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $drow += 2;

    // === ACTIF ===
    $detail_sheet->setCellValue('A' . $drow, 'ACTIF');
    $detail_sheet->mergeCells('A' . $drow . ':E' . $drow);
    $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4682B4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $drow++;

    $detail_sheet->setCellValue('A' . $drow, 'REF');
    $detail_sheet->setCellValue('B' . $drow, 'Libellé rubrique');
    $detail_sheet->setCellValue('C' . $drow, 'Compte');
    $detail_sheet->setCellValue('D' . $drow, 'Intitulé compte');
    $detail_sheet->setCellValue('E' . $drow, 'Solde NET (N)');
    $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D0E4F7']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $detail_sheet->getStyle('E' . $drow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $drow++;

    foreach ($libelles_actif as $ref => $libelle) {
        if (empty($actif_details[$ref])) continue;
        $detail_sheet->setCellValue('A' . $drow, $ref);
        $detail_sheet->setCellValue('B' . $drow, $libelle);
        $detail_sheet->mergeCells('C' . $drow . ':E' . $drow);
        $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBF3FB']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        $drow++;
        foreach ($actif_details[$ref] as $d) {
            $detail_sheet->setCellValue('C' . $drow, $d['compte']);
            $detail_sheet->setCellValue('D' . $drow, $d['intitule']);
            $detail_sheet->setCellValue('E' . $drow, $d['solde']);
            $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
                'font' => ['italic' => true, 'size' => 9],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR]]
            ]);
            $detail_sheet->getStyle('E' . $drow)->getNumberFormat()->setFormatCode('#,##0');
            $detail_sheet->getStyle('E' . $drow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $drow++;
        }
    }

    $drow += 2;

    // === PASSIF ===
    $detail_sheet->setCellValue('A' . $drow, 'PASSIF');
    $detail_sheet->mergeCells('A' . $drow . ':E' . $drow);
    $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '20B2AA']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $drow++;

    $detail_sheet->setCellValue('A' . $drow, 'REF');
    $detail_sheet->setCellValue('B' . $drow, 'Libellé rubrique');
    $detail_sheet->setCellValue('C' . $drow, 'Compte');
    $detail_sheet->setCellValue('D' . $drow, 'Intitulé compte');
    $detail_sheet->setCellValue('E' . $drow, 'Solde NET (N)');
    $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C8EEE8']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $detail_sheet->getStyle('E' . $drow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $drow++;

    foreach ($libelles_passif as $ref => $libelle) {
        if (empty($passif_details[$ref])) continue;
        $detail_sheet->setCellValue('A' . $drow, $ref);
        $detail_sheet->setCellValue('B' . $drow, $libelle);
        $detail_sheet->mergeCells('C' . $drow . ':E' . $drow);
        $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F8F6']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        $drow++;
        foreach ($passif_details[$ref] as $d) {
            $detail_sheet->setCellValue('C' . $drow, $d['compte']);
            $detail_sheet->setCellValue('D' . $drow, $d['intitule']);
            $detail_sheet->setCellValue('E' . $drow, $d['solde']);
            $detail_sheet->getStyle('A' . $drow . ':E' . $drow)->applyFromArray([
                'font' => ['italic' => true, 'size' => 9],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR]]
            ]);
            $detail_sheet->getStyle('E' . $drow)->getNumberFormat()->setFormatCode('#,##0');
            $detail_sheet->getStyle('E' . $drow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $drow++;
        }
    }

    // Largeurs colonnes feuille détail
    $detail_sheet->getColumnDimension('A')->setWidth(6);
    $detail_sheet->getColumnDimension('B')->setWidth(50);
    $detail_sheet->getColumnDimension('C')->setWidth(12);
    $detail_sheet->getColumnDimension('D')->setWidth(50);
    $detail_sheet->getColumnDimension('E')->setWidth(18);

    // Revenir sur la première feuille
    $spreadsheet->setActiveSheetIndex(0);
}

// Ajuster la largeur des colonnes
// ACTIF
$sheet->getColumnDimension('A')->setWidth(6);  // REF
$sheet->getColumnDimension('B')->setWidth(50); // ACTIF
$sheet->getColumnDimension('C')->setWidth(6);  // NOTE
$sheet->getColumnDimension('D')->setWidth(18); // BRUT
$sheet->getColumnDimension('E')->setWidth(18); // AMORT
$sheet->getColumnDimension('F')->setWidth(18); // NET (N)
$sheet->getColumnDimension('G')->setWidth(18); // NET (N-1)

// Colonne de séparation
$sheet->getColumnDimension('H')->setWidth(2);

// PASSIF
$sheet->getColumnDimension('I')->setWidth(6);  // REF
$sheet->getColumnDimension('J')->setWidth(50); // PASSIF
$sheet->getColumnDimension('K')->setWidth(6);  // NOTE
$sheet->getColumnDimension('L')->setWidth(18); // NET (N)
$sheet->getColumnDimension('M')->setWidth(18); // NET (N-1)

// Générer le fichier Excel
$filename = 'Bilan_OHADA_' . date('YmdHis') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
