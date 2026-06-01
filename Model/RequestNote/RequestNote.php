<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\RequestNote;

use MageMe\EUWithdrawal\Api\Data\RequestNoteInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\RequestNote as RequestNoteResource;
use Magento\Framework\Model\AbstractModel;

class RequestNote extends AbstractModel implements RequestNoteInterface
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(RequestNoteResource::class);
    }

    /** Get note id. */
    public function getNoteId(): ?int
    {
        $v = $this->getData(self::NOTE_ID);
        return $v === null ? null : (int) $v;
    }

    /** Set note id. */
    public function setNoteId(?int $noteId): self
    {
        $this->setData(self::NOTE_ID, $noteId);
        return $this;
    }

    /** Get request id. */
    public function getRequestId(): int
    {
        return (int) $this->getData(self::REQUEST_ID);
    }

    /** Set request id. */
    public function setRequestId(int $requestId): self
    {
        $this->setData(self::REQUEST_ID, $requestId);
        return $this;
    }

    /** Get author type. */
    public function getAuthorType(): string
    {
        return (string) $this->getData(self::AUTHOR_TYPE);
    }

    /** Set author type. */
    public function setAuthorType(string $authorType): self
    {
        $this->setData(self::AUTHOR_TYPE, $authorType);
        return $this;
    }

    /** Get author id. */
    public function getAuthorId(): ?string
    {
        $v = $this->getData(self::AUTHOR_ID);
        return $v === null ? null : (string) $v;
    }

    /** Set author id. */
    public function setAuthorId(?string $authorId): self
    {
        $this->setData(self::AUTHOR_ID, $authorId);
        return $this;
    }

    /** Get note text. */
    public function getNoteText(): string
    {
        return (string) $this->getData(self::NOTE_TEXT);
    }

    /** Set note text. */
    public function setNoteText(string $noteText): self
    {
        $this->setData(self::NOTE_TEXT, $noteText);
        return $this;
    }

    /** Get created at. */
    public function getCreatedAt(): string
    {
        return (string) $this->getData(self::CREATED_AT);
    }

    /** Set created at. */
    public function setCreatedAt(string $createdAt): self
    {
        $this->setData(self::CREATED_AT, $createdAt);
        return $this;
    }
}
