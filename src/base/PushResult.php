<?php

namespace justinholtweb\erpy\base;

/**
 * What came back when Erpy wrote a document to the ERP.
 *
 * The `remoteId` is the load-bearing field: it is what gets stored in the identity map, and it is
 * the only thing standing between a retried queue job and a duplicate sales order.
 */
class PushResult
{
    public function __construct(
        public bool $success = false,
        public ?string $remoteId = null,
        /** A human-facing secondary key — the order number the ERP assigned, say. */
        public ?string $remoteKey = null,
        public ?string $message = null,
        /** Whether the ERP told us this document already existed. */
        public bool $duplicate = false,
        /** Whether retrying could plausibly work. Validation failures set this false. */
        public bool $retryable = true,
        public array $raw = [],
    ) {
    }

    public static function ok(string $remoteId, ?string $remoteKey = null, array $raw = []): self
    {
        return new self(success: true, remoteId: $remoteId, remoteKey: $remoteKey, raw: $raw);
    }

    /** The ERP already has this document. Not an error — record the id and move on. */
    public static function alreadyExists(string $remoteId, ?string $remoteKey = null): self
    {
        return new self(success: true, remoteId: $remoteId, remoteKey: $remoteKey, duplicate: true);
    }

    /** The ERP rejected the document itself. Retrying will fail identically, so it dead-letters. */
    public static function rejected(string $message, array $raw = []): self
    {
        return new self(success: false, message: $message, retryable: false, raw: $raw);
    }

    /** The ERP was unreachable, throttled or having a bad day. Worth another go. */
    public static function failed(string $message, array $raw = []): self
    {
        return new self(success: false, message: $message, retryable: true, raw: $raw);
    }
}
