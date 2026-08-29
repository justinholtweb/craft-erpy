<?php

namespace justinholtweb\erpy;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\erpy\models\Settings;
use justinholtweb\erpy\services\Accounts;
use justinholtweb\erpy\services\Catalog;
use justinholtweb\erpy\services\Connections;
use justinholtweb\erpy\services\Connectors;
use justinholtweb\erpy\services\Cursors;
use justinholtweb\erpy\services\DeadLetters;
use justinholtweb\erpy\services\Links;
use justinholtweb\erpy\services\Log;
use justinholtweb\erpy\services\Mapping;
use justinholtweb\erpy\services\Orders;
use justinholtweb\erpy\services\Pricing;
use justinholtweb\erpy\services\Push;
use justinholtweb\erpy\services\Runs;
use justinholtweb\erpy\services\Sync;
use justinholtweb\erpy\twig\ErpyVariable;
use yii\base\Event;

/**
 * Erpy — the ERP gateway for Craft Commerce.
 *
 * The gateway owns the hard parts once: the sync engine, the identity map, paging, delta
 * watermarks, retries, dead letters, field mapping, the connection log and the control panel.
 * Each ERP is a free add-on that registers a connector and translates one vendor's payloads into
 * Erpy's canonical documents — the same arrangement Imager-X uses for its transformers, for the
 * same reason: the interesting work is not in any one integration, it is in everything that has
 * to be true of all of them.
 *
 * @property-read Connectors $connectors
 * @property-read Connections $connections
 * @property-read Sync $sync
 * @property-read Push $push
 * @property-read Links $links
 * @property-read Runs $runs
 * @property-read Log $log
 * @property-read Cursors $cursors
 * @property-read DeadLetters $deadLetters
 * @property-read Mapping $mapping
 * @property-read Catalog $catalog
 * @property-read Accounts $accounts
 * @property-read Orders $orders
 * @property-read Pricing $pricing
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'erpy';

    public string $schemaVersion = '5.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'connectors' => ['class' => Connectors::class],
                'connections' => ['class' => Connections::class],
                'sync' => ['class' => Sync::class],
                'push' => ['class' => Push::class],
                'links' => ['class' => Links::class],
                'runs' => ['class' => Runs::class],
                'log' => ['class' => Log::class],
                'cursors' => ['class' => Cursors::class],
                'deadLetters' => ['class' => DeadLetters::class],
                'mapping' => ['class' => Mapping::class],
                'catalog' => ['class' => Catalog::class],
                'accounts' => ['class' => Accounts::class],
                'orders' => ['class' => Orders::class],
                'pricing' => ['class' => Pricing::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerRoutes();
        $this->_registerGarbageCollection();

        // Everything below reaches into an order or a purchasable. The plugin can be installed
        // while Commerce is disabled or mid-upgrade, and a fatal during a Commerce update is a
        // very bad afternoon for somebody.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderPush();
        $this->_registerContractPricing();
    }

    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Where OAuth providers send the merchant back to. Shown on the connection screen so it can
     * be pasted into the ERP's app registration, and it must match byte for byte.
     */
    public static function redirectUri(): string
    {
        return UrlHelper::siteUrl('erpy/oauth/callback');
    }

    // ---------------------------------------------------------------------------------------
    // Services
    // ---------------------------------------------------------------------------------------

    public function getConnectors(): Connectors
    {
        return $this->get('connectors');
    }

    public function getConnections(): Connections
    {
        return $this->get('connections');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getPush(): Push
    {
        return $this->get('push');
    }

    public function getLinks(): Links
    {
        return $this->get('links');
    }

    public function getRuns(): Runs
    {
        return $this->get('runs');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getCursors(): Cursors
    {
        return $this->get('cursors');
    }

    public function getDeadLetters(): DeadLetters
    {
        return $this->get('deadLetters');
    }

    public function getMapping(): Mapping
    {
        return $this->get('mapping');
    }

    public function getCatalog(): Catalog
    {
        return $this->get('catalog');
    }

    public function getAccounts(): Accounts
    {
        return $this->get('accounts');
    }

    public function getOrders(): Orders
    {
        return $this->get('orders');
    }

    public function getPricing(): Pricing
    {
        return $this->get('pricing');
    }

    // ---------------------------------------------------------------------------------------
    // Control panel
    // ---------------------------------------------------------------------------------------

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('erpy/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('erpy', 'Erpy');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('erpy-viewConnections')) {
            $subNav['connections'] = [
                'label' => Craft::t('erpy', 'Connections'),
                'url' => 'erpy/connections',
            ];
        }

        if ($user->checkPermission('erpy-viewRuns')) {
            $subNav['runs'] = [
                'label' => Craft::t('erpy', 'Activity'),
                'url' => 'erpy/runs',
            ];

            $subNav['deadletters'] = [
                'label' => Craft::t('erpy', 'Problems'),
                'url' => 'erpy/problems',
            ];
        }

        if ($user->checkPermission('erpy-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('erpy', 'Log'),
                'url' => 'erpy/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('erpy', 'Settings'),
                'url' => 'settings/plugins/erpy',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('erpy', ErpyVariable::class);
            },
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('erpy', 'Erpy'),
                    'permissions' => [
                        'erpy-viewConnections' => [
                            'label' => Craft::t('erpy', 'View ERP connections'),
                            'nested' => [
                                'erpy-manageConnections' => [
                                    'label' => Craft::t('erpy', 'Add, edit and delete connections'),
                                ],
                                'erpy-runSync' => [
                                    'label' => Craft::t('erpy', 'Run a sync by hand'),
                                ],
                                'erpy-editMappings' => [
                                    'label' => Craft::t('erpy', 'Edit field mappings'),
                                ],
                            ],
                        ],
                        'erpy-viewRuns' => [
                            'label' => Craft::t('erpy', 'View sync activity and problems'),
                            'nested' => [
                                'erpy-replayDocuments' => [
                                    'label' => Craft::t('erpy', 'Replay and dismiss failed documents'),
                                ],
                            ],
                        ],
                        'erpy-viewLog' => [
                            'label' => Craft::t('erpy', 'View the connection log'),
                        ],
                    ],
                ];
            },
        );
    }

    private function _registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['erpy'] = 'erpy/connections/index';
                $event->rules['erpy/connections'] = 'erpy/connections/index';
                $event->rules['erpy/connections/new'] = 'erpy/connections/edit';
                $event->rules['erpy/connections/<connectionId:\d+>'] = 'erpy/connections/edit';
                $event->rules['erpy/connections/<connectionId:\d+>/mapping/<entity:[\w]+>'] = 'erpy/mappings/edit';
                $event->rules['erpy/runs'] = 'erpy/runs/index';
                $event->rules['erpy/runs/<runId:\d+>'] = 'erpy/runs/detail';
                $event->rules['erpy/problems'] = 'erpy/problems/index';
                $event->rules['erpy/problems/<letterId:\d+>'] = 'erpy/problems/detail';
                $event->rules['erpy/log'] = 'erpy/log/index';
                $event->rules['erpy/log/<entryId:\d+>'] = 'erpy/log/detail';
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                // The OAuth callback has to be a front-end URL: several of these providers refuse
                // to register a redirect URI containing a control panel trigger they cannot see.
                $event->rules['erpy/oauth/callback'] = 'erpy/oauth/callback';
                $event->rules['erpy/webhook/<connectionHandle:[\w\-]+>'] = 'erpy/webhook/receive';
            },
        );
    }

    /**
     * Queue an order push when Commerce says the order is complete.
     *
     * After completion rather than before, and queued rather than inline: a customer's payment
     * must never wait on an ERP, and an ERP outage must never be able to fail a checkout.
     */
    private function _registerOrderPush(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                $plugin = self::getInstance();

                if (!$plugin->getSettings()->pushOrdersOnComplete) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;

                try {
                    $plugin->getPush()->queueOrder($order, $plugin->getSettings()->pushOrderDelaySeconds);
                } catch (\Throwable $e) {
                    // Failing to *queue* must not fail the checkout either.
                    Craft::error('Erpy could not queue an order push: ' . $e->getMessage(), 'erpy');
                }
            },
        );
    }

    /**
     * Apply ERP contract pricing to cart lines.
     *
     * Done on populate rather than through Commerce's catalog pricing rules on purpose: a
     * mid-market ERP holds tens of thousands of negotiated price lines, and one pricing rule per
     * line would make catalog price generation the slowest thing on the site.
     */
    private function _registerContractPricing(): void
    {
        Event::on(
            LineItems::class,
            LineItems::EVENT_POPULATE_LINE_ITEM,
            static function(LineItemEvent $event) {
                $plugin = self::getInstance();

                if (!$plugin->getSettings()->applyContractPricing) {
                    return;
                }

                $lineItem = $event->lineItem;
                $purchasable = $lineItem->getPurchasable();

                if (!$purchasable) {
                    return;
                }

                try {
                    $price = $plugin->getPricing()->priceFor(
                        $purchasable,
                        (float)$lineItem->qty,
                        $lineItem->getOrder()?->getCustomer(),
                    );
                } catch (\Throwable $e) {
                    // Pricing runs on every cart request. A bad lookup drops back to Commerce's
                    // own price rather than taking the storefront down.
                    Craft::error('Erpy could not resolve a contract price: ' . $e->getMessage(), 'erpy');

                    return;
                }

                if ($price !== null) {
                    $lineItem->setPrice($price);
                }
            },
        );
    }

    private function _registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function() {
                $plugin = self::getInstance();

                $plugin->getLog()->prune();
                $plugin->getRuns()->prune();
                $plugin->getRuns()->reapStale($plugin->getSettings()->staleRunMinutes);
                $plugin->getDeadLetters()->prune();
            },
        );
    }
}
