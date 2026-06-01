<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverTexts
{
    /**
     * Constructor.
     *
     * @param string $consentText
     * @param string $ackText
     * @param string $subjectTemplate
     */
    public function __construct(
        public readonly string $consentText,
        public readonly string $ackText,
        public readonly string $subjectTemplate = '',
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
            'consent' => $this->consentText,
            'acknowledgment' => $this->ackText,
            'subject_template' => $this->subjectTemplate,
        ];
    }
}
