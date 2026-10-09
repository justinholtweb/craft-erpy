<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\events\AlertEvent;
use justinholtweb\erpy\helpers\Ip;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * Failure alerts: tell somebody when a connection is in trouble, once, and again when it is not.
 *
 * The Problems screen already knows everything that has gone wrong. What it cannot do is make a
 * merchant look at it, and nobody checks an integration's admin screen on a day it seems to be
 * working. Three incidents are worth interrupting somebody for:
 *
 * - **Dead letters** — at least `alertDeadLetterThreshold` documents failed inside the window.
 *   One bad SKU is a Tuesday; twenty in an hour is the ERP refusing everything.
 * - **Authentication** — the ERP answered a final 401, or refused an OAuth refresh. Nothing will
 *   sync until a person reconnects, and the ERP will not say so twice.
 * - **Stalled** — a scheduled entity has had no successful pull for `alertStallHours` (or twice
 *   its interval, whichever is longer). The quietest failure: no errors, because nothing ran.
 *
 * Each incident is a latch in `erpy_alerts`, one row per connection and incident type, unique in
 * the database. Opening it sends one alert; it stays open — and silent — however many checks see
 * the same trouble; clearing it sends one recovery. A send is claimed with a conditional update
 * before it goes out and released if it fails, so the queue and cron checking in the same minute
 * cannot both send, and a mail outage does not swallow the alert.
 *
 * Detection runs from three places: {@see afterRun()} at the end of every run (dead letters and
 * stalls, no cron needed), {@see noteAuthFailure()}/{@see noteAuthSuccess()} from the transport
 * and the OAuth strategies (authentication, immediately), and {@see check()} from
 * `erpy/alerts/check`, `erpy/sync/due` and the scheduled-sync job — the only path that can notice
 * a stall when nothing is running at all.
 *
 * Bodies are redacted ({@see redact()}): an alert goes to a mailbox and a chat channel, both of
 * which outlive the credential they would otherwise quote.
 */
class Alerts extends Component
{
    public const INCIDENT_DEAD_LETTERS = 'deadLetters';
    public const INCIDENT_AUTH = 'auth';
    public const INCIDENT_STALLED = 'stalled';

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';

    /** @event AlertEvent before an alert or a recovery is sent; set `isValid` false to swallow it. */
    public const EVENT_BEFORE_NOTIFY = 'beforeNotify';

    /** How often one process re-checks that an authentication incident is still clear. */
    private const AUTH_SUCCESS_TTL = 60;

    /**
     * The HTTP client for the webhook. Null means a curl-only client built per send; tests put a
     * Guzzle `MockHandler` client here. Never a client on Guzzle's default stack: it can hand a
     * request to PHP's stream wrapper, which ignores `CURLOPT_RESOLVE` — the address pin.
     */
    public ?ClientInterface $webhookClient = null;

    /** @var array<int,int> connection id => when this process last confirmed auth is clear */
    private array $authClearAt = [];

    /**
     * @return array<string,string> incident => label
     */
    public static function incidents(): array
    {
        return [
            self::INCIDENT_DEAD_LETTERS => Craft::t('erpy', 'Documents failing'),
            self::INCIDENT_AUTH => Craft::t('erpy', 'Authentication failed'),
            self::INCIDENT_STALLED => Craft::t('erpy', 'Scheduled sync stalled'),
        ];
    }

    public static function incidentLabel(string $incident): string
    {
        return self::incidents()[$incident] ?? $incident;
    }

    // ---------------------------------------------------------------------------------------
    // Detection
    // ---------------------------------------------------------------------------------------

    /**
     * Evaluate every enabled connection.
     *
     * @return array<int,array{connection:string,incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function check(): array
    {
        $results = [];

        foreach (Plugin::getInstance()->getConnections()->all() as $connection) {
            array_push($results, ...$this->checkConnection($connection));
        }

        return $results;
    }

    /**
     * Evaluate one connection. A disabled connection is left exactly as it is: switching it off
     * is not a recovery, and it is not a new incident either.
     *
     * @param string[]|null $only limit to these incidents
     * @return array<int,array{connection:string,incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function checkConnection(Connection $connection, ?array $only = null): array
    {
        if (!$connection->id || !$connection->enabled) {
            return [];
        }

        $results = [];

        foreach (array_keys(self::incidents()) as $incident) {
            if ($only !== null && !in_array($incident, $only, true)) {
                continue;
            }

            $row = $this->row($connection, $incident);
            [$open, $detail] = $this->measure($connection, $incident, $row);
            $results[] = $this->transition($connection, $incident, $open, $detail);
        }

        return $results;
    }

    /**
     * The end of every run. Fail-open: an alert that cannot be worked out must never be the reason
     * a sync reports a failure.
     */
    public function afterRun(Run $run): void
    {
        if ($run->dryRun || !$run->connectionId) {
            return;
        }

        try {
            $connection = Plugin::getInstance()->getConnections()->getById($run->connectionId);

            if ($connection) {
                $this->checkConnection($connection, [self::INCIDENT_DEAD_LETTERS, self::INCIDENT_STALLED]);
            }
        } catch (Throwable $e) {
            Craft::warning('Erpy could not evaluate alerts after a run: ' . $e->getMessage(), 'erpy');
        }
    }

    /**
     * The ERP refused the credentials: a final 401, or an OAuth grant it would not honour.
     *
     * Recorded as a signal rather than measured, because nothing else remembers it — the log can
     * be switched off, and a 401 does not make a dead letter.
     */
    public function noteAuthFailure(Connection $connection, string $reason): void
    {
        if (!$connection->id) {
            return;
        }

        try {
            $this->ensureRow($connection, self::INCIDENT_AUTH);

            Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalledAt' => $this->now(),
                'signalClearedAt' => null,
                'detail' => $this->redact($connection, $reason),
                'dateUpdated' => $this->now(),
            ], ['connectionId' => $connection->id, 'incident' => self::INCIDENT_AUTH])->execute();

            unset($this->authClearAt[$connection->id]);

            $this->checkConnection($connection, [self::INCIDENT_AUTH]);
        } catch (Throwable $e) {
            Craft::warning('Erpy could not record an authentication failure: ' . $e->getMessage(), 'erpy');
        }
    }

    /**
     * An authenticated request succeeded. Called by the transport on every 2xx, so it costs at
     * most one conditional UPDATE per connection per minute per process, and usually nothing.
     */
    public function noteAuthSuccess(Connection $connection): void
    {
        if (!$connection->id) {
            return;
        }

        $seen = $this->authClearAt[$connection->id] ?? null;

        if ($seen !== null && time() - $seen < self::AUTH_SUCCESS_TTL) {
            return;
        }

        $this->authClearAt[$connection->id] = time();

        try {
            $cleared = Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalClearedAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], [
                'and',
                ['connectionId' => $connection->id, 'incident' => self::INCIDENT_AUTH, 'signalClearedAt' => null],
                ['not', ['signalledAt' => null]],
            ])->execute();

            if ($cleared > 0) {
                $this->checkConnection($connection, [self::INCIDENT_AUTH]);
            }
        } catch (Throwable $e) {
            Craft::warning('Erpy could not clear an authentication alert: ' . $e->getMessage(), 'erpy');
        }
    }

    /**
     * Whether an incident is happening right now, and a redacted line saying what was seen.
     *
     * @return array{0:bool,1:?string}
     */
    private function measure(Connection $connection, string $incident, array $row): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $wasOpen = ($row['state'] ?? self::STATE_OK) === self::STATE_OPEN;

        switch ($incident) {
            case self::INCIDENT_DEAD_LETTERS:
                if (!$settings->alertOnDeadLetters) {
                    return [false, null];
                }

                $window = max(5, $settings->alertDeadLetterWindowMinutes);
                $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$window minutes"));
                $recent = (new Query())
                    ->from(Table::DEAD_LETTERS)
                    ->where(['connectionId' => $connection->id])
                    ->andWhere(['>=', 'lastAttemptAt', $cutoff]);
                $count = (int)(clone $recent)->count();

                // Hysteresis: it takes the threshold to open, and a whole quiet window to close.
                // Closing the moment the count dipped below the threshold would page somebody
                // twice an hour for an ERP that is refusing every fourth document.
                $open = $wasOpen ? $count > 0 : $count >= max(1, $settings->alertDeadLetterThreshold);

                if (!$open) {
                    return [false, null];
                }

                $latest = (clone $recent)->select(['error'])->orderBy(['lastAttemptAt' => SORT_DESC, 'id' => SORT_DESC])->scalar();

                return [true, $this->redact($connection, Craft::t('erpy', '{count} documents failed in the last {window} minutes; {unresolved} problems are unresolved. Latest: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'unresolved' => Plugin::getInstance()->getDeadLetters()->openCount($connection),
                    'error' => is_string($latest) && $latest !== '' ? $latest : '—',
                ]))];

            case self::INCIDENT_AUTH:
                $open = $settings->alertOnAuthFailure
                    && !empty($row['signalledAt'])
                    && empty($row['signalClearedAt']);

                return [$open, $open ? ($row['detail'] ?? null) : null];

            case self::INCIDENT_STALLED:
                $stalled = $this->stalledEntities($connection);

                if ($stalled === []) {
                    return [false, null];
                }

                $lines = array_map(static fn(array $s) => Craft::t('erpy', '{entity}: no successful sync since {since}', [
                    'entity' => Entity::displayName($s['entity']),
                    'since' => $s['since'] ? $s['since']->format('Y-m-d H:i') . ' UTC' : Craft::t('erpy', 'it was set up'),
                ]), $stalled);

                return [true, implode('; ', $lines)];
        }

        return [false, null];
    }

    /**
     * Scheduled entities with no successful pull for longer than they are allowed.
     *
     * Allowed is `alertStallHours` or twice the entity's own interval, whichever is longer — a
     * nightly catalogue sync is not stalled at 7am. Any trigger counts as a success, including a
     * merchant pressing the button: what matters is whether the data is fresh.
     *
     * @return array<int,array{entity:string,since:?DateTime}>
     */
    public function stalledEntities(Connection $connection): array
    {
        $hours = Plugin::getInstance()->getSettings()->alertStallHours;

        if ($hours <= 0 || !$connection->enabled) {
            return [];
        }

        $utc = new DateTimeZone('UTC');
        $created = (new Query())->select(['dateCreated'])->from(Table::CONNECTIONS)->where(['id' => $connection->id])->scalar();
        // Bare UTC strings, so the zone is named — read as site-local they shift by the offset.
        $createdAt = is_string($created) ? new DateTime($created, $utc) : null;
        $stalled = [];

        foreach ($connection->activeEntities() as $entity) {
            $interval = $connection->intervalFor($entity);

            if ($interval <= 0 || !$connection->syncs($entity, Direction::PULL)) {
                continue;
            }

            $allowed = max($hours * 3600, $interval * 2);
            $last = (new Query())
                ->from(Table::RUNS)
                ->where([
                    'connectionId' => $connection->id,
                    'entity' => $entity,
                    'direction' => Direction::PULL,
                    'dryRun' => false,
                    'status' => [Run::STATUS_SUCCESS, Run::STATUS_PARTIAL],
                ])
                ->max('finishedAt');
            $since = is_string($last) ? new DateTime($last, $utc) : null;
            $baseline = $since ?? $createdAt;

            if ($baseline !== null && time() - $baseline->getTimestamp() > $allowed) {
                $stalled[] = ['entity' => $entity, 'since' => $since];
            }
        }

        return $stalled;
    }

    // ---------------------------------------------------------------------------------------
    // The latch
    // ---------------------------------------------------------------------------------------

    /**
     * Move the latch, then send whatever it says is owed.
     *
     * @return array{connection:string,incident:string,state:string,transition:?string,notified:bool,detail:?string}
     */
    private function transition(Connection $connection, string $incident, bool $open, ?string $detail): array
    {
        $db = Craft::$app->getDb();
        $row = $this->row($connection, $incident);
        $transition = null;
        $where = ['id' => $row['id']];

        if ($open && $row['state'] !== self::STATE_OPEN) {
            // Conditional on the old state, so two checks racing open it once.
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OPEN,
                'openedAt' => $this->now(),
                'notifiedAt' => null,
                'recoveryNotifiedAt' => null,
                'detail' => $detail,
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OK])->execute();
            $transition = $won ? 'opened' : null;
        } elseif ($open && $detail !== null && $detail !== $row['detail']) {
            $db->createCommand()->update(Table::ALERTS, ['detail' => $detail, 'dateUpdated' => $this->now()], $where)->execute();
        } elseif (!$open && $row['state'] === self::STATE_OPEN) {
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OK,
                'recoveredAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OPEN])->execute();
            $transition = $won ? 'recovered' : null;
        }

        $notified = $this->deliverOwed($connection, $incident);
        $row = $this->row($connection, $incident);

        return [
            'connection' => $connection->handle,
            'incident' => $incident,
            'state' => $row['state'],
            'transition' => $transition,
            'notified' => $notified,
            'detail' => $row['detail'],
        ];
    }

    /**
     * Send what the latch says is owed: an opening alert nobody has had yet, or the recovery for
     * one somebody has. Claimed by a conditional update first, released again if every channel
     * failed — so it is sent once, and a mail outage delays it rather than losing it.
     */
    private function deliverOwed(Connection $connection, string $incident): bool
    {
        $db = Craft::$app->getDb();
        $row = $this->row($connection, $incident);
        $where = ['id' => $row['id']];

        if ($row['state'] === self::STATE_OPEN && $row['notifiedAt'] === null) {
            // A flapping connection gets one alert and one recovery per cooldown, not one each
            // per check. A reopening inside the cooldown is told about once the cooldown ends,
            // if it is still open by then.
            if ($row['quietUntil'] !== null && $row['quietUntil'] > $this->now()) {
                return false;
            }

            $claimed = $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => $this->now()], $where + ['notifiedAt' => null, 'state' => self::STATE_OPEN])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($connection, $incident, false, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => null], $where)->execute();

            return false;
        }

        // A recovery is only owed for an incident somebody was told about.
        if ($row['state'] === self::STATE_OK && $row['notifiedAt'] !== null && $row['recoveryNotifiedAt'] === null) {
            $cooldown = max(0, Plugin::getInstance()->getSettings()->alertCooldownMinutes);
            $claimed = $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => $this->now(),
                'quietUntil' => $cooldown > 0 ? Db::prepareDateForDb((new DateTime())->modify("+$cooldown minutes")) : null,
            ], $where + ['state' => self::STATE_OK, 'recoveryNotifiedAt' => null])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($connection, $incident, true, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => null,
                'quietUntil' => $row['quietUntil'],
            ], $where)->execute();
        }

        return false;
    }

    /**
     * The latch row, created on first sight.
     *
     * @return array<string,mixed>
     */
    private function row(Connection $connection, string $incident): array
    {
        $this->ensureRow($connection, $incident);

        return (array)(new Query())
            ->from(Table::ALERTS)
            ->where(['connectionId' => $connection->id, 'incident' => $incident])
            ->one();
    }

    private function ensureRow(Connection $connection, string $incident): void
    {
        $exists = (new Query())
            ->from(Table::ALERTS)
            ->where(['connectionId' => $connection->id, 'incident' => $incident])
            ->exists();

        if ($exists) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::ALERTS, [
                'connectionId' => $connection->id,
                'incident' => $incident,
                'state' => self::STATE_OK,
                'dateCreated' => $this->now(),
                'dateUpdated' => $this->now(),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\yii\db\IntegrityException) {
            // Another process created it between the check and the insert. The unique index is
            // the point; there is nothing to do.
        }
    }

    /**
     * Every latch row for a connection (or all of them), for the widget and the console.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openIncidents(?Connection $connection = null): array
    {
        $query = (new Query())->from(Table::ALERTS)->where(['state' => self::STATE_OPEN])->orderBy(['openedAt' => SORT_DESC]);

        if ($connection) {
            $query->andWhere(['connectionId' => $connection->id]);
        }

        return $query->all();
    }

    /**
     * What the Dashboard widget shows: per connection, the latest run, open problems and open
     * incidents.
     *
     * @return array<int,array{connection:Connection,lastRun:?Run,problems:int,incidents:array<int,array<string,mixed>>}>
     */
    public function overview(): array
    {
        $plugin = Plugin::getInstance();
        $incidents = [];

        foreach ($this->openIncidents() as $row) {
            $incidents[(int)$row['connectionId']][] = $row + ['label' => self::incidentLabel((string)$row['incident'])];
        }

        $overview = [];

        foreach ($plugin->getConnections()->all() as $connection) {
            $overview[] = [
                'connection' => $connection,
                'lastRun' => $plugin->getRuns()->recent($connection, 1)[0] ?? null,
                'problems' => $plugin->getDeadLetters()->openCount($connection),
                'incidents' => $incidents[$connection->id] ?? [],
            ];
        }

        return $overview;
    }

    // ---------------------------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------------------------

    /**
     * Send one alert to every configured channel. True when at least one channel took it — a
     * second email because the webhook failed would be worse than a missing Slack message.
     */
    public function notify(?Connection $connection, string $incident, bool $recovered, string $detail): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $hasWebhook = $webhookUrl !== '' && !str_starts_with($webhookUrl, '$');

        if ($recipients === [] && !$hasWebhook) {
            return false;
        }

        $message = $this->compose($connection, $incident, $recovered, $detail);

        $event = new AlertEvent([
            'connection' => $connection,
            'incident' => $incident,
            'recovered' => $recovered,
            'detail' => $message['detail'],
            'subject' => $message['subject'],
            'body' => $message['body'],
            'payload' => $this->payload($settings->alertWebhookFormat, $message),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_NOTIFY)) {
            $this->trigger(self::EVENT_BEFORE_NOTIFY, $event);

            if (!$event->isValid) {
                return true;
            }
        }

        $sent = false;

        if ($recipients !== []) {
            try {
                $sent = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($event->subject)
                    ->setTextBody($event->body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Erpy could not email an alert: ' . $e->getMessage(), 'erpy');
            }
        }

        if ($hasWebhook) {
            $result = $this->postWebhook($webhookUrl, $event->payload);

            if ($result === true) {
                $sent = true;
            } else {
                Craft::error('Erpy could not post an alert webhook: ' . $result, 'erpy');
            }
        }

        return $sent;
    }

    /**
     * Send a sample through every channel, for the settings screen's "Send a test alert".
     *
     * @return array{email:?bool,webhook:bool|string|null}
     */
    public function sendTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $message = $this->compose(null, 'test', false, Craft::t('erpy', 'This is a test. If you can read it, failure alerts will reach you here.'));
        $result = ['email' => null, 'webhook' => null];

        if ($recipients !== []) {
            try {
                $result['email'] = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($message['subject'])
                    ->setTextBody($message['body'])
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Erpy could not email a test alert: ' . $e->getMessage(), 'erpy');
                $result['email'] = false;
            }
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, '$')) {
            $result['webhook'] = $this->postWebhook($webhookUrl, $this->payload($settings->alertWebhookFormat, $message));
        }

        return $result;
    }

    /**
     * Subject, plain-text body and links for one alert.
     *
     * Plain text on purpose: it may be read on a phone at an inconvenient hour, and it should say
     * which connection, what happened and where to go — nothing that needs a rendering engine.
     * `UrlHelper::cpUrl()` rather than a hand-assembled host, because this runs from the queue and
     * the console, where there is no request to read a host from.
     *
     * @return array{subject:string,body:string,title:string,detail:string,url:string,problemsUrl:string,connection:?string,incident:string,recovered:bool,site:string}
     */
    public function compose(?Connection $connection, string $incident, bool $recovered, string $detail): array
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $name = $connection->name ?? Craft::t('erpy', 'Test');
        $label = $incident === 'test' ? Craft::t('erpy', 'Test alert') : self::incidentLabel($incident);
        $detail = $connection ? $this->redact($connection, $detail) : $detail;

        $problemsUrl = UrlHelper::cpUrl('erpy/problems', $connection ? ['connection' => $connection->id] : []);
        $url = match ($incident) {
            self::INCIDENT_AUTH => UrlHelper::cpUrl('erpy/connections/' . $connection?->id),
            self::INCIDENT_STALLED => UrlHelper::cpUrl('erpy/runs', $connection ? ['connection' => $connection->id] : []),
            default => $problemsUrl,
        };

        $title = $recovered
            ? Craft::t('erpy', 'Recovered: {label} on {connection}', ['label' => $label, 'connection' => $name])
            : Craft::t('erpy', '{label} on {connection}', ['label' => $label, 'connection' => $name]);

        $lines = [
            $recovered
                ? Craft::t('erpy', 'Erpy on {site}: this has cleared. No action is needed.', ['site' => $site])
                : Craft::t('erpy', 'Erpy on {site} needs attention.', ['site' => $site]),
            '',
            Craft::t('erpy', 'Connection: {name}', ['name' => $name]),
            Craft::t('erpy', 'Incident: {label}', ['label' => $label]),
        ];

        if (!$recovered && $detail !== '') {
            $lines[] = '';
            $lines[] = $detail;
        }

        if (!$recovered && $incident === self::INCIDENT_AUTH) {
            $lines[] = '';
            $lines[] = Craft::t('erpy', 'Nothing will sync until the credentials work again. Open the connection, check them, and press Reconnect if it uses OAuth.');
        }

        $lines[] = '';
        $lines[] = Craft::t('erpy', 'Open: {url}', ['url' => $url]);

        if ($url !== $problemsUrl) {
            $lines[] = Craft::t('erpy', 'Problems: {url}', ['url' => $problemsUrl]);
        }

        $lines[] = '';
        $lines[] = Craft::t('erpy', 'You get one message when this starts and one when it clears. Change who gets them in Erpy → Settings.');

        return [
            'subject' => '[' . $site . '] ' . $title,
            'body' => implode("\n", $lines) . "\n",
            'title' => $title,
            'detail' => $recovered ? '' : $detail,
            'url' => $url,
            'problemsUrl' => $problemsUrl,
            'connection' => $connection?->handle,
            'incident' => $incident,
            'recovered' => $recovered,
            'site' => $site,
        ];
    }

    /**
     * The webhook body in the receiver's own shape.
     *
     * @param array{subject:string,body:string,title:string,detail:string,url:string,problemsUrl:string,connection:?string,incident:string,recovered:bool,site:string} $m
     */
    public function payload(string $format, array $m): array
    {
        $text = $m['title'] . ($m['detail'] !== '' ? "\n" . $m['detail'] : '');

        return match ($format) {
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => $m['recovered'] ? 'Good' : 'Attention', 'text' => $m['title'], 'wrap' => true],
                            $m['detail'] !== '' ? ['type' => 'TextBlock', 'text' => $m['detail'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [['title' => 'Site', 'value' => $m['site']]]],
                        ])),
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => Craft::t('erpy', 'Open in Craft'), 'url' => $m['url']]],
                    ],
                ]],
            ],
            'json' => [
                'event' => $m['recovered'] ? 'erpy.alert.recovered' : 'erpy.alert.opened',
                'incident' => $m['incident'],
                'connection' => $m['connection'],
                'site' => $m['site'],
                'title' => $m['title'],
                'detail' => $m['detail'],
                'url' => $m['url'],
                'problemsUrl' => $m['problemsUrl'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $text,
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $m['title'] . '*' . ($m['detail'] !== '' ? "\n" . $m['detail'] : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $m['site'] . ' · <' . $m['url'] . '|' . Craft::t('erpy', 'Open in Craft') . '>']]],
                ],
            ],
        };
    }

    /**
     * Where an alert webhook may be sent, or why it may not. The family SSRF rules, as in Fjord:
     *
     * 1. `http` and `https` only, with no credentials in the URL.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The send pins the connection to those addresses with `CURLOPT_RESOLVE`, so a second
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are never followed.
     *
     * `allowPrivateAlertWebhookHosts` (config file only) skips 2 and 3; 1 and 4 still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('erpy', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('erpy', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateAlertWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('erpy', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Alert webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POST the payload. True on a 2xx, otherwise the reason — never an exception.
     */
    public function postWebhook(string $url, array $payload): bool|string
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Erpy alerts'];
        $secret = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->alertWebhookSecret));

        if ($secret !== '' && !str_starts_with($secret, '$')) {
            $timestamp = (string)time();
            $headers['X-Erpy-Timestamp'] = $timestamp;
            $headers['X-Erpy-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->webhookClient ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * Take anything secret out of a line that is about to leave the building.
     *
     * The transport already redacts the connection's secrets from failure bodies, but an alert
     * also quotes dead-letter errors and connector messages that never passed through it, and it
     * goes somewhere — a mailbox, a chat channel — that outlives the credential. So: this
     * connection's secret settings and tokens by value, anything shaped like a credential by
     * pattern, tags stripped, and a length cap so a stack trace cannot ride along.
     */
    public function redact(Connection $connection, string $text): string
    {
        $secrets = [];
        $connector = $connection->getConnector();

        foreach ($connector ? Field::secretNames($connector::settingsFields()) : [] as $name) {
            $secrets[] = (string)$connection->getSetting($name, '');
        }

        array_walk_recursive($connection->tokens, static function($value) use (&$secrets) {
            if (is_string($value)) {
                $secrets[] = $value;
            }
        });

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=]{6,}/i', '$1 ••••', $text);
        $text = (string)preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|client_secret|api[_-]?key|apikey|access_token|refresh_token|token|signature|sig)\b["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i',
            '$1••••',
            $text,
        );
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499) . '…' : $text;
    }

    private function now(): string
    {
        return (string)Db::prepareDateForDb(new DateTime());
    }
}
