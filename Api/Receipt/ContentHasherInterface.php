<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Receipt;

use MageMe\EUWithdrawal\Model\Receipt\ReceiptDto;

/**
 * Content hasher contract.
 *
 * Implemented by `MageMe_EUWithdrawalReceiptVerify` Pro tier add-on. Free
 * has no default binding — `RequestCreator` and `ReceiptSendConsumer`
 * accept a `?ContentHasherInterface = null` argument and skip hash
 * computation/validation when the interface is not bound. Pro `etc/di.xml`
 * registers a `<preference>` to the local `ContentHasher` implementation.
 */
interface ContentHasherInterface
{
    /**
     * Compute SHA-256 hash over canonicalized DTO contents.
     *
     * @param ReceiptDto $dto
     * @return string 64-char lowercase hex SHA-256
     */
    public function hash(ReceiptDto $dto): string;
}
