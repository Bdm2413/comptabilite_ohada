<?php
/**
 * tft_calculs.php — Fonctions et calculs partagés du Tableau de Flux de Trésorerie
 * Inclure APRÈS avoir défini $comptes, $date_debut_n, $date_fin_n, $date_debut_n1, $date_fin_n1
 */

// =====================================================================
// FONCTIONS HELPERS
// =====================================================================

function matchPx(string $s, array $prefixes): bool {
    foreach ($prefixes as $p) { if (str_starts_with($s, $p)) return true; }
    return false;
}
function sumPx(array $c, array $pfx, string $f): float {
    $t = 0.0;
    foreach ($c as $r) if (matchPx((string)$r['compte'], $pfx)) $t += (float)$r[$f];
    return $t;
}
function sumRd(array $c, array $rd, array $rc, string $f): float {
    $t = 0.0;
    foreach ($c as $r)
        if ((!empty($rd) && in_array($r['rd'], $rd)) || (!empty($rc) && in_array($r['rc'], $rc)))
            $t += (float)$r[$f];
    return $t;
}
function sdBd(array $c, array $bd, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r)
        if (in_array($r['bd'], $bd)) { $s = (float)$r["cum_debit_$p"] - (float)$r["cum_credit_$p"]; if ($s > 0) $t += $s; }
    return $t;
}
function scBc(array $c, array $bc, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r)
        if (in_array($r['bc'], $bc)) { $s = (float)$r["cum_credit_$p"] - (float)$r["cum_debit_$p"]; if ($s > 0) $t += $s; }
    return $t;
}
function sdPx(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r)
        if (matchPx((string)$r['compte'], $pfx)) { $s = (float)$r["cum_debit_$p"] - (float)$r["cum_credit_$p"]; if ($s > 0) $t += $s; }
    return $t;
}
function scPx(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r)
        if (matchPx((string)$r['compte'], $pfx)) { $s = (float)$r["cum_credit_$p"] - (float)$r["cum_debit_$p"]; if ($s > 0) $t += $s; }
    return $t;
}
function sdBd_N2(array $c, array $bd): float {
    $t = 0.0;
    foreach ($c as $r)
        if (in_array($r['bd'], $bd)) {
            $d = (float)$r['cum_debit_N1']  - (float)$r['mvt_debit_N1'];
            $cr= (float)$r['cum_credit_N1'] - (float)$r['mvt_credit_N1'];
            $s = $d - $cr; if ($s > 0) $t += $s;
        }
    return $t;
}
function scBc_N2(array $c, array $bc): float {
    $t = 0.0;
    foreach ($c as $r)
        if (in_array($r['bc'], $bc)) {
            $d = (float)$r['cum_debit_N1']  - (float)$r['mvt_debit_N1'];
            $cr= (float)$r['cum_credit_N1'] - (float)$r['mvt_credit_N1'];
            $s = $cr - $d; if ($s > 0) $t += $s;
        }
    return $t;
}
function sdPx_N2(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r)
        if (matchPx((string)$r['compte'], $pfx)) {
            $d = (float)$r['cum_debit_N1']  - (float)$r['mvt_debit_N1'];
            $cr= (float)$r['cum_credit_N1'] - (float)$r['mvt_credit_N1'];
            $s = $d - $cr; if ($s > 0) $t += $s;
        }
    return $t;
}
function scPx_N2(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r)
        if (matchPx((string)$r['compte'], $pfx)) {
            $d = (float)$r['cum_debit_N1']  - (float)$r['mvt_debit_N1'];
            $cr= (float)$r['cum_credit_N1'] - (float)$r['mvt_credit_N1'];
            $s = $cr - $d; if ($s > 0) $t += $s;
        }
    return $t;
}
/**
 * Valeur nette d'actif par rubrique BD (identique à classifierComptes du bilan) :
 * - Exclut les comptes 416, 426, 491, 496 (reclassements, comme le bilan)
 * - Soustrait les amortissements/dépréciations (préfixes 28/29/39/49/59)
 * - Somme les soldes débiteurs des autres comptes
 */
function netBd(array $c, array $bds, string $p = 'N'): float {
    $brut = 0.0; $depr = 0.0;
    foreach ($c as $r) {
        if (!in_array($r['bd'], $bds)) continue;
        $px2 = substr((string)$r['compte'], 0, 2);
        $px3 = substr((string)$r['compte'], 0, 3);
        if ($px3 === '416' || $px3 === '426' || $px3 === '491' || $px3 === '496') continue;
        $solde = (float)$r["cum_debit_$p"] - (float)$r["cum_credit_$p"];
        if (in_array($px2, ['28','29','39','49','59'])) {
            $depr += abs($solde);
        } elseif ($solde > 0) {
            $brut += $solde;
        }
    }
    return $brut - $depr;
}
/** Idem pour l'ouverture de N-1 (soldes avant les mouvements de N-1) */
function netBd_N2(array $c, array $bds): float {
    $brut = 0.0; $depr = 0.0;
    foreach ($c as $r) {
        if (!in_array($r['bd'], $bds)) continue;
        $px2 = substr((string)$r['compte'], 0, 2);
        $px3 = substr((string)$r['compte'], 0, 3);
        if ($px3 === '416' || $px3 === '426' || $px3 === '491' || $px3 === '496') continue;
        $d  = (float)$r['cum_debit_N1']  - (float)$r['mvt_debit_N1'];
        $cr = (float)$r['cum_credit_N1'] - (float)$r['mvt_credit_N1'];
        $solde = $d - $cr;
        if (in_array($px2, ['28','29','39','49','59'])) {
            $depr += abs($solde);
        } elseif ($solde > 0) {
            $brut += $solde;
        }
    }
    return $brut - $depr;
}

/** Solde net signé par préfixe (débit − crédit, peut être négatif) */
function netPx(array $c, array $pfx, string $p = 'N'): float {
    $t = 0.0;
    foreach ($c as $r)
        if (matchPx((string)$r['compte'], $pfx))
            $t += (float)$r["cum_debit_$p"] - (float)$r["cum_credit_$p"];
    return $t;
}
function netPx_N2(array $c, array $pfx): float {
    $t = 0.0;
    foreach ($c as $r)
        if (matchPx((string)$r['compte'], $pfx)) {
            $d  = (float)$r['cum_debit_N1']  - (float)$r['mvt_debit_N1'];
            $cr = (float)$r['cum_credit_N1'] - (float)$r['mvt_credit_N1'];
            $t += $d - $cr;
        }
    return $t;
}

// =====================================================================
// CALCUL DES POSTES TFT
// =====================================================================

// ─── ZA & ZH : Trésorerie nette ──────────────────────────────────────
// Utilise les rubriques bilan BQ/BR/BS (actif) et DQ/DR (passif)
// pour correspondre exactement aux valeurs du bilan SYSCOHADA.
$BC_TACT  = ['BQ','BR','BS'];
$BC_TPASS = ['DQ','DR'];

$tresoAct_N   = sdBd($comptes, $BC_TACT, 'N');
$tresoPass_N  = scBc($comptes, $BC_TPASS, 'N');
$ZH_N         = $tresoAct_N - $tresoPass_N;

$tresoAct_N1  = sdBd($comptes, $BC_TACT, 'N1');
$tresoPass_N1 = scBc($comptes, $BC_TPASS, 'N1');
$ZA_N         = $tresoAct_N1 - $tresoPass_N1;

$tresoAct_N2  = sdBd_N2($comptes, $BC_TACT);
$tresoPass_N2 = scBc_N2($comptes, $BC_TPASS);
$ZA_N1        = $tresoAct_N2 - $tresoPass_N2;

// ─── FA : CAFG ───────────────────────────────────────────────────────
// XD (EBE) + D(654) - C(754) + XF + TO - RP - RQ - RS
// XD et XF sont des SIG calculés, pas des rubriques directes du plan comptable.
// Même logique que compte_resultat.php : agrégation par rubrique rd/rc.

$prodN = []; $chrgN = [];
$prodN1 = []; $chrgN1 = [];
foreach ($comptes as $r) {
    if ($r['tableau'] !== 'Résultat') continue;
    $sN  = (float)$r['mvt_credit_N']  - (float)$r['mvt_debit_N'];
    $sN1 = (float)$r['mvt_credit_N1'] - (float)$r['mvt_debit_N1'];
    if ($sN  > 0 && !empty($r['rc'])) $prodN[$r['rc']]  = ($prodN[$r['rc']]  ?? 0) + $sN;
    if ($sN  < 0 && !empty($r['rd'])) $chrgN[$r['rd']]  = ($chrgN[$r['rd']]  ?? 0) + abs($sN);
    if ($sN1 > 0 && !empty($r['rc'])) $prodN1[$r['rc']] = ($prodN1[$r['rc']] ?? 0) + $sN1;
    if ($sN1 < 0 && !empty($r['rd'])) $chrgN1[$r['rd']] = ($chrgN1[$r['rd']] ?? 0) + abs($sN1);
}

// XC (Valeur Ajoutée) puis XD (EBE = XC - RK)
$XC_N = -($chrgN['RA'] ?? 0) - ($chrgN['RB'] ?? 0)
      + ($prodN['TA'] ?? 0) + ($prodN['TB'] ?? 0) + ($prodN['TC'] ?? 0) + ($prodN['TD'] ?? 0)
      + ($prodN['TE'] ?? 0) + ($prodN['TF'] ?? 0) + ($prodN['TG'] ?? 0) + ($prodN['TH'] ?? 0) + ($prodN['TI'] ?? 0)
      - ($chrgN['RC'] ?? 0) - ($chrgN['RD'] ?? 0) - ($chrgN['RE'] ?? 0) - ($chrgN['RF'] ?? 0)
      - ($chrgN['RG'] ?? 0) - ($chrgN['RH'] ?? 0) - ($chrgN['RI'] ?? 0) - ($chrgN['RJ'] ?? 0);
$XD_N = $XC_N - ($chrgN['RK'] ?? 0);

$XC_N1 = -($chrgN1['RA'] ?? 0) - ($chrgN1['RB'] ?? 0)
       + ($prodN1['TA'] ?? 0) + ($prodN1['TB'] ?? 0) + ($prodN1['TC'] ?? 0) + ($prodN1['TD'] ?? 0)
       + ($prodN1['TE'] ?? 0) + ($prodN1['TF'] ?? 0) + ($prodN1['TG'] ?? 0) + ($prodN1['TH'] ?? 0) + ($prodN1['TI'] ?? 0)
       - ($chrgN1['RC'] ?? 0) - ($chrgN1['RD'] ?? 0) - ($chrgN1['RE'] ?? 0) - ($chrgN1['RF'] ?? 0)
       - ($chrgN1['RG'] ?? 0) - ($chrgN1['RH'] ?? 0) - ($chrgN1['RI'] ?? 0) - ($chrgN1['RJ'] ?? 0);
$XD_N1 = $XC_N1 - ($chrgN1['RK'] ?? 0);

// XF = Résultat financier (TK + TL + TM - RM - RN)
$XF_N  = ($prodN['TK']  ?? 0) + ($prodN['TL']  ?? 0) + ($prodN['TM']  ?? 0)
       - ($chrgN['RM']  ?? 0) - ($chrgN['RN']  ?? 0);
$XF_N1 = ($prodN1['TK'] ?? 0) + ($prodN1['TL'] ?? 0) + ($prodN1['TM'] ?? 0)
       - ($chrgN1['RM'] ?? 0) - ($chrgN1['RN'] ?? 0);

// TO (produits HAO), RP (charges HAO), RQ (IS), RS (autres charges)
$TO_N  = $prodN['TO']  ?? 0;
$TO_N1 = $prodN1['TO'] ?? 0;
$RP_N  = $chrgN['RP']  ?? 0;
$RP_N1 = $chrgN1['RP'] ?? 0;
$RQ_N  = $chrgN['RQ']  ?? 0;
$RQ_N1 = $chrgN1['RQ'] ?? 0;
$RS_N  = $chrgN['RS']  ?? 0;
$RS_N1 = $chrgN1['RS'] ?? 0;

// VNC (654) et produits de cession (754) — ajustements retraitement
$vnc_N       = sdPx($comptes, ['654'], 'N');
$vnc_N1      = sdPx($comptes, ['654'], 'N1');
$prodCess_N  = scPx($comptes, ['754'], 'N');
$prodCess_N1 = scPx($comptes, ['754'], 'N1');

$FA_N  = $XD_N  + $vnc_N  - $prodCess_N  + $XF_N  + $TO_N  - $RP_N  - $RQ_N  - $RS_N;
$FA_N1 = $XD_N1 + $vnc_N1 - $prodCess_N1 + $XF_N1 + $TO_N1 - $RP_N1 - $RQ_N1 - $RS_N1;

$XI_N = $XI_N1 = 0.0;
foreach ($comptes as $r) {
    if ($r['tableau'] === 'Résultat') {
        $XI_N  += (float)$r['mvt_credit_N']  - (float)$r['mvt_debit_N'];
        $XI_N1 += (float)$r['mvt_credit_N1'] - (float)$r['mvt_debit_N1'];
    }
}

// ─── FB : Variation actif circulant HAO ──────────────────────────────
// Formule : BA(N) − BA(N-1)
//         − Solde 485(N) + Solde 485(N-1)
//         + Solde débiteur 4781(N) − Solde créditeur 4791(N)
//
// BA     = Actif circulant HAO (rubrique bilan bd='BA')
// 485    = Créances HAO à exclure du BF (déjà en investissement)
// 4781   = Dettes sur acquisition HAO (solde débiteur)
// 4791   = Créances sur cession HAO (solde créditeur)

$BA_N    = netBd($comptes, ['BA'], 'N');
$BA_N1   = netBd($comptes, ['BA'], 'N1');
$BA_N2   = netBd_N2($comptes, ['BA']);

$s485_N  = sdPx($comptes, ['485'], 'N');
$s485_N1 = sdPx($comptes, ['485'], 'N1');
$s485_N2 = sdPx_N2($comptes, ['485']);

$d4781_N  = sdPx($comptes, ['4781'], 'N');
$c4791_N  = scPx($comptes, ['4791'], 'N');
$d4781_N1 = sdPx($comptes, ['4781'], 'N1');
$c4791_N1 = scPx($comptes, ['4791'], 'N1');
$d4781_N2 = sdPx_N2($comptes, ['4781']);
$c4791_N2 = scPx_N2($comptes, ['4791']);

$FB_N  = $BA_N  - $BA_N1  - $s485_N  + $s485_N1  + $d4781_N  - $c4791_N;
$FB_N1 = $BA_N1 - $BA_N2  - $s485_N1 + $s485_N2  + $d4781_N1 - $c4791_N1;

// ─── FC : Variation stocks ────────────────────────────────────────────
// Formule : BB(N) − BB(N-1)
// BB = Stocks et encours (rubrique bilan bd='BB')
$BB_N  = netBd($comptes, ['BB'], 'N');
$BB_N1 = netBd($comptes, ['BB'], 'N1');
$BB_N2 = netBd_N2($comptes, ['BB']);
$FC_N  = $BB_N  - $BB_N1;
$FC_N1 = $BB_N1 - $BB_N2;

// ─── FD : Variation créances ──────────────────────────────────────────
// Formule :
//   (BH + BI + BJ)(N) − (BH + BI + BJ)(N-1)
//   + (4781 + 4782 + 4791 + 4792)(N) − (4781 + 4782 + 4791 + 4792)(N-1)
//   − D(414 + 4494 + 458 + 461 + 467 + 4751)(N)
//   + D(414 + 4494 + 458 + 461 + 467 + 4751)(N-1)
//   + Mvt débit 2714(N)
//
// BH = Fournisseurs avances versées, BI = Clients, BJ = Autres créances (rubriques bilan)
// 4781,4782 = dettes sur cessions HAO; 4791,4792 = créances sur cessions (soldes nets signés)
// 414,4494,458,461,467,4751 = créances liées aux activités d'investissement/financement à exclure
// 2714 = créances sur cessions d'immob. financières (mouvements débiteurs)

$PFX_CRED_EXCL = ['414','4494','458','461','467','4751']; // créances à exclure du BF

// Rubriques bilan BH + BI + BJ (valeurs nettes, en excluant 416/426/491/496 comme le bilan)
$BH_BI_BJ_N  = netBd($comptes, ['BH','BI','BJ'], 'N');
$BH_BI_BJ_N1 = netBd($comptes, ['BH','BI','BJ'], 'N1');
$BH_BI_BJ_N2 = netBd_N2($comptes, ['BH','BI','BJ']);

// Comptes 4781, 4782, 4791, 4792 (soldes nets signés)
$c47xx_N  = netPx($comptes, ['4781','4782','4791','4792'], 'N');
$c47xx_N1 = netPx($comptes, ['4781','4782','4791','4792'], 'N1');
$c47xx_N2 = netPx_N2($comptes, ['4781','4782','4791','4792']);

// Créances à exclure (liées investissement/financement) — soldes débiteurs
$excl_N  = sdPx($comptes, $PFX_CRED_EXCL, 'N');
$excl_N1 = sdPx($comptes, $PFX_CRED_EXCL, 'N1');
$excl_N2 = sdPx_N2($comptes, $PFX_CRED_EXCL);

// Compte 2714 — total mouvements débiteurs (créances sur cessions immos financières)
$mvt2714_N  = sumPx($comptes, ['2714'], 'mvt_debit_N');
$mvt2714_N1 = sumPx($comptes, ['2714'], 'mvt_debit_N1');

$FD_N  = ($BH_BI_BJ_N  - $BH_BI_BJ_N1) + ($c47xx_N  - $c47xx_N1) - $excl_N  + $excl_N1 + $mvt2714_N;
$FD_N1 = ($BH_BI_BJ_N1 - $BH_BI_BJ_N2) + ($c47xx_N1 - $c47xx_N2) - $excl_N1 + $excl_N2 + $mvt2714_N1;

// ─── FE : Variation passif circulant ─────────────────────────────────
// Formule :
//   DP(N) − DP(N-1)
//   − C(404 + 461 + 465 + 4726 + 481 + 482)(N)      ← dettes invest./financement à exclure
//   + C(404 + 461 + 465 + 4726 + 481 + 482)(N-1)
//   + C(4793)(N)   − D(4783)(N)
//   + MvtD(4752)(N) + MvtC(4752)(N)
//
// DP = Total passif circulant (rubriques bilan DH+DI+DJ+DK+DM+DN+DQ+DR+DV)
// 404,461,465,4726,481,482 = dettes liées investissement/financement (à exclure du BF opérationnel)
// 4793 = dettes sur cessions immos (créditeur)
// 4783 = créances sur acquisitions immos (débiteur)
// 4752 = subventions d'investissement (les deux sens du mouvement)

$dP_bc = ['DH','DI','DJ','DK','DM','DN','DQ','DR','DV'];
$DP_N  = scBc($comptes, $dP_bc, 'N');
$DP_N1 = scBc($comptes, $dP_bc, 'N1');
$DP_N2 = scBc_N2($comptes, $dP_bc);

// Dettes à exclure (liées investissement/financement) — soldes créditeurs
$PFX_PASS_EXCL = ['404','461','465','4726','481','482'];
$pexcl_N  = scPx($comptes, $PFX_PASS_EXCL, 'N');
$pexcl_N1 = scPx($comptes, $PFX_PASS_EXCL, 'N1');
$pexcl_N2 = scPx_N2($comptes, $PFX_PASS_EXCL);

// 4793 (créditeur) et 4783 (débiteur) — ajustements cessions/acquisitions
$c4793_N  = scPx($comptes, ['4793'], 'N');
$d4783_N  = sdPx($comptes, ['4783'], 'N');
$c4793_N1 = scPx($comptes, ['4793'], 'N1');
$d4783_N1 = sdPx($comptes, ['4783'], 'N1');
$c4793_N2 = scPx_N2($comptes, ['4793']);
$d4783_N2 = sdPx_N2($comptes, ['4783']);

// 4752 — total mouvements débiteurs + créditeurs (subventions)
$mvt4752_N  = sumPx($comptes, ['4752'], 'mvt_debit_N')  + sumPx($comptes, ['4752'], 'mvt_credit_N');
$mvt4752_N1 = sumPx($comptes, ['4752'], 'mvt_debit_N1') + sumPx($comptes, ['4752'], 'mvt_credit_N1');

$FE_N  = ($DP_N  - $DP_N1)  - $pexcl_N  + $pexcl_N1  + $c4793_N  - $d4783_N  + $mvt4752_N;
$FE_N1 = ($DP_N1 - $DP_N2)  - $pexcl_N1 + $pexcl_N2  + $c4793_N1 - $d4783_N1 + $mvt4752_N1;

// ─── ZB ──────────────────────────────────────────────────────────────
$ZB_N   = $FA_N  - $FB_N  - $FC_N  - $FD_N  + $FE_N;
$ZB_N1  = $FA_N1 - $FB_N1 - $FC_N1 - $FD_N1 + $FE_N1;

// ─── FF : Décaissements liés aux acquisitions d'immos incorporelles ──
// Formule :
//   VNC AD(N) − VNC AD(N-1)
//   + mvt_debit(251) − mvt_credit(251)
//   + mvt_debit(4041+4046+4811+4816+4817+4818+4821+6541+811)
//   − mvt_credit(4041+4046+4811+4816+4817+4818+4821+1984+1061+1062+1541)
//   + solde_débiteur_clôture(6541) + solde_débiteur_clôture(811)
//
// VNC AD = sdBd(AD) − scBc(AD)   (incorporelles nettes : brut − amortissements)
// 251    = avances/acomptes sur immos incorporelles
// 4041,4046,4811,4816-4818,4821 = dettes/créances liées aux acquisitions d'incorporelles
// 6541,811 = VNC des incorporelles cédées (charge)
// 1984,1061,1062,1541 = reprises/subventions liées aux incorporelles

$vncAD_N  = sdBd($comptes, ['AD'], 'N')  - scBc($comptes, ['AD'], 'N');
$vncAD_N1 = sdBd($comptes, ['AD'], 'N1') - scBc($comptes, ['AD'], 'N1');
$vncAD_N2 = sdBd_N2($comptes, ['AD'])    - scBc_N2($comptes, ['AD']);

$FF_PFX_D  = ['4041','4046','4811','4816','4817','4818','4821','6541','811'];
$FF_PFX_C  = ['4041','4046','4811','4816','4817','4818','4821','1984','1061','1062','1541'];

$FF_N  = ($vncAD_N  - $vncAD_N1)
       + sumPx($comptes, ['251'], 'mvt_debit_N')  - sumPx($comptes, ['251'], 'mvt_credit_N')
       + sumPx($comptes, $FF_PFX_D, 'mvt_debit_N')  - sumPx($comptes, $FF_PFX_C, 'mvt_credit_N')
       + sdPx($comptes, ['6541'], 'N') + sdPx($comptes, ['811'], 'N');

$FF_N1 = ($vncAD_N1 - $vncAD_N2)
       + sumPx($comptes, ['251'], 'mvt_debit_N1') - sumPx($comptes, ['251'], 'mvt_credit_N1')
       + sumPx($comptes, $FF_PFX_D, 'mvt_debit_N1') - sumPx($comptes, $FF_PFX_C, 'mvt_credit_N1')
       + sdPx($comptes, ['6541'], 'N1') + sdPx($comptes, ['811'], 'N1');

// ─── FG : Décaissements liés aux acquisitions d'immos corporelles ────
// Formule :
//   VNC AI(N) − VNC AI(N-1)
//   + mvt_debit(252) − mvt_credit(252)
//   + mvt_debit(4042+4047+4812+4816+4817+4818+4822+6542+282+283+284)
//   − mvt_credit(17+4042+4047+4812+4816+4817+4818+4822+1984+1061+1062+1542)
//   + solde_débiteur_clôture(6542) + solde_débiteur_clôture(812)
//
// VNC AI = sdBd(AI) − scBc(AI)   (corporelles nettes : brut − amortissements)
// 252         = avances/acomptes sur immos corporelles
// 4042,4047,4812,4816-4818,4822 = dettes/créances liées aux acquisitions de corporelles
// 6542,282-284 = VNC des corporelles cédées / amortissements soldés à la cession
// 17          = dettes sur crédit-bail (créditeur à exclure)
// 1984,1061,1062,1542 = reprises/subventions liées aux corporelles

$vncAI_N  = sdBd($comptes, ['AI'], 'N')  - scBc($comptes, ['AI'], 'N');
$vncAI_N1 = sdBd($comptes, ['AI'], 'N1') - scBc($comptes, ['AI'], 'N1');
$vncAI_N2 = sdBd_N2($comptes, ['AI'])    - scBc_N2($comptes, ['AI']);

$FG_PFX_D = ['4042','4047','4812','4816','4817','4818','4822','6542','282','283','284'];
$FG_PFX_C = ['17','4042','4047','4812','4816','4817','4818','4822','1984','1061','1062','1542'];

$FG_N  = ($vncAI_N  - $vncAI_N1)
       + sumPx($comptes, ['252'], 'mvt_debit_N')  - sumPx($comptes, ['252'], 'mvt_credit_N')
       + sumPx($comptes, $FG_PFX_D, 'mvt_debit_N')  - sumPx($comptes, $FG_PFX_C, 'mvt_credit_N')
       + sdPx($comptes, ['6542'], 'N') + sdPx($comptes, ['812'], 'N');

$FG_N1 = ($vncAI_N1 - $vncAI_N2)
       + sumPx($comptes, ['252'], 'mvt_debit_N1') - sumPx($comptes, ['252'], 'mvt_credit_N1')
       + sumPx($comptes, $FG_PFX_D, 'mvt_debit_N1') - sumPx($comptes, $FG_PFX_C, 'mvt_credit_N1')
       + sdPx($comptes, ['6542'], 'N1') + sdPx($comptes, ['812'], 'N1');

// ─── FH : Décaissements liés aux acquisitions d'immos financières ────
// Formule :
//   + mvt_debit(26) + mvt_debit(27 sauf 276 et 2714)
//   + mvt_debit(4813) − mvt_credit(4813)
//   − mvt_credit(1061+1062+1543)
//   + solde_débiteur_clôture(4782) + solde_créditeur_clôture(4792)
//
// 26,27       = immos financières (titres, prêts, dépôts)
// 276,2714    = créances sur cessions immos financières à exclure (déjà en FJ)
// 4813        = dettes/créances sur acquisitions immos financières (net)
// 1061,1062,1543 = reprises/subventions liées aux financières
// 4782        = dettes sur acquisitions immos financières (solde débiteur)
// 4792        = créances sur cessions immos financières (solde créditeur)

$FH_N  = sumPx($comptes, ['26','27'], 'mvt_debit_N')
       - sumPx($comptes, ['2714','276'], 'mvt_debit_N')
       + sumPx($comptes, ['4813'], 'mvt_debit_N')  - sumPx($comptes, ['4813'], 'mvt_credit_N')
       - sumPx($comptes, ['1061','1062','1543'], 'mvt_credit_N')
       + sdPx($comptes, ['4782'], 'N')
       + scPx($comptes, ['4792'], 'N');

$FH_N1 = sumPx($comptes, ['26','27'], 'mvt_debit_N1')
       - sumPx($comptes, ['2714','276'], 'mvt_debit_N1')
       + sumPx($comptes, ['4813'], 'mvt_debit_N1') - sumPx($comptes, ['4813'], 'mvt_credit_N1')
       - sumPx($comptes, ['1061','1062','1543'], 'mvt_credit_N1')
       + sdPx($comptes, ['4782'], 'N1')
       + scPx($comptes, ['4792'], 'N1');

// ─── FI : Encaissements liés aux cessions d'immos incorp. et corp. ───
// Formule :
//   + solde_créditeur_clôture(821+822+7541+7542)
//   + mvt_credit(4851+4852+4141+4142) − mvt_debit(4851+4852+4141+4142)
//
// 821,822     = produits de cessions d'immos incorp. et corp. (HAO)
// 7541,7542   = produits de cessions d'immos incorp. et corp. (ordinaires)
// 4851,4852   = créances sur cessions d'immos incorp. et corp. (net mouvement)
// 4141,4142   = créances clients liées aux cessions d'immos (net mouvement)

$FI_PFX_ADJ = ['4851','4852','4141','4142'];

$FI_N  = scPx($comptes, ['821','822','7541','7542'], 'N')
       + sumPx($comptes, $FI_PFX_ADJ, 'mvt_credit_N')
       - sumPx($comptes, $FI_PFX_ADJ, 'mvt_debit_N');

$FI_N1 = scPx($comptes, ['821','822','7541','7542'], 'N1')
       + sumPx($comptes, $FI_PFX_ADJ, 'mvt_credit_N1')
       - sumPx($comptes, $FI_PFX_ADJ, 'mvt_debit_N1');

// ─── FJ : Encaissements liés aux cessions d'immos financières ────────
// Formule :
//   + mvt_credit(26) + mvt_credit(27) − mvt_credit(2714+2766)
//   + solde_créditeur_clôture(826)
//   + mvt_credit(4143+4856) − mvt_debit(4143+4856)
//
// 26,27       = immos financières cédées (créditeur = sortie du bilan)
// 2714,2766   = comptes à exclure du 27 (créances sur cessions, pas des cessions directes)
// 826         = produits de cessions immos financières (HAO)
// 4143,4856   = créances sur cessions immos financières (net mouvement)

$FJ_PFX_ADJ = ['4143','4856'];

$FJ_N  = sumPx($comptes, ['26','27'], 'mvt_credit_N')
       - sumPx($comptes, ['2714','2766'], 'mvt_credit_N')
       + scPx($comptes, ['826'], 'N')
       + sumPx($comptes, $FJ_PFX_ADJ, 'mvt_credit_N')
       - sumPx($comptes, $FJ_PFX_ADJ, 'mvt_debit_N');

$FJ_N1 = sumPx($comptes, ['26','27'], 'mvt_credit_N1')
       - sumPx($comptes, ['2714','2766'], 'mvt_credit_N1')
       + scPx($comptes, ['826'], 'N1')
       + sumPx($comptes, $FJ_PFX_ADJ, 'mvt_credit_N1')
       - sumPx($comptes, $FJ_PFX_ADJ, 'mvt_debit_N1');

// ─── ZC ──────────────────────────────────────────────────────────────
$ZC_N  = -$FF_N  - $FG_N  - $FH_N  + $FI_N  + $FJ_N;
$ZC_N1 = -$FF_N1 - $FG_N1 - $FH_N1 + $FI_N1 + $FJ_N1;

// ─── FK : Augmentations de capital par apports nouveaux ──────────────
// Formule :
//   + scBc(101+102+1051, N) − scBc(101+102+1051, N-1)   ← variation capital
//   − sdPx(109+4581+4612+4613+467, N)                   ← actionnaires débiteurs / à déduire
//   − mvt_debit(11+1211+131, N)                          ← affectations résultat en réserves
//   + mvt_credit(103+104+11+1211+1291+1292+139+4619+465, N) ← primes, réserves, dividendes

$FK_CAP = ['101','102','1051'];
$FK_DEB = ['109','4581','4612','4613','467'];
$FK_MVD = ['11','1211','131'];
$FK_MVC = ['103','104','11','1211','1291','1292','139','4619','465'];

$FK_N  = scPx($comptes, $FK_CAP, 'N')  - scPx($comptes, $FK_CAP, 'N1')
       - sdPx($comptes, $FK_DEB, 'N')
       - sumPx($comptes, $FK_MVD, 'mvt_debit_N')
       + sumPx($comptes, $FK_MVC, 'mvt_credit_N');

$FK_N1 = scPx($comptes, $FK_CAP, 'N1') - scPx_N2($comptes, $FK_CAP)
       - sdPx($comptes, $FK_DEB, 'N1')
       - sumPx($comptes, $FK_MVD, 'mvt_debit_N1')
       + sumPx($comptes, $FK_MVC, 'mvt_credit_N1');

// ─── FL : Subventions d'investissement reçues ────────────────────────
// Formule :
//   + scBc(14, N) − scBc(14, N-1)   ← variation des subventions d'investissement
//   + scBc(799, N)                   ← autres produits à encaisser liés aux subventions
//   − scBc(4494+4581+4582, N)        ← subventions à rembourser / en attente

$FL_N  = scPx($comptes, ['14'], 'N')  - scPx($comptes, ['14'], 'N1')
       + scPx($comptes, ['799'], 'N')
       - scPx($comptes, ['4494','4581','4582'], 'N');

$FL_N1 = scPx($comptes, ['14'], 'N1') - scPx_N2($comptes, ['14'])
       + scPx($comptes, ['799'], 'N1')
       - scPx($comptes, ['4494','4581','4582'], 'N1');

// ─── FM : Prélèvements sur le capital ────────────────────────────────
// Formule :
//   + mvt_debit(4619+103+104, N)
//
// 4619 = actionnaires, capital à rembourser
// 103  = primes liées au capital (remboursement)
// 104  = écarts de réévaluation (remboursement)

$FM_N  = sumPx($comptes, ['4619','103','104'], 'mvt_debit_N');
$FM_N1 = sumPx($comptes, ['4619','103','104'], 'mvt_debit_N1');

// ─── FN : Dividendes versés ──────────────────────────────────────────
// Formule :
//   + mvt_debit(465, N)
//
// 465 = dividendes à payer (mouvements débiteurs = dividendes effectivement payés)

$FN_N  = sumPx($comptes, ['465'], 'mvt_debit_N');
$FN_N1 = sumPx($comptes, ['465'], 'mvt_debit_N1');

// ─── ZD ──────────────────────────────────────────────────────────────
$ZD_N  = $FK_N  + $FL_N  - $FM_N  - $FN_N;
$ZD_N1 = $FK_N1 + $FL_N1 - $FM_N1 - $FN_N1;

// ─── FO : Emprunts ───────────────────────────────────────────────────
// Formule :
//   + mvt_credit(161+162+1661+1662, N)   ← nouveaux emprunts obligataires/bancaires
//   − mvt_debit(4713, N)                 ← acomptes versés sur emprunts à déduire
//   − solde_débiteur_clôture(4784, N)    ← créances sur emprunts non encore encaissées

$FO_N  = sumPx($comptes, ['161','162','1661','1662'], 'mvt_credit_N')
       - sumPx($comptes, ['4713'], 'mvt_debit_N')
       - sdPx($comptes, ['4784'], 'N');

$FO_N1 = sumPx($comptes, ['161','162','1661','1662'], 'mvt_credit_N1')
       - sumPx($comptes, ['4713'], 'mvt_debit_N1')
       - sdPx($comptes, ['4784'], 'N1');

// ─── FP : Autres dettes financières diverses ─────────────────────────
// Formule :
//   + mvt_credit(163+164+165+167+168+181+182+183+184, N)  ← autres dettes financières nouvelles
//   − solde_débiteur_clôture(4784, N)                     ← créances sur emprunts non encaissées

$FP_PFX = ['163','164','165','167','168','181','182','183','184'];

$FP_N  = sumPx($comptes, $FP_PFX, 'mvt_credit_N')
       - sdPx($comptes, ['4784'], 'N');

$FP_N1 = sumPx($comptes, $FP_PFX, 'mvt_credit_N1')
       - sdPx($comptes, ['4784'], 'N1');

// ─── FQ : Remboursements des emprunts et autres dettes financières ───
// Formule :
//   + mvt_debit(16+17+181+182+183+184, N)   ← remboursements effectués
//   − solde_créditeur_clôture(4794, N)       ← dettes sur remboursements non encore décaissées

$FQ_PFX = ['16','17','181','182','183','184'];

$FQ_N  = sumPx($comptes, $FQ_PFX, 'mvt_debit_N')
       - scPx($comptes, ['4794'], 'N');

$FQ_N1 = sumPx($comptes, $FQ_PFX, 'mvt_debit_N1')
       - scPx($comptes, ['4794'], 'N1');

// ─── ZE, ZF, ZG ──────────────────────────────────────────────────────
$ZE_N  = $FO_N  + $FP_N  - $FQ_N;
$ZE_N1 = $FO_N1 + $FP_N1 - $FQ_N1;
$ZF_N  = $ZD_N  + $ZE_N;
$ZF_N1 = $ZD_N1 + $ZE_N1;
$ZG_N  = $ZB_N  + $ZC_N  + $ZF_N;
$ZG_N1 = $ZB_N1 + $ZC_N1 + $ZF_N1;

// ─── ZH : Vérification ───────────────────────────────────────────────
$ZH_check_N  = $ZA_N  + $ZG_N;
$ZH_check_N1 = $ZA_N1 + $ZG_N1;
$ecart_N     = round($ZH_N - $ZH_check_N, 2);
$ecart_N1    = round($ZA_N - $ZH_check_N1, 2);

// Ligne BF intermédiaire (pour affichage)
$BF_N  = -$FB_N  - $FC_N  - $FD_N  + $FE_N;
$BF_N1 = -$FB_N1 - $FC_N1 - $FD_N1 + $FE_N1;
