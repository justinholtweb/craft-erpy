<?php

namespace justinholtweb\erpy\auth;

use justinholtweb\erpy\base\RequestSigningAuthInterface;

/**
 * NetSuite Token-Based Authentication — OAuth 1.0a, HMAC-SHA256, with the account id as realm.
 *
 * NetSuite is the one holdout that still signs every request, and it is unforgiving about the
 * details: the base string uses the URL *without* its query, every parameter (query and oauth_*)
 * is percent-encoded then sorted as encoded strings, and the account id in the realm is upper
 * case with any hyphen kept. Get one of those wrong and the answer is an opaque
 * `INVALID_LOGIN_ATTEMPT` that says nothing about which one.
 */
class OAuth1Tba extends BaseAuth implements RequestSigningAuthInterface
{
    public function __construct(
        private string $accountField = 'accountId',
        private string $consumerKeyField = 'consumerKey',
        private string $consumerSecretField = 'consumerSecret',
        private string $tokenIdField = 'tokenId',
        private string $tokenSecretField = 'tokenSecret',
        private string $signatureMethod = 'HMAC-SHA256',
    ) {
    }

    public function headers(): array
    {
        // Never used: a signature without a request is meaningless. Transport routes every call
        // through headersForRequest() because this class implements RequestSigningAuthInterface.
        return [];
    }

    public function isConfigured(): bool
    {
        foreach ([
            $this->accountField,
            $this->consumerKeyField,
            $this->consumerSecretField,
            $this->tokenIdField,
            $this->tokenSecretField,
        ] as $field) {
            if ((string)$this->setting($field) === '') {
                return false;
            }
        }

        return true;
    }

    public function headersForRequest(string $method, string $url, array $query = []): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $realm = $this->realm();

        $oauth = [
            'oauth_consumer_key' => (string)$this->setting($this->consumerKeyField),
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => $this->signatureMethod,
            'oauth_timestamp' => (string)time(),
            'oauth_token' => (string)$this->setting($this->tokenIdField),
            'oauth_version' => '1.0',
        ];

        $oauth['oauth_signature'] = $this->sign($method, $url, array_merge($this->flatten($query), $oauth));

        $parts = [sprintf('realm="%s"', $realm)];

        foreach ($oauth as $key => $value) {
            $parts[] = sprintf('%s="%s"', rawurlencode($key), rawurlencode($value));
        }

        return ['Authorization' => 'OAuth ' . implode(', ', $parts)];
    }

    /**
     * NetSuite's realm is the account id, upper-cased. Sandbox accounts keep their `_SB1` suffix
     * with an underscore here even though the host name spells it with a hyphen.
     */
    public function realm(): string
    {
        return strtoupper(str_replace('-', '_', (string)$this->setting($this->accountField)));
    }

    private function sign(string $method, string $url, array $params): string
    {
        // The base string uses the URL stripped of its query; the query arrives through $params.
        $baseUrl = strtok($url, '?') ?: $url;

        $encoded = [];

        foreach ($params as $key => $value) {
            $encoded[rawurlencode((string)$key)] = rawurlencode((string)$value);
        }

        // Sorted by the *encoded* key, which is not always the same order as the raw one.
        ksort($encoded, SORT_STRING);

        $pairs = [];

        foreach ($encoded as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $baseString = implode('&', [
            strtoupper($method),
            rawurlencode($baseUrl),
            rawurlencode(implode('&', $pairs)),
        ]);

        $key = rawurlencode((string)$this->setting($this->consumerSecretField))
            . '&'
            . rawurlencode((string)$this->setting($this->tokenSecretField));

        $algorithm = $this->signatureMethod === 'HMAC-SHA1' ? 'sha1' : 'sha256';

        return base64_encode(hash_hmac($algorithm, $baseString, $key, true));
    }

    /**
     * Query values can arrive as arrays; OAuth signs scalars.
     */
    private function flatten(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $out[$key] = (string)$item;
                }
                continue;
            }

            if ($value === null || is_bool($value)) {
                $out[$key] = $value === true ? 'true' : ($value === false ? 'false' : '');
                continue;
            }

            $out[$key] = (string)$value;
        }

        return $out;
    }
}
