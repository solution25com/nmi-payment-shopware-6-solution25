import template from './nmi-api.html.twig';
import NmiApiTestService from "../../service/nmi-api-test.service";

const { Component, Mixin } = Shopware;

Component.register('nmi-api-test', {
    template,

    props: ['label'],
    inject: ['NmiApiTestService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            let $parent = this.$parent;

            while ($parent.actualConfigData === undefined) {
                $parent = $parent.$parent;
            }

            return $parent.actualConfigData.null;
        },
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        getCurrentSalesChannelId() {
            let $parent = this.$parent;

            while ($parent.currentSalesChannelId === undefined) {
                $parent = $parent.$parent;
            }

            return $parent.currentSalesChannelId;
        },

        check() {
            this.isLoading = true;

            const payload = {
                ...this.pluginConfig,
                salesChannelId: this.getCurrentSalesChannelId()
            };

            this.NmiApiTestService
                .check(payload)

                .then((response) => {
                    if (response.success) {
                        this.isSaveSuccessful = true;
                        let message = this.$tc('NMI.apiTest.success.message');
                        if (response.webhookUrl) {
                            message += `\n\nWebhook URL: ${response.webhookUrl}\n\nPlease register this URL in NMI merchant portal (Settings > Webhooks) and subscribe to all events.`;
                        }
                        this.createNotificationSuccess({
                            title: this.$tc('NMI.apiTest.success.title'),
                            message: message,
                        });
                    } else {
                        this.createNotificationError({
                            title: this.$tc('NMI.apiTest.error.title'),
                            message: this.$tc('NMI.apiTest.error.message'),
                        });
                    }
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: 'NMI API Test',
                        message: error.response?.data?.errors?.[0]?.detail || error.message || 'Connection failed!',
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
