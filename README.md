# ComptaSYSCOHADA - Application de Comptabilité

Application web de comptabilité conforme au référentiel **SYSCOHADA Révisé**.

## Caractéristiques principales

### Fonctionnalités comptables
- **Saisie par pièces** : Enregistrement des écritures comptables par pièce
- **Livre journal** : Consultation chronologique de toutes les écritures
- **Grand livre général** : Consultation par compte
- **Grand livre auxiliaire** : Suivi détaillé des tiers (clients/fournisseurs)
- **Balance générale** : État des soldes de tous les comptes
- **Balance auxiliaire** : État des soldes des comptes de tiers
- **Bilan** : Génération automatique selon SYSCOHADA (mensuel/trimestriel/annuel)
- **Compte de résultat** : Génération automatique (mensuel/trimestriel/annuel)

### Gestion
- **Plan comptable SYSCOHADA** : Plan comptable préchargé selon le référentiel
- **Gestion des tiers** : Clients, fournisseurs et autres tiers
- **Exercices comptables** : Gestion des périodes comptables
- **Utilisateurs** : Système multi-utilisateurs avec rôles

## Technologies utilisées

- **Backend** : PHP 7.4+
- **Base de données** : MySQL 5.7+
- **Frontend** :
  - Tailwind CSS (pour le design)
  - Anime.js (pour les animations)
  - JavaScript vanilla
- **Serveur local** : WAMP Server

## Installation

### 1. Prérequis
- WAMP Server installé et démarré
- PHP 7.4 ou supérieur
- MySQL 5.7 ou supérieur

### 2. Installation de la base de données

1. Ouvrir phpMyAdmin (`http://localhost/phpmyadmin`)
2. Créer une nouvelle base de données nommée `comptabilite_syscohada`
3. Importer le fichier `database/schema.sql`

**OU** en ligne de commande :
```bash
mysql -u root -p < database/schema.sql
```

### 3. Configuration

1. Vérifier les paramètres de connexion dans `config/database.php` :
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'comptabilite_syscohada');
define('DB_USER', 'root');
define('DB_PASS', '');
```

2. Vérifier l'URL de l'application dans `config/config.php` :
```php
define('APP_URL', 'http://localhost/comptabilite_ohada');
```

### 4. Accès à l'application

Accéder à l'application via : `http://localhost/comptabilite_ohada`

**Identifiants par défaut** :
- Email : `admin@comptabilite.local`
- Mot de passe : `admin123`

⚠️ **Important** : Changez ces identifiants après la première connexion !

## Structure du projet

```
comptabilite_ohada/
├── assets/
│   ├── js/           # Scripts JavaScript
│   └── images/       # Images et icônes
├── config/
│   ├── config.php    # Configuration générale
│   └── database.php  # Configuration base de données
├── database/
│   └── schema.sql    # Structure de la base de données
├── includes/
│   ├── classes/      # Classes PHP
│   └── functions/    # Fonctions utilitaires
│       ├── security.php  # Fonctions de sécurité
│       └── utils.php     # Fonctions utilitaires
├── pages/
│   ├── auth/         # Authentification (login/logout)
│   ├── dashboard/    # Tableau de bord
│   ├── journal/      # Livre journal et saisie
│   ├── reports/      # États financiers et rapports
│   ├── tiers/        # Gestion des tiers
│   └── settings/     # Paramètres
├── public/
│   └── uploads/      # Fichiers uploadés
├── index.php         # Point d'entrée
└── README.md         # Ce fichier
```

## Fonctionnalités à venir

### Phase 1 (En cours)
- [x] Authentification
- [x] Dashboard
- [ ] Saisie par pièces
- [ ] Livre journal

### Phase 2
- [ ] Grand livre général
- [ ] Grand livre auxiliaire
- [ ] Balance générale
- [ ] Balance auxiliaire

### Phase 3
- [ ] Bilan (mensuel/trimestriel/annuel)
- [ ] Compte de résultat (mensuel/trimestriel/annuel)
- [ ] Export PDF/Excel

### Phase 4
- [ ] Gestion avancée des tiers
- [ ] Lettrage des comptes
- [ ] Rapprochement bancaire
- [ ] Analytique

## Sécurité

- Mots de passe hashés avec `password_hash()`
- Protection contre les injections SQL (PDO avec requêtes préparées)
- Protection XSS (échappement des données)
- Sessions sécurisées
- Logs d'activité

## Support

Pour toute question ou problème :
- Consulter la documentation SYSCOHADA Révisé
- Vérifier les logs d'erreur PHP

## Licence

Projet développé pour usage interne.

## Référentiel

Application conforme au **Système Comptable OHADA Révisé** (SYSCOHADA Révisé).
