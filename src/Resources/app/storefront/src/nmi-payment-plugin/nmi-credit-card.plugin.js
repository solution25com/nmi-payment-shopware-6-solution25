import Plugin from 'src/plugin-system/plugin.class';
import CollectJsLoader from '../services/collect-js-loader';
import GatewayJsLoader from '../services/gateway-js-loader';
import PaymentService from '../services/payment-service';

export default class NmiCreditCardPlugin extends Plugin {
    static options = {
        confirmFormId: 'confirmOrderForm',
        formSelector: '.lightbox-container',
        paymentUrls: {
            creditCard: '/nmi-payment-credit-card',
            vaulted: '/nmi-payment-vaulted-customer',
            getVaultedData: '/nmi-payment-get-vaulted-customer',
            deleteVaultedCustomerData: '/nmi-payment-delete-vaulted-customer',
            addCard: '/nmi-add-card'
        },
        collectJsUrl: 'https://secure.nmi.com/token/Collect.js',
        gatewayJsUrl: 'https://secure.nmi.com/js/v1/Gateway.js',
        paymentType: 'cc',
        parentCreditCardWrapperId: 'nmi-credit-card',
    };

    init() {

        this._registerElements();
        this._registerEvents();

        if (this.isSavedCardBackend) {
            this.getVaultedCustomerData();
        }
    }

    _registerElements() {
        this.parentCreditCardWrapper = document.getElementById(this.options.parentCreditCardWrapperId);
        this.vaultedId = this.parentCreditCardWrapper.getAttribute('data-vaulted-customer-id');
        this.billingId = this.parentCreditCardWrapper.getAttribute('data-billing-customer-id');
        this.isSavedCardBackend = this.parentCreditCardWrapper.getAttribute('data-saved-card');
        this.currency = this.parentCreditCardWrapper.getAttribute('data-shop-currency');
        this.amount = this.parentCreditCardWrapper.getAttribute('data-amount');
        this.threeDSConfig = this.parentCreditCardWrapper.getAttribute('data-threeDSConfig');
        this.dropdownCards = this.parentCreditCardWrapper.getAttribute('data-dropdown-cards');
        this.deleteDataBtn = document.getElementById('delete-vaulted-customer-data');
        this.addMoreCards = document.getElementById('add-another-vaulted-card');
        this.loader = document.getElementById('nmiLoader');
        this.configs = JSON.parse(this.parentCreditCardWrapper.getAttribute('data-configs'));
        this.cardSelect = document.getElementById('cardSelect')
        this.cardHolderFirstName = document.getElementById('card-holder-first-name');
        this.cardHolderLastName = document.getElementById('card-holder-last-name');
        this.billingFirstName = this.parentCreditCardWrapper.getAttribute('data-billing-first-name');
        this.billingLastName = this.parentCreditCardWrapper.getAttribute('data-billing-last-name');
        this.billingCity = this.parentCreditCardWrapper.getAttribute('data-billing-city');
        this.confirmOrderForm = document.forms[this.options.confirmFormId];

        this.cards = JSON.parse(this.dropdownCards);
    }

    _registerEvents() {
        this.confirmOrderForm.addEventListener('submit', this._onPayButtonClick.bind(this));

        if (this.deleteDataBtn) {
            this.deleteDataBtn.addEventListener('click', this._onDeleteButtonClick.bind(this));
        }

        if (this.addMoreCards) {
            this.addMoreCards.addEventListener('click', this._onAddCardButtonClick.bind(this));
        }

        const payWithNewCardBtn = document.getElementById('pay-with-new-card');
        if (payWithNewCardBtn) {
            payWithNewCardBtn.addEventListener('click', this._onPayWithNewCard.bind(this));
        }

        const cardSelect = document.getElementById('cardSelect');


        cardSelect.addEventListener('change', (event) => {
            const selectedBillingId = event.target.value;
            const selectedCard = this.cards.find(card => card.billingId === selectedBillingId);
            if (selectedCard) {
                this.displayVaultedCustomerData({
                    first_name: selectedCard.firstName,
                    last_name: selectedCard.lastName,
                    cc_number: selectedCard.lastDigits,
                    cc_type: selectedCard.cardType
                });
            }
        });
    }

    async _onPayButtonClick(event) {
        event.preventDefault();

        await CollectJsLoader.loadCollectJS(this.options.collectJsUrl, this.submitPayment.bind(this), this.options.paymentType, {});

        if (!this.confirmOrderForm.checkValidity()) {
            return;
        }

        if (this.isSavedCardBackend) {
            this.submitVaultedPayment();
        } else {
            if (typeof CollectJS !== 'undefined') {
                CollectJS.startPaymentRequest();
            }
        }
    }

    async _onPayWithNewCard(event) {
        event.preventDefault();
        this._showLoading(true);

        try {
            await CollectJsLoader.loadCollectJS(
                this.options.collectJsUrl,
                this.submitPaymentWithNewC.bind(this),
                this.options.paymentType,
                {
                    theme: 'bootstrap',
                    primaryColor: '#007bff',
                    secondaryColor: '#6c757d',
                    buttonText: 'Pay Now'
                }
            );

            if (typeof CollectJS !== 'undefined') {
                CollectJS.startPaymentRequest();
            } else {
                console.error('CollectJS not loaded.');
            }
        } catch (e) {
            console.error('Error loading CollectJS:', e);
        }
    }

    _onDeleteButtonClick(event) {
        event.preventDefault();
        this._showLoading(true);
        this.deleteVaultedCustomerData();
    }

    async _onAddCardButtonClick(event) {
        event.preventDefault();

        CollectJsLoader.loadCollectJS(this.options.collectJsUrl, this.addBillingToCustomer.bind(this), this.options.paymentType, {
            theme: 'bootstrap',
            primaryColor: '#ff288d',
            secondaryColor: '#3e79db',
            buttonText: 'Add New Credit Card'
        });

        if (!this.confirmOrderForm.checkValidity()) {
            return;
        }

        if (typeof CollectJS !== 'undefined') {
            CollectJS.startPaymentRequest();
        }
    }


    _showLoading(isLoading) {
        const loader = document.getElementById('nmiLoader');
        if (loader) {
            loader.style.display = isLoading ? 'inline-block' : 'none';
        }
    }

    submitPayment(response) {

        if (!response.token) {
            console.error('Tokenization failed:', response.error);
            this.displayErrors(response.error)
            return;
        }
        if (this.isSavedCardBackend) {
            this.submitVaultedPayment(response);
            this._showLoading(false);
        } else {
            this.submitNormalPayment(response);
        }
    }

    submitPaymentWithNewC(response) {

        if (!response.token) {
            console.error('Tokenization failed:', response.error);
            this.displayErrors(response.error)
            return;
        }
        this.submitNormalPayment(response);
    }

    addBillingToCustomer(response) {

        if (!response.token) {
            console.error('Tokenization failed:', response.error);
            this.displayErrors(response.error)
            return;
        } else {
            this.addCards(response)
        }

    }


    addCards(response) {
        const paymentData = {
            token: response.token,
            ccnumber: response.card.number,
            ccexp: response.card.exp,
            card_type: response.card.type,
            vaulted_customer_id: this.vaultedId,
            first_name: this.billingFirstName,
            last_name: this.billingLastName,
        };
        this.submitCard(this.options.paymentUrls.addCard, paymentData);
    }

    deleteVaultedCustomerData(response) {
        const vaultedPaymentData = {
            customer_vault_id: this.vaultedId,
        };

        const paymentUrl = this.options.paymentUrls.deleteVaultedCustomerData;
        PaymentService.fetchCustomerData(paymentUrl, vaultedPaymentData)
            .then((data) => {
                this._showLoading(false);
                window.location.reload();
            })
            .catch((error) => {
                this.displayErrors(error)
                console.error('Error deleting vaulted customer data:', error);
                this._showLoading(false);
            });
    }


    async getVaultedCustomerData() {
        const vaultedPaymentData = {
            customer_vault_id: this.vaultedId,
        };

        const paymentUrl = this.options.paymentUrls.getVaultedData;
        this._showLoading(true);
        PaymentService.fetchCustomerData(paymentUrl, vaultedPaymentData)
            .then((data) => {
                this.fillDropdown(data)
                this.displayVaultedCustomerData(data);
            })
            .catch((error) => {
                console.error('Error fetching vaulted customer data:', error);
            })
            .finally(() => {
                this._showLoading(false);
            });
    }

    fillDropdown(defaultCard) {

        const cardSelect = document.getElementById('cardSelect');
        cardSelect.innerHTML = '';
        if (this.cards.length > 0) {
            this.cards.forEach( (card) => {
                const option = document.createElement('option');
                option.value = card.billingId;
                option.textContent = `${card.firstName} ****${card.lastDigits.slice(-4)}`;
                if (card.billingId === defaultCard.billingId) {
                    option.selected = true;
                }
                cardSelect.appendChild(option);
            });
        } else {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No saved cards available';
            cardSelect.appendChild(option);
        }
    }


    displayVaultedCustomerData(data) {

        const {first_name, last_name, cc_number, cc_type} = data;

        const last4 = cc_number.slice(-4);
        const maskedCC = '**** **** **** ' + last4;

        document.getElementById('vaulted-first-name').innerText = first_name;
        document.getElementById('vaulted-last-name').innerText = last_name;
        document.getElementById('vaulted-last-four-digits').innerText = maskedCC;
        document.getElementById('vaulted-card-type').innerText = cc_type;
    }


    submitNormalPayment(response) {
        const threeDSActivate = this.threeDSConfig; // till activate

        let gateway, threeDS;

        const paymentData = {
            token: response.token,
            amount: this.amount,
            first_name: document.querySelector('input[name="fname"]').value,
            last_name: document.querySelector('input[name="lname"]').value,
            address1: document.querySelector('input[name="address1"]').value,
            city: this.billingCity,
            // state: document.querySelector('input[name="state"]').value,
            zip: document.querySelector('input[name="zip"]').value,
            ccnumber: response.card.number,
            ccexp: response.card.exp,
            card_type: response.card.type,
            customer_vault: document.querySelector("#saveCardCheckbox") ? (document.querySelector("#saveCardCheckbox").checked ? "add_customer" : null) : null,
            saveCard: document.querySelector("#saveCardCheckbox") ? document.querySelector("#saveCardCheckbox").checked : false,
        };

        if (threeDSActivate) {
            const script = document.createElement('script');
            script.src = this.options.gatewayJsUrl;
            document.head.appendChild(script);

            script.onload = () => {
                gateway = GatewayJsLoader.createGateway(this.configs.checkoutKey);
                if (gateway) {
                    threeDS = gateway.get3DSecure();

                    paymentData.cavv = response.cavv;
                    paymentData.xid = response.xid;
                    paymentData.eci = response.eci;
                    paymentData.cardHolderAuth = response.cardHolderAuth;
                    paymentData.threeDsVersion = response.threeDsVersion;
                    paymentData.directoryServerId = response.directoryServerId;
                    paymentData.cardHolderInfo = response.cardHolderInfo;

                    const threeDSecureInterface = threeDS.createUI(paymentData);
                    threeDSecureInterface.start('body');
                    threeDSecureInterface.on('challenge', function (e) {
                    });

                    threeDSecureInterface.on('failure', function (e) {
                        console.error(e);
                    });

                    gateway.on('error', function (e) {
                        console.error(e);
                    });

                    this.submitToPaymentService(this.options.paymentUrls.creditCard, paymentData);
                }
            };

            script.onerror = () => {
                console.error('Failed to load Gateway.js.');
            };

        } else {
            this.submitToPaymentService(this.options.paymentUrls.creditCard, paymentData);
        }
    }

    submitVaultedPayment() {
        this._showLoading(true);
        const cardSelect = document.getElementById('cardSelect');
        const vaultedName = document.getElementById('vaulted-first-name').innerText
        const vaultedLast = document.getElementById('vaulted-last-name').innerText
        const selectedCardId = cardSelect ? cardSelect.value : null;


        const vaultedPaymentData = {
            amount: this.amount,
            customer_vault_id: this.vaultedId,
            first_name: vaultedName,
            last_name: vaultedLast,
            billing_id: cardSelect.value ?? null
        };

        const paymentUrl = this.options.paymentUrls.vaulted;
        this.submitToPaymentService(paymentUrl, vaultedPaymentData, true);
    }

    displayErrors(errors) {
        const errorMessageDiv = document.getElementById('error-message');
        const errorAlertDiv = errorMessageDiv.querySelector('.error-alert');

        errorAlertDiv.innerHTML = '';

        if (errors.length > 0) {
            errorAlertDiv.textContent = errors.join(' ');

            errorMessageDiv.classList.remove('d-none');
            errorMessageDiv.classList.add('d-block');
        } else {
            errorMessageDiv.classList.add('d-none');
            errorMessageDiv.classList.remove('d-block');
        }
    }


    submitToPaymentService(paymentUrl, paymentData, isVaultedPayment = false) {
        const loaderOverlay = document.getElementById('orderProcessingLoader1');
        const loaderOverlay2 = document.getElementById('orderProcessingLoader2');
        const nmiSubmitButton = document.querySelector('.nmiConfirmFormSubmit1');
        const nmiSubmitButton2 = document.querySelector('.nmiConfirmFormSubmit2');


        if (nmiSubmitButton && loaderOverlay) {
            loaderOverlay.style.display = 'flex';
        } else if (nmiSubmitButton2 && loaderOverlay2) {
            loaderOverlay2.style.display = 'flex';
        }

        PaymentService.submitPayment(paymentUrl, paymentData)
            .then((response) => {

                if (response.success) {
                    let transactionId = response.responses.payment.transaction_id;
                    let isSubscription = response.responses.payment.isSubscriptionCart
                    let subscriptionTransactionId = null;
                    const cardSelect = document.getElementById('cardSelect');
                    const selectedCardId = cardSelect ? cardSelect.value : null;

                    if (transactionId) {
                        document.getElementById('nmi-transaction-id').value = transactionId ?? null;
                        document.getElementById('nmi-is-subscription').value = isSubscription ?? null;
                        document.getElementById('nmi-selected-billing-id').value = selectedCardId ?? null;
                    }
                    document.getElementById('confirmOrderForm').submit();

                } else {
                    const errors = response.errors || [response.message || 'An unknown error occurred'];
                    this.displayErrors(errors);
                    if (loaderOverlay) loaderOverlay.style.display = 'none';
                    if (loaderOverlay2) loaderOverlay2.style.display = 'none';

                }
            })
            .catch((error) => {
                console.error('Error submitting payment:', error);
                this.displayErrors([error.message || 'Unexpected error occurred. Please try again later.']);
                if (loaderOverlay) loaderOverlay.style.display = 'none';
                if (loaderOverlay2) loaderOverlay2.style.display = 'none';
            });
    }

    submitCard(paymentUrl, paymentData, isVaultedPayment = false) {

        PaymentService.addBillingToCustomerData(paymentUrl, paymentData)
            .then((response) => {


                if (response.success) {
                    window.location.reload()
                } else {
                    const errors = response.errors || [response.message || 'An unknown error occurred'];
                    this.displayErrors(errors);
                }
            })
            .catch((error) => {
                console.error('Error submitting payment:', error);
                this.displayErrors([error.message || 'Unexpected error occurred. Please try again later.']);
            });
    }
}