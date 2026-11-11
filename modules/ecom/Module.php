<?php

namespace modules\ecom;

use Craft;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\TemplateEvent;
use craft\web\twig\variables\Cp;
use craft\web\View;
use yii\base\Event;

class Module extends \yii\base\Module
{
    private const HIDDEN_COMMERCE_NAV_ITEMS = ['subscriptions', 'subscription-plans', 'donations', 'inventory-transfers'];
    private const HIDDEN_COMMERCE_SETTINGS = ['subscriptions', 'transfers'];

    public function init(): void
    {
        Craft::setAlias('@modules/ecom', __DIR__);

        parent::init();

        $this->hideUnusedCommerceNavItems();
        $this->hideUnusedCommerceSettings();
    }

    // Commerce has no setting to turn these features off; the pages stay reachable by URL.
    private function hideUnusedCommerceNavItems(): void
    {
        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function (RegisterCpNavItemsEvent $event) {
            foreach ($event->navItems as &$item) {
                if (($item['url'] ?? null) === 'commerce') {
                    $item['subnav'] = array_diff_key($item['subnav'] ?? [], array_flip(self::HIDDEN_COMMERCE_NAV_ITEMS));
                }
            }
        });
    }

    private function hideUnusedCommerceSettings(): void
    {
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
}
