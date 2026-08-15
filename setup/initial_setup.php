<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions_multi_societes.php';
require_once __DIR__ . '/../config/functions_multi_devises.php';

if (!needsInitialSetup()) {
    header('Location: ../index.php');
    exit();
}

$erreurs  = [];
$succes   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        // --- Validation société ---
        $raison_sociale = trim($_POST['raison_sociale'] ?? '');
        $code_societe   = strtoupper(trim($_POST['code_societe'] ?? ''));
        $mode           = $_POST['mode'] ?? 'entreprise';
        $type_entite    = ($mode === 'cabinet') ? 'cabinet' : ($_POST['type_entite'] ?? 'entreprise_individuelle');
        $devise         = $_POST['devise_principale'] ?? 'XOF';

        if (empty($raison_sociale)) $erreurs[] = "La raison sociale est obligatoire.";
        if (empty($code_societe))   $erreurs[] = "Le code société est obligatoire.";

        // --- Validation admin ---
        $nom_utilisateur  = trim($_POST['nom_utilisateur'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $password         = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($nom_utilisateur))  $erreurs[] = "Le nom d'utilisateur est obligatoire.";
        if (empty($email))            $erreurs[] = "L'email est obligatoire.";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erreurs[] = "L'email n'est pas valide.";
        if (strlen($password) < 8)    $erreurs[] = "Le mot de passe doit contenir au moins 8 caractères.";
        elseif ($password !== $password_confirm) $erreurs[] = "Les mots de passe ne correspondent pas.";

        if (empty($erreurs)) {
            // Créer la société
            $societe_id = createSociete([
                'code_societe'          => $code_societe,
                'raison_sociale'        => $raison_sociale,
                'type_entite'           => $type_entite,
                'devise_principale'     => $devise,
                'adresse'               => $_POST['adresse']            ?? null,
                'ville'                 => $_POST['ville']              ?? null,
                'pays'                  => $_POST['pays']               ?? "Côte d'Ivoire",
                'telephone'             => $_POST['telephone']          ?? null,
                'email'                 => $_POST['email_societe']      ?? null,
                'site_web'              => $_POST['site_web']           ?? null,
                'numero_rccm'           => $_POST['numero_rccm']        ?? null,
                'numero_contribuable'   => $_POST['numero_contribuable'] ?? null,
                'forme_juridique'       => $_POST['forme_juridique']    ?? null,
                'secteur_activite'      => $_POST['secteur_activite']   ?? null,
                'capital'               => $_POST['capital']            ?? null,
                'regime_fiscal'         => $_POST['regime_fiscal']      ?? 'reel_normal',
                'actif'                 => 1,
            ]);

            if (!$societe_id) throw new Exception("Impossible de créer la société.");

            // Créer l'utilisateur admin
            $stmt = $db->prepare("
                INSERT INTO utilisateurs (nom_utilisateur, email, mot_de_passe, role_global, dernier_societe_id, actif)
                VALUES (?, ?, ?, 'super_admin', ?, 1)
            ");
            $stmt->execute([$nom_utilisateur, $email, password_hash($password, PASSWORD_DEFAULT), $societe_id]);
            $user_id = (int)$db->lastInsertId();

            if (!grantUserAccessToSociete($user_id, $societe_id, 'admin', true)) {
                throw new Exception("Impossible d'associer l'utilisateur à la société.");
            }

            // Créer l'exercice si les données sont présentes
            $exercice_debut = $_POST['exercice_debut'] ?? date('Y-01-01');
            $exercice_fin   = $_POST['exercice_fin']   ?? date('Y-12-31');
            try {
                $stmt = $db->prepare("
                    INSERT INTO exercices_comptables (societe_id, libelle, date_debut, date_fin, statut, actif)
                    VALUES (?, ?, ?, ?, 'ouvert', 1)
                ");
                $annee = date('Y', strtotime($exercice_debut));
                $stmt->execute([$societe_id, "Exercice $annee", $exercice_debut, $exercice_fin]);
            } catch (Exception $e) {
                // Table exercices peut ne pas exister, on continue
            }

            // Sauvegarder le mode d'installation
            $db->prepare("
                INSERT INTO parametres_systeme (cle, valeur, type_valeur, modifiable, description)
                VALUES ('mode_installation', ?, 'string', 0, 'Mode installation : entreprise ou cabinet')
                ON DUPLICATE KEY UPDATE valeur = ?
            ")->execute([$mode, $mode]);

            // Sauvegarder la longueur des numéros de compte
            $longueur_compte = max(4, (int)($_POST['longueur_compte'] ?? 4));
            $db->prepare("
                INSERT INTO parametres_systeme (cle, valeur, type_valeur, modifiable, description)
                VALUES ('longueur_compte', ?, 'int', 0, 'Longueur des numéros de compte - non modifiable après installation')
                ON DUPLICATE KEY UPDATE valeur = ?
            ")->execute([$longueur_compte, $longueur_compte]);

            // Import du plan comptable SYSCOHADA Révisé
            // Source : table_correspondance (1106 sous-comptes à 4 chiffres, couverture complète)
            $plan_type = $_POST['plan_type'] ?? 'ohada';
            if ($plan_type === 'ohada') {
                $comptes = $db->query("
                    SELECT compte, classe, libelle, tableau, bd, bc, rd, rc, type_compte
                    FROM table_correspondance
                    ORDER BY compte
                ")->fetchAll();

                $insComp = $db->prepare("
                    INSERT INTO plan_comptable
                        (societe_id, compte, intitule_compte, classe, quatre_chiffres, tableau, bd, bc, rd, rc, type, actif)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Oui')
                ");

                foreach ($comptes as $c) {
                    $num4 = (string)$c['compte'];
                    $lib  = $c['libelle'];
                    if (empty($num4) || empty($lib)) continue;

                    // Appliquer le padding si longueur choisie > 4
                    $num_final = ($longueur_compte > 4)
                        ? str_pad($num4, $longueur_compte, '0', STR_PAD_RIGHT)
                        : $num4;

                    $insComp->execute([
                        $societe_id,
                        (int)$num_final,
                        $lib,
                        (int)$c['classe'],
                        (int)$num4,
                        $c['tableau'] ?? 'ND',
                        $c['bd'],
                        $c['bc'],
                        $c['rd'],
                        $c['rc'],
                        $c['type_compte'],
                    ]);
                }
            }

            // Sauvegarder la taille de police
            $taille_police = $_POST['taille_police'] ?? 'normal';
            try {
                $db->prepare("
                    INSERT INTO parametres_systeme (cle, valeur, type_valeur, modifiable, description)
                    VALUES ('taille_police', ?, 'string', 1, 'Taille de police globale')
                    ON DUPLICATE KEY UPDATE valeur = ?
                ")->execute([$taille_police, $taille_police]);
            } catch (Exception $e) { /* table peut ne pas avoir cette colonne */ }

            // Taux de change
            mettreAJourTauxFixes();

            // Marquer installation complète
            if (!markInstallationComplete()) throw new Exception("Erreur lors de la finalisation.");

            $db->commit();

            // Connecter automatiquement
            $_SESSION['user_id']    = $user_id;
            $_SESSION['user_name']  = $nom_utilisateur;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role']  = 'super_admin';
            $_SESSION['societe_id'] = $societe_id;

            $succes = true;
        }

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $erreurs[] = $e->getMessage();
        error_log("Setup error: " . $e->getMessage());
    }
}

// Devises disponibles - liste exhaustive ISO 4217
$devises = [
    // Afrique
    ['code_devise' => 'XOF', 'libelle' => 'Franc CFA UEMOA (Bénin, Burkina, CI, Guinée-Bissau, Mali, Niger, Sénégal, Togo)', 'symbole' => 'FCFA'],
    ['code_devise' => 'XAF', 'libelle' => 'Franc CFA CEMAC (Cameroun, Centrafrique, Congo, Gabon, Guinée éq., Tchad)',        'symbole' => 'FCFA'],
    ['code_devise' => 'DZD', 'libelle' => 'Dinar algérien',                 'symbole' => 'DA'],
    ['code_devise' => 'AOA', 'libelle' => 'Kwanza angolais',                'symbole' => 'Kz'],
    ['code_devise' => 'BIF', 'libelle' => 'Franc burundais',                'symbole' => 'BIF'],
    ['code_devise' => 'CVE', 'libelle' => 'Escudo cap-verdien',             'symbole' => 'Esc'],
    ['code_devise' => 'KMF', 'libelle' => 'Franc comorien',                 'symbole' => 'FC'],
    ['code_devise' => 'CDF', 'libelle' => 'Franc congolais (RDC)',          'symbole' => 'FC'],
    ['code_devise' => 'DJF', 'libelle' => 'Franc djiboutien',              'symbole' => 'Fdj'],
    ['code_devise' => 'EGP', 'libelle' => 'Livre égyptienne',              'symbole' => 'EGP'],
    ['code_devise' => 'ERN', 'libelle' => 'Nakfa érythréen',               'symbole' => 'Nkf'],
    ['code_devise' => 'ETB', 'libelle' => 'Birr éthiopien',                'symbole' => 'Br'],
    ['code_devise' => 'GMD', 'libelle' => 'Dalasi gambien',                'symbole' => 'D'],
    ['code_devise' => 'GHS', 'libelle' => 'Cedi ghanéen',                  'symbole' => 'GH₵'],
    ['code_devise' => 'GNF', 'libelle' => 'Franc guinéen',                 'symbole' => 'FG'],
    ['code_devise' => 'KES', 'libelle' => 'Shilling kényan',               'symbole' => 'KSh'],
    ['code_devise' => 'LSL', 'libelle' => 'Loti lésothien',                'symbole' => 'L'],
    ['code_devise' => 'LRD', 'libelle' => 'Dollar libérien',               'symbole' => 'L$'],
    ['code_devise' => 'LYD', 'libelle' => 'Dinar libyen',                  'symbole' => 'LYD'],
    ['code_devise' => 'MGA', 'libelle' => 'Ariary malgache',               'symbole' => 'Ar'],
    ['code_devise' => 'MWK', 'libelle' => 'Kwacha malawien',               'symbole' => 'MK'],
    ['code_devise' => 'MAD', 'libelle' => 'Dirham marocain',               'symbole' => 'MAD'],
    ['code_devise' => 'MRU', 'libelle' => 'Ouguiya mauritanien',           'symbole' => 'UM'],
    ['code_devise' => 'MUR', 'libelle' => 'Roupie mauricienne',            'symbole' => 'Rs'],
    ['code_devise' => 'MZN', 'libelle' => 'Metical mozambicain',           'symbole' => 'MT'],
    ['code_devise' => 'NAD', 'libelle' => 'Dollar namibien',               'symbole' => 'N$'],
    ['code_devise' => 'NGN', 'libelle' => 'Naira nigérian',                'symbole' => '₦'],
    ['code_devise' => 'RWF', 'libelle' => 'Franc rwandais',                'symbole' => 'RF'],
    ['code_devise' => 'STN', 'libelle' => 'Dobra de São Tomé-et-Príncipe', 'symbole' => 'Db'],
    ['code_devise' => 'SCR', 'libelle' => 'Roupie seychelloise',           'symbole' => 'Rs'],
    ['code_devise' => 'SLE', 'libelle' => 'Leone sierra-léonais',          'symbole' => 'Le'],
    ['code_devise' => 'SOS', 'libelle' => 'Shilling somalien',             'symbole' => 'Sh'],
    ['code_devise' => 'ZAR', 'libelle' => 'Rand sud-africain',             'symbole' => 'R'],
    ['code_devise' => 'SSP', 'libelle' => 'Livre sud-soudanaise',          'symbole' => 'SSP'],
    ['code_devise' => 'SDG', 'libelle' => 'Livre soudanaise',              'symbole' => 'SDG'],
    ['code_devise' => 'SZL', 'libelle' => 'Lilangeni eswatinien',          'symbole' => 'L'],
    ['code_devise' => 'TZS', 'libelle' => 'Shilling tanzanien',            'symbole' => 'TSh'],
    ['code_devise' => 'TND', 'libelle' => 'Dinar tunisien',                'symbole' => 'DT'],
    ['code_devise' => 'UGX', 'libelle' => 'Shilling ougandais',            'symbole' => 'USh'],
    ['code_devise' => 'ZMW', 'libelle' => 'Kwacha zambien',                'symbole' => 'ZK'],
    ['code_devise' => 'ZWL', 'libelle' => 'Dollar zimbabwéen',             'symbole' => 'Z$'],
    // Europe
    ['code_devise' => 'EUR', 'libelle' => 'Euro',                          'symbole' => '€'],
    ['code_devise' => 'GBP', 'libelle' => 'Livre sterling',                'symbole' => '£'],
    ['code_devise' => 'CHF', 'libelle' => 'Franc suisse',                  'symbole' => 'Fr'],
    ['code_devise' => 'NOK', 'libelle' => 'Couronne norvégienne',          'symbole' => 'kr'],
    ['code_devise' => 'SEK', 'libelle' => 'Couronne suédoise',             'symbole' => 'kr'],
    ['code_devise' => 'DKK', 'libelle' => 'Couronne danoise',              'symbole' => 'kr'],
    ['code_devise' => 'ISK', 'libelle' => 'Couronne islandaise',           'symbole' => 'kr'],
    ['code_devise' => 'PLN', 'libelle' => 'Zloty polonais',                'symbole' => 'zł'],
    ['code_devise' => 'CZK', 'libelle' => 'Couronne tchèque',              'symbole' => 'Kč'],
    ['code_devise' => 'HUF', 'libelle' => 'Forint hongrois',               'symbole' => 'Ft'],
    ['code_devise' => 'RON', 'libelle' => 'Leu roumain',                   'symbole' => 'lei'],
    ['code_devise' => 'BGN', 'libelle' => 'Lev bulgare',                   'symbole' => 'лв'],
    ['code_devise' => 'RSD', 'libelle' => 'Dinar serbe',                   'symbole' => 'RSD'],
    ['code_devise' => 'MKD', 'libelle' => 'Denar macédonien',              'symbole' => 'ден'],
    ['code_devise' => 'ALL', 'libelle' => 'Lek albanais',                  'symbole' => 'L'],
    ['code_devise' => 'BAM', 'libelle' => 'Mark convertible bosnien',      'symbole' => 'KM'],
    ['code_devise' => 'MDL', 'libelle' => 'Leu moldave',                   'symbole' => 'L'],
    ['code_devise' => 'UAH', 'libelle' => 'Hryvnia ukrainienne',           'symbole' => '₴'],
    ['code_devise' => 'RUB', 'libelle' => 'Rouble russe',                  'symbole' => '₽'],
    ['code_devise' => 'TRY', 'libelle' => 'Livre turque',                  'symbole' => '₺'],
    ['code_devise' => 'GEL', 'libelle' => 'Lari géorgien',                 'symbole' => '₾'],
    ['code_devise' => 'AZN', 'libelle' => 'Manat azerbaïdjanais',          'symbole' => '₼'],
    ['code_devise' => 'AMD', 'libelle' => 'Dram arménien',                 'symbole' => '֏'],
    // Amériques
    ['code_devise' => 'USD', 'libelle' => 'Dollar américain',              'symbole' => '$'],
    ['code_devise' => 'CAD', 'libelle' => 'Dollar canadien',               'symbole' => 'CA$'],
    ['code_devise' => 'MXN', 'libelle' => 'Peso mexicain',                 'symbole' => 'MX$'],
    ['code_devise' => 'BRL', 'libelle' => 'Real brésilien',                'symbole' => 'R$'],
    ['code_devise' => 'ARS', 'libelle' => 'Peso argentin',                 'symbole' => '$'],
    ['code_devise' => 'CLP', 'libelle' => 'Peso chilien',                  'symbole' => 'CL$'],
    ['code_devise' => 'COP', 'libelle' => 'Peso colombien',                'symbole' => 'CO$'],
    ['code_devise' => 'PEN', 'libelle' => 'Sol péruvien',                  'symbole' => 'S/'],
    ['code_devise' => 'VES', 'libelle' => 'Bolívar vénézuélien',           'symbole' => 'Bs.S'],
    ['code_devise' => 'BOB', 'libelle' => 'Boliviano',                     'symbole' => 'Bs.'],
    ['code_devise' => 'UYU', 'libelle' => 'Peso uruguayen',                'symbole' => '$U'],
    ['code_devise' => 'PYG', 'libelle' => 'Guaraní paraguayen',            'symbole' => '₲'],
    ['code_devise' => 'GYD', 'libelle' => 'Dollar guyanais',               'symbole' => 'G$'],
    ['code_devise' => 'SRD', 'libelle' => 'Dollar surinamais',             'symbole' => 'SR$'],
    ['code_devise' => 'TTD', 'libelle' => 'Dollar de Trinité-et-Tobago',   'symbole' => 'TT$'],
    ['code_devise' => 'JMD', 'libelle' => 'Dollar jamaïcain',              'symbole' => 'J$'],
    ['code_devise' => 'HTG', 'libelle' => 'Gourde haïtienne',              'symbole' => 'G'],
    ['code_devise' => 'DOP', 'libelle' => 'Peso dominicain',               'symbole' => 'RD$'],
    ['code_devise' => 'GTQ', 'libelle' => 'Quetzal guatémaltèque',         'symbole' => 'Q'],
    ['code_devise' => 'HNL', 'libelle' => 'Lempira hondurien',             'symbole' => 'L'],
    ['code_devise' => 'NIO', 'libelle' => 'Córdoba nicaraguayen',          'symbole' => 'C$'],
    ['code_devise' => 'CRC', 'libelle' => 'Colón costaricain',             'symbole' => '₡'],
    ['code_devise' => 'PAB', 'libelle' => 'Balboa panaméen',               'symbole' => 'B/.'],
    ['code_devise' => 'XCD', 'libelle' => 'Dollar des Caraïbes orientales','symbole' => 'EC$'],
    ['code_devise' => 'BBD', 'libelle' => 'Dollar de la Barbade',          'symbole' => 'Bds$'],
    ['code_devise' => 'BSD', 'libelle' => 'Dollar des Bahamas',            'symbole' => 'B$'],
    ['code_devise' => 'BZD', 'libelle' => 'Dollar de Belize',              'symbole' => 'BZ$'],
    // Asie - Extrême-Orient
    ['code_devise' => 'JPY', 'libelle' => 'Yen japonais',                  'symbole' => '¥'],
    ['code_devise' => 'CNY', 'libelle' => 'Yuan renminbi (Chine)',         'symbole' => '¥'],
    ['code_devise' => 'HKD', 'libelle' => 'Dollar de Hong Kong',           'symbole' => 'HK$'],
    ['code_devise' => 'KRW', 'libelle' => 'Won sud-coréen',                'symbole' => '₩'],
    ['code_devise' => 'TWD', 'libelle' => 'Nouveau dollar taïwanais',      'symbole' => 'NT$'],
    ['code_devise' => 'MNT', 'libelle' => 'Tögrög mongol',                 'symbole' => '₮'],
    // Asie - Sud-Est
    ['code_devise' => 'SGD', 'libelle' => 'Dollar de Singapour',           'symbole' => 'S$'],
    ['code_devise' => 'IDR', 'libelle' => 'Roupiah indonésienne',          'symbole' => 'Rp'],
    ['code_devise' => 'MYR', 'libelle' => 'Ringgit malaisien',             'symbole' => 'RM'],
    ['code_devise' => 'THB', 'libelle' => 'Baht thaïlandais',              'symbole' => '฿'],
    ['code_devise' => 'PHP', 'libelle' => 'Peso philippin',                'symbole' => '₱'],
    ['code_devise' => 'VND', 'libelle' => 'Dong vietnamien',               'symbole' => '₫'],
    ['code_devise' => 'KHR', 'libelle' => 'Riel cambodgien',               'symbole' => '៛'],
    ['code_devise' => 'MMK', 'libelle' => 'Kyat birman',                   'symbole' => 'K'],
    ['code_devise' => 'LAK', 'libelle' => 'Kip laotien',                   'symbole' => '₭'],
    // Asie - Sud
    ['code_devise' => 'INR', 'libelle' => 'Roupie indienne',               'symbole' => '₹'],
    ['code_devise' => 'PKR', 'libelle' => 'Roupie pakistanaise',           'symbole' => '₨'],
    ['code_devise' => 'BDT', 'libelle' => 'Taka bangladais',               'symbole' => '৳'],
    ['code_devise' => 'LKR', 'libelle' => 'Roupie sri-lankaise',           'symbole' => 'Rs'],
    ['code_devise' => 'NPR', 'libelle' => 'Roupie népalaise',              'symbole' => '₨'],
    ['code_devise' => 'MVR', 'libelle' => 'Rufiyaa maldivien',             'symbole' => 'Rf'],
    ['code_devise' => 'BTN', 'libelle' => 'Ngultrum bhoutanais',           'symbole' => 'Nu'],
    // Asie centrale
    ['code_devise' => 'KZT', 'libelle' => 'Tenge kazakh',                  'symbole' => '₸'],
    ['code_devise' => 'UZS', 'libelle' => 'Som ouzbek',                    'symbole' => 'UZS'],
    ['code_devise' => 'KGS', 'libelle' => 'Som kirghiz',                   'symbole' => 'KGS'],
    ['code_devise' => 'TJS', 'libelle' => 'Somoni tadjik',                 'symbole' => 'SM'],
    ['code_devise' => 'TMT', 'libelle' => 'Manat turkmène',                'symbole' => 'T'],
    ['code_devise' => 'AFN', 'libelle' => 'Afghani afghan',                'symbole' => '؋'],
    // Moyen-Orient
    ['code_devise' => 'AED', 'libelle' => 'Dirham des Émirats arabes unis','symbole' => 'AED'],
    ['code_devise' => 'SAR', 'libelle' => 'Riyal saoudien',                'symbole' => 'SAR'],
    ['code_devise' => 'QAR', 'libelle' => 'Riyal qatarien',                'symbole' => 'QAR'],
    ['code_devise' => 'KWD', 'libelle' => 'Dinar koweïtien',               'symbole' => 'KWD'],
    ['code_devise' => 'BHD', 'libelle' => 'Dinar bahreïni',                'symbole' => 'BHD'],
    ['code_devise' => 'OMR', 'libelle' => 'Rial omanais',                  'symbole' => 'OMR'],
    ['code_devise' => 'JOD', 'libelle' => 'Dinar jordanien',               'symbole' => 'JOD'],
    ['code_devise' => 'ILS', 'libelle' => 'Shekel israélien',              'symbole' => '₪'],
    ['code_devise' => 'LBP', 'libelle' => 'Livre libanaise',               'symbole' => 'LL'],
    ['code_devise' => 'IQD', 'libelle' => 'Dinar irakien',                 'symbole' => 'IQD'],
    ['code_devise' => 'IRR', 'libelle' => 'Rial iranien',                  'symbole' => '﷼'],
    ['code_devise' => 'SYP', 'libelle' => 'Livre syrienne',                'symbole' => 'SYP'],
    ['code_devise' => 'YER', 'libelle' => 'Rial yéménite',                 'symbole' => '﷼'],
    // Océanie
    ['code_devise' => 'AUD', 'libelle' => 'Dollar australien',             'symbole' => 'A$'],
    ['code_devise' => 'NZD', 'libelle' => 'Dollar néo-zélandais',          'symbole' => 'NZ$'],
    ['code_devise' => 'FJD', 'libelle' => 'Dollar fidjien',                'symbole' => 'FJ$'],
    ['code_devise' => 'PGK', 'libelle' => 'Kina de Papouasie-Nvelle-Guinée','symbole' => 'K'],
    ['code_devise' => 'WST', 'libelle' => 'Tala samoan',                   'symbole' => 'WS$'],
    ['code_devise' => 'TOP', 'libelle' => "Pa'anga tongien",               'symbole' => 'T$'],
    ['code_devise' => 'SBD', 'libelle' => 'Dollar des Îles Salomon',       'symbole' => 'SI$'],
    ['code_devise' => 'VUV', 'libelle' => 'Vatu vanuatais',                'symbole' => 'VT'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration initiale - ComptaOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #0f2557 0%, #1a3a8f 40%, #1565c0 70%, #0d47a1 100%); min-height: 100vh; }

        .step-panel { display: none; }
        .step-panel.active { display: block; animation: fadeUp .35s ease both; }

        @keyframes fadeUp {
            from { opacity:0; transform:translateY(20px); }
            to   { opacity:1; transform:translateY(0); }
        }

        .input-field {
            width: 100%;
            padding: .55rem .85rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: .875rem;
            color: #1e293b;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: #fff;
        }
        .input-field:focus {
            border-color: #1565c0;
            box-shadow: 0 0 0 3px rgba(21,101,192,.12);
        }
        .input-field.error { border-color: #ef4444; }

        .step-dot {
            width: 2rem; height: 2rem;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .75rem; font-weight: 700;
            transition: all .3s;
        }
        .step-dot.done       { background: #10b981; color: #fff; }
        .step-dot.active     { background: #1565c0; color: #fff; box-shadow: 0 0 0 4px rgba(21,101,192,.3); }
        .step-dot.pending    { background: #334155; color: #94a3b8; }
        .step-line           { flex: 1; height: 2px; transition: background .3s; }
        .step-line.done      { background: #10b981; }
        .step-line.pending   { background: #334155; }

        .btn-primary {
            background: #1565c0; color: #fff;
            padding: .65rem 1.5rem; border-radius: 8px;
            font-weight: 600; font-size: .875rem;
            border: none; cursor: pointer;
            transition: background .2s, transform .1s;
        }
        .btn-primary:hover  { background: #0d47a1; }
        .btn-primary:active { transform: scale(.98); }
        .btn-secondary {
            background: #334155; color: #cbd5e1;
            padding: .65rem 1.5rem; border-radius: 8px;
            font-weight: 600; font-size: .875rem;
            border: none; cursor: pointer;
            transition: background .2s;
        }
        .btn-secondary:hover { background: #475569; }

        .radio-card {
            border: 2px solid #e2e8f0; border-radius: 10px;
            padding: .75rem 1rem; cursor: pointer;
            transition: all .2s; display: flex; align-items: center; gap: .75rem;
        }
        .radio-card:hover { border-color: #93c5fd; background: #eff6ff; }
        .radio-card input:checked + .radio-card,
        .radio-card.selected { border-color: #1565c0; background: #eff6ff; }

        .taille-card {
            border: 2px solid #e2e8f0; border-radius: 10px;
            padding: 1rem; cursor: pointer;
            transition: all .2s; text-align: center;
        }
        .taille-card:hover    { border-color: #93c5fd; }
        .taille-card.selected { border-color: #1565c0; background: #eff6ff; }

        .recap-row { display: flex; justify-content: space-between; padding: .5rem 0; border-bottom: 1px solid #f1f5f9; font-size: .875rem; }
        .recap-row:last-child { border-bottom: none; }
        .recap-label { color: #64748b; }
        .recap-value { font-weight: 600; color: #1e293b; }

        .mode-card {
            border: 2px solid #e2e8f0; border-radius: 12px;
            padding: 1rem; cursor: pointer;
            transition: all .2s;
        }
        .mode-card:hover { border-color: #93c5fd; background: #f8faff; }
        .mode-card.selected { border-color: #1565c0; background: #eff6ff; }
        .mode-card.selected-cabinet { border-color: #7c3aed; background: #f5f3ff; }

        .longueur-btn {
            padding: .45rem 1rem; border-radius: 8px; border: 1.5px solid #e2e8f0;
            font-size: .8rem; font-weight: 600; color: #64748b;
            background: #f8fafc; cursor: pointer; transition: all .2s;
        }
        .longueur-btn:hover { border-color: #93c5fd; color: #1565c0; background: #eff6ff; }
        .longueur-btn-active { border-color: #1565c0 !important; color: #1565c0 !important; background: #eff6ff !important; }
    </style>
</head>
<body class="flex flex-col min-h-screen">

<?php if ($succes): ?>
<!-- SUCCES -->
<div class="flex-1 flex items-center justify-center px-4">
    <div class="bg-white rounded-2xl shadow-2xl p-10 max-w-md w-full text-center">
        <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fas fa-check-circle text-emerald-500 text-4xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800 mb-2">Installation réussie !</h1>
        <p class="text-slate-500 text-sm mb-6">Votre ERP OHADA est prêt. Vous allez être redirigé vers le tableau de bord.</p>
        <div class="w-full bg-slate-100 rounded-full h-1.5 mb-6 overflow-hidden">
            <div class="bg-emerald-500 h-1.5 rounded-full animate-pulse" style="width:100%"></div>
        </div>
        <a href="../index.php" class="btn-primary inline-block">
            <i class="fas fa-rocket mr-2"></i>Accéder au tableau de bord
        </a>
    </div>
</div>
<script>setTimeout(() => window.location.href = '../index.php', 3000);</script>

<?php else: ?>

<!-- HEADER -->
<header class="px-8 py-5 flex items-center gap-3">
    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow">
        <i class="fas fa-calculator text-blue-700 text-lg"></i>
    </div>
    <div>
        <span class="text-white font-bold text-lg">ComptaOHADA</span>
        <span class="text-blue-300 text-xs ml-2">ERP SYSCOHADA Révisé</span>
    </div>
</header>

<main class="flex-1 flex items-start justify-center px-4 pb-10">
<div class="w-full max-w-2xl">

    <!-- Titre -->
    <div class="text-center mb-8">
        <h1 class="text-white text-2xl font-bold">Configuration initiale</h1>
        <p class="text-blue-200 text-sm mt-1">Suivez les étapes pour démarrer votre ERP en quelques minutes</p>
    </div>

    <!-- Stepper -->
    <div class="flex items-center mb-8 px-2" id="stepper">
        <?php
        $steps = ['Bienvenue', 'Société', 'Exercice', 'Compte', 'Préférences', 'Récap'];
        foreach ($steps as $i => $label):
            $n = $i + 1;
        ?>
        <?php if ($i > 0): ?>
        <div class="step-line <?= $i === 0 ? 'done' : 'pending' ?>" id="line-<?= $i ?>"></div>
        <?php endif; ?>
        <div class="flex flex-col items-center gap-1">
            <div class="step-dot <?= $n === 1 ? 'active' : 'pending' ?>" id="dot-<?= $n ?>">
                <span id="dot-icon-<?= $n ?>"><?= $n ?></span>
            </div>
            <span class="text-xs text-blue-200 whitespace-nowrap hidden sm:block"><?= $label ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">

        <?php if (!empty($erreurs)): ?>
        <div class="bg-red-50 border-b border-red-200 px-6 py-4">
            <p class="text-red-700 font-semibold text-sm flex items-center gap-2 mb-2">
                <i class="fas fa-exclamation-circle"></i> Veuillez corriger les erreurs suivantes :
            </p>
            <ul class="list-disc list-inside space-y-1">
                <?php foreach ($erreurs as $e): ?>
                <li class="text-red-600 text-sm"><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" id="setupForm">

        <!-- ===== ETAPE 1 : BIENVENUE ===== -->
        <div class="step-panel active" id="step-1">
            <div class="p-8">
                <div class="text-center mb-7">
                    <div class="w-20 h-20 bg-blue-50 rounded-2xl flex items-center justify-center mx-auto mb-5">
                        <i class="fas fa-rocket text-blue-600 text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-slate-800 mb-2">Bienvenue dans ComptaOHADA</h2>
                    <p class="text-slate-500 text-sm max-w-md mx-auto leading-relaxed">
                        Le premier ERP conçu pour les normes <strong>SYSCOHADA Révisé</strong>.
                        Commencez par choisir votre mode d'utilisation.
                    </p>
                </div>

                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider text-center mb-4">Comment allez-vous utiliser ComptaOHADA ?</p>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 mb-7">
                    <!-- Mode Entreprise -->
                    <div class="mode-card selected" id="mode-entreprise" onclick="selectMode('entreprise')">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-building text-blue-600 text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="font-bold text-slate-800 text-sm">Mode Entreprise</p>
                                    <i class="fas fa-check-circle text-blue-600 text-lg mode-check" id="check-entreprise"></i>
                                </div>
                                <p class="text-xs text-slate-500 leading-relaxed">
                                    Gérez la comptabilité de votre propre entreprise ou d'un groupe de sociétés (filiales, holding).
                                </p>
                                <div class="flex flex-wrap gap-1 mt-2">
                                    <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full">PME</span>
                                    <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full">Holding</span>
                                    <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full">Groupe</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mode Cabinet -->
                    <div class="mode-card" id="mode-cabinet" onclick="selectMode('cabinet')">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 bg-violet-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-briefcase text-violet-600 text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="font-bold text-slate-800 text-sm">Mode Cabinet</p>
                                    <i class="far fa-circle text-slate-300 text-lg mode-check" id="check-cabinet"></i>
                                </div>
                                <p class="text-xs text-slate-500 leading-relaxed">
                                    Gérez la comptabilité de plusieurs clients depuis un seul espace - vue portefeuille, dossiers et collaborateurs.
                                </p>
                                <div class="flex flex-wrap gap-1 mt-2">
                                    <span class="text-xs bg-violet-50 text-violet-600 px-2 py-0.5 rounded-full">Expertise comptable</span>
                                    <span class="text-xs bg-violet-50 text-violet-600 px-2 py-0.5 rounded-full">Fiduciaire</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="mode" id="mode_input" value="entreprise">

                <div class="text-center">
                    <button type="button" onclick="goTo(2)" class="btn-primary">
                        <i class="fas fa-arrow-right mr-2"></i>Commencer la configuration
                    </button>
                </div>
            </div>
        </div>

        <!-- ===== ETAPE 2 : SOCIETE / CABINET ===== -->
        <div class="step-panel" id="step-2">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="w-7 h-7 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold">2</span>
                    <span id="step2-title">Informations de la société</span>
                </h2>
                <p class="text-slate-400 text-xs mt-1 ml-9" id="step2-subtitle">Renseignez les informations légales de votre entreprise</p>
            </div>
            <div class="p-6 space-y-4">

                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Raison sociale <span class="text-red-500">*</span></label>
                        <input type="text" name="raison_sociale" class="input-field" placeholder="Ex: ACME Côte d'Ivoire SARL"
                               value="<?= htmlspecialchars($_POST['raison_sociale'] ?? '') ?>" id="f_raison_sociale">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Code société <span class="text-red-500">*</span></label>
                        <input type="text" name="code_societe" class="input-field" placeholder="Ex: ACME01"
                               value="<?= htmlspecialchars($_POST['code_societe'] ?? '') ?>" id="f_code_societe"
                               style="text-transform:uppercase">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Forme juridique</label>
                        <select name="forme_juridique" class="input-field">
                            <option value="">- Sélectionner -</option>
                            <?php foreach (['SARL','SA','SAS','SNC','GIE','Entreprise individuelle','Établissement public','ONG / Association'] as $f): ?>
                            <option value="<?= $f ?>" <?= ($_POST['forme_juridique'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Secteur d'activité</label>
                        <select name="secteur_activite" class="input-field">
                            <option value="">- Sélectionner -</option>
                            <?php foreach ([
                                'Agriculture, Élevage et Pêche',
                                'Mines et Carrières',
                                'Énergie et Eau',
                                'Industrie manufacturière',
                                'BTP (Bâtiment et Travaux Publics)',
                                'Commerce de gros',
                                'Commerce de détail',
                                'Transport et Logistique',
                                'Hôtellerie et Restauration',
                                'Informatique et Télécommunications',
                                'Services financiers et Assurance',
                                'Immobilier',
                                'Conseil et Expertise',
                                'Santé et Action sociale',
                                'Éducation et Formation',
                                'Médias et Communication',
                                'ONG / Association',
                                'Administration publique',
                                'Autre',
                            ] as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= ($_POST['secteur_activite'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Capital social</label>
                        <input type="text" name="capital" class="input-field" placeholder="Ex: 1 000 000"
                               value="<?= htmlspecialchars($_POST['capital'] ?? '') ?>"
                               inputmode="numeric">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Numéro RCCM</label>
                        <input type="text" name="numero_rccm" class="input-field" placeholder="CI-ABJ-2024-B-XXXXX"
                               value="<?= htmlspecialchars($_POST['numero_rccm'] ?? '') ?>">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Numéro contribuable (NCC)</label>
                        <input type="text" name="numero_contribuable" class="input-field" placeholder="XXXXXXXXXX"
                               value="<?= htmlspecialchars($_POST['numero_contribuable'] ?? '') ?>">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Régime fiscal</label>
                        <select name="regime_fiscal" class="input-field">
                            <?php foreach ([
                                'reel_normal'        => 'Réel normal',
                                'reel_simplifie'     => 'Réel simplifié',
                                'microentreprise'    => 'Micro-entreprise',
                                'taxe_entreprenant'  => "Taxe de l'entreprenant",
                                'aucun'              => 'Aucun',
                            ] as $val => $lab): ?>
                            <option value="<?= $val ?>" <?= ($_POST['regime_fiscal'] ?? 'reel_normal') === $val ? 'selected' : '' ?>><?= $lab ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Devise principale <span class="text-red-500">*</span></label>
                        <select name="devise_principale" class="input-field">
                            <?php foreach ($devises as $d): ?>
                            <option value="<?= $d['code_devise'] ?>" <?= ($d['code_devise'] === ($_POST['devise_principale'] ?? 'XOF')) ? 'selected' : '' ?>>
                                <?= $d['code_devise'] ?> - <?= htmlspecialchars($d['libelle']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Adresse</label>
                        <input type="text" name="adresse" class="input-field" placeholder="Rue, quartier, BP..."
                               value="<?= htmlspecialchars($_POST['adresse'] ?? '') ?>">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Ville</label>
                        <input type="text" name="ville" class="input-field" placeholder="Abidjan"
                               value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Pays</label>
                        <select name="pays" class="input-field">
                            <?php
                            $paysList = [
                                'Afghanistan','Afrique du Sud','Albanie','Algérie','Allemagne','Andorre','Angola',
                                'Antigua-et-Barbuda','Arabie saoudite','Argentine','Arménie','Australie','Autriche',
                                'Azerbaïdjan','Bahamas','Bahreïn','Bangladesh','Barbade','Bélarus','Belgique','Belize',
                                'Bénin','Bhoutan','Bolivie','Bosnie-Herzégovine','Botswana','Brésil','Brunéi',
                                'Bulgarie','Burkina Faso','Burundi','Cabo Verde','Cambodge','Cameroun','Canada',
                                'République centrafricaine','Chili','Chine','Chypre','Colombie','Comores',
                                'Congo (République du)','Congo (RDC)','Corée du Nord','Corée du Sud','Costa Rica',
                                "Côte d'Ivoire",'Croatie','Cuba','Danemark','Djibouti','Dominique','Égypte',
                                'Émirats arabes unis','Équateur','Érythrée','Espagne','Estonie','Eswatini',
                                'États-Unis','Éthiopie','Fidji','Finlande','France','Gabon','Gambie','Géorgie',
                                'Ghana','Grèce','Grenade','Guatemala','Guinée','Guinée-Bissau','Guinée équatoriale',
                                'Guyana','Haïti','Honduras','Hongrie','Îles Cook','Îles Marshall','Îles Salomon',
                                'Inde','Indonésie','Irak','Iran','Irlande','Islande','Israël','Italie','Jamaïque',
                                'Japon','Jordanie','Kazakhstan','Kenya','Kirghizistan','Kiribati','Kosovo','Koweït',
                                'Laos','Lesotho','Lettonie','Liban','Libéria','Libye','Liechtenstein','Lituanie',
                                'Luxembourg','Macédoine du Nord','Madagascar','Malaisie','Malawi','Maldives',
                                'Mali','Malte','Maroc','Maurice','Mauritanie','Mexique','Micronésie','Moldavie',
                                'Monaco','Mongolie','Monténégro','Mozambique','Myanmar','Namibie','Nauru',
                                'Népal','Nicaragua','Niger','Nigeria','Niue','Norvège','Nouvelle-Zélande','Oman',
                                'Ouganda','Ouzbékistan','Pakistan','Palaos','Palestine','Panama',
                                'Papouasie-Nouvelle-Guinée','Paraguay','Pays-Bas','Pérou','Philippines','Pologne',
                                'Portugal','Qatar','République dominicaine','République tchèque','Roumanie',
                                'Royaume-Uni','Russie','Rwanda','Saint-Christophe-et-Niévès','Saint-Marin',
                                'Saint-Vincent-et-les-Grenadines','Sainte-Lucie','Salvador','Samoa',
                                'São Tomé-et-Príncipe','Sénégal','Serbie','Seychelles','Sierra Leone',
                                'Singapour','Slovaquie','Slovénie','Somalie','Soudan','Soudan du Sud',
                                'Sri Lanka','Suède','Suisse','Suriname','Syrie','Tadjikistan','Tanzanie',
                                'Tchad','Thaïlande','Timor oriental','Togo','Tonga','Trinité-et-Tobago',
                                'Tunisie','Turkménistan','Turquie','Tuvalu','Ukraine','Uruguay','Vanuatu',
                                'Vatican','Venezuela','Viêt Nam','Yémen','Zambie','Zimbabwe',
                            ];
                            $paysSel = $_POST['pays'] ?? "Côte d'Ivoire";
                            foreach ($paysList as $p):
                            ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $paysSel === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Téléphone</label>
                        <input type="tel" name="telephone" class="input-field" placeholder="+225 XX XX XX XX XX"
                               value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Email société</label>
                        <input type="email" name="email_societe" class="input-field" placeholder="contact@societe.com"
                               value="<?= htmlspecialchars($_POST['email_societe'] ?? '') ?>">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Site web</label>
                        <input type="url" name="site_web" class="input-field" placeholder="https://www.societe.com"
                               value="<?= htmlspecialchars($_POST['site_web'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-between">
                <button type="button" onclick="goTo(1)" class="btn-secondary"><i class="fas fa-arrow-left mr-2"></i>Retour</button>
                <button type="button" onclick="validateStep2()" class="btn-primary">Suivant<i class="fas fa-arrow-right ml-2"></i></button>
            </div>
        </div>

        <!-- ===== ETAPE 3 : EXERCICE ===== -->
        <div class="step-panel" id="step-3">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="w-7 h-7 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold">3</span>
                    Exercice comptable
                </h2>
                <p class="text-slate-400 text-xs mt-1 ml-9">Définissez votre premier exercice comptable</p>
            </div>
            <div class="p-6 space-y-5">

                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3">
                    <i class="fas fa-info-circle text-blue-500 mt-0.5 flex-shrink-0"></i>
                    <p class="text-blue-700 text-xs leading-relaxed">
                        L'exercice comptable correspond généralement à l'année civile (1er janvier au 31 décembre).
                        Vous pourrez créer et gérer d'autres exercices ultérieurement depuis les paramètres.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Date de début <span class="text-red-500">*</span></label>
                        <input type="date" name="exercice_debut" id="f_exercice_debut" class="input-field"
                               value="<?= htmlspecialchars($_POST['exercice_debut'] ?? date('Y-01-01')) ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Date de fin <span class="text-red-500">*</span></label>
                        <input type="date" name="exercice_fin" id="f_exercice_fin" class="input-field"
                               value="<?= htmlspecialchars($_POST['exercice_fin'] ?? date('Y-12-31')) ?>">
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-3">Référentiel comptable</p>
                    <label class="radio-card mb-3 cursor-pointer" onclick="selectCard(this,'plan_type'); updateLongueurInfo()">
                        <input type="radio" name="plan_type" value="ohada" class="hidden" checked>
                        <div class="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-book text-blue-600 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Plan SYSCOHADA Révisé (recommandé)</p>
                            <p class="text-xs text-slate-400">Import automatique du plan comptable OHADA complet</p>
                        </div>
                        <i class="fas fa-check-circle text-blue-600 ml-auto text-lg check-icon"></i>
                    </label>
                    <label class="radio-card cursor-pointer" onclick="selectCard(this,'plan_type'); updateLongueurInfo()">
                        <input type="radio" name="plan_type" value="vide" class="hidden">
                        <div class="w-9 h-9 bg-slate-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-file text-slate-400 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Démarrer avec un plan vide</p>
                            <p class="text-xs text-slate-400">Vous saisirez manuellement les comptes</p>
                        </div>
                        <i class="fas fa-circle text-slate-300 ml-auto text-lg check-icon"></i>
                    </label>
                </div>

                <!-- Longueur des numéros de compte -->
                <div class="border-t border-slate-100 pt-4">
                    <p class="text-xs font-semibold text-slate-600 mb-1">Longueur des numéros de compte</p>
                    <p class="text-xs text-slate-400 mb-3">Ce choix est définitif - il ne pourra pas être modifié après l'installation.</p>

                    <div class="flex flex-wrap gap-2 mb-3" id="longueur-btns">
                        <?php foreach ([4,5,6,7,8,9] as $n): ?>
                        <button type="button"
                                onclick="selectLongueur(<?= $n ?>)"
                                id="lon-<?= $n ?>"
                                class="longueur-btn <?= $n === 4 ? 'longueur-btn-active' : '' ?>">
                            <?= $n ?> chiffres<?= $n === 4 ? ' <span class="text-xs opacity-70">(standard)</span>' : '' ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="longueur_compte" id="longueur_compte" value="4">

                    <div id="longueur-info" class="rounded-xl p-3.5 flex gap-2.5 text-xs leading-relaxed"></div>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-between">
                <button type="button" onclick="goTo(2)" class="btn-secondary"><i class="fas fa-arrow-left mr-2"></i>Retour</button>
                <button type="button" onclick="goTo(4)" class="btn-primary">Suivant<i class="fas fa-arrow-right ml-2"></i></button>
            </div>
        </div>

        <!-- ===== ETAPE 4 : COMPTE ADMIN ===== -->
        <div class="step-panel" id="step-4">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="w-7 h-7 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold">4</span>
                    Compte administrateur
                </h2>
                <p class="text-slate-400 text-xs mt-1 ml-9">Ce compte aura tous les droits sur l'application</p>
            </div>
            <div class="p-6 space-y-4">

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5">Nom complet <span class="text-red-500">*</span></label>
                    <input type="text" name="nom_utilisateur" id="f_nom_utilisateur" class="input-field" placeholder="Jean Konan"
                           value="<?= htmlspecialchars($_POST['nom_utilisateur'] ?? '') ?>">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5">Adresse email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" id="f_email" class="input-field" placeholder="admin@societe.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <p class="text-slate-400 text-xs mt-1">Utilisé pour la connexion et la récupération de compte</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5">Mot de passe <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" name="password" id="f_password" class="input-field pr-10" placeholder="Minimum 8 caractères">
                        <button type="button" onclick="togglePwd('f_password','eye1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <i class="fas fa-eye text-sm" id="eye1"></i>
                        </button>
                    </div>
                    <div id="pwd-strength" class="mt-2 hidden">
                        <div class="flex gap-1 mb-1">
                            <div class="h-1 flex-1 rounded-full bg-slate-200" id="s1"></div>
                            <div class="h-1 flex-1 rounded-full bg-slate-200" id="s2"></div>
                            <div class="h-1 flex-1 rounded-full bg-slate-200" id="s3"></div>
                            <div class="h-1 flex-1 rounded-full bg-slate-200" id="s4"></div>
                        </div>
                        <p class="text-xs text-slate-400" id="pwd-label"></p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5">Confirmer le mot de passe <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" name="password_confirm" id="f_password_confirm" class="input-field pr-10" placeholder="Répétez le mot de passe">
                        <button type="button" onclick="togglePwd('f_password_confirm','eye2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <i class="fas fa-eye text-sm" id="eye2"></i>
                        </button>
                    </div>
                    <p id="pwd-match" class="text-xs mt-1 hidden"></p>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-between">
                <button type="button" onclick="goTo(3)" class="btn-secondary"><i class="fas fa-arrow-left mr-2"></i>Retour</button>
                <button type="button" onclick="validateStep4()" class="btn-primary">Suivant<i class="fas fa-arrow-right ml-2"></i></button>
            </div>
        </div>

        <!-- ===== ETAPE 5 : PREFERENCES ===== -->
        <div class="step-panel" id="step-5">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="w-7 h-7 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold">5</span>
                    Préférences d'affichage
                </h2>
                <p class="text-slate-400 text-xs mt-1 ml-9">Personnalisez l'interface selon vos préférences</p>
            </div>
            <div class="p-6 space-y-6">

                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-3">Taille de la police</p>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="taille-card" onclick="selectTaille('petit')" id="taille-petit">
                            <p class="text-xs font-semibold text-slate-700 mb-1">Aa</p>
                            <p class="text-xs text-slate-500">Petit</p>
                            <p class="text-slate-400" style="font-size:10px">Texte réduit</p>
                        </div>
                        <div class="taille-card selected" onclick="selectTaille('normal')" id="taille-normal">
                            <p class="text-sm font-semibold text-slate-700 mb-1">Aa</p>
                            <p class="text-xs text-slate-500">Normal</p>
                            <p class="text-slate-400" style="font-size:11px">Recommandé</p>
                        </div>
                        <div class="taille-card" onclick="selectTaille('grand')" id="taille-grand">
                            <p class="text-base font-semibold text-slate-700 mb-1">Aa</p>
                            <p class="text-xs text-slate-500">Grand</p>
                            <p class="text-slate-400" style="font-size:12px">Plus lisible</p>
                        </div>
                    </div>
                    <input type="hidden" name="taille_police" id="taille_police" value="normal">
                </div>

                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-3">Fuseau horaire</p>
                    <select name="timezone" class="input-field">
                        <?php foreach ([
                            'Africa/Abidjan'     => 'Côte d\'Ivoire (GMT+0)',
                            'Africa/Dakar'       => 'Sénégal (GMT+0)',
                            'Africa/Douala'      => 'Cameroun (GMT+1)',
                            'Africa/Lagos'       => 'Nigeria (GMT+1)',
                            'Africa/Nairobi'     => 'Kenya (GMT+3)',
                            'Africa/Casablanca'  => 'Maroc (GMT+1)',
                            'Europe/Paris'       => 'France (GMT+1/+2)',
                        ] as $tz => $label): ?>
                        <option value="<?= $tz ?>" <?= ($_POST['timezone'] ?? 'Africa/Abidjan') === $tz ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-between">
                <button type="button" onclick="goTo(4)" class="btn-secondary"><i class="fas fa-arrow-left mr-2"></i>Retour</button>
                <button type="button" onclick="buildRecap(); goTo(6)" class="btn-primary">Suivant<i class="fas fa-arrow-right ml-2"></i></button>
            </div>
        </div>

        <!-- ===== ETAPE 6 : RECAPITULATIF ===== -->
        <div class="step-panel" id="step-6">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="w-7 h-7 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold">6</span>
                    Récapitulatif
                </h2>
                <p class="text-slate-400 text-xs mt-1 ml-9">Vérifiez vos informations avant de finaliser</p>
            </div>
            <div class="p-6 space-y-5">

                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">
                        <i class="fas fa-building mr-1.5"></i>Société
                    </p>
                    <div id="recap-societe"></div>
                </div>

                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">
                        <i class="fas fa-calendar mr-1.5"></i>Exercice comptable
                    </p>
                    <div id="recap-exercice"></div>
                </div>

                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">
                        <i class="fas fa-user-shield mr-1.5"></i>Administrateur
                    </p>
                    <div id="recap-admin"></div>
                </div>

                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex gap-3">
                    <i class="fas fa-shield-alt text-emerald-500 mt-0.5"></i>
                    <p class="text-emerald-700 text-xs leading-relaxed">
                        Toutes ces informations pourront être modifiées ultérieurement dans les <strong>Paramètres</strong>.
                        Cliquez sur "Finaliser" pour lancer votre ERP.
                    </p>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-between items-center">
                <button type="button" onclick="goTo(5)" class="btn-secondary"><i class="fas fa-arrow-left mr-2"></i>Retour</button>
                <button type="submit" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
                    <i class="fas fa-rocket mr-2"></i>Finaliser l'installation
                </button>
            </div>
        </div>

        </form>
    </div><!-- /card -->
</div>
</main>

<footer class="text-center py-4">
    <p class="text-blue-300 text-xs">ComptaOHADA - ERP SYSCOHADA Révisé - Côte d'Ivoire</p>
</footer>

<?php endif; ?>

<script>
let currentStep = 1;
const totalSteps = 6;
let currentMode = 'entreprise';

function selectMode(mode) {
    currentMode = mode;
    document.getElementById('mode_input').value = mode;

    const cardE = document.getElementById('mode-entreprise');
    const cardC = document.getElementById('mode-cabinet');
    const chkE  = document.getElementById('check-entreprise');
    const chkC  = document.getElementById('check-cabinet');

    if (mode === 'entreprise') {
        cardE.className = 'mode-card selected';
        cardC.className = 'mode-card';
        chkE.className  = 'fas fa-check-circle text-blue-600 text-lg mode-check';
        chkC.className  = 'far fa-circle text-slate-300 text-lg mode-check';
        document.getElementById('step2-title').textContent    = 'Informations de la société';
        document.getElementById('step2-subtitle').textContent = 'Renseignez les informations légales de votre entreprise';
    } else {
        cardC.className = 'mode-card selected-cabinet';
        cardE.className = 'mode-card';
        chkC.className  = 'fas fa-check-circle text-violet-600 text-lg mode-check';
        chkE.className  = 'far fa-circle text-slate-300 text-lg mode-check';
        document.getElementById('step2-title').textContent    = 'Informations du cabinet';
        document.getElementById('step2-subtitle').textContent = "Renseignez les informations légales de votre cabinet d'expertise";
    }
}

function goTo(step) {
    document.getElementById('step-' + currentStep).classList.remove('active');
    currentStep = step;
    document.getElementById('step-' + step).classList.add('active');
    updateStepper();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateStepper() {
    for (let i = 1; i <= totalSteps; i++) {
        const dot  = document.getElementById('dot-' + i);
        const icon = document.getElementById('dot-icon-' + i);
        dot.className = 'step-dot ' + (i < currentStep ? 'done' : i === currentStep ? 'active' : 'pending');
        icon.innerHTML = i < currentStep ? '<i class="fas fa-check text-xs"></i>' : i;
    }
    for (let i = 1; i < totalSteps; i++) {
        const line = document.getElementById('line-' + i);
        if (line) line.className = 'step-line ' + (i < currentStep ? 'done' : 'pending');
    }
}

function validateStep2() {
    const raison = document.getElementById('f_raison_sociale').value.trim();
    const code   = document.getElementById('f_code_societe').value.trim();
    let ok = true;

    [document.getElementById('f_raison_sociale'), document.getElementById('f_code_societe')].forEach(f => {
        f.classList.remove('error');
    });

    if (!raison) { document.getElementById('f_raison_sociale').classList.add('error'); ok = false; }
    if (!code)   { document.getElementById('f_code_societe').classList.add('error');   ok = false; }

    if (!ok) { alert('Veuillez renseigner la raison sociale et le code société.'); return; }
    goTo(3);
}

function validateStep4() {
    const nom   = document.getElementById('f_nom_utilisateur').value.trim();
    const email = document.getElementById('f_email').value.trim();
    const pwd   = document.getElementById('f_password').value;
    const pwd2  = document.getElementById('f_password_confirm').value;
    let ok = true, msg = [];

    [document.getElementById('f_nom_utilisateur'), document.getElementById('f_email'),
     document.getElementById('f_password'), document.getElementById('f_password_confirm')].forEach(f => f.classList.remove('error'));

    if (!nom)               { document.getElementById('f_nom_utilisateur').classList.add('error'); msg.push('Nom obligatoire'); ok = false; }
    if (!email || !email.includes('@')) { document.getElementById('f_email').classList.add('error'); msg.push('Email invalide'); ok = false; }
    if (pwd.length < 8)     { document.getElementById('f_password').classList.add('error'); msg.push('Mot de passe trop court (min. 8 caractères)'); ok = false; }
    if (pwd !== pwd2)       { document.getElementById('f_password_confirm').classList.add('error'); msg.push('Les mots de passe ne correspondent pas'); ok = false; }

    if (!ok) { alert(msg.join('\n')); return; }
    goTo(5);
}

// Force du mot de passe
// Initialisation de l'info longueur au chargement
updateLongueurInfo();

document.getElementById('f_password').addEventListener('input', function() {
    const pwd = this.value;
    const bar = document.getElementById('pwd-strength');
    bar.classList.toggle('hidden', pwd.length === 0);
    const score = [/.{8,}/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter(r => r.test(pwd)).length;
    const colors = ['bg-red-400','bg-orange-400','bg-yellow-400','bg-emerald-500'];
    const labels = ['Très faible','Faible','Moyen','Fort'];
    for (let i = 1; i <= 4; i++) {
        const el = document.getElementById('s' + i);
        el.className = 'h-1 flex-1 rounded-full ' + (i <= score ? colors[score - 1] : 'bg-slate-200');
    }
    document.getElementById('pwd-label').textContent = score > 0 ? labels[score - 1] : '';
});

document.getElementById('f_password_confirm').addEventListener('input', function() {
    const pwd = document.getElementById('f_password').value;
    const el  = document.getElementById('pwd-match');
    el.classList.remove('hidden');
    if (this.value === pwd) {
        el.className = 'text-xs mt-1 text-emerald-600';
        el.textContent = '✓ Les mots de passe correspondent';
    } else {
        el.className = 'text-xs mt-1 text-red-500';
        el.textContent = '✗ Les mots de passe ne correspondent pas';
    }
});

function togglePwd(fieldId, eyeId) {
    const f = document.getElementById(fieldId);
    const e = document.getElementById(eyeId);
    f.type = f.type === 'password' ? 'text' : 'password';
    e.classList.toggle('fa-eye');
    e.classList.toggle('fa-eye-slash');
}

// Longueur des comptes
var currentLongueur = 4;

function selectLongueur(n) {
    currentLongueur = n;
    document.getElementById('longueur_compte').value = n;
    var ids = [4, 5, 6, 7, 8, 9];
    for (var k = 0; k < ids.length; k++) {
        var btn = document.getElementById('lon-' + ids[k]);
        if (!btn) continue;
        if (ids[k] === n) {
            btn.classList.add('longueur-btn-active');
        } else {
            btn.classList.remove('longueur-btn-active');
        }
    }
    updateLongueurInfo();
}

function updateLongueurInfo() {
    var box = document.getElementById('longueur-info');
    if (!box) return;
    var checkedEl = document.querySelector('[name=plan_type]:checked');
    var isOhada   = checkedEl ? checkedEl.value === 'ohada' : true;
    var n         = currentLongueur;

    if (isOhada && n === 4) {
        box.className = 'rounded-xl p-3.5 flex gap-2.5 text-xs leading-relaxed bg-blue-50 border border-blue-200 text-blue-700';
        box.innerHTML = '<i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>'
            + '<span>Longueur standard SYSCOHADA Révisé. Les comptes sont à 4 chiffres '
            + '(ex: <strong>1011</strong> Capital, <strong>4011</strong> Fournisseurs). '
            + 'Recommandé pour la plupart des entreprises.</span>';
    } else if (isOhada && n > 4) {
        var zeros   = '';
        for (var z = 0; z < n - 4; z++) zeros += '0';
        var exemple = '1011' + zeros;
        var sousEx  = '1011' + zeros.slice(0, zeros.length - 1) + '1';
        box.className = 'rounded-xl p-3.5 flex gap-2.5 text-xs leading-relaxed bg-amber-50 border border-amber-200 text-amber-800';
        box.innerHTML = '<i class="fas fa-layer-group mt-0.5 flex-shrink-0"></i>'
            + '<span>Les comptes SYSCOHADA (4 chiffres) seront importés avec <strong>' + (n - 4)
            + ' zéro(s)</strong> ajouté(s) à droite '
            + '(ex: <strong>1011</strong> &rarr; <strong>' + exemple + '</strong>). '
            + 'Vous pourrez créer des sous-comptes dans cet espace '
            + '(ex: <strong>' + sousEx + '</strong>, <strong>' + (parseInt(sousEx, 10) + 1) + '</strong>...). '
            + 'Tous les numéros de compte devront faire exactement <strong>' + n + ' chiffres</strong>.</span>';
    } else {
        box.className = 'rounded-xl p-3.5 flex gap-2.5 text-xs leading-relaxed bg-slate-50 border border-slate-200 text-slate-600';
        box.innerHTML = '<i class="fas fa-pencil-alt mt-0.5 flex-shrink-0"></i>'
            + '<span>Plan vide - vous saisirez vos comptes manuellement. '
            + 'Tous vos numéros de compte devront faire exactement <strong>' + n + ' chiffres</strong>.</span>';
    }
}

// Sélection des cartes radio
function selectCard(el, name) {
    el.closest('.space-y-3, .p-6').querySelectorAll('.radio-card').forEach(c => {
        c.style.borderColor = '';
        c.style.background  = '';
        const icon = c.querySelector('.check-icon');
        if (icon) { icon.className = 'fas fa-circle text-slate-300 ml-auto text-lg check-icon'; }
    });
    el.style.borderColor = '#1565c0';
    el.style.background  = '#eff6ff';
    el.querySelector('input[type=radio]').checked = true;
    const icon = el.querySelector('.check-icon');
    if (icon) { icon.className = 'fas fa-check-circle text-blue-600 ml-auto text-lg check-icon'; }
}

// Sélection taille de police
function selectTaille(taille) {
    ['petit','normal','grand'].forEach(t => {
        document.getElementById('taille-' + t).classList.toggle('selected', t === taille);
    });
    document.getElementById('taille_police').value = taille;
}

// Construire le récapitulatif
function buildRecap() {
    const v = (id) => document.querySelector('[name=' + id + ']')?.value || '-';

    const modeLabel = document.getElementById('mode_input').value === 'cabinet' ? 'Cabinet d\'expertise' : 'Entreprise';
    const societe = [
        ['Mode',              modeLabel],
        ['Raison sociale',    v('raison_sociale')],
        ['Code société',      v('code_societe')],
        ['Forme juridique',   v('forme_juridique')   || '-'],
        ["Secteur d'activité",v('secteur_activite')  || '-'],
        ['Capital social',    v('capital') ? v('capital') + ' ' + v('devise_principale') : '-'],
        ['RCCM',              v('numero_rccm')        || '-'],
        ['NCC',               v('numero_contribuable')|| '-'],
        ['Régime fiscal',     v('regime_fiscal')      || '-'],
        ['Devise',            v('devise_principale')],
        ['Ville / Pays',      (v('ville') || '-') + ' / ' + (v('pays') || '-')],
        ['Site web',          v('site_web') || '-'],
    ];
    const longueur    = v('longueur_compte') || '4';
    const planType    = document.querySelector('[name=plan_type]:checked')?.value;
    const planLabel   = planType === 'ohada'
        ? 'SYSCOHADA Révisé (import auto)'
        : 'Plan vide (saisie manuelle)';
    const longueurEx  = planType === 'ohada' && parseInt(longueur) > 4
        ? ' - comptes padés (ex: 1011 → 1011' + '0'.repeat(parseInt(longueur) - 4) + ')'
        : '';
    const exercice = [
        ['Début',              v('exercice_debut')],
        ['Fin',                v('exercice_fin')],
        ['Plan comptable',     planLabel],
        ['Longueur des comptes', longueur + ' chiffres' + longueurEx],
    ];
    const admin = [
        ['Nom',   v('nom_utilisateur')],
        ['Email', v('email')],
        ['Rôle',  'Super administrateur'],
    ];

    const toHtml = rows => rows.map(([l, v]) =>
        `<div class="recap-row"><span class="recap-label">${l}</span><span class="recap-value">${v}</span></div>`
    ).join('');

    document.getElementById('recap-societe').innerHTML   = toHtml(societe);
    document.getElementById('recap-exercice').innerHTML  = toHtml(exercice);
    document.getElementById('recap-admin').innerHTML     = toHtml(admin);
}
</script>
</body>
</html>
