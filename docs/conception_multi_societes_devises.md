# 🏢 Conception Multi-Sociétés et Multi-Devises
## Application Comptabilité SYSCOHADA Révisé

**Date de création:** 30 Décembre 2024
**Version:** 1.0
**Statut:** En cours d'implémentation

---

## 📋 Table des matières

1. [Contexte et Objectifs](#contexte-et-objectifs)
2. [Cas d'usage](#cas-dusage)
3. [Architecture proposée](#architecture-proposée)
4. [Gestion Multi-Sociétés](#gestion-multi-sociétés)
5. [Gestion Multi-Devises](#gestion-multi-devises)
6. [Gestion des Droits d'Accès](#gestion-des-droits-daccès)
7. [Détection de Première Installation](#détection-de-première-installation)
8. [Structure de Base de Données](#structure-de-base-de-données)
9. [Plan de Migration](#plan-de-migration)
10. [Interface Utilisateur](#interface-utilisateur)
11. [Considérations Techniques](#considérations-techniques)

---

## 🎯 Contexte et Objectifs

### Problématique

Le système actuel gère une seule entité comptable. Pour élargir son utilisation, il est nécessaire de supporter:

- **Cabinets d'expertise comptable** gérant plusieurs dossiers clients
- **Groupes de sociétés** avec filiales et consolidation
- **Entreprises individuelles** évolutives vers multi-entités
- **Transactions en devises multiples** pour les sociétés à l'international

### Objectifs

1. ✅ Permettre la gestion de **multiples sociétés** dans une seule installation
2. ✅ Gérer des **transactions multi-devises** avec conversion automatique
3. ✅ Maintenir l'**isolation des données** entre sociétés
4. ✅ Rester **simple** pour une société unique
5. ✅ Permettre la **consolidation** pour les groupes
6. ✅ Assurer une **migration transparente** des données existantes

---

## 👥 Cas d'usage

### Cas 1: Cabinet d'Expertise Comptable

**Besoin:**
- Gérer 10 à 100+ dossiers clients
- Isoler complètement les données de chaque client
- Tenir sa propre comptabilité interne
- Facturer ses prestations

**Utilisation:**
```
Cabinet EXPERTISE SARL
  ├─ Dossier: Client A (Commerce)
  ├─ Dossier: Client B (Industrie)
  ├─ Dossier: Client C (Services)
  └─ Dossier: EXPERTISE SARL (propre comptabilité)
```

### Cas 2: Groupe de Sociétés

**Besoin:**
- Gérer comptabilité de chaque filiale séparément
- Consolider les comptes du groupe
- Gérer plusieurs devises (XOF, EUR, USD)
- Transactions inter-sociétés

**Utilisation:**
```
Groupe HOLDING SA
  ├─ Société Mère (Côte d'Ivoire, XOF)
  ├─ Filiale France (EUR)
  ├─ Filiale USA (USD)
  └─ Consolidation Groupe
```

### Cas 3: Entreprise Individuelle

**Besoin:**
- Gérer sa comptabilité principale
- Possible évolution future (création filiale)
- Pas de complexité inutile

**Utilisation:**
```
PME SARL
  └─ Comptabilité unique (interface simple, pas de sélecteur)
```

### Cas 4: Société Multi-Devises

**Besoin:**
- Comptabilité en XOF (devise principale)
- Factures clients en EUR et USD
- Fournisseurs en différentes devises
- Conversion automatique au taux du jour

**Utilisation:**
```
Import/Export SARL (XOF)
  ├─ Achats fournisseurs (USD, EUR, CNY)
  ├─ Ventes clients (EUR, USD, XOF)
  └─ Conversion automatique vers XOF
```

---

## 🏗️ Architecture proposée

### Choix de l'architecture: **Option 2 - Colonne société_id**

Après analyse, nous retenons l'approche **mono-base avec colonne société_id** pour:

✅ **Simplicité d'implémentation** - modification progressive du code existant
✅ **Flexibilité** - permet consolidation ET séparation
✅ **Un seul système** - pas de multiplication de bases de données
✅ **Migration facile** - possibilité d'évoluer vers multi-schémas plus tard
✅ **Performance acceptable** - avec indexation appropriée

### Hiérarchie des entités

```
┌─────────────────────────────────────────┐
│  NIVEAU 1: CABINET / GROUPE (optionnel) │
│  - Entité de niveau supérieur           │
│  - Gère plusieurs sociétés               │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  NIVEAU 2: SOCIÉTÉ / DOSSIER            │
│  - Entité comptable principale           │
│  - Propre plan comptable                 │
│  - Propre devise                         │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  NIVEAU 3: EXERCICE COMPTABLE           │
│  - Périodes comptables                   │
│  - Écritures                             │
└─────────────────────────────────────────┘
```

### Comparaison des approches

| Critère | Option 1: Multi-Schémas | Option 2: Colonne société_id |
|---------|------------------------|------------------------------|
| Isolation données | ⭐⭐⭐⭐⭐ Maximale | ⭐⭐⭐ Bonne (avec code) |
| Performance | ⭐⭐⭐⭐⭐ Excellente | ⭐⭐⭐⭐ Bonne (indexée) |
| Complexité | ⭐⭐ Complexe | ⭐⭐⭐⭐ Simple |
| Consolidation | ⭐⭐ Difficile | ⭐⭐⭐⭐⭐ Facile |
| Maintenance | ⭐⭐ Lourde | ⭐⭐⭐⭐ Légère |
| Migration | ⭐⭐ Complexe | ⭐⭐⭐⭐⭐ Simple |

**→ Option 2 retenue pour la version 1.0**

---

## 🏢 Gestion Multi-Sociétés

### Table `cabinets` (optionnel)

Pour les cabinets d'expertise comptable qui gèrent plusieurs clients:

```sql
CREATE TABLE cabinets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_cabinet VARCHAR(20) UNIQUE NOT NULL,
    raison_sociale VARCHAR(255) NOT NULL,
    forme_juridique VARCHAR(50),
    adresse TEXT,
    telephone VARCHAR(20),
    email VARCHAR(150),
    site_web VARCHAR(255),
    logo VARCHAR(255),
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_code (code_cabinet),
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table `societes`

Entité comptable principale (société, filiale, ou dossier client):

```sql
CREATE TABLE societes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code_societe VARCHAR(20) UNIQUE NOT NULL,
    raison_sociale VARCHAR(255) NOT NULL,
    forme_juridique VARCHAR(50),

    -- Type d'entité
    type_entite ENUM(
        'entreprise_individuelle',  -- PME seule
        'groupe',                    -- Holding avec filiales
        'cabinet',                   -- Cabinet comptable
        'filiale',                   -- Filiale d'un groupe
        'dossier_client'            -- Dossier dans un cabinet
    ) NOT NULL DEFAULT 'entreprise_individuelle',

    -- Rattachement
    id_cabinet INT NULL,           -- Si c'est un dossier client
    id_societe_mere INT NULL,      -- Si c'est une filiale

    -- Informations légales
    numero_rccm VARCHAR(50),
    numero_contribuable VARCHAR(50),
    forme_juridique VARCHAR(50),
    capital DECIMAL(15,2),

    -- Adresse
    adresse TEXT,
    ville VARCHAR(100),
    pays VARCHAR(100) DEFAULT 'Côte d''Ivoire',
    telephone VARCHAR(20),
    email VARCHAR(150),
    site_web VARCHAR(255),

    -- Paramètres comptables
    devise_principale VARCHAR(3) NOT NULL DEFAULT 'XOF',
    regime_fiscal ENUM('reel_normal', 'reel_simplifie', 'impot_synthetique') DEFAULT 'reel_normal',
    date_debut_activite DATE,

    -- Gestion
    logo VARCHAR(255),
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index
    INDEX idx_code (code_societe),
    INDEX idx_type (type_entite),
    INDEX idx_cabinet (id_cabinet),
    INDEX idx_mere (id_societe_mere),
    INDEX idx_actif (actif),
    INDEX idx_devise (devise_principale),

    -- Contraintes
    FOREIGN KEY (id_cabinet) REFERENCES cabinets(id) ON DELETE SET NULL,
    FOREIGN KEY (id_societe_mere) REFERENCES societes(id) ON DELETE SET NULL,
    FOREIGN KEY (devise_principale) REFERENCES devises(code_devise)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Modification des tables existantes

Toutes les tables métiers doivent inclure `societe_id`:

```sql
-- Tables à modifier:
ALTER TABLE plan_comptable ADD COLUMN societe_id INT NOT NULL AFTER id_compte;
ALTER TABLE tiers ADD COLUMN societe_id INT NOT NULL AFTER id_tiers;
ALTER TABLE journaux ADD COLUMN societe_id INT NOT NULL AFTER id_journal;
ALTER TABLE exercices ADD COLUMN societe_id INT NOT NULL AFTER id_exercice;
ALTER TABLE pieces_comptables ADD COLUMN societe_id INT NOT NULL AFTER id_piece;
ALTER TABLE ecritures_comptables ADD COLUMN societe_id INT NOT NULL AFTER id_ecriture;
-- ... (toutes les tables métiers)

-- Ajout des index pour performance
CREATE INDEX idx_societe ON plan_comptable(societe_id);
CREATE INDEX idx_societe ON tiers(societe_id);
CREATE INDEX idx_societe ON journaux(societe_id);
CREATE INDEX idx_societe ON exercices(societe_id);
CREATE INDEX idx_societe ON pieces_comptables(societe_id);
CREATE INDEX idx_societe ON ecritures_comptables(societe_id);

-- Ajout des contraintes de clé étrangère
ALTER TABLE plan_comptable ADD CONSTRAINT fk_pc_societe
    FOREIGN KEY (societe_id) REFERENCES societes(id) ON DELETE RESTRICT;
ALTER TABLE tiers ADD CONSTRAINT fk_tiers_societe
    FOREIGN KEY (societe_id) REFERENCES societes(id) ON DELETE RESTRICT;
-- ... (etc.)
```

### Unicité par société

Certaines contraintes d'unicité doivent être adaptées:

```sql
-- Exemple: Plan comptable
-- AVANT: numero_compte UNIQUE
-- APRÈS: UNIQUE KEY (societe_id, numero_compte)

ALTER TABLE plan_comptable DROP INDEX numero_compte;
ALTER TABLE plan_comptable ADD UNIQUE KEY unique_compte_societe (societe_id, numero_compte);

-- Exemple: Tiers
ALTER TABLE tiers DROP INDEX code_tiers;
ALTER TABLE tiers ADD UNIQUE KEY unique_tiers_societe (societe_id, code_tiers);

-- Exemple: Journaux
ALTER TABLE journaux DROP INDEX code_journal;
ALTER TABLE journaux ADD UNIQUE KEY unique_journal_societe (societe_id, code_journal);

-- Exemple: Pièces comptables
ALTER TABLE pieces_comptables DROP INDEX numero_piece;
ALTER TABLE pieces_comptables ADD UNIQUE KEY unique_piece_societe (societe_id, numero_piece);
```

---

## 💱 Gestion Multi-Devises

### Table `devises`

Référentiel des devises supportées (ISO 4217):

```sql
CREATE TABLE devises (
    code_devise VARCHAR(3) PRIMARY KEY,     -- ISO 4217: XOF, EUR, USD, etc.
    libelle VARCHAR(100) NOT NULL,          -- Franc CFA, Euro, Dollar US
    symbole VARCHAR(10) NOT NULL,           -- FCFA, €, $
    decimales TINYINT DEFAULT 2,            -- Nombre de décimales (2 pour la plupart)
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Données initiales
INSERT INTO devises (code_devise, libelle, symbole, decimales) VALUES
('XOF', 'Franc CFA (BCEAO)', 'FCFA', 0),
('XAF', 'Franc CFA (BEAC)', 'FCFA', 0),
('EUR', 'Euro', '€', 2),
('USD', 'Dollar américain', '$', 2),
('GBP', 'Livre sterling', '£', 2),
('CHF', 'Franc suisse', 'CHF', 2),
('JPY', 'Yen japonais', '¥', 0),
('CNY', 'Yuan chinois', '¥', 2),
('NGN', 'Naira nigérian', '₦', 2),
('GHS', 'Cedi ghanéen', '₵', 2),
('MAD', 'Dirham marocain', 'DH', 2);
```

### Table `taux_change`

Historique des taux de change:

```sql
CREATE TABLE taux_change (
    id INT PRIMARY KEY AUTO_INCREMENT,
    devise_source VARCHAR(3) NOT NULL,      -- Devise de départ
    devise_cible VARCHAR(3) NOT NULL,       -- Devise d'arrivée
    taux DECIMAL(15,6) NOT NULL,            -- Taux de conversion
    date_taux DATE NOT NULL,                -- Date de validité
    type_taux ENUM('officiel', 'manuel', 'api') DEFAULT 'manuel',
    societe_id INT NULL,                    -- NULL = taux global, sinon spécifique société
    source VARCHAR(100),                    -- Source du taux (BCE, BCEAO, manuel, etc.)
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Un seul taux par couple de devises, par date, par société
    UNIQUE KEY unique_taux (devise_source, devise_cible, date_taux, societe_id),

    INDEX idx_date (date_taux),
    INDEX idx_source (devise_source),
    INDEX idx_cible (devise_cible),
    INDEX idx_societe (societe_id),

    FOREIGN KEY (devise_source) REFERENCES devises(code_devise),
    FOREIGN KEY (devise_cible) REFERENCES devises(code_devise),
    FOREIGN KEY (societe_id) REFERENCES societes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taux fixes (EUR/XOF)
INSERT INTO taux_change (devise_source, devise_cible, taux, date_taux, type_taux, source) VALUES
('EUR', 'XOF', 655.957, '2024-01-01', 'officiel', 'BCEAO'),
('XOF', 'EUR', 0.00152449, '2024-01-01', 'officiel', 'BCEAO');
```

### Modification de la table `ecritures_comptables`

Ajout des champs pour gérer les devises:

```sql
ALTER TABLE ecritures_comptables
ADD COLUMN devise VARCHAR(3) DEFAULT 'XOF' AFTER montant,
ADD COLUMN montant_devise DECIMAL(15,2) NULL AFTER devise,
ADD COLUMN taux_change DECIMAL(15,6) NULL AFTER montant_devise,
ADD INDEX idx_devise (devise);

-- Contrainte de clé étrangère
ALTER TABLE ecritures_comptables
ADD CONSTRAINT fk_ecriture_devise
FOREIGN KEY (devise) REFERENCES devises(code_devise);
```

**Explications:**

- `montant` : Montant en devise de la société (XOF par défaut)
- `devise` : Code de la devise de l'opération (EUR, USD, etc.)
- `montant_devise` : Montant dans la devise d'origine (si différente)
- `taux_change` : Taux appliqué pour la conversion

**Exemples:**

```sql
-- Exemple 1: Achat en EUR converti en XOF
-- Facture: 1000 EUR à 655.957 = 655,957 XOF
INSERT INTO ecritures_comptables
(societe_id, numero_piece, compte, libelle, montant, devise, montant_devise, taux_change)
VALUES
(1, 'ACH001', '601100', 'Achat marchandises', 655957.00, 'EUR', 1000.00, 655.957);

-- Exemple 2: Vente en XOF (pas de conversion)
INSERT INTO ecritures_comptables
(societe_id, numero_piece, compte, libelle, montant, devise, montant_devise, taux_change)
VALUES
(1, 'VTE001', '701100', 'Vente marchandises', 500000.00, 'XOF', NULL, NULL);
```

### Fonctions de conversion

```sql
-- Fonction pour obtenir le taux de change à une date donnée
DELIMITER //
CREATE FUNCTION get_taux_change(
    p_devise_source VARCHAR(3),
    p_devise_cible VARCHAR(3),
    p_date DATE,
    p_societe_id INT
) RETURNS DECIMAL(15,6)
DETERMINISTIC
BEGIN
    DECLARE v_taux DECIMAL(15,6);

    -- Si même devise, taux = 1
    IF p_devise_source = p_devise_cible THEN
        RETURN 1.000000;
    END IF;

    -- Chercher taux spécifique société
    SELECT taux INTO v_taux
    FROM taux_change
    WHERE devise_source = p_devise_source
      AND devise_cible = p_devise_cible
      AND date_taux <= p_date
      AND societe_id = p_societe_id
    ORDER BY date_taux DESC
    LIMIT 1;

    -- Si pas trouvé, chercher taux global
    IF v_taux IS NULL THEN
        SELECT taux INTO v_taux
        FROM taux_change
        WHERE devise_source = p_devise_source
          AND devise_cible = p_devise_cible
          AND date_taux <= p_date
          AND societe_id IS NULL
        ORDER BY date_taux DESC
        LIMIT 1;
    END IF;

    RETURN v_taux;
END//
DELIMITER ;
```

---

## 👤 Gestion des Droits d'Accès

### Table `utilisateurs_societes`

Association utilisateurs ↔ sociétés avec rôles:

```sql
CREATE TABLE utilisateurs_societes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_utilisateur INT NOT NULL,
    societe_id INT NOT NULL,

    -- Rôle de l'utilisateur dans cette société
    role ENUM('admin', 'comptable', 'lecteur', 'saisie') DEFAULT 'lecteur',

    -- Période d'accès
    date_acces_debut DATE NOT NULL,
    date_acces_fin DATE NULL,           -- NULL = accès permanent

    -- Société par défaut
    par_defaut TINYINT(1) DEFAULT 0,    -- Société affichée au login

    -- Droits spécifiques
    peut_modifier_plan TINYINT(1) DEFAULT 0,
    peut_cloturer_exercice TINYINT(1) DEFAULT 0,
    peut_valider_ecritures TINYINT(1) DEFAULT 0,
    peut_exporter TINYINT(1) DEFAULT 1,

    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Un utilisateur ne peut avoir qu'un seul rôle par société
    UNIQUE KEY unique_user_societe (id_utilisateur, societe_id),

    INDEX idx_utilisateur (id_utilisateur),
    INDEX idx_societe (societe_id),
    INDEX idx_role (role),
    INDEX idx_actif (actif),

    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur) ON DELETE CASCADE,
    FOREIGN KEY (societe_id) REFERENCES societes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Matrice des droits

| Rôle | Consulter | Saisir | Modifier | Supprimer | Valider | Clôturer | Admin |
|------|-----------|--------|----------|-----------|---------|----------|-------|
| **Lecteur** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Saisie** | ✅ | ✅ | ✅ (ses écritures) | ❌ | ❌ | ❌ | ❌ |
| **Comptable** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Admin** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### Modification de la table `utilisateurs`

```sql
ALTER TABLE utilisateurs
ADD COLUMN role_global ENUM('super_admin', 'admin', 'utilisateur') DEFAULT 'utilisateur' AFTER role,
ADD COLUMN dernier_societe_id INT NULL AFTER derniere_connexion,
ADD INDEX idx_role_global (role_global),
ADD INDEX idx_dernier_societe (dernier_societe_id);

-- Contrainte
ALTER TABLE utilisateurs
ADD CONSTRAINT fk_user_dernier_societe
FOREIGN KEY (dernier_societe_id) REFERENCES societes(id) ON DELETE SET NULL;
```

**Rôles globaux:**

- `super_admin` : Accès total au système, peut créer des sociétés
- `admin` : Administrateur d'une ou plusieurs sociétés
- `utilisateur` : Utilisateur standard

---

## 🔍 Détection de Première Installation

### Table `parametres_systeme`

Configuration globale du système:

```sql
CREATE TABLE parametres_systeme (
    cle VARCHAR(50) PRIMARY KEY,
    valeur TEXT,
    type_valeur ENUM('string', 'int', 'bool', 'json') DEFAULT 'string',
    description VARCHAR(255),
    categorie VARCHAR(50),
    modifiable TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_categorie (categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres initiaux
INSERT INTO parametres_systeme (cle, valeur, type_valeur, description, categorie, modifiable) VALUES
('installation_complete', '0', 'bool', 'Indique si la configuration initiale est terminée', 'systeme', 0),
('version_schema', '1.0', 'string', 'Version du schéma de base de données', 'systeme', 0),
('date_installation', NULL, 'string', 'Date de la première installation', 'systeme', 0),
('nom_application', 'Comptabilité SYSCOHADA', 'string', 'Nom de l''application', 'general', 1),
('logo_application', NULL, 'string', 'Chemin vers le logo', 'general', 1),
('email_contact', NULL, 'string', 'Email de contact système', 'general', 1),
('multi_societes_active', '1', 'bool', 'Fonctionnalité multi-sociétés activée', 'fonctionnalites', 1),
('multi_devises_active', '1', 'bool', 'Fonctionnalité multi-devises activée', 'fonctionnalites', 1);
```

### Fonctions PHP de détection

```php
/**
 * Vérifie si le système nécessite une configuration initiale
 */
function needsInitialSetup(): bool {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier le flag d'installation
        $stmt = $db->query("
            SELECT valeur
            FROM parametres_systeme
            WHERE cle = 'installation_complete'
        ");
        $param = $stmt->fetch();

        // Si flag n'existe pas ou = 0, vérifier les sociétés
        if (!$param || $param['valeur'] === '0') {
            // Double vérification: y a-t-il au moins une société active?
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM societes
                WHERE actif = 1
            ");
            $result = $stmt->fetch();

            return ($result['count'] == 0);
        }

        return false; // Installation complète

    } catch (PDOException $e) {
        // Si erreur (tables n'existent pas), c'est une première installation
        return true;
    }
}

/**
 * Marque l'installation comme terminée
 */
function markInstallationComplete(): void {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        UPDATE parametres_systeme
        SET valeur = '1',
            date_modification = NOW()
        WHERE cle = 'installation_complete'
    ");
    $stmt->execute();

    // Enregistrer la date d'installation si pas déjà fait
    $stmt = $db->prepare("
        UPDATE parametres_systeme
        SET valeur = NOW()
        WHERE cle = 'date_installation'
          AND (valeur IS NULL OR valeur = '')
    ");
    $stmt->execute();
}

/**
 * Obtient un paramètre système
 */
function getSystemParameter(string $key, $default = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT valeur, type_valeur
            FROM parametres_systeme
            WHERE cle = ?
        ");
        $stmt->execute([$key]);
        $param = $stmt->fetch();

        if (!$param) {
            return $default;
        }

        // Conversion selon le type
        switch ($param['type_valeur']) {
            case 'int':
                return (int)$param['valeur'];
            case 'bool':
                return ($param['valeur'] === '1' || $param['valeur'] === 'true');
            case 'json':
                return json_decode($param['valeur'], true);
            default:
                return $param['valeur'];
        }

    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Définit un paramètre système
 */
function setSystemParameter(string $key, $value): bool {
    try {
        $db = Database::getInstance()->getConnection();

        // Vérifier si le paramètre est modifiable
        $stmt = $db->prepare("
            SELECT modifiable
            FROM parametres_systeme
            WHERE cle = ?
        ");
        $stmt->execute([$key]);
        $param = $stmt->fetch();

        if (!$param || !$param['modifiable']) {
            return false;
        }

        // Conversion de la valeur
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        }

        $stmt = $db->prepare("
            UPDATE parametres_systeme
            SET valeur = ?,
                date_modification = NOW()
            WHERE cle = ?
        ");

        return $stmt->execute([$value, $key]);

    } catch (PDOException $e) {
        return false;
    }
}
```

---

## 💾 Structure de Base de Données

### Script de migration complet

Voir le fichier: `database/migrations/multi_societes_devises.sql`

Le script contient:

1. ✅ Création de `parametres_systeme`
2. ✅ Création de `devises` avec données initiales
3. ✅ Création de `taux_change`
4. ✅ Création de `cabinets` (optionnel)
5. ✅ Création de `societes`
6. ✅ Modification de `utilisateurs` (ajout role_global, dernier_societe_id)
7. ✅ Création de `utilisateurs_societes`
8. ✅ Ajout de `societe_id` à toutes les tables métiers
9. ✅ Modification des contraintes d'unicité
10. ✅ Ajout des index de performance
11. ✅ Ajout des contraintes de clés étrangères
12. ✅ Modification de `ecritures_comptables` (devise, montant_devise, taux_change)
13. ✅ Création de fonctions SQL (get_taux_change)

### Tables impactées

**Tables à modifier (ajout societe_id):**

- `plan_comptable`
- `tiers`
- `journaux`
- `exercices`
- `pieces_comptables`
- `ecritures_comptables`
- `rapprochement_bancaire`
- `lettrage`
- `budget`
- `budget_lignes`
- `factures`
- Toutes les tables métiers

**Contraintes d'unicité à adapter:**

- `plan_comptable`: `(societe_id, numero_compte)`
- `tiers`: `(societe_id, code_tiers)`
- `journaux`: `(societe_id, code_journal)`
- `pieces_comptables`: `(societe_id, numero_piece)`

---

## 🚀 Plan de Migration

### Phase 1: Préparation (Sauvegarde)

```bash
# Sauvegarde complète avant migration
mysqldump -u root comptabilite_syscohada > backup_avant_multi_societes_$(date +%Y%m%d).sql
```

### Phase 2: Exécution du script de migration

```sql
-- Exécuter le script de migration
SOURCE database/migrations/multi_societes_devises.sql;
```

### Phase 3: Migration des données existantes

```sql
-- 1. Créer la société par défaut pour les données existantes
INSERT INTO societes (
    code_societe,
    raison_sociale,
    type_entite,
    devise_principale,
    actif
) VALUES (
    'SOC001',
    'Société Par Défaut',
    'entreprise_individuelle',
    'XOF',
    1
);

SET @societe_defaut_id = LAST_INSERT_ID();

-- 2. Affecter toutes les données existantes à cette société
UPDATE plan_comptable SET societe_id = @societe_defaut_id;
UPDATE tiers SET societe_id = @societe_defaut_id;
UPDATE journaux SET societe_id = @societe_defaut_id;
UPDATE exercices SET societe_id = @societe_defaut_id;
UPDATE pieces_comptables SET societe_id = @societe_defaut_id;
UPDATE ecritures_comptables SET societe_id = @societe_defaut_id;
-- ... (toutes les tables)

-- 3. Affecter tous les utilisateurs existants à cette société
INSERT INTO utilisateurs_societes (id_utilisateur, societe_id, role, par_defaut, date_acces_debut)
SELECT
    id_utilisateur,
    @societe_defaut_id,
    'admin',
    1,
    CURDATE()
FROM utilisateurs;

-- 4. Marquer l'installation comme non complète (pour forcer config)
UPDATE parametres_systeme SET valeur = '0' WHERE cle = 'installation_complete';
```

### Phase 4: Adaptation du code PHP

#### Modification de `config.php`

```php
// Ajouter les fonctions de gestion multi-sociétés
require_once __DIR__ . '/functions_multi_societes.php';
require_once __DIR__ . '/functions_devises.php';

// Vérifier si setup initial nécessaire
if (needsInitialSetup()) {
    // Rediriger vers setup (sauf si on y est déjà)
    if (!str_contains($_SERVER['REQUEST_URI'], '/setup/')) {
        header('Location: /setup/initial_setup.php');
        exit;
    }
}
```

#### Création de `functions_multi_societes.php`

```php
<?php
/**
 * Obtient la société courante de l'utilisateur
 */
function getCurrentSocieteId(): ?int {
    if (isset($_SESSION['societe_id'])) {
        return (int)$_SESSION['societe_id'];
    }
    return null;
}

/**
 * Définit la société courante
 */
function setCurrentSocieteId(int $societe_id): void {
    $_SESSION['societe_id'] = $societe_id;

    // Sauvegarder en BDD pour la prochaine connexion
    if (isset($_SESSION['user_id'])) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE utilisateurs
            SET dernier_societe_id = ?
            WHERE id_utilisateur = ?
        ");
        $stmt->execute([$societe_id, $_SESSION['user_id']]);
    }
}

/**
 * Obtient la liste des sociétés accessibles par l'utilisateur
 */
function getUserSocietes(int $user_id): array {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT
            s.*,
            us.role,
            us.par_defaut
        FROM societes s
        INNER JOIN utilisateurs_societes us ON s.id = us.societe_id
        WHERE us.id_utilisateur = ?
          AND s.actif = 1
          AND us.actif = 1
          AND (us.date_acces_fin IS NULL OR us.date_acces_fin >= CURDATE())
        ORDER BY us.par_defaut DESC, s.raison_sociale
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Vérifie si l'utilisateur a accès à une société
 */
function userHasAccessToSociete(int $user_id, int $societe_id): bool {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM utilisateurs_societes
        WHERE id_utilisateur = ?
          AND societe_id = ?
          AND actif = 1
          AND (date_acces_fin IS NULL OR date_acces_fin >= CURDATE())
    ");
    $stmt->execute([$user_id, $societe_id]);
    $result = $stmt->fetch();
    return ($result['count'] > 0);
}

/**
 * Obtient le nombre de sociétés actives pour un utilisateur
 */
function getNombreSocietesActives(int $user_id): int {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM societes s
        INNER JOIN utilisateurs_societes us ON s.id = us.societe_id
        WHERE us.id_utilisateur = ?
          AND s.actif = 1
          AND us.actif = 1
          AND (us.date_acces_fin IS NULL OR us.date_acces_fin >= CURDATE())
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return (int)$result['count'];
}

/**
 * Obtient les informations de la société courante
 */
function getCurrentSociete(): ?array {
    $societe_id = getCurrentSocieteId();
    if (!$societe_id) {
        return null;
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM societes WHERE id = ? AND actif = 1");
    $stmt->execute([$societe_id]);
    return $stmt->fetch() ?: null;
}
```

#### Adaptation des requêtes SQL

**Avant:**
```php
$stmt = $db->query("SELECT * FROM plan_comptable WHERE actif = 1");
```

**Après:**
```php
$societe_id = getCurrentSocieteId();
$stmt = $db->prepare("
    SELECT * FROM plan_comptable
    WHERE societe_id = ? AND actif = 1
");
$stmt->execute([$societe_id]);
```

### Phase 5: Interface utilisateur

#### Sélecteur de société (si > 1 société)

```php
// Dans includes/header.php ou sidebar.php

$user_id = $_SESSION['user_id'];
$societes = getUserSocietes($user_id);
$nb_societes = count($societes);
$societe_courante = getCurrentSociete();

if ($nb_societes > 1): ?>
    <div class="societe-selector">
        <select id="select-societe" class="form-select">
            <?php foreach ($societes as $societe): ?>
                <option
                    value="<?= $societe['id'] ?>"
                    <?= ($societe['id'] == $societe_courante['id']) ? 'selected' : '' ?>
                >
                    <?= htmlspecialchars($societe['raison_sociale']) ?>
                    <?php if ($societe['par_defaut']): ?> ⭐<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <script>
    document.getElementById('select-societe').addEventListener('change', function() {
        // Changer de société via AJAX
        fetch('/ajax/change_societe.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({societe_id: this.value})
        })
        .then(() => window.location.reload());
    });
    </script>
<?php else: ?>
    <!-- Affichage simple du nom de la société -->
    <div class="societe-info">
        <?= htmlspecialchars($societe_courante['raison_sociale']) ?>
    </div>
<?php endif; ?>
```

---

## 🖥️ Interface Utilisateur

### Page de configuration initiale

**Fichier:** `setup/initial_setup.php`

**Étapes:**

1. **Choix du type d'entité**
   - Entreprise individuelle
   - Groupe de sociétés
   - Cabinet d'expertise comptable

2. **Informations de la société**
   - Raison sociale
   - Forme juridique
   - Devise principale
   - Coordonnées

3. **Création du compte administrateur**
   - Nom, prénom
   - Email
   - Mot de passe

4. **Récapitulatif et validation**

### Gestion des sociétés

**Menu admin:**

- Liste des sociétés
- Créer une société
- Modifier une société
- Activer/Désactiver
- Gérer les accès utilisateurs

### Gestion des devises

**Menu admin:**

- Liste des devises actives
- Gérer les taux de change
- Historique des taux
- Import automatique (API future)

### Interface multi-devises

**Affichage dual:**

```
Montant: 1 000,00 EUR (655 957 FCFA)
         ↑ devise opération  ↑ devise société
```

**Saisie d'écriture:**

```
Compte: 601100 - Achats de marchandises
Montant: [_______] [EUR ▼]
→ Conversion: 655 957 FCFA (taux: 655.957 au 30/12/2024)
```

---

## ⚙️ Considérations Techniques

### Performance

**Index recommandés:**

```sql
-- Sur toutes les tables avec societe_id
CREATE INDEX idx_societe ON nom_table(societe_id);

-- Index composites pour recherches fréquentes
CREATE INDEX idx_societe_actif ON nom_table(societe_id, actif);
CREATE INDEX idx_societe_date ON ecritures_comptables(societe_id, date_ecriture);
```

**Requêtes optimisées:**

- Toujours filtrer par `societe_id` en premier
- Utiliser des index composites
- Éviter les `SELECT *` sur les grandes tables

### Sécurité

**Isolation des données:**

```php
// TOUJOURS vérifier l'accès avant affichage
function requireSocieteAccess(int $societe_id): void {
    $user_id = $_SESSION['user_id'];
    if (!userHasAccessToSociete($user_id, $societe_id)) {
        http_response_code(403);
        die('Accès refusé à cette société');
    }
}

// Exemple d'utilisation
$societe_id = $_GET['societe_id'];
requireSocieteAccess($societe_id);
```

**Protection contre l'injection:**

- Utiliser TOUJOURS des requêtes préparées
- Valider tous les `societe_id` avant utilisation
- Logger les tentatives d'accès non autorisées

### Sauvegarde et restauration

**Par société:**

```bash
# Sauvegarde d'une société spécifique
mysqldump comptabilite_syscohada \
  --where="societe_id=1" \
  plan_comptable tiers journaux ecritures_comptables \
  > backup_societe_1.sql
```

**Complète:**

```bash
# Sauvegarde de tout le système
mysqldump comptabilite_syscohada > backup_complet.sql
```

### Consolidation (future)

Pour les groupes, prévoir:

- Vue consolidée des comptes
- Élimination des transactions inter-sociétés
- Conversion de toutes les devises vers une devise de consolidation
- Reporting groupe

---

## 📝 TODO - Prochaines étapes

### Implémentation immédiate

- [ ] Exécuter le script de migration SQL
- [ ] Créer les fichiers PHP de gestion multi-sociétés
- [ ] Créer la page de setup initial
- [ ] Adapter les requêtes principales (plan comptable, écritures)
- [ ] Tester avec 1 société (mode simple)
- [ ] Tester avec 2+ sociétés (mode multi)

### Fonctionnalités futures

- [ ] Import automatique des taux de change (API BCE, BCEAO)
- [ ] Module de consolidation pour groupes
- [ ] Gestion des transactions inter-sociétés
- [ ] Reporting multi-sociétés
- [ ] Export par société
- [ ] Duplication de plan comptable entre sociétés
- [ ] Templates de société (pré-configuration)

---

## 📚 Références

- ISO 4217: Codes de devises
- SYSCOHADA Révisé: Normes comptables OHADA
- BCEAO: Taux de change officiels XOF/EUR
- Documentation PHP PDO: Gestion sécurisée des bases de données

---

**Document rédigé le:** 30 Décembre 2024
**Dernière mise à jour:** 30 Décembre 2024
**Version:** 1.0
**Auteur:** Équipe de développement ComptaSYSCOHADA
