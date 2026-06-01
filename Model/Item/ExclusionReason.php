<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

class ExclusionReason
{
    public const CUSTOM_MADE = 'CUSTOM_MADE';
    public const PERISHABLE = 'PERISHABLE';
    public const SEALED_HYGIENE = 'SEALED_HYGIENE';
    public const SEALED_AV = 'SEALED_AV';
    public const NEWSPAPERS = 'NEWSPAPERS';
    public const ACCOMMODATION_EVENT = 'ACCOMMODATION_EVENT';
    public const DIGITAL_WAIVED = 'DIGITAL_WAIVED';
    public const PERIOD_EXPIRED = 'PERIOD_EXPIRED';
    public const ALREADY_WITHDRAWN = 'ALREADY_WITHDRAWN';
    public const PENDING_ONLY = 'PENDING_ONLY';
    public const OTHER = 'OTHER';

    /**
     * Keys are the machine `reason` codes emitted by rules via
     * EligibilityDecisionInterface::withDeny(string $reason, string $exclusionBasis).
     * Consume $decision->getReason() for lookup — NOT getExclusionBasis()
     * (which returns the human-readable article label).
     */
    private const BASIS_TO_CODE = [
        'art_16_c_custom_made' => self::CUSTOM_MADE,
        'art_16_d_perishable' => self::PERISHABLE,
        'art_16_e_sealed_hygiene' => self::SEALED_HYGIENE,
        'art_16_i_sealed_av' => self::SEALED_AV,
        'art_16_j_newspapers' => self::NEWSPAPERS,
        'art_16_l_accommodation' => self::ACCOMMODATION_EVENT,
        'art_16_m_digital_waiver' => self::DIGITAL_WAIVED,
        'period_expired' => self::PERIOD_EXPIRED,
        'already_withdrawn' => self::ALREADY_WITHDRAWN,
        'pending_only' => self::PENDING_ONLY,
    ];

    private const CODE_TO_LABEL = [
        self::CUSTOM_MADE => 'Custom-made to your specifications',
        self::PERISHABLE => 'Perishable goods',
        self::SEALED_HYGIENE => 'Sealed hygiene item — seal broken',
        self::SEALED_AV => 'Sealed audio/video — seal broken',
        self::NEWSPAPERS => 'Newspapers, periodicals, magazines',
        self::ACCOMMODATION_EVENT => 'Accommodation / event on specific date',
        self::DIGITAL_WAIVED => 'Digital content — right waived at purchase',
        self::PERIOD_EXPIRED => 'Withdrawal period has expired',
        self::ALREADY_WITHDRAWN => 'Already withdrawn in a prior request',
        self::PENDING_ONLY => "A draft withdrawal is holding this item. Confirm it via the email we sent, or cancel it in the 'Existing withdrawals' section above.",
        self::OTHER => 'Not eligible for withdrawal',
    ];

    private function __construct()
    {
    }

    /**
     * @param ?string $basis The machine `reason` code from $decision->getReason().
     *                       Null means the item is eligible (no exclusion applies).
     * @return ?string       Null for eligible; a display code (one of the class
     *                       constants) otherwise; OTHER for unrecognised codes.
     */
    public static function fromBasis(?string $basis): ?string
    {
        if ($basis === null) {
            return null;
        }
        return self::BASIS_TO_CODE[$basis] ?? self::OTHER;
    }

    /**
     * Get label.
     *
     * @param string $code
     * @return string
     */
    public static function getLabel(string $code): string
    {
        return self::CODE_TO_LABEL[$code] ?? self::CODE_TO_LABEL[self::OTHER];
    }
}
