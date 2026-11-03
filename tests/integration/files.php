<?php
/**
 * The file / URL exchange connector, end to end.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/files.php
 *
 * The connector conformance suite drives connectors against a recorded HTTP double, which says
 * nothing about a CSV saved in Windows-1250 by an ERP in Ljubljana. This suite writes real files
 * — in each encoding the connector offers, in CSV, XML and JSON, with a malformed row, a row that
 * is not valid in its encoding, a truncated XML file and a hostile DOCTYPE — and runs them through
 * `Sync::run()`, `Push::deliver()` and the export URL over HTTP, the same paths a live site takes.
 *
 * Self-cleaning: fixture files, products, orders, the connection and everything hanging off it
 * are removed in a `finally`.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use GuzzleHttp\Client;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\connectors\FileConnector;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\files\CsvReader;
use justinholtweb\erpy\files\Encoding;
use justinholtweb\erpy\files\FileException;
use justinholtweb\erpy\files\HttpSource;
use justinholtweb\erpy\files\JsonRecordReader;
use justinholtweb\erpy\files\LocalSource;
use justinholtweb\erpy\files\OrderWriter;
use justinholtweb\erpy\files\RowMapper;
use justinholtweb\erpy\files\SftpSource;
use justinholtweb\erpy\files\Spool;
use justinholtweb\erpy\files\XmlRecordReader;
use justinholtweb\erpy\jobs\PushOrderJob;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderLine;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use justinholtweb\erpy\services\Mapping;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);
$dir = Craft::$app->getPath()->getStoragePath() . "/erpy-file-tests-$suffix";
$inDir = "$dir/in";
$outDir = "$dir/out";
@mkdir($inDir, 0775, true);
@mkdir($outDir, 0775, true);

$connection = null;
$createdProducts = [];
$createdOrders = [];
$createdUsers = [];
$originalSettings = $plugin->getSettings()->toArray();

// The same two sibling plugins checks.php detaches, for the same reasons: neither is Erpy's bug.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

if (Craft::$app->getPlugins()->isPluginEnabled('lyfe')) {
    yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);
}

/** Write a fixture file, in UTF-8 unless told otherwise, and backdate it past the settle window. */
function fixture(string $name, string $utf8, string $encoding = 'UTF-8', int $age = 120): string
{
    global $inDir;

    $path = "$inDir/$name";
    file_put_contents($path, $encoding === 'UTF-8' ? $utf8 : iconv('UTF-8', $encoding, $utf8));
    touch($path, time() - $age);

    return $path;
}

/** @return array<int,array> every record a reader yields */
function readAll(justinholtweb\erpy\files\RecordReader $reader, string $path): array
{
    return iterator_to_array($reader->read($path), false);
}

function makeProduct(string $sku, float $price = 10.0): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];
    $product = new Product(['typeId' => $type->id, 'title' => "Erpy file fixture $sku", 'enabled' => true]);
    $variant = new Variant(['sku' => $sku, 'isDefault' => true]);
    $variant->setBasePrice($price);
    $variant->inventoryTracked = true;
    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    return $createdProducts[] = $product;
}

function makeOrder(Variant $variant, int $qty = 2): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('erpy-files@example.com');
    Craft::$app->getElements()->saveElement($order, false);
    $createdOrders[] = $order;

    $order->setLineItems([Commerce::getInstance()->getLineItems()->createLineItem($order, $variant->id, [], $qty)]);
    $address = [
        'fullName' => 'Špela Čeh',
        'addressLine1' => 'Žabja ulica 7',
        'locality' => 'Ljubljana',
        'postalCode' => '1000',
        'countryCode' => 'SI',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);
    Craft::$app->getElements()->saveElement($order, false);
    $order->markAsComplete();

    return $order;
}

function stockOf(Variant $variant): ?int
{
    $level = Commerce::getInstance()->getInventory()->getInventoryLevelsForPurchasable($variant)->first();

    return $level ? (int)$level->availableTotal : null;
}

function settings(array $overrides = []): array
{
    global $inDir, $outDir;

    return array_merge([
        'source' => FileConnector::SOURCE_LOCAL,
        'directory' => $inDir,
        'format' => 'auto',
        'encoding' => 'UTF-8',
        'delimiter' => 'auto',
        'headerRow' => true,
        'decimalSeparator' => '.',
        'orderMode' => FileConnector::MODE_WRITE,
        'orderTarget' => FileConnector::SOURCE_LOCAL,
        'orderDirectory' => $outDir,
        'orderFilename' => 'order-{orderNumber}.{ext}',
        'orderFormat' => 'xml',
        'orderEncoding' => 'UTF-8',
    ], $overrides);
}

function reconfigure(Connection $connection, array $settings, array $sync = []): Connection
{
    $connection->settings = settings($settings);

    foreach ($sync as $entity => $config) {
        $connection->sync[$entity] = array_merge($connection->sync[$entity] ?? [], $config);
    }

    Plugin::getInstance()->getConnections()->save($connection, false);
    Plugin::getInstance()->getConnections()->clearCache();

    return Plugin::getInstance()->getConnections()->getById($connection->id);
}

function letters(Connection $connection, string $entity): array
{
    return (new craft\db\Query())->from(Table::DEAD_LETTERS)
        ->where(['connectionId' => $connection->id, 'entity' => $entity, 'resolvedAt' => null])
        ->all();
}

try {
    // -----------------------------------------------------------------------------------------
    section('Reading CSV');

    check('a header row names the columns, and a UTF-8 BOM does not end up in the first one', function() {
        $rows = readAll(new CsvReader(), fixture('bom.csv', "\xEF\xBB\xBFsku,qty\nA1,5\nA2,7\n"));

        return ($rows[0]['row'] ?? null) === ['sku' => 'A1', 'qty' => '5'] && count($rows) === 2
            ?: json_encode($rows);
    });

    check('Windows-1250 with semicolons is read as UTF-8, delimiter detected', function() {
        $rows = readAll(new CsvReader('WINDOWS-1250'), fixture('cp1250.csv', "Šifra;Naziv;Zaloga\nŠ-1;Žličke čajne;12,5\n", 'WINDOWS-1250'));
        $row = $rows[0]['row'] ?? [];

        return ($row['Šifra'] ?? null) === 'Š-1' && ($row['Naziv'] ?? null) === 'Žličke čajne' && ($row['Zaloga'] ?? null) === '12,5'
            ?: json_encode($rows, JSON_UNESCAPED_UNICODE);
    });

    check('ISO-8859-2 is read as UTF-8', function() {
        $rows = readAll(new CsvReader('ISO-8859-2', ';'), fixture('latin2.csv', "kod;naziv\nC1;Čokolada đak\n", 'ISO-8859-2'));

        return ($rows[0]['row']['naziv'] ?? null) === 'Čokolada đak' ?: json_encode($rows, JSON_UNESCAPED_UNICODE);
    });

    check('UTF-16 “Unicode text” from Excel is read, tab-separated', function() {
        $path = fixture('excel.txt', '');
        file_put_contents($path, "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', "sku\tqty\nŽ1\t3\n"));
        touch($path, time() - 120);
        $rows = readAll(new CsvReader('UTF-16LE'), $path);

        return ($rows[0]['row'] ?? null) === ['sku' => 'Ž1', 'qty' => '3'] ?: json_encode($rows, JSON_UNESCAPED_UNICODE);
    });

    check('without a header row, columns are numbered from 1', function() {
        $rows = readAll(new CsvReader(headerRow: false), fixture('nohead.csv', "A1,5\n"));

        return ($rows[0]['row'] ?? null) === [1 => 'A1', 2 => '5'] ?: json_encode($rows);
    });

    check('a row with the wrong number of columns is rejected by itself', function() {
        $rows = readAll(new CsvReader(), fixture('ragged.csv', "sku,qty,warehouse\nA1,5,MAIN\nA2,7\nA3,9,MAIN,\n"));

        return (count($rows) === 3 && !isset($rows[0]['error']) && str_contains($rows[1]['error'] ?? '', 'header has 3')
            && !isset($rows[2]['error']))
            ?: json_encode($rows);
    });

    check('a row that is not valid UTF-8 mid-file is rejected, and the rows after it still read', function() {
        $rows = readAll(new CsvReader(), fixture('badutf8.csv', "sku,name\nA1,ok\nA2," . iconv('UTF-8', 'WINDOWS-1250', 'Šal') . "\nA3,fine\n"));

        return (str_contains($rows[1]['error'] ?? '', 'not valid UTF-8') && ($rows[2]['row']['sku'] ?? null) === 'A3' && !isset($rows[2]['error']))
            ?: json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE);
    });

    check('a header that is not valid in the declared encoding refuses the file, naming the encoding', function() {
        try {
            readAll(new CsvReader(), fixture('badhead.csv', iconv('UTF-8', 'WINDOWS-1250', "Šifra,Zaloga\n") . "A1,1\n"));
        } catch (FileException $e) {
            return str_contains($e->getMessage(), 'UTF-8') ?: $e->getMessage();
        }

        return 'no exception';
    });

    check('quoted fields keep their delimiters and line breaks', function() {
        $rows = readAll(new CsvReader(), fixture('quoted.csv', "sku,name\nA1,\"Cups, large\nset of 6\"\n"));

        return ($rows[0]['row']['name'] ?? null) === "Cups, large\nset of 6" ?: json_encode($rows);
    });

    // -----------------------------------------------------------------------------------------
    section('Reading XML and JSON');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Export xmlns:x="urn:example">
  <Items>
    <Item code="A1"><Name>Čaj</Name><Price currency="EUR">9,90</Price><Tag>x</Tag><Tag>y</Tag></Item>
    <Item code="A2"><x:Name>Kava</x:Name><Price currency="EUR">4,50</Price></Item>
    <Other><Item code="NOT-ME"/></Other>
  </Items>
</Export>
XML;

    check('an absolute record path reads only that chain, with attributes, text and repeated elements', function() use ($xml) {
        $rows = readAll(new XmlRecordReader('/Export/Items/Item'), fixture('items.xml', $xml));
        $first = $rows[0]['row'] ?? [];

        return (count($rows) === 2 && ($first['@code'] ?? null) === 'A1' && ($first['Name'] ?? null) === 'Čaj'
            && ($first['Price']['#'] ?? null) === '9,90' && ($first['Price']['@currency'] ?? null) === 'EUR'
            && ($first['Tag'] ?? null) === ['x', 'y'] && ($rows[1]['row']['Name'] ?? null) === 'Kava')
            ?: json_encode($rows, JSON_UNESCAPED_UNICODE);
    });

    check('//Item finds the element at any depth', function() use ($xml) {
        $rows = readAll(new XmlRecordReader('//Item'), fixture('items2.xml', $xml));

        return count($rows) === 3 && ($rows[2]['row']['@code'] ?? null) === 'NOT-ME' ?: json_encode($rows);
    });

    check('an XML file declaring windows-1250 is read as UTF-8', function() {
        $body = '<?xml version="1.0" encoding="windows-1250"?><Zaloga><Artikel><Sifra>Š1</Sifra><Kolicina>3</Kolicina></Artikel></Zaloga>';
        $rows = readAll(new XmlRecordReader('/Zaloga/Artikel'), fixture('cp1250.xml', $body, 'WINDOWS-1250'));

        return ($rows[0]['row']['Sifra'] ?? null) === 'Š1' ?: json_encode($rows, JSON_UNESCAPED_UNICODE);
    });

    check('a truncated XML file is refused whole, and leaves no spool behind', function() {
        $before = count(glob(Spool::directory() . '/*.jsonl') ?: []);

        try {
            Spool::fill((new XmlRecordReader('//Item'))->read(fixture('cut.xml', '<Items><Item><Sku>A1</Sku></Item><Item><Sku>A2</Sk')));
        } catch (FileException $e) {
            $after = count(glob(Spool::directory() . '/*.jsonl') ?: []);

            return (str_contains($e->getMessage(), 'Nothing from this file was applied') && $after === $before) ?: $e->getMessage() . " ($before → $after spools)";
        }

        return 'no exception';
    });

    check('a DOCTYPE cannot read a local file into a record', function() {
        $secret = fixture('secret.txt', 'TOP-SECRET-VALUE');
        $body = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $secret . '">]><Items><Item><Sku>&x;</Sku></Item></Items>';

        try {
            $rows = readAll(new XmlRecordReader('//Item'), fixture('xxe.xml', $body));
        } catch (FileException $e) {
            return str_contains($e->getMessage(), 'declares entities') ?: $e->getMessage();
        }

        return 'read: ' . json_encode($rows);
    });

    check('nor can nested entities expand until the worker runs out of memory', function() {
        $body = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]><Items><Item><Sku>&c;</Sku></Item></Items>';

        try {
            readAll(new XmlRecordReader('//Item'), fixture('laughs.xml', $body));
        } catch (FileException) {
            return true;
        }

        return 'read';
    });

    check('an XML file’s own encoding declaration beats the connection setting', function() {
        $rows = readAll(new XmlRecordReader('//Item', 'WINDOWS-1250'), fixture('utf8decl.xml', '<?xml version="1.0" encoding="UTF-8"?><Items><Item><Sku>Šč</Sku></Item></Items>'));

        return ($rows[0]['row']['Sku'] ?? null) === 'Šč' ?: json_encode($rows, JSON_UNESCAPED_UNICODE);
    });

    check('JSON records under a dotted path; a record that is not an object is rejected', function() {
        $rows = readAll(new JsonRecordReader('data.items'), fixture('p.json', '{"data":{"items":[{"sku":"A1","price":"9.90"},"oops"]}}'));

        return (($rows[0]['row']['sku'] ?? null) === 'A1' && isset($rows[1]['error'])) ?: json_encode($rows);
    });

    // -----------------------------------------------------------------------------------------
    section('Reading values');

    check('decimals are read with the configured mark, never by PHP’s cast', function() {
        $comma = [Mapping::parseDecimal('1.234,56', ','), Mapping::parseDecimal('12,5', ','), Mapping::parseDecimal('1 234,5', ',')];
        $point = [Mapping::parseDecimal('1,234.56', '.'), Mapping::parseDecimal('abc', '.')];

        // The other mark where it cannot be a thousands separator is a decimal, not a ×100.
        $mixed = [Mapping::parseDecimal('9.90', ','), Mapping::parseDecimal('12,50', '.'), Mapping::parseDecimal('1.234', ','), Mapping::parseDecimal('1,234', '.'), Mapping::parseDecimal('-3,5', ','), Mapping::parseDecimal('1e3', '.')];

        return ($comma === [1234.56, 12.5, 1234.5] && $point === [1234.56, null] && $mixed === [9.9, 12.5, 1234.0, 1234.0, -3.5, null])
            ?: json_encode([$comma, $point, $mixed]);
    });

    check('the decimal transform is offered on the mapping screen and reads a comma', function() use ($plugin) {
        return (isset($plugin->getMapping()->transformOptions()['decimal:,']) && $plugin->getMapping()->transform('9,90', 'decimal:,') === 9.9)
            ?: 'transform missing or wrong';
    });

    check('column names are matched without accents, case or punctuation', function() {
        $stock = (new RowMapper(','))->document(Entity::INVENTORY, ['Šifra artikla' => 'S1', 'KOLIČINA' => '7,5', 'Skladišče' => 'LJ']);

        return ($stock instanceof ErpStock && $stock->sku === 'S1' && $stock->onHand === 7.5 && $stock->warehouse === 'LJ' && $stock->remoteId === 'S1|LJ')
            ?: json_encode($stock->toArray());
    });

    check('a stock row with no quantity it can read is rejected, never written as zero', function() {
        $mapper = new RowMapper(',');

        return ($mapper->unreadable(Entity::INVENTORY, ['sku' => 'A', 'Lager' => '5']) !== null
            && $mapper->unreadable(Entity::INVENTORY, ['sku' => 'A', 'qty' => 'n/a']) !== null
            && $mapper->unreadable(Entity::INVENTORY, ['sku' => 'A', 'qty' => '0']) === null
            && $mapper->unreadable(Entity::INVENTORY, ['sku' => 'A', 'Lager' => '5'], ['onHand']) === null
            && $mapper->unreadable(Entity::PRICE, ['sku' => 'A']) !== null)
            ?: 'a row with no quantity was accepted';
    });

    check('a date-only changed column counts the whole day, so a row changed this afternoon is not lost', function() {
        $today = (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('d.m.Y');
        $date = (new RowMapper(dateColumn: 'changed'))->rowDate(['changed' => $today]);

        return $date?->format('H:i:s') === '23:59:59' ?: (string)$date?->format(DATE_ATOM);
    });

    check('a d.m.Y date is read in the site’s timezone', function() {
        $date = (new RowMapper())->date('03.11.2026 14:30');

        return ($date?->format('Y-m-d H:i') === '2026-11-03 14:30' && $date->getTimezone()->getName() === Craft::$app->getTimeZone())
            ?: (string)$date?->format(DATE_ATOM);
    });

    // -----------------------------------------------------------------------------------------
    section('Sources');

    check('a file name cannot leave the folder', function() use ($inDir) {
        $source = new LocalSource($inDir);
        $refused = 0;

        foreach (['../../config/general.php', '/etc/passwd', 'file:///etc/passwd', 'C:/Windows/win.ini', "a\0b", 'a?/../../../etc/passwd', '%2e%2e/%2e%2e/etc/passwd'] as $path) {
            try {
                $source->fetch($path);
            } catch (FileException $e) {
                $refused += $e->retryable ? 0 : 1;
            }
        }

        return $refused === 7 ?: "only $refused of 7 refused permanently";
    });

    check('nor can a symlink inside it', function() use ($inDir) {
        @symlink('/etc', "$inDir/escape");

        try {
            (new LocalSource($inDir))->fetch('escape/hostname');
        } catch (FileException $e) {
            return str_contains($e->getMessage(), 'outside') ?: $e->getMessage();
        }

        return 'the symlink was followed';
    });

    check('a relative folder is refused rather than read from wherever PHP happens to be', function() {
        try {
            new LocalSource('exports');
        } catch (FileException $e) {
            return str_contains($e->getMessage(), 'absolute') ?: $e->getMessage();
        }

        return 'accepted';
    });

    check('a wildcard takes the newest match', function() {
        fixture('stock_20261101.csv', "sku,qty\nOLD,1\n", age: 3000);
        fixture('stock_20261102.csv', "sku,qty\nNEW,1\n", age: 600);
        global $inDir;
        $file = (new LocalSource($inDir))->fetch('stock_*.csv');

        return $file?->name === 'stock_20261102.csv' ?: (string)$file?->name;
    });

    check('an unchanged file is not fetched at all on a delta read', function() use ($inDir) {
        fixture('old.csv', "sku,qty\nA,1\n", age: 3600);

        return (new LocalSource($inDir))->fetch('old.csv', new DateTimeImmutable('-10 minutes')) === null ?: 'it was fetched';
    });

    check('a write is atomic and leaves no temporary file', function() use ($outDir) {
        (new LocalSource($outDir))->write('atomic.xml', '<x/>');
        $leftovers = glob("$outDir/.erpy-*") ?: [];

        return (file_get_contents("$outDir/atomic.xml") === '<x/>' && $leftovers === []) ?: 'leftovers: ' . implode(', ', $leftovers);
    });

    check('a URL source sends If-Modified-Since on a delta read and skips on 304', function() {
        $seen = [];
        $transport = (new Transport())->setDouble(function(string $method, string $url, array $options) use (&$seen) {
            $seen[] = [$url, $options['headers']['If-Modified-Since'] ?? null];

            return new Response(304, [], '');
        });

        $since = new DateTimeImmutable('2026-11-03 10:00:00', new DateTimeZone('UTC'));
        $file = (new HttpSource($transport, 'https://erp.example.test/export?key=1'))->fetch('stock.csv', $since);

        return ($file === null && $seen[0][0] === 'https://erp.example.test/export/stock.csv?key=1' && $seen[0][1] === 'Tue, 03 Nov 2026 10:00:00 GMT')
            ?: json_encode($seen);
    });

    check('a URL source treats a refused login as an auth failure, and a file name as never an address', function() {
        $transport = (new Transport())->setDouble(fn() => new Response(401, [], 'no'));
        $source = new HttpSource($transport, 'https://erp.example.test/');

        try {
            $source->fetch('stock.csv');

            return 'no exception on 401';
        } catch (FileException $e) {
            if (!$e->auth) {
                return 'a 401 was not marked as auth';
            }
        }

        try {
            $source->url('https://attacker.example/x');
        } catch (FileException) {
            return true;
        }

        return 'a full URL was accepted as a file name';
    });

    check('SFTP fingerprints match the form ssh-keygen prints', function() {
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
        $expected = 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode(explode(' ', $key)[1]), true)), '=');

        return (SftpSource::fingerprintOf($key) === $expected
            && SftpSource::normaliseFingerprint(substr($expected, 7) . '=') === $expected)
            ?: SftpSource::fingerprintOf($key);
    });

    check('an SFTP host is a host, not a URL with a password in it', function() {
        try {
            new SftpSource('user:pw@evil.example', 22, 'u', 'p', '', '');
        } catch (FileException) {
            return true;
        }

        return 'accepted';
    });

    // -----------------------------------------------------------------------------------------
    section('Pulling through the engine');

    $skuA = "FILE$suffix-A";
    $skuB = "FILE$suffix-B";
    $skuC = "FILE$suffix-C";
    $productA = makeProduct($skuA);
    $productB = makeProduct($skuB);
    $productC = makeProduct($skuC);
    $variantA = $productA->getVariants()[0];
    $variantB = $productB->getVariants()[0];
    $variantC = $productC->getVariants()[0];

    $connection = new Connection([
        'name' => "Erpy files $suffix",
        'handle' => "erpyfiles$suffix",
        'connector' => 'file',
        'enabled' => true,
        'storeId' => $storeId,
        'settings' => settings(),
        'sync' => [
            Entity::INVENTORY => ['enabled' => true, 'direction' => Direction::PULL, 'filters' => ['path' => 'zaloga.csv']],
            Entity::PRICE => ['enabled' => true, 'direction' => Direction::PULL, 'filters' => ['path' => 'prices.json', 'recordPath' => 'prices']],
            Entity::SHIPMENT => ['enabled' => true, 'direction' => Direction::PULL, 'filters' => ['path' => 'shipments.csv']],
            Entity::ORDER => ['enabled' => true, 'direction' => Direction::PUSH],
        ],
    ]);

    check('a file connection saves and tests green against its folder', function() use ($plugin, &$connection) {
        if (!$plugin->getConnections()->save($connection)) {
            return json_encode($connection->getErrors());
        }

        $result = $connection->getConnector()->test();

        return $result->ok ?: $result->message;
    });

    $stockCsv = "Šifra;Naziv;Zaloga;Skladišče\n"
        . "$skuA;Žličke;12,0;\n"
        . "$skuB;Krožniki\n"                // malformed: two columns short
        . "$skuC;Skodelice;7,0;\n";

    check('a Windows-1250 stock file sets stock, and its malformed row is a dead letter, not a failed run', function() use ($plugin, &$connection, $stockCsv, $variantA, $variantC, $skuB) {
        $connection = reconfigure($connection, ['encoding' => 'WINDOWS-1250', 'decimalSeparator' => ',']);
        fixture('zaloga.csv', $stockCsv, 'WINDOWS-1250');
        $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);
        $dead = letters($connection, Entity::INVENTORY);

        if ($run->status === Run::STATUS_FAILED) {
            return 'the run failed: ' . $run->message;
        }

        if (stockOf($variantA) !== 12 || stockOf($variantC) !== 7) {
            return 'stock is ' . stockOf($variantA) . ' / ' . stockOf($variantC);
        }

        return (count($dead) === 1 && str_starts_with($dead[0]['naturalKey'], $skuB) && !(bool)$dead[0]['retryable'] && $run->failed === 1)
            ?: 'dead letters: ' . json_encode($dead) . ", failed $run->failed";
    });

    check('the identity map knows each stock line by its natural key', function() use ($plugin, $connection, $skuA) {
        $link = $plugin->getLinks()->find($connection, Entity::INVENTORY, "$skuA|");

        return ($link?->remoteId === "$skuA|") ?: json_encode($link?->toArray());
    });

    check('a corrected file clears the dead letter by itself', function() use ($plugin, &$connection, $skuA, $skuB, $skuC, $variantB) {
        fixture('zaloga.csv', "Šifra;Naziv;Zaloga;Skladišče\n$skuA;Žličke;12,0;\n$skuB;Krožniki;4,0;\n$skuC;Skodelice;7,0;\n", 'WINDOWS-1250');
        $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);

        return (letters($connection, Entity::INVENTORY) === [] && stockOf($variantB) === 4) ?: 'still dead-lettered, or stock ' . stockOf($variantB);
    });

    check('a file read across several pages is applied in full, and its spool is removed', function() use ($plugin, $connection, $skuA, $skuB, $skuC, $variantA) {
        $before = count(glob(Spool::directory() . '/*.jsonl') ?: []);
        fixture('zaloga.csv', "Šifra;Zaloga\n$skuA;20\n$skuB;21\n$skuC;22\n", 'WINDOWS-1250');
        $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true, 'limit' => 1]);
        $after = count(glob(Spool::directory() . '/*.jsonl') ?: []);

        return ($run->updated === 3 && stockOf($variantA) === 20 && $after === $before)
            ?: "updated $run->updated, spools $before → $after";
    });

    check('a delta run skips a file that has not changed since the last one', function() use ($plugin, $connection, $variantA, $skuA) {
        // Changed on disk but backdated: the watermark says it was already read.
        fixture('zaloga.csv', "Šifra;Zaloga\n$skuA;99\n", 'WINDOWS-1250', age: 7200);
        $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['force' => true]);

        return (stockOf($variantA) === 20 && $run->updated === 0) ?: 'stock became ' . stockOf($variantA);
    });

    check('a file changed seconds ago is left for the next run, not read half-written', function() use ($plugin, $connection, $variantA, $skuA) {
        fixture('zaloga.csv', "Šifra;Zaloga\n$skuA;55\n", 'WINDOWS-1250', age: 0);
        $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);

        return stockOf($variantA) === 20 ?: 'it was read: ' . stockOf($variantA);
    });

    check('a changed-date column reads only the rows changed since the watermark', function() use ($plugin, &$connection, $skuA, $skuB, $variantA, $variantB) {
        $connection = reconfigure($connection, ['encoding' => 'WINDOWS-1250', 'decimalSeparator' => ','], [Entity::INVENTORY => ['filters' => ['path' => 'zaloga.csv', 'dateColumn' => 'Spremenjeno']]]);
        $old = (new DateTime('-30 days'))->format('d.m.Y H:i');
        $new = (new DateTime('+1 hour'))->format('d.m.Y H:i');
        fixture('zaloga.csv', "Šifra;Zaloga;Spremenjeno\n$skuA;31;$old\n$skuB;32;$new\n", 'WINDOWS-1250');

        // Pretend the last run was a day ago, so the file itself counts as changed.
        Plugin::getInstance()->getCursors()->advance($connection, Entity::INVENTORY, Direction::PULL, new DateTime('-1 day'));
        $plugin->getSync()->run($connection, Entity::INVENTORY, ['force' => true]);

        $connection = reconfigure($connection, ['encoding' => 'WINDOWS-1250', 'decimalSeparator' => ','], [Entity::INVENTORY => ['filters' => ['path' => 'zaloga.csv']]]);

        return (stockOf($variantA) === 20 && stockOf($variantB) === 32) ?: 'stock ' . stockOf($variantA) . ' / ' . stockOf($variantB);
    });

    check('a mapping rule corrects the column the connector guessed', function() use ($plugin, $connection, $skuA, $variantA) {
        $map = $plugin->getMapping()->get($connection, Entity::INVENTORY, Direction::PULL);
        $map->rules = [['source' => 'raw.Ident', 'target' => 'sku'], ['source' => 'raw.Prosto', 'target' => 'onHand', 'transform' => 'decimal:,']];
        $plugin->getMapping()->save($map);

        fixture('zaloga.csv', "Ident;Prosto;Šifra\n$skuA;41,0;WRONG\n", 'WINDOWS-1250');
        $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);

        $map->rules = [];
        $plugin->getMapping()->save($map);

        return stockOf($variantA) === 41 ?: 'stock ' . stockOf($variantA);
    });

    check('a JSON price file reaches the price table', function() use ($plugin, $connection, $skuA) {
        fixture('prices.json', json_encode(['prices' => [['sku' => $skuA, 'price' => 8.25, 'pricelist' => 'VP', 'currency' => 'EUR']]]));
        $run = $plugin->getSync()->run($connection, Entity::PRICE, ['full' => true, 'force' => true]);
        $row = (new craft\db\Query())->from(Table::PRICES)->where(['connectionId' => $connection->id, 'sku' => $skuA])->one();

        return ($row && (float)$row['unitPrice'] === 8.25 && $row['priceListCode'] === 'VP') ?: 'run ' . $run->status . ' ' . $run->message . ' ' . json_encode($row);
    });

    check('a truncated XML file fails the run, applies nothing, and holds the watermark', function() use ($plugin, &$connection, $skuA, $variantA) {
        $connection = reconfigure($connection, [], [Entity::INVENTORY => ['filters' => ['path' => 'stock.xml', 'recordPath' => '//Item']]]);
        $watermark = $plugin->getCursors()->watermark($connection, Entity::INVENTORY, Direction::PULL);
        fixture('stock.xml', "<Stock><Item><sku>$skuA</sku><qty>77</qty></Item><Item><sku>X</sku><qty>1");
        $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['force' => true, 'full' => false]);
        $after = $plugin->getCursors()->watermark($connection, Entity::INVENTORY, Direction::PULL);
        $connection = reconfigure($connection, [], [Entity::INVENTORY => ['filters' => ['path' => 'zaloga.csv']]]);

        return ($run->status === Run::STATUS_FAILED && stockOf($variantA) === 41 && $watermark?->getTimestamp() === $after?->getTimestamp())
            ?: "status $run->status, stock " . stockOf($variantA);
    });

    check('a file that is not there fails the run in words, not a stack trace', function() use ($plugin, &$connection) {
        $connection = reconfigure($connection, [], [Entity::INVENTORY => ['filters' => ['path' => 'nope.csv']]]);
        $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);
        $connection = reconfigure($connection, [], [Entity::INVENTORY => ['filters' => ['path' => 'zaloga.csv']]]);

        return ($run->status === Run::STATUS_FAILED && str_contains((string)$run->message, 'There is no nope.csv')) ?: (string)$run->message;
    });

    check('shipment rows of one delivery note become one shipment with several lines', function() use ($connection, $skuA, $skuB) {
        fixture('shipments.csv', "Narocilo,Dobavnica,Sledenje,Sifra,Kolicina\nORD-1,DN-1,TRK1,$skuA,2\nORD-1,DN-1,TRK1,$skuB,1\nORD-2,DN-2,TRK2,$skuA,1\n");
        $connector = $connection->getConnector();
        $page = $connector->fetchPage(Entity::SHIPMENT, new FetchCriteria(['limit' => 1, 'filters' => $connection->filtersFor(Entity::SHIPMENT)]));
        $first = $page->items[0] ?? null;

        return ($first instanceof ErpShipment && $first->shipmentNumber === 'DN-1' && count($first->lines) === 2
            && count($page->items) === 1 && $page->hasMore())
            ?: json_encode(array_map(fn($d) => $d->toArray(), $page->items));
    });

    check('uploaded files are read from the connection’s own inbox', function() use (&$connection, $skuC, $variantC, $plugin) {
        $connection = reconfigure($connection, ['source' => FileConnector::SOURCE_UPLOAD]);
        $inbox = FileConnector::inbox($connection);
        @mkdir($inbox, 0775, true);
        file_put_contents("$inbox/inventory.csv", "sku,qty\n$skuC,63\n");
        $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);
        $connection = reconfigure($connection, ['encoding' => 'WINDOWS-1250', 'decimalSeparator' => ',']);
        array_map('unlink', glob("$inbox/*") ?: []);
        @rmdir($inbox);

        return stockOf($variantC) === 63 ?: 'stock ' . stockOf($variantC);
    });

    check('the connection screen shows each entity’s file fields, and the upload control only for the inbox', function() use ($plugin, $connection) {
        $connector = $connection->getConnector();
        $render = function(Connection $c) use ($plugin, $connector) {
            $view = Craft::$app->getView();
            $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

            return $view->getTwig()->load('erpy/connections/_edit')->renderBlock('content', [
                'connection' => $c,
                'connector' => $plugin->getConnectors()->describeOne('file'),
                'connectors' => $plugin->getConnectors()->describe(),
                'settingsFields' => $connector::settingsFields(),
                'entityFields' => array_filter(array_combine(Entity::syncOrder(), array_map(fn($e) => $connector::entityFields($e), Entity::syncOrder()))),
                'capabilities' => $connector::capabilities(),
                'entities' => Entity::syncOrder(),
                'lastRuns' => [],
                'redirectUri' => Plugin::redirectUri(),
                'oauth' => null,
                'webhookUrl' => null,
                'canManage' => true,
            ]);
        };

        $folder = $render($connection);
        $upload = clone $connection;
        $upload->settings['source'] = FileConnector::SOURCE_UPLOAD;
        $inbox = $render($upload);

        return (str_contains($folder, 'name="sync[inventory][filters][path]"') && str_contains($folder, 'value="zaloga.csv"')
            && !str_contains($folder, 'erpy-upload-file') && str_contains($inbox, 'erpy-upload-file')
            && str_contains($inbox, 'type="hidden" name="sync[inventory][filters][path]"')
            && str_contains($folder, "erpy/export/{$connection->handle}/orders"))
            ?: 'the screen is missing a field';
    });

    // -----------------------------------------------------------------------------------------
    section('Writing orders');

    $order = makeOrder($variantA, 3);

    check('an order is written as one XML file in the ERP’s encoding, and the identity map has it', function() use ($plugin, &$connection, $order, $outDir) {
        $connection = reconfigure($connection, ['orderEncoding' => 'WINDOWS-1250']);
        $result = $plugin->getPush()->order($connection, $order);
        $path = "$outDir/order-{$order->number}.xml";

        if (!$result->success || !is_file($path)) {
            return 'push: ' . $result->message;
        }

        $bytes = (string)file_get_contents($path);
        $dom = new DOMDocument();
        $dom->loadXML($bytes);

        return (str_contains($bytes, 'encoding="windows-1250"') && str_contains($bytes, iconv('UTF-8', 'WINDOWS-1250', 'Špela Čeh'))
            && $dom->getElementsByTagName('orderNumber')->item(0)?->textContent === $order->number
            && $dom->getElementsByTagName('line')->length === 1
            && $plugin->getLinks()->find($connection, Entity::ORDER, $order->number)?->remoteId === basename($path))
            ?: substr($bytes, 0, 300);
    });

    check('pushing it again writes nothing new', function() use ($plugin, $connection, $order, $outDir) {
        $path = "$outDir/order-{$order->number}.xml";
        file_put_contents($path, 'ERP-TOUCHED');
        $result = $plugin->getPush()->order($connection, $order);

        return ($result->duplicate && file_get_contents($path) === 'ERP-TOUCHED') ?: 'rewritten';
    });

    check('a CSV order has one row per line and the decimal comma', function() use ($order, $connection) {
        $writer = new OrderWriter(OrderWriter::CSV, 'UTF-8', 'auto', ',');
        $document = Plugin::getInstance()->getPush()->buildOrder($connection, $order);
        $csv = $writer->render([$document]);
        $lines = array_values(array_filter(explode("\n", $csv)));

        return (count($lines) === 2 && str_contains($lines[0], 'line.sku') && str_contains($lines[0], ';') && preg_match('/;\d+,\d{2};/', $lines[1]))
            ?: $csv;
    });

    check('a refused folder is retryable, so the queue tries again once somebody fixes it', function() use ($connection, $order) {
        $broken = clone $connection;
        $broken->settings['orderDirectory'] = '/nonexistent-erpy-folder';
        $connector = $broken->getConnector();
        $result = $connector->pushDocument(Entity::ORDER, Plugin::getInstance()->getPush()->buildOrder($connection, $order));

        return (!$result->success && $result->retryable) ?: 'retryable: ' . var_export($result->retryable, true);
    });

    check('an order file name every order would share, or a script extension, is refused', function() use ($connection, $order) {
        $document = Plugin::getInstance()->getPush()->buildOrder($connection, $order);
        $results = [];

        foreach (['orders.{ext}', 'order-{orderNumber}.php'] as $pattern) {
            $c = clone $connection;
            $c->settings['orderFilename'] = $pattern;
            $results[] = $c->getConnector()->pushDocument(Entity::ORDER, $document);
        }

        return (!$results[0]->success && !$results[0]->retryable && str_contains((string)$results[0]->message, '{orderNumber}')
            && !$results[1]->success && !$results[1]->retryable)
            ?: json_encode(array_map(fn($r) => $r->message, $results));
    });

    check('a Twig template can write the order file instead', function() use ($connection, $order) {
        $templates = Craft::$app->getPath()->getSiteTemplatesPath();
        $name = "_erpy-file-test-{$connection->handle}";
        file_put_contents("$templates/$name.twig", "{% for o in orders %}NAR|{{ o.orderNumber }}|{{ o.lines|length }}\n{% endfor %}");

        try {
            $writer = new OrderWriter(template: $name, encoding: 'WINDOWS-1250');
            $out = $writer->render([Plugin::getInstance()->getPush()->buildOrder($connection, $order)]);
        } finally {
            @unlink("$templates/$name.twig");
        }

        return $out === "NAR|{$order->number}|1\n" ?: $out;
    });

    // -----------------------------------------------------------------------------------------
    section('Serving orders');

    $token = 'tok-' . bin2hex(random_bytes(16));
    $connection = reconfigure($connection, ['orderMode' => FileConnector::MODE_SERVE, 'exportToken' => $token, 'orderEncoding' => 'WINDOWS-1250']);
    // Placed once the connection serves, as a real order would be.
    $served = makeOrder($variantB, 1);

    check('a served connection is never pushed: no job at checkout, and a push from anywhere else is refused', function() use ($plugin, &$connection, $served) {
        // Other connections on this shared harness push orders too; only this one's jobs count.
        $mine = fn() => (int)(new craft\db\Query())->from('{{%queue}}')
            ->where(['like', 'job', 'PushOrderJob'])
            ->andWhere(['like', 'job', 'connectionId";i:' . $connection->id . ';'])
            ->count();
        $before = $mine();
        $plugin->getPush()->queueOrder($served);
        $after = $mine();

        // The control: the same query does see the job a writing connection gets.
        $connection = reconfigure($connection, ['orderMode' => FileConnector::MODE_WRITE]);
        $plugin->getPush()->queueOrder($served);
        $control = $mine();
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['and', ['like', 'job', 'PushOrderJob'], ['like', 'job', 'connectionId";i:' . $connection->id . ';']])->execute();
        global $token;
        $connection = reconfigure($connection, ['orderMode' => FileConnector::MODE_SERVE, 'exportToken' => $token, 'orderEncoding' => 'WINDOWS-1250']);

        if ($control !== $before + 1) {
            return "the job query sees nothing even in write mode ($before → $control), so this check proves nothing";
        }

        $result = $plugin->getPush()->order($connection, $served, ['trigger' => Run::TRIGGER_QUEUE]);
        $link = $plugin->getLinks()->find($connection, Entity::ORDER, $served->number);

        return ($after === $before && !$result->success && !$result->retryable && $link === null && letters($connection, Entity::ORDER) === [])
            ?: "jobs $before → $after, result " . json_encode($result) . ', link ' . json_encode($link?->toArray());
    });

    $http = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
    $export = "index.php?p=erpy/export/{$connection->handle}/orders";

    check('no token, a wrong token, and a token in the URL when that is not allowed are all refused, as a 404', function() use ($http, $export, $token) {
        $statuses = [
            $http->get($export)->getStatusCode(),
            $http->get($export, ['headers' => ['X-Erpy-Token' => 'wrong']])->getStatusCode(),
            $http->get($export . '&key=' . $token)->getStatusCode(),
        ];

        // The same 404 as a handle that does not exist, so the endpoint gives nothing away.
        return $statuses === [404, 404, 404] ?: json_encode($statuses);
    });

    check('only GET: a HEAD or a POST cannot mark orders delivered', function() use ($http, $export, $token, $plugin, $connection, $served) {
        $statuses = [
            $http->head($export, ['headers' => ['X-Erpy-Token' => $token]])->getStatusCode(),
            $http->post($export, ['headers' => ['X-Erpy-Token' => $token]])->getStatusCode(),
        ];

        return (!in_array(200, $statuses, true) && $plugin->getLinks()->find($connection, Entity::ORDER, $served->number) === null)
            ?: json_encode($statuses);
    });

    check('the right token gets the undelivered orders as Windows-1250 XML, and records them delivered', function() use ($http, $export, $token, $plugin, $connection, $served, $order) {
        $response = $http->get($export, ['headers' => ['X-Erpy-Token' => $token]]);
        $body = (string)$response->getBody();
        $dom = new DOMDocument();
        @$dom->loadXML($body);
        $numbers = array_map(fn($node) => $node->textContent, iterator_to_array($dom->getElementsByTagName('orderNumber')));

        if ($response->getStatusCode() !== 200) {
            return 'status ' . $response->getStatusCode() . ': ' . substr($body, 0, 200);
        }

        // The order written in write mode is already delivered, so only the new one is served.
        return (in_array($served->number, $numbers, true) && !in_array($order->number, $numbers, true)
            && str_contains($response->getHeaderLine('Content-Type'), 'windows-1250')
            && str_starts_with((string)$plugin->getLinks()->find($connection, Entity::ORDER, $served->number)?->remoteId, 'export:'))
            ?: json_encode([$numbers, $response->getHeaderLine('Content-Type')]);
    });

    check('an export that fails to render marks nothing delivered', function() use ($http, $export, $token, $plugin, &$connection, $variantA) {
        global $createdOrders;
        $failing = makeOrder($variantA, 1);
        $connection = reconfigure($connection, ['orderMode' => FileConnector::MODE_SERVE, 'exportToken' => $token, 'orderEncoding' => 'WINDOWS-1250', 'orderTemplate' => '_erpy-no-such-template']);
        $status = $http->get($export, ['headers' => ['X-Erpy-Token' => $token]])->getStatusCode();
        $connection = reconfigure($connection, ['orderMode' => FileConnector::MODE_SERVE, 'exportToken' => $token, 'orderEncoding' => 'WINDOWS-1250']);
        $link = $plugin->getLinks()->find($connection, Entity::ORDER, $failing->number);

        $served = $http->get($export, ['headers' => ['X-Erpy-Token' => $token]]);

        return ($status === 500 && !$link?->isDelivered() && str_contains((string)$served->getBody(), $failing->number))
            ?: "status $status, link " . json_encode($link?->toArray());
    });

    check('orders that cannot be served right now do not stop newer ones being served', function() use ($http, $export, $token, $variantA, $connection, $plugin) {
        $stuck = [makeOrder($variantA, 1), makeOrder($variantA, 1)];
        $fresh = makeOrder($variantA, 1);

        // Holding the push lock for the two older orders makes the web process fail them the way
        // a concurrent poll would — before anything is recorded — so they stay at the front of
        // the queue, oldest first, exactly where they could starve everything behind them.
        $mutex = Craft::$app->getMutex();
        $locks = array_map(fn($o) => 'erpy:push:' . $connection->id . ':' . Entity::ORDER . ':' . md5((string)$o->number), $stuck);

        foreach ($locks as $lock) {
            $mutex->acquire($lock);
        }

        try {
            $response = $http->get("$export&limit=1", ['headers' => ['X-Erpy-Token' => $token]]);
        } finally {
            foreach ($locks as $lock) {
                $mutex->release($lock);
            }
        }

        $body = (string)$response->getBody();

        return (str_contains($body, $fresh->number) && !str_contains($body, $stuck[0]->number)
            && !$plugin->getLinks()->find($connection, Entity::ORDER, $stuck[0]->number)?->isDelivered())
            ?: $response->getStatusCode() . ' ' . substr($body, 0, 300);
    });

    check('the next poll does not serve it again', function() use ($http, $export, $token, $served) {
        $response = $http->get($export, ['headers' => ['X-Erpy-Token' => $token]]);

        // Drain whatever the checks above left undelivered, then ask once more: nothing.
        $again = $http->get($export, ['headers' => ['X-Erpy-Token' => $token]]);

        return (!str_contains((string)$response->getBody(), $served->number) && $again->getHeaderLine('X-Erpy-Count') === '0')
            ?: (string)$again->getBody();
    });

    check('a date range serves it again, for an ERP that lost a response', function() use ($http, $export, $token, $served) {
        $today = (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
        $response = $http->get("$export&from=$today&to=$today", ['headers' => ['X-Erpy-Token' => $token]]);

        return str_contains((string)$response->getBody(), $served->number) ?: (string)$response->getBody();
    });

    check('basic auth carries the token too, and the URL does once allowed', function() use ($http, $export, $token, &$connection) {
        $basic = $http->get($export, ['auth' => ['erp', $token]])->getStatusCode();
        $connection = reconfigure($connection, ['orderMode' => FileConnector::MODE_SERVE, 'exportToken' => $token, 'exportTokenInUrl' => true]);
        $query = $http->get("$export&key=$token")->getStatusCode();

        return [$basic, $query] === [200, 200] ?: json_encode([$basic, $query]);
    });

    check('an unknown or disabled export answers 404, whatever the token', function() use ($http, $token, $plugin, &$connection) {
        $unknown = $http->get('index.php?p=erpy/export/no-such-handle/orders', ['headers' => ['X-Erpy-Token' => $token]])->getStatusCode();
        $connection->enabled = false;
        $plugin->getConnections()->save($connection, false);
        $disabled = $http->get("index.php?p=erpy/export/{$connection->handle}/orders", ['headers' => ['X-Erpy-Token' => $token]])->getStatusCode();
        $connection->enabled = true;
        $plugin->getConnections()->save($connection, false);

        return [$unknown, $disabled] === [404, 404] ?: json_encode([$unknown, $disabled]);
    });

    check('the export token is encrypted at rest like any other secret', function() use ($connection, $token) {
        $raw = (string)(new craft\db\Query())->select(['settings'])->from(Table::CONNECTIONS)->where(['id' => $connection->id])->scalar();

        return (!str_contains($raw, $token) && str_contains($raw, 'enc:')) ?: 'stored as ' . substr($raw, 0, 200);
    });

    // -----------------------------------------------------------------------------------------
    section('Over HTTP, as a signed-in user');

    $password = 'Erpy-' . bin2hex(random_bytes(6));
    $users = [];

    foreach (['runner' => ['accesscp', 'accessplugin-erpy', 'erpy-viewconnections', 'erpy-runsync', 'erpy-manageconnections'], 'viewer' => ['accesscp', 'accessplugin-erpy', 'erpy-viewconnections']] as $role => $permissions) {
        $user = new craft\elements\User(['username' => "erpy-files-$role-$suffix", 'email' => "erpy-files-$role-$suffix@example.com", 'newPassword' => $password]);
        Craft::$app->getElements()->saveElement($user, false);
        Craft::$app->getUsers()->activateUser($user);
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
        $users[$role] = $user;
        $createdUsers[] = $user;
    }

    $signIn = function(string $username) use ($password): array {
        $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new GuzzleHttp\Cookie\CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
        $json = ['Accept' => 'application/json'];
        $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
        $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

        return [$http, $csrf];
    };

    $upload = function(array $session, string $entity, string $name, string $contents) use (&$connection) {
        [$http, $csrf] = $session;

        return $http->post('index.php?p=admin/actions/erpy/files/upload', [
            'headers' => ['Accept' => 'application/json'],
            'multipart' => [
                ['name' => 'CRAFT_CSRF_TOKEN', 'contents' => $csrf()],
                ['name' => 'id', 'contents' => (string)$connection->id],
                ['name' => 'entity', 'contents' => $entity],
                ['name' => 'file', 'contents' => $contents, 'filename' => $name],
            ],
        ]);
    };

    $connection = reconfigure($connection, ['source' => FileConnector::SOURCE_UPLOAD]);
    $runner = $signIn($users['runner']->username);

    check('an upload lands in the inbox, replaces the last one, and queues a sync of that entity', function() use ($upload, $runner, &$connection) {
        $inbox = FileConnector::inbox($connection);
        $first = $upload($runner, Entity::INVENTORY, 'stock.csv', "sku,qty\nX,1\n");
        $second = $upload($runner, Entity::INVENTORY, 'Zaloga 03.11.xml', '<Stock/>');
        $files = array_map('basename', glob("$inbox/*") ?: []);
        $jobs = (int)(new craft\db\Query())->from('{{%queue}}')->where(['like', 'job', 'SyncJob'])->andWhere(['like', 'job', 'connectionId";i:' . $connection->id . ';'])->count();
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['and', ['like', 'job', 'SyncJob'], ['like', 'job', 'connectionId";i:' . $connection->id . ';']])->execute();

        return ($first->getStatusCode() === 200 && $second->getStatusCode() === 200 && $files === ['inventory.xml'] && $jobs === 2)
            ?: json_encode([$first->getStatusCode(), (string)$second->getBody(), $files, $jobs]);
    });

    check('a file that is not CSV, XML or JSON is refused', function() use ($upload, $runner, $connection) {
        $response = $upload($runner, Entity::INVENTORY, 'shell.php', '<?php echo 1;');
        $files = array_map('basename', glob(FileConnector::inbox($connection) . '/*') ?: []);

        return ($response->getStatusCode() === 400 && !in_array('inventory.php', $files, true)) ?: $response->getStatusCode() . ' ' . json_encode($files);
    });

    check('somebody who cannot run syncs cannot upload one', function() use ($upload, $signIn, $users) {
        $status = $upload($signIn($users['viewer']->username), Entity::INVENTORY, 'stock.csv', "sku,qty\nX,1\n")->getStatusCode();

        return $status === 403 ?: "status $status";
    });

    check('a manager who is not an admin cannot point the connection at another folder', function() use ($runner, &$connection) {
        [$http, $csrf] = $runner;
        $response = $http->post('index.php?p=admin/actions/erpy/connections/save', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'CRAFT_CSRF_TOKEN' => $csrf(),
                'connectionId' => $connection->id,
                'name' => $connection->name,
                'handle' => $connection->handle,
                'connector' => 'file',
                'settings' => array_merge($connection->settings, ['source' => FileConnector::SOURCE_LOCAL, 'directory' => '/etc']),
            ],
        ]);
        $stored = json_decode((string)(new craft\db\Query())->select(['settings'])->from(Table::CONNECTIONS)->where(['id' => $connection->id])->scalar(), true);

        return ($response->getStatusCode() === 403 && ($stored['directory'] ?? null) !== '/etc') ?: 'status ' . $response->getStatusCode();
    });

    check('the per-entity file fields save from the connection form', function() use ($runner, &$connection, $plugin) {
        [$http, $csrf] = $runner;
        $sync = $connection->sync;
        $sync[Entity::INVENTORY]['filters'] = ['path' => '  zaloga_*.csv ', 'recordPath' => '', 'dateColumn' => 'Spremenjeno'];
        $response = $http->post('index.php?p=admin/actions/erpy/connections/save', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'CRAFT_CSRF_TOKEN' => $csrf(),
                'connectionId' => $connection->id,
                'name' => $connection->name,
                'handle' => $connection->handle,
                'connector' => 'file',
                'enabled' => '1',
                'settings' => $connection->settings,
                'sync' => $sync,
            ],
        ]);
        $plugin->getConnections()->clearCache();
        $connection = $plugin->getConnections()->getById($connection->id);

        return (in_array($response->getStatusCode(), [200, 302], true) && $connection->filtersFor(Entity::INVENTORY)['path'] === 'zaloga_*.csv'
            && $connection->filtersFor(Entity::INVENTORY)['dateColumn'] === 'Spremenjeno')
            ?: $response->getStatusCode() . ' ' . json_encode($connection->filtersFor(Entity::INVENTORY));
    });

    $connection = reconfigure($connection, ['encoding' => 'WINDOWS-1250', 'decimalSeparator' => ','], [Entity::INVENTORY => ['filters' => ['path' => 'zaloga.csv']]]);
    array_map('unlink', glob(FileConnector::inbox($connection) . '/*') ?: []);
    @rmdir(FileConnector::inbox($connection));

    // -----------------------------------------------------------------------------------------
    // Against a real server, when one is given:
    //   docker run -d --rm --name erpy-sftp-test --network ddev-plugin-testing_default atmoz/sftp erpy:pass123:1001:1001:export
    //   docker exec -e ERPY_SFTP_HOST=erpy-sftp-test -w /var/www/html ddev-plugin-testing-web php …/files.php
    $sftpHost = (string)getenv('ERPY_SFTP_HOST');

    if ($sftpHost !== '') {
        section("SFTP ($sftpHost)");

        $user = getenv('ERPY_SFTP_USER') ?: 'erpy';
        $pass = getenv('ERPY_SFTP_PASSWORD') ?: 'pass123';
        $folder = getenv('ERPY_SFTP_DIR') ?: 'export';
        $fingerprint = '';

        check('with no fingerprint saved, Erpy refuses to log in and names the key the server showed', function() use ($sftpHost, $user, $pass, $folder, &$fingerprint) {
            try {
                (new SftpSource($sftpHost, 22, $user, $pass, '', '', $folder))->probe();
            } catch (FileException $e) {
                if (preg_match('/SHA256:[A-Za-z0-9+\/]+/', $e->getMessage(), $m) && !$e->retryable) {
                    $fingerprint = $m[0];

                    return true;
                }

                return $e->getMessage();
            }

            return 'it logged in to a server it could not identify';
        });

        check('a different fingerprint is refused before any password is sent', function() use ($sftpHost, $user, $pass, $folder) {
            try {
                (new SftpSource($sftpHost, 22, $user, $pass, '', 'SHA256:' . str_repeat('A', 43), $folder))->probe();
            } catch (FileException $e) {
                return str_contains($e->getMessage(), 'not the one saved') ?: $e->getMessage();
            }

            return 'logged in anyway';
        });

        check('a wrong password is an auth failure, so the alerts can say the credentials expired', function() use ($sftpHost, $user, $folder, &$fingerprint) {
            try {
                (new SftpSource($sftpHost, 22, $user, 'wrong', '', $fingerprint, $folder))->probe();
            } catch (FileException $e) {
                return $e->auth ?: $e->getMessage();
            }

            return 'logged in with the wrong password';
        });

        check('with the fingerprint, it writes atomically, finds the newest match, and reads it back', function() use ($sftpHost, $user, $pass, $folder, &$fingerprint) {
            $source = new SftpSource($sftpHost, 22, $user, $pass, '', $fingerprint, $folder);
            $source->write('stock_1.csv', "sku,qty\nOLD,1\n");
            sleep(1);
            $source->write('stock_2.csv', "sku,qty\nNEW,2\n");
            $file = $source->fetch('stock_*.csv');
            $contents = $file ? (string)file_get_contents($file->path) : '';
            $file?->release();

            return ($file?->name === 'stock_2.csv' && str_contains($contents, 'NEW') && $source->exists('stock_1.csv')
                && str_contains($source->probe(), 'Logged in'))
                ?: "got {$file?->name}: $contents";
        });

        check('a stock file on the SFTP server reaches Commerce through the engine', function() use ($plugin, &$connection, $sftpHost, $user, $pass, $folder, &$fingerprint, $skuC, $variantC) {
            (new SftpSource($sftpHost, 22, $user, $pass, '', $fingerprint, $folder))->write('zaloga.csv', "sku;qty\n$skuC;88\n");

            // Just written, so the settle guard would rightly leave it for the next run.
            $raw = new phpseclib3\Net\SFTP($sftpHost);
            $raw->login($user, $pass);
            $raw->touch("$folder/zaloga.csv", time() - 120);
            $connection = reconfigure($connection, [
                'source' => FileConnector::SOURCE_SFTP,
                'sftpHost' => $sftpHost,
                'sftpUsername' => $user,
                'sftpPassword' => $pass,
                'sftpFingerprint' => $fingerprint,
                'sftpDirectory' => $folder,
            ]);
            $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);

            return stockOf($variantC) === 88 ?: "run $run->status $run->message, stock " . stockOf($variantC);
        });

        check('an order is written to the SFTP server', function() use ($plugin, &$connection, $sftpHost, $user, $pass, $folder, &$fingerprint, $variantC) {
            $connection = reconfigure($connection, [
                'source' => FileConnector::SOURCE_SFTP,
                'sftpHost' => $sftpHost,
                'sftpUsername' => $user,
                'sftpPassword' => $pass,
                'sftpFingerprint' => $fingerprint,
                'sftpDirectory' => $folder,
                'orderTarget' => FileConnector::SOURCE_SFTP,
                'orderDirectory' => $folder,
            ]);
            $sftpOrder = makeOrder($variantC, 1);
            $result = $plugin->getPush()->order($connection, $sftpOrder);
            $exists = (new SftpSource($sftpHost, 22, $user, $pass, '', $fingerprint, $folder))->exists("order-{$sftpOrder->number}.xml");

            return ($result->success && $exists) ?: 'push: ' . $result->message;
        });
    }
} finally {
    echo "\nCleanup\n";
    $elements = Craft::$app->getElements();

    foreach (array_merge($createdOrders, $createdProducts, $createdUsers) as $element) {
        try {
            $elements->deleteElement($element, true);
        } catch (Throwable $e) {
            echo "  ! could not delete element {$element->id}: {$e->getMessage()}\n";
        }
    }

    if ($connection?->id) {
        try {
            $plugin->getConnections()->delete($connection);
        } catch (Throwable $e) {
            echo "  ! could not delete the connection: {$e->getMessage()}\n";
        }
    }

    // Queue jobs pushed for the fixture orders by the harness's other connections.
    try {
        foreach ($createdOrders as $fixtureOrder) {
            Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['like', 'job', (string)$fixtureOrder->number])->execute();
        }
    } catch (Throwable) {
    }

    craft\helpers\FileHelper::removeDirectory($dir);
    Craft::$app->getPlugins()->savePluginSettings($plugin, $originalSettings);

    echo "  ✓ fixtures removed\n";
    echo "\n" . str_repeat('-', 60) . "\n  $passed passed, $failed failed\n" . str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
