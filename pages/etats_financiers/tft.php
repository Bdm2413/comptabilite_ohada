<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

function nf($n, $dec = 0) {
    if ($n === null) return '';
    if (abs($n) < 0.5) return '';
    return number_format($n, $dec, ',', ' ');
}

$date_debut_n = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-01-01');
$date_fin_n   = isset($_GET['date_fin'])   ? $_GET['date_fin']   : date('Y-12-31');
$date_debut_n1 = date('Y-m-d', strtotime($date_debut_n . ' -1 year'));
$date_fin_n1   = date('Y-m-d', strtotime($date_fin_n   . ' -1 year'));
$annee_n  = date('Y', strtotime($date_fin_n));
$annee_n1 = date('Y', strtotime($date_fin_n1));

$sql = "
    SELECT pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS cum_debit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS cum_credit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS mvt_debit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS mvt_credit_N,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS cum_debit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture <= ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS cum_credit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.debit  ELSE 0 END),0) AS mvt_debit_N1,
        COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut='Validé' THEN le.credit ELSE 0 END),0) AS mvt_credit_N1
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON le.compte = pc.compte AND le.societe_id = pc.societe_id
    LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut='Validé' AND e.societe_id = pc.societe_id
    WHERE pc.actif='Oui' AND pc.societe_id = ?
    GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.bd, pc.bc, pc.rd, pc.rc
    ORDER BY pc.compte
";
$stmt = $db->prepare($sql);
$stmt->execute([
    $date_fin_n, $date_fin_n,
    $date_debut_n, $date_fin_n, $date_debut_n, $date_fin_n,
    $date_fin_n1, $date_fin_n1,
    $date_debut_n1, $date_fin_n1, $date_debut_n1, $date_fin_n1,
    $societe_id
]);
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/tft_calculs.php';

// ─── Tooltips pédagogiques ────────────────────────────────────────────
$tooltips = [
    'ZA' => 'Solde initial de trésorerie = Trésorerie actif N-1 − Trésorerie passif N-1.',
    'FA' => 'Capacité d\'Autofinancement Globale. Formule : XD + D(654) − C(754) + XF + TO − RP − RQ − RS.',
    'FB' => 'Variation des actifs circulants HAO (rubrique BA). Hausse = emploi de trésorerie.',
    'FC' => 'Variation des stocks et en-cours (rubrique BB). Hausse = emploi de trésorerie.',
    'FD' => 'Variation des créances d\'exploitation (BH+BI+BJ). Hausse = emploi de trésorerie.',
    'FE' => 'Variation des dettes d\'exploitation (DP). Hausse = ressource de trésorerie.',
    'ZB' => 'Flux opérationnels = FA − FB − FC − FD + FE. Doit être positif pour une entreprise saine.',
    'FF' => 'Décaissements pour acquisitions d\'incorporelles (brevets, licences, fonds commercial…).',
    'FG' => 'Décaissements pour acquisitions de corporelles (terrains, bâtiments, matériels…).',
    'FH' => 'Décaissements pour acquisitions de financières (titres, prêts consentis, dépôts…).',
    'FI' => 'Encaissements sur cessions d\'incorporelles et corporelles (prix de cession).',
    'FJ' => 'Encaissements sur cessions de financières et remboursements de prêts.',
    'ZC' => 'Flux d\'investissement = −FF − FG − FH + FI + FJ. Négatif = l\'entreprise investit.',
    'FK' => 'Apports nouveaux des actionnaires : augmentation du capital social et primes d\'apport.',
    'FL' => 'Subventions reçues des pouvoirs publics pour financer des immobilisations.',
    'FM' => 'Réductions de capital ou remboursements aux actionnaires.',
    'FN' => 'Dividendes effectivement décaissés aux actionnaires au cours de l\'exercice.',
    'ZD' => 'Flux capitaux propres = FK + FL − FM − FN.',
    'FO' => 'Nouveaux emprunts bancaires et obligataires encaissés (161, 162, 1661, 1662).',
    'FP' => 'Autres ressources financières externes (163 à 168, 18x).',
    'FQ' => 'Remboursements du capital des emprunts et autres dettes financières.',
    'ZE' => 'Flux capitaux étrangers = FO + FP − FQ.',
    'ZF' => 'Flux de financement total = ZD + ZE.',
    'ZG' => 'Variation de trésorerie nette sur la période = ZB + ZC + ZF.',
    'ZH' => 'Trésorerie nette au 31/12 = ZA + ZG. Doit correspondre au bilan.',
];

// ─── Données de détail par ligne ─────────────────────────────────────
$detail_rows = [
    'ZA' => [
        'formula' => 'Trésorerie actif (BQ+BR+BS) N-1 − Trésorerie passif (DQ+DR) N-1',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['Trésorerie actif (BQ+BR+BS)', $tresoAct_N1, $tresoAct_N2 ?? null],
            ['− Trésorerie passif (DQ+DR)', -$tresoPass_N1, -$tresoPass_N2 ?? null],
        ],
    ],
    'FA' => [
        'formula' => 'XD + D(654) − C(754) + XF + TO − RP − RQ − RS',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['XD — Excédent Brut d\'Exploitation', $XD_N, $XD_N1],
            ['+ D(654) VNC cessions incorporelles', $vnc_N, $vnc_N1],
            ['− C(754) Produits de cession', -$prodCess_N, -$prodCess_N1],
            ['+ XF — Résultat financier', $XF_N, $XF_N1],
            ['+ TO — Produits HAO', $TO_N, $TO_N1],
            ['− RP — Charges HAO', -$RP_N, -$RP_N1],
            ['− RQ — Participation des travailleurs', -$RQ_N, -$RQ_N1],
            ['− RS — Impôt sur le résultat', -$RS_N, -$RS_N1],
        ],
    ],
    'FB' => [
        'formula' => 'BA(N) − BA(N-1) − 485(N) + 485(N-1) + D(4781) − C(4791)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['Rubrique BA actif circulant HAO (fin N)', $BA_N, $BA_N1],
            ['− Rubrique BA (fin N-1)', -$BA_N1, -$BA_N2],
            ['− Solde débiteur 485 (fin N)', -$s485_N, -$s485_N1],
            ['+ Solde débiteur 485 (fin N-1)', $s485_N1, $s485_N2],
            ['+ Solde débiteur 4781', $d4781_N, $d4781_N1],
            ['− Solde créditeur 4791', -$c4791_N, -$c4791_N1],
        ],
    ],
    'FC' => [
        'formula' => 'BB(N) − BB(N-1)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['Rubrique BB stocks et en-cours (fin N)', $BB_N, $BB_N1],
            ['− Rubrique BB (fin N-1)', -$BB_N1, -$BB_N2],
        ],
    ],
    'FD' => [
        'formula' => '(BH+BI+BJ)(N−N1) + net(4781+4782+4791+4792)(N−N1) − excl.(414,4494,458,461,467,4751) + D(2714)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['Rubriques BH+BI+BJ créances (fin N)', $BH_BI_BJ_N, $BH_BI_BJ_N1],
            ['− BH+BI+BJ (fin N-1)', -$BH_BI_BJ_N1, -$BH_BI_BJ_N2],
            ['+ Net 4781+4782+4791+4792 (fin N)', $c47xx_N, $c47xx_N1],
            ['− Net 4781+4782+4791+4792 (fin N-1)', -$c47xx_N1, -$c47xx_N2],
            ['− Excl. invest./financement (fin N)', -$excl_N, -$excl_N1],
            ['+ Excl. invest./financement (fin N-1)', $excl_N1, $excl_N2],
            ['+ mvt débit 2714 (créances cessions fin.)', $mvt2714_N, $mvt2714_N1],
        ],
    ],
    'FE' => [
        'formula' => 'DP(N) − DP(N-1) − excl.(404,461,465,4726,481,482) + C(4793) − D(4783) + mvt(4752)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['Total passif circulant DP (fin N)', $DP_N, $DP_N1],
            ['− Total passif circulant DP (fin N-1)', -$DP_N1, -$DP_N2],
            ['− Excl. invest./financement (404,461,465,4726,481,482) fin N', -$pexcl_N, -$pexcl_N1],
            ['+ Excl. invest./financement (fin N-1)', $pexcl_N1, $pexcl_N2],
            ['+ Solde créditeur 4793', $c4793_N, $c4793_N1],
            ['− Solde débiteur 4783', -$d4783_N, -$d4783_N1],
            ['+ Mvt 4752 (subventions invest., D+C)', $mvt4752_N, $mvt4752_N1],
        ],
    ],
    'FF' => [
        'formula' => 'VNC AD(N−N1) + mvt_D(251)−mvt_C(251) + mvt_D(4041…811) − mvt_C(4041…1541) + sdD(6541,811)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['VNC rubrique AD incorporelles (fin N)', $vncAD_N, $vncAD_N1],
            ['− VNC rubrique AD (fin N-1)', -$vncAD_N1, -$vncAD_N2],
            ['+ mvt débit 251 − mvt crédit 251', sumPx($comptes, ['251'], 'mvt_debit_N') - sumPx($comptes, ['251'], 'mvt_credit_N'), sumPx($comptes, ['251'], 'mvt_debit_N1') - sumPx($comptes, ['251'], 'mvt_credit_N1')],
            ['+ mvt débit (4041,4046,4811,4816-4818,4821,6541,811)', sumPx($comptes, $FF_PFX_D, 'mvt_debit_N'), sumPx($comptes, $FF_PFX_D, 'mvt_debit_N1')],
            ['− mvt crédit (4041,4046,4811,4816-4818,4821,1984,1061,1062,1541)', -sumPx($comptes, $FF_PFX_C, 'mvt_credit_N'), -sumPx($comptes, $FF_PFX_C, 'mvt_credit_N1')],
            ['+ Solde débiteur clôture 6541', sdPx($comptes, ['6541'], 'N'), sdPx($comptes, ['6541'], 'N1')],
            ['+ Solde débiteur clôture 811', sdPx($comptes, ['811'], 'N'), sdPx($comptes, ['811'], 'N1')],
        ],
    ],
    'FG' => [
        'formula' => 'VNC AI(N−N1) + mvt_D(252)−mvt_C(252) + mvt_D(4042…284) − mvt_C(17,4042…1542) + sdD(6542,812)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['VNC rubrique AI corporelles (fin N)', $vncAI_N, $vncAI_N1],
            ['− VNC rubrique AI (fin N-1)', -$vncAI_N1, -$vncAI_N2],
            ['+ mvt débit 252 − mvt crédit 252', sumPx($comptes, ['252'], 'mvt_debit_N') - sumPx($comptes, ['252'], 'mvt_credit_N'), sumPx($comptes, ['252'], 'mvt_debit_N1') - sumPx($comptes, ['252'], 'mvt_credit_N1')],
            ['+ mvt débit (4042,4047,4812,4816-4818,4822,6542,282-284)', sumPx($comptes, $FG_PFX_D, 'mvt_debit_N'), sumPx($comptes, $FG_PFX_D, 'mvt_debit_N1')],
            ['− mvt crédit (17,4042,4047,4812,4816-4818,4822,1984,1061,1062,1542)', -sumPx($comptes, $FG_PFX_C, 'mvt_credit_N'), -sumPx($comptes, $FG_PFX_C, 'mvt_credit_N1')],
            ['+ Solde débiteur clôture 6542', sdPx($comptes, ['6542'], 'N'), sdPx($comptes, ['6542'], 'N1')],
            ['+ Solde débiteur clôture 812', sdPx($comptes, ['812'], 'N'), sdPx($comptes, ['812'], 'N1')],
        ],
    ],
    'FH' => [
        'formula' => 'mvt_D(26+27 sauf 276,2714) + mvt_D(4813)−mvt_C(4813) − mvt_C(1061,1062,1543) + sdD(4782) + sdC(4792)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ mvt débit 26 (participations)', sumPx($comptes, ['26'], 'mvt_debit_N'), sumPx($comptes, ['26'], 'mvt_debit_N1')],
            ['+ mvt débit 27 sauf 276,2714', sumPx($comptes, ['27'], 'mvt_debit_N') - sumPx($comptes, ['276','2714'], 'mvt_debit_N'), sumPx($comptes, ['27'], 'mvt_debit_N1') - sumPx($comptes, ['276','2714'], 'mvt_debit_N1')],
            ['+ mvt débit 4813 − mvt crédit 4813', sumPx($comptes, ['4813'], 'mvt_debit_N') - sumPx($comptes, ['4813'], 'mvt_credit_N'), sumPx($comptes, ['4813'], 'mvt_debit_N1') - sumPx($comptes, ['4813'], 'mvt_credit_N1')],
            ['− mvt crédit 1061+1062+1543', -sumPx($comptes, ['1061','1062','1543'], 'mvt_credit_N'), -sumPx($comptes, ['1061','1062','1543'], 'mvt_credit_N1')],
            ['+ Solde débiteur clôture 4782', sdPx($comptes, ['4782'], 'N'), sdPx($comptes, ['4782'], 'N1')],
            ['+ Solde créditeur clôture 4792', scPx($comptes, ['4792'], 'N'), scPx($comptes, ['4792'], 'N1')],
        ],
    ],
    'FI' => [
        'formula' => 'sdC(821+822+7541+7542) + mvt_C(4851+4852+4141+4142) − mvt_D(4851+4852+4141+4142)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ Solde créditeur clôture 821+822 (HAO)', scPx($comptes, ['821','822'], 'N'), scPx($comptes, ['821','822'], 'N1')],
            ['+ Solde créditeur clôture 7541+7542', scPx($comptes, ['7541','7542'], 'N'), scPx($comptes, ['7541','7542'], 'N1')],
            ['+ mvt crédit (4851,4852,4141,4142)', sumPx($comptes, $FI_PFX_ADJ, 'mvt_credit_N'), sumPx($comptes, $FI_PFX_ADJ, 'mvt_credit_N1')],
            ['− mvt débit (4851,4852,4141,4142)', -sumPx($comptes, $FI_PFX_ADJ, 'mvt_debit_N'), -sumPx($comptes, $FI_PFX_ADJ, 'mvt_debit_N1')],
        ],
    ],
    'FJ' => [
        'formula' => 'mvt_C(26+27 sauf 2714,2766) + sdC(826) + mvt_C(4143+4856) − mvt_D(4143+4856)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ mvt crédit 26+27 sauf 2714,2766', sumPx($comptes, ['26','27'], 'mvt_credit_N') - sumPx($comptes, ['2714','2766'], 'mvt_credit_N'), sumPx($comptes, ['26','27'], 'mvt_credit_N1') - sumPx($comptes, ['2714','2766'], 'mvt_credit_N1')],
            ['+ Solde créditeur clôture 826 (HAO)', scPx($comptes, ['826'], 'N'), scPx($comptes, ['826'], 'N1')],
            ['+ mvt crédit (4143,4856)', sumPx($comptes, $FJ_PFX_ADJ, 'mvt_credit_N'), sumPx($comptes, $FJ_PFX_ADJ, 'mvt_credit_N1')],
            ['− mvt débit (4143,4856)', -sumPx($comptes, $FJ_PFX_ADJ, 'mvt_debit_N'), -sumPx($comptes, $FJ_PFX_ADJ, 'mvt_debit_N1')],
        ],
    ],
    'FK' => [
        'formula' => 'sdC(101+102+1051, N−N1) − sdD(109+4581+4612+4613+467) − mvt_D(11+1211+131) + mvt_C(103+104+11+1211+1291+1292+139+4619+465)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ sdC 101+102+1051 (fin N)', scPx($comptes, $FK_CAP, 'N'), scPx($comptes, $FK_CAP, 'N1')],
            ['− sdC 101+102+1051 (fin N-1)', -scPx($comptes, $FK_CAP, 'N1'), -scPx_N2($comptes, $FK_CAP)],
            ['− sdD (109,4581,4612,4613,467)', -sdPx($comptes, $FK_DEB, 'N'), -sdPx($comptes, $FK_DEB, 'N1')],
            ['− mvt débit (11,1211,131)', -sumPx($comptes, $FK_MVD, 'mvt_debit_N'), -sumPx($comptes, $FK_MVD, 'mvt_debit_N1')],
            ['+ mvt crédit (103,104,11,1211,1291,1292,139,4619,465)', sumPx($comptes, $FK_MVC, 'mvt_credit_N'), sumPx($comptes, $FK_MVC, 'mvt_credit_N1')],
        ],
    ],
    'FL' => [
        'formula' => 'sdC(14, N−N1) + sdC(799) − sdC(4494+4581+4582)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['sdC 14 subventions (fin N)', scPx($comptes, ['14'], 'N'), scPx($comptes, ['14'], 'N1')],
            ['− sdC 14 (fin N-1)', -scPx($comptes, ['14'], 'N1'), null],
            ['+ sdC 799 − sdC(4494,4581,4582)', scPx($comptes, ['799'], 'N') - scPx($comptes, ['4494','4581','4582'], 'N'), scPx($comptes, ['799'], 'N1') - scPx($comptes, ['4494','4581','4582'], 'N1')],
        ],
    ],
    'FM' => [
        'formula' => 'mvt_D(4619+103+104)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['mvt débit 4619 (capital à rembourser)', sumPx($comptes, ['4619'], 'mvt_debit_N'), sumPx($comptes, ['4619'], 'mvt_debit_N1')],
            ['mvt débit 103+104 (primes & écarts)', sumPx($comptes, ['103','104'], 'mvt_debit_N'), sumPx($comptes, ['103','104'], 'mvt_debit_N1')],
        ],
    ],
    'FN' => [
        'formula' => 'mvt_D(465)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['mvt débit 465 (dividendes à payer)', sumPx($comptes, ['465'], 'mvt_debit_N'), sumPx($comptes, ['465'], 'mvt_debit_N1')],
        ],
    ],
    'FO' => [
        'formula' => 'mvt_C(161+162+1661+1662) − mvt_D(4713) − sdD(4784)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['mvt crédit 161+162+1661+1662', sumPx($comptes, ['161','162','1661','1662'], 'mvt_credit_N'), sumPx($comptes, ['161','162','1661','1662'], 'mvt_credit_N1')],
            ['− mvt débit 4713', -sumPx($comptes, ['4713'], 'mvt_debit_N'), -sumPx($comptes, ['4713'], 'mvt_debit_N1')],
            ['− sdD 4784', -sdPx($comptes, ['4784'], 'N'), -sdPx($comptes, ['4784'], 'N1')],
        ],
    ],
    'FP' => [
        'formula' => 'mvt_C(163+164+165+167+168+181→184) − sdD(4784)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['mvt crédit 163,164,165,167,168', sumPx($comptes, ['163','164','165','167','168'], 'mvt_credit_N'), sumPx($comptes, ['163','164','165','167','168'], 'mvt_credit_N1')],
            ['mvt crédit 181,182,183,184', sumPx($comptes, ['181','182','183','184'], 'mvt_credit_N'), sumPx($comptes, ['181','182','183','184'], 'mvt_credit_N1')],
            ['− sdD 4784', -sdPx($comptes, ['4784'], 'N'), -sdPx($comptes, ['4784'], 'N1')],
        ],
    ],
    'FQ' => [
        'formula' => 'mvt_D(16+17+181→184) − sdC(4794)',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['mvt débit 16 (emprunts)', sumPx($comptes, ['16'], 'mvt_debit_N'), sumPx($comptes, ['16'], 'mvt_debit_N1')],
            ['mvt débit 17+181→184', sumPx($comptes, ['17','181','182','183','184'], 'mvt_debit_N'), sumPx($comptes, ['17','181','182','183','184'], 'mvt_debit_N1')],
            ['− sdC 4794', -scPx($comptes, ['4794'], 'N'), -scPx($comptes, ['4794'], 'N1')],
        ],
    ],
    'ZB' => [
        'formula' => 'FA − FB − FC − FD + FE',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ FA CAFG', $FA_N, $FA_N1],
            ['− FB Actif circulant HAO', -$FB_N, -$FB_N1],
            ['− FC Stocks', -$FC_N, -$FC_N1],
            ['− FD Créances', -$FD_N, -$FD_N1],
            ['+ FE Passif circulant', $FE_N, $FE_N1],
        ],
    ],
    'ZC' => [
        'formula' => '−FF − FG − FH + FI + FJ',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['− FF Acq. incorporelles', -$FF_N, -$FF_N1],
            ['− FG Acq. corporelles', -$FG_N, -$FG_N1],
            ['− FH Acq. financières', -$FH_N, -$FH_N1],
            ['+ FI Cess. incorp.+corp.', $FI_N, $FI_N1],
            ['+ FJ Cess. financières', $FJ_N, $FJ_N1],
        ],
    ],
    'ZD' => [
        'formula' => 'FK + FL − FM − FN',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ FK Augment. capital', $FK_N, $FK_N1],
            ['+ FL Subventions', $FL_N, $FL_N1],
            ['− FM Prélèvements', -$FM_N, -$FM_N1],
            ['− FN Dividendes', -$FN_N, -$FN_N1],
        ],
    ],
    'ZE' => [
        'formula' => 'FO + FP − FQ',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ FO Emprunts', $FO_N, $FO_N1],
            ['+ FP Autres dettes fin.', $FP_N, $FP_N1],
            ['− FQ Remboursements', -$FQ_N, -$FQ_N1],
        ],
    ],
    'ZF' => [
        'formula' => 'ZD + ZE',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ ZD Capitaux propres', $ZD_N, $ZD_N1],
            ['+ ZE Capitaux étrangers', $ZE_N, $ZE_N1],
        ],
    ],
    'ZG' => [
        'formula' => 'ZB + ZC + ZF',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ ZB Flux opérationnels', $ZB_N, $ZB_N1],
            ['+ ZC Flux investissement', $ZC_N, $ZC_N1],
            ['+ ZF Flux financement', $ZF_N, $ZF_N1],
        ],
    ],
    'ZH' => [
        'formula' => 'ZA + ZG',
        'cols'    => ['Composante', $annee_n, $annee_n1],
        'items'   => [
            ['+ ZA Trésorerie début période', $ZA_N, $ZA_N1],
            ['+ ZG Variation de la période', $ZG_N, $ZG_N1],
        ],
    ],
];

// ─── KPI & vérifications ─────────────────────────────────────────────
$taux_couverture = ($ZC_N != 0) ? round(abs($ZB_N / $ZC_N) * 100) : null;
$check_zh_ok  = abs($ecart_N) < 1;
$check_zg_ok  = abs(($ZB_N + $ZC_N + $ZF_N) - $ZG_N) < 1;
$check_zf_ok  = abs(($ZD_N + $ZE_N) - $ZF_N) < 1;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Flux de Trésorerie <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        @media print {
            aside, .no-print, form { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-table { font-size: 10px; }
            .detail-panel { display: none !important; }
        }
        .page-title { font-size: 20px; }
        .detail-panel { display: none; }
        .detail-panel.open { display: table-row; }
        .tooltip-ref { position: relative; cursor: help; }
        .tooltip-ref:hover .tooltip-box {
            display: block;
        }
        .tooltip-box {
            display: none;
            position: absolute;
            left: 110%;
            top: 50%;
            transform: translateY(-50%);
            z-index: 50;
            width: 260px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 10px;
            color: #cbd5e1;
            line-height: 1.4;
            pointer-events: none;
            white-space: normal;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto p-8">

        <!-- Titre style Bilan -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <div>
                    <h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-teal-400 mb-1">
                        <i class="fas fa-stream mr-3"></i>Tableau de Flux de Trésorerie — SYSCOHADA Révisé
                    </h1>
                    <p class="text-slate-400 text-sm">
                        Flux de trésorerie de l'exercice du <?= date('d/m/Y', strtotime($date_debut_n)) ?> au <?= date('d/m/Y', strtotime($date_fin_n)) ?>
                        &nbsp;|&nbsp; N-1 : <?= date('d/m/Y', strtotime($date_debut_n1)) ?> au <?= date('d/m/Y', strtotime($date_fin_n1)) ?>
                    </p>
                </div>
                <a href="../rapports/index.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2 text-sm no-print">
                    <i class="fas fa-arrow-left"></i> Retour
                </a>
            </div>
        </div>

        <!-- Filtre -->
        <form method="GET" class="no-print mb-6 bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl p-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2"><i class="fas fa-calendar-alt mr-1"></i>Début</label>
                    <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut_n) ?>"
                           class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 text-slate-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2"><i class="fas fa-calendar-alt mr-1"></i>Fin</label>
                    <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin_n) ?>"
                           class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 text-slate-100">
                </div>
                <button type="submit" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2">
                    <i class="fas fa-search"></i>Afficher
                </button>
                <a href="tft.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                    <i class="fas fa-redo"></i>Réinit.
                </a>
                <div class="h-10 w-px bg-slate-600"></div>
                <button type="button" onclick="exportPDF()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i>PDF
                </button>
                <button type="button" onclick="exportExcel()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2">
                    <i class="fas fa-file-excel"></i>Excel
                </button>
                <button type="button" id="modeBtn" onclick="toggleMode()"
                    class="px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2">
                    <i class="fas fa-layer-group"></i>
                    <span id="modeBtnLabel">Mode détaillé</span>
                </button>
                <div class="h-10 w-px bg-slate-600"></div>
                <button type="button" onclick="exportPDFDetail()" class="px-4 py-2 bg-gradient-to-r from-red-700 to-rose-700 hover:from-red-800 hover:to-rose-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2" title="Export PDF avec détail des calculs">
                    <i class="fas fa-file-pdf"></i>PDF+
                </button>
                <button type="button" onclick="exportExcelDetail()" class="px-4 py-2 bg-gradient-to-r from-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800 text-white rounded-lg transition-all shadow-lg inline-flex items-center gap-2" title="Export Excel avec détail des calculs">
                    <i class="fas fa-file-excel"></i>Excel+
                </button>
            </div>
        </form>

        <!-- ── KPI Cards ── -->
        <div class="no-print grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <?php
            $kpi = [
                ['label' => 'Trésorerie fin N (ZH)', 'val' => $ZH_check_N, 'sub' => 'N-1 : '.nf($ZH_check_N1), 'icon' => 'fa-wallet', 'color' => $ZH_check_N >= 0 ? 'emerald' : 'red'],
                ['label' => 'Variation trésorerie (ZG)', 'val' => $ZG_N, 'sub' => 'N-1 : '.nf($ZG_N1), 'icon' => 'fa-arrows-alt-v', 'color' => $ZG_N >= 0 ? 'emerald' : 'red'],
                ['label' => 'Flux opérationnels (ZB)', 'val' => $ZB_N, 'sub' => 'N-1 : '.nf($ZB_N1), 'icon' => 'fa-cogs', 'color' => $ZB_N >= 0 ? 'emerald' : 'red'],
                ['label' => 'Couverture invest. ZB/ZC', 'val' => $taux_couverture !== null ? $taux_couverture.'%' : 'N/A', 'sub' => $ZC_N < 0 ? 'Invest. : '.nf($ZC_N) : 'Pas d\'invest.', 'icon' => 'fa-chart-pie', 'color' => ($taux_couverture !== null && $taux_couverture >= 100) ? 'emerald' : 'amber'],
            ];
            foreach ($kpi as $k):
                $c = $k['color'];
                $colorMap = ['emerald' => ['bg' => 'bg-emerald-900/30', 'border' => 'border-emerald-700/40', 'text' => 'text-emerald-400', 'icon' => 'text-emerald-500'],
                             'red'     => ['bg' => 'bg-red-900/30',     'border' => 'border-red-700/40',     'text' => 'text-red-400',     'icon' => 'text-red-500'],
                             'amber'   => ['bg' => 'bg-amber-900/30',   'border' => 'border-amber-700/40',   'text' => 'text-amber-400',   'icon' => 'text-amber-500']];
                $cm = $colorMap[$c];
            ?>
            <div class="<?= $cm['bg'] ?> border <?= $cm['border'] ?> rounded-xl p-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs text-slate-400"><?= $k['label'] ?></span>
                    <i class="fas <?= $k['icon'] ?> <?= $cm['icon'] ?> text-sm"></i>
                </div>
                <div class="text-xl font-bold <?= $cm['text'] ?>"><?= is_numeric($k['val']) ? nf($k['val']) : $k['val'] ?></div>
                <div class="text-xs text-slate-500 mt-0.5"><?= $k['sub'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Panneau de vérification ── -->
        <div class="no-print mb-4 bg-slate-800/50 border border-slate-700/50 rounded-xl p-3">
            <div class="flex items-center gap-2 mb-2">
                <i class="fas fa-check-double text-emerald-400 text-xs"></i>
                <span class="text-xs font-semibold text-slate-300 uppercase tracking-wider">Contrôles d'équilibre</span>
            </div>
            <div class="flex flex-wrap gap-4 text-xs">
                <div class="flex items-center gap-2">
                    <?php if ($check_zh_ok): ?>
                        <span class="w-4 h-4 bg-emerald-500/20 border border-emerald-500/50 rounded-full flex items-center justify-center"><i class="fas fa-check text-emerald-400" style="font-size:8px"></i></span>
                        <span class="text-slate-300">ZA + ZG = ZH</span>
                        <span class="text-emerald-400 font-mono"><?= nf($ZH_check_N) ?></span>
                    <?php else: ?>
                        <span class="w-4 h-4 bg-red-500/20 border border-red-500/50 rounded-full flex items-center justify-center"><i class="fas fa-times text-red-400" style="font-size:8px"></i></span>
                        <span class="text-slate-300">ZA + ZG ≠ ZH</span>
                        <span class="text-red-400">Écart : <?= nf($ecart_N) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-4 h-4 <?= $check_zg_ok ? 'bg-emerald-500/20 border-emerald-500/50' : 'bg-red-500/20 border-red-500/50' ?> border rounded-full flex items-center justify-center">
                        <i class="fas <?= $check_zg_ok ? 'fa-check text-emerald-400' : 'fa-times text-red-400' ?>" style="font-size:8px"></i>
                    </span>
                    <span class="text-slate-300">ZG = ZB + ZC + ZF</span>
                    <span class="<?= $check_zg_ok ? 'text-emerald-400' : 'text-red-400' ?> font-mono"><?= nf($ZG_N) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-4 h-4 <?= $check_zf_ok ? 'bg-emerald-500/20 border-emerald-500/50' : 'bg-red-500/20 border-red-500/50' ?> border rounded-full flex items-center justify-center">
                        <i class="fas <?= $check_zf_ok ? 'fa-check text-emerald-400' : 'fa-times text-red-400' ?>" style="font-size:8px"></i>
                    </span>
                    <span class="text-slate-300">ZF = ZD + ZE</span>
                    <span class="<?= $check_zf_ok ? 'text-emerald-400' : 'text-red-400' ?> font-mono"><?= nf($ZF_N) ?></span>
                </div>
                <div class="flex items-center gap-2 ml-auto">
                    <?php if ($FA_N < 0): ?>
                    <span class="px-2 py-0.5 bg-red-900/40 border border-red-700/50 rounded text-red-400">⚠ CAFG négative</span>
                    <?php endif; ?>
                    <?php if ($ZH_check_N < 0): ?>
                    <span class="px-2 py-0.5 bg-red-900/40 border border-red-700/50 rounded text-red-400">⚠ Trésorerie nette négative</span>
                    <?php endif; ?>
                    <?php if ($ZB_N < 0): ?>
                    <span class="px-2 py-0.5 bg-amber-900/40 border border-amber-700/50 rounded text-amber-400">⚠ Flux opérationnels négatifs</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Tableau TFT ── -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden print-table">
            <div class="bg-slate-700/50 px-4 py-3 text-center">
                <h2 class="text-sm font-bold text-white uppercase tracking-wider">Tableau de Flux de Trésorerie</h2>
                <p class="text-xs text-slate-400 mt-0.5">SYSCOHADA Révisé — En vigueur depuis le 01/01/2018</p>
            </div>
            <table class="w-full text-xs border-collapse" id="tftTable">
                <thead>
                    <tr class="bg-slate-700/30 border-b border-slate-600/50">
                        <th class="px-2 py-2 text-center text-slate-400 font-semibold w-10">REF</th>
                        <th class="px-3 py-2 text-left text-slate-400 font-semibold">LIBELLÉS</th>
                        <th class="px-2 py-2 text-center text-slate-400 font-semibold w-10">NOTE</th>
                        <th class="px-3 py-2 text-right text-slate-400 font-semibold w-40">EXERCICE<br><span class="font-bold text-white"><?= $annee_n ?></span></th>
                        <th class="px-3 py-2 text-right text-slate-400 font-semibold w-40">EXERCICE<br><span class="font-bold text-white"><?= $annee_n1 ?></span></th>
                    </tr>
                </thead>
                <tbody>
<?php

function fmtVal($v, $is_total = false, $is_subtotal = false) {
    if ($v === null || abs($v) < 0.5) return '';
    $cls = $v < 0 ? 'text-red-400' : ($is_total || $is_subtotal ? 'text-white' : 'text-slate-200');
    return '<span class="' . $cls . '">' . number_format($v, 0, ',', ' ') . '</span>';
}

function trow($ref, $label, $note, $val_n, $val_n1, $is_total = false, $is_subtotal = false, $indent = false, $tooltips = [], $detail_rows = []) {
    $base_cls = $is_total
        ? 'bg-emerald-900/30 font-bold text-white border-t-2 border-emerald-600/50'
        : ($is_subtotal ? 'bg-slate-700/40 font-semibold text-slate-100 border-t border-slate-600/50' : 'text-slate-300 hover:bg-slate-700/20');
    $indent_cls = $indent ? 'pl-8' : 'pl-3';
    $ref_cls = $is_total ? 'text-emerald-400 font-bold' : ($is_subtotal ? 'text-emerald-300' : 'text-slate-400');

    $has_detail = isset($detail_rows[$ref]);
    $toggle = $has_detail ? "onclick=\"toggleDetail('detail-$ref')\" style=\"cursor:pointer\"" : '';
    $expand_icon = $has_detail ? '<span class="ml-1 text-slate-500 text-[9px] detail-icon" id="icon-'.$ref.'">▶</span>' : '';

    $tooltip_html = '';
    if (isset($tooltips[$ref])) {
        $tip = htmlspecialchars($tooltips[$ref]);
        $tooltip_html = '<span class="tooltip-box">' . $tip . '</span>';
    }

    echo "<tr class=\"$base_cls border-b border-slate-700/30\" $toggle>";
    echo "<td class=\"px-2 py-1.5 text-center $ref_cls font-mono text-[11px]\"><span class=\"tooltip-ref\">$ref$tooltip_html</span></td>";
    echo "<td class=\"$indent_cls py-1.5\">$label$expand_icon</td>";
    echo "<td class=\"px-2 py-1.5 text-center text-slate-500 text-[10px]\">$note</td>";
    echo "<td class=\"px-3 py-1.5 text-right\">" . fmtVal($val_n, $is_total, $is_subtotal) . "</td>";
    echo "<td class=\"px-3 py-1.5 text-right\">" . fmtVal($val_n1, $is_total, $is_subtotal) . "</td>";
    echo "</tr>\n";
}

function detail_row($ref, $detail_rows, $annee_n, $annee_n1) {
    if (!isset($detail_rows[$ref])) return;
    $d = $detail_rows[$ref];
    echo "<tr class=\"detail-panel\" id=\"detail-$ref\">";
    echo "<td colspan=\"5\" class=\"px-4 pb-2 pt-0\">";
    echo "<div class=\"bg-slate-900/60 border border-slate-700/40 rounded-lg p-3 ml-8\">";
    // Formula
    echo "<div class=\"text-[10px] text-slate-400 mb-2\"><span class=\"text-slate-500 mr-1\">Formule :</span><span class=\"font-mono text-emerald-300/80\">{$d['formula']}</span></div>";
    // Table
    echo "<table class=\"w-full text-[11px]\">";
    echo "<thead><tr class=\"border-b border-slate-700/50\">";
    echo "<th class=\"text-left py-0.5 text-slate-500 font-normal\">{$d['cols'][0]}</th>";
    echo "<th class=\"text-right py-0.5 text-slate-500 font-normal pr-2\">{$d['cols'][1]}</th>";
    echo "<th class=\"text-right py-0.5 text-slate-500 font-normal pr-2\">{$d['cols'][2]}</th>";
    echo "</tr></thead><tbody>";
    foreach ($d['items'] as $item) {
        $vN  = $item[1];
        $vN1 = $item[2];
        $cn  = ($vN  !== null && $vN  < -0.5) ? 'text-red-400' : 'text-slate-300';
        $cn1 = ($vN1 !== null && $vN1 < -0.5) ? 'text-red-400' : 'text-slate-500';
        echo "<tr class=\"border-b border-slate-800/60\">";
        echo "<td class=\"py-0.5 text-slate-400\">" . htmlspecialchars($item[0]) . "</td>";
        echo "<td class=\"text-right py-0.5 pr-2 $cn font-mono\">" . ($vN !== null && abs($vN) >= 0.5 ? number_format($vN, 0, ',', ' ') : '') . "</td>";
        echo "<td class=\"text-right py-0.5 pr-2 $cn1 font-mono\">" . ($vN1 !== null && abs($vN1) >= 0.5 ? number_format($vN1, 0, ',', ' ') : '') . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</div></td></tr>\n";
}

function section_head($label) {
    echo "<tr class=\"bg-slate-700/50 border-b border-slate-600/50\">";
    echo "<td colspan=\"5\" class=\"px-3 py-1.5 text-[10px] font-bold text-slate-300 uppercase tracking-wider\">$label</td>";
    echo "</tr>\n";
}

function bf_row($label, $val_n, $val_n1) {
    echo "<tr class=\"bg-slate-800/30 border-b border-slate-700/40\">";
    echo "<td class=\"px-2 py-1 text-center text-slate-600 text-[10px]\"></td>";
    echo "<td class=\"pl-3 py-1 text-[10px] text-slate-400 italic\">$label</td>";
    echo "<td></td>";
    echo "<td class=\"px-3 py-1 text-right text-[10px] text-slate-400\">" . fmtVal($val_n) . "</td>";
    echo "<td class=\"px-3 py-1 text-right text-[10px] text-slate-400\">" . fmtVal($val_n1) . "</td>";
    echo "</tr>\n";
}

?>
                <!-- ── ZA ── -->
                <?php trow('ZA', 'Trésorerie nette au 1er janvier<br><span class="text-[10px] text-slate-500 font-normal">(Trésorerie actif N-1 − Trésorerie passif N-1)</span>', 'A', $ZA_N, $ZA_N1, false, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZA', $detail_rows, $annee_n, $annee_n1); ?>

                <!-- ── Section I : Opérationnelles ── -->
                <?php section_head('Flux de trésorerie provenant des activités opérationnelles'); ?>
                <?php trow('FA', 'Capacité d\'Autofinancement Globale (CAFG)', '', $FA_N, $FA_N1, false, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('FA', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FB', '- Variation d\'actif circulant HAO <sup class="text-slate-500">(1)</sup>', '', -$FB_N, -$FB_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FB', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FC', '- Variation des stocks', '', -$FC_N, -$FC_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FC', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FD', '- Variation des créances', '', -$FD_N, -$FD_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FD', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FE', '+ Variation du passif circulant <sup class="text-slate-500">(1)</sup>', '', $FE_N, $FE_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FE', $detail_rows, $annee_n, $annee_n1); ?>
                <?php bf_row('Variation du BF lié aux activités opérationnelles &nbsp; (FB+FC+FD+FE) :', $BF_N, $BF_N1); ?>
                <?php trow('ZB', 'Flux de trésorerie provenant des activités opérationnelles (Somme FA à FE)', 'B', $ZB_N, $ZB_N1, true, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZB', $detail_rows, $annee_n, $annee_n1); ?>

                <!-- ── Section II : Investissement ── -->
                <?php section_head('Flux de trésorerie provenant des activités d\'investissements'); ?>
                <?php trow('FF', '- Décaissements liés aux acquisitions d\'immobilisations incorporelles', '', -$FF_N, -$FF_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FF', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FG', '- Décaissements liés aux acquisitions d\'immobilisations corporelles', '', -$FG_N, -$FG_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FG', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FH', '- Décaissements liés aux acquisitions d\'immobilisations financières', '', -$FH_N, -$FH_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FH', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FI', '+ Encaissements liés aux cessions d\'immobilisations incorporelles et corporelles', '', $FI_N, $FI_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FI', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FJ', '+ Encaissements liés aux cessions d\'immobilisations financières', '', $FJ_N, $FJ_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FJ', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('ZC', 'Flux de trésorerie provenant des activités d\'investissement (somme FF à FJ)', 'C', $ZC_N, $ZC_N1, true, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZC', $detail_rows, $annee_n, $annee_n1); ?>

                <!-- ── Section III : Financement capitaux propres ── -->
                <?php section_head('Flux de trésorerie provenant du financement par les capitaux propres'); ?>
                <?php trow('FK', '+ Augmentations de capital par apports nouveaux', '', $FK_N, $FK_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FK', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FL', '+ Subventions d\'investissement reçues', '', $FL_N, $FL_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FL', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FM', '- Prélèvements sur le capital', '', -$FM_N, -$FM_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FM', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FN', '- Dividendes versés', '', -$FN_N, -$FN_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FN', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('ZD', 'Flux de trésorerie provenant des capitaux propres (somme FK à FN)', 'D', $ZD_N, $ZD_N1, false, true, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZD', $detail_rows, $annee_n, $annee_n1); ?>

                <!-- ── Section III : Financement capitaux étrangers ── -->
                <?php section_head('Trésorerie provenant du financement par les capitaux étrangers'); ?>
                <?php trow('FO', '+ Emprunts <sup class="text-slate-500">(2)</sup>', '', $FO_N, $FO_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FO', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FP', '+ Autres dettes financières diverses <sup class="text-slate-500">(3)</sup>', '', $FP_N, $FP_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FP', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('FQ', '- Remboursements des emprunts et autres dettes financières', '', -$FQ_N, -$FQ_N1, false, false, true, $tooltips, $detail_rows); ?>
                <?php detail_row('FQ', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('ZE', 'Flux de trésorerie provenant des capitaux étrangers (somme FO à FQ)', 'E', $ZE_N, $ZE_N1, false, true, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZE', $detail_rows, $annee_n, $annee_n1); ?>

                <?php trow('ZF', 'Flux de trésorerie provenant des activités de financement (D+E)', 'F', $ZF_N, $ZF_N1, true, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZF', $detail_rows, $annee_n, $annee_n1); ?>

                <?php trow('ZG', 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (B+C+F)', 'G', $ZG_N, $ZG_N1, true, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZG', $detail_rows, $annee_n, $annee_n1); ?>
                <?php trow('ZH', 'Trésorerie nette au 31 Décembre (G+A)', 'H', $ZH_check_N, $ZH_check_N1, true, false, false, $tooltips, $detail_rows); ?>
                <?php detail_row('ZH', $detail_rows, $annee_n, $annee_n1); ?>

                <!-- Ligne contrôle bilan -->
                <tr class="border-b border-slate-700/20 <?= !$check_zh_ok ? 'bg-red-900/20' : '' ?>">
                    <td></td>
                    <td class="pl-3 py-1 text-[10px] text-slate-500">
                        Contrôle : Trésorerie actif N − Trésorerie passif N
                        <?php if (!$check_zh_ok): ?>
                            <span class="ml-2 text-red-400">⚠ Écart : <?= nf($ecart_N) ?></span>
                        <?php else: ?>
                            <span class="ml-2 text-emerald-500">✓</span>
                        <?php endif; ?>
                    </td>
                    <td></td>
                    <td class="px-3 py-1 text-right text-[10px] <?= !$check_zh_ok ? 'text-red-400' : 'text-emerald-500' ?>"><?= nf($ZH_N) ?></td>
                    <td class="px-3 py-1 text-right text-[10px] text-slate-500"><?= nf($ZA_N) ?></td>
                </tr>
                </tbody>
            </table>

            <!-- Notes de bas de page -->
            <div class="px-4 py-3 border-t border-slate-700/50 bg-slate-800/30">
                <p class="text-[10px] text-slate-500 leading-relaxed">
                    <span class="text-slate-400">(1)</span> À l'exclusion des variations des créances et dettes liées aux activités d'investissement et de financement.<br>
                    <span class="text-slate-400">(2)</span> Comptes 161, 162, 1661, 1662 &nbsp;
                    <span class="text-slate-400">(3)</span> Comptes 16 sauf (161, 162, 1661, 1662) et comptes 18
                </p>
            </div>
        </div>

        <!-- ── Graphique en cascade ── -->
        <div class="no-print mt-4 bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
            <h3 class="text-xs font-semibold text-slate-300 uppercase tracking-wider mb-3">
                <i class="fas fa-chart-bar mr-1 text-emerald-400"></i>Cascade de trésorerie — Exercice <?= $annee_n ?>
            </h3>
            <div style="height:240px">
                <canvas id="waterfallChart"></canvas>
            </div>
        </div>

        <!-- Note méthodologique -->
        <div class="mt-3 p-3 bg-slate-800/30 border border-slate-700/30 rounded-lg no-print">
            <p class="text-[10px] text-slate-500">
                <strong class="text-slate-400">Note :</strong> Tableau généré par méthode indirecte (additive) conformément au SYSCOHADA révisé (01/01/2018).
                Cliquez sur une ligne pour afficher le détail du calcul. Survolez les codes REF pour une description pédagogique.
                Rubriques selon le Plan Comptable OHADA et le livre « Tout sur le TFT dans l'espace OHADA » — DA CHARLY.
            </p>
        </div>

    </main>
</div><!-- flex -->

<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });

// ─── Mode condensé / détaillé ─────────────────────────────────────
let detailMode = false;
function toggleMode() {
    detailMode = !detailMode;
    document.getElementById('modeBtnLabel').textContent = detailMode ? 'Mode condensé' : 'Mode détaillé';
    document.getElementById('modeBtn').classList.toggle('bg-emerald-700/70', detailMode);
    const panels = document.querySelectorAll('.detail-panel');
    panels.forEach(p => {
        p.classList.toggle('open', detailMode);
    });
    const icons = document.querySelectorAll('.detail-icon');
    icons.forEach(i => { i.textContent = detailMode ? '▼' : '▶'; });
}

// ─── Toggle détail individuel ─────────────────────────────────────
function toggleDetail(id) {
    const panel = document.getElementById(id);
    const ref   = id.replace('detail-', '');
    const icon  = document.getElementById('icon-' + ref);
    if (!panel) return;
    const isOpen = panel.classList.toggle('open');
    if (icon) icon.textContent = isOpen ? '▼' : '▶';
}

// ─── Exports ──────────────────────────────────────────────────────
function exportPDF() {
    const params = new URLSearchParams({ date_debut: '<?= $date_debut_n ?>', date_fin: '<?= $date_fin_n ?>' });
    window.location.href = 'export_tft_pdf.php?' + params.toString();
}
function exportExcel() {
    const params = new URLSearchParams({ date_debut: '<?= $date_debut_n ?>', date_fin: '<?= $date_fin_n ?>' });
    window.location.href = 'export_tft_excel.php?' + params.toString();
}
function exportPDFDetail() {
    const params = new URLSearchParams({ date_debut: '<?= $date_debut_n ?>', date_fin: '<?= $date_fin_n ?>' });
    window.location.href = 'export_tft_pdf_detail.php?' + params.toString();
}
function exportExcelDetail() {
    const params = new URLSearchParams({ date_debut: '<?= $date_debut_n ?>', date_fin: '<?= $date_fin_n ?>' });
    window.location.href = 'export_tft_excel_detail.php?' + params.toString();
}

// ─── Graphique en cascade ─────────────────────────────────────────
(function() {
    const ZA = <?= round($ZA_N) ?>;
    const ZB = <?= round($ZB_N) ?>;
    const ZC = <?= round($ZC_N) ?>;
    const ZF = <?= round($ZF_N) ?>;
    const ZH = <?= round($ZH_check_N) ?>;

    const s1 = ZA;
    const s2 = ZA + ZB;
    const s3 = ZA + ZB + ZC;
    const s4 = ZA + ZB + ZC + ZF;

    function barColor(start, end) {
        return end >= start ? 'rgba(16,185,129,0.75)' : 'rgba(239,68,68,0.75)';
    }

    const ctx = document.getElementById('waterfallChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['ZA\nDébut', 'ZB\nOpérat.', 'ZC\nInvest.', 'ZF\nFinanc.', 'ZH\nFin'],
            datasets: [{
                label: '<?= $annee_n ?>',
                data: [
                    [0, s1],
                    [s1, s2],
                    [s2, s3],
                    [s3, s4],
                    [0, ZH],
                ],
                backgroundColor: [
                    'rgba(59,130,246,0.75)',
                    barColor(s1, s2),
                    barColor(s2, s3),
                    barColor(s3, s4),
                    ZH >= 0 ? 'rgba(59,130,246,0.75)' : 'rgba(239,68,68,0.75)',
                ],
                borderColor: [
                    'rgba(59,130,246,1)',
                    s2 >= s1 ? 'rgba(16,185,129,1)' : 'rgba(239,68,68,1)',
                    s3 >= s2 ? 'rgba(16,185,129,1)' : 'rgba(239,68,68,1)',
                    s4 >= s3 ? 'rgba(16,185,129,1)' : 'rgba(239,68,68,1)',
                    ZH >= 0 ? 'rgba(59,130,246,1)' : 'rgba(239,68,68,1)',
                ],
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            const [a, b] = ctx.raw;
                            const v = b - a;
                            return ' ' + (v >= 0 ? '+' : '') + new Intl.NumberFormat('fr-FR').format(Math.round(v));
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#94a3b8', font: { size: 10 } },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                },
                y: {
                    ticks: {
                        color: '#94a3b8', font: { size: 10 },
                        callback: v => new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(v)
                    },
                    grid: { color: 'rgba(255,255,255,0.07)' }
                }
            }
        }
    });
})();
</script>
</body>
</html>
