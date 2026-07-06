<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\Receipt\ContentHasherInterface;
use MageMe\EUWithdrawal\Exception\ReceiptBuilderException;
use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use MageMe\EUWithdrawal\Model\Mail\EmailConfig;
use MageMe\EUWithdrawal\Model\Mail\ReceiptTransport;
use MageMe\EUWithdrawal\Model\Notification\DlqAlerter;
use MageMe\EUWithdrawal\Model\Receipt\ReceiptBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;

class ReceiptSendConsumer
{
    public const TABLE_REQUEST = 'mm_eu_withdrawal_request';

    private const XML_RETRY_ATTEMPTS = 'mageme_eu_withdrawal/notifications/receipt/retry_attempts';

    private const TERMINAL_STATUSES = ['sent', 'failed_permanent'];

    /**
     * Receipt statuses a worker may claim for sending. `sending` is deliberately
     * excluded: a row already in flight (or stuck under a live lease) must not be
     * re-claimed, which is what makes the send idempotent under MQ redelivery.
     */
    private const CLAIMABLE_STATUSES = ['pending', 'failed_retry'];

    /**
     * Lease pushed onto `receipt_next_send_at` when a row is claimed, so a worker
     * that dies mid-send releases the row to the retry cron after this window.
     */
    private const CLAIM_LEASE_SECONDS = 300;

    /**
     * Consumer only sends for live requests; a message for any other state was
     * enqueued in error — skip quietly so we don't trip the hash-mismatch →
     * markPermanent → DLQ cascade.
     */
    private const SENDABLE_REQUEST_STATUSES = [RequestInterface::STATUS_PENDING, RequestInterface::STATUS_APPROVED];

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param ReceiptBuilder $builder
     * @param ?ContentHasherInterface $hasher The base module ships unbound; Pro
     *        `MageMe_EUWithdrawalReceiptVerify` provides a `<preference>`.
     *        When null, the consumer does not validate hash and does not
     *        emit a verify URL into the receipt email.
     * @param ReceiptTransport $transport
     * @param DlqAlerter $alerter
     * @param RetryScheduler $scheduler
     * @param ScopeConfigInterface $scopeConfig
     * @param ManagerInterface $eventManager
     * @param Emulation $emulation
     * @param UrlInterface $url
     * @param EmailConfig $emailConfig
     * @param RouteResolver $routeResolver
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ReceiptBuilder $builder,
        private readonly ReceiptTransport $transport,
        private readonly DlqAlerter $alerter,
        private readonly RetryScheduler $scheduler,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ManagerInterface $eventManager,
        private readonly Emulation $emulation,
        private readonly UrlInterface $url,
        private readonly EmailConfig $emailConfig,
        private readonly RouteResolver $routeResolver,
        private readonly ?ContentHasherInterface $hasher = null,
    ) {
    }

    /**
     * Process.
     *
     * @param int $requestId
     * @return void
     */
    public function process(int $requestId): void
    {
        $row = $this->loadRow($requestId);
        if ($row === null) {
            return;
        }
        if (in_array((string) $row['receipt_status'], self::TERMINAL_STATUSES, true)) {
            return;
        }
        if (!in_array((string) $row['status'], self::SENDABLE_REQUEST_STATUSES, true)) {
            return;
        }

        $attempts = (int) $row['receipt_send_attempts'] + 1;

        // Atomically claim the row into a leased `sending` state before the
        // actual email goes out. A concurrent worker or a redelivered MQ message
        // finds 0 affected rows and skips, so the Art. 11a(4) durable-medium
        // email is not sent twice.
        if (!$this->claim($requestId, $attempts)) {
            return;
        }

        $storeId  = (int) $row['store_id'];

        // The email transport resolves a frontend-area template. Consumers run
        // in a neutral/CLI area by default — emulate the order's store in the
        // frontend area so the template resolver finds the right asset.
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $dto = $this->builder->build($requestId);
            $computed = null;
            $verifyUrl = '';

            // Hash validation is Pro-only (forensic tamper-evidence). When
            // ContentHasherInterface is unbound (module-only install) or the row
            // has no stored hash, skip both the equality check and the verify
            // URL — the email's integrity-hash card is hidden via {{depend}}.
            if ($this->hasher !== null && (string) $row['content_hash'] !== '') {
                $computed = $this->hasher->hash($dto);
                if (!hash_equals((string) $row['content_hash'], $computed)) {
                    throw new ReceiptBuilderException(
                        new Phrase('Receipt hash mismatch for request %1', [$requestId]),
                    );
                }
                $verifyUrl = $this->routeResolver->rewriteCanonical(
                    $this->url->getUrl(
                        RouteResolver::CANONICAL_FRONT_NAME . '/verify/index',
                        ['request_id' => $requestId, 'hash' => $computed, '_store' => $storeId],
                    ),
                    $storeId,
                );
            }

            $locale = (string) $row['locale'];

            if (!$this->emailConfig->isEnabled(EmailConfig::TYPE_RECEIPT, $storeId)) {
                // Receipt is still generated and stored — only the email send is gated.
                $this->markSent($requestId, $attempts, (string) $row['customer_email']);
                return;
            }
            $bcc = $this->emailConfig->getBccCsv(EmailConfig::TYPE_RECEIPT, $storeId);

            $this->transport->send(
                toEmail: (string) $row['customer_email'],
                bccCsv: $bcc,
                vars: [
                    'order_increment_id' => (string) $dto->order['increment_id'],
                    'consumer_name'      => (string) $dto->consumer['name'],
                    'refund_total'       => (string) $dto->refund['total'],
                    'verify_url'         => $verifyUrl,
                    'content_hash'       => $computed ?? '',
                ],
                locale: $locale,
                storeId: $storeId,
                requestId: $requestId,
            );

            $this->markSent($requestId, $attempts, (string) $row['customer_email']);
        } catch (ReceiptBuilderException $e) {
            $this->markPermanent($requestId, $attempts, $e);
        } catch (MailException | \RuntimeException $e) {
            $this->scheduleRetryOrPermanent($requestId, $attempts, $e);
        } catch (\Throwable $e) {
            $this->scheduleRetryOrPermanent($requestId, $attempts, $e);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Load row.
     *
     * @param int $requestId
     * @return ?array
     */
    private function loadRow(int $requestId): ?array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from($this->resource->getTableName(self::TABLE_REQUEST))
            ->where('request_id = ?', $requestId);
        $row = $conn->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Claim.
     *
     * Atomically move a claimable row into the leased `sending` state. Returns
     * true only for the single worker whose conditional UPDATE matched; every
     * other concurrent or redelivered attempt gets 0 affected rows and false.
     *
     * @param int $requestId
     * @param int $attempts
     * @return bool
     */
    private function claim(int $requestId, int $attempts): bool
    {
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS);
        $affected = $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE_REQUEST),
            [
                'receipt_status'        => 'sending',
                'receipt_send_attempts' => $attempts,
                'receipt_next_send_at'  => $leaseUntil,
            ],
            [
                'request_id = ?'        => $requestId,
                'receipt_status IN (?)' => self::CLAIMABLE_STATUSES,
            ],
        );
        return (int) $affected === 1;
    }

    /**
     * Mark sent.
     *
     * @param int $requestId
     * @param int $attempts
     * @param string $toEmail
     * @return void
     */
    private function markSent(int $requestId, int $attempts, string $toEmail): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE_REQUEST),
            [
                'receipt_status'        => 'sent',
                'receipt_send_attempts' => $attempts,
                'receipt_next_send_at'  => null,
                'receipt_last_error'    => null,
                'acknowledged_at'       => gmdate('Y-m-d H:i:s'),
            ],
            ['request_id = ?' => $requestId],
        );
        $this->eventManager->dispatch('mageme_eu_withdrawal_audit_receipt_sent', [
            'request_id' => $requestId,
            'email'      => $toEmail,
            'attempts'   => $attempts,
        ]);
    }

    /**
     * Mark permanent.
     *
     * @param int $requestId
     * @param int $attempts
     * @param \Throwable $e
     * @return void
     */
    private function markPermanent(int $requestId, int $attempts, \Throwable $e): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE_REQUEST),
            [
                'receipt_status'        => 'failed_permanent',
                'receipt_send_attempts' => $attempts,
                'receipt_next_send_at'  => null,
                'receipt_last_error'    => substr($e->getMessage(), 0, 500),
            ],
            ['request_id = ?' => $requestId],
        );
        $this->alerter->alert($requestId);
        $this->eventManager->dispatch('mageme_eu_withdrawal_audit_receipt_failed', [
            'request_id'   => $requestId,
            'error_class'  => get_class($e),
            'attempts'     => $attempts,
            'is_permanent' => true,
        ]);
    }

    /**
     * Configured maximum delivery attempts (1-10, default 3).
     *
     * @return int
     */
    private function maxRetryAttempts(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_RETRY_ATTEMPTS);
        return $value > 0 ? min(10, $value) : 3;
    }

    /**
     * Schedule retry or permanent.
     *
     * @param int $requestId
     * @param int $attempts
     * @param \Throwable $e
     * @return void
     */
    private function scheduleRetryOrPermanent(int $requestId, int $attempts, \Throwable $e): void
    {
        $next = $this->scheduler->next(
            $attempts,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $this->maxRetryAttempts(),
        );
        if ($next === null) {
            $this->markPermanent($requestId, $attempts, $e);
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE_REQUEST),
            [
                'receipt_status'        => 'failed_retry',
                'receipt_send_attempts' => $attempts,
                'receipt_next_send_at'  => $next->format('Y-m-d H:i:s'),
                'receipt_last_error'    => substr($e->getMessage(), 0, 500),
            ],
            ['request_id = ?' => $requestId],
        );
    }
}
