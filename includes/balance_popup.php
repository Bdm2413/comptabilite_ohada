<!-- Modal Balance Générale -->
<div id="balanceModal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-slate-800 rounded-xl border border-slate-700 w-full max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
        <!-- Header -->
        <div class="p-6 border-b border-slate-700 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-white flex items-center gap-2" id="balanceModalTitle">
                    <i class="fas fa-list text-cyan-400"></i>
                    Balance Générale
                </h2>
                <p class="text-sm text-slate-400 mt-1" id="balancePeriode">Chargement...</p>
            </div>
            <div class="flex items-center gap-2">
                <!-- Boutons export (visibles uniquement après chargement des données) -->
                <button id="balanceBtnExportExcel" onclick="exportBalanceExcel()" title="Exporter en Excel"
                    class="hidden items-center gap-1 px-2 py-1 text-xs bg-emerald-700 hover:bg-emerald-600 text-white rounded-lg transition-colors">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button id="balanceBtnExportPDF" onclick="exportBalancePDF()" title="Exporter en PDF"
                    class="hidden items-center gap-1 px-2 py-1 text-xs bg-rose-700 hover:bg-rose-600 text-white rounded-lg transition-colors">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button onclick="closeBalanceModal()" class="text-slate-400 hover:text-white transition-colors ml-2">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
        </div>

        <!-- Loading State -->
        <div id="balanceLoading" class="flex-1 flex items-center justify-center">
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-cyan-400 mb-4"></i>
                <p class="text-slate-400">Chargement de la balance...</p>
            </div>
        </div>

        <!-- Content -->
        <div id="balanceContent" class="hidden flex-1 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900/50 sticky top-0">
                    <tr class="border-b border-slate-700">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Compte</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-300 uppercase">Intitulé</th>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-slate-300 uppercase">Rubrique</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-300 uppercase">Débit</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-300 uppercase">Crédit</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-300 uppercase">Solde</th>
                    </tr>
                </thead>
                <tbody id="balanceTableBody" class="divide-y divide-slate-700">
                    <!-- Les lignes seront insérées ici par JavaScript -->
                </tbody>
                <tfoot class="bg-slate-900/50 sticky bottom-0">
                    <tr class="border-t-2 border-slate-600 font-bold">
                        <td colspan="3" class="px-4 py-3 text-white uppercase">Total Général</td>
                        <td id="balanceTotalDebit" class="px-4 py-3 text-right text-blue-400"></td>
                        <td id="balanceTotalCredit" class="px-4 py-3 text-right text-green-400"></td>
                        <td class="px-4 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Error State -->
        <div id="balanceError" class="hidden flex-1 flex items-center justify-center">
            <div class="text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
                <p class="text-slate-400" id="balanceErrorMessage">Une erreur est survenue</p>
                <button onclick="loadBalance()" class="mt-4 px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg transition">
                    <i class="fas fa-redo mr-2"></i>Réessayer
                </button>
            </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-700 flex justify-end gap-2">
            <button onclick="closeBalanceModal()" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                Fermer
            </button>
        </div>
    </div>
</div>

<script>
// Variables globales pour la balance
let balanceData = null;

// Ouvrir le modal de la balance
function openBalanceModal() {
    // Adapter le titre selon le filtre actif
    const titleEl = document.getElementById('balanceModalTitle');
    if (titleEl) {
        const filter = (typeof window.balanceTableauFilter !== 'undefined') ? window.balanceTableauFilter : '';
        if (filter === 'Bilan') {
            titleEl.innerHTML = '<i class="fas fa-list text-cyan-400"></i> Balance – Comptes de Bilan';
        } else if (filter === 'Resultat') {
            titleEl.innerHTML = '<i class="fas fa-list text-cyan-400"></i> Balance – Comptes de Résultat';
        } else {
            titleEl.innerHTML = '<i class="fas fa-list text-cyan-400"></i> Balance Générale';
        }
    }
    document.getElementById('balanceModal').classList.remove('hidden');
    loadBalance();
}

// Fermer le modal de la balance
function closeBalanceModal() {
    document.getElementById('balanceModal').classList.add('hidden');
}

// Charger les données de la balance
function loadBalance() {
    const dateDebut = document.querySelector('input[name="date_debut"]')?.value || '<?= date('Y-01-01') ?>';
    const dateFin = document.querySelector('input[name="date_fin"]')?.value || '<?= date('Y-m-d') ?>';

    // Masquer les boutons export pendant le chargement
    document.getElementById('balanceBtnExportExcel').classList.add('hidden');
    document.getElementById('balanceBtnExportExcel').classList.remove('inline-flex');
    document.getElementById('balanceBtnExportPDF').classList.add('hidden');
    document.getElementById('balanceBtnExportPDF').classList.remove('inline-flex');

    // Afficher le loading
    document.getElementById('balanceLoading').classList.remove('hidden');
    document.getElementById('balanceContent').classList.add('hidden');
    document.getElementById('balanceError').classList.add('hidden');

    // Filtre optionnel sur le tableau (défini par chaque page hôte via window.balanceTableauFilter)
    const tableauFilter = (typeof window.balanceTableauFilter !== 'undefined' && window.balanceTableauFilter)
        ? `&tableau=${encodeURIComponent(window.balanceTableauFilter)}`
        : '';

    // Appel API
    fetch(`../../api/get_balance.php?date_debut=${dateDebut}&date_fin=${dateFin}${tableauFilter}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                balanceData = data;
                displayBalance(data);
            } else {
                showBalanceError(data.error || 'Erreur inconnue');
            }
        })
        .catch(error => {
            showBalanceError('Erreur de connexion au serveur');
            console.error('Erreur:', error);
        });
}

// Afficher les données de la balance
function displayBalance(data) {
    // Masquer le loading
    document.getElementById('balanceLoading').classList.add('hidden');
    document.getElementById('balanceContent').classList.remove('hidden');

    // Afficher les boutons export
    const btnExcel = document.getElementById('balanceBtnExportExcel');
    const btnPDF = document.getElementById('balanceBtnExportPDF');
    btnExcel.classList.remove('hidden');
    btnExcel.classList.add('inline-flex');
    btnPDF.classList.remove('hidden');
    btnPDF.classList.add('inline-flex');

    // Mettre à jour la période
    const dateDebut = new Date(data.periode.debut).toLocaleDateString('fr-FR');
    const dateFin = new Date(data.periode.fin).toLocaleDateString('fr-FR');
    document.getElementById('balancePeriode').textContent = `Période du ${dateDebut} au ${dateFin}`;

    // Remplir le tableau
    const tbody = document.getElementById('balanceTableBody');
    tbody.innerHTML = '';

    data.comptes.forEach(compte => {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-700/30 transition-colors';

        const solde = compte.solde;
        const soldeClass = solde > 0 ? 'text-blue-400' : solde < 0 ? 'text-red-400' : 'text-slate-500';

        // Déterminer la rubrique selon la nature du solde et le tableau (Bilan ou Résultat)
        let rubrique = '-';
        let rubriqueColor = 'text-slate-500';
        if (solde > 0.01) {
            // Solde débiteur → BD (bilan) ou RD (résultat)
            rubrique = compte.bd || compte.rd || '-';
            rubriqueColor = 'text-cyan-400';
        } else if (solde < -0.01) {
            // Solde créditeur → BC (bilan) ou RC (résultat)
            rubrique = compte.bc || compte.rc || '-';
            rubriqueColor = 'text-purple-400';
        }

        // Créer le lien vers le grand livre
        const dateDebut = data.periode.debut;
        const dateFin = data.periode.fin;
        const grandLivreUrl = `../rapports/grand_livre.php?compte=${encodeURIComponent(compte.compte)}&date_debut=${dateDebut}&date_fin=${dateFin}`;

        row.innerHTML = `
            <td class="px-4 py-2 font-mono">
                <a href="${grandLivreUrl}" class="text-cyan-400 hover:text-cyan-300 hover:underline transition-colors" title="Voir le grand livre">
                    ${compte.compte}
                </a>
            </td>
            <td class="px-4 py-2 text-slate-300">${compte.intitule_compte}</td>
            <td class="px-3 py-2 text-center text-xs font-mono ${rubriqueColor}">${rubrique}</td>
            <td class="px-4 py-2 text-right font-mono ${compte.total_debit > 0 ? 'text-blue-400' : 'text-slate-500'}">
                ${compte.total_debit > 0 ? formatNumber(compte.total_debit) : ''}
            </td>
            <td class="px-4 py-2 text-right font-mono ${compte.total_credit > 0 ? 'text-green-400' : 'text-slate-500'}">
                ${compte.total_credit > 0 ? formatNumber(compte.total_credit) : ''}
            </td>
            <td class="px-4 py-2 text-right font-mono font-semibold ${soldeClass}">
                ${Math.abs(solde) > 0.01 ? formatNumber(Math.abs(solde)) + (solde > 0 ? ' D' : ' C') : '-'}
            </td>
        `;

        tbody.appendChild(row);
    });

    // Mettre à jour les totaux
    document.getElementById('balanceTotalDebit').textContent = formatNumber(data.totaux.debit);
    document.getElementById('balanceTotalCredit').textContent = formatNumber(data.totaux.credit);
}

// Afficher une erreur
function showBalanceError(message) {
    document.getElementById('balanceLoading').classList.add('hidden');
    document.getElementById('balanceContent').classList.add('hidden');
    document.getElementById('balanceError').classList.remove('hidden');
    document.getElementById('balanceErrorMessage').textContent = message;
}

// Formater un nombre
function formatNumber(number) {
    return new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(number);
}

// Exporter la balance en PDF
function exportBalancePDF() {
    const dateDebut = document.querySelector('input[name="date_debut"]')?.value || '<?= date('Y-01-01') ?>';
    const dateFin = document.querySelector('input[name="date_fin"]')?.value || '<?= date('Y-m-d') ?>';
    const params = new URLSearchParams();
    params.set('date_debut', dateDebut);
    params.set('date_fin', dateFin);
    if (typeof window.balanceTableauFilter !== 'undefined' && window.balanceTableauFilter) {
        params.set('tableau', window.balanceTableauFilter);
    }
    window.open('../../pages/rapports/export_balance_generale_pdf.php?' + params.toString(), '_blank');
}

// Exporter la balance en Excel
function exportBalanceExcel() {
    const dateDebut = document.querySelector('input[name="date_debut"]')?.value || '<?= date('Y-01-01') ?>';
    const dateFin = document.querySelector('input[name="date_fin"]')?.value || '<?= date('Y-m-d') ?>';
    const params = new URLSearchParams();
    params.set('date_debut', dateDebut);
    params.set('date_fin', dateFin);
    if (typeof window.balanceTableauFilter !== 'undefined' && window.balanceTableauFilter) {
        params.set('tableau', window.balanceTableauFilter);
    }
    window.open('../../pages/rapports/export_balance_generale_excel.php?' + params.toString(), '_blank');
}

// Fermer le modal avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBalanceModal();
    }
});

// Fermer le modal en cliquant en dehors
document.getElementById('balanceModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeBalanceModal();
    }
});
</script>
