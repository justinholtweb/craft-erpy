<?php

namespace justinholtweb\erpy\base;

use justinholtweb\erpy\models\Connection;

/**
 * How a connector proves who it is.
 *
 * Thirteen ERPs use, between them, roughly six authentication schemes and no two spell any of
 * them the same way. Erpy ships one implementation of each so a connector picks a strategy
 * instead of writing a token refresh loop for the fourteenth time.
 */
interface AuthInterface
{
    public function setConnection(Connection $connection): void;

    /**
     * Headers to merge into every request. Called per request, so a strategy that refreshes an
     * expired token can do it here and the connector never notices.
     */
    public function headers(): array;

    /** Query parameters to merge into every request, for the schemes that authenticate that way. */
    public function query(): array;

    /**
     * Called when the ERP answers 401. Returning true means "I have fixed it, try once more" —
     * a refreshed access token, a re-established session cookie. Returning false gives up.
     */
    public function reauthenticate(): bool;

    /** Whether enough credentials are present to attempt a request at all. */
    public function isConfigured(): bool;
}
