<?php

namespace justinholtweb\erpy\base;

use justinholtweb\erpy\models\canonical\ErpOrder;

/**
 * A connector whose ERP fetches orders from Erpy's export URL (`erpy/export/<handle>/orders`)
 * instead of having them sent.
 *
 * Implement this, answer true from `deliversOnRequest(Entity::ORDER)` when the connection is
 * set to be fetched from, declare order push in `capabilities()`, and add
 * `OrderExport::fields()` to `settingsFields()` so the merchant can set the token. The export
 * endpoint does the rest: it finds the orders, passes each through `Push::deliver()` — the
 * identity map, the mutex, the run log, the Problems screen — and hands the ones that were
 * delivered to `renderServed()`. The connector only turns them into its ERP's format.
 *
 * `renderServed()` runs inside the transaction that records those orders as delivered. If it
 * throws, nothing is marked delivered and the ERP gets a 500, so the next poll serves the same
 * orders again.
 */
interface ServesOrders
{
    /**
     * The response body for these orders. May be called with an empty array: a poll that finds
     * nothing new still needs a well-formed, empty document.
     *
     * @param ErpOrder[] $orders
     */
    public function renderServed(array $orders): string;

    /**
     * The `Content-Type` header, charset included, e.g. `application/xml; charset=windows-1250`.
     */
    public function servedContentType(): string;

    /**
     * The file extension the response is offered under (`orders.<ext>`), without the dot.
     */
    public function servedExtension(): string;
}
