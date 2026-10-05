<?php
/**
 * Where a connection's credentials can be sent, how they are stored, and how a webhook proves
 * itself — checked in the plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/security.php
 *
 * Until 5.1.1 a user with "Manage connections" could point a connection at their own host with the
 * secret left blank — the stored one was carried over — and press Test; tokens and literal secrets
 * sat in the database as plain JSON; and a webhook took its secret as `?secret=` on a GET.
 *
 * Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\helpers\Secret;
use justinholtweb\erpy\migrations\m261005_000000_encrypt_credentials;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$connections = $plugin->getConnections();
$run = bin2hex(random_bytes(3));
$password = 'Erpy-' . bin2hex(random_bytes(6));
$literal = "sk-literal-$run";
$cleanup = ['connections' => [], 'users' => []];

register_shutdown_function(function() use (&$cleanup, $connections) {
    foreach ($cleanup['connections'] as $id) {
        if ($c = $connections->getById($id)) {
            $connections->delete($c);
        }
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

if (!$plugin->getConnectors()->has('odoo')) {
    echo "The Odoo add-on is not installed in this harness.\n";
    exit(1);
}

$connection = new Connection([
    'name' => "Security $run",
    'handle' => "security$run",
    'connector' => 'odoo',
    'enabled' => false,
    'settings' => ['url' => 'https://example.odoo.com', 'database' => 'example', 'username' => 'api@example.com', 'apiKey' => $literal, 'webhookSecret' => "hook-$run"],
    'tokens' => ['refreshToken' => "rt-$run"],
]);
$connections->save($connection) or throw new RuntimeException(json_encode($connection->getErrors()));
$cleanup['connections'][] = $connection->id;

$row = static fn() => (new Query())->select(['settings', 'tokens', 'connector'])->from(Table::CONNECTIONS)->where(['id' => $connection->id])->one();
$fresh = static function() use ($connections, $connection): Connection {
    // The web process saved it; read the row again rather than this process's memo.
    return new Connection((new Query())->from(Table::CONNECTIONS)->where(['id' => $connection->id])->one());
};

echo "\nAt rest\n";

check('a literal secret is stored encrypted, and still reads', function() use ($row, $fresh, $literal) {
    $stored = json_decode($row()['settings'], true)['apiKey'] ?? '';

    return Secret::isEncrypted($stored) && !str_contains($row()['settings'], $literal) && $fresh()->getSetting('apiKey') === $literal
        ?: 'stored as ' . substr($stored, 0, 20);
});

check('the token bag is stored encrypted, and still reads', function() use ($row, $fresh, $run) {
    $stored = (string)$row()['tokens'];

    return Secret::isEncrypted($stored) && !str_contains($stored, "rt-$run") && ($fresh()->tokens['refreshToken'] ?? null) === "rt-$run"
        ?: 'stored as ' . substr($stored, 0, 20);
});

check('an $ENV reference is stored as it is', function() {
    return Secret::protectSetting('$ODOO_API_KEY') === '$ODOO_API_KEY' ?: 'encrypted';
});

check('a row written before 5.1.1 still reads, and the migration encrypts it', function() use ($connection, $row, $fresh, $literal, $run) {
    Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, [
        'settings' => json_encode(['url' => 'https://example.odoo.com', 'database' => 'example', 'username' => 'api@example.com', 'apiKey' => $literal, 'webhookSecret' => "hook-$run"]),
        'tokens' => json_encode(['refreshToken' => "rt-$run"]),
    ], ['id' => $connection->id])->execute();

    $before = $fresh()->getSetting('apiKey') === $literal && ($fresh()->tokens['refreshToken'] ?? null) === "rt-$run";
    (new m261005_000000_encrypt_credentials())->safeUp();
    $r = $row();

    return $before && Secret::isEncrypted($r['tokens']) && Secret::isEncrypted(json_decode($r['settings'], true)['apiKey'] ?? '')
        && $fresh()->getSetting('apiKey') === $literal
        ?: json_encode(['legacy read' => $before]);
});

echo "\nRepointing a connection\n";

$manager = new User(['username' => "erpy-manager-$run", 'email' => "erpy-manager-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($manager, false);
Craft::$app->getUsers()->activateUser($manager);
Craft::$app->getUserPermissions()->saveUserPermissions($manager->id, ['accesscp', 'accessplugin-erpy', 'erpy-viewconnections', 'erpy-manageconnections']);
$cleanup['users'][] = $manager;

function client(string $username, string $password): Closure
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return static fn(string $action, array $params) => $http->post("index.php?p=admin/actions/$action", ['headers' => $json, 'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode();
}

$asManager = client($manager->username, $password);
$asAdmin = client('admin', 'claudepassword');

$save = static fn(array $settings, string $connector = 'odoo') => [
    'connectionId' => $connection->id, 'name' => "Security $run", 'handle' => "security$run",
    'connector' => $connector, 'settings' => $settings,
];

check('“Manage connections” can’t point it at another host', function() use ($asManager, $save, $fresh) {
    $status = $asManager('erpy/connections/save', $save(['url' => 'https://attacker.example', 'apiKey' => '']));

    return $status === 403 && $fresh()->settings['url'] === 'https://example.odoo.com' ?: "status $status, url " . $fresh()->settings['url'];
});

check('…or switch the connector', function() use ($asManager, $save) {
    $status = $asManager('erpy/connections/save', $save(['url' => 'https://example.odoo.com', 'apiKey' => ''], 'sapb1'));

    return $status === 403 ?: "status $status";
});

check('…but can still save everything else, the stored secret kept', function() use ($asManager, $save, $fresh, $literal) {
    $status = $asManager('erpy/connections/save', $save(['url' => 'https://example.odoo.com', 'database' => 'renamed', 'username' => 'api@example.com', 'apiKey' => '']));

    return in_array($status, [200, 302], true) && $fresh()->settings['database'] === 'renamed' && $fresh()->getSetting('apiKey') === $literal ?: "status $status";
});

check('an admin repointing it leaves the stored secret and tokens behind', function() use ($asAdmin, $save, $fresh) {
    $status = $asAdmin('erpy/connections/save', $save(['url' => 'https://new.odoo.example', 'database' => 'x', 'username' => 'api@example.com', 'apiKey' => '']));
    $c = $fresh();

    return in_array($status, [200, 302], true) && $c->settings['url'] === 'https://new.odoo.example' && ($c->getSetting('apiKey') ?? '') === '' && $c->tokens === []
        ?: "status $status, key " . var_export($c->getSetting('apiKey'), true) . ', tokens ' . json_encode($c->tokens);
});

check('…and keeps a new one posted with the change', function() use ($asAdmin, $save, $fresh, $run) {
    $status = $asAdmin('erpy/connections/save', $save(['url' => 'https://other.odoo.example', 'database' => 'x', 'username' => 'api@example.com', 'apiKey' => "sk-new-$run"]));

    return in_array($status, [200, 302], true) && $fresh()->getSetting('apiKey') === "sk-new-$run" ?: "status $status";
});

echo "\nThe webhook\n";

// The repoints above dropped the stored settings; give it a webhook secret again.
Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, [
    'enabled' => true,
    'settings' => json_encode(['url' => 'https://example.odoo.com', 'database' => 'x', 'username' => 'api@example.com', 'webhookSecret' => "hook-$run"]),
], ['id' => $connection->id])->execute();

$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
$hook = "index.php?p=actions/erpy/webhook/receive&connectionHandle=security$run";

check('the secret on a GET query string is refused', function() use ($anon, $hook, $run) {
    $status = $anon->get("$hook&secret=hook-$run")->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('…and in a POST body', function() use ($anon, $hook, $run) {
    $status = $anon->post($hook, ['form_params' => ['secret' => "hook-$run"]])->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('POST with the X-Erpy-Secret header is accepted', function() use ($anon, $hook, $run) {
    $status = $anon->post($hook, ['headers' => ['X-Erpy-Secret' => "hook-$run"]])->getStatusCode();

    return $status === 200 ?: "status $status";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
