define([
    'uiComponent',
    'jquery',
    'ko',
    'Magento_Customer/js/customer-data',
], function (Component, $, ko, customerData) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'MageMe_EUWithdrawal/checkout/precontract',
            logEndpointUrl: '',
            downloadAnnexIbUrl: '',
            snapshotVersion: '',
            publishedAt: '',
            annexIaSections: [],
            heading: '',
            articlePill: 'EU Art. 6(1)(h)',
            subhead: '',
            accordionTitle: '',
            downloadLabel: '',
            downloadHint: '',
            footerText: '',
            isExpanded: false,
            displayLogged: false,
        },

        /**
         * Initialize and fire fire-and-forget log_display POST.
         *
         * Layout-injected config (snapshotVersion, downloadAnnexIbUrl, etc.) must
         * be wrapped in ko.observable so KO `text:`/`attr:` bindings update when
         * the values resolve. Magento's `tracks` mechanism does not reliably
         * activate for this component (likely interaction with the parent
         * payment-step rebind cycle), so we wrap explicitly.
         */
        initialize: function () {
            this._super();
            this.snapshotVersion = ko.observable(this.snapshotVersion || '');
            this.publishedAt = ko.observable(this.publishedAt || '');
            this.downloadAnnexIbUrl = ko.observable(this.downloadAnnexIbUrl || '');
            this.downloadLabel = ko.observable(this.downloadLabel || '');
            this.downloadHint = ko.observable(this.downloadHint || '');
            this.heading = ko.observable(this.heading || '');
            this.subhead = ko.observable(this.subhead || '');
            this.accordionTitle = ko.observable(this.accordionTitle || '');
            this.footerText = ko.observable(this.footerText || '');
            this.annexIaSections = ko.observableArray(this.annexIaSections || []);
            this.isExpanded = ko.observable(this.isExpanded);
            this.logDisplay();
            return this;
        },

        /**
         * Log display event server-side. Fire-and-forget.
         */
        logDisplay: function () {
            if (this.displayLogged || !this.logEndpointUrl) {
                return;
            }
            var formKey = $.cookie ? $.cookie('form_key') : (document.cookie.match(/form_key=([^;]+)/) || [])[1];
            var data = new URLSearchParams();
            data.append('checkout_step', 'payment');
            if (formKey) {
                data.append('form_key', formKey);
            }
            this.displayLogged = true;
            fetch(this.logEndpointUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString(),
            }).catch(function (err) {
                console.warn('precontract logDisplay failed:', err);
            });
        },

        /**
         * Toggle accordion.
         */
        toggleExpand: function () {
            this.isExpanded(!this.isExpanded());
        },
    });
});
