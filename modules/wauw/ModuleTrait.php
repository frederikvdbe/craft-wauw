<?php

namespace modules\wauw;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\events\RegisterAvailableShippingMethodsEvent;
use craft\commerce\services\ShippingMethods;
use craft\events\ModelEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

use modules\wauw\jobs\GenerateSpecSheet;
use modules\wauw\shippingmethods\GalleryPickup;
use modules\wauw\shippingmethods\StandardDoorstepDelivery;
use modules\wauw\shippingmethods\WhiteGlove;
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

        Event::on(
            ShippingMethods::class,
            ShippingMethods::EVENT_REGISTER_AVAILABLE_SHIPPING_METHODS,
            function(RegisterAvailableShippingMethodsEvent $event) {
                $event->shippingMethods[] = new GalleryPickup();
                $event->shippingMethods[] = new StandardDoorstepDelivery();
                $event->shippingMethods[] = new WhiteGlove();
            }
        );
    }

    protected function registerCpEvents(): void {
        $this->registerCpNavOrder();
        $this->hideUnusedCommerceNavItems();
        $this->hideUnusedCommerceSettings();
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

    private function registerTwigExtensions(): void {
        Craft::$app->view->registerTwigExtension(new SiteTwigExtension());
    }

    private function registerSiteRoutes(): void {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['products/<slug:[^\/]+>/product-sheet'] = 'wauw/product-sheet';
        });
    }
}
