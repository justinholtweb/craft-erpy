<?php

namespace justinholtweb\erpy\files;

use Craft;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\models\canonical\ErpDocument;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\services\Mapping;

/**
 * A first guess at which column is which.
 *
 * A file has no schema, so this matches column names against the ones exports actually use —
 * English, and the Slovene, Croatian, Serbian and German that the file-first ERPs of the region
 * write (`Šifra`, `Naziv`, `Zaloga`, `Količina`, `Cijena`). Accents, case, spaces and punctuation
 * are ignored, so `Šifra artikla` and `SIFRA_ARTIKLA` are one name.
 *
 * It only has to be right most of the time. Every column also arrives untouched under `raw.`,
 * and a mapping rule that targets a canonical field (`sku` ← `raw.Ident`) overrides the guess
 * before anything reaches Commerce. The guesses are the defaults; the mapping is the truth.
 */
class RowMapper
{
    /** @var array<string,array<string,string[]>> entity => canonical field => normalised names */
    private const ALIASES = [
        Entity::PRODUCT => [
            'sku' => ['sku', 'code', 'itemcode', 'itemno', 'itemnumber', 'item', 'articlecode', 'articleno', 'article', 'artikel', 'artikelnummer', 'sifra', 'sifraartikla', 'sifraizdelka', 'productcode', 'partno', 'partnumber', 'ident', 'id'],
            'name' => ['name', 'itemname', 'productname', 'title', 'naziv', 'nazivartikla', 'nazivizdelka', 'bezeichnung', 'description1'],
            'description' => ['description', 'longdescription', 'opis', 'beschreibung'],
            'price' => ['price', 'unitprice', 'salesprice', 'baseprice', 'cena', 'cijena', 'mpc', 'vpc', 'preis', 'retailprice'],
            'currency' => ['currency', 'valuta', 'wahrung', 'currencycode'],
            'cost' => ['cost', 'costprice', 'nabavnacena', 'nabavnacijena', 'purchaseprice'],
            'barcode' => ['barcode', 'ean', 'ean13', 'gtin', 'upc', 'crtnakoda', 'barkod'],
            'category' => ['category', 'group', 'itemgroup', 'productgroup', 'kategorija', 'skupina', 'grupa', 'warengruppe'],
            'brand' => ['brand', 'manufacturer', 'blagovnaznamka', 'proizvodjac', 'proizvajalec', 'marke'],
            'unitOfMeasure' => ['unit', 'uom', 'unitofmeasure', 'em', 'enotamere', 'jedinicamjere', 'jm', 'me', 'einheit'],
            'weight' => ['weight', 'masa', 'teza', 'tezina', 'gewicht', 'netweight'],
            'taxCategory' => ['taxcategory', 'taxcode', 'vatcode', 'vatrate', 'ddv', 'pdv', 'davcnastopnja', 'stopapdv'],
            'enabled' => ['enabled', 'active', 'aktiven', 'aktivan', 'aktiv'],
            'blocked' => ['blocked', 'discontinued', 'blokiran', 'ukinjen'],
        ],
        Entity::PRICE => [
            'sku' => ['sku', 'code', 'itemcode', 'itemno', 'item', 'articlecode', 'article', 'artikel', 'sifra', 'sifraartikla', 'productcode', 'ident'],
            'unitPrice' => ['unitprice', 'price', 'cena', 'cijena', 'mpc', 'vpc', 'preis', 'netprice', 'amount'],
            'priceListCode' => ['pricelist', 'pricelistcode', 'pricegroup', 'cenik', 'cjenik', 'cenovnik', 'preisliste'],
            'customerCode' => ['customer', 'customercode', 'customerno', 'kupac', 'kupec', 'partner', 'partnercode', 'sifrakupca', 'sifrapartnerja', 'kunde', 'kundennummer'],
            'customerGroupCode' => ['customergroup', 'customergroupcode', 'skupinakupcev', 'grupakupaca'],
            'currency' => ['currency', 'valuta', 'wahrung', 'currencycode'],
            'minQuantity' => ['minquantity', 'minqty', 'fromquantity', 'quantityfrom', 'odkolicine', 'odkolicina', 'abmenge'],
            'discountPercent' => ['discount', 'discountpercent', 'rabat', 'popust', 'rabatt'],
            'startsAt' => ['validfrom', 'startdate', 'from', 'veljaod', 'vrijediod', 'gultigab'],
            'endsAt' => ['validto', 'enddate', 'to', 'veljado', 'vrijedido', 'gultigbis'],
            'unitOfMeasure' => ['unit', 'uom', 'em', 'jm', 'me'],
        ],
        Entity::INVENTORY => [
            'sku' => ['sku', 'code', 'itemcode', 'itemno', 'item', 'articlecode', 'article', 'artikel', 'sifra', 'sifraartikla', 'productcode', 'ident'],
            'onHand' => ['onhand', 'qty', 'quantity', 'stock', 'qtyonhand', 'instock', 'zaloga', 'kolicina', 'stanje', 'zalihe', 'zaliha', 'bestand', 'menge'],
            'available' => ['available', 'qtyavailable', 'availablequantity', 'freestock', 'razpolozljivo', 'raspolozivo', 'verfugbar'],
            'allocated' => ['allocated', 'reserved', 'committed', 'rezervirano', 'rezervisano', 'reserviert'],
            'incoming' => ['incoming', 'onorder', 'vprihodu', 'naputu', 'bestellt'],
            'warehouse' => ['warehouse', 'location', 'store', 'skladisce', 'skladiste', 'magacin', 'lager'],
        ],
        Entity::CUSTOMER => [
            'code' => ['code', 'customercode', 'customerno', 'customernumber', 'customer', 'account', 'accountno', 'partnercode', 'partner', 'sifra', 'sifrakupca', 'sifrapartnerja', 'kupac', 'kupec', 'kundennummer', 'debtor'],
            'name' => ['name', 'customername', 'company', 'companyname', 'naziv', 'nazivpartnerja', 'firma', 'podjetje'],
            'email' => ['email', 'emailaddress', 'mail', 'eposta', 'epost'],
            'phone' => ['phone', 'telephone', 'tel', 'telefon', 'gsm', 'mobile'],
            'taxId' => ['taxid', 'vat', 'vatid', 'vatnumber', 'ddvid', 'davcnastevilka', 'idzaddv', 'oib', 'pib', 'ustid', 'uid'],
            'priceListCode' => ['pricelist', 'pricelistcode', 'cenik', 'cjenik', 'cenovnik'],
            'customerGroupCode' => ['customergroup', 'group', 'skupina', 'grupa'],
            'paymentTermsCode' => ['paymentterms', 'terms', 'placilnipogoji', 'uvjetiplacanja', 'rokplacila'],
            'creditLimit' => ['creditlimit', 'limit', 'kreditnilimit'],
            'balance' => ['balance', 'saldo', 'odprto', 'otvoreno', 'dolg'],
            'currency' => ['currency', 'valuta'],
            'onHold' => ['onhold', 'blocked', 'blokiran', 'gesperrt'],
            'language' => ['language', 'jezik', 'sprache'],
        ],
        Entity::ORDER_STATUS => [
            'orderNumber' => ['ordernumber', 'orderno', 'order', 'ordernr', 'reference', 'webordernumber', 'narocilo', 'stnarocila', 'narudzba', 'brojnarudzbe', 'porudzbina', 'bestellnummer'],
            'status' => ['status', 'state', 'orderstatus', 'stanje'],
            'statusCode' => ['statuscode', 'statecode'],
            'isShipped' => ['shipped', 'isshipped', 'odpremljeno', 'poslano', 'otpremljeno'],
            'isCancelled' => ['cancelled', 'canceled', 'iscancelled', 'storno', 'preklicano', 'otkazano'],
            'isInvoiced' => ['invoiced', 'isinvoiced', 'fakturirano', 'zaracunano'],
            'invoiceNumber' => ['invoice', 'invoicenumber', 'invoiceno', 'racun', 'stracuna', 'brojracuna'],
            'message' => ['message', 'note', 'comment', 'opomba', 'napomena'],
        ],
        Entity::SHIPMENT => [
            'orderNumber' => ['ordernumber', 'orderno', 'order', 'ordernr', 'reference', 'webordernumber', 'narocilo', 'stnarocila', 'narudzba', 'brojnarudzbe', 'bestellnummer'],
            'shipmentNumber' => ['shipmentnumber', 'shipment', 'shipmentno', 'deliverynote', 'deliveryno', 'dispatchno', 'dobavnica', 'otpremnica', 'lieferschein'],
            'trackingNumber' => ['trackingnumber', 'tracking', 'trackingno', 'trackingcode', 'parcelnumber', 'stevilkapaketa', 'brojpaketa', 'sendungsnummer'],
            'trackingUrl' => ['trackingurl', 'trackinglink'],
            'carrier' => ['carrier', 'courier', 'dostavljavec', 'dostavnasluzba', 'kurirska', 'spediteur'],
            'service' => ['service', 'shippingservice', 'storitev'],
            'shippedAt' => ['shippedat', 'shipdate', 'shippeddate', 'date', 'datum', 'datumodpreme', 'datumotpreme'],
            'warehouse' => ['warehouse', 'skladisce', 'skladiste', 'lager'],
        ],
    ];

    /** Shipment line columns, read alongside the shipment fields above. */
    private const LINE_SKU = ['sku', 'code', 'itemcode', 'item', 'article', 'artikel', 'sifra', 'sifraartikla', 'ident'];

    private const LINE_QUANTITY = ['quantity', 'qty', 'shippedquantity', 'kolicina', 'menge'];

    private const MODIFIED = ['modifiedat', 'modified', 'lastmodified', 'changed', 'changedat', 'updated', 'updatedat', 'datumspremembe', 'spremenjeno', 'izmijenjeno'];

    private DateTimeZone $timezone;

    public function __construct(
        private string $decimalSeparator = '.',
        private string $dateFormat = '',
        ?DateTimeZone $timezone = null,
        private string $dateColumn = '',
    ) {
        $this->timezone = $timezone ?? new DateTimeZone(Craft::$app->getTimeZone());
    }

    public static function handles(string $entity): bool
    {
        return isset(self::ALIASES[$entity]);
    }

    /**
     * Build the canonical document for one row.
     */
    public function document(string $entity, array $row): ErpDocument
    {
        $index = $this->index($row);
        $config = ['raw' => $row];

        foreach (self::ALIASES[$entity] ?? [] as $field => $names) {
            $value = $this->pick($index, $names);

            if ($value !== null && $value !== '') {
                $config[$field] = $value;
            }
        }

        $class = Entity::documentClass($entity);
        /** @var ErpDocument $document */
        $document = new $class([]);
        $document->raw = $row;

        foreach ($config as $field => $value) {
            if ($field === 'raw' || !property_exists($document, $field)) {
                continue;
            }

            $document->$field = $this->cast($document, $field, $value);
        }

        if ($document instanceof ErpShipment) {
            $sku = $this->pick($index, self::LINE_SKU);
            $quantity = $this->number($this->pick($index, self::LINE_QUANTITY));

            if ($sku !== null && $sku !== '' && $quantity !== null) {
                $document->lines = [['sku' => (string)$sku, 'quantity' => $quantity]];
            }
        }

        $document->modifiedAt = $this->modifiedAt($index);

        // A file has no ids of its own; the natural key is the only identity a row has.
        if ($document->remoteId === null) {
            $key = $document->naturalKey();
            $document->remoteId = trim($key, '|') === '' ? null : $key;
        }

        return $document;
    }

    /**
     * The row's date in the configured date column, for a delta read that filters rows rather
     * than whole files.
     */
    public function rowDate(array $row): ?DateTimeInterface
    {
        if ($this->dateColumn === '') {
            return null;
        }

        $value = $row[$this->dateColumn] ?? $this->pick($this->index($row), [self::normalise($this->dateColumn)]);
        $date = $this->date($value);

        // A date with no time means "some time that day". Read as midnight, a row changed at
        // 14:00 and first seen by the 15:00 run would fall before the watermark and never be read.
        if ($date instanceof DateTime && is_scalar($value) && !str_contains((string)$value, ':')) {
            $date->setTime(23, 59, 59);
        }

        return $date;
    }

    /**
     * Why a row cannot be trusted as this entity, or null when it can: a stock line with no
     * quantity it could read, a price line with no price. Writing those as zero would empty the
     * shelf or give the stock away; a row that says nothing is rejected instead. Fields the
     * merchant has mapped are left to the mapping.
     *
     * @param string[] $mapped canonical fields a mapping rule targets
     */
    public function unreadable(string $entity, array $row, array $mapped = []): ?string
    {
        $required = match ($entity) {
            Entity::INVENTORY => ['onHand', 'available'],
            Entity::PRICE => ['unitPrice'],
            default => [],
        };

        if ($required === [] || array_intersect($required, $mapped) !== []) {
            return null;
        }

        $index = $this->index($row);

        foreach ($required as $field) {
            if ($this->number($this->pick($index, self::ALIASES[$entity][$field])) !== null) {
                return null;
            }
        }

        return $entity === Entity::INVENTORY
            ? Craft::t('erpy', 'No quantity Erpy could read. Map `onHand` to the quantity column on the mapping screen.')
            : Craft::t('erpy', 'No price Erpy could read. Map `unitPrice` to the price column on the mapping screen.');
    }

    /**
     * Fold a column name to compare it: no accents, no case, nothing but letters and digits.
     */
    public static function normalise(string $name): string
    {
        $name = strtr(ltrim($name, '@'), [
            'š' => 's', 'Š' => 's', 'č' => 'c', 'Č' => 'c', 'ć' => 'c', 'Ć' => 'c', 'ž' => 'z', 'Ž' => 'z',
            'đ' => 'dj', 'Đ' => 'dj', 'ä' => 'a', 'Ä' => 'a', 'ö' => 'o', 'Ö' => 'o', 'ü' => 'u', 'Ü' => 'u', 'ß' => 'ss',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $ascii === false ? $name : $ascii));
    }

    /**
     * @return array<string,mixed> normalised name => scalar value, first column wins
     */
    private function index(array $row): array
    {
        $index = [];

        foreach ($row as $name => $value) {
            // `<Price currency="EUR">9,90</Price>` arrives as ['#' => '9,90', '@currency' => …].
            if (is_array($value) && array_key_exists('#', $value)) {
                $value = $value['#'];
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $key = self::normalise((string)$name);

            if ($key !== '' && !array_key_exists($key, $index)) {
                $index[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $index
     * @param string[] $names
     */
    private function pick(array $index, array $names): mixed
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $index) && $index[$name] !== '' && $index[$name] !== null) {
                return $index[$name];
            }
        }

        return null;
    }

    private function cast(ErpDocument $document, string $field, mixed $value): mixed
    {
        $type = (new \ReflectionProperty($document, $field))->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        $nullable = $type?->allowsNull() ?? true;

        return match ($name) {
            'float' => $this->number($value) ?? ($nullable ? null : 0.0),
            'int' => ($number = $this->number($value)) === null ? ($nullable ? null : 0) : (int)$number,
            'bool' => $this->bool($value),
            'string' => is_scalar($value) ? (string)$value : ($nullable ? null : ''),
            DateTimeInterface::class => $this->date($value),
            default => $value,
        };
    }

    public function number(mixed $value): ?float
    {
        return Mapping::parseDecimal($value, $this->decimalSeparator);
    }

    private function bool(mixed $value): bool
    {
        if (!is_string($value)) {
            return (bool)$value;
        }

        // Including the region's own yeses: da (sl/hr/sr), ja (de).
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on', 't', 'x', 'da', 'd', 'ja', 'j'], true);
    }

    /**
     * Read a date the ERP wrote in its own local time, in the timezone Craft is set to — never
     * the server's, which is the trap a bare `new DateTime()` falls into.
     */
    public function date(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (!is_scalar($value) || trim((string)$value) === '') {
            return null;
        }

        $value = trim((string)$value);

        if ($this->dateFormat !== '') {
            $date = DateTime::createFromFormat('!' . $this->dateFormat, $value, $this->timezone)
                ?: DateTime::createFromFormat($this->dateFormat, $value, $this->timezone);

            if ($date !== false) {
                return $date;
            }
        }

        // The region writes 03.11.2026 and 3. 11. 2026, which PHP would read as nothing useful.
        if (preg_match('/^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
            $date = new DateTime('now', $this->timezone);
            $date->setDate((int)$m[3], (int)$m[2], (int)$m[1]);
            $date->setTime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0));

            return $date;
        }

        try {
            return new DateTime($value, $this->timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $index
     */
    private function modifiedAt(array $index): ?DateTimeInterface
    {
        $names = $this->dateColumn !== '' ? [self::normalise($this->dateColumn), ...self::MODIFIED] : self::MODIFIED;

        return $this->date($this->pick($index, $names));
    }

    /**
     * The canonical fields a row of this entity is read into, for the docs and the mapping screen.
     *
     * @return string[]
     */
    public static function fields(string $entity): array
    {
        return array_keys(self::ALIASES[$entity] ?? []);
    }
}
