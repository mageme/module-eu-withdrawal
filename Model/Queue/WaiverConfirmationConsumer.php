<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

use MageMe\EUWithdrawal\Model\Notification\DlqAlerter;
use MageMe\EUWithdrawal\Model\Waiver\PerformanceDetector;
use MageMe\EUWithdrawal\Model\Waiver\WaiverConfirmationBuilder;
use MageMe\EUWithdrawal\Model\Waiver\WaiverConfirmationDto;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEmailRenderer;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventWriter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class WaiverConfirmationConsumer
{
    public const XML_BCC = 'mageme_eu_withdrawal/digital_waiver/email/bcc_merchant';
    public const XML_VIRTUAL_TIMER_ENABLED = 'mageme_eu_withdrawal/digital_waiver/virtual_timer_enabled';

    /**
     * Constructor.
     *
     * @param OrderRepositoryInterface $orderRepo
     * @param WaiverConfirmationBuilder $builder
     * @param WaiverEmailRenderer $renderer
     * @param WaiverEventWriter $writer
     * @param PerformanceDetector $performance
     * @param WaiverConfirmationStateRepository $stateRepo
     * @param RetryScheduler $retryScheduler
     * @param DlqAlerter $dlqAlerter
     * @param ScopeConfigInterface $config
     * @param LoggerInterface $logger
     * @param EventManager $eventManager
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly WaiverConfirmationBuilder $builder,
        private readonly WaiverEmailRenderer $renderer,
        private readonly WaiverEventWriter $writer,
        private readonly PerformanceDetector $performance,
        private readonly WaiverConfirmationStateRepository $stateRepo,
        private readonly RetryScheduler $retryScheduler,
        private readonly DlqAlerter $dlqAlerter,
        private readonly ScopeConfigInterface $config,
        private readonly LoggerInterface $logger,
        private readonly EventManager $eventManager,
    ) {
    }

    /**
     * Process.
     *
     * @param int $orderId
     * @return void
     */
    public function process(int $orderId): void
    {
        $state = $this->stateRepo->getByOrderId($orderId);
        if ($state !== null && $state->status === WaiverConfirmationState::STATUS_SENT) {
            return;
        }
        try {
            $order = $this->orderRepo->get($orderId);
            $dto = $this->builder->build($order);
            $storeId = (int) $order->getStoreId();

            $this->sendEmails($dto, $storeId);
            $this->writeConfirmationEvents($orderId, $dto);
            $this->stateRepo->markSent($orderId);
            $this->eventManager->dispatch('mageme_eu_withdrawal_audit_waiver_confirmation_sent', [
                'order_id' => $orderId,
                'email'    => $dto->customerEmail,
                'attempts' => ($state?->attempts ?? 0) + 1,
            ]);
            $this->maybeStartVirtualTimers($orderId, $dto, $storeId);
        } catch (\Throwable $e) {
            // Never re-throw: re-throwing makes Magento's MQ consumer reject(false)
            // and drop the message, so the Art. 11a(4) confirmation is lost with no
            // record. Persist retry/permanent state instead (MQ acks); the retry
            // cron republishes due rows. Mirrors ReceiptSendConsumer.
            $this->scheduleRetryOrDlq($orderId, $state, $e);
        }
    }

    /**
     * Send emails.
     *
     * @param WaiverConfirmationDto $dto
     * @param int $storeId
     * @return void
     */
    private function sendEmails(WaiverConfirmationDto $dto, int $storeId): void
    {
        $this->renderer->renderConsumer($dto, $storeId)->sendMessage();

        $bccTo = (string) $this->config->getValue(
            self::XML_BCC,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($bccTo !== '') {
            $this->renderer->renderMerchantBcc($dto, $storeId, $bccTo)->sendMessage();
        }
    }

    /**
     * Write confirmation events.
     *
     * @param int $orderId
     * @param WaiverConfirmationDto $dto
     * @return void
     */
    private function writeConfirmationEvents(int $orderId, WaiverConfirmationDto $dto): void
    {
        foreach ($dto->items as $item) {
            $this->writer->write([
                'order_id'             => $orderId,
                'order_item_id'        => $item->orderItemId,
                'event_type'           => WaiverEventReader::EVT_CONFIRM,
                'consent_value'        => 1,
                'product_sku'          => $item->sku,
                'confirmation_sent_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Maybe start virtual timers.
     *
     * @param int $orderId
     * @param WaiverConfirmationDto $dto
     * @param int $storeId
     * @return void
     */
    private function maybeStartVirtualTimers(int $orderId, WaiverConfirmationDto $dto, int $storeId): void
    {
        $virtualTimerOn = (bool) $this->config->getValue(
            self::XML_VIRTUAL_TIMER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($virtualTimerOn) {
            foreach ($dto->items as $item) {
                if ($item->productType === 'virtual') {
                    $this->performance->markStarted(
                        $orderId,
                        $item->orderItemId,
                        PerformanceDetector::TRIGGER_VIRTUAL_TIMER
                    );
                }
            }
        }
    }

    /**
     * Schedule retry or dlq.
     *
     * @param int $orderId
     * @param ?WaiverConfirmationState $state
     * @param \Throwable $e
     * @return void
     */
    private function scheduleRetryOrDlq(int $orderId, ?WaiverConfirmationState $state, \Throwable $e): void
    {
        $attempts = ($state?->attempts ?? 0) + 1;
        $nextAt = $this->retryScheduler->next(
            $attempts,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        if ($nextAt === null) {
            $this->stateRepo->markPermanent($orderId, $e->getMessage());
            $this->dlqAlerter->alert($orderId);
            return;
        }
        $this->logger->warning(
            'WaiverConfirmationConsumer: confirmation send failed, retry scheduled: ' . $e->getMessage(),
            ['order_id' => $orderId, 'attempt' => $attempts],
        );
        $this->stateRepo->markRetry(
            $orderId,
            $attempts,
            $nextAt->format('Y-m-d H:i:s'),
            $e->getMessage()
        );
    }
}
