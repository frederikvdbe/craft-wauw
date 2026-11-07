<?php

namespace modules\shop;

use Craft;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\TemplateEvent;
use craft\web\twig\variables\Cp;
use craft\web\View;
use yii\base\Event;

class Module extends \yii\base\Module
{
    private const HIDDEN_COMMERCE_NAV_ITEMS = ['subscriptions', 'subscription-plans', 'donations'];

    public function init(): void
    {
        Craft::setAlias('@modules/shop', __DIR__);

        parent::init();

        $this->hideUnusedCommerceNavItems();
        $this->hideCommerceSubscriptionSettings();
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

    private function hideCommerceSubscriptionSettings(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function (TemplateEvent $event) {
            if ($event->templateMode !== View::TEMPLATE_MODE_CP || !str_starts_with($event->template, 'commerce/settings/general')) {
                return;
            }

            Craft::$app->getView()->registerCss(
                '#updateBillingDetailsUrl-field,'
                . 'h3:has(+ #updateBillingDetailsUrl-field),'
                . 'hr:has(+ h3 + #updateBillingDetailsUrl-field) { display: none; }'
            );
        });
    }
}
