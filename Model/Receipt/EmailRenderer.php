<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Receipt;

class EmailRenderer
{
    private const SUPPORTED = ['en_US', 'de_DE', 'fr_FR'];
    private const FALLBACK  = 'en_US';

    private const STRINGS = [
        'en_US' => [
            'subject'  => 'Withdrawal Receipt — Order #%s',
            'heading'  => 'Your withdrawal has been recorded',
            'intro'    => 'Dear %s, we acknowledge receipt of your withdrawal from contract for order #%s.',
            'refund'   => 'Total refund: %s EUR',
            'verify'   => 'Verify the integrity of this receipt:',
            'regards'  => 'Kind regards,',
        ],
        'de_DE' => [
            'subject'  => 'Widerruf — Bestellung #%s',
            'heading'  => 'Ihr Widerruf wurde registriert',
            'intro'    => 'Sehr geehrte/r %s, wir bestätigen den Eingang Ihres Vertragswiderrufs zur Bestellung #%s.',
            'refund'   => 'Gesamterstattung: %s EUR',
            'verify'   => 'Prüfen Sie die Integrität dieses Belegs:',
            'regards'  => 'Mit freundlichen Grüßen,',
        ],
        'fr_FR' => [
            'subject'  => 'Rétractation — Commande #%s',
            'heading'  => 'Votre rétractation a été enregistrée',
            'intro'    => 'Bonjour %s, nous accusons réception de votre rétractation du contrat pour la commande #%s.',
            'refund'   => 'Remboursement total : %s EUR',
            'verify'   => 'Vérifiez l\'intégrité de ce reçu :',
            'regards'  => 'Cordialement,',
        ],
    ];

    /**
     * Render.
     *
     * @param ReceiptDto $dto
     * @param string $locale
     * @return array
     */
    public function render(ReceiptDto $dto, string $locale): array
    {
        $loc = in_array($locale, self::SUPPORTED, true) ? $locale : self::FALLBACK;
        $t   = self::STRINGS[$loc];

        $orderNo  = $this->escape($dto->order['increment_id']);
        $name     = $this->escape($dto->consumer['name']);
        $refund   = $this->escape($dto->refund['total']);
        $merchant = $this->escape($dto->merchant['name']);

        $subject = sprintf($t['subject'], $orderNo);
        $plain = implode("\n\n", [
            $t['heading'],
            sprintf($t['intro'], $name, $orderNo),
            sprintf($t['refund'], $refund),
            $t['regards'],
            $merchant,
        ]);
        $html = '<html><body>'
            . '<h1>' . $t['heading'] . '</h1>'
            . '<p>' . sprintf($t['intro'], $name, $orderNo) . '</p>'
            . '<p><strong>' . sprintf($t['refund'], $refund) . '</strong></p>'
            . '<p>' . $t['regards'] . '<br/>' . $merchant . '</p>'
            . '</body></html>';

        return ['subject' => $subject, 'html' => $html, 'plain' => $plain];
    }

    /**
     * Escape.
     *
     * @param string $s
     * @return string
     */
    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
