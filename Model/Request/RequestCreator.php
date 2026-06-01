<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\EligibilityEngineInterface;
use MageMe\EUWithdrawal\Api\Receipt\ContentHasherInterface;
use MageMe\EUWithdrawal\Exception\ItemCapacityExceededException;
use MageMe\EUWithdrawal\Exception\NoEligibleItemsException;
use MageMe\EUWithdrawal\Model\EligibilityEngine;
use MageMe\EUWithdrawal\Model\EligibilityRequestBuilder;
use MageMe\EUWithdrawal\Model\EligibilitySnapshot;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Lookup\OrderLookupByIncrementId;
use MageMe\EUWithdrawal\Model\Receipt\ReceiptBuilder;
use MageMe\EUWithdrawal\Model\Refund\RefundCalculator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

class RequestCreator
{
    public const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    public const TABLE_ITEM = 'mm_eu_withdrawal_item';

    /**
     * Constructor.
     *
     * @param OrderLookupByIncrementId $orderLookup
     * @param EligibilityEngineInterface $eligibilityEngine
     * @param ResourceConnection $resource
     * @param DateTime $dateTime
     * @param EligibilityRequestBuilder $requestBuilder
     * @param MultiplePartialGuard $guard
     * @param RefundCalculator $calculator
     * @param EventManagerInterface $eventManager
     * @param ScopeConfigInterface $scopeConfig
     * @param ReasonsConfigReader $reasonsConfig
     * @param ReceiptBuilder $receiptBuilder
     * @param ?ContentHasherInterface $contentHasher The base module ships with no binding;
     *        Pro `MageMe_EUWithdrawalReceiptVerify` registers a `<preference>`
     *        in its `etc/di.xml` to satisfy this argument.
     */
    public function __construct(
        private readonly OrderLookupByIncrementId $orderLookup,
        private readonly EligibilityEngineInterface $eligibilityEngine,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly EligibilityRequestBuilder $requestBuilder,
        private readonly MultiplePartialGuard $guard,
        private readonly RefundCalculator $calculator,
        private readonly EventManagerInterface $eventManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReasonsConfigReader $reasonsConfig,
        private readonly ReceiptBuilder $receiptBuilder,
        private readonly ?ContentHasherInterface $contentHasher = null,
    ) {
    }

    /**
     * Create a withdrawal request directly in the `submitted` state: the row,
     * its items, the frozen receipt snapshot and content hash all land in one
     * transaction. Withdrawal must be as easy as purchase (Art. 2 Directive
     * (EU) 2023/2673), so there is no separate confirmation step.
     *
     * @throws ItemCapacityExceededException
     * @throws NoEligibleItemsException
     */
    public function create(CreateRequestInput $input): CreateRequestResult
    {
        $order = $this->loadOrderByIncrementId($input->orderIncrementId);
        if ($order === null) {
            return CreateRequestResult::silentFailure();
        }
        if (strcasecmp((string) $order->getCustomerEmail(), $input->customerEmail) !== 0) {
            return CreateRequestResult::silentFailure();
        }

        $storeId = (int) $order->getStoreId();
        $eligibilityRequest = $this->requestBuilder->build($order, $storeId);
        $result = $this->eligibilityEngine->evaluate($eligibilityRequest);
        $orderDecision = $result->getOrderDecision();
        if ($orderDecision->isFinal() && !$orderDecision->isEligible()) {
            return CreateRequestResult::silentFailure();
        }

        // Resolve items: explicit from input, or "all eligible x full qty" default.
        // Both paths drop Art.16-ineligible items (custom-made 16(c), perishable
        // 16(d)) so they are never recorded or refunded — the disabled item-selector
        // row omits them client-side; this is the server-side guarantee.
        $items = $input->items !== []
            ? $this->filterEligibleItems($input->items, $result->getItemDecisions())
            : $this->defaultFullEligibleItems($order, $result->getItemDecisions());

        if ($items === []) {
            throw new NoEligibleItemsException();
        }

        $ts = time();
        $now = $this->dateTime->gmtDate(null, $ts);

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->guard->assertCapacity($order, $items, null);

            $breakdown = $this->calculator->calculate($order, $items, $result);

            $packedIp = null;
            if ($input->ip !== null) {
                $packed = (filter_var($input->ip, FILTER_VALIDATE_IP) !== false ? inet_pton($input->ip) : false);
                if ($packed !== false) {
                    $packedIp = $packed;
                }
            }

            $isPartial = $this->detectIsPartial($order, $items) ? 1 : 0;

            // Aggregate per-item reasons into a single request-level summary so receipt
            // (PDF/email) keeps working without per-item awareness. Falls back to the
            // legacy reasonText if no per-item data was supplied.
            $aggregatedReason = $this->aggregateReasons($input->itemReasons, $storeId)
                ?? $input->reasonText;

            $connection->insert($this->resource->getTableName(self::TABLE_REQUEST), [
                RequestInterface::ORDER_ID => (int) $order->getEntityId(),
                RequestInterface::STORE_ID => $storeId,
                // Rome I Art. 6: consumer's habitual residence at contract time — derive
                // from the order's billing address, not the browsing store code.
                RequestInterface::JURISDICTION => $this->resolveJurisdiction($order, $input->jurisdiction),
                RequestInterface::CUSTOMER_ID => $input->customerId,
                RequestInterface::CUSTOMER_EMAIL => $input->customerEmail,
                RequestInterface::CUSTOMER_NAME => $input->customerName,
                RequestInterface::CONTRACT_IDENTIFIER => $input->orderIncrementId,
                RequestInterface::STATUS => RequestInterface::STATUS_PENDING,
                RequestInterface::REASON_TEXT => $aggregatedReason,
                RequestInterface::IS_PARTIAL => $isPartial,
                // Use the BCP-47 locale configured on the order's store view, not the
                // browsing store's code (which for Magento's default store is "default").
                RequestInterface::LOCALE => $resolvedLocale = $this->resolveLocale($order, $input->locale),
                RequestInterface::IP => $packedIp,
                RequestInterface::USER_AGENT => mb_substr((string) $input->userAgent, 0, 512),
                RequestInterface::CONTENT_HASH => '',
                // Freeze Art. 13(2) delivery refund at consent time — subsequent order edits
                // must not silently change the already-agreed amount.
                RequestInterface::SHIPPING_REFUND => $breakdown->getShippingRefund() > 0 ? $breakdown->getShippingRefund() : null,
                RequestInterface::SUBMITTED_AT => $now,
                RequestInterface::CONFIRMED_AT => $now,
            ]);

            $requestId = (int) $connection->lastInsertId(
                $this->resource->getTableName(self::TABLE_REQUEST),
            );

            // Human-facing identifier — 9-digit zero-padded request_id with an
            // optional merchant-configured prefix (general/increment_prefix).
            // Persisted to the row so every downstream surface (success page,
            // email template, admin grid) renders the same string without
            // concatenation at the edges.
            $prefix = trim((string) $this->scopeConfig->getValue(
                'mageme_eu_withdrawal/general/increment_prefix',
                ScopeInterface::SCOPE_STORE,
                $storeId,
            ));
            $connection->update(
                $this->resource->getTableName(self::TABLE_REQUEST),
                [RequestInterface::INCREMENT_ID => $prefix . sprintf('%09d', $requestId)],
                ['request_id = ?' => $requestId],
            );

            $itemIds = [];
            foreach ($breakdown->getItems() as $line) {
                $decision = $result->getItemDecisions()[$line->orderItemId] ?? null;
                $eligibility = EligibilitySnapshot::eligibilityFor($decision);
                $exclusionBasis = $decision !== null && !$decision->isEligible()
                    ? $decision->getExclusionBasis()
                    : null;
                $reason = $input->itemReasons[$line->orderItemId] ?? null;
                $connection->insert($this->resource->getTableName(self::TABLE_ITEM), [
                    ItemInterface::REQUEST_ID => $requestId,
                    ItemInterface::ORDER_ITEM_ID => $line->orderItemId,
                    ItemInterface::SKU => $line->sku,
                    ItemInterface::QTY_WITHDRAW => $line->qty,
                    ItemInterface::REFUND_AMOUNT => $line->lineSubtotal + $line->lineTax,
                    ItemInterface::ELIGIBILITY => $eligibility,
                    ItemInterface::EXCLUSION_BASIS => $exclusionBasis,
                    ItemInterface::REASON_CODE => $reason['code'] ?? null,
                    ItemInterface::REASON_TEXT => $reason['text'] ?? null,
                ]);
                $itemIds[] = (int) $connection->lastInsertId(
                    $this->resource->getTableName(self::TABLE_ITEM),
                );
            }

            // Freeze the canonical receipt: ReceiptBuilder reads the just-written
            // row + items to build the DTO whose JSON snapshot is persisted, so a
            // later store-config or order edit can't drift the content hash;
            // ReceiptBuilder reads the snapshot back on every later build. Free
            // (no ReceiptVerify Pro) writes NULL into content_hash and the receipt
            // email's integrity-hash card is hidden via {{depend}}.
            $dto = $this->receiptBuilder->build($requestId);
            $hash = $this->contentHasher !== null ? $this->contentHasher->hash($dto) : null;
            $snapshot = json_encode(
                $dto->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $connection->update(
                $this->resource->getTableName(self::TABLE_REQUEST),
                [
                    RequestInterface::CONTENT_HASH     => $hash,
                    RequestInterface::RECEIPT_SNAPSHOT => $snapshot,
                    RequestInterface::RECEIPT_STATUS   => 'pending',
                ],
                ['request_id = ?' => $requestId],
            );

            $connection->commit();

            $this->eventManager->dispatch('mageme_eu_withdrawal_audit_eligibility_evaluated', [
                'request_id'  => $requestId,
                'decision'    => EligibilityEngine::summarizeDecision(
                    $result->getOrderDecision(),
                    $result->getItemDecisions(),
                ),
                'rules_fired' => EligibilityEngine::collectFiredRules(
                    $result->getOrderDecision(),
                    $result->getItemDecisions(),
                ),
            ]);

            $this->eventManager->dispatch('mageme_eu_withdrawal_audit_request_submitted', [
                'request_id'    => $requestId,
                'order_id'      => (int) $order->getEntityId(),
                'item_count'    => count($items),
                // Use the BCP-47 locale resolved from the order's store view (same
                // value persisted to mm_eu_withdrawal_request.locale above), not the
                // raw browsing store-view code which can be "default".
                'locale'        => $resolvedLocale,
                'jurisdiction'  => $input->jurisdiction,
                'referrer_host' => $input->referrerHost,
                'customer_hash' => hash('sha256', strtolower(trim($input->customerEmail))),
                'ip'            => $input->ip,
                'user_agent'    => $input->userAgent,
            ]);

            // Drives the receipt-send queue, eligibility-snapshot persistence and
            // the merchant new-request alert (see etc/events.xml observers).
            $this->eventManager->dispatch('mageme_eu_withdrawal_request_create_after', [
                'eligibility_result' => $result,
                'request_id'         => $requestId,
                'order_id'           => (int) $order->getEntityId(),
                'order_items'        => $order->getItems() ?? [],
                'submitted_at'       => new \DateTimeImmutable('@' . $ts),
            ]);

            return CreateRequestResult::success(
                requestId: $requestId,
                itemIds: $itemIds,
                breakdown: $breakdown,
                storeId: $storeId,
            );
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Load order by increment id.
     *
     * @param string $incrementId
     * @return ?OrderInterface
     */
    private function loadOrderByIncrementId(string $incrementId): ?OrderInterface
    {
        return $this->orderLookup->find($incrementId);
    }

    /**
     * Resolve jurisdiction.
     *
     * @param OrderInterface $order
     * @param string $fallback
     * @return string
     */
    private function resolveJurisdiction(OrderInterface $order, string $fallback): string
    {
        $billing = $order->getBillingAddress();
        if ($billing !== null) {
            $country = (string) $billing->getCountryId();
            if ($country !== '') {
                return $country;
            }
        }
        $default = (string) $this->scopeConfig->getValue(
            'general/country/default',
            ScopeInterface::SCOPE_STORE,
            (int) $order->getStoreId(),
        );
        if ($default !== '') {
            return $default;
        }
        return $fallback !== '' && $fallback !== 'default' ? $fallback : 'EU';
    }

    /**
     * Resolve locale.
     *
     * @param OrderInterface $order
     * @param string $fallback
     * @return string
     */
    private function resolveLocale(OrderInterface $order, string $fallback): string
    {
        // `general/locale/code` is the BCP-47 tag (e.g. "de_DE") configured per store view,
        // distinct from the store's admin slug / "code" (e.g. "default").
        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            (int) $order->getStoreId(),
        );
        if ($locale !== '' && $locale !== 'default') {
            return $locale;
        }
        return $fallback !== '' && $fallback !== 'default' ? $fallback : 'en_EU';
    }

    /**
     * Keep only explicitly-selected items whose Art.16 eligibility decision
     * permits withdrawal; deny-decisions are dropped.
     *
     * @param array<int, int> $items order_item_id => qty
     * @param array<int, \MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface> $decisions
     * @return array<int, int>
     */
    private function filterEligibleItems(array $items, array $decisions): array
    {
        $out = [];
        foreach ($items as $oid => $qty) {
            $decision = $decisions[(int) $oid] ?? null;
            if ($decision !== null && !$decision->isEligible()) {
                continue;
            }
            $out[$oid] = $qty;
        }
        return $out;
    }

    /**
     * @param array<int, \MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface> $decisions
     * @return array<int, int>
     */
    private function defaultFullEligibleItems(OrderInterface $order, array $decisions): array
    {
        $items = [];
        foreach (($order->getItems() ?? []) as $oi) {
            if ($oi->getParentItemId()) {
                continue;
            }
            $oid = (int) $oi->getItemId();
            $d = $decisions[$oid] ?? null;
            if ($d !== null && !$d->isEligible()) {
                continue;
            }
            $items[$oid] = (int) ((float) $oi->getQtyOrdered());
        }
        return $items;
    }

    /**
     * Aggregate distinct per-item reasons into a single request.reason_text line so the
     * receipt template (which reads request-level reason) keeps showing something useful.
     * Returns null when no item carries a reason — caller falls back to legacy reasonText.
     *
     * @param array<int, array{code: ?string, text: ?string}> $itemReasons
     */
    private function aggregateReasons(array $itemReasons, int $storeId): ?string
    {
        $parts = [];
        foreach ($itemReasons as $r) {
            $label = null;
            $code = $r['code'] ?? null;
            $text = $r['text'] ?? null;
            if ($code !== null && $code !== ReasonsConfigReader::RESERVED_CODE_OTHER) {
                $label = $this->reasonsConfig->resolveLabel($code, $storeId);
            } elseif ($code === ReasonsConfigReader::RESERVED_CODE_OTHER) {
                // Free text wins for "Other"; falls back to the configured "Other" label.
                $label = ($text !== null && trim($text) !== '')
                    ? trim($text)
                    : $this->reasonsConfig->resolveLabel(ReasonsConfigReader::RESERVED_CODE_OTHER, $storeId);
            } elseif ($text !== null && trim($text) !== '') {
                $label = trim($text);
            }
            if ($label !== null && !in_array($label, $parts, true)) {
                $parts[] = $label;
            }
        }
        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * @param array<int, int> $items
     */
    private function detectIsPartial(OrderInterface $order, array $items): bool
    {
        foreach (($order->getItems() ?? []) as $oi) {
            if ($oi->getParentItemId()) {
                continue;
            }
            $oid = (int) $oi->getItemId();
            if (!isset($items[$oid])) {
                return true;
            }
            if ($items[$oid] !== (int) ((float) $oi->getQtyOrdered())) {
                return true;
            }
        }
        return false;
    }
}
