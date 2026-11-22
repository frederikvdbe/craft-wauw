<?php

namespace modules\wauw;

use Craft;
use craft\commerce\elements\Product;
use craft\events\ModelEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

use modules\wauw\jobs\GenerateSpecSheet;
use modules\wauw\web\twig\SiteTwigExtension;

trait ModuleTrait {
    private const HIDDEN_COMMERCE_NAV_ITEMS = ['subscriptions', 'subscription-plans', 'donations', 'inventory-transfers'];
    private const HIDDEN_COMMERCE_SETTINGS = ['subscriptions', 'transfers'];

    private const CP_NAV_ORDER = [
        'dashboard',
        'commerce',
        'content',
        'assets',
        'globals',
        'core-seo-geo',
        '*',
        'utilities',
        'settings',
        'plugin-store',
    ];

    protected function registerEvents(): void {
        $this->registerSharedEvents();

        if (Craft::$app->request->isCpRequest) $this->registerCpEvents();
        if (Craft::$app->request->isSiteRequest) $this->registerSiteEvents();
    }

    protected function registerSharedEvents(): void {
        Event::on(Product::class, Product::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Product $product */
            $product = $event->sender;

            if ($product->getIsDraft() || $product->getIsRevision() || $product->propagating) {
                return;
            }

            Craft::$app->getQueue()->push(new GenerateSpecSheet([
                'productId' => $product->id,
                'siteId' => $product->siteId,
            ]));
        });
    }

    protected function registerCpEvents(): void {
        $this->registerCpNavOrder();
        $this->hideUnusedCommerceNavItems();
        $this->hideUnusedCommerceSettings();
        $this->registerShippingOverviewRoute();
        $this->addShippingOverviewNavItem();
    }

    protected function registerSiteEvents(): void {
        $this->registerTwigExtensions();
        $this->registerSiteRoutes();
    }

    private function registerCpNavOrder(): void {
        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function (RegisterCpNavItemsEvent $event) {
            $position = function (array $item): int {
                $index = array_search(explode('/', $item['url'])[0], self::CP_NAV_ORDER, true);

                return $index !== false ? $index : array_search('*', self::CP_NAV_ORDER, true);
            };

            usort($event->navItems, fn (array $a, array $b) => $position($a) <=> $position($b));
        });
    }

    private function hideUnusedCommerceNavItems(): void {
        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function (RegisterCpNavItemsEvent $event) {
            foreach ($event->navItems as &$item) {
                if (($item['url'] ?? null) === 'commerce') {
                    $item['subnav'] = array_diff_key($item['subnav'] ?? [], array_flip(self::HIDDEN_COMMERCE_NAV_ITEMS));
                }
            }
        });
    }

    private function hideUnusedCommerceSettings(): void {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function (TemplateEvent $event) {
            if ($event->templateMode !== View::TEMPLATE_MODE_CP || !str_starts_with($event->template, 'commerce/settings')) {
                return;
            }

            $selectors = [
                '#updateBillingDetailsUrl-field',
                'h3:has(+ #updateBillingDetailsUrl-field)',
                'hr:has(+ h3 + #updateBillingDetailsUrl-field)',
            ];

            foreach (self::HIDDEN_COMMERCE_SETTINGS as $handle) {
                $link = "a[href*=\"commerce/settings/$handle\"]";
                $selectors[] = "li:has(> $link)";
                $selectors[] = "li.heading:has(+ li > $link)";
            }

            Craft::$app->getView()->registerCss(implode(',', $selectors) . ' { display: none; }');
        });
    }

    /**
     * Commerce builds its Store Management -> Shipping nav from its own hard-coded array
     * with no registration event, so the path for a plugin-provided page under that nav is
     * a CP URL rule for the "path" Commerce's nav links to ({@see addShippingOverviewNavItem})
     * pointing at our own controller. The method picker (one matrix per shipping method)
     * lives inside our own template, not this nav, so there's just this one entry point and
     * a second rule for the per-method page underneath it.
     */
    private function registerShippingOverviewRoute(): void {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['commerce/store-management/<storeHandle:.+>/shipping-overview'] = 'wauw/shipping/index';
            $event->rules['commerce/store-management/<storeHandle:.+>/shipping-overview/<methodHandle:[\w-]+>'] = 'wauw/shipping/method';
        });
    }

    /**
     * Inserts a single "Shipping management" item into Commerce's own Store Management nav,
     * right below its Shipping Methods/Zones/Categories items. That nav array is only exposed
     * as a template variable (no registration event), so this rewrites it on its way into the
     * template. Commerce renders that nav as its own partial (not a page template), so this
     * has to hook the generic "before render template" event rather than the page-level one.
     */
    private function addShippingOverviewNavItem(): void {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE, function (TemplateEvent $event) {
            if ($event->template !== 'commerce/_includes/_storeManagementNav' || !isset($event->variables['storeSettingsNav'])) return;

            $nav = [];
            foreach ($event->variables['storeSettingsNav'] as $handle => $item) {
                $nav[$handle] = $item;

                if ($handle === 'shippingcategories') {
                    $nav['shipping-overview'] = [
                        'label' => Craft::t('app', 'Shipping Management'),
                        'path' => 'shipping-overview',
                    ];
                }
            }

            $event->variables['storeSettingsNav'] = $nav;
        });
    }

    private function registerTwigExtensions(): void {
        Craft::$app->view->registerTwigExtension(new SiteTwigExtension());
    }

    private function registerSiteRoutes(): void {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['products/<slug:[^\/]+>/product-sheet'] = 'wauw/product-sheet';
        });
    }
}
