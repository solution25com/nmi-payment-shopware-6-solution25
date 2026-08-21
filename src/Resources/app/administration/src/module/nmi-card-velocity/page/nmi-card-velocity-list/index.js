import template from './nmi-card-velocity-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('nmi-card-velocity-list', {
    template,
    inject: ['repositoryFactory', 'acl'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            repository: null,
            customerRepository: null,
            rows: [],
            isLoading: false,
            page: 1,
            limit: 25,
            total: 0,
            sortBy: 'lastBlockedAt',
            sortDirection: 'DESC',
            selectedRow: null,
            resetModalVisible: false,
            deactivateModalVisible: false,
            columns: [
                { property: 'customerName', dataIndex: 'customer.lastName,customer.firstName', label: this.$tc('nmi-card-velocity.list.customer'), sortable: true },
                { property: 'email', dataIndex: 'customer.email', label: this.$tc('nmi-card-velocity.list.email'), sortable: true },
                { property: 'customerType', label: this.$tc('nmi-card-velocity.list.customerType'), sortable: false },
                { property: 'salesChannel', label: this.$tc('nmi-card-velocity.list.salesChannel'), sortable: false },
                { property: 'distinctCards', label: this.$tc('nmi-card-velocity.list.cards'), sortable: true },
                { property: 'blockedAttempts', label: this.$tc('nmi-card-velocity.list.attempts'), sortable: true },
                { property: 'lastBlockedAt', label: this.$tc('nmi-card-velocity.list.lastBlocked'), sortable: true },
                { property: 'active', label: this.$tc('nmi-card-velocity.list.status'), sortable: false },
            ],
        };
    },

    created() {
        this.repository = this.repositoryFactory.create('nmi_card_velocity');
        this.customerRepository = this.repositoryFactory.create('customer');
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;
            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('customer');
            criteria.addAssociation('salesChannel');
            criteria.addFilter(Criteria.range('blockedAttempts', { gte: 1 }));
            this.sortBy.split(',').forEach((field) => {
                criteria.addSorting(Criteria.sort(field, this.sortDirection));
            });

            try {
                const result = await this.repository.search(criteria, Shopware.Context.api);

                if (result.length === 0 && this.page > 1) {
                    this.page -= 1;
                    await this.load();
                    return;
                }

                this.total = result.total;
                this.rows = result.map((row) => ({
                    id: row.id,
                    customerId: row.customerId,
                    customerName: `${row.customer?.firstName || ''} ${row.customer?.lastName || ''}`.trim() || '-',
                    email: row.customer?.email || '-',
                    active: row.customer?.active === true,
                    isGuest: row.customer?.guest === true,
                    salesChannel: row.salesChannel?.translated?.name || row.salesChannel?.name || '-',
                    distinctCards: row.distinctCards,
                    blockedAttempts: row.blockedAttempts,
                    lastBlockedAt: row.lastBlockedAt,
                }));
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('nmi-card-velocity.list.loadError'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.load();
        },

        onRefresh() {
            this.load();
        },

        onColumnSort(column) {
            if (!column?.dataIndex) {
                return;
            }

            if (this.sortBy === column.dataIndex) {
                this.sortDirection = this.sortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sortBy = column.dataIndex;
                this.sortDirection = 'ASC';
            }

            this.page = 1;
            this.load();
        },

        formatDate(value) {
            return value ? new Date(value).toLocaleString() : '-';
        },

        openReset(row) {
            this.selectedRow = row;
            this.resetModalVisible = true;
        },

        openDeactivate(row) {
            this.selectedRow = row;
            this.deactivateModalVisible = true;
        },

        async reset() {
            try {
                await this.repository.delete(this.selectedRow.id, Shopware.Context.api);
                this.resetModalVisible = false;
                await this.load();
                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('nmi-card-velocity.list.resetSuccess', 0, { name: this.selectedRow.customerName }),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('nmi-card-velocity.list.resetError'),
                });
            }
        },

        async deactivate() {
            try {
                const customer = await this.customerRepository.get(this.selectedRow.customerId, Shopware.Context.api);
                customer.active = false;
                await this.customerRepository.save(customer, Shopware.Context.api);
                this.deactivateModalVisible = false;
                await this.load();
                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('nmi-card-velocity.list.deactivateSuccess', 0, { name: this.selectedRow.customerName }),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('nmi-card-velocity.list.deactivateError'),
                });
            }
        },
    },
});
