<?php

namespace justinholtweb\erpy\auth;

use Craft;
use justinholtweb\erpy\models\Connection;

/**
 * OAuth 2.0 client credentials — the service-to-service grant.
 *
 * This is the right shape for an ERP integration and the one most of these vendors now push you
 * towards: no user sits in front of a nightly stock sync, so there is nobody to bounce through a
 * consent screen. Business Central (via Entra ID), NetSuite's M2M grant and several others land
 * here.
 *
 * Access tokens are cached, never stored, and re-fetched a minute before they expire so a long
 * run does not die three pages from the end.
 */
class OAuth2ClientCredentials extends BaseAuth
{
    /** Refresh this many seconds before the token actually expires. */
    private const EXPIRY_SLACK = 60;

    /**
     * @param string|callable $tokenUrl a URL, or a closure given the Connection
     * @param string|callable|null $scope a scope string, or a closure given the Connection
     * @param array $extraParams anything else the vendor's token endpoint insists on
     */
    public function __construct(
        private mixed $tokenUrl,
        private mixed $scope = null,
        private string $clientIdField = 'clientId',
        private string $clientSecretField = 'clientSecret',
        private array $extraParams = [],
        private bool $sendCredentialsInBody = true,
    ) {
    }

    public function headers(): array
    {
        $token = $this->accessToken();

        return $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting($this->clientIdField) !== ''
            && (string)$this->setting($this->clientSecretField) !== '';
    }

    /**
     * A 401 on a client-credentials grant means the cached token went stale early — a tenant
     * revoked it, or the clock drifted. Drop it and let the next call mint a new one.
     */
    public function reauthenticate(): bool
    {
        $this->cacheForget('access');

        return $this->accessToken() !== null;
    }

    public function accessToken(): ?string
    {
        $cached = $this->cacheGet('access');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        $params = array_merge([
            'grant_type' => 'client_credentials',
        ], $this->extraParams);

        $scope = $this->resolve($this->scope);

        if ($scope) {
            $params['scope'] = $scope;
        }

        $options = ['form' => $params];

        if ($this->sendCredentialsInBody) {
            $options['form']['client_id'] = (string)$this->setting($this->clientIdField);
            $options['form']['client_secret'] = (string)$this->setting($this->clientSecretField);
        } else {
            $options['headers'] = [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->setting($this->clientIdField) . ':' . $this->setting($this->clientSecretField),
                ),
            ];
        }

        $response = $this->http()->request('POST', (string)$this->resolve($this->tokenUrl), $options);

        if (!$response->ok()) {
            Craft::warning('Erpy could not get an access token: ' . $response->errorMessage(), 'erpy');
            $this->grantRefused($response, Craft::t('erpy', 'Getting an access token'));

            return null;
        }

        $token = (string)$response->at('access_token', '');

        if ($token === '') {
            return null;
        }

        $expiresIn = (int)$response->at('expires_in', 3600);
        $this->cacheSet('access', $token, max(30, $expiresIn - self::EXPIRY_SLACK));

        return $token;
    }

    private function resolve(mixed $value): ?string
    {
        if (is_callable($value)) {
            return (string)$value($this->connection);
        }

        return $value === null ? null : (string)$value;
    }
}
