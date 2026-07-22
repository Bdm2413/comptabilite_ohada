# 🔧 Correction : Intégration du lettrage manuel dans la Balance Âgée

## 🐛 Problème rencontré

### Symptôme
Quand la case **"Inclure les réglées"** était **décochée**, certaines factures **non réglées** disparaissaient de la Balance Âgée. Elles ne réapparaissaient que lorsque la case était cochée.

### Cause racine

**Le système ignorait complètement le lettrage manuel** effectué dans le module de lettrage !

#### Logique problématique (avant correction)

```php
// Balance Âgée Fournisseurs (ancien code)
$cle_reglement = $facture['numero_facture'] . '_' . $facture['compte_tiers'];
$total_regle = isset($reglements[$cle_reglement]) ? $reglements[$cle_reglement]['total_regle'] : 0;
$solde_restant = $montant_facture - $total_regle;

// Afficher SEULEMENT si solde > 0 OU si on veut voir les réglées
if ($solde_restant > 0.01 || $afficher_lettrees) {
    // Afficher la facture
}
```

**Problèmes avec cette approche :**

1. ❌ Le calcul du solde se base sur `numero_facture + compte_tiers`
2. ❌ Si `numero_facture = NULL` → Plusieurs factures du même fournisseur partagent la même clé
3. ❌ Le lettrage manuel effectué dans `lettrage.php` est **totalement ignoré**
4. ❌ Une facture peut être considérée "réglée" par calcul alors qu'elle ne l'est pas vraiment

#### Balance Âgée Clients : Double problème

En plus du problème ci-dessus, la Balance Âgée Clients avait un **filtre SQL** qui excluait directement les factures lettrées :

```php
// Ancien code (ligne 13)
$whereClause = $afficher_lettrees ? "" : "AND (e.statut_lettrage = 'Non lettré' OR e.statut_lettrage IS NULL)";

// Dans la requête SQL (ligne 74)
... WHERE ... $whereClause ...
```

**Résultat** : Ce filtre SQL excluait TOUTES les factures avec `statut_lettrage = 'Lettré'`, même celles avec un solde restant dû !

---

## ✅ Solution implémentée

### Principe : Respecter le lettrage manuel

Puisque l'utilisateur fait un lettrage manuel dans le module `lettrage.php`, la Balance Âgée doit **respecter ce lettrage** au lieu de faire un calcul approximatif.

### Logique corrigée

```php
// ✅ NOUVEAU : Vérifier d'abord le statut de lettrage
$est_lettree = !empty($facture['statut_lettrage']) && $facture['statut_lettrage'] === 'Lettré';

// Si la facture est lettrée et qu'on ne veut pas voir les lettrées → ignorer
if ($est_lettree && !$afficher_lettrees) {
    continue; // Passer à la facture suivante
}

// Calculer le solde restant (pour affichage dans le détail)
// Note: Ce calcul reste pour information, mais le lettrage a la priorité
$montant_facture = $facture['montant'];
$cle_reglement = $facture['numero_facture'] . '_' . $facture['compte_tiers'];
$total_regle = isset($reglements[$cle_reglement]) ? $reglements[$cle_reglement]['total_regle'] : 0;
$solde_restant = $est_lettree ? 0 : ($montant_facture - $total_regle);

// Toujours afficher si on arrive ici
```

---

## 📊 Tableau de vérité

Pour mieux comprendre la nouvelle logique :

| `statut_lettrage` | `afficher_lettrees` (case cochée) | Résultat |
|-------------------|-----------------------------------|----------|
| **"Lettré"** | ❌ Non | ❌ **Masquée** (facture réglée) |
| **"Lettré"** | ✅ Oui | ✅ **Affichée** (on veut voir les réglées) |
| **"Non lettré"** ou NULL | ❌ Non | ✅ **Affichée** (facture non réglée) |
| **"Non lettré"** ou NULL | ✅ Oui | ✅ **Affichée** |
| **"Partiellement lettré"** | ❌ Non | ✅ **Affichée** (partiellement réglée) |
| **"Partiellement lettré"** | ✅ Oui | ✅ **Affichée** |

---

## 🎯 Cas d'usage réels

### Cas 1 : Facture non lettrée (non réglée)

```
Facture fournisseur :
  numero_facture = "FACT-001"
  compte_tiers = "4011001"
  montant = 100 000 FCFA
  statut_lettrage = "Non lettré" (ou NULL)

Résultat :
  ✅ S'affiche dans la Balance Âgée
  ✅ Montant affiché : 100 000 FCFA

Pourquoi :
  statut_lettrage != "Lettré"
  → La facture n'est pas réglée → Elle doit apparaître
```

### Cas 2 : Facture lettrée manuellement (réglée)

```
Facture fournisseur :
  numero_facture = "FACT-002"
  compte_tiers = "4011001"
  montant = 50 000 FCFA
  statut_lettrage = "Lettré"

Règlement :
  numero_facture = "FACT-002"
  compte_tiers = "4011001"
  montant = 50 000 FCFA (débit)

Lettrage manuel :
  Les deux lignes ont été lettrées ensemble avec le code "A1B2C3"

Résultat (case "Inclure les réglées" DÉCOCHÉE) :
  ❌ NE s'affiche PAS dans la Balance Âgée

Résultat (case "Inclure les réglées" COCHÉE) :
  ✅ S'affiche avec solde = 0

Pourquoi :
  statut_lettrage = "Lettré"
  → La facture a été lettrée manuellement → Elle est réglée
```

### Cas 3 : Factures avec numero_facture = NULL (ancien problème)

```
Fournisseur A (compte_tiers = "4011001"):
  Facture 1: numero_facture = NULL, montant = 100 000, statut_lettrage = "Non lettré"
  Facture 2: numero_facture = NULL, montant = 50 000, statut_lettrage = "Non lettré"

Règlement pour Facture 1 SEULEMENT :
  numero_facture = NULL, montant = 100 000

AVANT la correction :
  ❌ Facture 1 : Solde = 100 000 - 100 000 = 0 → Masquée
  ❌ Facture 2 : Solde = 50 000 - 100 000 = -50 000 → Masquée aussi !
  → Problème : Les deux factures partagent la même clé "_4011001"

APRÈS la correction :
  ✅ Facture 1 : statut_lettrage = "Lettré" (après lettrage manuel) → Masquée
  ✅ Facture 2 : statut_lettrage = "Non lettré" → S'affiche normalement
  → Le lettrage manuel détermine ce qui est réglé ou non
```

### Cas 4 : Extourne et nouvelle saisie (cas réel de l'utilisateur)

```
1. Facture initiale erronée (ACH11250023) :
   numero_facture = "25000000056"
   montant = 24 002 100 FCFA (au crédit)
   statut_lettrage = "Non lettré"

2. Extourne de la facture (ACH12250016) :
   numero_facture = "25000000056" (même numéro !)
   montant = 24 002 100 FCFA (au débit - inverse)
   statut_lettrage = "Non lettré"

3. Nouvelle facture correcte (ACH12250017) :
   numero_facture = "25000000056" (toujours le même !)
   montant = 23 379 600 FCFA (au crédit)
   statut_lettrage = "Non lettré"

PROBLÈME AVANT CORRECTION :
  Clé = "25000000056_FNR0153" (partagée par les 3 lignes)
  Total réglé = 24 002 100 (l'extourne)

  ACH11250023 : Réglé = 24 002 100 → Restant = 0 ✅ Correct
  ACH12250017 : Réglé = 24 002 100 → Restant = -622 500 ❌ FAUX !

SOLUTION :
  1. Lettrer ACH11250023 avec ACH12250016 dans lettrage.php
  2. Les deux deviennent statut_lettrage = "Lettré"

RÉSULTAT APRÈS CORRECTION :
  - ACH11250023 → Masquée (lettrée)
  - ACH12250016 → Masquée (lettrée) ET exclue du calcul des règlements
  - ACH12250017 → Affichée avec Réglé = 0, Restant = 23 379 600 ✅ Correct !

Pourquoi ça fonctionne :
  1. La requête des règlements exclut les lignes lettrées (ligne 97)
  2. L'extourne ACH12250016 n'est plus comptée dans "total_regle"
  3. ACH12250017 n'est pas impactée par l'extourne
```

### Cas 5 : Lettrage partiel

```
Facture fournisseur :
  numero_facture = "FACT-003"
  montant = 200 000 FCFA

Règlement partiel :
  montant = 150 000 FCFA

Lettrage manuel :
  statut_lettrage = "Partiellement lettré"

Résultat :
  ✅ S'affiche dans la Balance Âgée
  ✅ Montant affiché : 200 000 FCFA (facture complète)

Pourquoi :
  statut_lettrage != "Lettré"
  → La facture n'est pas complètement réglée → Elle doit apparaître
```

---

## 🔍 Différence entre les versions

### Version 1 (Problématique)

```php
// Balance Âgée Clients : Filtre SQL
$whereClause = $afficher_lettrees ? "" : "AND (e.statut_lettrage = 'Non lettré' OR e.statut_lettrage IS NULL)";

// Balance Âgée Fournisseurs : Calcul approximatif
$solde_restant = $montant_facture - $total_regle;
if ($solde_restant > 0.01 || $afficher_lettrees) {
    // Afficher
}
```

**Problèmes** :
- ❌ Le filtre SQL dans Balance Âgée Clients excluait TOUTES les factures lettrées
- ❌ Le calcul avec `numero_facture` était approximatif
- ❌ Le lettrage manuel était ignoré
- ❌ Comportement incohérent entre Balance Âgée Fournisseurs et Clients

### Version 2 (Corrigée)

```php
// ✅ Pas de filtre SQL (gestion dans le code PHP)
// ✅ Utilisation du statut_lettrage comme source de vérité

$est_lettree = !empty($facture['statut_lettrage']) && $facture['statut_lettrage'] === 'Lettré';

if ($est_lettree && !$afficher_lettrees) {
    continue; // Respecter le lettrage manuel
}

$solde_restant = $est_lettree ? 0 : ($montant_facture - $total_regle);
```

**Avantages** :
- ✅ Le lettrage manuel est respecté
- ✅ Comportement cohérent entre Fournisseurs et Clients
- ✅ Logique claire et déterministe
- ✅ Contrôle total pour l'utilisateur via le module de lettrage

---

## 📝 Points techniques importants

### 1. Le module de lettrage

Le module `pages/ecritures/lettrage.php` permet de :
- Lettrer manuellement des écritures comptables
- Générer un code de lettrage unique (ex: "A1B2C3")
- Mettre à jour `statut_lettrage` dans la table `ecritures`

**Valeurs possibles de `statut_lettrage`** :
- `"Lettré"` → Écritures équilibrées (débit = crédit)
- `"Partiellement lettré"` → Écritures non équilibrées (débit ≠ crédit)
- `"Non lettré"` → Écritures non lettrées
- `NULL` → Anciennes écritures (avant implémentation du lettrage)

### 2. Pourquoi numero_facture peut être NULL

- Anciennes écritures créées avant l'ajout du champ `numero_facture`
- Écritures de type salaire, OD, banque, etc. (pas de facture)
- Oubli de remplir le champ lors de la saisie

### 3. Pourquoi numero_facture n'est pas unique

Un même numéro de facture peut exister chez plusieurs fournisseurs différents :
- Fournisseur A → Facture "ABC"
- Fournisseur B → Facture "ABC" (différente !)

C'est pourquoi la clé est composée : `numero_facture + compte_tiers`

---

## 🧪 Tests à effectuer

### Test 1 : Facture non lettrée
1. Créer une facture sans la lettrer
2. Aller dans Balance Âgée Fournisseurs
3. **Résultat attendu** : La facture s'affiche ✅

### Test 2 : Facture lettrée
1. Créer une facture et son règlement
2. Les lettrer via `lettrage.php`
3. Aller dans Balance Âgée Fournisseurs (case "Inclure les réglées" **décochée**)
4. **Résultat attendu** : La facture NE s'affiche PAS ✅
5. Cocher la case "Inclure les réglées"
6. **Résultat attendu** : La facture s'affiche avec solde = 0 ✅

### Test 3 : Lettrage partiel
1. Créer une facture de 100 000 et un règlement de 60 000
2. Les lettrer partiellement via `lettrage.php`
3. Vérifier que `statut_lettrage = "Partiellement lettré"`
4. **Résultat attendu** : La facture s'affiche dans la Balance Âgée ✅

### Test 4 : Multiples factures NULL
1. Créer 2 factures avec `numero_facture = NULL` pour le même fournisseur
2. Lettrer uniquement la première
3. **Résultat attendu** : Seule la deuxième s'affiche dans la Balance Âgée ✅

---

## 📦 Fichiers modifiés

### Modification 1 : Respect du lettrage manuel (13/12/2024)

1. **`pages/rapports/balance_agee_fournisseurs.php`** (lignes 134-155)
   - Ajout du check `$est_lettree`
   - Priorité donnée au `statut_lettrage` sur le calcul manuel

2. **`pages/rapports/balance_agee_clients.php`** (lignes 9-13, 140-159)
   - Suppression du filtre SQL `$whereClause`
   - Ajout du check `$est_lettree` (même logique que Fournisseurs)

### Modification 2 : Exclusion des extournes lettrées (13/12/2024)

3. **`pages/rapports/balance_agee_fournisseurs.php`** (ligne 97)
   - Ajout de `AND (e.statut_lettrage != 'Lettré' OR e.statut_lettrage IS NULL)` dans la requête des règlements
   - Les extournes lettrées ne sont plus comptées dans le calcul du montant réglé

4. **`pages/rapports/balance_agee_clients.php`** (ligne 100)
   - Même correction pour les clients

### Modification 3 : Ajout de la colonne ID Écriture (13/12/2024)

5. **`pages/rapports/balance_agee_fournisseurs.php`** (lignes 482-495)
   - Ajout de la colonne "ID Écriture" dans le tableau détaillé
   - Permet d'identifier chaque facture de façon unique

6. **`pages/rapports/balance_agee_clients.php`** (lignes 509-522)
   - Même ajout pour les clients

7. **`CORRECTION_LETTRAGE_BALANCE_AGEE.md`** (ce document)
   - Documentation technique de toutes les corrections

---

## 🎓 Leçons apprises

1. **Toujours respecter le travail manuel de l'utilisateur** : Le lettrage manuel est une source de vérité
2. **Éviter les calculs approximatifs** : Quand on a une information fiable (`statut_lettrage`), l'utiliser !
3. **Cohérence entre modules** : Balance Âgée Fournisseurs et Clients doivent avoir la même logique
4. **Filtres SQL vs Filtres PHP** : Les filtres dans le code PHP offrent plus de flexibilité
5. **Documentation** : Documenter le "pourquoi" et pas seulement le "comment"

---

## 🔗 Lien avec le module de lettrage

La Balance Âgée et le module de lettrage sont maintenant **parfaitement synchronisés** :

```
Module de lettrage (lettrage.php)
         ↓
  Met à jour statut_lettrage
         ↓
Balance Âgée (balance_agee_*.php)
         ↓
  Lit statut_lettrage
         ↓
  Affiche/Masque selon la case "Inclure les réglées"
```

**Workflow utilisateur** :
1. Saisir une facture fournisseur (journal ACH, compte 4011000 au crédit)
2. Saisir le règlement (journal BQE, compte 4011000 au débit)
3. Lettrer les deux via `lettrage.php` → `statut_lettrage = "Lettré"`
4. Consulter la Balance Âgée → La facture n'apparaît plus (car réglée)
5. Si besoin, cocher "Inclure les réglées" → La facture réapparaît avec solde = 0

---

---

## 🎉 Résumé des corrections

### Problème initial
La Balance Âgée ne respectait pas le lettrage manuel et calculait les montants réglés de façon approximative avec `numero_facture`, ce qui causait des erreurs notamment avec les extournes.

### Solution finale
1. ✅ **Respect du lettrage manuel** : Le `statut_lettrage` est maintenant la source de vérité
2. ✅ **Exclusion des extournes lettrées** : Les extournes ne sont plus comptées dans le calcul des règlements
3. ✅ **Colonne ID Écriture** : Ajout d'une colonne pour identifier chaque ligne de façon unique
4. ✅ **Cohérence Fournisseurs/Clients** : Même logique appliquée aux deux balances âgées

### Impact
- Les factures extournées puis ressaisies s'affichent correctement
- Le lettrage manuel est respecté
- La Balance Âgée reflète fidèlement la situation comptable

---

*Document créé le : 13 Décembre 2024*
*Dernière mise à jour : 13 Décembre 2024*
*Version : 2.0*
*Corrections : Intégration du lettrage manuel + Exclusion des extournes lettrées + Colonne ID Écriture*
