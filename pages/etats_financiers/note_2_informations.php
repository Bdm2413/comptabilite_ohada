<?php
require_once '../../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$societe_id = getCurrentSocieteId();
if (!$societe_id) { header('Location: ../dashboard/index.php'); exit; }

// ── Création de la table ──────────────────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS note_2_informations (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        societe_id  INT NOT NULL,
        exercice    YEAR NOT NULL,
        cle         VARCHAR(20) NOT NULL,
        contenu     TEXT,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_soc_ex_cle (societe_id, exercice, cle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$date_debut = $_GET['date_debut'] ?? date('Y-01-01');
$date_fin   = $_GET['date_fin']   ?? date('Y-12-31');
$annee_n    = (int)date('Y', strtotime($date_fin));
$qs         = http_build_query(['date_debut' => $date_debut, 'date_fin' => $date_fin]);

$message = '';

// ── Sauvegarde ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $champs = [
        'A_conformite', 'A_faits_marquants',
        'B_bases', 'B_immos_inc', 'B_immos_corp', 'B_stocks',
        'B_creances', 'B_provisions', 'B_devises', 'B_autres',
        'C_derogations',
    ];
    $stmt = $db->prepare("
        INSERT INTO note_2_informations (societe_id, exercice, cle, contenu)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE contenu = VALUES(contenu), updated_at = NOW()
    ");
    foreach ($champs as $cle) {
        $val = trim($_POST[$cle] ?? '');
        $stmt->execute([$societe_id, $annee_n, $cle, $val ?: null]);
    }
    $_SESSION['note2_msg'] = 'Informations sauvegardées.';
    header("Location: note_2_informations.php?$qs");
    exit;
}

if (isset($_SESSION['note2_msg'])) {
    $message = $_SESSION['note2_msg'];
    unset($_SESSION['note2_msg']);
}

// ── Chargement ───────────────────────────────────────────────────────────────
$rows = $db->prepare("SELECT cle, contenu FROM note_2_informations WHERE societe_id=? AND exercice=?");
$rows->execute([$societe_id, $annee_n]);
$data = [];
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) $data[$r['cle']] = $r['contenu'];

function val(array $d, string $k): string {
    return htmlspecialchars($d[$k] ?? '');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Note 2 — Informations obligatoires <?= $annee_n ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-section { border: 1px solid #d1d5db !important; margin-bottom: 16px; }
            .print-section h3 { background: #f3f4f6 !important; color: #111 !important; }
            .print-content { color: black !important; white-space: pre-wrap; }
        }
        textarea { resize: vertical; }
        .section-card { transition: border-color 0.2s; }
        .section-card:focus-within { border-color: rgba(99, 102, 241, 0.5); }
    </style>
</head>
<body class="bg-slate-900 text-slate-100">
<div class="flex h-screen overflow-hidden">

<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">

    <!-- En-tête -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-400 mb-1">
                <i class="fas fa-info-circle mr-3"></i>Note 2 — Informations obligatoires
            </h1>
            <p class="text-slate-400 text-sm">Exercice <?= $annee_n ?> — SYSCOHADA Système Normal</p>
        </div>
        <div class="flex gap-2 no-print">
            <a href="notes_annexes.php?<?= $qs ?>"
               class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors inline-flex items-center gap-2 text-sm">
                <i class="fas fa-arrow-left"></i> Toutes les notes
            </a>
            <button onclick="window.print()"
                    class="px-4 py-2 bg-slate-600 hover:bg-slate-500 text-white rounded-lg inline-flex items-center gap-2 text-sm">
                <i class="fas fa-print"></i> Imprimer
            </button>
        </div>
    </div>

    <!-- Filtre exercice -->
    <form method="GET" class="no-print mb-6 bg-slate-800 border border-slate-700 rounded-xl p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Début N</label>
                <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1">Fin N</label>
                <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-100 text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Afficher
            </button>
        </div>
    </form>

    <?php if ($message): ?>
    <div class="no-print mb-4 px-4 py-3 bg-emerald-900/30 border border-emerald-700 text-emerald-300 rounded-lg text-sm">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <form method="POST">

    <!-- ══════════════════════════════════════════════════════════
         SECTION A — Déclaration de conformité & faits marquants
    ══════════════════════════════════════════════════════════ -->
    <div class="section-card print-section bg-slate-800 border border-slate-700 rounded-xl mb-5 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-white text-sm font-bold shrink-0">A</span>
            <div>
                <h3 class="font-bold text-slate-100 text-sm">Déclaration de conformité au SYSCOHADA et faits marquants de l'exercice</h3>
                <p class="text-xs text-slate-500 mt-0.5">Déclarer la conformité au référentiel SYSCOHADA. Mentionner les faits marquants ayant une incidence comptable significative.</p>
            </div>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-semibold text-indigo-300 mb-2 uppercase tracking-wide">
                    <i class="fas fa-check-double mr-1"></i>Déclaration de conformité
                </label>
                <textarea name="A_conformite" rows="3"
                          placeholder="Ex. : Les états financiers de l'exercice clos le 31 décembre <?= $annee_n ?> ont été établis conformément aux dispositions du SYSCOHADA révisé adopté par l'OHADA le 26 janvier 2017..."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 print-content"><?= val($data,'A_conformite') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-semibold text-indigo-300 mb-2 uppercase tracking-wide">
                    <i class="fas fa-flag mr-1"></i>Faits marquants de l'exercice
                </label>
                <textarea name="A_faits_marquants" rows="5"
                          placeholder="Décrire les événements significatifs de l'exercice ayant une incidence sur la comparabilité (création d'activité, cession, restructuration, perte d'un client principal, pandémie, sinistre...)&#10;Laisser vide s'il n'y a aucun fait marquant à signaler."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 print-content"><?= val($data,'A_faits_marquants') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION B — Règles et méthodes comptables
    ══════════════════════════════════════════════════════════ -->
    <div class="section-card print-section bg-slate-800 border border-slate-700 rounded-xl mb-5 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white text-sm font-bold shrink-0">B</span>
            <div>
                <h3 class="font-bold text-slate-100 text-sm">Règles et méthodes comptables</h3>
                <p class="text-xs text-slate-500 mt-0.5">Décrire les règles et méthodes utilisées pour l'établissement des états financiers.</p>
            </div>
        </div>
        <div class="p-6 space-y-5">

            <!-- B1 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B1 — Bases générales d'évaluation
                </label>
                <textarea name="B_bases" rows="3"
                          placeholder="Ex. : Les états financiers sont établis selon la convention du coût historique. Les actifs sont évalués à leur coût d'acquisition ou de production."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_bases') ?></textarea>
            </div>

            <!-- B2 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B2 — Immobilisations incorporelles
                </label>
                <textarea name="B_immos_inc" rows="3"
                          placeholder="Méthode d'évaluation et d'amortissement (linéaire, dégressif...). Durée d'utilité retenue. Traitement des frais de développement, logiciels, fonds commercial..."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_immos_inc') ?></textarea>
            </div>

            <!-- B3 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B3 — Immobilisations corporelles
                </label>
                <textarea name="B_immos_corp" rows="4"
                          placeholder="Méthode d'amortissement (linéaire / dégressif). Durées d'utilité retenues par catégorie (bâtiments, matériels, véhicules...). Traitement des révisions de valeur résiduelle. Critères d'activation des dépenses ultérieures."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_immos_corp') ?></textarea>
            </div>

            <!-- B4 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B4 — Stocks et en-cours
                </label>
                <textarea name="B_stocks" rows="3"
                          placeholder="Méthode de valorisation : CMUP (Coût Moyen Unitaire Pondéré), PEPS (FIFO), ou autre. Règle de dépréciation (valeur nette de réalisation)..."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_stocks') ?></textarea>
            </div>

            <!-- B5 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B5 — Créances et dettes
                </label>
                <textarea name="B_creances" rows="3"
                          placeholder="Créances enregistrées à leur valeur nominale. Dépréciations constituées pour les créances douteuses sur la base du risque de non-recouvrement estimé. Dettes enregistrées à leur valeur de remboursement..."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_creances') ?></textarea>
            </div>

            <!-- B6 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B6 — Provisions pour risques et charges
                </label>
                <textarea name="B_provisions" rows="3"
                          placeholder="Les provisions sont constituées dès lors qu'il existe une obligation probable envers un tiers et que son montant peut être estimé de manière fiable..."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_provisions') ?></textarea>
            </div>

            <!-- B7 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B7 — Opérations en devises étrangères
                </label>
                <textarea name="B_devises" rows="3"
                          placeholder="Les opérations en devises sont converties au cours de change en vigueur à la date de l'opération. Les écarts de conversion sont enregistrés en compte d'attente...&#10;Laisser vide si l'entité n'a pas d'opérations en devises."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_devises') ?></textarea>
            </div>

            <!-- B8 -->
            <div class="border-l-2 border-purple-700/50 pl-4">
                <label class="block text-xs font-semibold text-purple-300 mb-2 uppercase tracking-wide">
                    B8 — Autres méthodes et principes
                </label>
                <textarea name="B_autres" rows="3"
                          placeholder="Contrats pluriannuels, concessions, comptes intermédiaires, fusions, méthodes spécifiques au secteur d'activité..."
                          class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-purple-500 print-content"><?= val($data,'B_autres') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION C — Dérogations aux postulats et conventions
    ══════════════════════════════════════════════════════════ -->
    <div class="section-card print-section bg-slate-800 border border-slate-700 rounded-xl mb-6 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center gap-3">
            <span class="w-8 h-8 rounded-full bg-rose-700 flex items-center justify-center text-white text-sm font-bold shrink-0">C</span>
            <div>
                <h3 class="font-bold text-slate-100 text-sm">Dérogation aux postulats et conventions comptables</h3>
                <p class="text-xs text-slate-500 mt-0.5">À remplir uniquement si des dérogations aux principes SYSCOHADA ont été appliquées. Laisser vide si aucune dérogation.</p>
            </div>
        </div>
        <div class="p-6">
            <textarea name="C_derogations" rows="5"
                      placeholder="Indiquer les dérogations aux postulats (continuité d'exploitation, permanence des méthodes, spécialisation des exercices...) ou aux conventions (prudence, coût historique, importance significative...) avec justification.&#10;&#10;Laisser vide si aucune dérogation n'a été appliquée."
                      class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:ring-2 focus:ring-rose-500 print-content"><?= val($data,'C_derogations') ?></textarea>
        </div>
    </div>

    <!-- Bouton enregistrer -->
    <div class="no-print flex justify-end gap-3">
        <a href="notes_annexes.php?<?= $qs ?>"
           class="px-5 py-2.5 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm transition-colors inline-flex items-center gap-2">
            <i class="fas fa-times"></i> Annuler
        </a>
        <button type="submit"
                class="px-6 py-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white rounded-lg text-sm font-semibold transition-all shadow-lg inline-flex items-center gap-2">
            <i class="fas fa-save"></i> Enregistrer toutes les sections
        </button>
    </div>

    </form>

</main>
</div>
<script>
anime({ targets: '#sidebar', opacity: [0, 1], duration: 600, easing: 'easeOutQuad' });
</script>
</body>
</html>
