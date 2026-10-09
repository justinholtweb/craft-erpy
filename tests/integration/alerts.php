<?php
/**
 * Failure alerts — the reference implementation for the connector family.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/alerts.php
 *
 * Drives the real latch, the real mailer and the real webhook code against a Mock ERP connection:
 * dead letters, a final 401, a refused OAuth refresh and a stalled schedule each open exactly one
 * incident, send exactly one alert, and send exactly one recovery. The webhook goes to a Guzzle
 * MockHandler (the harness has no outbound network) after passing the SSRF guard for real.
 *
 * Settings changes stay in memory. Self-cleaning: the fixture connection — and with it, by
 * cascade, its runs, dead letters and alert rows — and the fixture user are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\mail\Mailer;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\erpy\auth\BasicAuth;
use justinholtweb\erpy\auth\OAuth2AuthorizationCode;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\events\AlertEvent;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\models\Settings;
use justinholtweb\erpy\Plugin;
use justinholtweb\erpy\services\Alerts;
use justinholtweb\erpy\widgets\HealthWidget;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

Craft::$app->getPlugins()->loadPlugins();

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$alerts = $plugin->getAlerts();
$settings = $plugin->getSettings();
$original = $settings->toArray();
$suffix = bin2hex(random_bytes(3));
$cleanup = ['connections' => [], 'users' => []];

register_shutdown_function(function() use (&$cleanup, $plugin) {
    foreach ($cleanup['connections'] as $id) {
        if ($c = $plugin->getConnections()->getById($id)) {
            $plugin->getConnections()->delete($c);
        }
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

// The real mailer, on Symfony's null transport: the whole compose/send path runs, but the result
// does not depend on whether the harness's Mailpit happens to be up.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());

// Everything the mailer sends, and a switch to make it fail.
$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $to = (array)$e->message->getTo();
        $mail[] = ['to' => array_map(static fn($k, $v) => is_string($k) ? $k : (string)$v, array_keys($to), $to), 'subject' => $e->message->getSubject(), 'body' => (string)$e->message->toString()];
    }
});

// The webhook receiver.
$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$alerts->webhookClient = new Client(['handler' => $stack]);

$reset = function(array $overrides = []) use ($settings, $original, &$mail, &$history, $mock) {
    $settings->setAttributes(array_merge($original, [
        'alertRecipients' => 'ops@example.test, erp@example.test',
        'alertWebhookUrl' => '',
        'alertWebhookSecret' => '',
        'alertWebhookFormat' => 'slack',
        'alertOnDeadLetters' => true,
        'alertDeadLetterThreshold' => 3,
        'alertDeadLetterWindowMinutes' => 60,
        'alertOnAuthFailure' => true,
        'alertStallHours' => 6,
        'alertCooldownMinutes' => 0,
        'allowPrivateAlertWebhookHosts' => false,
    ], $overrides), false);
    $mail = [];
    $history = [];
    $mock->reset();
};

$connection = new Connection([
    'name' => "Alerts $suffix",
    'handle' => "alerts$suffix",
    'connector' => 'mock',
    'enabled' => true,
    'settings' => ['itemCount' => 2, 'skuPrefix' => "ALRT$suffix-"],
    'sync' => [Entity::PRODUCT => ['enabled' => true, 'direction' => Direction::PULL, 'interval' => 900]],
    'tokens' => ['refreshToken' => "rt-secret-$suffix"],
]);
$plugin->getConnections()->save($connection) or throw new RuntimeException(json_encode($connection->getErrors()));
$cleanup['connections'][] = $connection->id;

$latch = fn(string $incident) => (new Query())->from(Table::ALERTS)->where(['connectionId' => $connection->id, 'incident' => $incident])->one() ?: [];
$letter = function(string $key, string $error = 'Item does not exist') use ($plugin, $connection) {
    $plugin->getDeadLetters()->record($connection, Entity::PRODUCT, Direction::PULL, $key, null, ['sku' => $key], $error);
};
$ago = fn(string $modify) => Db::prepareDateForDb((new DateTime())->modify($modify));

// ---------------------------------------------------------------------------------------------
section('Settings');

check('a fresh install saves with no recipients and no webhook (nothing is required)', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertRecipients' => '', 'alertWebhookUrl' => '']));

    return $s->validate() ?: json_encode($s->getErrors());
});

check('a bad address is refused, and named', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertRecipients' => 'ops@example.test, not-an-address']));

    return !$s->validate() && str_contains(implode(' ', $s->getErrors('alertRecipients')), 'not-an-address') ?: json_encode($s->getErrors());
});

check('an unset $ENV reference is allowed and means nobody', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertRecipients' => '$ERPY_ALERTS_NOT_SET', 'alertWebhookUrl' => '$ERPY_HOOK_NOT_SET']));

    return $s->validate() && $s->recipientList() === [] ?: json_encode($s->getErrors());
});

check('a non-http webhook URL is refused at save', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertWebhookUrl' => 'ftp://hooks.example.test/x']));

    return !$s->validate() && $s->hasErrors('alertWebhookUrl') ?: 'accepted';
});

check('recipients split on commas, semicolons and newlines, de-duplicated', function() {
    $s = new Settings(['alertRecipients' => "a@example.test; b@example.test\nc@example.test, a@example.test"]);

    return $s->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test'] ?: json_encode($s->recipientList());
});

// ---------------------------------------------------------------------------------------------
section('The SSRF guard on the webhook');

$reset();

foreach ([
    'http://127.0.0.1/hook' => 'loopback',
    'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
    'http://10.1.2.3/hook' => 'a private address',
    'http://[::1]/hook' => 'IPv6 loopback',
    'http://[::ffff:127.0.0.1]/hook' => 'IPv4-mapped loopback',
    'http://100.64.0.1/hook' => 'carrier-grade NAT',
    'ftp://93.184.215.14/hook' => 'a non-http scheme',
    'https://user:pass@93.184.215.14/hook' => 'credentials in the URL',
] as $url => $what) {
    check("refuses $what", function() use ($alerts, $url) {
        return is_string($alerts->webhookTarget($url)) ?: 'allowed';
    });
}

check('a public address is allowed, and pinned', function() use ($alerts) {
    $t = $alerts->webhookTarget('https://93.184.215.14/hook');

    return is_array($t) && $t['addresses'] === ['93.184.215.14'] && $t['port'] === 443 ?: json_encode($t);
});

check('a refused URL is never requested', function() use ($alerts, &$history) {
    $result = $alerts->postWebhook('http://127.0.0.1:8080/hook', ['text' => 'x']);

    return is_string($result) && $history === [] ?: 'requested: ' . count($history);
});

check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $mock, &$history) {
    $mock->append(new Psr7Response(404));
    $result = $alerts->postWebhook('https://93.184.215.14/hook', ['text' => 'x']);
    $options = $history[0]['options'] ?? [];
    $pin = $options['curl'][CURLOPT_RESOLVE][0] ?? '';

    return $result === 'HTTP 404' && $pin === '93.184.215.14:443:93.184.215.14' && ($options['allow_redirects'] ?? null) === false
        ?: json_encode(['result' => $result, 'pin' => $pin, 'redirects' => $options['allow_redirects'] ?? null]);
});

check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts, $settings) {
    $settings->allowPrivateAlertWebhookHosts = true;
    $t = $alerts->webhookTarget('http://10.1.2.3/hook');
    $settings->allowPrivateAlertWebhookHosts = false;

    return is_array($t) && $t['addresses'] === [] ?: json_encode($t);
});

// ---------------------------------------------------------------------------------------------
section('Redaction');

check('a connection’s secret settings and tokens are taken out by value', function() use ($alerts, $connection, $suffix) {
    $odoo = new Connection(['connector' => 'odoo', 'settings' => ['apiKey' => "sk-live-$suffix-abcdef"]]);
    $a = $alerts->redact($odoo, "Odoo said: bad key sk-live-$suffix-abcdef");
    $b = $alerts->redact($connection, "token rt-secret-$suffix was refused");

    return !str_contains($a, "sk-live-$suffix") && !str_contains($b, "rt-secret-$suffix") ?: "$a | $b";
});

check('anything shaped like a credential is taken out by pattern', function() use ($alerts, $connection) {
    $out = $alerts->redact($connection, 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc {"client_secret":"hunter22xyz"} password=Sup3rS3cret&x=1');

    return !str_contains($out, 'eyJhbGciOiJIUzI1NiJ9') && !str_contains($out, 'hunter22xyz') && !str_contains($out, 'Sup3rS3cret') ?: $out;
});

check('tags are stripped and the length is capped', function() use ($alerts, $connection) {
    $out = $alerts->redact($connection, '<html><body>' . str_repeat('x', 3000) . '</body></html>');

    return !str_contains($out, '<') && mb_strlen($out) <= 500 ?: mb_strlen($out) . ' chars';
});

// ---------------------------------------------------------------------------------------------
section('Dead letters');

$reset();

check('below the threshold, nothing opens and nothing is sent', function() use ($alerts, $connection, $letter, $latch, &$mail) {
    $letter('A');
    $letter('B');
    $alerts->checkConnection($connection);

    return ($latch(Alerts::INCIDENT_DEAD_LETTERS)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode($latch(Alerts::INCIDENT_DEAD_LETTERS));
});

check('reaching it opens the incident and sends one email to every recipient', function() use ($alerts, $connection, $letter, $latch, &$mail) {
    $letter('C', 'Rejected: password=hunter22xyz is wrong');
    $results = $alerts->checkConnection($connection, [Alerts::INCIDENT_DEAD_LETTERS]);
    $row = $latch(Alerts::INCIDENT_DEAD_LETTERS);

    return $row['state'] === 'open' && $row['notifiedAt'] !== null && ($results[0]['transition'] ?? null) === 'opened'
        && count($mail) === 1 && $mail[0]['to'] === ['ops@example.test', 'erp@example.test']
        ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('the email names the connection, links the Problems screen for it, and is redacted', function() use ($connection, &$mail) {
    $body = quoted_printable_decode($mail[0]['body'] ?? '');

    return str_contains($mail[0]['subject'], $connection->name)
        && preg_match('#erpy/problems[?&]connection=' . $connection->id . '\b#', $body)
        && str_contains($body, '3 documents failed')
        && !str_contains($body, 'hunter22xyz')
        ?: $mail[0]['subject'] . "\n" . substr($body, -900);
});

check('it stays quiet while the incident stays open, however often it is checked', function() use ($alerts, $connection, $letter, &$mail) {
    $letter('D');
    $alerts->checkConnection($connection);
    $alerts->checkConnection($connection);
    $alerts->checkConnection($connection);

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('it stays open while anything is still failing inside the window (hysteresis)', function() use ($alerts, $connection, $latch, $ago) {
    // Three of the four age out; one recent failure is below the threshold but keeps it open.
    Craft::$app->getDb()->createCommand()->update(Table::DEAD_LETTERS, ['lastAttemptAt' => $ago('-3 hours')], ['connectionId' => $connection->id, 'naturalKey' => ['A', 'B', 'C']])->execute();
    $alerts->checkConnection($connection);

    return $latch(Alerts::INCIDENT_DEAD_LETTERS)['state'] === 'open' ?: 'closed early';
});

check('a whole quiet window recovers it, with exactly one recovery email', function() use ($alerts, $connection, $latch, $ago, &$mail) {
    Craft::$app->getDb()->createCommand()->update(Table::DEAD_LETTERS, ['lastAttemptAt' => $ago('-3 hours')], ['connectionId' => $connection->id])->execute();
    $alerts->checkConnection($connection);
    $alerts->checkConnection($connection);
    $row = $latch(Alerts::INCIDENT_DEAD_LETTERS);

    return $row['state'] === 'ok' && $row['recoveryNotifiedAt'] !== null && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('a reopening inside the quiet period is held, then sent once it ends', function() use ($alerts, $connection, $letter, $latch, $settings, &$mail) {
    $settings->alertCooldownMinutes = 60;
    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('+30 minutes'))], ['connectionId' => $connection->id, 'incident' => Alerts::INCIDENT_DEAD_LETTERS])->execute();
    $letter('E');
    $letter('F');
    $letter('G');
    $alerts->checkConnection($connection);
    $held = $latch(Alerts::INCIDENT_DEAD_LETTERS)['state'] === 'open' && count($mail) === 2;

    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('-1 minute'))], ['connectionId' => $connection->id, 'incident' => Alerts::INCIDENT_DEAD_LETTERS])->execute();
    $alerts->checkConnection($connection);

    return $held && count($mail) === 3 ?: json_encode(['held' => $held, 'mail' => array_column($mail, 'subject')]);
});

check('a failed send is released and retried on the next check, not lost', function() use ($alerts, $connection, $latch, $ago, $letter, &$mail, &$mailFails) {
    Craft::$app->getDb()->createCommand()->update(Table::DEAD_LETTERS, ['lastAttemptAt' => $ago('-3 hours')], ['connectionId' => $connection->id])->execute();
    $alerts->checkConnection($connection); // recovery
    $mail = [];

    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => null], ['connectionId' => $connection->id])->execute();
    $letter('H');
    $letter('I');
    $letter('J');
    $mailFails = true;
    $alerts->checkConnection($connection);
    $afterFailure = $latch(Alerts::INCIDENT_DEAD_LETTERS);
    $mailFails = false;
    $alerts->checkConnection($connection);

    return $afterFailure['state'] === 'open' && $afterFailure['notifiedAt'] === null && count($mail) === 1
        ?: json_encode(['afterFailure' => $afterFailure, 'mail' => count($mail)]);
});

check('a run finishing evaluates dead letters with no cron at all', function() use ($plugin, $connection, $latch, $ago, &$mail) {
    Craft::$app->getDb()->createCommand()->update(Table::DEAD_LETTERS, ['lastAttemptAt' => $ago('-3 hours')], ['connectionId' => $connection->id])->execute();
    $mail = [];
    $run = $plugin->getRuns()->start($connection, Entity::PRODUCT, Direction::PULL, Run::TRIGGER_MANUAL);
    $plugin->getRuns()->finish($run, Run::STATUS_SUCCESS);

    return $latch(Alerts::INCIDENT_DEAD_LETTERS)['state'] === 'ok' && count($mail) === 1 && str_contains($mail[0]['subject'], 'Recovered')
        ?: json_encode(['state' => $latch(Alerts::INCIDENT_DEAD_LETTERS)['state'], 'mail' => array_column($mail, 'subject')]);
});

// ---------------------------------------------------------------------------------------------
section('Authentication');

$reset();

$api = function(int $status) use ($connection) {
    $auth = new BasicAuth();
    $auth->setConnection($connection);

    return (new Transport())
        ->setConnection($connection)
        ->setAuth($auth)
        ->setMaxAttempts(1)
        ->setDouble(fn() => Response::json($status, $status === 401 ? ['error' => 'Unauthorized: token rt-secret-' . substr($connection->tokens['refreshToken'], 10)] : ['ok' => true]))
        ->get('https://erp.example.test/api/items');
};

check('a final 401 opens the auth incident and alerts at once', function() use ($api, $latch, &$mail, $suffix) {
    $api(401);
    $row = $latch(Alerts::INCIDENT_AUTH);
    $body = quoted_printable_decode($mail[0]['body'] ?? '');

    return $row['state'] === 'open' && count($mail) === 1 && str_contains($mail[0]['subject'], 'Authentication failed')
        && str_contains($body, 'erpy/connections/') && !str_contains($body, "rt-secret-$suffix")
        ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('a second 401 does not send a second alert', function() use ($api, &$mail) {
    $api(401);

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('the next authenticated success recovers it', function() use ($api, $latch, &$mail) {
    $api(200);
    $row = $latch(Alerts::INCIDENT_AUTH);

    return $row['state'] === 'ok' && $row['signalClearedAt'] !== null && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('a 500 is not an authentication failure', function() use ($api, $latch, &$mail) {
    $api(500);

    return $latch(Alerts::INCIDENT_AUTH)['state'] === 'ok' && count($mail) === 2 ?: json_encode($latch(Alerts::INCIDENT_AUTH));
});

check('a refused OAuth refresh opens it too', function() use ($connection, $latch, &$mail) {
    $auth = new OAuth2AuthorizationCode(authorizeUrl: 'https://login.example.test/authorize', tokenUrl: 'https://login.example.test/token');
    $auth->setConnection($connection);
    $auth->setTransport((new Transport())->setConnection($connection)->setDouble(fn() => Response::json(400, ['error' => 'invalid_grant'])));
    $refreshed = $auth->refresh();
    $row = $latch(Alerts::INCIDENT_AUTH);

    return !$refreshed && $row['state'] === 'open' && str_contains((string)$row['detail'], 'invalid_grant') && count($mail) === 3
        ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('a network failure on refresh is not an authentication failure', function() use ($alerts, $connection, $latch) {
    // Clear it first, via a success, then fail the refresh with no HTTP status at all.
    $alerts->noteAuthSuccess($connection);
    $before = $latch(Alerts::INCIDENT_AUTH)['state'];
    $auth = new OAuth2AuthorizationCode(authorizeUrl: 'https://login.example.test/authorize', tokenUrl: 'https://login.example.test/token');
    $auth->setConnection($connection);
    $auth->setTransport((new Transport())->setConnection($connection)->setMaxAttempts(1)->setDouble(fn() => new Response(status: 0, error: 'Network: could not resolve host')));
    $auth->refresh();

    return $before === 'ok' && $latch(Alerts::INCIDENT_AUTH)['state'] === 'ok' ?: "before $before, after " . $latch(Alerts::INCIDENT_AUTH)['state'];
});

check('switched off, a 401 records the signal but alerts nobody', function() use ($api, $latch, $settings, &$mail) {
    $settings->alertOnAuthFailure = false;
    $count = count($mail);
    $api(401);
    $settings->alertOnAuthFailure = true;

    return $latch(Alerts::INCIDENT_AUTH)['state'] === 'ok' && count($mail) === $count ?: json_encode($latch(Alerts::INCIDENT_AUTH));
});

// ---------------------------------------------------------------------------------------------
section('Stalled schedules');

$reset();
Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['state' => 'ok', 'signalClearedAt' => Db::prepareDateForDb(new DateTime())], ['connectionId' => $connection->id])->execute();
Craft::$app->getDb()->createCommand()->delete(Table::RUNS, ['connectionId' => $connection->id])->execute();

check('a connection younger than the stall window is not stalled', function() use ($alerts, $connection) {
    return $alerts->stalledEntities($connection) === [] ?: json_encode($alerts->stalledEntities($connection));
});

check('no successful run for longer than allowed is a stall, and alerts', function() use ($alerts, $connection, $latch, $ago, &$mail) {
    Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, ['dateCreated' => $ago('-10 hours')], ['id' => $connection->id])->execute();
    $stalled = $alerts->stalledEntities($connection);
    $alerts->checkConnection($connection);
    $row = $latch(Alerts::INCIDENT_STALLED);

    return ($stalled[0]['entity'] ?? null) === Entity::PRODUCT && $row['state'] === 'open' && count($mail) === 1
        && preg_match('#erpy/runs[?&]connection=' . $connection->id . '\b#', quoted_printable_decode($mail[0]['body']))
        ?: json_encode(['stalled' => $stalled, 'row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('a failed run does not count as fresh', function() use ($plugin, $alerts, $connection) {
    $run = $plugin->getRuns()->start($connection, Entity::PRODUCT, Direction::PULL, Run::TRIGGER_SCHEDULE);
    $plugin->getRuns()->finish($run, Run::STATUS_FAILED, 'boom');

    return count($alerts->stalledEntities($connection)) === 1 ?: 'treated a failure as fresh';
});

check('twice a long interval beats the stall hours (a nightly sync is not stalled at 7am)', function() use ($alerts, $connection, $plugin) {
    $copy = clone $connection;
    $copy->sync[Entity::PRODUCT]['interval'] = 86400;

    return $alerts->stalledEntities($copy) === [] ?: 'stalled';
});

check('a successful run recovers it, from the run itself', function() use ($plugin, $connection, $latch, &$mail) {
    $run = $plugin->getRuns()->start($connection, Entity::PRODUCT, Direction::PULL, Run::TRIGGER_SCHEDULE);
    $plugin->getRuns()->finish($run, Run::STATUS_SUCCESS);
    $row = $latch(Alerts::INCIDENT_STALLED);

    return $row['state'] === 'ok' && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered') ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('alertStallHours = 0 switches it off', function() use ($alerts, $connection, $settings, $ago) {
    Craft::$app->getDb()->createCommand()->delete(Table::RUNS, ['connectionId' => $connection->id])->execute();
    $on = count($alerts->stalledEntities($connection));
    $settings->alertStallHours = 0;
    $off = count($alerts->stalledEntities($connection));
    $settings->alertStallHours = 6;

    return $on === 1 && $off === 0 ?: "on $on, off $off";
});

check('a disabled connection is left exactly as it is', function() use ($alerts, $connection, $latch) {
    $copy = clone $connection;
    $copy->enabled = false;
    $before = $latch(Alerts::INCIDENT_STALLED);
    $results = $alerts->checkConnection($copy);

    return $results === [] && $latch(Alerts::INCIDENT_STALLED)['dateUpdated'] === $before['dateUpdated'] ?: json_encode($results);
});

// ---------------------------------------------------------------------------------------------
section('The webhook');

$reset(['alertRecipients' => '', 'alertWebhookUrl' => 'https://93.184.215.14/services/T000/B000/xyz', 'alertWebhookSecret' => "whsec-$suffix"]);

check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $connection, $mock, &$history, $suffix) {
    $mock->append(new Psr7Response(200));
    $alerts->checkConnection($connection); // the stall from above reopens: its runs were deleted
    $request = $history[0]['request'] ?? null;

    if (!$request) {
        return 'nothing posted';
    }

    $body = (string)$request->getBody();
    $payload = json_decode($body, true);
    $ts = $request->getHeaderLine('X-Erpy-Timestamp');
    $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, "whsec-$suffix");

    return str_contains($payload['text'] ?? '', 'Scheduled sync stalled') && isset($payload['blocks'])
        && hash_equals($expected, $request->getHeaderLine('X-Erpy-Signature'))
        && ($history[0]['options']['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.215.14:443:93.184.215.14'
        ?: $body;
});

check('a webhook that fails with no email configured is retried, not lost', function() use ($alerts, $connection, $mock, $latch, $plugin, &$history) {
    $run = $plugin->getRuns()->start($connection, Entity::PRODUCT, Direction::PULL, Run::TRIGGER_SCHEDULE);
    $mock->append(new Psr7Response(500));
    $plugin->getRuns()->finish($run, Run::STATUS_SUCCESS); // recovery owed, webhook 500
    $owed = $latch(Alerts::INCIDENT_STALLED)['recoveryNotifiedAt'] === null;
    $mock->append(new Psr7Response(200));
    $alerts->checkConnection($connection);

    return $owed && $latch(Alerts::INCIDENT_STALLED)['recoveryNotifiedAt'] !== null && count($history) === 3
        ?: json_encode(['owed' => $owed, 'posts' => count($history)]);
});

check('Teams gets an Adaptive Card, JSON gets a flat event', function() use ($alerts, $connection) {
    $m = $alerts->compose($connection, Alerts::INCIDENT_AUTH, false, 'HTTP 401');
    $teams = $alerts->payload('teams', $m);
    $json = $alerts->payload('json', $m);

    return ($teams['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard'
        && $json['event'] === 'erpy.alert.opened' && $json['incident'] === 'auth' && $json['connection'] === $connection->handle
        ?: json_encode([$teams, $json]);
});

check('a handler on EVENT_BEFORE_NOTIFY can reword or swallow an alert', function() use ($alerts) {
    $seen = null;
    $handler = function(AlertEvent $e) use (&$seen) {
        $seen = $e->subject;
        $e->isValid = false;
    };
    $alerts->on(Alerts::EVENT_BEFORE_NOTIFY, $handler);
    $result = $alerts->notify(null, 'test', false, 'x');
    $alerts->off(Alerts::EVENT_BEFORE_NOTIFY, $handler);

    return $result === true && is_string($seen) ?: 'not called';
});

// ---------------------------------------------------------------------------------------------
section('Dashboard widget');

$reset();

check('it lists the connection, its last run, its problems and its open incidents', function() use ($connection, $plugin) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['state' => 'open', 'detail' => 'shown on hover'], ['connectionId' => $connection->id, 'incident' => Alerts::INCIDENT_AUTH])->execute();
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);
    $html = (string)(new HealthWidget())->getBodyHtml();
    $view->setTemplateMode($mode);

    return str_contains($html, $connection->name) && str_contains($html, 'Authentication failed') && str_contains($html, 'shown on hover')
        && str_contains($html, 'erpy/runs/') && HealthWidget::isSelectable()
        ?: substr(strip_tags($html), 0, 400);
});

check('it is registered with the Dashboard', function() {
    return in_array(HealthWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes(), true) ?: 'missing';
});

// ---------------------------------------------------------------------------------------------
section('“Send a test alert” over HTTP');

$password = 'Erpy-' . bin2hex(random_bytes(6));
$viewer = new User(['username' => "erpy-alerts-$suffix", 'email' => "erpy-alerts-$suffix@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-erpy', 'erpy-viewconnections', 'erpy-manageconnections', 'erpy-viewruns', 'erpy-replaydocuments']);
$cleanup['users'][] = $viewer;

function client(?string $username, ?string $password): Closure
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
            or throw new RuntimeException("Could not sign in as $username");
    }

    return static fn(string $action, array $params = [], string $method = 'POST', bool $withCsrf = true) => $http->request($method, "index.php?p=admin/actions/$action", [
        'headers' => $json,
        'form_params' => $method === 'POST' ? $params + ($withCsrf ? ['CRAFT_CSRF_TOKEN' => $csrf()] : []) : null,
    ]);
}

check('anonymous is refused', function() {
    $status = client(null, null)('erpy/alerts/test')->getStatusCode();

    return in_array($status, [400, 401, 403], true) ?: "status $status";
});

check('a non-admin with every Erpy permission is refused', function() use ($viewer, $password) {
    $status = client($viewer->username, $password)('erpy/alerts/test')->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('an admin without a CSRF token is refused', function() {
    $status = client('admin', 'claudepassword')('erpy/alerts/test', [], 'POST', false)->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('an admin GET is refused', function() {
    $status = client('admin', 'claudepassword')('erpy/alerts/test', [], 'GET')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin POST answers JSON from the saved settings (and takes no URL from the request)', function() {
    $response = client('admin', 'claudepassword')('erpy/alerts/test', ['alertWebhookUrl' => 'http://169.254.169.254/']);
    $data = json_decode((string)$response->getBody(), true);

    return in_array($response->getStatusCode(), [200, 400], true) && is_string($data['message'] ?? null) && !str_contains((string)$data['message'], '169.254')
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

// ---------------------------------------------------------------------------------------------
section('Cleanup');

check('deleting the connection takes its alert rows with it', function() use ($plugin, $connection, &$cleanup) {
    $plugin->getConnections()->delete($connection);
    $cleanup['connections'] = [];

    return (int)(new Query())->from(Table::ALERTS)->where(['connectionId' => $connection->id])->count() === 0 ?: 'orphans';
});

$settings->setAttributes($original, false);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
