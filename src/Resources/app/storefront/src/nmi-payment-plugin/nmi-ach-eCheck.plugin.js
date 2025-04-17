import CollectJsLoader from '../services/collect-js-loader';
import PaymentService from '../services/payment-service';

export default class NmiCreditCardPlugin extends window.PluginBaseClass {
    static options = {
        confirmFormId: 'confirmOrderForm',
        // formSelector: '.checkout-form-nmi',
        paymentUrl: '/nmi-payment-ach-e-check',
        collectJsUrl: 'https://secure.nmi.com/token/Collect.js',
        paymentType: 'ck',
        parentCreditCardWrapperId: 'nmi-ach-echeck',
    };

    init() {
        this._registerElements();
        this._registerEvents();
    }

    _registerElements() {
        this.parentCreditCardWrapper = document.getElementById(
            this.options.parentCreditCardWrapperId
        );
        this.amount = this.parentCreditCardWrapper.getAttribute('data-amount');
        this.confirmOrderForm = document.forms[this.options.confirmFormId];
    }

    _registerEvents() {
        this.confirmOrderForm.addEventListener(
            'submit',
            this._onPayButtonClick.bind(this)
        );
    }

    async _onPayButtonClick(event) {
        event.preventDefault();
        CollectJsLoader.loadCollectJS(
            this.options.collectJsUrl,
            this.submitPayment.bind(this),
            this.options.paymentType
        );
        if (!this.confirmOrderForm.checkValidity()) {
            return;
        }
        if (typeof CollectJS !== 'undefined') {
            CollectJS.startPaymentRequest();
        } else {
            console.error('CollectJS is not available');
        }
    }

    submitPayment(response) {
        if (!response || !response.token) {
            console.error(
                'Tokenization failed:',
                response ? response.error : 'No response'
            );
            console.error('Payment tokenization failed. Please try again.');
            return;
        }
        this.submitaCheck(response);
    }

    submitaCheck(response) {
        if (!response || !response.token) {
            console.error(
                'Tokenization failed:',
                response ? response.error : 'No response'
            );
            console.error('Payment tokenization failed. Please try again.');
            return;
        }

        const paymentData = {
            token: response.token,
            amount: this.amount,
            checkname: response.check.name,
            checkaba: response.check.aba,
            checkaccount: response.check.account,
        };

        this.submitToPaymentService(this.options.paymentUrl, paymentData);
    }

    submitToPaymentService(paymentUrl, paymentData) {
        PaymentService.submitPayment(paymentUrl, paymentData)
            .then((data) => {
                if (data.success) {
                    document.getElementById('nmi-transaction-id').value =
                        JSON.parse(data.transaction_id);
                    console.error(`Payment success: ${data.message}`);
                    document.getElementById('confirmOrderForm').submit();
                } else {
                    console.error(`Payment failed: ${data.message}`);
                }
            })
            .catch((error) => {
                console.error('Error submitting payment: ' + error);
            });
    }
}
