import template from './sw-customer-detail-base.html.twig';

const { Component } = Shopware;

Component.override('sw-customer-detail-base', {
    template,

    inject: [
        'repositoryFactory',
        'acl',
        'NetThirtyCustomerApprovalApiService',
    ],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

    data() {
        return {
            isSavingNet30Approval: false,
        };
    },

    computed: {
        net30Approved() {
            return this.customer?.customFields?.nmi_net30_approved === true;
        },

        canEditNet30Approval() {
            return this.acl.can('nmi_net30_approval.editor');
        },

        visibleCustomerCustomFieldSets() {
            if (!this.customerCustomFieldSets) {
                return this.customerCustomFieldSets;
            }

            return this.customerCustomFieldSets.filter((set) => {
                if (!set.customFields) {
                    return true;
                }

                set.customFields = set.customFields.filter((field) => field.name !== 'nmi_net30_approved');

                return set.customFields.length > 0;
            });
        },
    },

    methods: {
        async onNet30ApprovalChange(approved) {
            if (!this.canEditNet30Approval || !this.customer?.id) {
                return;
            }

            const previousValue = this.net30Approved;
            this.isSavingNet30Approval = true;

            try {
                const response = await this.NetThirtyCustomerApprovalApiService.updateApproval(
                    this.customer.id,
                    approved
                );

                if (!this.customer.customFields) {
                    this.customer.customFields = {};
                }

                this.customer.customFields.nmi_net30_approved = response.approved === true;

                this.createNotificationSuccess({
                    message: this.$tc('dealer-payment-terms.customerApproval.saveSuccess'),
                });
            } catch (error) {
                if (!this.customer.customFields) {
                    this.customer.customFields = {};
                }

                this.customer.customFields.nmi_net30_approved = previousValue;
                this.createNotificationError({
                    message: this.$tc('dealer-payment-terms.customerApproval.saveError'),
                });
            } finally {
                this.isSavingNet30Approval = false;
            }
        },
    },
});
