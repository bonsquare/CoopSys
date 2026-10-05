// Teller window: member lookup, account panel, tabs, quick amounts and live balance preview.
(function () {
    const form = document.getElementById('teller-form');
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const kinds = JSON.parse(form.dataset.kinds);
    const memberSelect = $('member_id');
    const search = $('member-search');
    const loanSelect = $('loan_id');
    const loanBox = $('loan-box');
    const amount = $('amount');
    const kindInput = $('kind');
    const panel = $('account-panel');
    const allOptions = Array.from(memberSelect.options).map((o) => ({ value: o.value, text: o.textContent }));

    let account = null; // the chosen member's account summary
    let kind = kindInput.value;

    const peso = (n) => '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // ---- Member search: narrows the dropdown as you type ----
    search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        const current = memberSelect.value;
        memberSelect.innerHTML = '';
        allOptions
            .filter((o) => o.value === '' || o.value === current || o.text.toLowerCase().includes(q))
            .forEach((o) => {
                const opt = new Option(o.text, o.value, false, o.value === current);
                memberSelect.add(opt);
            });
        // exactly one match: select it
        const matches = Array.from(memberSelect.options).filter((o) => o.value !== '');
        if (q && matches.length === 1 && memberSelect.value !== matches[0].value) {
            memberSelect.value = matches[0].value;
            loadAccount();
        }
    });

    // ---- Tabs ----
    function setKind(next) {
        kind = next;
        kindInput.value = next;
        document.querySelectorAll('.teller-tab').forEach((tab) => {
            const on = tab.dataset.kind === next;
            tab.classList.toggle('border-primary', on);
            tab.classList.toggle('text-primary', on);
            tab.classList.toggle('font-semibold', on);
            tab.classList.toggle('bg-surface-container-low', on);
            tab.classList.toggle('border-transparent', !on);
            tab.classList.toggle('text-on-surface-variant', !on);
        });
        loanBox.classList.toggle('hidden', next !== 'loan_payment');
        loanSelect.required = next === 'loan_payment';
        if (next !== 'loan_payment') loanSelect.value = '';
        $('submit-btn').textContent = 'Post ' + kinds[next];
        // a member with a single active loan: pick it for the teller
        if (next === 'loan_payment' && account && account.loans.length === 1 && !loanSelect.value) loanSelect.value = account.loans[0].id;
        renderQuickAmounts();
        updatePreview();
    }

    document.querySelectorAll('.teller-tab').forEach((tab) => tab.addEventListener('click', () => setKind(tab.dataset.kind)));

    // ---- Method: show the reference box for anything but cash ----
    $('payment_method').addEventListener('change', () => {
        $('ref-box').classList.toggle('hidden', $('payment_method').value === 'cash');
    });

    // ---- Account panel ----
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function row(label, value, valueClass) {
        const r = el('div', 'flex justify-between gap-2 py-1');
        r.append(el('span', 'text-on-surface-variant', label), el('strong', valueClass || '', value));
        return r;
    }

    function renderPanel() {
        panel.replaceChildren();
        if (!account) {
            panel.append(el('p', 'text-on-surface-variant', 'Choose a member to see their savings, shares and loans.'));
            return;
        }

        const head = el('div', 'mb-2');
        head.append(el('div', 'text-base font-bold', account.name), el('div', 'text-[11px] text-outline', account.member_no + (account.status !== 'active' ? ' · INACTIVE' : '')));
        panel.append(head);

        panel.append(row('Savings balance', peso(account.savings), 'text-lg text-primary'));
        if (account.min_balance > 0) panel.append(row('Minimum balance', peso(account.min_balance)));
        panel.append(row('Share capital paid', peso(account.shares_paid) + ' of ' + peso(account.shares_subscribed)));

        panel.append(el('div', 'field-label mt-3', 'Active loans'));
        if (!account.loans.length) {
            panel.append(el('p', 'text-on-surface-variant', 'No active loans.'));
        }
        account.loans.forEach((l) => {
            const box = el('div', 'border border-outline-variant rounded-lg p-2 mb-2');
            const top = el('div', 'flex justify-between');
            top.append(el('strong', 'text-primary', l.loan_no), el('strong', l.past_due ? 'text-error' : '', peso(l.balance)));
            box.append(top);
            box.append(el('div', 'text-[11px] text-outline', 'Payment ' + peso(l.payment) + (l.maturity ? ' · matures ' + l.maturity : '') + (l.past_due ? ' · PAST DUE' : '')));
            panel.append(box);
        });

        panel.append(el('div', 'field-label mt-3', 'Recent activity'));
        if (!account.recent.length) panel.append(el('p', 'text-on-surface-variant', 'No transactions yet.'));
        account.recent.forEach((t) => {
            const line = el('div', 'flex justify-between gap-2 text-[12px] py-0.5');
            line.append(el('span', 'text-on-surface-variant', t.date + ' · ' + t.label), el('strong', t.amount >= 0 ? 'text-secondary' : '', (t.amount >= 0 ? '+' : '-') + peso(Math.abs(t.amount))));
            panel.append(line);
        });

        const link = el('a', 'btn btn-secondary btn-sm mt-3', 'Open statement');
        link.href = account.statement_url;
        panel.append(link);
    }

    async function loadAccount() {
        const id = memberSelect.value;
        account = null;
        loanSelect.innerHTML = '<option value="">Choose a member first</option>';
        if (!id) { renderPanel(); updatePreview(); return; }

        const res = await fetch(form.dataset.accountUrl.replace('__ID__', id), { headers: { 'Accept': 'application/json' } });
        if (!res.ok || memberSelect.value !== id) return;
        account = await res.json();

        loanSelect.innerHTML = account.loans.length
            ? '<option value="">Choose loan…</option>'
            : '<option value="">This member has no active loan</option>';
        account.loans.forEach((l) => {
            const opt = new Option(l.loan_no + ' — balance ' + peso(l.balance), l.id);
            loanSelect.add(opt);
        });
        if (form.dataset.initialLoan) { loanSelect.value = form.dataset.initialLoan; form.dataset.initialLoan = ''; }
        if (kind === 'loan_payment' && account.loans.length === 1 && !loanSelect.value) loanSelect.value = account.loans[0].id;

        renderPanel();
        renderQuickAmounts();
        updatePreview();
    }

    function chosenLoan() {
        return account ? account.loans.find((l) => String(l.id) === loanSelect.value) : null;
    }

    // ---- Quick amount buttons ----
    function renderQuickAmounts() {
        const box = $('quick-amounts');
        box.replaceChildren();
        const add = (text, value) => {
            const b = el('button', 'btn btn-secondary btn-sm', text);
            b.type = 'button';
            b.addEventListener('click', () => { amount.value = value.toFixed(2); updatePreview(); });
            box.append(b);
        };
        if (!account) return;

        if (kind === 'loan_payment') {
            const loan = chosenLoan();
            if (loan) {
                add('Installment ' + peso(Math.min(loan.payment, loan.balance)), Math.min(loan.payment, loan.balance));
                add('Pay in full ' + peso(loan.balance), loan.balance);
            }
        } else if (kind === 'savings_withdrawal') {
            const max = Math.max(account.savings - account.min_balance, 0);
            if (max > 0) add('Withdraw all available ' + peso(max), max);
        }
    }

    // ---- Live "balance after" preview ----
    function updatePreview() {
        const value = parseFloat(amount.value) || 0;
        const warning = $('preview-warning');
        warning.classList.add('hidden');

        let label = 'Savings balance';
        let before = null;
        let after = null;
        let change = '—';

        if (account) {
            if (kind === 'savings_deposit') { before = account.savings; after = before + value; change = '+' + peso(value); }
            if (kind === 'savings_withdrawal') {
                before = account.savings; after = before - value; change = '-' + peso(value);
                if (value > before) { warning.textContent = 'Not enough savings for this withdrawal.'; warning.classList.remove('hidden'); }
                else if (value > 0 && after < account.min_balance) { warning.textContent = 'The account must keep a minimum balance of ' + peso(account.min_balance) + '.'; warning.classList.remove('hidden'); }
            }
            if (kind === 'loan_payment') {
                label = 'Loan balance';
                const loan = chosenLoan();
                if (loan) {
                    before = loan.balance; after = before - value; change = '-' + peso(value);
                    if (value > before) { warning.textContent = 'The payment is more than the loan balance.'; warning.classList.remove('hidden'); }
                }
            }
        }

        $('preview-label').textContent = label + ' before';
        $('preview-after-label').textContent = label + ' after';
        $('preview-before').textContent = before === null ? '—' : peso(before);
        $('preview-change').textContent = before === null ? '—' : change;
        $('preview-after').textContent = after === null ? '—' : peso(after);
    }

    // ---- Wire up ----
    memberSelect.addEventListener('change', loadAccount);
    loanSelect.addEventListener('change', () => { renderQuickAmounts(); updatePreview(); });
    amount.addEventListener('input', updatePreview);

    form.addEventListener('submit', (e) => {
        const who = memberSelect.options[memberSelect.selectedIndex].text;
        const ok = confirm('Post ' + kinds[kind].toLowerCase() + ' of ' + peso(parseFloat(amount.value) || 0) + ' for ' + who + '?');
        if (!ok) e.preventDefault();
    });

    setKind(kinds[kind] ? kind : Object.keys(kinds)[0]);
    $('payment_method').dispatchEvent(new Event('change'));
    if (memberSelect.value) loadAccount();
})();
