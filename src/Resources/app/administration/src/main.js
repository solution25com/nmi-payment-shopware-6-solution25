import './acl/net30-approval.acl';
import './acl/card-velocity.acl';
import './component/nmi-api-test';
import './module/dealer-payment-terms';
import './module/nmi-card-velocity';
import './init/api-service.init';

import NmiApiTestService from './service/nmi-api-test.service';

Shopware.Service().register('NmiApiTestService', () => {
    return new NmiApiTestService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});
