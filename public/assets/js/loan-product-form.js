// Loan product form: add/remove deductions and switch off fields that do not apply.
(function () {
    const form = document.getElementById('product-form');
    if (!form) return;

    const $ = (id) => document.getElementById(id);

    // Turn a numeric box off (it is not sent; the server saves 0)
    function disableNumber(input, off) {
        input.disabled = off;
        if (off) input.value = 0;
    }

    // Turn a Yes/No box off but still send its value (disabled fields are not submitted)
    function lockSelect(select, value, off) {
        const marker = 'lock-' + select.id;
        let hidden = document.getElementById(marker);
        select.disabled = off;
        if (off) {
            select.value = value;
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.id = marker;
                hidden.name = select.name;
                select.after(hidden);
            }
            hidden.value = value;
        } else if (hidden) {
            hidden.remove();
        }
    }

    function applyRules() {
        // Interest-Free: the rate must be 0
        disableNumber($('interest_rate'), $('interest_type').value === 'interest_free');

        // Guarantor not required: the number must be 0
        disableNumber($('guarantors_required'), $('guarantor_required').value === '0');

        // No penalty type: the value must be 0
        disableNumber($('penalty_value'), $('penalty_type').value === 'none');

        // Early payment not allowed: no early payment penalty. No penalty: no penalty rate.
        lockSelect($('early_payment_penalty'), '0', $('allow_early_payment').value === '0');
        disableNumber($('early_payment_penalty_rate'), $('early_payment_penalty').value === '0');
    }

    ['interest_type', 'guarantor_required', 'penalty_type', 'allow_early_payment', 'early_payment_penalty'].forEach((id) => {
        $(id).addEventListener('change', applyRules);
    });

    // Deductions
    const list = $('deduction-list');
    const template = $('deduction-template');
    const empty = $('no-deductions');
    let nextIndex = list.querySelectorAll('[data-row]').length + 1000; // large start so it never clashes with saved rows

    function updateEmptyNote() {
        empty.classList.toggle('hidden', list.querySelectorAll('[data-row]').length > 0);
    }

    function applyDeductionRule(row) {
        const none = row.querySelector('[data-deduction-type]').value === 'none';
        disableNumber(row.querySelector('[data-deduction-value]'), none);
    }

    $('add-deduction').addEventListener('click', () => {
        list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', nextIndex++));
        applyDeductionRule(list.lastElementChild);
        updateEmptyNote();
    });

    list.addEventListener('click', (e) => {
        if (e.target.closest('[data-remove-row]')) {
            e.target.closest('[data-row]').remove();
            updateEmptyNote();
        }
    });

    list.addEventListener('change', (e) => {
        if (e.target.matches('[data-deduction-type]')) applyDeductionRule(e.target.closest('[data-row]'));
    });

    list.querySelectorAll('[data-row]').forEach(applyDeductionRule);
    applyRules();
})();
