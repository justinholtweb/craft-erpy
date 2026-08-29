<?php

namespace justinholtweb\erpy\base;

use justinholtweb\erpy\models\Connection;

/**
 * The contract every ERP add-on implements.
 *
 * The whole point of Erpy is that this interface is small and the engine behind it is not. A
 * connector translates between one ERP's wire format and Erpy's canonical documents, and does
 * nothing else: no queueing, no retrying, no logging, no field mapping, no CP screens, no
 * scheduling, no identity map. All of that is the gateway's job, once, for everybody.
 */
interface ConnectorInterface
{
    /** Stable, kebab-case, never changes — it is what connections store. */
    public static function handle(): string;

    /** How the ERP calls itself, spelled the way its own marketing spells it. */
    public static function displayName(): string;

    /** The software vendor, for grouping in the connector picker. */
    public static function vendor(): string;

    /** One sentence a merchant can use to tell this apart from its siblings. */
    public static function description(): string;

    /** Where the merchant goes to create the credentials this connector asks for. */
    public static function setupUrl(): ?string;

    /** What this connector can and cannot do. Erpy builds the whole UI from this. */
    public static function capabilities(): Capabilities;

    /** The credential form, in Erpy's declarative field vocabulary. */
    public static function settingsFields(): array;

    public function setConnection(Connection $connection): void;

    public function getConnection(): Connection;

    /**
     * Fetch one page of records from the ERP and return them as canonical documents.
     *
     * Connectors are not asked to loop: the engine calls this repeatedly, feeding back the cursor
     * from the previous page, until a page comes back without one.
     */
    public function fetchPage(string $entity, FetchCriteria $criteria): Page;

    /**
     * Write one canonical document to the ERP.
     *
     * `$remoteId` is non-null when the identity map already knows this document, which is the
     * connector's signal to update rather than create.
     */
    public function pushDocument(string $entity, object $document, ?string $remoteId = null): PushResult;

    /**
     * Prove the credentials work, in a way a merchant can read.
     */
    public function test(): HealthResult;
}
