import './extension/sw-order-detail-details';

const { Module } = Shopware;

Module.register('dealer-payment-terms', {
    type: 'plugin',
    name: 'DealerPaymentTerms',
    title: 'Dealer Payment Terms',
    description: 'Net 30 payment terms management',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#189eff',
});
