#!/bin/bash

# Script pour ajouter le popup de la balance dans bilan.php et compte_resultat.php

echo "Application du popup de la balance générale..."

# 1. Ajouter l'include du popup dans bilan.php
BILAN_FILE="pages/etats_financiers/bilan.php"

if [ -f "$BILAN_FILE" ]; then
    # Vérifier si l'include n'est pas déjà présent
    if ! grep -q "balance_popup.php" "$BILAN_FILE"; then
        # Ajouter l'include juste avant </body>
        sed -i 's|</body>|    <?php include '\''../../includes/balance_popup.php'\''; ?>\n</body>|' "$BILAN_FILE"
        echo "✓ Include ajouté dans bilan.php"
    else
        echo "⚠ Include déjà présent dans bilan.php"
    fi

    # Ajouter le bouton Balance après le bouton Excel
    if ! grep -q "openBalanceModal" "$BILAN_FILE"; then
        # Trouver la ligne avec le bouton Excel et ajouter après
        sed -i '/Bouton Excel/,/button>/{
            /button>/ a\
\
                        <!-- Séparateur vertical -->\
                        <div class="h-10 w-px bg-slate-600"></div>\
\
                        <!-- Bouton Balance -->\
                        <button type="button" onclick="openBalanceModal()" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">\
                            <i class="fas fa-list"></i>\
                            Balance\
                        </button>
        }' "$BILAN_FILE"
        echo "✓ Bouton Balance ajouté dans bilan.php"
    else
        echo "⚠ Bouton Balance déjà présent dans bilan.php"
    fi
else
    echo "✗ Fichier $BILAN_FILE non trouvé"
fi

# 2. Ajouter l'include du popup dans compte_resultat.php
CR_FILE="pages/etats_financiers/compte_resultat.php"

if [ -f "$CR_FILE" ]; then
    # Vérifier si l'include n'est pas déjà présent
    if ! grep -q "balance_popup.php" "$CR_FILE"; then
        # Ajouter l'include juste avant </body>
        sed -i 's|</body>|    <?php include '\''../../includes/balance_popup.php'\''; ?>\n</body>|' "$CR_FILE"
        echo "✓ Include ajouté dans compte_resultat.php"
    else
        echo "⚠ Include déjà présent dans compte_resultat.php"
    fi

    # Ajouter le bouton Balance (chercher après le bouton Excel)
    if ! grep -q "openBalanceModal" "$CR_FILE"; then
        sed -i '/file-excel/,/button>/{
            /button>/ a\
\
                        <!-- Séparateur vertical -->\
                        <div class="h-10 w-px bg-slate-600"></div>\
\
                        <!-- Bouton Balance -->\
                        <button type="button" onclick="openBalanceModal()" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 text-white rounded-lg transition-all shadow-lg hover:shadow-xl inline-flex items-center gap-2">\
                            <i class="fas fa-list"></i>\
                            Balance\
                        </button>
        }' "$CR_FILE"
        echo "✓ Bouton Balance ajouté dans compte_resultat.php"
    else
        echo "⚠ Bouton Balance déjà présent dans compte_resultat.php"
    fi
else
    echo "✗ Fichier $CR_FILE non trouvé"
fi

echo ""
echo "✓ Script terminé!"
echo ""
echo "Pour tester:"
echo "1. Ouvrez http://localhost/comptabilite_ohada/pages/etats_financiers/bilan.php"
echo "2. Cliquez sur le bouton 'Balance' (cyan)"
echo "3. Vérifiez que le popup s'affiche correctement"
