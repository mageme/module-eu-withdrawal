<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Bundled icon catalogue for the Email Branding → USP strip. Each entry
 * pairs a stable `value` (used as the saved config value) with a human
 * label shown in the admin dropdown. The actual SVG markup lives next to
 * `Block\Email\Layout::USP_SVGS` so the rendering logic and option list
 * never drift apart — adding a new icon means appending to both maps.
 */
class UspIcon implements OptionSourceInterface
{
    public const ICONS = [
        'truck'   => 'Delivery — truck',
        'return'  => 'Returns — curved arrow',
        'shield'  => 'Security — shield with check',
        'lock'    => 'Secure payment — padlock',
        'gift'    => 'Gift box',
        'star'    => 'Quality — star',
        'headset' => 'Support — headset',
        'clock'   => 'Speed — clock',
        'globe'   => 'International — globe',
        'leaf'    => 'Sustainable — leaf',
        'tag'     => 'Pricing — tag',
        'package' => 'Packaging — box',
    ];

    /**
     * To option array.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $out = [];
        foreach (self::ICONS as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }
        return $out;
    }
}
