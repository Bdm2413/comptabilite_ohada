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
$charges = [];
$produits = [];
$charges_n1 = [];
$produits_n1 = [];

try {
    // Récupérer tous les comptes de résultat avec leurs soldes
    $sql = "
        SELECT
            pc.compte,
            pc.intitule_compte,
            pc.tableau,
            pc.rd,
            pc.rc,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.debit ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND e.statut = 'Validé' THEN le.credit ELSE 0 END), 0) as total_credit
        FROM plan_comptable pc
        LEFT JOIN lignes_ecriture le ON pc.compte = le.compte AND le.societe_id = pc.societe_id
        LEFT JOIN ecritures e ON le.id_ecriture = e.id AND e.statut = 'Validé' AND e.societe_id = pc.societe_id
        WHERE pc.actif = 'Oui' AND pc.tableau = 'Résultat'
        AND pc.societe_id = ?
        GROUP BY pc.compte, pc.intitule_compte, pc.tableau, pc.rd, pc.rc
        ORDER BY pc.compte
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$date_debut, $date_fin, $date_debut, $date_fin, $societe_id]);
    $comptes = $stmt->fetchAll();

    // Organiser les comptes par rubrique — solde net (crédit − débit)
    foreach ($comptes as $compte) {
        $solde_net = $compte['total_credit'] - $compte['total_debit'];
        if (abs($solde_net) < 0.01) continue;

        if ($solde_net > 0 && !empty($compte['rc'])) {
            $ref = $compte['rc'];
            if (!isset($produits[$ref])) $produits[$ref] = 0;
            $produits[$ref] += abs($solde_net);
        } elseif ($solde_net < 0 && !empty($compte['rd'])) {
            $ref = $compte['rd'];
            if (!isset($charges[$ref])) $charges[$ref] = 0;
            $charges[$ref] += abs($solde_net);
        }
    }

    // Calculer les Soldes Intermédiaires de Gestion (SIG) selon SYSCOHADA
    // XA = TA + RA + RB (Marge commerciale)
    $XA = ($produits['TA'] ?? 0) - ($charges['RA'] ?? 0) - ($charges['RB'] ?? 0);

    // XB = TA + TB + TC + TD (Chiffre d'affaires)
    $XB = ($produits['TA'] ?? 0) + ($produits['TB'] ?? 0) + ($produits['TC'] ?? 0) + ($produits['TD'] ?? 0);

    // XC = RA + RB + XB + TE + TF + TG + TH + TI + RC + RD + RE + RF + RG + RH + RI + RJ (Valeur ajoutée)
    $XC = -($charges['RA'] ?? 0) - ($charges['RB'] ?? 0) + $XB + ($produits['TE'] ?? 0) + ($produits['TF'] ?? 0)
          + ($produits['TG'] ?? 0) + ($produits['TH'] ?? 0) + ($produits['TI'] ?? 0)
          - ($charges['RC'] ?? 0) - ($charges['RD'] ?? 0) - ($charges['RE'] ?? 0) - ($charges['RF'] ?? 0)
          - ($charges['RG'] ?? 0) - ($charges['RH'] ?? 0) - ($charges['RI'] ?? 0) - ($charges['RJ'] ?? 0);

    // XD = XC + RK (Excédent Brut d'Exploitation)
    $XD = $XC - ($charges['RK'] ?? 0);

    // XE = XD + TJ + RL (Résultat d'exploitation)
    $XE = $XD + ($produits['TJ'] ?? 0) - ($charges['RL'] ?? 0);

    // XF = TK + TL + TM + RM + RN (Résultat financier)
    $XF = ($produits['TK'] ?? 0) + ($produits['TL'] ?? 0) + ($produits['TM'] ?? 0)
          - ($charges['RM'] ?? 0) - ($charges['RN'] ?? 0);

    // XG = XE + XF (Résultat des Activités Ordinaires)
    $XG = $XE + $XF;

    // XH = TN + TO + RO + RP (Résultat HAO)
    $XH = ($produits['TN'] ?? 0) + ($produits['TO'] ?? 0) - ($charges['RO'] ?? 0) - ($charges['RP'] ?? 0);

    // XI = XG + XH + RQ + RS (Résultat Net)
    $XI = $XG + $XH - ($charges['RQ'] ?? 0) - ($charges['RS'] ?? 0);

    // Ajouter les SIG aux tableaux pour l'affichage
    $soldes = [
        'XA' => $XA,
        'XB' => $XB,
        'XC' => $XC,
        'XD' => $XD,
        'XE' => $XE,
        'XF' => $XF,
        'XG' => $XG,
        'XH' => $XH,
        'XI' => $XI
    ];

    // ===== CALCULS POUR LA PÉRIODE N-1 =====

    // Récupérer tous les comptes de résultat N-1
    $stmt_n1 = $db->prepare($sql);
    $stmt_n1->execute([$date_debut_n1, $date_fin_n1, $date_debut_n1, $date_fin_n1, $societe_id]);
    $comptes_n1 = $stmt_n1->fetchAll();

    foreach ($comptes_n1 as $compte) {
        $solde_net = $compte['total_credit'] - $compte['total_debit'];
        if (abs($solde_net) < 0.01) continue;

        if ($solde_net > 0 && !empty($compte['rc'])) {
            $ref = $compte['rc'];
            if (!isset($produits_n1[$ref])) $produits_n1[$ref] = 0;
            $produits_n1[$ref] += abs($solde_net);
        } elseif ($solde_net < 0 && !empty($compte['rd'])) {
            $ref = $compte['rd'];
            if (!isset($charges_n1[$ref])) $charges_n1[$ref] = 0;
            $charges_n1[$ref] += abs($solde_net);
        }
    }

    // Calculer les SIG pour N-1
    $XA_n1 = ($produits_n1['TA'] ?? 0) - ($charges_n1['RA'] ?? 0) - ($charges_n1['RB'] ?? 0);
    $XB_n1 = ($produits_n1['TA'] ?? 0) + ($produits_n1['TB'] ?? 0) + ($produits_n1['TC'] ?? 0) + ($produits_n1['TD'] ?? 0);
    $XC_n1 = -($charges_n1['RA'] ?? 0) - ($charges_n1['RB'] ?? 0) + $XB_n1 + ($produits_n1['TE'] ?? 0) + ($produits_n1['TF'] ?? 0)
          + ($produits_n1['TG'] ?? 0) + ($produits_n1['TH'] ?? 0) + ($produits_n1['TI'] ?? 0)
          - ($charges_n1['RC'] ?? 0) - ($charges_n1['RD'] ?? 0) - ($charges_n1['RE'] ?? 0) - ($charges_n1['RF'] ?? 0)
          - ($charges_n1['RG'] ?? 0) - ($charges_n1['RH'] ?? 0) - ($charges_n1['RI'] ?? 0) - ($charges_n1['RJ'] ?? 0);
    $XD_n1 = $XC_n1 - ($charges_n1['RK'] ?? 0);
    $XE_n1 = $XD_n1 + ($produits_n1['TJ'] ?? 0) - ($charges_n1['RL'] ?? 0);
    $XF_n1 = ($produits_n1['TK'] ?? 0) + ($produits_n1['TL'] ?? 0) + ($produits_n1['TM'] ?? 0)
          - ($charges_n1['RM'] ?? 0) - ($charges_n1['RN'] ?? 0);
    $XG_n1 = $XE_n1 + $XF_n1;
    $XH_n1 = ($produits_n1['TN'] ?? 0) + ($produits_n1['TO'] ?? 0) - ($charges_n1['RO'] ?? 0) - ($charges_n1['RP'] ?? 0);
    $XI_n1 = $XG_n1 + $XH_n1 - ($charges_n1['RQ'] ?? 0) - ($charges_n1['RS'] ?? 0);

    $soldes_n1 = [
        'XA' => $XA_n1,
        'XB' => $XB_n1,
        'XC' => $XC_n1,
        'XD' => $XD_n1,
        'XE' => $XE_n1,
        'XF' => $XF_n1,
        'XG' => $XG_n1,
        'XH' => $XH_n1,
        'XI' => $XI_n1
    ];

} catch (Exception $e) {
    die('Erreur: ' . $e->getMessage());
}

// Définir les libellés
$libelles_compte_resultat = [
    'TA' => ['libelle' => 'Ventes de marchandises', 'type' => 'produit', 'signe' => '+'],
    'RA' => ['libelle' => 'Achats de marchandises', 'type' => 'charge', 'signe' => '-'],
    'RB' => ['libelle' => 'Variation de stocks de marchandises', 'type' => 'variable', 'signe' => '(+/-)'],
    'XA' => ['libelle' => 'MARGE COMMERCIALE (SOMME TA à RB)', 'type' => 'solde', 'signe' => '='],
    'TB' => ['libelle' => 'Vente de produits fabriqués', 'type' => 'produit', 'signe' => '+'],
    'TC' => ['libelle' => 'Travaux, services vendus', 'type' => 'produit', 'signe' => '+'],
    'TD' => ['libelle' => 'Produits accessoires', 'type' => 'produit', 'signe' => '+'],
    'XB' => ['libelle' => 'CHIFFRE D\'AFFAIRES (TA + TB + TC + TD)', 'type' => 'solde', 'signe' => '='],
    'TE' => ['libelle' => 'Production stockée (ou déstockage)', 'type' => 'variable', 'signe' => '(+/-)'],
    'TF' => ['libelle' => 'Production immobilisée', 'type' => 'produit', 'signe' => '+'],
    'TG' => ['libelle' => 'Subventions d\'exploitation', 'type' => 'produit', 'signe' => '+'],
    'TH' => ['libelle' => 'Autres produits', 'type' => 'produit', 'signe' => '+'],
    'TI' => ['libelle' => 'Transfert de charges d\'exploitation', 'type' => 'produit', 'signe' => '+'],
    'RC' => ['libelle' => 'Achats de matières premières et fournitures liées', 'type' => 'charge', 'signe' => '-'],
    'RD' => ['libelle' => 'Variation de stocks de matières premières et fournitures liées', 'type' => 'variable', 'signe' => '(+/-)'],
    'RE' => ['libelle' => 'Autres achats', 'type' => 'charge', 'signe' => '-'],
    'RF' => ['libelle' => 'Variation de stocks d\'autres approvisionnements', 'type' => 'variable', 'signe' => '(+/-)'],
    'RG' => ['libelle' => 'Transports', 'type' => 'charge', 'signe' => '-'],
    'RH' => ['libelle' => 'Services extérieurs', 'type' => 'charge', 'signe' => '-'],
    'RI' => ['libelle' => 'Impôts et taxes', 'type' => 'charge', 'signe' => '-'],
    'RJ' => ['libelle' => 'Autres charges', 'type' => 'charge', 'signe' => '-'],
    'XC' => ['libelle' => 'VALEUR AJOUTEE (XB + RA + RB) + (somme TE à RJ)', 'type' => 'solde', 'signe' => '='],
    'RK' => ['libelle' => 'Charges de personnel', 'type' => 'charge', 'signe' => '-'],
    'XD' => ['libelle' => 'EXCÉDENT BRUT D\'EXPLOITATION (XC + RK)', 'type' => 'solde', 'signe' => '='],
    'TJ' => ['libelle' => 'Reprise d\'amortissements, provisions et dépréciations', 'type' => 'produit', 'signe' => '+'],
    'RL' => ['libelle' => 'Dotations aux amortissements et aux provisions', 'type' => 'charge', 'signe' => '-'],
    'XE' => ['libelle' => 'RÉSULTAT D\'EXPLOITATION (XD+TJ+ RL)', 'type' => 'solde', 'signe' => '='],
    'TK' => ['libelle' => 'Revenus financiers et assimilés', 'type' => 'produit', 'signe' => '+'],
    'TL' => ['libelle' => 'Reprises de provisions et dépréciations financières', 'type' => 'produit', 'signe' => '+'],
    'TM' => ['libelle' => 'Transferts de charges financières', 'type' => 'produit', 'signe' => '+'],
    'RM' => ['libelle' => 'Frais financiers et charges assimilées', 'type' => 'charge', 'signe' => '-'],
    'RN' => ['libelle' => 'Dotations aux provisions et dépréciations financières', 'type' => 'charge', 'signe' => '-'],
    'XF' => ['libelle' => 'RESULTAT FINANCIER (somme TK à RN)', 'type' => 'solde', 'signe' => '='],
    'XG' => ['libelle' => 'RESULTAT DES ACTIVITES ORDINAIRES (XE+XF)', 'type' => 'solde', 'signe' => '='],
    'TN' => ['libelle' => 'Produit des cessions d\'immobilisations', 'type' => 'produit', 'signe' => '+'],
    'TO' => ['libelle' => 'Autres produits HAO', 'type' => 'produit', 'signe' => '+'],
    'RO' => ['libelle' => 'Valeurs comptables des cessions d\'immobilisations', 'type' => 'charge', 'signe' => '-'],
    'RP' => ['libelle' => 'Autres Charges HAO', 'type' => 'charge', 'signe' => '-'],
    'XH' => ['libelle' => 'RESULTAT HORS ACTIVITES ORDINAIRES (somme TN à RP)', 'type' => 'solde', 'signe' => '='],
    'RQ' => ['libelle' => 'Participation des travailleurs', 'type' => 'charge', 'signe' => '-'],
    'RS' => ['libelle' => 'Impôts sur le résultat', 'type' => 'charge', 'signe' => '-'],
    'XI' => ['libelle' => 'RESULTAT NET (XG + XH + RQ + RS)', 'type' => 'solde', 'signe' => '=']
];

// Créer le PDF en mode portrait A4
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Comptabilité OHADA');
$pdf->SetAuthor('Système Comptable');
$pdf->SetTitle('Compte de Résultat OHADA');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$pdf->AddPage();
$pdf->SetMargins(5, 10, 5);
$pdf->SetAutoPageBreak(false, 5);

// Titre
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'COMPTE DE RESULTAT OHADA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(0, 4, 'Exercice du ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1, 'C');
$pdf->Cell(0, 4, 'Période N-1: du ' . date('d/m/Y', strtotime($date_debut_n1)) . ' au ' . date('d/m/Y', strtotime($date_fin_n1)), 0, 1, 'C');
$pdf->Ln(2);

// Calculer la largeur totale du tableau et le centrer
$tableWidth = 190; // Largeur totale du tableau
$pageWidth = 210; // Largeur A4
$leftMargin = ($pageWidth - $tableWidth) / 2;
$pdf->SetLeftMargin($leftMargin);
$pdf->SetX($leftMargin);

// En-tête du tableau
$pdf->SetFont('helvetica', 'B', 6);
$pdf->SetFillColor(34, 139, 34);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(9, 5, 'REF', 1, 0, 'C', true);
$pdf->Cell(78, 5, 'LIBELLES', 1, 0, 'L', true);
$pdf->Cell(6, 5, '+/-', 1, 0, 'C', true);
$pdf->Cell(24, 5, 'NET (N)', 1, 0, 'R', true);
$pdf->Cell(24, 5, 'NET (N-1)', 1, 0, 'R', true);
$pdf->Cell(24, 5, 'VAR', 1, 0, 'R', true);
$pdf->Cell(25, 5, '%', 1, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);

// Données
foreach ($libelles_compte_resultat as $ref => $info) {
    $is_solde = $info['type'] === 'solde';

    // Calculer le montant N
    $montant_n = 0;
    if ($info['type'] === 'solde' && isset($soldes[$ref])) {
        $montant_n = $soldes[$ref];
    } elseif ($info['type'] === 'charge' && isset($charges[$ref])) {
        $montant_n = -$charges[$ref];
    } elseif ($info['type'] === 'produit' && isset($produits[$ref])) {
        $montant_n = $produits[$ref];
    } elseif ($info['type'] === 'variable') {
        if (isset($charges[$ref])) {
            $montant_n = -$charges[$ref];
        } elseif (isset($produits[$ref])) {
            $montant_n = $produits[$ref];
        }
    }

    // Calculer le montant N-1
    $montant_n1 = 0;
    if ($info['type'] === 'solde' && isset($soldes_n1[$ref])) {
        $montant_n1 = $soldes_n1[$ref];
    } elseif ($info['type'] === 'charge' && isset($charges_n1[$ref])) {
        $montant_n1 = -$charges_n1[$ref];
    } elseif ($info['type'] === 'produit' && isset($produits_n1[$ref])) {
        $montant_n1 = $produits_n1[$ref];
    } elseif ($info['type'] === 'variable') {
        if (isset($charges_n1[$ref])) {
            $montant_n1 = -$charges_n1[$ref];
        } elseif (isset($produits_n1[$ref])) {
            $montant_n1 = $produits_n1[$ref];
        }
    }

    // Calculer variation et taux
    $variation = $montant_n - $montant_n1;
    $taux = ($montant_n1 != 0) ? (($variation / $montant_n1) * 100) : 0;

    if ($is_solde) {
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetFillColor(220, 220, 220);
    } else {
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetFillColor(255, 255, 255);
    }

    $pdf->SetX($leftMargin);
    $pdf->Cell(9, 4, $ref, 1, 0, 'C', true);
    $pdf->Cell(78, 4, mb_substr($info['libelle'], 0, 68), 1, 0, 'L', true);
    $pdf->Cell(6, 4, $info['signe'], 1, 0, 'C', true);

    // NET (N)
    $montant_n_display = '';
    if ($montant_n != 0) {
        $montant_n_display = ($montant_n < 0 ? '-' : '') . safe_number_format(abs($montant_n));
    }
    $pdf->Cell(24, 4, $montant_n_display, 1, 0, 'R', true);

    // NET (N-1)
    $montant_n1_display = '';
    if ($montant_n1 != 0) {
        $montant_n1_display = ($montant_n1 < 0 ? '-' : '') . safe_number_format(abs($montant_n1));
    }
    $pdf->Cell(24, 4, $montant_n1_display, 1, 0, 'R', true);

    // Variation avec couleur
    if ($variation != 0) {
        $pdf->SetTextColor($variation > 0 ? 0 : 255, $variation > 0 ? 128 : 0, 0);
        $pdf->Cell(24, 4, ($variation > 0 ? '+' : '') . ($variation < 0 ? '-' : '') . safe_number_format(abs($variation)), 1, 0, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell(24, 4, '', 1, 0, 'R', true);
    }

    // Taux avec couleur
    if ($taux != 0) {
        $pdf->SetTextColor($taux > 0 ? 0 : 255, $taux > 0 ? 128 : 0, 0);
        $pdf->Cell(25, 4, ($taux > 0 ? '+' : '') . number_format($taux, 1) . '%', 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell(25, 4, '', 1, 1, 'R', true);
    }
}

// Générer le fichier
$filename = 'Compte_Resultat_OHADA_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D');
exit;
