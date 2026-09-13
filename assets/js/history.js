// assets/js/history.js - Tunza Waleti History Page Logic

document.addEventListener('DOMContentLoaded', function () {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const searchInput = document.getElementById('historySearchInput');
    const transactionItems = document.querySelectorAll('.transaction-item');
    const countBadge = document.getElementById('transactionCountBadge');
    const noResults = document.getElementById('noResultsState');
    const btnExport = document.getElementById('btnExportHistory');

    let currentFilter = 'all';
    let currentSearch = '';

    // Filter and Search logic
    function filterTransactions() {
        let visibleCount = 0;

        transactionItems.forEach(item => {
            const category = item.getAttribute('data-category');
            const itemText = item.textContent.toLowerCase();

            const matchesCategory = (currentFilter === 'all' || category === currentFilter);
            const matchesSearch = (currentSearch === '' || itemText.includes(currentSearch));

            if (matchesCategory && matchesSearch) {
                item.style.display = 'flex';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // Update count badge
        if (countBadge) {
            countBadge.textContent = `${visibleCount} Item${visibleCount !== 1 ? 's' : ''}`;
        }

        // Show/hide empty state
        if (noResults) {
            if (visibleCount === 0) {
                noResults.style.display = 'flex';
            } else {
                noResults.style.display = 'none';
            }
        }
    }

    // Tab buttons event listener
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            currentFilter = this.getAttribute('data-filter');
            filterTransactions();
        });
    });

    // Search input event listener
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            currentSearch = this.value.trim().toLowerCase();
            filterTransactions();
        });
    }

    // Export Statement Button Alert
    if (btnExport) {
        btnExport.addEventListener('click', function () {
            alert('Downloading your Official Tunza Waleti Transaction Statement (PDF)...');
        });
    }
});
