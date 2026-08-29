<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\LogEntry;
use justinholtweb\erpy\Plugin;

/**
 * The connection log.
 *
 * Merchants do not buy middleware, they buy the ability to answer "why didn't that order arrive?"
 * at four in the afternoon. So the log records the actual request and the actual response, with
 * credentials redacted at the transport rather than here, and keeps them long enough to be useful
 * without becoming the largest table in the database.
 */
class Log extends Component
{
    /** Anything longer than this is stored truncated, with a marker. */
    private const MAX_BODY = 65000;

    private ?int $runId = null;

    /** Requests recorded during the current run, for the run's own counter. */
    private int $requestCount = 0;

    public function setRunId(?int $runId): void
    {
        $this->runId = $runId;
        $this->requestCount = 0;
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function request(
        Connection $connection,
        ?int $runId,
        string $method,
        string $url,
        array $requestHeaders,
        string $requestBody,
        int $status,
        string $responseBody,
        int $durationMs,
        ?string $error = null,
        int $attempt = 1,
    ): void {
        $this->requestCount++;

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->logRequests) {
            return;
        }

        // "Errors only" is the setting a busy store lands on: a nightly catalogue sync is tens of
        // thousands of requests and nobody reads the successful ones.
        if ($settings->logErrorsOnly && $status >= 200 && $status < 300) {
            return;
        }

        $this->insert([
            'connectionId' => $connection->id,
            'runId' => $runId ?? $this->runId,
            'type' => 'request',
            'method' => $method,
            'url' => $url,
            'requestHeaders' => json_encode($requestHeaders),
            'requestBody' => $settings->logBodies ? $this->truncate($requestBody) : null,
            'status' => $status,
            'responseBody' => $settings->logBodies ? $this->truncate($responseBody) : null,
            'durationMs' => $durationMs,
            'attempt' => $attempt,
            'message' => $error,
        ]);
    }

    public function note(Connection $connection, string $message, ?int $runId = null): void
    {
        $this->insert([
            'connectionId' => $connection->id,
            'runId' => $runId ?? $this->runId,
            'type' => 'note',
            'message' => $message,
        ]);
    }

    public function error(?Connection $connection, string $message, ?int $runId = null): void
    {
        $this->insert([
            'connectionId' => $connection?->id,
            'runId' => $runId ?? $this->runId,
            'type' => 'error',
            'message' => $message,
        ]);

        Craft::error('Erpy: ' . $message, 'erpy');
    }

    private function insert(array $values): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()
            ->insert(Table::LOG, array_merge([
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], $values))
            ->execute();
    }

    private function truncate(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return substr($body, 0, self::MAX_BODY) . "\n… truncated, " . strlen($body) . ' bytes total';
    }

    public function query(): Query
    {
        return (new Query())->from(Table::LOG)->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getById(int $id): ?LogEntry
    {
        $row = (new Query())->from(Table::LOG)->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * @return LogEntry[]
     */
    public function forRun(int $runId, int $limit = 200): array
    {
        return array_map(
            static fn(array $row) => new LogEntry($row),
            $this->query()->where(['runId' => $runId])->limit($limit)->all(),
        );
    }

    /**
     * Drop log rows older than the configured retention.
     *
     * Run from the garbage-collection hook rather than on write: deleting on every insert turns a
     * 40,000-request sync into 40,000 deletes.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::LOG, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }

    public function clear(?Connection $connection = null): int
    {
        $condition = $connection ? ['connectionId' => $connection->id] : [];

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, $condition)->execute();
    }
}
