# 📘 Guide du Report à Nouveau - Logique Cumulative

## Date: 2025-12-19

## 🎯 Vue d'Ensemble

Ce guide explique la logique cumulative du report à nouveau implémentée dans votre système de comptabilité SYSCOHADA, conformément à votre demande et aux normes comptables.

---

## 📚 Principe Fondamental

### Report à Nouveau Cumulatif

**Le report à nouveau (CH) accumule TOUS les résultats antérieurs non affectés, année après année.**

### Formule Générale

```
CH (Année N) = Compte 12 + Σ (Tous les résultats jusqu'à N-1)
```

Où:
- **Compte 12**: Écritures d'affectation du résultat (passées manuellement)
- **Compte 13**: Stockage des résultats de chaque exercice

---

## 💡 Exemple Concret (Votre Cas)

### Situation Initiale - 2024 (N-1)
```
Résultat 2024 (CJ): -1,586,846,772.89 FCFA
À la clôture → stocké dans compte 13
```

### Exercice 2025 (N)
```
CH (Report à nouveau): -1,586,846,772.89 FCFA  ← Résultat 2024
CJ (Résultat 2025):    -585,109,602.56 FCFA
```

### Exercice 2026 (N+1)
```
CH (Report à nouveau): -1,586,846,772.89 + (-585,109,602.56)
                     = -2,171,956,375.45 FCFA  ← Cumul 2024 + 2025
CJ (Résultat 2026):    +2,000,000,000.00 FCFA  (hypothèse)
```

### Exercice 2027 (N+2)
```
CH (Report à nouveau): -2,171,956,375.45 + 2,000,000,000.00
                     = -171,956,375.45 FCFA  ← Cumul 2024 + 2025 + 2026
CJ (Résultat 2027):    à calculer
```

---

## 🔧 Implémentation Technique

### 1. Code dans bilan.php (lignes 243-303)

```php
// CH (Report à nouveau) en N = Compte 12 + Résultat N-1
$sql_resultat_n1 = "
    SELECT COALESCE(SUM(le.credit - le.debit), 0) as resultat_n1
    FROM plan_comptable pc
    LEFT JOIN lignes_ecriture le ON pc.compte = le.compte
    LEFT JOIN ecritures e ON le.id_ecriture = e.id
    WHERE pc.actif = 'Oui'
    AND LEFT(pc.compte, 2) = '13'
    AND e.date_ecriture < ?      -- ← CLEF: Tout AVANT le début de N
    AND e.statut = 'Validé'
";

$stmt_resultat_n1->execute([$date_debut]);  // Exemple: '2025-01-01'
```

**Magie de la formule:**
- En 2025: `date < '2025-01-01'` → Prend le compte 13 jusqu'au 31/12/2024 = Résultat 2024
- En 2026: `date < '2026-01-01'` → Prend le compte 13 jusqu'au 31/12/2025 = Résultats 2024 + 2025
- En 2027: `date < '2027-01-01'` → Prend le compte 13 jusqu'au 31/12/2026 = Résultats 2024 + 2025 + 2026

**C'est automatiquement cumulatif!** 🎉

### 2. Structure des Capitaux Propres

```
CP = CA + CB + CD + CE + CF + CG + CH + CJ + CL + CM
```

Détail:
- **CA**: Capital social
- **CF**: Réserves indisponibles (compte 11)
- **CH**: Report à nouveau (compte 12 + résultats cumulés) ← NOTRE FOCUS
- **CJ**: Résultat de l'exercice en cours (classes 6,7,8)
- **CL**: Subventions d'investissement

---

## 📊 Outils Créés

### 1. Commentaires Explicatifs dans le Code

**Fichier**: `pages/etats_financiers/bilan.php` lignes 243-267

Documentation complète avec:
- Principe SYSCOHADA
- Fonctionnement cumulatif
- Exemple concret avec vos chiffres
- Note sur l'affectation du résultat

### 2. Rapport d'Évolution

**Fichier**: `pages/rapports/evolution_report_nouveau.php`

**Fonctionnalités**:
- Tableau d'évolution année par année
- Affichage de Compte 12, Cumul Antérieur, CH, CJ
- Graphique des variations
- Statistiques (années en déficit/bénéfice)
- Filtrage par période

**Accès**:
```
http://localhost/comptabilite_ohada/pages/rapports/evolution_report_nouveau.php
```

Ou via le bouton dans le bilan (affiché si CP < 0 ou ratio CP/Actif < 20%)

### 3. Alertes Capitaux Propres

**Fichier**: `pages/etats_financiers/bilan.php` lignes 840-940

**3 Niveaux d'Alerte**:

#### Niveau 1: Alerte Critique (CP < 0)
```
🔴 ALERTE CRITIQUE: Capitaux Propres Négatifs
- Affichage avec animation pulse
- Recommandations d'actions
- Rappel des obligations légales
```

#### Niveau 2: Alerte Warning (CP < 10% Actif)
```
🟡 ATTENTION: Capitaux Propres Faibles
- Affichage du ratio CP / Actif
- Recommandations d'amélioration
```

#### Niveau 3: Information (CP en Diminution)
```
🔵 INFO: Capitaux Propres en Diminution
- Comparaison N vs N-1
- Affichage de la variation
```

---

## ⚖️ Normes SYSCOHADA vs Pratique Actuelle

### Norme SYSCOHADA (Idéal)

À la clôture de chaque exercice, l'Assemblée Générale doit décider de l'affectation du résultat:

```
Résultat de l'Exercice
├─ Réserve Légale (5% minimum jusqu'à 20% du capital)
├─ Réserves Statutaires (selon statuts)
├─ Réserves Facultatives (décision AG)
├─ Dividendes (distribution aux actionnaires)
└─ Report à Nouveau (solde non affecté)
```

**Écriture comptable d'affectation**:
```
Débit:  Compte 13x (Résultat)
Crédit: Compte 11x (Réserves)
Crédit: Compte 12x (Report à nouveau)
Crédit: Compte 46x (Dividendes à payer)
```

### Votre Pratique Actuelle (Valide en l'absence d'AG)

**En l'absence de décision d'affectation par l'Assemblée Générale:**

```
Résultat de l'Exercice
└─ Report à Nouveau (automatique via compte 13)
```

**Avantages**:
- ✅ Simple et automatique
- ✅ Conforme tant qu'aucune décision d'AG n'est prise
- ✅ Permet un suivi cumulatif des pertes/bénéfices

**À faire quand l'AG se réunira**:
1. Décider de l'affectation des résultats cumulés
2. Passer les écritures d'affectation dans compte 12
3. Le système basculera automatiquement sur les soldes réels du compte 12

---

## 🔍 Vérifications et Contrôles

### Vérification 1: Équilibre du Bilan

```
ACTIF = PASSIF

Avec:
PASSIF = CP + Dettes + Provisions
CP = ... + CH + CJ + ...
```

**Attendu**: Total Actif = Total Passif pour N et N-1

### Vérification 2: Continuité du Report

```
CH (2026) devrait égaler:
CH (2025) + CJ (2025)
```

Vérifiable dans le rapport d'évolution.

### Vérification 3: Balance Équilibrée

```
Total Débit = Total Crédit
```

Vérifiable via le popup "Balance" dans le bilan.

---

## 🚀 Utilisation Pratique

### Pour Consulter le Bilan

1. Accéder au bilan:
   ```
   http://localhost/comptabilite_ohada/pages/etats_financiers/bilan.php
   ```

2. Observer:
   - **CH (Report à nouveau)**: Cumul des résultats antérieurs
   - **CJ (Résultat exercice)**: Résultat de l'année en cours
   - **CP (Capitaux Propres)**: Somme incluant CH et CJ

3. Vérifier l'alerte si affichée (CP négatifs, faibles, ou en diminution)

### Pour Analyser l'Évolution

1. Cliquer sur "Voir l'Évolution du Report à Nouveau" (si lien affiché)

   OU accéder directement:
   ```
   http://localhost/comptabilite_ohada/pages/rapports/evolution_report_nouveau.php
   ```

2. Filtrer la période d'analyse (années de début/fin)

3. Observer:
   - Évolution du report à nouveau année par année
   - Total cumulé progressif
   - Années en déficit vs bénéfice
   - Variation entre années

### Pour Consulter la Balance

1. Dans le bilan, cliquer sur le bouton "Balance" (cyan)

2. Observer:
   - Tous les comptes avec leurs soldes
   - Rubrique BD/BC selon la nature du solde
   - Total Débit = Total Crédit (devrait être équilibré)

3. Cliquer sur un compte pour voir son grand livre

---

## 📝 Cas Pratiques

### Cas 1: Pertes Continues (Votre Situation)

**Scénario**:
- 2024: Perte de -1,586,846,772.89
- 2025: Perte de -585,109,602.56
- 2026: À venir

**Impact**:
```
CH (2026) = -1,586,846,772.89 + (-585,109,602.56) = -2,171,956,375.45
CP (2026) = ... + CH(-2,171,956,375.45) + CJ(résultat 2026) + ...
```

**Alerte affichée**: Critique si CP < 0, Warning si CP < 10% actif

**Action recommandée**: Consulter le rapport d'évolution, envisager redressement

### Cas 2: Retour à l'Équilibre

**Scénario**:
- Cumul jusqu'à 2025: -2,171,956,375.45
- 2026: Bénéfice de +2,000,000,000.00

**Impact**:
```
CH (2027) = -2,171,956,375.45 + 2,000,000,000.00 = -171,956,375.45
CP (2027) = ... + CH(-171,956,375.45) + CJ(résultat 2027) + ...
```

**Progression**: CP moins négatifs → Alerte passe de Critique à Warning puis Info

### Cas 3: Affectation par l'AG

**Scénario**: L'AG décide d'affecter les résultats cumulés

**Étapes**:
1. AG décide: par exemple, mise en réserves de 50%, report du reste
2. Écriture comptable:
   ```
   Débit:  131xxx (Résultat cumulé)         2,171,956,375.45
   Crédit: 11xxxx (Réserves)                1,085,978,187.72
   Crédit: 12xxxx (Report à nouveau)        1,085,978,187.73
   ```
3. Le système utilisera automatiquement le compte 12 dans CH

**Résultat**:
```
CH (futur) = Compte 12 (nouveau solde) + Compte 13 (nouveaux résultats)
```

---

## ✅ Résumé des Améliorations

### 1. Code Documenté
- ✅ Commentaires explicatifs complets
- ✅ Exemples concrets avec vos chiffres
- ✅ Notes sur les normes SYSCOHADA

### 2. Rapport d'Évolution
- ✅ Visualisation année par année
- ✅ Statistiques et analyse
- ✅ Filtrage personnalisable

### 3. Système d'Alertes
- ✅ 3 niveaux d'alerte (Critique/Warning/Info)
- ✅ Recommandations d'actions
- ✅ Lien vers outils d'analyse

### 4. Logique Automatique
- ✅ Cumul automatique des résultats
- ✅ Pas de modification manuelle nécessaire
- ✅ Prêt pour affectation future par AG

---

## 🎓 Pour Aller Plus Loin

### Documentation SYSCOHADA

Consultez le Plan Comptable Général SYSCOHADA pour:
- Compte 11: Réserves
- Compte 12: Report à nouveau
- Compte 13: Résultat net de l'exercice

### Obligations Légales

Selon le Code des Sociétés (varie par pays OHADA):
- Information des actionnaires si CP < 50% du capital
- Dissolution possible si CP < 0 pendant 2 exercices
- Obligation de reconstitution des CP

### Bonnes Pratiques

1. **Suivi régulier**: Consulter le rapport d'évolution trimestriellement
2. **Anticipation**: Projeter l'impact des résultats sur les CP
3. **Documentation**: Conserver les décisions d'AG relatives à l'affectation
4. **Conseil**: Consulter un expert-comptable pour plan d'action

---

## 📞 Support

Pour toute question sur:
- Le fonctionnement du report à nouveau
- L'interprétation des alertes
- La préparation d'une AG pour affectation

Consultez ce guide ou les commentaires dans le code source.

---

**Date de création**: 2025-12-19
**Version**: 1.0
**Conforme**: SYSCOHADA Révisé
