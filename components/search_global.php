<!-- Recherche Globale (Ctrl+K) -->
<div id="searchModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-start justify-center pt-20">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4 animate-slideDown">
        <!-- Barre de recherche -->
        <div class="p-4 border-b border-gray-200">
            <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input
                    type="text"
                    id="globalSearchInput"
                    class="flex-1 border-0 outline-none text-lg text-gray-900 bg-white placeholder-gray-400"
                    placeholder="Rechercher dans toute l'application... (Ctrl+K)"
                    autocomplete="off"
                >
                <button id="closeSearchModal" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Filtres -->
            <div class="flex space-x-2 mt-3">
                <button class="search-filter active px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-700" data-module="all">
                    Tout
                </button>
                <button class="search-filter px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200" data-module="ecritures">
                    Écritures
                </button>
                <button class="search-filter px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200" data-module="comptes">
                    Comptes
                </button>
                <button class="search-filter px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200" data-module="tiers">
                    Tiers
                </button>
                <button class="search-filter px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200" data-module="pieces">
                    Pièces
                </button>
                <button class="search-filter px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200" data-module="journaux">
                    Journaux
                </button>
            </div>
        </div>

        <!-- Résultats de recherche -->
        <div id="searchResults" class="max-h-96 overflow-y-auto">
            <!-- État initial -->
            <div id="searchInitial" class="p-8 text-center text-gray-500">
                <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <p class="text-sm">Commencez à taper pour rechercher...</p>
                <p class="text-xs mt-2 text-gray-400">Écritures • Comptes • Tiers • Pièces • Journaux</p>
            </div>

            <!-- Chargement -->
            <div id="searchLoading" class="hidden p-8 text-center">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                <p class="mt-3 text-sm text-gray-500">Recherche en cours...</p>
            </div>

            <!-- Résultats groupés -->
            <div id="searchResultsList" class="hidden">
                <!-- Les résultats seront insérés ici dynamiquement -->
            </div>

            <!-- Aucun résultat -->
            <div id="searchNoResults" class="hidden p-8 text-center text-gray-500">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-sm">Aucun résultat trouvé</p>
                <p class="text-xs mt-1 text-gray-400">Essayez avec d'autres mots-clés</p>
            </div>
        </div>

        <!-- Pied de page -->
        <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 rounded-b-lg">
            <div class="flex items-center justify-between text-xs text-gray-500">
                <div class="flex items-center space-x-4">
                    <span class="flex items-center">
                        <kbd class="px-2 py-1 bg-white border border-gray-300 rounded">↑↓</kbd>
                        <span class="ml-1">pour naviguer</span>
                    </span>
                    <span class="flex items-center">
                        <kbd class="px-2 py-1 bg-white border border-gray-300 rounded">Enter</kbd>
                        <span class="ml-1">pour ouvrir</span>
                    </span>
                    <span class="flex items-center">
                        <kbd class="px-2 py-1 bg-white border border-gray-300 rounded">Esc</kbd>
                        <span class="ml-1">pour fermer</span>
                    </span>
                </div>
                <div id="searchStats" class="text-gray-400"></div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-slideDown {
    animation: slideDown 0.2s ease-out;
}

.search-filter.active {
    background-color: #DBEAFE;
    color: #1E40AF;
}

.search-result-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #E5E7EB;
    cursor: pointer;
    transition: background-color 0.15s;
}

.search-result-item:hover,
.search-result-item.selected {
    background-color: #F3F4F6;
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-group-title {
    background-color: #F9FAFB;
    padding: 0.5rem 1rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #6B7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    position: sticky;
    top: 0;
    z-index: 10;
}

.result-icon {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.result-icon-ecriture { background-color: #DBEAFE; color: #1E40AF; }
.result-icon-compte { background-color: #D1FAE5; color: #065F46; }
.result-icon-tiers { background-color: #FEF3C7; color: #92400E; }
.result-icon-piece { background-color: #E0E7FF; color: #3730A3; }
.result-icon-journal { background-color: #FCE7F3; color: #831843; }

.result-badge {
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.result-badge-valide { background-color: #D1FAE5; color: #065F46; }
.result-badge-brouillon { background-color: #FEF3C7; color: #92400E; }
.result-badge-client { background-color: #DBEAFE; color: #1E40AF; }
.result-badge-fournisseur { background-color: #E0E7FF; color: #3730A3; }
</style>

<script src="https://cdn.jsdelivr.net/npm/fuse.js@7.0.0"></script>
<script>
// Gestion de la recherche globale
(function() {
    const modal = document.getElementById('searchModal');
    const input = document.getElementById('globalSearchInput');
    const closeBtn = document.getElementById('closeSearchModal');
    const filters = document.querySelectorAll('.search-filter');
    const resultsContainer = document.getElementById('searchResultsList');
    const initialState = document.getElementById('searchInitial');
    const loadingState = document.getElementById('searchLoading');
    const noResultsState = document.getElementById('searchNoResults');
    const statsElement = document.getElementById('searchStats');

    let currentModule = 'all';
    let searchTimeout = null;
    let selectedIndex = -1;
    let currentResults = [];
    let fuseInstance = null;
    let cachedData = [];

    // Historique de recherche
    const HISTORY_KEY = 'search_history';
    const MAX_HISTORY = 10;

    function getSearchHistory() {
        const history = localStorage.getItem(HISTORY_KEY);
        return history ? JSON.parse(history) : [];
    }

    function addToHistory(query) {
        if (!query || query.trim().length < 2) return;

        let history = getSearchHistory();
        history = history.filter(item => item !== query); // Supprimer les doublons
        history.unshift(query); // Ajouter au début
        history = history.slice(0, MAX_HISTORY); // Limiter la taille

        localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
    }

    // Ouvrir/fermer le modal
    function openSearchModal() {
        modal.classList.remove('hidden');
        input.focus();
        input.value = '';
        showHistoryOrInitial();
    }

    // Afficher l'historique ou l'état initial
    function showHistoryOrInitial() {
        const history = getSearchHistory();
        if (history.length > 0) {
            displaySearchHistory(history);
        } else {
            showInitialState();
        }
    }

    // Afficher l'historique de recherche
    function displaySearchHistory(history) {
        hideAllStates();
        resultsContainer.classList.remove('hidden');

        let html = '<div class="search-group-title">Recherches récentes</div>';

        history.forEach((query, index) => {
            html += `
                <div class="search-result-item flex items-center space-x-3 group" data-history-query="${query}">
                    <div class="result-icon" style="background-color: #F3F4F6; color: #6B7280;">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-700">${query}</p>
                    </div>
                    <button class="delete-history-btn opacity-0 group-hover:opacity-100 transition p-1 hover:bg-red-100 rounded" data-query="${query}">
                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;
        });

        resultsContainer.innerHTML = html;

        // Événements de clic sur l'historique
        document.querySelectorAll('[data-history-query]').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.closest('.delete-history-btn')) {
                    const query = this.dataset.historyQuery;
                    input.value = query;
                    performSearch(query);
                }
            });
        });

        // Événements de suppression
        document.querySelectorAll('.delete-history-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const query = this.dataset.query;
                removeFromHistory(query);
                showHistoryOrInitial();
            });
        });

        statsElement.textContent = `${history.length} recherche${history.length > 1 ? 's' : ''} récente${history.length > 1 ? 's' : ''}`;
    }

    function removeFromHistory(query) {
        let history = getSearchHistory();
        history = history.filter(item => item !== query);
        localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
    }

    function closeSearchModal() {
        modal.classList.add('hidden');
        input.value = '';
        selectedIndex = -1;
        currentResults = [];
    }

    // Raccourci clavier Ctrl+K ou Cmd+K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openSearchModal();
        }

        // Fermer avec Escape
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeSearchModal();
        }

        // Navigation avec flèches
        if (!modal.classList.contains('hidden')) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                navigateResults(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                navigateResults(-1);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                openSelectedResult();
            }
        }
    });

    closeBtn.addEventListener('click', closeSearchModal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeSearchModal();
        }
    });

    // Filtres
    filters.forEach(filter => {
        filter.addEventListener('click', function() {
            filters.forEach(f => f.classList.remove('active'));
            this.classList.add('active');
            currentModule = this.dataset.module;

            if (input.value.trim().length > 0) {
                performSearch(input.value.trim());
            }
        });
    });

    // Recherche avec debounce
    input.addEventListener('input', function() {
        const query = this.value.trim();

        clearTimeout(searchTimeout);

        if (query.length === 0) {
            showInitialState();
            return;
        }

        if (query.length < 2) {
            return;
        }

        showLoadingState();

        searchTimeout = setTimeout(() => {
            performSearch(query);
        }, 300);
    });

    // Effectuer la recherche
    function performSearch(query) {
        // Déterminer le chemin de base vers l'API (avec .php pour éviter le URL rewriting)
        const apiBasePath = window.location.pathname.includes('/pages/')
            ? '../../api/v1/search.php'
            : 'api/v1/search.php';

        fetch(`${apiBasePath}?q=${encodeURIComponent(query)}&module=${currentModule}&limit=50`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    addToHistory(query);
                    cachedData = data.results;

                    // Initialiser Fuse.js pour la recherche floue
                    if (cachedData.length > 0) {
                        const fuseOptions = {
                            keys: [
                                { name: 'display_text', weight: 2 },
                                { name: 'libelle', weight: 1.5 },
                                { name: 'nom', weight: 1.5 },
                                { name: 'intitule_compte', weight: 1.5 },
                                { name: 'numero_piece', weight: 1 },
                                { name: 'compte', weight: 1 },
                                { name: 'email', weight: 0.5 },
                                { name: 'telephone', weight: 0.5 }
                            ],
                            threshold: 0.4, // Plus tolérant aux fautes de frappe (0 = exact, 1 = tout correspond)
                            distance: 100,
                            minMatchCharLength: 2,
                            includeScore: true,
                            ignoreLocation: true,
                            useExtendedSearch: false
                        };

                        fuseInstance = new Fuse(cachedData, fuseOptions);

                        // Utiliser Fuse pour un classement amélioré
                        const fuseResults = fuseInstance.search(query);

                        // Combiner les résultats de l'API et de Fuse
                        if (fuseResults.length > 0) {
                            // Utiliser les résultats de Fuse avec leur score
                            data.results = fuseResults.map((result, index) => {
                                const item = result.item;
                                // Convertir le score Fuse (0 = parfait, 1 = mauvais) en score de pertinence (0-100)
                                item.relevance_score = Math.round((1 - result.score) * 100);
                                return item;
                            }).slice(0, 20);

                            // Regrouper à nouveau
                            const grouped = {};
                            data.results.forEach(result => {
                                const type = result.type;
                                if (!grouped[type]) {
                                    grouped[type] = [];
                                }
                                grouped[type].push(result);
                            });
                            data.grouped = grouped;
                            data.total = data.results.length;
                        }
                    }

                    displayResults(data);
                } else {
                    showNoResults();
                }
            })
            .catch(error => {
                console.error('Erreur de recherche:', error);
                showNoResults();
            });
    }

    // Afficher les résultats
    function displayResults(data) {
        if (data.total === 0) {
            showNoResults();
            return;
        }

        currentResults = data.results;
        selectedIndex = -1;

        hideAllStates();
        resultsContainer.classList.remove('hidden');

        let html = '';

        // Grouper les résultats par type
        const typeLabels = {
            'ecriture': 'Écritures comptables',
            'compte': 'Plan comptable',
            'tiers': 'Tiers (Clients/Fournisseurs)',
            'piece': 'Pièces justificatives',
            'journal': 'Journaux'
        };

        const typeIcons = {
            'ecriture': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
            'compte': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>',
            'tiers': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>',
            'piece': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>',
            'journal': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>'
        };

        for (const [type, results] of Object.entries(data.grouped)) {
            html += `<div class="search-group-title">${typeLabels[type] || type} (${results.length})</div>`;

            results.forEach((result, index) => {
                const globalIndex = currentResults.findIndex(r => r === result);
                html += `
                    <div class="search-result-item flex items-center space-x-3" data-index="${globalIndex}" data-url="${result.url}${result.id}">
                        <div class="result-icon result-icon-${type}">
                            ${typeIcons[type] || ''}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2">
                                <p class="text-sm font-medium text-gray-900 truncate">${result.display_text}</p>
                                ${result.statut ? `<span class="result-badge result-badge-${result.statut.toLowerCase()}">${result.statut}</span>` : ''}
                                ${result.tiers_type ? `<span class="result-badge result-badge-${result.tiers_type.toLowerCase()}">${result.tiers_type}</span>` : ''}
                            </div>
                            <p class="text-xs text-gray-500 truncate">
                                ${getResultMeta(result)}
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-400">${Math.round(result.relevance_score)}% pertinent</div>
                        </div>
                    </div>
                `;
            });
        }

        resultsContainer.innerHTML = html;

        // Attacher les événements de clic
        document.querySelectorAll('.search-result-item').forEach(item => {
            item.addEventListener('click', function() {
                window.location.href = this.dataset.url;
            });
        });

        // Afficher les stats
        statsElement.textContent = `${data.total} résultat${data.total > 1 ? 's' : ''} trouvé${data.total > 1 ? 's' : ''}`;
    }

    // Obtenir les métadonnées du résultat
    function getResultMeta(result) {
        switch (result.type) {
            case 'ecriture':
                return `${result.date_ecriture || ''} • ${result.montant_total ? result.montant_total + ' FCFA' : ''}`;
            case 'compte':
                return `Classe ${result.classe || ''} • ${result.compte_type || ''}`;
            case 'tiers':
                return `${result.email || ''} ${result.telephone ? '• ' + result.telephone : ''}`;
            case 'piece':
                return `${result.type_piece || ''} • ${result.date_upload || ''}`;
            case 'journal':
                return `${result.journal_type || ''}`;
            default:
                return '';
        }
    }

    // Navigation au clavier
    function navigateResults(direction) {
        if (currentResults.length === 0) return;

        selectedIndex += direction;

        if (selectedIndex < 0) {
            selectedIndex = currentResults.length - 1;
        } else if (selectedIndex >= currentResults.length) {
            selectedIndex = 0;
        }

        updateSelectedResult();
    }

    function updateSelectedResult() {
        document.querySelectorAll('.search-result-item').forEach((item, index) => {
            if (parseInt(item.dataset.index) === selectedIndex) {
                item.classList.add('selected');
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('selected');
            }
        });
    }

    function openSelectedResult() {
        if (selectedIndex >= 0 && currentResults[selectedIndex]) {
            const result = currentResults[selectedIndex];
            window.location.href = result.url + result.id;
        }
    }

    // États d'affichage
    function showInitialState() {
        hideAllStates();
        initialState.classList.remove('hidden');
        statsElement.textContent = '';
    }

    function showLoadingState() {
        hideAllStates();
        loadingState.classList.remove('hidden');
    }

    function showNoResults() {
        hideAllStates();
        noResultsState.classList.remove('hidden');
        statsElement.textContent = '0 résultat';
    }

    function hideAllStates() {
        initialState.classList.add('hidden');
        loadingState.classList.add('hidden');
        noResultsState.classList.add('hidden');
        resultsContainer.classList.add('hidden');
    }
})();
</script>
