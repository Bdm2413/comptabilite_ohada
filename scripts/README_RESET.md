# Procédure de Réinitialisation de l'Application

## Objectif
Remettre l'application à neuf pour une utilisation en production, en conservant uniquement les données de référence et en supprimant toutes les données de test.

## Données CONSERVÉES ✅
- **Plan comptable** (table `plan_comptable`)
- **Table de correspondance** (table `table_correspondance`)
- **Codes journaux** (table `code_journal`)
- **Plan tiers** (table `plan_tiers`) - Clients, Fournisseurs, Salariés

## Données SUPPRIMÉES ❌
- **Écritures comptables** (table `ecritures`)
- **Lignes d'écritures** (table `lignes_ecriture`)
- **Exercices comptables de test** (table `exercices_comptables`)
- **Logs d'activité** (table `logs_activite`)
- **Pièces comptables** (table `pieces_comptables`)
- **Compteurs Sage** (table `sage_piece_counter`)

---

## PROCÉDURE COMPLÈTE

### Étape 1 : SAUVEGARDE (OBLIGATOIRE)
**Avant toute manipulation, faites une sauvegarde complète !**

**Option A : Utiliser le script automatique**
```batch
Double-cliquez sur: sauvegarde_avant_reset.bat
```

**Option B : Sauvegarde manuelle**
```batch
cd C:\wamp64\bin\mysql\mysql9.1.0\bin
mysqldump -u root comptabilite_syscohada > C:\wamp64\www\comptabilite_ohada\backups\backup_%date%.sql
```

Le fichier de sauvegarde sera créé dans le dossier `backups/`

---

### Étape 2 : VÉRIFICATION
Vérifiez le contenu actuel de votre base :

```sql
-- Nombre d'écritures (sera supprimé)
SELECT COUNT(*) FROM ecritures;

-- Nombre de tiers (sera conservé)
SELECT COUNT(*) FROM plan_tiers;

-- Nombre de comptes (sera conservé)
SELECT COUNT(*) FROM plan_comptable;
```

---

### Étape 3 : EXÉCUTION DU RESET

**Option A : Utiliser le script automatique (RECOMMANDÉ)**
```batch
Double-cliquez sur: executer_reset.bat
```
Le script vous demandera une confirmation (tapez "OUI" pour continuer)

**Option B : Exécution manuelle via MySQL**
```batch
cd C:\wamp64\bin\mysql\mysql9.1.0\bin
mysql -u root comptabilite_syscohada < C:\wamp64\www\comptabilite_ohada\scripts\reset_donnees_test.sql
```

---

### Étape 4 : VÉRIFICATION POST-RESET

Connectez-vous à l'application et vérifiez :

1. **Plan comptable** : Allez dans Paramètres → Plan comptable
   - Vous devriez voir tous vos comptes SYSCOHADA

2. **Tiers** : Allez dans Paramètres → Gestion des tiers
   - Vous devriez voir tous vos clients, fournisseurs et salariés

3. **Écritures** : Allez dans Écritures → Liste des écritures
   - La liste devrait être vide ✅

4. **Exercices** : Allez dans Paramètres → Exercices comptables
   - Vous devriez voir uniquement l'exercice 2025 créé automatiquement

---

### Étape 5 : CONFIGURATION INITIALE

#### A. Créer vos exercices comptables
Si vous avez besoin d'autres exercices que 2025, créez-les :

```sql
-- Exemple : Ajouter l'exercice 2024 clôturé (pour les à-nouveaux)
INSERT INTO exercices_comptables (code, libelle, date_debut, date_fin, statut, date_cloture)
VALUES ('EX2024', 'Exercice 2024', '2024-01-01', '2024-12-31', 'Clôturé', '2025-01-15');

-- Exemple : Ajouter l'exercice 2026
INSERT INTO exercices_comptables (code, libelle, date_debut, date_fin, statut)
VALUES ('EX2026', 'Exercice 2026', '2026-01-01', '2026-12-31', 'En attente');
```

#### B. Modifier l'exercice par défaut
Si vous voulez commencer avec un autre exercice que 2025, modifiez le script `reset_donnees_test.sql` avant de l'exécuter.

---

## RESTAURATION EN CAS DE PROBLÈME

Si vous voulez annuler le reset et restaurer vos données de test :

```batch
cd C:\wamp64\bin\mysql\mysql9.1.0\bin
mysql -u root comptabilite_syscohada < C:\wamp64\www\comptabilite_ohada\backups\[FICHIER_BACKUP].sql
```

Remplacez `[FICHIER_BACKUP].sql` par le nom de votre fichier de sauvegarde.

---

## NOTES IMPORTANTES

⚠️ **Avant d'exécuter le reset :**
1. Assurez-vous que tous les utilisateurs sont déconnectés
2. Fermez toutes les pages de l'application dans votre navigateur
3. Faites une sauvegarde (c'est OBLIGATOIRE !)

⚠️ **Après le reset :**
1. Videz le cache de votre navigateur (Ctrl + F5)
2. Reconnectez-vous à l'application
3. Vérifiez que toutes vos données de référence sont présentes

---

## QUESTIONS FRÉQUENTES

**Q : Est-ce que je vais perdre mes utilisateurs ?**
R : Non, la table `utilisateurs` n'est pas affectée.

**Q : Est-ce que je peux annuler le reset ?**
R : Oui, si vous avez fait une sauvegarde, vous pouvez la restaurer à tout moment.

**Q : Combien de temps prend le reset ?**
R : Quelques secondes seulement.

**Q : Est-ce que je dois recréer mes tiers ?**
R : Non, tous vos tiers (clients, fournisseurs, salariés) sont conservés.

**Q : Que faire si j'ai une erreur "Foreign key constraint" ?**
R : Le script désactive temporairement les contraintes de clés étrangères. Si l'erreur persiste, contactez le support.

---

## SUPPORT

En cas de problème, conservez le fichier de sauvegarde et les messages d'erreur pour obtenir de l'aide.

Date de création : 2025-01-05
Version : 1.0
