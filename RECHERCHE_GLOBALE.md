# 🔍 Recherche Globale Intelligente

## Vue d'ensemble

Le système de recherche globale permet de trouver rapidement n'importe quelle information dans l'application ComptaSYSCOHADA. Il intègre une recherche cross-module avec tolérance aux fautes de frappe, historique et classement intelligent par pertinence.

---

## ✨ Fonctionnalités

### 1. **Recherche Cross-Module**
Recherchez simultanément dans :
- ✅ **Écritures comptables** (numéro de pièce, libellé, référence)
- ✅ **Plan comptable** (numéro de compte, intitulé)
- ✅ **Tiers** (clients/fournisseurs : nom, email, téléphone)
- ✅ **Pièces comptables** (référence, type, nom du fichier)
- ✅ **Journaux** (code, libellé)

### 2. **Recherche Floue avec Fuse.js**
- Tolérance aux fautes de frappe (threshold: 0.4)
- Recherche phonétique et approximative
- Classement intelligent par pertinence (score 0-100%)

### 3. **Interface Moderne**
- Modal centré avec animations fluides
- Filtres rapides par module
- Navigation au clavier (↑↓, Enter, Esc)
- Indicateur de pertinence pour chaque résultat
- Badges visuels pour les statuts

### 4. **Historique de Recherche**
- Mémorisation des 10 dernières recherches
- Affichage au lancement du modal
- Suppression individuelle des entrées
- Stockage local (localStorage)

### 5. **Raccourcis Clavier**
- `Ctrl+K` / `Cmd+K` : Ouvrir la recherche
- `Esc` : Fermer le modal
- `↑` / `↓` : Naviguer dans les résultats
- `Enter` : Ouvrir le résultat sélectionné

---

## 🚀 Utilisation

### Ouverture de la recherche

**Option 1 : Raccourci clavier**
```
Ctrl + K (Windows/Linux)
Cmd + K (macOS)
```

**Option 2 : Bouton dans le header**
Cliquez sur le bouton "Rechercher" dans la barre de navigation.

### Effectuer une recherche

1. **Tapez votre requête** dans le champ de recherche (minimum 2 caractères)
2. **Filtrez par module** (optionnel) en cliquant sur un filtre
3. **Naviguez** avec les flèches ou la souris
4. **Ouvrez** un résultat avec Enter ou un clic

### Exemples de recherches

| Recherche | Résultats trouvés |
|-----------|-------------------|
| `FAC2024` | Écritures avec ce numéro de pièce |
| `5210000` | Compte bancaire et lignes liées |
| `dupont` | Clients/fournisseurs nommés Dupont |
| `facture` | Toutes pièces et écritures type facture |
| `caisse` | Journaux et comptes liés à la caisse |

### Recherche floue

Le système tolère les fautes de frappe :

| Vous tapez | Trouve |
|------------|--------|
| `factur` | **Facture** |
| `clint` | **Client** |
| `bnaque` | **Banque** |
| `dupond` | **Dupont** |

---

## 🔧 Intégration

### Ajouter la recherche à une page

```php
<!-- À la fin du body, avant </body> -->
<?php include '../../components/search_global.php'; ?>
```

### Ajouter le bouton de recherche (optionnel)

```html
<button onclick="document.getElementById('searchModal').classList.remove('hidden'); document.getElementById('globalSearchInput').focus();"
        class="flex items-center gap-2 px-4 py-2 bg-slate-700/50 hover:bg-slate-700 border border-slate-600 rounded-lg transition group">
    <svg class="w-4 h-4 text-slate-400 group-hover:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
    </svg>
    <span class="text-sm text-slate-400 group-hover:text-slate-300">Rechercher</span>
    <kbd class="hidden sm:inline-block px-2 py-1 text-xs bg-slate-800 border border-slate-600 rounded text-slate-400">Ctrl K</kbd>
</button>
```

---

## 🎨 Personnalisation

### Modifier le seuil de tolérance

Dans `components/search_global.php` :

```javascript
const fuseOptions = {
    threshold: 0.4, // 0 = exact, 1 = tout correspond
    // Augmenter pour plus de tolérance
    // Diminuer pour plus de précision
};
```

### Modifier le nombre de résultats

Dans `api/v1/search.php` :

```php
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
//                                                    ^^     ^^
//                                                    max    défaut
```

### Ajouter un module de recherche

**1. Modifier l'API** (`api/v1/search.php`) :

```php
// 6. RECHERCHE DANS MON NOUVEAU MODULE
if ($module === 'all' || $module === 'mon_module') {
    $stmt = $db->prepare("
        SELECT
            'mon_type' as type,
            id,
            nom as display_text,
            'pages/mon_module/voir.php?id=' as url
        FROM ma_table
        WHERE nom LIKE :search1
        ORDER BY nom ASC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $monModule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['results'] = array_merge($results['results'], $monModule);
}
```

**2. Ajouter le filtre** dans `components/search_global.php` :

```html
<button class="search-filter px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200"
        data-module="mon_module">
    Mon Module
</button>
```

**3. Ajouter l'icône et le style** :

```javascript
const typeIcons = {
    // ... icônes existantes
    'mon_type': '<svg>...</svg>'
};

const typeLabels = {
    // ... labels existants
    'mon_type': 'Mon Module'
};
```

```css
.result-icon-mon_type { background-color: #FEF3C7; color: #92400E; }
```

---

## 📡 API REST

### Endpoint

```
GET /api/v1/search
```

### Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `q` | string | ✅ | Terme de recherche (min 1 caractère) |
| `module` | string | ❌ | Filtre par module (`all`, `ecritures`, `comptes`, `tiers`, `pieces`, `journaux`) |
| `limit` | integer | ❌ | Nombre max de résultats (défaut: 20, max: 50) |
| `offset` | integer | ❌ | Décalage pour la pagination (défaut: 0) |

### Exemple de requête

```bash
curl -X GET "http://localhost/comptabilite_ohada/api/v1/search?q=facture&module=ecritures&limit=10" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Réponse

```json
{
  "success": true,
  "query": "facture",
  "total": 15,
  "results": [
    {
      "type": "ecriture",
      "id": 123,
      "numero_piece": "FAC2024001",
      "libelle": "Facture client Dupont",
      "date_ecriture": "2024-01-15",
      "statut": "Validé",
      "montant_total": 15000,
      "display_text": "FAC2024001 - Facture client Dupont",
      "url": "pages/ecritures/modifier_ecriture.php?id=",
      "relevance_score": 95
    }
    // ... autres résultats
  ],
  "grouped": {
    "ecriture": [ /* ... */ ],
    "piece": [ /* ... */ ]
  }
}
```

### Codes d'erreur

| Code | Message | Description |
|------|---------|-------------|
| 400 | `Le paramètre de recherche "q" est requis` | Paramètre `q` manquant |
| 401 | `Non autorisé` | Token JWT invalide ou manquant |
| 500 | `Erreur lors de la recherche` | Erreur serveur |

---

## 🎯 Score de Pertinence

Le système calcule un score de pertinence (0-100%) pour chaque résultat :

| Score | Signification |
|-------|---------------|
| 90-100% | Correspondance exacte |
| 70-89% | Commence par la requête |
| 50-69% | Contient la requête |
| 0-49% | Correspondance partielle |

Avec Fuse.js, le score est basé sur :
- Distance de Levenshtein (fautes de frappe)
- Position du terme dans le texte
- Poids des champs (display_text > libellé > détails)

---

## ⚡ Performance

### Optimisations appliquées

1. **Debouncing** : 300ms de délai avant la recherche
2. **Indexation** : Indexes SQL sur les champs recherchés
3. **Limite de résultats** : Max 50 résultats par requête
4. **Cache Fuse.js** : Instance réutilisée entre recherches
5. **Lazy loading** : Chargement uniquement quand nécessaire

### Temps de réponse typiques

| Module | Résultats | Temps moyen |
|--------|-----------|-------------|
| Écritures | 100 | ~50ms |
| Comptes | 50 | ~30ms |
| Tiers | 30 | ~25ms |
| Tous | 200 | ~100ms |

### Recommandations

- ✅ Utiliser les filtres de module pour des recherches ciblées
- ✅ Taper au moins 3 caractères pour des résultats pertinents
- ✅ Vider l'historique régulièrement (10 max)
- ⚠️ Éviter les requêtes trop courtes (1-2 caractères)

---

## 🔒 Sécurité

### Protection SQL Injection

```php
// Échappement des caractères spéciaux SQL
$searchTerm = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

// Utilisation de requêtes préparées
$stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
```

### Authentification

L'endpoint `/api/v1/search` requiert :
- Session utilisateur active (`requireLogin()`)
- Token JWT valide (si API REST utilisée)

### Limite de requêtes

- Rate limiting : 60 requêtes/minute par IP
- Timeout : 30 secondes max par requête

---

## 📊 Statistiques

L'historique de recherche permet d'analyser :
- Les termes les plus recherchés
- Les modules les plus utilisés
- L'efficacité de la recherche

```javascript
// Récupérer les statistiques
const history = JSON.parse(localStorage.getItem('search_history'));
console.log('Recherches effectuées :', history.length);
```

---

## 🐛 Dépannage

### La recherche ne s'ouvre pas avec Ctrl+K

**Vérifiez :**
1. Le composant est bien inclus : `<?php include '../../components/search_global.php'; ?>`
2. Aucun autre script n'intercepte Ctrl+K
3. La console JavaScript pour des erreurs

### Aucun résultat trouvé

**Causes possibles :**
1. Terme de recherche trop court (< 2 caractères)
2. Base de données vide
3. Erreur SQL (vérifier les logs)

**Solution :**
```bash
# Tester l'API directement
curl "http://localhost/comptabilite_ohada/api/v1/search?q=test&module=all"
```

### Recherche trop lente

**Optimisations :**
1. Vérifier que les indexes existent (`database/optimisations.sql`)
2. Réduire la limite de résultats
3. Utiliser un module spécifique au lieu de "all"

```sql
-- Vérifier les indexes
SHOW INDEX FROM ecritures;
SHOW INDEX FROM plan_comptable;
SHOW INDEX FROM tiers;
```

### L'historique ne se sauvegarde pas

**Vérifiez :**
1. localStorage activé dans le navigateur
2. Mode navigation privée (localStorage désactivé)
3. Console JavaScript pour erreurs

```javascript
// Tester localStorage
try {
    localStorage.setItem('test', 'ok');
    console.log('localStorage fonctionne');
} catch (e) {
    console.error('localStorage bloqué:', e);
}
```

---

## 📝 Changelog

### Version 1.0.0 (Phase 6)
- ✅ Recherche cross-module (5 modules)
- ✅ API REST `/api/v1/search`
- ✅ Interface modale avec Ctrl+K
- ✅ Recherche floue avec Fuse.js (tolérance aux fautes)
- ✅ Historique de recherche (10 entrées max)
- ✅ Navigation au clavier (↑↓ Enter Esc)
- ✅ Score de pertinence (0-100%)
- ✅ Filtres par module
- ✅ Badges et icônes par type
- ✅ Documentation complète

---

## 🚀 Évolutions futures

### Court terme
- [ ] Preview inline des résultats (aperçu sans quitter la page)
- [ ] Recherche vocale (Web Speech API)
- [ ] Export des résultats (CSV, Excel)

### Moyen terme
- [ ] Recherche sémantique (synonymes, concepts)
- [ ] Suggestions automatiques (autocomplete)
- [ ] Recherche avancée (opérateurs AND/OR/NOT)

### Long terme
- [ ] Recherche full-text avec Elasticsearch
- [ ] Machine Learning pour prédire les recherches
- [ ] Recherche dans les fichiers PDF/images (OCR)

---

## 📚 Ressources

### Documentation utilisée
- [Fuse.js](https://fusejs.io/) - Recherche floue
- [Tailwind CSS](https://tailwindcss.com/) - Styles
- [Chart.js](https://www.chartjs.org/) - (Autre module)

### Fichiers du projet

```
comptabilite_ohada/
├── api/
│   └── v1/
│       └── search.php           # Endpoint API de recherche
├── components/
│   └── search_global.php        # Interface modale de recherche
├── pages/
│   └── dashboard/
│       └── index.php            # Exemple d'intégration
└── RECHERCHE_GLOBALE.md         # Cette documentation
```

---

## 💡 Support

Pour toute question ou problème :
1. Consultez cette documentation
2. Vérifiez les logs PHP (`error_log`)
3. Testez l'API avec curl ou Postman
4. Contactez l'équipe de développement

---

**ComptaSYSCOHADA** - Système de Comptabilité OHADA
Phase 6 : Recherche Globale Intelligente ✅
