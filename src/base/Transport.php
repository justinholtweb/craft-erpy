<?php

namespace justinholtweb\erpy\base;

use Craft;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * Every byte Erpy exchanges with an ERP goes through here.
 *
 * That is deliberate and it is what makes the log trustworthy: there is no second path a
 * connector could take that would miss being recorded, redacted, retried or throttled. It is also
 * what makes thirteen unverifiable connectors testable — swap in a recorded transport with
 * `setDouble()` and the connector cannot tell the difference.
 */
class Transport
{
    /** Statuses worth another attempt: throttling, and the server having a bad moment. */
    private const RETRY_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * Statuses that mean the ERP turned the request away before acting on it, so even a POST can
     * be sent again. A 502 or a 504 means nothing of the sort: the proxy gave up, the ERP behind
     * it may have finished booking the order.
     */
    private const REFUSED_STATUSES = [425, 429];

    /** Methods that create something, where a second copy of the request is a second document. */
    private const UNSAFE_METHODS = ['POST', 'PATCH'];

    /**
     * curl errors raised before a byte of the request was written: resolving the proxy or the
     * host, connecting, the TLS handshake.
     */
    private const UNSENT_CURL_ERRORS = [5, 6, 7, 35];

    private ?Connection $connection = null;

    private ?AuthInterface $auth = null;

    private string $baseUri = '';

    private array $defaultHeaders = [];

    private int $timeout = 30;

    private int $maxAttempts = 4;

    /** Requests per second the ERP will tolerate. 0 disables throttling. */
    private float $rateLimit = 0;

    private ?float $lastRequestAt = null;

    /** @var callable|null a stand-in that answers requests without touching the network */
    private $double = null;

    private ?int $runId = null;

    /** Redacted before anything is written to the log. */
    private array $secretValues = [];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function setConnection(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function setAuth(?AuthInterface $auth): self
    {
        $this->auth = $auth;

        return $this;
    }

    public function setBaseUri(string $uri): self
    {
        $this->baseUri = rtrim($uri, '/');

        return $this;
    }

    public function setDefaultHeaders(array $headers): self
    {
        $this->defaultHeaders = $headers;

        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function setMaxAttempts(int $attempts): self
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    public function setRateLimit(float $requestsPerSecond): self
    {
        $this->rateLimit = max(0, $requestsPerSecond);

        return $this;
    }

    public function setRunId(?int $runId): self
    {
        $this->runId = $runId;

        return $this;
    }

    public function setSecretValues(array $values): self
    {
        $this->secretValues = array_values(array_filter($values, fn($v) => is_string($v) && strlen($v) >= 6));

        return $this;
    }

    /**
     * Redact one more value from here on — a token the ERP issued, say, rather than one the
     * merchant typed in. Every value an auth strategy contributes to a request is added this way
     * automatically, so a connector only needs this for a credential it handles itself.
     */
    public function addSecretValue(string $value): self
    {
        if (strlen($value) >= 6 && !in_array($value, $this->secretValues, true)) {
            $this->secretValues[] = $value;
        }

        return $this;
    }

    /**
     * Answer requests from a callable instead of the network.
     *
     * The callable receives `(string $method, string $uri, array $options)` and returns a
     * `Response`. This is how the connector suite runs: recorded payloads from each vendor's
     * documentation, replayed through the identical code path a live tenant would take.
     */
    public function setDouble(?callable $double): self
    {
        $this->double = $double;

        return $this;
    }

    public function hasDouble(): bool
    {
        return $this->double !== null;
    }

    public function get(string $uri, array $query = [], array $options = []): Response
    {
        return $this->request('GET', $uri, array_merge($options, ['query' => $query]));
    }

    public function post(string $uri, mixed $body = null, array $options = []): Response
    {
        return $this->request('POST', $uri, array_merge($options, ['json' => $body]));
    }

    public function patch(string $uri, mixed $body = null, array $options = []): Response
    {
        return $this->request('PATCH', $uri, array_merge($options, ['json' => $body]));
    }

    public function put(string $uri, mixed $body = null, array $options = []): Response
    {
        return $this->request('PUT', $uri, array_merge($options, ['json' => $body]));
    }

    public function delete(string $uri, array $options = []): Response
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * The one method. Everything above is sugar.
     */
    public function request(string $method, string $uri, array $options = []): Response
    {
        $method = strtoupper($method);
        $url = $this->absoluteUri($uri);
        $attempt = 0;
        $reauthenticated = false;

        while (true) {
            $attempt++;
            $this->throttle();

            $options = $this->applyAuth($options, $method, $url);
            $startedAt = microtime(true);

            try {
                $response = $this->send($method, $url, $options);
            } catch (Throwable $e) {
                $response = $this->responseFromThrowable($e);
            }

            $response->durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $response->attempt = $attempt;

            // Several of these ERPs echo the credential back inside their own error message, and
            // a connector's health check hands that message straight to the merchant. Redaction
            // happens here, on failure bodies only: a successful body is data a connector has to
            // parse, and an error body never is.
            if (!$response->ok()) {
                $response->setBody($this->redact($response->body));
            }

            $this->record($method, $url, $options, $response);

            // One shot at fixing an expired token, then treat 401 as final. Retrying a genuinely
            // wrong credential just gets the merchant's ERP account locked out.
            if ($response->status === 401 && !$reauthenticated && $this->auth?->reauthenticate()) {
                $reauthenticated = true;
                continue;
            }

            $this->signalAuth($response);

            if (!$this->shouldRetry($response, $attempt, $method, $options)) {
                return $response;
            }

            $this->sleep($this->backoffSeconds($response, $attempt));
        }
    }

    /**
     * Tell the failure alerts how authentication is going. Only for an authenticated API request:
     * a token endpoint's own transport has no auth, and its refusals are reported by the strategy,
     * which knows what it was trying to do.
     */
    private function signalAuth(Response $response): void
    {
        if (!$this->connection?->id || $this->auth === null) {
            return;
        }

        $alerts = Plugin::getInstance()?->getAlerts();

        if ($alerts === null) {
            return;
        }

        // Reaching here with a 401 means re-authenticating was tried, or was not possible.
        if ($response->status === 401) {
            $alerts->noteAuthFailure($this->connection, Craft::t('erpy', 'The ERP refused the credentials: {message}', [
                'message' => $response->errorMessage(),
            ]));
        } elseif ($response->ok()) {
            $alerts->noteAuthSuccess($this->connection);
        }
    }

    private function send(string $method, string $url, array $options): Response
    {
        if ($this->double !== null) {
            $response = ($this->double)($method, $url, $options);

            if (!$response instanceof Response) {
                throw new \RuntimeException('A transport double must return an ' . Response::class . '.');
            }

            return $response;
        }

        $client = Craft::createGuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => min(10, $this->timeout),
            'http_errors' => false,
        ]);

        $guzzleOptions = ['headers' => $options['headers'] ?? []];

        if (!empty($options['query'])) {
            $guzzleOptions['query'] = $options['query'];
        }

        if (array_key_exists('json', $options) && $options['json'] !== null) {
            $guzzleOptions['json'] = $options['json'];
        } elseif (array_key_exists('body', $options) && $options['body'] !== null) {
            $guzzleOptions['body'] = $options['body'];
        } elseif (!empty($options['form'])) {
            $guzzleOptions['form_params'] = $options['form'];
        }

        if (!empty($options['auth'])) {
            $guzzleOptions['auth'] = $options['auth'];
        }

        $guzzleResponse = $client->request($method, $url, $guzzleOptions);

        return new Response(
            status: $guzzleResponse->getStatusCode(),
            headers: $guzzleResponse->getHeaders(),
            body: (string)$guzzleResponse->getBody(),
        );
    }

    private function responseFromThrowable(Throwable $e): Response
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $psr = $e->getResponse();

            return new Response(
                status: $psr->getStatusCode(),
                headers: $psr->getHeaders(),
                body: (string)$psr->getBody(),
            );
        }

        // A DNS failure or a timeout is not an HTTP status, but the engine only speaks statuses.
        // 0 means "no answer from the ERP", which retries and never dead-letters as a rejection.
        // It does not mean the ERP never saw the request: a read timeout is a 0 too.
        $response = new Response(
            status: 0,
            headers: [],
            body: '',
            error: ($e instanceof TransferException ? 'Network: ' : '') . $e->getMessage(),
        );
        $response->sent = !$this->provablyUnsent($e);

        return $response;
    }

    /**
     * Whether curl gave up before writing the request. Guzzle files a read timeout and an empty
     * reply under ConnectException alongside a refused connection, so the exception class cannot
     * answer this; curl's error number and timings can.
     */
    private function provablyUnsent(Throwable $e): bool
    {
        if (!$e instanceof ConnectException && !$e instanceof RequestException) {
            return false;
        }

        $context = $e->getHandlerContext();
        $errno = (int)($context['errno'] ?? 0);

        if (in_array($errno, self::UNSENT_CURL_ERRORS, true)) {
            return true;
        }

        // A timeout that struck before the transfer began — still connecting, still shaking hands.
        return $errno === 28
            && array_key_exists('pretransfer_time', $context)
            && (float)$context['pretransfer_time'] === 0.0;
    }

    private function applyAuth(array $options, string $method = 'GET', string $url = ''): array
    {
        $headers = array_merge($this->defaultHeaders, $options['headers'] ?? []);
        $query = $options['query'] ?? [];

        if ($this->auth) {
            $query = array_merge($this->auth->query(), is_array($query) ? $query : []);

            // OAuth 1.0a signs the method, the URL and the query, so it has to see them. Anything
            // simpler just contributes a static header.
            $authHeaders = $this->auth instanceof RequestSigningAuthInterface
                ? $this->auth->headersForRequest($method, $url, $query)
                : $this->auth->headers();

            $headers = array_merge($authHeaders, $headers);

            // Whatever the strategy puts on the wire is a credential, whether the merchant typed
            // it or the ERP issued it at login. Learning it here means an ERP echoing its own
            // session id back in a body is redacted like a configured password (GitHub #6).
            foreach (array_merge(array_values($authHeaders), array_values($this->auth->query())) as $value) {
                if (is_string($value)) {
                    $this->addSecretValue((string)preg_replace('/^(Bearer|Basic|Token|OAuth)\s+/i', '', $value));
                }
            }
        }

        $options['headers'] = $headers;

        if ($query !== []) {
            $options['query'] = $query;
        }

        return $options;
    }

    /**
     * A GET, PUT or DELETE can be repeated without changing what the ERP holds; a POST or PATCH
     * cannot. When a POST times out after the ERP booked the order, or a proxy answers 504 while
     * the ERP is still writing it, sending it again books it again — inside one `request()` call,
     * below anything the identity map or a dead-letter retry can see (GitHub #5).
     *
     * So an unsafe method is only repeated when the ERP provably did not act on it — the
     * connection never opened, or it answered 425/429 — unless the connector says otherwise: the
     * request option `'retry' => true` (an ERP that de-duplicates on its own), or an
     * `Idempotency-Key` header. `'retry' => false` turns retrying off for any method.
     */
    private function shouldRetry(Response $response, int $attempt, string $method = 'GET', array $options = []): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($response->status !== 0 && !in_array($response->status, self::RETRY_STATUSES, true)) {
            return false;
        }

        if (array_key_exists('retry', $options)) {
            return (bool)$options['retry'];
        }

        if (!in_array($method, self::UNSAFE_METHODS, true) || $this->hasIdempotencyKey($options)) {
            return true;
        }

        return ($response->status === 0 && !$response->sent)
            || in_array($response->status, self::REFUSED_STATUSES, true);
    }

    private function hasIdempotencyKey(array $options): bool
    {
        foreach (array_keys($options['headers'] ?? []) as $name) {
            if (strcasecmp((string)$name, 'Idempotency-Key') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Honour `Retry-After` when the ERP sends one — several of these vendors mean it — and fall
     * back to exponential backoff with jitter so a fleet of sites does not stampede.
     */
    private function backoffSeconds(Response $response, int $attempt): float
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter !== null) {
            if (is_numeric($retryAfter)) {
                return min(60, (float)$retryAfter);
            }

            $timestamp = strtotime($retryAfter);

            if ($timestamp !== false) {
                return (float)max(0, min(60, $timestamp - time()));
            }
        }

        return min(30, (2 ** ($attempt - 1)) + (random_int(0, 1000) / 1000));
    }

    private function throttle(): void
    {
        if ($this->rateLimit <= 0) {
            return;
        }

        $minGap = 1 / $this->rateLimit;

        if ($this->lastRequestAt !== null) {
            $elapsed = microtime(true) - $this->lastRequestAt;

            if ($elapsed < $minGap) {
                $this->sleep($minGap - $elapsed);
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        // Doubles are used by the test suite, which should not spend four seconds proving that
        // backoff works. The decision to wait is still exercised; only the waiting is skipped.
        if ($this->double !== null) {
            return;
        }

        usleep((int)round($seconds * 1_000_000));
    }

    private function absoluteUri(string $uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return $this->baseUri . '/' . ltrim($uri, '/');
    }

    private function record(string $method, string $url, array $options, Response $response): void
    {
        if (!$this->connection) {
            return;
        }

        try {
            Plugin::getInstance()->getLog()->request(
                connection: $this->connection,
                runId: $this->runId,
                method: $method,
                url: $this->redact($url),
                requestHeaders: $this->redactArray($options['headers'] ?? []),
                requestBody: $this->redactBody($this->redact($this->bodyToString($options))),
                status: $response->status,
                responseBody: $this->redactBody($this->redact($response->body)),
                durationMs: $response->durationMs ?? 0,
                error: $response->error,
                attempt: $response->attempt ?? 1,
            );
        } catch (Throwable $e) {
            // A log write must never be the reason a sync fails.
            Craft::warning('Erpy could not record a request: ' . $e->getMessage(), 'erpy');
        }
    }

    private function bodyToString(array $options): string
    {
        if (array_key_exists('json', $options) && $options['json'] !== null) {
            return (string)json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!empty($options['body'])) {
            return (string)$options['body'];
        }

        if (!empty($options['form'])) {
            return http_build_query($options['form']);
        }

        return '';
    }

    private function redact(string $value): string
    {
        if ($value === '' || $this->secretValues === []) {
            return $value;
        }

        return str_replace($this->secretValues, '••••••••', $value);
    }

    /**
     * Mask credential-named fields in a body bound for the log.
     *
     * Value redaction cannot cover a login: the token in `{"access_token": "…"}` or
     * `{"SessionId": "…"}` was issued by that very response, so no list of known values holds it
     * yet. The field names are the same everywhere, so those are masked instead — in JSON, form
     * and XML bodies alike, request and response, success or failure. Only the log copy is
     * touched; the connector still parses the real body.
     */
    private function redactBody(string $body): string
    {
        $trimmed = ltrim($body);

        if ($trimmed === '') {
            return $body;
        }

        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $data = json_decode($body, true);

            if (!is_array($data)) {
                return $body;
            }

            $changed = false;
            $masked = $this->maskSecretKeys($data, $changed);

            return $changed ? (string)json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $body;
        }

        if ($trimmed[0] === '<') {
            return (string)preg_replace_callback(
                '/<((?:[\w.-]+:)?([\w.-]+))(\s[^>]*)?>([^<]+)<\/\1>/',
                fn(array $m) => self::isSecretKey($m[2]) ? "<{$m[1]}{$m[3]}>••••••••</{$m[1]}>" : $m[0],
                $body,
            );
        }

        // A form body: key=value pairs, nothing else.
        if (preg_match('/^[^\s=&]+=[^\s]*$/', $trimmed)) {
            return (string)preg_replace_callback(
                '/(^|&)([^=&]+)=([^&]+)/',
                fn(array $m) => self::isSecretKey(urldecode($m[2])) ? $m[1] . $m[2] . '=' . urlencode('••••••••') : $m[0],
                $body,
            );
        }

        return $body;
    }

    private function maskSecretKeys(array $data, bool &$changed): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskSecretKeys($value, $changed);
            } elseif (is_string($key) && self::isSecretKey($key) && is_scalar($value) && (string)$value !== '') {
                $data[$key] = '••••••••';
                $changed = true;
            }
        }

        return $data;
    }

    /**
     * Credential-shaped field names, compared without case or separators so `access_token`,
     * `accessToken` and `Access-Token` are one name. `token` alone is a credential, but
     * `nextPageToken` is a cursor somebody debugging paging needs to read, so suffixes are
     * matched only for the unambiguous names.
     */
    private static function isSecretKey(string $key): bool
    {
        $name = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $key));

        if (in_array($name, ['token', 'secret', 'password', 'passwd', 'pwd', 'assertion', 'sessionid', 'sessiontoken', 'privatekey'], true)) {
            return true;
        }

        foreach (['accesstoken', 'refreshtoken', 'idtoken', 'authtoken', 'bearertoken', 'apitoken', 'apikey', 'secret', 'password', 'sessionid'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function redactArray(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $flat = is_array($value) ? implode(', ', $value) : (string)$value;

            // Authorization, API keys and cookies never reach the log, whatever they contain.
            if (preg_match('/authorization|api-?key|cookie|token|secret|password/i', (string)$key)) {
                $out[$key] = '••••••••';
                continue;
            }

            $out[$key] = $this->redact($flat);
        }

        return $out;
    }
}
