import './acl/net30-approval.acl';
import './component/nmi-api-test';
import './module/dealer-payment-terms';
import './init/api-service.init';

import NmiApiTestService from './service/nmi-api-test.service';

Shopware.Service().register('NmiApiTestService', () => {
    return new NmiApiTestService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});
