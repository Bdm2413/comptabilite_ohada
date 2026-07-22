# 📐 Guide Typographique - Comptabilité OHADA

## 🎯 Objectif
Ce guide définit le système typographique harmonisé pour toute l'application afin d'assurer une interface professionnelle et cohérente.

---

## 📏 Échelle de Tailles

### Variables CSS à utiliser

```css
:root {
    --font-size-xs: 10px;      /* Extra small - labels secondaires, rubriques */
    --font-size-sm: 11px;      /* Small - données tableau, textes compacts */
    --font-size-base: 12px;    /* Base - texte normal, paragraphes */
    --font-size-md: 13px;      /* Medium - en-têtes tableau, sous-titres */
    --font-size-lg: 16px;      /* Large - titres de sections */
    --font-size-xl: 20px;      /* Extra large - titre principal de page */
}
```

---

## 📊 Application par Contexte

### 1. **Titres de Pages**
```html
<h1 style="font-size: var(--font-size-xl);" class="font-bold">
    Titre Principal
</h1>
```
**Taille:** `20px` (var(--font-size-xl))
**Usage:** Titre principal en haut de chaque page

---

### 2. **Titres de Sections**
```html
<h2 style="font-size: var(--font-size-lg);" class="font-bold">
    Titre de Section
</h2>
```
**Taille:** `16px` (var(--font-size-lg))
**Usage:** Sections, cartes, panneaux

---

### 3. **Texte Normal**
```html
<p style="font-size: var(--font-size-base);">
    Texte normal
</p>
```
**Taille:** `12px` (var(--font-size-base))
**Usage:** Paragraphes, descriptions, labels principaux

---

### 4. **En-têtes de Tableau**

#### En-têtes principaux (mois, grandes colonnes)
```css
.mois-header {
    font-size: var(--font-size-md);  /* 13px */
}
```

#### En-têtes secondaires (Débit, Crédit, etc.)
```css
.col-montant-header {
    font-size: var(--font-size-xs);  /* 10px */
}
```

---

### 5. **Données de Tableau**

#### Colonnes de texte (Compte, Intitulé)
```css
.col-compte, .col-intitule {
    font-size: var(--font-size-sm);  /* 11px */
}
```

#### Colonnes de montants (Débit, Crédit)
```css
.col-montant {
    font-size: var(--font-size-sm);  /* 11px */
    font-family: 'Courier New', monospace;
}
```

#### Colonnes compactes (Rubrique)
```css
.col-rubrique {
    font-size: var(--font-size-xs);  /* 10px */
}
```

---

## 💰 Gestion des Montants

### Problème
Les montants peuvent atteindre des milliards (1 000 000 000) et ne doivent **JAMAIS** se couper sur deux lignes.

### Solution

```css
.col-montant {
    min-width: 100px;          /* Largeur suffisante pour milliards */
    max-width: 100px;
    font-size: var(--font-size-sm);
    font-family: 'Courier New', monospace;  /* Police à chasse fixe */
    white-space: nowrap;       /* Pas de retour à la ligne */
    overflow: visible;         /* Montants visibles même si débordent */
    padding: 8px 4px !important;
    text-align: right;
}
```

### Format d'affichage
```php
// Afficher sans décimales pour économiser l'espace
<?= safe_number_format($montant, 0) ?>

// Résultat: 1 000 000 000 au lieu de 1 000 000 000,00
```

---

## 📐 Largeurs de Colonnes Optimisées

### Balance Mensuelle

| Colonne | Largeur | Taille Police |
|---------|---------|---------------|
| Compte | 90px | 11px (sm) |
| Intitulé | 300px | 11px (sm) |
| Tableau | 70px | 11px (sm) |
| Débit | 100px | 11px (sm) |
| Crédit | 100px | 11px (sm) |
| Rubrique | 50px | 10px (xs) |

**Total par mois:** 250px (Débit + Crédit + Rubrique)
**Total fixe:** 460px (Compte + Intitulé + Tableau)

---

## 🎨 Espacement (Padding)

### En-têtes de tableau
```css
padding: 8px 6px;    /* Pour colonnes larges (Compte, Intitulé) */
padding: 8px 4px;    /* Pour colonnes moyennes (Tableau, Montants) */
padding: 8px 2px;    /* Pour colonnes étroites (Rubrique) */
```

### Cellules de données
```css
padding: 8px 6px;    /* Mêmes règles que les en-têtes */
```

---

## ✅ Checklist d'Implémentation

Lors de la création ou modification d'une page :

- [ ] Importer les variables CSS typographiques
- [ ] Utiliser `var(--font-size-xx)` au lieu de tailles fixes
- [ ] Appliquer les classes `.col-montant` pour les montants
- [ ] Vérifier que les montants ne se coupent pas
- [ ] Tester avec des milliards (1 000 000 000)
- [ ] Vérifier la cohérence avec les autres pages
- [ ] Tester sur différentes résolutions d'écran

---

## 📄 Pages à Harmoniser

### Déjà harmonisées ✅
- [x] Balance Mensuelle (rapport_mensuel.php)

### À harmoniser ⏳
- [ ] Compte de Résultat
- [ ] Bilan OHADA
- [ ] Journal Général
- [ ] Balance Auxiliaire
- [ ] Balance Générale
- [ ] Grand Livre

---

## 🔧 Code Réutilisable

### Bloc CSS complet à copier

```css
<style>
    /* SYSTÈME TYPOGRAPHIQUE HARMONISÉ */
    :root {
        --font-size-xs: 10px;
        --font-size-sm: 11px;
        --font-size-base: 12px;
        --font-size-md: 13px;
        --font-size-lg: 16px;
        --font-size-xl: 20px;
    }

    body {
        font-size: var(--font-size-base);
    }

    /* Colonnes de montants optimisées */
    .col-montant {
        min-width: 100px;
        max-width: 100px;
        font-size: var(--font-size-sm);
        font-family: 'Courier New', monospace;
        white-space: nowrap;
        overflow: visible;
        padding: 8px 4px !important;
        text-align: right;
    }

    .col-montant-header {
        font-size: var(--font-size-xs);
        min-width: 100px;
        max-width: 100px;
        padding: 8px 4px !important;
    }
</style>
```

---

## 📝 Notes Importantes

1. **Cohérence:** Toutes les pages doivent utiliser les mêmes tailles
2. **Lisibilité:** Ne jamais descendre sous 10px
3. **Montants:** Toujours en monospace (Courier New)
4. **Sans décimales:** Utiliser `safe_number_format($montant, 0)` pour les tableaux
5. **Responsive:** Les tailles sont fixes mais le layout doit s'adapter

---

**Version:** 1.0
**Date:** 2025-11-02
**Auteur:** Équipe Comptabilité OHADA
