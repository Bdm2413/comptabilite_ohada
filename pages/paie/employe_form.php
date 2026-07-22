<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// Dossier uploads
$uploadDir = '../../uploads/paie/photos/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

// ── Chargement de l'employé en édition ─────────────────────────────────────
$editEmploye = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM paie_employes WHERE id=? AND societe_id=?");
    $stmt->execute([$editId, $societe_id]);
    $editEmploye = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editEmploye) { header('Location: employes.php'); exit; }
}
$isEdit = (bool)$editEmploye;

// Liste des supérieurs hiérarchiques (actifs)
$stmt_sup = $db->prepare("SELECT id, nom, prenom, poste FROM paie_employes WHERE societe_id=? AND actif=1 ORDER BY nom, prenom");
$stmt_sup->execute([$societe_id]);
$tous_employes = $stmt_sup->fetchAll(PDO::FETCH_ASSOC);

// ── Traitement formulaire ───────────────────────────────────────────────────
$errors = [];
$formData = $editEmploye ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collecte
    $id_edit             = (int)($_POST['id'] ?? 0);
    $matricule           = trim($_POST['matricule'] ?? '');
    $nom                 = trim($_POST['nom'] ?? '');
    $prenom              = trim($_POST['prenom'] ?? '');
    $sexe                = $_POST['sexe'] ?? null;
    $date_naissance      = trim($_POST['date_naissance'] ?? '') ?: null;
    $lieu_naissance      = trim($_POST['lieu_naissance'] ?? '');
    $nationalite_civile  = trim($_POST['nationalite_civile'] ?? '');
    $num_piece_identite  = trim($_POST['num_piece_identite'] ?? '');
    $telephone           = trim($_POST['telephone'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $date_embauche       = trim($_POST['date_embauche'] ?? '');
    $poste               = trim($_POST['poste'] ?? '');
    $categorie           = trim($_POST['categorie'] ?? '');
    $departement         = trim($_POST['departement'] ?? '');
    $superieur_id        = (int)($_POST['superieur_id'] ?? 0) ?: null;
    $type_contrat        = $_POST['type_contrat'] ?? 'CDI';
    $date_fin_contrat    = trim($_POST['date_fin_contrat'] ?? '') ?: null;
    $salaire_base        = (float)str_replace([' ','  '], '', $_POST['salaire_base'] ?? 0);
    $transport           = (float)str_replace([' ','  '], '', $_POST['indemnite_transport'] ?? 30000);
    $nationalite         = $_POST['nationalite'] ?? 'locale';
    $situation           = $_POST['situation_famille'] ?? 'C';
    $nb_enfants          = (int)($_POST['nb_enfants'] ?? 0);
    $num_cnps            = trim($_POST['num_cnps'] ?? '');
    $compte_charge       = trim($_POST['compte_charge'] ?? '6611');
    $banque              = trim($_POST['banque'] ?? '');
    $num_compte_bancaire = trim($_POST['num_compte_bancaire'] ?? '');
    $date_depart         = trim($_POST['date_depart'] ?? '') ?: null;
    $motif_depart        = trim($_POST['motif_depart'] ?? '');

    // Préserver les données du formulaire en cas d'erreur
    $formData = $_POST;

    // Validation
    if (!$nom)          $errors[] = 'Le nom est obligatoire.';
    if (!$date_embauche) $errors[] = 'La date d\'embauche est obligatoire.';
    if ($salaire_base <= 0) $errors[] = 'Le salaire de base doit être supérieur à 0.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'L\'adresse e-mail est invalide.';

    // Gestion photo
    $photo = $id_edit ? ($_POST['photo_actuelle'] ?? null) : null;
    if (!empty($_FILES['photo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $errors[] = 'Format de photo non autorisé (JPG, PNG, WEBP, GIF).';
        } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'La photo ne doit pas dépasser 2 Mo.';
        } elseif ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $fname = 'emp_' . $societe_id . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fname)) {
                if ($id_edit && !empty($_POST['photo_actuelle'])) {
                    @unlink('../../' . $_POST['photo_actuelle']);
                }
                $photo = 'uploads/paie/photos/' . $fname;
            } else {
                $errors[] = 'Erreur lors de l\'enregistrement de la photo.';
            }
        }
    }

    if (empty($errors)) {
        if ($id_edit) {
            $stmt = $db->prepare("UPDATE paie_employes SET
                matricule=?, nom=?, prenom=?, sexe=?, date_naissance=?,
                lieu_naissance=?, nationalite_civile=?, num_piece_identite=?,
                telephone=?, email=?, date_embauche=?, poste=?, categorie=?,
                departement=?, superieur_id=?, type_contrat=?, date_fin_contrat=?,
                salaire_base=?, indemnite_transport=?, nationalite=?,
                situation_famille=?, nb_enfants=?, num_cnps=?, compte_charge=?,
                banque=?, num_compte_bancaire=?, date_depart=?, motif_depart=?, photo=?
                WHERE id=? AND societe_id=?");
            $stmt->execute([
                $matricule, $nom, $prenom, $sexe ?: null, $date_naissance,
                $lieu_naissance, $nationalite_civile, $num_piece_identite,
                $telephone, $email, $date_embauche, $poste, $categorie,
                $departement, $superieur_id, $type_contrat, $date_fin_contrat,
                $salaire_base, $transport, $nationalite,
                $situation, $nb_enfants, $num_cnps, $compte_charge,
                $banque, $num_compte_bancaire, $date_depart, $motif_depart, $photo,
                $id_edit, $societe_id
            ]);
            // Auto-désactiver si date de départ renseignée
            if ($date_depart) {
                $db->prepare("UPDATE paie_employes SET actif=0 WHERE id=? AND societe_id=?")->execute([$id_edit, $societe_id]);
            }
            header('Location: employes.php?ok=updated');
        } else {
            $stmt = $db->prepare("INSERT INTO paie_employes (
                societe_id, matricule, nom, prenom, sexe, date_naissance,
                lieu_naissance, nationalite_civile, num_piece_identite,
                telephone, email, date_embauche, poste, categorie,
                departement, superieur_id, type_contrat, date_fin_contrat,
                salaire_base, indemnite_transport, nationalite,
                situation_famille, nb_enfants, num_cnps, compte_charge,
                banque, num_compte_bancaire, date_depart, motif_depart, photo
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $societe_id, $matricule, $nom, $prenom, $sexe ?: null, $date_naissance,
                $lieu_naissance, $nationalite_civile, $num_piece_identite,
                $telephone, $email, $date_embauche, $poste, $categorie,
                $departement, $superieur_id, $type_contrat, $date_fin_contrat,
                $salaire_base, $transport, $nationalite,
                $situation, $nb_enfants, $num_cnps, $compte_charge,
                $banque, $num_compte_bancaire, $date_depart, $motif_depart, $photo
            ]);
            header('Location: employes.php?ok=created');
        }
        exit;
    }
}

// Helpers
function fv($key, $default = '') {
    global $formData;
    return htmlspecialchars($formData[$key] ?? $default);
}
function fsel($key, $val, $default = '') {
    global $formData;
    return ($formData[$key] ?? $default) === $val ? 'selected' : '';
}

$pageTitle = $isEdit ? 'Modifier l\'employé' : 'Nouvel employé';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        .field-label { display: block; font-size: 10px; color: rgb(148 163 184); margin-bottom: 4px; }
        .field-label span.req { color: rgb(248 113 113); }
        .fi { width: 100%; background: rgb(51 65 85 / 0.5); border: 1px solid rgb(71 85 105 / 0.5); border-radius: 8px; padding: 7px 11px; font-size: 12px; color: white; outline: none; transition: border-color .15s; }
        .fi:focus { border-color: rgb(139 92 246); }
        .fi::placeholder { color: rgb(100 116 139); }
        select.fi option { background: #1e293b; }
        .section-header { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-bottom: 1px solid rgb(51 65 85 / 0.4); margin-bottom: 16px; }
        .section-header i { font-size: 11px; }
        .section-header span { font-size: 11px; font-weight: 600; letter-spacing: .03em; }
        .card { background: rgb(30 41 59 / 0.5); border: 1px solid rgb(51 65 85 / 0.5); border-radius: 12px; overflow: hidden; margin-bottom: 16px; }
        .card-body { padding: 16px; }
        .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
<?php include '../../includes/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0">
    <!-- Header -->
    <header class="h-12 bg-slate-800/50 border-b border-slate-700/50 flex items-center px-4 gap-3 flex-shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <a href="employes.php" class="p-1 hover:bg-slate-700/50 rounded-lg text-slate-400 hover:text-slate-200 transition flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-xs"></i>
            </a>
            <div class="w-6 h-6 bg-violet-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fa-solid <?php echo $isEdit ? 'fa-pen-to-square text-amber-400' : 'fa-user-plus text-violet-400'; ?> text-xs"></i>
            </div>
            <nav class="flex items-center gap-1.5 text-xs min-w-0 overflow-hidden">
                <a href="employes.php" class="text-slate-400 hover:text-slate-200 transition whitespace-nowrap">Employés</a>
                <i class="fa-solid fa-chevron-right text-slate-600 text-[9px]"></i>
                <span class="text-white font-medium truncate"><?php echo $pageTitle; ?></span>
            </nav>
        </div>
        <div class="flex items-center gap-2">
            <a href="employes.php" class="px-3 py-1.5 bg-slate-700/60 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                Annuler
            </a>
            <button form="formEmploye" type="submit" class="flex items-center gap-1.5 px-4 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition">
                <i class="fa-solid fa-floppy-disk text-xs"></i>
                <?php echo $isEdit ? 'Enregistrer les modifications' : 'Créer l\'employé'; ?>
            </button>
        </div>
    </header>

    <div class="flex-1 overflow-auto p-5">
        <!-- Erreurs -->
        <?php if (!empty($errors)): ?>
        <div class="mb-5 px-4 py-3 bg-red-500/10 border border-red-500/20 rounded-xl text-sm text-red-400">
            <p class="font-semibold mb-1 flex items-center gap-2"><i class="fa-solid fa-circle-exclamation"></i> Veuillez corriger les erreurs suivantes :</p>
            <ul class="list-disc list-inside space-y-0.5 text-xs">
                <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form id="formEmploye" method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo $editEmploye['id'] ?? ''; ?>">
            <input type="hidden" name="photo_actuelle" value="<?php echo htmlspecialchars($editEmploye['photo'] ?? ''); ?>">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                <!-- ══ Colonne gauche ══ -->
                <div class="lg:col-span-1 space-y-4">

                    <!-- Photo & Identité principale -->
                    <div class="card">
                        <div class="section-header bg-violet-500/5">
                            <i class="fa-solid fa-id-card text-violet-400"></i>
                            <span class="text-violet-300">Identité</span>
                        </div>
                        <div class="card-body pt-0">
                            <!-- Zone photo -->
                            <div class="flex flex-col items-center mb-5">
                                <div id="photoWrapper" class="w-24 h-24 rounded-2xl overflow-hidden bg-slate-700/60 border-2 border-dashed border-slate-600/50 flex items-center justify-center cursor-pointer hover:border-violet-500/60 transition mb-2" onclick="document.getElementById('photoInput').click()">
                                    <?php if (!empty($editEmploye['photo'])): ?>
                                    <img id="photoImg" src="../../<?php echo htmlspecialchars($editEmploye['photo']); ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                    <div id="photoPlaceholder" class="text-center">
                                        <i class="fa-solid fa-camera text-slate-500 text-2xl block mb-1"></i>
                                        <span class="text-[10px] text-slate-600">Cliquer pour<br>ajouter une photo</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="photo" id="photoInput" accept="image/*" class="hidden" onchange="previewPhoto(this)">
                                <p class="text-[10px] text-slate-500">JPG, PNG, WEBP · 2 Mo max</p>
                                <?php if (!empty($editEmploye['photo'])): ?>
                                <button type="button" onclick="clearPhoto()" class="mt-1 text-[10px] text-red-400/70 hover:text-red-400 transition">
                                    <i class="fa-solid fa-xmark mr-0.5"></i>Supprimer la photo
                                </button>
                                <?php endif; ?>
                            </div>

                            <div class="g2 mb-3">
                                <div>
                                    <label class="field-label">Nom <span class="req">*</span></label>
                                    <input type="text" name="nom" value="<?php echo fv('nom'); ?>" required class="fi" placeholder="KONAN">
                                </div>
                                <div>
                                    <label class="field-label">Prénom</label>
                                    <input type="text" name="prenom" value="<?php echo fv('prenom'); ?>" class="fi" placeholder="Kouamé">
                                </div>
                            </div>

                            <div class="g2 mb-3">
                                <div>
                                    <label class="field-label">Sexe</label>
                                    <select name="sexe" class="fi">
                                        <option value="">—</option>
                                        <option value="M" <?php echo fsel('sexe','M'); ?>>Masculin</option>
                                        <option value="F" <?php echo fsel('sexe','F'); ?>>Féminin</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="field-label">Date de naissance</label>
                                    <input type="date" name="date_naissance" value="<?php echo fv('date_naissance'); ?>" class="fi">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="field-label">Lieu de naissance</label>
                                <input type="text" name="lieu_naissance" value="<?php echo fv('lieu_naissance'); ?>" class="fi" placeholder="Abidjan, Bouaké…">
                            </div>

                            <div class="g2">
                                <div>
                                    <label class="field-label">Nationalité</label>
                                    <input type="text" name="nationalite_civile" value="<?php echo fv('nationalite_civile'); ?>" class="fi" placeholder="Ivoirienne">
                                </div>
                                <div>
                                    <label class="field-label">N° pièce d'identité</label>
                                    <input type="text" name="num_piece_identite" value="<?php echo fv('num_piece_identite'); ?>" class="fi" placeholder="CI / Passeport">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="card">
                        <div class="section-header bg-sky-500/5">
                            <i class="fa-solid fa-phone text-sky-400"></i>
                            <span class="text-sky-300">Contact</span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="mb-3">
                                <label class="field-label">Téléphone</label>
                                <input type="tel" name="telephone" value="<?php echo fv('telephone'); ?>" class="fi" placeholder="+225 07 00 00 00 00">
                            </div>
                            <div>
                                <label class="field-label">E-mail professionnel</label>
                                <input type="email" name="email" value="<?php echo fv('email'); ?>" class="fi" placeholder="prenom@societe.ci">
                            </div>
                        </div>
                    </div>

                    <!-- Coordonnées bancaires -->
                    <div class="card">
                        <div class="section-header bg-amber-500/5">
                            <i class="fa-solid fa-building-columns text-amber-400"></i>
                            <span class="text-amber-300">Coordonnées bancaires</span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="mb-3">
                                <label class="field-label">Banque</label>
                                <input type="text" name="banque" value="<?php echo fv('banque'); ?>" class="fi" placeholder="SGCI, BICICI, Ecobank…">
                            </div>
                            <div>
                                <label class="field-label">N° de compte bancaire</label>
                                <input type="text" name="num_compte_bancaire" value="<?php echo fv('num_compte_bancaire'); ?>" class="fi" placeholder="RIB / IBAN">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ Colonne droite ══ -->
                <div class="lg:col-span-2 space-y-4">

                    <!-- Emploi & Contrat -->
                    <div class="card">
                        <div class="section-header bg-blue-500/5">
                            <i class="fa-solid fa-briefcase text-blue-400"></i>
                            <span class="text-blue-300">Emploi &amp; Contrat</span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="g3 mb-4">
                                <div>
                                    <label class="field-label">Matricule</label>
                                    <input type="text" name="matricule" value="<?php echo fv('matricule'); ?>" class="fi" placeholder="EMP001">
                                </div>
                                <div>
                                    <label class="field-label">Date d'embauche <span class="req">*</span></label>
                                    <input type="date" name="date_embauche" value="<?php echo fv('date_embauche'); ?>" required class="fi">
                                </div>
                                <div>
                                    <label class="field-label">Type de contrat</label>
                                    <select name="type_contrat" id="type_contrat" class="fi" onchange="toggleDateFin()">
                                        <?php foreach (['CDI','CDD','Stage','Interim'] as $tc): ?>
                                        <option value="<?php echo $tc; ?>" <?php echo fsel('type_contrat',$tc,'CDI'); ?>><?php echo $tc; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="g3 mb-4">
                                <div>
                                    <label class="field-label">Poste / Fonction</label>
                                    <input type="text" name="poste" value="<?php echo fv('poste'); ?>" class="fi" placeholder="Directeur financier">
                                </div>
                                <div>
                                    <label class="field-label">Catégorie</label>
                                    <input type="text" name="categorie" value="<?php echo fv('categorie'); ?>" class="fi" placeholder="Cadre supérieur">
                                </div>
                                <div>
                                    <label class="field-label">Département</label>
                                    <input type="text" name="departement" value="<?php echo fv('departement'); ?>" class="fi" placeholder="Finance, RH, IT…">
                                </div>
                            </div>

                            <div class="g2 mb-4">
                                <div>
                                    <label class="field-label">Supérieur hiérarchique</label>
                                    <select name="superieur_id" class="fi">
                                        <option value="">— Aucun —</option>
                                        <?php foreach ($tous_employes as $sup):
                                            if (($editEmploye['id'] ?? 0) == $sup['id']) continue; ?>
                                        <option value="<?php echo $sup['id']; ?>" <?php echo ($formData['superieur_id'] ?? null) == $sup['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['nom'].' '.$sup['prenom'].($sup['poste'] ? ' — '.$sup['poste'] : '')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="dateFinWrapper">
                                    <label class="field-label">Date de fin de contrat</label>
                                    <input type="date" name="date_fin_contrat" id="date_fin_contrat" value="<?php echo fv('date_fin_contrat'); ?>" class="fi">
                                    <p id="dateFinNote" class="text-[9px] text-slate-600 mt-1">Applicable pour CDD, Stage et Intérim.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paie & Cotisations -->
                    <div class="card">
                        <div class="section-header bg-emerald-500/5">
                            <i class="fa-solid fa-coins text-emerald-400"></i>
                            <span class="text-emerald-300">Paie &amp; Cotisations</span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="g3 mb-4">
                                <div>
                                    <label class="field-label">Salaire de base (FCFA) <span class="req">*</span></label>
                                    <input type="number" name="salaire_base" value="<?php echo fv('salaire_base'); ?>" required min="0" step="1" class="fi" placeholder="150000">
                                </div>
                                <div>
                                    <label class="field-label">Indemnité transport (FCFA)</label>
                                    <input type="number" name="indemnite_transport" value="<?php echo fv('indemnite_transport', 30000); ?>" min="0" step="1" class="fi">
                                </div>
                                <div>
                                    <label class="field-label">Statut fiscal</label>
                                    <select name="nationalite" class="fi">
                                        <option value="locale" <?php echo fsel('nationalite','locale','locale'); ?>>Locale</option>
                                        <option value="expatrie" <?php echo fsel('nationalite','expatrie'); ?>>Expatrié(e)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="g3 mb-4">
                                <div>
                                    <label class="field-label">Situation de famille</label>
                                    <select name="situation_famille" class="fi">
                                        <option value="C" <?php echo fsel('situation_famille','C','C'); ?>>Célibataire</option>
                                        <option value="M" <?php echo fsel('situation_famille','M'); ?>>Marié(e)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="field-label">Nombre d'enfants à charge</label>
                                    <input type="number" name="nb_enfants" value="<?php echo fv('nb_enfants', 0); ?>" min="0" max="20" class="fi">
                                </div>
                                <div>
                                    <label class="field-label">Compte de charge</label>
                                    <input type="text" name="compte_charge" value="<?php echo fv('compte_charge', '6611'); ?>" class="fi" placeholder="6611">
                                </div>
                            </div>

                            <div>
                                <label class="field-label">N° d'affiliation CNPS</label>
                                <input type="text" name="num_cnps" value="<?php echo fv('num_cnps'); ?>" class="fi" placeholder="Numéro CNPS de l'employé">
                            </div>

                            <div class="mt-3 p-3 bg-slate-700/30 rounded-xl">
                                <p class="text-[10px] text-slate-500 flex items-start gap-2">
                                    <i class="fa-solid fa-circle-info text-violet-400/60 mt-0.5 flex-shrink-0"></i>
                                    Le <strong class="text-slate-400">statut fiscal</strong> détermine l'application de la Contribution Expatriés (CE 9,2%).
                                    La <strong class="text-slate-400">situation de famille</strong> et le <strong class="text-slate-400">nombre d'enfants</strong> servent au calcul du RICF (réduction d'impôt sur les traitements et salaires).
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Départ -->
                    <div class="card">
                        <div class="section-header bg-red-500/5">
                            <i class="fa-solid fa-door-open text-red-400/80"></i>
                            <span class="text-red-300/80">Départ de l'entreprise</span>
                            <span class="ml-auto text-[9px] text-slate-600 font-normal">Laisser vide si l'employé est toujours en poste</span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="g2">
                                <div>
                                    <label class="field-label">Date de départ</label>
                                    <input type="date" name="date_depart" value="<?php echo fv('date_depart'); ?>" class="fi">
                                </div>
                                <div>
                                    <label class="field-label">Motif de départ</label>
                                    <input type="text" name="motif_depart" value="<?php echo fv('motif_depart'); ?>" class="fi" placeholder="Démission, Licenciement, Retraite…">
                                </div>
                            </div>
                            <p class="text-[10px] text-slate-600 mt-2 flex items-center gap-1.5">
                                <i class="fa-solid fa-triangle-exclamation text-amber-500/50"></i>
                                Renseigner une date de départ désactivera automatiquement l'employé dans le système.
                            </p>
                        </div>
                    </div>

                    <!-- Boutons bas de page -->
                    <div class="flex items-center justify-between pt-1">
                        <a href="employes.php" class="px-4 py-2 bg-slate-700/60 hover:bg-slate-700 text-slate-300 rounded-lg text-sm transition flex items-center gap-2">
                            <i class="fa-solid fa-xmark text-xs"></i> Annuler
                        </a>
                        <button type="submit" class="px-6 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition flex items-center gap-2">
                            <i class="fa-solid fa-floppy-disk text-xs"></i>
                            <?php echo $isEdit ? 'Enregistrer les modifications' : 'Créer l\'employé'; ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof toggleAccordion === 'function') toggleAccordion('paie');
    toggleDateFin();
});

function toggleDateFin() {
    const tc = document.getElementById('type_contrat').value;
    const wrap = document.getElementById('dateFinWrapper');
    const input = document.getElementById('date_fin_contrat');
    const note  = document.getElementById('dateFinNote');
    const show  = ['CDD','Stage','Interim'].includes(tc);
    wrap.style.opacity = show ? '1' : '0.4';
    input.disabled = !show;
    if (note) note.style.display = show ? 'block' : 'none';
}

function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const wrapper = document.getElementById('photoWrapper');
        wrapper.innerHTML = '<img src="' + e.target.result + '" class="w-full h-full object-cover" id="photoImg">';
    };
    reader.readAsDataURL(input.files[0]);
}

function clearPhoto() {
    document.getElementById('photoInput').value = '';
    document.querySelector('[name="photo_actuelle"]').value = '';
    const wrapper = document.getElementById('photoWrapper');
    wrapper.innerHTML = '<div id="photoPlaceholder" class="text-center"><i class="fa-solid fa-camera text-slate-500 text-2xl block mb-1"></i><span class="text-[10px] text-slate-600">Cliquer pour<br>ajouter une photo</span></div>';
}
</script>
</div>
</body>
</html>
