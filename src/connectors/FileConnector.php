<?php

namespace justinholtweb\erpy\connectors;

use Craft;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use justinholtweb\erpy\auth\ApiKeyAuth;
use justinholtweb\erpy\auth\BasicAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\files\CsvReader;
use justinholtweb\erpy\files\Encoding;
use justinholtweb\erpy\files\FileException;
use justinholtweb\erpy\files\FileSource;
use justinholtweb\erpy\files\HttpSource;
use justinholtweb\erpy\files\JsonRecordReader;
use justinholtweb\erpy\files\LocalFile;
use justinholtweb\erpy\files\LocalSource;
use justinholtweb\erpy\files\OrderWriter;
use justinholtweb\erpy\files\RecordReader;
use justinholtweb\erpy\files\RowMapper;
use justinholtweb\erpy\files\SftpSource;
use justinholtweb\erpy\files\Spool;
use justinholtweb\erpy\files\XmlRecordReader;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * An ERP whose API is a file.
 *
 * Most of the ERPs small and mid-size shops run — and nearly all the local ones in south-east
 * Europe (Pantheon, Minimax, Synesis, Luceed, 4D) — exchange data as files: they export stock and
 * prices as CSV or XML, and import orders from a folder or by polling a URL the shop exposes. The
 * transport is trivial. What matters is that the identity map, delta watermarks, dead letters,
 * the run log and the mapping overlay apply to a file exactly as they apply to NetSuite.
 *
 * **In:** products, prices, stock, customers, order statuses and shipments, from CSV, XML or
 * JSON, in a folder on this server, at a URL, on an SFTP server, or uploaded in the control
 * panel. Each file is parsed once per run into a spool and paged from there.
 *
 * **Out:** orders, either *written* — one file per order, atomically, to a folder or SFTP — or
 * *served* at `erpy/export/<handle>/orders` for the ERP to fetch. Both go through
 * `Push::deliver()`, so a served order is in the identity map and is not served twice.
 *
 * Traps, for whoever debugs this next:
 * - A UTF-8 file that is really Windows-1250 fails on its first `š`, row by row. The rows are
 *   dead-lettered with "not valid UTF-8", which is the clue.
 * - Excel saves CSV with `;` wherever `,` is the decimal mark. The delimiter is sniffed from the
 *   first line unless set; decimals are not — set the decimal mark.
 * - An ERP mid-write leaves half a file. A file changed in the last 15 seconds is left for the
 *   next run, and an XML file that stops being well-formed is refused whole.
 * - Files carry no ids. A row's natural key is its remote id, so the identity map still works.
 */
class FileConnector extends Connector
{
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_URL = 'url';
    public const SOURCE_SFTP = 'sftp';
    public const SOURCE_UPLOAD = 'upload';

    public const MODE_WRITE = 'write';
    public const MODE_SERVE = 'serve';

    /** A file changed this recently may still be being written. */
    private const SETTLE_SECONDS = 15;

    private const PULL_ENTITIES = [
        Entity::CUSTOMER,
        Entity::PRODUCT,
        Entity::PRICE,
        Entity::INVENTORY,
        Entity::ORDER_STATUS,
        Entity::SHIPMENT,
    ];

    /** @var ErpOrder[]|null orders handed over while the export endpoint is collecting */
    private static ?array $collected = null;

    private ?FileSource $source = null;

    public static function handle(): string
    {
        return 'file';
    }

    public static function displayName(): string
    {
        return 'File / URL exchange';
    }

    public static function vendor(): string
    {
        return 'Erpy';
    }

    public static function description(): string
    {
        return 'For an ERP that exchanges files instead of offering an API: read CSV, XML or JSON from a folder, a URL, SFTP or an upload, and write orders as files or serve them at a URL the ERP fetches.';
    }

    public static function capabilities(): Capabilities
    {
        // Delta is real for a file: an unchanged file (by its modification time, or a 304 from a
        // URL) is skipped without being parsed, and a changed-date column filters rows.
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 250)
            ->supports(Entity::PRICE, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: true, pageSize: 1000)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 500)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: 500);
    }

    public static function settingsFields(): array
    {
        $encodings = Encoding::options();

        return [
            Field::heading(Craft::t('erpy', 'Where the files are'), Craft::t('erpy', 'Each entity’s file is named on the “What to sync” table below. The location itself can only be set or changed by an admin.')),
            Field::select('source', Craft::t('erpy', 'Read files from'), [
                self::SOURCE_LOCAL => Craft::t('erpy', 'A folder on this server'),
                self::SOURCE_URL => Craft::t('erpy', 'A URL'),
                self::SOURCE_SFTP => Craft::t('erpy', 'An SFTP server'),
                self::SOURCE_UPLOAD => Craft::t('erpy', 'Files uploaded in the control panel'),
            ], ['required' => true, 'endpoint' => true]),
            Field::text('directory', Craft::t('erpy', 'Folder'), [
                'instructions' => Craft::t('erpy', 'For a folder on this server: an absolute path or an alias such as `@storage/erp`.'),
                'endpoint' => true,
            ]),
            Field::url('baseUrl', Craft::t('erpy', 'Base URL'), [
                'instructions' => Craft::t('erpy', 'For a URL: the address each file name is added to, or the whole address of a single export.'),
            ]),
            Field::select('urlAuth', Craft::t('erpy', 'URL login'), [
                'none' => Craft::t('erpy', 'None'),
                'basic' => Craft::t('erpy', 'Username and password'),
                'bearer' => Craft::t('erpy', 'Bearer token'),
            ]),
            Field::text('urlUsername', Craft::t('erpy', 'URL username')),
            Field::secret('urlPassword', Craft::t('erpy', 'URL password or token')),
            Field::text('sftpHost', Craft::t('erpy', 'SFTP host'), ['endpoint' => true, 'placeholder' => 'sftp.example.com']),
            Field::number('sftpPort', Craft::t('erpy', 'SFTP port'), ['default' => 22, 'min' => 1, 'max' => 65535, 'endpoint' => true]),
            Field::text('sftpUsername', Craft::t('erpy', 'SFTP username')),
            Field::secret('sftpPassword', Craft::t('erpy', 'SFTP password'), [
                'instructions' => Craft::t('erpy', 'Or the passphrase of the private key below.'),
            ]),
            Field::secret('sftpPrivateKey', Craft::t('erpy', 'SFTP private key'), [
                // It may name a key file on this server, so choosing it is an admin's call.
                'endpoint' => true,
                'instructions' => Craft::t('erpy', 'An environment variable holding the key, or the path to a key file. Leave blank to log in with the password.'),
            ]),
            Field::text('sftpFingerprint', Craft::t('erpy', 'SFTP server fingerprint'), [
                'instructions' => Craft::t('erpy', 'The server’s SHA256 host key fingerprint. Erpy will not log in without it; Test connection shows the one the server presents.'),
                'placeholder' => 'SHA256:…',
                'endpoint' => true,
            ]),
            Field::text('sftpDirectory', Craft::t('erpy', 'SFTP folder'), ['placeholder' => '/export', 'endpoint' => true]),

            Field::heading(Craft::t('erpy', 'Reading files')),
            Field::select('format', Craft::t('erpy', 'Format'), [
                'auto' => Craft::t('erpy', 'Tell from the file'),
                'csv' => 'CSV',
                'xml' => 'XML',
                'json' => 'JSON',
            ]),
            Field::select('encoding', Craft::t('erpy', 'Encoding'), $encodings, [
                'instructions' => Craft::t('erpy', 'How the ERP saves its files. An XML file’s own declaration takes precedence.'),
            ]),
            Field::select('delimiter', Craft::t('erpy', 'CSV delimiter'), [
                'auto' => Craft::t('erpy', 'Detect'),
                ',' => Craft::t('erpy', 'Comma'),
                ';' => Craft::t('erpy', 'Semicolon'),
                'tab' => Craft::t('erpy', 'Tab'),
                '|' => Craft::t('erpy', 'Pipe'),
            ]),
            Field::boolean('headerRow', Craft::t('erpy', 'CSV files have a header row'), [
                'default' => true,
                'instructions' => Craft::t('erpy', 'Without one, columns are mapped by number: `raw.1`, `raw.2`…'),
            ]),
            Field::select('decimalSeparator', Craft::t('erpy', 'Decimal mark'), [
                '.' => Craft::t('erpy', 'Point (1,234.56)'),
                ',' => Craft::t('erpy', 'Comma (1.234,56)'),
            ]),
            Field::text('dateFormat', Craft::t('erpy', 'Date format'), [
                'instructions' => Craft::t('erpy', 'A PHP date format such as `d.m.Y H:i`, for dates the ERP writes unusually. Dates are read in the site’s timezone.'),
                'suggestEnvVars' => false,
            ]),

            Field::heading(Craft::t('erpy', 'Sending orders')),
            Field::select('orderMode', Craft::t('erpy', 'Orders reach the ERP by'), [
                self::MODE_WRITE => Craft::t('erpy', 'Erpy writing a file for each order'),
                self::MODE_SERVE => Craft::t('erpy', 'The ERP fetching them from the export URL'),
            ], ['endpoint' => true]),
            Field::select('orderTarget', Craft::t('erpy', 'Write order files to'), [
                self::SOURCE_LOCAL => Craft::t('erpy', 'A folder on this server'),
                self::SOURCE_SFTP => Craft::t('erpy', 'The SFTP server above'),
            ], ['endpoint' => true]),
            Field::text('orderDirectory', Craft::t('erpy', 'Order folder'), [
                'instructions' => Craft::t('erpy', 'An absolute path or alias on this server, or a folder on the SFTP server.'),
                'endpoint' => true,
            ]),
            Field::text('orderFilename', Craft::t('erpy', 'Order file name'), [
                'default' => 'order-{orderNumber}.{ext}',
                'instructions' => Craft::t('erpy', 'Must contain `{orderNumber}` and end in `.{ext}`, `.xml`, `.csv`, `.json` or `.txt`. Also `{reference}` and `{date}`.'),
                'suggestEnvVars' => false,
            ]),
            Field::select('orderFormat', Craft::t('erpy', 'Order format'), [
                OrderWriter::XML => 'XML',
                OrderWriter::CSV => Craft::t('erpy', 'CSV, one row per line'),
                OrderWriter::JSON => 'JSON',
            ]),
            Field::select('orderEncoding', Craft::t('erpy', 'Order encoding'), $encodings),
            Field::text('orderTemplate', Craft::t('erpy', 'Order template'), [
                'instructions' => Craft::t('erpy', 'Optional. A site template that receives `orders` and writes the file itself, for an ERP that wants its own layout.'),
                'suggestEnvVars' => false,
            ]),
            Field::secret('exportToken', Craft::t('erpy', 'Export token'), [
                'instructions' => Craft::t('erpy', 'What the ERP sends to fetch orders: as an `X-Erpy-Token` header, or as the password of a basic-auth login. Use a long random value.'),
            ]),
            Field::boolean('exportTokenInUrl', Craft::t('erpy', 'Also accept the token in the URL'), [
                'instructions' => Craft::t('erpy', 'As `?key=…`, only for an ERP that can fetch nothing but a plain address. A token in a URL ends up in server logs.'),
            ]),
            Field::copyable('exportUrl', Craft::t('erpy', 'Export URL'), self::exportUrlTemplate(), [
                'instructions' => Craft::t('erpy', 'Add `?from=2026-11-01&to=2026-11-03` to fetch a date range again, or `?all=1` for every order. Without them, only orders not yet fetched.'),
            ]),
        ];
    }

    public static function entityFields(string $entity): array
    {
        if (!in_array($entity, self::PULL_ENTITIES, true)) {
            return [];
        }

        return [
            Field::text('path', Craft::t('erpy', 'File'), [
                'when' => ['source' => [self::SOURCE_LOCAL, self::SOURCE_URL, self::SOURCE_SFTP]],
                'placeholder' => match ($entity) {
                    Entity::INVENTORY => 'stock.csv',
                    Entity::PRICE => 'prices.csv',
                    Entity::PRODUCT => 'items.xml',
                    Entity::CUSTOMER => 'customers.csv',
                    default => $entity . '.csv',
                },
                'instructions' => Craft::t('erpy', 'Inside the folder or URL above. `stock_*.csv` takes the newest match.'),
                'suggestEnvVars' => false,
            ]),
            Field::text('recordPath', Craft::t('erpy', 'Record path'), [
                'placeholder' => '/Export/Items/Item',
                'instructions' => Craft::t('erpy', 'XML or JSON: where one record is.'),
                'suggestEnvVars' => false,
            ]),
            Field::text('dateColumn', Craft::t('erpy', 'Changed-date column'), [
                'instructions' => Craft::t('erpy', 'Optional. Only rows changed since the last run are read.'),
                'suggestEnvVars' => false,
            ]),
            Field::upload('upload', Craft::t('erpy', 'Upload'), [
                'when' => ['source' => self::SOURCE_UPLOAD],
                'instructions' => Craft::t('erpy', 'When files are uploaded here: the latest one replaces the last.'),
            ]),
        ];
    }

    public function deliversOnRequest(string $entity): bool
    {
        return $entity === Entity::ORDER && $this->setting('orderMode') === self::MODE_SERVE;
    }

    protected function buildAuth(): ?AuthInterface
    {
        if ($this->setting('source') !== self::SOURCE_URL) {
            return null;
        }

        return match ($this->setting('urlAuth')) {
            'basic' => new BasicAuth('urlUsername', 'urlPassword'),
            'bearer' => new ApiKeyAuth('urlPassword', 'Authorization', 'Bearer '),
            default => null,
        };
    }

    // ---------------------------------------------------------------------------------------
    // Health
    // ---------------------------------------------------------------------------------------

    protected function probe(): HealthResult
    {
        $details = [];

        try {
            $details[Craft::t('erpy', 'Files')] = $this->source()->probe();
            $this->authWorked();
        } catch (FileException $e) {
            $this->authFailed($e);

            return HealthResult::fail($this->redactSecrets($e->getMessage()), $this->hints());
        }

        if ($this->setting('orderMode') === self::MODE_SERVE) {
            $details[Craft::t('erpy', 'Orders')] = (string)$this->setting('exportToken') === ''
                ? Craft::t('erpy', 'Served at the export URL once an export token is set.')
                : Craft::t('erpy', 'Served at {url}', ['url' => str_replace('{handle}', $this->connection->handle, self::exportUrlTemplate())]);
        } elseif ($this->connection->syncs(Entity::ORDER, Direction::PUSH)) {
            try {
                $target = $this->orderTarget();
                $target->probe();
                $details[Craft::t('erpy', 'Orders')] = Craft::t('erpy', 'Written to {where}', ['where' => $target->describe()]);
            } catch (FileException $e) {
                return HealthResult::fail($this->redactSecrets($e->getMessage()), [], $details);
            }
        }

        return HealthResult::pass(Craft::t('erpy', 'The files are within reach.'), $details);
    }

    private function hints(): array
    {
        return match ($this->setting('source')) {
            self::SOURCE_SFTP => [Craft::t('erpy', 'Check the host, the login and the fingerprint with whoever runs the SFTP server.')],
            self::SOURCE_URL => [Craft::t('erpy', 'Open the base URL in a browser with the same login.')],
            default => [Craft::t('erpy', 'The folder must exist and be readable by the web server’s user.')],
        };
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->readPage(Entity::CUSTOMER, $criteria);
    }

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->readPage(Entity::PRODUCT, $criteria);
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        return $this->readPage(Entity::PRICE, $criteria);
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        return $this->readPage(Entity::INVENTORY, $criteria);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->readPage(Entity::ORDER_STATUS, $criteria);
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        return $this->readPage(Entity::SHIPMENT, $criteria);
    }

    /**
     * One page of an entity's file: the first page reads the file into a spool, every later one
     * continues from the spool at the cursor's byte offset.
     */
    private function readPage(string $entity, FetchCriteria $criteria): Page
    {
        if ($criteria->cursor !== null && $criteria->cursor !== '') {
            [$id, $offset] = array_pad(explode(':', $criteria->cursor, 2), 2, '0');
            $spool = Spool::open($id);

            if ($spool === null) {
                throw new FileException(Craft::t('erpy', 'The spooled copy of the file has gone. Run the sync again.'));
            }

            $offset = (int)$offset;
        } else {
            $spool = $this->spool($entity, $criteria);

            if ($spool === null) {
                return Page::empty();
            }

            $offset = 0;
        }

        $mapper = $this->mapper($criteria);
        $limit = max(1, $criteria->limit);
        $keepTogether = $entity === Entity::SHIPMENT
            ? fn(array $last, array $next) => $this->shipmentKey($mapper, $last) !== null && $this->shipmentKey($mapper, $last) === $this->shipmentKey($mapper, $next)
            : null;

        // A page that a date column filters down to nothing must not end the run while the file
        // has more, so keep reading until something is kept or the spool runs out.
        do {
            [$records, $next] = $spool->read($offset, $limit, $keepTogether);
            $page = $this->toPage($entity, $records, $mapper, $criteria);
            $offset = (int)$next;
        } while ($next !== null && $page->items === [] && $page->rejected === []);

        if ($next === null) {
            $spool->delete();
        } else {
            $page->cursor = $spool->id . ':' . $next;
        }

        return $page;
    }

    /**
     * Fetch the entity's file and read it into a spool, or null when it has not changed.
     */
    private function spool(string $entity, FetchCriteria $criteria): ?Spool
    {
        $source = $this->source();
        $path = $this->setting('source') === self::SOURCE_UPLOAD
            ? $entity . '.*'
            : trim((string)($criteria->filters['path'] ?? ''));

        if ($path === '') {
            throw FileException::permanent(Craft::t('erpy', 'Name the file that holds {entity} on the connection’s “What to sync” table.', ['entity' => strtolower(Entity::displayName($entity))]));
        }

        // A file's time comes from somebody else's clock. Allowing a quarter of an hour for it
        // costs re-reading an unchanged file now and then — one hash comparison a row — where a
        // clock running slow would otherwise hide a changed file for good.
        $since = $criteria->since === null ? null : DateTimeImmutable::createFromInterface($criteria->since)->modify('-15 minutes');

        try {
            $file = $source->fetch($path, $since);
            $this->authWorked();
        } catch (FileException $e) {
            $this->authFailed($e);

            throw $e;
        }

        if ($file === null) {
            $this->note(Craft::t('erpy', '{entity}: the file has not changed since the last run.', ['entity' => Entity::displayName($entity)]));

            return null;
        }

        try {
            // An upload is complete by the time it is saved; anything else may be mid-write.
            if ($this->setting('source') !== self::SOURCE_UPLOAD
                && $file->modifiedAt !== null && time() - $file->modifiedAt->getTimestamp() < self::SETTLE_SECONDS) {
                // The watermark this run sets is its own start, which is before this change, so
                // the next run reads the file in full.
                $this->note(Craft::t('erpy', '{name} changed a moment ago and may still be being written; it will be read on the next run.', ['name' => $file->name]));

                return null;
            }

            return Spool::fill($this->reader($file, $criteria)->read($file->path));
        } finally {
            $file->release();
        }
    }

    /**
     * @param array<int,array{l:int,r:array,e?:string}> $records
     */
    private function toPage(string $entity, array $records, RowMapper $mapper, FetchCriteria $criteria): Page
    {
        $page = new Page();
        $previous = null;
        $mapped = $this->mappedFields($entity);

        foreach ($records as $record) {
            $row = (array)($record['r'] ?? []);
            $error = $record['e'] ?? null;

            if ($error === null && ($why = $mapper->unreadable($entity, $row, $mapped)) !== null) {
                $error = Craft::t('erpy', 'Row {line}: {error}', ['line' => (int)$record['l'], 'error' => $why]);
            }

            if ($error !== null) {
                $key = $this->bestKey($entity, $mapper, $row, (int)$record['l']);
                $page->reject($key, (string)$error, $row);
                $previous = null;
                continue;
            }

            if ($criteria->since !== null) {
                $date = $mapper->rowDate($row);

                if ($date !== null && $date < $criteria->since) {
                    continue;
                }
            }

            $document = $mapper->document($entity, $row);

            if ($criteria->ids !== [] && !in_array((string)$document->remoteId, $criteria->ids, true)) {
                continue;
            }

            // A shipment of several items is usually one row per item; consecutive rows of the
            // same shipment become one document with several lines.
            if ($document instanceof ErpShipment && $previous instanceof ErpShipment
                && self::groupKey($document) !== null && self::groupKey($document) === self::groupKey($previous)) {
                $previous->lines = array_merge($previous->lines, $document->lines);
                $previous->raw['_rows'][] = $row;
                continue;
            }

            $page->items[] = $document;
            $previous = $document;
        }

        return $page;
    }

    private function shipmentKey(RowMapper $mapper, array $record): ?string
    {
        if (isset($record['e'])) {
            return null;
        }

        $document = $mapper->document(Entity::SHIPMENT, (array)($record['r'] ?? []));

        return $document instanceof ErpShipment ? self::groupKey($document) : null;
    }

    /**
     * What makes consecutive rows one shipment: its delivery note number, or failing that the
     * order and tracking number — the same identity its natural key uses.
     */
    private static function groupKey(ErpShipment $shipment): ?string
    {
        if ($shipment->shipmentNumber !== null && $shipment->shipmentNumber !== '') {
            return $shipment->shipmentNumber;
        }

        return $shipment->trackingNumber ? $shipment->orderNumber . '|' . strtolower($shipment->trackingNumber) : null;
    }

    /**
     * Canonical fields the merchant's mapping sets, which the row need not supply itself.
     *
     * @return string[]
     */
    private function mappedFields(string $entity): array
    {
        if (!$this->connection->id) {
            return [];
        }

        $map = Plugin::getInstance()->getMapping()->get($this->connection, $entity, Direction::PULL);

        return array_values(array_filter(array_map(static fn(array $rule) => trim((string)($rule['target'] ?? '')), $map->rules)));
    }

    /**
     * The natural key a rejected row would have had, so its dead letter clears when a corrected
     * file brings it back — or its row number, when nothing in it can be read.
     */
    private function bestKey(string $entity, RowMapper $mapper, array $row, int $line): string
    {
        try {
            $key = $mapper->document($entity, $row)->naturalKey();
        } catch (Throwable) {
            $key = '';
        }

        return trim($key, '|') !== '' ? $key : Craft::t('erpy', 'row {line}', ['line' => $line]);
    }

    private function reader(LocalFile $file, FetchCriteria $criteria): RecordReader
    {
        $encoding = Encoding::normalise((string)$this->setting('encoding', Encoding::UTF8));

        if (!Encoding::isSupported($encoding)) {
            throw FileException::permanent(Craft::t('erpy', 'This server cannot read the {encoding} encoding.', ['encoding' => $encoding]));
        }

        $recordPath = trim((string)($criteria->filters['recordPath'] ?? ''));

        return match ($this->format($file)) {
            'xml' => new XmlRecordReader($recordPath, $encoding),
            'json' => new JsonRecordReader($recordPath, $encoding),
            default => new CsvReader(
                encoding: $encoding,
                delimiter: (string)$this->setting('delimiter', 'auto'),
                headerRow: $this->boolSetting('headerRow', true),
            ),
        };
    }

    /**
     * The configured format, or — on "tell from the file" — its extension, and failing that its
     * first character.
     */
    private function format(LocalFile $file): string
    {
        $format = (string)$this->setting('format', 'auto');

        if (in_array($format, ['csv', 'xml', 'json'], true)) {
            return $format;
        }

        $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));

        if (in_array($extension, ['xml', 'json'], true)) {
            return $extension;
        }

        if (in_array($extension, ['csv', 'tsv', 'txt'], true)) {
            return 'csv';
        }

        $head = ltrim(Encoding::stripBom((string)@file_get_contents($file->path, false, null, 0, 512)));

        return match ($head[0] ?? '') {
            '<' => 'xml',
            '{', '[' => 'json',
            default => 'csv',
        };
    }

    private function mapper(FetchCriteria $criteria): RowMapper
    {
        return new RowMapper(
            decimalSeparator: $this->setting('decimalSeparator') === ',' ? ',' : '.',
            dateFormat: trim((string)$this->setting('dateFormat', '')),
            dateColumn: trim((string)($criteria->filters['dateColumn'] ?? '')),
        );
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(object $document, ?string $remoteId = null): PushResult
    {
        if (!$document instanceof ErpOrder) {
            return PushResult::rejected(Craft::t('erpy', 'That is not an order.'));
        }

        if ($this->setting('orderMode') === self::MODE_SERVE) {
            // Only the export endpoint hands orders over in this mode, and it is collecting.
            if (self::$collected === null) {
                return PushResult::rejected(Craft::t('erpy', 'This connection’s orders are fetched by the ERP from the export URL.'));
            }

            self::$collected[] = $document;

            return PushResult::ok('export:' . gmdate('Ymd\THis\Z'), $document->orderNumber);
        }

        try {
            $target = $this->orderTarget();
            $writer = $this->orderWriter();
            $name = $this->orderFileName($document, $writer->extension());

            // The file under this order's own name is this order. An ERP that has already
            // collected it will have moved it, and the identity map covers that case.
            if ($remoteId === null && $target->exists($name)) {
                return PushResult::alreadyExists($name, $document->orderNumber);
            }

            $target->write($name, $writer->render([$document]));
            $this->authWorked();
        } catch (FileException $e) {
            $this->authFailed($e);
            $message = $this->redactSecrets($e->getMessage());

            return $e->retryable ? PushResult::failed($message) : PushResult::rejected($message);
        } catch (\Twig\Error\Error $e) {
            return PushResult::rejected(Craft::t('erpy', 'The order template failed: {message}', ['message' => $e->getMessage()]));
        } catch (Throwable $e) {
            return PushResult::failed($this->redactSecrets($e->getMessage()));
        }

        return PushResult::ok($name, $document->orderNumber);
    }

    /**
     * Run `$send`, and return every order this connector was handed while it ran. Only the
     * export endpoint calls this: it is how a served order passes through `Push::deliver()` —
     * the identity map, the mutex, the run log — on its way into a response instead of a request.
     *
     * @return ErpOrder[]
     */
    public static function collect(callable $send): array
    {
        $previous = self::$collected;
        self::$collected = [];

        try {
            $send();

            return self::$collected;
        } finally {
            self::$collected = $previous;
        }
    }

    public function orderWriter(): OrderWriter
    {
        return new OrderWriter(
            format: (string)$this->setting('orderFormat', OrderWriter::XML),
            encoding: (string)$this->setting('orderEncoding', Encoding::UTF8),
            delimiter: (string)$this->setting('delimiter', 'auto'),
            decimalSeparator: $this->setting('decimalSeparator') === ',' ? ',' : '.',
            template: trim((string)$this->setting('orderTemplate', '')),
        );
    }

    private function orderFileName(ErpOrder $order, string $extension): string
    {
        $safe = static fn(?string $value) => trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$value), '-.');
        $pattern = trim((string)$this->setting('orderFilename', '')) ?: 'order-{orderNumber}.{ext}';

        $name = strtr($pattern, [
            '{orderNumber}' => $safe($order->orderNumber),
            '{reference}' => $safe($order->reference ?: $order->orderNumber),
            '{date}' => ($order->orderedAt ?? new DateTimeImmutable())->format('Ymd-His'),
            '{ext}' => $extension,
        ]);

        if (str_contains($name, '/') || str_contains($name, '\\') || str_starts_with($name, '.')) {
            throw FileException::permanent(Craft::t('erpy', 'The order file name “{name}” must be a plain file name.', ['name' => $name]));
        }

        // Each order needs a file of its own: a name two orders share would make the second look
        // already delivered. And only a data extension, so no setting can write `order-1.php`.
        if (!str_contains($pattern, '{orderNumber}')) {
            throw FileException::permanent(Craft::t('erpy', 'The order file name must contain {orderNumber}, so every order gets a file of its own.', ['orderNumber' => '{orderNumber}']));
        }

        if (!preg_match('/\.(xml|csv|json|txt)$/i', $name)) {
            throw FileException::permanent(Craft::t('erpy', 'The order file name must end in .xml, .csv, .json or .txt.'));
        }

        return $name;
    }

    private function orderTarget(): FileSource
    {
        $directory = trim((string)$this->setting('orderDirectory', ''));

        if ($this->setting('orderTarget') === self::SOURCE_SFTP) {
            return $this->sftp($directory);
        }

        return new LocalSource($directory);
    }

    // ---------------------------------------------------------------------------------------
    // Sources
    // ---------------------------------------------------------------------------------------

    public function source(): FileSource
    {
        return $this->source ??= match ($this->setting('source')) {
            self::SOURCE_URL => new HttpSource($this->transport(), (string)$this->setting('baseUrl', '')),
            self::SOURCE_SFTP => $this->sftp((string)$this->setting('sftpDirectory', '')),
            self::SOURCE_UPLOAD => new LocalSource(self::inbox($this->connection), create: true),
            default => new LocalSource((string)$this->setting('directory', '')),
        };
    }

    /** Swap the source, for tests. */
    public function useSource(?FileSource $source): void
    {
        $this->source = $source;
    }

    private function sftp(string $directory): SftpSource
    {
        return new SftpSource(
            host: (string)$this->setting('sftpHost', ''),
            port: (int)($this->setting('sftpPort', 22) ?: 22),
            username: (string)$this->setting('sftpUsername', ''),
            password: (string)$this->setting('sftpPassword', ''),
            privateKey: (string)$this->setting('sftpPrivateKey', ''),
            fingerprint: (string)$this->setting('sftpFingerprint', ''),
            directory: $directory,
        );
    }

    /**
     * Where the control panel's uploads for a connection are kept: under Craft's storage folder,
     * which is never web-readable.
     */
    public static function inbox(\justinholtweb\erpy\models\Connection $connection): string
    {
        $key = $connection->uid ?: (string)$connection->id;

        return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'erpy' . DIRECTORY_SEPARATOR . 'inbox'
            . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9-]/', '', $key);
    }

    public static function exportUrlTemplate(): string
    {
        try {
            return UrlHelper::siteUrl('erpy/export/{handle}/orders');
        } catch (Throwable) {
            return '/erpy/export/{handle}/orders';
        }
    }

    private function authFailed(FileException $e): void
    {
        if ($e->auth && $this->connection->id) {
            Plugin::getInstance()?->getAlerts()->noteAuthFailure($this->connection, $this->redactSecrets($e->getMessage()));
        }
    }

    private function authWorked(): void
    {
        // A URL source reports to the alerts through its transport already.
        if ($this->connection->id && $this->setting('source') === self::SOURCE_SFTP) {
            Plugin::getInstance()?->getAlerts()->noteAuthSuccess($this->connection);
        }
    }
}
