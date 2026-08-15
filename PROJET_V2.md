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
- Intégration **FNE** (Fichier des Numéros d'Entreprise - Côte d'Ivoire) : identification officielle des entreprises rattachée à chaque dossier

### 2.9 Modules Fiscaux - DGI (Côte d'Ivoire)
Formulaires de déclaration conformes aux modèles de la Direction Générale des Impôts ivoirienne.

| Déclaration | Périodicité | Délai légal |
|---|---|---|
| TVA (Taxe sur la Valeur Ajoutée) | Mensuelle | 15 du mois suivant |
| ITS (Impôts sur Traitements et Salaires) | Mensuelle | 15 du mois suivant |
| TSE (Taxe sur les Salaires des Expatriés) | Mensuelle | 15 du mois suivant |
| PPSSI (Prélèvement sur les Paiements faits aux Prestataires de Services Installés hors de Côte d'Ivoire) | Mensuelle | 15 du mois suivant |
| RIBNC (Retenue à la source sur les Bénéfices Non Commerciaux) | Mensuelle | 15 du mois suivant |
| Acomptes IS/BIC/BNC | Trimestrielle | Selon calendrier DGI |

Fonctionnalités :
- Formulaires à l'identique des imprimés DGI
- Pré-remplissage depuis les données comptables (TVA collectée, TVA déductible, masse salariale...)
- Taux, seuils et barèmes paramétrables en BDD (modification sans toucher au code)
- Génération de l'écriture comptable de la déclaration (débit impôt à payer / crédit trésorerie)
- Marquage : A déclarer / Déclaré / Payé
- Calendrier fiscal intelligent selon le régime et le secteur d'activité

### 2.10 Modules Sociaux - CNPS (Côte d'Ivoire)
Formulaires de déclaration conformes aux modèles de la Caisse Nationale de Prévoyance Sociale.

| Déclaration | Périodicité paiement | Conditions |
|---|---|---|
| Cotisations retraite (branche vieillesse) | Mensuelle ou Trimestrielle | Mensuel si > 20 salariés, Trimestriel si <= 20 salariés |
| CMU (Couverture Maladie Universelle) | Mensuelle ou Trimestrielle | Même règle que retraite |
| Déclaration nominative des salariés | Annuelle | Avant le 31 janvier de l'année N+1 |
| Accident du travail (AT/MP) | Au fil des événements | Déclaration dans les 48h |

Fonctionnalités :
- Formulaires conformes aux imprimés CNPS
- Calcul automatique des bases et taux depuis les données de paie
- Gestion différenciée selon le nombre de salariés (périodicité de paiement)
- Génération de l'écriture de charges sociales patronales
- Marquage : A déclarer / Déclaré / Payé

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

### 4.5 Approche Document-First (innovation clé)

L'approche classique des logiciels comptables africains impose de saisir l'écriture directement dans un journal. L'approche retenue ici s'inspire de Pennylane, Odoo et QuickBooks : **on part du document métier, la comptabilisation en découle automatiquement**.

```
Document métier          Comptabilisation automatique
─────────────────        ──────────────────────────────
Facture client    ──►    Débit 411xxx / Crédit 70xxxx
Facture fourn.    ──►    Débit 60xxxx / Crédit 401xxx
Déclaration TVA   ──►    Débit 443xxx / Crédit 445xxx
Déclaration ITS   ──►    Débit 661xxx / Crédit 447xxx
Déclaration CNPS  ──►    Débit 664xxx / Crédit 430xxx
Bulletin de paie  ──►    Débit 66xxxx / Crédit 421xxx
```

**Avantage :** l'utilisateur ne saisit plus d'écritures manuelles pour ces opérations récurrentes. Il gère ses documents métier, le système génère les écritures. La saisie manuelle reste disponible pour les opérations non couvertes par un module.

### 4.6 Catalogue (référentiel opérationnel)

Le catalogue est le pont entre les opérations métier et la comptabilité.

**Existant (table `catalogues_fournisseurs`) :**
- Articles liés à un fournisseur spécifique (reference, designation, description, type_article, unite, prix_unitaire_ht, actif)
- Utilisé dans les devis fournisseurs et bons de commande via `api_catalogue.php`
- Taxes gérées au niveau de la ligne de devis (TVA 18%, PPSSI 2%, BNC 7.5%)

**Evolutions prévues (Phase 3) :**
- Ajouter `compte_produit` et `compte_charge` (comptes OHADA pour comptabilisation auto)
- Ajouter `type_taxe_defaut` (TVA / PPSSI / BNC / Aucune - pré-sélectionné à la ligne)
- Ajouter `usage` ENUM('vente','achat','les_deux') - ouvrir le catalogue aux articles de vente
- Rendre `id_fournisseur` nullable pour un catalogue général non lié à un fournisseur unique

**Modèles d'écritures (nouveau - Phase 3) :**
- Gabarits débit/crédit complets pour les opérations non documentaires
- Exemples : dotation aux amortissements, écriture de paie, provision, régularisation
- L'utilisateur remplit uniquement les montants, les comptes sont pré-câblés
- Utilisé dans : saisie des écritures (onglet dédié "Depuis un modèle")

**Taxes gérées dans le système (à paramétrer en BDD en Phase 4) :**

| Taxe | Taux | Nature | Sens comptable |
|---|---|---|---|
| TVA | 18% | Taxe collectée / déductible | Ajoutée au HT |
| PPSSI | 2% | Retenue à la source sur services hors CI | Déduite du net à payer |
| BNC/RIBNC | 7.5% | Retenue sur Bénéfices Non Commerciaux | Déduite du net à payer |
| ITS | Barème progressif | Retenue sur salaires | Via module paie |

### 4.7 Tableau de bord des échéances fiscales et sociales

Widget dédié accessible depuis le Dashboard principal, conçu pour qu'aucune déclaration ne soit oubliée.

**Structure du tableau de bord échéances :**

| Colonne | Contenu |
|---|---|
| Déclaration | ITS, TVA, TSE, CNPS Retraite, CMU, etc. |
| Période concernée | Janvier 2026, T1 2026, etc. |
| Date limite légale | Date exacte selon régime et secteur |
| Montant estimé | Pré-calculé depuis les données comptables |
| Statut | A préparer / Déclaré / Payé / En retard |
| Action | Bouton "Préparer" qui ouvre le formulaire de déclaration |

**Paramétrage fin du calendrier :**

Le calendrier fiscal est entièrement paramétrable en BDD (table `calendrier_fiscal`) :
- **Régime fiscal** : Réel normal, Réel simplifié, Impôt synthétique
- **Secteur d'activité** : Certains secteurs ont des dates dérogatoires (ex: agriculture, BTP)
- **Taille de l'entreprise** : Périodicité CNPS mensuelle (> 20 salariés) ou trimestrielle (<= 20 salariés)
- **Taux et seuils** : Tous les paramètres numériques (taux TVA, barèmes ITS, taux CNPS) stockés en BDD - modifiables par l'admin sans toucher au code

**Alertes proactives :**
- J-7 : notification "7 jours avant l'échéance TVA de janvier"
- J-2 : alerte rouge si non encore déclaré
- J+1 : alerte critique "Déclaration en retard - risques de pénalités"
- Récapitulatif hebdomadaire envoyable par email

### 4.8 GED intégrée et FNE (Côte d'Ivoire)

Chaque pièce comptable (facture, déclaration, reçu de paiement, relevé bancaire) peut avoir un ou plusieurs documents joints.

**Fonctionnement :**
- Upload depuis le formulaire de saisie ou depuis le module GED central
- Formats acceptés : PDF, JPG, PNG, Excel, Word
- Stockage local sécurisé (ou cloud en option SaaS)
- Liaison directe avec l'écriture comptable, la facture ou la déclaration

**Intégration FNE (Fichier des Numéros d'Entreprise) :**
- Le numéro FNE est un identifiant unique des entreprises en Côte d'Ivoire
- Chaque dossier société contient son numéro FNE
- Documents officiels DGI/CNPS pré-remplis avec le FNE de la société

### 4.9 Parametre global : taille de police
- Réglable dans les paramètres utilisateur ou société
- 3 niveaux : Petit / Normal / Grand
- S'applique à toute l'interface via une variable CSS globale

### 4.10 Reorganisation du menu (nouvelle structure)
Structure proposée :
```
TABLEAU DE BORD
  - Dashboard principal (KPIs + Echéances fiscales)

COMPTABILITE (GL)
  - Saisie des ecritures
  - Grand livre
  - Journal general
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

FISCAL (DGI)
  - Déclarations TVA
  - Déclarations ITS
  - Autres déclarations (TSE, PPSSI, RIBNC)
  - Acomptes IS / BIC / BNC
  - Calendrier fiscal

SOCIAL (CNPS)
  - Déclarations cotisations (Retraite + CMU)
  - Déclaration nominative annuelle
  - Paramètres CNPS (taux, seuils)

PAIE ET RH
  - Employes
  - Bulletins de paie
  - Livre de paie
  - Parametres de paie

TRESORERIE (CM)
  - Rapprochement bancaire
  - Rapport de caisse
  - Position de tresorerie

IMMOBILISATIONS (FA)
  - Liste des immobilisations
  - Amortissements
  - Synthese

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
  - Gestion des periodes (exercices + periodes mensuelles)
  - Tiers (clients/fournisseurs)
  - Catalogue (Articles, Charges récurrentes, Modèles d'écritures)
  - Parametres fiscaux (taux DGI, barèmes, calendrier)
  - Parametres sociaux (taux CNPS, seuils)
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

> **Principe :** les périodes comptables sont le verrou central. Rien d'autre ne peut être correctement construit sans elles.

- [x] **Périodes comptables - Etape 1 :** schéma BDD (`periodes`), génération automatique des 12 périodes à la création d'un exercice, fonction verrou `requirePeriodeOuverte()` - intégrée dans saisie, extourne, import
- [x] **Périodes comptables - Etape 2 :** interface admin (Paramètres > Gestion des périodes) - grille des 12 mois, boutons Ouvrir/Fermer/Définitif, audit trail, renommage menu sidebar
- [x] **Saisie des écritures :** verrou de période intégré - message explicite si période fermée
- [x] **Grand livre, Journal, Balance :** filtres par période mensuelle (dropdown dynamique, auto-remplissage dates, soumission auto)
- [ ] **Lettrage amélioré :** par compte tiers, avec numéro de facture, lettrage automatique
- [ ] **Clôture mensuelle :** contrôles automatiques (balance équilibrée, écritures non lettrées), rapport de clôture
- [ ] **Clôture annuelle :** génération de l'écriture RAN (Report à Nouveau), verrouillage définitif de l'exercice
- [ ] **Reprise de balance d'ouverture :** écriture RAN guidée pour sociétés avec historique
- [ ] **Flux "Nouvelle société / filiale" :** dans Paramètres, avec exercices archivés
- [ ] Plan de comptes enrichi (longueur variable, sous-comptes analytiques)

### PHASE 3 - Document-First : Catalogue + Factures (priorité haute)

> **Principe :** le Catalogue est le prérequis. Sans lui, pas d'automatisation de la comptabilisation sur les factures.
> **Approche :** évolution de l'existant, pas de remplacement. La table `catalogues_fournisseurs` et les modules devis/BC sont conservés et enrichis.

**Etat de l'existant (inventaire avant codage) :**
- Table `catalogues_fournisseurs` : catalogue lié aux fournisseurs, sans compte comptable ni taxe par défaut
- Table `lignes_devis` : champ `type_taxe` ENUM('Aucune','TVA','PPSSI','BNC') - 3 taxes déjà gérées dans les devis
- Taxes déjà codées dans `devis_form.php` :
  - TVA 18% : ajoutée au montant HT
  - PPSSI 2% : Prélèvement sur Paiements à Prestataires hors CI (retenue, déduite du net à payer)
  - BNC/RIBNC 7.5% : Retenue sur Bénéfices Non Commerciaux (retenue, déduite du net à payer)
- Flux devis existant : Devis fournisseur → Approuvé → Bon de commande (`bc_form.php`)
- API catalogue : `api_catalogue.php?action=liste&fournisseur_id=X`

**Ce qui manque et doit être ajouté :**
- Comptes comptables dans le catalogue (`compte_produit`, `compte_charge`)
- Taxe par défaut par article (`type_taxe_defaut`)
- Usage vente/achat/les_deux (catalogue actuellement achat uniquement, lié à un fournisseur)
- Module Factures clients (AR) - inexistant
- Module Factures fournisseurs (AP) - inexistant (seuls les devis/BC existent)
- Connexion Bon de commande → Facture fournisseur
- Module Règlements (encaissements et paiements avec écriture comptable auto)

**Tâches :**
- [ ] **Catalogue - évolution :** ajouter `compte_produit`, `compte_charge`, `type_taxe_defaut`, `usage`(vente/achat/les_deux) à `catalogues_fournisseurs`; permettre un catalogue général (id_fournisseur nullable)
- [ ] **Catalogue - Modèles d'écritures :** nouvelle table `catalogue_modeles` + `catalogue_modeles_lignes` - gabarits débit/crédit complets pour amortissements, paie, provisions
- [x] **Devis clients (AR) :** `pages/ventes/devis_clients.php` + `devis_client_form.php` - numérotation DVC-AAAA-NNNN, workflow Brouillon→Envoye→Accepte→Refuse/Converti, TVA 18%, catalogue vente, conversion vers BC client
- [x] **BC clients (AR) :** `pages/ventes/bons_commande_clients.php` + `bc_client_form.php` - numérotation BCC-AAAA-NNNN, conversion depuis devis (pre-remplissage complet), workflow En cours→Livre partiellement→Livre→Facture, conversion vers facture client
- [x] **Factures clients (AR) :** `pages/ventes/factures_clients.php` + `facture_client_form.php` - numérotation FAC-AAAA-NNNN, TVA 18%, statuts (brouillon/envoyee/partiellement_payee/payee/annulee), comptabilisation automatique journal VTE (Debit 411/Credit 70x+443), conversion depuis BC client (`?from_bc=ID`)
- [x] **Menu AR/AP restructuré :** ordre symétrique Devis → BC → Factures → Balance âgée dans sidebar pour AR (Clients) et AP (Fournisseurs)
- [ ] **Factures fournisseurs (AP) :** `pages/achats/factures_fournisseurs.php` + `facture_fournisseur_form.php` - numéro libre (fournisseur), lignes depuis catalogue, taxes TVA/PPSSI/BNC, statuts, comptabilisation automatique journal ACH (Debit 60x+445+retenues/Credit 401), conversion depuis BC fournisseur approuvé
- [ ] **Encaissements clients :** modal règlement depuis la facture, écriture Debit 521/Credit 411 auto, lettrage auto
- [ ] **Paiements fournisseurs :** modal règlement depuis la facture, écriture Debit 401/Credit 521 auto, lettrage auto
- [ ] **Aging (ancienneté des factures) :** champ `date_echeance` sur chaque facture, calcul du retard en jours, buckets 0-30 / 31-60 / 61-90 / +90 jours - base de la balance âgée clients et fournisseurs
- [ ] **Balance âgée clients :** tableau par client, colonnes par tranche d'ancienneté, total échu/non échu
- [ ] **Balance âgée fournisseurs :** même structure côté fournisseurs

**Structure aging dans les tables de factures :**
- `date_facture` : date d'émission
- `date_echeance` : date limite de paiement (calculée : date_facture + délai tiers, ou saisie manuelle)
- `montant_ttc` : montant total dû
- `montant_regle` : cumul des règlements enregistrés
- `solde_restant` : montant_ttc - montant_regle (calculé)
- Buckets aging calculés à la volée : `DATEDIFF(CURDATE(), date_echeance)` par tranche

### PHASE 4 - Fiscal & Social (différenciateur marché)

> **Principe :** tous les taux, seuils et délais sont paramétrables en BDD. Aucune valeur codée en dur.

- [ ] **Paramètres fiscaux BDD :** table `parametres_fiscaux` (taux TVA, barèmes ITS, seuils, dates limites par régime et secteur)
- [ ] **Paramètres sociaux BDD :** table `parametres_cnps` (taux retraite, CMU, seuils, règle mensuel/trimestriel)
- [ ] **Calendrier fiscal intelligent :** par régime (Réel normal, Réel simplifié, Impôt synthétique), par secteur, alertes J-7/J-2/J+1
- [ ] **Déclaration TVA :** formulaire conforme DGI, pré-rempli depuis les écritures, génération écriture comptable
- [ ] **Déclaration ITS :** formulaire conforme DGI, pré-rempli depuis données de paie, génération écriture
- [ ] **Autres déclarations DGI :** TSE, PPSSI, RIBNC
- [ ] **Acomptes IS/BIC/BNC :** trimestriels avec rappels calendrier
- [ ] **Déclarations CNPS :** cotisations retraite + CMU, gestion mensuel/trimestriel selon effectif, formulaires conformes
- [ ] **Déclaration nominative annuelle CNPS :** liste nominative des salariés avec salaires
- [ ] **Tableau de bord des échéances fiscales et sociales :** widget dashboard, vue consolidée toutes déclarations, statuts, alertes

### PHASE 5 - Etats financiers (amélioration)
- [ ] Bilan OHADA (amélioré, conforme SYSCOHADA Révisé)
- [ ] Compte de résultat (amélioré)
- [ ] TFT - Tableau des Flux de Trésorerie (amélioré)
- [ ] Notes annexes (complétées)
- [ ] Export PDF et Excel soignés (en-tête société, logo)

### PHASE 6 - Trésorerie & Immobilisations
- [ ] CM - Rapprochement bancaire automatisé (import relevé CSV/OFX)
- [ ] CM - Position de trésorerie en temps réel
- [ ] FA - Immobilisations (amélioré) : calcul amortissements, plan, cessions
- [ ] FA - Intégration automatique des dotations en GL

### PHASE 7 - GED et Archives
- [ ] Upload et stockage sécurisé (PDF, images, Excel...)
- [ ] Liaison avec les pièces : factures, déclarations, écritures, reçus
- [ ] Intégration FNE (numéro entreprise Côte d'Ivoire) dans les documents officiels
- [ ] Classement et recherche full-text
- [ ] Partage interne/externe (lien sécurisé à durée limitée)
- [ ] Versioning des documents

### PHASE 8 - RH, Paie, Expenses
- [ ] Module Paie simplifié : charges salariales + ITS + CNPS sans gestion complète des bulletins
- [ ] Module Paie complet : bulletins, congés, ancienneté, conventions collectives
- [ ] Notes de frais (Expenses) : workflow validation, remboursement, pièce jointe

### PHASE 9 - Intelligence et finition
- [ ] Alertes automatiques (solde anormal, facture impayée, échéances)
- [ ] Suggestions IA (imputation automatique, détection doublons)
- [ ] Assistant normatif interactif (OHADA, IFRS, fiscal CI)
- [ ] Glossaire de requêtes documenté (Oracle-style)
- [ ] Consolidation multi-sociétés
- [ ] API REST (intégration banques, logiciels tiers)
- [ ] Mode hors-ligne partiel (important contexte africain)

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
