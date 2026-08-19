Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'customers',
    key: 'nmi_net30_approval',
    roles: {
        editor: {
            privileges: [
                'nmi_net30_approval:update',
                'customer:read',
            ],
            dependencies: [
                'customer.viewer',
            ],
        },
    },
});
