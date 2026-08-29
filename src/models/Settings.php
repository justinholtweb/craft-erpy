<?php

namespace justinholtweb\erpy\models;

use craft\base\Model;

/**
 * Plugin-wide settings.
 *
 * Anything that belongs to one ERP lives on its connection instead. What is left here is the
 * behaviour a merchant wants to be true of every ERP they talk to.
 */
class Settings extends Model
{
    // Logging ------------------------------------------------------------------------------

    public bool $logRequests = true;

    /** Bodies are the useful part and also the large part. */
    public bool $logBodies = true;

    /** A busy catalogue sync is tens of thousands of successful requests nobody will read. */
    public bool $logErrorsOnly = false;

    public int $logRetentionDays = 14;

    public int $runRetentionDays = 30;

    /** Off by default: on a 40,000-SKU delta sync, "unchanged" is 39,900 of the rows. */
    public bool $recordSkippedItems = false;

    /** A run still marked "running" after this long had its worker killed. */
    public int $staleRunMinutes = 60;

    // Orders -------------------------------------------------------------------------------

    /** Queue a push when an order completes. The only automatic write Erpy makes. */
    public bool $pushOrdersOnComplete = true;

    /**
     * Wait before pushing. Useful when payment capture is asynchronous and the ERP should not
     * see an order the gateway has not settled.
     */
    public int $pushOrderDelaySeconds = 0;

    /** Retry a failed order push this many times before it dead-letters for good. */
    public int $pushMaxAttempts = 5;

    // Storefront ---------------------------------------------------------------------------

    /** Apply ERP contract pricing to cart line items. */
    public bool $applyContractPricing = true;

    /**
     * Refuse to complete an on-account order that would put the customer over their credit
     * limit. Off by default: it can stop a sale, and that has to be a decision, not a surprise.
     */
    public bool $enforceCreditLimit = false;

    /** Credit figures older than this are re-fetched before they are trusted to block a sale. */
    public int $creditMaxAgeMinutes = 60;

    // Scheduling ---------------------------------------------------------------------------

    /** Let scheduled syncs run from Craft's queue. */
    public bool $scheduleEnabled = true;

    public function defineRules(): array
    {
        return [
            [[
                'logRequests', 'logBodies', 'logErrorsOnly', 'recordSkippedItems',
                'pushOrdersOnComplete', 'applyContractPricing', 'enforceCreditLimit',
                'scheduleEnabled',
            ], 'boolean'],
            [[
                'logRetentionDays', 'runRetentionDays', 'staleRunMinutes',
                'pushOrderDelaySeconds', 'pushMaxAttempts', 'creditMaxAgeMinutes',
            ], 'integer', 'min' => 0],
            [['pushMaxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['staleRunMinutes'], 'integer', 'min' => 5],
        ];
    }
}
