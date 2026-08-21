Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'nmi_card_velocity',
    roles: {
        viewer: {
            privileges: ['nmi_card_velocity:read', 'customer:read', 'sales_channel:read'],
            dependencies: [],
        },
        editor: {
            privileges: ['customer:update'],
            dependencies: ['nmi_card_velocity.viewer'],
        },
        deleter: {
            privileges: ['nmi_card_velocity:delete'],
            dependencies: ['nmi_card_velocity.viewer'],
        },
    },
});
