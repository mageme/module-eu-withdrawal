<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\RefundBreakdownInterface;

class CreateRequestResult
{
    /**
     * @param int[] $itemIds
     */
    private function __construct(
        private readonly bool $success,
        private readonly ?int $requestId,
        private readonly array $itemIds,
        private readonly ?RefundBreakdownInterface $breakdown,
        private readonly ?int $storeId = null,
    ) {
    }

    /**
     * @param int[] $itemIds
     */
    public static function success(
        int $requestId,
        array $itemIds,
        RefundBreakdownInterface $breakdown,
        int $storeId,
    ): self {
        return new self(true, $requestId, $itemIds, $breakdown, $storeId);
    }

    /**
     * Silent failure.
     *
     * @return self
     */
    public static function silentFailure(): self
    {
        return new self(false, null, [], null, null);
    }

    /**
     * Is success.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get request id.
     *
     * @return ?int
     */
    public function getRequestId(): ?int
    {
        return $this->requestId;
    }

    /** @return int[] */
    public function getItemIds(): array
    {
        return $this->itemIds;
    }

    /**
     * Get breakdown.
     *
     * @return ?RefundBreakdownInterface
     */
    public function getBreakdown(): ?RefundBreakdownInterface
    {
        return $this->breakdown;
    }

    /**
     * @return ?int
     */
    public function getStoreId(): ?int
    {
        return $this->storeId;
    }
}
