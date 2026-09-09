<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\ViewModel\Adminhtml;

use Magento\AdminNotification\Model\ResourceModel\Inbox\Collection\UnreadFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Markup-free accessor for the newest unread MageMe message already sitting in
 * Magento's admin notification inbox, for the announcement bar on the request
 * grid. Nothing is fetched over HTTP here: MageMe\Core\Config\Feed pulls the
 * vendor feed into that inbox, and this only reads the newest row back.
 *
 * The message kind comes from the `utm_content` marker on the feed link, which
 * is the one field the core feed parser keeps verbatim. An absent or unknown
 * marker falls back to the generic call to action.
 */
class FeedNotice implements ArgumentInterface
{
    private const MARKER = 'utm_content';

    private const VENDOR_URL_PREFIX = 'https://mageme.com/';

    /**
     * ACL the native ajaxMarkAsRead action behind the dismiss control requires.
     */
    private const DISMISS_RESOURCE = 'Magento_AdminNotification::mark_as_read';

    /**
     * Known markers. A marker outside this list is treated as absent.
     */
    private const KINDS = ['blog' => true, 'news' => true, 'release' => true, 'services' => true, 'security' => true];

    private ?DataObject $notice = null;

    /**
     * Constructor.
     *
     * @param UnreadFactory $unreadCollectionFactory
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly UnreadFactory $unreadCollectionFactory,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    /**
     * Whether there is a message to render.
     *
     * @return bool
     */
    public function hasNotice(): bool
    {
        return $this->getId() > 0;
    }

    /**
     * Whether this admin role may mark the message read.
     *
     * @return bool
     */
    public function canDismiss(): bool
    {
        return $this->authorization->isAllowed(self::DISMISS_RESOURCE);
    }

    /**
     * Inbox id of the message, used by the dismiss control.
     *
     * @return int
     */
    public function getId(): int
    {
        return (int) $this->load()->getData('notification_id');
    }

    /**
     * Message headline, verbatim from the feed.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return (string) $this->load()->getData('title');
    }

    /**
     * Target of the call to action.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return (string) $this->load()->getData('url');
    }

    /**
     * Message kind, or an empty string when the feed carries no usable marker.
     *
     * @return string
     */
    public function getKind(): string
    {
        $query = parse_url(html_entity_decode($this->getUrl(), ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }
        parse_str($query, $params);
        $marker = $params[self::MARKER] ?? '';

        return is_string($marker) && isset(self::KINDS[$marker]) ? $marker : '';
    }

    /**
     * Call-to-action label for the message kind.
     *
     * @return Phrase
     */
    public function getLinkLabel(): Phrase
    {
        return match ($this->getKind()) {
            'blog' => __('Read article'),
            'release' => __('See what changed'),
            'services' => __('Learn more'),
            'security' => __('Read advisory'),
            default => __('Read more'),
        };
    }

    /**
     * Native inbox severity: 1 critical, 2 major, 3 minor, 4 notice.
     *
     * @return int
     */
    public function getSeverity(): int
    {
        $severity = (int) $this->load()->getData('severity');

        return $severity >= 1 && $severity <= 4 ? $severity : 4;
    }

    /**
     * Newest unread, non-removed vendor message; an empty object when none.
     *
     * @return DataObject
     */
    private function load(): DataObject
    {
        if ($this->notice === null) {
            $this->notice = $this->unreadCollectionFactory->create()
                ->addFieldToFilter('url', ['like' => self::VENDOR_URL_PREFIX . '%'])
                ->setOrder('date_added', 'DESC')
                ->setOrder('notification_id', 'DESC')
                ->setPageSize(1)
                ->getFirstItem();
        }

        return $this->notice;
    }
}
