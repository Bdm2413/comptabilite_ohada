#!/usr/bin/env python3
"""
Script pour uniformiser les titres des pages
"""
import re
import os

# Dictionnaire des pages à corriger avec leurs configurations
pages_config = {
    # Écritures
    'pages/ecritures/saisie.php': {
        'icon': 'fa-edit',
        'gradient': 'from-cyan-400 to-blue-600',
        'title': 'Saisie d\'Écriture'
    },
    'pages/ecritures/lettrage.php': {
        'icon': 'fa-link',
        'gradient': 'from-cyan-400 to-blue-600',
        'title': 'Lettrage'
    },
    'pages/ecritures/voir.php': {
        'icon': 'fa-eye',
        'gradient': 'from-cyan-400 to-blue-600',
        'title': 'Détail de l\'Écriture'
    },
    # Settings
    'pages/settings/plan_comptable.php': {
        'icon': 'fa-list-alt',
        'gradient': 'from-orange-400 to-red-600',
        'title': 'Plan Comptable'
    },
    'pages/settings/code_journaux.php': {
        'icon': 'fa-book',
        'gradient': 'from-orange-400 to-red-600',
        'title': 'Code Journaux'
    },
    'pages/settings/correspondance.php': {
        'icon': 'fa-exchange-alt',
        'gradient': 'from-orange-400 to-red-600',
        'title': 'Tableau de Correspondance'
    },
    # Analyses
    'pages/analyses/heatmap.php': {
        'icon': 'fa-fire',
        'gradient': 'from-red-400 to-orange-600',
        'title': 'Heatmap des Mouvements'
    },
    'pages/analyses/comparaison.php': {
        'icon': 'fa-chart-bar',
        'gradient': 'from-red-400 to-orange-600',
        'title': 'Comparaison de Comptes'
    },
    # Rapports
    'pages/rapports/balance_agee_clients.php': {
        'icon': 'fa-users',
        'gradient': 'from-green-400 to-emerald-600',
        'title': 'Balance Âgée Clients'
    },
    'pages/rapports/balance_agee_fournisseurs.php': {
        'icon': 'fa-building',
        'gradient': 'from-green-400 to-emerald-600',
        'title': 'Balance Âgée Fournisseurs'
    },
    'pages/rapports/evolution_report_nouveau.php': {
        'icon': 'fa-chart-line',
        'gradient': 'from-green-400 to-emerald-600',
        'title': 'Évolution Report à Nouveau'
    },
}

def fix_title(filepath, config):
    """Corrige le titre d'une page"""
    full_path = os.path.join('c:/wamp64/www/comptabilite_ohada', filepath)

    if not os.path.exists(full_path):
        print(f"[X] Fichier non trouve: {filepath}")
        return False

    with open(full_path, 'r', encoding='utf-8') as f:
        content = f.read()

    # Pattern 1: h1 sur une seule ligne avec texte
    pattern1 = r'<h1\s+class="[^"]*text-white[^"]*">([^<]+)</h1>'

    # Pattern 2: h1 sur une seule ligne avec variable PHP
    pattern2 = r'<h1\s+class="[^"]*text-white[^"]*"><\?=\s*\$pageTitle\s*\?></h1>'

    # Pattern 3: h1 multiline avec variable PHP
    pattern3 = r'<h1\s+class="[^"]*text-white[^"]*">\s*<\?=[^?]+\?>\s*</h1>'

    # Nouveau titre avec icône et gradient
    new_title = f'<h1 class="page-title font-bold text-transparent bg-clip-text bg-gradient-to-r {config["gradient"]} mb-2">\n                            <i class="fas {config["icon"]} mr-3"></i>{config["title"]}\n                        </h1>'

    # Essayer chaque pattern
    new_content = content
    for pattern in [pattern1, pattern2, pattern3]:
        new_content = re.sub(pattern, new_title, new_content, flags=re.MULTILINE | re.DOTALL)
        if new_content != content:
            break

    if content != new_content:
        with open(full_path, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"[OK] {filepath} - Titre corrige")
        return True
    else:
        print(f"[!] {filepath} - Aucune correspondance trouvee")
        return False

def main():
    print("Uniformisation des titres de pages...\n")

    success_count = 0
    for filepath, config in pages_config.items():
        if fix_title(filepath, config):
            success_count += 1

    print(f"\n{success_count}/{len(pages_config)} pages corrigees")

if __name__ == '__main__':
    main()
