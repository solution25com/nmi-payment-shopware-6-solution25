import NmiCreditCardPlugin from './nmi-payment-plugin/nmi-credit-card.plugin';

const PluginManager = window.PluginManager;
PluginManager.register(
    'NmiCreditCardPlugin',
    NmiCreditCardPlugin,
    '[nmi-payment-credit-card-plugin]'
);
