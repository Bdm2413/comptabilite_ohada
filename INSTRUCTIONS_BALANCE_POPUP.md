# Instructions pour ajouter le popup de la Balance Générale

## ✅ Fichiers déjà créés:

1. **API de la balance**: `api/get_balance.php`
2. **Popup réutilisable**: `includes/balance_popup.php`

## 📝 Modifications à apporter:

### 1. Dans `pages/etats_financiers/bilan.php`

#### A) Ajouter le bouton Balance (après le bouton Excel, vers la ligne 710):

```php
                        <!-- Bouton Excel -->
                        <button type="button" onclick="exportExcel()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>

                        <!-- Séparateur vertical -->
                        <div class="h-10 w-px bg-slate-600"></div>

                        <!-- Bouton Balance -->
                        <button type="button" onclick="openBalanceModal()" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                            <i class="fas fa-list"></i>
                            Balance
                        </button>
```

#### B) Inclure le popup (juste avant `</body>`, à la toute fin du fichier):

```php
    <?php include '../../includes/balance_popup.php'; ?>
</body>
</html>
```

### 2. Dans `pages/etats_financiers/compte_resultat.php`

#### A) Ajouter le même bouton Balance (chercher la section avec les boutons PDF/Excel)

#### B) Inclure le popup (juste avant `</body>`)

```php
    <?php include '../../includes/balance_popup.php'; ?>
</body>
</html>
```

## 🎯 Résultat attendu:

- Un nouveau bouton "Balance" apparaîtra à côté des boutons PDF et Excel
- Cliquer sur ce bouton ouvrira un popup modal affichant la balance générale
- La balance sera filtrée selon la même période que le bilan/compte de résultat
- Le popup affiche tous les comptes avec leurs débits, crédits et soldes
- Les totaux sont calculés automatiquement

## 🔧 Test:

1. Ouvrez le bilan: `http://localhost/comptabilite_ohada/pages/etats_financiers/bilan.php`
2. Cliquez sur le bouton "Balance" (cyan)
3. Vérifiez que le popup s'ouvre et affiche les données
4. Vérifiez que les totaux Débit = Crédit

## 💡 Notes:

- Le popup est réutilisable (même fichier include pour bilan et compte de résultat)
- Les données sont chargées dynamiquement via AJAX
- Le popup est responsive et scrollable
- Fermeture possible par: bouton X, bouton Fermer, touche Escape, ou clic en dehors
