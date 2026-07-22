# 📘 Documentation API REST - ComptaSYSCOHADA

## 🌐 URL de Base

```
http://localhost/comptabilite_ohada/api/v1
```

---

## 🔐 Authentification

L'API utilise des **tokens JWT (JSON Web Tokens)** pour l'authentification.

### 1. Obtenir un Token

**Endpoint** : `POST /api/v1/auth/login`

**Headers** :
```
Content-Type: application/json
```

**Body** :
```json
{
  "email": "admin@comptabilite.local",
  "password": "admin123"
}
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "message": "Login successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_in": 86400,
    "user": {
      "id": 1,
      "email": "admin@comptabilite.local",
      "nom": "Administrateur",
      "role": "admin"
    }
  },
  "timestamp": "2025-11-30T10:00:00+00:00",
  "api_version": "v1"
}
```

### 2. Utiliser le Token

Pour toutes les autres requêtes, incluez le token dans le header `Authorization` :

```
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

### 3. Rafraîchir le Token

**Endpoint** : `POST /api/v1/auth/refresh`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "message": "Token refreshed successfully",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_in": 86400
  }
}
```

---

## 📝 Écritures Comptables

### 1. Liste des Écritures (avec pagination)

**Endpoint** : `GET /api/v1/ecritures`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Query Parameters** (optionnels) :
- `page` : Numéro de page (défaut: 1)
- `limit` : Nombre d'éléments par page (défaut: 20, max: 100)
- `journal` : Filtrer par journal (ex: "AC", "VE")
- `statut` : Filtrer par statut ("brouillon" ou "valide")
- `date_debut` : Date de début (format: YYYY-MM-DD)
- `date_fin` : Date de fin (format: YYYY-MM-DD)
- `exercice_id` : ID de l'exercice

**Exemple** :
```
GET /api/v1/ecritures?page=1&limit=10&statut=valide&journal=AC
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "data": {
    "items": [
      {
        "id": 1,
        "numero_piece": "AC001",
        "date_piece": "2025-01-15",
        "journal": "AC",
        "libelle_ecriture": "Achat marchandises",
        "statut": "valide",
        "montant_total": "250000.00",
        "exercice_annee": "2025",
        "created_at": "2025-01-15 10:30:00",
        "updated_at": null
      }
    ],
    "pagination": {
      "total": 150,
      "page": 1,
      "limit": 10,
      "total_pages": 15,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

### 2. Détail d'une Écriture

**Endpoint** : `GET /api/v1/ecritures/{id}`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Exemple** :
```
GET /api/v1/ecritures/1
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "data": {
    "id": 1,
    "numero_piece": "AC001",
    "date_piece": "2025-01-15",
    "journal": "AC",
    "libelle_ecriture": "Achat marchandises",
    "statut": "valide",
    "montant_total": "250000.00",
    "exercice_annee": "2025",
    "lignes": [
      {
        "id": 1,
        "compte": "601100",
        "intitule_compte": "Achats de marchandises",
        "libelle_ligne": "Marchandises société XYZ",
        "debit": "250000.00",
        "credit": "0.00",
        "tiers_type": "fournisseur",
        "tiers_id": 5
      },
      {
        "id": 2,
        "compte": "401100",
        "intitule_compte": "Fournisseurs",
        "libelle_ligne": "Société XYZ",
        "debit": "0.00",
        "credit": "250000.00",
        "tiers_type": "fournisseur",
        "tiers_id": 5
      }
    ]
  }
}
```

### 3. Créer une Écriture

**Endpoint** : `POST /api/v1/ecritures`

**Headers** :
```
Authorization: Bearer <votre_token>
Content-Type: application/json
```

**Body** :
```json
{
  "numero_piece": "AC002",
  "date_piece": "2025-01-20",
  "journal": "AC",
  "libelle_ecriture": "Achat fournitures",
  "statut": "brouillon",
  "exercice_id": 1,
  "lignes": [
    {
      "compte": "605300",
      "libelle": "Fournitures de bureau",
      "debit": 50000,
      "credit": 0
    },
    {
      "compte": "401100",
      "libelle": "Fournisseur ABC",
      "debit": 0,
      "credit": 50000,
      "tiers_type": "fournisseur",
      "tiers_id": 3
    }
  ]
}
```

**Réponse** (201 Created) :
```json
{
  "success": true,
  "status": 201,
  "message": "Écriture created successfully",
  "data": {
    "id": 152
  }
}
```

### 4. Modifier une Écriture

**Endpoint** : `PUT /api/v1/ecritures/{id}`

**Headers** :
```
Authorization: Bearer <votre_token>
Content-Type: application/json
```

**Body** (champs optionnels) :
```json
{
  "statut": "valide",
  "libelle_ecriture": "Achat fournitures - modifié"
}
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "message": "Écriture updated successfully"
}
```

### 5. Supprimer une Écriture

**Endpoint** : `DELETE /api/v1/ecritures/{id}`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "message": "Écriture deleted successfully"
}
```

---

## ⚖️ Balance

### 1. Balance Générale

**Endpoint** : `GET /api/v1/balance/generale`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Query Parameters** (optionnels) :
- `date_debut` : Date de début (format: YYYY-MM-DD)
- `date_fin` : Date de fin (format: YYYY-MM-DD)
- `exercice_id` : ID de l'exercice
- `classe` : Filtrer par classe de compte (1-9)

**Exemple** :
```
GET /api/v1/balance/generale?classe=6&date_debut=2025-01-01&date_fin=2025-01-31
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "data": {
    "balance": [
      {
        "compte": "601100",
        "intitule_compte": "Achats de marchandises",
        "classe": "6",
        "total_debit": 1250000.00,
        "total_credit": 0.00,
        "solde": 1250000.00
      },
      {
        "compte": "605300",
        "intitule_compte": "Fournitures de bureau",
        "classe": "6",
        "total_debit": 150000.00,
        "total_credit": 0.00,
        "solde": 150000.00
      }
    ],
    "totaux": {
      "total_debit": 1400000.00,
      "total_credit": 0.00,
      "solde_debiteur": 1400000.00,
      "solde_crediteur": 0.00
    },
    "parametres": {
      "date_debut": "2025-01-01",
      "date_fin": "2025-01-31",
      "exercice_id": null,
      "classe": "6"
    },
    "count": 2
  }
}
```

### 2. Balance Auxiliaire

**Endpoint** : `GET /api/v1/balance/auxiliaire`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Query Parameters** (optionnels) :
- `date_debut` : Date de début
- `date_fin` : Date de fin
- `exercice_id` : ID de l'exercice
- `tiers_type` : Type de tiers ("client" ou "fournisseur")

**Exemple** :
```
GET /api/v1/balance/auxiliaire?tiers_type=client
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "data": {
    "balance": [
      {
        "compte": "411000",
        "intitule_compte": "Clients",
        "tiers_type": "client",
        "tiers_id": 10,
        "tiers_nom": "Société ABC",
        "tiers_categorie": "client",
        "total_debit": 500000.00,
        "total_credit": 300000.00,
        "solde": 200000.00
      }
    ],
    "totaux": {
      "total_debit": 500000.00,
      "total_credit": 300000.00,
      "solde_debiteur": 200000.00,
      "solde_crediteur": 0.00
    },
    "count": 1
  }
}
```

---

## 📖 Grand-Livre

### 1. Grand-Livre Général (Résumé par compte)

**Endpoint** : `GET /api/v1/grand-livre`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Query Parameters** (optionnels) :
- `date_debut` : Date de début
- `date_fin` : Date de fin
- `exercice_id` : ID de l'exercice
- `classe` : Filtrer par classe de compte

**Exemple** :
```
GET /api/v1/grand-livre?classe=7
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "data": {
    "comptes": [
      {
        "compte": "701100",
        "intitule_compte": "Ventes de marchandises",
        "classe": "7",
        "nb_mouvements": 25,
        "total_debit": 0.00,
        "total_credit": 5000000.00,
        "solde": -5000000.00
      }
    ],
    "count": 1
  }
}
```

### 2. Grand-Livre d'un Compte Spécifique

**Endpoint** : `GET /api/v1/grand-livre/{compte}`

**Headers** :
```
Authorization: Bearer <votre_token>
```

**Query Parameters** (optionnels) :
- `date_debut` : Date de début
- `date_fin` : Date de fin
- `exercice_id` : ID de l'exercice

**Exemple** :
```
GET /api/v1/grand-livre/601100?date_debut=2025-01-01&date_fin=2025-01-31
```

**Réponse** (200 OK) :
```json
{
  "success": true,
  "status": 200,
  "data": {
    "compte": {
      "numero": "601100",
      "intitule": "Achats de marchandises",
      "classe": "6"
    },
    "mouvements": [
      {
        "ecriture_id": 1,
        "numero_piece": "AC001",
        "date_piece": "2025-01-15",
        "journal": "AC",
        "libelle_ecriture": "Achat marchandises",
        "ligne_id": 1,
        "libelle_ligne": "Marchandises société XYZ",
        "debit": 250000.00,
        "credit": 0.00,
        "tiers_type": "fournisseur",
        "tiers_id": 5,
        "tiers_nom": "Société XYZ",
        "solde_progressif": 250000.00
      }
    ],
    "totaux": {
      "total_debit": 250000.00,
      "total_credit": 0.00,
      "solde_final": 250000.00
    },
    "count": 1
  }
}
```

---

## 🚦 Rate Limiting

L'API implémente un système de rate limiting pour éviter les abus :

- **Limite** : 100 requêtes par heure par IP
- **Headers de réponse** :
  - `X-RateLimit-Limit` : Nombre maximum de requêtes
  - `X-RateLimit-Remaining` : Requêtes restantes
  - `X-RateLimit-Reset` : Timestamp de réinitialisation

**Erreur 429 (Too Many Requests)** :
```json
{
  "success": false,
  "status": 429,
  "error": {
    "message": "Rate limit exceeded - Trop de requêtes",
    "code": 429,
    "details": {
      "limit": 100,
      "window": 3600,
      "reset_at": "2025-11-30T11:00:00+00:00"
    }
  }
}
```

---

## ❌ Codes d'Erreur

| Code | Signification | Description |
|------|---------------|-------------|
| 200 | OK | Requête réussie |
| 201 | Created | Ressource créée avec succès |
| 400 | Bad Request | Données invalides |
| 401 | Unauthorized | Token manquant ou invalide |
| 403 | Forbidden | Accès refusé (permissions insuffisantes) |
| 404 | Not Found | Ressource non trouvée |
| 405 | Method Not Allowed | Méthode HTTP non autorisée |
| 429 | Too Many Requests | Limite de requêtes dépassée |
| 500 | Internal Server Error | Erreur serveur |

**Format d'erreur standardisé** :
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

## 🧪 Exemples avec cURL

### Login
```bash
curl -X POST http://localhost/comptabilite_ohada/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@comptabilite.local","password":"admin123"}'
```

### Liste des écritures
```bash
curl -X GET "http://localhost/comptabilite_ohada/api/v1/ecritures?page=1&limit=5" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

### Balance générale
```bash
curl -X GET "http://localhost/comptabilite_ohada/api/v1/balance/generale?classe=6" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

---

## 🔒 Sécurité

### Bonnes Pratiques

1. **Stockage du token**
   - NE PAS stocker dans localStorage (vulnérable aux XSS)
   - Utiliser sessionStorage ou httpOnly cookies

2. **HTTPS en production**
   - Toujours utiliser HTTPS en production
   - Le token JWT contient des données sensibles

3. **Rotation des tokens**
   - Utiliser `/auth/refresh` pour renouveler le token
   - Implémenter une blacklist pour les tokens révoqués

4. **Validation côté client**
   - Valider les données avant envoi
   - Gérer les erreurs proprement

---

## 📝 Notes

- Tous les montants sont en FCFA
- Les dates sont au format `YYYY-MM-DD`
- Les timestamps sont au format ISO 8601
- Les réponses sont en UTF-8
- L'API retourne toujours du JSON

---

**Version** : 1.0
**Date** : 2025-11-30
**Contact** : ComptaSYSCOHADA Support
