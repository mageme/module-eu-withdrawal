<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\RequestNote;

use MageMe\EUWithdrawal\Api\Data\RequestNoteInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\RequestNote as RequestNoteResource;
use MageMe\EUWithdrawal\Model\ResourceModel\RequestNote\CollectionFactory;

/**
 * Operational store for admin internal notes. First-class Free state — the
 * Pro audit log forensically mirrors each note via the
 * `mageme_eu_withdrawal_audit_admin_note_added` event (not a dual write).
 */
class RequestNoteRepository
{
    /**
     * Constructor.
     *
     * @param RequestNoteFactory $noteFactory
     * @param RequestNoteResource $resource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly RequestNoteFactory $noteFactory,
        private readonly RequestNoteResource $resource,
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Persist a new internal note.
     *
     * @param int $requestId
     * @param string $noteText
     * @param string $authorType
     * @param ?string $authorId
     * @return RequestNote
     */
    public function add(int $requestId, string $noteText, string $authorType, ?string $authorId): RequestNote
    {
        /** @var RequestNote $note */
        $note = $this->noteFactory->create();
        $note->setRequestId($requestId)
            ->setNoteText($noteText)
            ->setAuthorType($authorType)
            ->setAuthorId($authorId);
        $this->resource->save($note);
        return $note;
    }

    /**
     * Notes for a request, newest first.
     *
     * @param int $requestId
     * @return RequestNote[]
     */
    public function getByRequest(int $requestId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(RequestNoteInterface::REQUEST_ID, $requestId)
            ->setOrder(RequestNoteInterface::NOTE_ID, 'DESC');
        return array_values($collection->getItems());
    }
}
