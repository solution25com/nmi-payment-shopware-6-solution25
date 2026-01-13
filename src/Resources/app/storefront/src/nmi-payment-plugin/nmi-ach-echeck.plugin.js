import CollectJsLoader from '../services/collect-js-loader';
import PaymentService from '../services/payment-service';

export default class NmiAchEcheckPlugin extends window.PluginBaseClass {
    static options = {
        confirmFormId: 'confirmOrderForm',
        paymentUrls: {
            ach: '/nmi-payment-ach',
        },
        collectJsUrl: 'https://secure.nmi.com/token/Collect.js',
        paymentType: 'ck',
        parentAchWrapperId: 'nmi-ach-echeck',
    };

    init() {
        this._registerElements();
        this._registerEvents();
    }

    _registerElements() {
        this.parentAchWrapper = document.getElementById(this.options.parentAchWrapperId);
        this.amount = this.parentAchWrapper.getAttribute('data-amount');
        this.configs = JSON.parse(this.parentAchWrapper.getAttribute('data-configs') || '{}');
        this.confirmOrderForm = document.forms[this.options.confirmFormId];
    }

    _registerEvents() {
        this.confirmOrderForm.addEventListener('submit', this._onPayButtonClick.bind(this));
    }

    async _onPayButtonClick(event) {
        event.preventDefault();

        await CollectJsLoader.loadCollectJS(
            this.options.collectJsUrl,
            this.submitPayment.bind(this),
            this.options.paymentType,
            {
                theme: 'bootstrap',
                primaryColor: '#007bff',
                secondaryColor: '#6c757d',
                buttonText: 'Pay Now'
            }
        );

        if (!this.confirmOrderForm.checkValidity()) {
            return;
        }

        if (typeof CollectJS !== 'undefined') {
            CollectJS.startPaymentRequest();
        }
    }

    submitPayment(response) {
        if (!response.token) {
            console.error('Tokenization failed:', response.error);
            this.displayErrors(response.error);
            return;
        }

        this.submitNormalPayment(response);
    }

    submitNormalPayment(response) {
        const paymentData = {
            token: response.token,
            amount: this.amount,
            first_name: document.querySelector('input[name="fname"]').value,
            last_name: document.querySelector('input[name="lname"]').value,
            address1: document.querySelector('input[name="address1"]').value,
            city: document.querySelector('input[name="city"]').value,
            state: document.querySelector('input[name="state"]').value,
            zip: document.querySelector('input[name="zip"]').value,
            checkname: response.check?.name || '',
            checkaba: response.check?.routing || '',
            checkaccount: response.check?.account || '',
            account_holder_type: response.check?.account_holder_type || 'personal',
            account_type: response.check?.account_type || 'checking',
        };

        this.submitToPaymentService(this.options.paymentUrls.ach, paymentData);
    }

    displayErrors(errors) {
        const errorMessageDiv = document.getElementById('error-message');
        if (!errorMessageDiv) {
            return;
        }

        const errorAlertDiv = errorMessageDiv.querySelector('.error-alert') || errorMessageDiv;

        errorAlertDiv.innerHTML = '';

        if (errors && errors.length > 0) {
            errorAlertDiv.textContent = Array.isArray(errors) ? errors.join(' ') : errors;
            errorMessageDiv.classList.remove('d-none');
            errorMessageDiv.classList.add('d-block');
        } else {
            errorMessageDiv.classList.add('d-none');
            errorMessageDiv.classList.remove('d-block');
        }
    }

    submitToPaymentService(paymentUrl, paymentData) {
        const loaderOverlay = document.getElementById('orderProcessingLoader1');
        const nmiSubmitButton = document.querySelector('.nmiConfirmFormSubmit');

        if (nmiSubmitButton && loaderOverlay) {
            loaderOverlay.style.display = 'flex';
        }

        PaymentService.submitPayment(paymentUrl, paymentData)
            .then((response) => {
                if (response.success) {
                    let transactionId = response.transaction_id;
                    if (transactionId) {
                        document.getElementById('nmi-transaction-id').value = transactionId;
                    }
                    document.getElementById('confirmOrderForm').submit();
                } else {
                    const errors = response.errors || [response.message || 'An unknown error occurred'];
                    this.displayErrors(errors);
                    if (loaderOverlay) loaderOverlay.style.display = 'none';
                }
            })
            .catch((error) => {
                console.error('Error submitting payment:', error);
                this.displayErrors([error.message || 'Unexpected error occurred. Please try again later.']);
                if (loaderOverlay) loaderOverlay.style.display = 'none';
            });
    }
}


