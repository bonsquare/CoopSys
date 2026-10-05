// Dashboard page interactions (no build step needed).
(function () {
    // Export dropdown: open/close on click, close when clicking elsewhere
    const exportBtn = document.getElementById('exportDropdownBtn');
    const exportMenu = document.getElementById('exportDropdownMenu');

    if (exportBtn && exportMenu) {
        exportBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            exportMenu.classList.toggle('hidden');
        });
        document.addEventListener('click', () => exportMenu.classList.add('hidden'));
    }

    // Transaction table: search box + category filter
    const searchInput = document.getElementById('tableSearchInput');
    const filterSelect = document.getElementById('typeFilterSelect');
    const tableBody = document.getElementById('transactionTableBody');
    const rows = tableBody ? tableBody.querySelectorAll('tr') : [];

    function filterTable() {
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const selectedType = filterSelect ? filterSelect.value : 'All';

        rows.forEach((row) => {
            const text = row.innerText.toLowerCase();
            const matchesSearch = text.includes(query);
            const matchesCategory = selectedType === 'All' || text.includes(selectedType.toLowerCase());
            row.style.display = matchesSearch && matchesCategory ? '' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterTable);
    if (filterSelect) filterSelect.addEventListener('change', filterTable);
})();
