<?php

namespace justinholtweb\erpy\files;

use Craft;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\erpy\base\Transport;

/**
 * Files an ERP publishes at a URL.
 *
 * Every request goes through the connection's `Transport`, so it is logged, redacted, retried and
 * authenticated like any API call. A delta read sends `If-Modified-Since`, and a `304` — or a
 * `Last-Modified` older than the watermark — means the file is skipped without being parsed.
 */
class HttpSource extends BaseSource
{
    private string $base;

    public function __construct(private Transport $transport, string $baseUrl)
    {
        $baseUrl = trim($baseUrl);

        if (!preg_match('#^https?://[^/\s]+#i', $baseUrl)) {
            throw FileException::permanent(Craft::t('erpy', 'The base URL must start with https:// or http://.'));
        }

        $this->base = $baseUrl;
    }

    public function fetch(string $path, ?DateTimeInterface $since = null): ?LocalFile
    {
        if (self::isPattern($path)) {
            throw FileException::permanent(Craft::t('erpy', 'A URL cannot be searched with a wildcard; name the file exactly.'));
        }

        $url = $this->url($path);
        $headers = ['Accept' => '*/*'];

        if ($since !== null) {
            $headers['If-Modified-Since'] = (new DateTimeImmutable('@' . $since->getTimestamp()))->format('D, d M Y H:i:s') . ' GMT';
        }

        // A large file over a slow link is the normal case here, not a hung API.
        $response = $this->transport->setTimeout(300)->request('GET', $url, ['headers' => $headers]);

        if ($response->status === 304) {
            return null;
        }

        if ($response->status === 401 || $response->status === 403) {
            throw FileException::auth(Craft::t('erpy', 'The server refused the credentials for {url} ({status}).', ['url' => $this->describePath($path), 'status' => $response->status]));
        }

        if ($response->status === 404) {
            throw new FileException(Craft::t('erpy', 'There is no file at {url}.', ['url' => $this->describePath($path)]));
        }

        if (!$response->ok()) {
            throw new FileException(Craft::t('erpy', 'Fetching {url} failed: {message}', ['url' => $this->describePath($path), 'message' => $response->errorMessage()]));
        }

        $modifiedAt = null;
        $header = $response->header('Last-Modified');

        if ($header) {
            try {
                $modifiedAt = (new DateTimeImmutable($header))->setTimezone(new DateTimeZone('UTC'));
            } catch (\Throwable) {
                $modifiedAt = null;
            }
        }

        // A server that ignores If-Modified-Since but reports Last-Modified honestly still gets
        // the file skipped.
        if ($since !== null && $modifiedAt !== null && $modifiedAt < $since) {
            return null;
        }

        $local = LocalFile::tempPath();

        if (@file_put_contents($local, $response->body) === false) {
            throw new FileException(Craft::t('erpy', 'This server could not save a copy of {url}.', ['url' => $this->describePath($path)]));
        }

        $name = basename((string)parse_url($url, PHP_URL_PATH)) ?: 'download';

        return new LocalFile($local, $name, $modifiedAt, temporary: true);
    }

    public function exists(string $path): bool
    {
        $response = $this->transport->request('HEAD', $this->url($path));

        return $response->ok();
    }

    public function write(string $path, string $contents): void
    {
        throw FileException::permanent(Craft::t('erpy', 'A URL can only be read. Write orders to a folder or an SFTP server, or let the ERP fetch them from the export URL.'));
    }

    public function probe(): string
    {
        $response = $this->transport->setTimeout(30)->request('HEAD', $this->base);

        // Plenty of servers refuse HEAD on a folder while serving the files in it happily, so
        // only a refused login is a failed test.
        if ($response->status === 401 || $response->status === 403) {
            throw FileException::auth(Craft::t('erpy', 'The server refused the credentials ({status}).', ['status' => $response->status]));
        }

        if ($response->status === 0) {
            throw new FileException(Craft::t('erpy', 'Could not reach {url}: {message}', ['url' => $this->describe(), 'message' => (string)$response->error]));
        }

        return Craft::t('erpy', '{url} answered {status}.', ['url' => $this->describe(), 'status' => $response->status]);
    }

    public function describe(): string
    {
        return (string)preg_replace('#//[^/@]*@#', '//', strtok($this->base, '?') ?: $this->base);
    }

    private function describePath(string $path): string
    {
        return (string)preg_replace('#//[^/@]*@#', '//', strtok($this->url($path), '?') ?: '');
    }

    /**
     * The file's URL: the base on its own when no name is set (an export endpoint that is the
     * file), otherwise the name joined to it.
     */
    public function url(string $path): string
    {
        $path = self::clean($path, allowEmpty: true, allowQuery: true);

        if ($path === '') {
            return $this->base;
        }

        [$basePath, $baseQuery] = array_pad(explode('?', $this->base, 2), 2, null);
        $url = rtrim($basePath, '/') . '/' . $path;

        if ($baseQuery !== null && $baseQuery !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $baseQuery;
        }

        return $url;
    }
}
