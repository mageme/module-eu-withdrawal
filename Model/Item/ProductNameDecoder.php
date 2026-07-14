<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * `sales_order_item.name` stores HTML, not text: Magento's own sample data holds
 * "Quest Lumaflex&trade; Band". A PHP template renders that correctly because
 * `escapeHtml()` leaves existing entities alone, but the same string handed to
 * JavaScript ends up in `textContent` or an `aria-label`, where the consumer
 * reads the entity itself. Decoding once, at the point the name leaves the
 * order item, gives every surface plain text.
 *
 * The result is plain text and never safe HTML: a stored `&lt;/script&gt;`
 * decodes to a literal `</script>`. Every consumer must therefore keep escaping
 * on output — `escapeHtml` / `escapeHtmlAttr` in a template, `textContent` or
 * Alpine `x-text` in JavaScript, and `JSON_HEX_TAG` when the value is encoded
 * into markup. Never hand the result to `innerHTML` or `x-html`.
 */
class ProductNameDecoder
{
    /**
     * Decode HTML entities once. A single pass is deliberate: a name that
     * literally reads "&trade;" is stored double-encoded and must survive as
     * text rather than collapse into the character.
     *
     * @param string $name
     * @return string
     */
    public function decode(string $name): string
    {
        return html_entity_decode($name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
