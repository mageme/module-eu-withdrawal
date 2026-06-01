// MageMe_EUWithdrawal — status badge column.
// Extends the stock select column so the filter dropdown still resolves
// option labels from the `<options>` source, but display reads the raw
// (server-rendered HTML) value from the row instead of looking the
// status code up in the option list. The PHP-side
// `Ui/Component/Listing/Column/StatusBadge` wraps the status in a
// `<span class="mageme-eu-w-status-badge ...">` and that HTML is what
// the `ui/grid/cells/html` bodyTmpl renders verbatim.
define([
    'Magento_Ui/js/grid/columns/select'
], (Select) => Select.extend({
    getLabelUnsanitizedHtml(record) {
        return record !== undefined && record[this.index] !== undefined
            ? record[this.index]
            : '';
    },
}));
