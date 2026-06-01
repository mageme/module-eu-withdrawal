<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\RequestNote\RequestNote;
use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\User\Model\UserFactory;

/**
 * Internal-notes section, rendered in its original place — the left column of
 * the General ("Information") tab — via the name-lookup seam in
 * request/tab/general.phtml. First-class Free feature backed by
 * mm_eu_withdrawal_request_note; Pro additionally forensic-logs each note.
 */
class Notes extends Template
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param RequestNoteRepository $noteRepository
     * @param TimezoneInterface $timezone
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly RequestNoteRepository $noteRepository,
        private readonly TimezoneInterface $timezone,
        private readonly UserFactory $userFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /** @var array<int, string> */
    private array $adminNameCache = [];

    private function getRequest_(): ?RequestInterface
    {
        $r = $this->registry->registry('mageme_eu_withdrawal_current_request');
        return $r instanceof RequestInterface ? $r : null;
    }

    /**
     * Get request id.
     *
     * @return int
     */
    public function getRequestId(): int
    {
        $r = $this->getRequest_();
        return $r === null ? 0 : (int) $r->getRequestId();
    }

    /**
     * Whether the request is in a terminal state (hides the add-note form).
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        $r = $this->getRequest_();
        if ($r === null) {
            return true;
        }
        return in_array($r->getStatus(), [
            RequestInterface::STATUS_APPROVED,
            RequestInterface::STATUS_DENIED,
            RequestInterface::STATUS_CANCELLED,
            RequestInterface::STATUS_ANONYMISED,
        ], true);
    }

    /**
     * Notes for the current request, newest first.
     *
     * @return RequestNote[]
     */
    public function getNotes(): array
    {
        $id = $this->getRequestId();
        return $id > 0 ? $this->noteRepository->getByRequest($id) : [];
    }

    /**
     * Get add note url.
     *
     * @return string
     */
    public function getAddNoteUrl(): string
    {
        return $this->getUrl(
            'mageme_eu_withdrawal/request/addNote',
            ['request_id' => $this->getRequestId()],
        );
    }

    /**
     * Format a stored UTC timestamp for admin display.
     *
     * @param string $iso
     * @return string
     */
    public function formatIsoDate(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return $this->timezone->formatDateTime(
                new \DateTime($iso, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT,
            );
        } catch (\Throwable) {
            return $iso;
        }
    }

    /**
     * Human-readable author label for a note: the admin user's name when an
     * admin wrote it, falling back to the author type + id.
     *
     * @param RequestNote $note
     * @return string
     */
    public function getAuthorLabel(RequestNote $note): string
    {
        $type = $note->getAuthorType();
        $id = $note->getAuthorId();
        if ($type === 'admin' && $id !== null && ctype_digit($id)) {
            $name = $this->resolveAdminName((int) $id);
            if ($name !== '') {
                return $name;
            }
        }
        return $type . ($id !== null && $id !== '' ? ' (#' . $id . ')' : '');
    }

    /**
     * Resolve an admin user's display name (full name, else username) by id.
     *
     * @param int $userId
     * @return string
     */
    private function resolveAdminName(int $userId): string
    {
        if (!array_key_exists($userId, $this->adminNameCache)) {
            $user = $this->userFactory->create()->load($userId);
            $name = '';
            if ((int) $user->getId() === $userId) {
                $name = trim((string) $user->getName());
                if ($name === '') {
                    $name = (string) $user->getUserName();
                }
            }
            $this->adminNameCache[$userId] = $name;
        }
        return $this->adminNameCache[$userId];
    }
}
