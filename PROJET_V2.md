# PROJET ERP OHADA - VERSION 2.0
## Document de référence et feuille de route

---

## 1. VISION GLOBALE

Construire le **premier ERP SaaS véritablement adapté à l'espace OHADA**, aligné sur les standards des grands ERP mondiaux (Oracle Fusion, SAP) tout en comblant leurs lacunes pour le contexte africain.

L'outil doit être :
- **Moderne et fluide** - interface soignée, réactive, intuitive
- **Intelligent** - IA intégrée, suggestions proactives, alertes automatiques
- **Normatif** - OHADA/SYSCOHADA Révisé, IFRS, US GAAP, droit fiscal, social et juridique
- **Distinctif** - une identité forte, une référence en Côte d'Ivoire, en Afrique et dans le monde
- **SaaS-ready** - multi-société, multi-utilisateur, multi-devise, périodes mensuelles

---

## 2. MODULES PREVUS (inspirés d'Oracle Fusion)

### 2.1 General Ledger - GL (Comptabilité Générale)
- GL en **devise locale** (FCFA et autres devises OHADA)
- GL en **autre devise** (multidevise : EUR, USD, GBP, etc.)
- Centralisation de toutes les écritures
- Gestion du plan de comptes (référentiel OHADA intégré)
- Production des états financiers (bilan, compte de résultat, TFT)
- Ouverture et clôture des **périodes comptables mensuelles**
- Glossaire de requêtes documentées (chaque traitement automatique expliqué)

### 2.2 Accounts Payables - AP (Fournisseurs)
- Réception et saisie des factures fournisseurs
- Workflow de validation (approbation multi-niveaux)
- Planification et exécution des paiements
- Lettrage automatique des règlements
- Balance âgée fournisseurs
- Rapprochement avec les bons de commande (liaison avec module Achats)

### 2.3 Accounts Receivables - AR (Clients)
- Création et envoi de factures clients
- Suivi des créances et encaissements
- Lettrage des règlements clients
- Balance âgée clients
- Relances automatiques
- Liaison avec les devis et bons de commande

### 2.4 Paie et Ressources Humaines - RH
- Gestion des dossiers salariés
- Calcul des bulletins de paie (CNPS, impôts, retenues OHADA)
- Livre de paie
- Déclarations sociales et fiscales
- Historique des contrats, congés, avancements

### 2.5 Asset Management / Fixed Assets - FA (Immobilisations)
- Suivi du cycle de vie des immobilisations
- Calcul des amortissements (linéaire, dégressif)
- Cessions, mises au rebut
- Plan d'amortissement
- Intégration automatique en GL

### 2.6 Cash Management - CM (Trésorerie)
- Position de trésorerie en temps réel
- Rapprochement bancaire automatique
- Import de relevés bancaires (CSV, OFX)
- Prévisions de trésorerie
- Rapport de caisse

### 2.7 Expenses (Notes de frais)
- Saisie simplifiée des notes de frais par les collaborateurs
- Workflow de validation (manager, comptable)
- Remboursement automatique via paie ou virement
- Justificatifs attachés (GED intégrée)

### 2.8 GED - Gestion Electronique des Documents (Archives)
- Conservation sécurisée de documents (PDF, images, Excel, Word, etc.)
- Classement par type, société, période, module
- Partage interne (entre utilisateurs) et externe (lien sécurisé)
- Versioning des documents
- Liaison avec toutes les pièces comptables

---

## 3. CE QU'ON CONSERVE ET AMELIORE DE LA V1.0.0

| Element | Action |
|---|---|
| Référentiel OHADA (plan comptable SYSCOHADA Révisé) | Conserver + enrichir |
| Gestion multi-société | Conserver + renforcer (voir section 5) |
| Page de login redessinée (Standard Chartered) | Conserver |
| Authentification 2FA TOTP (Google Authenticator) | Conserver |
| Bilan, Compte de résultat, TFT | Conserver + améliorer |
| Notes annexes | Conserver + compléter |
| Grand livre, Journal, Balance | Conserver + améliorer |
| Balance âgée, Balance auxiliaire | Conserver + améliorer |
| Rapprochement bancaire | Conserver + automatiser |
| Rapport de caisse | Conserver |
| Report à nouveau | Conserver |
| Gestion des exercices | Conserver + étendre aux périodes mensuelles |
| Module paie | Conserver + améliorer |
| Immobilisations | Conserver + améliorer |
| Lettrage (avec N° facture) | Conserver |
| Saisie avec drag-and-drop | Conserver |
| Bons de commande, catalogue, devis | Conserver + améliorer |
| Budget | Conserver + améliorer |
| Suivi PCA/CCA | Conserver |
| Vue d'ensemble (dashboard analytique) | Fusionner avec le Tableau de bord principal |

---

## 4. NOUVELLES FONCTIONNALITES STRUCTURELLES

### 4.1 Onboarding Wizard (Démarrage guidé)
Quand l'application est installée sur un nouveau poste avec une BDD vide :
- **Etape 1** - Création de la première société (nom, RCCM, NIF, régime fiscal, devise, adresse)
- **Etape 2** - Création du premier compte administrateur
- **Etape 3** - Choix et import du plan comptable (référentiel OHADA proposé par défaut)
- **Etape 4** - Création du premier exercice comptable
- **Etape 5** - Paramétrage de base (logo, monnaie, format des dates, taille de police)
- **Etape 6** - Récapitulatif et accès au tableau de bord

### 4.2 Periodes comptables mensuelles
- Chaque exercice est découpé en 12 périodes (janvier à décembre)
- Chaque période peut être : Ouverte, Fermée, Verrouillée
- Les écritures ne peuvent être saisies que dans une période ouverte
- Clôture mensuelle avec contrôles automatiques (balance équilibrée, etc.)
- Seul l'admin peut rouvrir une période fermée

### 4.3 Multi-devise
- Devise de référence par société (FCFA par défaut)
- Saisie possible en devise étrangère avec taux de change
- Réévaluation automatique des soldes en devise
- Tableau des taux de change (historique)
- Etats financiers en devise locale ET en devise de référence

### 4.4 Tableau de bord unique (fusion Dashboard + Vue d'ensemble)
- La page "Vue d'ensemble" devient le nouveau Tableau de bord principal
- L'ancienne page "Tableau de bord" est supprimée
- KPIs : trésorerie, résultat net, créances clients, dettes fournisseurs
- Activité du mois, santé financière, alertes, top clients/fournisseurs
- Dernières écritures, indicateurs de performance

### 4.5 Parametre global : taille de police
- Réglable dans les paramètres utilisateur ou société
- 3 niveaux : Petit / Normal / Grand
- S'applique à toute l'interface via une variable CSS globale

### 4.6 Reorganisation du menu (nouvelle structure)
Structure proposée :
```
TABLEAU DE BORD
COMPTABILITE
  - Saisie des ecritures
  - Grand livre
  - Journal
  - Balance generale
  - Lettrage
ETATS FINANCIERS
  - Bilan
  - Compte de resultat
  - Flux de tresorerie (TFT)
  - Notes annexes
CLIENTS (AR)
  - Factures clients
  - Encaissements
  - Balance âgée clients
  - Devis / Bons de commande
FOURNISSEURS (AP)
  - Factures fournisseurs
  - Paiements
  - Balance âgée fournisseurs
  - Commandes fournisseurs
TRESORERIE (CM)
  - Rapprochement bancaire
  - Rapport de caisse
  - Position de tresorerie
IMMOBILISATIONS (FA)
  - Liste des immobilisations
  - Amortissements
  - Synthese
PAIE ET RH
  - Employes
  - Bulletins de paie
  - Livre de paie
  - Parametres de paie
NOTES DE FRAIS (Expenses)
BUDGET
  - Saisie du budget
  - Suivi budgetaire
ARCHIVES (GED)
PARAMETRES
  - Societes
  - Utilisateurs
  - Plan comptable
  - Journaux
  - Exercices et periodes
  - Tiers (clients/fournisseurs)
  - Devises et taux de change
  - Securite (2FA)
  - Preferences (police, langue, etc.)
```

---

## 5. GESTION MULTI-SOCIETE RENFORCEE

- Une installation peut gérer N sociétés (holding, filiales, etc.)
- Chaque société a : son plan comptable, ses exercices, ses utilisateurs, ses paramètres
- Un utilisateur peut être rattaché à plusieurs sociétés avec des rôles différents
- Changement de société en un clic depuis la topbar
- Isolation totale des données entre sociétés (societe_id sur toutes les tables)
- Consolidation possible (états financiers multi-sociétés) - phase avancée

---

## 6. INTELLIGENCE ARTIFICIELLE INTEGREE

L'outil doit être proactif et apprenant :

### Alertes automatiques
- Solde créditeur anormal sur un compte de trésorerie
- Facture fournisseur impayée approchant l'échéance
- Ecart de rapprochement bancaire détecté
- Ratio financier dégradé (liquidité, solvabilité, rentabilité)
- Période comptable non clôturée après la date prévue

### Suggestions intelligentes
- Imputation automatique des écritures selon l'historique (ML)
- Suggestion du compte de contrepartie lors de la saisie
- Détection des doublons de factures
- Classement automatique des documents (GED)

### Assistant normatif intégré
- Aide à l'application des normes OHADA / SYSCOHADA Révisé
- Références IFRS et US GAAP avec comparatif
- Rappels fiscaux (TVA, IS, IRPP, CNPS selon pays)
- Alertes juridiques et sociales
- Glossaire interactif des termes comptables

### Glossaire de requetes (inspiré d'Oracle)
- Chaque traitement automatique est documenté
- Description de la requete, son objectif, les tables impliquées
- Accessible aux administrateurs et auditeurs

---

## 7. REGLES ET CONVENTIONS PERMANENTES

| Regle | Detail |
|---|---|
| Caractere interdit | Ne JAMAIS utiliser "—" (tiret long). Toujours "-" |
| Standard comptable | SYSCOHADA Révisé (référence principale) |
| Devise par défaut | FCFA (XOF) - configurable par société |
| Langue | Français (principal), extensible |
| Framework CSS | Tailwind CSS |
| Icones | Font Awesome 6.4+ |
| Animations | Anime.js |
| Backend | PHP 8+ |
| Base de données | MySQL / MariaDB |
| Securite | Hashage bcrypt, 2FA TOTP, sessions sécurisées |
| Git | Ne jamais commiter le dossier vendor/, les .sql de backup, les .env |

---

## 8. PLAN D'EXECUTION PAR ETAPES

### PHASE 1 - Fondations (En cours)
- [ ] Onboarding wizard (nouvelle installation)
- [ ] Nouvelle structure de navigation / menu
- [ ] Fusion Dashboard + Vue d'ensemble
- [ ] Parametre taille de police
- [ ] Periodes comptables mensuelles (schema BDD)

### PHASE 2 - Noyau GL
- [ ] Plan de comptes enrichi + multidevise
- [ ] Saisie des ecritures (ameliorée)
- [ ] Grand livre, Journal, Balance
- [ ] Lettrage amelioré
- [ ] Clôture/ouverture des périodes

### PHASE 3 - Etats financiers
- [ ] Bilan (amelioré)
- [ ] Compte de résultat (amelioré)
- [ ] TFT (amelioré)
- [ ] Notes annexes (complétées)
- [ ] Export PDF et Excel

### PHASE 4 - Modules transactionnels
- [ ] AR - Clients (factures, encaissements, balance âgée)
- [ ] AP - Fournisseurs (factures, paiements, balance âgée)
- [ ] CM - Tresorerie (rapprochement bancaire automatise)
- [ ] FA - Immobilisations (amelioré)

### PHASE 5 - RH, Paie, Expenses
- [ ] Module Paie amelioré
- [ ] Notes de frais (Expenses)
- [ ] Declarations sociales

### PHASE 6 - GED et Archives
- [ ] Upload et stockage sécurisé
- [ ] Classement et recherche
- [ ] Partage interne/externe

### PHASE 7 - Intelligence et finition
- [ ] Alertes automatiques
- [ ] Suggestions IA (imputation, doublons)
- [ ] Assistant normatif (OHADA, IFRS, fiscal)
- [ ] Glossaire de requetes documenté
- [ ] Consolidation multi-societes

---

## 9. PROPOSITIONS COMPLEMENTAIRES (suggestions de l'assistant)

Ces elements ne figurent pas explicitement dans le document initial mais s'inscrivent dans la vision :

- **Mode hors-ligne partiel** : permettre la saisie sans connexion et synchroniser au retour en ligne (important pour l'Afrique)
- **Tableau de bord CFO** : vue executive avec KPIs financiers pour les dirigeants, sans accès aux détails comptables
- **Notifications push** : alertes en temps réel (navigateur ou mobile) pour les approbations en attente
- **Audit trail complet** : journal de toutes les modifications avec qui/quand/quoi - essentiel pour les audits
- **Sauvegarde automatique** : backup chiffré planifié de la BDD, téléchargeable
- **Impressions personnalisables** : en-tête avec logo société sur tous les documents imprimés
- **Module fiscal** : calcul TVA, IS, IRPP avec déclarations pré-remplies selon pays OHADA
- **API REST** : pour permettre l'intégration avec d'autres systèmes (banques, logiciels tiers)
- **Gestion des devises crypto** : USDT, etc. (anticipation des usages émergents en Afrique)

---

*Document créé le 2026-08-15 - A mettre à jour au fil de l'avancement du projet*
*Version de référence conservée : tag Git v1.0.0 sur GitHub (Bdm2413/comptabilite_ohada)*
