import template from './sw-order-detail-details.html.twig';

const { Component } = Shopware;
const { isEmpty } = Shopware.Utils.types;

Component.override('sw-order-detail-details', {
    template,

    inject: [
        'repositoryFactory',
        'DealerPaymentTermsApiService',
    ],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

    data() {
        return {
            isNet30Order: false,
            net30OrderDetails: null,
            isLoadingNet30: false,
            alternateDueDate: null,
            isResendingLink: false,
        };
    },

    computed: {
        transaction() {
            return this.order?.transactions?.last();
        },

        orderCustomFields() {
            return this.order?.customFields ?? {};
        },

        isNet30Payment() {
            if (!this.order) {
                return false;
            }

            const customFields = this.orderCustomFields;
            const transactionCustomFields = this.transaction?.customFields ?? {};

            const hasPaymentLink = !isEmpty(customFields.net_30_payment_link) ||
                                   !isEmpty(transactionCustomFields.net_30_payment_link);
            const isNet30Type = customFields.dealer_payment_type === 'net_30';

            return hasPaymentLink || isNet30Type;
        },

        canResendLink() {
            if (!this.isNet30Payment) {
                return false;
            }
            const customFields = this.orderCustomFields;
            return !customFields.net_30_payment_completed && !customFields.net_30_payment_failed;
        },
    },

    watch: {
        order: {
            handler(newOrder) {
                if (newOrder && this.isNet30Payment) {
                    this.loadFromOrderCustomFields();
                } else {
                    this.net30OrderDetails = null;
                    this.alternateDueDate = null;
                }
            },
            immediate: true,
            deep: true,
        },
    },

    mounted() {
        if (this.order && this.isNet30Payment) {
            this.loadFromOrderCustomFields();
        }
    },

    methods: {
        loadFromOrderCustomFields() {
            if (!this.order) {
                return;
            }

            const customFields = this.orderCustomFields;
            const transaction = this.transaction;
            const transactionCustomFields = transaction?.customFields ?? {};

            const isNet30 = customFields?.net_30_payment_link ||
                           transactionCustomFields?.net_30_payment_link ||
                           customFields?.dealer_payment_type === 'net_30';

            if (isNet30) {
                this.net30OrderDetails = {
                    id: this.order?.id,
                    orderNumber: this.order?.orderNumber,
                    paymentLink: customFields?.net_30_payment_link || transactionCustomFields?.net_30_payment_link || null,
                    expiresAt: customFields?.net_30_payment_link_expires_at || transactionCustomFields?.net_30_payment_link_expires_at || null,
                    alternateDueDate: customFields?.net_30_alternate_due_date || null,
                    paymentCompleted: customFields?.net_30_payment_completed || false,
                    paymentFailed: customFields?.net_30_payment_failed || false,
                    paymentOverdue: customFields?.net_30_payment_overdue || false,
                    resendCount: customFields?.net_30_payment_link_resend_count || 0,
                    lastResentAt: customFields?.net_30_payment_link_resent_at || null,
                    currentState: transaction?.stateMachineState?.technicalName || null,
                };
                this.alternateDueDate = customFields?.net_30_alternate_due_date || null;
            }
        },

        async resendPaymentLink() {
            if (!this.order?.id) {
                return;
            }

            this.isResendingLink = true;
            try {
                const response = await this.DealerPaymentTermsApiService.resendPaymentLink(
                    this.order.id,
                    this.alternateDueDate
                );

                if (response && response.success) {
                    this.createNotificationSuccess({
                        title: this.$tc('dealer-payment-terms.notification.successTitle'),
                        message: this.$tc('dealer-payment-terms.notification.resendSuccess'),
                    });
                    this.$emit('reload-order');
                    await this.$nextTick();
                    setTimeout(() => {
                        this.loadFromOrderCustomFields();
                    }, 500);
                } else {
                    const errorMessage = (response && response.message) ||
                                        this.$tc('dealer-payment-terms.notification.resendError');
                    this.createNotificationError({
                        title: this.$tc('dealer-payment-terms.notification.errorTitle'),
                        message: errorMessage,
                    });
                }
            } catch (error) {
                const errorMessage = (error.response && error.response.data && error.response.data.message) ||
                                    error.message ||
                                    this.$tc('dealer-payment-terms.notification.resendError');
                this.createNotificationError({
                    title: this.$tc('dealer-payment-terms.notification.errorTitle'),
                    message: errorMessage,
                });
            } finally {
                this.isResendingLink = false;
            }
        },

        async setAlternateDueDate() {
            if (!this.order?.id) {
                return;
            }

            if (!this.alternateDueDate && this.net30OrderDetails?.expiresAt) {
                this.alternateDueDate = this.net30OrderDetails.expiresAt.split(' ')[0];
            }

            if (!this.alternateDueDate) {
                this.createNotificationError({
                    title: this.$tc('dealer-payment-terms.notification.errorTitle'),
                    message: 'Please select an alternate due date',
                });
                return;
            }

            try {
                const response = await this.DealerPaymentTermsApiService.setAlternateDueDate(
                    this.order.id,
                    this.alternateDueDate
                );

                if (response && response.success) {
                    this.createNotificationSuccess({
                        title: this.$tc('dealer-payment-terms.notification.successTitle'),
                        message: this.$tc('dealer-payment-terms.notification.alternateDateSet'),
                    });
                    this.$emit('reload-order');
                    await this.$nextTick();
                    setTimeout(() => {
                        this.loadFromOrderCustomFields();
                    }, 500);
                } else {
                    const errorMessage = (response && response.message) ||
                                        this.$tc('dealer-payment-terms.notification.setDateError');
                    this.createNotificationError({
                        title: this.$tc('dealer-payment-terms.notification.errorTitle'),
                        message: errorMessage,
                    });
                }
            } catch (error) {
                const errorMessage = (error.response && error.response.data && error.response.data.message) ||
                                    error.message ||
                                    this.$tc('dealer-payment-terms.notification.setDateError');
                this.createNotificationError({
                    title: this.$tc('dealer-payment-terms.notification.errorTitle'),
                    message: errorMessage,
                });
            }
        },

        formatDate(dateString) {
            if (!dateString) {
                return '';
            }
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            });
        },
    },
});
