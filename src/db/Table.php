<?php

namespace justinholtweb\erpy\db;

/**
 * Erpy's tables, named in one place so a typo is a fatal rather than a silent second table.
 */
abstract class Table
{
    /** One configured link to one ERP company. */
    public const CONNECTIONS = '{{%erpy_connections}}';

    /** The identity map: which local record is which ERP record. */
    public const LINKS = '{{%erpy_links}}';

    /** Field mapping rules, per connection, entity and direction. */
    public const MAPS = '{{%erpy_maps}}';

    /** One row per sync run. */
    public const RUNS = '{{%erpy_runs}}';

    /** One row per document handled in a run. */
    public const RUN_ITEMS = '{{%erpy_runitems}}';

    /** Every request and response, redacted. */
    public const LOG = '{{%erpy_log}}';

    /** Documents that could not be delivered, kept so they can be fixed and replayed. */
    public const DEAD_LETTERS = '{{%erpy_deadletters}}';

    /** Delta-sync watermarks. */
    public const CURSORS = '{{%erpy_cursors}}';

    /** Contract and tier pricing pulled from the ERP, resolved at cart time. */
    public const PRICES = '{{%erpy_prices}}';

    /** The B2B profile and credit standing of a Craft user, as the ERP sees them. */
    public const ACCOUNTS = '{{%erpy_accounts}}';
}
