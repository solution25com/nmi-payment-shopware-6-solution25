export default class NmiSavedCardsPlugin extends window.PluginBaseClass {
    init() {
        this.addCardButton = document.getElementById('addCardButton');
        this.paymentForm = document.getElementById('paymentForm');
        this.payButton = document.getElementById('payButton');

        if (this.addCardButton) {
            this.addCardButton.addEventListener('click', this.toggleForm.bind(this));
        }

        if (this.payButton) {
            this.payButton.addEventListener('click', () => {
                this.payButton.disabled = true;
            });
        }

        this.initCardActions();
    }

    toggleForm() {
        try {
            const isFormVisible = this.paymentForm.style.display === 'block';

            if (isFormVisible) {
                this.paymentForm.style.display = 'none';
                this.addCardButton.textContent = '+ Add Card';
            } else {
                this.paymentForm.style.display = 'block';
                this.addCardButton.textContent = '- Remove Card';

                CollectJS.configure({
                    callback: async (response) => {
                        try {
                            if (response.error) {
                                this.displayErrors(
                                    Array.isArray(response.error) ? response.error : [response.error]
                                );
                            } else {
                                await this.submitPaymentData(response);
                            }
                        } catch (error) {
                            this.displayErrors([error.message || 'Unexpected error in payment callback.']);
                        }
                    },
                    variant: 'inline',
                    googleFont: 'Abel',
                    invalidCss: { color: '#B40E3E' },
                    validCss: { color: '#175033' },
                    customCss: { 'border-color': '#FFFFFF', 'border-style': 'solid' },
                    focusCss: {
                        'border-color': '#3e79db',
                        'border-style': 'solid',
                        'border-width': '3px'
                    },
                    fields: {
                        ccnumber: { selector: '#ccnumber', title: 'Card Number', placeholder: '0000 0000 0000 0000' },
                        ccexp: { selector: '#ccexp', title: 'Card Expiration', placeholder: '01 / 25' },
                        cvv: { display: 'show', selector: '#cvv', title: 'CVV Code', placeholder: '***' }
                    }
                });
            }
        } catch (error) {
            this.displayErrors([error.message || 'Unexpected error toggling the payment form.']);
        }
    }


    async submitPaymentData(paymentResponse) {
        try {
            const form = document.querySelector('.theForm');
            const formData = {
                first_name: form.fname.value,
                last_name: form.lname.value,
                token: paymentResponse.token,
                ccnumber: paymentResponse.card.number,
                ccexp: paymentResponse.card.exp,
                card_type: paymentResponse.card.type,
                vaulted_customer_id: document.getElementById('delete-all').getAttribute('data-vault-id')
            };

            const response = await fetch('/nmi-add-card', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });

            const result = await response.json();

            if (result.success) {
                form.submit();
            } else {
                this.displayErrors(result.errors || ['Payment failed.']);
            }
        } catch (error) {
            this.displayErrors([error.message || 'Error submitting payment data.']);
        }
    }


    initCardActions() {
        const deleteButtons = document.querySelectorAll('.delete-card-btn');
        const setDefaultButtons = document.querySelectorAll('.set-default-btn');
        const deleteAllButton = document.getElementById('delete-all');

        if (deleteAllButton) {
            deleteAllButton.addEventListener('click', () => {
                const vaultId = deleteAllButton.getAttribute('data-vault-id');
                this.deleteCustomerVault(vaultId);
            });
        }

        deleteButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const billingId = button.getAttribute('data-billing-id');
                const vaultedId = button.closest('.credit-card').getAttribute('data-vaulted-id');
                this.deleteVaultedCustomerData(vaultedId, billingId);
            });
        });

        setDefaultButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const card = button.closest('.credit-card');
                const billingId = button.getAttribute('data-billing-id');
                const vaultedId = card.getAttribute('data-vaulted-id');
                this.setCardAsDefault(vaultedId, billingId);
            });
        });
    }

    deleteVaultedCustomerData(vaultedId, billingId) {
        fetch('/account/delete-billing-id', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ vaulted_customer_id: vaultedId, billing_id: billingId }),
        })
            .then(r => r.json())
            .then(() => window.location.reload())
            .catch(e => this.displayErrors([e.message || 'Error deleting billing.']));
    }

    setCardAsDefault(vaultedId, billingId) {
        fetch('/account/set-default-billing-id', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ vaulted_customer_id: vaultedId, billing_id: billingId }),
        })
            .then(r => r.json())
            .then(() => window.location.reload())
            .catch(e =>  this.displayErrors([e.message || 'Error setting billing as default.']));
    }

    deleteCustomerVault(vaultId) {
        fetch('/nmi-payment-delete-vaulted-customer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ customer_vault_id: vaultId }),
        })
            .then(r => r.json())
            .then(() => window.location.reload())
            .catch(e => this.displayErrors([e.message || 'Error deleting customer vault.']));
    }
}
