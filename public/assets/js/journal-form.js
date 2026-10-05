// Journal entry form: add/remove lines and show whether the entry balances.
(function () {
    const form = document.getElementById('journal-form');
    if (!form) return;

    const body = document.getElementById('lines-body');
    const template = document.getElementById('line-template');
    const peso = (n) => '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num = (v) => { const x = parseFloat(v); return isNaN(x) ? 0 : x; };
    let next = body.querySelectorAll('[data-line]').length + 1000;   // never clashes with the lines already on the page

    function recalc() {
        let debit = 0;
        let credit = 0;
        let problem = '';

        body.querySelectorAll('[data-line]').forEach((row) => {
            const d = num(row.querySelector('[data-debit]').value);
            const c = num(row.querySelector('[data-credit]').value);
            debit += d;
            credit += c;
            if (d > 0 && c > 0) problem = 'A line cannot have both a debit and a credit.';
        });

        debit = Math.round(debit * 100) / 100;
        credit = Math.round(credit * 100) / 100;
        const diff = Math.round((debit - credit) * 100) / 100;
        const note = document.getElementById('balance-note');

        document.getElementById('total-debit').textContent = peso(debit);
        document.getElementById('total-credit').textContent = peso(credit);

        const accountsChosen = [...body.querySelectorAll('select')].filter((s, i) => {
            const row = s.closest('[data-line]');
            return num(row.querySelector('[data-debit]').value) > 0 || num(row.querySelector('[data-credit]').value) > 0;
        }).every((s) => s.value !== '');

        let ok = false;
        if (problem) { note.textContent = '⚠ ' + problem; note.className = 'text-sm font-semibold text-error'; }
        else if (debit === 0 && credit === 0) { note.textContent = 'Enter amounts on at least two lines.'; note.className = 'text-sm text-on-surface-variant'; }
        else if (diff !== 0) { note.textContent = '⚠ Out of balance by ' + peso(Math.abs(diff)) + (diff > 0 ? ' (more debits)' : ' (more credits)'); note.className = 'text-sm font-semibold text-error'; }
        else if (!accountsChosen) { note.textContent = 'Choose an account for every line that has an amount.'; note.className = 'text-sm text-on-surface-variant'; }
        else { note.textContent = '✔ Balanced'; note.className = 'text-sm font-semibold text-secondary'; ok = true; }

        document.getElementById('post-btn').disabled = !ok;
    }

    // Typing a debit clears the credit on the same line, and the other way round
    body.addEventListener('input', (e) => {
        const row = e.target.closest('[data-line]');
        if (row && e.target.matches('[data-debit]') && num(e.target.value) > 0) row.querySelector('[data-credit]').value = '';
        if (row && e.target.matches('[data-credit]') && num(e.target.value) > 0) row.querySelector('[data-debit]').value = '';
        recalc();
    });
    body.addEventListener('change', recalc);

    body.addEventListener('click', (e) => {
        if (!e.target.closest('[data-remove]')) return;
        if (body.querySelectorAll('[data-line]').length <= 2) return;   // keep at least two lines
        e.target.closest('[data-line]').remove();
        recalc();
    });

    document.getElementById('add-line').addEventListener('click', () => {
        body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', next++));
        body.lastElementChild.querySelector('select').focus();
        recalc();
    });

    form.addEventListener('submit', (e) => {
        if (document.getElementById('post-btn').disabled) { e.preventDefault(); return; }
        if (!confirm('Post this journal entry? It cannot be edited afterwards, only reversed.')) e.preventDefault();
    });

    recalc();
})();
