<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverContext
{
    /**
     * @param WaiverItem[] $items
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $jurisdiction,
        public readonly WaiverTexts $texts,
        public readonly array $items,
    ) {
    }

    /**
     * To array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'jurisdiction' => $this->jurisdiction,
            'texts' => $this->texts->toArray(),
            'items' => array_map(fn(WaiverItem $i) => $i->toArray(), $this->items),
        ];
    }
}
