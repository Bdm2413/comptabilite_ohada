<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
$user_id = $_SESSION['user_id'];

// Gestion des actions (Ajout, Modification, Suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $stmt = $db->prepare("
                INSERT INTO charges_constatees_avance
                (societe_id, numero_facture, date_facture, id_tiers, description, compte_charge,
                 montant_total, date_debut, date_fin, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $societe_id,
                $_POST['numero_facture'],
                $_POST['date_facture'],
                !empty($_POST['id_tiers']) ? $_POST['id_tiers'] : null,
                $_POST['description'],
                $_POST['compte_charge'],
                $_POST['montant_total'],
                $_POST['date_debut'],
                $_POST['date_fin'],
                $_POST['notes'] ?? null,
                $user_id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Charge constatée d\'avance ajoutée avec succès'];
        }
        elseif ($action === 'edit') {
            $stmt = $db->prepare("
                UPDATE charges_constatees_avance
                SET numero_facture = ?, date_facture = ?, id_tiers = ?, description = ?,
                    compte_charge = ?, montant_total = ?, date_debut = ?, date_fin = ?,
                    notes = ?, statut = ?
                WHERE id = ? AND societe_id = ?
            ");
            $stmt->execute([
                $_POST['numero_facture'],
                $_POST['date_facture'],
                !empty($_POST['id_tiers']) ? $_POST['id_tiers'] : null,
                $_POST['description'],
                $_POST['compte_charge'],
                $_POST['montant_total'],
                $_POST['date_debut'],
                $_POST['date_fin'],
                $_POST['notes'] ?? null,
                $_POST['statut'],
                $_POST['id'],
                $societe_id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Charge constatée d\'avance modifiée avec succès'];
        }
        elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM charges_constatees_avance WHERE id = ? AND societe_id = ?");
            $stmt->execute([$_POST['id'], $societe_id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Charge constatée d\'avance supprimée avec succès'];
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
    }

    header('Location: charges_avance.php');
    exit;
}

// Récupérer les filtres
$statut_filter = $_GET['statut'] ?? 'Actif';
$annee_filter = $_GET['annee'] ?? date('Y');

// Récupérer les charges constatées d'avance
$sql = "
    SELECT
        cca.*,
        pt.nom as tiers_nom,
        pt.compte_tiers,
        pc.intitule_compte as compte_charge_libelle
    FROM charges_constatees_avance cca
    LEFT JOIN plan_tiers pt ON cca.id_tiers = pt.id
    LEFT JOIN plan_comptable pc ON cca.compte_charge = pc.compte AND pc.societe_id = ?
    WHERE cca.societe_id = ?
";

$params = [$societe_id, $societe_id];

if ($statut_filter !== 'tous') {
    $sql .= " AND cca.statut = ?";
    $params[] = $statut_filter;
}

if (!empty($annee_filter)) {
    $sql .= " AND (YEAR(cca.date_debut) = ? OR YEAR(cca.date_fin) = ?)";
    $params[] = $annee_filter;
    $params[] = $annee_filter;
}

$sql .= " ORDER BY cca.date_facture DESC, cca.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$charges = $stmt->fetchAll();

// Récupérer les fournisseurs pour le formulaire
$stmt = $db->prepare("SELECT id, compte_tiers, nom FROM plan_tiers WHERE type IN ('Fournisseur', 'Les deux') AND actif = 1 ORDER BY nom");
$stmt->execute();
$fournisseurs = $stmt->fetchAll();

// Récupérer les comptes de charges (classe 6)
$stmt = $db->prepare("SELECT compte, intitule_compte FROM plan_comptable WHERE societe_id = ? AND compte LIKE '6%' ORDER BY compte");
$stmt->execute([$societe_id]);
$comptes_charges = $stmt->fetchAll();

$pageTitle = "Charges Constatées d'Avance";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Comptabilité SYSCOHADA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100">
    <div class="flex h-screen overflow-hidden">
        <?php include '../../includes/sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto">
            <div class="p-6">
    <!-- En-tête avec boutons d'action -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white mb-2">💼 Charges Constatées d'Avance (CCA)</h1>
            <p class="text-slate-400 text-sm">Suivi extra-comptable des factures d'achat à répartir dans le temps</p>
        </div>
        <div class="flex gap-3">
            <a href="recap_mensuel_cca.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Récap mensuel
            </a>
            <button onclick="openAddModal()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Nouvelle CCA
            </button>
        </div>
    </div>

    <!-- Filtres -->
    <div class="bg-slate-800 rounded-lg p-4 mb-6">
        <form method="GET" class="flex gap-4 items-end">
            <div class="flex-1">
                <label class="block text-sm font-medium text-slate-300 mb-2">Statut</label>
                <select name="statut" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    <option value="tous" <?= $statut_filter === 'tous' ? 'selected' : '' ?>>Tous</option>
                    <option value="Actif" <?= $statut_filter === 'Actif' ? 'selected' : '' ?>>Actif</option>
                    <option value="Terminé" <?= $statut_filter === 'Terminé' ? 'selected' : '' ?>>Terminé</option>
                    <option value="Annulé" <?= $statut_filter === 'Annulé' ? 'selected' : '' ?>>Annulé</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-slate-300 mb-2">Année</label>
                <select name="annee" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    <option value="">Toutes</option>
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $annee_filter == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                Filtrer
            </button>
        </form>
    </div>

    <!-- Statistiques rapides -->
    <?php
    $total_actif = 0;
    $total_mensuel = 0;
    $nb_actif = 0;
    foreach ($charges as $charge) {
        if ($charge['statut'] === 'Actif') {
            $total_actif += $charge['montant_total'];
            $total_mensuel += $charge['montant_mensuel'];
            $nb_actif++;
        }
    }
    ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-200 text-sm">CCA Actives</p>
                    <p class="text-2xl font-bold mt-1"><?= $nb_actif ?></p>
                </div>
                <svg class="w-10 h-10 text-blue-200 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
        </div>
        <div class="bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-lg p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-emerald-200 text-sm">Total à répartir</p>
                    <p class="text-2xl font-bold mt-1"><?= number_format($total_actif, 0, ',', ' ') ?> F</p>
                </div>
                <svg class="w-10 h-10 text-emerald-200 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-200 text-sm">Par mois (moyen)</p>
                    <p class="text-2xl font-bold mt-1"><?= number_format($total_mensuel, 0, ',', ' ') ?> F</p>
                </div>
                <svg class="w-10 h-10 text-purple-200 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Tableau des charges -->
    <div class="bg-slate-800 rounded-lg overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">N° Facture</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Fournisseur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Description</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Compte</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Période</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-300 uppercase">Montant total</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-300 uppercase">Par mois</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-300 uppercase">Statut</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($charges)): ?>
                <tr>
                    <td colspan="10" class="px-4 py-8 text-center text-slate-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Aucune charge constatée d'avance enregistrée
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($charges as $charge): ?>
                <tr class="hover:bg-slate-700/50 transition">
                    <td class="px-4 py-3 text-sm text-white font-mono"><?= htmlspecialchars($charge['numero_facture']) ?></td>
                    <td class="px-4 py-3 text-sm text-slate-300"><?= date('d/m/Y', strtotime($charge['date_facture'])) ?></td>
                    <td class="px-4 py-3 text-sm text-slate-300">
                        <?php if ($charge['tiers_nom']): ?>
                            <span class="text-blue-400"><?= htmlspecialchars($charge['tiers_nom']) ?></span>
                        <?php else: ?>
                            <span class="text-slate-500">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-300 max-w-xs truncate" title="<?= htmlspecialchars($charge['description']) ?>">
                        <?= htmlspecialchars($charge['description']) ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <span class="text-emerald-400 font-mono"><?= htmlspecialchars($charge['compte_charge']) ?></span>
                        <span class="text-xs text-slate-500 block"><?= htmlspecialchars($charge['compte_charge_libelle'] ?? '') ?></span>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-300">
                        <div class="text-xs">
                            <?= date('d/m/Y', strtotime($charge['date_debut'])) ?> au<br>
                            <?= date('d/m/Y', strtotime($charge['date_fin'])) ?>
                            <span class="text-slate-500">(<?= $charge['nb_mois'] ?> mois)</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-semibold text-white">
                        <?= number_format($charge['montant_total'], 0, ',', ' ') ?> F
                    </td>
                    <td class="px-4 py-3 text-sm text-right text-purple-400 font-semibold">
                        <?= number_format($charge['montant_mensuel'], 0, ',', ' ') ?> F
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $badge_colors = [
                            'Actif' => 'bg-emerald-500/10 text-emerald-400',
                            'Terminé' => 'bg-slate-500/10 text-slate-400',
                            'Annulé' => 'bg-red-500/10 text-red-400'
                        ];
                        $color = $badge_colors[$charge['statut']] ?? 'bg-slate-500/10 text-slate-400';
                        ?>
                        <span class="px-2 py-1 rounded-full text-xs font-medium <?= $color ?>">
                            <?= $charge['statut'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <button onclick='openEditModal(<?= json_encode($charge) ?>)' class="text-blue-400 hover:text-blue-300 transition" title="Modifier">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            <button onclick="deleteCharge(<?= $charge['id'] ?>, '<?= htmlspecialchars($charge['numero_facture']) ?>')" class="text-red-400 hover:text-red-300 transition" title="Supprimer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Ajouter/Modifier CCA -->
<div id="cca-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50">
    <div class="bg-slate-800 rounded-lg w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto">
        <form method="POST" id="cca-form">
            <input type="hidden" name="action" id="modal-action" value="add">
            <input type="hidden" name="id" id="modal-id">

            <div class="p-6 border-b border-slate-700">
                <h2 class="text-xl font-bold text-white" id="modal-title">Nouvelle Charge Constatée d'Avance</h2>
            </div>

            <div class="p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">N° Facture *</label>
                        <input type="text" name="numero_facture" id="numero_facture" required
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Date Facture *</label>
                        <input type="date" name="date_facture" id="date_facture" required
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Fournisseur</label>
                    <select name="id_tiers" id="id_tiers" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                        <option value="">-- Aucun --</option>
                        <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['compte_tiers'] . ' - ' . $f['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Description *</label>
                    <textarea name="description" id="description" required rows="2"
                              class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Compte de charge *</label>
                    <select name="compte_charge" id="compte_charge" required
                            class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($comptes_charges as $c): ?>
                        <option value="<?= $c['compte'] ?>"><?= htmlspecialchars($c['compte'] . ' - ' . $c['intitule_compte']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Montant Total *</label>
                        <input type="number" name="montant_total" id="montant_total" required step="0.01"
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Date Début *</label>
                        <input type="date" name="date_debut" id="date_debut" required
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Date Fin *</label>
                        <input type="date" name="date_fin" id="date_fin" required
                               class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                    </div>
                </div>

                <div id="calcul-info" class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-3 hidden">
                    <p class="text-sm text-blue-400">
                        <span class="font-semibold">Répartition:</span>
                        <span id="nb-mois-calc">0</span> mois ×
                        <span id="montant-mensuel-calc">0</span> F/mois
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Notes</label>
                    <textarea name="notes" id="notes" rows="2"
                              class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white"></textarea>
                </div>

                <div id="statut-field" class="hidden">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Statut</label>
                    <select name="statut" id="statut" class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white">
                        <option value="Actif">Actif</option>
                        <option value="Terminé">Terminé</option>
                        <option value="Annulé">Annulé</option>
                    </select>
                </div>
            </div>

            <div class="p-6 border-t border-slate-700 flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modal-title').textContent = 'Nouvelle Charge Constatée d\'Avance';
    document.getElementById('modal-action').value = 'add';
    document.getElementById('cca-form').reset();
    document.getElementById('statut-field').classList.add('hidden');
    document.getElementById('cca-modal').classList.remove('hidden');
    document.getElementById('cca-modal').classList.add('flex');
    document.getElementById('date_facture').value = new Date().toISOString().split('T')[0];
}

function openEditModal(charge) {
    document.getElementById('modal-title').textContent = 'Modifier Charge Constatée d\'Avance';
    document.getElementById('modal-action').value = 'edit';
    document.getElementById('modal-id').value = charge.id;
    document.getElementById('numero_facture').value = charge.numero_facture;
    document.getElementById('date_facture').value = charge.date_facture;
    document.getElementById('id_tiers').value = charge.id_tiers || '';
    document.getElementById('description').value = charge.description;
    document.getElementById('compte_charge').value = charge.compte_charge;
    document.getElementById('montant_total').value = charge.montant_total;
    document.getElementById('date_debut').value = charge.date_debut;
    document.getElementById('date_fin').value = charge.date_fin;
    document.getElementById('notes').value = charge.notes || '';
    document.getElementById('statut').value = charge.statut;
    document.getElementById('statut-field').classList.remove('hidden');
    document.getElementById('cca-modal').classList.remove('hidden');
    document.getElementById('cca-modal').classList.add('flex');
    calculateRepartition();
}

function closeModal() {
    document.getElementById('cca-modal').classList.add('hidden');
    document.getElementById('cca-modal').classList.remove('flex');
}

function deleteCharge(id, numero) {
    if (confirm(`Êtes-vous sûr de vouloir supprimer la CCA ${numero} ?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Calcul automatique de la répartition
function calculateRepartition() {
    const montant = parseFloat(document.getElementById('montant_total').value) || 0;
    const dateDebut = new Date(document.getElementById('date_debut').value);
    const dateFin = new Date(document.getElementById('date_fin').value);

    if (montant > 0 && dateDebut && dateFin && dateFin >= dateDebut) {
        // Calcul précis du nombre de mois
        let nbMois = (dateFin.getFullYear() - dateDebut.getFullYear()) * 12;
        nbMois += dateFin.getMonth() - dateDebut.getMonth();
        nbMois += 1; // Inclure le mois de début

        const montantMensuel = montant / nbMois;

        document.getElementById('nb-mois-calc').textContent = nbMois;
        document.getElementById('montant-mensuel-calc').textContent = montantMensuel.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        document.getElementById('calcul-info').classList.remove('hidden');
    } else {
        document.getElementById('calcul-info').classList.add('hidden');
    }
}

document.getElementById('montant_total').addEventListener('input', calculateRepartition);
document.getElementById('date_debut').addEventListener('change', calculateRepartition);
document.getElementById('date_fin').addEventListener('change', calculateRepartition);

// Fermer modal en cliquant en dehors
document.getElementById('cca-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

            </div>
        </main>
    </div>
</body>
</html>
