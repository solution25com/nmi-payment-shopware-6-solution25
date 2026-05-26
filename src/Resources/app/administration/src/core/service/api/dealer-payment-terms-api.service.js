const ApiService = Shopware.Classes.ApiService;

class DealerPaymentTermsApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'dealer-payment-terms') {
        super(httpClient, loginService, apiEndpoint);
    }

    resendPaymentLink(orderId, alternateDueDate = null) {
        const payload = {};
        if (alternateDueDate) {
            payload.alternateDueDate = alternateDueDate;
        }

        return this.httpClient.post(
            `_action/${this.getApiBasePath()}/resend-payment-link/${orderId}`,
            payload,
            { headers: this.getBasicHeaders() }
        ).then(ApiService.handleResponse.bind(this));
    }

    setAlternateDueDate(orderId, alternateDueDate) {
        return this.httpClient.post(
            `_action/${this.getApiBasePath()}/set-alternate-due-date/${orderId}`,
            { alternateDueDate: alternateDueDate },
            { headers: this.getBasicHeaders() }
        ).then(ApiService.handleResponse.bind(this));
    }

    getOrderDetails(orderId) {
        return this.httpClient.get(
            `_action/${this.getApiBasePath()}/order-details/${orderId}`,
            { headers: this.getBasicHeaders() }
        ).then(ApiService.handleResponse.bind(this));
    }
}

export default DealerPaymentTermsApiService;
