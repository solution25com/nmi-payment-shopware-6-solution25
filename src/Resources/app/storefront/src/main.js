import NmiCreditCardPlugin      from './nmi-payment-plugin/nmi-credit-card.plugin';
import NmiAchEcheckPlugin       from './nmi-payment-plugin/nmi-ach-echeck.plugin';
import NmiSavedCardsPlugin      from './nmi-payment-plugin/nmi-saved-cards.plugin';
import NetThirtyInvoicesPlugin  from './net-thirty-plugin/net-thirty-invoices.plugin';
import NetThirtyBulkPaymentPlugin from './net-thirty-plugin/net-thirty-bulk-payment.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'NmiCreditCardPlugin',
    NmiCreditCardPlugin,
    '[nmi-payment-credit-card-plugin]'
);

PluginManager.register(
    'NmiSavedCardsPlugin',
    NmiSavedCardsPlugin,
    '[data-nmi-payment-saved-cards-plugin]'
);

PluginManager.register(
    'NmiAchEcheckPlugin',
    NmiAchEcheckPlugin,
    '[nmi-payment-ach-eCheck-plugin]'
);

// Net-Thirty feature plugins (invoice management & bulk payment)
PluginManager.register(
    'NetThirtyInvoicesPlugin',
    NetThirtyInvoicesPlugin,
    '[data-net-thirty-invoices-plugin]'
);

PluginManager.register(
    'NetThirtyBulkPaymentPlugin',
    NetThirtyBulkPaymentPlugin,
    '[data-net-thirty-bulk-payment-plugin]'
);
