<?php

namespace justinholtweb\erpy\auth;

use Craft;

/**
 * Log in once, keep the cookie, log in again when it expires.
 *
 * SAP Business One's Service Layer and Acumatica's contract API both work this way, and both
 * punish you for doing it naively: SAP B1 licences are consumed per session and leak if you never
 * log out, and Acumatica counts concurrent logins against the licence too. So the cookie is
 * cached and shared for its full life rather than re-established per request, and both connectors
 * expose an explicit logout.
 */
class SessionAuth extends BaseAuth
{
    /**
     * @param callable $login given the Connection and a Transport, returns
     *                        `['cookie' => string, 'ttl' => int]` or null
     * @param callable|null $logout given the Connection, a Transport and the cookie
     */
    public function __construct(
        private $login,
        private $logout = null,
        private string $header = 'Cookie',
        private array $requiredFields = [],
    ) {
    }

    public function headers(): array
    {
        $cookie = $this->cookie();

        return $cookie !== null ? [$this->header => $cookie] : [];
    }

    public function isConfigured(): bool
    {
        foreach ($this->requiredFields as $field) {
            if ((string)$this->setting($field) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A 401 here almost always means the session timed out rather than that the password is
     * wrong, so it is worth exactly one fresh login before giving up.
     */
    public function reauthenticate(): bool
    {
        $this->cacheForget('session');

        return $this->cookie() !== null;
    }

    public function cookie(): ?string
    {
        $cached = $this->cacheGet('session');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        $result = ($this->login)($this->connection, $this->http());

        if (!is_array($result) || ($result['cookie'] ?? '') === '') {
            Craft::warning('Erpy could not establish a session: ' . ($result['error'] ?? 'no session token returned'), 'erpy');

            return null;
        }

        // A minute of slack, and never longer than half an hour: these servers drop sessions
        // early under memory pressure and a stale cookie costs a whole run.
        $ttl = min(1800, max(60, (int)($result['ttl'] ?? 1500) - 60));
        $this->cacheSet('session', $result['cookie'], $ttl);

        return $result['cookie'];
    }

    /** Hand the licence back. Worth doing: SAP B1 counts sessions, not requests. */
    public function close(): void
    {
        $cookie = $this->cacheGet('session');

        if (is_string($cookie) && $cookie !== '' && $this->logout !== null) {
            try {
                ($this->logout)($this->connection, $this->http(), $cookie);
            } catch (\Throwable $e) {
                Craft::warning('Erpy could not close a session: ' . $e->getMessage(), 'erpy');
            }
        }

        $this->cacheForget('session');
    }
}
