// Teller collection screen: member lookup, balances, payment breakdown, summary and keyboard handling.
(function () {
    const T = window.TELLER;
    const form = document.getElementById('collection-form');
    if (!T || !form) return;

    const $ = (id) => document.getElementById(id);
    const RO = T.readonly;
    const kinds = T.allocations;
    const body = $('breakdown-body');

    const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num = (v) => { const x = parseFloat(v); return isNaN(x) ? 0 : x; };
    const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
    const same = (a, b) => Math.abs(a - b) < 0.005;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    let account = null;     // the chosen member's accounts (from the server)
    let autoMode = true;    // true until the teller edits the breakdown by hand
    let suggestTimer = null;
    let unallocatedNote = 0;
    let amountTouched = false;   // true once the teller types the amount

    // ------------------------------------------------------------------
    // Clock
    // ------------------------------------------------------------------
    setInterval(() => { $('clock').textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }, 15000);

    // ------------------------------------------------------------------
    // Member search (type-ahead)
    // ------------------------------------------------------------------
    const search = $('member-search');
    const results = $('member-results');
    let items = [];
    let active = -1;
    let searchTimer = null;

    function hideResults() { results.classList.add('hidden'); active = -1; }

    function renderResults() {
        results.replaceChildren();
        if (!items.length) results.append(el('div', 'p-2 text-sm text-on-surface-variant', 'No member found'));
        items.forEach((m, i) => {
            const b = el('button', i === active ? 'active' : '', m.label + (m.status !== 'active' ? '  (inactive)' : ''));
            b.type = 'button';
            b.addEventListener('mousedown', (e) => { e.preventDefault(); chooseMember(m.id, m.label); });
            results.append(b);
        });
        results.classList.remove('hidden');
    }

    async function runSearch() {
        const res = await fetch(T.urls.members + '?q=' + encodeURIComponent(search.value.trim()), { headers: { Accept: 'application/json' } });
        items = await res.json();
        active = -1;
        renderResults();
    }

    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(runSearch, 200); });
    search.addEventListener('focus', () => { if (!RO) runSearch(); });
    search.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (results.classList.contains('hidden')) return runSearch();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % Math.max(items.length, 1);
            renderResults();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            if (!results.classList.contains('hidden') && items.length) {
                const m = items[active >= 0 ? active : 0];
                chooseMember(m.id, m.label);
            }
        } else if (e.key === 'Escape') {
            hideResults();
        }
    });
    document.addEventListener('click', (e) => { if (e.target !== search && !results.contains(e.target)) hideResults(); });

    function chooseMember(id, label) {
        search.value = label;
        hideResults();
        $('member_id').value = id;
        body.replaceChildren();
        autoMode = true;
        amountTouched = false;
        $('total_amount').value = '';
        loadAccount().then(() => { applyType(); $('total_amount').focus(); });
    }

    // ------------------------------------------------------------------
    // Left panel: member and balances
    // ------------------------------------------------------------------
    async function loadAccount() {
        const id = $('member_id').value;
        account = null;
        if (id) {
            const res = await fetch(T.urls.overview.replace('__ID__', id), { headers: { Accept: 'application/json' } });
            if (res.ok) account = await res.json();
        }
        renderAccount();
        body.querySelectorAll('tr').forEach((tr) => { fillLoanOptions(tr); refreshRow(tr); });
        recalc();
    }

    function setBalance(cardId, valueId, value, alert) {
        $(valueId).textContent = peso(value);
        $(cardId)?.classList.toggle('alert', !!alert);
    }

    function renderAccount() {
        const a = account;
        $('acc-name').textContent = a ? a.member.name : 'No member selected';
        $('acc-no').textContent = a ? a.member.member_no : '—';
        $('acc-avatar').textContent = a ? a.member.initials : '?';
        $('acc-contact').textContent = (a && a.member.contact) || '—';
        $('acc-address').textContent = (a && a.member.address) || '—';
        $('acc-joined').textContent = (a && a.member.joined) || '—';

        const status = $('acc-status');
        status.classList.toggle('hidden', !a);
        if (a) {
            status.textContent = a.member.status === 'active' ? 'ACTIVE' : 'INACTIVE';
            status.className = 'tl-badge ' + (a.member.status === 'active' ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-surface-container text-outline');
        }
        const flag = $('acc-flag');
        flag.classList.toggle('hidden', !(a && a.member.flag && a.member.flag !== 'INACTIVE'));
        if (a && a.member.flag) flag.textContent = '⚠ ' + a.member.flag;

        const ledger = $('acc-ledger');
        ledger.classList.toggle('hidden', !a);
        if (a) ledger.href = T.urls.ledger.replace('__ID__', a.member.id);

        const t = a ? a.totals : { balance: 0, principal: 0, interest_due: 0, penalty_due: 0, total_due: 0 };
        setBalance('card-loan', 'bal-loan', t.balance, false);
        setBalance('card-principal', 'bal-principal', t.principal, false);
        setBalance('card-interest', 'bal-interest', t.interest_due, t.interest_due > 0);
        setBalance('card-penalty', 'bal-penalty', t.penalty_due, t.penalty_due > 0);
        setBalance('card-total-due', 'bal-total-due', t.total_due, t.total_due > 0);
        $('bal-savings').textContent = peso(a ? a.savings : 0);
        $('bal-shares').textContent = peso(a ? a.shares_paid : 0);
        $('bal-available').textContent = peso(a ? a.available_withdrawal : 0);

        const list = $('loan-list');
        list.replaceChildren();
        if (!a) { list.append(el('span', 'text-on-surface-variant', 'Select a member.')); return; }
        if (!a.loans.length) { list.append(el('span', 'text-on-surface-variant', 'No active loans.')); return; }
        a.loans.forEach((l) => {
            const box = el('div', 'flex flex-wrap justify-between gap-2 py-1 border-b border-dotted border-outline-variant');
            const left = el('div');
            left.append(el('strong', 'font-mono text-primary', l.loan_no), el('span', 'text-[11px] text-outline ml-2', l.product || ''));
            const sub = el('div', 'text-[11px] ' + (l.past_due ? 'text-error font-semibold' : 'text-outline'),
                (l.past_due ? 'PAST DUE · ' : '') + 'Due now ' + peso(l.total_due) + ' · next ' + peso(l.payment) + (l.next_due ? ' on ' + l.next_due : '') + (l.penalty_due > 0 ? ' · penalty ' + peso(l.penalty_due) : ''));
            left.append(sub);
            box.append(left, el('strong', l.past_due ? 'text-error' : '', peso(l.balance)));
            list.append(box);
        });
    }

    // ------------------------------------------------------------------
    // Payment breakdown rows
    // ------------------------------------------------------------------
    function defaultType() {
        const t = T.types[$('collection_type').value];
        if (t && t.row) return t.row;
        return account && account.loans.length ? 'loan_principal' : 'savings_deposit';
    }

    function addRow(data) {
        data = data || {};
        const tr = document.createElement('tr');
        tr.dataset.loan = data.loan_id || '';
        tr.dataset.loanNo = data.loan_no || '';
        tr.dataset.stored = data.balance_after === undefined || data.balance_after === null ? '' : data.balance_after;
        tr.dataset.storedDue = data.due_amount === undefined || data.due_amount === null ? '' : data.due_amount;

        const type = el('select', 'input row-type');
        Object.entries(kinds).forEach(([key, k]) => type.add(new Option(k.label, key)));
        type.value = data.allocation_type || defaultType();

        const loan = el('select', 'input row-loan');
        const plain = el('span', 'row-text text-[12px] text-on-surface-variant');
        const due = el('td', 'text-right tabular-nums row-due');
        const dueInput = el('input'); dueInput.type = 'hidden'; dueInput.className = 'row-due-input';
        const amount = el('input', 'input amount-input text-right font-semibold');
        amount.type = 'number'; amount.step = '0.01'; amount.min = '0';
        amount.value = data.amount === undefined || data.amount === '' ? '' : data.amount;
        const after = el('td', 'text-right tabular-nums row-after text-on-surface-variant');
        const remove = el('button', 'btn btn-danger btn-sm', '✕');
        remove.type = 'button'; remove.title = 'Remove this row';

        const cell = (...nodes) => { const td = el('td'); td.append(...nodes); return td; };
        due.append(dueInput);
        tr.append(cell(type), cell(loan, plain), due, cell(amount), after, cell(remove));
        body.append(tr);

        type.addEventListener('change', () => { autoMode = false; fillLoanOptions(tr); refreshRow(tr, true); recalc(); });
        loan.addEventListener('change', () => { autoMode = false; refreshRow(tr, true); recalc(); });
        amount.addEventListener('input', () => { autoMode = false; recalc(); });
        remove.addEventListener('click', () => { autoMode = false; tr.remove(); recalc(); });

        if (RO) tr.querySelectorAll('input, select, button').forEach((n) => { n.disabled = true; });

        fillLoanOptions(tr);
        refreshRow(tr, data.amount === undefined || data.amount === '');
        return tr;
    }

    function fillLoanOptions(tr) {
        const loan = tr.querySelector('.row-loan');
        const wanted = loan.value || tr.dataset.loan;
        loan.replaceChildren(new Option('Choose loan…', ''));
        const loans = account ? account.loans : [];
        loans.forEach((l) => loan.add(new Option(l.loan_no + ' · ' + peso(l.balance), l.id)));

        // a loan on a saved receipt that is fully paid now is not in the active list: keep it visible
        if (wanted && !loans.some((l) => String(l.id) === String(wanted))) {
            loan.add(new Option(tr.dataset.loanNo || 'Loan #' + wanted, wanted));
        }
        if (wanted) loan.value = wanted;
        else if (loans.length === 1) loan.value = loans[0].id;
    }

    function loanFor(id) { return account ? account.loans.find((l) => String(l.id) === String(id)) : null; }

    /** What is due for this row (null when nothing applies). */
    function dueFor(type, loanId) {
        if (!account) return null;
        const l = loanFor(loanId);
        switch (type) {
            case 'loan_principal': return l ? l.suggest.principal : null;
            case 'loan_interest': return l ? l.suggest.interest : null;
            case 'loan_fees': return l ? l.suggest.fees : null;
            case 'loan_penalty': return l ? l.penalty_due : null;
            case 'savings_withdrawal': return account.available_withdrawal;
            case 'share_withdrawal': return account.share_withdrawal_allowed ? account.shares_paid : 0;
            case 'share_contribution': return account.shares_unpaid > 0 ? account.shares_unpaid : null;
            default: return null;
        }
    }

    function refreshRow(tr, fillAmount) {
        const type = tr.querySelector('.row-type').value;
        const meta = kinds[type] || {};
        const loan = tr.querySelector('.row-loan');
        const plain = tr.querySelector('.row-text');
        const isLoan = meta.kind === 'loan';

        loan.classList.toggle('hidden', !isLoan);
        plain.classList.toggle('hidden', isLoan);
        plain.textContent = { savings: 'Regular savings account', share: 'Share capital account', other: '—' }[meta.kind] || '';
        tr.dataset.loan = loan.value;

        const dueCell = tr.querySelector('.row-due');
        const dueInput = tr.querySelector('.row-due-input');
        let due = RO && tr.dataset.storedDue !== '' ? num(tr.dataset.storedDue) : dueFor(type, loan.value);
        dueInput.value = due === null || due === undefined ? '' : due;
        dueCell.firstChild && dueCell.firstChild !== dueInput ? dueCell.firstChild.remove() : null;
        const text = el('span', '', due === null || due === undefined ? '—' : peso(due));
        // overdue amounts are shown in red
        const l = loanFor(loan.value);
        const overdue = l && ((type === 'loan_penalty' && l.penalty_due > 0) || (['loan_principal', 'loan_interest', 'loan_fees'].includes(type) && l.due_now[type.replace('loan_', '')] > 0));
        text.className = overdue ? 'text-error font-bold' : '';
        dueCell.prepend(text);

        const amount = tr.querySelector('.amount-input');
        if (fillAmount && !RO && due > 0 && num(amount.value) === 0) {
            const A = num($('total_amount').value);
            const others = rowsData().filter((r) => r.tr !== tr);
            const room = A > 0 ? round2(A - others.reduce((s, r) => s + (r.meta.direction === 'out' ? -r.amount : r.amount), 0)) : due;
            amount.value = Math.max(Math.min(due, room > 0 ? room : due), 0) || '';
        }
    }

    function rowsData() {
        return [...body.querySelectorAll('tr')].map((tr) => {
            const type = tr.querySelector('.row-type').value;
            return { tr, type, meta: kinds[type] || {}, loanId: tr.querySelector('.row-loan').value || null, amount: round2(num(tr.querySelector('.amount-input').value)) };
        });
    }

    // ------------------------------------------------------------------
    // Summary, balances after, warnings and button states
    // ------------------------------------------------------------------
    function recalc() {
        const data = rowsData();
        let inSum = 0; let outSum = 0; let charges = 0; let dep = 0; let wd = 0;
        const loanBal = {}; const loanPen = {};
        let loanTotal = account ? account.totals.balance : null;
        let savings = account ? account.savings : null;
        let shares = account ? account.shares_paid : null;
        if (account) account.loans.forEach((l) => { loanBal[l.id] = l.balance; loanPen[l.id] = l.penalty_due; });

        const errors = []; const notes = [];
        const used = {};   // total applied per loan part, to check against what is owed

        data.forEach((r) => {
            const a = r.amount;
            if (r.meta.direction === 'out') outSum += a; else inSum += a;
            if (r.meta.charge) charges += a;

            let after = null;
            const l = loanFor(r.loanId);
            const part = r.type.replace('loan_', '');

            if (r.meta.kind === 'loan') {
                if (!r.loanId) { if (a > 0) errors.push(r.meta.label + ': choose the loan.'); }
                else if (l) {
                    const key = r.loanId + ':' + part;
                    used[key] = round2((used[key] || 0) + a);
                    const limit = { principal: l.principal_balance, interest: l.interest_balance, fees: l.fees_balance, penalty: l.penalty_due }[part];
                    if (used[key] > limit + 0.005) errors.push(l.loan_no + ': ' + r.meta.label.toLowerCase() + ' is more than owed (' + peso(limit) + ').');
                    if (part === 'penalty') { loanPen[r.loanId] = round2(loanPen[r.loanId] - a); after = loanPen[r.loanId]; }
                    else { loanBal[r.loanId] = round2(loanBal[r.loanId] - a); loanTotal = round2(loanTotal - a); after = loanBal[r.loanId]; }
                }
            } else if (r.type === 'savings_deposit') { dep += a; if (savings !== null) { savings = round2(savings + a); after = savings; } }
            else if (r.type === 'savings_withdrawal') { wd += a; if (savings !== null) { savings = round2(savings - a); after = savings; } }
            else if (r.type === 'share_contribution') { if (shares !== null) { shares = round2(shares + a); after = shares; } }
            else if (r.type === 'share_withdrawal') { if (shares !== null) { shares = round2(shares - a); after = shares; } }

            // what the row shows under "balance after" (a saved receipt shows what was recorded)
            const cell = r.tr.querySelector('.row-after');
            const stored = r.tr.dataset.stored;
            cell.textContent = RO && stored !== '' ? peso(num(stored)) : (after === null ? '—' : peso(after));

            // green when the due amount is fully covered
            const dueVal = r.tr.querySelector('.row-due-input').value;
            r.tr.classList.toggle('done', dueVal !== '' && a > 0 && same(a, num(dueVal)));
        });

        // Account-level warnings
        if (account) {
            if (wd > 0 && account.savings + dep - wd < account.min_balance - 0.005) {
                errors.push('Withdrawal exceeds the available balance (' + peso(Math.max(account.savings + dep - account.min_balance, 0)) + ').');
            }
            const shareOut = data.filter((r) => r.type === 'share_withdrawal').reduce((s, r) => s + r.amount, 0);
            if (shareOut > 0 && !account.share_withdrawal_allowed) errors.push('Share capital withdrawal is not allowed by the organization policy for this member.');
            else if (shareOut > 0 && shareOut > account.shares_paid + 0.005) errors.push('Share capital withdrawal is more than the amount paid in (' + peso(account.shares_paid) + ').');
            data.filter((r) => r.type === 'share_contribution').length && notes.push('Share capital contributions cannot exceed the unpaid subscription (' + peso(account.shares_unpaid) + ').');
        }
        if (unallocatedNote > 0.004 && autoMode) notes.push(peso(unallocatedNote) + ' could not be allocated automatically (nothing more is due). Add a row, e.g. a savings deposit, or lower the amount.');

        const net = round2(inSum - outSum);
        const A = num($('total_amount').value);
        const out = net < 0;

        // until the teller types an amount, the amount simply follows the breakdown
        if (!RO && !amountTouched && document.activeElement !== $('total_amount')) {
            $('total_amount').value = Math.abs(net) > 0 ? Math.abs(net) : '';
        }
        const amountNow = num($('total_amount').value);
        const balanced = amountNow > 0 && Math.abs(net) > 0.004 && same(amountNow, Math.abs(net));
        const remaining = round2(amountNow - Math.abs(net));

        // cash tendered and change
        const isCash = $('payment_method').value === 'cash';
        const tenderedBox = $('tendered-box');
        tenderedBox.classList.toggle('hidden', !(isCash && net > 0));
        $('ref-box').classList.toggle('hidden', isCash);
        const tendered = $('cash_tendered').value === '' ? Math.max(net, 0) : num($('cash_tendered').value);
        const change = isCash && net > 0 ? Math.max(round2(tendered - net), 0) : 0;
        if (isCash && net > 0 && tendered + 0.005 < net) errors.push('The cash received is less than the amount to collect.');

        $('amount-label').textContent = out ? 'Amount paid out' : 'Amount received';
        $('s-received').textContent = peso(amountNow);
        $('s-allocated').textContent = peso(Math.abs(net));
        $('s-remaining').textContent = peso(remaining);
        $('s-remaining').className = same(remaining, 0) ? '' : 'text-error';
        $('s-charges').textContent = peso(charges);
        $('s-in').textContent = peso(Math.max(net, 0));
        $('s-out').textContent = peso(Math.max(-net, 0));
        $('s-change').textContent = peso(change);
        $('s-loan').textContent = loanTotal === null ? '—' : peso(loanTotal);
        $('s-savings').textContent = savings === null ? '—' : peso(savings);
        $('s-shares').textContent = shares === null ? '—' : peso(shares);

        const badge = $('alloc-badge');
        if (!data.length && amountNow === 0) { badge.textContent = 'Nothing to allocate'; badge.className = 'tl-badge bg-surface-container text-on-surface-variant normal-case tracking-normal'; }
        else if (balanced) { badge.textContent = '✔ Allocation complete'; badge.className = 'tl-badge bg-secondary-fixed text-on-secondary-fixed normal-case tracking-normal'; }
        else if (remaining > 0) { badge.textContent = peso(remaining) + ' still to allocate'; badge.className = 'tl-badge bg-surface-container-high text-primary normal-case tracking-normal'; }
        else { badge.textContent = 'Over-allocated by ' + peso(Math.abs(remaining)); badge.className = 'tl-badge bg-error-container text-on-error-container normal-case tracking-normal'; }

        // messages
        const box = $('messages');
        box.replaceChildren();
        errors.forEach((m) => box.append(el('div', 'text-error font-semibold', '⚠ ' + m)));
        notes.forEach((m) => box.append(el('div', 'text-on-surface-variant', m)));

        // name the rows for the server and set button states
        data.forEach((r, i) => {
            r.tr.querySelector('.row-type').name = 'lines[' + i + '][allocation_type]';
            r.tr.querySelector('.row-loan').name = r.meta.kind === 'loan' ? 'lines[' + i + '][loan_id]' : '';
            r.tr.querySelector('.row-due-input').name = 'lines[' + i + '][due_amount]';
            r.tr.querySelector('.amount-input').name = 'lines[' + i + '][amount]';
        });

        const hasMember = !!$('member_id').value;
        if (!RO) {
            $('btn-post').disabled = !(hasMember && account && balanced && errors.length === 0);
            $('btn-draft').disabled = !(hasMember && data.some((r) => r.amount > 0));
        }
        return { net, balanced, errors };
    }

    // ------------------------------------------------------------------
    // Auto allocate / manual
    // ------------------------------------------------------------------
    async function autoAllocate() {
        if (RO) return;
        if (!account) { $('messages').replaceChildren(el('div', 'text-error font-semibold', '⚠ Select a member first.')); return; }
        const amount = num($('total_amount').value);
        if (!(amount > 0)) { $('messages').replaceChildren(el('div', 'text-error font-semibold', '⚠ Enter the amount received first.')); return; }

        const url = T.urls.suggest + '?member_id=' + account.member.id + '&amount=' + amount + '&type=' + encodeURIComponent($('collection_type').value);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();

        body.replaceChildren();
        data.rows.forEach((r) => addRow({ allocation_type: r.allocation_type, loan_id: r.loan_id, loan_no: r.loan_no, amount: r.amount }));
        unallocatedNote = data.unallocated;
        autoMode = true;
        recalc();
    }

    function manualAllocation() {
        if (RO) return;
        body.replaceChildren();
        autoMode = false;
        unallocatedNote = 0;
        addRow({ allocation_type: defaultType() });
        recalc();
        body.querySelector('.row-type')?.focus();
    }

    function scheduleAuto() {
        if (RO || !autoMode || !account) return;
        clearTimeout(suggestTimer);
        suggestTimer = setTimeout(() => { if (num($('total_amount').value) > 0) autoAllocate(); else { body.replaceChildren(); recalc(); } }, 300);
    }

    // The type decides what the first row looks like
    function applyType() {
        if (RO) return;
        const t = T.types[$('collection_type').value];
        if (autoMode && num($('total_amount').value) > 0 && account) return autoAllocate();
        if (!body.children.length && t && t.row) addRow({ allocation_type: t.row });
        recalc();
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------
    $('total_amount').addEventListener('input', () => { amountTouched = true; scheduleAuto(); recalc(); });
    $('cash_tendered').addEventListener('input', recalc);
    $('payment_method').addEventListener('change', recalc);
    $('collection_type').addEventListener('change', () => { body.replaceChildren(); autoMode = true; unallocatedNote = 0; applyType(); });
    $('btn-auto').addEventListener('click', autoAllocate);
    $('btn-manual').addEventListener('click', manualAllocation);
    $('btn-add-row').addEventListener('click', () => { autoMode = false; const tr = addRow({ allocation_type: defaultType() }); recalc(); tr.querySelector('.row-type').focus(); });

    // Enter moves to the next field instead of submitting
    form.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const t = e.target;
        if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.type === 'submit') return;
        e.preventDefault();
        const fields = [...form.querySelectorAll('input:not([type=hidden]):not([disabled]), select:not([disabled]), button:not([disabled])')].filter((n) => n.offsetParent !== null && n.id !== 'member-search');
        const next = fields[fields.indexOf(t) + 1];
        if (next) { next.focus(); if (next.select && next.tagName === 'INPUT') next.select(); }
    });

    // Confirm before posting; drop empty rows
    form.addEventListener('submit', (e) => {
        if (RO) { e.preventDefault(); return; }
        const action = e.submitter ? e.submitter.value : 'post';
        rowsData().filter((r) => r.amount <= 0).forEach((r) => r.tr.remove());
        const state = recalc();

        if (action === 'post') {
            const A = num($('total_amount').value);
            const ok = confirm('Post this transaction?\n\n' + $('acc-name').textContent + '\n' + (state.net < 0 ? 'Cash paid out: ' : 'Cash received: ') + peso(A) + '\n\nA posted receipt cannot be edited. It can only be reversed.');
            if (!ok) e.preventDefault();
        }
    });

    // Cancel a saved draft / start over, and reverse a posted receipt
    const cancelForm = $('cancel-form');
    function askReason(message) {
        const reason = (prompt(message) || '').trim();
        if (!reason) return;
        $('cancel-reason').value = reason;
        cancelForm.submit();
    }
    $('btn-cancel').addEventListener('click', () => {
        if (T.status === 'draft' && cancelForm) askReason('Cancel this draft receipt. Reason:');
        else if (!RO && confirm('Discard this transaction and start again?')) window.location = T.urls.create;
    });
    $('btn-reverse').addEventListener('click', () => { if (cancelForm) askReason('Reverse this posted receipt. Every line will be reversed. Reason:'); });

    // ------------------------------------------------------------------
    // Start up
    // ------------------------------------------------------------------
    const init = T.initial;
    amountTouched = init.amount !== '' && init.amount !== null && init.amount !== undefined;
    if (init.lines && init.lines.length) {
        init.lines.forEach((l) => addRow({ ...l, loan_no: l.loan_no }));
        autoMode = false;
    }
    if (RO) {
        form.querySelectorAll('input:not([type=hidden]), select').forEach((n) => { n.disabled = true; });
        ['btn-auto', 'btn-manual', 'btn-add-row', 'btn-draft', 'btn-post'].forEach((id) => { $(id).disabled = true; });
        $('btn-cancel').disabled = true;
        search.disabled = true;
        $('breakdown-tools').classList.add('opacity-40');
    }

    if (init.member_id) {
        loadAccount().then(() => {
            if (account) search.value = account.member.member_no + ' — ' + account.member.name;
            if (!RO && !init.lines.length) applyType();
            recalc();
        });
    } else {
        renderAccount();
        recalc();
    }
    $('payment_method').dispatchEvent(new Event('change'));
    if (!RO && !init.member_id) search.focus();
})();
