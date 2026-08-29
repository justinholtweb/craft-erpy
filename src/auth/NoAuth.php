<?php

namespace justinholtweb\erpy\auth;

/**
 * For connectors whose credentials ride in the URL, or that authenticate per request in a way no
 * shared strategy could describe (Odoo's RPC login, for one).
 */
class NoAuth extends BaseAuth
{
    public function headers(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
