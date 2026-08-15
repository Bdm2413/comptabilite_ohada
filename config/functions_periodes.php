<?php
/**
 * Fonctions de gestion des périodes comptables
 * Standard : SYSCOHADA Révisé - inspiré du modèle Oracle GL
 *
 * Principe central : aucune écriture ne peut être saisie sur une période
 * dont le statut est autre que 'Ouvert'. Ce verrou est la fondation du GL.
 */

// Noms des mois en français
const MOIS_FR = [
    1=>'Janvier', 2=>'Février', 3=>'Mars', 4=>'Avril',
    5=>'Mai', 6=>'Juin', 7=>'Juillet', 8=>'Août',
    9=>'Septembre', 10=>'Octobre', 11=>'Novembre', 12=>'Décembre',
];

// Couleurs de statut (pour les vues)
const PERIODE_STATUT_COLORS = [
    'Jamais_ouvert'         => ['bg'=>'bg-slate-700/40',  'text'=>'text-slate-400',   'icon'=>'fa-minus-circle'],
    'Ouvert'                => ['bg'=>'bg-emerald-900/40', 'text'=>'text-emerald-300', 'icon'=>'fa-unlock'],
    'Fermé'                 => ['bg'=>'bg-amber-900/40',   'text'=>'text-amber-300',   'icon'=>'fa-lock'],
    'Définitivement_fermé'  => ['bg'=>'bg-red-900/40',     'text'=>'text-red-300',     'icon'=>'fa-ban'],
];

/**
 * Génère automatiquement les 12 périodes mensuelles pour un exercice.
 * Appelée à la création d'un exercice.
 * La période contenant la date du jour est ouverte ; les autres restent 'Jamais_ouvert'.
 *
 * @param int    $exercice_id
 * @param int    $societe_id
 * @param string $date_debut  Format YYYY-MM-DD
 * @param string $date_fin    Format YYYY-MM-DD
 * @param int    $user_id     Utilisateur qui crée l'exercice
 * @return int   Nombre de périodes créées
 */
function generatePeriodesExercice(int $exercice_id, int $societe_id, string $date_debut, string $date_fin, int $user_id): int
{
    $db = Database::getInstance()->getConnection();

    // Supprimer les éventuelles périodes déjà existantes pour cet exercice/société
    $db->prepare("DELETE FROM periodes WHERE id_exercice = ? AND societe_id = ?")
       ->execute([$exercice_id, $societe_id]);

    $debut   = new DateTime($date_debut);
    $fin     = new DateTime($date_fin);
    $today   = new DateTime();
    $created = 0;

    $ins = $db->prepare("
        INSERT INTO periodes
            (societe_id, id_exercice, type_periode, periode_numero, date_debut, date_fin,
             libelle, type_periode_detail, statut, ouvert_par, date_ouverture, cloture)
        VALUES
            (?, ?, 'mensuelle', ?, ?, ?, ?, 'normal', ?, ?, ?, 0)
    ");

    $current = clone $debut;
    $numero  = 1;

    while ($current <= $fin && $numero <= 12) {
        // Dernier jour du mois courant (mais pas après date_fin)
        $finMois = new DateTime($current->format('Y-m-t'));
        if ($finMois > $fin) {
            $finMois = clone $fin;
        }

        $moisLabel = MOIS_FR[(int)$current->format('n')] . ' ' . $current->format('Y');

        // Ouvrir automatiquement la période contenant la date du jour
        $estCourante = ($today >= $current && $today <= $finMois);
        $statut      = $estCourante ? 'Ouvert' : 'Jamais_ouvert';
        $ouvertPar   = $estCourante ? $user_id : null;
        $dateOuvert  = $estCourante ? date('Y-m-d H:i:s') : null;

        $ins->execute([
            $societe_id,
            $exercice_id,
            $numero,
            $current->format('Y-m-d'),
            $finMois->format('Y-m-d'),
            $moisLabel,
            $statut,
            $ouvertPar,
            $dateOuvert,
        ]);

        $created++;
        $numero++;

        // Avancer au premier jour du mois suivant
        $current = new DateTime($current->format('Y-m-01'));
        $current->modify('+1 month');
    }

    return $created;
}

/**
 * Retourne la période correspondant à une date pour une société.
 *
 * @param string $date       Format YYYY-MM-DD ou tout format PHP
 * @param int    $societe_id
 * @return array|null        Ligne de la table periodes, ou null si introuvable
 */
function getPeriodeByDate(string $date, int $societe_id): ?array
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT p.*, e.annee
        FROM periodes p
        JOIN exercices e ON p.id_exercice = e.id
        WHERE p.societe_id = ?
          AND p.date_debut <= ?
          AND p.date_fin   >= ?
          AND p.type_periode_detail = 'normal'
        ORDER BY p.date_debut DESC
        LIMIT 1
    ");
    $stmt->execute([$societe_id, $date, $date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Vérifie qu'une période est ouverte pour une date et une société données.
 * Lève une exception si la période est fermée ou introuvable.
 * À appeler dans TOUS les points d'écriture comptable.
 *
 * @param string $date_ecriture  Date de l'écriture (YYYY-MM-DD)
 * @param int    $societe_id
 * @throws RuntimeException      Si la période n'est pas ouverte
 */
function requirePeriodeOuverte(string $date_ecriture, int $societe_id): void
{
    $periode = getPeriodeByDate($date_ecriture, $societe_id);

    if (!$periode) {
        throw new RuntimeException(
            "Aucune période comptable trouvée pour la date $date_ecriture. " .
            "Vérifiez que l'exercice correspondant existe et que ses périodes sont générées."
        );
    }

    if ($periode['statut'] !== 'Ouvert') {
        $labels = [
            'Jamais_ouvert'        => "n'a pas encore été ouverte",
            'Fermé'                => 'est fermée',
            'Définitivement_fermé' => 'est définitivement fermée (clôture annuelle)',
        ];
        $detail = $labels[$periode['statut']] ?? 'n\'est pas disponible';
        throw new RuntimeException(
            "Saisie impossible : la période {$periode['libelle']} $detail. " .
            "Contactez l'administrateur pour ouvrir cette période."
        );
    }
}

/**
 * Ouvre une période (statut -> Ouvert).
 * Seul un admin peut appeler cette fonction.
 *
 * @param int $periode_id
 * @param int $societe_id
 * @param int $user_id
 * @return bool
 * @throws RuntimeException si la période est Définitivement_fermée
 */
function ouvrirPeriode(int $periode_id, int $societe_id, int $user_id): bool
{
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT * FROM periodes WHERE id_periode = ? AND societe_id = ?");
    $stmt->execute([$periode_id, $societe_id]);
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        throw new RuntimeException("Période introuvable.");
    }

    if ($periode['statut'] === 'Définitivement_fermé') {
        throw new RuntimeException(
            "La période {$periode['libelle']} est définitivement fermée (clôture annuelle). Elle ne peut plus être rouverte."
        );
    }

    if ($periode['statut'] === 'Ouvert') {
        return true; // Déjà ouverte, pas d'erreur
    }

    $db->prepare("
        UPDATE periodes
        SET statut = 'Ouvert', ouvert_par = ?, date_ouverture = NOW(), cloture = 0, updated_at = NOW()
        WHERE id_periode = ? AND societe_id = ?
    ")->execute([$user_id, $periode_id, $societe_id]);

    logActivity("Ouverture période {$periode['libelle']}", 'periodes', $periode_id, $periode['libelle']);
    return true;
}

/**
 * Ferme une période.
 *
 * @param int  $periode_id
 * @param int  $societe_id
 * @param int  $user_id
 * @param bool $definitif   true = clôture annuelle irréversible
 * @return bool
 * @throws RuntimeException
 */
function fermerPeriode(int $periode_id, int $societe_id, int $user_id, bool $definitif = false): bool
{
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT * FROM periodes WHERE id_periode = ? AND societe_id = ?");
    $stmt->execute([$periode_id, $societe_id]);
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        throw new RuntimeException("Période introuvable.");
    }

    if ($periode['statut'] === 'Définitivement_fermé') {
        throw new RuntimeException("La période {$periode['libelle']} est déjà définitivement fermée.");
    }

    $nouveauStatut = $definitif ? 'Définitivement_fermé' : 'Fermé';

    $db->prepare("
        UPDATE periodes
        SET statut = ?, ferme_par = ?, date_cloture_periode = NOW(), cloture = 1, updated_at = NOW()
        WHERE id_periode = ? AND societe_id = ?
    ")->execute([$nouveauStatut, $user_id, $periode_id, $societe_id]);

    $action = $definitif ? "Clôture définitive période" : "Clôture période";
    logActivity("$action {$periode['libelle']}", 'periodes', $periode_id, $periode['libelle']);
    return true;
}

/**
 * Retourne toutes les périodes d'un exercice pour une société.
 *
 * @param int $exercice_id
 * @param int $societe_id
 * @return array
 */
function getPeriodesExercice(int $exercice_id, int $societe_id): array
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT p.*,
               ou.nom_utilisateur AS ouvert_par_nom,
               fe.nom_utilisateur AS ferme_par_nom
        FROM periodes p
        LEFT JOIN utilisateurs ou ON p.ouvert_par  = ou.id_utilisateur
        LEFT JOIN utilisateurs fe ON p.ferme_par   = fe.id_utilisateur
        WHERE p.id_exercice = ? AND p.societe_id = ?
        ORDER BY p.type_periode_detail ASC, p.periode_numero ASC
    ");
    $stmt->execute([$exercice_id, $societe_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retourne la période actuellement ouverte pour une société.
 *
 * @param int $societe_id
 * @return array|null
 */
function getPeriodeOuverte(int $societe_id): ?array
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT p.*, e.annee
        FROM periodes p
        JOIN exercices e ON p.id_exercice = e.id
        WHERE p.societe_id = ? AND p.statut = 'Ouvert'
        ORDER BY p.date_debut ASC
        LIMIT 1
    ");
    $stmt->execute([$societe_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}
