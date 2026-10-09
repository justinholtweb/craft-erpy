<?php

namespace justinholtweb\erpy\auth;

use Craft;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;

/**
 * OAuth 2.0 authorization code, with refresh.
 *
 * Exact Online, Visma.net and MYOB all make you do the consent dance once, in a browser, as a
 * named user — so Erpy has to own a redirect URI, store a refresh token, and rotate it. Exact in
 * particular hands back a *new* refresh token on every refresh and invalidates the old one, which
 * is why the refresh path writes to the connection row rather than assuming the stored token
 * stays valid.
 */
class OAuth2AuthorizationCode extends BaseAuth
{
    private const EXPIRY_SLACK = 60;

    /**
     * @param string|callable $authorizeUrl where the merchant is sent to consent
     * @param string|callable $tokenUrl where codes and refresh tokens are exchanged
     */
    public function __construct(
        private mixed $authorizeUrl,
        private mixed $tokenUrl,
        private mixed $scope = null,
        private string $clientIdField = 'clientId',
        private string $clientSecretField = 'clientSecret',
        private array $extraAuthorizeParams = [],
        private array $extraTokenParams = [],
    ) {
    }

    public function headers(): array
    {
        $token = $this->accessToken();

        return $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function isConfigured(): bool
    {
        return $this->hasClientCredentials() && $this->token('refreshToken') !== null;
    }

    /**
     * Whether the app registration is saved — the point at which the merchant can be sent to
     * the consent screen. Consent without a client id is a vendor error page, not a connection.
     */
    public function hasClientCredentials(): bool
    {
        return (string)$this->setting($this->clientIdField) !== ''
            && (string)$this->setting($this->clientSecretField) !== '';
    }

    /**
     * What the connection screen can honestly say about the consent, as a plain array for Twig.
     *
     * `accessExpiresAt` is only known while an access token is cached; `refreshExpiresAt` only
     * for the providers that say (Sage does, Exact does not). Neither is guessed.
     *
     * @return array{authorized:bool,hasClientCredentials:bool,authorizedAt:?\DateTime,accessExpiresAt:?\DateTime,refreshExpiresAt:?\DateTime}
     */
    public function describe(): array
    {
        $at = static function(mixed $timestamp): ?\DateTime {
            return is_numeric($timestamp) && (int)$timestamp > 0
                ? (new \DateTime('@' . (int)$timestamp))->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
                : null;
        };

        $authorized = $this->isAuthorized();

        return [
            'authorized' => $authorized,
            'hasClientCredentials' => $this->hasClientCredentials(),
            'authorizedAt' => $authorized ? $at($this->connection->tokens['obtainedAt'] ?? null) : null,
            'accessExpiresAt' => $authorized && $this->cacheGet('access') !== null ? $at($this->cacheGet('accessExpiresAt')) : null,
            'refreshExpiresAt' => $authorized ? $at($this->connection->tokens['refreshExpiresAt'] ?? null) : null,
        ];
    }

    /** Whether the merchant has completed the consent step at least once. */
    public function isAuthorized(): bool
    {
        return $this->token('refreshToken') !== null;
    }

    public function reauthenticate(): bool
    {
        $this->cacheForget('access');

        return $this->refresh();
    }

    public function accessToken(): ?string
    {
        $cached = $this->cacheGet('access');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refresh() ? (string)$this->cacheGet('access') : null;
    }

    /**
     * The URL to send the merchant to. `state` carries the connection so the callback knows which
     * of several connections it is finishing.
     */
    public function authorizationUrl(string $state): string
    {
        $params = array_merge([
            'response_type' => 'code',
            'client_id' => (string)$this->setting($this->clientIdField),
            'redirect_uri' => Plugin::redirectUri(),
            'state' => $state,
        ], $this->extraAuthorizeParams);

        $scope = $this->resolve($this->scope);

        if ($scope) {
            $params['scope'] = $scope;
        }

        $url = (string)$this->resolve($this->authorizeUrl);

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Exchange the code the ERP just handed back for a token pair.
     */
    public function exchangeCode(string $code): bool
    {
        return $this->grant(array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Plugin::redirectUri(),
        ], $this->extraTokenParams));
    }

    public function refresh(): bool
    {
        $refreshToken = $this->token('refreshToken');

        if ($refreshToken === null) {
            return false;
        }

        return $this->grant(array_merge([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $this->extraTokenParams));
    }

    /** Forget both tokens, so the merchant is asked to consent again. */
    public function revoke(): void
    {
        $this->cacheForget('access');
        $this->cacheForget('accessExpiresAt');
        $this->storeTokens(['refreshToken' => null, 'obtainedAt' => null, 'refreshExpiresAt' => null]);
    }

    private function grant(array $params): bool
    {
        $params['client_id'] = (string)$this->setting($this->clientIdField);
        $params['client_secret'] = (string)$this->setting($this->clientSecretField);

        $response = $this->http()->request('POST', (string)$this->resolve($this->tokenUrl), [
            'form' => $params,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (!$response->ok()) {
            Craft::warning('Erpy OAuth grant failed: ' . $response->errorMessage(), 'erpy');

            // A refused refresh is the consent expiring or being revoked: nothing syncs again
            // until somebody presses Reconnect, so it is worth an alert. A refused code exchange
            // has the merchant looking at the screen already.
            if (($params['grant_type'] ?? '') === 'refresh_token') {
                $this->grantRefused($response, Craft::t('erpy', 'Refreshing the OAuth token'));
            }

            return false;
        }

        $accessToken = (string)$response->at('access_token', '');

        if ($accessToken === '') {
            return false;
        }

        $ttl = max(30, (int)$response->at('expires_in', 3600) - self::EXPIRY_SLACK);
        $this->cacheSet('access', $accessToken, $ttl);
        $this->cacheSet('accessExpiresAt', time() + $ttl, $ttl);

        // Rotating providers hand back a fresh refresh token and kill the old one. A provider
        // that does not rotate simply omits it, and the stored one stays good.
        $refreshToken = $response->at('refresh_token');

        if (is_string($refreshToken) && $refreshToken !== '') {
            $refreshTtl = (int)$response->at('refresh_token_expires_in', 0);

            $this->storeTokens([
                'refreshToken' => $refreshToken,
                'obtainedAt' => time(),
                'refreshExpiresAt' => $refreshTtl > 0 ? time() + $refreshTtl : null,
            ]);
        }

        return true;
    }

    private function resolve(mixed $value): ?string
    {
        if (is_callable($value)) {
            return (string)$value($this->connection);
        }

        return $value === null ? null : (string)$value;
    }
}
