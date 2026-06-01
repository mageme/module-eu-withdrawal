<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract;

use MageMe\EUWithdrawal\Model\Precontract\Exception\MissingMerchantVarsException;

/**
 * Pure function: combines an Annex I config (loaded by AnnexIConfigReader)
 * with a merchant-vars associative array and produces a rendered
 * (annex_ia_text, annex_ib_text) tuple. Fails closed if any required
 * placeholder is unresolved.
 */
class AnnexIRenderer
{
    /** @var string[] */
    private const REQUIRED_VARS = [
        'period_days',
        'merchant_name',
        'merchant_address',
        'merchant_email',
        'merchant_return_address',
    ];

    /**
     * Constructor.
     *
     * @param AnnexIConfigReader $reader
     */
    public function __construct(private readonly AnnexIConfigReader $reader)
    {
    }

    /**
     * Render.
     *
     * @param string $locale
     * @param array<string, string> $merchantVars
     * @return array{ia: string, ib: string, ia_sections: array<int, array{id: string, text: string}>}
     * @throws MissingMerchantVarsException
     */
    public function render(string $locale, array $merchantVars): array
    {
        $missing = [];
        foreach (self::REQUIRED_VARS as $key) {
            if (!isset($merchantVars[$key]) || trim((string) $merchantVars[$key]) === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new MissingMerchantVarsException($missing);
        }

        $config = $this->reader->read($locale);

        $renderedSections = [];
        $iaText = '';
        foreach ($config['ia_sections'] as $section) {
            $rendered = $this->interpolate($section['text'], $merchantVars);
            $renderedSections[] = ['id' => $section['id'], 'text' => $rendered];
            $iaText .= $rendered . "\n\n";
        }

        return [
            'ia'          => trim($iaText),
            'ib'          => $this->interpolate($config['ib_text'], $merchantVars),
            'ia_sections' => $renderedSections,
        ];
    }

    /**
     * Interpolate.
     *
     * @param string $template
     * @param array<string, string> $vars
     * @return string
     */
    private function interpolate(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }
        return $out;
    }
}
