<?php

namespace modules\site;

use Craft;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use yii\base\Event;
use yii\base\Module;

use modules\site\web\twig\SiteTwigExtension;

class Site extends Module {
    private const CP_NAV_ORDER = [
        'dashboard',
        'commerce',
        'content',
        'assets',
        'globals',
        'core-seo-geo',
        '*', // all items not listed here
        'utilities',
        'settings',
        'plugin-store',
    ];

    public function init() {
        Craft::setAlias('@modules', __DIR__);

        parent::init();

        $this->controllerNamespace = 'modules\\site\\controllers';

        $this->registerTwigExtensions();
        $this->registerCpNavOrder();
    }

    private function registerTwigExtensions(): void {
        Craft::$app->view->registerTwigExtension(new SiteTwigExtension());
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
}
