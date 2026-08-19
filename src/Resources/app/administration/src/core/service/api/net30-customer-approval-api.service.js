const ApiService = Shopware.Classes.ApiService;

class NetThirtyCustomerApprovalApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'nmi/net30/customer-approval') {
        super(httpClient, loginService, apiEndpoint);
    }

    updateApproval(customerId, approved) {
        return this.httpClient.post(
            `_action/${this.getApiBasePath()}/${customerId}`,
            { approved },
            { headers: this.getBasicHeaders() }
        ).then(ApiService.handleResponse.bind(this));
    }
}

export default NetThirtyCustomerApprovalApiService;
