define([
    'jquery',
    'ko',
    'uiComponent',
    'Magento_Checkout/js/model/step-navigator',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
], function ($, ko, Component, stepNavigator, alert, $t) {
    'use strict';

    /**
     * Digital-content waiver checkout step (Art. 16(m) Directive 2011/83/EU).
     *
     * Registers itself with `step-navigator` only when the cart actually
     * contains digital items, so non-digital carts never see the step in
     * the progress bar. When registered, sits between Shipping (sortOrder
     * 10) and Review-Payments (sortOrder 30).
     *
     * Each cart item rendered in the step carries a `waiver_text_hash`
     * fetched from the `Controller\Withdraw\Waiver\Context` endpoint —
     * `saveAndProceed` posts it back to `Save` so the merchant has a
     * tamper-evident audit trail of exactly which legal text the customer
     * consented to.
     */
    return Component.extend({
        defaults: {
            template: 'MageMe_EUWithdrawal/checkout/waiver',
            contextUrl: null,
            saveUrl: null,
            hasDigitalContent: false,
            stepCode: 'eu-withdrawal-waiver-step',
            stepTitle: 'Digital content waiver',
            stepSortOrder: 15,
        },

        initialize: function () {
            this._super();
            this.items = ko.observableArray([]);
            this.consentExpress = ko.observable(false);
            this.lossAck = ko.observable(false);
            this.loaded = ko.observable(false);
            this.isVisible = ko.observable(false);
            this.registered = false;

            var self = this;
            this.canProceed = ko.computed(function () {
                return self.loaded()
                    && self.consentExpress()
                    && self.lossAck()
                    && self.items().length > 0;
            });

            // Register before the progress bar picks the first step, using the
            // server-computed flag. `loadContext` still runs to populate the
            // consent texts/hashes (and registers as a fallback if the flag is
            // absent but the cart does carry digital items).
            if (this.hasDigitalContent) {
                this.registerStep();
            }
            this.loadContext();
            return this;
        },

        registerStep: function () {
            if (this.registered) { return; }
            stepNavigator.registerStep(
                this.stepCode,
                null,
                $t(this.stepTitle),
                this.isVisible,
                $.proxy(this.navigate, this),
                this.stepSortOrder
            );
            this.registered = true;
        },

        loadContext: function () {
            var self = this;
            $.getJSON(this.contextUrl)
                .done(function (resp) {
                    self.items(resp.items || []);
                    self.loaded(true);
                    if (self.items().length > 0 && !self.registered) {
                        self.registerStep();
                    }
                });
        },

        /**
         * Refresh `items` (esp. `waiver_text_hash`) when the customer reaches
         * this step. Initial init happens before the shipping address is set,
         * so the jurisdiction is `__eu_generic__` and the hash differs from
         * what Save computes. Re-fetching here lets the hash track the now-
         * known billing country.
         */
        navigate: function () {
            this.isVisible(true);
            var self = this;
            $.getJSON(this.contextUrl).done(function (resp) {
                self.items(resp.items || []);
            });
        },

        saveAndProceed: function () {
            if (!this.canProceed()) { return; }
            var self = this;
            // The hash is jurisdiction-bound and the address commit races with
            // step entry — fetch the freshest context and post its hashes in
            // one go instead of trusting the copy cached at navigation time.
            $.getJSON(this.contextUrl)
                .done(function (resp) {
                    self.items(resp.items || []);
                    self.postConsent();
                })
                .fail(function () {
                    alert({ content: $t('Waiver could not be saved. Please retry.') });
                });
        },

        postConsent: function () {
            var payload = {
                consent_express: this.consentExpress(),
                loss_ack: this.lossAck(),
                items: this.items().map(function (i) {
                    return {
                        quote_item_id: i.quote_item_id,
                        waiver_text_hash: i.waiver_text_hash,
                    };
                }),
            };
            $.ajax({
                url: this.saveUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
            })
                .done(function () {
                    // stepNavigator.next() handles isVisible toggling for us:
                    // it hides every currently-visible step and shows the next.
                    stepNavigator.next();
                })
                .fail(function () {
                    alert({ content: $t('Waiver could not be saved. Please retry.') });
                });
        },
    });
});
