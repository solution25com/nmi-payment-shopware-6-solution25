import DealerPaymentTermsApiService from '../core/service/api/dealer-payment-terms-api.service';

const { Application } = Shopware;

Application.addServiceProvider('DealerPaymentTermsApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new DealerPaymentTermsApiService(
        initContainer.httpClient,
        container.loginService
    );
});
