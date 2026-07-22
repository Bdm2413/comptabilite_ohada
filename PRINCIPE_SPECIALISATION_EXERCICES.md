# Principe de la spécialisation des exercices - Correction appliquée

## 📋 Problème identifié

Les comptes de charges et produits (classes 6, 7, 8) étaient traités de la même manière que les comptes de bilan, avec un cumul des soldes depuis le début de l'historique comptable.

**Ceci est incorrect** selon le principe comptable de la spécialisation des exercices.

## ✅ Principe comptable correct

### Comptes de BILAN (classes 1, 2, 3, 4, 5)
- **Cumul permanent** : Les soldes sont reportés d'année en année
- Exemple : Un client qui doit 1 000 000 en décembre 2024 continuera de devoir cette somme en janvier 2025

### Comptes de RÉSULTAT (classes 6, 7, 8)
- **Remise à zéro annuelle** : Les soldes ne sont PAS reportés sur l'exercice suivant
- Les charges et produits se rattachent uniquement à la période concernée
- Exemple concret :
  ```
  Compte 6324000 (Fournitures de bureau)
  - Octobre 2024 : Débit 1 000 000 → Solde : 1 000 000 D
  - Novembre 2024 : Aucun mouvement → Solde : 0 (pas de cumul du mois précédent)
  - Décembre 2024 : Débit 500 000 → Solde : 500 000 D
  - Total annuel 2024 : 1 500 000 D

  - Janvier 2025 : Remise à zéro, nouveau compteur
  ```

## 🔧 Corrections appliquées

### 1. Balance Mensuelle (`calcul_rapport_mensuel_optimise.php`)

**Lignes 106-129** : Ajout de la logique de distinction

```php
// Déterminer si c'est un compte de résultat (classes 6, 7, 8)
$premiere_classe = substr($compte, 0, 1);
$est_compte_resultat = in_array($premiere_classe, ['6', '7', '8']);

// Pour les comptes de résultat : pas de report des années antérieures
if ($mois == 1 && !$est_compte_resultat) {
    // SEULEMENT pour les comptes de bilan (classes 1-5)
    // Calculer le solde de toutes les années antérieures
}
```

### 2. Balance Générale (`balance_generale.php`)

**Lignes 30-68** : Modification de la requête SQL

```sql
-- Mouvements antérieurs (pour comptes de bilan uniquement)
COALESCE(SUM(CASE
    WHEN e.statut = 'Validé'
        AND e.date_ecriture < ?
        AND (LEFT(pc.compte, 1) NOT IN ('6', '7', '8') OR e.date_ecriture >= ?)
    THEN le.debit
    ELSE 0
END), 0) as debit_anterieur
```

**Logique** :
- Si compte de bilan (1-5) → Prendre TOUS les mouvements antérieurs
- Si compte de résultat (6, 7, 8) → Prendre uniquement depuis le 1er janvier de l'année

### 3. Grand Livre (`grand_livre.php`)

**Lignes 36-59** : Calcul du solde initial adapté

```php
// Déterminer si c'est un compte de résultat
$est_compte_resultat = in_array($premiere_classe, ['6', '7', '8']);

// Pour les comptes de résultat : solde initial au 1er janvier de l'année
// Pour les comptes de bilan : solde initial depuis toujours
$date_debut_calcul = $est_compte_resultat
    ? $annee_debut . '-01-01'
    : '1900-01-01';
```

## 💡 Équilibrage de la balance

### Conséquence
Avec cette correction, la balance peut ne plus être équilibrée (Débit Total ≠ Crédit Total) car :
- Les comptes de résultat de l'année N-1 ne sont plus reportés
- Mais leur résultat net (bénéfice ou perte) DOIT être reporté

### Solution comptable
**Écriture de clôture à créer manuellement** :
```
Si bénéfice (résultat positif) :
  Débit : Compte 131 - Résultat net : Bénéfice
  Crédit : Compte 110 - Report à nouveau créditeur

Si perte (résultat négatif) :
  Débit : Compte 110 - Report à nouveau débiteur
  Crédit : Compte 139 - Résultat net : Perte
```

Cette écriture transfère le résultat des comptes de classes 6, 7, 8 vers les comptes de bilan (classe 1), permettant l'équilibre de la balance.

## 📝 Comptes SYSCOHADA concernés

### Comptes de report à nouveau et résultat
- **110** : Report à nouveau créditeur (bénéfices antérieurs)
- **119** : Report à nouveau débiteur (pertes antérieures)
- **130** : Résultat en instance d'affectation
- **131** : Résultat net : Bénéfice
- **139** : Résultat net : Perte

## ✅ Impact de la correction

### Pages concernées
1. ✅ **Balance Mensuelle** - Comptes de résultat remis à zéro chaque année
2. ✅ **Balance Générale** - Soldes antérieurs corrects selon le type de compte
3. ✅ **Grand Livre** - Solde initial correct pour chaque compte
4. ✅ **Balance Auxiliaire** - Non concernée (comptes de tiers classe 4 = bilan)

### Avantages
- **Conformité SYSCOHADA** : Respect du principe de spécialisation des exercices
- **Lisibilité** : Les soldes mensuels des charges/produits reflètent uniquement le mois concerné
- **Analyse** : Facilite l'analyse périodique des performances
- **Exactitude** : Le résultat annuel est correct et non faussé par des cumuls

## 📅 Date de correction
**3 novembre 2025**

## 👤 Notes
Cette correction est fondamentale pour la conformité comptable et doit être maintenue dans toutes les futures évolutions du système.
