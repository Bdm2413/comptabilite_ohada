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
Quand l'application est installée sur un nouveau poste avec une BDD vide, l'assistant guide l'utilisateur en 6 étapes.

**Choix du mode en étape 1 (Bienvenue) :**
Avant de commencer, l'utilisateur sélectionne l'un des deux modes qui détermine l'expérience complète :

| Mode | Description | Usage typique |
|---|---|---|
| **Entreprise** | Gestion de la propre comptabilité de l'entité (ou d'un groupe de filiales) | PME, holding, groupe |
| **Cabinet** | Gestion de la comptabilité de plusieurs clients distincts depuis un poste unique | Cabinet d'expertise comptable, fiduciaire |

Ce choix est enregistré dans `parametres_systeme.mode_installation` et conditionne l'interface, le dashboard et les fonctionnalités disponibles après le wizard.

**Etapes du wizard :**
- **Etape 1** - Bienvenue + sélection du mode (Entreprise ou Cabinet)
- **Etape 2** - Informations de la société / du cabinet (nom, RCCM, NCC, secteur, capital, devise, pays...)
- **Etape 3** - Premier exercice comptable (dates, référentiel OHADA ou vide)
- **Etape 4** - Création du compte administrateur
- **Etape 5** - Préférences d'affichage (taille de police, fuseau horaire)
- **Etape 6** - Récapitulatif et finalisation

### 4.2 Periodes comptables mensuelles

**Concept clé (inspiré d'Oracle GL) :** aucune écriture ne peut être saisie sur une période fermée. C'est le verrou central du GL. Toutes les fonctionnalités comptables (saisie, lettrage, clôture) dépendent de ce mécanisme.

**Structure BDD :**

```sql
periodes_comptables
├── id
├── exercice_id          FK vers exercices
├── societe_id
├── numero_periode       1 à 12 (+ 13/14 pour périodes d'ajustement)
├── libelle              "Janvier 2025", "Période de clôture"
├── date_debut
├── date_fin
├── type                 ENUM('normal','ajustement')
├── statut               ENUM('Jamais_ouvert','Ouvert','Fermé','Définitivement_fermé')
├── ouvert_par           FK utilisateurs
├── date_ouverture
├── ferme_par            FK utilisateurs
├── date_cloture
└── created_at / updated_at
```

**Statuts (identiques à Oracle) :**

| Statut | Description | Saisie possible |
|---|---|---|
| Jamais_ouvert | Période créée mais pas encore ouverte | Non |
| Ouvert | Période active - saisie autorisée | Oui |
| Fermé | Clôture mensuelle effectuée - rouvrable par admin | Non |
| Définitivement_fermé | Clôture annuelle - irréversible | Non |

**Périodes d'ajustement (13 et 14) :**
- Réservées aux écritures de régularisation de fin d'exercice (provisions, amortissements, régularisations)
- Même statut que les périodes normales mais distinguées par `type = 'ajustement'`
- N'apparaissent que si l'exercice est en phase de clôture

**Verrou PHP :**
```php
// Appel obligatoire dans tout point d'écriture
requirePeriodeOuverte($date_ecriture, $societe_id);
// Lance une exception si la période est Fermée ou Définitivement_fermée
```

**Interface admin (Paramètres > Exercices et périodes) :**
- Grille des 12 mois de l'exercice avec statut visuel par couleur
- Bouton "Ouvrir" / "Fermer" par période (admin uniquement)
- Chaque action est auditée (qui, quand)
- Les périodes d'ajustement sont créées manuellement à la clôture annuelle

**Règles métier :**
- Seul l'admin peut ouvrir ou fermer une période
- Une période `Définitivement_fermé` ne peut jamais être rouverte
- À la création d'un exercice, les 12 périodes sont générées avec statut `Jamais_ouvert`
- La période courante (selon date du jour) est automatiquement ouverte lors de la création de l'exercice

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

## 5. GESTION MULTI-SOCIETE ET MODE CABINET

### 5.1 Fondations communes (Entreprise et Cabinet)
- Une installation gère N sociétés/dossiers avec isolation totale des données (`societe_id` sur toutes les tables)
- Chaque entité a son plan comptable, ses exercices, ses journaux et ses paramètres propres
- Un utilisateur peut être rattaché à plusieurs entités avec des rôles différents par entité
- Droits fins par entité : `peut_modifier_plan`, `peut_cloturer_exercice`, `peut_valider_ecritures`, `peut_exporter`
- Changement d'entité active en un clic depuis la topbar

### 5.2 Mode Entreprise
- Usage : PME, groupe, holding avec filiales
- La première société créée est l'entité principale
- L'administrateur a accès à toutes les sociétés du groupe
- Consolidation multi-sociétés possible en phase avancée (étapes financiers agrégés)

### 5.3 Mode Cabinet (nouveau)
Ce mode est conçu pour un cabinet d'expertise comptable gérant la comptabilité de plusieurs clients.

**Concept clé : Cabinet = entité racine, Dossiers = sociétés clientes**
- Le cabinet est créé comme entité de type `cabinet` avec `id_cabinet = NULL` (il est sa propre racine)
- Chaque client est un dossier (type `dossier_client`) lié au cabinet via `id_cabinet`
- Les collaborateurs du cabinet sont définis une fois et assignés aux dossiers clients

**Fonctionnalités spécifiques au mode Cabinet :**

| Fonctionnalité | Description |
|---|---|
| **Vue tableau de bord Cabinet** | Liste de tous les dossiers clients avec statut, avancement, alertes et prochaines échéances |
| **Statut de dossier** | Chaque dossier a un état : En cours, A jour, En retard, Clôturé - visible depuis le dashboard cabinet |
| **Ajout de dossier client** | Flux dédié pour créer un nouveau client (nom, NCC, RCCM, devise, exercice) sans repasser par le wizard |
| **Gestion des collaborateurs** | Les utilisateurs du cabinet existent au niveau cabinet et sont assignés aux dossiers clients avec rôle spécifique |
| **Accès client (lecture seule)** | Un client peut disposer d'un accès limité pour consulter ses propres états financiers |
| **Traçabilité par dossier** | Qui a fait quoi sur quel dossier, avec horodatage - utile pour la facturation des honoraires |
| **Alertes globales cabinet** | Périodes non clôturées, déclarations TVA/IS approchant, rapprochements en attente - vue consolidée |

**Structure BDD pour le mode Cabinet :**
- `parametres_systeme.mode_installation = 'cabinet'`
- La société créée dans le wizard a `type_entite = 'cabinet'`
- Les dossiers clients futurs auront `type_entite = 'dossier_client'` et `id_cabinet = id_du_cabinet`
- La table `cabinets` existante sert de référence complémentaire (raison sociale, agréments, etc.)

### 5.4 Changement de mode après installation (à prévoir dans Paramètres)
Un utilisateur peut démarrer en Mode Entreprise puis souhaiter passer en Mode Cabinet (ou inversement). Ce changement n'est pas possible via le wizard une fois l'installation terminée - il doit être accessible depuis **Paramètres > Général**.

**Comportement attendu lors du changement :**
- Mise à jour de `parametres_systeme.mode_installation`
- Mise à jour du `type_entite` de la société principale (`entreprise_individuelle` -> `cabinet` ou inverse)
- Confirmation explicite demandée à l'utilisateur : "Cette action modifie le mode de votre installation. Vos données existantes restent intactes."
- Rechargement de l'interface (dashboard, sidebar) pour refléter le nouveau mode

**Contrainte :** implémenter lors de la refonte du module Paramètres (Phase 2 ou séparément), pas en page isolée.

### 5.5 Intégration d'une société rachetée ou filiale historique
Cas concret : une société mère créée en 2023 rachète une entité dont la comptabilité remonte à 2020. Le wizard ne couvre que la société principale. Les entités additionnelles sont gérées via un flux dédié.

**Trois mécanismes à implémenter :**

| Mécanisme | Description | Localisation |
|---|---|---|
| **Flux "Nouvelle société"** | Créer une filiale/société rachetée avec sa date de début d'activité réelle, son propre plan comptable et ses exercices historiques en statut "archivé" | Paramètres > Sociétés |
| **Reprise de balance d'ouverture** | Saisie guidée des soldes de chaque compte à une date donnée - génère une écriture de type `RAN` (Report À Nouveau) qui constitue le point de départ dans le système | Module GL - création d'exercice |
| **Exercices archivés** | Exercices passés en statut `archivé` : visibles pour consultation et états financiers historiques, verrouillés en saisie | `exercices_comptables.statut = 'archive'` |

**Principe clé :** on ne ressaisit pas des années d'écritures. La reprise de balance capture l'état de chaque compte à une date charnière (ex: 31/12/2022) via une écriture unique. La comptabilité courante repart de là.

**À implémenter lors de la refonte du module Paramètres (Phase 2 ou dédiée).**

### 5.6 Consolidation (phase avancée)
- Etats financiers multi-sociétés (somme des bilans filiales par exemple)
- Rapport de gestion de portefeuille pour le cabinet
- Export Excel multi-dossiers

---

## 6. REFERENTIEL PLAN COMPTABLE SYSCOHADA

### 6.1 Architecture des tables de référence

Deux tables coexistent et ont des rôles distincts :

| Table | Comptes | Rôle |
|---|---|---|
| `ohada_plan_comptable` | 696 lignes (4 niveaux hiérarchiques) | Référentiel structuré : classes, comptes principaux, divisionnaires, sous-comptes |
| `table_correspondance` | 1106 comptes 4 chiffres | Source canonique pour BD/BC/RD/RC - lookup lors de la création d'un compte |

**Règle de hiérarchie SYSCOHADA Révisé :**
- 1 chiffre = classe (ex: `1`)
- 2 chiffres = compte principal (ex: `10`)
- 3 chiffres = compte divisionnaire (ex: `101 Capital social`)
- 4 chiffres = **sous-compte saisissable** (ex: `1011 Capital souscrit, non appelé`) - seul niveau importé dans `plan_comptable`

### 6.2 Import initial (wizard)

Lors de la finalisation du wizard :
- Seuls les comptes de `ohada_plan_comptable` à `niveau = 4` (4 chiffres) sont importés dans `plan_comptable`
- Le champ `type_compte` est lu directement depuis `ohada_plan_comptable` (pré-renseigné par classe et racine de compte)
- Si `longueur_compte > 4`, le numéro est padé à droite avec des zéros : `1011` -> `10110000` pour une longueur de 8
- `quatre_chiffres` conserve toujours la racine 4 chiffres pour les correspondances

**À faire :** aligner le wizard pour utiliser `table_correspondance` (1106 comptes, plus complet) au lieu de `ohada_plan_comptable` (370 comptes niveau 4)

### 6.3 Création d'un compte en cours d'utilisation

Quand l'utilisateur crée un nouveau compte dans `plan_comptable` (hors wizard) :
1. Il saisit le numéro de compte (minimum 4 chiffres)
2. Le système extrait les 4 premiers chiffres (`quatre_chiffres`)
3. Il recherche ce code dans `table_correspondance`
4. BD, BC, RD, RC et tableau sont renseignés automatiquement depuis le référentiel
5. Si le code n'existe pas dans `table_correspondance`, la création est bloquée avec message d'erreur

### 6.4 Administration du référentiel (réalisé)

L'interface d'administration est disponible dans **Paramètres > Référentiel SYSCOHADA** (`referentiel_ohada.php`, Tab 2 "Gestion BD/BC/RD/RC").

**Fonctionnalités implémentées :**
- Accès restreint à l'admin (`$_SESSION['user_role'] === 'admin'`)
- Modifier libellé, type_compte, tableau, BD/BC/RD/RC avec propagation optionnelle vers `plan_comptable`
- Ajouter un nouveau compte dans le référentiel (modal avec validation 4 chiffres)
- Supprimer un compte du référentiel (confirmation JS, sans cascade vers plan_comptable)
- Filtres par classe, type, recherche texte - pagination 25 par page

---

## 7. INTELLIGENCE ARTIFICIELLE INTEGREE

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

### PHASE 1 - Fondations
- [x] Onboarding wizard 6 étapes (réalisé)
- [x] Nouvelle structure de navigation / menu - sidebar accordéon (réalisé)
- [x] Sélection du mode installation (Entreprise / Cabinet) dans le wizard (réalisé)
- [x] Import plan comptable SYSCOHADA via `table_correspondance` (1106 comptes, 4 chiffres, avec type_compte et padding automatique) (réalisé)
- [x] Référentiel SYSCOHADA : vue hiérarchique + interface admin add/edit/delete BD/BC/RD/RC avec propagation vers plan_comptable (réalisé)
- [x] Fusion `correspondance.php` dans `referentiel_ohada.php` - suppression de la page redondante (réalisé)
- [ ] Vue dashboard Cabinet (liste dossiers, statuts, alertes)
- [ ] Fusion Dashboard + Vue d'ensemble
- [ ] Parametre taille de police (wizard OK, reste à appliquer globalement)

### PHASE 2 - Noyau GL (ordre d'exécution obligatoire)

> **Principe :** les périodes comptables sont le verrou central. Rien d'autre ne peut être correctement construit sans elles. L'ordre ci-dessous est donc impératif.

- [ ] **[EN COURS] Périodes comptables - Etape 1 :** schéma BDD (`periodes_comptables`), génération automatique des 12 périodes à la création d'un exercice, fonction verrou `requirePeriodeOuverte()`
- [ ] **Périodes comptables - Etape 2 :** interface admin (Paramètres > Exercices et périodes) - grille des 12 mois, boutons Ouvrir/Fermer, audit trail
- [ ] **Saisie des écritures :** intégration du verrou de période, amélioration UX (validation en temps réel, raccourcis clavier, auto-complétion des comptes)
- [ ] **Grand livre, Journal, Balance :** avec filtres par période (mois), export PDF/Excel
- [ ] **Lettrage amélioré :** par compte tiers, avec numéro de facture, lettrage automatique
- [ ] **Clôture mensuelle :** contrôles automatiques (balance équilibrée, écritures non lettrées), génération du rapport de clôture
- [ ] **Clôture annuelle :** génération de l'écriture de report à nouveau (RAN), verrouillage définitif de l'exercice
- [ ] **Reprise de balance d'ouverture :** écriture RAN guidée pour sociétés avec historique
- [ ] **Flux "Nouvelle société / filiale" :** dans Paramètres, avec exercices archivés
- [ ] Plan de comptes enrichi (longueur variable, sous-comptes analytiques)

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
