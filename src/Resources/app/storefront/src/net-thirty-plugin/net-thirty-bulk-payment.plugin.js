export default class NetThirtyBulkPaymentPlugin extends window.PluginBaseClass {

    init() {
        this._readOptions();
        this._overridePaymentRequest();
        this._collectJsMode   = null;
        this._collectJsLoaded = false;

        this._registerElements();
        this._registerEvents();
        this._setInitialState();
    }

    _readOptions() {
        const d = this.el.dataset;
        this._hasSavedCards           = d.hasSavedCards === '1';
        this._orderIds                = JSON.parse(d.orderIds || '[]');
        this._enableCreditCardPayment = d.enableCreditCard === '1';
        this._publicKey               = d.publicKey || '';
        this._totalAmount             = parseFloat(d.totalAmount || '0');
        this._urlProcessNmi           = d.urlProcessNmi || '';
        this._urlProcessAch           = d.urlProcessAch || '';
        this._urlProcessNmiToken      = d.urlProcessNmiToken || '';
        this._urlInvoices             = d.urlInvoices || '';
    }

    _registerElements() {
        this._payButton        = this.el.querySelector('#payButton');
        this._newCardPayButton = this.el.querySelector('#newCardPayButton');
        this._achPayButton     = this.el.querySelector('#achPayButton');
        this._loaderWrapper    = this.el.querySelector('#loaderWrapper');
        this._creditCardSection = this.el.querySelector('#creditCardSection');
        this._achSection       = this.el.querySelector('#achSection');
        this._ccFormVisible    = false;
    }

    _registerEvents() {
        // payment-type toggle (CC ↔ ACH)
        this.el.querySelectorAll('input[name="paymentType"]').forEach(radio => {
            radio.addEventListener('change', () => this._onPaymentTypeChange(radio.value));
        });

        // saved card tile selection
        if (this._enableCreditCardPayment) {
            this.el.querySelectorAll('#creditCardSection .credit-card').forEach(tile => {
                tile.addEventListener('click', () => this._onCardTileClick(tile));
            });
        }

        // pay saved card
        if (this._payButton && this._enableCreditCardPayment) {
            this._payButton.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                this._onPayButtonClick();
            });
        }

        // pay with new card
        if (this._newCardPayButton) {
            this._newCardPayButton.addEventListener('click', e => {
                e.preventDefault();
                this._onNewCardPayButtonClick();
            });
        }

        // ACH pay
        if (this._achPayButton) {
            this._achPayButton.addEventListener('click', () => this._processACHPayment());
        }

        // prevent accidental form submit
        this.el.addEventListener('submit', e => {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            return false;
        });
    }

    _setInitialState() {
        if (!this._enableCreditCardPayment) {
            if (this._creditCardSection) this._creditCardSection.style.display = 'none';
            if (this._achSection)        this._achSection.style.display = 'block';
            if (this._payButton)         this._payButton.style.display = 'none';
            if (this._newCardPayButton)  this._newCardPayButton.style.display = 'none';
            if (this._achPayButton)      this._achPayButton.style.display = 'inline-block';
            this._initACHForm();
            return;
        }

        const defaultRadio = this.el.querySelector('input[name="paymentType"]:checked');
        if (defaultRadio && defaultRadio.value === 'credit_card') {
            if (this._hasSavedCards) {
                if (this._payButton) this._payButton.style.display = 'inline-block';
                // pre-select first saved card tile
                const firstCardRadio = this.el.querySelector('input[name="paymentMethod"]');
                if (firstCardRadio) {
                    firstCardRadio.checked = true;
                    const tile = firstCardRadio.closest('.credit-card');
                    if (tile) {
                        tile.classList.add('selected');
                        const mark = tile.querySelector('.card-selected-mark');
                        if (mark) mark.classList.remove('d-none');
                    }
                }
            } else {
                // no saved cards — form is visible on load, init CollectJS now
                if (this._newCardPayButton) this._newCardPayButton.style.display = 'inline-block';
                this._ccFormVisible = true;
                this._initCCForm();
            }
        } else {
            if (this._achPayButton) this._achPayButton.style.display = 'inline-block';
            this._initACHForm();
        }
    }

    _overridePaymentRequest() {
        window.addEventListener('error', e => {
            if (
                e.message &&
                e.message.includes('ApplePayRequest') &&
                e.message.includes('PaymentRequestAbstraction')
            ) {
                e.preventDefault();
                console.warn('Apple Pay initialization skipped on bulk invoices page');
                return true;
            }
        }, true);

        if (!window.PaymentRequest) return;

        try {
            const DisabledPaymentRequest = function () {
                console.warn('PaymentRequest is disabled on this bulk payment page');
                return {
                    canMakePayment:    () => Promise.resolve(false),
                    show:              () => Promise.reject(new DOMException('PaymentRequest is disabled on this page', 'AbortError')),
                    abort:             () => Promise.resolve(),
                    addEventListener:    () => {},
                    removeEventListener: () => {},
                };
            };
            window.PaymentRequest = DisabledPaymentRequest;
        } catch (err) {
            console.warn('Failed to disable PaymentRequest on bulk payment page', err);
        }
    }

    _loadCollectJS() {
        if (this._collectJsLoaded || typeof CollectJS !== 'undefined') {
            return Promise.resolve();
        }
        if (this._collectJsLoadPromise) {
            return this._collectJsLoadPromise;
        }
        this._collectJsLoadPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://secure.nmi.com/token/Collect.js';
            script.setAttribute('data-tokenization-key', this._publicKey);
            script.onload = () => {
                this._collectJsLoaded = true;
                resolve();
            };
            script.onerror = () => reject(new Error('Failed to load CollectJS'));
            document.head.appendChild(script);
        });
        return this._collectJsLoadPromise;
    }

    _onPaymentTypeChange(value) {
        if (!this._enableCreditCardPayment) return;

        if (value === 'credit_card') {
            if (this._creditCardSection) this._creditCardSection.style.display = 'block';
            if (this._achSection)        this._achSection.style.display = 'none';
            if (this._achPayButton)      this._achPayButton.style.display = 'none';

            if (this._hasSavedCards) {
                if (this._payButton) this._payButton.style.display = 'inline-block';
            } else {
                if (this._newCardPayButton) this._newCardPayButton.style.display = 'inline-block';
                if (!this._ccFormVisible) {
                    this._ccFormVisible = true;
                    this._initCCForm();
                }
            }
        } else if (value === 'ach') {
            if (this._creditCardSection) this._creditCardSection.style.display = 'none';
            if (this._achSection)        this._achSection.style.display = 'block';
            if (this._payButton)         this._payButton.style.display = 'none';
            if (this._newCardPayButton)  this._newCardPayButton.style.display = 'none';
            if (this._achPayButton)      this._achPayButton.style.display = 'inline-block';
            this._initACHForm();
        }
    }

    _onCardTileClick(clickedTile) {
        this.el.querySelectorAll('#creditCardSection .credit-card').forEach(tile => {
            tile.classList.remove('selected');
            const mark = tile.querySelector('.card-selected-mark');
            if (mark) mark.classList.add('d-none');
        });

        clickedTile.classList.add('selected');
        const mark = clickedTile.querySelector('.card-selected-mark');
        if (mark) mark.classList.remove('d-none');

        const radio = clickedTile.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
        if (this._payButton) this._payButton.disabled = false;
    }

    _onPayButtonClick() {
        if (!this._enableCreditCardPayment) return;

        const payTypeRadio = this.el.querySelector('input[name="paymentType"]:checked');
        if (!payTypeRadio || payTypeRadio.value === 'ach') return;

        const selectedMethod = this.el.querySelector('input[name="paymentMethod"]:checked');
        if (!selectedMethod) {
            this._displayError('Please select a saved credit card to proceed with payment.');
            return;
        }

        const vaultId   = selectedMethod.getAttribute('data-vault-id');
        const billingId = selectedMethod.getAttribute('data-billing-id');

        if (!vaultId || !billingId) {
            this._displayError('Invalid payment method selected. Please select a valid saved card.');
            return;
        }

        this._processCreditCardPayment({ customer_vault_id: vaultId, billing_id: billingId });
    }

    _onNewCardPayButtonClick() {
        this._processNewCardPayment();
    }

    _initCCForm() {
        if (this._collectJsMode === 'cc') return;

        this._loadCollectJS()
            .then(() => {
                try {
                    CollectJS.configure({
                        paymentType: 'cc',
                        callback:    response => this._handleCCTokenization(response),
                        theme:       'bootstrap',
                        primaryColor:   '#1f4f8a',
                        secondaryColor: '#3f8fcd',
                        fields: {
                            ccnumber: { selector: '#cc-number-container', title: 'Card Number',  placeholder: '•••• •••• •••• ••••' },
                            ccexp:    { selector: '#cc-expiry-container', title: 'Expiry',        placeholder: 'MM / YY' },
                            cvv:      { selector: '#cc-cvv-container',    title: 'CVV',           placeholder: '•••' },
                        },
                    });
                    this._collectJsMode = 'cc';
                } catch (err) {
                    console.error('Error configuring CollectJS for CC:', err);
                    this._displayCCError('Failed to initialize card form. Please refresh the page.');
                }
            })
            .catch(err => {
                console.error('Error loading CollectJS:', err);
                this._displayCCError('Payment library failed to load. Please refresh the page.');
            });
    }

    _handleCCTokenization(response) {
        this._displayCCError('');

        if (!response.token) {
            this._displayCCError(response.error || response.message || 'Card tokenization failed. Please check your card details.');
            if (this._newCardPayButton) this._newCardPayButton.disabled = false;
            if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
            return;
        }

        const firstName = (this.el.querySelector('#cc-first-name')?.value || '').trim();
        const lastName  = (this.el.querySelector('#cc-last-name')?.value  || '').trim();

        const payload = {
            token:      response.token,
            orderIds:   this._orderIds,
            first_name: firstName,
            last_name:  lastName,
            card_type:  response.card?.type   || null,
            ccnumber:   response.card?.number  || null,
            ccexp:      response.card?.exp     || null,
        };

        if (this._loaderWrapper) this._loaderWrapper.style.display = 'flex';
        if (this._newCardPayButton) this._newCardPayButton.disabled = true;

        fetch(this._urlProcessNmiToken, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
        })
            .then(r => r.json())
            .then(data => {
                if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
                if (this._newCardPayButton) this._newCardPayButton.disabled = false;
                if (data.success) {
                    window.location.href = data.redirectUrl || this._urlInvoices;
                } else {
                    this._displayCCError(data.message || 'Payment failed. Please try again.');
                }
            })
            .catch(err => {
                if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
                if (this._newCardPayButton) this._newCardPayButton.disabled = false;
                this._displayCCError('An error occurred. Please try again.');
                console.error('New card bulk payment error:', err);
            });
    }

    _processNewCardPayment() {
        this._displayCCError('');

        const firstName = (this.el.querySelector('#cc-first-name')?.value || '').trim();
        const lastName  = (this.el.querySelector('#cc-last-name')?.value  || '').trim();

        if (!firstName || !lastName) {
            this._displayCCError('Please enter your first and last name.');
            return;
        }
        if (this._collectJsMode !== 'cc') {
            this._displayCCError('Card form is not ready. Please wait and try again.');
            return;
        }
        try {
            CollectJS.startPaymentRequest();
        } catch (err) {
            console.error('CollectJS.startPaymentRequest error:', err);
            this._displayCCError('Could not start card validation. Please refresh the page.');
        }
    }

    _displayCCError(message) {
        const el = this.el.querySelector('#cc-new-card-error');
        if (!el) return;
        if (!message) {
            el.classList.add('d-none');
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.classList.remove('d-none');
    }

    _initACHForm() {
        if (this._collectJsMode === 'ck') return;

        const container = this.el.querySelector('#ach-payment-form-container');
        if (!container) return;

        this._loadCollectJS()
            .then(() => {
                container.innerHTML = '';
                try {
                    CollectJS.configure({
                        paymentType: 'ck',
                        callback:    response => this._handleACHTokenization(response),
                        theme:       'bootstrap',
                        primaryColor:   '#007bff',
                        secondaryColor: '#6c757d',
                        fields: {
                            account: { placeholder: 'Account Number',            title: 'Account Number',      selector: '#ach-payment-form-container' },
                            routing: { placeholder: 'Routing Number (9 digits)', title: 'Routing Number',      selector: '#ach-payment-form-container' },
                            name:    { placeholder: 'Account Holder Name',       title: 'Account Holder Name', selector: '#ach-payment-form-container' },
                        },
                    });
                    this._collectJsMode = 'ck';
                } catch (err) {
                    console.error('Error configuring CollectJS for ACH:', err);
                    container.innerHTML = '<div class="alert alert-danger">Failed to initialize payment form. Please refresh the page.</div>';
                    this._displayACHError('Failed to initialize payment form: ' + err.message);
                }
            })
            .catch(err => {
                console.error('Error loading CollectJS:', err);
                container.innerHTML = '<div class="alert alert-danger">Payment library failed to load. Please refresh the page.</div>';
                this._displayACHError('Payment library failed to load. Please refresh the page.');
            });
    }

    _handleACHTokenization(response) {
        const errorDiv = this.el.querySelector('#ach-error-message');
        if (errorDiv) {
            errorDiv.classList.add('d-none');
            errorDiv.classList.remove('d-block');
        }

        if (!response.token) {
            const msg = response.error || response.message || 'Tokenization failed. Please check your bank account information and try again.';
            this._displayACHError(msg);
            if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
            if (this._achPayButton) this._achPayButton.disabled = false;
            return;
        }

        const customerData = this.el.querySelector('#nmi-customer-data-bulk');
        const getVal = name => {
            const input = customerData?.querySelector(`input[name="${name}"]`);
            return input ? input.value : '';
        };

        const paymentData = {
            token:               response.token,
            amount:              this._totalAmount,
            first_name:          getVal('fname'),
            last_name:           getVal('lname'),
            address1:            getVal('address1'),
            city:                getVal('city'),
            state:               getVal('state'),
            zip:                 getVal('zip'),
            checkname:           response.check?.name    || '',
            checkaba:            response.check?.routing || '',
            checkaccount:        response.check?.account || '',
            account_holder_type: 'personal',
            account_type:        'checking',
        };

        this._processACHPaymentRequest(paymentData);
    }

    _processACHPayment() {
        this._loadCollectJS()
            .then(() => {
                if (typeof CollectJS === 'undefined') {
                    this._displayACHError('Payment library not loaded. Please refresh the page.');
                    return;
                }

                const errorDiv = this.el.querySelector('#ach-error-message');
                if (errorDiv) {
                    errorDiv.classList.add('d-none');
                    errorDiv.classList.remove('d-block');
                }

                try {
                    CollectJS.startPaymentRequest();
                } catch (err) {
                    console.error('Error starting ACH payment:', err);
                    this._displayACHError('Error starting payment: ' + (err.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Error loading CollectJS:', err);
                this._displayACHError('Payment library failed to load. Please refresh the page.');
            });
    }

    _processACHPaymentRequest(paymentData) {
        if (this._loaderWrapper) this._loaderWrapper.style.display = 'flex';
        if (this._achPayButton) this._achPayButton.disabled = true;

        fetch(this._urlProcessAch, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ paymentData, orderIds: this._orderIds }),
        })
            .then(r => r.json())
            .then(data => {
                if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
                if (this._achPayButton) this._achPayButton.disabled = false;

                if (data.success) {
                    window.location.href = data.redirectUrl || this._urlInvoices;
                } else {
                    this._displayACHError(data.message || 'Payment failed. Please try again.');
                }
            })
            .catch(err => {
                console.error('ACH payment error:', err);
                if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
                if (this._achPayButton) this._achPayButton.disabled = false;
                this._displayACHError('An error occurred. Please try again.');
            });
    }

    _displayACHError(message) {
        const errorDiv = this.el.querySelector('#ach-error-message');
        if (!errorDiv) {
            return;
        }

        const errorAlert = errorDiv.querySelector('.error-alert');
        if (errorAlert) errorAlert.textContent = message;
        errorDiv.classList.remove('d-none');
        errorDiv.classList.add('d-block');
    }

    _displayError(message) {
        this._displayACHError(message);
    }

    _processCreditCardPayment(paymentData) {
        if (!this._enableCreditCardPayment) {
            this._displayError('Credit card payment is not available. Please use ACH/eCheck payment.');
            return;
        }

        if (!paymentData?.customer_vault_id || !paymentData?.billing_id) {
            this._displayError('Invalid payment data. Please select a valid saved card.');
            return;
        }

        if (!this._orderIds || this._orderIds.length === 0) {
            this._displayError('No orders selected. Please try again.');
            return;
        }

        if (this._loaderWrapper) this._loaderWrapper.style.display = 'flex';
        if (this._payButton) this._payButton.disabled = true;

        fetch(this._urlProcessNmi, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ paymentData, orderIds: this._orderIds }),
        })
            .then(r => {
                if (!r.ok) {
                    return r.text().then(() => {
                        throw new Error('Network response was not ok: ' + r.status);
                    });
                }
                return r.json();
            })
            .then(data => {
                if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
                if (this._payButton) this._payButton.disabled = false;

                if (data.success) {
                    window.location.href = data.redirectUrl || this._urlInvoices;
                } else {
                    const msg = data.message || 'Payment failed. Please try again.';
                    this._displayError(msg);
                    if (data.redirectUrl) {
                        setTimeout(() => { window.location.href = data.redirectUrl; }, 2000);
                    }
                }
            })
            .catch(err => {
                console.error('Credit card payment error:', err);
                if (this._loaderWrapper) this._loaderWrapper.style.display = 'none';
                if (this._payButton) this._payButton.disabled = false;
                this._displayError('An error occurred while processing your payment: ' + (err.message || 'Unknown error') + '. Please try again or contact support if the problem persists.');
            });
    }
}
