import Plugin from 'src/plugin-system/plugin.class';
import CollectJsLoader from '../services/collect-js-loader';
import PaymentService from '../services/payment-service';

export default class NmiCreditCardPlugin extends Plugin {
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
        console.log(this.amount);
    }

    _registerElements() {
        this.parentCreditCardWrapper = document.getElementById(this.options.parentCreditCardWrapperId);
        this.amount = this.parentCreditCardWrapper.getAttribute('data-amount');
        this.confirmOrderForm = document.forms[this.options.confirmFormId];
    }

    _registerEvents() {
        this.confirmOrderForm.addEventListener('submit', this._onPayButtonClick.bind(this));
    }

    async _onPayButtonClick(event) {
        event.preventDefault();
        CollectJsLoader.loadCollectJS(this.options.collectJsUrl, this.submitPayment.bind(this), this.options.paymentType);
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
        console.log('Processing payment with response:', response);
        if (!response || !response.token) {
            console.error('Tokenization failed:', response ? response.error : 'No response');
            alert('Payment tokenization failed. Please try again.');
            return;
        }
        this.submitaCheck(response);
    }


    submitaCheck(response) {
        if (!response || !response.token) {
            console.error('Tokenization failed:', response ? response.error : 'No response');
            alert('Payment tokenization failed. Please try again.');
            return;
        }

        console.log('ACH response:', response);

        const paymentData = {
            token: response.token,
            amount: this.amount,
            checkname: response.check.name,
            checkaba: response.check.aba,
            checkaccount: response.check.account,
        };

        console.log('Payment data:', paymentData);

        this.submitToPaymentService(this.options.paymentUrl, paymentData);
    }


    submitToPaymentService(paymentUrl, paymentData) {
        console.log("Submitting payment to service...");
        console.log(paymentUrl, paymentData);

        PaymentService.submitPayment(paymentUrl, paymentData)
          .then((data) => {
              console.log('Server response:', data);
              if (data.success) {
                  document.getElementById('nmi-transaction-id').value = JSON.parse(data.transaction_id);
                  alert(`Payment success: ${data.message}`);
                  document.getElementById('confirmOrderForm').submit();
              } else {
                  alert(`Payment failed: ${data.message}`);
              }
          })
          .catch((error) => {
              alert('Error submitting payment: ' + error);
          });
    }
}
