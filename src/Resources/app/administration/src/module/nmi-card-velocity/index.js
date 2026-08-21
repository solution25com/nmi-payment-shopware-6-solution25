import './page/nmi-card-velocity-list';
import enGB from './snippet/en-GB.json';
import enUS from './snippet/en-US.json';

Shopware.Module.register('nmi-card-velocity', {
    type: 'plugin',
    name: 'nmi-card-velocity',
    title: 'nmi-card-velocity.general.mainMenuItemGeneral',
    description: 'nmi-card-velocity.general.description',
    color: '#de294c',
    icon: 'regular-credit-card',
    snippets: {
        'en-GB': enGB,
        'en-US': enUS,
    },
    routes: {
        index: {
            component: 'nmi-card-velocity-list',
            path: 'index',
            meta: { privilege: 'nmi_card_velocity.viewer' },
        },
    },
    navigation: [{
        label: 'nmi-card-velocity.general.mainMenuItemGeneral',
        path: 'nmi.card.velocity.index',
        icon: 'regular-credit-card',
        position: 110,
        parent: 'sw-customer',
        privilege: 'nmi_card_velocity.viewer',
    }],
});
