# 📜 Implémentation Article 664 AUDSCGIE - 2025-12-19

## 📋 Table des Matières

1. [Vue d'Ensemble](#vue-densemble)
2. [Texte Légal Complet](#texte-légal-complet)
3. [Implémentation Technique](#implémentation-technique)
4. [Niveaux d'Alerte](#niveaux-dalerte)
5. [Calculs et Seuils](#calculs-et-seuils)
6. [Affichage des Alertes](#affichage-des-alertes)
7. [Obligations Légales](#obligations-légales)
8. [Sanctions](#sanctions)
9. [Recommandations](#recommandations)
10. [Cas Pratiques](#cas-pratiques)

---

## 📖 Vue d'Ensemble

L'Article 664 de l'Acte Uniforme OHADA relatif au Droit des Sociétés Commerciales et du Groupement d'Intérêt Économique (AUDSCGIE) impose des obligations spécifiques aux sociétés dont les capitaux propres deviennent inférieurs à la moitié du capital social.

### Objectif de l'Implémentation

Cette implémentation vise à:
- ✅ Détecter automatiquement la situation d'alerte Article 664
- ✅ Informer les utilisateurs des obligations légales
- ✅ Préciser les délais et procédures à respecter
- ✅ Alerter sur les sanctions en cas de non-respect
- ✅ Proposer des recommandations d'action

---

## 📜 Texte Légal Complet

### Article 664 AUDSCGIE

> **"Si, du fait de pertes constatées dans les états financiers de synthèse, les capitaux propres de la société deviennent inférieurs à la moitié du capital social, le conseil d'administration ou l'administrateur général, selon le cas, est tenu, dans les quatre mois qui suivent l'approbation des comptes ayant fait apparaître cette perte, de convoquer l'assemblée générale extraordinaire à l'effet de décider s'il y a lieu à dissolution anticipée de la société."**

### Analyse du Texte

#### Condition de Déclenchement
**Capitaux Propres < 50% du Capital Social**

Formule:
```
CP < (Capital Social / 2)
```

#### Responsables
- Conseil d'administration, OU
- Administrateur général (selon la forme de société)

#### Délai Impératif
**4 mois** à compter de l'approbation des comptes par l'Assemblée Générale Ordinaire

#### Action Requise
**Convocation d'une Assemblée Générale Extraordinaire (AGE)**

#### Objet de l'AGE
**Décider s'il y a lieu à dissolution anticipée de la société**

Options possibles:
1. **Dissolution anticipée** de la société
2. **Poursuite d'activité** avec mesures de reconstitution des capitaux propres

---

## ⚙️ Implémentation Technique

### Fichier Modifié

**[pages/etats_financiers/bilan.php](c:\wamp64\www\comptabilite_ohada\pages\etats_financiers\bilan.php)**

### Code Source

#### 1. Calcul des Métriques (lignes 841-858)

```php
<?php
// Extraire les données nécessaires pour les alertes
$cp_n = $passif['CP']['net'] ?? 0;
$cp_n1 = $passif_n1['CP']['net'] ?? 0;
$total_actif_n = $actif['BZ']['net'] ?? 0;
$capital_social = $passif['CA']['net'] ?? 0; // Capital social (ligne CA)

// Calculer le ratio CP / Total Actif
$ratio_cp = $total_actif_n > 0 ? ($cp_n / $total_actif_n) * 100 : 0;

// Calculer le ratio CP / Capital Social (Article 664 AUDSCGIE)
$ratio_cp_capital = $capital_social > 0 ? ($cp_n / $capital_social) * 100 : 0;

// Déterminer le niveau d'alerte
$alerte_critique = $cp_n < 0; // Capitaux propres négatifs
$alerte_art664 = !$alerte_critique && $capital_social > 0 && $cp_n < ($capital_social / 2); // Article 664: CP < 50% du capital
$alerte_warning = !$alerte_critique && !$alerte_art664 && $ratio_cp < 10; // CP < 10% de l'actif
$alerte_info = !$alerte_critique && !$alerte_art664 && !$alerte_warning && $cp_n < $cp_n1; // CP en diminution
?>
```

#### 2. Logique de Priorisation des Alertes

Les alertes sont mutuellement exclusives et s'affichent par ordre de gravité:

```
1. Alerte CRITIQUE (CP < 0)
   ↓
2. Alerte ARTICLE 664 (0 < CP < 50% du Capital)
   ↓
3. Alerte WARNING (CP < 10% de l'actif)
   ↓
4. Alerte INFO (CP en diminution)
```

---

## 🚨 Niveaux d'Alerte

### Niveau 1: CRITIQUE (CP < 0) 🔴

**Condition**: `$cp_n < 0`

**Signification**: Capitaux propres négatifs - Situation de faillite virtuelle

**Couleur**: Rouge (red-900/30)

**Article 664**: Mentionné dans l'alerte avec note que la situation est plus grave que le seuil de 50%

**Code** (lignes 861-918):
```php
<?php if ($alerte_critique): ?>
<div class="mb-6 bg-red-900/30 border-2 border-red-500 rounded-xl p-6">
    <!-- ... -->
    <div class="mt-4 bg-red-800/50 p-4 rounded-lg border-l-4 border-red-400">
        <p class="font-bold mb-2 text-red-100">
            <i class="fas fa-gavel mr-2"></i>Article 664 AUDSCGIE
        </p>
        <p class="text-sm italic mb-3 text-red-100">
            "Si, du fait de pertes constatées dans les états financiers de synthèse, les capitaux propres de la société
            deviennent inférieurs à la moitié du capital social, le conseil d'administration ou l'administrateur général,
            selon le cas, est tenu, dans les quatre mois qui suivent l'approbation des comptes ayant fait apparaître cette
            perte, de convoquer l'assemblée générale extraordinaire à l'effet de décider s'il y a lieu à dissolution
            anticipée de la société."
        </p>
        <p class="text-sm font-semibold text-red-100">
            ⚠️ Vos capitaux propres sont négatifs, ce qui est plus grave que le seuil de 50% du capital social.
        </p>
    </div>
    <!-- ... -->
</div>
<?php elseif ($alerte_art664): ?>
```

---

### Niveau 2: ARTICLE 664 (0 < CP < 50% Capital) 🟠

**Condition**:
```php
!$alerte_critique && $capital_social > 0 && $cp_n < ($capital_social / 2)
```

**Signification**: Déclenchement de l'obligation légale Article 664

**Couleur**: Orange (orange-900/30)

**Code** (lignes 923-1007):
```php
<?php elseif ($alerte_art664): ?>
<div class="mb-6 bg-orange-900/30 border-2 border-orange-500 rounded-xl p-6">
    <div class="flex items-start gap-4">
        <div class="flex-shrink-0">
            <div class="w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center">
                <i class="fas fa-balance-scale text-white text-2xl"></i>
            </div>
        </div>
        <div class="flex-1">
            <h3 class="text-xl font-bold text-orange-300 mb-2">
                ⚖️ ALERTE ARTICLE 664 AUDSCGIE: Capitaux Propres < 50% du Capital Social
            </h3>

            <!-- Métriques Grid -->
            <div class="text-orange-200 space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-orange-300">Capital Social (CA):</p>
                        <p class="font-bold text-lg"><?= number_format($capital_social, 2, ',', ' ') ?> FCFA</p>
                    </div>
                    <div>
                        <p class="text-sm text-orange-300">Capitaux Propres (CP):</p>
                        <p class="font-bold text-lg"><?= number_format($cp_n, 2, ',', ' ') ?> FCFA</p>
                    </div>
                    <div>
                        <p class="text-sm text-orange-300">Seuil de 50%:</p>
                        <p class="font-bold text-lg"><?= number_format($capital_social / 2, 2, ',', ' ') ?> FCFA</p>
                    </div>
                    <div>
                        <p class="text-sm text-orange-300">Ratio CP / Capital:</p>
                        <p class="font-bold text-lg"><?= number_format($ratio_cp_capital, 2, ',', ' ') ?>%</p>
                    </div>
                </div>

                <!-- Texte Article 664 -->
                <!-- Obligations Légales -->
                <!-- Sanctions -->
                <!-- Recommandations -->
                <!-- Lien vers évolution report -->
            </div>
        </div>
    </div>
</div>
<?php elseif ($alerte_warning): ?>
```

---

### Niveau 3: WARNING (CP < 10% Actif) 🟡

**Condition**:
```php
!$alerte_critique && !$alerte_art664 && $ratio_cp < 10
```

**Signification**: Capitaux propres faibles par rapport au total actif

**Couleur**: Jaune (yellow-900/30)

---

### Niveau 4: INFO (CP en diminution) 🔵

**Condition**:
```php
!$alerte_critique && !$alerte_art664 && !$alerte_warning && $cp_n < $cp_n1
```

**Signification**: Tendance à la baisse des capitaux propres

**Couleur**: Bleu (blue-900/30)

---

## 📊 Calculs et Seuils

### Données Sources

| Variable | Source | Description |
|----------|--------|-------------|
| `$cp_n` | `$passif['CP']['net']` | Capitaux Propres exercice N |
| `$cp_n1` | `$passif_n1['CP']['net']` | Capitaux Propres exercice N-1 |
| `$capital_social` | `$passif['CA']['net']` | Capital Social (ligne CA du passif) |
| `$total_actif_n` | `$actif['BZ']['net']` | Total Actif (ligne BZ) exercice N |

### Formules

#### Ratio CP / Capital Social
```php
$ratio_cp_capital = $capital_social > 0 ? ($cp_n / $capital_social) * 100 : 0;
```

**Interprétation**:
- `>= 100%`: Situation normale (CP >= Capital)
- `50% - 100%`: Situation acceptable
- `< 50%`: **DÉCLENCHEMENT ARTICLE 664**
- `< 0%`: Capitaux propres négatifs (critique)

#### Ratio CP / Total Actif
```php
$ratio_cp = $total_actif_n > 0 ? ($cp_n / $total_actif_n) * 100 : 0;
```

**Interprétation**:
- `>= 10%`: Situation acceptable
- `< 10%`: Alerte warning
- Plus le ratio est élevé, plus la société est solide

#### Seuil de 50% du Capital Social
```php
$seuil_50_pct = $capital_social / 2;
$alerte_art664 = $cp_n < $seuil_50_pct;
```

---

## 🎨 Affichage des Alertes

### Structure de l'Alerte Article 664

#### A. En-tête avec Icône Balance
```html
<div class="w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center">
    <i class="fas fa-balance-scale text-white text-2xl"></i>
</div>
```

#### B. Métriques Grid (2 colonnes responsives)

Affiche:
- Capital Social (CA)
- Capitaux Propres (CP)
- Seuil de 50% du capital
- Ratio CP / Capital en pourcentage

```html
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <!-- 4 métriques clés -->
</div>
```

#### C. Texte de l'Article 664

Encadré orange avec bordure gauche:
```html
<div class="mt-4 bg-orange-800/50 p-4 rounded-lg border-l-4 border-orange-400">
    <p class="font-bold mb-2 text-orange-100">
        <i class="fas fa-gavel mr-2"></i>Article 664 de l'Acte Uniforme OHADA...
    </p>
    <p class="text-sm italic mb-3 text-orange-100">
        "Si, du fait de pertes constatées..."
    </p>
</div>
```

#### D. Obligations Légales (Liste Ordonnée)

5 points numérotés:
1. Délai de 4 mois
2. Convocation AGE
3. Ordre du jour
4. Si poursuite
5. Délai de reconstitution

```html
<ol class="list-decimal list-inside space-y-2 text-sm">
    <!-- 5 obligations -->
</ol>
```

#### E. Sanctions (Liste à Puces)

4 sanctions possibles:
- Responsabilité civile
- Responsabilité pénale
- Nullité des actes
- Dissolution judiciaire

```html
<ul class="list-disc list-inside space-y-1 text-sm">
    <!-- 4 sanctions -->
</ul>
```

#### F. Recommandations (Liste à Puces)

6 actions recommandées:
1. Consulter un expert-comptable
2. Documenter la situation
3. Préparer dossier AGE
4. Étudier mesures de redressement
5. Respecter délai de 4 mois
6. Consulter avocat spécialisé

```html
<ul class="list-disc list-inside space-y-1 text-sm">
    <!-- 6 recommandations -->
</ul>
```

#### G. Lien vers Évolution Report

Bouton pour analyser la tendance:
```html
<a href="<?php echo $basePath; ?>/rapports/evolution_report_nouveau.php">
    <i class="fas fa-chart-line mr-2"></i>Consulter l'Évolution du Report à Nouveau
</a>
```

---

## ⚖️ Obligations Légales

### 1. Délai de 4 Mois

**Point de départ**: Date d'approbation des comptes par l'Assemblée Générale Ordinaire (AGO)

**Exemple**:
- AGO du 30/04/2026 approuve les comptes 2025
- Détection: CP 2025 < 50% du Capital Social
- **Délai limite**: 30/08/2026 (4 mois après l'AGO)

### 2. Convocation de l'AGE

**Responsable**:
- Conseil d'Administration, OU
- Administrateur Général

**Procédure**:
- Lettre recommandée avec AR
- Délai de convocation: selon statuts (généralement 15 jours minimum)
- Respect des formalités statutaires

### 3. Ordre du Jour de l'AGE

**Objet principal**:
> "Décision sur la dissolution anticipée de la société suite à la constatation que les capitaux propres sont devenus inférieurs à la moitié du capital social (Article 664 AUDSCGIE)"

**Décisions possibles**:
1. **Dissolution anticipée** de la société
2. **Poursuite d'activité** avec plan de reconstitution

### 4. Si Poursuite d'Activité

**L'AGE doit décider**:
- Des mesures concrètes de reconstitution des capitaux propres
- Du délai de reconstitution (généralement 2 exercices)
- Des moyens de redressement

**Mesures possibles**:
- Augmentation de capital
- Incorporation de créances
- Conversion de dettes en capital
- Abandon de compte courant d'associés
- Apports nouveaux
- Réduction des charges structurelles

### 5. Délai de Reconstitution

**Délai raisonnable**:
- Généralement: 2 exercices comptables
- À préciser dans la décision de l'AGE
- Suivi par le commissaire aux comptes

**Contrôle**:
- Vérification chaque année
- Si échec: nouvelle AGE obligatoire

---

## ⚠️ Sanctions

### 1. Responsabilité Civile des Dirigeants

**Fondement**: Faute de gestion

**Conséquences**:
- Dommages-intérêts envers la société
- Dommages-intérêts envers les tiers lésés
- Solidarité entre dirigeants

**Montant**:
- Variable selon le préjudice
- Peut être très élevé en cas de poursuite d'activité sans AGE

### 2. Responsabilité Pénale

**Fondement**: Infractions selon les législations nationales

**Sanctions possibles**:
- Amende
- Emprisonnement (selon gravité et pays)
- Interdiction de gérer

**Exemples d'infractions**:
- Présentation de comptes infidèles
- Détournement d'actifs
- Abus de biens sociaux

### 3. Nullité des Actes

**Actes concernés**: Décisions prises après expiration du délai de 4 mois

**Conséquences**:
- Annulation rétroactive
- Restitution des prestations
- Dommages-intérêts pour les tiers de bonne foi

### 4. Dissolution Judiciaire

**Demandeurs possibles**:
- Tout intéressé (associés, créanciers, etc.)
- Ministère Public
- Commissaire aux comptes

**Procédure**:
- Saisine du tribunal
- Demande de dissolution pour non-respect de l'Article 664
- Décision judiciaire exécutoire

**Conséquences**:
- Liquidation de la société
- Perte de contrôle par les associés
- Désignation d'un liquidateur judiciaire

---

## 💡 Recommandations

### 1. Consulter un Expert-Comptable

**Objectif**: Audit approfondi de la situation financière

**Missions**:
- Vérification des calculs
- Identification des causes des pertes
- Analyse de la viabilité économique
- Proposition de mesures de redressement

### 2. Documenter la Situation

**Documents à préparer**:
- États financiers détaillés
- Rapport de gestion
- Analyse des écarts budget/réalisé
- Tableau de flux de trésorerie
- Prévisionnel sur 2-3 ans

### 3. Préparer le Dossier AGE

**Contenu du dossier**:
- Rapport du conseil d'administration
- États financiers certifiés
- Rapport du commissaire aux comptes
- Projet de résolutions
- Plan de redressement (si poursuite)

### 4. Étudier les Mesures de Redressement

**Axes d'analyse**:

#### A. Renforcement des Capitaux Propres
- Augmentation de capital en numéraire
- Incorporation de comptes courants
- Incorporation de réserves
- Conversion de dettes

#### B. Réduction des Charges
- Analyse des charges fixes
- Renégociation des contrats
- Optimisation des effectifs
- Réduction des frais généraux

#### C. Amélioration du Chiffre d'Affaires
- Développement commercial
- Nouveaux marchés
- Nouveaux produits/services
- Amélioration des marges

#### D. Optimisation de la Trésorerie
- Réduction du BFR
- Accélération des encaissements
- Négociation des délais fournisseurs
- Cession d'actifs non stratégiques

### 5. Respecter le Délai de 4 Mois

**Planning type**:

| Semaine | Action |
|---------|--------|
| S1-S2 | Audit complet et diagnostic |
| S3-S4 | Élaboration du plan de redressement |
| S5-S6 | Préparation dossier AGE |
| S7-S8 | Consultation des associés |
| S9-S10 | Convocation officielle AGE |
| S11-S12 | Tenue de l'AGE |
| S13-S16 | Formalités post-AGE |

### 6. Consulter un Avocat Spécialisé

**Domaines d'expertise**:
- Droit des sociétés OHADA
- Droit commercial
- Restructuration d'entreprises

**Missions**:
- Conseil sur la procédure
- Rédaction des convocations
- Rédaction des résolutions
- Sécurisation juridique
- Défense en cas de contentieux

---

## 📚 Cas Pratiques

### Cas 1: Société avec CP < 50% Capital (Alerte Article 664)

#### Données
```
Capital Social (CA):    1,000,000,000 FCFA
Capitaux Propres (CP):    400,000,000 FCFA
Seuil de 50%:             500,000,000 FCFA
Ratio CP / Capital:       40%
```

#### Diagnostic
✅ CP positifs (pas de situation critique)
❌ CP < 50% du Capital Social
**→ DÉCLENCHEMENT ARTICLE 664**

#### Alerte Affichée
**Alerte Orange "ARTICLE 664 AUDSCGIE"** avec:
- Métriques détaillées
- Texte intégral de l'Article 664
- Liste des obligations légales
- Liste des sanctions
- Recommandations d'action

#### Actions Requises
1. **Immédiat**: Informer le conseil d'administration
2. **Semaine 1-2**: Audit complet avec expert-comptable
3. **Semaine 3-4**: Élaboration plan de redressement
4. **Semaine 5-6**: Préparation dossier AGE
5. **Avant 4 mois**: Convocation et tenue de l'AGE

#### Mesures de Redressement Possibles
- Augmentation de capital de 200M FCFA (pour atteindre 600M CP > 500M seuil)
- Incorporation de compte courant d'associé de 150M FCFA
- Plan de rentabilité sur 2 exercices

---

### Cas 2: Société avec CP Négatifs (Alerte Critique + Article 664)

#### Données
```
Capital Social (CA):      1,000,000,000 FCFA
Capitaux Propres (CP):   -1,586,846,773 FCFA
Seuil de 50%:               500,000,000 FCFA
Ratio CP / Capital:        -158.68%
```

#### Diagnostic
❌ CP négatifs (situation critique)
❌ CP << 50% du Capital Social
**→ ALERTE CRITIQUE + Mention Article 664**

#### Alerte Affichée
**Alerte Rouge "CRITIQUE"** avec:
- Indication CP négatifs
- Encadré spécial Article 664 mentionnant que la situation est plus grave que le seuil de 50%
- Urgence absolue
- Lien vers évolution du report à nouveau

#### Actions Requises
1. **Immédiat**: Réunion d'urgence du conseil
2. **Semaine 1**: Audit de crise
3. **Semaine 2**: Décision dissolution ou redressement massif
4. **Avant 4 mois**: AGE obligatoire

#### Mesures de Redressement Possibles
- Augmentation de capital massive (> 2.5 milliards FCFA)
- Restructuration complète de la dette
- Abandon de créances par les associés
- Cession d'actifs majeurs
- **Réaliste ?** Évaluer sérieusement la dissolution

---

### Cas 3: Société Proche du Seuil (CP = 52% du Capital)

#### Données
```
Capital Social (CA):    1,000,000,000 FCFA
Capitaux Propres (CP):    520,000,000 FCFA
Seuil de 50%:             500,000,000 FCFA
Ratio CP / Capital:       52%
```

#### Diagnostic
✅ CP positifs
✅ CP > 50% du Capital Social (pas de déclenchement Article 664)
⚠️ Proximité du seuil (marge de seulement 20M FCFA)

#### Alerte Affichée
**Alerte Jaune "WARNING"** (CP < 10% de l'actif, si applicable)
OU
**Alerte Bleue "INFO"** (si CP en diminution par rapport à N-1)

#### Actions Préventives
1. Surveillance mensuelle des CP
2. Plan de rentabilité pour augmenter les CP
3. Préparer un plan de contingence si passage sous 50%
4. Informer les associés de la proximité du seuil

#### Objectif
**Remonter les CP au-dessus de 60-70% du capital** pour avoir une marge de sécurité confortable

---

### Cas 4: Société avec CP > Capital Social (Situation Saine)

#### Données
```
Capital Social (CA):    1,000,000,000 FCFA
Capitaux Propres (CP):  1,500,000,000 FCFA
Seuil de 50%:             500,000,000 FCFA
Ratio CP / Capital:       150%
```

#### Diagnostic
✅ CP positifs
✅ CP > Capital Social
✅ Aucun seuil d'alerte franchi

#### Alerte Affichée
**Aucune alerte** (ou alerte verte de félicitations si implémentée)

#### Situation
**Situation financière saine**
- Capitaux propres représentent 150% du capital social
- Réserves de 500M FCFA
- Capacité d'autofinancement solide

---

## 🔗 Documents Associés

### Documentation Technique
- [GUIDE_REPORT_A_NOUVEAU.md](c:\wamp64\www\comptabilite_ohada\GUIDE_REPORT_A_NOUVEAU.md) - Guide complet du report à nouveau
- [CORRECTIONS_FINALES_REPORT_A_NOUVEAU.md](c:\wamp64\www\comptabilite_ohada\CORRECTIONS_FINALES_REPORT_A_NOUVEAU.md) - Corrections techniques
- [AMELIORATIONS_RAPPORT_EVOLUTION.md](c:\wamp64\www\comptabilite_ohada\AMELIORATIONS_RAPPORT_EVOLUTION.md) - Améliorations du rapport d'évolution
- [SYNTHESE_COMPLETE_SESSION_2025-12-19.md](c:\wamp64\www\comptabilite_ohada\SYNTHESE_COMPLETE_SESSION_2025-12-19.md) - Synthèse complète de la session

### Fichiers Impactés
- [pages/etats_financiers/bilan.php](c:\wamp64\www\comptabilite_ohada\pages\etats_financiers\bilan.php) - Affichage du bilan avec alertes
- [pages/rapports/evolution_report_nouveau.php](c:\wamp64\www\comptabilite_ohada\pages\rapports\evolution_report_nouveau.php) - Évolution du report à nouveau

---

## 📅 Informations de Version

**Date de réalisation**: 2025-12-19
**Version**: 1.0
**Statut**: ✅ Opérationnel
**Auteur**: Claude Code (Anthropic)
**Conformité**: SYSCOHADA / OHADA Article 664 AUDSCGIE

---

## 📞 Utilisation

### Accès aux Alertes

1. **Via le Bilan**:
   - Ouvrir: `pages/etats_financiers/bilan.php`
   - Sélectionner l'exercice N
   - L'alerte s'affiche automatiquement en haut de page si CP < 50% du capital

2. **Vérification Manuelle**:
   ```
   Ratio CP / Capital = (CP / Capital Social) × 100

   Si Ratio < 50% → Alerte Article 664
   Si Ratio < 0% → Alerte Critique + Article 664
   ```

3. **Analyse de Tendance**:
   - Cliquer sur le lien "Consulter l'Évolution du Report à Nouveau"
   - Voir l'évolution année par année
   - Identifier les causes de dégradation

### Interprétation des Alertes

| Couleur | Niveau | Condition | Action |
|---------|--------|-----------|--------|
| 🔴 Rouge | CRITIQUE | CP < 0 | Urgence absolue - AGE dans 4 mois |
| 🟠 Orange | ARTICLE 664 | 0 < CP < 50% Capital | AGE obligatoire dans 4 mois |
| 🟡 Jaune | WARNING | CP < 10% Actif | Surveillance renforcée |
| 🔵 Bleu | INFO | CP en baisse | Analyse des causes |

---

## ✅ Points de Contrôle

### Conformité Légale
- ✅ Texte intégral de l'Article 664 affiché
- ✅ Délai de 4 mois mentionné
- ✅ Obligations détaillées
- ✅ Sanctions listées
- ✅ Procédure AGE expliquée

### Exactitude des Calculs
- ✅ Capital Social extrait de passif['CA']
- ✅ CP extrait de passif['CP']
- ✅ Seuil de 50% calculé correctement
- ✅ Ratio en pourcentage affiché

### Expérience Utilisateur
- ✅ Alertes visuellement distinctes (couleurs)
- ✅ Métriques clés affichées
- ✅ Texte légal intégral fourni
- ✅ Recommandations actionnables
- ✅ Lien vers analyse de tendance

### Priorisation
- ✅ Alerte critique prioritaire sur Article 664
- ✅ Article 664 prioritaire sur warning
- ✅ Warning prioritaire sur info
- ✅ Une seule alerte affichée à la fois

---

## 🎓 Références Légales

### Acte Uniforme OHADA
- **Titre**: Acte Uniforme relatif au Droit des Sociétés Commerciales et du Groupement d'Intérêt Économique (AUDSCGIE)
- **Article**: 664
- **Thème**: Dissolution pour pertes (capitaux propres < 50% capital social)
- **Applicable**: Tous les pays membres de l'OHADA

### Pays Membres OHADA (17 pays)
1. Bénin
2. Burkina Faso
3. Cameroun
4. Centrafrique
5. Comores
6. Congo
7. Congo RDC
8. Côte d'Ivoire
9. Gabon
10. Guinée
11. Guinée-Bissau
12. Guinée Équatoriale
13. Mali
14. Niger
15. Sénégal
16. Tchad
17. Togo

---

**Fin du document**

Pour toute question ou besoin de clarification, consulter la documentation technique associée ou contacter un expert-comptable agréé OHADA.
