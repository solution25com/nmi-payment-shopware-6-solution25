import DealerPaymentTermsApiService from '../core/service/api/dealer-payment-terms-api.service';
import NetThirtyCustomerApprovalApiService from '../core/service/api/net30-customer-approval-api.service';

const { Application } = Shopware;

Application.addServiceProvider('DealerPaymentTermsApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new DealerPaymentTermsApiService(
        initContainer.httpClient,
        container.loginService
    );
});

Application.addServiceProvider('NetThirtyCustomerApprovalApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new NetThirtyCustomerApprovalApiService(
        initContainer.httpClient,
        container.loginService
    );
});
