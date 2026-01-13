import './component/nmi-api-test';

import NmiApiTestService from './service/nmi-api-test.service';

Shopware.Service().register('NmiApiTestService', () => {
    return new NmiApiTestService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});