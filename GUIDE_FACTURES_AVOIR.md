# 📘 Guide d'utilisation : Gestion des Factures AVOIR

## 🎯 Objectif

Cette fonctionnalité permet de lier les factures **AVOIR** (annulation) à leur facture **DOIT** (normale) d'origine. Les factures DOIT ayant un AVOIR associé n'apparaissent plus dans les Balances Âgées.

---

## ✅ Modifications apportées

### 1. Base de données
- ✅ Ajout du champ `type_facture` (ENUM: 'DOIT', 'AVOIR', 'NORMALE')
- ✅ Ajout du champ `facture_initiale` (VARCHAR 100) pour stocker le N° de facture annulée
- ✅ Ajout d'index pour optimiser les performances

### 2. Formulaire de saisie
- ✅ Nouveau champ **"Type de facture"** (visible uniquement pour journaux ACH et VTE)
- ✅ Nouveau champ **"Facture DOIT annulée"** (visible uniquement si type = AVOIR)
- ✅ Affichage conditionnel automatique selon le journal sélectionné

### 3. Balances Âgées
- ✅ **Balance Âgée Fournisseurs** : exclut les factures DOIT ayant un AVOIR
- ✅ **Balance Âgée Clients** : exclut les factures DOIT ayant un AVOIR

---

## 📝 Comment utiliser

### Cas 1 : Enregistrer une facture DOIT normale

1. Créer une nouvelle écriture
2. Sélectionner le journal **ACH** (Achats) ou **VTE** (Ventes)
3. Remplir le champ **"Référence pièce"** avec le N° de facture : `FACT-2025-001`
4. *(Optionnel)* Sélectionner **Type de facture** = "DOIT"
5. Laisser **"Facture DOIT annulée"** vide
6. Enregistrer

**Résultat** : La facture apparaît normalement dans la Balance Âgée.

---

### Cas 2 : Enregistrer une facture AVOIR

1. Créer une nouvelle écriture
2. Sélectionner le journal **ACH** ou **VTE**
3. Remplir le champ **"Référence pièce"** avec le N° de l'AVOIR : `AV-2025-001`
4. Sélectionner **Type de facture** = **"AVOIR"**
5. ⚠️ **IMPORTANT** : Remplir **"Facture DOIT annulée"** avec le N° de la facture originale : `FACT-2025-001`
6. Enregistrer

**Résultat** : La facture DOIT `FACT-2025-001` **n'apparaît plus** dans la Balance Âgée !

---

### Cas 3 : Autres opérations (Salaires, OD, etc.)

1. Créer une nouvelle écriture
2. Sélectionner un journal autre que ACH/VTE (ex: OD, SAL, BQE, CAI)
3. Les champs "Type de facture" et "Facture DOIT annulée" **ne s'affichent pas**
4. Enregistrer normalement

**Résultat** : Aucun impact, les champs restent NULL.

---

## 🔍 Exemple concret

### Scénario : Achat d'ordinateur puis retour

#### Étape 1 : Facture DOIT (achat initial)
```
Journal : ACH
Date : 2025-01-10
Référence pièce : FACT-HP-2025-001
Type de facture : DOIT (ou vide)
Facture DOIT annulée : (vide)
Libellé : Achat ordinateur HP

Lignes :
  Débit  : 2211000 - Matériel informatique   1 500 000 FCFA
  Crédit : 4011000 - Fournisseur HP          1 500 000 FCFA
```

**✅ Balance Âgée Fournisseurs** : Affiche 1 500 000 FCFA dû à HP

---

#### Étape 2 : Facture AVOIR (retour)
```
Journal : ACH
Date : 2025-01-15
Référence pièce : AV-HP-2025-001
Type de facture : AVOIR ⚠️
Facture DOIT annulée : FACT-HP-2025-001 ⚠️
Libellé : Retour ordinateur HP défectueux

Lignes :
  Débit  : 4011000 - Fournisseur HP          1 500 000 FCFA
  Crédit : 2211000 - Matériel informatique   1 500 000 FCFA
```

**✅ Balance Âgée Fournisseurs** : N'affiche PLUS la facture HP (0 FCFA dû) !

---

## 🎨 Interface utilisateur

### Affichage conditionnel des champs

| Journal sélectionné | Type de facture | Facture DOIT annulée |
|---------------------|-----------------|----------------------|
| **ACH** (Achats) | ✅ Visible | ✅ Visible si AVOIR |
| **VTE** (Ventes) | ✅ Visible | ✅ Visible si AVOIR |
| OD, SAL, BQE, CAI | ❌ Masqué | ❌ Masqué |

### Exemple visuel

Quand vous sélectionnez le journal **ACH** :
- Le champ "Type de facture" apparaît automatiquement
- Si vous choisissez "AVOIR" : le champ "Facture DOIT annulée" apparaît en orange

---

## ⚠️ Points importants

### 1. Le N° de facture doit correspondre EXACTEMENT
```
✅ CORRECT :
   Facture DOIT : reference_piece = "FACT-2025-001"
   Facture AVOIR : facture_initiale = "FACT-2025-001"

❌ INCORRECT :
   Facture DOIT : reference_piece = "FACT-2025-001"
   Facture AVOIR : facture_initiale = "Facture 2025-001"  ⚠️ Différent !
```

### 2. La facture AVOIR doit être validée
- Tant que l'AVOIR est en **Brouillon**, la facture DOIT apparaît encore
- Une fois l'AVOIR **Validé**, la facture DOIT disparaît de la Balance Âgée

### 3. Plusieurs AVOIR possibles
- Vous pouvez créer plusieurs AVOIR pour la même facture DOIT
- Exemple : retour partiel en 2 fois

### 4. Champs optionnels
- ✅ Vous **pouvez** laisser vide si l'opération n'est pas une facture
- ✅ Les champs sont NULL par défaut
- ✅ Aucun impact sur les autres opérations comptables

---

## 🔧 Technique : Requête SQL

La Balance Âgée exclut maintenant les factures avec :

```sql
AND NOT (
    e.reference_piece IS NOT NULL
    AND EXISTS (
        SELECT 1 FROM ecritures e_avoir
        WHERE e_avoir.type_facture = 'AVOIR'
        AND e_avoir.facture_initiale = e.reference_piece
        AND e_avoir.statut = 'Validé'
    )
)
```

**Explication de la logique :**

1. ✅ **Si `reference_piece` est NULL** → La facture s'affiche TOUJOURS (pas d'exclusion)
2. ✅ **Si `reference_piece` est rempli** :
   - On vérifie s'il existe un AVOIR avec `facture_initiale = reference_piece`
   - Si OUI → La facture est **exclue** (annulée par AVOIR)
   - Si NON → La facture s'affiche normalement

**Pourquoi cette logique ?**

En SQL, `NULL = NULL` est toujours **FALSE**. Sans la vérification `IS NOT NULL`, les anciennes factures sans `reference_piece` pourraient être mal gérées.

---

## 📊 Impacts

### Avant (sans cette fonctionnalité)
```
Balance Âgée Fournisseurs :
- Fournisseur HP : 1 500 000 FCFA (FACT-2025-001)
- Même après l'AVOIR, la facture reste visible ❌
```

### Après (avec cette fonctionnalité)
```
Balance Âgée Fournisseurs :
- Fournisseur HP : 0 FCFA
- La facture FACT-2025-001 n'apparaît plus ✅
```

---

## ❓ FAQ

### Q1 : Que se passe-t-il si je ne remplis pas ces champs ?
**R** : Aucun problème ! Les champs sont optionnels. Si vous ne les remplissez pas, le comportement est identique à avant.

### Q2 : Puis-je modifier une facture AVOIR après validation ?
**R** : Non, les écritures validées ne peuvent pas être modifiées. Il faudra créer une écriture de contre-passation.

### Q3 : Cela fonctionne pour les clients aussi ?
**R** : Oui ! La même logique s'applique à la Balance Âgée Clients.

### Q4 : Mes anciennes factures ont disparu après la mise à jour !
**R** : **Problème résolu !** La version corrigée vérifie d'abord si `reference_piece IS NOT NULL` avant de comparer. Les anciennes factures s'affichent maintenant normalement, même si elles ont `reference_piece = NULL`.

### Q5 : J'ai extourné une facture puis refait l'écriture, que se passe-t-il ?
**R** : Scénario typique :
1. **Facture initiale** : `reference_piece = "FACT-001"` → S'affiche
2. **Extourne** : Crée une contre-écriture → Les deux s'annulent comptablement
3. **Nouvelle saisie** : `reference_piece = "FACT-001-BIS"` → S'affiche normalement
4. **AVOIR du fournisseur** : `type_facture = "AVOIR"`, `facture_initiale = "FACT-001-BIS"` → Masque "FACT-001-BIS"

**Important** : L'extourne et le mécanisme AVOIR sont **différents** :
- **Extourne** : Annulation comptable interne (crédit devient débit)
- **AVOIR** : Annulation par le fournisseur/client (nouveau document)

### Q6 : Je vois encore une ancienne facture qui devrait être annulée
**R** : Vérifiez que :
1. L'AVOIR est bien **Validé** (pas en Brouillon)
2. Le champ `facture_initiale` de l'AVOIR contient EXACTEMENT le même N° que `reference_piece` de la facture DOIT
3. Le champ `type_facture` de l'AVOIR est bien "AVOIR"
4. Le champ `reference_piece` de la facture DOIT n'est pas NULL

---

## 🚀 Prochaines améliorations possibles

1. **Alerte automatique** : "Cette facture a déjà un AVOIR associé" lors de la saisie
2. **Visualisation** : Badge "Annulée" sur les factures ayant un AVOIR
3. **Rapport** : Liste des factures annulées avec leur AVOIR
4. **Auto-complétion** : Liste déroulante des factures DOIT disponibles

---

## 📞 Support

Si vous rencontrez un problème ou avez des questions, vérifiez :
1. Que le script SQL a bien été exécuté
2. Que les champs apparaissent dans le formulaire
3. Que la Balance Âgée ne montre plus les factures annulées

---

*Document créé le : 13 Janvier 2025*
*Version : 1.0*
*Fonctionnalité : Gestion Factures AVOIR*
