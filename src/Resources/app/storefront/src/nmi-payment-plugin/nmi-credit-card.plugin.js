import CollectJsLoader from '../services/collect-js-loader';
import GatewayJsLoader from '../services/gateway-js-loader';
import PaymentService from '../services/payment-service';

export default class NmiCreditCardPlugin extends window.PluginBaseClass {
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

  _configurationError() {
    const paymentWrapper = document.getElementById(this.options.parentCreditCardWrapperId);
    if (!paymentWrapper) {
      return;
    }

    const paymentMethodId = paymentWrapper.getAttribute('data-payment-method-id')
    const input = document.getElementById("paymentMethod" + paymentMethodId);
    if (!input) {
      return;
    }

    const parentGroup = input.parentElement?.parentElement;
    input.disabled = true;
    const error = document.createElement("div");
    error.className = "payment-error";
    error.textContent = "Configuration Error";
    error.style.color = "red";
    error.style.marginTop = "5px";
    parentGroup?.appendChild(error);

    const submitButton = document.getElementsByClassName('nmiConfirmFormSubmit')[0];
    const payWithNewCardButton = document.getElementById('pay-with-new-card');
    if (submitButton) submitButton.disabled = true;
    if (payWithNewCardButton) payWithNewCardButton.disabled = true;
  }

  init() {
    CollectJsLoader.loadCollectJS(this.options.collectJsUrl, () => {}, this.options.paymentType, {}, () => {
      this._configurationError()
    })

    this._registerElements();
    this._registerEvents();
    if (this.isSavedCardBackend) {
      this.getVaultedCustomerData();
    }
  }

  _registerElements() {
    this._paymentInProgress = false;
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
    this.billingFirstName = this.parentCreditCardWrapper.getAttribute('data-billing-first-name');
    this.billingLastName = this.parentCreditCardWrapper.getAttribute('data-billing-last-name');
    this.billingCity = this.parentCreditCardWrapper.getAttribute('data-billing-city');
    this.confirmOrderForm = document.forms[this.options.confirmFormId];
    this.isFinalizingSubmit = false;
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

    cardSelect?.addEventListener('change', (event) => {
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
    if (this.isFinalizingSubmit) {
      this.isFinalizingSubmit = false;
      return;
    }

    event.preventDefault();

    if (!this._validateConfirmOrderForm()) {
      return;
    }

    this._showLoading(true);

    try {
      await CollectJsLoader.loadCollectJS(
          this.options.collectJsUrl,
          this.submitPayment.bind(this),
          this.options.paymentType,
          {}
      );

      if (this.isSavedCardBackend) {
        this.submitVaultedPayment();
      } else if (typeof CollectJS !== 'undefined') {
        CollectJS.startPaymentRequest();
      } else {
        this.displayErrors(['Payment library (CollectJS) could not be loaded.']);
      }
    } catch (error) {
      this.displayErrors([error.message || 'Unexpected error occurred while processing payment.']);
    } finally {
      this._showLoading(false);
    }
  }


  async _onPayWithNewCard(event) {
    event.preventDefault();

    if (!this._validateConfirmOrderForm()) {
      return;
    }

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
        this.displayErrors(['Payment library (CollectJS) could not be loaded.']);
      }
    } catch (error) {
      this.displayErrors([error.message || 'Unexpected error occurred while loading payment form.']);
    } finally {
      this._showLoading(false);
    }
  }

  async _onDeleteButtonClick(event) {
    event.preventDefault();
    this._showLoading(true);
    await this.deleteVaultedCustomerData();
  }


  async _onAddCardButtonClick(event) {
    event.preventDefault();

    if (!this._validateConfirmOrderForm()) {
      this.displayErrors(['Please complete all required fields before adding a card.']);
      return;
    }

    this._showLoading(true);

    try {
      await CollectJsLoader.loadCollectJS(
          this.options.collectJsUrl,
          this.addBillingToCustomer.bind(this),
          this.options.paymentType,
          {
            theme: 'bootstrap',
            primaryColor: '#ff288d',
            secondaryColor: '#3e79db',
            buttonText: 'Add New Credit Card'
          }
      );

      if (typeof CollectJS !== 'undefined') {
        CollectJS.startPaymentRequest();
      } else {
        this.displayErrors(['Payment library (CollectJS) could not be loaded.']);
      }
    } catch (error) {
      this.displayErrors([error.message || 'Unexpected error occurred while adding a new card.']);
    } finally {
      this._showLoading(false);
    }
  }

  _showLoading(isLoading) {
    const loader = document.getElementById('nmiLoader');
    if (loader) {
      loader.style.display = isLoading ? 'inline-block' : 'none';
    }
  }

  _validateConfirmOrderForm() {
    if (!this.confirmOrderForm.checkValidity()) {
      this.confirmOrderForm.reportValidity();
      return false;
    }

    return true;
  }

  _submitConfirmOrderForm() {
    this.isFinalizingSubmit = true;

    if (typeof this.confirmOrderForm.requestSubmit === 'function') {
      this.confirmOrderForm.requestSubmit();
      return;
    }

    this.confirmOrderForm.submit();
  }

  submitPayment(response) {
    try {
      if (!response || !response.token) {
        this.displayErrors(
            Array.isArray(response?.error) ? response.error : [response?.error || 'Tokenization failed.']
        );
        return;
      }

      if (this.isSavedCardBackend) {
        this.submitVaultedPayment(response);
        this._showLoading(false);
      } else {
        this.submitNormalPayment(response);
      }
    } catch (error) {
      this.displayErrors([error.message || 'Unexpected error occurred during payment submission.']);
      this._showLoading(false);
    }
  }

  submitPaymentWithNewC(response) {

    if (!response.token) {
      this.displayErrors(response.error)
      return;
    }
    this.submitNormalPayment(response);
  }

  addBillingToCustomer(response) {

    if (!response.token) {
      this.displayErrors(response.error)
    } else {
      this.addCards(response)
    }
  }

  async addCards(response) {
    const paymentData = {
      token: response.token,
      ccnumber: response.card.number,
      ccexp: response.card.exp,
      card_type: response.card.type,
      vaulted_customer_id: this.vaultedId,
      first_name: this.billingFirstName,
      last_name: this.billingLastName,
    };
    await this.submitCard(this.options.paymentUrls.addCard, paymentData);
  }

 async deleteVaultedCustomerData() {
    const vaultedPaymentData = {
      customer_vault_id: this.vaultedId,
    };

    const paymentUrl = this.options.paymentUrls.deleteVaultedCustomerData;
     try{
       await PaymentService.fetchCustomerData(paymentUrl, vaultedPaymentData)
       window.location.reload();
     }
     catch (error){
       this.displayErrors([error.message || 'Failed to delete saved card.'])
       this._showLoading(false);
     }
  }


  async getVaultedCustomerData() {
    const vaultedPaymentData = {
      customer_vault_id: this.vaultedId,
    };

    const paymentUrl = this.options.paymentUrls.getVaultedData;
    this._showLoading(true);

    try {
      const data = await PaymentService.fetchCustomerData(paymentUrl, vaultedPaymentData);
      this.fillDropdown(data);
      this.displayVaultedCustomerData(data);
    } catch (error) {
      this.displayErrors([error.message || 'Failed to fetch vaulted customer data.']);
    } finally {
      this._showLoading(false);
    }
  }


  fillDropdown(defaultCard) {

    const cardSelect = document.getElementById('cardSelect');
    if (!cardSelect) {
      return;
    }

    cardSelect.innerHTML = '';
    if (this.cards.length > 0) {
      this.cards.forEach((card) => {
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

    const last4 = String(cc_number || '').slice(-4);
    const maskedCC = '**** **** **** ' + last4;

    const firstNameElement = document.getElementById('vaulted-first-name');
    const lastNameElement = document.getElementById('vaulted-last-name');
    const lastFourElement = document.getElementById('vaulted-last-four-digits');
    const cardTypeElement = document.getElementById('vaulted-card-type');

    if (firstNameElement) firstNameElement.innerText = first_name || '';
    if (lastNameElement) lastNameElement.innerText = last_name || '';
    if (lastFourElement) lastFourElement.innerText = maskedCC;
    if (cardTypeElement) cardTypeElement.innerText = cc_type || '';
  }


  submitNormalPayment(response) {
    const flow = document.getElementById('nmi-credit-card').getAttribute('data-flow');
    const threeDSActivate = this.threeDSConfig === '1'
      || this.threeDSConfig === 'true'
      || this.threeDSConfig === true;

    let gateway, threeDS;
    const paymentData = {
      token: response.token,
      amount: this.amount,
      first_name: document.querySelector('input[name="fname"]').value,
      last_name: document.querySelector('input[name="lname"]').value,
      address1: document.querySelector('input[name="address1"]').value,
      city: this.billingCity,
      zip: document.querySelector('input[name="zip"]').value,
      ccnumber: response.card.number,
      ccexp: response.card.exp,
      card_type: response.card.type,
      customer_vault: document.querySelector("#saveCardCheckbox") ? (document.querySelector("#saveCardCheckbox").checked ? "add_customer" : null) : null,
      saveCard: document.querySelector("#saveCardCheckbox") ? document.querySelector("#saveCardCheckbox").checked : false,
    };

    if (flow === 'order_payment') {
      document.getElementById('nmiPaymentData').value = JSON.stringify(paymentData);
      this._submitConfirmOrderForm();
    } else {
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
            threeDSecureInterface.on('challenge', function () {
            });

            threeDSecureInterface.on('failure', (e) => {
              this.displayErrors([e.message || '3D Secure authentication failed.']);
            });

            gateway.on('error',  (e) => {
              this.displayErrors([e.message || '3D Secure authentication failed.']);
            });

            this.submitToPaymentService(this.options.paymentUrls.creditCard, paymentData);
          }
        };

        script.onerror = () => {
            this.displayErrors(['Failed to load Gateway.js.']);
        };

      } else {
        this.submitToPaymentService(this.options.paymentUrls.creditCard, paymentData);
      }
    }
  }

  submitVaultedPayment() {
    const flow = document.getElementById('nmi-credit-card').getAttribute('data-flow');

    this._showLoading(true);
    const cardSelect = document.getElementById('cardSelect');
    const vaultedName = document.getElementById('vaulted-first-name').innerText
    const vaultedLast = document.getElementById('vaulted-last-name').innerText


    const vaultedPaymentData = {
      amount: this.amount,
      customer_vault_id: this.vaultedId,
      first_name: vaultedName,
      last_name: vaultedLast,
      billing_id: cardSelect?.value ?? this.billingId ?? null
    };

    if (flow === 'order_payment') {
      document.getElementById('nmiPaymentData').value = JSON.stringify(vaultedPaymentData);
      this._submitConfirmOrderForm();
    } else {
      const paymentUrl = this.options.paymentUrls.vaulted;
      this.submitToPaymentService(paymentUrl, vaultedPaymentData, true);
    }
  }

  displayErrors(errors) {
    const errorMessageDiv = document.getElementById('error-message');
    const errorAlertDiv = errorMessageDiv?.querySelector('.error-alert');

    if (!errorMessageDiv || !errorAlertDiv) {
      return;
    }

    const messages = Array.isArray(errors) ? errors : [errors].filter(Boolean);

    errorAlertDiv.innerHTML = '';

    if (messages.length > 0) {
      errorAlertDiv.textContent = messages.join(' ');

      errorMessageDiv.classList.remove('d-none');
      errorMessageDiv.classList.add('d-block');
    } else {
      errorMessageDiv.classList.add('d-none');
      errorMessageDiv.classList.remove('d-block');
    }
  }


  submitToPaymentService(paymentUrl, paymentData, isVaultedPayment = false) {
    if (this._paymentInProgress) {
      return;
    }

    this._paymentInProgress = true;

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
          const paymentResponse = response.responses?.payment;
          const transactionId = paymentResponse?.transaction_id;
          const isSubscription = paymentResponse?.isSubscriptionCart;
          const cardSelect = document.getElementById('cardSelect');
          const selectedCardId = cardSelect ? cardSelect.value : null;

          if (!transactionId) {
            this._paymentInProgress = false;
            this.displayErrors(['Payment could not be confirmed because no transaction reference was returned. Please try again.']);
            if (loaderOverlay) loaderOverlay.style.display = 'none';
            if (loaderOverlay2) loaderOverlay2.style.display = 'none';
            return;
          }

          const transactionIdInput = document.getElementById('nmi-transaction-id');
          const subscriptionInput = document.getElementById('nmi-is-subscription');
          const selectedBillingInput = document.getElementById('nmi-selected-billing-id');

          if (transactionIdInput) transactionIdInput.value = transactionId;
          if (subscriptionInput) subscriptionInput.value = isSubscription ?? '';
          if (selectedBillingInput) selectedBillingInput.value = selectedCardId ?? '';

          this._submitConfirmOrderForm();

        } else {
          this._paymentInProgress = false;
          const errors = response.errors || [response.message || 'An unknown error occurred'];
          this.displayErrors(errors);
          if (loaderOverlay) loaderOverlay.style.display = 'none';
          if (loaderOverlay2) loaderOverlay2.style.display = 'none';

        }
      })
      .catch((error) => {
        this._paymentInProgress = false;
        this.displayErrors([error.message || 'Unexpected error occurred. Please try again later.']);
        if (loaderOverlay) loaderOverlay.style.display = 'none';
        if (loaderOverlay2) loaderOverlay2.style.display = 'none';
      });
  }

  async submitCard(paymentUrl, paymentData, isVaultedPayment = false) {

    try{
      await PaymentService.addBillingToCustomerData(paymentUrl, paymentData)
      window.location.reload();
    }catch (error){
      this.displayErrors([error.message || 'Failed to add saved card.'])
      this._showLoading(false);
    }
  }
}
