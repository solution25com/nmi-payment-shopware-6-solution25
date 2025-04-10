import NmiCreditCardPlugin from './nmi-payment-plugin/nmi-credit-card.plugin';
import NmiAchEcheckPlugin from './nmi-payment-plugin/nmi-ach-eCheck.plugin.js';

const PluginManager = window.PluginManager;
PluginManager.register('NmiCreditCardPlugin', NmiCreditCardPlugin, '[nmi-payment-credit-card-plugin]');
PluginManager.register('NmiAchEcheckPlugin', NmiAchEcheckPlugin, '[nmi-payment-ach-eCheck-plugin]');