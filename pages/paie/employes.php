<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) die('Erreur: Aucune société sélectionnée');

// ── Migrations ─────────────────────────────────────────────────────────────
$migrations = [
    "CREATE TABLE IF NOT EXISTS paie_employes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        matricule VARCHAR(50),
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100),
        date_naissance DATE NULL,
        date_embauche DATE NOT NULL,
        poste VARCHAR(100),
        categorie VARCHAR(50),
        salaire_base DECIMAL(15,2) NOT NULL DEFAULT 0,
        indemnite_transport DECIMAL(15,2) DEFAULT 30000,
        nationalite ENUM('locale','expatrie') DEFAULT 'locale',
        situation_famille ENUM('C','M') DEFAULT 'C',
        nb_enfants INT DEFAULT 0,
        num_cnps VARCHAR(50),
        compte_charge VARCHAR(20) DEFAULT '6611',
        departement VARCHAR(100),
        superieur_id INT NULL,
        actif TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS paie_bulletins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        employe_id INT NOT NULL,
        mois TINYINT NOT NULL,
        annee INT NOT NULL,
        salaire_base DECIMAL(15,2) DEFAULT 0,
        indemnite_transport DECIMAL(15,2) DEFAULT 0,
        salaire_brut DECIMAL(15,2) DEFAULT 0,
        cnps_salarie DECIMAL(15,2) DEFAULT 0,
        cmu_salarie DECIMAL(15,2) DEFAULT 0,
        its_avant_ricf DECIMAL(15,2) DEFAULT 0,
        ricf DECIMAL(15,2) DEFAULT 0,
        its_net DECIMAL(15,2) DEFAULT 0,
        net_a_payer DECIMAL(15,2) DEFAULT 0,
        cnps_patronal DECIMAL(15,2) DEFAULT 0,
        pf_patronal DECIMAL(15,2) DEFAULT 0,
        am_patronal DECIMAL(15,2) DEFAULT 0,
        at_patronal DECIMAL(15,2) DEFAULT 0,
        cmu_patronal DECIMAL(15,2) DEFAULT 0,
        ce_patronal DECIMAL(15,2) DEFAULT 0,
        cn_patronal DECIMAL(15,2) DEFAULT 0,
        ta_patronal DECIMAL(15,2) DEFAULT 0,
        fdfp_patronal DECIMAL(15,2) DEFAULT 0,
        cout_total_employeur DECIMAL(15,2) DEFAULT 0,
        statut ENUM('brouillon','valide','comptabilise') DEFAULT 'brouillon',
        id_ecriture INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_bulletin (societe_id, employe_id, mois, annee)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS paie_parametres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL UNIQUE,
        cnps_salarie_taux DECIMAL(6,4) DEFAULT 0.0630,
        cnps_patronal_taux DECIMAL(6,4) DEFAULT 0.0770,
        cnps_plafond DECIMAL(15,2) DEFAULT 3375000,
        pf_taux DECIMAL(6,4) DEFAULT 0.0500,
        pf_plafond DECIMAL(15,2) DEFAULT 75000,
        am_taux DECIMAL(6,4) DEFAULT 0.0075,
        am_plafond DECIMAL(15,2) DEFAULT 75000,
        at_taux DECIMAL(6,4) DEFAULT 0.0500,
        at_plafond DECIMAL(15,2) DEFAULT 75000,
        cmu_salarie_c DECIMAL(10,2) DEFAULT 500,
        cmu_salarie_m DECIMAL(10,2) DEFAULT 1000,
        cmu_salarie_enfant DECIMAL(10,2) DEFAULT 500,
        cmu_patronal_c DECIMAL(10,2) DEFAULT 500,
        cmu_patronal_m DECIMAL(10,2) DEFAULT 1000,
        cmu_patronal_enfant DECIMAL(10,2) DEFAULT 500,
        ce_taux DECIMAL(6,4) DEFAULT 0.0920,
        cn_taux DECIMAL(6,4) DEFAULT 0.0120,
        ta_taux DECIMAL(6,4) DEFAULT 0.0040,
        fdfp_taux DECIMAL(6,4) DEFAULT 0.0120,
        indemnite_transport_defaut DECIMAL(15,2) DEFAULT 30000,
        ricf_valeur_part DECIMAL(10,2) DEFAULT 5500,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS paie_its_bareme (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        tranche_min DECIMAL(15,2) NOT NULL,
        tranche_max DECIMAL(15,2) NULL,
        taux DECIMAL(5,4) NOT NULL,
        ordre INT NOT NULL,
        UNIQUE KEY unique_tranche (societe_id, ordre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($migrations as $sql) { try { $db->exec($sql); } catch (Exception $e) {} }

$alter_cols = [
    "ALTER TABLE paie_employes ADD COLUMN departement VARCHAR(100) NULL",
    "ALTER TABLE paie_employes ADD COLUMN superieur_id INT NULL",
    "ALTER TABLE paie_employes ADD COLUMN photo VARCHAR(255) NULL",
    "ALTER TABLE paie_employes ADD COLUMN lieu_naissance VARCHAR(100) NULL",
    "ALTER TABLE paie_employes ADD COLUMN nationalite_civile VARCHAR(50) NULL",
    "ALTER TABLE paie_employes ADD COLUMN sexe ENUM('M','F') NULL",
    "ALTER TABLE paie_employes ADD COLUMN telephone VARCHAR(20) NULL",
    "ALTER TABLE paie_employes ADD COLUMN email VARCHAR(150) NULL",
    "ALTER TABLE paie_employes ADD COLUMN type_contrat ENUM('CDI','CDD','Stage','Interim') DEFAULT 'CDI'",
    "ALTER TABLE paie_employes ADD COLUMN date_fin_contrat DATE NULL",
    "ALTER TABLE paie_employes ADD COLUMN num_piece_identite VARCHAR(50) NULL",
    "ALTER TABLE paie_employes ADD COLUMN banque VARCHAR(100) NULL",
    "ALTER TABLE paie_employes ADD COLUMN num_compte_bancaire VARCHAR(50) NULL",
    "ALTER TABLE paie_employes ADD COLUMN date_depart DATE NULL",
    "ALTER TABLE paie_employes ADD COLUMN motif_depart VARCHAR(255) NULL",
];
foreach ($alter_cols as $sql) { try { $db->exec($sql); } catch (Exception $e) {} }

// Paramètres & barème par défaut
$check = $db->prepare("SELECT id FROM paie_parametres WHERE societe_id=?");
$check->execute([$societe_id]);
if (!$check->fetchColumn()) {
    $db->prepare("INSERT INTO paie_parametres (societe_id) VALUES (?)")->execute([$societe_id]);
}
$checkBareme = $db->prepare("SELECT COUNT(*) FROM paie_its_bareme WHERE societe_id=?");
$checkBareme->execute([$societe_id]);
if (!$checkBareme->fetchColumn()) {
    $ins = $db->prepare("INSERT INTO paie_its_bareme (societe_id, tranche_min, tranche_max, taux, ordre) VALUES (?,?,?,?,?)");
    foreach ([[0,75000,0.00,1],[75000,240000,0.16,2],[240000,800000,0.21,3],[800000,null,0.24,4]] as $t)
        $ins->execute([$societe_id, $t[0], $t[1], $t[2], $t[3]]);
}

// ── Actions rapides (toggle / delete) ──────────────────────────────────────
$message = '';
$messageType = '';

// Flash depuis le formulaire
if (!empty($_GET['ok'])) {
    $msgs = ['created' => 'Employé créé avec succès.', 'updated' => 'Employé mis à jour avec succès.'];
    $message = $msgs[$_GET['ok']] ?? '';
    $messageType = 'success';
}
if (!empty($_GET['err'])) {
    $message = htmlspecialchars($_GET['err']);
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_actif') {
        $db->prepare("UPDATE paie_employes SET actif = 1-actif WHERE id=? AND societe_id=?")
           ->execute([(int)$_POST['id'], $societe_id]);
        $message = 'Statut modifié.'; $messageType = 'success';
    }

    if ($action === 'delete') {
        $id_del = (int)$_POST['id'];
        $nb = $db->prepare("SELECT COUNT(*) FROM paie_bulletins WHERE employe_id=? AND societe_id=?");
        $nb->execute([$id_del, $societe_id]);
        if ($nb->fetchColumn() > 0) {
            $message = 'Impossible de supprimer : cet employé a des bulletins de paie.';
            $messageType = 'error';
        } else {
            $photoRow = $db->prepare("SELECT photo FROM paie_employes WHERE id=? AND societe_id=?");
            $photoRow->execute([$id_del, $societe_id]);
            $oldPhoto = $photoRow->fetchColumn();
            if ($oldPhoto) @unlink('../../' . $oldPhoto);
            $db->prepare("DELETE FROM paie_employes WHERE id=? AND societe_id=?")->execute([$id_del, $societe_id]);
            $message = 'Employé supprimé.'; $messageType = 'success';
        }
    }
}

// ── Données ─────────────────────────────────────────────────────────────────
$showInactifs = isset($_GET['inactifs']);
$whereActif = $showInactifs ? '' : 'AND e.actif = 1';
$stmt = $db->prepare("SELECT e.*,
    (SELECT COUNT(*) FROM paie_bulletins b WHERE b.employe_id=e.id) as nb_bulletins
    FROM paie_employes e
    WHERE e.societe_id=? $whereActif
    ORDER BY e.nom, e.prenom");
$stmt->execute([$societe_id]);
$employes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pour afficher les supérieurs dans le tableau
$stmt_sup = $db->prepare("SELECT id, nom, prenom FROM paie_employes WHERE societe_id=?");
$stmt_sup->execute([$societe_id]);
$tous_employes = $stmt_sup->fetchAll(PDO::FETCH_ASSOC);
$supMap = array_column($tous_employes, null, 'id');

$tcColors = [
    'CDI'    => 'text-emerald-400 bg-emerald-500/10',
    'CDD'    => 'text-blue-400 bg-blue-500/10',
    'Stage'  => 'text-amber-400 bg-amber-500/10',
    'Interim'=> 'text-orange-400 bg-orange-500/10',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employés | <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">
<?php include '../../includes/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0">
    <!-- Header -->
    <header class="h-12 bg-slate-800/50 border-b border-slate-700/50 flex items-center px-4 gap-3 flex-shrink-0">
        <div class="flex items-center gap-2 flex-1">
            <div class="w-6 h-6 bg-violet-500/20 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-users text-violet-400 text-xs"></i>
            </div>
            <h1 class="text-sm font-semibold text-white">Employés</h1>
            <span class="text-slate-500 text-xs">/</span>
            <span class="text-slate-400 text-xs">Liste du personnel</span>
        </div>
        <a href="index.php" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/60 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
            <i class="fa-solid fa-file-invoice-dollar text-xs"></i>
            Bulletins
        </a>
        <a href="employe_form.php" class="flex items-center gap-1.5 px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition">
            <i class="fa-solid fa-user-plus text-xs"></i>
            Nouvel employé
        </a>
    </header>

    <div class="flex-1 p-4 overflow-auto">
        <?php if ($message): ?>
        <div class="mb-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2 <?php echo $messageType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border border-red-500/20 text-red-400'; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Barre filtres / stats -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 bg-slate-800/50 border border-slate-700/50 rounded-xl px-3 py-2">
                    <i class="fa-solid fa-users text-violet-400 text-xs"></i>
                    <span class="text-sm font-semibold text-white"><?php echo count($employes); ?></span>
                    <span class="text-xs text-slate-400"><?php echo $showInactifs ? 'employés (tous)' : 'employés actifs'; ?></span>
                </div>
            </div>
            <a href="?<?php echo $showInactifs ? '' : 'inactifs=1'; ?>" class="text-xs text-slate-400 hover:text-slate-300 transition flex items-center gap-1.5 px-3 py-1.5 bg-slate-800/50 border border-slate-700/50 rounded-lg">
                <i class="fa-solid <?php echo $showInactifs ? 'fa-eye-slash' : 'fa-eye'; ?> text-[10px]"></i>
                <?php echo $showInactifs ? 'Masquer les inactifs' : 'Afficher les inactifs'; ?>
            </a>
        </div>

        <!-- Tableau -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <?php if (empty($employes)): ?>
            <div class="py-20 text-center text-slate-500">
                <i class="fa-solid fa-users text-4xl mb-4 block opacity-20"></i>
                <p class="text-sm mb-1">Aucun employé enregistré</p>
                <p class="text-xs mb-5">Ajoutez votre premier employé pour commencer</p>
                <a href="employe_form.php" class="inline-flex items-center gap-2 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium transition">
                    <i class="fa-solid fa-user-plus"></i> Ajouter un employé
                </a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-700/50 bg-slate-800/30">
                            <th class="text-left px-4 py-3 text-slate-400 font-medium">Employé</th>
                            <th class="text-left px-3 py-3 text-slate-400 font-medium hidden md:table-cell">Poste / Département</th>
                            <th class="text-left px-3 py-3 text-slate-400 font-medium hidden lg:table-cell">Contrat</th>
                            <th class="text-left px-3 py-3 text-slate-400 font-medium hidden xl:table-cell">Supérieur</th>
                            <th class="text-right px-3 py-3 text-slate-400 font-medium">Salaire base</th>
                            <th class="text-center px-3 py-3 text-slate-400 font-medium hidden lg:table-cell">Famille</th>
                            <th class="text-center px-3 py-3 text-slate-400 font-medium">Statut</th>
                            <th class="text-right px-4 py-3 text-slate-400 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/30">
                        <?php foreach ($employes as $emp): ?>
                        <tr class="hover:bg-slate-700/20 transition-colors <?php echo !$emp['actif'] ? 'opacity-50' : ''; ?>">
                            <!-- Employé -->
                            <td class="px-4 py-3">
                                <a href="employe_detail.php?id=<?php echo $emp['id']; ?>" class="flex items-center gap-3 group">
                                    <div class="w-9 h-9 rounded-xl flex-shrink-0 overflow-hidden bg-violet-500/20">
                                        <?php if (!empty($emp['photo'])): ?>
                                        <img src="../../<?php echo htmlspecialchars($emp['photo']); ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center">
                                            <span class="text-xs font-bold text-violet-300"><?php echo strtoupper(substr($emp['nom'],0,1).substr($emp['prenom']??'',0,1)); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-white group-hover:text-violet-300 transition"><?php echo htmlspecialchars($emp['nom'].' '.$emp['prenom']); ?></p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <?php if ($emp['matricule']): ?>
                                            <span class="text-[10px] text-slate-500"><?php echo htmlspecialchars($emp['matricule']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($emp['telephone']): ?>
                                            <span class="text-[10px] text-slate-500"><i class="fa-solid fa-phone text-[8px] mr-0.5"></i><?php echo htmlspecialchars($emp['telephone']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            </td>
                            <!-- Poste -->
                            <td class="px-3 py-3 hidden md:table-cell">
                                <p class="text-slate-200"><?php echo htmlspecialchars($emp['poste'] ?: '—'); ?></p>
                                <?php if ($emp['departement']): ?>
                                <p class="text-[10px] text-slate-500 mt-0.5"><i class="fa-solid fa-building text-[9px] mr-1"></i><?php echo htmlspecialchars($emp['departement']); ?></p>
                                <?php endif; ?>
                            </td>
                            <!-- Contrat -->
                            <td class="px-3 py-3 hidden lg:table-cell">
                                <?php $tc = $emp['type_contrat'] ?? 'CDI'; ?>
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold <?php echo $tcColors[$tc] ?? 'text-slate-400 bg-slate-700/50'; ?>"><?php echo $tc; ?></span>
                                <?php if ($emp['date_embauche']): ?>
                                <p class="text-[10px] text-slate-500 mt-0.5">depuis <?php echo date('d/m/Y', strtotime($emp['date_embauche'])); ?></p>
                                <?php endif; ?>
                                <?php if ($emp['date_fin_contrat']): ?>
                                <p class="text-[10px] text-amber-500/80 mt-0.5">fin <?php echo date('d/m/Y', strtotime($emp['date_fin_contrat'])); ?></p>
                                <?php endif; ?>
                            </td>
                            <!-- Supérieur -->
                            <td class="px-3 py-3 hidden xl:table-cell">
                                <?php $sup = $emp['superieur_id'] ? ($supMap[$emp['superieur_id']] ?? null) : null; ?>
                                <?php if ($sup): ?>
                                <div class="flex items-center gap-1.5">
                                    <div class="w-6 h-6 rounded-full bg-slate-600/60 flex items-center justify-center flex-shrink-0">
                                        <span class="text-[9px] font-bold text-slate-300"><?php echo strtoupper(substr($sup['nom'],0,1).substr($sup['prenom']??'',0,1)); ?></span>
                                    </div>
                                    <span class="text-slate-400"><?php echo htmlspecialchars($sup['nom'].' '.$sup['prenom']); ?></span>
                                </div>
                                <?php else: ?><span class="text-slate-600">—</span><?php endif; ?>
                            </td>
                            <!-- Salaire -->
                            <td class="px-3 py-3 text-right">
                                <span class="font-mono font-semibold text-emerald-300"><?php echo number_format($emp['salaire_base'], 0, ',', ' '); ?></span>
                                <span class="text-[10px] text-slate-500 block">FCFA</span>
                            </td>
                            <!-- Famille -->
                            <td class="px-3 py-3 text-center hidden lg:table-cell">
                                <span class="text-[10px] bg-slate-700/60 px-2 py-0.5 rounded-md">
                                    <?php echo $emp['situation_famille'] === 'M' ? 'Marié(e)' : 'Célibataire'; ?>
                                    <?php if ($emp['nb_enfants'] > 0): ?>· <?php echo $emp['nb_enfants']; ?> enf.<?php endif; ?>
                                </span>
                            </td>
                            <!-- Statut -->
                            <td class="px-3 py-3 text-center">
                                <?php if ($emp['date_depart']): ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-red-500/15 text-red-400 font-medium" title="Parti le <?php echo date('d/m/Y', strtotime($emp['date_depart'])); ?>">Parti</span>
                                <?php else: ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-medium <?php echo $emp['actif'] ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-600/30 text-slate-500'; ?>">
                                    <?php echo $emp['actif'] ? 'Actif' : 'Inactif'; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <!-- Actions -->
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="employe_form.php?edit=<?php echo $emp['id']; ?>" class="p-1.5 hover:bg-slate-700/60 rounded-lg text-slate-400 hover:text-amber-400 transition" title="Modifier">
                                        <i class="fa-solid fa-pen-to-square text-[11px]"></i>
                                    </a>
                                    <a href="bulletin.php?employe_id=<?php echo $emp['id']; ?>" class="p-1.5 hover:bg-slate-700/60 rounded-lg text-slate-400 hover:text-violet-400 transition" title="Créer un bulletin">
                                        <i class="fa-solid fa-file-invoice-dollar text-[11px]"></i>
                                    </a>
                                    <form method="post" class="inline" onsubmit="return confirm('Modifier le statut de cet employé ?')">
                                        <input type="hidden" name="action" value="toggle_actif">
                                        <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" class="p-1.5 hover:bg-slate-700/60 rounded-lg text-slate-400 hover:text-sky-400 transition" title="<?php echo $emp['actif'] ? 'Désactiver' : 'Activer'; ?>">
                                            <i class="fa-solid <?php echo $emp['actif'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?> text-[11px]"></i>
                                        </button>
                                    </form>
                                    <?php if ($emp['nb_bulletins'] == 0): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Supprimer définitivement cet employé ?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" class="p-1.5 hover:bg-slate-700/60 rounded-lg text-slate-400 hover:text-red-400 transition" title="Supprimer">
                                            <i class="fa-solid fa-trash text-[11px]"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof toggleAccordion === 'function') toggleAccordion('paie');
});
</script>
</div>
</body>
</html>
