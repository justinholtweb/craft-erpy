<?php

namespace justinholtweb\erpy\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

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

    // Alerts -------------------------------------------------------------------------------
    // Nothing here is `required`: an empty recipient list and an empty webhook URL simply mean
    // nobody is told, and a fresh install must be able to save every other setting.

    /** Comma- or newline-separated addresses, or an `$ENV` reference that resolves to them. */
    public string $alertRecipients = '';

    /** A Slack or Teams incoming-webhook URL (or `$ENV`). Sent through the SSRF guard. */
    public string $alertWebhookUrl = '';

    /** `slack`, `teams` or `json` — the shape of the webhook body. */
    public string $alertWebhookFormat = 'slack';

    /** Optional. When set, the webhook carries an `X-Erpy-Signature` HMAC of its body. */
    public string $alertWebhookSecret = '';

    public bool $alertOnDeadLetters = true;

    /** This many documents failing inside the window opens a dead-letter incident. */
    public int $alertDeadLetterThreshold = 5;

    public int $alertDeadLetterWindowMinutes = 60;

    /** A final 401 from the ERP, or a refused OAuth refresh. */
    public bool $alertOnAuthFailure = true;

    /** No successful pull of a scheduled entity for this long is a stall. 0 switches it off. */
    public int $alertStallHours = 6;

    /** An incident that reopens this soon after its recovery email waits out the rest. */
    public int $alertCooldownMinutes = 60;

    /**
     * Config-file only: let the alert webhook reach private, loopback and link-local hosts (a
     * self-hosted Mattermost on the LAN). The scheme and no-redirect rules still hold.
     */
    public bool $allowPrivateAlertWebhookHosts = false;

    public function defineRules(): array
    {
        return [
            [[
                'logRequests', 'logBodies', 'logErrorsOnly', 'recordSkippedItems',
                'pushOrdersOnComplete', 'applyContractPricing', 'enforceCreditLimit',
                'scheduleEnabled', 'alertOnDeadLetters', 'alertOnAuthFailure',
                'allowPrivateAlertWebhookHosts',
            ], 'boolean'],
            [[
                'logRetentionDays', 'runRetentionDays', 'staleRunMinutes',
                'pushOrderDelaySeconds', 'pushMaxAttempts', 'creditMaxAgeMinutes',
            ], 'integer', 'min' => 0],
            [['pushMaxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['staleRunMinutes'], 'integer', 'min' => 5],
            [['alertDeadLetterThreshold'], 'integer', 'min' => 1, 'max' => 10000],
            [['alertDeadLetterWindowMinutes'], 'integer', 'min' => 5, 'max' => 10080],
            [['alertStallHours'], 'integer', 'min' => 0, 'max' => 720],
            [['alertCooldownMinutes'], 'integer', 'min' => 0, 'max' => 10080],
            [['alertWebhookFormat'], 'in', 'range' => ['slack', 'teams', 'json']],
            [['alertRecipients', 'alertWebhookUrl', 'alertWebhookSecret'], 'string', 'max' => 2000],
            [['alertRecipients'], 'validateRecipients'],
            [['alertWebhookUrl'], 'validateWebhookUrl'],
        ];
    }

    /**
     * Every address must be one, when there are any. An `$ENV` reference that is not set yet is
     * allowed — a staging site legitimately has no recipients.
     */
    public function validateRecipients(string $attribute): void
    {
        foreach ($this->recipientList(false) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError($attribute, Craft::t('erpy', '“{address}” is not an email address.', ['address' => $address]));
            }
        }
    }

    /**
     * Only the shape is checked here. Where the host resolves is checked at send time, every time,
     * because DNS can change between a save and a send.
     */
    public function validateWebhookUrl(string $attribute): void
    {
        $url = trim((string)App::parseEnv($this->alertWebhookUrl));

        if ($url === '' || str_starts_with($url, '$')) {
            return;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            $this->addError($attribute, Craft::t('erpy', 'Only http:// and https:// webhook URLs are allowed.'));
        }
    }

    /**
     * The recipients, with `$ENV` resolved.
     *
     * @return string[]
     */
    public function recipientList(bool $validOnly = true): array
    {
        $raw = trim((string)App::parseEnv($this->alertRecipients));

        if ($raw === '' || str_starts_with($raw, '$')) {
            return [];
        }

        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []))));

        return $validOnly
            ? array_values(array_filter($list, static fn(string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false))
            : $list;
    }
}
