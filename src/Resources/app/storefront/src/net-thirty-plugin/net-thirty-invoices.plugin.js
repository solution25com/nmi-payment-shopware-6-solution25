export default class NetThirtyInvoicesPlugin extends window.PluginBaseClass {

    init() {
        this._bulkPaymentUrl = this.el.dataset.bulkPaymentUrl || '';
        this._bindEvents();
        this._updateBulkActions();
    }

    _bindEvents() {
        const selectAll = this.el.querySelector('#selectAllCheckbox');
        if (selectAll) {
            selectAll.addEventListener('change', () => this._toggleSelectAll(selectAll));
        }

        this.el.querySelectorAll('.order-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                this._updateRowSelection(cb);
                this._updateBulkActions();
            });
        });

        const payBtn = this.el.querySelector('#paySelectedBtn');
        if (payBtn) {
            payBtn.addEventListener('click', () => this._paySelectedOrders());
        }
    }

    _updateRowSelection(checkbox) {
        const row = checkbox.closest('.account-invoice-row');
        if (row) {
            row.classList.toggle('selected', checkbox.checked);
        }
    }

    _updateBulkActions() {
        const checkedBoxes = this.el.querySelectorAll('.order-checkbox:checked');
        const allBoxes     = this.el.querySelectorAll('.order-checkbox');
        const count        = checkedBoxes.length;

        const bulkContainer = this.el.querySelector('#bulkActionsContainer');
        const countEl       = this.el.querySelector('#selectedCount');
        const totalEl       = this.el.querySelector('#selectedTotal');
        const payBtn        = this.el.querySelector('#paySelectedBtn');
        const selectAll     = this.el.querySelector('#selectAllCheckbox');

        if (countEl) {
            countEl.textContent = count;
        }

        let total = 0;
        let currencyCode = 'USD';

        checkedBoxes.forEach(cb => {
            const amountStr = cb.getAttribute('data-order-amount');
            if (!amountStr || amountStr === 'null' || amountStr === '0') return;
            const amount = parseFloat(amountStr);
            if (isNaN(amount) || amount <= 0) return;
            total += amount;
            const currency = cb.getAttribute('data-currency');
            if (currency) currencyCode = currency;
        });

        if (totalEl) {
            const fmt = new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currencyCode || 'USD',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
            totalEl.textContent = fmt.format(total / 100);
        }

        if (bulkContainer) {
            bulkContainer.style.display = count > 0 ? 'block' : 'none';
        }
        if (payBtn) {
            payBtn.disabled = count === 0;
        }

        if (selectAll) {
            selectAll.checked       = count === allBoxes.length && count > 0;
            selectAll.indeterminate = count > 0 && count < allBoxes.length;
        }

        this.el.querySelectorAll('.order-checkbox').forEach(cb => {
            if (!cb.checked) {
                const row = cb.closest('.account-invoice-row');
                if (row) row.classList.remove('selected');
            }
        });
    }

    _toggleSelectAll(checkbox) {
        this.el.querySelectorAll('.order-checkbox').forEach(cb => {
            cb.checked = checkbox.checked;
            this._updateRowSelection(cb);
        });
        this._updateBulkActions();
    }

    _paySelectedOrders() {
        const checkedBoxes = this.el.querySelectorAll('.order-checkbox:checked');

        if (checkedBoxes.length === 0) {
            alert('Please select at least one order to pay.');
            return;
        }

        const orderIds      = [];
        const invalidOrders = [];

        checkedBoxes.forEach(cb => {
            const row = cb.closest('.account-invoice-row');
            if (row && row.classList.contains('invoice-paid')) {
                invalidOrders.push(cb.getAttribute('data-order-number'));
            } else {
                orderIds.push(cb.value);
            }
        });

        if (invalidOrders.length > 0) {
            alert(
                'The following orders cannot be paid as they are already paid: '
                + invalidOrders.join(', ')
                + '\n\nPlease unselect these orders and try again.'
            );
            return;
        }

        if (orderIds.length === 0) {
            alert('No valid unpaid orders selected. Please select unpaid orders only.');
            return;
        }

        const payBtn = this.el.querySelector('#paySelectedBtn');
        if (payBtn) {
            payBtn.disabled  = true;
            payBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        }

        const params = new URLSearchParams();
        orderIds.forEach(id => params.append('orderIds[]', id));
        window.location.href = this._bulkPaymentUrl + '?' + params.toString();
    }
}
