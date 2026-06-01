<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface RequestNoteInterface
{
    public const NOTE_ID     = 'note_id';
    public const REQUEST_ID  = 'request_id';
    public const AUTHOR_TYPE = 'author_type';
    public const AUTHOR_ID   = 'author_id';
    public const NOTE_TEXT   = 'note_text';
    public const CREATED_AT  = 'created_at';

    /** Get note id. */
    public function getNoteId(): ?int;

    /** Set note id. */
    public function setNoteId(?int $noteId): self;

    /** Get request id. */
    public function getRequestId(): int;

    /** Set request id. */
    public function setRequestId(int $requestId): self;

    /** Get author type. */
    public function getAuthorType(): string;

    /** Set author type. */
    public function setAuthorType(string $authorType): self;

    /** Get author id. */
    public function getAuthorId(): ?string;

    /** Set author id. */
    public function setAuthorId(?string $authorId): self;

    /** Get note text. */
    public function getNoteText(): string;

    /** Set note text. */
    public function setNoteText(string $noteText): self;

    /** Get created at. */
    public function getCreatedAt(): string;

    /** Set created at. */
    public function setCreatedAt(string $createdAt): self;
}
