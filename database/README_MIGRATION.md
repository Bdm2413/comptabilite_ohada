# Guide de Migration v2

## Vue d'ensemble

Ce script de migration adapte la structure de la base de données pour correspondre au modèle éprouvé de l'application `accounting_workflow_approval`.

## Nouvelles tables créées

### 1. **table_correspondance**
Table des comptes racines à 4 chiffres servant de référence pour le plan comptable détaillé.

**Colonnes principales :**
- `compte` : Numéro de compte à 4 chiffres (ex: 1000, 2100, 6000)
- `classe` : Classe du compte (1 à 8)
- `libelle` : Libellé du compte
- `tableau` : "Bilan" ou "Compte de résultat"
- `bd`, `bc`, `rd`, `rc` : Positions dans les états financiers

### 2. **code_journal** (remplace journaux)
Codes journaux avec types et comptes de trésorerie associés.

**Colonnes principales :**
- `code` : Code court (AC, VE, BQ, CA, OD, etc.)
- `journal` : Libellé complet
- `type` : Achat, Vente, Trésorerie, etc.
- `compte_tresorerie` : Compte bancaire/caisse lié

### 3. **plan_tiers** (remplace tiers)
Gestion détaillée des tiers avec comptes auxiliaires.

**Colonnes principales :**
- `nom` : Nom complet du tiers
- `type` : Client, Fournisseur, Salarié, etc.
- `compte_gle` : Compte général (ex: 411, 401)
- `compte_tiers` : Compte auxiliaire (ex: 411CLI001)
- `ncc` : Numéro de compte contribuable
- `matricule` : Pour les salariés

### 4. **plan_comptable** (restructuré)
Plan comptable détaillé avec liaison vers la table de correspondance.

**Nouvelles colonnes :**
- `quatre_chiffres` : Lien vers `table_correspondance`
- `type` : Type de compte (Client, Fournisseur, Charge, etc.)
- `collectif` : Indicateur de compte collectif
- `auxiliaire` : Indicateur de compte auxiliaire

### 5. **correspondance_moderne**
Schémas d'écritures prédéfinis pour faciliter la saisie.

### 6. **sage_piece_counter**
Compteur automatique pour la numérotation des pièces.

## Modifications des tables existantes

### pieces_comptables
- `id_journal` modifié pour accepter le code du journal (VARCHAR)

### lignes_ecriture
- Ajout de `compte_auxiliaire` pour gérer les comptes tiers

## Instructions d'exécution

### Option 1 : Via phpMyAdmin
1. Ouvrir phpMyAdmin
2. Sélectionner la base `comptabilite_syscohada`
3. Aller dans l'onglet "SQL"
4. Coller le contenu de `migration_v2.sql`
5. Cliquer sur "Exécuter"

### Option 2 : Ligne de commande
```bash
mysql -u root -p comptabilite_syscohada < migration_v2.sql
```

### Option 3 : Script PHP d'installation
Accéder à :
```
http://localhost/comptabilite_ohada/install_migration.php
```

## Sauvegardes

Le script crée automatiquement des tables de sauvegarde :
- `plan_comptable_backup`
- `journaux_backup`
- `tiers_backup`

Ces sauvegardes permettent de restaurer les données en cas de problème.

## Après la migration

### Vérifications recommandées
1. Vérifier que toutes les tables ont été créées
2. Vérifier que les données ont été migrées
3. Tester les codes journaux
4. Vérifier les comptes dans le plan comptable

### Commandes SQL de vérification
```sql
-- Compter les enregistrements
SELECT COUNT(*) FROM table_correspondance;
SELECT COUNT(*) FROM code_journal;
SELECT COUNT(*) FROM plan_tiers;
SELECT COUNT(*) FROM plan_comptable;

-- Vérifier les codes journaux
SELECT * FROM code_journal;

-- Vérifier la table de correspondance
SELECT * FROM table_correspondance ORDER BY compte;
```

## Nouveau menu

Après la migration, le menu sidebar a été réorganisé :

**Paramètres :**
- Tableau de correspondance
- Plan comptable
- Code journaux
- Tiers

**Opérations :**
- Comptabilisation (saisie par pièces)

**États :**
- Grand livre
- Balance
- Bilan
- Compte de résultat

## Support

En cas de problème :
1. Consulter les tables de sauvegarde (`*_backup`)
2. Vérifier les logs d'erreur MySQL
3. Utiliser la page de diagnostic : `diagnostic.php`

## Rollback (Annulation)

Si vous devez annuler la migration :

```sql
-- Restaurer plan_comptable
DROP TABLE IF EXISTS plan_comptable;
RENAME TABLE plan_comptable_backup TO plan_comptable;

-- Restaurer journaux
DROP TABLE IF EXISTS code_journal;
RENAME TABLE journaux_backup TO journaux;

-- Restaurer tiers
DROP TABLE IF EXISTS plan_tiers;
RENAME TABLE tiers_backup TO tiers;
```
