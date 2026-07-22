<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// Fonction helper pour number_format avec protection contre null
function safe_number_format($number, $decimals = 0, $decimal_separator = ' ', $thousands_separator = ' ') {
    return number_format($number ?: 0, $decimals, $decimal_separator, $thousands_separator);
}

// Récupérer les paramètres de filtrage
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');

// Calculer automatiquement les dates N-1 (période comparable année précédente)
$date_debut_n1 = date('Y-m-d', strtotime($date_debut . ' -1 year'));
$date_fin_n1 = date('Y-m-d', strtotime($date_fin . ' -1 year'));

// Initialiser les tableaux pour stocker les données du bilan
$actif = [];
$passif = [];
$actif_n1 = [];
$passif_n1 = [];

try {
    // Récupérer tous les comptes de bilan avec leurs soldes
    $sql = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.tableau,
            pc.bd,
            pc.bc,
            COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui' AND pc.tableau = 'Bilan'
        GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc
        ORDER BY pc.compte
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$date_fin, $date_fin]);
    $comptes = $stmt->fetchAll();

    // Définir les rubriques d'actif et de passif pour savoir comment traiter les comptes avec BD = BC
    $rubriques_actif = ['AF', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AS', 'AZ', 'BA', 'BB', 'BG', 'BH', 'BI', 'BJ', 'BK', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BZ'];
    $rubriques_passif = ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM', 'CP', 'DA', 'DB', 'DC', 'DD', 'DF', 'DH', 'DI', 'DJ', 'DK', 'DM', 'DN', 'DP', 'DQ', 'DR', 'DT', 'DV', 'DZ'];

    // Tableaux pour stocker les détails des comptes par rubrique
    $details_actif = [];
    $details_passif = [];

    // Organiser les comptes par rubrique
    foreach ($comptes as $compte) {
        $solde = $compte['total_debit'] - $compte['total_credit'];
        $prefix1 = substr($compte['compte'], 0, 1);
        $prefix2 = substr($compte['compte'], 0, 2);

        if ($solde == 0) continue; // Ignorer les comptes à solde nul

        // Exclure les comptes de résultat (6, 7, 8) car le résultat est calculé séparément dans CJ
        if ($prefix1 == '6' || $prefix1 == '7' || $prefix1 == '8') continue;

        // Exclure le compte 13 (Résultat net) car il sera remplacé par le calcul dans CJ
        if ($prefix2 == '13') continue;

        // Exclure les comptes de reclassement (416x, 496x, etc.) ET leurs dépréciations (491x, 496x)
        // car ils se compensent mutuellement et ne doivent pas apparaître au bilan
        $prefix3 = substr($compte['compte'], 0, 3);
        if ($prefix3 == '416' || $prefix3 == '426' || $prefix3 == '491' || $prefix3 == '496') continue;

        // Exception: Les comptes 28, 29, 39, 49, 59 (amortissements/dépréciations) vont toujours en AMORT à l'actif
        if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
            if (!empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif[$ref])) {
                    $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                    $details_actif[$ref] = [];
                }
                $actif[$ref]['amort_deprec'] += abs($solde);
                $actif[$ref]['net'] -= abs($solde);

                // Stocker les détails du compte (amortissements)
                $details_actif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => 0,
                    'amort_deprec' => abs($solde),
                    'net' => -abs($solde),
                    'type' => 'amort'
                ];
            }
        }
        // Cas 1: BD ≠ BC - Le compte va à l'actif OU au passif selon le signe du solde
        elseif ($compte['bd'] != $compte['bc']) {
            if ($solde > 0 && !empty($compte['bd'])) {
                // Solde débiteur → utilise rubrique BD (actif)
                $ref = $compte['bd'];
                if (!isset($actif[$ref])) {
                    $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                    $details_actif[$ref] = [];
                }
                $actif[$ref]['brut'] += $solde;
                $actif[$ref]['net'] += $solde;

                // Stocker les détails du compte
                $details_actif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => $solde,
                    'amort_deprec' => 0,
                    'net' => $solde,
                    'type' => 'normal'
                ];
            } elseif ($solde < 0 && !empty($compte['bc'])) {
                // Solde créditeur → utilise rubrique BC (passif)
                $ref = $compte['bc'];
                if (!isset($passif[$ref])) {
                    $passif[$ref] = ['net' => 0];
                    $details_passif[$ref] = [];
                }
                $passif[$ref]['net'] += abs($solde);

                // Stocker les détails du compte
                $details_passif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'net' => abs($solde)
                ];
            }
        }
        // Cas 2: BD = BC - Le compte reste dans la même rubrique, le solde peut augmenter ou diminuer
        elseif ($compte['bd'] == $compte['bc'] && !empty($compte['bd'])) {
            $ref = $compte['bd'];

            // Déterminer si c'est une rubrique d'actif ou de passif
            if (in_array($ref, $rubriques_actif)) {
                // Rubrique d'actif: solde débiteur augmente, solde créditeur diminue
                if (!isset($actif[$ref])) {
                    $actif[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                    $details_actif[$ref] = [];
                }
                $actif[$ref]['brut'] += $solde; // Peut être positif ou négatif
                $actif[$ref]['net'] += $solde;

                // Stocker les détails du compte
                $details_actif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => $solde,
                    'amort_deprec' => 0,
                    'net' => $solde,
                    'type' => 'normal'
                ];
            } elseif (in_array($ref, $rubriques_passif)) {
                // Rubrique de passif: solde créditeur augmente, solde débiteur diminue
                if (!isset($passif[$ref])) {
                    $passif[$ref] = ['net' => 0];
                    $details_passif[$ref] = [];
                }
                $passif[$ref]['net'] -= $solde; // Inverse le signe: créditeur (négatif) devient positif au passif

                // Stocker les détails du compte
                $details_passif[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'net' => -$solde
                ];
            }
        }
    }

    // Calculer le total global des amortissements pour vérification
    $total_amort_deprec = 0;
    foreach ($actif as $ref => $data) {
        $total_amort_deprec += $data['amort_deprec'];
    }

    // Calculer les totaux selon les formules SYSCOHADA
    // ACTIF
    // AD = AE + AF + AG + AH
    $actif['AD']['brut'] = ($actif['AE']['brut'] ?? 0) + ($actif['AF']['brut'] ?? 0) + ($actif['AG']['brut'] ?? 0) + ($actif['AH']['brut'] ?? 0);
    $actif['AD']['amort_deprec'] = ($actif['AE']['amort_deprec'] ?? 0) + ($actif['AF']['amort_deprec'] ?? 0) + ($actif['AG']['amort_deprec'] ?? 0) + ($actif['AH']['amort_deprec'] ?? 0);
    $actif['AD']['net'] = ($actif['AE']['net'] ?? 0) + ($actif['AF']['net'] ?? 0) + ($actif['AG']['net'] ?? 0) + ($actif['AH']['net'] ?? 0);

    // AI = AJ + AK + AL + AM + AN (+ AP est dans la formule mais c'est une rubrique à part)
    $actif['AI']['brut'] = ($actif['AJ']['brut'] ?? 0) + ($actif['AK']['brut'] ?? 0) + ($actif['AL']['brut'] ?? 0) + ($actif['AM']['brut'] ?? 0) + ($actif['AN']['brut'] ?? 0);
    $actif['AI']['amort_deprec'] = ($actif['AJ']['amort_deprec'] ?? 0) + ($actif['AK']['amort_deprec'] ?? 0) + ($actif['AL']['amort_deprec'] ?? 0) + ($actif['AM']['amort_deprec'] ?? 0) + ($actif['AN']['amort_deprec'] ?? 0);
    $actif['AI']['net'] = ($actif['AJ']['net'] ?? 0) + ($actif['AK']['net'] ?? 0) + ($actif['AL']['net'] ?? 0) + ($actif['AM']['net'] ?? 0) + ($actif['AN']['net'] ?? 0);

    // AQ = AR + AS
    $actif['AQ']['brut'] = ($actif['AR']['brut'] ?? 0) + ($actif['AS']['brut'] ?? 0);
    $actif['AQ']['amort_deprec'] = ($actif['AR']['amort_deprec'] ?? 0) + ($actif['AS']['amort_deprec'] ?? 0);
    $actif['AQ']['net'] = ($actif['AR']['net'] ?? 0) + ($actif['AS']['net'] ?? 0);

    // AZ = AD + AI + AQ
    $actif['AZ']['brut'] = ($actif['AD']['brut'] ?? 0) + ($actif['AI']['brut'] ?? 0) + ($actif['AQ']['brut'] ?? 0) + ($actif['AP']['brut'] ?? 0);
    $actif['AZ']['amort_deprec'] = ($actif['AD']['amort_deprec'] ?? 0) + ($actif['AI']['amort_deprec'] ?? 0) + ($actif['AQ']['amort_deprec'] ?? 0) + ($actif['AP']['amort_deprec'] ?? 0);
    $actif['AZ']['net'] = ($actif['AD']['net'] ?? 0) + ($actif['AI']['net'] ?? 0) + ($actif['AQ']['net'] ?? 0) + ($actif['AP']['net'] ?? 0);

    // BG = BH + BI + BJ
    $actif['BG']['brut'] = ($actif['BH']['brut'] ?? 0) + ($actif['BI']['brut'] ?? 0) + ($actif['BJ']['brut'] ?? 0);
    $actif['BG']['amort_deprec'] = ($actif['BH']['amort_deprec'] ?? 0) + ($actif['BI']['amort_deprec'] ?? 0) + ($actif['BJ']['amort_deprec'] ?? 0);
    $actif['BG']['net'] = ($actif['BH']['net'] ?? 0) + ($actif['BI']['net'] ?? 0) + ($actif['BJ']['net'] ?? 0);

    // BK = BA + BB + BG
    $actif['BK']['brut'] = ($actif['BA']['brut'] ?? 0) + ($actif['BB']['brut'] ?? 0) + ($actif['BG']['brut'] ?? 0);
    $actif['BK']['amort_deprec'] = ($actif['BA']['amort_deprec'] ?? 0) + ($actif['BB']['amort_deprec'] ?? 0) + ($actif['BG']['amort_deprec'] ?? 0);
    $actif['BK']['net'] = ($actif['BA']['net'] ?? 0) + ($actif['BB']['net'] ?? 0) + ($actif['BG']['net'] ?? 0);

    // BT = BQ + BR + BS
    $actif['BT']['brut'] = ($actif['BQ']['brut'] ?? 0) + ($actif['BR']['brut'] ?? 0) + ($actif['BS']['brut'] ?? 0);
    $actif['BT']['amort_deprec'] = ($actif['BQ']['amort_deprec'] ?? 0) + ($actif['BR']['amort_deprec'] ?? 0) + ($actif['BS']['amort_deprec'] ?? 0);
    $actif['BT']['net'] = ($actif['BQ']['net'] ?? 0) + ($actif['BR']['net'] ?? 0) + ($actif['BS']['net'] ?? 0);

    // BZ = AZ + BK + BT + BU
    $actif['BZ']['brut'] = ($actif['AZ']['brut'] ?? 0) + ($actif['BK']['brut'] ?? 0) + ($actif['BT']['brut'] ?? 0) + ($actif['BU']['brut'] ?? 0);
    $actif['BZ']['amort_deprec'] = ($actif['AZ']['amort_deprec'] ?? 0) + ($actif['BK']['amort_deprec'] ?? 0) + ($actif['BT']['amort_deprec'] ?? 0) + ($actif['BU']['amort_deprec'] ?? 0);
    $actif['BZ']['net'] = ($actif['AZ']['net'] ?? 0) + ($actif['BK']['net'] ?? 0) + ($actif['BT']['net'] ?? 0) + ($actif['BU']['net'] ?? 0);

    // PASSIF
    // Calcul spécifique pour CF (Report à nouveau) et CJ (Résultat net de l'exercice)

    // CF (Report à nouveau) en N = Cumul de tous les résultats jusqu'à fin N-1
    $sql_report_nouveau_n = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as report_nouveau
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 2) = '12'
        AND e.date_ecriture < ?
        AND e.statut = 'Validé'
    ";
    $stmt_report_n = $db->prepare($sql_report_nouveau_n);
    $stmt_report_n->execute([$date_debut]);
    $passif['CF']['net'] = $stmt_report_n->fetchColumn();

    // CJ (Résultat net de l'exercice) = Produits - Charges (Classes 6, 7, 8)
    // C'est le résultat de l'exercice en cours qui doit figurer au passif pour équilibrer le bilan
    $sql_resultat_exercice = "
        SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_net
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
        LEFT JOIN ecritures e ON le.id_ecriture = e.id
        WHERE pc.actif = 'Oui'
        AND LEFT(pc.compte, 1) IN ('6', '7', '8')
        AND e.date_ecriture BETWEEN ? AND ?
        AND e.statut = 'Validé'
    ";
    $stmt_resultat = $db->prepare($sql_resultat_exercice);
    $stmt_resultat->execute([$date_debut, $date_fin]);
    $passif['CJ']['net'] = $stmt_resultat->fetchColumn();

    // CP = CA + CB + CD + CE + CF + CG + CH + CJ + CL + CM
    $passif['CP']['net'] = ($passif['CA']['net'] ?? 0) + ($passif['CB']['net'] ?? 0) + ($passif['CD']['net'] ?? 0) +
                           ($passif['CE']['net'] ?? 0) + ($passif['CF']['net'] ?? 0) + ($passif['CG']['net'] ?? 0) +
                           ($passif['CH']['net'] ?? 0) + ($passif['CJ']['net'] ?? 0) + ($passif['CL']['net'] ?? 0) +
                           ($passif['CM']['net'] ?? 0);

    // DD = DA + DB + DC
    $passif['DD']['net'] = ($passif['DA']['net'] ?? 0) + ($passif['DB']['net'] ?? 0) + ($passif['DC']['net'] ?? 0);

    // DF = CP + DD
    $passif['DF']['net'] = ($passif['CP']['net'] ?? 0) + ($passif['DD']['net'] ?? 0);

    // DP = DH + DI + DJ + DK + DM + DN
    $passif['DP']['net'] = ($passif['DH']['net'] ?? 0) + ($passif['DI']['net'] ?? 0) + ($passif['DJ']['net'] ?? 0) +
                           ($passif['DK']['net'] ?? 0) + ($passif['DM']['net'] ?? 0) + ($passif['DN']['net'] ?? 0);

    // DT = DQ + DR
    $passif['DT']['net'] = ($passif['DQ']['net'] ?? 0) + ($passif['DR']['net'] ?? 0);

    // DZ = DF + DP + DT + DV
    $passif['DZ']['net'] = ($passif['DF']['net'] ?? 0) + ($passif['DP']['net'] ?? 0) + ($passif['DT']['net'] ?? 0) + ($passif['DV']['net'] ?? 0);

    // ============================================================
    // CALCUL PÉRIODE N-1 (Année précédente)
    // ============================================================

    // Récupérer tous les comptes de bilan avec leurs soldes pour N-1
    $stmt_n1 = $db->prepare($sql);
    $stmt_n1->execute([$date_fin_n1, $date_fin_n1]);
    $comptes_n1 = $stmt_n1->fetchAll();

    // Tableaux pour stocker les détails des comptes par rubrique (N-1)
    $details_actif_n1 = [];
    $details_passif_n1 = [];

    // Organiser les comptes par rubrique pour N-1
    foreach ($comptes_n1 as $compte) {
        $solde = $compte['total_debit'] - $compte['total_credit'];
        $prefix1 = substr($compte['compte'], 0, 1);
        $prefix2 = substr($compte['compte'], 0, 2);

        if ($solde == 0) continue;

        // Exclure les comptes de résultat (6, 7, 8) car le résultat est calculé séparément dans CJ
        if ($prefix1 == '6' || $prefix1 == '7' || $prefix1 == '8') continue;

        // Exclure le compte 13 (Résultat net) car il sera remplacé par le calcul dans CJ
        if ($prefix2 == '13') continue;

        // Exclure les comptes de reclassement ET leurs dépréciations
        $prefix3 = substr($compte['compte'], 0, 3);
        if ($prefix3 == '416' || $prefix3 == '426' || $prefix3 == '491' || $prefix3 == '496') continue;

        // Exception: Les comptes 28, 29, 39, 49, 59 (amortissements/dépréciations) vont toujours en AMORT à l'actif
        if ($prefix2 == '28' || $prefix2 == '29' || $prefix2 == '39' || $prefix2 == '49' || $prefix2 == '59') {
            if (!empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif_n1[$ref])) {
                    $actif_n1[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                    $details_actif_n1[$ref] = [];
                }
                $actif_n1[$ref]['amort_deprec'] += abs($solde);
                $actif_n1[$ref]['net'] -= abs($solde);

                // Stocker les détails du compte
                $details_actif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => 0,
                    'amort_deprec' => abs($solde),
                    'net' => -abs($solde),
                    'type' => 'amort'
                ];
            }
        }
        // Cas 1: BD ≠ BC - Le compte va à l'actif OU au passif selon le signe du solde
        elseif ($compte['bd'] != $compte['bc']) {
            if ($solde > 0 && !empty($compte['bd'])) {
                $ref = $compte['bd'];
                if (!isset($actif_n1[$ref])) {
                    $actif_n1[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                    $details_actif_n1[$ref] = [];
                }
                $actif_n1[$ref]['brut'] += $solde;
                $actif_n1[$ref]['net'] += $solde;

                // Stocker les détails du compte
                $details_actif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => $solde,
                    'amort_deprec' => 0,
                    'net' => $solde,
                    'type' => 'normal'
                ];
            } elseif ($solde < 0 && !empty($compte['bc'])) {
                $ref = $compte['bc'];
                if (!isset($passif_n1[$ref])) {
                    $passif_n1[$ref] = ['net' => 0];
                    $details_passif_n1[$ref] = [];
                }
                $passif_n1[$ref]['net'] += abs($solde);

                // Stocker les détails du compte
                $details_passif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'net' => abs($solde)
                ];
            }
        }
        // Cas 2: BD = BC - Le compte reste dans la même rubrique
        elseif ($compte['bd'] == $compte['bc'] && !empty($compte['bd'])) {
            $ref = $compte['bd'];

            if (in_array($ref, $rubriques_actif)) {
                if (!isset($actif_n1[$ref])) {
                    $actif_n1[$ref] = ['brut' => 0, 'amort_deprec' => 0, 'net' => 0];
                    $details_actif_n1[$ref] = [];
                }
                $actif_n1[$ref]['brut'] += $solde;
                $actif_n1[$ref]['net'] += $solde;

                // Stocker les détails du compte
                $details_actif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'brut' => $solde,
                    'amort_deprec' => 0,
                    'net' => $solde,
                    'type' => 'normal'
                ];
            } elseif (in_array($ref, $rubriques_passif)) {
                if (!isset($passif_n1[$ref])) {
                    $passif_n1[$ref] = ['net' => 0];
                    $details_passif_n1[$ref] = [];
                }
                $passif_n1[$ref]['net'] -= $solde;

                // Stocker les détails du compte
                $details_passif_n1[$ref][] = [
                    'compte' => $compte['compte'],
                    'intitule' => $compte['intitule_compte'],
                    'net' => -$solde
                ];
            }
        }
    }

    // Calculer le total global des amortissements N-1
    $total_amort_deprec_n1 = 0;
    foreach ($actif_n1 as $ref => $data) {
        $total_amort_deprec_n1 += $data['amort_deprec'];
    }

    // Calculer les totaux N-1 selon les formules SYSCOHADA
    // ACTIF N-1
    $actif_n1['AD']['brut'] = ($actif_n1['AE']['brut'] ?? 0) + ($actif_n1['AF']['brut'] ?? 0) + ($actif_n1['AG']['brut'] ?? 0) + ($actif_n1['AH']['brut'] ?? 0);
    $actif_n1['AD']['amort_deprec'] = ($actif_n1['AE']['amort_deprec'] ?? 0) + ($actif_n1['AF']['amort_deprec'] ?? 0) + ($actif_n1['AG']['amort_deprec'] ?? 0) + ($actif_n1['AH']['amort_deprec'] ?? 0);
    $actif_n1['AD']['net'] = ($actif_n1['AE']['net'] ?? 0) + ($actif_n1['AF']['net'] ?? 0) + ($actif_n1['AG']['net'] ?? 0) + ($actif_n1['AH']['net'] ?? 0);

    $actif_n1['AI']['brut'] = ($actif_n1['AJ']['brut'] ?? 0) + ($actif_n1['AK']['brut'] ?? 0) + ($actif_n1['AL']['brut'] ?? 0) + ($actif_n1['AM']['brut'] ?? 0) + ($actif_n1['AN']['brut'] ?? 0);
    $actif_n1['AI']['amort_deprec'] = ($actif_n1['AJ']['amort_deprec'] ?? 0) + ($actif_n1['AK']['amort_deprec'] ?? 0) + ($actif_n1['AL']['amort_deprec'] ?? 0) + ($actif_n1['AM']['amort_deprec'] ?? 0) + ($actif_n1['AN']['amort_deprec'] ?? 0);
    $actif_n1['AI']['net'] = ($actif_n1['AJ']['net'] ?? 0) + ($actif_n1['AK']['net'] ?? 0) + ($actif_n1['AL']['net'] ?? 0) + ($actif_n1['AM']['net'] ?? 0) + ($actif_n1['AN']['net'] ?? 0);

    $actif_n1['AQ']['brut'] = ($actif_n1['AR']['brut'] ?? 0) + ($actif_n1['AS']['brut'] ?? 0);
    $actif_n1['AQ']['amort_deprec'] = ($actif_n1['AR']['amort_deprec'] ?? 0) + ($actif_n1['AS']['amort_deprec'] ?? 0);
    $actif_n1['AQ']['net'] = ($actif_n1['AR']['net'] ?? 0) + ($actif_n1['AS']['net'] ?? 0);

    $actif_n1['AZ']['brut'] = ($actif_n1['AD']['brut'] ?? 0) + ($actif_n1['AI']['brut'] ?? 0) + ($actif_n1['AQ']['brut'] ?? 0) + ($actif_n1['AP']['brut'] ?? 0);
    $actif_n1['AZ']['amort_deprec'] = ($actif_n1['AD']['amort_deprec'] ?? 0) + ($actif_n1['AI']['amort_deprec'] ?? 0) + ($actif_n1['AQ']['amort_deprec'] ?? 0) + ($actif_n1['AP']['amort_deprec'] ?? 0);
    $actif_n1['AZ']['net'] = ($actif_n1['AD']['net'] ?? 0) + ($actif_n1['AI']['net'] ?? 0) + ($actif_n1['AQ']['net'] ?? 0) + ($actif_n1['AP']['net'] ?? 0);

    $actif_n1['BG']['brut'] = ($actif_n1['BH']['brut'] ?? 0) + ($actif_n1['BI']['brut'] ?? 0) + ($actif_n1['BJ']['brut'] ?? 0);
    $actif_n1['BG']['amort_deprec'] = ($actif_n1['BH']['amort_deprec'] ?? 0) + ($actif_n1['BI']['amort_deprec'] ?? 0) + ($actif_n1['BJ']['amort_deprec'] ?? 0);
    $actif_n1['BG']['net'] = ($actif_n1['BH']['net'] ?? 0) + ($actif_n1['BI']['net'] ?? 0) + ($actif_n1['BJ']['net'] ?? 0);

    $actif_n1['BK']['brut'] = ($actif_n1['BA']['brut'] ?? 0) + ($actif_n1['BB']['brut'] ?? 0) + ($actif_n1['BG']['brut'] ?? 0);
    $actif_n1['BK']['amort_deprec'] = ($actif_n1['BA']['amort_deprec'] ?? 0) + ($actif_n1['BB']['amort_deprec'] ?? 0) + ($actif_n1['BG']['amort_deprec'] ?? 0);
    $actif_n1['BK']['net'] = ($actif_n1['BA']['net'] ?? 0) + ($actif_n1['BB']['net'] ?? 0) + ($actif_n1['BG']['net'] ?? 0);

    $actif_n1['BT']['brut'] = ($actif_n1['BQ']['brut'] ?? 0) + ($actif_n1['BR']['brut'] ?? 0) + ($actif_n1['BS']['brut'] ?? 0);
    $actif_n1['BT']['amort_deprec'] = ($actif_n1['BQ']['amort_deprec'] ?? 0) + ($actif_n1['BR']['amort_deprec'] ?? 0) + ($actif_n1['BS']['amort_deprec'] ?? 0);
    $actif_n1['BT']['net'] = ($actif_n1['BQ']['net'] ?? 0) + ($actif_n1['BR']['net'] ?? 0) + ($actif_n1['BS']['net'] ?? 0);

    $actif_n1['BZ']['brut'] = ($actif_n1['AZ']['brut'] ?? 0) + ($actif_n1['BK']['brut'] ?? 0) + ($actif_n1['BT']['brut'] ?? 0) + ($actif_n1['BU']['brut'] ?? 0);
    $actif_n1['BZ']['amort_deprec'] = ($actif_n1['AZ']['amort_deprec'] ?? 0) + ($actif_n1['BK']['amort_deprec'] ?? 0) + ($actif_n1['BT']['amort_deprec'] ?? 0) + ($actif_n1['BU']['amort_deprec'] ?? 0);
    $actif_n1['BZ']['net'] = ($actif_n1['AZ']['net'] ?? 0) + ($actif_n1['BK']['net'] ?? 0) + ($actif_n1['BT']['net'] ?? 0) + ($actif_n1['BU']['net'] ?? 0);

    // PASSIF N-1
    // Calcul spécifique pour CF (Report à nouveau) et CJ (Résultat net de l'exercice) en N-1

    // CF (Report à nouveau) en N-1 = Cumul de tous les résultats jusqu'à fin N-2
    $stmt_report_n1 = $db->prepare($sql_report_nouveau_n);
    $stmt_report_n1->execute([$date_debut_n1]);
    $passif_n1['CF']['net'] = $stmt_report_n1->fetchColumn();

    // CJ (Résultat net de l'exercice N-1) = Produits - Charges de la période N-1
    $stmt_resultat_n1 = $db->prepare($sql_resultat_exercice);
    $stmt_resultat_n1->execute([$date_debut_n1, $date_fin_n1]);
    $passif_n1['CJ']['net'] = $stmt_resultat_n1->fetchColumn();

    $passif_n1['CP']['net'] = ($passif_n1['CA']['net'] ?? 0) + ($passif_n1['CB']['net'] ?? 0) + ($passif_n1['CD']['net'] ?? 0) +
                           ($passif_n1['CE']['net'] ?? 0) + ($passif_n1['CF']['net'] ?? 0) + ($passif_n1['CG']['net'] ?? 0) +
                           ($passif_n1['CH']['net'] ?? 0) + ($passif_n1['CJ']['net'] ?? 0) + ($passif_n1['CL']['net'] ?? 0) +
                           ($passif_n1['CM']['net'] ?? 0);

    $passif_n1['DD']['net'] = ($passif_n1['DA']['net'] ?? 0) + ($passif_n1['DB']['net'] ?? 0) + ($passif_n1['DC']['net'] ?? 0);

    $passif_n1['DF']['net'] = ($passif_n1['CP']['net'] ?? 0) + ($passif_n1['DD']['net'] ?? 0);

    $passif_n1['DP']['net'] = ($passif_n1['DH']['net'] ?? 0) + ($passif_n1['DI']['net'] ?? 0) + ($passif_n1['DJ']['net'] ?? 0) +
                           ($passif_n1['DK']['net'] ?? 0) + ($passif_n1['DM']['net'] ?? 0) + ($passif_n1['DN']['net'] ?? 0);

    $passif_n1['DT']['net'] = ($passif_n1['DQ']['net'] ?? 0) + ($passif_n1['DR']['net'] ?? 0);

    $passif_n1['DZ']['net'] = ($passif_n1['DF']['net'] ?? 0) + ($passif_n1['DP']['net'] ?? 0) + ($passif_n1['DT']['net'] ?? 0) + ($passif_n1['DV']['net'] ?? 0);

} catch (Exception $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur lors du calcul du bilan: ' . $e->getMessage()];
}

// Définir les libellés des rubriques selon SYSCOHADA
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

// Structure des groupes collapsibles pour le bilan condensé
$groupes_actif = [
    'AD' => ['AE', 'AF', 'AG', 'AH'],
    'AI' => ['AJ', 'AK', 'AL', 'AM', 'AN', 'AP'],
    'AQ' => ['AR', 'AS'],
    'BG' => ['BH', 'BI', 'BJ'],
    'BT' => ['BQ', 'BR', 'BS']
];

$groupes_passif = [
    'CP' => ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM'],
    'DD' => ['DA', 'DB', 'DC'],
    'DP' => ['DH', 'DI', 'DJ', 'DK', 'DM', 'DN'],
    'DT' => ['DQ', 'DR']
];

$pageTitle = "Bilan OHADA";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité OHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        /* ============================================ */
        /* SYSTÈME TYPOGRAPHIQUE HARMONISÉ             */
        /* Conforme au GUIDE_TYPOGRAPHIQUE.md          */
        /* ============================================ */
        :root {
            --font-size-xs: 10px;      /* Extra small - labels secondaires, références */
            --font-size-sm: 11px;      /* Small - données tableau */
            --font-size-base: 12px;    /* Base - texte normal */
            --font-size-md: 13px;      /* Medium - en-têtes tableau */
            --font-size-lg: 16px;      /* Large - titres sections */
            --font-size-xl: 20px;      /* Extra large - titre principal */
        }

        body {
            font-size: var(--font-size-base);
        }

        /* Classes pour les colonnes de montants */
        .col-montant {
            min-width: 100px;
            max-width: 100px;
            font-size: var(--font-size-sm);
            font-family: 'Courier New', monospace;
            white-space: nowrap;
            overflow: visible;
            padding: 8px 4px !important;
            text-align: right;
        }

        .col-montant-header {
            font-size: var(--font-size-xs);
            min-width: 100px;
            max-width: 100px;
            padding: 8px 4px !important;
        }

        /* Colonnes de référence */
        .col-ref {
            font-size: var(--font-size-xs);
            font-family: 'Courier New', monospace;
        }

        /* Colonnes de libellés */
        .col-libelle {
            font-size: var(--font-size-sm);
        }

        /* Titre principal de page */
        .page-title {
            font-size: var(--font-size-xl);
        }

        /* Titres de sections */
        .section-title {
            font-size: var(--font-size-lg);
        }

        /* En-têtes de tableau */
        .table-header {
            font-size: var(--font-size-xs);
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto p-8">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-600 mb-2">
                            <i class="fas fa-balance-scale mr-3"></i>Bilan SYSCOHADA Révisé
                        </h1>
                        <p class="text-slate-400" style="font-size: var(--font-size-base);">État de la situation patrimoniale du <?= date('d/m/Y', strtotime($date_debut)) ?> au <?= date('d/m/Y', strtotime($date_fin)) ?></p>
                    </div>
                    <a href="../rapports/index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                </div>
            </div>

            <!-- Filtres -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 border border-slate-700 mb-6">
                <form method="GET">
                    <div class="flex flex-wrap items-end gap-3">
                        <!-- Date début -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-1"></i>Début
                            </label>
                            <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                                   class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100">
                        </div>

                        <!-- Date fin -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                <i class="fas fa-calendar-alt mr-1"></i>Fin
                            </label>
                            <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                                   class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-100">
                        </div>

                        <!-- Bouton Mode Condensé -->
                        <button type="button" id="btnToggleCondense" onclick="toggleCondensedMode()" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-compress-alt"></i>
                            <span id="btnCondenseText">Condensé</span>
                        </button>

                        <!-- Bouton Afficher -->
                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-search"></i>
                            Afficher
                        </button>

                        <!-- Bouton Réinitialiser -->
                        <a href="bilan.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-redo"></i>
                            Réinit.
                        </a>

                        <!-- Séparateur vertical -->
                        <div class="h-10 w-px bg-slate-600"></div>

                        <!-- Bouton PDF -->
                        <button type="button" onclick="exportPDF()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>

                        <!-- Bouton Excel -->
                        <button type="button" onclick="exportExcel()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </form>
            </div>

            <!-- Bilan en un seul tableau vertical -->
            <div class="mb-6">
                <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <!-- SECTION ACTIF -->
                            <thead class="bg-gradient-to-r from-blue-600 to-blue-700">
                                <tr>
                                    <th colspan="8" class="px-6 py-4 text-left section-title font-bold text-white">ACTIF</th>
                                </tr>
                                <tr class="bg-gradient-to-r from-slate-700 to-slate-800">
                                    <th class="px-2 py-2 text-left table-header font-semibold text-slate-300 uppercase">REF</th>
                                    <th class="px-2 py-2 text-left table-header font-semibold text-slate-300 uppercase">ACTIF</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">BRUT</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">AMORT</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">NET (N)</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">NET (N-1)</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">VAR</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">%</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php
                                foreach ($libelles_actif as $ref => $libelle):
                                    $is_total = strpos($libelle, 'TOTAL') !== false;
                                    $is_uppercase = strtoupper($libelle) === $libelle;

                                    $net_n = isset($actif[$ref]) ? $actif[$ref]['net'] : 0;
                                    $net_n1 = isset($actif_n1[$ref]) ? $actif_n1[$ref]['net'] : 0;
                                    $brut = isset($actif[$ref]) ? $actif[$ref]['brut'] : 0;
                                    $amort = isset($actif[$ref]) ? $actif[$ref]['amort_deprec'] : 0;

                                    // Calcul variation et taux
                                    $variation = $net_n - $net_n1;
                                    $taux = ($net_n1 != 0) ? (($variation / $net_n1) * 100) : 0;

                                    // Déterminer si c'est un parent ou un enfant
                                    $is_parent = array_key_exists($ref, $groupes_actif);
                                    $parent_ref = '';
                                    foreach ($groupes_actif as $parent => $children) {
                                        if (in_array($ref, $children)) {
                                            $parent_ref = $parent;
                                            break;
                                        }
                                    }
                                    $is_child = !empty($parent_ref);

                                    $data_attrs = '';
                                    if ($is_parent) {
                                        $data_attrs = 'data-group="parent" data-ref="' . $ref . '"';
                                    } elseif ($is_child) {
                                        $data_attrs = 'data-group="child" data-parent="' . $parent_ref . '"';
                                    }
                                ?>
                                    <?php
                                    // Vérifier si cette rubrique a des détails
                                    $has_details = isset($details_actif[$ref]) && count($details_actif[$ref]) > 0;
                                    ?>
                                    <tr class="hover:bg-slate-700/30 transition-colors <?= $is_total || $is_uppercase ? 'bg-slate-700/50 font-bold' : '' ?>" <?= $data_attrs ?>>
                                        <td class="px-2 py-2 text-slate-300 col-ref"><?= $ref ?></td>
                                        <td class="px-2 py-2 text-slate-300 col-libelle <?= $is_uppercase ? 'font-bold' : '' ?>">
                                            <?php if ($is_parent): ?>
                                                <i class="fas fa-chevron-down expand-icon mr-2 text-xs transition-transform cursor-pointer" onclick="toggleGroup('<?= $ref ?>', 'actif')"></i>
                                            <?php endif; ?>
                                            <?php if ($is_child): ?>
                                                <span class="ml-4"></span>
                                            <?php endif; ?>
                                            <?php if ($has_details && !$is_total): ?>
                                                <button onclick="toggleDetailsActif('<?= $ref ?>')" class="inline-flex items-center gap-1 hover:text-blue-400 transition-colors">
                                                    <i class="fas fa-chevron-right text-xs transition-transform" id="icon-actif-<?= $ref ?>"></i>
                                                    <?= htmlspecialchars($libelle) ?>
                                                </button>
                                            <?php else: ?>
                                                <?= htmlspecialchars($libelle) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-montant text-slate-300">
                                            <?= $brut > 0 ? safe_number_format($brut, 2) : '' ?>
                                        </td>
                                        <td class="col-montant text-slate-300">
                                            <?= $amort > 0 ? safe_number_format($amort, 2) : '' ?>
                                        </td>
                                        <td class="col-montant <?= $net_n > 0 ? 'text-blue-400 font-semibold' : 'text-slate-500' ?>">
                                            <?= $net_n > 0 ? safe_number_format($net_n, 2) : '' ?>
                                        </td>
                                        <td class="col-montant text-slate-400">
                                            <?= $net_n1 > 0 ? safe_number_format($net_n1, 2) : '' ?>
                                        </td>
                                        <td class="col-montant <?= $variation > 0 ? 'text-green-400' : ($variation < 0 ? 'text-red-400' : 'text-slate-500') ?>">
                                            <?php if ($variation != 0): ?>
                                                <?= $variation > 0 ? '+' : '' ?><?= safe_number_format($variation, 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-montant <?= $taux > 0 ? 'text-green-400' : ($taux < 0 ? 'text-red-400' : 'text-slate-500') ?>">
                                            <?php if ($net_n1 != 0 && $variation != 0): ?>
                                                <?= $taux > 0 ? '+' : '' ?><?= number_format($taux, 1) ?>%
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <?php if ($has_details && !$is_total): ?>
                                    <!-- Ligne de détails pour l'ACTIF (initialement cachée) -->
                                    <tr id="details-actif-<?= $ref ?>" class="hidden bg-slate-800/50" <?= $data_attrs ?>>
                                        <td colspan="8" class="px-0 py-0">
                                            <div class="py-3">
                                                <table class="w-full text-xs">
                                                    <colgroup>
                                                        <col style="width: 80px;">
                                                        <col>
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                    </colgroup>
                                                    <thead>
                                                        <tr class="text-slate-400 border-b border-slate-600">
                                                            <th class="text-left py-1 px-2 font-semibold">COMPTE</th>
                                                            <th class="text-left py-1 px-2 font-semibold">INTITULÉ</th>
                                                            <th class="text-right py-1 px-2 font-semibold col-montant-header">BRUT</th>
                                                            <th class="text-right py-1 px-2 font-semibold col-montant-header">AMORT</th>
                                                            <th class="text-right py-1 px-2 font-semibold col-montant-header">NET (N)</th>
                                                            <th class="text-right py-1 px-2 font-semibold col-montant-header">NET (N-1)</th>
                                                            <th class="py-1 px-2"></th>
                                                            <th class="py-1 px-2"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $details = $details_actif[$ref];
                                                        $details_n1 = $details_actif_n1[$ref] ?? [];

                                                        // Créer un tableau associatif pour N-1 par compte
                                                        $details_n1_by_compte = [];
                                                        foreach ($details_n1 as $d) {
                                                            $details_n1_by_compte[$d['compte']] = $d;
                                                        }

                                                        // Afficher tous les comptes (N et N-1 combinés)
                                                        foreach ($details as $detail):
                                                            $detail_n1 = $details_n1_by_compte[$detail['compte']] ?? null;
                                                        ?>
                                                            <tr class="hover:bg-slate-700/30 border-b border-slate-700/30">
                                                                <td class="py-1 px-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($detail['compte']) ?></td>
                                                                <td class="py-1 px-2 text-slate-300 text-xs"><?= htmlspecialchars($detail['intitule']) ?></td>
                                                                <td class="col-montant text-slate-200">
                                                                    <?= $detail['brut'] != 0 ? safe_number_format(abs($detail['brut']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant text-slate-200">
                                                                    <?= $detail['amort_deprec'] != 0 ? safe_number_format(abs($detail['amort_deprec']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant text-slate-200">
                                                                    <?= $detail['net'] != 0 ? safe_number_format(abs($detail['net']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant text-slate-400">
                                                                    <?= $detail_n1 && $detail_n1['net'] != 0 ? safe_number_format(abs($detail_n1['net']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant"></td>
                                                            </tr>
                                                        <?php endforeach;

                                                        // Afficher les comptes qui existent seulement en N-1
                                                        foreach ($details_n1 as $detail_n1):
                                                            $already_shown = false;
                                                            foreach ($details as $d) {
                                                                if ($d['compte'] === $detail_n1['compte']) {
                                                                    $already_shown = true;
                                                                    break;
                                                                }
                                                            }
                                                            if ($already_shown) continue;
                                                        ?>
                                                            <tr class="hover:bg-slate-700/30 border-b border-slate-700/30">
                                                                <td class="py-1 px-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($detail_n1['compte']) ?></td>
                                                                <td class="py-1 px-2 text-slate-300 text-xs"><?= htmlspecialchars($detail_n1['intitule']) ?></td>
                                                                <td class="col-montant text-slate-400"></td>
                                                                <td class="col-montant text-slate-400"></td>
                                                                <td class="col-montant text-slate-400"></td>
                                                                <td class="col-montant text-slate-400">
                                                                    <?= $detail_n1['net'] != 0 ? safe_number_format(abs($detail_n1['net']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant"></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>

                            <!-- SECTION PASSIF -->
                            <thead class="bg-gradient-to-r from-cyan-600 to-cyan-700">
                                <tr>
                                    <th colspan="8" class="px-6 py-4 text-left section-title font-bold text-white">PASSIF</th>
                                </tr>
                                <tr class="bg-gradient-to-r from-slate-700 to-slate-800">
                                    <th class="px-2 py-2 text-left table-header font-semibold text-slate-300 uppercase">REF</th>
                                    <th class="px-2 py-2 text-left table-header font-semibold text-slate-300 uppercase">PASSIF</th>
                                    <th class="px-2 py-2 text-right table-header font-semibold text-slate-300 uppercase" colspan="2"></th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">NET (N)</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">NET (N-1)</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">VAR</th>
                                    <th class="px-2 py-2 text-right col-montant-header font-semibold text-slate-300 uppercase">%</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php
                                foreach ($libelles_passif as $ref => $libelle):
                                    $is_total = strpos($libelle, 'TOTAL') !== false;
                                    $is_uppercase = strtoupper($libelle) === $libelle;

                                    $net_n = isset($passif[$ref]) ? $passif[$ref]['net'] : 0;
                                    $net_n1 = isset($passif_n1[$ref]) ? $passif_n1[$ref]['net'] : 0;

                                    // Calcul variation et taux
                                    $variation = $net_n - $net_n1;
                                    $taux = ($net_n1 != 0) ? (($variation / $net_n1) * 100) : 0;

                                    // Déterminer si c'est un parent ou un enfant
                                    $is_parent = array_key_exists($ref, $groupes_passif);
                                    $parent_ref = '';
                                    foreach ($groupes_passif as $parent => $children) {
                                        if (in_array($ref, $children)) {
                                            $parent_ref = $parent;
                                            break;
                                        }
                                    }
                                    $is_child = !empty($parent_ref);

                                    $data_attrs = '';
                                    if ($is_parent) {
                                        $data_attrs = 'data-group="parent" data-ref="' . $ref . '"';
                                    } elseif ($is_child) {
                                        $data_attrs = 'data-group="child" data-parent="' . $parent_ref . '"';
                                    }
                                    // Vérifier si cette rubrique a des détails
                                    $has_details = isset($details_passif[$ref]) && count($details_passif[$ref]) > 0;
                                ?>
                                    <tr class="hover:bg-slate-700/30 transition-colors <?= $is_total || $is_uppercase ? 'bg-slate-700/50 font-bold' : '' ?>" <?= $data_attrs ?>>
                                        <td class="px-2 py-2 text-slate-300 col-ref"><?= $ref ?></td>
                                        <td class="px-2 py-2 text-slate-300 col-libelle <?= $is_uppercase ? 'font-bold' : '' ?>">
                                            <?php if ($is_parent): ?>
                                                <i class="fas fa-chevron-down expand-icon mr-2 text-xs transition-transform cursor-pointer" onclick="toggleGroup('<?= $ref ?>', 'passif')"></i>
                                            <?php endif; ?>
                                            <?php if ($is_child): ?>
                                                <span class="ml-4"></span>
                                            <?php endif; ?>
                                            <?php if ($has_details && !$is_total): ?>
                                                <button onclick="toggleDetailsPassif('<?= $ref ?>')" class="inline-flex items-center gap-1 hover:text-blue-400 transition-colors">
                                                    <i class="fas fa-chevron-right text-xs transition-transform" id="icon-passif-<?= $ref ?>"></i>
                                                    <?= htmlspecialchars($libelle) ?>
                                                </button>
                                            <?php else: ?>
                                                <?= htmlspecialchars($libelle) ?>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Colonnes vides pour BRUT et AMORT -->
                                        <td class="px-2 py-2"></td>
                                        <td class="px-2 py-2"></td>
                                        <td class="col-montant <?= $net_n != 0 ? 'text-cyan-400 font-semibold' : 'text-slate-500' ?>">
                                            <?= $net_n != 0 ? safe_number_format($net_n, 2) : '' ?>
                                        </td>
                                        <td class="col-montant text-slate-400">
                                            <?= $net_n1 != 0 ? safe_number_format($net_n1, 2) : '' ?>
                                        </td>
                                        <td class="col-montant <?= $variation > 0 ? 'text-green-400' : ($variation < 0 ? 'text-red-400' : 'text-slate-500') ?>">
                                            <?php if ($variation != 0): ?>
                                                <?= $variation > 0 ? '+' : '' ?><?= safe_number_format($variation, 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-montant <?= $taux > 0 ? 'text-green-400' : ($taux < 0 ? 'text-red-400' : 'text-slate-500') ?>">
                                            <?php if ($net_n1 != 0 && $variation != 0): ?>
                                                <?= $taux > 0 ? '+' : '' ?><?= number_format($taux, 1) ?>%
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <?php if ($has_details && !$is_total): ?>
                                    <!-- Ligne de détails pour le PASSIF (initialement cachée) -->
                                    <tr id="details-passif-<?= $ref ?>" class="hidden bg-slate-800/50" <?= $data_attrs ?>>
                                        <td colspan="8" class="px-0 py-0">
                                            <div class="py-3">
                                                <table class="w-full text-xs">
                                                    <colgroup>
                                                        <col style="width: 80px;">
                                                        <col>
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                        <col class="col-montant">
                                                    </colgroup>
                                                    <thead>
                                                        <tr class="text-slate-400 border-b border-slate-600">
                                                            <th class="text-left py-1 px-2 font-semibold">COMPTE</th>
                                                            <th class="text-left py-1 px-2 font-semibold">INTITULÉ</th>
                                                            <th class="py-1 px-2"></th>
                                                            <th class="py-1 px-2"></th>
                                                            <th class="text-right py-1 px-2 font-semibold col-montant-header">NET (N)</th>
                                                            <th class="text-right py-1 px-2 font-semibold col-montant-header">NET (N-1)</th>
                                                            <th class="py-1 px-2"></th>
                                                            <th class="py-1 px-2"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $details = $details_passif[$ref];
                                                        $details_n1 = $details_passif_n1[$ref] ?? [];

                                                        // Créer un tableau associatif pour N-1 par compte
                                                        $details_n1_by_compte = [];
                                                        foreach ($details_n1 as $d) {
                                                            $details_n1_by_compte[$d['compte']] = $d;
                                                        }

                                                        // Afficher tous les comptes (N et N-1 combinés)
                                                        foreach ($details as $detail):
                                                            $detail_n1 = $details_n1_by_compte[$detail['compte']] ?? null;
                                                        ?>
                                                            <tr class="hover:bg-slate-700/30 border-b border-slate-700/30">
                                                                <td class="py-1 px-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($detail['compte']) ?></td>
                                                                <td class="py-1 px-2 text-slate-300 text-xs"><?= htmlspecialchars($detail['intitule']) ?></td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant text-slate-200">
                                                                    <?= $detail['net'] != 0 ? safe_number_format(abs($detail['net']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant text-slate-400">
                                                                    <?= $detail_n1 && $detail_n1['net'] != 0 ? safe_number_format(abs($detail_n1['net']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant"></td>
                                                            </tr>
                                                        <?php endforeach;

                                                        // Afficher les comptes qui existent seulement en N-1
                                                        foreach ($details_n1 as $detail_n1):
                                                            $already_shown = false;
                                                            foreach ($details as $d) {
                                                                if ($d['compte'] === $detail_n1['compte']) {
                                                                    $already_shown = true;
                                                                    break;
                                                                }
                                                            }
                                                            if ($already_shown) continue;
                                                        ?>
                                                            <tr class="hover:bg-slate-700/30 border-b border-slate-700/30">
                                                                <td class="py-1 px-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($detail_n1['compte']) ?></td>
                                                                <td class="py-1 px-2 text-slate-300 text-xs"><?= htmlspecialchars($detail_n1['intitule']) ?></td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant text-slate-400"></td>
                                                                <td class="col-montant text-slate-400">
                                                                    <?= $detail_n1['net'] != 0 ? safe_number_format(abs($detail_n1['net']), 2) : '' ?>
                                                                </td>
                                                                <td class="col-montant"></td>
                                                                <td class="col-montant"></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        let isCondensed = false;
        const collapsedGroups = new Set();

        function toggleCondensedMode() {
            isCondensed = !isCondensed;
            const btn = document.getElementById('btnCondenseText');
            const icon = document.querySelector('#btnToggleCondense i');

            if (isCondensed) {
                // Mode condensé: cacher toutes les lignes enfants
                document.querySelectorAll('tr[data-group="child"]').forEach(row => {
                    anime({
                        targets: row,
                        opacity: [1, 0],
                        height: [row.offsetHeight, 0],
                        duration: 300,
                        easing: 'easeOutQuad',
                        complete: () => {
                            row.style.display = 'none';
                        }
                    });
                });

                // Masquer toutes les icones expand
                document.querySelectorAll('.expand-icon').forEach(icon => {
                    icon.style.display = 'none';
                });

                btn.textContent = 'Détaillé';
                icon.className = 'fas fa-expand-alt';
            } else {
                // Mode détaillé: afficher toutes les lignes
                document.querySelectorAll('tr[data-group="child"]').forEach(row => {
                    row.style.display = '';
                    row.style.height = '';
                    anime({
                        targets: row,
                        opacity: [0, 1],
                        duration: 300,
                        easing: 'easeInQuad'
                    });
                });

                // Réafficher les icones expand
                document.querySelectorAll('.expand-icon').forEach(icon => {
                    icon.style.display = '';
                });

                btn.textContent = 'Condensé';
                icon.className = 'fas fa-compress-alt';

                // Réinitialiser les états des groupes
                collapsedGroups.clear();
                document.querySelectorAll('.expand-icon').forEach(icon => {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-down');
                });
            }

            // Sauvegarder la préférence
            localStorage.setItem('bilanCondensed', isCondensed);
        }

        function toggleGroup(ref, section) {
            // Ne fonctionne qu'en mode détaillé
            if (isCondensed) return;

            const icon = event.target;
            const childRows = document.querySelectorAll(`tr[data-parent="${ref}"]`);
            const isCollapsed = collapsedGroups.has(ref);

            if (isCollapsed) {
                // Expand: afficher les enfants
                childRows.forEach(row => {
                    row.style.display = '';
                    anime({
                        targets: row,
                        opacity: [0, 1],
                        duration: 300,
                        easing: 'easeInQuad'
                    });
                });
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-down');
                collapsedGroups.delete(ref);
            } else {
                // Collapse: cacher les enfants
                childRows.forEach(row => {
                    anime({
                        targets: row,
                        opacity: [1, 0],
                        duration: 300,
                        easing: 'easeOutQuad',
                        complete: () => {
                            row.style.display = 'none';
                        }
                    });
                });
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-right');
                collapsedGroups.add(ref);
            }

            // Sauvegarder l'état des groupes
            localStorage.setItem('bilanCollapsedGroups', JSON.stringify([...collapsedGroups]));
        }

        function toggleDetailsActif(ref) {
            const detailsRow = document.getElementById('details-actif-' + ref);
            const icon = document.getElementById('icon-actif-' + ref);

            if (detailsRow.classList.contains('hidden')) {
                // Afficher les détails
                detailsRow.classList.remove('hidden');
                icon.classList.add('rotate-90');
            } else {
                // Cacher les détails
                detailsRow.classList.add('hidden');
                icon.classList.remove('rotate-90');
            }
        }

        function toggleDetailsPassif(ref) {
            const detailsRow = document.getElementById('details-passif-' + ref);
            const icon = document.getElementById('icon-passif-' + ref);

            if (detailsRow.classList.contains('hidden')) {
                // Afficher les détails
                detailsRow.classList.remove('hidden');
                icon.classList.add('rotate-90');
            } else {
                // Cacher les détails
                detailsRow.classList.add('hidden');
                icon.classList.remove('rotate-90');
            }
        }

        function exportPDF() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>'
            });
            window.location.href = 'export_bilan_pdf.php?' + params.toString();
        }

        function exportExcel() {
            const params = new URLSearchParams({
                date_debut: '<?= $date_debut ?>',
                date_fin: '<?= $date_fin ?>'
            });
            window.location.href = 'export_bilan_excel.php?' + params.toString();
        }

        // Restaurer les préférences au chargement de la page
        document.addEventListener('DOMContentLoaded', function() {
            // Restaurer le mode condensé/détaillé
            const savedState = localStorage.getItem('bilanCondensed');
            if (savedState === 'true') {
                toggleCondensedMode();
            }

            // Restaurer les groupes collapsés (uniquement en mode détaillé)
            if (savedState !== 'true') {
                const savedGroups = localStorage.getItem('bilanCollapsedGroups');
                if (savedGroups) {
                    try {
                        const groups = JSON.parse(savedGroups);
                        groups.forEach(ref => {
                            const icon = document.querySelector(`i[onclick*="${ref}"]`);
                            if (icon) {
                                // Simuler un click sur l'icone
                                const clickEvent = new Event('click');
                                icon.dispatchEvent(clickEvent);
                            }
                        });
                    } catch (e) {
                        console.error('Erreur lors de la restauration des groupes:', e);
                    }
                }
            }
        });
    </script>
</body>
</html>
