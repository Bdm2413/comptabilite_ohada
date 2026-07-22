# 🚀 API REST - ComptaSYSCOHADA

## ✅ Implémentation Complète

L'API REST pour ComptaSYSCOHADA est maintenant **entièrement opérationnelle** !

---

## 📁 Structure de l'API

```
api/
├── config.php              # Configuration générale (CORS, JWT, rate limiting)
├── .htaccess              # URL rewriting pour des URLs propres
├── test.html              # Page de test interactive
│
├── middleware/
│   ├── JWT.php            # Gestion des tokens JWT
│   ├── Auth.php           # Middleware d'authentification
│   └── RateLimit.php      # Limitation du nombre de requêtes
│
└── v1/
    ├── auth.php           # Authentification (login, refresh, logout)
    ├── ecritures.php      # CRUD écritures comptables
    ├── balance.php        # Balance générale et auxiliaire
    └── grand-livre.php    # Grand-livre général et par compte
```

---

## 🎯 Fonctionnalités Implémentées

### ✅ Authentification JWT
- Login avec email/password
- Génération de tokens JWT
- Tokens valides 24h
- Refresh de tokens
- Logout (invalidation côté client)

### ✅ Écritures Comptables
- **GET** Liste avec pagination (20 par page par défaut, max 100)
- **GET** Détail d'une écriture avec ses lignes
- **POST** Créer une nouvelle écriture
- **PUT** Modifier une écriture (brouillon uniquement)
- **DELETE** Supprimer une écriture (brouillon uniquement)
- **Filtres** : journal, statut, dates, exercice

### ✅ Balance
- **Balance Générale** : Tous les comptes avec soldes
- **Balance Auxiliaire** : Comptes tiers (clients/fournisseurs)
- **Filtres** : dates, exercice, classe, type de tiers
- **Totaux automatiques** : Débit, crédit, soldes

### ✅ Grand-Livre
- **Résumé général** : Tous les comptes avec nb mouvements
- **Détail par compte** : Tous les mouvements avec solde progressif
- **Filtres** : dates, exercice, classe

### ✅ Sécurité
- **JWT** : Authentification par tokens
- **Rate Limiting** : 100 requêtes/heure par IP
- **CORS** : Configuration pour requêtes cross-origin
- **Validation** : Toutes les données sont validées
- **Logs** : Traçabilité des requêtes API

---

## 🔧 Configuration

### JWT Secret Key
⚠️ **IMPORTANT** : Changez la clé secrète dans `api/config.php` :

```php
define('JWT_SECRET_KEY', 'votre_cle_secrete_super_securisee_2025');
```

### Rate Limiting
Ajustez les limites dans `api/config.php` :

```php
define('RATE_LIMIT_REQUESTS', 100);  // Nombre de requêtes
define('RATE_LIMIT_WINDOW', 3600);   // Par heure
```

---

## 🧪 Tester l'API

### Méthode 1 : Page de Test Interactive
Ouvrez dans votre navigateur :
```
http://localhost/comptabilite_ohada/api/test.html
```

Cette page permet de :
- ✅ Se connecter et obtenir un token
- ✅ Tester tous les endpoints en 1 clic
- ✅ Voir les réponses formatées
- ✅ Créer des écritures de test

### Méthode 2 : cURL (Ligne de commande)

**1. Login :**
```bash
curl -X POST http://localhost/comptabilite_ohada/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@comptabilite.local","password":"admin123"}'
```

**2. Liste des écritures :**
```bash
curl -X GET "http://localhost/comptabilite_ohada/api/v1/ecritures?page=1&limit=5" \
  -H "Authorization: Bearer VOTRE_TOKEN_ICI"
```

**3. Balance générale :**
```bash
curl -X GET "http://localhost/comptabilite_ohada/api/v1/balance/generale" \
  -H "Authorization: Bearer VOTRE_TOKEN_ICI"
```

### Méthode 3 : Postman / Insomnia
1. Importer la collection depuis `API_DOCUMENTATION.md`
2. Configurer l'URL de base : `http://localhost/comptabilite_ohada/api/v1`
3. Ajouter le token dans l'en-tête `Authorization: Bearer <token>`

---

## 📊 Endpoints Disponibles

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| **POST** | `/auth/login` | Obtenir un token JWT | ❌ |
| **POST** | `/auth/refresh` | Rafraîchir un token | ✅ |
| **POST** | `/auth/logout` | Se déconnecter | ✅ |
| **GET** | `/ecritures` | Liste des écritures (paginée) | ✅ |
| **GET** | `/ecritures/{id}` | Détail d'une écriture | ✅ |
| **POST** | `/ecritures` | Créer une écriture | ✅ |
| **PUT** | `/ecritures/{id}` | Modifier une écriture | ✅ |
| **DELETE** | `/ecritures/{id}` | Supprimer une écriture | ✅ |
| **GET** | `/balance/generale` | Balance générale | ✅ |
| **GET** | `/balance/auxiliaire` | Balance auxiliaire | ✅ |
| **GET** | `/grand-livre` | Grand-livre (résumé) | ✅ |
| **GET** | `/grand-livre/{compte}` | Grand-livre d'un compte | ✅ |

---

## 📖 Documentation Complète

Consultez [API_DOCUMENTATION.md](./API_DOCUMENTATION.md) pour :
- ✅ Exemples de requêtes/réponses détaillés
- ✅ Paramètres de filtrage
- ✅ Codes d'erreur
- ✅ Format des données
- ✅ Bonnes pratiques de sécurité

---

## 🎨 Format des Réponses

### ✅ Succès (200/201)
```json
{
  "success": true,
  "status": 200,
  "message": "Optional message",
  "data": { ... },
  "timestamp": "2025-11-30T10:00:00+00:00",
  "api_version": "v1"
}
```

### ❌ Erreur (4xx/5xx)
```json
{
  "success": false,
  "status": 401,
  "error": {
    "message": "Unauthorized - Token manquant ou invalide",
    "code": 401,
    "details": "Invalid signature"
  },
  "timestamp": "2025-11-30T10:00:00+00:00",
  "api_version": "v1"
}
```

---

## 🔒 Sécurité

### En Développement
- ✅ JWT avec clé secrète
- ✅ Rate limiting par IP
- ✅ Validation des données
- ✅ Protection XSS/SQL Injection (PDO)
- ✅ CORS configuré

### Pour la Production
⚠️ **À FAIRE** :
1. ✅ Changer `JWT_SECRET_KEY` (utiliser une clé aléatoire forte)
2. ✅ Activer HTTPS (obligatoire pour JWT)
3. ✅ Limiter CORS aux domaines autorisés
4. ✅ Ajouter une blacklist de tokens révoqués
5. ✅ Configurer des logs séparés
6. ✅ Augmenter le rate limiting si nécessaire

---

## 📈 Performances

- **Pagination** : Max 100 résultats par page
- **Caching** : Headers appropriés pour GET
- **Requêtes optimisées** : Jointures SQL efficaces
- **Rate Limiting** : Empêche la surcharge

---

## 🐛 Logs

Les logs de l'API sont stockés dans :
```
logs/api_YYYY-MM-DD.log
logs/rate_limit.json
```

Format des logs :
```
[2025-11-30 10:00:00] GET /ecritures - User: 1 - Status: 200 - IP: 127.0.0.1
```

---

## 🚀 Prochaines Étapes (Optionnel)

### Extensions Possibles
1. **Webhooks** : Notifications pour événements
2. **API Key** : Alternative au JWT pour intégrations
3. **GraphQL** : Alternative à REST
4. **Swagger UI** : Interface interactive de documentation
5. **Versioning** : v2, v3 avec rétrocompatibilité
6. **Endpoints supplémentaires** :
   - Tiers (clients/fournisseurs)
   - Exercices
   - Journaux
   - Plan comptable
   - Utilisateurs

---

## 📞 Support

Pour toute question sur l'API :
1. Consultez `API_DOCUMENTATION.md`
2. Testez avec `api/test.html`
3. Vérifiez les logs dans `logs/`

---

## ✨ Résumé

✅ **API REST moderne et complète**
✅ **Authentification JWT sécurisée**
✅ **Rate limiting implémenté**
✅ **Documentation exhaustive**
✅ **Page de test interactive**
✅ **Endpoints CRUD pour écritures**
✅ **Rapports (balance, grand-livre)**
✅ **Prête pour intégrations**

**Date de création** : 2025-11-30
**Version** : 1.0
**Status** : ✅ Production Ready (après configuration sécurité)
