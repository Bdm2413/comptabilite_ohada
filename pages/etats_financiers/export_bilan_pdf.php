<?php
require_once '../../config/config.php';
require_once '../../vendor/autoload.php';
requireLogin();

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

// Calculer automatiquement les dates N-1
$date_debut_n1 = date('Y-m-d', strtotime($date_debut . ' -1 year'));
$date_fin_n1 = date('Y-m-d', strtotime($date_fin . ' -1 year'));

// Initialiser les tableaux
$actif = [];
$passif = [];
$actif_n1 = [];
$passif_n1 = [];

// Rubriques pour le cas BD = BC
$rubriques_actif  = ['AE','AF','AG','AH','AJ','AK','AL','AM','AN','AP','AR','AS','AZ','BA','BB','BG','BH','BI','BJ','BK','BQ','BR','BS','BT','BU','BZ'];
$rubriques_passif = ['CA','CB','CD','CE','CF','CG','CH','CJ','CL','CM','CP','DA','DB','DC','DD','DF','DH','DI','DJ','DK','DM','DN','DP','DQ','DR','DT','DV','DZ'];

// Fonction générique de classification d'une liste de comptes dans actif/passif
function classifierComptes(array $comptes, array &$actif, array &$passif,
                           array $rubriques_actif, array $rubriques_passif): void
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
        if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
            if (!empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $actif[$ref]['amort_deprec'] += abs($solde);
                $actif[$ref]['net']          -= abs($solde);
            }
        }
        // Cas 1: BD ≠ BC → actif si solde débiteur, passif si créditeur
        elseif ($compte['bd'] != $compte['bc']) {
            if ($solde > 0 && !empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $actif[$ref]['brut'] += $solde;
                $actif[$ref]['net']  += $solde;
            } elseif ($solde < 0 && !empty($compte['bc'])) {
                $ref = $compte['bc'];
                if (!isset($passif[$ref])) $passif[$ref] = ['net' => 0];
                $passif[$ref]['net'] += abs($solde);
            }
        }
        // Cas 2: BD = BC → la rubrique est fixe, le sens dépend du type de rubrique
        elseif ($compte['bd'] == $compte['bc'] && !empty($compte['bd'])) {
            $ref = $compte['bd'];
            if (in_array($ref, $rubriques_actif)) {
                if (!isset($actif[$ref])) $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                $actif[$ref]['brut'] += $solde;
                $actif[$ref]['net']  += $solde;
            } elseif (in_array($ref, $rubriques_passif)) {
                if (!isset($passif[$ref])) $passif[$ref] = ['net' => 0];
                $passif[$ref]['net'] -= $solde;
            }
        }
    }
}

// Fonction pour calculer les sous-totaux ACTIF selon SYSCOHADA
function calculerTotauxActif(array &$actif): void {
    $actif['AD']['brut']         = ($actif['AE']['brut'] ?? 0) + ($actif['AF']['brut'] ?? 0) + ($actif['AG']['brut'] ?? 0) + ($actif['AH']['brut'] ?? 0);
    $actif['AD']['amort_deprec'] = ($actif['AE']['amort_deprec'] ?? 0) + ($actif['AF']['amort_deprec'] ?? 0) + ($actif['AG']['amort_deprec'] ?? 0) + ($actif['AH']['amort_deprec'] ?? 0);
    $actif['AD']['net']          = ($actif['AE']['net'] ?? 0) + ($actif['AF']['net'] ?? 0) + ($actif['AG']['net'] ?? 0) + ($actif['AH']['net'] ?? 0);

    $actif['AI']['brut']         = ($actif['AJ']['brut'] ?? 0) + ($actif['AK']['brut'] ?? 0) + ($actif['AL']['brut'] ?? 0) + ($actif['AM']['brut'] ?? 0) + ($actif['AN']['brut'] ?? 0);
    $actif['AI']['amort_deprec'] = ($actif['AJ']['amort_deprec'] ?? 0) + ($actif['AK']['amort_deprec'] ?? 0) + ($actif['AL']['amort_deprec'] ?? 0) + ($actif['AM']['amort_deprec'] ?? 0) + ($actif['AN']['amort_deprec'] ?? 0);
    $actif['AI']['net']          = ($actif['AJ']['net'] ?? 0) + ($actif['AK']['net'] ?? 0) + ($actif['AL']['net'] ?? 0) + ($actif['AM']['net'] ?? 0) + ($actif['AN']['net'] ?? 0);

    $actif['AQ']['brut']         = ($actif['AR']['brut'] ?? 0) + ($actif['AS']['brut'] ?? 0);
    $actif['AQ']['amort_deprec'] = ($actif['AR']['amort_deprec'] ?? 0) + ($actif['AS']['amort_deprec'] ?? 0);
    $actif['AQ']['net']          = ($actif['AR']['net'] ?? 0) + ($actif['AS']['net'] ?? 0);

    $actif['AZ']['brut']         = ($actif['AD']['brut'] ?? 0) + ($actif['AI']['brut'] ?? 0) + ($actif['AQ']['brut'] ?? 0) + ($actif['AP']['brut'] ?? 0);
    $actif['AZ']['amort_deprec'] = ($actif['AD']['amort_deprec'] ?? 0) + ($actif['AI']['amort_deprec'] ?? 0) + ($actif['AQ']['amort_deprec'] ?? 0) + ($actif['AP']['amort_deprec'] ?? 0);
    $actif['AZ']['net']          = ($actif['AD']['net'] ?? 0) + ($actif['AI']['net'] ?? 0) + ($actif['AQ']['net'] ?? 0) + ($actif['AP']['net'] ?? 0);

    $actif['BG']['brut']         = ($actif['BH']['brut'] ?? 0) + ($actif['BI']['brut'] ?? 0) + ($actif['BJ']['brut'] ?? 0);
    $actif['BG']['amort_deprec'] = ($actif['BH']['amort_deprec'] ?? 0) + ($actif['BI']['amort_deprec'] ?? 0) + ($actif['BJ']['amort_deprec'] ?? 0);
    $actif['BG']['net']          = ($actif['BH']['net'] ?? 0) + ($actif['BI']['net'] ?? 0) + ($actif['BJ']['net'] ?? 0);

    $actif['BK']['brut']         = ($actif['BA']['brut'] ?? 0) + ($actif['BB']['brut'] ?? 0) + ($actif['BG']['brut'] ?? 0);
    $actif['BK']['amort_deprec'] = ($actif['BA']['amort_deprec'] ?? 0) + ($actif['BB']['amort_deprec'] ?? 0) + ($actif['BG']['amort_deprec'] ?? 0);
    $actif['BK']['net']          = ($actif['BA']['net'] ?? 0) + ($actif['BB']['net'] ?? 0) + ($actif['BG']['net'] ?? 0);

    $actif['BT']['brut']         = ($actif['BQ']['brut'] ?? 0) + ($actif['BR']['brut'] ?? 0) + ($actif['BS']['brut'] ?? 0);
    $actif['BT']['amort_deprec'] = ($actif['BQ']['amort_deprec'] ?? 0) + ($actif['BR']['amort_deprec'] ?? 0) + ($actif['BS']['amort_deprec'] ?? 0);
    $actif['BT']['net']          = ($actif['BQ']['net'] ?? 0) + ($actif['BR']['net'] ?? 0) + ($actif['BS']['net'] ?? 0);

    $actif['BZ']['brut']         = ($actif['AZ']['brut'] ?? 0) + ($actif['BK']['brut'] ?? 0) + ($actif['BT']['brut'] ?? 0) + ($actif['BU']['brut'] ?? 0);
    $actif['BZ']['amort_deprec'] = ($actif['AZ']['amort_deprec'] ?? 0) + ($actif['BK']['amort_deprec'] ?? 0) + ($actif['BT']['amort_deprec'] ?? 0) + ($actif['BU']['amort_deprec'] ?? 0);
    $actif['BZ']['net']          = ($actif['AZ']['net'] ?? 0) + ($actif['BK']['net'] ?? 0) + ($actif['BT']['net'] ?? 0) + ($actif['BU']['net'] ?? 0);
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
    // Requête commune (réutilisée pour N et N-1)
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

    classifierComptes($comptes, $actif, $passif, $rubriques_actif, $rubriques_passif);
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
    $passif['CH']['net'] = $report_12 + (float)$stmt_ch13->fetchColumn();

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

    classifierComptes($comptes_n1, $actif_n1, $passif_n1, $rubriques_actif, $rubriques_passif);
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

// Libellés des rubriques - TOUS LES ELEMENTS
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
    'BG' => 'CREANCES ET EMPLOIS ASSIMILES',
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

// Créer le PDF en mode paysage
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor('Système Comptable');
$pdf->SetTitle('Bilan OHADA');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// ===== PAGE 1: ACTIF =====
$pdf->AddPage();
$pdf->SetMargins(5, 10, 5);

// Titre PAGE 1
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 6, 'BILAN OHADA - ACTIF', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, 'Exercice du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
$pdf->Cell(0, 4, 'Période N-1: du ' . date('d/m/Y', strtotime($date_debut_n1)) . ' au ' . date('d/m/Y', strtotime($date_fin_n1)), 0, 1, 'C');
$pdf->Ln(2);

// En-tête ACTIF
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(70, 130, 180);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(9, 5, 'REF', 1, 0, 'C', true);
$pdf->Cell(95, 5, 'ACTIF', 1, 0, 'L', true);
$pdf->Cell(30, 5, 'BRUT', 1, 0, 'R', true);
$pdf->Cell(30, 5, 'AMORT/DEPREC', 1, 0, 'R', true);
$pdf->Cell(30, 5, 'NET (N)', 1, 0, 'R', true);
$pdf->Cell(30, 5, 'NET (N-1)', 1, 0, 'R', true);
$pdf->Cell(30, 5, 'VAR', 1, 0, 'R', true);
$pdf->Cell(26, 5, '%', 1, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 6);

foreach ($libelles_actif as $ref => $libelle) {
    $is_total = strpos($libelle, 'TOTAL') !== false;
    $brut = $actif[$ref]['brut'] ?? 0;
    $amort = $actif[$ref]['amort_deprec'] ?? 0;
    $net_n = $actif[$ref]['net'] ?? 0;
    $net_n1 = $actif_n1[$ref]['net'] ?? 0;

    // Calculer la variation et le taux
    $variation = $net_n - $net_n1;
    $taux = ($net_n1 != 0) ? (($variation / $net_n1) * 100) : 0;

    if ($is_total) {
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetFillColor(230, 230, 230);
    } else {
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetFillColor(255, 255, 255);
    }

    $pdf->Cell(9, 4, $ref, 1, 0, 'C', true);
    $pdf->Cell(95, 4, mb_substr($libelle, 0, 80), 1, 0, 'L', true);
    $pdf->Cell(30, 4, $brut > 0 ? safe_number_format($brut) : '', 1, 0, 'R', true);
    $pdf->Cell(30, 4, $amort > 0 ? safe_number_format($amort) : '', 1, 0, 'R', true);
    $pdf->Cell(30, 4, $net_n > 0 ? safe_number_format($net_n) : '', 1, 0, 'R', true);
    $pdf->Cell(30, 4, $net_n1 > 0 ? safe_number_format($net_n1) : '', 1, 0, 'R', true);

    // Variation avec couleur
    if ($variation != 0) {
        $pdf->SetTextColor($variation > 0 ? 0 : 255, $variation > 0 ? 128 : 0, 0);
        $pdf->Cell(30, 4, ($variation > 0 ? '+' : '') . safe_number_format($variation), 1, 0, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell(30, 4, '', 1, 0, 'R', true);
    }

    // Taux avec couleur
    if ($taux != 0) {
        $pdf->SetTextColor($taux > 0 ? 0 : 255, $taux > 0 ? 128 : 0, 0);
        $pdf->Cell(26, 4, ($taux > 0 ? '+' : '') . number_format($taux, 1) . '%', 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell(26, 4, '', 1, 1, 'R', true);
    }
}

// ===== PAGE 2: PASSIF =====
$pdf->AddPage();

// Titre PAGE 2
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 6, 'BILAN OHADA - PASSIF', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, 'Exercice du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
$pdf->Cell(0, 4, 'Période N-1: du ' . date('d/m/Y', strtotime($date_debut_n1)) . ' au ' . date('d/m/Y', strtotime($date_fin_n1)), 0, 1, 'C');
$pdf->Ln(2);

// En-tête PASSIF
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(20, 178, 170);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(9, 5, 'REF', 1, 0, 'C', true);
$pdf->Cell(135, 5, 'PASSIF', 1, 0, 'L', true);
$pdf->Cell(35, 5, 'NET (N)', 1, 0, 'R', true);
$pdf->Cell(35, 5, 'NET (N-1)', 1, 0, 'R', true);
$pdf->Cell(35, 5, 'VAR', 1, 0, 'R', true);
$pdf->Cell(31, 5, '%', 1, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 5);

// Données PASSIF
foreach ($libelles_passif as $ref => $libelle) {
    $is_total = strpos($libelle, 'TOTAL') !== false;
    $net_n = $passif[$ref]['net'] ?? 0;
    $net_n1 = $passif_n1[$ref]['net'] ?? 0;

    // Calculer la variation et le taux
    $variation = $net_n - $net_n1;
    $taux = ($net_n1 != 0) ? (($variation / $net_n1) * 100) : 0;

    if ($is_total) {
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetFillColor(230, 230, 230);
    } else {
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetFillColor(255, 255, 255);
    }

    $pdf->Cell(9, 4, $ref, 1, 0, 'C', true);
    $pdf->Cell(135, 4, mb_substr($libelle, 0, 115), 1, 0, 'L', true);
    $pdf->Cell(35, 4, $net_n != 0 ? safe_number_format($net_n) : '', 1, 0, 'R', true);
    $pdf->Cell(35, 4, $net_n1 != 0 ? safe_number_format($net_n1) : '', 1, 0, 'R', true);

    // Variation avec couleur
    if ($variation != 0) {
        $pdf->SetTextColor($variation > 0 ? 0 : 255, $variation > 0 ? 128 : 0, 0);
        $pdf->Cell(35, 4, ($variation > 0 ? '+' : '') . safe_number_format($variation), 1, 0, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell(35, 4, '', 1, 0, 'R', true);
    }

    // Taux avec couleur
    if ($taux != 0) {
        $pdf->SetTextColor($taux > 0 ? 0 : 255, $taux > 0 ? 128 : 0, 0);
        $pdf->Cell(31, 4, ($taux > 0 ? '+' : '') . number_format($taux, 1) . '%', 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell(31, 4, '', 1, 1, 'R', true);
    }
}

// Générer le fichier
$filename = 'Bilan_OHADA_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
exit;
